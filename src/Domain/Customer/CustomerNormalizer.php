<?php
/**
 * Normalizes customer identifiers used for Sapo lookup.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Domain\Customer;

defined('ABSPATH') || exit;

final class CustomerNormalizer
{
	private function __construct()
	{
	}

	public static function phone(string $phone): string
	{
		$value = preg_replace('/[^0-9+]/', '', trim($phone)) ?: '';
		$value = ltrim($value, '+');

		if (0 === strpos($value, '00')) {
			$value = substr($value, 2);
		}

		if (0 === strpos($value, '0')) {
			$value = '84' . substr($value, 1);
		}

		return '' === $value ? '' : '+' . $value;
	}

	public static function email(string $email): string
	{
		return strtolower(trim($email));
	}

	/**
	 * Convert common WooCommerce/Vietnamese province codes to the names Sapo
	 * accepts. Full names are preserved so stores using Sapo's own labels remain
	 * unchanged.
	 */
	public static function province(string $province): string
	{
		$value = trim($province);
		if ('' === $value) {
			return '';
		}

		$key = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $value));
		$aliases = [
			'HCM' => 'Hồ Chí Minh',
			'HCMC' => 'Hồ Chí Minh',
			'SG' => 'Hồ Chí Minh',
			'VNSG' => 'Hồ Chí Minh',
			'HN' => 'Hà Nội',
			'HANOI' => 'Hà Nội',
			'VNHN' => 'Hà Nội',
			'DN' => 'Đà Nẵng',
			'DNG' => 'Đà Nẵng',
			'VNDN' => 'Đà Nẵng',
			'HP' => 'Hải Phòng',
			'VNHP' => 'Hải Phòng',
			'CT' => 'Cần Thơ',
			'VNCT' => 'Cần Thơ',
		];

		return $aliases[$key] ?? $value;
	}
}
