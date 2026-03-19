=== CartTrigger – Holded Sync ===
Contributors: polettoespana
Tags: woocommerce, holded, sync, inventory, products
Requires at least: 6.3
Tested up to: 6.9
Requires PHP: 7.4
WC tested up to: 10.6.1
Requires Plugins: woocommerce
Stable tag: 1.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bidirectional sync between WooCommerce products/stock and Holded ERP.

== Description ==

**CartTrigger – Holded Sync** keeps your WooCommerce store and your Holded ERP in sync automatically.

= WooCommerce → Holded (real-time) =
Every time you save a product or a stock change occurs in WooCommerce, the plugin pushes the update to Holded immediately — no manual work needed.

= Holded → WooCommerce (every 15 minutes) =
Since Holded does not provide outbound webhooks, the plugin pulls product and stock data from Holded on a scheduled basis (every 15 minutes) and updates WooCommerce accordingly.

= What is synced =
* Product name
* Regular price
* Description (optional)
* SKU
* Stock quantity
* Product variants (variable products)

= Matching logic =
Products are matched between the two systems by SKU. On first sync, the Holded product ID is stored in WooCommerce product meta (`_ctholded_product_id`) to speed up subsequent syncs.

= Manual sync =
A "Pull from Holded now" button in the settings page lets you trigger an immediate pull at any time.

== Installation ==

1. Upload the `carttrigger-holded` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **WooCommerce → Holded Sync** and enter your Holded API key.
4. Enable sync and configure which fields to synchronise.

== Frequently Asked Questions ==

= Where do I find my Holded API key? =
In Holded, go to **Settings → Integrations → API** and generate a new key.

= How are products matched between WooCommerce and Holded? =
By SKU. Make sure your products have the same SKU in both systems before enabling sync.

= Can I sync only stock and not prices? =
Yes. Each sync field (stock, prices, description) can be enabled or disabled independently in the settings page.

= What happens if the same product is updated in both systems at the same time? =
WooCommerce takes priority for real-time changes. Holded changes are applied every 15 minutes and only update fields that have actually changed.

== Changelog ==

= 1.0.4 =
* Fix: admin buttons now restore their original translated text after each action instead of hardcoded English strings.
* Enhancement: added missing i18n strings (Loading…, Select warehouse, No warehouses found) to Italian and Spanish translations.

= 1.0.3 =
* Renamed plugin directory to carttrigger-holded-sync and main file accordingly.
* Renamed all PHP prefixes to cthls_ / CTHLS_ to comply with the WordPress 5-character unique prefix requirement.
* Updated text domain to carttrigger-holded-sync.
* Added Italian (it_IT) and Spanish (es_ES) translations.
* Added cost price and barcode fields to the Holded Sync product tab.

= 1.0.2 =
* Fix: nonce verification added explicitly in product meta save callback.
* Fix: plugin name aligned between plugin header and readme.txt.
* Enhancement: redesigned settings page with card-based layout and icons.
* Enhancement: added "Append brand to name" option.
* Enhancement: replaced WP-Cron with Action Scheduler for Holded pull.
* Enhancement: configurable pull interval (default 15 minutes).

= 1.0.0 =
* Initial release.
