<?php
/**
 * Runs gateway capability probes and records their result in the gate.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Application;

use WooSapoSync\Contracts\SapoGateway;

defined('ABSPATH') || exit;

final class CapabilityVerifier
{
	private function __construct()
	{
	}

	/**
	 * @return array{connection_ok: bool, verified: int, missing: int, message: string, error_code: string}
	 */
	public static function verify(SapoGateway $gateway): array
	{
		$connection = $gateway->test_connection();
		$snapshot = $gateway->capabilities();
		$verified = 0;
		$missing = 0;
		foreach (CapabilityGate::REQUIRED_CAPABILITIES as $capability) {
			$supported = $connection->ok && $snapshot->supports($capability);
			$note = $supported ? 'Gateway probe passed.' : ($connection->message ?: 'Capability is not supported by the adapter.');
			CapabilityGate::mark($capability, $supported, $note);
			if ($supported) {
				$verified++;
			} else {
				$missing++;
			}
		}

		return [
			'connection_ok' => $connection->ok,
			'verified' => $verified,
			'missing' => $missing,
			'message' => $connection->message,
			'error_code' => (string) ($connection->details['error_code'] ?? ''),
		];
	}
}
