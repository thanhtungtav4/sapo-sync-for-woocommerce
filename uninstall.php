<?php
/**
 * Removes data owned by Inventory Bridge for Sapo when the plugin is deleted.
 *
 * WooCommerce orders, customer records and remote Sapo data are intentionally
 * preserved. Only this plugin's options, operational tables and order metadata
 * are removed.
 *
 * @package WooSapoSync
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

/* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Deletion is limited to plugin-owned tables/options/meta during uninstall; identifiers are built from the trusted WordPress prefix and the meta-key placeholders are generated with matching arguments at runtime. */

$woo_sapo_option_names = [
	'woo_sapo_sync_connection',
	'woo_sapo_sync_settings',
	'woo_sapo_sync_capabilities',
	'woo_sapo_order_contract_verified',
	'woo_sapo_sync_webhook_secret',
	'woo_sapo_sync_webhook_token',
	'woo_sapo_sync_cron_secret',
	'woo_sapo_sync_site_uuid',
	'woo_sapo_sync_location_policy',
	'woo_sapo_sync_db_version',
	'woo_sapo_sync_external_mapping_last',
	'woo_sapo_sync_maintenance_last',
	'pixelcam_sapo_sync_connection',
	'pixelcam_sapo_sync_settings',
	'pixelcam_sapo_sync_capabilities',
	'pixelcam_sapo_order_contract_verified',
	'pixelcam_sapo_sync_webhook_secret',
	'pixelcam_sapo_sync_site_uuid',
	'pixelcam_sapo_sync_location_policy',
];

foreach ($woo_sapo_option_names as $woo_sapo_option_name) {
	delete_option($woo_sapo_option_name);
}

global $wpdb;

if (isset($wpdb) && is_object($wpdb)) {
	$woo_sapo_table_suffixes = [
		'wss_sapo_product_mappings',
		'wss_sapo_sync_operations',
		'wss_sapo_events',
		'pxc_sapo_product_mappings',
		'pxc_sapo_sync_operations',
		'pxc_sapo_events',
	];

	foreach ($woo_sapo_table_suffixes as $woo_sapo_table_suffix) {
		$woo_sapo_table_name = $wpdb->prefix . $woo_sapo_table_suffix;
		$wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $woo_sapo_table_name));
	}

	$woo_sapo_meta_keys = [
		'_woo_sapo_assigned_location',
		'_woo_sapo_location_error',
		'_woo_sapo_order_id',
		'_woo_sapo_sync_status',
		'_woo_sapo_cancel_status',
		'_woo_sapo_remote_modified_at',
		'_woo_sapo_tracking_number',
		'_pixelcam_sapo_order_id',
		'_pixelcam_sapo_remote_modified_at',
	];
	$woo_sapo_placeholders = implode(', ', array_fill(0, count($woo_sapo_meta_keys), '%s'));
	$wpdb->query($wpdb->prepare(
		"DELETE FROM %i WHERE meta_key IN ({$woo_sapo_placeholders})",
		$wpdb->postmeta,
		...$woo_sapo_meta_keys
	));
}
