<?php

defined('ABSPATH') || define('ABSPATH', __DIR__ . '/');

if (! function_exists('wp_parse_url')) {
	function wp_parse_url(string $url, int $component = -1)
	{
		return parse_url($url, $component);
	}
}

require_once dirname(__DIR__) . '/src/Autoloader.php';
\WooSapoSync\Autoloader::register(dirname(__DIR__) . '/src');
