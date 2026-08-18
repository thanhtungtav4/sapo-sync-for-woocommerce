<?php
/**
 * Small response-shape validator used at the Sapo boundary.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Infrastructure\Sapo;

use WooSapoSync\Infrastructure\Sapo\Exception\SapoException;

defined('ABSPATH') || exit;

/* phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages and context are not browser output. */

final class ResponseValidator
{
	private function __construct()
	{
	}

	/**
	 * @param mixed $payload
	 * @return array<string, mixed>
	 */
	public static function object($payload, string $context): array
	{
		if (! is_array($payload) || self::is_list($payload)) {
			throw new SapoException(ErrorCode::VALIDATION, "Sapo {$context} response must be an object.");
		}

		return $payload;
	}

	/**
	 * @param mixed $payload
	 * @return array<int, mixed>
	 */
	public static function list($payload, string $context): array
	{
		if (! is_array($payload) || ! self::is_list($payload)) {
			throw new SapoException(ErrorCode::VALIDATION, "Sapo {$context} response must be a list.");
		}

		return $payload;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public static function require_string(array $payload, string $key, string $context): string
	{
		$value = $payload[$key] ?? null;
		if (! is_string($value) || '' === trim($value)) {
			throw new SapoException(ErrorCode::VALIDATION, "Sapo {$context} response is missing {$key}.");
		}

		return trim($value);
	}

	/**
	 * PHP 8.0-compatible replacement for array_is_list().
	 *
	 * @param array<mixed> $value
	 */
	private static function is_list(array $value): bool
	{
		$keys = array_keys($value);
		return $keys === [] || $keys === range(0, count($keys) - 1);
	}
}
