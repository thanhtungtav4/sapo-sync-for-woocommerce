<?php
/**
 * Matches WooCommerce and Sapo product snapshots by exact SKU only.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Domain\Product;

use WooSapoSync\Domain\Sku\SkuNormalizer;

defined('ABSPATH') || exit;

final class MappingMatcher
{
	private function __construct()
	{
	}

	/**
	 * @param array<int, array<string, mixed>> $woo_items
	 * @param array<int, array<string, mixed>> $sapo_items
	 * @return array<int, array<string, mixed>>
	 */
	public static function match(array $woo_items, array $sapo_items): array
	{
		$by_sku = [];

		foreach ($sapo_items as $sapo_item) {
			$sku = SkuNormalizer::match_key((string) ($sapo_item['sku'] ?? ''));
			if (! SkuNormalizer::is_valid($sku)) {
				continue;
			}

			$by_sku[$sku][] = $sapo_item;
		}

		$results = [];
		foreach ($woo_items as $woo_item) {
			$sku_raw = SkuNormalizer::raw((string) ($woo_item['sku'] ?? ''));
			$sku_key = SkuNormalizer::match_key($sku_raw);
			$base = [
				'woo_object_key' => (string) ($woo_item['object_key'] ?? ''),
				'woo_product_id' => (int) ($woo_item['product_id'] ?? 0),
				'woo_variation_id' => ! empty($woo_item['variation_id']) ? (int) $woo_item['variation_id'] : null,
				'sku_raw' => $sku_raw,
				'sku_match_key' => $sku_key,
				'product_type' => (string) ($woo_item['product_type'] ?? ProductType::SIMPLE),
				'mapping_status' => MappingStatus::NEEDS_REVIEW,
				'reason' => 'NO_EXACT_SKU_MATCH',
			];

			if (! SkuNormalizer::is_valid($sku_key)) {
				$base['reason'] = 'EMPTY_SKU';
				$results[] = $base;
				continue;
			}

			$candidates = $by_sku[$sku_key] ?? [];
			if (count($candidates) !== 1) {
				$base['reason'] = count($candidates) > 1 ? 'DUPLICATE_SAPO_SKU' : 'NO_EXACT_SKU_MATCH';
				$results[] = $base;
				continue;
			}

			$sapo_item = $candidates[0];
			$sapo_type = (string) ($sapo_item['product_type'] ?? ProductType::SIMPLE);
			if ($sapo_type !== $base['product_type'] || ! ProductType::is_supported($sapo_type)) {
				$base['reason'] = 'PRODUCT_TYPE_MISMATCH';
				$results[] = $base;
				continue;
			}

			$base['mapping_status'] = MappingStatus::ACTIVE;
			$base['reason'] = 'EXACT_SKU_MATCH';
			$base['sapo_product_id'] = (string) ($sapo_item['product_id'] ?? '');
			$base['sapo_variant_id'] = (string) ($sapo_item['variant_id'] ?? '');
			$results[] = $base;
		}

		return $results;
	}
}
