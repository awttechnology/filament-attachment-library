# Performance & Reliability Improvement Plan — Filament Attachment Library

**Date:** 2026-07-07
**Status:** Draft — awaiting review
**Reported pain:** (1) attachment browser is slow; (2) a selected attachment is not persisted — it does not stick when the form is saved, and is not highlighted when the picker is reopened.

## Goals

- Make the attachment browser fast on cold cache with a remote (BunnyCDN) disk.
- Fix the selection persistence bug end-to-end (choose → save → reopen).
- Fix latent correctness bugs that can corrupt or delete data.
- Put a test harness in place so all of the above is provable and stays fixed.

## Non-goals

- UI redesign of the browser.
- Changing the public API of `AttachmentManager` / `AttachmentField` (additive changes only).
- Multi-disk browsing UI (but queries must stop leaking across disks).

## Evidence (verified in code)

### Correctness bugs

| # | Location | Bug |
|---|----------|-----|
| C1 | `src/AttachmentQueryBuilder.php:33` `whereInPath()` | Un-grouped `orWhere` — `whereDisk()->whereInPath()` compiles to `disk = ? AND path = ? OR path LIKE ?`; the OR escapes the disk constraint. Used by `deleteDirectory()` (can delete rows from other disks) and `renameDirectory()`. |
| C2 | `src/AttachmentManager.php:327` `renameDirectory()` | `REPLACE(path, old, new)` substitutes the substring anywhere in the path, not only the leading prefix — corrupts paths like `products/x/products`. |
| C3 | `src/AttachmentManager.php:250` `replace()` | Deletes the old file before writing the new one; a failed write loses the original. |
| C4 | Selection wiring: `src/Livewire/AttachmentBrowser.php` + `resources/views/forms/components/attachment-field.blade.php` | `openModal(?bool $multiple …)` assigns `null` into `public bool $multiple` (TypeError); `closeModal()` calls `$this->reset()` wiping all state; README-recommended `formatStateUsing` does `Attachment::get()->first()` (full-table load) and silently yields `null` on URL mismatch → field appears unsaved. Root-cause during Phase 1 with a Livewire repro test; symptoms confirmed by user. |
| C5 | `src/Livewire/AttachmentBrowser.php:120` `mount()` | Invalid `pageSize` falls back to `1` instead of the default `25`. |
| C6 | `src/ViewModels/AttachmentViewModel.php:124` `thumbnailUrl()` | A single corrupt/unreadable image throws during render and breaks the whole browser page. No fallback. |
| C7 | `src/AttachmentManager.php:127` `syncIfDue()` | Check-then-act on `Cache::has` — concurrent requests run duplicate syncs; duplicate-row risk since `updateFiles()` has no unique constraint backing it. |
| C8 | `src/Livewire/AttachmentBrowser.php:361,381` | Browser attachment query and directory item-count query never filter by `disk`. |
| C9 | `src/AttachmentManager.php` `move()/rename()` | Filesystem op then DB update, no failure handling — a DB failure leaves file and record disagreeing. |

### Browser performance hot spots

| # | Location | Cost |
|---|----------|------|
| P1 | `AttachmentViewModel::__construct` → `$attachment->metadata` | On cache miss, downloads the entire file from the CDN (`getImageSizes` → `getContents`) to run `getimagesize()`. 25 items/page, serial, inside render. |
| P2 | `GlideManager::imageIsSupported()` | Calls `makeImage()` — generates a full-size variant on the CDN cache just to test support — and caches the verdict only 5 minutes. Runs per item in `thumbnailUrl()`. |
| P3 | `AttachmentViewModel::fromLivewire()` | `Attachment::find()` per item on every Livewire interaction (N+1), re-running the heavy constructor including P1. |
| P4 | `AttachmentManager::directories()` (filesystem mode) | `syncIfDue` per subdirectory per browse; `updateFiles()` issues `mimeType()` + `size()` HTTP calls per new file, inline. |
| P5 | `AttachmentBrowser::getAttachments()` search | Leading-wildcard `LIKE '%…%'`, unscoped by disk — full scan at scale. |
| P6 | `AttachmentManager::upload()` | `$file->getContent()` loads the whole upload into memory; no streaming. |
| P7 | `AttachmentViewModel` user names | `Cache::remember` per user id per item — fine at small scale, but batchable. |

## Approaches considered

- **A — Fix pass without a harness.** Fastest start; rejected because the selection bug needs a Livewire repro to fix confidently, and the package has zero tests — regressions would ship silently to live sites.
- **B — Thin harness first, then prioritized fixes.** *(Chosen.)* Orchestra Testbench + Pest + PHPStan, repro tests, then fixes ordered by user pain.
- **C — Deep rework first** (queued pipeline, persisted dimensions, event sync). Highest ceiling but too much scope to start; adopted as the final phase instead.

## Design

### Phase 0 — Harness & measurement

- Add dev deps: `orchestra/testbench`, `pestphp/pest`, `phpstan/phpstan` (+ `larastan`). Wire `composer test` / `composer analyse`.
- A `TestCase` that registers the service provider, an in-memory SQLite schema from the migration stubs, and `Storage::fake` disks (one "local", one flagged remote via a fake driver config).
- A measurement test: render `AttachmentBrowser` with N seeded attachments and assert an upper bound on query count; log wall time. This is the before/after yardstick for Phase 2.

### Phase 1 — Correctness (fixes C1–C9)

- **C1:** group the two path conditions in a closure: `where(fn ($q) => $q->where('path', $path)->orWhere('path', 'LIKE', "$path/%"))`.
- **C2:** prefix-safe rewrite — `path = CONCAT(?, SUBSTR(path, LENGTH(?) + 1))` guarded by the (fixed) `whereInPath`; portable across MySQL/SQLite/Postgres via grammar-aware expressions or a chunked Eloquent fallback.
- **C3:** write the replacement first (temp name if same filename), then delete the old file; on write failure the original is untouched.
- **C4 (selection persistence):**
  1. Livewire test reproducing: open modal with existing state → assert highlighted; select → close(save) → assert dispatched payload; full form save → assert column/pivot written.
  2. Fix `openModal()` nullable params (`$this->multiple = $multiple ?? false;` etc.).
  3. Replace blanket `$this->reset()` in `closeModal()` with a targeted reset of selection/modal state only.
  4. Ensure single-select state round-trips as a scalar ID everywhere (blade `entangle` ↔ `selected` array ↔ dehydration).
  5. First-class URL-column support so the README's fragile `formatStateUsing`/`dehydrateStateUsing` workaround is unnecessary: `AttachmentField::storeAsUrl()` (or documented recipe) built on the indexed `whereUrl()` scope instead of `Attachment::get()->first()`.
- **C5:** fall back to `25` (first of `PAGE_SIZES` default).
- **C6:** wrap `thumbnailUrl()` body in try/catch → on failure cache & return the original URL (or a placeholder), log a warning.
- **C7:** replace check-then-act with `Cache::add($key, true, $ttl)` as the gate (single winner), or `Cache::lock` around `updateFiles`.
- **C8:** add `whereDisk(config('attachment-library.disk'))` to the browser's attachment query and the directory-count query.
- **C9:** order ops FS-first then DB inside try/catch; on DB failure attempt FS rollback (move back) and rethrow.

Each fix lands with a test written first (red → green).

### Phase 2 — Browser hot path (P1–P3, P5, P7)

- **P2:** stop calling `makeImage()` to answer "is this an image?" — decide from `mime_type` against the driver's supported-format list (already cached a day via `getSupportedImageFormats()`). Keep a long-TTL negative cache for files that later fail to decode.
- **P1:** remove metadata from the view-model constructor. Dimensions become lazy (only the info panel needs bits/channels/dimensions — `AttachmentInfo` can load them on demand) so the grid render does zero remote reads.
- **P3:** eliminate per-item rehydration queries — `toLivewire()` returns the already-computed display fields (id, name, url, thumbnail url, size, dates) and `fromLivewire()` rebuilds the view model from that payload without touching the DB. The full `Attachment` model is loaded only when an action actually needs it.
- **P5:** scope search by disk (C8) and drop the leading wildcard by default (`name LIKE 'term%'` uses the index); keep contains-search as an opt-in.
- **P7:** collect `created_by`/`updated_by` ids for the page and resolve names in one query per render.
- Re-run the Phase 0 measurement test; target: browser render performs **zero** filesystem/CDN calls when caches are warm, and ≤ ~6 queries per page regardless of page size.

### Phase 3 — Async pipeline & upload path (P4, P6, cold-cache latency)

- **Persisted image data:** add nullable `width`, `height`, `thumbnail_url` columns to `attachments`. Fill at upload time; backfill via `glide:warm` (extend to also persist columns). `thumbnailUrl()` becomes: column → cache → queue-and-placeholder.
- **Queued thumbnails:** on browse, missing thumbnails dispatch a `GenerateThumbnail` job (deduplicated via unique jobs) and the UI shows the original (h320 via CSS) or a placeholder; Livewire polling/event refreshes when ready. First paint is never blocked by Glide + Bunny writes.
- **Sync off the request path:** `attachment-library:sync` command for scheduler; `auto_sync` stays but its inline work is replaced by dispatching a queued sync (config flag `sync_queue`, default sync-inline preserved for BC).
- **P6:** stream uploads with `putFileAs`/`writeStream` instead of `getContent()`.
- Recommend `directory_source = database` as the documented default for remote disks (already supported).

### Error handling principles

- The browser must always render: any per-item failure (thumbnail, metadata, missing file) degrades that item, never the page.
- Destructive operations (`deleteDirectory`, `replace`, `move`, `rename`) must be disk-scoped, prefix-safe, and ordered so a mid-operation failure never loses the original file.

### Testing strategy

- Unit: query builder scopes (SQL shape assertions), `Resizer` math, `Filename` parsing.
- Feature: `AttachmentManager` FS+DB operations against fake disks, including failure injection for C3/C9.
- Livewire: `AttachmentBrowser` selection lifecycle (C4), pagination/sort/search, `AttachmentField` save round-trip via a test form.
- Performance guard: query-count assertions on browser render (Phase 0 yardstick).

## Rollout & compatibility

- All schema changes ship as guarded migration stubs (same pattern as `add_indexes_to_attachments_table`).
- No public API removals; new behaviour behind config where it changes semantics (queued thumbnails, sync queue).
- Phases are independently shippable; Phase 1 alone fixes the user-reported persistence bug and the data-safety issues.

## Open questions (deferred, non-blocking)

- Library scale unconfirmed (question timed out) — Phase 0 measurement covers this; Phase 3 priorities may shift if the library is small (hundreds).
- Whether any production sites rely on the README `formatStateUsing` workaround — the new URL-column support must not break them.
