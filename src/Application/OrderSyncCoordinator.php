<?php
/**
 * Builds an idempotent order outbox record without making a remote call.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Application;

use WooSapoSync\Domain\ValueObjects\ExternalReference;
use WooSapoSync\Domain\Sync\OperationType;
use WooSapoSync\Infrastructure\WordPress\Repository\SyncOperationRepository;

defined('ABSPATH') || exit;

final class OrderSyncCoordinator
{
	private SyncOperationRepository $operations;

	public function __construct(SyncOperationRepository $operations)
	{
		$this->operations = $operations;
	}

	/**
	 * @param array<string, mixed> $order_snapshot
	 * @return array<string, mixed>|null
	 */
	public function enqueue(int $woo_order_id, string $site_uuid, array $order_snapshot): ?array
	{
		if ($woo_order_id <= 0) {
			return null;
		}

		$reference = ExternalReference::for_woo_order($woo_order_id, $site_uuid);
		$encoded = json_encode($order_snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
		$encoded = false === $encoded ? '{}' : $encoded;

		return $this->operations->get_or_create([
			'operation_type' => OperationType::CREATE_ORDER,
			'aggregate_type' => 'woo_order',
			'aggregate_id' => (string) $woo_order_id,
			'external_reference' => $reference->value(),
			'request_hash' => hash('sha256', $encoded),
		]);
	}

	/**
	 * @param array<string, mixed> $order_snapshot
	 * @return array<string, mixed>|null
	 */
	public function enqueue_cancellation(int $woo_order_id, string $site_uuid, array $order_snapshot): ?array
	{
		if ($woo_order_id <= 0) {
			return null;
		}

		$reference = ExternalReference::for_cancel_order($woo_order_id, $site_uuid);
		$encoded = json_encode($order_snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
		$encoded = false === $encoded ? '{}' : $encoded;

		return $this->operations->get_or_create([
			'operation_type' => OperationType::CANCEL_ORDER,
			'aggregate_type' => 'woo_order',
			'aggregate_id' => (string) $woo_order_id,
			'external_reference' => $reference->value(),
			'request_hash' => hash('sha256', $encoded),
		]);
	}

	public function cancel_pending_create(int $woo_order_id): int
	{
		if ($woo_order_id <= 0) {
			return 0;
		}

		return $this->operations->cancel_pending_create('woo_order', (string) $woo_order_id);
	}
}
