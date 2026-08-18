<?php
/**
 * Settings API integration for safe sync configuration.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Admin;

use WooSapoSync\Infrastructure\WordPress\InventoryLocationPolicy;
use WooSapoSync\Infrastructure\WordPress\Scheduler;
use WooSapoSync\Webhook\WebhookSignature;

defined('ABSPATH') || exit;

final class Settings
{
	public const OPTION_KEY = 'woo_sapo_sync_settings';

	public const CRON_SECRET_OPTION = 'woo_sapo_sync_cron_secret';

	public const CRON_MODE_AUTOMATIC = 'automatic';

	public const CRON_MODE_EXTERNAL = 'external';

	public const CRON_MODE_HYBRID = 'hybrid';

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
				'default' => [
					'sync_mode' => 'shadow',
					'cron_mode' => self::CRON_MODE_AUTOMATIC,
					'location_policy_json' => '[]',
				],
			]
		);
		register_setting(
			'woo_sapo_sync_settings_group',
			WebhookSignature::SECRET_OPTION,
			[
				'sanitize_callback' => [self::class, 'sanitize_secret'],
			]
		);
		register_setting(
			'woo_sapo_sync_settings_group',
			self::CRON_SECRET_OPTION,
			[
				'sanitize_callback' => [self::class, 'sanitize_cron_secret'],
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
		$stored = get_option(self::OPTION_KEY, []);
		$stored = is_array($stored) ? $stored : [];
		$mode = ('write' === ($input['sync_mode'] ?? '')) ? 'write' : 'shadow';
		$cron_mode = in_array(($input['cron_mode'] ?? ''), [self::CRON_MODE_AUTOMATIC, self::CRON_MODE_EXTERNAL, self::CRON_MODE_HYBRID], true)
			? (string) $input['cron_mode']
			: self::CRON_MODE_AUTOMATIC;
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
		$previous_cron_mode = (string) ($stored['cron_mode'] ?? self::CRON_MODE_AUTOMATIC);
		if (self::CRON_MODE_EXTERNAL === $cron_mode && self::CRON_MODE_EXTERNAL !== $previous_cron_mode) {
			Scheduler::unschedule();
		}
		return [
			'sync_mode' => $mode,
			'cron_mode' => $cron_mode,
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

	/**
	 * Keep the external runner token independent from the Sapo webhook secret.
	 */
	public static function sanitize_cron_secret($input): string
	{
		$input = trim((string) $input);
		if ('' === $input) {
			$current = get_option(self::CRON_SECRET_OPTION, '');
			return is_string($current) ? $current : '';
		}

		return sanitize_text_field($input);
	}

	public static function cron_mode(): string
	{
		$settings = get_option(self::OPTION_KEY, []);
		$mode = is_array($settings) ? (string) ($settings['cron_mode'] ?? '') : '';

		return in_array($mode, [self::CRON_MODE_AUTOMATIC, self::CRON_MODE_EXTERNAL, self::CRON_MODE_HYBRID], true)
			? $mode
			: self::CRON_MODE_AUTOMATIC;
	}

	public static function cron_secret(): string
	{
		$value = get_option(self::CRON_SECRET_OPTION, '');

		return is_string($value) ? trim($value) : '';
	}

	public static function external_cron_enabled(): bool
	{
		return in_array(self::cron_mode(), [self::CRON_MODE_EXTERNAL, self::CRON_MODE_HYBRID], true);
	}
}
