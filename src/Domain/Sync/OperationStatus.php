<?php
/**
 * Outbox operation lifecycle constants.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Domain\Sync;

defined('ABSPATH') || exit;

final class OperationStatus
{
	public const PENDING = 'PENDING';
	public const PROCESSING = 'PROCESSING';
	public const RETRY = 'RETRY';
	public const COMPLETED = 'COMPLETED';
	public const NEEDS_REVIEW = 'NEEDS_REVIEW';
	public const CANCELLED = 'CANCELLED';

	private function __construct()
	{
	}
}
