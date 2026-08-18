<?php
/**
 * Webhook/polling event inbox with deduplication at the database boundary.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Infrastructure\WordPress\Repository;

defined('ABSPATH') || exit;

/* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Internal custom-table reads/writes are required for the event inbox; table names are derived from $wpdb->prefix and value arguments are passed to prepare(). */

final class EventInboxRepository
{
	private $wpdb;

	private string $table;

	public function __construct($wpdb)
	{
		$this->wpdb = $wpdb;
		$this->table = $wpdb->prefix . 'wss_sapo_events';
	}

	/**
	 * Returns true only for a newly accepted event. Duplicate event IDs/fingerprints
	 * are deliberately treated as successful receipt so providers do not retry forever.
	 *
	 * @param array<string, mixed> $event
	 * @return bool|null True for a new event, false for a duplicate, null for a DB error.
	 */
	public function receive(array $event): ?bool
	{
		$event_key = trim((string) ($event['event_key'] ?? ''));
		$event_type = trim((string) ($event['event_type'] ?? ''));
		if ($event_key === '' || $event_type === '') {
			return false;
		}

		$payload = (string) ($event['payload'] ?? '');
		$payload_hash = preg_match('/^[a-f0-9]{64}$/', (string) ($event['payload_hash'] ?? ''))
			? (string) $event['payload_hash']
			: hash('sha256', $payload);

		$inserted = $this->wpdb->query($this->wpdb->prepare(
			"INSERT IGNORE INTO {$this->table} (event_key, event_type, remote_object_id, remote_modified_at, payload_hash, status, attempt_count, next_attempt_at, processing_at, received_at) VALUES (%s, %s, %s, %s, %s, %s, %d, %s, %s, %s)",
			$event_key,
			$event_type,
			! empty($event['remote_object_id']) ? (string) $event['remote_object_id'] : null,
			! empty($event['remote_modified_at']) ? (string) $event['remote_modified_at'] : null,
			$payload_hash,
			'RECEIVED',
			0,
			null,
			null,
			gmdate('Y-m-d H:i:s')
		));

		if (false === $inserted) {
			return null;
		}

		return 1 === (int) $inserted;
	}

	/**
	 * Claim one event for processing. A stale PROCESSING row is recoverable
	 * after a worker crash; RECEIVED/RETRY rows respect next_attempt_at.
	 */
	public function claim(string $event_key, int $lease_seconds = 300): bool
	{
		$now = gmdate('Y-m-d H:i:s');
		$stale = gmdate('Y-m-d H:i:s', time() - max(60, $lease_seconds));
		$updated = $this->wpdb->query($this->wpdb->prepare(
			"UPDATE {$this->table} SET status = %s, attempt_count = attempt_count + 1, processing_at = %s, next_attempt_at = NULL WHERE event_key = %s AND ((status IN (%s, %s) AND (next_attempt_at IS NULL OR next_attempt_at <= %s)) OR (status = %s AND (processing_at IS NULL OR processing_at < %s)))",
			'PROCESSING',
			$now,
			$event_key,
			'RECEIVED',
			'RETRY',
			$now,
			'PROCESSING',
			$stale
		));

		return 1 === (int) $updated;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_by_key(string $event_key): ?array
	{
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare("SELECT * FROM {$this->table} WHERE event_key = %s LIMIT 1", $event_key),
			ARRAY_A
		);

		return is_array($row) ? $row : null;
	}

	public function mark_processed(string $event_key): bool
	{
		return 1 === (int) $this->wpdb->update(
			$this->table,
			[
				'status' => 'PROCESSED',
				'next_attempt_at' => null,
				'processing_at' => null,
				'processed_at' => gmdate('Y-m-d H:i:s'),
			],
			['event_key' => $event_key]
		);
	}

	public function mark_failed(string $event_key, string $error_code, string $error_message): bool
	{
		return 1 === (int) $this->wpdb->update(
			$this->table,
			[
				'status' => 'FAILED',
				'next_attempt_at' => null,
				'processing_at' => null,
				'error_code' => $error_code,
				'error_message' => $error_message,
			],
			['event_key' => $event_key]
		);
	}

	public function mark_retry(string $event_key, string $error_code, string $error_message, string $next_attempt_at): bool
	{
		return 1 === (int) $this->wpdb->update(
			$this->table,
			[
				'status' => 'RETRY',
				'next_attempt_at' => $next_attempt_at,
				'processing_at' => null,
				'error_code' => $error_code,
				'error_message' => $error_message,
			],
			['event_key' => $event_key]
		);
	}

	/**
	 * Recover received/retry rows whose scheduled queue item was lost.
	 *
	 * @return string[]
	 */
	public function due_keys(int $limit = 100): array
	{
		$limit = max(1, min($limit, 500));
		$now = gmdate('Y-m-d H:i:s');
		$rows = $this->wpdb->get_col($this->wpdb->prepare(
			"SELECT event_key FROM {$this->table} WHERE status IN (%s, %s) AND (next_attempt_at IS NULL OR next_attempt_at <= %s) ORDER BY id ASC LIMIT %d",
			'RECEIVED',
			'RETRY',
			$now,
			$limit
		));

		return array_values(array_filter(array_map('strval', (array) $rows)));
	}
}
