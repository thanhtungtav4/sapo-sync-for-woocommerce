<?php
/**
 * Price source policy constants.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Domain\Product;

defined('ABSPATH') || exit;

final class PriceSource
{
	public const WOO = 'WOO';
	public const SAPO = 'SAPO';

	private function __construct()
	{
	}

	public static function is_valid(string $source): bool
	{
		return in_array($source, [self::WOO, self::SAPO], true);
	}
}
