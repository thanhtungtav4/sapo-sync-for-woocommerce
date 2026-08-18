<?php
/**
 * Applies a deduplicated Sapo event to its Woo aggregate.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Application;

use WooSapoSync\Contracts\SapoGateway;
use WooSapoSync\Domain\Order\OrderStateMapper;
use WooSapoSync\Infrastructure\Sapo\ErrorCode;
use WooSapoSync\Infrastructure\Sapo\Exception\SapoException;
use WooSapoSync\Domain\Sync\RetryPolicy;
use WooSapoSync\Infrastructure\WordPress\Repository\EventInboxRepository;
use WooSapoSync\Infrastructure\WordPress\Repository\SyncOperationRepository;
use WooSapoSync\Infrastructure\WordPress\SyncLogger;
use WooSapoSync\Infrastructure\WordPress\ActionScheduler\Queue;

defined('ABSPATH') || exit;

final class SapoEventWorker
{
	private SapoGateway $gateway;

	private EventInboxRepository $events;

	private SyncOperationRepository $operations;

	/**
	 * @var callable|null
	 */
	private $order_loader;

	/**
	 * @param callable|null $order_loader Receives a Woo order ID and returns a CRUD order object.
	 */
	public function __construct(SapoGateway $gateway, EventInboxRepository $events, SyncOperationRepository $operations, $order_loader = null)
	{
		$this->gateway = $gateway;
		$this->events = $events;
		$this->operations = $operations;
		$this->order_loader = $order_loader;
	}

	/**
	 * @param mixed $event_key Action Scheduler may pass named arguments as an array.
	 */
	public function process($event_key): void
	{
		if (is_array($event_key)) {
			$event_key = $event_key['event_key'] ?? '';
		}
		$event_key = trim((string) $event_key);
		if ('' === $event_key) {
			return;
		}

		$event = $this->events->find_by_key($event_key);
		if (! $event || 'PROCESSED' === ($event['status'] ?? '')) {
			return;
		}
		if (! $this->events->claim($event_key)) {
			return;
		}
		$event = $this->events->find_by_key($event_key) ?: $event;

		$event_type = strtolower((string) ($event['event_type'] ?? ''));
		$is_inventory_event = false !== strpos($event_type, 'inventory') || false !== strpos($event_type, 'stock');
		$is_catalog_event = $this->is_catalog_event($event_type);
		if ($is_inventory_event || $is_catalog_event) {
			$mapping_queued = ! $is_catalog_event || Queue::enqueue_mapping();
			$inventory_queued = ! ($is_inventory_event || $is_catalog_event) || Queue::enqueue_inventory();
			if ($inventory_queued && $mapping_queued) {
				$this->events->mark_processed($event_key);
				SyncLogger::log('info', 'Sapo realtime event queued for reconciliation.', [
					'event_key' => $event_key,
					'inventory_queued' => $inventory_queued,
					'mapping_queued' => $mapping_queued,
				]);
			} else {
				$this->retry_or_fail($event, ErrorCode::REMOTE_SERVER, 'Không có hàng đợi để chạy realtime reconciliation.');
			}
			return;
		}
		if (false === strpos($event_type, 'order')) {
			$this->events->mark_processed($event_key);
			return;
		}

		$remote_id = trim((string) ($event['remote_object_id'] ?? ''));
		if ('' === $remote_id) {
			$this->events->mark_failed($event_key, ErrorCode::VALIDATION, 'Sapo order event has no remote object ID.');
			return;
		}

		try {
			$operation = $this->operations->find_by_sapo_object_id($remote_id);
			if (! $operation) {
				$this->retry_or_fail($event, ErrorCode::NOT_FOUND, 'Woo operation for Sapo order was not found.');
				return;
			}

			$order = $this->load_order((int) ($operation['aggregate_id'] ?? 0));
			if (! is_object($order)) {
				$this->retry_or_fail($event, ErrorCode::NOT_FOUND, 'Woo order was not found.');
				return;
			}

			$modified_at = trim((string) ($event['remote_modified_at'] ?? ''));
			$previous_modified_at = method_exists($order, 'get_meta') ? (string) $order->get_meta('_woo_sapo_remote_modified_at', true) : '';
			if ('' === $previous_modified_at && method_exists($order, 'get_meta')) {
				$previous_modified_at = (string) $order->get_meta('_pixelcam_sapo_remote_modified_at', true);
			}
			if ($this->is_stale($modified_at, $previous_modified_at)) {
				$this->events->mark_processed($event_key);
				return;
			}

			$state = $this->gateway->get_order_state($remote_id);
			$woo_status = OrderStateMapper::to_woo_status($state);
			if (null !== $woo_status && method_exists($order, 'update_status')) {
				$order->update_status($woo_status, 'Cập nhật trạng thái từ Sapo.', true);
			}

			$tracking = $this->tracking_number($state);
			if ('' !== $tracking && method_exists($order, 'update_meta_data')) {
				$order->update_meta_data('_woo_sapo_tracking_number', $tracking);
			}
			if ('' !== $modified_at && method_exists($order, 'update_meta_data')) {
				$order->update_meta_data('_woo_sapo_remote_modified_at', $modified_at);
			}
			if (method_exists($order, 'save')) {
				$order->save();
			}

			$this->events->mark_processed($event_key);
			SyncLogger::log('info', 'Sapo order event processed.', ['event_key' => $event_key, 'remote_object_id' => $remote_id]);
		} catch (SapoException $exception) {
			SyncLogger::log('warning', 'Sapo event processing failed.', ['event_key' => $event_key, 'error_code' => $exception->error_code()]);
			$this->retry_or_fail($event, $exception->error_code(), $exception->getMessage());
		} catch (\Throwable $exception) {
			SyncLogger::log('error', 'Unexpected Sapo event worker failure.', ['event_key' => $event_key]);
			$this->retry_or_fail($event, ErrorCode::REMOTE_SERVER, 'Unexpected Sapo event worker failure.');
		}
	}

	public function requeue_due(): void
	{
		foreach ($this->events->due_keys(100) as $event_key) {
			Queue::enqueue_event($event_key);
		}
	}

	/**
	 * Retry transient events and delayed order aggregates. After the bounded
	 * retry budget, leave a durable FAILED row for operator review.
	 *
	 * @param array<string, mixed> $event
	 */
	private function retry_or_fail(array $event, string $error_code, string $message): void
	{
		$event_key = trim((string) ($event['event_key'] ?? ''));
		$attempt = max(1, (int) ($event['attempt_count'] ?? 1));
		$retryable = RetryPolicy::is_retryable($error_code) || ErrorCode::NOT_FOUND === $error_code;
		if ($retryable && $attempt <= 8) {
			$delay = RetryPolicy::next_delay($attempt);
			$next = gmdate('Y-m-d H:i:s', time() + $delay);
			$retry_saved = $this->events->mark_retry($event_key, $error_code, $message, $next);
			if ($retry_saved && Queue::enqueue_event_after($event_key, $delay)) {
				return;
			}
		}

		$this->events->mark_failed($event_key, $error_code, $message);
	}

	private function is_catalog_event(string $event_type): bool
	{
		foreach (['product', 'variant', 'store', 'location'] as $keyword) {
			if (false !== strpos($event_type, $keyword)) {
				return true;
			}
		}

		return false;
	}

	private function is_stale(string $incoming, string $previous): bool
	{
		if ('' === $incoming || '' === $previous) {
			return false;
		}

		$incoming_timestamp = strtotime($incoming);
		$previous_timestamp = strtotime($previous);
		return false !== $incoming_timestamp && false !== $previous_timestamp && $incoming_timestamp <= $previous_timestamp;
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function tracking_number(array $state): string
	{
		foreach (['tracking_number', 'tracking_code', 'waybill'] as $key) {
			if (isset($state[$key]) && is_scalar($state[$key]) && '' !== trim((string) $state[$key])) {
				return trim((string) $state[$key]);
			}
		}

		return '';
	}

	/**
	 * @return mixed
	 */
	private function load_order(int $order_id)
	{
		if (is_callable($this->order_loader)) {
			return call_user_func($this->order_loader, $order_id);
		}

		return function_exists('wc_get_order') ? wc_get_order($order_id) : null;
	}
}
