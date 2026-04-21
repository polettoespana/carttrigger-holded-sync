# CartTrigger – Holded Sync

<p>
  <img src="https://img.shields.io/badge/version-1.4.7-0a0a23?style=flat-square" alt="Version 1.4.7">
  <img src="https://img.shields.io/badge/WordPress-6.3%2B-3858e9?style=flat-square&logo=wordpress&logoColor=white" alt="WordPress 6.3+">
  <img src="https://img.shields.io/badge/WooCommerce-8.0%2B-96588a?style=flat-square" alt="WooCommerce 8.0+">
  <img src="https://img.shields.io/badge/PHP-7.4%2B-777bb4?style=flat-square&logo=php&logoColor=white" alt="PHP 7.4+">
  <img src="https://img.shields.io/badge/license-GPLv2-38a169?style=flat-square" alt="GPLv2">
</p>

> **Work in progress** — this plugin is under active development. For questions or support contact [info@poletto.es](mailto:info@poletto.es).

Bidirectional sync between WooCommerce products/stock and Holded ERP.

---

## How it works

| Direction       | Trigger                                   | Notes                                                      |
| --------------- | ----------------------------------------- | ---------------------------------------------------------- |
| **WC → Holded** | Real-time on product save or stock change | Can also be triggered manually (bulk push)                 |
| **Holded → WC** | Scheduled pull via Action Scheduler       | Default every 15 min — configurable. Manual pull available |
| **Orders**      | On payment confirmation (`woocommerce_payment_complete`) | Creates a Holded document (invoice or sales order) for each paid order |

Each sync direction can be enabled independently in settings.

---

## What is synced

| Field                  | WC → Holded  | Holded → WC |
| ---------------------- | ------------ | ----------- |
| Product name           | ✓            | ✓           |
| Regular price          | ✓ optional   | ✓ optional  |
| Sale price             | ✓ optional ¹ | —           |
| Description            | ✓ optional   | ✓ optional  |
| SKU                    | ✓            | ✓           |
| Stock quantity         | ✓ optional   | ✓ optional  |
| Brand appended to name | ✓ optional   | —           |

<sub>¹ Sent only when the product is currently on sale (respects scheduled date range). If no sale price is active, the regular price is sent instead.</sub>

---

## Settings

**WooCommerce → Holded Sync** in the WordPress admin.

| Option               | Description                                                                      |
| -------------------- | -------------------------------------------------------------------------------- |
| Holded API Key       | Generate in Holded under **Configuración → Más → Desarrolladores**               |
| Warehouse            | Holded warehouse for stock movements                                             |
| Default tax rate     | Fallback VAT rate (default: 21)                                                  |
| Sync direction       | Enable WC→Holded and/or Holded→WC independently                                  |
| Pull interval        | How often to pull from Holded (min 5 min, default 15)                            |
| Prices include tax   | Strips VAT before sending to Holded; adds it back when pulling                   |
| Sync stock           | Enable stock sync (both directions)                                              |
| Sync regular price   | Enable regular price sync (both directions)                                      |
| Sync sale price      | Send sale price to Holded instead of regular price when on sale (WC→Holded only) |
| Sync description     | Enable description sync (both directions)                                        |
| Description source   | Custom field (Holded Sync tab) or full WooCommerce description                   |
| Append brand to name        | Appends `product_brand` taxonomy term to product name in Holded                  |
| Variation name format       | Space, Dash (`–`) or Parentheses separator between parent name and attribute values |
| Enable log                  | Stores last 50 sync events for debugging                                         |

### Orders → Holded documents

| Option                    | Description                                                                                          |
| ------------------------- | ---------------------------------------------------------------------------------------------------- |
| Create document           | Enable automatic document creation in Holded on payment confirmation                                 |
| Document type             | **Invoice** (factura, reduces Holded stock) or **Sales order** (pedido de venta, does not affect stock) |
| Avoid stock duplication   | After creating a document, re-pushes WC stock to Holded. For invoices: corrects the reduction caused by the invoice. For sales orders: prevents the Holded→WC pull from restoring the pre-sale quantity |
| NIF/CIF meta key          | Order meta key where the customer NIF/CIF/NIE is stored. Default: `_billing_nif`                     |
| Email meta key            | Order meta key for the customer email. Leave empty to use the standard WooCommerce billing email     |

---

## Order meta

When a document is created in Holded, the following meta keys are stored on the WC order:

| Meta key             | Content                              |
| -------------------- | ------------------------------------ |
| `_cthls_contact_id`  | Holded contact ID (found or created) |
| `_cthls_invoice_id`  | Holded document ID (invoice or sales order) |

The Holded contact ID is also saved on the WP user (`_cthls_contact_id`) so subsequent orders by the same customer reuse the existing Holded contact without an extra API call.

---

## Product tab

Each product has a **Holded Sync** tab in the **Product data** meta box.

| Field                  | Meta key             | Notes                                                 |
| ---------------------- | -------------------- | ----------------------------------------------------- |
| Description for Holded | `_cthls_description` | Used when Description source is set to "Custom field" |
| Cost price             | `_cost_price`        | Net cost price sent to Holded (excl. tax)             |
| Barcode                | `_barcode`           | EAN, UPC or any barcode format                        |
| Holded product ID      | `_cthls_product_id`  | Auto-populated on first sync. Read-only               |

---

## Tax handling

Holded stores prices as net (excl. tax). WooCommerce can store prices either way.

- **WC → Holded**: if _Prices include tax_ is enabled, the plugin strips VAT before sending the net price to Holded.
- **Holded → WC**: if _Prices include tax_ is enabled, the plugin adds VAT back before saving in WooCommerce.

All prices are rounded to 2 decimal places.

---

## Product matching

Products are matched by **SKU**. On first sync the Holded product ID is stored in `_cthls_product_id`. Before creating a new product in Holded, the plugin searches by SKU to avoid duplicates — if a match is found it links and updates instead of creating.

---

## Requirements

- WordPress **6.3+**
- WooCommerce _(required, 8.0+ recommended)_ — tested up to **10.7.0**
- PHP **7.4+**

---

## Installation

1. Clone or download the ZIP → upload to `/wp-content/plugins/`.
2. Activate from **Plugins** in WordPress admin.
3. Go to **WooCommerce → Holded Sync**, enter your API key and configure.

---

## Known limitations

- **Variable products — flattened to simple in Holded** — Holded does not handle product variants reliably via API. Variable products in WooCommerce are pushed to Holded as separate simple products — one per variation — each with its own SKU. The parent variable product is never created in Holded. During pull (Holded → WC), each Holded product is matched by SKU to the corresponding WC variation; only stock and price are updated. The product structure in WooCommerce is never altered by the pull.
- **Multiple price tiers** — The Holded API does not expose secondary price rates (e.g. Ho.re.ca). Only the main price is synced. Additional price tiers must be set manually in Holded for each product (including each variation pushed as a simple product). Support will be added as soon as Holded makes them available via API.
- **Product images** — The Holded API does not support setting product images via REST API. Images must be uploaded manually through the Holded interface.

---

## Changelog

### 1.4.7

- Fix: "Avoid stock duplication" now also applies to sales orders. When Holded→WC pull is active and the document type is Sales order, the pull was restoring the pre-sale quantity in WooCommerce (Holded stock not reduced → pull sees old value → overwrites WC). The re-sync now runs after both invoices and sales orders.

### 1.4.6

- Fix: stock update to Holded was not applying — the `/stock` endpoint requires a nested body format `{"stock": {"warehouseId": {"productId": delta}}}`. Previous versions sent `{"stock": {"warehouseId": delta}}` which Holded accepted with 200 OK but silently ignored.

### 1.4.5

- Fix: stock updates were not working because the Holded `/stock` endpoint is **delta-based** (positive = add units, negative = remove), not an absolute setter. The plugin now fetches the current Holded stock first, computes the required delta (`desired − current`), and sends that. If stock is already at the desired value, no API call is made.

### 1.4.4

- Fix: stock of simple products was never updated in Holded — only the general product update (`PUT /products/{id}`) was called, which Holded ignores for stock. Now also calls `PUT /products/{id}/stock` after every product save.
- Fix: stock updates now include `warehouseId` when a warehouse is configured in the plugin settings. Without it, Holded was updating a different warehouse than the one visible in the UI.

### 1.4.3

- Fix: stock of variable product variations was not updated in Holded even when the payload correctly contained `stock: 0`. Root cause: Holded ignores the `stock` field in `PUT /products/{id}`. Stock is now updated via the dedicated `PUT /products/{id}/stock` endpoint after every variation sync.

### 1.4.2

- Fix: when all variations of a variable product are set to stock 0 in WooCommerce, only the last variation was synced to Holded. Root cause: WooCommerce fires `woocommerce_update_product` for the parent on every variation save; the deduplication guard caused `sync_variable_product` to run before all variation stocks were committed to the database. Fixed by deferring the sync to the `shutdown` hook, ensuring all DB writes are complete before stock values are read.

### 1.4.1

- Enhancement: after a Holded → WC pull that updates at least one product, the LiteSpeed Cache is automatically purged (`litespeed_purge_all`). No effect if LiteSpeed Cache is not installed.

### 1.4.0

- Feature: **Orders → Holded documents** — on payment confirmation (`woocommerce_payment_complete`), the plugin automatically finds or creates the Holded contact (matched by NIF/CIF then email) and creates an invoice or sales order with all line items, shipping and tax.
- Feature: **Document type** setting — choose between Invoice (factura, reduces Holded stock) and Sales order (pedido de venta, does not affect stock).
- Feature: **Avoid stock duplication** — after creating an invoice, re-pushes WC stock to Holded to correct the stock reduction caused by the invoice. Allows keeping WC→Holded stock push active alongside invoice creation.
- Feature: **Variation name format** — new "Dash" option (`Benaco – Magnum (1,5 litros)`) to avoid nested parentheses when attribute values already contain parentheses.
- Enhancement: Holded contact ID stored in order meta (`_cthls_contact_id`) and WP user meta for reuse across orders.
- Enhancement: new admin settings — NIF/CIF meta key (default `_billing_nif`), email meta key (optional override).
- Translations (ES, IT) updated for all new strings.

### 1.3.8

- Enhancement: Manual push, pull and single SKU sync now always run regardless of the automatic sync direction settings — disabling automatic sync no longer blocks manual operations.
- Enhancement: "Sync direction" setting renamed to "Automatic sync direction" with an explanatory note to clarify the distinction.

### 1.3.7

- Enhancement: Single SKU sync — new card in settings page to push or pull a single product/variation by SKU without running a full bulk sync.

### 1.3.6

- Debug: added `pull_price_check` log event for variations — logs Holded raw price, converted WC price, current WC price and whether an update is triggered.

### 1.3.5

- Enhancement: new "Include draft products" option — when enabled, draft products are included in both real-time and bulk push (WC → Holded). The pull direction already handles any product regardless of status.

### 1.3.4

- Enhancement: new "Variation name format" setting — choose between space ("Benaco Magnum 15 litros") or parentheses ("Benaco (Magnum 15 litros)") when pushing variation names to Holded.

### 1.3.3

- Fix: variation names pushed to Holded now use the readable attribute label instead of the taxonomy slug (e.g. "Magnum 15 litros" instead of "magnum-15-litros").

### 1.3.2

- Fix: product name in WooCommerce is never overwritten from Holded — WC is the source of truth. A `pull_name_skipped` log entry is recorded when the name in Holded differs.
- Fix: price of WC variations is now correctly synced from Holded during pull (variations are pushed as simple products, so their price is fully accessible via API).

### 1.3.1

- Fix: when "Append brand to name" is enabled, the product name is no longer overwritten in WooCommerce during pull — prevents the brand from being appended repeatedly on each push/pull cycle ("Averoldi Averoldi Averoldi…").

### 1.3.0

- Change: variable products in WooCommerce are now pushed to Holded as separate simple products — one per variation — instead of a single product with variants. Holded does not handle variant-type products correctly via API.
- Change: Holded → WC pull now matches Holded products to WC variations by SKU and updates only stock and price; the product structure (parent/variation) in WooCommerce is never modified.
- Change: `_cthls_product_id` is now stored on each WC variation directly (not on the parent). Stock sync for variations uses the variation's own Holded product ID.
- Known limitation updated: variable product handling and price tiers.

### 1.2.3

- Fix: variable products no longer send the `variants` array in PUT requests — the Holded API rejects PUT on `kind: variants` products regardless of payload. After initial creation, only stock is kept in sync via the dedicated `/stock` endpoint. Name, price and description changes on variable products must be made manually in Holded.
- Known limitation added: variable product updates not supported by Holded REST API.

### 1.2.2

- (internal — superseded by 1.2.3)

### 1.2.1

- (internal — superseded by 1.2.2)

### 1.2.0

- Fix: variant price field corrected from `price` to `subtotal` (Holded API input field name).
- Fix: each variant now includes a `name` field (attribute values, e.g. "75cl / 6").
- Fix: variant cost falls back to parent product cost when not set on the individual variation.
- Fix: when a product is linked by SKU match, variant IDs are fetched before the update payload is built.

### 1.1.9

- Fix: variable products already linked to Holded (via SKU match) now correctly fetch variant IDs before the first update, preventing "Cannot update product variants" errors.

### 1.1.8

- Fix: variable product variants now sync correctly — after each push the plugin fetches Holded variant IDs and stores them on WC variations, so subsequent updates target the correct variant instead of failing with "Cannot update product variants".

### 1.1.7

- Fix: variable products no longer send `price: 0` to Holded — price is managed at variant level.
- Fix: removed image sync — Holded API silently ignores the `image` field; images must be set via the Holded UI.
- Enhancement: added "Product images" to Known limitations.

### 1.1.6

- Fix: log table message column no longer overflows horizontally.
- Enhancement: page reloads automatically after manual push/pull so the log is visible without a manual refresh.

### 1.1.5

- Fix: removed deprecated `load_plugin_textdomain()` call.
- Fix: sanitize `_cost_price` POST input before `wc_format_decimal()`.
- Enhancement: image sync option (WC → Holded) — sends the product featured image URL to Holded.
- Enhancement: image overwrite control — by default the image is sent only on first sync; enable "Overwrite existing image" to force resend on every sync.

### 1.1.4

- Fix: unschedule now uses both Action Scheduler and WooCommerce queue API to ensure stale jobs are reliably removed when pull sync is disabled.

### 1.1.3

- Fix: on init, if pull sync is disabled and an Action Scheduler job is still queued, it is now automatically removed.

### 1.1.2

- Fix: disabling Holded → WC sync now immediately unschedules the Action Scheduler job; re-enabling reschedules it. "Next scheduled run" is no longer shown when pull is disabled.

### 1.1.1

- Fix: sale price sync (WC→Holded) now respects sale date range — falls back to regular price outside the scheduled period.

### 1.1.0

- Fix: Holded→WC price now adds tax back when WC uses tax-inclusive prices, rounded to 2 decimals.
- Fix: WC→Holded price rounded to 2 decimal places.
- Fix: duplicate Holded product prevented via SKU lookup before create.
- Fix: Action Scheduler GROUP constant, self-healing on init, `plugins_loaded` priority raised to 20.
- Enhancement: sync direction independently configurable (push / pull).
- Enhancement: manual bulk push (WC→Holded) from settings page.
- Enhancement: sync sale price option (WC→Holded), respecting scheduled dates.
- Enhancement: description source applies bidirectionally.
- Enhancement: event reference legend, next scheduled run, reschedule button in admin.
- Enhancement: known limitations card (Holded price tiers API not supported).
- Enhancement: log limited to 50 entries. Translations updated (it_IT, es_ES).

### 1.0.4

- Fix: admin buttons restore translated text after each action.

### 1.0.3

- Renamed to `carttrigger-holded-sync`, PHP prefix to `cthls_` / `CTHLS_`.
- Added it_IT and es_ES translations.
- Added cost price and barcode fields to product tab.

### 1.0.2

- Fix: nonce verification in product meta save.
- Redesigned settings page (card layout, icons).
- Added brand append option, Action Scheduler pull, configurable interval.

### 1.0.0

- Initial release.

---

<sub>**Disclaimer:** independent open source project, not affiliated with <a href="https://www.holded.com">Holded Technologies S.L.</a> — <a href="https://www.gnu.org/licenses/gpl-2.0.html">GPLv2 or later</a> — developed by <a href="https://poletto.es">Poletto 1976 S.L.U.</a></sub>
