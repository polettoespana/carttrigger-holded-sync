# Project

## What it is
**CartTrigger – Holded Sync** — a WordPress/WooCommerce plugin providing bidirectional sync between WooCommerce (products, stock, prices) and Holded ERP, plus automatic creation of Holded invoices/sales-orders when a WooCommerce order is paid.

## Purpose
Keep a WooCommerce store and Holded ERP in sync without manual duplicate data entry: product/stock/price changes flow both ways, and paid orders automatically generate the corresponding Holded accounting document (invoice or sales order) with the right contact, line items, shipping and tax.

## Target
Clients of Poletto 1976 S.L.U. running WooCommerce stores who also use Holded as ERP/invoicing. Client-facing commercial plugin (support via info@poletto.es), not a public marketplace listing. Currently "work in progress" / active development.

## Technology stack
- PHP 7.4+
- WordPress 6.3+
- WooCommerce 8.0+ (tested up to 10.7.0)
- Action Scheduler (WC's async job library) — drives the Holded→WC pull cron
- Holded ERP REST API **v2** (`https://api.holded.com/api/v2/`, Bearer auth) — migrated from v1 in v1.6.0
- No build step: plain PHP, no composer/npm. Manual versioning (bumped in plugin header + README badge/changelog).
- No automated test suite.

## Architecture
Classic WP plugin bootstrap (`carttrigger-holded-sync.php`) requiring 6 static-init classes under `includes/`:

| Class | File | Lines | Role |
|---|---|---|---|
| `CTHLS_API` | class-cthls-api.php | 360 | Thin Holded API v2 client |
| `CTHLS_Sync` | class-cthls-sync.php | 1006 | Core bidirectional product/stock/price sync engine |
| `CTHLS_Admin` | class-cthls-admin.php | 784 | Settings page, product tab UI, manual sync buttons, log viewer |
| `CTHLS_Cron` | class-cthls-cron.php | 103 | Action Scheduler registration for Holded→WC pull |
| `CTHLS_Product_Meta` | class-cthls-product-meta.php | 138 | Custom product fields (cost price, barcode, Holded description) |
| `CTHLS_Orders` | class-cthls-orders.php | 334 | Order→Holded document creation on payment, contact matching |

Hook-driven: real-time push on product save/stock change, scheduled pull via Action Scheduler (default every 15 min), `woocommerce_payment_complete` for order documents.

`assets/css` + `assets/js` for admin UI. `languages/` has es_ES and it_IT translations (.po/.mo) plus a .pot template.

## Main features
1. WC → Holded real-time push (name, price, stock, description, SKU, brand) on product save/stock change
2. Holded → WC scheduled pull (stock/price/description), configurable interval, min 5 min / default 15 min
3. Orders → Holded documents (invoice or sales order) on payment confirmation, with contact find-or-create (NIF/CIF → email)
4. Product tab (Holded Sync) for cost price, barcode, custom Holded description, read-only Holded product ID
5. Admin settings page: manual bulk push/pull, single-SKU sync, sync event log (last N entries, exportable as JSON)

## Product matching
Matched by SKU. `_cthls_product_id` stores the linked Holded product ID (on the WC product, or on each variation for variable products). Before creating, searches Holded by SKU to avoid duplicates.

## Known limitations (documented, accepted constraints)
- **Variable products flattened to simple in Holded** — each variation pushed as its own simple product (Holded doesn't handle variants reliably via API); the parent variable product is never created in Holded.
- **Multiple price tiers not supported** — Holded API doesn't expose secondary price rates (e.g. Ho.re.ca); only main price synced.
- **Product images** — uploaded only on first creation; image updates are not synced (Holded API doesn't support replacing images).

## Current state (as of 2026-08-05)
- Latest work: v1.6.2 — fixed Holded API v2 payload issues (variation SKU dedup, `taxes[]` array field, price-as-decimal-string, stale-ID 404 fallback with auto-recreate/re-link).
- v1.6.0 was a breaking migration from Holded API v1 to v2 (June 2026): base URL, Bearer auth, cursor pagination, comma-decimal price parsing, updated invoice/sales-order endpoints.
- Design-log tracking (this system) is newly set up for this repo — no prior TODO/bug backlog existed before this session.
