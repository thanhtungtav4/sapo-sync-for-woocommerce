=== Sapo Sync for WooCommerce ===
Contributors: nttungdev
Tags: woocommerce, sapo, inventory, stock, order sync
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Synchronize WooCommerce products, inventory and orders with Sapo Omni/POS using SKU-based mapping.

== Description ==

Sapo Sync for WooCommerce keeps Sapo as the operational source for product variants, branch inventory and order fulfillment while WooCommerce remains the storefront and checkout system.

Highlights:

* SKU-first mapping for simple products and variations.
* Branch-aware inventory reconciliation with Shadow and Write modes.
* Action Scheduler support with WP-Cron fallback.
* Idempotent order outbox with retries, leases and duplicate protection.
* Capability verification before enabling order write operations.
* HMAC or signed-token webhook intake with polling fallback.
* HPOS-compatible WooCommerce CRUD access.

Combo/bundle products and price-list synchronization are intentionally outside the current stable scope.

== Installation ==

1. Install and activate WooCommerce.
2. Upload the `sapo-sync-for-woocommerce` folder to `/wp-content/plugins/` or install the ZIP from Plugins > Add New > Upload Plugin.
3. Activate Sapo Sync for WooCommerce.
4. Open WooCommerce > Sapo Sync and follow the connection guide.
5. Keep inventory in Shadow mode until mappings and the Sapo contract test pass.

== Upgrading from an earlier build ==

Deactivate the earlier plugin before activating this package. On activation, the plugin migrates
connection settings, capability state, sync tables and legacy order references to the generic
namespace. Re-run the connection and order contract checks after the migration.

== Frequently Asked Questions ==

= Where do I get Sapo credentials? =

Create a Sapo Private App for Basic authentication, or use an OAuth app and paste the access token. The plugin includes a step-by-step guide on its settings page.

= Does the plugin overwrite product content? =

No. The stable scope maps products by SKU and synchronizes operational inventory. WooCommerce remains the content owner.

= Is inventory real-time? =

Webhooks trigger an immediate reconciliation when available. A one-minute Action Scheduler/WP-Cron reconciliation remains the safety net.

= Does the plugin support bundles? =

Not in the current stable release. The mapping boundary is designed so bundle support can be added later without changing simple/variation identifiers.

== Privacy ==

The plugin sends only the order, customer, product and inventory data required by the configured Sapo connection. Credentials are stored in WordPress options and are never rendered back into the settings form. The plugin does not add analytics, advertising, tracking pixels or telemetry.

WooCommerce remains the local system of record for customer and order personal data. The plugin stores connection credentials, sync status, remote object identifiers and operational hashes in WordPress. Webhook event records store identifiers and payload hashes, not the raw webhook body. Operational logs redact common personal-data and credential fields.

The site owner is responsible for publishing a privacy policy that explains the Sapo data transfer and for configuring retention according to their legal and business requirements. Disconnecting Sapo stops new transfers; it does not delete WooCommerce orders or remote Sapo records.

When the plugin is deleted from WordPress, its connection settings, sync tables and plugin-owned order metadata are removed. WooCommerce orders, customer records and Sapo records are not deleted.

== Support ==

Documentation and contact information: https://nttung.dev/portal/

When reporting an issue, include the plugin version, WordPress and WooCommerce versions, the sync mode, the affected operation/event identifier, and a redacted log excerpt. Never include API keys, access tokens, webhook secrets, customer personal data or full order payloads.

== External services ==

This plugin connects to the Sapo Omni/POS Admin API, operated by Sapo, only after a site administrator configures a Sapo connection and enables the relevant sync capabilities.

Depending on the enabled features, the plugin sends SKU/product and variation identifiers, branch inventory quantities, and order data required to create or reconcile an order. Order data can include the WooCommerce order number, customer name, email, phone number, billing/shipping address, order lines, totals, currency, payment and fulfilment status. Sapo can send signed webhook requests back to the WordPress site for inventory and order updates.

Sapo service documentation: https://support.sapo.vn/
Sapo privacy policy: https://www.sapo.vn/privacy_vn.html
Sapo personal-data policy: https://help.sapo.vn/chinh-sach-bao-ve-du-lieu-ca-nhan

Sapo is an external service. Its terms and privacy policies apply to data processed by Sapo; the site owner should review those terms before enabling order synchronisation.

== Changelog ==

= 0.5.0 =
* Renamed the public plugin and slug to Sapo Sync for WooCommerce for WordPress.org trademark compliance.
* Updated the Plugin Check workflow and resolved current WordPress 7.0/readme compatibility findings.

= 0.4.5 =
* Matched the contributor field to the WordPress.org account `nttungdev`.

= 0.4.4 =
* Added a support section with a reproducible issue-reporting checklist.

= 0.4.3 =
* Linked the Sapo privacy and personal-data policies in the external-services disclosure.

= 0.4.2 =
* Added a WordPress uninstall handler for plugin-owned options, tables and order metadata.

= 0.4.1 =
* Aligned plugin metadata and readme with WordPress.org directory requirements.
* Added explicit external-service and privacy disclosures for Sapo data transfers.
* Added an automated WordPress Plugin Check workflow for pull requests and pushes.

= 0.4.0 =
* Hardened customer and order contract verification.
* Added status/totals reconciliation, legacy migration and event recovery.
* Added generic WordPress.org branding and installation documentation.
