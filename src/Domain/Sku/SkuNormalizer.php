<?php
/**
 * Conservative SKU normalization.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Domain\Sku;

defined('ABSPATH') || exit;

final class SkuNormalizer
{
	private function __construct()
	{
	}

	public static function raw(string $sku): string
	{
		return trim($sku);
	}

	public static function match_key(string $sku): string
	{
		// Preserve case for the canonical value; only surrounding whitespace is ignored.
		return self::raw($sku);
	}

	public static function is_valid(string $sku): bool
	{
		return self::raw($sku) !== '';
	}
}
