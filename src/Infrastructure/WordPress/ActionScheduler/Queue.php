<?php
/**
 * Action Scheduler/WP-Cron bridge for sync operations.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Infrastructure\WordPress\ActionScheduler;

use WooSapoSync\Infrastructure\WordPress\Scheduler;

defined('ABSPATH') || exit;

final class Queue
{
	public const PROCESS_ORDER_HOOK = 'woo_sapo_sync_process_order';

	public const PROCESS_MAPPING_HOOK = Scheduler::MAPPING_HOOK;

	private function __construct()
	{
	}

	public static function enqueue_order(int $operation_id): bool
	{
		return self::enqueue_order_after($operation_id, 1);
	}

	public static function enqueue_order_after(int $operation_id, int $delay_seconds): bool
	{
		if ($operation_id <= 0) {
			return false;
		}

		$delay_seconds = max(1, $delay_seconds);
		if (function_exists('as_schedule_single_action')) {
			as_schedule_single_action(time() + $delay_seconds, self::PROCESS_ORDER_HOOK, ['operation_id' => $operation_id], 'sapo-sync-for-woocommerce', true);
			return true;
		}

		if (function_exists('wp_schedule_single_event')) {
			wp_schedule_single_event(time() + $delay_seconds, self::PROCESS_ORDER_HOOK, [$operation_id]);
			return true;
		}

		return false;
	}

	public static function enqueue_event(string $event_key): bool
	{
		return self::enqueue_event_after($event_key, 1);
	}

	public static function enqueue_event_after(string $event_key, int $delay_seconds): bool
	{
		$event_key = trim($event_key);
		if ('' === $event_key) {
			return false;
		}
		$delay_seconds = max(1, $delay_seconds);

		if (function_exists('as_enqueue_async_action')) {
			if (function_exists('as_has_scheduled_action') && as_has_scheduled_action('woo_sapo_sync_process_event', ['event_key' => $event_key], 'sapo-sync-for-woocommerce')) {
				return true;
			}
			if ($delay_seconds > 1 && function_exists('as_schedule_single_action')) {
				as_schedule_single_action(time() + $delay_seconds, 'woo_sapo_sync_process_event', ['event_key' => $event_key], 'sapo-sync-for-woocommerce', true);
			} else {
				as_enqueue_async_action('woo_sapo_sync_process_event', ['event_key' => $event_key], 'sapo-sync-for-woocommerce', true);
			}
			return true;
		}

		if (function_exists('wp_schedule_single_event')) {
			if (function_exists('wp_next_scheduled') && wp_next_scheduled('woo_sapo_sync_process_event', [$event_key])) {
				return true;
			}
			wp_schedule_single_event(time() + $delay_seconds, 'woo_sapo_sync_process_event', [$event_key]);
			return true;
		}

		return false;
	}

	public static function enqueue_inventory(): bool
	{
		if (function_exists('as_enqueue_async_action')) {
			if (function_exists('as_has_scheduled_action') && as_has_scheduled_action(Scheduler::INVENTORY_HOOK, [], 'sapo-sync-for-woocommerce')) {
				return true;
			}
			as_enqueue_async_action(Scheduler::INVENTORY_HOOK, [], 'sapo-sync-for-woocommerce', true);
			return true;
		}

		if (function_exists('wp_schedule_single_event')) {
			if (function_exists('wp_next_scheduled') && wp_next_scheduled(Scheduler::INVENTORY_HOOK)) {
				return true;
			}
			wp_schedule_single_event(time() + 1, Scheduler::INVENTORY_HOOK);
			return true;
		}

		return false;
	}

	public static function enqueue_mapping(): bool
	{
		if (function_exists('as_enqueue_async_action')) {
			if (function_exists('as_has_scheduled_action') && as_has_scheduled_action(self::PROCESS_MAPPING_HOOK, [], 'sapo-sync-for-woocommerce')) {
				return true;
			}
			as_enqueue_async_action(self::PROCESS_MAPPING_HOOK, [], 'sapo-sync-for-woocommerce', true);
			return true;
		}

		if (function_exists('wp_schedule_single_event')) {
			if (function_exists('wp_next_scheduled') && wp_next_scheduled(self::PROCESS_MAPPING_HOOK)) {
				return true;
			}
			wp_schedule_single_event(time() + 1, self::PROCESS_MAPPING_HOOK);
			return true;
		}

		return false;
	}
}
