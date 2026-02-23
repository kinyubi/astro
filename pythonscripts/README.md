# pythonscripts — Web-Facing Python Tools

Scripts called by PHP (via `shell_exec`) or run directly from the CLI.
All scripts require the virtual environment in `venv/`.

## Setup

```bash
cd C:\laragon7\www\astro\pythonscripts
python -m venv venv
venv\Scripts\activate
pip install -r requirements.txt
```

Or on Linux/Mac:
```bash
source venv/bin/activate
pip install -r requirements.txt
```

---

## Active Scripts

### `todays_dsos_web.py` — Visibility Report Generator
Called by `public/vis/index.php` via `shell_exec`. Generates an HTML visibility
report for a given date and location profile.

```bash
python todays_dsos_web.py --date 2026-02-23 --profile default
python todays_dsos_web.py --date 2026-02-23 --profile backyard
```

Outputs a complete HTML document to stdout. The PHP caller caches it in
`public/vis/cache/`.

---

### `batch_ai_populate.py` — Bulk AI Field Population
Calls the admin `api_populate.php` + `api_save.php` endpoints for every object
in the database. Uses the Anthropic API to fill empty fields and regenerate
social blurbs. Runs on localhost — no auth required.

```bash
python batch_ai_populate.py                  # All objects
python batch_ai_populate.py M42              # Single object (DSOKey or any catalog ID)
python batch_ai_populate.py --blurb-only     # Regenerate SocialBlurb only
python batch_ai_populate.py --empty-only     # Only objects with no ObjectTypeID
python batch_ai_populate.py --dry-run        # Preview without calling the API
```

---

### `sync_myworks.py` — Sync Project Folders to Database
Scans `C:\Astronomy\myWorks` and updates `Projects.ProjectFolder` and
`Projects.MostRecentObservation` in the database to match the filesystem.

```bash
python sync_myworks.py           # Sync all projects
python sync_myworks.py --dry-run # Preview changes
```

---

### `db_utils.py` — Database Utilities
General-purpose CLI for inspecting and querying the database.

```bash
python db_utils.py stats                  # Record counts, totals
python db_utils.py objects                # List all objects
python db_utils.py projects               # List projects with integration time
python db_utils.py observations [N]       # Recent N observations (default 20)
python db_utils.py needs-data [hours]     # Objects under threshold (default 2h)
python db_utils.py search <term>          # Search by name or catalog ID
python db_utils.py export-json            # Export gallery data to dso_watchlist_info.json
```

---

### `delete_orphans.py` — Image Orphan Cleanup
Finds image files that don't have a complete set across all 6 image directories
(`fav`, `full`, `wall`, `annotated_fav`, `annotated_full`, `annotated_wall`).
Prompts before deleting.

```bash
python delete_orphans.py
```

---

### `profile_cli.py` — Location Profile CLI
Called by `public/profiles/index.php`. Manages observation location profiles
stored as JSON files in `profiles/`. Not intended for direct use — use the
web UI at `/profiles` instead.

### `profile_manager.py` — Profile Manager Library
Shared library used by `profile_cli.py` and `todays_dsos_web.py`. Not a
standalone script.

---

## profiles/ Directory

JSON files defining observation locations. Each profile contains:
- `latitude`, `longitude`, `timezone`
- `min_altitude` — objects below this elevation are excluded
- `az_min`, `az_max` — azimuth window (0° = North, 180° = South)

The `default` profile is required and cannot be deleted.

---

## migrations/ — One-Off Scripts (Already Run)

These scripts were run once during the initial database build and restructuring.
Kept for reference in case the database needs to be rebuilt from scratch.

| Script | Purpose | When Run |
|--------|---------|----------|
| `setup_database.py` | Initial DB creation from schema + JSON + myWorks scan | Project start |
| `migrate_json_to_db.py` | Populated DB fields from `dso_watchlist_info.json` | After setup |
| `swap_dso_keys.py` | Promoted M-designations to primary DSOKeys | Feb 2026 |
| `update_common_name_in_json_info.py` | Fixed CommonName field in legacy JSON | Pre-migration |

> **Do not re-run these against a live database without reading the source first.**
> `setup_database.py` drops and recreates the database from scratch.
> `swap_dso_keys.py` creates a timestamped backup automatically.
