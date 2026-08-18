<?php
/**
 * Small, recoverable lock for scheduled reconciliation jobs.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Infrastructure\WordPress;

defined('ABSPATH') || exit;

final class JobLock
{
	private string $name;

	private string $option;

	private int $ttl;

	private bool $held = false;

	public function __construct(string $name, int $ttl = 300)
	{
		$this->name = trim($name);
		$this->option = 'woo_sapo_sync_lock_' . sanitize_key($this->name);
		$this->ttl = max(30, $ttl);
	}

	public function acquire(): bool
	{
		if ('' === $this->name || ! function_exists('add_option')) {
			return true;
		}

		$now = time();
		$current = get_option($this->option, []);
		if (is_array($current) && (int) ($current['expires_at'] ?? 0) > $now) {
			return false;
		}

		$token = wp_generate_uuid4();
		$lock = ['token' => $token, 'expires_at' => $now + $this->ttl];
		if (is_array($current) && [] !== $current) {
			delete_option($this->option);
		}
		if (! add_option($this->option, $lock, '', false)) {
			return false;
		}

		$this->held = true;
		return true;
	}

	public function release(): void
	{
		if ($this->held && function_exists('delete_option')) {
			delete_option($this->option);
		}
		$this->held = false;
	}
}
