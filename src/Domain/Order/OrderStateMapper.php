<?php
/**
 * Explicit mapping of Sapo's independent order axes to Woo statuses.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Domain\Order;

defined('ABSPATH') || exit;

final class OrderStateMapper
{
	private function __construct()
	{
	}

	/**
	 * Return a Woo status only when the remote state is unambiguous.
	 *
	 * @param array<string, mixed> $state
	 */
	public static function to_woo_status(array $state): ?string
	{
		$order = strtolower((string) ($state['order'] ?? ''));
		$financial = strtolower((string) ($state['financial'] ?? ''));
		$delivery = strtolower((string) ($state['delivery'] ?? ''));
		$return = strtolower((string) ($state['return'] ?? ''));

		if ('refunded' === $financial || ('received' === $return && in_array($order, ['returned', 'closed'], true))) {
			return 'refunded';
		}

		if (in_array($order, ['cancelled', 'canceled'], true)) {
			return 'cancelled';
		}

		if (in_array($delivery, ['fulfilled', 'delivered', 'successful'], true) && in_array($financial, ['paid', 'partially_paid'], true)) {
			return 'completed';
		}

		if (in_array($order, ['approved', 'processing', 'fulfilled'], true)) {
			return 'processing';
		}

		return null;
	}
}
