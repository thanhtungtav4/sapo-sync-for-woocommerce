<?php
/**
 * Recurring sync schedule with Action Scheduler/WP-Cron fallback.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Infrastructure\WordPress;

use WooSapoSync\Admin\Settings;

defined('ABSPATH') || exit;

final class Scheduler
{
	public const INVENTORY_HOOK = 'woo_sapo_sync_reconcile_inventory';

	public const MAPPING_HOOK = 'woo_sapo_sync_reconcile_mapping';

	public const EVENT_SWEEP_HOOK = 'woo_sapo_sync_requeue_events';

	private const GROUP = 'sapo-sync-for-woocommerce';

	/** Webhooks trigger an immediate run; this is the inventory safety net. */
	private const INTERVAL = 60;

	private const DAILY_INTERVAL = 86400;

	private static bool $registered = false;

	private function __construct()
	{
	}

	public static function register(): void
	{
		if (self::$registered) {
			return;
		}
		self::$registered = true;

		// In external mode the dedicated REST runner invokes these hooks. Do not
		// leave recurring WP-Cron/Action Scheduler entries behind as a second source
		// of writes, while hybrid mode deliberately keeps both execution paths.
		if (Settings::CRON_MODE_EXTERNAL === Settings::cron_mode()) {
			return;
		}

		if (function_exists('as_schedule_recurring_action')) {
			if (! function_exists('as_has_scheduled_action') || ! as_has_scheduled_action(self::INVENTORY_HOOK, [], self::GROUP)) {
				as_schedule_recurring_action(time() + self::INTERVAL, self::INTERVAL, self::INVENTORY_HOOK, [], self::GROUP, true);
			}
			if (! function_exists('as_has_scheduled_action') || ! as_has_scheduled_action(self::MAPPING_HOOK, [], self::GROUP)) {
				as_schedule_recurring_action(time() + self::DAILY_INTERVAL, self::DAILY_INTERVAL, self::MAPPING_HOOK, [], self::GROUP, true);
			}
			if (! function_exists('as_has_scheduled_action') || ! as_has_scheduled_action(self::EVENT_SWEEP_HOOK, [], self::GROUP)) {
				as_schedule_recurring_action(time() + self::INTERVAL, self::INTERVAL, self::EVENT_SWEEP_HOOK, [], self::GROUP, true);
			}
			return;
		}

		add_filter('cron_schedules', [self::class, 'cron_schedules']);
		if (function_exists('wp_next_scheduled') && ! wp_next_scheduled(self::INVENTORY_HOOK)) {
			wp_schedule_event(time() + self::INTERVAL, 'woo_sapo_one_minute', self::INVENTORY_HOOK);
		}
		if (function_exists('wp_next_scheduled') && ! wp_next_scheduled(self::MAPPING_HOOK)) {
			wp_schedule_event(time() + self::DAILY_INTERVAL, 'daily', self::MAPPING_HOOK);
		}
		if (function_exists('wp_next_scheduled') && ! wp_next_scheduled(self::EVENT_SWEEP_HOOK)) {
			wp_schedule_event(time() + self::INTERVAL, 'woo_sapo_one_minute', self::EVENT_SWEEP_HOOK);
		}
	}

	/**
	 * @param array<string, array<string, mixed>> $schedules
	 * @return array<string, array<string, mixed>>
	 */
	public static function cron_schedules(array $schedules): array
	{
		$schedules['woo_sapo_one_minute'] = [
			'interval' => self::INTERVAL,
			'display' => __('Mỗi phút — Sapo Sync for WooCommerce', 'sapo-sync-for-woocommerce'),
		];
		return $schedules;
	}

	public static function unschedule(): void
	{
		if (function_exists('as_unschedule_all_actions')) {
			as_unschedule_all_actions(self::INVENTORY_HOOK, [], self::GROUP);
			as_unschedule_all_actions(self::MAPPING_HOOK, [], self::GROUP);
			as_unschedule_all_actions(self::EVENT_SWEEP_HOOK, [], self::GROUP);
		}
		if (function_exists('wp_clear_scheduled_hook')) {
			wp_clear_scheduled_hook(self::INVENTORY_HOOK);
			wp_clear_scheduled_hook(self::MAPPING_HOOK);
			wp_clear_scheduled_hook(self::EVENT_SWEEP_HOOK);
		}
	}
}
