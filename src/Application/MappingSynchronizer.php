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
use WooSapoSync\Infrastructure\Sapo\ErrorCode;
use WooSapoSync\Infrastructure\Sapo\Exception\SapoException;
use WooSapoSync\Infrastructure\WordPress\Repository\ProductMappingRepository;
use WooSapoSync\Infrastructure\WooCommerce\CatalogReader;

defined('ABSPATH') || exit;

/* phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are logged, not rendered as browser output. */

final class MappingSynchronizer
{
	private const MAX_SAPO_PAGES = 1000;

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
		for ($page = 0; $page < self::MAX_SAPO_PAGES; $page++) {
			$response = $this->gateway->list_variants($cursor);
			foreach ((array) ($response['items'] ?? []) as $item) {
				if (is_array($item)) {
					$sapo_items[] = $item;
				}
			}

			$next = isset($response['next_cursor']) && null !== $response['next_cursor'] ? (string) $response['next_cursor'] : '';
			if ('' === $next) {
				break;
			}
			if (isset($seen_cursors[$next])) {
				throw new SapoException(ErrorCode::CONFLICT, 'Sapo variant pagination repeated a cursor; mapping was not changed.');
			}
			$seen_cursors[$next] = true;
			$cursor = $next;
		}
		if ('' !== (string) $cursor && $page >= self::MAX_SAPO_PAGES) {
			throw new SapoException(ErrorCode::CONFLICT, 'Sapo catalog exceeds the safe mapping page limit; mapping was not changed.');
		}

		$results = MappingMatcher::match($woo_items, $sapo_items);
		$report = ['woo_items' => count($woo_items), 'sapo_items' => count($sapo_items), 'active' => 0, 'needs_review' => 0, 'saved' => 0];
		foreach ($results as $result) {
			$existing = $this->mappings->find_by_woo_object_key((string) ($result['woo_object_key'] ?? ''));
			if (is_array($existing)) {
				// Price policy is an operator decision and survives a mapping refresh.
				$result['price_source'] = (string) ($existing['price_source'] ?? 'WOO');
				$result['sapo_price_list_id'] = $existing['sapo_price_list_id'] ?? null;
				// Operational timestamps are owned by the verification/inventory jobs;
				// a catalog refresh must not erase their audit trail.
				$result['last_verified_at'] = $existing['last_verified_at'] ?? null;
				$result['last_inventory_sync_at'] = $existing['last_inventory_sync_at'] ?? null;
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
