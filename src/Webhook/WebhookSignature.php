<?php
/**
 * HMAC verification for the Sapo webhook boundary.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Webhook;

defined('ABSPATH') || exit;

final class WebhookSignature
{
	public const SECRET_OPTION = 'woo_sapo_sync_webhook_secret';

	private function __construct()
	{
	}

	public static function verify(string $payload, string $provided, string $secret): bool
	{
		$secret = trim($secret);
		$provided = trim($provided);
		if ('' === $secret || '' === $provided) {
			return false;
		}

		$provided = preg_replace('/^sha256=/i', '', $provided) ?: '';
		$expected = hash_hmac('sha256', $payload, $secret);
		$expected_base64 = base64_encode(hash_hmac('sha256', $payload, $secret, true));

		return hash_equals($expected, strtolower($provided)) || hash_equals($expected_base64, $provided);
	}

	public static function secret(): string
	{
		$value = get_option(self::SECRET_OPTION, '');
		return is_string($value) ? trim($value) : '';
	}
}
