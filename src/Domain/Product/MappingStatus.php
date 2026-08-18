<?php
/**
 * Product mapping lifecycle constants.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Domain\Product;

defined('ABSPATH') || exit;

final class MappingStatus
{
	public const ACTIVE = 'ACTIVE';
	public const NEEDS_REVIEW = 'NEEDS_REVIEW';
	public const MISSING = 'MISSING';
	public const DISABLED = 'DISABLED';

	private function __construct()
	{
	}
}
