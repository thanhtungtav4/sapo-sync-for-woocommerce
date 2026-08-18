<?php
/**
 * Converts an untrusted webhook envelope to the event inbox shape.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Webhook;

defined('ABSPATH') || exit;

final class WebhookEventNormalizer
{
	private function __construct()
	{
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>|null
	 */
	public static function normalize(array $payload, string $raw_body, string $event_type_hint = ''): ?array
	{
		$resource = self::resource($payload);
		$event_key = self::first_string($payload, ['event_id', 'eventId', 'event_key', 'webhook_id']);
		$event_type = self::first_string($payload, ['event_type', 'type', 'event', 'topic']);
		if ('' === $event_type) {
			$event_type = trim($event_type_hint);
		}
		$remote_object_id = self::first_string($payload, ['object_id', 'resource_id', 'order_id', 'product_id', 'variant_id', 'customer_id', 'id']);
		if ('' === $remote_object_id) {
			$remote_object_id = self::first_string($resource, ['id', 'object_id', 'resource_id', 'order_id', 'product_id', 'variant_id', 'customer_id']);
		}
		if ('' === $event_type || ('' === $event_key && '' === $remote_object_id)) {
			return null;
		}
		$modified_at = self::first_string($payload, ['modified_at', 'updated_at', 'modifiedAt', 'modified_on']);
		if ('' === $modified_at) {
			$modified_at = self::first_string($resource, ['modified_at', 'updated_at', 'modifiedAt', 'modified_on']);
		}
		$modified_at = self::normalize_datetime($modified_at);
		if ('' === $event_key) {
			// Prefer the provider identity plus modified time. Canonical JSON is
			// only the final fallback so formatting changes do not duplicate an
			// otherwise identical event.
			$identity = strtolower($event_type) . '|' . $remote_object_id . '|' . $modified_at;
			if ('' === $modified_at) {
				$identity .= '|' . self::canonical_json($payload);
			}
			$event_key = substr(hash('sha256', $identity), 0, 64);
		}

		return [
			'event_key' => substr($event_key, 0, 191),
			'event_type' => substr($event_type, 0, 64),
			'remote_object_id' => '' !== $remote_object_id ? substr($remote_object_id, 0, 191) : null,
			'remote_modified_at' => '' !== $modified_at ? substr($modified_at, 0, 64) : null,
			'payload_hash' => hash('sha256', $raw_body),
			'payload' => $raw_body,
		];
	}

	/**
	 * Sapo may wrap the resource under a topic-specific key (product, order, etc.).
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private static function resource(array $payload): array
	{
		foreach (['resource', 'order', 'product', 'variant', 'customer', 'fulfillment', 'store', 'location'] as $key) {
			if (isset($payload[$key]) && is_array($payload[$key])) {
				return $payload[$key];
			}
		}
		if (isset($payload['data']) && is_array($payload['data'])) {
			if (isset($payload['data']['id']) || isset($payload['data']['object_id'])) {
				return $payload['data'];
			}
			$first = reset($payload['data']);
			if (is_array($first)) {
				return $first;
			}
		}

		return [];
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param string[] $keys
	 */
	private static function first_string(array $payload, array $keys): string
	{
		foreach ($keys as $key) {
			if (isset($payload[$key]) && is_scalar($payload[$key])) {
				$value = trim((string) $payload[$key]);
				if ('' !== $value) {
					return $value;
				}
			}
		}

		return '';
	}

	private static function normalize_datetime(string $value): string
	{
		$value = trim($value);
		if ('' === $value) {
			return '';
		}

		$timestamp = strtotime($value);
		return false === $timestamp ? '' : gmdate('Y-m-d H:i:s', $timestamp);
	}

	/**
	 * @param array<string, mixed> $value
	 */
	private static function canonical_json(array $value): string
	{
		$sort = static function (&$item) use (&$sort): void {
			if (! is_array($item)) {
				return;
			}
			if ([] !== $item && array_keys($item) !== range(0, count($item) - 1)) {
				ksort($item);
			}
			foreach ($item as &$child) {
				$sort($child);
			}
			unset($child);
		};

		$copy = $value;
		$sort($copy);
		$encoded = function_exists('wp_json_encode')
			? wp_json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
			: json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		return is_string($encoded) ? $encoded : '';
	}
}
