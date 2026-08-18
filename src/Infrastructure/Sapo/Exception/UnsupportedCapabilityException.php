<?php
/**
 * Raised when a Sapo capability has not passed verification.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Infrastructure\Sapo\Exception;

use WooSapoSync\Infrastructure\Sapo\ErrorCode;

defined('ABSPATH') || exit;

final class UnsupportedCapabilityException extends SapoException
{
	public function __construct(string $capability)
	{
		parent::__construct(
			ErrorCode::UNSUPPORTED_CAPABILITY,
			sprintf('Sapo capability is not verified: %s', $capability),
			['capability' => $capability]
		);
	}
}
