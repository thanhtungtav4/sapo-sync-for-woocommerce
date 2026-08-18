<?php
/**
 * HTTP boundary used by a future verified Sapo adapter.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Infrastructure\Sapo\Http;

defined('ABSPATH') || exit;

interface HttpTransport
{
	/**
	 * @param array<string, string> $headers
	 * @param array<string, mixed>|null $body
	 * @return array{status: int, headers: array<string, string>, body: mixed}
	 */
	public function request(string $method, string $url, array $headers = [], ?array $body = null): array;
}
