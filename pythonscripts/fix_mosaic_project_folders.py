"""
fix_mosaic_project_folders.py

At some point several myWorks project folders were renamed on disk to
drop a redundant trailing '_mosaic' -- since the individual session
directories inside them already carry '_mosaic' when relevant (e.g.
'20251102_207x60s_mosaic_S30'), keeping it on the parent folder too was
redundant, and it prevented one project folder from holding both mosaic
and non-mosaic sessions side by side. For example,
C:\\Astronomy\\myWorks\\ic1805_heart_nebula now contains both a
non-mosaic session (20250928_162x60s_S30) and a mosaic one
(20251102_207x60s_mosaic_S30).

Projects.ProjectFolder in the DB was not updated when those folders were
renamed, so some rows still point at a "<name>_mosaic" folder that no
longer exists on disk -- only "<name>" does. This script finds every
Projects row where:

  - ProjectFolder ends in "_mosaic", AND
  - myWorks\\<ProjectFolder> does NOT exist on disk, AND
  - myWorks\\<ProjectFolder with the trailing "_mosaic" removed> DOES exist

and updates ProjectFolder to the version without the suffix. It does NOT
touch IsMosaic, DSOKey, or anything else -- only the stale folder-name
string. A project can legitimately still be IsMosaic=1 with a
plain-named ProjectFolder now that both project types can share one
folder.

Rows whose ProjectFolder ends in "_mosaic" but where NEITHER the direct
nor the stripped folder exists on disk are left alone and listed
separately -- that likely means the folder moved or was deleted, and
needs a manual look rather than a guess.

Run this before tag_equipment_in_filenames.py so that script's myWorks
paths resolve directly. (That script also has its own fallback for this
same pattern, as a safety net -- but fixing the DB here is the real
fix.)

SAFE BY DEFAULT: prints every planned change and touches nothing until
you pass --apply.

Usage:
    python fix_mosaic_project_folders.py            # dry run (default)
    python fix_mosaic_project_folders.py --apply     # actually update the DB
"""
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))  # pythonscripts/, for db_connect
from db_connect import get_connection  # noqa: E402

WORKS_ROOT = Path(r"C:\Astronomy\myWorks")
SUFFIX = '_mosaic'


def main():
    apply_changes = '--apply' in sys.argv

    conn = get_connection()
    cur = conn.cursor()

    cur.execute("""
        SELECT ProjectID     AS "ProjectID",
               DSOKey        AS "DSOKey",
               ProjectFolder AS "ProjectFolder",
               IsMosaic      AS "IsMosaic"
        FROM Projects
        ORDER BY ProjectID
    """)
    rows = cur.fetchall()

    fixes = []
    already_ok = 0
    unresolved = []

    for row in rows:
        folder = row['ProjectFolder']
        if not folder or not folder.lower().endswith(SUFFIX):
            continue  # not a candidate

        if (WORKS_ROOT / folder).is_dir():
            already_ok += 1  # folder still exists exactly as named -- leave it
            continue

        stripped = folder[:-len(SUFFIX)]
        if (WORKS_ROOT / stripped).is_dir():
            fixes.append((row, stripped))
        else:
            unresolved.append(row)  # neither form exists on disk

    print(f"Checked {len(rows)} Projects rows.")
    print(f"  ProjectFolder ends in '_mosaic' and still exists as-is (left alone): {already_ok}")
    print(f"  Stale -- folder renamed on disk, DB not updated (will fix):          {len(fixes)}")
    print(f"  ProjectFolder ends in '_mosaic' but NEITHER form exists on disk:     {len(unresolved)}")
    print()

    if unresolved:
        print("-- Unresolved (need a manual look -- folder may have moved or been deleted) --")
        for row in unresolved:
            print(f"  ProjectID {row['ProjectID']} (DSOKey={row['DSOKey']}): "
                  f"ProjectFolder={row['ProjectFolder']!r}")
        print()

    print("-- Planned fixes --")
    for row, new_folder in fixes:
        print(f"  ProjectID {row['ProjectID']} (DSOKey={row['DSOKey']}, "
              f"IsMosaic={row['IsMosaic']}): {row['ProjectFolder']!r} -> {new_folder!r}")

    if not apply_changes:
        print()
        print("Dry run only -- no DB changes made. Re-run with --apply to execute.")
        conn.close()
        return

    print()
    print("Applying changes...")
    applied = 0
    errors = []
    for row, new_folder in fixes:
        try:
            cur.execute(
                'UPDATE Projects SET ProjectFolder = ? WHERE ProjectID = ?',
                (new_folder, row['ProjectID'])
            )
            conn.commit()
            applied += 1
        except Exception as e:
            errors.append(f"ProjectID {row['ProjectID']}: {e}")

    print(f"Done. Updated {applied} rows.")
    if errors:
        print()
        print("Errors:")
        for e in errors:
            print(f"  {e}")

    conn.close()


if __name__ == '__main__':
    main()
