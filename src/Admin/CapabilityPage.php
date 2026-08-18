<?php
/**
 * Admin diagnostics page for the Sapo capability gate.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Admin;

use WooSapoSync\Application\CapabilityGate;
use WooSapoSync\Application\CapabilityVerifier;
use WooSapoSync\Application\GatewayFactory;
use WooSapoSync\Infrastructure\Sapo\SapoAdminGateway;
use WooSapoSync\Webhook\WebhookSignature;

defined('ABSPATH') || exit;

final class CapabilityPage
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
		add_action('admin_init', [Settings::class, 'register']);
		add_action('admin_post_woo_sapo_verify_capabilities', [self::class, 'verify_capabilities']);
		add_action('admin_post_woo_sapo_verify_order_contract', [self::class, 'verify_order_contract']);
	}

	public static function add_menu(): void
	{
		if (! function_exists('add_submenu_page')) {
			return;
		}

		add_submenu_page(
			'woocommerce',
			__('Sapo Sync', 'sapo-sync-for-woocommerce'),
			__('Sapo Sync', 'sapo-sync-for-woocommerce'),
			'manage_woocommerce',
			'sapo-sync-for-woocommerce',
			[self::class, 'render']
		);
	}

	public static function render(): void
	{
		if (! current_user_can('manage_woocommerce')) {
			wp_die(esc_html__('Bạn không có quyền xem trang này.', 'sapo-sync-for-woocommerce'));
		}

		$snapshot = CapabilityGate::snapshot();
		$passed = CapabilityGate::is_passed();
		$settings = get_option(Settings::OPTION_KEY, []);
		$settings = is_array($settings) ? $settings : [];
		$cron_mode = Settings::cron_mode();
		$cron_secret = Settings::cron_secret();
		$connection_configured = ConnectionSettings::is_configured();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__('Sapo Sync for WooCommerce', 'sapo-sync-for-woocommerce'); ?></h1>
			<?php /* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect flag; the mutating admin-post handler verifies its nonce. */ ?>
			<?php if (isset($_GET['woo_sapo_order_verified'])) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__('Order contract smoke test thành công: đã tạo và hủy order test, capability ghi đơn đã được mở cho connection hiện tại.', 'sapo-sync-for-woocommerce'); ?></p></div>
			<?php /* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect flag; the mutating admin-post handler verifies its nonce. */ ?>
			<?php elseif (isset($_GET['woo_sapo_order_verify_error'])) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html__('Order contract smoke test thất bại. Capability ghi đơn vẫn bị khóa; kiểm tra quyền Order và log Sapo.', 'sapo-sync-for-woocommerce'); ?></p></div>
			<?php endif; ?>
			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only diagnostic redirect flag; the mutating admin-post handler verifies its nonce.
			if (isset($_GET['woo_sapo_error_code'])) :
			?>
				<?php
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only diagnostic redirect flag; the mutating admin-post handler verifies its nonce.
				$error_code = sanitize_key((string) wp_unslash($_GET['woo_sapo_error_code']));
				$error_message = sprintf(
					/* translators: %s: Sanitized Sapo diagnostic error code. */
					__('Mã lỗi kết nối Sapo: %s. Đây là mã chẩn đoán, không phải credential.', 'sapo-sync-for-woocommerce'),
					$error_code
				);
				?>
				<div class="notice notice-warning is-dismissible"><p><?php echo esc_html($error_message); ?></p></div>
			<?php endif; ?>
			<div class="notice <?php echo $passed ? 'notice-success' : ($connection_configured ? 'notice-info' : 'notice-warning'); ?> inline">
				<p>
				<?php
				echo esc_html(
					$passed
						? __('Sẵn sàng đồng bộ production: capability Sapo đã được xác minh.', 'sapo-sync-for-woocommerce')
						: ($connection_configured
							? __('Đã lưu kết nối Sapo. Hãy kiểm tra quyền để bật các luồng đồng bộ phù hợp.', 'sapo-sync-for-woocommerce')
							: __('Chưa kết nối Sapo. Plugin chưa thực hiện đồng bộ nào; nhập credential bên dưới để bắt đầu.', 'sapo-sync-for-woocommerce'))
				);
				?>
				</p>
			</div>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="woo_sapo_verify_capabilities" />
				<?php wp_nonce_field('woo_sapo_verify_capabilities'); ?>
				<?php submit_button(__('Kiểm tra kết nối & quyền Sapo', 'sapo-sync-for-woocommerce'), 'primary', 'submit', false); ?>
			</form>
			<details style="max-width: 960px; margin: 16px 0;">
				<summary><strong><?php echo esc_html__('Contract test order (nâng cao)', 'sapo-sync-for-woocommerce'); ?></strong></summary>
				<p class="notice notice-warning inline"><strong><?php echo esc_html__('Chỉ dùng khi cần xác minh quyền ghi production.', 'sapo-sync-for-woocommerce'); ?></strong> <?php echo esc_html__('Plugin sẽ tạo rồi hủy một order test trên Sapo; không dùng trong giờ cao điểm.', 'sapo-sync-for-woocommerce'); ?></p>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="woo_sapo_verify_order_contract" />
					<?php wp_nonce_field('woo_sapo_verify_order_contract'); ?>
					<?php submit_button(__('Chạy contract test tạo + hủy order', 'sapo-sync-for-woocommerce'), 'secondary', 'submit', false); ?>
				</form>
				<p class="description"><?php echo esc_html__('Test dùng order test, tắt webhook/receipt và inventory_behaviour=bypass. Capability ghi đơn chỉ mở khi test hoàn tất.', 'sapo-sync-for-woocommerce'); ?></p>
			</details>
			<table class="widefat striped" style="max-width: 960px">
				<thead>
					<tr>
						<th><?php echo esc_html__('Capability', 'sapo-sync-for-woocommerce'); ?></th>
						<th><?php echo esc_html__('Trạng thái', 'sapo-sync-for-woocommerce'); ?></th>
						<th><?php echo esc_html__('Ghi chú', 'sapo-sync-for-woocommerce'); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ($snapshot as $capability => $state) : ?>
					<tr>
						<td><code><?php echo esc_html($capability); ?></code></td>
						<td>
							<?php echo esc_html(! empty($state['verified']) ? __('Đã xác minh', 'sapo-sync-for-woocommerce') : __('Chưa xác minh', 'sapo-sync-for-woocommerce')); ?>
						</td>
						<td><?php echo esc_html((string) ($state['notes'] ?? '')); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php $connection = ConnectionSettings::get(); ?>
			<h2><?php echo esc_html__('Cấu hình production', 'sapo-sync-for-woocommerce'); ?></h2>
			<form method="post" action="options.php" style="max-width: 960px">
				<?php settings_fields('woo_sapo_sync_settings_group'); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="sapo-sync-for-woocommerce-base-url"><?php echo esc_html__('Sapo base URL', 'sapo-sync-for-woocommerce'); ?></label></th>
						<td>
							<input id="sapo-sync-for-woocommerce-base-url" type="url" class="regular-text" name="<?php echo esc_attr(ConnectionSettings::OPTION_KEY); ?>[base_url]" value="<?php echo esc_attr((string) ($connection['base_url'] ?? '')); ?>" placeholder="https://store.mysapo.net" />
							<p class="description"><?php echo esc_html__('Chỉ nhận HTTPS; không nhúng API key/secret vào URL.', 'sapo-sync-for-woocommerce'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sapo-sync-for-woocommerce-auth-mode"><?php echo esc_html__('Kiểu xác thực', 'sapo-sync-for-woocommerce'); ?></label></th>
						<td>
							<select id="sapo-sync-for-woocommerce-auth-mode" name="<?php echo esc_attr(ConnectionSettings::OPTION_KEY); ?>[auth_mode]">
								<option value="basic" <?php selected((string) ($connection['auth_mode'] ?? 'basic'), 'basic'); ?>><?php echo esc_html__('Basic — API key/secret', 'sapo-sync-for-woocommerce'); ?></option>
								<option value="bearer" <?php selected((string) ($connection['auth_mode'] ?? 'basic'), 'bearer'); ?>><?php echo esc_html__('Bearer — access token', 'sapo-sync-for-woocommerce'); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__('Cách lấy token', 'sapo-sync-for-woocommerce'); ?></th>
						<td>
							<div id="woo-sapo-setup-guide" class="notice notice-info inline" style="margin: 0 0 12px;">
								<p><strong><?php echo esc_html__('Cách dễ nhất cho người không kỹ thuật: tạo Private App trên Sapo Web', 'sapo-sync-for-woocommerce'); ?></strong></p>
							</div>
							<details open>
								<summary><strong><?php echo esc_html__('Hướng dẫn từng bước (khoảng 5 phút)', 'sapo-sync-for-woocommerce'); ?></strong></summary>
								<ol>
									<li>
										<?php
										echo wp_kses_post(
											sprintf(
												/* translators: %s: Private Apps documentation URL. */
												__('Trong Sapo Web, mở <strong>Ứng dụng</strong> → “Bạn đang làm việc với nhà phát triển?” → <strong>Ứng dụng riêng</strong> → <strong>Tạo ứng dụng riêng</strong>. Nếu cần hình minh họa, mở <a href="%s" target="_blank" rel="noopener noreferrer">tài liệu Sapo</a>.', 'sapo-sync-for-woocommerce'),
												esc_url('https://support.sapo.vn/ung-dung-rieng-private-apps')
											)
										);
										?>
									</li>
									<li>
									<?php echo esc_html__('Đặt tên ứng dụng, ví dụ “Sapo Sync for WooCommerce”. Email liên hệ có thể để trống nếu Sapo cho phép.', 'sapo-sync-for-woocommerce'); ?>
									</li>
									<li>
										<?php echo esc_html__('Chọn quyền tối thiểu cho luồng đồng bộ hiện tại:', 'sapo-sync-for-woocommerce'); ?>
										<table class="widefat striped" style="max-width: 720px; margin: 8px 0;">
											<thead>
												<tr>
													<th><?php echo esc_html__('Nhóm dữ liệu', 'sapo-sync-for-woocommerce'); ?></th>
													<th><?php echo esc_html__('Quyền', 'sapo-sync-for-woocommerce'); ?></th>
													<th><?php echo esc_html__('Dùng để', 'sapo-sync-for-woocommerce'); ?></th>
												</tr>
											</thead>
											<tbody>
												<tr><td><?php echo esc_html__('Sản phẩm, phiên bản và danh mục', 'sapo-sync-for-woocommerce'); ?></td><td><code><?php echo esc_html__('Chỉ đọc', 'sapo-sync-for-woocommerce'); ?></code></td><td><?php echo esc_html__('Lấy sản phẩm/biến thể theo SKU', 'sapo-sync-for-woocommerce'); ?></td></tr>
												<tr><td><?php echo esc_html__('Kho', 'sapo-sync-for-woocommerce'); ?></td><td><code><?php echo esc_html__('Chỉ đọc', 'sapo-sync-for-woocommerce'); ?></code></td><td><?php echo esc_html__('Đọc tồn khả dụng theo location', 'sapo-sync-for-woocommerce'); ?></td></tr>
												<tr><td><?php echo esc_html__('Chi nhánh', 'sapo-sync-for-woocommerce'); ?></td><td><code><?php echo esc_html__('Chỉ đọc', 'sapo-sync-for-woocommerce'); ?></code></td><td><?php echo esc_html__('Lấy danh sách location và thứ tự ưu tiên', 'sapo-sync-for-woocommerce'); ?></td></tr>
												<tr><td><?php echo esc_html__('Khách hàng', 'sapo-sync-for-woocommerce'); ?></td><td><code><?php echo esc_html__('Đọc và ghi', 'sapo-sync-for-woocommerce'); ?></code></td><td><?php echo esc_html__('Tìm hoặc tạo khách khi đẩy đơn', 'sapo-sync-for-woocommerce'); ?></td></tr>
												<tr><td><?php echo esc_html__('Đơn hàng, giao dịch và vận chuyển', 'sapo-sync-for-woocommerce'); ?></td><td><code><?php echo esc_html__('Đọc và ghi', 'sapo-sync-for-woocommerce'); ?></code></td><td><?php echo esc_html__('Tạo, duyệt, đọc trạng thái và hủy đơn theo contract', 'sapo-sync-for-woocommerce'); ?></td></tr>
											</tbody>
										</table>
										<p class="description"><?php echo esc_html__('Các nhóm còn lại chọn “Không cho phép”. Không bật Storefront API cho plugin này.', 'sapo-sync-for-woocommerce'); ?></p>
									</li>
									<li><?php echo esc_html__('Bấm Lưu. Sapo sẽ hiển thị API key và API secret của ứng dụng.', 'sapo-sync-for-woocommerce'); ?></li>
									<li><?php echo esc_html__('Quay lại đây: nhập Sapo base URL dạng https://ten-shop.mysapo.net, chọn “Basic — API key/secret”, rồi dán API key và API secret. Không dán credential vào URL hoặc gửi qua chat.', 'sapo-sync-for-woocommerce'); ?></li>
									<li><?php echo esc_html__('Bấm Lưu cấu hình, sau đó bấm Chạy kiểm tra capability. Giữ chế độ Shadow cho tới khi contract test và đối soát đạt.', 'sapo-sync-for-woocommerce'); ?></li>
								</ol>
							</details>
							<details>
								<summary><strong><?php echo esc_html__('Đã tạo Private App rồi?', 'sapo-sync-for-woocommerce'); ?></strong></summary>
								<p><?php echo esc_html__('Vào Ứng dụng → Ứng dụng riêng → mở đúng ứng dụng → copy lại API key/secret. Khi đổi quyền, API key và API secret vẫn giữ nguyên; chỉ cần lưu lại cấu hình rồi chạy kiểm tra capability.', 'sapo-sync-for-woocommerce'); ?></p>
							</details>
							<details>
								<summary><strong><?php echo esc_html__('OAuth App (dành cho đội kỹ thuật)', 'sapo-sync-for-woocommerce'); ?></strong></summary>
								<p>
									<?php
									echo wp_kses_post(
										sprintf(
										/* translators: 1: OAuth documentation URL, 2: developer portal URL. */
										__('Tạo App trên <a href="%1$s" target="_blank" rel="noopener noreferrer">Sapo OAuth</a> hoặc <a href="%2$s" target="_blank" rel="noopener noreferrer">developers.sapo.vn</a>; cấp scope cần thiết, cài đặt vào shop rồi đổi authorization code lấy access token. Dán riêng giá trị token vào ô Bearer bên dưới, không thêm chữ <code>Bearer</code>.', 'sapo-sync-for-woocommerce'),
										esc_url('https://support.sapo.vn/oauth'),
										esc_url('https://developers.sapo.vn')
									)
								);
								?>
								</p>
							</details>
							<p class="description">
								<?php
								echo wp_kses_post(
									sprintf(
										/* translators: %s: Sapo Loyalty integration-token documentation URL. */
										__('Không dùng token của Sapo Loyalty cho sản phẩm, tồn kho hoặc đơn hàng; token đó chỉ dành cho API Loyalty. <a href="%s" target="_blank" rel="noopener noreferrer">Xem tài liệu</a>.', 'sapo-sync-for-woocommerce'),
										esc_url('https://help.sapo.vn/lich-su-hoat-dong-va-quan-ly-tich-hop-tren-ung-dung-sapo-loyalty-sapo-omniai')
									)
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sapo-sync-for-woocommerce-api-key"><?php echo esc_html__('API key', 'sapo-sync-for-woocommerce'); ?></label></th>
						<td><input id="sapo-sync-for-woocommerce-api-key" type="password" class="regular-text" autocomplete="new-password" name="<?php echo esc_attr(ConnectionSettings::OPTION_KEY); ?>[api_key]" value="" /><p class="description"><?php echo esc_html__('Để trống để giữ giá trị hiện tại; không hiển thị lại.', 'sapo-sync-for-woocommerce'); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="sapo-sync-for-woocommerce-api-secret"><?php echo esc_html__('API secret', 'sapo-sync-for-woocommerce'); ?></label></th>
						<td><input id="sapo-sync-for-woocommerce-api-secret" type="password" class="regular-text" autocomplete="new-password" name="<?php echo esc_attr(ConnectionSettings::OPTION_KEY); ?>[api_secret]" value="" /><p class="description"><?php echo esc_html__('Để trống để giữ giá trị hiện tại; không hiển thị lại.', 'sapo-sync-for-woocommerce'); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="sapo-sync-for-woocommerce-access-token"><?php echo esc_html__('Access token', 'sapo-sync-for-woocommerce'); ?></label></th>
						<td><input id="sapo-sync-for-woocommerce-access-token" type="password" class="regular-text" autocomplete="new-password" name="<?php echo esc_attr(ConnectionSettings::OPTION_KEY); ?>[access_token]" value="" /><p class="description"><?php echo esc_html__('Chỉ dùng cho Bearer/OAuth; dán token nguyên giá trị, không dán access token vào URL hoặc gửi qua chat. Để trống để giữ giá trị hiện tại.', 'sapo-sync-for-woocommerce'); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="sapo-sync-for-woocommerce-mode"><?php echo esc_html__('Chế độ tồn kho', 'sapo-sync-for-woocommerce'); ?></label></th>
						<td>
							<select id="sapo-sync-for-woocommerce-mode" name="<?php echo esc_attr(Settings::OPTION_KEY); ?>[sync_mode]">
								<option value="shadow" <?php selected((string) ($settings['sync_mode'] ?? 'shadow'), 'shadow'); ?>><?php echo esc_html__('Shadow — chỉ đối chiếu', 'sapo-sync-for-woocommerce'); ?></option>
								<option value="write" <?php selected((string) ($settings['sync_mode'] ?? 'shadow'), 'write'); ?>><?php echo esc_html__('Write — ghi tồn vào Woo', 'sapo-sync-for-woocommerce'); ?></option>
							</select>
							<p class="description"><?php echo esc_html__('Webhook product/store sẽ kích hoạt đồng bộ gần realtime; tồn kho vẫn polling mỗi phút để dự phòng. Giữ Shadow cho tới khi contract test và reconciliation đạt.', 'sapo-sync-for-woocommerce'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sapo-sync-for-woocommerce-cron-mode"><?php echo esc_html__('Cách chạy nền', 'sapo-sync-for-woocommerce'); ?></label></th>
						<td>
							<select id="sapo-sync-for-woocommerce-cron-mode" name="<?php echo esc_attr(Settings::OPTION_KEY); ?>[cron_mode]">
								<option value="automatic" <?php selected($cron_mode, Settings::CRON_MODE_AUTOMATIC); ?>><?php echo esc_html__('Automatic — Action Scheduler/WP-Cron', 'sapo-sync-for-woocommerce'); ?></option>
								<option value="external" <?php selected($cron_mode, Settings::CRON_MODE_EXTERNAL); ?>><?php echo esc_html__('External — chỉ chạy qua cron bên ngoài', 'sapo-sync-for-woocommerce'); ?></option>
								<option value="hybrid" <?php selected($cron_mode, Settings::CRON_MODE_HYBRID); ?>><?php echo esc_html__('Hybrid — tự động và cron bên ngoài', 'sapo-sync-for-woocommerce'); ?></option>
							</select>
							<p class="description"><?php echo esc_html__('External phù hợp khi WP-Cron bị tắt: cron server gọi endpoint có token bên dưới. Hybrid giữ lịch tự động làm dự phòng.', 'sapo-sync-for-woocommerce'); ?></p>
						</td>
					</tr>
					<?php if (Settings::external_cron_enabled()) : ?>
						<tr>
							<th scope="row"><label for="sapo-sync-for-woocommerce-cron-secret"><?php echo esc_html__('External cron token', 'sapo-sync-for-woocommerce'); ?></label></th>
							<td>
								<input id="sapo-sync-for-woocommerce-cron-secret" type="password" class="regular-text" autocomplete="new-password" name="<?php echo esc_attr(Settings::CRON_SECRET_OPTION); ?>" value="" />
								<p class="description"><?php echo esc_html__('Đặt một token riêng cho cron server. Để trống để giữ token hiện tại; không dùng lại Sapo API key hoặc webhook secret.', 'sapo-sync-for-woocommerce'); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__('Lệnh cron bên ngoài', 'sapo-sync-for-woocommerce'); ?></th>
							<td>
								<?php $cron_endpoint = function_exists('rest_url') ? rest_url('woo-sapo/v1/cron') : ''; ?>
								<?php if ('' !== $cron_secret && '' !== $cron_endpoint) : ?>
									<p><code>curl -fsS -X POST -H 'Authorization: Bearer YOUR_CRON_TOKEN' <?php echo esc_html($cron_endpoint); ?></code></p>
									<p class="description"><?php echo esc_html__('Thay YOUR_CRON_TOKEN bằng token đã lưu. Chạy mỗi phút để xử lý tồn, mapping, webhook inbox và queue đơn hàng.', 'sapo-sync-for-woocommerce'); ?></p>
								<?php else : ?>
									<p class="notice notice-warning inline"><strong><?php echo esc_html__('Chưa có lệnh chạy.', 'sapo-sync-for-woocommerce'); ?></strong> <?php echo esc_html__('Lưu External cron token trước để plugin tạo lệnh gọi an toàn.', 'sapo-sync-for-woocommerce'); ?></p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><label for="sapo-sync-for-woocommerce-secret"><?php echo esc_html__('Webhook secret', 'sapo-sync-for-woocommerce'); ?></label></th>
						<td>
							<input id="sapo-sync-for-woocommerce-secret" type="password" class="regular-text" autocomplete="new-password" name="<?php echo esc_attr('woo_sapo_sync_webhook_secret'); ?>" value="" />
							<p class="description"><?php echo esc_html__('Để trống để giữ secret hiện tại. Không hiển thị lại secret đã lưu.', 'sapo-sync-for-woocommerce'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__('Cấu hình realtime', 'sapo-sync-for-woocommerce'); ?></th>
						<td>
				<?php $webhook_endpoint = function_exists('rest_url') ? rest_url('woo-sapo/v1/webhook') : ''; ?>
				<?php $webhook_secret = WebhookSignature::secret(); ?>
				<?php if ('' !== $webhook_secret && function_exists('add_query_arg')) : ?>
					<?php $webhook_endpoint = add_query_arg('token', $webhook_secret, $webhook_endpoint); ?>
				<?php endif; ?>
							<p><?php echo esc_html__('Trong Sapo, tạo webhook JSON cho các topic products/create, products/update, products/delete và store/update. Các topic này chỉ kích hoạt đồng bộ; plugin vẫn đọc tồn mới từ API.', 'sapo-sync-for-woocommerce'); ?></p>
							<?php if ('' !== $webhook_endpoint) : ?>
								<p><strong><?php echo esc_html__('URL nhận webhook:', 'sapo-sync-for-woocommerce'); ?></strong> <code><?php echo esc_html($webhook_endpoint); ?></code></p>
							<?php endif; ?>
				<p class="description"><?php echo esc_html__('Nếu Sapo gửi HMAC, dùng secret ở ô trên để xác thực chữ ký. Nếu webhook không có HMAC, dùng đúng URL có token ở trên; không chia sẻ URL này công khai. Nếu Sapo không phát topic tồn kho, polling mỗi phút vẫn tự bù dữ liệu.', 'sapo-sync-for-woocommerce'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sapo-sync-for-woocommerce-location-policy"><?php echo esc_html__('Location policy JSON', 'sapo-sync-for-woocommerce'); ?></label></th>
						<td>
							<textarea id="sapo-sync-for-woocommerce-location-policy" class="large-text code" rows="6" name="<?php echo esc_attr(Settings::OPTION_KEY); ?>[location_policy_json]"><?php echo esc_textarea((string) ($settings['location_policy_json'] ?? '[]')); ?></textarea>
							<p class="description"><?php echo esc_html__('Ví dụ: [{"id":"941850","priority":0,"serves":true}]. ID chỉ là dữ liệu cấu hình, không hard-code trong plugin.', 'sapo-sync-for-woocommerce'); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(__('Lưu cấu hình', 'sapo-sync-for-woocommerce')); ?>
			</form>
			<p class="description">
				<?php echo esc_html__('Không đánh dấu thủ công capability. Hãy dùng hai nút kiểm tra ở trên; smoke test order sẽ tạo + hủy một order test rồi mới mở capability ghi.', 'sapo-sync-for-woocommerce'); ?>
			</p>
		</div>
		<?php
	}

	public static function verify_capabilities(): void
	{
		if (! current_user_can('manage_woocommerce')) {
			wp_die(esc_html__('Bạn không có quyền thực hiện kiểm tra.', 'sapo-sync-for-woocommerce'));
		}

		check_admin_referer('woo_sapo_verify_capabilities');
		$result = CapabilityVerifier::verify(GatewayFactory::make());
		$query = ['page' => 'sapo-sync-for-woocommerce', 'woo_sapo_verified' => '1'];
		if (! $result['connection_ok'] && '' !== $result['error_code']) {
			$query['woo_sapo_error_code'] = $result['error_code'];
		}
		$redirect = add_query_arg($query, admin_url('admin.php'));
		wp_safe_redirect($redirect);
		exit;
	}

	public static function verify_order_contract(): void
	{
		if (! current_user_can('manage_woocommerce')) {
			wp_die(esc_html__('Bạn không có quyền thực hiện smoke test order.', 'sapo-sync-for-woocommerce'));
		}

		check_admin_referer('woo_sapo_verify_order_contract');
		$gateway = GatewayFactory::make();
		$success = false;
		if ($gateway instanceof SapoAdminGateway) {
			try {
				$contract = $gateway->verify_order_contract();
				update_option(
					CapabilityGate::ORDER_CONTRACT_OPTION,
					[
						'verified' => true,
						'verified_at' => gmdate('Y-m-d H:i:s'),
						'capabilities' => (array) ($contract['capabilities'] ?? []),
						'notes' => 'Live create + cancel smoke test passed.',
					],
					false
				);
				$success = true;
			} catch (\Throwable $exception) {
				delete_option(CapabilityGate::ORDER_CONTRACT_OPTION);
			}
		}

		// Refresh the visible snapshot immediately; the next normal page load also
		// re-runs the read-side gate from the persisted connection.
		CapabilityVerifier::verify($gateway);
		$redirect = add_query_arg(
			[
				'page' => 'sapo-sync-for-woocommerce',
				$success ? 'woo_sapo_order_verified' : 'woo_sapo_order_verify_error' => '1',
			],
			admin_url('admin.php')
		);
		wp_safe_redirect($redirect);
		exit;
	}
}
