<?php
/**
 * Reads simple products and variations through WooCommerce CRUD.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Infrastructure\WooCommerce;

use WooSapoSync\Domain\Product\ProductType;

defined('ABSPATH') || exit;

final class CatalogReader
{
	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array
	{
		if (! function_exists('wc_get_products')) {
			return [];
		}

		$products = wc_get_products([
			'limit' => -1,
			'paginate' => false,
			'status' => ['publish', 'private', 'draft'],
			'orderby' => 'ID',
			'order' => 'ASC',
		]);
		$items = [];

		foreach ((array) $products as $product) {
			if (! is_object($product) || ! method_exists($product, 'get_type')) {
				continue;
			}

			$type = (string) $product->get_type();
			if ('simple' === $type) {
				$items[] = $this->snapshot($product, ProductType::SIMPLE, null);
				continue;
			}

			if ('variable' === $type && method_exists($product, 'get_children')) {
				foreach ((array) $product->get_children() as $variation_id) {
					$variation = function_exists('wc_get_product') ? wc_get_product((int) $variation_id) : null;
					if (is_object($variation)) {
						$items[] = $this->snapshot($variation, ProductType::VARIATION, (int) $product->get_id());
					}
				}
			}
		}

		return array_values(array_filter($items, static fn (array $item): bool => '' !== $item['object_key']));
	}

	/**
	 * @param mixed $product
	 * @return array<string, mixed>
	 */
	private function snapshot($product, string $type, ?int $parent_id): array
	{
		$id = method_exists($product, 'get_id') ? (int) $product->get_id() : 0;
		$sku = method_exists($product, 'get_sku') ? trim((string) $product->get_sku()) : '';
		return [
			'object_key' => $type . ':' . $id,
			'product_id' => $parent_id ?: $id,
			'variation_id' => $parent_id ? $id : null,
			'sku' => $sku,
			'product_type' => $type,
		];
	}
}
