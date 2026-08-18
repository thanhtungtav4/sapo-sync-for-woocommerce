<?php
/**
 * Sapo Admin API gateway for Private Apps/OAuth credentials.
 *
 * The gateway implements the verified read path and public customer/order
 * lookup primitives. Omni/POS create-and-approve remains fail-closed until
 * the account-specific write contract is captured and enabled.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Infrastructure\Sapo;

use WooSapoSync\Application\CapabilityGate;
use WooSapoSync\Contracts\SapoGateway;
use WooSapoSync\Domain\Customer\CustomerNormalizer;
use WooSapoSync\Domain\Product\ProductType;
use WooSapoSync\Domain\ValueObjects\ExternalReference;
use WooSapoSync\Infrastructure\Sapo\Exception\UnsupportedCapabilityException;
use WooSapoSync\Infrastructure\Sapo\Http\HttpTransport;
use WooSapoSync\Infrastructure\Sapo\Http\WordPressHttpTransport;

defined('ABSPATH') || exit;

/* phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages and context are not browser output. */

final class SapoAdminGateway implements SapoGateway
{
	private string $base_url;

	private string $auth_mode;

	private string $api_key;

	private string $api_secret;

	private string $access_token;

	private HttpTransport $transport;

	/** @var array<string, array<string, mixed>> */
	private array $variant_cache = [];

	private ?CapabilitySnapshot $capability_snapshot = null;

	public function __construct(
		string $base_url,
		string $auth_mode,
		string $api_key = '',
		string $api_secret = '',
		string $access_token = '',
		?HttpTransport $transport = null
	) {
		$this->base_url = rtrim($base_url, '/');
		$this->auth_mode = 'bearer' === $auth_mode ? 'bearer' : 'basic';
		$this->api_key = trim($api_key);
		$this->api_secret = trim($api_secret);
		$this->access_token = trim($access_token);
		$this->transport = $transport ?: new WordPressHttpTransport();
	}

	public function test_connection(): ConnectionResult
	{
		$capabilities = array_fill_keys([
			'authentication',
			'locations',
			'variants',
			'availability_by_location',
			'prices',
			'customers',
			'order_state',
			'webhooks_or_polling',
			'create_and_approve_orders',
			'order_external_reference_lookup',
			'cancel_orders',
		], false);
		$probes = [];

		try {
			$products = $this->request_object('GET', '/admin/products.json?page=1&limit=1', 'products');
			$product_items = $this->list_from_body($products, 'products', 'products');
			$capabilities['authentication'] = true;
			$capabilities['variants'] = true;
			$capabilities['prices'] = true;
			$capabilities['webhooks_or_polling'] = true;
			$probes['products'] = 200;
			$first_variant = $this->first_variant($product_items);

			$locations = $this->probe_list('/admin/locations.json?inventory_management=true&page=1&limit=1', 'locations', $probes);
			$capabilities['locations'] = null !== $locations;

			if (null !== $locations && null !== $first_variant) {
				$inventory_item_id = trim((string) ($first_variant['inventory_item_id'] ?? ''));
				$location_id = trim((string) ($locations[0]['id'] ?? $locations[0]['location_id'] ?? ''));
				if ('' !== $inventory_item_id && '' !== $location_id) {
					$availability = $this->probe_list(
						'/admin/inventory_levels.json?inventory_item_ids=' . rawurlencode($inventory_item_id) . '&location_ids=' . rawurlencode($location_id),
						'inventory_levels',
						$probes
					);
					$capabilities['availability_by_location'] = null !== $availability;
				} else {
					$probes['inventory_levels'] = 0;
				}
			} else {
				$probes['inventory_levels'] = 0;
			}

			$customers = $this->probe_list('/admin/customers.json?limit=1', 'customers', $probes);
			$capabilities['customers'] = null !== $customers;
			$customer_probe = (string) ($probes['customers'] ?? 'unknown');
			$orders = $this->probe_list('/admin/orders.json?limit=1', 'orders', $probes);
			$capabilities['order_state'] = false;
			$order_probe = (string) ($probes['orders'] ?? 'unknown');
			if (null !== $orders) {
				$probes['order_state'] = 'detail contract required';
			}
			$notes = [
				'customers' => null !== $customers
					? 'Đã đọc được customer fixture từ Sapo Admin API.'
					: 'Probe customers thất bại (' . $customer_probe . '); kiểm tra quyền Khách hàng trên Private App.',
				'order_state' => null !== $orders
					? 'Đã đọc được order fixture; cần contract test chi tiết trước khi bật runtime.'
					: 'Probe orders thất bại (' . $order_probe . '); cần quyền Đơn hàng và contract test.',
				'create_and_approve_orders' => 'Chưa bật: cần xác minh payload tạo/duyệt đơn Omni/POS.',
				'order_external_reference_lookup' => 'Chưa bật: cần xác minh field external reference.',
				'cancel_orders' => 'Chưa bật: cần xác minh rule hủy theo fulfillment.',
			];
			$contract_capabilities = CapabilityGate::order_contract_capabilities();
			foreach ($contract_capabilities as $capability => $verified) {
				// The read probe above is authoritative for customer data access.
				// The order contract also exercises customer write/lookup, but must
				// not downgrade a successfully verified customer-read capability.
				if ('customers' === $capability) {
					continue;
				}
				if (! array_key_exists($capability, $capabilities)) {
					continue;
				}
				$capabilities[$capability] = $verified;
				if ($verified) {
					$notes[$capability] = 'Đã xác minh bằng contract test trên connection hiện tại.';
				}
			}
			$this->capability_snapshot = new CapabilitySnapshot($capabilities, $notes);

			return new ConnectionResult(true, 'Sapo Admin API kết nối được; luồng đọc đã sẵn sàng.', ['probes' => $probes]);
		} catch (\Throwable $exception) {
			$this->capability_snapshot = new CapabilitySnapshot($capabilities);
			$error_code = $exception instanceof \WooSapoSync\Infrastructure\Sapo\Exception\SapoException
				? $exception->error_code()
				: ErrorCode::REMOTE_SERVER;

			return new ConnectionResult(false, 'Không thể kết nối Sapo Admin API.', ['error_code' => $error_code]);
		}
	}

	public function capabilities(): CapabilitySnapshot
	{
		return $this->capability_snapshot ?: new CapabilitySnapshot();
	}

	/**
	 * Run an explicit, reversible order contract smoke test for this connection.
	 * The test verifies customer write/lookup, order create, external-reference
	 * lookup, order detail and cancellation. It uses inventory_behaviour=bypass,
	 * disables webhooks/receipts and immediately cancels the created order, so it
	 * does not alter stock.
	 *
	 * @return array{order_id: string, create_status: string, cancel_status: string, capabilities: array<string, bool>}
	 */
	public function verify_order_contract(): array
	{
		$locations = $this->list_locations();
		$location = null;
		foreach ((array) ($locations['items'] ?? []) as $candidate) {
			if (is_array($candidate) && '' !== trim((string) ($candidate['id'] ?? $candidate['location_id'] ?? ''))) {
				$location = $candidate;
				break;
			}
		}
		$variants = $this->list_variants();
		$variant = null;
		foreach ((array) ($variants['items'] ?? []) as $candidate) {
			if (is_array($candidate)
				&& '' !== trim((string) ($candidate['product_id'] ?? ''))
				&& '' !== trim((string) ($candidate['variant_id'] ?? ''))
				&& '' !== trim((string) ($candidate['sku'] ?? ''))) {
				$variant = $candidate;
				break;
			}
		}
		if (! is_array($location) || ! is_array($variant)) {
			throw new \WooSapoSync\Infrastructure\Sapo\Exception\SapoException(ErrorCode::VALIDATION, 'Không có location/variant phù hợp để chạy order contract test.');
		}

		$random = function_exists('wp_rand') ? wp_rand(1000, 9999) : random_int(1000, 9999);
		$customer_email = 'sapo-sync-contract-' . gmdate('YmdHis') . '-' . $random . '@example.com';
		$customer_id = '';
		$order_id = '';
		try {
			$customer = $this->create_customer([
				'email' => $customer_email,
				'first_name' => 'Sapo Sync',
				'last_name' => 'Contract Test',
				'phone' => '+84909999998',
			]);
			$customer_id = trim((string) ($customer['id'] ?? ''));
			if ('' === $customer_id) {
				throw new \WooSapoSync\Infrastructure\Sapo\Exception\SapoException(ErrorCode::VALIDATION, 'Customer contract create response has no ID.');
			}
			$found_customer = $this->find_customer(['email' => $customer_email]);
			if (! is_array($found_customer) || $customer_id !== trim((string) ($found_customer['id'] ?? ''))) {
				throw new \WooSapoSync\Infrastructure\Sapo\Exception\SapoException(ErrorCode::VALIDATION, 'Customer contract lookup did not return the created customer.');
			}
			$this->delete_customer($customer_id);
			$customer_id = '';

			$reference = 'WOOSAPO-CONTRACT-TEST-' . gmdate('YmdHis') . '-' . $random;
			$payload = [
				'order' => [
					'email' => $customer_email,
					'source_name' => 'woo-sapo-contract-test',
					'reference' => $reference,
					'note' => $reference,
					'note_attributes' => [['name' => 'woo_sapo_external_reference', 'value' => $reference]],
					'send_receipt' => false,
					'send_webhooks' => false,
					'inventory_behaviour' => 'bypass',
					'currency' => 'VND',
					'location_id' => (string) ($location['id'] ?? $location['location_id']),
					'line_items' => [[
						'product_id' => (string) $variant['product_id'],
						'variant_id' => (string) $variant['variant_id'],
						'sku' => (string) $variant['sku'],
						'quantity' => 1,
						'price' => (string) ($variant['price'] ?? '0'),
					]],
				],
			];
			$created = $this->request_object('POST', '/admin/orders.json', 'order contract create', $payload);
			$order = ResponseValidator::object($created['order'] ?? null, 'order contract create');
			$order_id = trim((string) ($order['id'] ?? ''));
			if ('' === $order_id || empty($order['confirmed']) || '' === trim((string) ($order['processed_on'] ?? ''))) {
				throw new \WooSapoSync\Infrastructure\Sapo\Exception\SapoException(ErrorCode::VALIDATION, 'Order contract create did not return a confirmed and processed order.');
			}
			$found_order = $this->find_order_by_external_reference(ExternalReference::from_string($reference));
			if (! is_array($found_order) || $order_id !== trim((string) ($found_order['id'] ?? ''))) {
				throw new \WooSapoSync\Infrastructure\Sapo\Exception\SapoException(ErrorCode::VALIDATION, 'Order contract lookup did not return the created order.');
			}
			$state = $this->get_order_state($order_id);
			if ($order_id !== (string) ($state['order_id'] ?? '') || 'approved' !== (string) ($state['order'] ?? '')) {
				throw new \WooSapoSync\Infrastructure\Sapo\Exception\SapoException(ErrorCode::VALIDATION, 'Order contract detail did not map to an approved order.');
			}

			$cancelled = $this->request_object(
				'POST',
				'/admin/orders/' . rawurlencode($order_id) . '/cancel.json',
				'order contract cancel',
				['order_cancel' => ['cancel_reason' => 'other']]
			);
			ResponseValidator::object($cancelled['order'] ?? null, 'order contract cancel');

			return [
				'order_id' => $order_id,
				'create_status' => (string) ($order['status'] ?? ''),
				'cancel_status' => 'cancelled',
				'capabilities' => [
					'customers' => true,
					'create_and_approve_orders' => true,
					'order_external_reference_lookup' => true,
					'order_state' => true,
					'cancel_orders' => true,
				],
			];
		} finally {
			if ('' !== $customer_id) {
				try {
					$this->delete_customer($customer_id);
				} catch (\Throwable $exception) {
					// Contract cleanup must not hide the actual verification failure.
				}
			}
		}
	}

	/**
	 * @return array{items: array<int, array<string, mixed>>, next_cursor: string|null}
	 */
	public function list_locations(?string $cursor = null): array
	{
		$page = max(1, (int) ($cursor ?: 1));
		$body = $this->request_object('GET', '/admin/locations.json?inventory_management=true&page=' . $page . '&limit=250', 'locations');
		$items = $this->list_from_body($body, 'locations', 'locations');

		return [
			'items' => $items,
			'next_cursor' => count($items) >= 250 ? (string) ($page + 1) : null,
		];
	}

	/**
	 * @return array{items: array<int, array<string, mixed>>, next_cursor: string|null}
	 */
	public function list_variants(?string $cursor = null, ?string $modified_after = null): array
	{
		$page = max(1, (int) ($cursor ?: 1));
		$query = '/admin/products.json?page=' . $page . '&limit=250';
		if (null !== $modified_after && '' !== trim($modified_after)) {
			$query .= '&modified_on_min=' . rawurlencode($modified_after);
		}

		$body = $this->request_object('GET', $query, 'products');
		$products = $this->list_from_body($body, 'products', 'products');
		$items = [];
		foreach ($products as $product) {
			$product_id = trim((string) ($product['id'] ?? ''));
			$variants = is_array($product['variants'] ?? null) ? $product['variants'] : [];
			$is_variation = $this->is_variation_product($product, $variants);
			foreach ($variants as $variant) {
				if (! is_array($variant)) {
					continue;
				}
				$variant_id = trim((string) ($variant['id'] ?? ''));
				if ('' === $variant_id) {
					continue;
				}
				$item = [
					'product_id' => $product_id,
					'variant_id' => $variant_id,
					'inventory_item_id' => (string) ($variant['inventory_item_id'] ?? ''),
					'sku' => (string) ($variant['sku'] ?? ''),
					'price' => (string) ($variant['price'] ?? ''),
					'name' => (string) ($product['name'] ?? $product['title'] ?? ''),
					'product_type' => $is_variation ? ProductType::VARIATION : ProductType::SIMPLE,
					'modified_on' => (string) ($product['modified_on'] ?? $product['updated_at'] ?? ''),
				];
				$this->variant_cache[$variant_id] = $item;
				$items[] = $item;
			}
		}

		return [
			'items' => $items,
			'next_cursor' => count($products) >= 250 ? (string) ($page + 1) : null,
		];
	}

	/**
	 * @param string[] $variant_ids
	 * @param string[] $location_ids
	 * @return array<int, array{variant_id: string, location_id: string, available: float}>
	 */
	public function get_availability(array $variant_ids, array $location_ids): array
	{
		$variant_ids = array_values(array_unique(array_filter(array_map('strval', $variant_ids))));
		$location_ids = array_values(array_unique(array_filter(array_map('strval', $location_ids))));
		if ([] === $variant_ids || [] === $location_ids) {
			return [];
		}

		$this->prime_variants($variant_ids);
		$inventory_ids = [];
		$variant_by_inventory = [];
		foreach ($variant_ids as $variant_id) {
			$inventory_id = trim((string) ($this->variant_cache[$variant_id]['inventory_item_id'] ?? ''));
			if ('' === $inventory_id) {
				throw new \WooSapoSync\Infrastructure\Sapo\Exception\SapoException(ErrorCode::VALIDATION, 'Sapo variant is missing inventory_item_id.');
			}
			$inventory_ids[] = $inventory_id;
			$variant_by_inventory[$inventory_id] = $variant_id;
		}

		$availability = [];
		// Sapo accepts comma-separated IDs, but a single request for a large
		// catalog can exceed proxy URL limits. Keep both dimensions bounded.
		foreach (array_chunk($inventory_ids, 100) as $inventory_batch) {
			foreach (array_chunk($location_ids, 25) as $location_batch) {
				$body = $this->request_object(
					'GET',
					'/admin/inventory_levels.json?inventory_item_ids=' . rawurlencode(implode(',', $inventory_batch)) . '&location_ids=' . rawurlencode(implode(',', $location_batch)),
					'inventory_levels'
				);
				$rows = $this->list_from_body($body, 'inventory_levels', 'inventory_levels');
				foreach ($rows as $row) {
					if (! is_array($row) || ! array_key_exists('available', $row)) {
						continue;
					}
					$inventory_id = trim((string) ($row['inventory_item_id'] ?? ''));
					$variant_id = trim((string) ($row['variant_id'] ?? ($variant_by_inventory[$inventory_id] ?? '')));
					$location_id = trim((string) ($row['location_id'] ?? ''));
					if ('' === $variant_id || '' === $location_id) {
						continue;
					}
					$availability[] = [
						'variant_id' => $variant_id,
						'location_id' => $location_id,
						'available' => (float) $row['available'],
					];
				}
			}
		}

		return $availability;
	}

	/**
	 * @param string[] $variant_ids
	 * @return array<int, array{variant_id: string, price: string, price_list_id: string|null}>
	 */
	public function get_prices(array $variant_ids, ?string $price_list_id = null): array
	{
		if (null !== $price_list_id && '' !== trim($price_list_id)) {
			// The public Admin API contract does not expose the Omni/POS price-list
			// response shape. Never silently return a base price for a requested list.
			$this->unsupported('price_lists');
		}

		$variant_ids = array_values(array_unique(array_filter(array_map('strval', $variant_ids))));
		$this->prime_variants($variant_ids);
		$prices = [];
		foreach ($variant_ids as $variant_id) {
			if (! isset($this->variant_cache[$variant_id])) {
				continue;
			}
			$prices[] = [
				'variant_id' => $variant_id,
				'price' => (string) ($this->variant_cache[$variant_id]['price'] ?? ''),
				'price_list_id' => $price_list_id,
			];
		}

		return $prices;
	}

	/**
	 * @param array<string, mixed> $lookup
	 * @return array<string, mixed>|null
	 */
	public function find_customer(array $lookup): ?array
	{
		$needle = trim((string) ($lookup['email'] ?? $lookup['phone'] ?? ''));
		if ('' === $needle) {
			return null;
		}
		$email = trim((string) ($lookup['email'] ?? ''));
		$phone = trim((string) ($lookup['phone'] ?? ''));
		for ($page = 1; $page <= 100; $page++) {
			$body = $this->request_object(
				'GET',
				'/admin/customers.json?page=' . $page . '&limit=250&fields=id,email,phone,addresses,default_address',
				'customers'
			);
			$customers = $this->list_from_body($body, 'customers', 'customers');
			foreach ($customers as $customer) {
				if (! is_array($customer)) {
					continue;
				}
				if ('' !== $email && strcasecmp($email, trim((string) ($customer['email'] ?? ''))) === 0) {
					return $customer;
				}
				if ('' !== $phone && CustomerNormalizer::phone($phone) === CustomerNormalizer::phone((string) ($customer['phone'] ?? ''))) {
					return $customer;
				}
			}
			if (count($customers) < 250) {
				break;
			}
			if (100 === $page) {
				throw new \WooSapoSync\Infrastructure\Sapo\Exception\SapoException(ErrorCode::CONFLICT, 'Không thể chứng minh customer chưa tồn tại sau 25.000 bản ghi; dừng để tránh tạo khách trùng.');
			}
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $customer
	 * @return array<string, mixed>
	 */
	public function create_customer(array $customer): array
	{
		$payload = $customer;
		if (isset($payload['address_1']) || isset($payload['address_2']) || isset($payload['city'])) {
			$payload['addresses'] = [[
				'address1' => (string) ($payload['address_1'] ?? ''),
				'address2' => (string) ($payload['address_2'] ?? ''),
				'city' => (string) ($payload['city'] ?? ''),
				'province' => (string) ($payload['state'] ?? $payload['province'] ?? ''),
				'zip' => (string) ($payload['postcode'] ?? $payload['zip'] ?? ''),
				'country' => (string) ($payload['country'] ?? ''),
				'phone' => (string) ($payload['phone'] ?? ''),
			]];
			unset($payload['address_1'], $payload['address_2'], $payload['city'], $payload['state'], $payload['province'], $payload['postcode'], $payload['zip'], $payload['country']);
		}
		$body = $this->request_object('POST', '/admin/customers.json', 'customers', ['customer' => $payload]);
		return ResponseValidator::object($body['customer'] ?? null, 'customer');
	}

	public function delete_customer(string $sapo_customer_id): bool
	{
		$sapo_customer_id = trim($sapo_customer_id);
		if ('' === $sapo_customer_id) {
			throw new \WooSapoSync\Infrastructure\Sapo\Exception\SapoException(ErrorCode::VALIDATION, 'Sapo customer ID is required.');
		}

		$response = $this->transport->request(
			'DELETE',
			$this->base_url . '/admin/customers/' . rawurlencode($sapo_customer_id) . '.json',
			$this->headers()
		);

		return in_array((int) ($response['status'] ?? 0), [200, 204], true);
	}

	public function find_order_by_external_reference(ExternalReference $reference): ?array
	{
		$needle = $reference->value();
		for ($page = 1; $page <= 100; $page++) {
			$body = $this->request_object('GET', '/admin/orders.json?status=any&page=' . $page . '&limit=250&fields=id,status,reference,note,note_attributes,tags', 'orders');
			$orders = $this->list_from_body($body, 'orders', 'orders');
			foreach ($orders as $order) {
				if (is_array($order) && $this->order_has_reference($order, $needle)) {
					return $order;
				}
			}
			if (count($orders) < 250) {
				break;
			}
			if (100 === $page) {
				throw new \WooSapoSync\Infrastructure\Sapo\Exception\SapoException(ErrorCode::CONFLICT, 'Không thể chứng minh order chưa tồn tại sau 25.000 bản ghi; dừng để tránh tạo đơn trùng.');
			}
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $command
	 * @return array<string, mixed>
	 */
	public function create_and_approve_order(array $command): array
	{
		$reference = trim((string) ($command['external_reference'] ?? ''));
		$location_id = trim((string) ($command['assigned_location_id'] ?? ''));
		$currency = trim((string) ($command['currency'] ?? 'VND')) ?: 'VND';
		if ('' === $reference || '' === $location_id) {
			throw new \WooSapoSync\Infrastructure\Sapo\Exception\SapoException(ErrorCode::VALIDATION, 'Order command is missing external reference or location.');
		}

		$line_items = [];
		foreach ((array) ($command['lines'] ?? []) as $line) {
			if (! is_array($line)) {
				continue;
			}
			$line_items[] = array_filter([
				'product_id' => (string) ($line['sapo_product_id'] ?? ''),
				'variant_id' => (string) ($line['sapo_variant_id'] ?? ''),
				'sku' => (string) ($line['sku'] ?? ''),
				'quantity' => (float) ($line['quantity'] ?? 0),
				'price' => (string) ($line['unit_price'] ?? '0'),
			], static fn ($value): bool => '' !== trim((string) $value));
		}
		if ([] === $line_items) {
			throw new \WooSapoSync\Infrastructure\Sapo\Exception\SapoException(ErrorCode::VALIDATION, 'Order command has no line items.');
		}

		$customer = is_array($command['customer'] ?? null) ? $command['customer'] : [];
		$order = [
			'email' => (string) ($customer['email'] ?? ''),
			'phone' => (string) ($customer['phone'] ?? ''),
			'gateway' => (string) ($command['payment_method'] ?? ''),
			'currency' => $currency,
			'source_name' => 'woo-sapo-woocommerce',
			'reference' => $reference,
			'note' => $reference,
			'note_attributes' => [['name' => 'woo_sapo_external_reference', 'value' => $reference]],
			'location_id' => $location_id,
			'line_items' => $line_items,
			'total_discounts' => (string) ($command['discount_total'] ?? '0'),
			'total_tax' => (string) ($command['tax_total'] ?? '0'),
			'inventory_behaviour' => 'decrement_obeying_policy',
			'send_receipt' => false,
			'send_webhooks' => true,
		];
		if (! empty($customer['id'])) {
			$order['customer'] = ['id' => (string) $customer['id']];
		}
		$billing_address = $this->order_address((array) ($command['billing_address'] ?? []), $customer);
		$shipping_address = $this->order_address((array) ($command['shipping_address'] ?? []), $customer);
		if ([] !== $shipping_address) {
			$order['shipping_address'] = $shipping_address;
		}
		if ([] !== $billing_address) {
			$order['billing_address'] = $billing_address;
		}
		$discount_codes = array_values(array_filter((array) ($command['discount_codes'] ?? []), 'is_array'));
		if ([] === $discount_codes && (float) ($command['discount_total'] ?? 0) > 0) {
			$discount_codes[] = [
				'code' => 'woo_sapo_discount',
				'amount' => (float) $command['discount_total'],
				'type' => 'fixed_amount',
			];
		}
		if ([] !== $discount_codes) {
			$order['discount_codes'] = $discount_codes;
		}
		$shipping_total = (float) ($command['shipping_total'] ?? 0);
		if ($shipping_total > 0) {
			$order['shipping_lines'] = [['title' => 'WooCommerce shipping', 'price' => (string) $shipping_total]];
		}
		if (! empty($command['is_paid'])) {
			$order['transactions'] = [[
				'kind' => 'sale',
				'status' => 'success',
				'amount' => (string) ($command['total'] ?? '0'),
			]];
		}

		$body = $this->request_object('POST', '/admin/orders.json', 'orders', ['order' => $order]);
		$created = ResponseValidator::object($body['order'] ?? null, 'order');
		if ('' === trim((string) ($created['id'] ?? ''))) {
			throw new \WooSapoSync\Infrastructure\Sapo\Exception\SapoException(ErrorCode::VALIDATION, 'Sapo create order response has no ID.');
		}

		return $created;
	}

	/**
	 * @param array<string, mixed> $address
	 * @param array<string, mixed> $customer
	 * @return array<string, string>
	 */
	private function order_address(array $address, array $customer): array
	{
		if ([] === $address) {
			$address = [
				'first_name' => $customer['first_name'] ?? '',
				'last_name' => $customer['last_name'] ?? '',
				'address1' => $customer['address_1'] ?? $customer['address1'] ?? '',
				'address2' => $customer['address_2'] ?? $customer['address2'] ?? '',
				'city' => $customer['city'] ?? '',
				'province' => $customer['state'] ?? $customer['province'] ?? '',
				'zip' => $customer['postcode'] ?? $customer['zip'] ?? '',
				'country' => $customer['country'] ?? '',
			];
		}

		return array_filter([
			'first_name' => (string) ($address['first_name'] ?? ''),
			'last_name' => (string) ($address['last_name'] ?? ''),
			'address1' => (string) ($address['address1'] ?? $address['address_1'] ?? ''),
			'address2' => (string) ($address['address2'] ?? $address['address_2'] ?? ''),
			'city' => (string) ($address['city'] ?? ''),
			'province' => (string) ($address['province'] ?? $address['state'] ?? ''),
			'zip' => (string) ($address['zip'] ?? $address['postcode'] ?? ''),
			'country' => (string) ($address['country'] ?? ''),
			'phone' => (string) ($address['phone'] ?? ''),
		], static fn ($value): bool => '' !== trim((string) $value));
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_order_state(string $sapo_order_id): array
	{
		$sapo_order_id = trim($sapo_order_id);
		if ('' === $sapo_order_id) {
			throw new \WooSapoSync\Infrastructure\Sapo\Exception\SapoException(ErrorCode::VALIDATION, 'Sapo order ID is required.');
		}
		$body = $this->request_object('GET', '/admin/orders/' . rawurlencode($sapo_order_id) . '.json', 'order');
		$order = ResponseValidator::object($body['order'] ?? $body, 'order');
		$fulfillments = array_values(array_filter((array) ($order['fulfillments'] ?? []), 'is_array'));
		$last_fulfillment = [] !== $fulfillments ? end($fulfillments) : [];
		$delivery = strtolower(trim((string) ($order['fulfillment_status'] ?? '')));
		if ('' === $delivery && is_array($last_fulfillment)) {
			$delivery = strtolower(trim((string) ($last_fulfillment['status'] ?? '')));
		}
		if ('' === $delivery && ! empty($order['delivered_on'])) {
			$delivery = 'delivered';
		}
		$tracking = '';
		if (is_array($last_fulfillment)) {
			$tracking = trim((string) ($last_fulfillment['tracking_number'] ?? ''));
			if ('' === $tracking && ! empty($last_fulfillment['tracking_numbers'][0])) {
				$tracking = trim((string) $last_fulfillment['tracking_numbers'][0]);
			}
		}
		$refunds = array_values(array_filter((array) ($order['refunds'] ?? []), 'is_array'));
		$return = strtolower(trim((string) ($order['return_status'] ?? $order['refund_status'] ?? '')));
		if ('' === $return && [] !== $refunds) {
			$return = 'received';
		}

		return [
			'order' => ! empty($order['cancelled_on']) || ! empty($order['cancelled_at'])
				? 'cancelled'
				: ((! empty($order['confirmed']) || ! empty($order['confirmed_on']) || in_array(strtolower((string) ($order['status'] ?? '')), ['open', 'processing', 'approved'], true)) ? 'approved' : 'draft'),
				'financial' => (string) ($order['financial_status'] ?? ''),
			'delivery' => $delivery,
			'return' => $return,
			'refund_status' => (string) ($order['refund_status'] ?? ''),
			'restock_status' => (string) ($order['restock_status'] ?? ''),
			'tracking_number' => $tracking,
			'location_id' => (string) ($order['location_id'] ?? ''),
			'order_id' => (string) ($order['id'] ?? $sapo_order_id),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function cancel_order(string $sapo_order_id, string $reason): array
	{
		$sapo_order_id = trim($sapo_order_id);
		if ('' === $sapo_order_id) {
			throw new \WooSapoSync\Infrastructure\Sapo\Exception\SapoException(ErrorCode::VALIDATION, 'Sapo order ID is required.');
		}
		$reason = strtolower(trim($reason));
		$reason = in_array($reason, ['customer', 'fraud', 'inventory', 'declined', 'wrong_item', 'duplicate', 'contact', 'delivery', 'other'], true) ? $reason : 'other';
		return $this->request_object('POST', '/admin/orders/' . rawurlencode($sapo_order_id) . '/cancel.json', 'order cancellation', [
			'order_cancel' => ['cancel_reason' => $reason],
		]);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function request_object(string $method, string $path, string $context, ?array $body = null): array
	{
		$response = $this->transport->request($method, $this->base_url . $path, $this->headers(), $body);
		return ResponseValidator::object($response['body'] ?? null, $context);
	}

	/**
	 * @param array<string, mixed> $order
	 */
	private function order_has_reference(array $order, string $reference): bool
	{
		foreach (['external_reference', 'reference'] as $key) {
			if ($reference === trim((string) ($order[$key] ?? ''))) {
				return true;
			}
		}
		if ($reference === trim((string) ($order['note'] ?? ''))) {
			return true;
		}
		$tags = array_map('trim', explode(',', (string) ($order['tags'] ?? '')));
		if (in_array($reference, $tags, true)) {
			return true;
		}
		foreach ((array) ($order['note_attributes'] ?? []) as $attribute) {
			if (! is_array($attribute)) {
				continue;
			}
			$key = strtolower((string) ($attribute['name'] ?? $attribute['key'] ?? ''));
			$value = trim((string) ($attribute['value'] ?? ''));
			if (in_array($key, ['external_reference', 'woo_sapo_external_reference'], true) && $reference === $value) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<int, array<string, mixed>>
	 */
	private function list_from_body(array $body, string $key, string $context): array
	{
		$items = $body[$key] ?? null;
		if (null === $items && isset($body['data']) && is_array($body['data'])) {
			$items = $body['data'][$key] ?? $body['data'];
		}
		$items = ResponseValidator::list($items, $context);
		return array_values(array_filter($items, 'is_array'));
	}

	/**
	 * @param array<string, int|string> $probes
	 * @return array<int, array<string, mixed>>|null
	 */
	private function probe_list(string $path, string $key, array &$probes): ?array
	{
		try {
			$body = $this->request_object('GET', $path, $key);
			$items = $this->list_from_body($body, $key, $key);
			$probes[$key] = 200;
			return $items;
		} catch (\Throwable $exception) {
			$probes[$key] = $exception instanceof \WooSapoSync\Infrastructure\Sapo\Exception\SapoException
				? $exception->error_code()
				: 'error';
			return null;
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $products
	 * @return array<string, mixed>|null
	 */
	private function first_variant(array $products): ?array
	{
		foreach ($products as $product) {
			foreach ((array) ($product['variants'] ?? []) as $variant) {
				if (is_array($variant)) {
					return $variant;
				}
			}
		}
		return null;
	}

	/**
	 * Sapo may return a single default variant with an options envelope. Treat
	 * that shape as a simple product; only meaningful options or multiple
	 * variants should require a Woo variation mapping.
	 *
	 * @param array<string, mixed> $product
	 * @param array<int, mixed> $variants
	 */
	private function is_variation_product(array $product, array $variants): bool
	{
		if (count($variants) > 1) {
			return true;
		}

		$options = array_values(array_filter((array) ($product['options'] ?? [])));
		foreach ($options as $option) {
			if (is_string($option)) {
				if (! in_array(strtolower(trim($option)), ['', 'default title', 'default'], true)) {
					return true;
				}
				continue;
			}
			if (! is_array($option)) {
				continue;
			}
			$name = strtolower(trim((string) ($option['name'] ?? '')));
			$values = array_values(array_filter((array) ($option['values'] ?? [])));
			if ('' !== $name && ! in_array($name, ['title', 'default title', 'default'], true)) {
				return true;
			}
			foreach ($values as $value) {
				if (! in_array(strtolower(trim((string) $value)), ['', 'default title', 'default'], true)) {
					return true;
				}
			}
		}

		$variant = is_array($variants[0] ?? null) ? $variants[0] : [];
		$title = strtolower(trim((string) ($variant['title'] ?? $variant['option1'] ?? '')));
		return '' !== $title && ! in_array($title, ['default title', 'default'], true);
	}

	/**
	 * @param string[] $variant_ids
	 */
	private function prime_variants(array $variant_ids): void
	{
		$remaining = array_values(array_filter($variant_ids, fn (string $id): bool => ! isset($this->variant_cache[$id])));
		$cursor = null;
		for ($page = 0; $page < 100 && [] !== $remaining; $page++) {
			$response = $this->list_variants($cursor);
			$remaining = array_values(array_filter($remaining, fn (string $id): bool => ! isset($this->variant_cache[$id])));
			$cursor = $response['next_cursor'];
			if (null === $cursor) {
				break;
			}
		}
	}

	/**
	 * @return array<string, string>
	 */
	private function headers(): array
	{
		if ('bearer' === $this->auth_mode) {
			return ['X-Sapo-Access-Token' => $this->access_token];
		}

		return ['Authorization' => 'Basic ' . base64_encode($this->api_key . ':' . $this->api_secret)];
	}

	private function unsupported(string $capability): void
	{
		throw new UnsupportedCapabilityException($capability);
	}
}
