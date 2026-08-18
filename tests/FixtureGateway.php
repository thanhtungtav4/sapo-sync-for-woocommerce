<?php

use WooSapoSync\Contracts\SapoGateway;
use WooSapoSync\Domain\ValueObjects\ExternalReference;
use WooSapoSync\Infrastructure\Sapo\CapabilitySnapshot;
use WooSapoSync\Infrastructure\Sapo\ConnectionResult;

/**
 * Deterministic gateway used by contract tests.
 *
 * Fixtures are normalized at the gateway boundary. Raw Sapo captures must be
 * scrubbed and mapped before they are added to tests/fixtures/contract.
 */
final class WooSapoFixtureGateway implements SapoGateway
{
	/**
	 * @param array<string, mixed> $fixtures
	 */
	public function __construct(private array $fixtures)
	{
	}

	public function test_connection(): ConnectionResult
	{
		return new ConnectionResult(true, 'Fixture gateway is available.', ['fixture' => true]);
	}

	public function capabilities(): CapabilitySnapshot
	{
		return new CapabilitySnapshot([
			'authentication' => true,
			'locations' => true,
			'variants' => true,
			'availability_by_location' => true,
			'prices' => true,
			'customers' => true,
			'create_and_approve_orders' => true,
			'order_external_reference_lookup' => true,
			'order_state' => true,
			'cancel_orders' => true,
			'webhooks_or_polling' => true,
		]);
	}

	/**
	 * @return array{items: array<int, array<string, mixed>>, next_cursor: string|null}
	 */
	public function list_locations(?string $cursor = null): array
	{
		return $this->page('locations');
	}

	/**
	 * @return array{items: array<int, array<string, mixed>>, next_cursor: string|null}
	 */
	public function list_variants(?string $cursor = null, ?string $modified_after = null): array
	{
		return $this->page('variants');
	}

	/**
	 * @param string[] $variant_ids
	 * @param string[] $location_ids
	 * @return array<int, array{variant_id: string, location_id: string, available: float}>
	 */
	public function get_availability(array $variant_ids, array $location_ids): array
	{
		return array_values(array_filter(
			(array) ($this->fixtures['availability'] ?? []),
			static fn ($row): bool => is_array($row)
				&& in_array((string) ($row['variant_id'] ?? ''), $variant_ids, true)
				&& in_array((string) ($row['location_id'] ?? ''), $location_ids, true)
		));
	}

	/**
	 * @param string[] $variant_ids
	 * @return array<int, array{variant_id: string, price: string, price_list_id: string|null}>
	 */
	public function get_prices(array $variant_ids, ?string $price_list_id = null): array
	{
		return array_values(array_filter(
			(array) ($this->fixtures['prices'] ?? []),
			static fn ($row): bool => is_array($row)
				&& in_array((string) ($row['variant_id'] ?? ''), $variant_ids, true)
		));
	}

	/**
	 * @param array<string, mixed> $lookup
	 * @return array<string, mixed>|null
	 */
	public function find_customer(array $lookup): ?array
	{
		$customer = $this->fixtures['customer'] ?? null;
		return is_array($customer) ? $customer : null;
	}

	/**
	 * @param array<string, mixed> $customer
	 * @return array<string, mixed>
	 */
	public function create_customer(array $customer): array
	{
		$created = $this->fixtures['created_customer'] ?? $customer;
		return is_array($created) ? $created : $customer;
	}

	public function delete_customer(string $sapo_customer_id): bool
	{
		return '' !== trim($sapo_customer_id);
	}

	public function find_order_by_external_reference(ExternalReference $reference): ?array
	{
		$order = $this->fixtures['order'] ?? null;
		if (! is_array($order) || (string) ($order['external_reference'] ?? '') !== $reference->value()) {
			return null;
		}

		return $order;
	}

	/**
	 * @param array<string, mixed> $command
	 * @return array<string, mixed>
	 */
	public function create_and_approve_order(array $command): array
	{
		$created = $this->fixtures['created_order'] ?? $command;
		return is_array($created) ? $created : $command;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_order_state(string $sapo_order_id): array
	{
		$state = $this->fixtures['order_state'] ?? [];
		return is_array($state) ? $state : [];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function cancel_order(string $sapo_order_id, string $reason): array
	{
		$cancelled = $this->fixtures['cancelled_order'] ?? [];
		return is_array($cancelled) ? $cancelled : [];
	}

	/**
	 * @return array{items: array<int, array<string, mixed>>, next_cursor: string|null}
	 */
	private function page(string $key): array
	{
		$page = $this->fixtures[$key] ?? [];
		return [
			'items' => is_array($page) ? array_values($page) : [],
			'next_cursor' => null,
		];
	}
}
