<?php
/**
 * Reconciles Sapo availability into the Woo stock view.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Application;

use WooSapoSync\Contracts\SapoGateway;
use WooSapoSync\Domain\Inventory\StockAvailabilityCalculator;
use WooSapoSync\Infrastructure\Sapo\ErrorCode;
use WooSapoSync\Infrastructure\Sapo\Exception\SapoException;
use WooSapoSync\Infrastructure\WordPress\InventoryLocationPolicy;
use WooSapoSync\Infrastructure\WordPress\Repository\ProductMappingRepository;
use WooSapoSync\Infrastructure\WooCommerce\ProductStockUpdater;

defined('ABSPATH') || exit;

/* phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages and normalized error codes are logged, not rendered as browser output. */

final class InventoryReconciler
{
	private const MAX_LOCATION_PAGES = 100;

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
		$active_count = $this->mappings->count_active();
		$mode = $shadow ? 'shadow' : 'write';
		if (0 === $active_count) {
			return ['mode' => $mode, 'mapped' => 0, 'updated' => 0, 'differences' => 0, 'errors' => []];
		}

		$remote_location_items = [];
		$cursor = null;
		$seen_cursors = [];
		$location_pagination_complete = false;
		for ($page = 0; $page < self::MAX_LOCATION_PAGES; $page++) {
			$remote_locations = $this->gateway->list_locations($cursor);
			$items = (array) ($remote_locations['items'] ?? $remote_locations);
			foreach ($items as $item) {
				if (is_array($item)) {
					$remote_location_items[] = $item;
				}
			}
			$next = isset($remote_locations['next_cursor']) && null !== $remote_locations['next_cursor'] ? (string) $remote_locations['next_cursor'] : '';
			if ('' === $next) {
				$location_pagination_complete = true;
				break;
			}
			if (isset($seen_cursors[$next])) {
				throw new SapoException(ErrorCode::CONFLICT, 'Sapo location pagination repeated a cursor.');
			}
			$seen_cursors[$next] = true;
			$cursor = $next;
		}
		if (! $location_pagination_complete) {
			throw new SapoException(ErrorCode::CONFLICT, 'Sapo location list exceeds the safe pagination limit.');
		}
		$locations = InventoryLocationPolicy::resolve($remote_location_items);
		$eligible_locations = array_values(array_filter($locations, static fn (array $location): bool => ! empty($location['serves'])));
		$report = ['mode' => $mode, 'mapped' => $active_count, 'updated' => 0, 'differences' => 0, 'errors' => []];
		if ([] === $eligible_locations) {
			$report['errors'][] = 'NO_ELIGIBLE_LOCATIONS';
			return $report;
		}

		$location_ids = array_values(array_unique(array_map(static fn (array $location): string => $location['id'], $eligible_locations)));

		// Keep each remote request bounded. Keyset pagination avoids loading the
		// complete mapping table into PHP memory before the first stock update.
		$last_mapping_id = 0;
		while (true) {
			$batch = $this->mappings->find_active_after_id(500, $last_mapping_id);
			if ([] === $batch) {
				break;
			}
			$next_mapping_id = (int) ($batch[count($batch) - 1]['id'] ?? 0);
			if ($next_mapping_id <= $last_mapping_id) {
				$report['errors'][] = 'MAPPING_PAGINATION_STALLED';
				break;
			}
			$last_mapping_id = $next_mapping_id;
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
