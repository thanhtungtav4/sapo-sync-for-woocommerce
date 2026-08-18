<?php
/**
 * Calculates the Woo stock view from Sapo availability by eligible location.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Domain\Inventory;

defined('ABSPATH') || exit;

final class StockAvailabilityCalculator
{
	private function __construct()
	{
	}

	/**
	 * @param array<int, array{id: string, serves: bool}> $locations
	 * @param array<int, array{variant_id: string, location_id: string, available: float}> $availability
	 * @return array<string, float>
	 */
	public static function calculate(array $locations, array $availability): array
	{
		$eligible = [];
		foreach ($locations as $location) {
			if (! empty($location['serves'])) {
				$eligible[(string) $location['id']] = true;
			}
		}

		$stocks = [];
		foreach ($availability as $row) {
			$variant_id = trim((string) ($row['variant_id'] ?? ''));
			$location_id = trim((string) ($row['location_id'] ?? ''));
			if ('' === $variant_id || '' === $location_id || empty($eligible[$location_id])) {
				continue;
			}

			$available = max(0.0, (float) ($row['available'] ?? 0));
			$stocks[$variant_id] = max($stocks[$variant_id] ?? 0.0, $available);
		}

		return $stocks;
	}
}
