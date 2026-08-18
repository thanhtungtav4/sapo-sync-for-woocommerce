<?php
/**
 * Woo order status hook that creates an idempotent outbox operation.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Application;

use WooSapoSync\Domain\Order\OrderSnapshotValidator;
use WooSapoSync\Infrastructure\WordPress\ActionScheduler\Queue;
use WooSapoSync\Infrastructure\WooCommerce\OrderSnapshotBuilder;

defined('ABSPATH') || exit;

final class OrderSyncHooks
{
	private static bool $registered = false;

	private OrderSyncCoordinator $coordinator;

	private OrderSnapshotBuilder $snapshots;

	private string $site_uuid;

	public function __construct(OrderSyncCoordinator $coordinator, OrderSnapshotBuilder $snapshots, string $site_uuid)
	{
		$this->coordinator = $coordinator;
		$this->snapshots = $snapshots;
		$this->site_uuid = $site_uuid;
	}

	public function register(): void
	{
		if (self::$registered || ! function_exists('add_action')) {
			return;
		}

		self::$registered = true;
		add_action('woocommerce_order_status_processing', [$this, 'on_processing'], 10, 1);
		add_action('woocommerce_order_status_cancelled', [$this, 'on_cancelled'], 10, 1);
	}

	public function on_cancelled(int $order_id): void
	{
		if (! function_exists('wc_get_order')) {
			return;
		}

		$order = wc_get_order($order_id);
		$sapo_order_id = is_object($order) && method_exists($order, 'get_meta')
			? trim((string) $order->get_meta('_woo_sapo_order_id', true))
			: '';
		if ('' === $sapo_order_id && is_object($order) && method_exists($order, 'get_meta')) {
			$sapo_order_id = trim((string) $order->get_meta('_pixelcam_sapo_order_id', true));
		}
		if (! is_object($order) || ! method_exists($order, 'get_meta') || '' === $sapo_order_id) {
			$this->coordinator->cancel_pending_create($order_id);
			$this->mark_order($order, 'CANCELLED', 'Đơn chưa có Sapo order nên không cần gửi yêu cầu hủy.');
			return;
		}

		$snapshot = $this->snapshots->build($order);
		$operation = $this->coordinator->enqueue_cancellation($order_id, $this->site_uuid, $snapshot);
		if (! is_array($operation) || empty($operation['id'])) {
			$this->mark_order($order, 'NEEDS_REVIEW', 'Không đưa được yêu cầu hủy Sapo vào hàng đợi.');
			return;
		}
		if (in_array((string) ($operation['status'] ?? ''), ['COMPLETED', 'NEEDS_REVIEW', 'CANCELLED'], true)) {
			$this->mark_order($order, (string) $operation['status'], 'Yêu cầu hủy Sapo đã có trạng thái cuối, không tạo lại.');
			return;
		}
		if (! Queue::enqueue_order((int) $operation['id'])) {
			$this->mark_order($order, 'NEEDS_REVIEW', 'Không đưa được yêu cầu hủy Sapo vào hàng đợi.');
			return;
		}

		$this->mark_order($order, 'CANCEL_PENDING', 'Đã đưa yêu cầu hủy đơn Sapo vào hàng đợi.');
	}

	public function on_processing(int $order_id): void
	{
		if (! function_exists('wc_get_order')) {
			return;
		}

		$order = wc_get_order($order_id);
		$snapshot = $this->snapshots->build($order);
		$errors = OrderSnapshotValidator::errors($snapshot);
		if ([] !== $errors) {
			$this->mark_order($order, 'NEEDS_REVIEW', 'Không đưa đơn vào hàng đợi Sapo: ' . implode(', ', $errors));
			return;
		}

		$operation = $this->coordinator->enqueue($order_id, $this->site_uuid, $snapshot);
		if (! is_array($operation) || empty($operation['id'])) {
			$this->mark_order($order, 'NEEDS_REVIEW', 'Không tạo được outbox operation Sapo.');
			return;
		}
		if (in_array((string) ($operation['status'] ?? ''), ['COMPLETED', 'NEEDS_REVIEW'], true)) {
			$this->mark_order($order, (string) $operation['status'], 'Sapo operation đã có trạng thái cuối, không tạo lại.');
			return;
		}

		if (! Queue::enqueue_order((int) $operation['id'])) {
			$this->mark_order($order, 'NEEDS_REVIEW', 'Không có Action Scheduler hoặc WP-Cron để xử lý outbox Sapo.');
			return;
		}

		$this->mark_order($order, 'PENDING', 'Đã đưa đơn vào hàng đợi đồng bộ Sapo.');
	}

	/**
	 * @param mixed $order
	 */
	private function mark_order($order, string $status, string $note): void
	{
		if (! is_object($order)) {
			return;
		}
		if (method_exists($order, 'update_meta_data')) {
			$order->update_meta_data('_woo_sapo_sync_status', $status);
		}
		if (method_exists($order, 'add_order_note')) {
			$order->add_order_note($note);
		}
		if (method_exists($order, 'save')) {
			$order->save();
		}
	}
}
