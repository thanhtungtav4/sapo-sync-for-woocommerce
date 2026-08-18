<?php
/**
 * Resolves configured online locations against the remote Sapo location list.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Infrastructure\WordPress;

defined('ABSPATH') || exit;

final class InventoryLocationPolicy
{
	public const OPTION_KEY = 'woo_sapo_sync_location_policy';

	private function __construct()
	{
	}

	/**
	 * @param array<int, array<string, mixed>> $remote_locations
	 * @return array<int, array{id: string, priority: int, serves: bool}>
	 */
	public static function resolve(array $remote_locations): array
	{
		$stored = get_option(self::OPTION_KEY, []);
		$stored = is_array($stored) ? $stored : [];
		$stored_by_id = [];
		foreach ($stored as $policy) {
			if (is_array($policy) && ! empty($policy['id'])) {
				$stored_by_id[(string) $policy['id']] = $policy;
			}
		}

		$locations = [];
		$has_explicit_policy = [] !== $stored_by_id;
		foreach ($remote_locations as $index => $remote) {
			$id = trim((string) ($remote['id'] ?? $remote['location_id'] ?? ''));
			if ('' === $id) {
				continue;
			}

			$policy = $stored_by_id[$id] ?? [];
			$locations[] = [
				'id' => $id,
				'priority' => isset($policy['priority']) ? (int) $policy['priority'] : (int) $index,
				// An empty policy is fail-closed: operators must explicitly allowlist
				// locations before stock can be exposed or assigned at checkout.
				'serves' => $has_explicit_policy && array_key_exists($id, $stored_by_id)
					? (array_key_exists('serves', $policy) ? (bool) $policy['serves'] : true)
					: false,
			];
		}

		usort($locations, static fn (array $left, array $right): int => $left['priority'] <=> $right['priority']);
		return $locations;
	}
}
