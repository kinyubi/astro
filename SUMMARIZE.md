# Astro Project — Session Summaries

---

## Session: 2026-05-22

### Feature: DSO Info Button on Visibility Report

Added an ℹ info button at the end of each row in the DSO visibility report (`/vis`) that opens a modal popup displaying the same information shown for that object in the DSO admin panel.

**Files modified:**

**`public/api/dso.php`**
- Extended the SQL query to `JOIN Objects o ON v.DSOKey = o.DSOKey` and add `o.Notes`, `o.WantBetter`, and `o.SqArcMins` to the SELECT. These three fields exist in the `Objects` table but were missing from `vw_GalleryObjects`, so they were not previously returned by the public API.

**`pythonscripts/todays_dsos_web.py`**
- Added `.info-btn` CSS and a full DSO info modal CSS block (overlay, panel, section headers, field label/value grid, close button) to the `<style>` section.
- Added a blank `<th></th>` column header at the end of the table header row.
- Added `<td><button class="info-btn" onclick="showDSOInfo('${obj.name}')">ℹ</button></td>` to the `renderTable` JS function's `row.innerHTML` template.
- Added `#dso-modal-overlay` HTML div and a `<script>` block containing `showDSOInfo(dsoKey)` and `closeDSOInfo()` functions, placed between the sort script and the Quick Add modal. The modal fetches `/api/dso.php?key=<DSOKey>` and renders four admin-style sections: Identity, Astrometrics, Observation & Project, and (conditionally) Notes and Social Blurb. Closes on ✕ click, backdrop click, or Escape key.

**`public/vis/index.php`** (bug fix required by the above)
- The PHP error-detection code checked `stripos($output, 'Error:')` against the Python script output. The new JS code contains the literal string `'Error: '` (in the error-handling path of `showDSOInfo`), which caused a false-positive Python error page on every page load.
- Fixed by replacing the broad `'Error:'` string check with a proper Python-specific error detection: checks for `'Traceback (most recent call last)'` or a regex matching `\w+Error:` / `\w+Exception:` at the start of a line.

### Key learning
When the Python script outputs HTML containing JavaScript with error-message string literals (e.g. `'Error: ' + ...`), PHP's naïve `stripos($output, 'Error:')` check will false-positive. Always use pattern-specific checks for Python tracebacks rather than generic keyword matching.


---

## Session: 2026-06-07

### Feature: Multi-Image Gallery Revamp — Schema & API (Steps 1–2 of 4)

Designed and partially implemented a revamp of the public gallery (`public/index.php`) to support multiple images per DSO, images from other photographers, per-image metadata (palette, date, caption, attribution), and DSO reference links (Wikipedia, Cloudy Nights, etc.).

#### Design decisions made

- **One card per DSO** in the gallery grid (grouped view), not one card per image. A badge shows image count when >1.
- **All images** (own or others') use the same standard file variants: `_thumb.jpg`, `_fav.jpg`, `_full.jpg`, `_wall.jpg`, optionally `_wall4k.jpg`. Other people's images are resized/cropped to fit this convention before adding (Option A). No special-casing in the frontend.
- **`GalleryImages` table** is the gallery presentation layer; the existing `Images` table remains the asset/processing registry and is not modified.
- **`BaseName`** is the grouping key for a render group (e.g. `ic1848_soul_mosaic_1108_hso`). All size variants are derived from it.
- **`IsFeature = 1`** marks the image used as the gallery card thumbnail per DSO. `SortOrder` controls carousel order within the modal.
- **Thumb fallback**: if `_thumb.jpg` missing, fall back to `_fav.jpg` for the gallery card.
- **Width/height not stored** — dimensions are deterministic from image type for own images; not needed at runtime.
- **RA/Dec JNow display** noted as a future feature (convert J2000 stored values to JNow HMS/DMS for display, with clipboard copy in both formats). Not implemented this session.

#### New database tables (created by migration script)

**`GalleryImages`**
```sql
GalleryImageID  INTEGER PRIMARY KEY AUTOINCREMENT
DSOKey          TEXT NOT NULL  (FK → Objects)
BaseName        TEXT NOT NULL  (e.g. "m42_orion_a1228")
Caption         TEXT
PaletteID       INTEGER DEFAULT 0  (FK → PaletteTreatments)
DateCaptured    TEXT  (YYYY-MM-DD)
Copyright       TEXT
IsOwn           INTEGER DEFAULT 1
Attribution     TEXT  (credit line if IsOwn=0)
SortOrder       INTEGER DEFAULT 0
IsFeature       INTEGER DEFAULT 0
Equipment       TEXT  (e.g. "S30", "S50") — added later
IsMosaic        INTEGER DEFAULT 0 — moved here from Objects, added later
SessionDir      TEXT  (e.g. "20251108_165x60s_S30") — added later
```

**`DSOLinks`**
```sql
LinkID      INTEGER PRIMARY KEY AUTOINCREMENT
DSOKey      TEXT NOT NULL  (FK → Objects)
Label       TEXT NOT NULL  (e.g. "Wikipedia")
URL         TEXT NOT NULL
SortOrder   INTEGER DEFAULT 0
```

#### Migration scripts (all in `pythonscripts/`)

- **`migrate_gallery_images.py`** — creates `GalleryImages` and `DSOLinks` tables; auto-populates 44 render groups from `Images → ProcessingRuns → Projects`
- **`migrate_add_equipment_ismosaic.py`** — adds `Equipment` and `IsMosaic` columns to `GalleryImages`
- **`migrate_add_session_dir.py`** — adds `SessionDir` column to `GalleryImages`; backfills by scanning `MyWorks` for each fav file
- **`backfill_date_captured.py`** — backfills `DateCaptured`, `Equipment`, `IsMosaic`, `PaletteID` from filesystem; supports `--force` to reprocess all rows

#### API changes

**`public/admin/api_search.php`** (modified)
- Batch-fetches `GalleryImages` (with `PaletteName` JOIN) and `DSOLinks` for all result DSOKeys
- `GalleryImages` SELECT includes: `GalleryImageID`, `BaseName`, `Caption`, `PaletteID`, `PaletteName`, `DateCaptured`, `Copyright`, `IsOwn`, `Attribution`, `Equipment`, `IsMosaic`, `SessionDir`, `SortOrder`, `IsFeature`

**`public/admin/api_save.php`** (modified)
- Full-replace handlers for both `GalleryImages` and `DSOLinks`
- `GalleryImages` upsert includes all fields: `Equipment`, `IsMosaic`, `SessionDir` included
- `IsMosaic` removed from `$allowed_cols` for the `Objects` table

**`public/admin/api_sync_folder.php`** (new)
- POST `{DSOKey}` → walks `WORKS_ROOT/<ProjectFolder>/<YYYYMMDD_*>/` for fav files
- Infers `DateCaptured`, `Equipment`, `IsMosaic`, `PaletteID`, `SessionDir` from dir/filename
- Upserts matching `GalleryImages` rows; inserts new ones (auto-features if none featured yet)
- Returns `inserted`, `updated`, `warnings` (missing fav files) arrays
- **Folder not found detection**: if `ProjectFolder` doesn't exist on disk, scans `WORKS_ROOT` for similar directory names using `similar_text()` + prefix matching and returns `folder_not_found: true` with `candidates` array
- Candidate picker shown in UI — clicking a candidate saves the new `ProjectFolder` and re-runs sync automatically (`syncWithFolder()`)

**`public/admin/api_delete.php`** (modified)
- Extended to handle `GalleryImageID` deletion (single row) in addition to full DSO deletion

**`public/admin/config.php`** (modified)
- Added `WORKS_ROOT` constant: `C:\Astronomy\MyWorks`

#### Admin panel UI (Step 3 — complete)

**`public/admin/index.php`** changes:
- `IsMosaic` field removed from Objects / Observation & Project section
- **Gallery Images section** added after Catalog IDs:
  - Card per render group with fields: BaseName, Palette dropdown (all 8), Date Captured, Caption, Copyright, Photographer (Mine/Other), Attribution (shown when Other), Equipment, Session Directory, IsMosaic checkbox, Featured checkbox, Sort Order, Remove button
  - Card header shows BaseName + Equipment badge + Mosaic badge + SessionDir (monospace) + Featured star
  - **↻ Sync Folder** button — scans disk, populates/updates cards, shows colour-coded result panel
  - Sync result panel: green inserted list, blue updated list, red warnings with inline Remove buttons
  - Folder-not-found state shows candidate folder buttons; clicking one saves ProjectFolder and re-syncs
- **DSO Links section** added after Gallery Images:
  - Compact table with Label, URL, SortOrder, Remove per row
  - "+ Add Link" button
- `saveObject()` extended to include `GalleryImages` and `DSOLinks` arrays in payload
- `loadObject()` and `newObject()` call `renderGalleryImages()` and `renderDSOLinks()`
- Admin list scroll position preserved across re-renders (saves no longer reset scroll)
- Active item scrolled into view on selection

#### Key decisions & learnings
- **`Images` table deprecated** for live app use — nothing queries it; `GalleryImages` is the sole source of truth going forward. `copy_images_to_local_web.py` kept as historical archive tool only.
- **`IsMosaic` moved to `GalleryImages`** — it's a property of a specific imaging run, not the astronomical object
- **`SessionDir` added** — `BaseName` alone doesn't tell you where the file lives; `ProjectFolder` (from Objects) + `SessionDir` (from GalleryImages) gives the full source path: `MyWorks/<ProjectFolder>/<SessionDir>/<BaseName>_fav.jpg`
- **Three-place field rule** violations found and fixed for `Equipment`, `IsMosaic`, `SessionDir` — all three needed to be added to `api_search.php` SELECT, `api_save.php` upsert params, and JS card renderer/collector
- **ProjectFolder mismatches** for IC1848, IC2118, NGC2264, NGC7000 — DB had stale `_mosaic` suffixed folder names; fixed via Sync Folder candidate picker
- `similar_text()` PHP function used for fuzzy folder name matching in sync endpoint

#### Remaining step
- **Step 4**: Public gallery frontend (`public/index.php`) — update to query `GalleryImages` grouped by DSO, show multi-image badge on cards, carousel/prev-next in modal, palette + date caption line, attribution for others' images, DSOLinks below social blurb

---

## Session: 2026-06-13

### Bug Fix: Sync Folder orphan cleanup

**`public/admin/api_sync_folder.php`**
- Previously, `GalleryImages` rows whose `_fav.jpg` file had been deleted from `public/images/fav/` were only reported as warnings, never removed.
- Fixed: rows with missing fav files are now **deleted from the DB** during sync. If the deleted row had `IsFeature = 1`, the next surviving row (by `SortOrder`, then `GalleryImageID`) is automatically promoted as featured.
- Warnings array still reports what was removed so the UI shows the cleanup.

### Feature: DSO preview image in vis info modal

Added a preview image between the title and Identity section of the DSO info modal in the visibility report (`/vis`).

**`public/api/dso_preview.php`** (new)
- `GET /api/dso_preview.php?key=IC1805` — scans `public/images/thumbs/` then `public/images/visibility/` for any file whose name starts with the DSO key (case-insensitive).
- Returns JSON `{"url": "/images/thumbs/ic1805_heart_thumb.jpg"}` on match, or `{"url": null}` with 404 on no match.

**`pythonscripts/todays_dsos_web.py`**
- In `showDSOInfo()`, added an `await fetch('/api/dso_preview.php?key=...')` call **before** building the `html` string. The preview URL is resolved first so the image is injected into `body.innerHTML` in one shot — no post-render DOM insertion, no flash.
- Root cause of long debugging: the `showDSOInfo` and QuickAdd script blocks live inside `print("""...""")` (a plain string, not an f-string), so `{{` was being output literally as `{{` — invalid JS that broke both functions silently. Fixed by converting all `{{`/`}}` pairs to single `{`/`}` throughout both script blocks.

### Bug Fix: Gallery modal crash on second open

**`public/index.php`**
- `renderDSOModalSlide()` moves `#modalImageContainer` (and its child `#modalImage`) into a newly created `.dso-carousel` div.
- The teardown in `openModal()` was calling `document.querySelector('.dso-carousel')?.remove()`, which removed `#modalImage` from the DOM along with the carousel.
- On the second card click, `getElementById('modalImage')` returned `null`, causing `Uncaught TypeError: Cannot read properties of null (reading 'src')`.
- Fixed: teardown now rescues `#modalImageContainer` back to its original position before removing the carousel.

### Key learnings
- Plain Python `print("""...""")` strings output `{{` literally — only f-strings collapse `{{` to `{`. Any JS block with brace-heavy syntax (object literals, arrow functions, try/catch) inside a plain `print("""...""")` needs single braces throughout.
- When a DOM element is moved into a dynamically created wrapper, teardown must rescue it before removing the wrapper, or `getElementById` will return null on the next open.

---

## Session: 2026-06-15

### Feature: Blog System

Added a complete blog platform to the astro app, consisting of a public blog manager, a Markdown-based post format, and the first converted blog post.

#### Landing page

**`public/index.php`**
- Added a third **Blog** tile (📝) to the `.options-container` grid, linking to `/blog-manager/`.

#### New directory structure

```
public/blog-manager/
    index.php          — blog listing page
    post.php           — individual post renderer
    blog-manager.css   — dark-theme styles matching gallery
    Parsedown.php      — third-party Markdown parser (manually placed by user)

public/blogs/
    what-color-is-it/
        index.md       — first blog post, converted from HTML
```

#### Blog format conventions

- Posts live in `public/blogs/<slug>/index.md`
- YAML front matter at top: `title`, `date` (YYYY-MM-DD), `tags` (bracket list), `summary`, `thumbnail` (basename from `images/fav/`)
- Images: standard Markdown `![alt](path "Caption as title attr")` — rendered as `<figure><figcaption>` by `post.php`
- Side-by-side image pairs: raw HTML `<div class="img-compare">` blocks with two `<figure>` children

#### `public/blog-manager/index.php`

- Scans `public/blogs/*/index.md`, parses YAML front matter
- Renders blog card grid sorted newest-first
- Each card shows thumbnail (from `images/fav/`), date, title, summary, tags, "Read more" link
- Falls back to placeholder icon if no thumbnail set

#### `public/blog-manager/post.php`

- Reads `index.md` for the requested slug, parses front matter, renders Markdown via Parsedown
- `setMarkupEscaped(false)` and `setBreaksEnabled(false)` set so raw HTML blocks (e.g. `.img-compare` divs) pass through untouched
- Post-processes rendered HTML: `<img ... title="caption">` → `<figure><img><figcaption></figure>`
- Displays: date, title, tags, post body, "Back to all posts" footer link

#### `public/blog-manager/blog-manager.css`

- Dark theme consistent with gallery (same color variables: `#4a9eff`, `#1a1f3a`, `#0a0e27`, etc.)
- Blog listing grid, post card styles, single post typography
- `.img-compare` class: `display: grid; grid-template-columns: 1fr 1fr` — collapses to single column below 540px
- `.post-body .img-compare figure img` rule uses higher specificity than `.post-body img` to ensure `width: 100%` wins over `width: auto`

#### First blog post: "Why Nebulae Look the Way They Do"

**`public/blogs/what-color-is-it/index.md`**
- Converted from original `public/blogs/what-color-is-it.html`
- Covers SHO, HOO, HSO, HOS palettes with explanation of channel assignments
- Images: Hubble Pillars of Creation (NASA, hotlinked), plus three side-by-side pairs from Carl's own `annotated_fav` images
- All local images reference `/images/annotated_fav/` directly

#### Key learnings

- Parsedown requires `setMarkupEscaped(false)` to pass raw HTML blocks through; without it, `<div>` tags in Markdown are escaped or wrapped in `<p>` tags, breaking grid layouts.
- PHP typed parameters (`string $raw`) and return type hints (`: array`) caused parse errors; removed to ensure compatibility regardless of PHP version misconfiguration.
- CSS specificity: `.post-body img { width: auto }` overrides `.img-compare figure img { width: 100% }` — must use `.post-body .img-compare figure img` to win the cascade.
- Always read the current file from disk before making edits — working from memory of a previous version caused incorrect filenames to be written back.

---

## Session: 2026-06-22

### Tool: Gallery Missing Entry Checker (`public/check-missing/`)

Created a new admin-gated utility page at `http://astro.app/check-missing/` to quickly audit the `public/images/fav/` directory against the `GalleryImages` table.

**`public/check-missing/index.php`** (new)
- Protected by the same `auth.php` session authentication as the admin panel.
- Scans `public/images/fav/` for all `*_fav.jpg` files and extracts their BaseNames.
- Queries `GalleryImages` for all registered BaseNames.
- Computes two diffs:
  - **Fav files missing from GalleryImages** — files on disk with no DB row (the primary use case, e.g. Bat Nebula not yet synced).
  - **GalleryImages entries with no fav file** — DB rows whose fav file has been deleted (orphans).
- For missing-from-DB entries, infers a likely DSOKey from the filename (splits on `_`, stops before a date/session number token) and provides an "Open in Admin →" link pre-loaded on that key.
- Stats bar shows total fav file count, total DB row count, and per-category missing counts.
- Shows an "All clear" message when both lists are empty.
- Diagnosed root cause of Bat Nebula not appearing in gallery: `public/index.php` uses `JOIN Objects o ON gi.DSOKey = o.DSOKey` (INNER JOIN), so DSOs in `Objects` without a `GalleryImages` row are silently excluded. Fix is to run Sync Folder in admin, not change the query.

### Blog: Second Post — Astrophotography Workflow

Reviewed, revised, and published a second blog post at `public/blogs/create-astrophotography-image/index.md`.

#### Content changes to `index.md`
- **Title** changed from "Creating an Astrophotography Photograph" to "From Photons to Picture: How I Create an Astrophotography Image."
- **Teaser image** of the finished Bat Nebula moved to the top with a one-paragraph hook before any gear discussion.
- **"Background Information" section** renamed to "My Setup"; dew shield and power bank split into separate paragraphs each with their own image.
- **Processing sections** rewritten from dense bullet lists to flowing prose with bold step labels.
- **Star separation** given a "why" explanation (stars and nebulae respond differently to processing).
- **Stretching** given a film-negative analogy for non-technical readers; YouTube admission added for human voice.
- **Closing line** added: "A night outside in the dark, hours at the computer, and one more nebula on the wall."
- Three typos fixed: `befpre`, `ome`, `a versions`.
- Post-processing bullet list converted from Markdown `- ` syntax to raw `<ul><li>` HTML to avoid Parsedown indentation issues.

#### Blog CSS fixes (`public/blog-manager/blog-manager.css`)
- Added `.post-body ul, .post-body ol` rules with `padding-left: 1.8em !important` and `list-style-position: outside` to override the global `* { padding: 0 }` reset in `style.css`.
- Bumped `figcaption` font sizes from `0.82em` / `0.78em` to `1em` for both `.post-body figcaption` and `.img-compare figure figcaption` to match body text size.

#### `public/blog-manager/post.php` fixes
- Stylesheet link changed from relative (`blog-manager.css`) to absolute (`/blog-manager/blog-manager.css`) to ensure correct loading regardless of URL routing.
- CSS version query string added and incremented with each CSS change (`?ver=1.1` → `?ver=1.2`) to bust browser cache automatically.

### Key learnings
- **Always bump CSS `?ver=` query string** when modifying a stylesheet, or browsers will serve the cached version and changes appear to have no effect — requiring a hard Ctrl+Shift+R refresh.
- **Global `* { padding: 0 }` reset** in `style.css` strips browser default list indentation; `.post-body ul` rules need `!important` to override it since `*` has higher effective specificity than element selectors in some cascade situations.
- **INNER JOIN in gallery query** silently excludes DSOs that have an `Objects` row but no `GalleryImages` row — the correct fix is to sync via admin, not change to a LEFT JOIN.

---

## Session: 2026-07-03

### Feature: Admin Login Inactivity Timeout (2 hours)

Added an explicit 2-hour inactivity timeout to the DSO Admin session. Previously there was no enforced timeout — the login page already had dead code referencing `?expired=1`, but nothing ever set it; session length depended entirely on PHP's default (browser-session cookie / default `gc_maxlifetime`).

**`public/admin/config.php`** (modified)
- Added shared constant `ADMIN_SESSION_TIMEOUT` = 7200 seconds (2 hours), used by both `auth.php` and `auth_api.php`.

**`public/admin/auth.php`** (modified)
- On login success, `$_SESSION['LAST_ACTIVITY']` is now set alongside `authenticated`/`username`.
- On every page load where the session is already authenticated: if `time() - LAST_ACTIVITY > ADMIN_SESSION_TIMEOUT`, the session is destroyed and the user is redirected to the login page with `?expired=1` (which already rendered the "Your session expired" message — previously unreachable code). Otherwise `LAST_ACTIVITY` is refreshed to the current time.

**`public/admin/auth_api.php`** (modified)
- Same inactivity check applied to API requests from non-local origins, so a stale admin tab can't keep making authenticated calls (search, save, sync, etc.) past the 2-hour window. Returns HTTP 401 with a JSON error body on timeout.
- Local-origin requests (127.0.0.1 / ::1) remain exempt from auth entirely, unchanged from before.

#### Key learnings
- The login page (`auth.php`) had a latent `?expired` UI state that was never triggered by any code path — worth checking for this pattern (dead code implying a planned-but-unbuilt feature) elsewhere in the app.
- Shared constants used by multiple auth-related files belong in `config.php` rather than being redefined locally, to avoid `define()` redeclaration errors.

### Discussion: Third-Party Image Attribution (no code changes)

Walked through how attribution/captioning for others' images works in the gallery browser (this was already implemented, just not documented in this file — the 2026-06-07 entry still listed "Step 4: Public gallery frontend" as remaining, but it has since been completed).

- `public/index.php` PHP query pulls `IsOwn`, `Attribution`, `Copyright` from `GalleryImages` into the `galleryData` JSON blob per image.
- `renderDSOModalSlide()` (JS) builds the caption bar: own images always show "Photographer: Carl Baker" plus an auto-generated current-year copyright; third-party images show `Attribution`/`Copyright` if set, or fall back to a generic "Image credit: third party" tag if both are empty.
- No file-path special-casing — third-party images use the same `_thumb`/`_fav`/`_full`/`_wall` convention as Carl's own, per the original Option A design decision.
- Note: the top-level Slideshow (landing page) only reads from `images/annotated_full`/`images/annotated_wall` on disk and is exclusively Carl's own work — third-party attribution only ever appears in the Gallery Browser modal, not the Slideshow.

---

## Session: 2026-07-04

### Bug Fix: Admin login required even on localhost

Carl reported that `DSO Admin` requested a login when run locally, contrary to the expected "local = no login" behavior.

**`public/admin/auth.php`** (modified)
- Root cause: the local-origin bypass added 2026-07-03 only went into `auth_api.php` (API-level re-auth), never into `auth.php` (the page-level gate for `admin/index.php`). Page loads always hit the login form regardless of origin.
- Fixed: `auth.php` now checks `REMOTE_ADDR` against `127.0.0.1` / `::1` / `localhost` (same pattern as `auth_api.php`) and returns immediately, skipping session/login logic entirely, when local. Remote access is unaffected and still requires login.
- Known side effect: since no `$_SESSION` is started on local access, anything reading `$_SESSION['username']` for a "logged in as..." display will be empty locally. Not yet raised as an issue by Carl.

### Performance Fix: `/vis` Visibility Dates recomputing every run

Carl reported the visibility report taking a long time to run and suspected the per-DSO "visible date range" (added in the `VisibilityForecast` caching feature) was being recalculated every run instead of once per year.

**`pythonscripts/todays_dsos_web.py`** (modified)
- Root cause confirmed: the cache lookup only trusted `VisibilityForecast` while `specified_date < FirstVisibleDate` (i.e. before the window opens). The instant today's date reached or passed `FirstVisibleDate`, `need_compute` stayed `True` and the full `find_visibility_window()` search reran on every single report run, for every DSO whose season had started — the main cause of the slowdown.
- Fixed: cache is now considered valid (`need_compute = False`) as long as `specified_date <= LastVisibleDate` — i.e. reused for the DSO's entire visibility season, not just the days before it starts. Recompute now only triggers once `LastVisibleDate` has fully passed, producing the next occurrence's (typically next year's) window. Confirmed with Carl: once a new `FirstVisibleDate`/`LastVisibleDate` pair is computed, it is reused for every run until *that* `LastVisibleDate` also passes.
- Note for Carl: the 24-hour PHP page cache in `public/vis/index.php` means this fix won't be visible until a `?rebuild=1` forced rebuild.

### Bug Fix: LDN1228 "Observation & Project" section empty in DSO info modal

Carl noticed the Observation & Project section was blank for LDN1228 (Fighting Dragons of Cepheus) despite the values existing in the database.

**Root cause:** `vw_GalleryObjects` (read by `public/api/dso.php`) pulls `ProjectFolder`, `IsMosaic`, and `MostRecentObservation` exclusively via `LEFT JOIN Projects p ON o.DSOKey = p.DSOKey AND p.Status = 'ACTIVE'` (plus an `Observations` subquery keyed on `p.ProjectID`). LDN1228 has no row in `Projects` at all, so all three fields returned NULL — even though `Objects.ProjectFolder`, `Objects.IsMosaic`, and `Objects.MostRecentObservation` (legacy columns, pre-dating the `Projects`/`Observations` schema) are populated. Confirmed via direct DB inspection that **11 DSOs total** have this same legacy-only pattern: IC1396, NGC7380, NGC7635, SH2-129, M87, M66, LDN1228, NGC6995, M94, C2025-R3, and one more.

**`pythonscripts/migrate_view_project_fallback.py`** (new, later superseded — see 2026-07-06)
- Drops and recreates `vw_GalleryObjects` with `COALESCE(p.ProjectFolder, o.ProjectFolder)`, `COALESCE(p.IsMosaic, o.IsMosaic)`, and a `COALESCE` around the `MostRecentObservation` subquery, falling back to the legacy `Objects` columns whenever no `Projects` row exists.

### Tabled: Smarter file/folder-based inference for missing Project/Observation data

Carl wants a more comprehensive script that can *derive* the missing `ProjectFolder`/`Observation` data for legacy DSOs from `MyWorks` folder names, session directory naming, and file counts. Carl was going to write a detailed prompt for this and revisit it in a future session — **this became the `audit_observations.py` work in the 2026-07-06/07 session below.**

### Feature: Remote Change Log for manual DB reconciliation

Discussion started around the risk of the local/remote `astro.db` copies diverging when both are edited before the existing DB sync tool runs. Explored and rejected "always use the remote database" (SQLite isn't safe over a network share; a real fix would mean migrating to a networked engine like MySQL or wrapping the DB behind an API).

Carl proposed a lighter alternative instead: log every remote DB write as raw SQL so it can be manually re-applied to the local DB when a conflict is spotted, rather than running a full (lossy) two-way sync.

**`public/admin/db_logger.php`** (new)
- Provides `get_db()`: opens the PDO connection to `DB_PATH`, sets `PRAGMA foreign_keys = ON`, and installs a `LoggingPDOStatement` (extends `PDOStatement`) via `PDO::ATTR_STATEMENT_CLASS`.
- `LoggingPDOStatement::execute()` detects `INSERT`/`UPDATE`/`DELETE` statements, substitutes bound parameter values into the SQL text, and appends the resulting standalone, executable statement to `C:\laragon7\www\astro\remote.log` with a timestamp comment — but only when `db_is_local()` is false. `SELECT` statements are never logged; local runs never write to the log at all.
- Carl confirmed he'll manage `remote.log` growth/rotation manually.

**Updated to use `get_db()` instead of inline `new PDO(...)`**: `api_save.php`, `api_delete.php`, `api_constellation_add.php`, `api_quickadd.php`, `api_sync_folder.php`.

- **Status: not yet tested live** — logging only activates on non-local requests.

### Key learnings
- **Local-origin auth bypass must be added at every layer that gates access** — `auth_api.php` and `auth.php` are separate gates.
- **Time-window cache validity should be checked against the *end* of the cached range, not the start.**
- **Schema migrations that introduce a new "source of truth" table can leave orphaned legacy data on the old table** — worth an explicit audit after any such migration.
- **PDO's `PDO::ATTR_STATEMENT_CLASS`** can transparently intercept every `execute()` call across a connection — the custom `PDOStatement` subclass needs a `protected` (not `private`) constructor.

---

## Session: 2026-07-06/07 (DB Rework + Observation Audit tooling)

### Design: DB Rework — "Project is the top of the hierarchy"

Carl brought a draft spec (`CLAUDE_PROMPT_2026_0704.md`) for a larger DB rework. Extensive back-and-forth resolved every open question; the final design lives in **`DB_REWORK_PLAN.md`** (project root), which supersedes the original draft.

**Core model change:** Project is the top of the hierarchy, not Object. One Object can legitimately be referenced by multiple Projects (e.g. IC1805 has a standard-framing project and a separate mosaic project) — not a data problem, the correct model.

**Key decisions (full detail in `DB_REWORK_PLAN.md`):**
- `Objects` keeps `WantBetter`/`SocialBlurb`/`Notes`; only `ProjectFolder`/`MostRecentObservation`/`IsMosaic` move off it.
- `Projects.Notes` kept as a genuine duplicate of `Objects.Notes`.
- `Projects.IsMosaic` becomes a **SQLite generated column** (`LOWER(ProjectFolder) LIKE '%mosaic%'`) so it can never drift from the folder name.
- `GalleryImages` gains `ProjectID` (FK → Projects), loses its own `IsMosaic`. **Gallery grouping changes from one-card-per-DSO to one-card-per-Project.**
- Custom `DSOKey`s (`CUST1`, `CUST2`, ...) for multi-DSO or non-catalog projects — documented as Appendix B.
- Open-Meteo's free historical weather API will supply Temperature/Humidity for the future Observation Management feature.
- FIT filename exposure-time regex widened from `(\d{2})\.0s` to `(\d+)\.0s`.

**Bug found:** `IC1805`/`NGC1499` both had two `Projects` rows, both `Status='ACTIVE'` — `vw_GalleryObjects`'s filter was already non-deterministically matching both, `dso.php`'s single-row `fetch()` had no tiebreak. Pre-existing latent bug, resolved by the rework.

### Implementation: Phase 1 schema + data migration

**`pythonscripts/migrate_db_rework_phase1.py`** (new) — **supersedes `migrate_view_project_fallback.py`**. In one transaction:
- Creates a real `Projects` row for all 11 legacy DSOs (LDN1228, IC1396, NGC7380, NGC7635, SH2-129, M87, M66, NGC6995, M94, C2025-R3, and one more).
- Rebuilds `Objects` (drops `ProjectFolder`/`IsMosaic`/`MostRecentObservation`).
- Rebuilds `Projects` (drops `MosaicConfig`/`Status`/`TotalGoodLights`/`TotalIntegrationMins`/`CreatedDate`; `IsMosaic` → generated); copies `Objects.Notes` into `Projects.Notes` for the 6 DSOs with both.
- Adds `GalleryImages.ProjectID`, backfills, drops `GalleryImages.IsMosaic`.
- Adds `Observations.ObservationFolder`, drops `RejectedLights`/`BortleScale`.
- Drops/recreates all four dependent views.
- Tested against a sandbox copy first (caught a view-dependency issue this way).

### Immediate consumer fixes
`api_save.php`, `api_search.php`, `api_sync_folder.php` all updated to stop referencing dropped columns and start using `Projects`/`ProjectID`. `api_quickadd.php` was already clean.

### Implementation: Phase 2 — list-of-projects display + gallery grouping

**`public/api/dso.php`** (rewritten) — no longer selects from `vw_GalleryObjects`; returns DSO-level fields plus a `Projects` array (one entry per `Projects` row, zero/one/many).

**`pythonscripts/todays_dsos_web.py`** — `/vis` modal's "Observation & Project" section now iterates `d.Projects`, one block per project.

**`public/index.php`** (public gallery) — grouping key changed from `DSOKey` to `ProjectID`; confirmed with Carl: one gallery card per Project, distinguished by a small amber "Mosaic" badge (not a title change). Added a `groupKey` field and fixed two JS lookups (search dropdown click, Enter-key handler) that previously matched cards by `dsoKey`, which would have opened the wrong card for a multi-project DSO.

**Bug Fix — admin "Observation & Project" blank (IC1396):** `public/admin/index.php`'s form still had `#f_ProjectFolder`/`#f_MostRecentObservation` inputs bound to columns dropped in Phase 1. Replaced with a read-only `#projects-list` + `renderProjects()` rendering one card per project. (Accidentally deleted the `Notes` textarea in the same edit; caught and restored immediately.)

**Bug Fix — garbled `�` characters in `/vis` modal:** raw Unicode escapes (`\u2014`/`\u2013`/`\u2026`) inside `todays_dsos_web.py`'s plain `print("""...""")` block get mangled on Windows cp1252. All missing-value placeholders switched to plain ASCII `-`; separators switched to plain `' - '`; ellipses to `'...'`. Key learning: `textContent` never decodes HTML entities, so entity-based fixes don't work in `textContent` contexts — only plain ASCII is safe everywhere.

**Not yet done:** dead `IsMosaic` checkbox in admin Gallery Image card; no Project-picker UI for multi-project DSOs (`GalleryImages.ProjectID` assignment, `api_sync_folder.php` disambiguation currently requires passing `ProjectID` by hand).

### Feature: `Observations.IntegrationMins` — real stored field, and `audit_observations.py`

**Design iteration:** first built as `ManualIntegrationMins` (a separate override column combined with `COALESCE` at read time, in `migrate_add_manual_integration_override.py`) — before that script was ever run, Carl asked for a simpler design: **one real field, `IntegrationMins`**, calculated and stored if blank, left alone if not, with the calculation done as a distinct bulk "post update" step rather than inline. Replaced with **`pythonscripts/migrate_add_integration_mins.py`** (supersedes and replaces the override version, which Carl deleted manually since it was never run):
- Adds `Observations.IntegrationMins` (nullable `REAL`).
- One-time bulk backfill of every existing row with `GoodLights`+`ExposureTimeSecs` but blank `IntegrationMins`.
- Recreates `vw_GalleryObjects`/`vw_ObservationSummary`/`vw_NeedsMoreData` to read `IntegrationMins` directly (no more inline calc/`COALESCE`).
- Shows per-row minutes and a per-project "Projected Total Integration Time" (current → projected) summary in its preview report.

**`public/api/dso.php`** and **`public/admin/api_search.php`** — `TotalIntegrationMins` simplified to plain `SUM(IntegrationMins)`. (Along the way, found and fixed a real bug: `api_search.php`'s `Projects` query had never computed `MostRecentObservation`/`TotalLights`/`TotalIntegrationMins` at all — admin's `renderProjects()` had been showing blank for all three since it was built.)

**`pythonscripts/audit_observations.py`** (new) — the ongoing, repeatable tool Carl actually wanted from the 2026-07-04 "tabled" request. Walks `C:\Astronomy\MyWorks`; top-level folders match `Projects.ProjectFolder` (unmatched → "unregistered project folder", reported and skipped). For each project, scans observation-folder subfolders (the two patterns from `DB_REWORK_PLAN.md` §3), extracts `ExposureTimeSeconds`/`TotalExposures` from the folder name or, if absent, from the shared `lights_<EQUIPMENT>` folder's FIT files filtered to the correct date/time range; computes `GoodLights` from actual FIT files found. Creates new `Observations` rows or fills gaps in existing ones; reports orphans; never deletes. Same preview-then-confirm-then-transaction UX as prior migration scripts. As its last step, bulk-fills `IntegrationMins` for anything still blank.

**Iterative fixes made to `audit_observations.py` (and mirrored into `migrate_add_integration_mins.py` where applicable) over several rounds of Carl's real-world testing/feedback:**

1. **Report readability** — replaced `ProjectID`/`ObservationID` in all printed report lines with `ProjectFolder`/`ObservationFolder` (or `ObservationDate` as a last resort for rows with no folder). `ObservationID` still used internally for the actual `UPDATE ... WHERE ObservationID = ?` SQL.

2. **Matching bug: no-folder rows never linked to a real disk folder.** Root cause: the matching pool only included existing rows that already had `ObservationFolder` set; no-folder rows were pulled out and permanently marked unlinkable *before* the disk scan ran, even when a matching disk folder existed this run. Fixed — the matching pool now includes every row, keyed by `ObservationFolder[:8]` if present or by `ObservationDate` (reformatted) if not; no-folder rows are full matching participants and get linked via the same disk-name-supersedes logic used for count corrections. This was a real correctness bug, not just cosmetic (a matching disk folder could otherwise have been inserted as a duplicate row).

3. **Matching correction (Carl's explicit instruction):** match by the **first 8 characters (date)** of `ObservationFolder`, not the full string — historical count corrections mean the disk name and stored value can legitimately differ. **The name on disk always supersedes**: when matched, `ObservationFolder` and the name-derived `TotalExposures`/`ExposureTimeSecs` are unconditionally updated to the disk value; everything else (`GoodLights`, `StartTime`, `EndTime`) is still fill-only-if-blank. If more than one existing row shares a date, disambiguate by `EquipmentID`; if still ambiguous, skip and report rather than guess.

4. **FIT filename regex bug (real data):** Carl's actual comet-project filenames (`Light_C-2025 R3_20.0s_IRCUT_20260405-060757.fit`) contain a hyphen and space in the target-name segment, which the original `[A-Z0-9_]+` character class didn't allow — caused `exposures`/`exp_secs`/`good_lights` to come back `None` entirely for the `C2025-R3` comet project despite real FIT files being present. Widened `FIT_FILENAME` to match free-text segments permissively. **Follow-on regression**, caught by re-testing old fixtures rather than just the new comet case: the first widened version accidentally required a literal underscore immediately before the date/time segment, which isn't actually guaranteed (older filename conventions have letters running straight into digits with no separator). Fixed by dropping the forced separator; re-verified against both conventions.

5. **Integration-time reporting:** every previewed row (create or update) now shows its own `integration_mins`; new "Projected Total Integration Time by project" section in both scripts shows current → projected totals, counting only rows whose `IntegrationMins` will actually be newly filled.

6. **Zero/missing `GoodLights` fallback:** when the FIT date-boundary matching finds nothing for a specific night (`GoodLights` comes back `0`) even though a shot count was recorded (from the folder name or an earlier fill), both scripts now fall back to `TotalExposures * ExposureTimeSecs / 60` instead of silently reporting/writing zero. Fixed in both the Python preview calculation and the actual SQL `UPDATE ... CASE WHEN GoodLights > 0 THEN ... ELSE TotalExposures * ExposureTimeSecs ... END` write path — verified in sandbox that the Python and SQL logic agree exactly.

All of the above were **tested against synthetic sandbox data** (including a scenario built to exactly mirror Carl's real comet-project structure) at each step — **none have yet been re-run by Carl against the live DB/real `MyWorks` folder with the final, fully-corrected versions.**

### Tooling incident: `edit_file` corruption bug

Hit a reproducible bug in the `edit_file` tool: patches to files containing regex-metacharacter-heavy content (particularly involving `$`) duplicated large sections of file content instead of applying a clean patch. Happened twice on `audit_observations.py` (recovered via full `write_file` rewrites both times) and once on this `SUMMARIZE.md` file itself (much more severe — duplicated the entire ~270KB file). For `SUMMARIZE.md`, several append attempts silently failed (returned "too large for context" without actually saving, confirmed via unchanged file-modified timestamp) before the corrupting one hit. **Carl rolled back `SUMMARIZE.md` via `git checkout` to recover** — the file you're reading now is that restored state plus this consolidated entry. No application code was affected, only this documentation file.

**Going forward: avoid `edit_file` for large append-only files or any content with heavy regex/backslash characters — prefer reading full current content and using `write_file` to write it back plus the addition, as was done for this very entry.**

### Key learnings (this block)
- **A reframing of the data model can turn what looked like a bug into the correct, intended shape** — worth checking whether a perceived anomaly is actually a modeling assumption before proposing a fix.
- **SQLite views are validated against their referenced tables during `DROP TABLE`/`ALTER TABLE ... RENAME` in the same transaction** — drop all dependent views up front, recreate at the end.
- **Always test a nontrivial migration script against a sandbox copy of the real DB before handing it off.**
- **Any JS/PHP code that looks up an item by a key assumed unique needs re-auditing the moment the underlying grouping changes to allow duplicates.**
- **When a field moves off a table in a migration, every UI form bound to that field needs updating in the same pass, not just the API layer** — API-level fixes can correctly stop *sending* a dropped field while a UI form silently keeps *reading* it, failing quietly (empty value) rather than loudly.
- **`textContent` assignments never decode HTML entities** — only plain characters are safe in that context; HTML entities only work in `innerHTML`-consumed strings.
- **When a person asks to move a calculation "out of the update SQL" and do it "post update," that's a request for a separate, explicit bulk step** — not just a different SQL expression in the same place.
- **When matching a derived/generated identifier against a stored value, match on the stable portion (e.g. the date) rather than the whole string** — avoids false "new record" detection when a volatile portion (e.g. a count) has legitimately changed since the DB was last updated.
- **When excluding rows from a matching process based on an incomplete field, check whether that exclusion also blocks the row from ever becoming complete** — a structural correctness bug can hide behind what looks like a cosmetic display issue.
- **Regression-test old fixtures after a "pure widening" regex change, not just the new case that motivated it** — a change that looks strictly more permissive on paper can still silently narrow coverage elsewhere if it adds an implicit new requirement.
- **This `edit_file` tool has a reproducible corruption bug on patches with regex-metacharacter-heavy content, especially involving `$`** — prefer full `write_file` rewrites for such files, and always re-verify after any `edit_file` patch to such content before trusting it.

### Status at end of session
- **Next session should start by running, in order, against the real live DB/`MyWorks` folder:** `migrate_add_integration_mins.py`, then `audit_observations.py`. Review each preview report carefully before confirming — especially the FIT-file-derived values for the comet project, given how many rounds of fixes that took.
- Confirm `pythonscripts/migrate_add_manual_integration_override.py` has been deleted (Carl confirmed he would do this manually).
- Still open from Phase 2: dead `IsMosaic` checkbox in admin Gallery Image card; no Project-picker UI for multi-project DSOs; the tabled deeper "smarter file/folder-based inference" idea has effectively been superseded by `audit_observations.py` itself.

---

## Session: 2026-07-13 (Postgres live migration + automated backups)

### Postgres migration executed for real

The `migrate_sqlite_to_postgres.py` script (written/validated in a prior session against a sandbox) was run for real against the IONOS VPS's `astro` Postgres database over the WireGuard tunnel.

- **First attempt failed**: `psycopg2.errors.InsufficientPrivilege: must be owner of schema public` on `DROP SCHEMA public CASCADE` inside `reset_target()`. Root cause: `astro_app` had connect/write grants but not ownership of the `public` schema (default owner was `postgres`).
- **Fix**: `sudo -u postgres psql -d astro -c "ALTER SCHEMA public OWNER TO astro_app;"` — one-time ownership transfer, no other config touched.
- **Second run succeeded completely**: all 19 tables migrated with matching SQLite/Postgres row counts (Constellations 38, Objects 100, CatalogIDs 165, Projects 62, GalleryImages 75, Observations 90, ProcessingRuns 41, Images 331, VisibilityForecast 54, etc.), all 20 foreign key constraints created, `Projects.IsMosaic` generated column recreated correctly, all 4 views created, column casing verified preserved (`DSOKey`, `ProjectFolder`, etc.).
- **The live `astro` database on the VPS is now a real, populated copy of local `astro.db`.** Local SQLite `astro.db` was not modified by the migration script (read-only source).

### Automated Postgres backups (pg_dump → Dropbox), on the VPS

Built an end-to-end automated backup pipeline per Carl's spec: connect via the WireGuard address (`10.8.0.1`, not loopback), run every ~3 days, keep 5 rotated backups both locally and on Dropbox.

**`/usr/local/bin/backup_astro_pg.sh`** (new, on VPS)
- `pg_dump -h 10.8.0.1 -U astro_app -d astro -Fc` (custom format) to a timestamped file in `/var/backups/postgres/`.
- Pushes the new dump to Dropbox via `rclone copy` to `dropbox:astro-backups`.
- Rotates to keep only the 5 newest dumps locally (`ls -1t ... | tail -n +6 | xargs rm -f`) and the 5 newest on Dropbox (`rclone lsf --files-only | sort -r | tail -n +6` → `rclone deletefile` per leftover).
- Logs each run to `/var/backups/postgres/backup.log`.
- Password currently embedded in the script (`PGPASSWORD=...`) — flagged as a candidate for a `~/.pgpass` file later, not yet changed.

**rclone Dropbox remote setup** — done via the standard headless-machine flow: `rclone config` on the VPS walked to a `config_token>` prompt; `rclone authorize "dropbox"` was run on Carl's Windows machine (browser-based Dropbox OAuth approval), and the resulting JSON token pasted back into the VPS prompt to complete the `dropbox:` remote.

**Two real infrastructure bugs found and fixed while getting the first successful backup run:**

1. **Missing `pg_hba.conf` entry for the VPS's own WireGuard IP.** The existing rule only allowed `10.8.0.2/32` (Carl's Windows box); the backup script runs *on* the VPS but connects via the tunnel address `10.8.0.1`, which had no matching rule. Fixed by adding `host astro astro_app 10.8.0.1/32 scram-sha-256` (matching the existing line's auth method) below the existing entry.
2. **`postgresql.service` is a meta-unit, not the real server, on this Debian/Ubuntu install.** `systemctl reload postgresql` / `restart postgresql` appeared to succeed (`active (exited)`, `ExecStart=/bin/true`) but never actually reloaded the running cluster, so the new `pg_hba.conf` line was never picked up despite being correct and correctly ordered. The real per-cluster service is `postgresql@16-main`; `sudo systemctl reload postgresql@16-main` (or `restart`) is what actually applies config changes. This was the actual fix — `pg_hba.conf` had been correct since the first edit.
3. **`postgresql@16-main` showed `enabled-runtime`, not persistently `enabled`**, meaning the boot-enablement symlink only lived in `/run/systemd/system/` and would not have survived a reboot. Fixed with an explicit `sudo systemctl enable postgresql@16-main`, confirmed now shows plain `enabled`. (Reboot verification deferred — not yet done, low urgency since backups already run successfully post-fix.)

**Cron scheduling** — initial crontab entry was accidentally left as `0 3 * * *` (daily) instead of the intended `0 3 */3 * *` (~every 3 days); caught by Carl reviewing `crontab -l` and corrected. Final confirmed crontab line: `0 3 */3 * * /usr/local/bin/backup_astro_pg.sh`. Noted caveat: `*/3` on day-of-month resets at each month boundary, so the actual gap is usually 3 days but occasionally 1 day right after a month rolls over — considered harmless for a backup job.

**End state, verified working:** manual run of `backup_astro_pg.sh` completes, writes a local dump, pushes to `dropbox:astro-backups`, rotates both locations to 5, and appends to `backup.log`. Scheduled via cron for recurring runs.

### Key learnings (this session)
- **Postgres database/schema ownership is separate from GRANT-based privileges** — a role can have full read/write grants on a database yet still be unable to `DROP SCHEMA`/`CREATE SCHEMA` without owning the schema itself. `ALTER SCHEMA public OWNER TO <role>` is the one-time fix for a freshly created app role.
- **On Debian/Ubuntu, `postgresql.service` is a meta/target unit, not the running server** — `ExecStart=/bin/true` and `active (exited)` are the tells. Config reloads must target the real per-cluster unit (`postgresql@<version>-<cluster>`, e.g. `postgresql@16-main`), or changes silently fail to apply while the meta-unit reports success.
- **`systemctl is-enabled` can report `enabled-runtime`**, which looks fine at a glance but only reflects a `/run/systemd/system/` symlink that resets on reboot — persistent boot-enablement requires the enable command to succeed against `/etc/systemd/system/`; re-running `systemctl enable` on the specific unit is the fix, and a real reboot test is the only fully reliable verification.
- **`pg_hba.conf` matching is address-based, not "which machine is asking"** — a script running locally on the VPS but connecting via a tunnel IP (`10.8.0.1`) needs its own `pg_hba.conf` entry distinct from a genuinely remote client's IP (`10.8.0.2`), even though both eventually reach the same server process.
- **rclone's headless/no-browser OAuth flow (`rclone authorize` run on a *different*, browser-capable machine, token pasted back) is the standard pattern for configuring cloud-storage remotes on a VPS** — worth remembering for any future headless-server-to-cloud-storage integration.
- **Always verify a systemd config change with the service's actual runtime status, not just the exit code of the reload command** — a reload command can return success while having reloaded the wrong (meta) unit.

### Status at end of session
- Postgres `astro` database on the VPS is now live and populated; automated backups (pg_dump → Dropbox, 3-day cadence, 5-deep rotation both locally and remotely) are confirmed working and scheduled via cron.
- **Not yet done, from the original Postgres punch list:**
  1. Refactor PHP (Laragon admin app) and Python (`titling.py`, `todays_dsos_web.py`, `audit_observations.py`, etc.) to point at Postgres instead of SQLite — app code still reads/writes local `astro.db` only; the new Postgres copy is not yet consumed by anything.
  2. `plesk-firewall.service` cleanup (disabled but not uninstalled) — untouched this session.
  3. WireGuard as a Windows service, for unattended script runs without the GUI app open — untouched this session.
  4. Consider moving the backup script's embedded `PGPASSWORD` to a `~/.pgpass` file.
- **Still outstanding from the 2026-07-06/07 session, not addressed this session:** running `migrate_add_integration_mins.py` then `audit_observations.py` against the live SQLite DB/`MyWorks` folder. Worth deciding, before running those, whether to apply them to SQLite and re-run the Postgres migration afterward, or apply them directly against Postgres going forward (depends on which DB becomes the app's source of truth first).
