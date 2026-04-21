=== CartTrigger – Holded Sync ===
Contributors: polettoespana
Tags: woocommerce, holded, sync, inventory, products
Requires at least: 6.3
Tested up to: 6.9
Requires PHP: 7.4
WC tested up to: 10.6.1
Requires Plugins: woocommerce
Stable tag: 1.4.8
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

= 1.4.8 =
* Enhancement: configurable log entries limit — new "Log entries limit" field (default 50, range 10–500) replaces the hardcoded 50.
* Enhancement: "Export log (JSON)" button in the system log — downloads the current log as a JSON file.
* Fix: missing translations for Single SKU sync card and several event reference descriptions.

= 1.4.7 =
* Fix: "Avoid stock duplication" now also applies to sales orders. When Holded→WC pull is active and the document type is Sales order, the pull was restoring the pre-sale quantity in WooCommerce (because Holded stock had not been reduced). The re-sync now runs after both invoices and sales orders.

= 1.4.6 =
* Fix: stock update to Holded was not applying — the /stock endpoint requires a nested body format {"stock": {"warehouseId": {"productId": delta}}}. Previous versions sent {"stock": {"warehouseId": delta}} which Holded accepted (200 OK) but silently ignored.

= 1.4.5 =
* Fix: stock updates were not working because the Holded /stock endpoint is delta-based (positive = add units, negative = remove), not an absolute setter. The plugin now fetches the current Holded stock first, computes the required delta, and sends that. If stock is already at the desired value, no API call is made.

= 1.4.4 =
* Fix: stock of simple products was never updated in Holded — only the general product update (PUT /products/{id}) was called, which Holded ignores for stock. Now also calls PUT /products/{id}/stock after every product save.
* Fix: stock updates now include warehouseId when a warehouse is configured in the plugin settings. Without it, Holded was updating a different warehouse than the one visible in the UI.

= 1.4.3 =
* Fix: stock of variable product variations was not updated in Holded even when the payload correctly contained stock:0. Root cause: Holded ignores the stock field in PUT /products/{id}. Stock is now updated via the dedicated PUT /products/{id}/stock endpoint after every variation sync.

= 1.4.2 =
* Fix: when all variations of a variable product are set to stock 0 in WooCommerce, only the last variation was synced to Holded. Root cause: WooCommerce fires woocommerce_update_product for the parent on every variation save; the deduplication guard caused sync_variable_product to run before all variation stocks were committed. Fixed by deferring the sync to the shutdown hook, ensuring all DB writes are complete before stock is read.

= 1.4.1 =
* Enhancement: after a Holded → WC pull that updates at least one product, LiteSpeed Cache is automatically purged. No effect if LiteSpeed Cache is not installed.

= 1.4.0 =
* Feature: Orders → Holded documents — on payment confirmation, the plugin finds or creates the Holded contact (matched by NIF/CIF then email) and creates an invoice or sales order with all line items, shipping and tax.
* Feature: Document type setting — choose between Invoice (factura, reduces Holded stock) and Sales order (pedido de venta, does not affect Holded stock).
* Feature: Avoid stock duplication — after creating an invoice, re-pushes WC stock to Holded to correct the stock reduction caused by the invoice. Allows keeping WC→Holded stock push active alongside invoice creation.
* Feature: Variation name format — new "Dash" option (Benaco – Magnum (1,5 litros)) to avoid nested parentheses when attribute values already contain parentheses.
* Enhancement: Holded contact ID stored in order meta (_cthls_contact_id) and WP user meta for reuse across orders.
* Enhancement: configurable NIF/CIF meta key (default _billing_nif) and email meta key (optional override of WC billing email).
* Enhancement: translations (ES, IT) updated for all new strings.

= 1.3.8 =
* Enhancement: Manual push, pull and single SKU sync now always run regardless of the automatic sync direction settings.
* Enhancement: "Sync direction" setting renamed to "Automatic sync direction" with a note clarifying it only affects automatic sync.

= 1.3.7 =
* Enhancement: Single SKU sync — new card in settings page to push or pull a single product/variation by SKU without running a full bulk sync.

= 1.3.6 =
* Debug: added pull_price_check log event for variations — logs Holded raw price, converted WC price, current WC price and whether an update is triggered.

= 1.3.5 =
* Enhancement: new "Include draft products" option — when enabled, draft products are included in both real-time and bulk push (WC → Holded).

= 1.3.4 =
* Enhancement: new "Variation name format" setting — choose between space ("Benaco Magnum 15 litros") or parentheses ("Benaco (Magnum 15 litros)") when pushing variation names to Holded.

= 1.3.3 =
* Fix: variation names pushed to Holded now use the readable attribute label instead of the taxonomy slug (e.g. "Magnum 15 litros" instead of "magnum-15-litros").

= 1.3.2 =
* Fix: product name in WooCommerce is never overwritten from Holded — WC is the source of truth. A log entry (pull_name_skipped) is recorded when the name in Holded differs.
* Fix: price of WC variations now correctly synced from Holded during pull.

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
