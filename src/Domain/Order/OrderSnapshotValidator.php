<?php
/**
 * Validates the minimum invariant before an order enters the Sapo outbox.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Domain\Order;

use WooSapoSync\Domain\Sku\SkuNormalizer;

defined('ABSPATH') || exit;

final class OrderSnapshotValidator
{
	private function __construct()
	{
	}

	/**
	 * @param array<string, mixed> $snapshot
	 * @return array<int, string>
	 */
	public static function errors(array $snapshot): array
	{
		$errors = [];
		if ((int) ($snapshot['woo_order_id'] ?? 0) <= 0) {
			$errors[] = 'INVALID_ORDER_ID';
		}

		if ('processing' !== (string) ($snapshot['status'] ?? '')) {
			$errors[] = 'ORDER_NOT_ACCEPTED';
		}

		$lines = $snapshot['lines'] ?? [];
		if (! is_array($lines) || [] === $lines) {
			$errors[] = 'EMPTY_LINES';
			return $errors;
		}

		foreach ($lines as $line) {
			if (! is_array($line) || ! SkuNormalizer::is_valid((string) ($line['sku'] ?? ''))) {
				$errors[] = 'LINE_MISSING_SKU';
				continue;
			}

			if ((float) ($line['quantity'] ?? 0) <= 0) {
				$errors[] = 'LINE_INVALID_QUANTITY';
			}
		}

		return array_values(array_unique($errors));
	}

	/**
	 * @param array<string, mixed> $snapshot
	 */
	public static function is_valid(array $snapshot): bool
	{
		return [] === self::errors($snapshot);
	}
}
