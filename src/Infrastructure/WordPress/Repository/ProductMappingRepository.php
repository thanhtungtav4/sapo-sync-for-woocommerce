<?php
/**
 * Persistence for WooCommerce ↔ Sapo product mappings.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Infrastructure\WordPress\Repository;

use WooSapoSync\Domain\Product\MappingStatus;
use WooSapoSync\Domain\Product\PriceSource;
use WooSapoSync\Domain\Product\ProductType;

defined('ABSPATH') || exit;

/* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Internal custom-table reads/writes are required for SKU mappings; table names are derived from $wpdb->prefix and value arguments are passed to prepare(). */

final class ProductMappingRepository
{
	private $wpdb;

	private string $table;

	public function __construct($wpdb)
	{
		$this->wpdb = $wpdb;
		$this->table = $wpdb->prefix . 'wss_sapo_product_mappings';
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_by_woo_object_key(string $woo_object_key): ?array
	{
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare("SELECT * FROM {$this->table} WHERE woo_object_key = %s LIMIT 1", $woo_object_key),
			ARRAY_A
		);

		return is_array($row) ? $row : null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_by_id(int $id): ?array
	{
		$id = absint($id);
		if ($id <= 0) {
			return null;
		}

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d LIMIT 1", $id),
			ARRAY_A
		);

		return is_array($row) ? $row : null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_by_sapo_variant_id(string $sapo_variant_id): ?array
	{
		if ('' === trim($sapo_variant_id)) {
			return null;
		}

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare("SELECT * FROM {$this->table} WHERE sapo_variant_id = %s LIMIT 1", $sapo_variant_id),
			ARRAY_A
		);

		return is_array($row) ? $row : null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function find_by_sku(string $sku_match_key): array
	{
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare("SELECT * FROM {$this->table} WHERE sku_match_key = %s ORDER BY id ASC", $sku_match_key),
			ARRAY_A
		);

		return is_array($rows) ? $rows : [];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function find_active(int $limit = 500, int $offset = 0): array
	{
		$limit = max(1, min($limit, 5000));
		$offset = max(0, $offset);
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE mapping_status = %s ORDER BY id ASC LIMIT %d OFFSET %d",
				MappingStatus::ACTIVE,
				$limit,
				$offset
			),
			ARRAY_A
		);

		return is_array($rows) ? $rows : [];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function find_page(string $search = '', string $status = '', int $limit = 50, int $offset = 0): array
	{
		[$where, $args] = $this->browse_where($search, $status);
		$limit = max(1, min($limit, 200));
		$offset = max(0, $offset);
		$sql = "SELECT * FROM {$this->table} WHERE " . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT %d OFFSET %d';
		$args[] = $limit;
		$args[] = $offset;
		$rows = $this->wpdb->get_results($this->wpdb->prepare($sql, ...$args), ARRAY_A);

		return is_array($rows) ? $rows : [];
	}

	public function count(string $search = '', string $status = ''): int
	{
		[$where, $args] = $this->browse_where($search, $status);
		$sql = "SELECT COUNT(*) FROM {$this->table} WHERE " . implode(' AND ', $where);
		return (int) $this->wpdb->get_var($this->wpdb->prepare($sql, ...$args));
	}

	/**
	 * Link a mapping after an operator has verified both Sapo IDs.
	 */
	public function manual_link(int $id, string $sapo_product_id, string $sapo_variant_id): bool
	{
		$id = absint($id);
		$sapo_product_id = trim($sapo_product_id);
		$sapo_variant_id = trim($sapo_variant_id);
		if ($id <= 0 || '' === $sapo_product_id || '' === $sapo_variant_id) {
			return false;
		}

		$existing = $this->find_by_sapo_variant_id($sapo_variant_id);
		if (is_array($existing) && (int) ($existing['id'] ?? 0) !== $id) {
			return false;
		}

		$updated = $this->wpdb->update(
			$this->table,
			[
				'sapo_product_id' => $sapo_product_id,
				'sapo_variant_id' => $sapo_variant_id,
				'mapping_status' => MappingStatus::ACTIVE,
				'last_verified_at' => gmdate('Y-m-d H:i:s'),
				'updated_at' => gmdate('Y-m-d H:i:s'),
			],
			['id' => $id]
		);

		// wpdb returns 0 when the operator submits the already-current link.
		return false !== $updated;
	}

	/**
	 * Insert or update one mapping. Validation is intentionally strict so an ambiguous SKU
	 * cannot become ACTIVE by accident.
	 *
	 * @param array<string, mixed> $mapping
	 */
	public function save(array $mapping): int
	{
		$woo_object_key = (string) ($mapping['woo_object_key'] ?? '');
		$product_type = (string) ($mapping['product_type'] ?? ProductType::SIMPLE);
		$price_source = (string) ($mapping['price_source'] ?? PriceSource::WOO);

		if ($woo_object_key === '' || ! ProductType::is_supported($product_type) || ! PriceSource::is_valid($price_source)) {
			return 0;
		}

		$existing = $this->find_by_woo_object_key($woo_object_key);
		$now = gmdate('Y-m-d H:i:s');
		$data = [
			'woo_object_key' => $woo_object_key,
			'woo_product_id' => absint($mapping['woo_product_id'] ?? 0),
			'woo_variation_id' => ! empty($mapping['woo_variation_id']) ? absint($mapping['woo_variation_id']) : null,
			'sku_raw' => trim((string) ($mapping['sku_raw'] ?? '')),
			'sku_match_key' => trim((string) ($mapping['sku_match_key'] ?? '')),
			'sapo_product_id' => $this->nullable_string($mapping['sapo_product_id'] ?? null),
			'sapo_variant_id' => $this->nullable_string($mapping['sapo_variant_id'] ?? null),
			'product_type' => $product_type,
			'price_source' => $price_source,
			'sapo_price_list_id' => ! empty($mapping['sapo_price_list_id']) ? (string) $mapping['sapo_price_list_id'] : null,
			'mapping_status' => (string) ($mapping['mapping_status'] ?? MappingStatus::NEEDS_REVIEW),
			'last_verified_at' => $mapping['last_verified_at'] ?? null,
			'last_inventory_sync_at' => $mapping['last_inventory_sync_at'] ?? null,
			'updated_at' => $now,
		];

		if ($data['sku_match_key'] === '' || null === $data['sapo_variant_id']) {
			$data['mapping_status'] = MappingStatus::NEEDS_REVIEW;
		}

		if ($existing) {
			$this->wpdb->update($this->table, $data, ['id' => (int) $existing['id']]);
			return (int) $existing['id'];
		}

		$data['created_at'] = $now;
		if (false === $this->wpdb->insert($this->table, $data)) {
			return 0;
		}

		return (int) $this->wpdb->insert_id;
	}

	public function mark_inventory_synced(int $id): bool
	{
		return 1 === (int) $this->wpdb->update(
			$this->table,
			[
				'last_inventory_sync_at' => gmdate('Y-m-d H:i:s'),
				'updated_at' => gmdate('Y-m-d H:i:s'),
			],
			['id' => $id]
		);
	}

	private function nullable_string($value): ?string
	{
		$value = trim((string) $value);
		return '' === $value ? null : $value;
	}

	/**
	 * @return array{0: array<int, string>, 1: array<int, mixed>}
	 */
	private function browse_where(string $search, string $status): array
	{
		$where = ['1=1'];
		$args = [];
		$status = trim($status);
		if (in_array($status, [MappingStatus::ACTIVE, MappingStatus::NEEDS_REVIEW, MappingStatus::MISSING, MappingStatus::DISABLED], true)) {
			$where[] = 'mapping_status = %s';
			$args[] = $status;
		}

		$search = trim($search);
		if ('' !== $search) {
			$escaped = method_exists($this->wpdb, 'esc_like') ? $this->wpdb->esc_like($search) : addcslashes($search, '%_\\');
			$like = '%' . $escaped . '%';
			$where[] = '(sku_raw LIKE %s OR sku_match_key LIKE %s OR woo_object_key LIKE %s OR sapo_variant_id LIKE %s)';
			$args[] = $like;
			$args[] = $like;
			$args[] = $like;
			$args[] = $like;
		}

		return [$where, $args];
	}
}
