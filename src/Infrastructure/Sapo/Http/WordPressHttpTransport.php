<?php
/**
 * WordPress HTTP API implementation with normalized Sapo errors.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Infrastructure\Sapo\Http;

use WooSapoSync\Infrastructure\Sapo\ErrorCode;
use WooSapoSync\Infrastructure\Sapo\Exception\SapoException;

defined('ABSPATH') || exit;

/* phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages and context are not browser output. */

final class WordPressHttpTransport implements HttpTransport
{
	private int $timeout;

	public function __construct(int $timeout = 15)
	{
		$this->timeout = max(1, min($timeout, 60));
	}

	/**
	 * @param array<string, string> $headers
	 * @param array<string, mixed>|null $body
	 * @return array{status: int, headers: array<string, string>, body: mixed}
	 */
	public function request(string $method, string $url, array $headers = [], ?array $body = null): array
	{
		$args = [
			'method' => strtoupper($method),
			'timeout' => $this->timeout,
			'headers' => array_merge(
				[
					'Accept' => 'application/json',
					// Sapo's Private App contract expects JSON for every Admin API request,
					// including read-only probes that do not carry a body.
					'Content-Type' => 'application/json',
				],
				$headers
			),
			'data_format' => 'body',
		];

		if (null !== $body) {
			$args['body'] = wp_json_encode($body);
		}

		$response = wp_safe_remote_request($url, $args);
		if (is_wp_error($response)) {
			$code = $response->get_error_code();
			$error_code = in_array($code, ['http_request_failed', 'curl_timeout'], true)
				? ErrorCode::TIMEOUT
				: ErrorCode::REMOTE_SERVER;
			throw new SapoException($error_code, $response->get_error_message(), ['wp_error_code' => $code]);
		}

		$status = (int) wp_remote_retrieve_response_code($response);
		$raw_body = (string) wp_remote_retrieve_body($response);
		$decoded = '' === $raw_body ? [] : json_decode($raw_body, true);
		if (JSON_ERROR_NONE !== json_last_error()) {
			throw new SapoException(ErrorCode::VALIDATION, 'Sapo response is not valid JSON.', ['status' => $status]);
		}

		if ($status < 200 || $status >= 300) {
			throw new SapoException($this->map_status($status), 'Sapo request failed.', [
				'status' => $status,
				'body' => is_array($decoded) ? $decoded : [],
			]);
		}

		$headers_out = [];
		foreach ((array) wp_remote_retrieve_headers($response) as $key => $value) {
			$headers_out[(string) $key] = is_scalar($value) ? (string) $value : '';
		}

		return [
			'status' => $status,
			'headers' => $headers_out,
			'body' => $decoded,
		];
	}

	private function map_status(int $status): string
	{
		if (in_array($status, [401, 403], true)) {
			return ErrorCode::AUTH;
		}

		if (404 === $status) {
			return ErrorCode::NOT_FOUND;
		}

		if (409 === $status) {
			return ErrorCode::CONFLICT;
		}

		if (422 === $status) {
			return ErrorCode::VALIDATION;
		}

		if (429 === $status) {
			return ErrorCode::RATE_LIMIT;
		}

		return $status >= 500 ? ErrorCode::REMOTE_SERVER : ErrorCode::VALIDATION;
	}
}
