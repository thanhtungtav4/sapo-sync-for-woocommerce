<?php
/**
 * Settings API integration for safe sync configuration.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Admin;

use WooSapoSync\Infrastructure\WordPress\InventoryLocationPolicy;
use WooSapoSync\Webhook\WebhookSignature;

defined('ABSPATH') || exit;

final class Settings
{
	public const OPTION_KEY = 'woo_sapo_sync_settings';

	private function __construct()
	{
	}

	public static function register(): void
	{
		if (! function_exists('register_setting')) {
			return;
		}

		ConnectionSettings::register();

		register_setting(
			'woo_sapo_sync_settings_group',
			self::OPTION_KEY,
			[
				'sanitize_callback' => [self::class, 'sanitize'],
				'default' => ['sync_mode' => 'shadow', 'location_policy_json' => '[]'],
			]
		);
		register_setting(
			'woo_sapo_sync_settings_group',
			WebhookSignature::SECRET_OPTION,
			[
				'sanitize_callback' => [self::class, 'sanitize_secret'],
			]
		);
		add_filter('option_page_capability_woo_sapo_sync_settings_group', static fn (): string => 'manage_woocommerce');
	}

	/**
	 * @param mixed $input
	 * @return array<string, mixed>
	 */
	public static function sanitize($input): array
	{
		$input = is_array($input) ? $input : [];
		$mode = ('write' === ($input['sync_mode'] ?? '')) ? 'write' : 'shadow';
		$json = isset($input['location_policy_json']) ? (string) $input['location_policy_json'] : '[]';
		$decoded = json_decode($json, true);
		$policy = [];
		if (is_array($decoded)) {
			foreach ($decoded as $row) {
				if (! is_array($row) || empty($row['id'])) {
					continue;
				}
				$policy[] = [
					'id' => sanitize_text_field((string) $row['id']),
					'priority' => (int) ($row['priority'] ?? count($policy)),
					'serves' => ! empty($row['serves']),
				];
			}
		}

		update_option(InventoryLocationPolicy::OPTION_KEY, $policy, false);
		return [
			'sync_mode' => $mode,
			'location_policy_json' => wp_json_encode($policy, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
		];
	}

	/**
	 * Keep the current secret when the password field is left blank.
	 */
	public static function sanitize_secret($input): string
	{
		$input = trim((string) $input);
		if ('' === $input) {
			$current = get_option(WebhookSignature::SECRET_OPTION, '');
			return is_string($current) ? $current : '';
		}

		return sanitize_text_field($input);
	}
}
