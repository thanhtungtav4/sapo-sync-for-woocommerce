<?php
/**
 * Mapping search and operator-confirmed manual linking screen.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Admin;

use WooSapoSync\Application\GatewayFactory;
use WooSapoSync\Domain\Product\MappingStatus;
use WooSapoSync\Domain\Sku\SkuNormalizer;
use WooSapoSync\Contracts\SapoGateway;
use WooSapoSync\Infrastructure\WordPress\Repository\ProductMappingRepository;

defined('ABSPATH') || exit;

final class MappingPage
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
		add_action('admin_post_woo_sapo_manual_mapping', [self::class, 'manual_mapping']);
	}

	public static function add_menu(): void
	{
		if (! function_exists('add_submenu_page')) {
			return;
		}

		add_submenu_page(
			'woocommerce',
			__('Sapo Product Mappings', 'sapo-sync-for-woocommerce'),
			__('Sapo Mappings', 'sapo-sync-for-woocommerce'),
			'manage_woocommerce',
			'sapo-sync-for-woocommerce-mappings',
			[self::class, 'render']
		);
	}

	public static function render(): void
	{
		if (! current_user_can('manage_woocommerce')) {
			wp_die(esc_html__('Bạn không có quyền xem mapping.', 'sapo-sync-for-woocommerce'));
		}

		global $wpdb;
		$repository = new ProductMappingRepository($wpdb);
		/* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter; the mapping mutation below uses a nonce-protected POST. */
		$search = isset($_GET['s']) ? sanitize_text_field(wp_unslash((string) $_GET['s'])) : '';
		/* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter; the mapping mutation below uses a nonce-protected POST. */
		$status = isset($_GET['mapping_status']) ? sanitize_key((string) $_GET['mapping_status']) : '';
		/* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination value; the mapping mutation below uses a nonce-protected POST. */
		$page = max(1, absint($_GET['paged'] ?? 1));
		$per_page = 50;
		$total = $repository->count($search, $status);
		$rows = $repository->find_page($search, $status, $per_page, ($page - 1) * $per_page);
		$statuses = [MappingStatus::ACTIVE, MappingStatus::NEEDS_REVIEW, MappingStatus::MISSING, MappingStatus::DISABLED];
		?>
		<div class="wrap">
			<h1><?php echo esc_html__('Sapo Product Mappings', 'sapo-sync-for-woocommerce'); ?></h1>
			<p><?php echo esc_html__('Tìm theo SKU hoặc ID. Liên kết thủ công chỉ chuyển ACTIVE sau khi người vận hành đã xác minh Sapo product/variant ID.', 'sapo-sync-for-woocommerce'); ?></p>
			<form method="get">
				<input type="hidden" name="page" value="sapo-sync-for-woocommerce-mappings" />
				<input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="SKU hoặc ID" />
				<select name="mapping_status">
					<option value=""><?php echo esc_html__('Tất cả trạng thái', 'sapo-sync-for-woocommerce'); ?></option>
					<?php foreach ($statuses as $value) : ?>
						<option value="<?php echo esc_attr($value); ?>" <?php selected($status, $value); ?>><?php echo esc_html($value); ?></option>
					<?php endforeach; ?>
				</select>
				<?php submit_button(__('Lọc', 'sapo-sync-for-woocommerce'), 'secondary', '', false); ?>
			</form>
		<?php /* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect flag; the mutating admin-post handler verifies its nonce. */ ?>
		<?php if (isset($_GET['mapping_saved'])) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html__('Mapping đã được cập nhật.', 'sapo-sync-for-woocommerce'); ?></p></div>
		<?php /* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect flag; the mutating admin-post handler verifies its nonce. */ ?>
		<?php elseif (isset($_GET['mapping_error'])) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html__('Không thể cập nhật mapping; kiểm tra ID hoặc mapping trùng.', 'sapo-sync-for-woocommerce'); ?></p></div>
			<?php endif; ?>
			<?php /* translators: %d: number of product mappings. */ ?>
			<p><?php echo esc_html(sprintf(__('Tổng: %d mapping', 'sapo-sync-for-woocommerce'), $total)); ?></p>
			<table class="widefat striped">
				<thead><tr><th>ID</th><th>Woo object</th><th>SKU</th><th>Sapo product</th><th>Sapo variant</th><th>Type</th><th>Status</th><th><?php echo esc_html__('Liên kết thủ công', 'sapo-sync-for-woocommerce'); ?></th></tr></thead>
				<tbody>
				<?php foreach ($rows as $row) : ?>
					<tr>
						<td><?php echo esc_html((string) ($row['id'] ?? '')); ?></td>
						<td><code><?php echo esc_html((string) ($row['woo_object_key'] ?? '')); ?></code></td>
						<td><code><?php echo esc_html((string) ($row['sku_raw'] ?? '')); ?></code></td>
						<td><?php echo esc_html((string) ($row['sapo_product_id'] ?? '')); ?></td>
						<td><?php echo esc_html((string) ($row['sapo_variant_id'] ?? '')); ?></td>
						<td><?php echo esc_html((string) ($row['product_type'] ?? '')); ?></td>
						<td><code><?php echo esc_html((string) ($row['mapping_status'] ?? '')); ?></code></td>
						<td>
							<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
								<input type="hidden" name="action" value="woo_sapo_manual_mapping" />
								<input type="hidden" name="mapping_id" value="<?php echo esc_attr((string) ($row['id'] ?? '')); ?>" />
								<?php wp_nonce_field('woo_sapo_manual_mapping_' . (int) ($row['id'] ?? 0)); ?>
								<input type="text" name="sapo_product_id" value="<?php echo esc_attr((string) ($row['sapo_product_id'] ?? '')); ?>" placeholder="product ID" size="14" />
								<input type="text" name="sapo_variant_id" value="<?php echo esc_attr((string) ($row['sapo_variant_id'] ?? '')); ?>" placeholder="variant ID" size="14" />
								<?php submit_button(__('Lưu', 'sapo-sync-for-woocommerce'), 'small', '', false); ?>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				<?php if ([] === $rows) : ?><tr><td colspan="8"><?php echo esc_html__('Chưa có mapping phù hợp.', 'sapo-sync-for-woocommerce'); ?></td></tr><?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public static function manual_mapping(): void
	{
		if (! current_user_can('manage_woocommerce')) {
			wp_die(esc_html__('Bạn không có quyền cập nhật mapping.', 'sapo-sync-for-woocommerce'));
		}

		$id = absint($_POST['mapping_id'] ?? 0);
		check_admin_referer('woo_sapo_manual_mapping_' . $id);
		global $wpdb;
		$repository = new ProductMappingRepository($wpdb);
		$sapo_product_id = sanitize_text_field(wp_unslash((string) ($_POST['sapo_product_id'] ?? '')));
		$sapo_variant_id = sanitize_text_field(wp_unslash((string) ($_POST['sapo_variant_id'] ?? '')));
		$mapping = $repository->find_by_id($id);
		$remote = null;
		try {
			$remote = self::find_remote_variant(GatewayFactory::make(), $sapo_product_id, $sapo_variant_id);
		} catch (\Throwable $exception) {
			$remote = null;
		}
		if (! is_array($mapping) || ! is_array($remote)
			|| (string) ($mapping['product_type'] ?? '') !== (string) ($remote['product_type'] ?? '')
			|| SkuNormalizer::match_key((string) ($mapping['sku_match_key'] ?? '')) !== SkuNormalizer::match_key((string) ($remote['sku'] ?? ''))) {
			$redirect = add_query_arg(
				['page' => 'sapo-sync-for-woocommerce-mappings', 'mapping_error' => '1'],
				admin_url('admin.php')
			);
			wp_safe_redirect($redirect);
			exit;
		}
		$saved = $repository->manual_link(
			$id,
			$sapo_product_id,
			$sapo_variant_id
		);
		$notice_key = $saved ? 'mapping_saved' : 'mapping_error';
		$redirect = add_query_arg(
			[
				'page' => 'sapo-sync-for-woocommerce-mappings',
				$notice_key => '1',
			],
			admin_url('admin.php')
		);
		wp_safe_redirect($redirect);
		exit;
	}

	/**
	 * Validate the remote IDs and SKU/type before allowing an ACTIVE manual link.
	 * This is intentionally bounded to avoid an unbounded admin request.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function find_remote_variant(SapoGateway $gateway, string $product_id, string $variant_id): ?array
	{
		$cursor = null;
		$seen = [];
		for ($page = 0; $page < 100; $page++) {
			$response = $gateway->list_variants($cursor);
			foreach ((array) ($response['items'] ?? []) as $item) {
				if (! is_array($item)) {
					continue;
				}
				if ((string) ($item['variant_id'] ?? '') === $variant_id
					&& (string) ($item['product_id'] ?? '') === $product_id) {
					return $item;
				}
			}
			$next = isset($response['next_cursor']) && null !== $response['next_cursor'] ? (string) $response['next_cursor'] : '';
			if ('' === $next || isset($seen[$next])) {
				break;
			}
			$seen[$next] = true;
			$cursor = $next;
		}

		return null;
	}
}
