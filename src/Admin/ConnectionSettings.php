<?php
/**
 * Stores the Sapo transport settings without rendering credentials back to admins.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Admin;

use WooSapoSync\Application\CapabilityGate;

defined('ABSPATH') || exit;

final class ConnectionSettings
{
	public const OPTION_KEY = 'woo_sapo_sync_connection';

	private const SECRET_FIELDS = ['api_key', 'api_secret', 'access_token'];

	private function __construct()
	{
	}

	public static function register(): void
	{
		if (! function_exists('register_setting')) {
			return;
		}

		register_setting(
			'woo_sapo_sync_settings_group',
			self::OPTION_KEY,
			[
				'sanitize_callback' => [self::class, 'sanitize'],
				'default' => [
					'base_url' => '',
					'auth_mode' => 'basic',
					'api_key' => '',
					'api_secret' => '',
					'access_token' => '',
				],
			]
		);
	}

	/**
	 * @param mixed $input
	 * @return array<string, string>
	 */
	public static function sanitize($input): array
	{
		$input = is_array($input) ? $input : [];
		$stored = get_option(self::OPTION_KEY, []);
		$stored = is_array($stored) ? $stored : [];
		$auth_mode = in_array(($input['auth_mode'] ?? ''), ['basic', 'bearer'], true)
			? (string) $input['auth_mode']
			: 'basic';

		$sanitized = [
			'base_url' => self::normalize_base_url((string) ($input['base_url'] ?? ($stored['base_url'] ?? ''))),
			'auth_mode' => $auth_mode,
		];

		foreach (self::SECRET_FIELDS as $field) {
			$value = trim((string) ($input[$field] ?? ''));
			if ('' === $value) {
				$value = (string) ($stored[$field] ?? '');
			}
			$sanitized[$field] = function_exists('sanitize_text_field')
				? sanitize_text_field($value)
				: $value;
		}

		$connection_changed = false;
		foreach (array_merge(['base_url', 'auth_mode'], self::SECRET_FIELDS) as $field) {
			if ((string) ($stored[$field] ?? '') !== (string) ($sanitized[$field] ?? '')) {
				$connection_changed = true;
				break;
			}
		}
		if ($connection_changed) {
			if (function_exists('delete_option')) {
				delete_option(CapabilityGate::OPTION_KEY);
				delete_option(CapabilityGate::ORDER_CONTRACT_OPTION);
			}
		}

		return $sanitized;
	}

	/**
	 * Reject credentials embedded in a URL and require HTTPS for remote Sapo calls.
	 */
	public static function normalize_base_url(string $value): string
	{
		$value = trim($value);
		if ('' === $value) {
			return '';
		}

		$url = function_exists('esc_url_raw') ? esc_url_raw($value) : $value;
		$parts = wp_parse_url($url);
		if (! is_array($parts) || 'https' !== strtolower((string) ($parts['scheme'] ?? '')) || '' === (string) ($parts['host'] ?? '')) {
			return '';
		}
		if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
			return '';
		}

		$normalized = 'https://' . strtolower((string) $parts['host']);
		if (! empty($parts['port'])) {
			$normalized .= ':' . (int) $parts['port'];
		}
		$path = rtrim((string) ($parts['path'] ?? ''), '/');
		if ('' !== $path) {
			$normalized .= '/' . ltrim($path, '/');
		}

		return $normalized;
	}

	/**
	 * @return array<string, string>
	 */
	public static function get(): array
	{
		$stored = get_option(self::OPTION_KEY, []);
		return self::sanitize(is_array($stored) ? $stored : []);
	}

	public static function is_configured(): bool
	{
		$settings = self::get();
		if ('' === $settings['base_url']) {
			return false;
		}

		if ('bearer' === $settings['auth_mode']) {
			return '' !== $settings['access_token'];
		}

		return '' !== $settings['api_key'] && '' !== $settings['api_secret'];
	}
}
