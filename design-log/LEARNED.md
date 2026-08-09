# Learned - Learning memory (local)

> Technical knowledge specific to this project. Read at every /startsession.

## Technical Patterns

- Holded API v2 `GET /products`/`GET /products/{id}`: the `price` field (net) is truncated to 2 decimals in the response, even when the actual stored value has more precision. A separate `total` field carries the correct full-precision gross, already computed by Holded internally. `total` can be briefly stale (a few seconds) right after another system writes a price update to Holded (propagation lag, not a precision issue) — worth a small retry/delay if polling immediately after a write.
- In `includes/class-cthls-sync.php`, the Holded→WC price-pull path logs a `pull_price_check` event for **variations** (`holded_product_to_wc()`, variation branch) but NOT for **simple products** (same function, simple-product branch) — a silent diagnostic gap for any future price-sync report on a simple product. If adding logging there, follow the variation branch's log format.

## Mistakes Not to Repeat

- The `cthls_sync_prices` ("Sincronizza prezzi") checkbox is registered as a WP option and shown in the settings UI, but is never actually read anywhere in `class-cthls-sync.php` — price sync always runs regardless of its state. Don't assume a visible settings checkbox is wired up; grep for the option name before trusting it gates behavior.

## User Preferences

## Reusable Solutions
