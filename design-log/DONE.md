# DONE - Completed work (recent)

### 2026-08-05 — Version realignment (repo ↔ production)
**What:** Discovered production deploys were built from a separate, stale local clone (`~/Downloads/carttrigger-holded-sync-164`, uncommitted, checked out at an old commit) whose only real difference from this repo was a manually-bumped version number (1.6.4) with no functional code changes and less-complete docs (missing EN readme translation, missing admin API v2 notice). Bumped this repo to **v1.6.5** (`carttrigger-holded-sync.php`, `readme.txt`, `README.md` badge + changelog) to reconcile, keeping this repo's more complete content. User built a zip from this repo, deployed to production, and confirmed v1.6.5 shows in the plugin settings page.
**How:** Version string updates in 3 files + changelog entries explaining the reconciliation. See `PROJECT_UPDATES.md` for the full incident writeup (deploy source, why it happened).
**Files:** `carttrigger-holded-sync.php`, `readme.txt`, `README.md`
**Notes:** `~/Downloads/carttrigger-holded-sync-164` was intentionally left untouched (user's call) — it's no longer the deploy source but wasn't cleaned up. This repo is now the sole source for production zips going forward.
