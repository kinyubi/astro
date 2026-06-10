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
