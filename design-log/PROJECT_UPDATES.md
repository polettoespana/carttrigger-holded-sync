# Project Updates

> Project-specific context/conventions/operational decisions.

---

## Project Context / Conventions

### Every deployable change gets its own version + git tag (established 2026-08-09)
This project bumps a distinct plugin version and pushes a matching git tag for every change deployed to production — including documentation-only changes (e.g. bumping the readme "tested up to" fields), not just functional fixes. Two versions were released back-to-back on 2026-08-09 (v1.6.6 for the price-drift fix, v1.6.7 for a compatibility-only readme update) to keep this granularity.

### Cross-project handoffs with hora-app use a plain root-level .md (established 2026-08-09)
Ad-hoc diagnostic handoffs with hora-app (the internal ERP app that also writes to Holded) are done via a standalone `.md` file at this repo's root, which the user copies manually into the other session — not the formal `.plan/inbox/` cross-project coordination system (which assumes both subs are enrolled via `/startcoord`). Used once for the price-drift investigation (`HANDOFF-price-drift-to-hora-app.md`, since removed after use — it's a transient exchange artifact, not meant to persist in the repo).

### Deploy source is a SEPARATE, uncommitted local clone — not this repo (found 2026-08-05)
Production (`https://poletto.es`) runs plugin **v1.6.4**, but the working copy in this repo (`~/Sites/poletto/wp-content/plugins/carttrigger-holded-sync`) is at **v1.6.2**. Root cause: the zips deployed to production are built from a **different, separate git clone** at `~/Downloads/carttrigger-holded-sync-164` — same GitHub remote (`polettoespana/carttrigger-holded-sync`), but checked out at an OLD commit (`688a854`, even before the 1.6.2 fixes in this repo) with **uncommitted working-tree changes** that reach 1.6.4. Those 1.6.3/1.6.4 changes exist ONLY in that Downloads folder — never committed, never pushed.
- **Verified 2026-08-05**: diffed both clones file-by-file. The actual sync logic (`class-cthls-sync.php`, `class-cthls-api.php`, `class-cthls-orders.php`, `class-cthls-cron.php`, `class-cthls-product-meta.php`) is **byte-identical** between the two — the 1.6.3/1.6.4 bump only touched the version header, readme/changelog text, and one admin-page notice string (`class-cthls-admin.php`, ~3 lines). So code-reading conclusions from this repo (v1.6.2) currently DO still reflect production behavior — but this is incidental, not guaranteed, and will silently stop being true the next time someone edits code in the Downloads clone without also committing here.
- **Resolved 2026-08-05**: user decided to abandon the Downloads clone as a deploy source going forward — will build zips from **this repo** from now on. This repo bumped to **v1.6.5** (`carttrigger-holded-sync.php`, `readme.txt`, `README.md`) with a changelog note explaining the reconciliation. The `~/Downloads/carttrigger-holded-sync-164` clone was left untouched/unresolved (still at its own uncommitted 1.6.4 state) — it is no longer the deploy source, but nobody cleaned it up. If it resurfaces confusion later, that's why.

### Local dev environment (`poletto.test`) is a stale mirror
The local WordPress install at `/Users/gpoletto/Sites/poletto` (site URL `poletto.test`) has its own DB, not synced with production. Observed 2026-08-05: its `cthls_log` option only had entries up to 2026-03-20, and a SKU that exists in production wasn't found locally. Do not assume local DB state reflects production — for live debugging, use browser automation against the real production `/wp-admin` (with the user logging in manually — credentials are never entered by Claude).
