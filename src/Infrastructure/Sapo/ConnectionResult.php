<?php
/**
 * Normalized Sapo connection test result.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Infrastructure\Sapo;

defined('ABSPATH') || exit;

final class ConnectionResult
{
	/**
	 * @param array<string, mixed> $details
     */
	public function __construct(
		public bool $ok,
		public string $message,
		public array $details = []
	) {
	}
}
