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
}
