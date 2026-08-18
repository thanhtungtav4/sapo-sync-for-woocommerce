<?php
/**
 * Lightweight PSR-4-style autoloader for the plugin.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync;

defined('ABSPATH') || exit;

final class Autoloader
{
	private function __construct()
	{
	}

	public static function register(string $source_path): void
	{
		spl_autoload_register(
			static function (string $class) use ($source_path): void {
				$prefix = __NAMESPACE__ . '\\';

				if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
					return;
				}

				$relative = substr($class, strlen($prefix));
				$file = rtrim($source_path, '/\\') . '/' . str_replace('\\', '/', $relative) . '.php';

				if (is_readable($file)) {
					require_once $file;
				}
			}
		);
	}
}
