<?php
/**
 * Product types supported by the MVP mapping layer.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Domain\Product;

defined('ABSPATH') || exit;

final class ProductType
{
	public const SIMPLE = 'SIMPLE';
	public const VARIATION = 'VARIATION';

	private function __construct()
	{
	}

	public static function is_supported(string $type): bool
	{
		return in_array($type, [self::SIMPLE, self::VARIATION], true);
	}
}
