# TO_FIX - Bugs to fix

### 🔴 CRITICAL

### 🟡 MEDIUM

- [ ] **Single-SKU pull (Holded→WC) doesn't update price — NOT reproduced; investigate secondary lead (page-load hang)** — Reported 2026-08-05: user changed price of SKU `SAN-TI-CUOOR-22-B-75-6` in Holded (29,50→29€), ran "Sync single SKU → Import from Holded" on production (poletto.es), price stayed 29,50€ in WC. Also could not load `wp-admin/admin.php?page=cthls-settings` at first to check the log.
  - **Debug-log (2026-08-05):** Re-ran the exact repro live in production via browser automation: entered SKU `SAN-TI-CUOOR-22-B-75-6`, clicked "Importa da Holded". Network: `admin-ajax.php` POST → 200, followed by an automatic page reload (expected UI behavior). Re-checked the WC product list (`Cuor di Vigna`, product ID 663, simple product) immediately after: **price now shows 29,00€ — correctly updated.** Bug NOT reproduced on this attempt.
  - **Divergence point:** unclear — could not confirm whether the user's original attempt actually completed (the settings page was failing to load for them at the time, "probabilmente perché stava girando il cron"), so the single-SKU AJAX click may never have fired/completed originally. Price may have simply been stale in their view (cache/screen not refreshed) rather than a real sync failure.
  - **Separately confirmed via code reading (still valid, not the root cause of this report but a real gap):** in the locally-committed source (`v1.6.2`, `includes/class-cthls-sync.php`), the price-update path for a **simple product** pull (~line 718-724) never calls `self::log()`, unlike the **variation** path (~line 653-668) which logs a `pull_price_check` event every time. This makes diagnosing a future simple-product price-sync issue much harder (no log trail at all). **Note: production is running v1.6.4, this repo's committed code is v1.6.2 — production code has diverged from the repo (see PROJECT_UPDATES.md).**
  - **Also noted (separately, not this bug's cause):** the "Sincronizza prezzi" (`cthls_sync_prices`) checkbox is registered as a WP option but never read anywhere in `class-cthls-sync.php` — price sync always runs regardless of the checkbox state.
  - **Next step if it recurs:** ask user to reproduce again, screenshot the AJAX response/success message directly (don't let it reload before reading), and check whether the page-load hang recurs — that hang, if real and reproducible, is a more concrete lead than the price sync itself.

### 🟢 LOW
