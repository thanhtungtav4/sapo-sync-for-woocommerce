<?php
/**
 * Outbox operation types.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Domain\Sync;

defined('ABSPATH') || exit;

final class OperationType
{
	public const CREATE_ORDER = 'CREATE_ORDER';
	public const CANCEL_ORDER = 'CANCEL_ORDER';

	private function __construct()
	{
	}
}
