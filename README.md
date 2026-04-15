# CartTrigger – Holded Sync

<p>
  <img src="https://img.shields.io/badge/version-1.2.0-0a0a23?style=flat-square" alt="Version 1.2.0">
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

Each direction can be enabled independently in settings.

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
| Sync image                  | Send the product featured image URL to Holded (WC → Holded only)                 |
| Overwrite existing image    | If unchecked, image is sent only on first sync                                   |
| Enable log                  | Stores last 50 sync events for debugging                                         |

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

- **Secondary price tiers** — The Holded API does not expose secondary price rates. Only the main price (Tarifa principal) is synced. We are active Holded users and will add support for additional price tiers as soon as the API makes them available.
- **Product images** — The Holded API does not support setting product images via REST API. Images must be uploaded manually through the Holded interface.

---

## Changelog

### 1.2.0

- Fix: each variant now includes a `name` field (attribute value combination, e.g. "75cl / 6") required by the Holded API for variant updates.
- Fix: variant cost falls back to the parent product cost if not set on the individual variation.

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
