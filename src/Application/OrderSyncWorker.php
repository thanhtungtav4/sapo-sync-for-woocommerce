<?php
/**
 * Processes one Woo order outbox operation against the verified Sapo gateway.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Application;

use WooSapoSync\Contracts\SapoGateway;
use WooSapoSync\Domain\Customer\CustomerNormalizer;
use WooSapoSync\Domain\Order\OrderSnapshotValidator;
use WooSapoSync\Domain\Product\MappingStatus;
use WooSapoSync\Domain\Product\PriceSource;
use WooSapoSync\Domain\Sku\SkuNormalizer;
use WooSapoSync\Domain\Sync\OperationStatus;
use WooSapoSync\Domain\Sync\OperationType;
use WooSapoSync\Domain\Sync\RetryPolicy;
use WooSapoSync\Domain\ValueObjects\ExternalReference;
use WooSapoSync\Infrastructure\Sapo\ErrorCode;
use WooSapoSync\Infrastructure\Sapo\Exception\SapoException;
use WooSapoSync\Infrastructure\WordPress\Repository\ProductMappingRepository;
use WooSapoSync\Infrastructure\WordPress\Repository\SyncOperationRepository;
use WooSapoSync\Infrastructure\WordPress\SyncLogger;
use WooSapoSync\Infrastructure\WooCommerce\OrderSnapshotBuilder;
use WooSapoSync\Infrastructure\WordPress\ActionScheduler\Queue;

defined('ABSPATH') || exit;

/* phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages and context are not browser output. */

final class OrderSyncWorker
{
	private SapoGateway $gateway;

	private SyncOperationRepository $operations;

	private ProductMappingRepository $mappings;

	private OrderSnapshotBuilder $snapshots;

	private string $site_uuid;

	/**
	 * @var callable|null
	 */
	private $order_loader;

	/**
	 * @param callable|null $order_loader Receives a Woo order ID and returns a CRUD order object.
	 */
	public function __construct(
		SapoGateway $gateway,
		SyncOperationRepository $operations,
		ProductMappingRepository $mappings,
		OrderSnapshotBuilder $snapshots,
		string $site_uuid,
		$order_loader = null
	) {
		$this->gateway = $gateway;
		$this->operations = $operations;
		$this->mappings = $mappings;
		$this->snapshots = $snapshots;
		$this->site_uuid = $site_uuid;
		$this->order_loader = $order_loader;
	}

	/**
	 * @param mixed $operation_id Action Scheduler passes named arguments as an array
	 *                            while WP-Cron passes the scalar argument.
	 */
	public function process($operation_id): void
	{
		if (is_array($operation_id)) {
			$operation_id = $operation_id['operation_id'] ?? 0;
		}
		$operation_id = (int) $operation_id;
		if ($operation_id <= 0) {
			return;
		}

		$operation = $this->operations->find_by_id($operation_id);
		if (! $operation || in_array((string) ($operation['status'] ?? ''), [OperationStatus::COMPLETED, OperationStatus::NEEDS_REVIEW, OperationStatus::CANCELLED], true)) {
			return;
		}

		if (! $this->operations->mark_processing($operation_id)) {
			return;
		}

		try {
			if (OperationType::CANCEL_ORDER === (string) ($operation['operation_type'] ?? '')) {
				$this->process_cancellation($operation_id, $operation);
				return;
			}

			$order_id = (int) ($operation['aggregate_id'] ?? 0);
			$order = $this->load_order($order_id);
			$snapshot = $this->snapshots->build($order);
			$errors = OrderSnapshotValidator::errors($snapshot);
			if ([] !== $errors) {
				$this->needs_review($operation_id, ErrorCode::VALIDATION, implode(', ', $errors));
				return;
			}

			$reference = ExternalReference::for_woo_order($order_id, $this->site_uuid);
			$existing = $this->gateway->find_order_by_external_reference($reference);
			if (! is_array($existing)) {
				$existing = $this->gateway->find_order_by_external_reference(ExternalReference::legacy_for_woo_order($order_id, $this->site_uuid));
			}
			if (is_array($existing)) {
				$this->complete($operation_id, $order, $existing, $snapshot);
				return;
			}

			$command = $this->build_command($snapshot, $reference);
			$remote_order = $this->gateway->create_and_approve_order($command);
			$this->complete($operation_id, $order, $remote_order, $snapshot);
		} catch (SapoException $exception) {
			$this->handle_sapo_error($operation_id, $exception);
		} catch (\Throwable $exception) {
			$this->needs_review($operation_id, ErrorCode::REMOTE_SERVER, 'Unexpected sync worker failure.');
		}
	}

	/**
	 * @param array<string, mixed> $operation
	 */
	private function process_cancellation(int $operation_id, array $operation): void
	{
		$order_id = (int) ($operation['aggregate_id'] ?? 0);
		$order = $this->load_order($order_id);
		$sapo_id = is_object($order) && method_exists($order, 'get_meta')
			? trim((string) $order->get_meta('_woo_sapo_order_id', true))
			: '';
		if ('' === $sapo_id && is_object($order) && method_exists($order, 'get_meta')) {
			$sapo_id = trim((string) $order->get_meta('_pixelcam_sapo_order_id', true));
		}
		if ('' === $sapo_id) {
			$this->needs_review($operation_id, ErrorCode::NOT_FOUND, 'Woo order has no Sapo order ID to cancel.');
			return;
		}

		$state = $this->gateway->get_order_state($sapo_id);
		$order_state = strtolower((string) ($state['order'] ?? ''));
		$delivery_state = strtolower((string) ($state['delivery'] ?? ''));
		if (in_array($order_state, ['cancelled', 'canceled'], true)) {
			$this->complete_cancellation($operation_id, $order, $sapo_id);
			return;
		}
		if (in_array($delivery_state, ['packed', 'shipped', 'delivered', 'successful'], true)) {
			$this->needs_review($operation_id, ErrorCode::CONFLICT, 'Sapo order đã vào luồng giao hàng, không tự hủy.');
			return;
		}

		$this->gateway->cancel_order($sapo_id, 'WooCommerce order cancelled.');
		$this->complete_cancellation($operation_id, $order, $sapo_id);
	}

	/**
	 * @param mixed $order
	 */
	private function complete_cancellation(int $operation_id, $order, string $sapo_id): void
	{
		$this->operations->mark_completed($operation_id, $sapo_id);
		SyncLogger::log('info', 'Sapo order sync completed.', ['operation_id' => $operation_id, 'sapo_order_id' => $sapo_id]);
		if (is_object($order) && method_exists($order, 'update_meta_data')) {
			$order->update_meta_data('_woo_sapo_cancel_status', OperationStatus::COMPLETED);
			if (method_exists($order, 'add_order_note')) {
				$order->add_order_note('Đã gửi/xác nhận hủy đơn Sapo: ' . $sapo_id);
			}
			if (method_exists($order, 'save')) {
				$order->save();
			}
		}
	}

	/**
	 * @param mixed $order
	 * @return array<string, mixed>
	 */
	private function build_command(array $snapshot, ExternalReference $reference): array
	{
		$assigned_location_id = trim((string) ($snapshot['assigned_location_id'] ?? ''));
		if ('' === $assigned_location_id) {
			throw new SapoException(ErrorCode::VALIDATION, 'Order has no assigned Sapo location.');
		}

		$lines = [];
		foreach ((array) $snapshot['lines'] as $line) {
			$sku = SkuNormalizer::match_key((string) ($line['sku'] ?? ''));
			$matches = $this->mappings->find_by_sku($sku);
			$active = array_values(array_filter($matches, static function (array $mapping) use ($line): bool {
				return MappingStatus::ACTIVE === ($mapping['mapping_status'] ?? '')
					&& (string) ($mapping['product_type'] ?? '') === (string) ($line['product_type'] ?? '');
			}));

			if (1 !== count($active)) {
				throw new SapoException(ErrorCode::VALIDATION, 'Order line has no unique active SKU mapping.', ['sku' => $sku]);
			}

			$mapping = $active[0];
			$quantity = (float) $line['quantity'];
			$unit_price = $quantity > 0 ? (float) ($line['subtotal'] ?? $line['total']) / $quantity : 0.0;
			if (! empty($line['is_gift'])) {
				$unit_price = 0.0;
			} elseif (PriceSource::SAPO === ($mapping['price_source'] ?? PriceSource::WOO)) {
				$prices = $this->gateway->get_prices(
					[(string) $mapping['sapo_variant_id']],
					! empty($mapping['sapo_price_list_id']) ? (string) $mapping['sapo_price_list_id'] : null
				);
				$price = $this->price_for_variant($prices, (string) $mapping['sapo_variant_id']);
				if (null === $price) {
					throw new SapoException(ErrorCode::VALIDATION, 'Sapo price is missing for mapped variant.');
				}
				$unit_price = $price;
			}

			$lines[] = [
				'sapo_product_id' => (string) ($mapping['sapo_product_id'] ?? ''),
				'sapo_variant_id' => (string) ($mapping['sapo_variant_id'] ?? ''),
				'sku' => $sku,
				'quantity' => $quantity,
				'unit_price' => $unit_price,
				'line_total' => (float) $line['total'],
				'is_gift' => ! empty($line['is_gift']),
			];
		}
		$this->assert_available_at_location($lines, $assigned_location_id);

		return [
			'external_reference' => $reference->value(),
			'woo_order_id' => (int) $snapshot['woo_order_id'],
			'currency' => (string) ($snapshot['currency'] ?? ''),
			'assigned_location_id' => $assigned_location_id,
			'payment_method' => (string) ($snapshot['payment_method'] ?? ''),
				'is_paid' => ! empty($snapshot['is_paid']),
			'total' => (float) $snapshot['total'],
			'discount_total' => (float) $snapshot['discount_total'],
			'discount_codes' => (array) ($snapshot['discount_codes'] ?? []),
			'shipping_total' => (float) $snapshot['shipping_total'],
			'tax_total' => (float) $snapshot['tax_total'],
			'customer' => $this->resolve_customer((array) ($snapshot['customer'] ?? [])),
			'billing_address' => (array) ($snapshot['billing_address'] ?? []),
			'shipping_address' => (array) ($snapshot['shipping_address'] ?? []),
			'lines' => $lines,
		];
	}

	/**
	 * Re-check the assigned branch after checkout to prevent creating an order on stale stock.
	 *
	 * @param array<int, array<string, mixed>> $lines
	 */
	private function assert_available_at_location(array $lines, string $location_id): void
	{
		$required = [];
		foreach ($lines as $line) {
			$variant_id = (string) $line['sapo_variant_id'];
			$required[$variant_id] = ($required[$variant_id] ?? 0.0) + (float) $line['quantity'];
		}
		$variant_ids = array_keys($required);
		$availability = [];
		foreach ((array) $this->gateway->get_availability($variant_ids, [$location_id]) as $row) {
			if (! is_array($row)) {
				continue;
			}
			$key = (string) ($row['location_id'] ?? '') . ':' . (string) ($row['variant_id'] ?? '');
			$availability[$key] = (float) ($row['available'] ?? 0);
		}

		foreach ($required as $variant_id => $quantity) {
			$key = $location_id . ':' . (string) $variant_id;
			if (($availability[$key] ?? 0.0) < (float) $quantity) {
				throw new SapoException(ErrorCode::CONFLICT, 'Tồn Sapo tại chi nhánh đã thay đổi, không tạo đơn thiếu hàng.', [
					'location_id' => $location_id,
					'variant_id' => (string) $variant_id,
				]);
			}
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $prices
	 */
	private function price_for_variant(array $prices, string $variant_id): ?float
	{
		foreach ($prices as $price) {
			if ((string) ($price['variant_id'] ?? '') === $variant_id && isset($price['price']) && is_numeric($price['price'])) {
				return (float) $price['price'];
			}
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $customer
	 * @return array<string, mixed>
	 */
	private function resolve_customer(array $customer): array
	{
		$phone = CustomerNormalizer::phone((string) ($customer['phone'] ?? ''));
		$email = CustomerNormalizer::email((string) ($customer['email'] ?? ''));
		$remote = null;
		if ('' !== $phone) {
			$remote = $this->gateway->find_customer(['phone' => $phone]);
		}
		if (! is_array($remote) && '' !== $email) {
			$remote = $this->gateway->find_customer(['email' => $email]);
		}
		if (is_array($remote)) {
			return $remote;
		}
		if ('' === $phone && '' === $email) {
			throw new SapoException(ErrorCode::VALIDATION, 'Order has no customer phone or email.');
		}

		$create = array_filter([
			'phone' => $phone,
			'email' => $email,
			'first_name' => (string) ($customer['first_name'] ?? ''),
			'last_name' => (string) ($customer['last_name'] ?? ''),
			'address_1' => (string) ($customer['address_1'] ?? ''),
			'address_2' => (string) ($customer['address_2'] ?? ''),
			'city' => (string) ($customer['city'] ?? ''),
			'state' => (string) ($customer['state'] ?? ''),
			'postcode' => (string) ($customer['postcode'] ?? ''),
			'country' => (string) ($customer['country'] ?? ''),
		], static fn ($value): bool => '' !== trim((string) $value));

		return $this->gateway->create_customer($create);
	}

	/**
	 * @param mixed $order
	 * @param array<string, mixed> $remote_order
	 */
	private function complete(int $operation_id, $order, array $remote_order, array $snapshot = []): void
	{
		$sapo_id = $this->remote_id($remote_order);
		if ('' === $sapo_id) {
			$this->needs_review($operation_id, ErrorCode::VALIDATION, 'Sapo order response has no stable ID.');
			return;
		}
		$remote_currency = trim((string) ($remote_order['currency'] ?? ''));
		$expected_currency = trim((string) ($snapshot['currency'] ?? ''));
		$remote_total = $remote_order['total_price'] ?? null;
		$expected_total = $snapshot['total'] ?? null;
		if ('' !== $remote_currency && '' !== $expected_currency && 0 !== strcasecmp($remote_currency, $expected_currency)) {
			$this->needs_review($operation_id, ErrorCode::CONFLICT, 'Currency Sapo không khớp currency WooCommerce.');
			return;
		}
		if (is_numeric($remote_total) && is_numeric($expected_total) && abs((float) $remote_total - (float) $expected_total) > 0.01) {
			$this->needs_review($operation_id, ErrorCode::CONFLICT, 'Tổng tiền Sapo không khớp tổng tiền WooCommerce.');
			return;
		}

		$this->operations->mark_completed($operation_id, $sapo_id);
		if (is_object($order) && method_exists($order, 'update_meta_data')) {
			$order->update_meta_data('_woo_sapo_order_id', $sapo_id);
			$order->update_meta_data('_woo_sapo_sync_status', OperationStatus::COMPLETED);
			if (method_exists($order, 'add_order_note')) {
				$order->add_order_note('Đồng bộ Sapo thành công: ' . $sapo_id);
			}
			if (method_exists($order, 'save')) {
				$order->save();
			}
		}
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

	private function handle_sapo_error(int $operation_id, SapoException $exception): void
	{
		$operation = $this->operations->find_by_id($operation_id);
		// mark_processing increments the attempt before the remote call. Do not
		// add one again or the first retry would incorrectly start at backoff #2.
		$attempt = max(1, (int) ($operation['attempt_count'] ?? 1));
		if (RetryPolicy::is_retryable($exception->error_code()) && $attempt <= 8) {
			$delay = RetryPolicy::next_delay($attempt);
			$next = gmdate('Y-m-d H:i:s', time() + $delay);
			$this->operations->mark_failed($operation_id, OperationStatus::RETRY, $exception->error_code(), $exception->getMessage(), $next);
			if (! Queue::enqueue_order_after($operation_id, $delay)) {
				$this->needs_review($operation_id, ErrorCode::REMOTE_SERVER, 'Không thể lên lịch retry operation Sapo.');
			}
			return;
		}

		$this->needs_review($operation_id, $exception->error_code(), $exception->getMessage());
	}

	private function needs_review(int $operation_id, string $error_code, string $message): void
	{
		SyncLogger::log('warning', 'Sapo order operation needs review.', ['operation_id' => $operation_id, 'error_code' => $error_code]);
		$this->operations->mark_failed($operation_id, OperationStatus::NEEDS_REVIEW, $error_code, $message);
	}

	/**
	 * @param array<string, mixed> $remote_order
	 */
	private function remote_id(array $remote_order): string
	{
		foreach (['id', 'order_id', 'sapo_order_id'] as $key) {
			if (isset($remote_order[$key]) && is_scalar($remote_order[$key]) && '' !== trim((string) $remote_order[$key])) {
				return trim((string) $remote_order[$key]);
			}
		}

		return '';
	}
}
