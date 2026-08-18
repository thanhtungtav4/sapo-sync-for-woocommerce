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

		$items = [];
		$page = 1;
		$max_pages = 1;
		$limit = 250;

		do {
			$result = wc_get_products([
				'limit' => $limit,
				'page' => $page,
				'paginate' => true,
				'status' => ['publish', 'private', 'draft'],
				'orderby' => 'ID',
				'order' => 'ASC',
			]);
			if (is_object($result) && isset($result->products)) {
				$products = (array) $result->products;
				$max_pages = max($page, (int) ($result->max_num_pages ?? $page));
			} else {
				// Older WooCommerce versions may ignore paginate=true and return the
				// complete result. Treat that as a single page for compatibility.
				$products = (array) $result;
				$max_pages = $page;
			}

			foreach ($products as $product) {
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

			$page++;
		} while ($page <= $max_pages);

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
