=== CartTrigger – Holded Sync ===
Contributors: polettoespana
Tags: woocommerce, holded, sync, inventory, products
Requires at least: 6.3
Tested up to: 6.9
Requires PHP: 7.4
WC tested up to: 10.6.1
Requires Plugins: woocommerce
Stable tag: 1.3.1
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
* Variable products: each variation is pushed as a separate simple product in Holded

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

= 1.3.1 =
* Fix: when "Append brand to name" is enabled, the product name is no longer overwritten in WooCommerce during pull — prevents the brand from being appended repeatedly on each push/pull cycle ("Averoldi Averoldi Averoldi…").

= 1.3.0 =
* Change: variable products in WooCommerce are now pushed to Holded as separate simple products — one per variation — instead of a single product with variants. Holded does not handle variant-type products correctly via API.
* Change: Holded → WC pull now matches Holded products to WC variations by SKU and updates only stock and price; the WooCommerce product structure is never modified.
* Change: _cthls_product_id is now stored on each WC variation directly. Stock sync uses the variation's own Holded product ID.
* Known limitation updated: variable product handling and price tiers.

= 1.2.3 =
* Fix: variable products no longer attempt PUT update — the Holded API rejects PUT on kind=variants products regardless of payload. After initial creation, only stock is kept in sync via the /stock endpoint.
* Known limitation added: variable product updates (name, price, description) not supported by Holded REST API.

= 1.2.2 =
* (internal — superseded by 1.2.3)

= 1.2.1 =
* (internal — superseded by 1.2.3)

= 1.2.0 =
* Fix: variant price field corrected from price to subtotal (Holded API input field name).
* Fix: each variant now includes a name field (attribute values, e.g. "75cl / 6").
* Fix: variant cost falls back to parent product cost when not set on the individual variation.
* Fix: when a product is linked by SKU match, variant IDs are fetched before the update payload is built.

= 1.1.9 =
* Fix: variable products already linked to Holded (via SKU match) now correctly fetch variant IDs before the first update, preventing "Cannot update product variants" errors.

= 1.1.8 =
* Fix: variable product variants now sync correctly — after each push the plugin fetches Holded variant IDs and stores them on WC variations, so subsequent updates target the correct variant instead of failing with "Cannot update product variants".

= 1.1.7 =
* Fix: variable products no longer send price: 0 to Holded — price is managed at variant level.
* Fix: removed image sync feature — Holded API silently ignores the image field; images must be set via Holded UI.
* Enhancement: added "Product images" entry to Known limitations.

= 1.1.6 =
* Fix: log table message column no longer overflows horizontally.
* Enhancement: page reloads automatically after manual push/pull so the log is visible without a manual refresh.

= 1.1.5 =
* Fix: removed deprecated load_plugin_textdomain() call (translations loaded automatically by WordPress).
* Fix: sanitize _cost_price POST input via sanitize_text_field() before wc_format_decimal().
* Enhancement: image sync option (WC → Holded) — sends the product featured image URL to Holded.
* Enhancement: image overwrite control — by default the image is sent only on first sync; an "Overwrite existing image" option forces resend on every sync.

= 1.1.4 =
* Fix: unschedule now uses both Action Scheduler and WooCommerce queue API to ensure stale jobs are reliably removed when pull sync is disabled.

= 1.1.3 =
* Fix: on init, if pull sync is disabled and an Action Scheduler job is still queued, it is now automatically removed.

= 1.1.2 =
* Fix: disabling Holded → WC sync now immediately unschedules the Action Scheduler job; re-enabling reschedules it. "Next scheduled run" is no longer displayed when pull sync is disabled.

= 1.1.1 =
* Fix: sale price sync (WC → Holded) now respects sale date range — sends sale price only when the product is currently on sale; falls back to regular price outside the scheduled period.

= 1.1.0 =
* Fix: Holded → WC price now correctly adds tax back when WooCommerce is configured with tax-inclusive prices, and is always rounded to 2 decimal places.
* Fix: WC → Holded price correctly rounded to 2 decimal places.
* Fix: duplicate product creation in Holded — before creating, the plugin now searches by SKU and links/updates if a match is found.
* Fix: GROUP constant in Action Scheduler corrected from 'ctholded' to 'cthls'.
* Fix: Action Scheduler self-healing on init; plugins_loaded priority raised to 20.
* Enhancement: sync direction is now independently configurable (WC → Holded and/or Holded → WC).
* Enhancement: manual push button (WC → Holded bulk) added to the settings page.
* Enhancement: sync sale price option (WC → Holded): sends sale price to Holded instead of regular price when set.
* Enhancement: Description source setting now applies bidirectionally.
* Enhancement: collapsible Event reference legend in System log.
* Enhancement: next scheduled run displayed in the Manual sync card.
* Enhancement: Reschedule button added to force Action Scheduler registration.
* Enhancement: Known limitations card added with note on Holded price tiers API limitation.
* Enhancement: log reduced to last 50 entries.
* Enhancement: Italian and Spanish translations updated.

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
