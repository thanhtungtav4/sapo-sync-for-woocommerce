<?php
/**
 * Stable idempotency key for an order crossing the Woo/Sapo boundary.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Domain\ValueObjects;

defined('ABSPATH') || exit;

final class ExternalReference
{
	private string $value;

	private function __construct(string $value)
	{
		$this->value = $value;
	}

	public static function from_string(string $value): self
	{
		$value = trim($value);
		if ('' === $value) {
			throw new \InvalidArgumentException('External reference cannot be empty.');
		}

		return new self($value);
	}

	public static function for_woo_order(int $order_id, string $site_uuid): self
	{
		$site_uuid = preg_replace('/[^a-zA-Z0-9_-]/', '', $site_uuid) ?: 'site';

		return new self(sprintf('WOOSAPO-%s-%d', $site_uuid, $order_id));
	}

	public static function legacy_for_woo_order(int $order_id, string $site_uuid): self
	{
		$site_uuid = preg_replace('/[^a-zA-Z0-9_-]/', '', $site_uuid) ?: 'site';

		return new self(sprintf('PIXELCAM-%s-%d', $site_uuid, $order_id));
	}

	public static function for_cancel_order(int $order_id, string $site_uuid): self
	{
		$site_uuid = preg_replace('/[^a-zA-Z0-9_-]/', '', $site_uuid) ?: 'site';

		return new self(sprintf('WOOSAPO-CANCEL-%s-%d', $site_uuid, $order_id));
	}

	public function value(): string
	{
		return $this->value;
	}

	public function __toString(): string
	{
		return $this->value;
	}
}
