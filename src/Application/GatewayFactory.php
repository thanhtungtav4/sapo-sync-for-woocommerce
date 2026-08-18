<?php
/**
 * Single composition point for the verified Sapo gateway.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Application;

use WooSapoSync\Admin\ConnectionSettings;
use WooSapoSync\Contracts\SapoGateway;
use WooSapoSync\Infrastructure\Sapo\SapoAdminGateway;
use WooSapoSync\Infrastructure\Sapo\UnavailableGateway;

defined('ABSPATH') || exit;

final class GatewayFactory
{
	private function __construct()
	{
	}

	public static function make(): SapoGateway
	{
		// A verified adapter may be injected by the integration bridge after its
		// contract fixtures pass. Any invalid/missing injection stays fail-closed.
		if (function_exists('apply_filters')) {
			$injected = apply_filters('woo_sapo_gateway', null);
			if ($injected instanceof SapoGateway) {
				return $injected;
			}
		}

		$connection = ConnectionSettings::get();
		if (ConnectionSettings::is_configured()) {
			return new SapoAdminGateway(
				(string) ($connection['base_url'] ?? ''),
				(string) ($connection['auth_mode'] ?? 'basic'),
				(string) ($connection['api_key'] ?? ''),
				(string) ($connection['api_secret'] ?? ''),
				(string) ($connection['access_token'] ?? '')
			);
		}

		return new UnavailableGateway();
	}
}
