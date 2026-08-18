<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/FixtureGateway.php';

use WooSapoSync\Domain\Inventory\LocationAllocator;
use WooSapoSync\Domain\Inventory\StockAvailabilityCalculator;
use WooSapoSync\Domain\Customer\CustomerNormalizer;
use WooSapoSync\Domain\Order\OrderStateMapper;
use WooSapoSync\Domain\Order\OrderSnapshotValidator;
use WooSapoSync\Domain\Product\MappingMatcher;
use WooSapoSync\Domain\Product\MappingStatus;
use WooSapoSync\Domain\Sku\SkuNormalizer;
use WooSapoSync\Domain\Sync\RetryPolicy;
use WooSapoSync\Domain\ValueObjects\ExternalReference;
use WooSapoSync\Infrastructure\Sapo\UnavailableGateway;
use WooSapoSync\Infrastructure\Sapo\Exception\UnsupportedCapabilityException;
use WooSapoSync\Infrastructure\Sapo\ErrorCode;
use WooSapoSync\Infrastructure\Sapo\Exception\SapoException;
use WooSapoSync\Infrastructure\Sapo\ResponseValidator;
use WooSapoSync\Infrastructure\Sapo\SapoAdminGateway;
use WooSapoSync\Infrastructure\Sapo\Http\HttpTransport;
use WooSapoSync\Infrastructure\WooCommerce\OrderSnapshotBuilder;
use WooSapoSync\Infrastructure\WooCommerce\ProductStockUpdater;
use WooSapoSync\Infrastructure\WordPress\Repository\ProductMappingRepository;
use WooSapoSync\Admin\ConnectionSettings;
use WooSapoSync\Infrastructure\WordPress\InventoryLocationPolicy;
use WooSapoSync\Webhook\WebhookEventNormalizer;
use WooSapoSync\Webhook\WebhookSignature;

$passed = 0;
$failed = 0;

$assert = static function (bool $condition, string $message) use (&$passed, &$failed): void {
	if ($condition) {
		$passed++;
	return;
	}

	$failed++;
	fwrite(STDERR, "FAIL: {$message}\n");
};

$assert('SKU-001' === SkuNormalizer::match_key('  SKU-001  '), 'SKU matching trims whitespace');
$assert('Sku-001' !== SkuNormalizer::match_key('SKU-001'), 'SKU matching does not silently fold case');
$assert(! SkuNormalizer::is_valid('   '), 'blank SKU is invalid');
$assert('WOOSAPO-site01-42' === ExternalReference::for_woo_order(42, 'site.01')->value(), 'external reference is stable and sanitized');
$assert('WOOSAPO-CANCEL-site01-42' === ExternalReference::for_cancel_order(42, 'site.01')->value(), 'cancel reference is distinct and stable');
$assert('PIXELCAM-site01-42' === ExternalReference::legacy_for_woo_order(42, 'site.01')->value(), 'legacy external reference remains discoverable during upgrade');
$assert(RetryPolicy::is_retryable(ErrorCode::TIMEOUT), 'timeout is retryable');
$assert(! RetryPolicy::is_retryable(ErrorCode::VALIDATION), 'validation error is not retryable');
$assert(60 === RetryPolicy::next_delay(1, 0) && 120 === RetryPolicy::next_delay(2, 0), 'retry policy uses exponential backoff');
$assert('https://pixel-demo.mysapo.net' === ConnectionSettings::normalize_base_url('https://Pixel-Demo.mysapo.net/'), 'connection settings normalize HTTPS base URL');
$assert('' === ConnectionSettings::normalize_base_url('http://pixel-demo.mysapo.net'), 'connection settings reject insecure base URL');
$assert('' === ConnectionSettings::normalize_base_url('https://key:secret@pixel-demo.mysapo.net'), 'connection settings reject credentials embedded in URL');

$allocator = new LocationAllocator();
$locations = [
	['id' => 'hcm', 'priority' => 20, 'serves' => true],
	['id' => 'hn', 'priority' => 10, 'serves' => true],
	['id' => 'blocked', 'priority' => 1, 'serves' => false],
];
$lines = [
	['variant_id' => 'v1', 'quantity' => 1],
	['variant_id' => 'v2', 'quantity' => 2],
];
$availability = [
	'hn:v1' => 1,
	'hn:v2' => 1,
	'hcm:v1' => 1,
	'hcm:v2' => 2,
];
$assert('hcm' === $allocator->choose($locations, $lines, $availability), 'allocation requires one location to fulfil all lines');
$assert(null === $allocator->choose($locations, $lines, ['hn:v1' => 1, 'hn:v2' => 1]), 'allocation rejects split stock across locations');

$assert('completed' === OrderStateMapper::to_woo_status(['order' => 'fulfilled', 'financial' => 'paid', 'delivery' => 'delivered']), 'delivered paid order completes');
$assert('completed' === OrderStateMapper::to_woo_status(['order' => 'approved', 'financial' => 'paid', 'delivery' => 'fulfilled']), 'fulfilled paid order completes');
$assert('refunded' === OrderStateMapper::to_woo_status(['order' => 'closed', 'financial' => 'refunded', 'delivery' => 'fulfilled']), 'fully refunded order maps to refunded');
$assert('cancelled' === OrderStateMapper::to_woo_status(['order' => 'cancelled']), 'cancelled order maps to cancelled');
$assert(null === OrderStateMapper::to_woo_status(['order' => 'unknown', 'delivery' => '']), 'incomplete remote state is not guessed');

$mapping = MappingMatcher::match(
	[
		['object_key' => 'woo-1', 'product_id' => 1, 'sku' => 'CAM-001', 'product_type' => 'SIMPLE'],
		['object_key' => 'woo-2', 'product_id' => 2, 'sku' => ' ', 'product_type' => 'SIMPLE'],
		['object_key' => 'woo-3', 'product_id' => 3, 'sku' => 'CAM-DUP', 'product_type' => 'SIMPLE'],
		['object_key' => 'woo-4', 'product_id' => 4, 'sku' => 'CAM-VAR', 'product_type' => 'VARIATION'],
	],
	[
		['product_id' => 'p1', 'variant_id' => 'v1', 'sku' => 'CAM-001', 'product_type' => 'SIMPLE'],
		['product_id' => 'p2', 'variant_id' => 'v2', 'sku' => 'CAM-DUP', 'product_type' => 'SIMPLE'],
		['product_id' => 'p3', 'variant_id' => 'v3', 'sku' => 'CAM-DUP', 'product_type' => 'SIMPLE'],
		['product_id' => 'p4', 'variant_id' => 'v4', 'sku' => 'CAM-VAR', 'product_type' => 'SIMPLE'],
	]
);
$assert(MappingStatus::ACTIVE === $mapping[0]['mapping_status'], 'mapping ignores product name and matches exact SKU');
$assert('EMPTY_SKU' === $mapping[1]['reason'], 'empty SKU is never auto-mapped');
$assert('DUPLICATE_SAPO_SKU' === $mapping[2]['reason'], 'duplicate Sapo SKU requires review');
$assert('PRODUCT_TYPE_MISMATCH' === $mapping[3]['reason'], 'simple/variation type mismatch requires review');

if (! defined('ARRAY_A')) {
	define('ARRAY_A', 'ARRAY_A');
}
if (! function_exists('absint')) {
	function absint($value): int { return abs((int) $value); }
}
if (! function_exists('get_option')) {
	function get_option($key, $default = false) { return $default; }
}
$resolved_without_policy = InventoryLocationPolicy::resolve([['id' => 'loc-unconfigured']]);
$assert(false === ($resolved_without_policy[0]['serves'] ?? true), 'location policy fail-closes unconfigured remote branches');
$fake_mapping_insert = [];
$fake_mapping_db = new class($fake_mapping_insert) {
	public string $prefix = 'wp_';
	public int $insert_id = 1;
	private array $inserted;
	public function __construct(array &$inserted) { $this->inserted =& $inserted; }
	public function prepare(string $query, ...$args): string { return $query; }
	public function get_row(string $query, $format = null) { return null; }
	public function insert(string $table, array $data): bool { $this->inserted[] = $data; return true; }
};
$mapping_repository = new ProductMappingRepository($fake_mapping_db);
$mapping_repository->save(['woo_object_key' => 'woo-empty-1', 'woo_product_id' => 11, 'sku_match_key' => 'EMPTY-1']);
$mapping_repository->save(['woo_object_key' => 'woo-empty-2', 'woo_product_id' => 12, 'sku_match_key' => 'EMPTY-2']);
$assert(2 === count($fake_mapping_insert) && null === $fake_mapping_insert[0]['sapo_variant_id'] && null === $fake_mapping_insert[1]['sapo_variant_id'], 'unmatched mappings store nullable Sapo IDs and do not collide');

$gateway = new UnavailableGateway();
$assert(! $gateway->test_connection()->ok, 'gateway remains disabled before capability verification');
try {
	$gateway->get_availability(['v1'], ['hcm']);
	$assert(false, 'unavailable gateway must reject external calls');
} catch (UnsupportedCapabilityException $exception) {
	$assert(false !== strpos($exception->getMessage(), 'availability_by_location'), 'gateway exposes missing capability');
	$assert(ErrorCode::UNSUPPORTED_CAPABILITY === $exception->error_code(), 'gateway uses normalized error taxonomy');
}

$fixture_dir = __DIR__ . '/fixtures/contract';
$read_fixture = static function (string $name) use ($fixture_dir): array {
	$decoded = json_decode((string) file_get_contents($fixture_dir . '/' . $name . '.json'), true);
	return is_array($decoded) ? $decoded : [];
};
$fixture_gateway = new WooSapoFixtureGateway([
	'locations' => $read_fixture('locations')['items'] ?? [],
	'variants' => $read_fixture('variants')['items'] ?? [],
	'availability' => $read_fixture('availability'),
	'prices' => $read_fixture('prices'),
	'customer' => $read_fixture('customer'),
	'order_state' => $read_fixture('order-state'),
]);
$fixture_locations = $fixture_gateway->list_locations();
$fixture_variants = $fixture_gateway->list_variants();
$fixture_availability = $fixture_gateway->get_availability(['var-simple-1'], ['loc-main']);
$fixture_prices = $fixture_gateway->get_prices(['var-simple-1']);
$fixture_state = $fixture_gateway->get_order_state('sapo-order-1');
$assert($fixture_gateway->test_connection()->ok && $fixture_gateway->capabilities()->supports('availability_by_location'), 'contract fixture gateway exposes verified capabilities only in tests');
$assert('loc-main' === ($fixture_locations['items'][0]['id'] ?? '') && 2 === count($fixture_variants['items']), 'contract fixtures cover location and simple/variation catalog shapes');
$assert(8.0 === (float) ($fixture_availability[0]['available'] ?? 0) && '149000' === ($fixture_prices[0]['price'] ?? ''), 'contract fixtures preserve stock and price fields');
$assert('delivered' === ($fixture_state['delivery'] ?? '') && 'TRACK-FIXTURE-1' === ($fixture_state['tracking_number'] ?? ''), 'contract fixture preserves order state and tracking shape');

$sapo_transport = new class implements HttpTransport {
	/** @var array<int, string> */
	public array $requests = [];

	/** @var array<int, array<string, string>> */
	public array $headers_seen = [];

	public function request(string $method, string $url, array $headers = [], ?array $body = null): array
	{
		$this->requests[] = $url;
		$this->headers_seen[] = $headers;
		if (false !== strpos($url, '/admin/products.json')) {
			return [
				'status' => 200,
				'headers' => [],
				'body' => ['products' => [[
					'id' => 'p-1',
					'name' => 'Camera demo',
					'variants' => [[
						'id' => 'v-1',
						'inventory_item_id' => 'ii-1',
						'sku' => 'CAM-1',
						'price' => '149000',
					]],
				]]],
			];
		}
		if (false !== strpos($url, '/admin/locations.json')) {
			return ['status' => 200, 'headers' => [], 'body' => ['locations' => [['id' => 'loc-1']]]];
		}
		if (false !== strpos($url, '/admin/inventory_levels.json')) {
			return ['status' => 200, 'headers' => [], 'body' => ['inventory_levels' => [[
				'inventory_item_id' => 'ii-1',
				'variant_id' => 'v-1',
				'location_id' => 'loc-1',
				'available' => 5,
			]]]];
		}
		if (false !== strpos($url, '/admin/customers.json')) {
			return ['status' => 200, 'headers' => [], 'body' => ['customers' => []]];
		}
		if (false !== strpos($url, '/admin/orders.json')) {
			return ['status' => 200, 'headers' => [], 'body' => ['orders' => []]];
		}

		return ['status' => 200, 'headers' => [], 'body' => []];
	}
};
$sapo_gateway = new SapoAdminGateway('https://demo.mysapo.net', 'basic', 'test-key', 'test-secret', '', $sapo_transport);
$sapo_connection = $sapo_gateway->test_connection();
$sapo_variants = $sapo_gateway->list_variants();
$sapo_availability = $sapo_gateway->get_availability(['v-1'], ['loc-1']);
$sapo_prices = $sapo_gateway->get_prices(['v-1']);
$assert($sapo_connection->ok && $sapo_gateway->capabilities()->supports('availability_by_location'), 'Sapo Admin gateway verifies read-side capabilities');
$assert('CAM-1' === ($sapo_variants['items'][0]['sku'] ?? '') && 'SIMPLE' === ($sapo_variants['items'][0]['product_type'] ?? ''), 'Sapo Admin gateway flattens simple product variants');
$assert(5.0 === (float) ($sapo_availability[0]['available'] ?? 0) && '149000' === ($sapo_prices[0]['price'] ?? ''), 'Sapo Admin gateway preserves live stock and price fields');
try {
	$sapo_gateway->get_prices(['v-1'], 'price-list-1');
	$assert(false, 'Sapo Admin gateway must not silently use base price for an unverified price list');
} catch (UnsupportedCapabilityException $exception) {
	$assert(false !== strpos($exception->getMessage(), 'price_lists'), 'unverified price list is fail-closed');
}

$default_transport = new class implements HttpTransport {
	public function request(string $method, string $url, array $headers = [], ?array $body = null): array
	{
		return [
			'status' => 200,
			'headers' => [],
			'body' => ['products' => [[
				'id' => 'p-default',
				'name' => 'Simple default',
				'options' => [['name' => 'Title', 'values' => ['Default Title']]],
				'variants' => [[
					'id' => 'v-default',
					'inventory_item_id' => 'ii-default',
					'sku' => 'DEFAULT-1',
					'title' => 'Default Title',
					'price' => '100',
				]],
			]]],
		];
	}
};
$default_gateway = new SapoAdminGateway('https://demo.mysapo.net', 'basic', 'key', 'secret', '', $default_transport);
$default_variants = $default_gateway->list_variants();
$assert('SIMPLE' === ($default_variants['items'][0]['product_type'] ?? ''), 'Sapo default title option remains a simple product');
$write_transport = new class implements HttpTransport {
	public array $requests = [];

	public function request(string $method, string $url, array $headers = [], ?array $body = null): array
	{
		$this->requests[] = ['method' => $method, 'url' => $url, 'body' => $body];
		if ('POST' === $method && false !== strpos($url, '/admin/orders.json')) {
			return ['status' => 201, 'headers' => [], 'body' => ['order' => ['id' => 'order-write-1', 'status' => 'open']]];
		}
		if ('GET' === $method && false !== strpos($url, '/admin/orders.json')) {
			return ['status' => 200, 'headers' => [], 'body' => ['orders' => [['id' => 'order-write-1', 'reference' => 'PIXELCAM-test-1']]]];
		}
		if ('POST' === $method && false !== strpos($url, '/cancel.json')) {
			return ['status' => 200, 'headers' => [], 'body' => ['order' => ['id' => 'order-write-1', 'cancelled_on' => '2026-08-18T10:00:00Z']]];
		}
		return ['status' => 200, 'headers' => [], 'body' => ['order' => ['id' => 'order-write-1', 'status' => 'open', 'confirmed_on' => '2026-08-18T10:00:00Z']]];
	}
};
$write_gateway = new SapoAdminGateway('https://demo.mysapo.net', 'basic', 'key', 'secret', '', $write_transport);
$write_order = $write_gateway->create_and_approve_order([
	'external_reference' => 'WOOSAPO-test-1',
	'assigned_location_id' => 'loc-1',
	'currency' => 'VND',
	'lines' => [['sapo_product_id' => 'p-1', 'sapo_variant_id' => 'v-1', 'sku' => 'CAM-1', 'quantity' => 1, 'unit_price' => 149000]],
	'customer' => ['id' => 'customer-1', 'email' => 'test@example.com'],
	'discount_total' => 0,
	'tax_total' => 0,
	'shipping_total' => 0,
	'is_paid' => false,
	'total' => 149000,
]);
$assert('order-write-1' === ($write_order['id'] ?? ''), 'Sapo Admin gateway creates an order from the normalized command');
$assert('v-1' === (($write_transport->requests[0]['body']['order']['line_items'][0]['variant_id'] ?? '')), 'Sapo order payload preserves variant ID');
$found_write_order = $write_gateway->find_order_by_external_reference(ExternalReference::from_string('PIXELCAM-test-1'));
$assert('order-write-1' === ($found_write_order['id'] ?? ''), 'Sapo order lookup matches exact external reference');
$assert(false !== strpos((string) ($write_transport->requests[1]['url'] ?? ''), 'status=any'), 'Sapo order lookup includes closed and cancelled orders');
$write_state = $write_gateway->get_order_state('order-write-1');
$assert('approved' === ($write_state['order'] ?? '') && 'order-write-1' === ($write_state['order_id'] ?? ''), 'Sapo order detail maps confirmed order state');
$write_gateway->cancel_order('order-write-1', 'WooCommerce order cancelled.');
$last_write_request = end($write_transport->requests);
$assert('other' === (($last_write_request['body']['order_cancel']['cancel_reason'] ?? '')), 'Sapo order cancellation maps free text to a valid reason');
$bearer_gateway = new SapoAdminGateway('https://demo.mysapo.net', 'bearer', '', '', 'access-token-fixture', $sapo_transport);
$bearer_gateway->list_locations();
$last_headers = end($sapo_transport->headers_seen);
$assert('access-token-fixture' === ($last_headers['X-Sapo-Access-Token'] ?? ''), 'Sapo Admin gateway sends OAuth token in the documented header');

$assert('abc' === ResponseValidator::require_string(['id' => ' abc '], 'id', 'variant'), 'response validator trims required strings');
try {
	ResponseValidator::object([['items' => []]], 'variant');
	$assert(false, 'response validator rejects list/object shape mismatch');
} catch (SapoException $exception) {
	$assert(ErrorCode::VALIDATION === $exception->error_code(), 'response validator reports validation errors');
}

$webhook_body = '{"event_id":"evt-1","event_type":"order.updated","order_id":"sapo-42"}';
$webhook_signature = hash_hmac('sha256', $webhook_body, 'test-secret');
$assert(WebhookSignature::verify($webhook_body, 'sha256=' . $webhook_signature, 'test-secret'), 'webhook accepts valid HMAC signature');
$assert(WebhookSignature::verify($webhook_body, base64_encode(hash_hmac('sha256', $webhook_body, 'test-secret', true)), 'test-secret'), 'webhook accepts base64 HMAC signature');
$assert(! WebhookSignature::verify($webhook_body, 'sha256=bad', 'test-secret'), 'webhook rejects invalid HMAC signature');
$normalized_event = WebhookEventNormalizer::normalize(json_decode($webhook_body, true), $webhook_body);
$assert('evt-1' === $normalized_event['event_key'] && 'sapo-42' === $normalized_event['remote_object_id'], 'webhook normalizes event identity without storing PII fields');
$topic_event = WebhookEventNormalizer::normalize(['product' => ['id' => 'sapo-product-1', 'updated_at' => '2026-08-18T10:00:00Z']], '{}', 'products/update');
$assert('products/update' === ($topic_event['event_type'] ?? '') && 'sapo-product-1' === ($topic_event['remote_object_id'] ?? ''), 'webhook accepts Sapo topic header and nested resource identity');
$top_level_event = WebhookEventNormalizer::normalize(['id' => 'sapo-product-2'], '{}', 'products/update');
$assert('sapo-product-2' === ($top_level_event['remote_object_id'] ?? ''), 'webhook accepts top-level resource ID when Sapo sends topic in header');
$canonical_a = WebhookEventNormalizer::normalize(['type' => 'product.updated', 'product' => ['id' => 'p-3', 'updated_at' => '2026-08-18T10:00:00Z']], '{"type":"product.updated","product":{"id":"p-3","updated_at":"2026-08-18T10:00:00Z"}}');
$canonical_b = WebhookEventNormalizer::normalize(['type' => 'product.updated', 'product' => ['updated_at' => '2026-08-18T10:00:00Z', 'id' => 'p-3']], "{\n  \"product\": {\"id\": \"p-3\", \"updated_at\": \"2026-08-18T10:00:00Z\"},\n  \"type\": \"product.updated\"\n}");
$assert(($canonical_a['event_key'] ?? '') === ($canonical_b['event_key'] ?? '') && '2026-08-18 10:00:00' === ($canonical_a['remote_modified_at'] ?? ''), 'webhook fingerprint is stable across JSON formatting and stores MySQL datetime');
$assert(null === WebhookEventNormalizer::normalize(['event_type' => 'order.updated'], '{}'), 'webhook rejects envelope without event id');

$assert('+84901234567' === CustomerNormalizer::phone('090 123 45 67'), 'Vietnamese phone is normalized to E.164-like value');
$assert('+84901234567' === CustomerNormalizer::phone('+84 90 123 45 67'), 'international phone keeps country code');
$assert('customer@example.com' === CustomerNormalizer::email(' Customer@Example.COM '), 'customer email is normalized');
$assert('Hồ Chí Minh' === CustomerNormalizer::province('HCM') && 'Hồ Chí Minh' === CustomerNormalizer::province('Hồ Chí Minh'), 'Vietnamese province codes are normalized for Sapo');
$valid_order = [
	'woo_order_id' => 10,
	'status' => 'processing',
	'lines' => [['sku' => 'CAM-1', 'quantity' => 1]],
];
$assert(OrderSnapshotValidator::is_valid($valid_order), 'accepted order snapshot with SKU is valid');
$assert(in_array('ORDER_NOT_ACCEPTED', OrderSnapshotValidator::errors(['woo_order_id' => 10, 'status' => 'pending', 'lines' => [['sku' => 'CAM-1', 'quantity' => 1]]]), true), 'pending order cannot enter outbox');
$assert(in_array('LINE_MISSING_SKU', OrderSnapshotValidator::errors(['woo_order_id' => 10, 'status' => 'processing', 'lines' => [['sku' => '', 'quantity' => 1]]]), true), 'order line without SKU is rejected');

$fake_product = new class {
	public function get_sku(): string { return 'CAM-FAKE'; }
	public function is_type(string $type): bool { return 'simple' === $type; }
};
$fake_item = new class($fake_product) {
	private object $product;
	public function __construct(object $product) { $this->product = $product; }
	public function get_product(): object { return $this->product; }
	public function get_product_id(): int { return 99; }
	public function get_variation_id(): int { return 0; }
	public function get_quantity(): int { return 2; }
	public function get_subtotal(): float { return 200.0; }
	public function get_total(): float { return 180.0; }
	public function get_total_tax(): float { return 18.0; }
};
$fake_order = new class($fake_item) {
	private object $item;
	public function __construct(object $item) { $this->item = $item; }
	public function get_id(): int { return 99; }
	public function get_items(string $type): array { return ['7' => $this->item]; }
	public function get_status(): string { return 'processing'; }
	public function get_currency(): string { return 'VND'; }
	public function get_total(): float { return 198.0; }
	public function get_subtotal(): float { return 200.0; }
	public function get_discount_total(): float { return 20.0; }
	public function get_shipping_total(): float { return 0.0; }
	public function get_total_tax(): float { return 18.0; }
	public function get_payment_method(): string { return 'cod'; }
	public function is_paid(): bool { return false; }
	public function get_meta(string $key, bool $single): string { return '_woo_sapo_assigned_location' === $key ? '941850' : ''; }
	public function get_billing_phone(): string { return '0901234567'; }
	public function get_billing_email(): string { return 'buyer@example.com'; }
	public function get_billing_first_name(): string { return 'Test'; }
	public function get_billing_last_name(): string { return 'Buyer'; }
	public function get_shipping_address_1(): string { return '1 Demo Street'; }
	public function get_shipping_address_2(): string { return ''; }
	public function get_shipping_city(): string { return 'HCM'; }
	public function get_shipping_state(): string { return ''; }
	public function get_shipping_postcode(): string { return '700000'; }
	public function get_shipping_country(): string { return 'VN'; }
};
$fake_snapshot = (new OrderSnapshotBuilder())->build($fake_order);
$assert('CAM-FAKE' === $fake_snapshot['lines'][0]['sku'] && 2.0 === $fake_snapshot['lines'][0]['quantity'], 'Woo CRUD order snapshot preserves SKU and quantity');
$assert('+84901234567' === $fake_snapshot['customer']['phone'], 'Woo order snapshot normalizes customer phone');
$assert('1 Demo Street' === ($fake_snapshot['shipping_address']['address1'] ?? ''), 'Woo order snapshot keeps shipping address separate');

$stock_values = ['quantity' => 2.0, 'manage' => false, 'status' => 'instock', 'saved' => false];
$stock_product = new class($stock_values) {
	private array $state;
	public function __construct(array &$state) { $this->state =& $state; }
	public function get_stock_quantity(): float { return $this->state['quantity']; }
	public function set_manage_stock(bool $value): void { $this->state['manage'] = $value; }
	public function set_stock_quantity(float $value): void { $this->state['quantity'] = $value; }
	public function set_stock_status(string $value): void { $this->state['status'] = $value; }
	public function save(): void { $this->state['saved'] = true; }
};
$updater = new ProductStockUpdater(static function (int $id) use ($stock_product) {
	return $stock_product;
});
$assert(2.0 === $updater->current(['woo_product_id' => 50]), 'stock updater shadow read does not mutate');
$update_result = $updater->update(['woo_product_id' => 50], 0.0);
$assert($update_result['updated'] && $stock_values['manage'] && 'outofstock' === $stock_values['status'], 'stock updater writes through Woo CRUD and status');
$calculated_stock = StockAvailabilityCalculator::calculate(
	[
		['id' => 'hcm', 'serves' => true],
		['id' => 'hn', 'serves' => false],
	],
	[
		['variant_id' => 'v1', 'location_id' => 'hcm', 'available' => 3],
		['variant_id' => 'v1', 'location_id' => 'hn', 'available' => 99],
		['variant_id' => 'v2', 'location_id' => 'hcm', 'available' => -1],
	]
);
$assert(3.0 === $calculated_stock['v1'] && 0.0 === $calculated_stock['v2'], 'stock calculator uses direct available from eligible locations only');

fwrite(STDOUT, sprintf("%d passed, %d failed\n", $passed, $failed));
exit($failed > 0 ? 1 : 0);
