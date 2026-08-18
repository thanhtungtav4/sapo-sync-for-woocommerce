<?php
/**
 * Protects runtime sync from running before the Sapo contract is verified.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Application;

defined('ABSPATH') || exit;

final class CapabilityGate
{
	public const OPTION_KEY = 'woo_sapo_sync_capabilities';

	public const ORDER_CONTRACT_OPTION = 'woo_sapo_order_contract_verified';

	/**
	 * Capabilities that must be explicitly verified against the real Sapo account.
	 *
	 * @var string[]
	 */
	public const REQUIRED_CAPABILITIES = [
		'authentication',
		'locations',
		'variants',
		'availability_by_location',
		'prices',
		'customers',
		'create_and_approve_orders',
		'order_external_reference_lookup',
		'order_state',
		'cancel_orders',
		'webhooks_or_polling',
	];

	private function __construct()
	{
	}

	public static function is_passed(): bool
	{
		$capabilities = get_option(self::OPTION_KEY, []);

		if (! is_array($capabilities)) {
			return false;
		}

		foreach (self::REQUIRED_CAPABILITIES as $capability) {
			if (empty($capabilities[$capability]['verified'])) {
				return false;
			}
		}

		return true;
	}

	public static function order_contract_verified(): bool
	{
		$state = get_option(self::ORDER_CONTRACT_OPTION, []);
		$capabilities = self::order_contract_capabilities();
		return is_array($state)
			&& ! empty($state['verified'])
			&& count($capabilities) === count(array_filter($capabilities));
	}

	/**
	 * @return array<string, bool>
	 */
	public static function order_contract_capabilities(): array
	{
		$state = get_option(self::ORDER_CONTRACT_OPTION, []);
		$capabilities = is_array($state) && is_array($state['capabilities'] ?? null)
			? $state['capabilities']
			: [];

		$result = [];
		foreach (['customers', 'create_and_approve_orders', 'order_external_reference_lookup', 'order_state', 'cancel_orders'] as $capability) {
			$result[$capability] = ! empty($capabilities[$capability]);
		}

		return $result;
	}

	/**
	 * @return array<string, array{verified: bool, verified_at?: string, notes?: string}>
	 */
	public static function snapshot(): array
	{
		$stored = get_option(self::OPTION_KEY, []);
		$stored = is_array($stored) ? $stored : [];
		$snapshot = [];

		foreach (self::REQUIRED_CAPABILITIES as $capability) {
			$state = isset($stored[$capability]) && is_array($stored[$capability]) ? $stored[$capability] : [];
			$snapshot[$capability] = [
				'verified' => ! empty($state['verified']),
				'verified_at' => (string) ($state['verified_at'] ?? ''),
				'notes' => (string) ($state['notes'] ?? ''),
			];
		}

		return $snapshot;
	}

	public static function mark(string $capability, bool $verified, string $notes = ''): bool
	{
		if (! in_array($capability, self::REQUIRED_CAPABILITIES, true)) {
			return false;
		}

		$snapshot = self::snapshot();
		$snapshot[$capability] = [
			'verified'    => $verified,
			'verified_at' => current_time('mysql', true),
			'notes'       => sanitize_textarea_field($notes),
		];

		return update_option(self::OPTION_KEY, $snapshot, false);
	}

	/**
	 * Fail closed immediately after Sapo rejects an authenticated request.
	 *
	 * A previously verified capability is not proof that the token still has
	 * permission. Clearing the snapshot forces the administrator to verify the
	 * current credentials before any later queued job can write again.
	 */
	public static function invalidate(string $notes = 'Sapo từ chối quyền truy cập; cần xác minh lại kết nối.'): void
	{
		$snapshot = self::snapshot();
		$timestamp = current_time('mysql', true);
		$notes = sanitize_textarea_field($notes);
		foreach (self::REQUIRED_CAPABILITIES as $capability) {
			$snapshot[$capability] = [
				'verified'    => false,
				'verified_at' => $timestamp,
				'notes'       => $notes,
			];
		}

		update_option(self::OPTION_KEY, $snapshot, false);
		delete_option(self::ORDER_CONTRACT_OPTION);
	}
}
