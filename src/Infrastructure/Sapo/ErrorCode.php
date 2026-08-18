<?php
/**
 * Stable error taxonomy at the Sapo boundary.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Infrastructure\Sapo;

defined('ABSPATH') || exit;

final class ErrorCode
{
	public const AUTH = 'AUTH';
	public const RATE_LIMIT = 'RATE_LIMIT';
	public const TIMEOUT = 'TIMEOUT';
	public const VALIDATION = 'VALIDATION';
	public const NOT_FOUND = 'NOT_FOUND';
	public const CONFLICT = 'CONFLICT';
	public const REMOTE_SERVER = 'REMOTE_SERVER';
	public const UNSUPPORTED_CAPABILITY = 'UNSUPPORTED_CAPABILITY';

	private function __construct()
	{
	}
}
