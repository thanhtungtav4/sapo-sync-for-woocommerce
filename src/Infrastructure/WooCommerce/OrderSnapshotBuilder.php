<?php
/**
 * Converts a WooCommerce order CRUD object to a deterministic sync snapshot.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Infrastructure\WooCommerce;

use WooSapoSync\Domain\Customer\CustomerNormalizer;
use WooSapoSync\Domain\Product\ProductType;

defined('ABSPATH') || exit;

final class OrderSnapshotBuilder
{
	/**
	 * @param mixed $order
	 * @return array<string, mixed>
	 */
	public function build($order): array
	{
		if (! is_object($order) || ! method_exists($order, 'get_id') || ! method_exists($order, 'get_items')) {
			return [];
		}

		$lines = [];
		foreach ((array) $order->get_items('line_item') as $item_id => $item) {
			$product = method_exists($item, 'get_product') ? $item->get_product() : null;
			$product_id = method_exists($item, 'get_product_id') ? (int) $item->get_product_id() : 0;
			$variation_id = method_exists($item, 'get_variation_id') ? (int) $item->get_variation_id() : 0;
			$quantity = method_exists($item, 'get_quantity') ? (float) $item->get_quantity() : 0.0;
			$sku = is_object($product) && method_exists($product, 'get_sku') ? trim((string) $product->get_sku()) : '';
			$type = is_object($product) && method_exists($product, 'is_type') && $product->is_type('variation')
				? ProductType::VARIATION
				: ProductType::SIMPLE;

			$lines[] = [
				'item_id' => (int) $item_id,
				'product_id' => $product_id,
				'variation_id' => $variation_id > 0 ? $variation_id : null,
				'product_type' => $type,
				'sku' => $sku,
				'quantity' => $quantity,
				'subtotal' => $this->item_amount($item, 'get_subtotal'),
				'total' => $this->item_amount($item, 'get_total'),
				'tax' => $this->item_amount($item, 'get_total_tax'),
				'is_gift' => $this->item_amount($item, 'get_total') <= 0.0,
			];
		}

		$phone = method_exists($order, 'get_billing_phone') ? CustomerNormalizer::phone((string) $order->get_billing_phone()) : '';
		$email = method_exists($order, 'get_billing_email') ? CustomerNormalizer::email((string) $order->get_billing_email()) : '';
		$billing_address = $this->address($order, 'billing');
		$shipping_address = $this->address($order, 'shipping');
		$discount_codes = [];
		if (method_exists($order, 'get_items')) {
			foreach ((array) $order->get_items('coupon') as $coupon) {
				if (! is_object($coupon) || ! method_exists($coupon, 'get_code')) {
					continue;
				}
				$code = trim((string) $coupon->get_code());
				$amount = method_exists($coupon, 'get_discount') ? (float) $coupon->get_discount() : 0.0;
				if ('' !== $code && $amount > 0) {
					$discount_codes[] = ['code' => $code, 'amount' => $amount, 'type' => 'fixed_amount'];
				}
			}
		}

		return [
			'woo_order_id' => (int) $order->get_id(),
			'status' => method_exists($order, 'get_status') ? (string) $order->get_status() : '',
			'currency' => method_exists($order, 'get_currency') ? (string) $order->get_currency() : '',
			'total' => $this->amount($order, 'get_total'),
			'subtotal' => $this->amount($order, 'get_subtotal'),
			'discount_total' => $this->amount($order, 'get_discount_total'),
			'shipping_total' => $this->amount($order, 'get_shipping_total'),
			'tax_total' => $this->amount($order, 'get_total_tax'),
			'discount_codes' => $discount_codes,
			'payment_method' => method_exists($order, 'get_payment_method') ? (string) $order->get_payment_method() : '',
			'is_paid' => method_exists($order, 'is_paid') && $order->is_paid(),
			'assigned_location_id' => method_exists($order, 'get_meta') ? (string) $order->get_meta('_woo_sapo_assigned_location', true) : '',
			'customer' => [
				'phone' => $phone,
				'email' => $email,
				'first_name' => method_exists($order, 'get_billing_first_name') ? (string) $order->get_billing_first_name() : '',
				'last_name' => method_exists($order, 'get_billing_last_name') ? (string) $order->get_billing_last_name() : '',
				'address_1' => (string) ($billing_address['address1'] ?? ''),
				'address_2' => (string) ($billing_address['address2'] ?? ''),
				'city' => (string) ($billing_address['city'] ?? ''),
				'state' => (string) ($billing_address['province'] ?? ''),
				'postcode' => (string) ($billing_address['zip'] ?? ''),
				'country' => (string) ($billing_address['country'] ?? ''),
			],
			'billing_address' => $billing_address,
			'shipping_address' => $shipping_address,
			'lines' => $lines,
		];
	}

	/**
	 * @param mixed $order
	 * @return array<string, string>
	 */
	private function address($order, string $type): array
	{
		$prefix = 'shipping' === $type ? 'shipping' : 'billing';
		$get = static function ($order, string $method): string {
			return method_exists($order, $method) ? (string) $order->{$method}() : '';
		};

		return array_filter([
			'first_name' => $get($order, 'get_' . $prefix . '_first_name'),
			'last_name' => $get($order, 'get_' . $prefix . '_last_name'),
			'address1' => $get($order, 'get_' . $prefix . '_address_1'),
			'address2' => $get($order, 'get_' . $prefix . '_address_2'),
			'city' => $get($order, 'get_' . $prefix . '_city'),
			'province' => $get($order, 'get_' . $prefix . '_state'),
			'zip' => $get($order, 'get_' . $prefix . '_postcode'),
			'country' => $get($order, 'get_' . $prefix . '_country'),
			'phone' => 'billing' === $prefix ? $get($order, 'get_billing_phone') : $get($order, 'get_shipping_phone'),
		], static fn ($value): bool => '' !== trim((string) $value));
	}

	/**
	 * @param mixed $item
	 */
	private function item_amount($item, string $method): float
	{
		return is_object($item) && method_exists($item, $method) ? (float) $item->{$method}() : 0.0;
	}

	/**
	 * @param mixed $order
	 */
	private function amount($order, string $method): float
	{
		return method_exists($order, $method) ? (float) $order->{$method}() : 0.0;
	}
}
