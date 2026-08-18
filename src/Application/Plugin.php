<?php
/**
 * Plugin application bootstrap.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Application;

use WooSapoSync\Infrastructure\WordPress\Installer;
use WooSapoSync\Admin\CapabilityPage;
use WooSapoSync\Admin\OperationsPage;
use WooSapoSync\Admin\MappingPage;
use WooSapoSync\Infrastructure\WordPress\Repository\EventInboxRepository;
use WooSapoSync\Infrastructure\WordPress\Repository\ProductMappingRepository;
use WooSapoSync\Infrastructure\WordPress\Repository\SyncOperationRepository;
use WooSapoSync\Infrastructure\WordPress\SiteIdentity;
use WooSapoSync\Infrastructure\WordPress\Scheduler;
use WooSapoSync\Infrastructure\WordPress\Privacy;
use WooSapoSync\Infrastructure\WooCommerce\ProductStockUpdater;
use WooSapoSync\Infrastructure\WooCommerce\CatalogReader;
use WooSapoSync\Infrastructure\WordPress\ActionScheduler\Queue;
use WooSapoSync\Infrastructure\WooCommerce\OrderSnapshotBuilder;
use WooSapoSync\Webhook\RestController;
use WooSapoSync\Cron\ExternalCronController;
use WooSapoSync\Admin\ConnectionSettings;

defined('ABSPATH') || exit;

final class Plugin
{
	private static bool $booted = false;

	private function __construct()
	{
	}

	public static function boot(string $plugin_file): void
	{
		if (self::$booted) {
			return;
		}

		self::$booted = true;

		register_activation_hook($plugin_file, [Installer::class, 'activate']);
		register_deactivation_hook($plugin_file, [Installer::class, 'deactivate']);

		add_action('plugins_loaded', [self::class, 'on_plugins_loaded']);
	}

	public static function on_plugins_loaded(): void
	{
		Installer::maybe_migrate();
		add_action('admin_init', [Privacy::class, 'register']);
		CapabilityPage::register();
		OperationsPage::register();
		MappingPage::register();
		add_action('rest_api_init', [ExternalCronController::class, 'register']);

		if (! class_exists('WooCommerce')) {
			add_action('admin_notices', [self::class, 'woocommerce_required_notice']);
			return;
		}

		// Read-only inventory/catalog sync may run as soon as a connection is configured.
		// Order writes and checkout allocation remain protected by the full capability gate.
		if (! CapabilityGate::is_passed() && ! ConnectionSettings::is_configured()) {
			return;
		}

		global $wpdb;
		if (! isset($wpdb)) {
			return;
		}

		$gateway = GatewayFactory::make();
		$full_capability_gate = CapabilityGate::is_passed();

		// The webhook boundary and read-side jobs can run with a configured connection.
		$events = new EventInboxRepository($wpdb);
		$webhook = new RestController($events);
		add_action('rest_api_init', [$webhook, 'register']);

		$operations = new SyncOperationRepository($wpdb);
		$event_worker = new SapoEventWorker($gateway, $events, $operations);
		add_action('woo_sapo_sync_process_event', [$event_worker, 'process'], 10, 1);
		add_action('pixelcam_sapo_sync_process_event', [$event_worker, 'process'], 10, 1);
		add_action(Scheduler::EVENT_SWEEP_HOOK, [$event_worker, 'requeue_due']);

		$inventory_job = new InventorySyncJob(new InventoryReconciler(
			$gateway,
			new ProductMappingRepository($wpdb),
			new ProductStockUpdater()
		));
		add_action(Scheduler::INVENTORY_HOOK, [$inventory_job, 'run']);
		add_action('pixelcam_sapo_sync_reconcile_inventory', [$inventory_job, 'run']);
		$mapping_job = new MappingSyncJob(new MappingSynchronizer(
			$gateway,
			new ProductMappingRepository($wpdb),
			new CatalogReader()
		));
		add_action(Scheduler::MAPPING_HOOK, [$mapping_job, 'run']);
		add_action('pixelcam_sapo_sync_reconcile_mapping', [$mapping_job, 'run']);

		if ($full_capability_gate) {
			$site_uuid = SiteIdentity::value();
			$snapshots = new OrderSnapshotBuilder();
			$coordinator = new OrderSyncCoordinator($operations);
			(new OrderSyncHooks($coordinator, $snapshots, $site_uuid))->register();

			$worker = new OrderSyncWorker(
				$gateway,
				$operations,
				new ProductMappingRepository($wpdb),
				$snapshots,
				$site_uuid
			);
			add_action(Queue::PROCESS_ORDER_HOOK, [$worker, 'process'], 10, 1);
			add_action('pixelcam_sapo_sync_process_order', [$worker, 'process'], 10, 1);

			(new CheckoutLocationAllocator(
				$gateway,
				new ProductMappingRepository($wpdb)
			))->register();
		}

		Scheduler::register();
	}

	public static function woocommerce_required_notice(): void
	{
		if (! current_user_can('activate_plugins')) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__('Inventory Bridge for Sapo cần WooCommerce được kích hoạt.', 'sapo-sync-for-woocommerce')
		);
	}
}
