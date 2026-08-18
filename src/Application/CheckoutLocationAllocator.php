<?php
/**
 * Validates a cart against one Sapo location and carries the choice to the order.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Application;

use WooSapoSync\Contracts\SapoGateway;
use WooSapoSync\Domain\Inventory\LocationAllocator;
use WooSapoSync\Domain\Product\MappingStatus;
use WooSapoSync\Domain\Product\ProductType;
use WooSapoSync\Domain\Sku\SkuNormalizer;
use WooSapoSync\Infrastructure\Sapo\ErrorCode;
use WooSapoSync\Infrastructure\Sapo\Exception\SapoException;
use WooSapoSync\Infrastructure\WordPress\InventoryLocationPolicy;
use WooSapoSync\Infrastructure\WordPress\Repository\ProductMappingRepository;

defined('ABSPATH') || exit;

final class CheckoutLocationAllocator
{
	private const MAX_LOCATION_PAGES = 100;

	private SapoGateway $gateway;

	private ProductMappingRepository $mappings;

	private bool $resolved = false;

	private ?string $selected_location = null;

	private string $error = '';

	public function __construct(SapoGateway $gateway, ProductMappingRepository $mappings)
	{
		$this->gateway = $gateway;
		$this->mappings = $mappings;
	}

	public function register(): void
	{
		add_action('woocommerce_after_checkout_validation', [$this, 'validate'], 10, 2);
		add_action('woocommerce_checkout_create_order', [$this, 'assign'], 20, 2);
	}

	/**
	 * @param mixed $data
	 * @param mixed $errors
	 */
	public function validate($data, $errors): void
	{
		if (! is_object($errors) || ! method_exists($errors, 'add')) {
			return;
		}

		$location = $this->resolve_cart_location();
		if (null === $location && '' !== $this->error) {
			$errors->add('woo_sapo_location', $this->error);
		}
	}

	/**
	 * @param mixed $order
	 * @param array<string, mixed> $data
	 */
	public function assign($order, array $data): void
	{
		if (! is_object($order) || ! method_exists($order, 'update_meta_data')) {
			return;
		}

		$location = $this->resolve_cart_location();
		if (null !== $location) {
			$order->update_meta_data('_woo_sapo_assigned_location', $location);
			return;
		}

		if ('' !== $this->error) {
			$order->update_meta_data('_woo_sapo_location_error', $this->error);
		}
	}

	private function resolve_cart_location(): ?string
	{
		if ($this->resolved) {
			return $this->selected_location;
		}

		$this->resolved = true;
		$cart = function_exists('WC') ? WC() : null;
		if (! is_object($cart) || ! isset($cart->cart) || ! is_object($cart->cart) || ! method_exists($cart->cart, 'get_cart')) {
			return null;
		}

		$lines = $this->cart_lines((array) $cart->cart->get_cart());
		if ([] === $lines) {
			return null;
		}

		try {
			$remote_locations = [];
			$cursor = null;
			$seen = [];
			$location_pagination_complete = false;
			for ($page = 0; $page < self::MAX_LOCATION_PAGES; $page++) {
				$response = $this->gateway->list_locations($cursor);
				foreach ((array) ($response['items'] ?? []) as $item) {
					if (is_array($item)) {
						$remote_locations[] = $item;
					}
				}
				$next = isset($response['next_cursor']) && null !== $response['next_cursor'] ? (string) $response['next_cursor'] : '';
				if ('' === $next) {
					$location_pagination_complete = true;
					break;
				}
				if (isset($seen[$next])) {
					throw new SapoException(ErrorCode::CONFLICT, 'Sapo location pagination repeated a cursor.');
				}
				$seen[$next] = true;
				$cursor = $next;
			}
			if (! $location_pagination_complete) {
				throw new SapoException(ErrorCode::CONFLICT, 'Sapo location list exceeds the safe pagination limit.');
			}
			$locations = InventoryLocationPolicy::resolve($remote_locations);
			if ([] === $locations) {
				$this->error = 'Chưa cấu hình chi nhánh Sapo nhận đơn online.';
				return null;
			}

			$variant_ids = array_values(array_unique(array_map(static fn (array $line): string => $line['variant_id'], $lines)));
			$location_ids = array_values(array_unique(array_map(static fn (array $location): string => $location['id'], $locations)));
			$availability = [];
			foreach ((array) $this->gateway->get_availability($variant_ids, $location_ids) as $row) {
				if (! is_array($row)) {
					continue;
				}
				$key = (string) ($row['location_id'] ?? '') . ':' . (string) ($row['variant_id'] ?? '');
				$availability[$key] = (float) ($row['available'] ?? 0);
			}

			$this->selected_location = (new LocationAllocator())->choose($locations, $lines, $availability);
			if (null === $this->selected_location) {
				$this->error = 'Không có chi nhánh Sapo nào đủ tồn cho toàn bộ giỏ hàng.';
			}
		} catch (SapoException $exception) {
			if (ErrorCode::AUTH === $exception->error_code()) {
				CapabilityGate::invalidate();
			}
			$this->error = 'Không thể xác minh tồn kho Sapo lúc checkout; vui lòng thử lại sau.';
		} catch (\Throwable $exception) {
			$this->error = 'Không thể xác minh tồn kho Sapo lúc checkout; vui lòng thử lại sau.';
		}

		return $this->selected_location;
	}

	/**
	 * @param array<int, array<string, mixed>> $cart
	 * @return array<int, array{variant_id: string, quantity: float}>
	 */
	private function cart_lines(array $cart): array
	{
		$by_variant = [];
		foreach ($cart as $item) {
			$product = is_array($item) ? ($item['data'] ?? null) : null;
			if (! is_object($product) || ! method_exists($product, 'get_sku')) {
				$this->error = 'Giỏ hàng có sản phẩm chưa sẵn sàng đồng bộ Sapo.';
				return [];
			}

			$sku = SkuNormalizer::match_key((string) $product->get_sku());
			$type = method_exists($product, 'get_type') && 'variation' === $product->get_type()
				? ProductType::VARIATION
				: ProductType::SIMPLE;
			$matches = array_values(array_filter(
				$this->mappings->find_by_sku($sku),
				static fn (array $mapping): bool => MappingStatus::ACTIVE === ($mapping['mapping_status'] ?? '')
					&& $type === ($mapping['product_type'] ?? '')
			));
			if (1 !== count($matches)) {
				$this->error = 'Giỏ hàng có SKU chưa được mapping duy nhất với Sapo.';
				return [];
			}

			$quantity = (float) (is_array($item) ? ($item['quantity'] ?? 0) : 0);
			if ($quantity <= 0) {
				$this->error = 'Giỏ hàng có số lượng sản phẩm không hợp lệ.';
				return [];
			}
			$variant_id = (string) ($matches[0]['sapo_variant_id'] ?? '');
			$by_variant[$variant_id] = ($by_variant[$variant_id] ?? 0.0) + $quantity;
		}

		$lines = [];
		foreach ($by_variant as $variant_id => $quantity) {
			$lines[] = ['variant_id' => $variant_id, 'quantity' => (float) $quantity];
		}

		return $lines;
	}
}
