# CartTrigger – Holded Sync

<p>
  <img src="https://img.shields.io/badge/version-1.0.3-0a0a23?style=flat-square" alt="Version 1.0.3">
  <img src="https://img.shields.io/badge/WordPress-6.3%2B-3858e9?style=flat-square&logo=wordpress&logoColor=white" alt="WordPress 6.3+">
  <img src="https://img.shields.io/badge/WooCommerce-required-96588a?style=flat-square&logo=woocommerce&logoColor=white" alt="WooCommerce required">
  <img src="https://img.shields.io/badge/PHP-7.4%2B-777bb4?style=flat-square&logo=php&logoColor=white" alt="PHP 7.4+">
  <img src="https://img.shields.io/badge/license-GPLv2-38a169?style=flat-square" alt="GPLv2">
</p>

> **Work in progress** — this plugin is under active development. Features may change and some functionality may not yet be complete. For questions, feedback or support, contact us at [info@poletto.es](mailto:info@poletto.es).

Bidirectional sync between WooCommerce products/stock and Holded ERP. Changes in WooCommerce are pushed to Holded in real time; changes in Holded are pulled into WooCommerce on a configurable schedule via Action Scheduler.

## How it works

### WooCommerce → Holded (real-time)

Every time a product is saved or a stock change occurs in WooCommerce, the plugin pushes the update to Holded immediately — no manual work needed.

### Holded → WooCommerce (scheduled or manual pull)

Since Holded does not provide outbound webhooks, the plugin pulls product and stock data from Holded on a scheduled basis (default: every 15 minutes) using Action Scheduler, bundled with WooCommerce. The interval is fully configurable. A manual pull can also be triggered at any time from the settings page.

## What is synced

| Field | WC → Holded | Holded → WC |
|---|---|---|
| Product name | ✓ | ✓ |
| Regular price | ✓ (optional) | ✓ (optional) |
| Description | ✓ (optional) | ✓ (optional) |
| SKU | ✓ | ✓ |
| Stock quantity | ✓ (optional) | ✓ (optional) |
| Product variants | ✓ ¹ | — |
| Brand appended to name | ✓ (optional) | — |

_¹ Variable products and variant sync require the **Gemma Inventario** add-on in your Holded subscription._

## Product matching

Products are matched between the two systems by SKU. On first sync the Holded product ID is stored in WooCommerce product meta (`_ctholded_product_id`) to speed up subsequent syncs.

## Tax handling

If WooCommerce prices are tax-inclusive (common in Spain at 21% VAT), the plugin can strip the tax before sending the net price to Holded. Enable the **"Prices include tax"** option in settings and set the fallback tax rate if WooCommerce cannot determine it automatically.

## Settings

Go to **WooCommerce → Holded Sync** to configure:

- **Holded API Key** — generate one in Holded under Settings → Integrations → API
- **Warehouse** — select the Holded warehouse to use for stock movements
- **Default tax rate** — fallback VAT rate (default: 21)
- **Enable sync** — master switch for bidirectional sync
- **Pull interval** — how often to pull from Holded (minimum 5 minutes, default 15)
- **Prices include tax** — strip VAT from WooCommerce prices before sending to Holded
- **Sync stock / prices / description** — enable each field independently
- **Append brand to name** — append the `product_brand` taxonomy term to the product name in Holded
- **Enable log** — keep the last 100 sync messages for debugging

## Product tab

Each WooCommerce product has a **Holded Sync** tab inside the native **Product data** meta box (the same panel that contains General, Inventory, Shipping, etc.).

The following fields are available:

| Field | Meta key | Description |
|---|---|---|
| Description for Holded | `_ctholded_description` | Short description sent to Holded instead of the full product description. Leave empty to skip. |
| Cost price | `_cost_price` | Net cost price sent to Holded (excluding tax). |
| Barcode | `_barcode` | EAN, UPC or any barcode format sent to Holded. |
| Holded product ID | `_ctholded_product_id` | Read-only. Populated automatically on first sync — identifies the linked product in Holded. |

## Requirements

- WordPress **6.3** or later
- WooCommerce _(required, 8.0+ recommended)_
- PHP **7.4** or later

Tested with WordPress **6.9** and WooCommerce **10.6.1**.

## Installation

1. Clone this repository or download the ZIP and upload to `/wp-content/plugins/`.
2. Activate the plugin from **Plugins** in your WordPress admin.
3. Go to **WooCommerce → Holded Sync** and enter your Holded API key.
4. Test the connection, select a warehouse, enable sync and configure which fields to synchronise.

## Changelog

### 1.0.3

- Renamed plugin directory to `carttrigger-holded-sync` and main file accordingly.
- Renamed all PHP prefixes to `cthls_` / `CTHLS_` to comply with the WordPress 5-character unique prefix requirement.
- Updated text domain to `carttrigger-holded-sync`.
- Renamed all include files, asset files and language files accordingly.
- Added Italian (it_IT) and Spanish (es_ES) translations.
- Added cost price and barcode fields to the Holded Sync product tab.

### 1.0.2

- Fix: nonce verification added explicitly in product meta save callback.
- Fix: plugin name aligned between plugin header and readme.txt.
- Enhancement: redesigned settings page with card-based layout and icons.
- Enhancement: added "Append brand to name" option.
- Enhancement: replaced WP-Cron with Action Scheduler for Holded pull.
- Enhancement: configurable pull interval (default 15 minutes).

### 1.0.0

- Initial release.

---

**Disclaimer:** this plugin is an independent open source project. We are not affiliated with, sponsored by, or paid by [Holded Technologies S.L.](https://www.holded.com) in any way. All product names and trademarks are the property of their respective owners.

---

[GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html) — developed by [Poletto 1976 S.L.U.](https://poletto.es)
