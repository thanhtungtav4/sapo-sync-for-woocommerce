<?php
/**
 * Writes product stock through WooCommerce CRUD only.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Infrastructure\WooCommerce;

defined('ABSPATH') || exit;

final class ProductStockUpdater
{
	/**
	 * @var callable|null
	 */
	private $product_loader;

	/**
	 * @param callable|null $product_loader Receives a Woo product/variation ID.
	 */
	public function __construct($product_loader = null)
	{
		$this->product_loader = $product_loader;
	}

	/**
	 * @param array<string, mixed> $mapping
	 * @return array{updated: bool, previous: float|null, current: float, error: string}
	 */
	public function update(array $mapping, float $available): array
	{
		$id = ! empty($mapping['woo_variation_id']) ? (int) $mapping['woo_variation_id'] : (int) ($mapping['woo_product_id'] ?? 0);
		$product = $this->load_product($id);
		if (! is_object($product)) {
			return ['updated' => false, 'previous' => null, 'current' => max(0.0, $available), 'error' => 'MISSING_WOO_PRODUCT'];
		}
		if (! method_exists($product, 'set_manage_stock') || ! method_exists($product, 'set_stock_quantity')) {
			return ['updated' => false, 'previous' => null, 'current' => max(0.0, $available), 'error' => 'UNSUPPORTED_WOO_PRODUCT'];
		}

		$previous = method_exists($product, 'get_stock_quantity') ? $product->get_stock_quantity() : null;
		$previous = null === $previous ? null : (float) $previous;
		$current = max(0.0, $available);
		$product->set_manage_stock(true);
		$product->set_stock_quantity($current);
		if (method_exists($product, 'set_stock_status')) {
			$product->set_stock_status($current > 0 ? 'instock' : 'outofstock');
		}
		if (method_exists($product, 'save')) {
			$product->save();
		}

		return [
			'updated' => null === $previous || abs($previous - $current) > 0.00001,
			'previous' => $previous,
			'current' => $current,
			'error' => '',
		];
	}

	/**
	 * Read current stock without mutating the Woo product.
	 *
	 * @param array<string, mixed> $mapping
	 */
	public function current(array $mapping): ?float
	{
		$id = ! empty($mapping['woo_variation_id']) ? (int) $mapping['woo_variation_id'] : (int) ($mapping['woo_product_id'] ?? 0);
		$product = $this->load_product($id);
		if (! is_object($product) || ! method_exists($product, 'get_stock_quantity')) {
			return null;
		}

		$value = $product->get_stock_quantity();
		return null === $value ? null : (float) $value;
	}

	/**
	 * @return mixed
	 */
	private function load_product(int $id)
	{
		if ($id <= 0) {
			return null;
		}
		if (is_callable($this->product_loader)) {
			return call_user_func($this->product_loader, $id);
		}

		return function_exists('wc_get_product') ? wc_get_product($id) : null;
	}
}
