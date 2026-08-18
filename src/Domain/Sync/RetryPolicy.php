<?php
/**
 * Retry policy for transient Sapo errors.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Domain\Sync;

use WooSapoSync\Infrastructure\Sapo\ErrorCode;

defined('ABSPATH') || exit;

final class RetryPolicy
{
	private const MAX_DELAY = 86400;

	private function __construct()
	{
	}

	public static function is_retryable(string $error_code): bool
	{
		return in_array($error_code, [ErrorCode::RATE_LIMIT, ErrorCode::TIMEOUT, ErrorCode::REMOTE_SERVER], true);
	}

	public static function next_delay(int $attempt_count, ?int $jitter = null): int
	{
		$attempt_count = max(1, min($attempt_count, 10));
		$base = min(self::MAX_DELAY, 60 * (2 ** ($attempt_count - 1)));
		$jitter = null === $jitter ? random_int(0, max(1, (int) floor($base * 0.2))) : max(0, $jitter);

		return min(self::MAX_DELAY, $base + $jitter);
	}
}
