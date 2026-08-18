<?php
/**
 * Result of the Sapo capability verification step.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Infrastructure\Sapo;

defined('ABSPATH') || exit;

final class CapabilitySnapshot
{
	/**
	 * @param array<string, bool>  $capabilities
	 * @param array<string, string> $notes
	 */
	public function __construct(
		private array $capabilities = [],
		private array $notes = []
	) {
	}

	public function supports(string $capability): bool
	{
		return ! empty($this->capabilities[$capability]);
	}

	/**
	 * @return array<string, bool>
	 */
	public function all(): array
	{
		return $this->capabilities;
	}

	/**
	 * @return array<string, string>
	 */
	public function notes(): array
	{
		return $this->notes;
	}
}
