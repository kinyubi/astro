#!/usr/bin/env python3
"""
sync_myworks.py
Scans C:\\Astronomy\\myWorks and updates Objects in the database with:
  - ProjectFolder         : the folder name (relative to myWorks)
  - MostRecentObservation : the latest session date found in subfolders

When multiple folders map to the same object (e.g. a regular and mosaic folder),
the first folder sets ProjectFolder and MostRecentObservation. Any subsequent
folder for the same object only updates MostRecentObservation if its date is later.

Folder naming convention:
  <catalogid>_<description>[_mosaic]
  e.g.  m1_crab_nebula
        ic443_jellyfish_nebula
        ngc2174_monkey_head_nebula
        ic1805_heart_nebula_mosaic

Session subfolder naming convention (8-digit date prefix):
  20251213_201x20s_S50
  20251114_S30
  20260209_S30

Usage:
    python sync_myworks.py            # update all matched folders
    python sync_myworks.py --dry-run  # show what would be updated
"""

import argparse
import sqlite3
import re
from datetime import date
from pathlib import Path

# ── Configure ────────────────────────────────────────────────────────────────
MYWORKS_DIR = Path(r"C:\Astronomy\myWorks")
DB_PATH     = Path(r"C:\laragon7\www\astro\dsodb\astro.db")

# Folders to skip entirely
SKIP_FOLDERS = {'moon', 'sun', 'Scenery'}

# ── Helpers ──────────────────────────────────────────────────────────────────
DATE_RE = re.compile(r'^(\d{8})')   # matches 20251213 at start of subfolder name


def extract_catalog_id(folder_name: str) -> str:
    """
    Extract the catalog ID from a folder name.
    'ic443_jellyfish_nebula'        -> 'IC443'
    'ngc2174_monkey_head_nebula'    -> 'NGC2174'
    'm1_crab_nebula'                -> 'M1'
    'ic1805_heart_nebula_mosaic'    -> 'IC1805'
    'c2025-A6_lemmon_comet'         -> 'C2025-A6'
    """
    part = folder_name.split('_')[0]
    return part.upper()


def most_recent_session(project_dir: Path) -> date | None:
    """Return the most recent session date from subfolders, or None."""
    dates = []
    for sub in project_dir.iterdir():
        if not sub.is_dir():
            continue
        m = DATE_RE.match(sub.name)
        if m:
            try:
                d = date(int(m.group(1)[:4]),
                         int(m.group(1)[4:6]),
                         int(m.group(1)[6:8]))
                dates.append(d)
            except ValueError:
                pass
    return max(dates) if dates else None


def resolve_dso_key(cur: sqlite3.Cursor, catalog_id: str) -> str | None:
    """Look up DSOKey from CatalogIDs table."""
    cur.execute("SELECT DSOKey FROM CatalogIDs WHERE CatalogID = ?", (catalog_id,))
    row = cur.fetchone()
    return row[0] if row else None


def parse_date(date_str: str | None) -> date | None:
    """Parse an ISO date string to a date object, or None."""
    if not date_str:
        return None
    try:
        return date.fromisoformat(date_str)
    except ValueError:
        return None


# ── Main ─────────────────────────────────────────────────────────────────────
def main():
    parser = argparse.ArgumentParser(description="Sync myWorks folders to DSO database")
    parser.add_argument("--dry-run", action="store_true", help="Show changes without writing to DB")
    args = parser.parse_args()

    if not MYWORKS_DIR.exists():
        print(f"ERROR: myWorks directory not found at {MYWORKS_DIR}")
        return
    if not DB_PATH.exists():
        print(f"ERROR: Database not found at {DB_PATH}")
        return

    con = sqlite3.connect(DB_PATH)
    con.row_factory = sqlite3.Row
    cur = con.cursor()

    # Track what we've already set this run so mosaic/duplicate folders
    # don't clobber the primary folder name or an earlier update.
    # key: DSOKey, value: dict with 'folder' and 'date' (date object)
    seen: dict[str, dict] = {}

    updated = skipped = unmatched = 0

    for project_dir in sorted(MYWORKS_DIR.iterdir()):
        if not project_dir.is_dir():
            continue
        folder_name = project_dir.name

        if folder_name in SKIP_FOLDERS:
            continue

        catalog_id = extract_catalog_id(folder_name)
        dso_key    = resolve_dso_key(cur, catalog_id)

        if not dso_key:
            print(f"  ? {folder_name:45s}  catalog ID '{catalog_id}' not found in DB")
            unmatched += 1
            continue

        # Fetch current DB values
        cur.execute("SELECT ProjectFolder, MostRecentObservation FROM Objects WHERE DSOKey = ?", (dso_key,))
        obj = cur.fetchone()
        if not obj:
            print(f"  ? {folder_name:45s}  DSOKey '{dso_key}' not found in Objects table")
            unmatched += 1
            continue

        folder_date = most_recent_session(project_dir)

        # Determine the effective current state — prefer in-run updates over DB values
        if dso_key in seen:
            # We've already processed another folder for this object this run
            effective_folder = seen[dso_key]['folder']
            effective_date   = seen[dso_key]['date']

            new_folder = effective_folder   # never replace an already-set folder name
            new_date   = max(d for d in [effective_date, folder_date] if d is not None) \
                         if any([effective_date, folder_date]) else None

            changes = []
            if new_date != effective_date:
                changes.append(f"MostRecentObservation: '{effective_date}' -> '{new_date}' (from {folder_name})")

            if changes:
                print(f"  ✓ {folder_name:45s}  ({dso_key}) [additional folder]  {', '.join(changes)}")
                seen[dso_key]['date'] = new_date
                updated += 1
            else:
                print(f"  - {folder_name:45s}  ({dso_key}) [additional folder, no newer date]")
                skipped += 1

        else:
            # First time we've seen this object
            current_folder = obj["ProjectFolder"]
            current_date   = parse_date(obj["MostRecentObservation"])

            new_folder = folder_name if not current_folder else current_folder
            new_date   = max(d for d in [current_date, folder_date] if d is not None) \
                         if any([current_date, folder_date]) else None

            changes = []
            if new_folder != current_folder:
                changes.append(f"ProjectFolder: '{current_folder}' -> '{new_folder}'")
            if new_date != current_date:
                new_date_str = new_date.isoformat() if new_date else None
                cur_date_str = current_date.isoformat() if current_date else None
                changes.append(f"MostRecentObservation: '{cur_date_str}' -> '{new_date_str}'")

            seen[dso_key] = {'folder': new_folder, 'date': new_date}

            if changes:
                print(f"  ✓ {folder_name:45s}  ({dso_key})  {', '.join(changes)}")
                updated += 1
            else:
                skipped += 1

    # Write all accumulated updates to DB in one pass
    if not args.dry_run:
        for dso_key, vals in seen.items():
            cur.execute("""
                UPDATE Objects
                SET ProjectFolder = ?, MostRecentObservation = ?
                WHERE DSOKey = ?
            """, (
                vals['folder'],
                vals['date'].isoformat() if vals['date'] else None,
                dso_key,
            ))
        con.commit()

    con.close()

    print()
    if args.dry_run:
        print(f"DRY RUN — {updated} would be updated, {skipped} already current, {unmatched} unmatched")
    else:
        print(f"Done — {updated} updated, {skipped} already current, {unmatched} unmatched")


if __name__ == "__main__":
    main()
