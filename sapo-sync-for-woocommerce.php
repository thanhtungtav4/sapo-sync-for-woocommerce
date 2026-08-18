<?php
/**
 * Plugin Name:       Inventory Bridge for Sapo
 * Plugin URI:        https://nttung.dev/sapo-sync-for-woocommerce/
 * Description:       Đồng bộ sản phẩm, tồn kho và đơn hàng WooCommerce với Sapo Omni/POS.
 * Version:           0.5.2
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * Author:            Nttung
 * Author URI:        https://nttung.dev
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sapo-sync-for-woocommerce
 *
 * @package WooSapoSync
 */

defined('ABSPATH') || exit;

if (! defined('WOO_SAPO_SYNC_VERSION')) {
	define('WOO_SAPO_SYNC_VERSION', '0.5.2');
}

if (! defined('WOO_SAPO_SYNC_FILE')) {
	define('WOO_SAPO_SYNC_FILE', __FILE__);
}

if (! defined('WOO_SAPO_SYNC_PATH')) {
	define('WOO_SAPO_SYNC_PATH', plugin_dir_path(__FILE__));
}

require_once WOO_SAPO_SYNC_PATH . 'src/Autoloader.php';

\WooSapoSync\Autoloader::register(WOO_SAPO_SYNC_PATH . 'src');
\WooSapoSync\Application\Plugin::boot(WOO_SAPO_SYNC_FILE);
