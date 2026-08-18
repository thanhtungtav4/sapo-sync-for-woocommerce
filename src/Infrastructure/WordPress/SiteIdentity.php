<?php
/**
 * Stable site identifier used in external references.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Infrastructure\WordPress;

defined('ABSPATH') || exit;

final class SiteIdentity
{
	public const OPTION_KEY = 'woo_sapo_sync_site_uuid';

	private function __construct()
	{
	}

	public static function value(): string
	{
		$stored = get_option(self::OPTION_KEY, '');
		if (is_string($stored) && '' !== trim($stored)) {
			return sanitize_key($stored);
		}

		$url = function_exists('home_url') ? home_url('/') : 'woocommerce-sapo-sync.example';
		$derived = substr(hash('sha256', (string) $url), 0, 16);
		return function_exists('sanitize_key') ? sanitize_key($derived) : $derived;
	}
}
