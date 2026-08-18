<?php
/**
 * Sapo webhook REST endpoint.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Webhook;

use WooSapoSync\Infrastructure\WordPress\Repository\EventInboxRepository;
use WooSapoSync\Infrastructure\WordPress\ActionScheduler\Queue;

defined('ABSPATH') || exit;

final class RestController
{
	private const NAMESPACE = 'woo-sapo/v1';

	private const LEGACY_NAMESPACE = 'pixelcam-sapo/v1';

	private static bool $registered = false;

	private EventInboxRepository $events;

	public function __construct(EventInboxRepository $events)
	{
		$this->events = $events;
	}

	public function register(): void
	{
		if (self::$registered || ! function_exists('register_rest_route')) {
			return;
		}

		self::$registered = true;
		foreach ([self::NAMESPACE, self::LEGACY_NAMESPACE] as $namespace) {
			register_rest_route(
				$namespace,
				'/webhook',
				[
					'methods' => \WP_REST_Server::CREATABLE,
					'callback' => [$this, 'receive'],
					'permission_callback' => [$this, 'authorize'],
					'args' => [],
				]
			);
		}
	}

	/**
	 * @param mixed $request
	 * @return mixed
	 */
	public function authorize($request)
	{
		$secret = WebhookSignature::secret();
		$webhook_token = WebhookSignature::token();
		if ('' === $secret && '' === $webhook_token) {
			return new \WP_Error(
				'woo_sapo_webhook_unconfigured',
				'Sapo webhook secret hoặc URL token chưa được cấu hình.',
				['status' => 503]
			);
		}

		$payload = method_exists($request, 'get_body') ? (string) $request->get_body() : '';
		$signature = $this->header($request, ['x-sapo-signature', 'x-sapo-hmac', 'x-sapo-hmac-sha256']);
		$token = method_exists($request, 'get_param') ? trim((string) $request->get_param('token')) : '';
		// Keep old tokenized webhook URLs working during upgrade, but never expose
		// the HMAC secret in newly-rendered settings UI. New installations should
		// use the dedicated token or, preferably, an HMAC header.
		$legacy_token = '' === $webhook_token ? $secret : '';
		$authorized = '' !== $signature
			? ('' !== $secret && WebhookSignature::verify($payload, $signature, $secret))
			: (('' !== $webhook_token && hash_equals($webhook_token, $token))
				|| ('' !== $legacy_token && hash_equals($legacy_token, $token)));
		if (! $authorized) {
			return new \WP_Error(
				'woo_sapo_webhook_invalid_signature',
				'Sapo webhook signature không hợp lệ.',
				['status' => 401]
			);
		}

		return true;
	}

	/**
	 * @param mixed $request
	 * @return mixed
	 */
	public function receive($request)
	{
		$raw_body = method_exists($request, 'get_body') ? (string) $request->get_body() : '';
		$payload = method_exists($request, 'get_json_params') ? $request->get_json_params() : json_decode($raw_body, true);
		if (! is_array($payload)) {
			return new \WP_Error(
				'woo_sapo_webhook_invalid_payload',
				'Payload Sapo webhook phải là JSON object.',
				['status' => 400]
			);
		}

		$event_type_hint = $this->header($request, ['x-sapo-topic', 'x-sapo-event', 'x-sapo-event-type']);
		$event = WebhookEventNormalizer::normalize($payload, $raw_body, $event_type_hint);
		if (null === $event) {
			return new \WP_Error(
				'woo_sapo_webhook_missing_event_fields',
				'Webhook thiếu event id hoặc event type.',
				['status' => 400]
			);
		}

		$is_new = $this->events->receive($event);
		if (null === $is_new) {
			return new \WP_Error(
				'woo_sapo_webhook_storage_failed',
				'Không lưu được webhook vào event inbox.',
				['status' => 500]
			);
		}
		$queued = Queue::enqueue_event((string) $event['event_key']);
		if (! $queued) {
			return new \WP_Error(
				'woo_sapo_webhook_queue_unavailable',
				'Webhook đã nhận nhưng chưa có hàng đợi xử lý nền.',
				['status' => 503]
			);
		}
		$response = [
			'accepted' => true,
			'duplicate' => ! $is_new,
			'queued' => $queued,
			'event_key' => $event['event_key'],
		];

		return new \WP_REST_Response($response, 202);
	}

	/**
	 * @param mixed $request
	 * @param string[] $names
	 */
	private function header($request, array $names): string
	{
		if (! method_exists($request, 'get_header')) {
			return '';
		}

		foreach ($names as $name) {
			$value = trim((string) $request->get_header($name));
			if ('' !== $value) {
				return $value;
			}
		}

		return '';
	}
}
