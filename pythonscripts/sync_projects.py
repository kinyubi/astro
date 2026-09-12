#!/usr/bin/env python3
"""
sync_projects.py

Scans C:\\Astronomy\\myWorks for project folders that don't yet have a
matching Projects row in the database, and creates one for each that
resolves to a known DSO.

Supersedes sync_myworks.py, which is now obsolete on two counts: it wrote
to the legacy SQLite file (dsodb/astro.db) directly instead of the live
Postgres DB, and it targeted Objects.ProjectFolder / Objects.
MostRecentObservation -- columns that no longer exist since the "Project
is the hierarchy root" schema migration (see DB_REWORK_PLAN.md). It also
never created a missing Projects row in the first place, only updated
ones that already matched -- which is the actual root cause of "No
Project exists yet for DSO X -- add one before syncing" errors from Sync
Folder: nothing in the app has ever created that row automatically.

Folder naming convention (same as dso_add.py / sync_myworks.py):
    <catalogid>_<description>[_mosaic]
    e.g.  m1_crab_nebula
          ic1805_heart_nebula_mosaic
          c2025-A6_lemmon_comet

Matching is by the folder's leading catalog-id token against CatalogIDs
(falling back to a direct Objects.DSOKey match), case-insensitively --
Windows folder names are case-insensitive, so the token on disk may not
match the DB's stored casing exactly.

Every existing Projects.ProjectFolder is also compared case-insensitively,
so a folder that's already registered under different casing is correctly
recognized as already-registered rather than proposed as a duplicate.

'sun', 'moon', and 'scenery' are skipped entirely -- solar objects don't
use the Projects/GalleryImages pipeline (see the solar guard in
api_sync_folder.php).

A DSO can legitimately have more than one Project (e.g. a standard framing
and a separate mosaic framing) -- matching is by ProjectFolder, not by
whether the DSO already has some other Project, so a second folder for an
already-registered DSO is still correctly proposed as a new row.

IsMosaic is intentionally left out of the INSERT -- it has a DB-side
default (and per DB_REWORK_PLAN.md is intended to become a generated
column derived from ProjectFolder), so this script never sets it
directly.

SAFE BY DEFAULT: prints every planned change and creates nothing until
you pass --apply, matching every other maintenance script in this repo.

Usage:
    python sync_projects.py            # dry run (default)
    python sync_projects.py --apply    # actually create the Projects rows
"""
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))  # pythonscripts/, for db_connect
from db_connect import get_connection  # noqa: E402

WORKS_ROOT = Path(r"C:\Astronomy\myWorks")

# 'sun'/'moon' get a separate hardcoded pipeline (the solar guard in
# api_sync_folder.php blocks Sync Folder for ObjectTypeID='SOLAR_SYSTEM'
# entirely, so there's no point proposing a Project for them). Other
# planets aren't DSOs at all -- todays_dsos_web.py gets their positions
# live from the ephemeris, not the Objects table -- so a folder like
# 'jupiter' or 'saturn' will never resolve via CatalogIDs and would just
# show up as a permanent false-positive in the unresolved list.
SKIP_FOLDERS = {
    'sun', 'moon', 'scenery',
    'mercury', 'venus', 'mars', 'jupiter', 'saturn', 'uranus', 'neptune',
}


def extract_catalog_id(folder_name: str) -> str:
    """
    'ic342_hidden_galaxy'        -> 'IC342'
    'ngc2174_monkey_head_nebula' -> 'NGC2174'
    'm1_crab_nebula'             -> 'M1'
    'ic1805_heart_nebula_mosaic' -> 'IC1805'
    'c2025-A6_lemmon_comet'      -> 'C2025-A6'
    """
    return folder_name.split('_')[0].upper()


def main():
    apply_changes = '--apply' in sys.argv

    if not WORKS_ROOT.is_dir():
        print(f"ERROR: myWorks directory not found at {WORKS_ROOT}")
        sys.exit(1)

    conn = get_connection()
    cur = conn.cursor()

    # Existing Projects, keyed by lowercased ProjectFolder for
    # case-insensitive matching against disk folder names (see module
    # docstring -- Windows folder names are case-insensitive).
    cur.execute('SELECT ProjectFolder AS "ProjectFolder" FROM Projects')
    existing_folders = {row['ProjectFolder'].lower() for row in cur.fetchall() if row['ProjectFolder']}

    to_create = []      # (folder_name, dso_key, common_name)
    unresolved = []     # (folder_name, catalog_id) -- no matching DSO
    already_registered = 0
    skipped = 0

    for project_dir in sorted(WORKS_ROOT.iterdir()):
        if not project_dir.is_dir():
            continue
        folder_name = project_dir.name

        if folder_name.lower() in SKIP_FOLDERS:
            skipped += 1
            continue

        if folder_name.lower() in existing_folders:
            already_registered += 1
            continue

        catalog_id = extract_catalog_id(folder_name)

        # Resolve DSOKey: CatalogIDs first (the normal path), falling back
        # to a direct Objects.DSOKey match for DSOs with no separate
        # CatalogIDs row. Both comparisons case-insensitive.
        cur.execute("""
            SELECT o.DSOKey AS "DSOKey", o.CommonName AS "CommonName"
            FROM CatalogIDs c
            JOIN Objects o ON o.DSOKey = c.DSOKey
            WHERE UPPER(c.CatalogID) = UPPER(?)
            LIMIT 1
        """, (catalog_id,))
        row = cur.fetchone()

        if not row:
            cur.execute("""
                SELECT DSOKey AS "DSOKey", CommonName AS "CommonName"
                FROM Objects
                WHERE UPPER(DSOKey) = UPPER(?)
            """, (catalog_id,))
            row = cur.fetchone()

        if not row:
            unresolved.append((folder_name, catalog_id))
            continue

        to_create.append((folder_name, row['DSOKey'], row['CommonName']))

    print(f"Scanned {WORKS_ROOT}")
    print(f"  Already registered:         {already_registered}")
    print(f"  Skipped (sun/moon/planets/scenery): {skipped}")
    print(f"  No matching DSO found:      {len(unresolved)}")
    print(f"  New Projects to create:     {len(to_create)}")
    print()

    if unresolved:
        print("-- No matching DSO found (add the DSO via Quick Add first, then re-run) --")
        for folder_name, catalog_id in unresolved:
            print(f"  {folder_name:45s}  catalog id '{catalog_id}' not found")
        print()

    print("-- Planned new Projects rows --")
    for folder_name, dso_key, common_name in to_create:
        print(f"  {folder_name:45s}  -> DSOKey={dso_key}  ({common_name or '\u2014'})")

    if not to_create:
        print()
        print("Nothing to create.")
        conn.close()
        return

    if not apply_changes:
        print()
        print("Dry run only -- no DB changes made. Re-run with --apply to execute.")
        conn.close()
        return

    print()
    print("Applying changes...")
    created = 0
    errors = []
    for folder_name, dso_key, common_name in to_create:
        try:
            cur.execute(
                'INSERT INTO Projects (DSOKey, ProjectFolder) VALUES (?, ?)',
                (dso_key, folder_name)
            )
            conn.commit()
            created += 1
        except Exception as e:
            errors.append(f"{folder_name} ({dso_key}): {e}")

    print(f"Done. Created {created} Projects row(s).")
    if errors:
        print()
        print("Errors:")
        for e in errors:
            print(f"  {e}")

    conn.close()


if __name__ == '__main__':
    main()
