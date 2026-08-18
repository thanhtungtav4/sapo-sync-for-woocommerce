<?php
/**
 * Synchronizes SKU-based mapping candidates without mutating Woo products.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Application;

use WooSapoSync\Contracts\SapoGateway;
use WooSapoSync\Domain\Product\MappingMatcher;
use WooSapoSync\Domain\Product\MappingStatus;
use WooSapoSync\Infrastructure\WordPress\Repository\ProductMappingRepository;
use WooSapoSync\Infrastructure\WooCommerce\CatalogReader;

defined('ABSPATH') || exit;

final class MappingSynchronizer
{
	private SapoGateway $gateway;

	private ProductMappingRepository $mappings;

	private CatalogReader $catalog;

	public function __construct(SapoGateway $gateway, ProductMappingRepository $mappings, CatalogReader $catalog)
	{
		$this->gateway = $gateway;
		$this->mappings = $mappings;
		$this->catalog = $catalog;
	}

	/**
	 * @return array{woo_items: int, sapo_items: int, active: int, needs_review: int, saved: int}
	 */
	public function sync(): array
	{
		$woo_items = $this->catalog->all();
		$sapo_items = [];
		$cursor = null;
		$seen_cursors = [];
		for ($page = 0; $page < 100; $page++) {
			$response = $this->gateway->list_variants($cursor);
			foreach ((array) ($response['items'] ?? []) as $item) {
				if (is_array($item)) {
					$sapo_items[] = $item;
				}
			}

			$next = isset($response['next_cursor']) && null !== $response['next_cursor'] ? (string) $response['next_cursor'] : '';
			if ('' === $next || isset($seen_cursors[$next])) {
				break;
			}
			$seen_cursors[$next] = true;
			$cursor = $next;
		}

		$results = MappingMatcher::match($woo_items, $sapo_items);
		$report = ['woo_items' => count($woo_items), 'sapo_items' => count($sapo_items), 'active' => 0, 'needs_review' => 0, 'saved' => 0];
		foreach ($results as $result) {
			$existing = $this->mappings->find_by_woo_object_key((string) ($result['woo_object_key'] ?? ''));
			if (is_array($existing)) {
				// Price policy is an operator decision and survives a mapping refresh.
				$result['price_source'] = (string) ($existing['price_source'] ?? 'WOO');
				$result['sapo_price_list_id'] = $existing['sapo_price_list_id'] ?? null;
			}
			if (MappingStatus::ACTIVE !== ($result['mapping_status'] ?? '') && is_array($existing)) {
				// A changed/ambiguous SKU must not erase the last known Sapo IDs.
				$result['sapo_product_id'] = (string) ($existing['sapo_product_id'] ?? '');
				$result['sapo_variant_id'] = (string) ($existing['sapo_variant_id'] ?? '');
			}

			$id = $this->mappings->save($result);
			if ($id > 0) {
				$report['saved']++;
			}
			if (MappingStatus::ACTIVE === ($result['mapping_status'] ?? '')) {
				$report['active']++;
			} else {
				$report['needs_review']++;
			}
		}

		return $report;
	}
}
