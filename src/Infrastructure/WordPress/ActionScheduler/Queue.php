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
			$args = ['operation_id' => $operation_id];
			if (function_exists('as_has_scheduled_action') && as_has_scheduled_action(self::PROCESS_ORDER_HOOK, $args, 'sapo-sync-for-woocommerce')) {
				return true;
			}
			$result = as_schedule_single_action(time() + $delay_seconds, self::PROCESS_ORDER_HOOK, $args, 'sapo-sync-for-woocommerce', true);
			return self::action_scheduled($result, self::PROCESS_ORDER_HOOK, $args);
		}

		if (function_exists('wp_schedule_single_event')) {
			$args = [$operation_id];
			if (function_exists('wp_next_scheduled') && wp_next_scheduled(self::PROCESS_ORDER_HOOK, $args)) {
				return true;
			}
			$result = wp_schedule_single_event(time() + $delay_seconds, self::PROCESS_ORDER_HOOK, $args);
			return false !== $result || (function_exists('wp_next_scheduled') && (bool) wp_next_scheduled(self::PROCESS_ORDER_HOOK, $args));
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
			$args = ['event_key' => $event_key];
			if (function_exists('as_has_scheduled_action') && as_has_scheduled_action('woo_sapo_sync_process_event', $args, 'sapo-sync-for-woocommerce')) {
				return true;
			}
			$result = null;
			if ($delay_seconds > 1 && function_exists('as_schedule_single_action')) {
				$result = as_schedule_single_action(time() + $delay_seconds, 'woo_sapo_sync_process_event', $args, 'sapo-sync-for-woocommerce', true);
			} else {
				$result = as_enqueue_async_action('woo_sapo_sync_process_event', $args, 'sapo-sync-for-woocommerce', true);
			}
			return self::action_scheduled($result, 'woo_sapo_sync_process_event', $args);
		}

		if (function_exists('wp_schedule_single_event')) {
			$args = [$event_key];
			if (function_exists('wp_next_scheduled') && wp_next_scheduled('woo_sapo_sync_process_event', $args)) {
				return true;
			}
			$result = wp_schedule_single_event(time() + $delay_seconds, 'woo_sapo_sync_process_event', $args);
			return false !== $result || (function_exists('wp_next_scheduled') && (bool) wp_next_scheduled('woo_sapo_sync_process_event', $args));
		}

		return false;
	}

	public static function enqueue_inventory(): bool
	{
		if (function_exists('as_enqueue_async_action')) {
			$args = [];
			if (function_exists('as_has_scheduled_action') && as_has_scheduled_action(Scheduler::INVENTORY_HOOK, $args, 'sapo-sync-for-woocommerce')) {
				return true;
			}
			$result = as_enqueue_async_action(Scheduler::INVENTORY_HOOK, $args, 'sapo-sync-for-woocommerce', true);
			return self::action_scheduled($result, Scheduler::INVENTORY_HOOK, $args);
		}

		if (function_exists('wp_schedule_single_event')) {
			$args = [];
			if (function_exists('wp_next_scheduled') && wp_next_scheduled(Scheduler::INVENTORY_HOOK, $args)) {
				return true;
			}
			$result = wp_schedule_single_event(time() + 1, Scheduler::INVENTORY_HOOK, $args);
			return false !== $result || (function_exists('wp_next_scheduled') && (bool) wp_next_scheduled(Scheduler::INVENTORY_HOOK, $args));
		}

		return false;
	}

	public static function enqueue_mapping(): bool
	{
		if (function_exists('as_enqueue_async_action')) {
			$args = [];
			if (function_exists('as_has_scheduled_action') && as_has_scheduled_action(self::PROCESS_MAPPING_HOOK, $args, 'sapo-sync-for-woocommerce')) {
				return true;
			}
			$result = as_enqueue_async_action(self::PROCESS_MAPPING_HOOK, $args, 'sapo-sync-for-woocommerce', true);
			return self::action_scheduled($result, self::PROCESS_MAPPING_HOOK, $args);
		}

		if (function_exists('wp_schedule_single_event')) {
			$args = [];
			if (function_exists('wp_next_scheduled') && wp_next_scheduled(self::PROCESS_MAPPING_HOOK, $args)) {
				return true;
			}
			$result = wp_schedule_single_event(time() + 1, self::PROCESS_MAPPING_HOOK, $args);
			return false !== $result || (function_exists('wp_next_scheduled') && (bool) wp_next_scheduled(self::PROCESS_MAPPING_HOOK, $args));
		}

		return false;
	}

	/**
	 * Action Scheduler returns an action ID (or zero on failure/duplicate). A
	 * post-write lookup handles the race where another request enqueues the same
	 * unique action between our pre-check and schedule call.
	 *
	 * @param mixed $result
	 * @param array<string, mixed> $args
	 */
	private static function action_scheduled($result, string $hook, array $args): bool
	{
		if (is_numeric($result) && (int) $result > 0) {
			return true;
		}
		if (false !== $result && ! is_numeric($result) && null !== $result) {
			return true;
		}

		return function_exists('as_has_scheduled_action') && (bool) as_has_scheduled_action($hook, $args, 'sapo-sync-for-woocommerce');
	}
}
