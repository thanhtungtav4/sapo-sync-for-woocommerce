<?php
/**
 * Safe gateway used until the Sapo Omni/POS contract has been verified.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Infrastructure\Sapo;

use WooSapoSync\Contracts\SapoGateway;
use WooSapoSync\Domain\ValueObjects\ExternalReference;
use WooSapoSync\Infrastructure\Sapo\Exception\UnsupportedCapabilityException;

defined('ABSPATH') || exit;

/* phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages and context are not browser output. */

final class UnavailableGateway implements SapoGateway
{
	public function test_connection(): ConnectionResult
	{
		return new ConnectionResult(false, 'Sapo Omni/POS API capability gate chưa được xác minh.');
	}

	public function capabilities(): CapabilitySnapshot
	{
		return new CapabilitySnapshot();
	}

	public function list_locations(?string $cursor = null): array
	{
		$this->unsupported('locations');
	}

	public function list_variants(?string $cursor = null, ?string $modified_after = null): array
	{
		$this->unsupported('variants');
	}

	public function get_availability(array $variant_ids, array $location_ids): array
	{
		$this->unsupported('availability_by_location');
	}

	public function get_prices(array $variant_ids, ?string $price_list_id = null): array
	{
		$this->unsupported('prices');
	}

	public function find_customer(array $lookup): ?array
	{
		$this->unsupported('customers');
	}

	public function create_customer(array $customer): array
	{
		$this->unsupported('customers');
	}

	public function delete_customer(string $sapo_customer_id): bool
	{
		$this->unsupported('customers');
	}

	public function find_order_by_external_reference(ExternalReference $reference): ?array
	{
		$this->unsupported('order_external_reference_lookup');
	}

	public function create_and_approve_order(array $command): array
	{
		$this->unsupported('create_and_approve_orders');
	}

	public function get_order_state(string $sapo_order_id): array
	{
		$this->unsupported('order_state');
	}

	public function cancel_order(string $sapo_order_id, string $reason): array
	{
		$this->unsupported('cancel_orders');
	}

	private function unsupported(string $capability): void
	{
		throw new UnsupportedCapabilityException($capability);
	}
}
