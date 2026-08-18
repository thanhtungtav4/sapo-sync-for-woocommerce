<?php
/**
 * Authenticated runner for hosts that execute cron outside WordPress.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Cron;

use WooSapoSync\Admin\Settings;
use WooSapoSync\Infrastructure\WordPress\JobLock;
use WooSapoSync\Infrastructure\WordPress\Scheduler;

defined('ABSPATH') || exit;

final class ExternalCronController
{
	private const NAMESPACE = 'woo-sapo/v1';

	private const LEGACY_NAMESPACE = 'pixelcam-sapo/v1';

	private const TOKEN_HEADER = 'x-woo-sapo-cron-token';

	private static bool $registered = false;

	private function __construct()
	{
	}

	public static function register(): void
	{
		if (self::$registered || ! function_exists('register_rest_route')) {
			return;
		}

		self::$registered = true;
		foreach ([self::NAMESPACE, self::LEGACY_NAMESPACE] as $namespace) {
			register_rest_route(
				$namespace,
				'/cron',
				[
					'methods' => \WP_REST_Server::CREATABLE,
					'callback' => [self::class, 'run'],
					'permission_callback' => [self::class, 'authorize'],
					'args' => [],
				]
			);
		}
	}

	/**
	 * @param mixed $request
	 * @return mixed
	 */
	public static function authorize($request)
	{
		if (! Settings::external_cron_enabled()) {
			return new \WP_Error(
				'woo_sapo_external_cron_disabled',
				'External cron chưa được bật trong cấu hình Inventory Bridge.',
				['status' => 409]
			);
		}

		$secret = Settings::cron_secret();
		if ('' === $secret) {
			return new \WP_Error(
				'woo_sapo_external_cron_unconfigured',
				'External cron secret chưa được cấu hình.',
				['status' => 503]
			);
		}

		$provided = self::token($request);
		if ('' === $provided || ! hash_equals($secret, $provided)) {
			return new \WP_Error(
				'woo_sapo_external_cron_unauthorized',
				'External cron token không hợp lệ.',
				['status' => 401]
			);
		}

		return true;
	}

	/**
	 * Run reconciliation hooks and release any due queue items.
	 *
	 * The endpoint is intentionally idempotent. Job-level locks and the existing
	 * repositories protect against overlapping calls and duplicate remote writes.
	 *
	 * @param mixed $request
	 * @return mixed
	 */
	public static function run($request)
	{
		$lock = new JobLock('external-cron', 1800);
		if (! $lock->acquire()) {
			return new \WP_REST_Response(
				[
					'accepted' => true,
					'skipped' => 'already_running',
				],
				202
			);
		}

		$started_at = microtime(true);
		try {
			if (! $lock->refresh()) {
				return new \WP_REST_Response(['accepted' => true, 'skipped' => 'lock_lost'], 202);
			}
			/* phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook names are plugin-prefixed constants owned by Scheduler. */
			do_action(Scheduler::EVENT_SWEEP_HOOK);
			if (! $lock->refresh()) {
				return new \WP_REST_Response(['accepted' => true, 'skipped' => 'lock_lost'], 202);
			}
			/* phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook names are plugin-prefixed constants owned by Scheduler. */
			do_action(Scheduler::INVENTORY_HOOK);
			if (Scheduler::external_mapping_due()) {
				if (! $lock->refresh()) {
					return new \WP_REST_Response(['accepted' => true, 'skipped' => 'lock_lost'], 202);
				}
				/* phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook names are plugin-prefixed constants owned by Scheduler. */
				do_action(Scheduler::MAPPING_HOOK);
				Scheduler::mark_external_mapping_run();
			}

			$queue_runner = false;
			if (! $lock->refresh()) {
				return new \WP_REST_Response(['accepted' => true, 'skipped' => 'lock_lost'], 202);
			}
			if (function_exists('wp_cron')) {
				wp_cron();
				$queue_runner = true;
			}
			if (function_exists('as_enqueue_async_action')) {
				// Action Scheduler's runner is normally invoked by WP-Cron. Calling
				// its public hook here also supports DISABLE_WP_CRON installations.
				/* phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- This is Action Scheduler's public vendor hook. */
				do_action('action_scheduler_run_queue');
				$queue_runner = true;
			}

			return new \WP_REST_Response(
				[
					'accepted' => true,
					'mode' => Settings::cron_mode(),
					'queue_runner' => $queue_runner,
					'duration_ms' => (int) round((microtime(true) - $started_at) * 1000),
				],
				202
			);
		} finally {
			$lock->release();
		}
	}

	/**
	 * @param mixed $request
	 */
	private static function token($request): string
	{
		if (! is_object($request) || ! method_exists($request, 'get_header')) {
			return '';
		}

		$authorization = trim((string) $request->get_header('authorization'));
		if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
			return trim((string) ($matches[1] ?? ''));
		}

		return trim((string) $request->get_header(self::TOKEN_HEADER));
	}
}
