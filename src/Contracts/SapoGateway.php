<?php
/**
 * Boundary between domain services and Sapo Omni/POS.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Contracts;

use WooSapoSync\Domain\ValueObjects\ExternalReference;
use WooSapoSync\Infrastructure\Sapo\CapabilitySnapshot;
use WooSapoSync\Infrastructure\Sapo\ConnectionResult;

defined('ABSPATH') || exit;

interface SapoGateway
{
	public function test_connection(): ConnectionResult;

	public function capabilities(): CapabilitySnapshot;

	/**
	 * @return array{items: array<int, array<string, mixed>>, next_cursor: string|null}
	 */
	public function list_locations(?string $cursor = null): array;

	/**
	 * @return array{items: array<int, array<string, mixed>>, next_cursor: string|null}
	 */
	public function list_variants(?string $cursor = null, ?string $modified_after = null): array;

	/**
	 * @param string[] $variant_ids
	 * @param string[] $location_ids
	 * @return array<int, array{variant_id: string, location_id: string, available: float}>
	 */
	public function get_availability(array $variant_ids, array $location_ids): array;

	/**
	 * @param string[] $variant_ids
	 * @return array<int, array{variant_id: string, price: string, price_list_id: string|null}>
	 */
	public function get_prices(array $variant_ids, ?string $price_list_id = null): array;

	/**
	 * @param array<string, mixed> $lookup
	 * @return array<string, mixed>|null
	 */
	public function find_customer(array $lookup): ?array;

	/**
	 * @param array<string, mixed> $customer
	 * @return array<string, mixed>
	 */
	public function create_customer(array $customer): array;

	/**
	 * Remove a short-lived contract-test customer after verification.
	 */
	public function delete_customer(string $sapo_customer_id): bool;

	public function find_order_by_external_reference(ExternalReference $reference): ?array;

	/**
	 * @param array<string, mixed> $command
	 * @return array<string, mixed>
	 */
	public function create_and_approve_order(array $command): array;

	/**
	 * @return array<string, mixed>
	 */
	public function get_order_state(string $sapo_order_id): array;

	/**
	 * @return array<string, mixed>
	 */
	public function cancel_order(string $sapo_order_id, string $reason): array;
}
