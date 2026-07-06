# DB Rework Plan

Status: **Design finalized, no code changes made yet.** This supersedes `CLAUDE_PROMPT_2026_0704.md` — every open question in that doc was resolved through discussion on 2026-07-04/05. This file is the single source of truth for what to build; update it if anything changes during implementation.

---

## 1. Guiding principle established this round

**Project is the top of the hierarchy, not Object.** An Object (DSO) is a characteristic of a Project, the same way an Equipment row is a characteristic of an Observation — one Object can be referenced by multiple Projects (e.g. a standard framing and a separate mosaic framing of the same DSO), and that is not a data problem to be resolved, it's the correct model. Every decision below follows from this.

---

## 2. Schema changes

### `Objects` table — DSO-only fields
**Delete:** `ProjectFolder`, `MostRecentObservation`, `IsMosaic`
**Keep:** `WantBetter`, `SocialBlurb`, `Notes` — these describe the DSO itself (used for not-yet-imaged targets in `/vis` and Quick Add), not any specific project, so they stay despite living on the "Objects should only hold DSO fields" table.

### `Projects` table
**Delete:** `MosaicConfig`, `Status`, `TotalGoodLights`, `TotalIntegrationMins`, `CreatedDate` — all confirmed unused, and `Status` currently offers no real disambiguation anyway (see Appendix A).
**Keep `Notes`** (already exists on this table, currently empty for all 51 rows). One-time data step: copy the 6 `Objects.Notes` values into the matching `Projects.Notes` row where a Project row exists for that DSOKey. `Objects.Notes` is *not* deleted — this is a copy, not a move; both tables keep independent `Notes` going forward.
**Change `IsMosaic` to a generated column:**
```sql
IsMosaic INTEGER GENERATED ALWAYS AS (LOWER(ProjectFolder) LIKE '%mosaic%') VIRTUAL
```
This makes it structurally impossible for `IsMosaic` to disagree with the folder name — nothing writes to it directly, SQLite computes it. (This is the same class of bug as the `Status='ACTIVE'` issue in Appendix A — a manually-set flag that can silently drift from reality. Generated column closes that permanently for this field.)

### `GalleryImages` table
**Add:** `ProjectID INTEGER REFERENCES Projects(ProjectID)` — every gallery image belongs to a specific project, not just a DSO.
**Delete:** `IsMosaic` — this duplicated project-level info; mosaic status now comes from `Projects.IsMosaic` via the `ProjectID` join, so it can't drift.
**Gallery grouping changes from "one card per DSO" to "one card per Project."** A DSO with two projects (e.g. IC1805 standard + IC1805 mosaic) will now produce two gallery cards. An Object can legitimately appear in more than one grouping.
**Backfill plan:** for DSOs with a single Project row, the match is unambiguous. For DSOs with multiple Project rows (currently IC1805, NGC1499), match each existing `GalleryImages` row to the Project whose `IsMosaic` value agrees with that image's current `IsMosaic` value before the column is dropped. Verified this is clean for the current data (see Appendix A) — 3 existing rows across both DSOs, all consistently named/flagged.

### `Observations` table
**Add:** `ObservationFolder TEXT` — 1-to-1 with an actual observation folder on disk (see §3).
**Delete:** `RejectedLights`, `BortleScale` — confirmed zero non-null/non-zero values in current data, no data loss.

---

## 3. Folder / filename conventions (for auto-populate)

All of the following are **local-only** — FIT files, `ProjectFolder`, and `ObservationFolder` only ever exist on the local machine, never remotely. Observation Management's folder-scanning and AI Generate features are both local-only (no remote hint-based fallback, unlike `api_sync_folder.php`).

**Observation folder name**, one of:
- `^(\d{8})_(\d+)x(\d+)s_([A-Z0-9]+)$` → match1 = ObservationDate (YYYYMMDD), match2 = TotalExposures, match3 = ExposureTimeSecs, match4 = EquipmentID
- `^(\d{8})_([A-Z0-9]+)$` → match1 = ObservationDate (YYYYMMDD), match2 = EquipmentID

**Lights folder name:** `^lights_([A-Z0-9]+)$` → match1 = EquipmentID. Contains all subexposure FIT files from *all* observations for that equipment (not per-observation).

**FIT filename:** `^[A-Z0-9_]+_(\d+)\.0s_[A-Z0-9_]+(\d{8})-(\d{6})\.fit$` → match1 = ExposureTimeSeconds, match2 = date (YYYYMMDD), match3 = time (hhmmss). (Widened from `\d{2}` to `\d+` to support 3+ digit exposure times.)

**Date-boundary rule used throughout:** a FIT file "belongs to" an observation night if its date matches `ObservationDate` and its hour is > 12, OR its date matches `ObservationDate + 1 day` and its hour is < 12 (handles observations spanning midnight).

---

## 4. Observation Management auto-populate logic

When `ObservationFolder` is specified, auto-populate:

| Field | Logic |
|---|---|
| `ObservationDate` | First 8 characters of `ObservationFolder` |
| `StartTime` | Earliest FIT file in the lights folder matching the date-boundary rule above |
| `EndTime` | Latest FIT file in the lights folder matching the date-boundary rule above |
| `ExposureTimeSeconds` | From observation folder name match, or from a FIT filename match between `StartTime`/`EndTime` |
| `TotalExposures` | From observation folder name match, or count of FIT files between `StartTime`/`EndTime` inclusive |
| `GoodLights` | If `TotalExposures` came from the folder name: count of FIT files between `StartTime`/`EndTime` inclusive (don't populate if that count is 0). If `TotalExposures` came from the file count instead: default `GoodLights` to that same count (confirm if this default is wrong). |
| `Temperature` | Open-Meteo Historical Weather API (`/v1/archive`), free, no key required — for Star, Idaho at the midpoint of `StartTime`/`EndTime` |
| `Humidity` | Same Open-Meteo call, `relative_humidity_2m` |

AI Generate button available when adding/editing an observation, **local-only**.

---

## 5. New Admin landing page

New top-level admin landing page to choose between:
- **DSO/Project Maintenance** (current admin scope, unchanged in kind — though its "Observation & Project" section needs a proper rebuild per §6)
- **Observation Management** (new — add/edit observations, per §4)

---

## 6. Consequence of Project-as-hierarchy-root: display layer changes needed

These weren't in the original spec doc — they're required once Objects can have multiple Projects:

- **`vw_GalleryObjects` / `dso.php`** currently return one flat row of project fields per DSO. This needs to change to return a *list* of projects per DSO (each with its own folder, mosaic flag, most recent observation), not a single flattened row.
- **`/vis` info modal's "Observation & Project" section** (`todays_dsos_web.py`) needs to render a repeatable block, one per project, instead of a single fixed set of fields.
- **Admin panel's legacy Objects-embedded Observation & Project fields** are being deleted anyway (§2) — the replacement is the new Observation Management flow: pick a DSO → see its list of Projects → select or add one → manage its Observations.
- **Public gallery** moves from one-card-per-DSO to one-card-per-Project (§2, `GalleryImages`).

---

## 7. Suggested implementation sequencing

1. **Schema + data migration** (its own phase, verified before moving on):
   - Add `Projects.Notes`-copy step (data only, column already exists)
   - Convert `Projects.IsMosaic` to generated column
   - Add `GalleryImages.ProjectID`, backfill it, drop `GalleryImages.IsMosaic`
   - Drop `Objects.ProjectFolder` / `MostRecentObservation` / `IsMosaic`
   - Drop `Projects.MosaicConfig` / `Status` / `TotalGoodLights` / `TotalIntegrationMins` / `CreatedDate`
   - Add `Observations.ObservationFolder`, drop `Observations.RejectedLights` / `BortleScale`
   - Update `vw_GalleryObjects` to stop depending on `Projects.Status` and to reflect the new column set
2. **Fix every consumer of the deleted/changed columns** before moving on — `api_save.php`, `api_search.php`, `api_sync_folder.php`, `api_quickadd.php`, admin UI form, public gallery query/grouping, `/vis` modal, `dso.php`. (Given the `db_logger.php` work from the prior session, anything touching `Projects`/`Observations` writes should go through `get_db()` to stay logged.)
3. **New Admin landing page** (§5) — routing only, minimal risk.
4. **Observation Management UI + auto-populate logic** (§4) — the bulk of new feature work, local-only.
5. **Open-Meteo integration** for Temperature/Humidity auto-populate.

---

## Appendix A: Bug found during this analysis (informs §2's `Projects.Status` removal)

`IC1805` and `NGC1499` each have two `Projects` rows (standard + mosaic framing), and **both rows are `Status='ACTIVE'`** for each pair. `vw_GalleryObjects`'s `LEFT JOIN Projects p ON o.DSOKey = p.DSOKey AND p.Status = 'ACTIVE'` therefore already matches both rows for these two DSOs today — `dso.php`'s single-row `fetch()` has been non-deterministically returning whichever one SQLite's query planner returns first, with no `ORDER BY` to make that a real choice. This was already a live bug, not something introduced by this rework. Resolved by the model change in §1: both rows are legitimate and should both surface (as separate gallery cards, per §2/§6), rather than picking one.

Existing `GalleryImages` data confirms this is backfillable cleanly:
```
GalleryImageID=2,  DSOKey=IC1805,  BaseName=ic1805_heart,               SessionDir=20250928_162x60s_S30,        IsMosaic=0
GalleryImageID=45, DSOKey=IC1805,  BaseName=ic1805_heart_nebula_mosaic, SessionDir=20251102_207x60s_mosaic_S30, IsMosaic=1
GalleryImageID=29, DSOKey=NGC1499, BaseName=ngc1499_california,         SessionDir=20251114_60s_mosaic_S30,     IsMosaic=1
```

---

## Appendix B: Custom (multi-DSO / non-catalog) Objects convention

Some projects legitimately combine multiple real DSOs (e.g. a wide framing of the Heart & Soul Nebulae together) or, in principle, no cataloged DSO at all (aurora, star trails, etc.). Rather than adding a many-to-many Project↔Object relationship, the convention is to create a synthetic `Objects` row with a custom `DSOKey` (e.g. `CUST1`, `CUST2`, ...) and a normal `CommonName` (e.g. "Heart & Soul Nebulae"), then reference it from a `Projects` row the same as any real DSO (`ProjectFolder = 'cust1_heart_and_soul_nebulae'`, following the standard lowercase/underscore convention).

No DDL changes are needed for this — confirmed the only `NOT NULL` constraint on `Objects` is `WantBetter` (defaults to 0). This is fully consistent with §1's "Project is the hierarchy root, Object is just a characteristic of it" model: a synthetic DSO is just another `Objects` row a `Project` happens to reference.

**Give combined-target custom Objects real RA/Dec** — for a combo like Heart & Soul, use the framing centroid so the `/vis` visibility calculation still works normally and the project shows up in the nightly report like any real target.

**A true "no DSO at all" project (aurora, star trails, full Moon, etc.) is a different, harder case** and is deliberately *not* solved by this convention — there's no meaningful sky position to assign, so forcing a fake coordinate would make the visibility report track a target that isn't really one. To be addressed separately if/when such a project actually comes up, including whether `todays_dsos_web.py` degrades gracefully on a null-coordinate `Objects` row.

`PrimaryCatalogID` will correctly come back `NULL` for custom entries via the existing `LEFT JOIN CatalogIDs` — accurate, not a bug, no special-casing required.
