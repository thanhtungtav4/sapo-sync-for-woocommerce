<?php
/**
 * Redacted operational logger for sync workers.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Infrastructure\WordPress;

defined('ABSPATH') || exit;

final class SyncLogger
{
	private const SOURCE = 'sapo-sync-for-woocommerce';

	private function __construct()
	{
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public static function log(string $level, string $message, array $context = []): void
	{
		if (! function_exists('wc_get_logger')) {
			return;
		}

		$context['correlation_id'] = self::correlation_id($context['correlation_id'] ?? null);
		$logger = wc_get_logger();
		$logger->log($level, $message, [
			'source' => self::SOURCE,
			'context' => self::redact($context),
		]);
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	private static function redact($value, int $depth = 0)
	{
		if ($depth > 4) {
			return '[truncated]';
		}
		if (is_array($value)) {
			$redacted = [];
			foreach ($value as $key => $item) {
				$key_string = strtolower((string) $key);
				$redacted[$key] = preg_match('/token|secret|password|authorization|phone|email|address|postcode/', $key_string)
					? '[redacted]'
					: self::redact($item, $depth + 1);
			}
			return $redacted;
		}

		return is_scalar($value) || null === $value ? $value : '[redacted]';
	}

	private static function correlation_id($value): string
	{
		if (is_string($value) && '' !== trim($value)) {
			return substr(trim($value), 0, 64);
		}
		if (function_exists('wp_generate_uuid4')) {
			return wp_generate_uuid4();
		}
		return bin2hex(random_bytes(16));
	}
}
