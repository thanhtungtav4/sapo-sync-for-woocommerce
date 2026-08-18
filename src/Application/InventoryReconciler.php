<?php
/**
 * Reconciles Sapo availability into the Woo stock view.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Application;

use WooSapoSync\Contracts\SapoGateway;
use WooSapoSync\Domain\Inventory\StockAvailabilityCalculator;
use WooSapoSync\Domain\Product\MappingStatus;
use WooSapoSync\Infrastructure\WordPress\InventoryLocationPolicy;
use WooSapoSync\Infrastructure\WordPress\Repository\ProductMappingRepository;
use WooSapoSync\Infrastructure\WooCommerce\ProductStockUpdater;

defined('ABSPATH') || exit;

final class InventoryReconciler
{
	private SapoGateway $gateway;

	private ProductMappingRepository $mappings;

	private ProductStockUpdater $stocks;

	public function __construct(SapoGateway $gateway, ProductMappingRepository $mappings, ProductStockUpdater $stocks)
	{
		$this->gateway = $gateway;
		$this->mappings = $mappings;
		$this->stocks = $stocks;
	}

	/**
	 * @return array{mode: string, mapped: int, updated: int, differences: int, errors: array<int, string>}
	 */
	public function sync(bool $shadow = true): array
	{
		$active = [];
		for ($offset = 0; $offset < 50000; $offset += 500) {
			$page = $this->mappings->find_active(500, $offset);
			$active = array_merge($active, $page);
			if (count($page) < 500) {
				break;
			}
		}
		$active = array_values(array_filter($active, static fn (array $mapping): bool => MappingStatus::ACTIVE === ($mapping['mapping_status'] ?? '')));
		if ([] === $active) {
			return ['mode' => $shadow ? 'shadow' : 'write', 'mapped' => 0, 'updated' => 0, 'differences' => 0, 'errors' => []];
		}

		$remote_location_items = [];
		$cursor = null;
		$seen_cursors = [];
		for ($page = 0; $page < 100; $page++) {
			$remote_locations = $this->gateway->list_locations($cursor);
			$items = (array) ($remote_locations['items'] ?? $remote_locations);
			foreach ($items as $item) {
				if (is_array($item)) {
					$remote_location_items[] = $item;
				}
			}
			$next = isset($remote_locations['next_cursor']) && null !== $remote_locations['next_cursor'] ? (string) $remote_locations['next_cursor'] : '';
			if ('' === $next || isset($seen_cursors[$next])) {
				break;
			}
			$seen_cursors[$next] = true;
			$cursor = $next;
		}
		$locations = InventoryLocationPolicy::resolve($remote_location_items);
		$eligible_locations = array_values(array_filter($locations, static fn (array $location): bool => ! empty($location['serves'])));
		$report = ['mode' => $shadow ? 'shadow' : 'write', 'mapped' => count($active), 'updated' => 0, 'differences' => 0, 'errors' => []];
		if ([] === $eligible_locations) {
			$report['errors'][] = 'NO_ELIGIBLE_LOCATIONS';
			return $report;
		}

		$location_ids = array_values(array_unique(array_map(static fn (array $location): string => $location['id'], $eligible_locations)));

		// Keep each remote request bounded. The gateway also chunks its query, but
		// batching here limits memory and lets a large catalog make incremental progress.
		foreach (array_chunk($active, 500) as $batch) {
			$variant_ids = array_values(array_unique(array_map(static fn (array $mapping): string => (string) $mapping['sapo_variant_id'], $batch)));
			$availability = $this->gateway->get_availability($variant_ids, $location_ids);
			$calculated = StockAvailabilityCalculator::calculate($eligible_locations, $availability);

			foreach ($batch as $mapping) {
				$variant_id = (string) $mapping['sapo_variant_id'];
				if (! array_key_exists($variant_id, $calculated)) {
					$report['errors'][] = 'MISSING_AVAILABILITY:' . $variant_id;
					continue;
				}
				$available = (float) $calculated[$variant_id];
				if ($shadow) {
					$current = $this->stocks->current($mapping);
					if ($current !== null && abs($current - $available) > 0.00001) {
						$report['differences']++;
					}
					continue;
				}

				$result = $this->stocks->update($mapping, $available);
				if ('' !== (string) ($result['error'] ?? '')) {
					$report['errors'][] = (string) $result['error'] . ':' . (string) ($mapping['woo_object_key'] ?? '');
					continue;
				}
				if ($result['updated']) {
					$report['updated']++;
				}
				if (! empty($mapping['id'])) {
					$this->mappings->mark_inventory_synced((int) $mapping['id']);
				}
			}
		}

		return $report;
	}

	/**
	 * Use the persisted safety setting. Shadow remains the default when missing/invalid.
	 *
	 * @return array{mode: string, mapped: int, updated: int, differences: int, errors: array<int, string>}
	 */
	public function sync_configured(): array
	{
		$settings = get_option('woo_sapo_sync_settings', []);
		$write = is_array($settings) && 'write' === ($settings['sync_mode'] ?? '');
		return $this->sync(! $write);
	}
}
