<?php
/**
 * Registers the plugin's suggested privacy-policy text.
 *
 * The plugin does not maintain a separate customer database. Customer and order
 * personal data is read from WooCommerce only while an order is sent to Sapo;
 * the plugin's own tables retain operational identifiers and hashes.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Infrastructure\WordPress;

defined('ABSPATH') || exit;

final class Privacy
{
	private function __construct()
	{
	}

	public static function register(): void
	{
		if (! function_exists('wp_add_privacy_policy_content')) {
			return;
		}

		$content = '<p>' . esc_html__('Sapo Sync for WooCommerce transfers only the WooCommerce order, customer, product and inventory data required by the Sapo connection configured by the site administrator. The plugin does not add analytics, advertising, tracking pixels or telemetry.', 'sapo-sync-for-woocommerce') . '</p>';
		$content .= '<p>' . esc_html__('The plugin stores connection credentials, sync status, remote object identifiers and operational hashes in WordPress. It does not store raw webhook payloads or maintain a separate customer database. WooCommerce remains the local system of record for customer and order personal data.', 'sapo-sync-for-woocommerce') . '</p>';
		$sapo_policy_url = 'https://help.sapo.vn/chinh-sach-bao-ve-du-lieu-ca-nhan';
		$content .= '<p>' . esc_html__('Sapo Omni/POS is an external service. When order or inventory sync is enabled, the relevant data is sent to Sapo and signed webhook requests may be received from Sapo. Review Sapo\'s terms and privacy policy before enabling the connection.', 'sapo-sync-for-woocommerce') . ' <a href="' . esc_url($sapo_policy_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Sapo personal-data policy', 'sapo-sync-for-woocommerce') . '</a>.</p>';

		wp_add_privacy_policy_content(
			esc_html__('Sapo Sync for WooCommerce', 'sapo-sync-for-woocommerce'),
			$content
		);
	}
}
