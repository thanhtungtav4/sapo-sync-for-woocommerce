<?php
/**
 * Idempotent outbox/ledger persistence.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Infrastructure\WordPress\Repository;

use WooSapoSync\Domain\Sync\OperationStatus;
use WooSapoSync\Domain\Sync\OperationType;

defined('ABSPATH') || exit;

/* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Internal custom-table reads/writes are required for the idempotent ledger; table names are derived from $wpdb->prefix and value arguments are passed to prepare(). */

final class SyncOperationRepository
{
	private $wpdb;

	private string $table;

	public function __construct($wpdb)
	{
		$this->wpdb = $wpdb;
		$this->table = $wpdb->prefix . 'wss_sapo_sync_operations';
	}

	/**
	 * Return the existing operation or create one. The unique operation/reference key
	 * makes retries safe when a remote request succeeded before the local response arrived.
	 *
	 * @param array<string, mixed> $operation
	 * @return array<string, mixed>|null
	 */
	public function get_or_create(array $operation): ?array
	{
		$type = trim((string) ($operation['operation_type'] ?? ''));
		$reference = trim((string) ($operation['external_reference'] ?? ''));
		if ($type === '' || $reference === '') {
			return null;
		}

		$existing = $this->find_by_reference($type, $reference);
		if ($existing) {
			return $existing;
		}

		$now = gmdate('Y-m-d H:i:s');
		$data = [
			'operation_type' => $type,
			'aggregate_type' => trim((string) ($operation['aggregate_type'] ?? 'order')),
			'aggregate_id' => trim((string) ($operation['aggregate_id'] ?? '')),
			'external_reference' => $reference,
			'request_hash' => preg_match('/^[a-f0-9]{64}$/', (string) ($operation['request_hash'] ?? ''))
				? (string) $operation['request_hash']
				: hash('sha256', (string) ($operation['request_hash'] ?? '')),
			'status' => OperationStatus::PENDING,
			'attempt_count' => 0,
			'created_at' => $now,
			'updated_at' => $now,
		];

		$this->wpdb->query($this->wpdb->prepare(
			"INSERT IGNORE INTO {$this->table} (operation_type, aggregate_type, aggregate_id, external_reference, request_hash, status, attempt_count, created_at, updated_at) VALUES (%s, %s, %s, %s, %s, %s, %d, %s, %s)",
			$data['operation_type'],
			$data['aggregate_type'],
			$data['aggregate_id'],
			$data['external_reference'],
			$data['request_hash'],
			$data['status'],
			$data['attempt_count'],
			$data['created_at'],
			$data['updated_at']
		));

		return $this->find_by_reference($type, $reference);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_by_reference(string $operation_type, string $external_reference): ?array
	{
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE operation_type = %s AND external_reference = %s LIMIT 1",
				$operation_type,
				$external_reference
			),
			ARRAY_A
		);

		return is_array($row) ? $row : null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_by_id(int $id): ?array
	{
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d LIMIT 1", $id),
			ARRAY_A
		);

		return is_array($row) ? $row : null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_by_sapo_object_id(string $sapo_object_id): ?array
	{
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare("SELECT * FROM {$this->table} WHERE sapo_object_id = %s ORDER BY id DESC LIMIT 1", $sapo_object_id),
			ARRAY_A
		);

		return is_array($row) ? $row : null;
	}

	public function mark_processing(int $id): bool
	{
		$now = gmdate('Y-m-d H:i:s');
		$stale = gmdate('Y-m-d H:i:s', time() - 900);
		$updated = $this->wpdb->query($this->wpdb->prepare(
			"UPDATE {$this->table} SET status = %s, attempt_count = attempt_count + 1, processing_at = %s, next_attempt_at = NULL, updated_at = %s WHERE id = %d AND ((status IN (%s, %s) AND (next_attempt_at IS NULL OR next_attempt_at <= %s)) OR (status = %s AND (processing_at IS NULL OR processing_at < %s)))",
			OperationStatus::PROCESSING,
			$now,
			$now,
			$id,
			OperationStatus::PENDING,
			OperationStatus::RETRY,
			$now,
			OperationStatus::PROCESSING,
			$stale
		));

		return 1 === (int) $updated;
	}

	public function mark_completed(int $id, string $sapo_object_id): bool
	{
		return 1 === (int) $this->wpdb->update(
			$this->table,
			[
				'status' => OperationStatus::COMPLETED,
				'sapo_object_id' => $sapo_object_id,
				'processing_at' => null,
				'updated_at' => gmdate('Y-m-d H:i:s'),
				'completed_at' => gmdate('Y-m-d H:i:s'),
			],
			['id' => $id]
		);
	}

	public function mark_failed(int $id, string $status, string $error_code, string $error_message, ?string $next_attempt_at = null): bool
	{
		if (! in_array($status, [OperationStatus::RETRY, OperationStatus::NEEDS_REVIEW], true)) {
			return false;
		}

		return 1 === (int) $this->wpdb->update(
			$this->table,
			[
				'status' => $status,
				'last_error_code' => $error_code,
				'last_error_message' => $error_message,
				'next_attempt_at' => $next_attempt_at,
				'processing_at' => null,
				'updated_at' => gmdate('Y-m-d H:i:s'),
			],
			['id' => $id]
		);
	}

	/**
	 * Stop a not-yet-sent create operation when Woo cancels before Sapo has an ID.
	 */
	public function cancel_pending_create(string $aggregate_type, string $aggregate_id): int
	{
		return (int) $this->wpdb->query($this->wpdb->prepare(
			"UPDATE {$this->table} SET status = %s, updated_at = %s WHERE operation_type = %s AND aggregate_type = %s AND aggregate_id = %s AND status IN (%s, %s)",
			OperationStatus::CANCELLED,
			gmdate('Y-m-d H:i:s'),
			OperationType::CREATE_ORDER,
			$aggregate_type,
			$aggregate_id,
			OperationStatus::PENDING,
			OperationStatus::RETRY
		));
	}

	/**
	 * Remove terminal outbox rows after their audit window. Pending, retrying and
	 * processing operations are never pruned by maintenance.
	 */
	public function prune(int $completed_days = 90, int $failed_days = 180): int
	{
		$completed_cutoff = gmdate('Y-m-d H:i:s', time() - (max(1, $completed_days) * DAY_IN_SECONDS));
		$failed_cutoff = gmdate('Y-m-d H:i:s', time() - (max(1, $failed_days) * DAY_IN_SECONDS));

		return (int) $this->wpdb->query($this->wpdb->prepare(
			"DELETE FROM {$this->table} WHERE (status = %s AND completed_at IS NOT NULL AND completed_at < %s) OR (status IN (%s, %s) AND updated_at < %s)",
			OperationStatus::COMPLETED,
			$completed_cutoff,
			OperationStatus::NEEDS_REVIEW,
			OperationStatus::CANCELLED,
			$failed_cutoff
		));
	}
}
