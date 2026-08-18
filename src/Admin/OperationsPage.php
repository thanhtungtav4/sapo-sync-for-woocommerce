<?php
/**
 * Operational dashboard for mappings, outbox and event inbox.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Admin;

defined('ABSPATH') || exit;

/* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- This read-only dashboard queries plugin-owned custom tables; table identifiers are derived from $wpdb->prefix and cannot be placeholders. */

final class OperationsPage
{
	private static bool $registered = false;

	private function __construct()
	{
	}

	public static function register(): void
	{
		if (self::$registered || ! function_exists('add_action')) {
			return;
		}
		self::$registered = true;
		add_action('admin_menu', [self::class, 'add_menu']);
	}

	public static function add_menu(): void
	{
		if (! function_exists('add_submenu_page')) {
			return;
		}
		add_submenu_page(
			'woocommerce',
			__('Sapo Sync Operations', 'sapo-sync-for-woocommerce'),
			__('Sapo Operations', 'sapo-sync-for-woocommerce'),
			'manage_woocommerce',
			'sapo-sync-for-woocommerce-operations',
			[self::class, 'render']
		);
	}

	public static function render(): void
	{
		if (! current_user_can('manage_woocommerce')) {
			wp_die(esc_html__('Bạn không có quyền xem trang này.', 'sapo-sync-for-woocommerce'));
		}

		global $wpdb;
		$operations_table = $wpdb->prefix . 'wss_sapo_sync_operations';
		$events_table = $wpdb->prefix . 'wss_sapo_events';
		$mappings_table = $wpdb->prefix . 'wss_sapo_product_mappings';
		$operation_counts = $wpdb->get_results("SELECT status, COUNT(*) AS total FROM {$operations_table} GROUP BY status ORDER BY status", ARRAY_A);
		$event_counts = $wpdb->get_results("SELECT status, COUNT(*) AS total FROM {$events_table} GROUP BY status ORDER BY status", ARRAY_A);
		$mapping_counts = $wpdb->get_results("SELECT mapping_status, COUNT(*) AS total FROM {$mappings_table} GROUP BY mapping_status ORDER BY mapping_status", ARRAY_A);
		$recent_operations = $wpdb->get_results("SELECT operation_type, aggregate_id, status, attempt_count, last_error_code, updated_at FROM {$operations_table} ORDER BY id DESC LIMIT 25", ARRAY_A);
		?>
		<div class="wrap">
			<h1><?php echo esc_html__('Sapo Sync Operations', 'sapo-sync-for-woocommerce'); ?></h1>
			<p><?php echo esc_html__('Dashboard chỉ hiển thị trạng thái/metadata vận hành; payload webhook và credentials không được hiển thị.', 'sapo-sync-for-woocommerce'); ?></p>
			<?php self::render_counts(__('Outbox operations', 'sapo-sync-for-woocommerce'), $operation_counts, 'status'); ?>
			<?php self::render_counts(__('Event inbox', 'sapo-sync-for-woocommerce'), $event_counts, 'status'); ?>
			<?php self::render_counts(__('Product mappings', 'sapo-sync-for-woocommerce'), $mapping_counts, 'mapping_status'); ?>
			<h2><?php echo esc_html__('Recent operations', 'sapo-sync-for-woocommerce'); ?></h2>
			<table class="widefat striped" style="max-width: 1100px">
				<thead><tr><th><?php echo esc_html__('Type', 'sapo-sync-for-woocommerce'); ?></th><th><?php echo esc_html__('Aggregate', 'sapo-sync-for-woocommerce'); ?></th><th><?php echo esc_html__('Status', 'sapo-sync-for-woocommerce'); ?></th><th><?php echo esc_html__('Attempts', 'sapo-sync-for-woocommerce'); ?></th><th><?php echo esc_html__('Error', 'sapo-sync-for-woocommerce'); ?></th><th><?php echo esc_html__('Updated', 'sapo-sync-for-woocommerce'); ?></th></tr></thead>
				<tbody>
				<?php foreach ((array) $recent_operations as $operation) : ?>
					<tr>
						<td><code><?php echo esc_html((string) $operation['operation_type']); ?></code></td>
						<td><?php echo esc_html((string) $operation['aggregate_id']); ?></td>
						<td><?php echo esc_html((string) $operation['status']); ?></td>
						<td><?php echo esc_html((string) $operation['attempt_count']); ?></td>
						<td><?php echo esc_html((string) $operation['last_error_code']); ?></td>
						<td><?php echo esc_html((string) $operation['updated_at']); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * @param array<int, array<string, mixed>>|null $counts
	 */
	private static function render_counts(string $title, $counts, string $key): void
	{
		?>
		<h2><?php echo esc_html($title); ?></h2>
		<table class="widefat striped" style="max-width: 520px">
			<thead><tr><th><?php echo esc_html__('Status', 'sapo-sync-for-woocommerce'); ?></th><th><?php echo esc_html__('Count', 'sapo-sync-for-woocommerce'); ?></th></tr></thead>
			<tbody>
			<?php foreach ((array) $counts as $row) : ?>
				<tr><td><code><?php echo esc_html((string) ($row[$key] ?? '')); ?></code></td><td><?php echo esc_html((string) ($row['total'] ?? 0)); ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
