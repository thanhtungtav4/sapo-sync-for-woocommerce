<?php
/**
 * Chooses one Sapo location for a complete Woo cart.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Domain\Inventory;

defined('ABSPATH') || exit;

final class LocationAllocator
{
	/**
	 * @param array<int, array{id: string, priority: int, serves: bool}> $locations
	 * @param array<int, array{variant_id: string, quantity: float}> $lines
	 * @param array<string, float> $availability Keyed by "location_id:variant_id".
	 */
	public function choose(array $locations, array $lines, array $availability): ?string
	{
		usort(
			$locations,
			static fn (array $left, array $right): int => $left['priority'] <=> $right['priority']
		);

		foreach ($locations as $location) {
			if (empty($location['serves'])) {
				continue;
			}

			$can_fulfil = true;

			foreach ($lines as $line) {
				$key = $location['id'] . ':' . $line['variant_id'];
				$available = (float) ($availability[$key] ?? 0);

				if ($available < (float) $line['quantity']) {
					$can_fulfil = false;
					break;
				}
			}

			if ($can_fulfil) {
				return $location['id'];
			}
		}

		return null;
	}
}
