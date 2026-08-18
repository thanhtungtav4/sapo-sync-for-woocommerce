<?php
/**
 * Plugin database lifecycle.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Infrastructure\WordPress;

defined('ABSPATH') || exit;

final class Installer
{
	public const DB_VERSION = '1.2.0';
	public const DB_VERSION_OPTION = 'woo_sapo_sync_db_version';

	private function __construct()
	{
	}

	public static function activate(): void
	{
		self::migrate();
	}

	public static function deactivate(): void
	{
		Scheduler::unschedule();
	}

	public static function maybe_migrate(): void
	{
		self::migrate_legacy_installation();
		$current = (string) get_option(self::DB_VERSION_OPTION, '0.0.0');

		if (version_compare($current, self::DB_VERSION, '<')) {
			self::migrate();
		}
	}

	public static function migrate(): void
	{
		global $wpdb;
		self::migrate_legacy_installation();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$mapping_table = $wpdb->prefix . 'wss_sapo_product_mappings';
		$operations_table = $wpdb->prefix . 'wss_sapo_sync_operations';
		$events_table = $wpdb->prefix . 'wss_sapo_events';

		$sql = [];
		$sql[] = "CREATE TABLE {$mapping_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			woo_object_key varchar(191) NOT NULL,
			woo_product_id bigint(20) unsigned NOT NULL,
			woo_variation_id bigint(20) unsigned NULL,
			sku_raw varchar(191) NOT NULL,
			sku_match_key varchar(191) NOT NULL,
			sapo_product_id varchar(191) NULL,
			sapo_variant_id varchar(191) NULL,
			product_type varchar(32) NOT NULL DEFAULT 'SIMPLE',
			price_source varchar(16) NOT NULL DEFAULT 'WOO',
			sapo_price_list_id varchar(191) NULL,
			mapping_status varchar(32) NOT NULL DEFAULT 'NEEDS_REVIEW',
			last_verified_at datetime NULL,
			last_inventory_sync_at datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY woo_object_key (woo_object_key),
			UNIQUE KEY sapo_variant_id (sapo_variant_id),
			KEY sku_match_key (sku_match_key),
			KEY mapping_status (mapping_status)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$operations_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			operation_type varchar(64) NOT NULL,
			aggregate_type varchar(64) NOT NULL,
			aggregate_id varchar(191) NOT NULL,
			external_reference varchar(191) NOT NULL,
			request_hash char(64) NOT NULL,
			sapo_object_id varchar(191) NULL,
			status varchar(32) NOT NULL DEFAULT 'PENDING',
			attempt_count smallint(5) unsigned NOT NULL DEFAULT 0,
			next_attempt_at datetime NULL,
			processing_at datetime NULL,
			last_error_code varchar(64) NULL,
			last_error_message text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			completed_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY operation_reference (operation_type, external_reference),
			KEY status_next_attempt (status, next_attempt_at),
			KEY aggregate (aggregate_type, aggregate_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$events_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_key varchar(191) NOT NULL,
			event_type varchar(64) NOT NULL,
			remote_object_id varchar(191) NULL,
			remote_modified_at datetime NULL,
			payload_hash char(64) NOT NULL,
			status varchar(32) NOT NULL DEFAULT 'RECEIVED',
			attempt_count smallint(5) unsigned NOT NULL DEFAULT 0,
			next_attempt_at datetime NULL,
			processing_at datetime NULL,
			error_code varchar(64) NULL,
			error_message text NULL,
			received_at datetime NOT NULL,
			processed_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_key (event_key),
			KEY remote_object (event_type, remote_object_id),
			KEY status (status)
		) {$charset_collate};";

		foreach ($sql as $statement) {
			dbDelta($statement);
		}

		update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
	}

	private static function migrate_legacy_installation(): void
	{
		global $wpdb;
		$legacy_options = [
			'pixelcam_sapo_sync_connection' => 'woo_sapo_sync_connection',
			'pixelcam_sapo_sync_settings' => 'woo_sapo_sync_settings',
			'pixelcam_sapo_sync_capabilities' => 'woo_sapo_sync_capabilities',
			'pixelcam_sapo_order_contract_verified' => 'woo_sapo_order_contract_verified',
			'pixelcam_sapo_sync_webhook_secret' => 'woo_sapo_sync_webhook_secret',
			'pixelcam_sapo_sync_site_uuid' => 'woo_sapo_sync_site_uuid',
			'pixelcam_sapo_sync_location_policy' => 'woo_sapo_sync_location_policy',
		];
		foreach ($legacy_options as $legacy => $current) {
			if (false === get_option($current, false) && false !== get_option($legacy, false)) {
				update_option($current, get_option($legacy), false);
			}
		}

		$table_pairs = [
			$wpdb->prefix . 'pxc_sapo_product_mappings' => $wpdb->prefix . 'wss_sapo_product_mappings',
			$wpdb->prefix . 'pxc_sapo_sync_operations' => $wpdb->prefix . 'wss_sapo_sync_operations',
			$wpdb->prefix . 'pxc_sapo_events' => $wpdb->prefix . 'wss_sapo_events',
		];
		foreach ($table_pairs as $legacy => $current) {
			$legacy_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $legacy)) === $legacy;
			$current_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $current)) === $current;
			if ($legacy_exists && ! $current_exists) {
				$wpdb->query("RENAME TABLE `{$legacy}` TO `{$current}`");
			}
		}
	}
}
