#!/usr/bin/env python3
"""
consolidate_duplicate_projects.py

One-off cleanup for a design change: a DSO used to legitimately have more
than one Projects row (e.g. a standard framing plus a separate mosaic
framing in its own myWorks folder). That's no longer how this app works --
mosaics now live inside the DSO's single Project folder alongside its
regular frames -- but the old second Projects row (e.g. an
"..._heart_mosaic"-style folder that was later merged into
"ic1805_heart_nebula") is still sitting in the DB, which is why a DSO like
IC1805 shows two Projects in admin when there's really only one folder on
disk any more.

This scans for every DSOKey with more than one Projects row and decides
which row to KEEP, in two passes:

  1. Literal duplicates -- every row for the DSO has the SAME
     ProjectFolder text (case-insensitively). This is the common case in
     practice (e.g. IC1805 and NGC1499 both turned out to be two rows
     both named "ic1805_heart_nebula" / "ngc1499_california_nebula" --
     not a stale-folder-vs-current-folder situation at all, just the same
     row inserted twice, most likely from the admin's auto-detect
     "unregistered folder" prompt or +Add Project being run more than
     once). There's no disk signal to distinguish these, so instead we
     keep whichever row has the most data actually attached to it --
     Observations + GalleryImages + ProcessingRuns row counts, summed --
     so we drop the emptier duplicate rather than an arbitrary one; ties
     go to the lowest ProjectID (the original row). The other row(s) are
     deleted.

  2. Genuinely different ProjectFolder values (the situation this script
     was originally written for -- an old stale folder reference, like a
     retired separate "..._mosaic" folder, alongside the current one).
     Here we check which ProjectFolder(s) still exist as a real directory
     under WORKS_ROOT (C:\\Astronomy\\myWorks) -- matching
     case-insensitively, same convention as sync_projects.py and
     api_check_project.php, since Windows folder names are
     case-insensitive:
       - Exactly one candidate's folder exists on disk -> that's the
         real, current Project. The other row(s) are deleted.
       - Zero or 2+ candidates' folders exist on disk -> can't safely
         tell which one is current, so nothing for that DSO is touched.
         Reported under "Could not resolve" for you to sort out by hand
         (e.g. fix a ProjectFolder value, or delete the wrong row from
         admin yourself).

Deleting a stale Projects row cascades per the DB schema
(migrations/migrate_fk_cascade.py):
  - Observations rows for that ProjectID: deleted (ON DELETE CASCADE)
  - ProcessingRuns rows for that ProjectID: deleted (ON DELETE CASCADE)
  - GalleryImages rows: NOT deleted, just unlinked (ProjectID set to NULL,
    ON DELETE SET NULL) -- they stay attached to the DSO via DSOKey

Per Carl: none of that is a real loss here -- Audit Observations and Sync
Folder both rebuild from what's actually on disk, so re-running those
(Sync Folder also runs Audit Observations automatically) for the
consolidated DSO after this restores everything from the surviving
folder. This script does NOT do that re-sync itself -- run it from admin
(or api_sync_folder.php / audit_observations.py) afterward for each
consolidated DSO.

SAFE BY DEFAULT: prints every planned change and deletes nothing until
you pass --apply, matching every other maintenance script in this repo.

Usage:
    python consolidate_duplicate_projects.py            # dry run (default)
    python consolidate_duplicate_projects.py --apply    # actually delete the stale rows
"""
import os
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))  # pythonscripts/, for db_connect
from db_connect import get_connection  # noqa: E402

WORKS_ROOT = Path(r"C:\Astronomy\MyWorks")


def main():
    apply_changes = '--apply' in sys.argv

    if not WORKS_ROOT.is_dir():
        print(f"ERROR: myWorks directory not found at {WORKS_ROOT}")
        sys.exit(1)

    # Folders actually on disk right now, lowercased, for case-insensitive
    # matching against stored ProjectFolder values.
    disk_folders = {d.lower() for d in os.listdir(WORKS_ROOT) if (WORKS_ROOT / d).is_dir()}

    conn = get_connection()
    cur = conn.cursor()

    cur.execute('''
        SELECT ProjectID AS "ProjectID", DSOKey AS "DSOKey", ProjectFolder AS "ProjectFolder"
        FROM Projects
        ORDER BY DSOKey, ProjectID
    ''')
    by_dso = {}
    for row in cur.fetchall():
        r = dict(row)
        by_dso.setdefault(r['DSOKey'], []).append(r)

    dupes = {dso: projects for dso, projects in by_dso.items() if len(projects) > 1}

    if not dupes:
        print("No DSOs with more than one Project. Nothing to do.")
        conn.close()
        return

    to_keep = {}      # DSOKey -> kept row
    to_delete = []    # rows being removed
    unresolved = []   # (DSOKey, [rows]) -- couldn't safely pick one
    resolutions = {}  # DSOKey -> human-readable reason, for the report below

    def data_count(project_id):
        cur.execute('SELECT COUNT(*) AS "n" FROM Observations WHERE ProjectID = ?', (project_id,))
        n = dict(cur.fetchone())['n']
        cur.execute('SELECT COUNT(*) AS "n" FROM GalleryImages WHERE ProjectID = ?', (project_id,))
        n += dict(cur.fetchone())['n']
        cur.execute('SELECT COUNT(*) AS "n" FROM ProcessingRuns WHERE ProjectID = ?', (project_id,))
        n += dict(cur.fetchone())['n']
        return n

    for dso_key, projects in dupes.items():
        for p in projects:
            folder = p['ProjectFolder']
            p['_on_disk'] = bool(folder) and folder.lower() in disk_folders

        distinct_folders = {(p['ProjectFolder'] or '').strip().lower() for p in projects}

        if len(distinct_folders) == 1:
            # Pass 1: literal duplicates -- same ProjectFolder text on every
            # row, so there's no disk signal to distinguish them. Keep
            # whichever has the most data attached; ties go to the lowest
            # (original) ProjectID.
            for p in projects:
                p['_data_count'] = data_count(p['ProjectID'])
            keep = max(projects, key=lambda p: (p['_data_count'], -p['ProjectID']))
            to_keep[dso_key] = keep
            resolutions[dso_key] = (
                f"duplicate rows, same folder ({keep['ProjectFolder']}) -- "
                f"kept ProjectID={keep['ProjectID']} (most data attached)"
            )
            for p in projects:
                if p['ProjectID'] != keep['ProjectID']:
                    to_delete.append(p)
            continue

        # Pass 2: folder names actually differ -- fall back to checking
        # which one still exists on disk.
        on_disk = [p for p in projects if p['_on_disk']]
        if len(on_disk) == 1:
            keep = on_disk[0]
            to_keep[dso_key] = keep
            resolutions[dso_key] = (
                f"different folders -- kept ProjectID={keep['ProjectID']} "
                f"({keep['ProjectFolder']}, only one still on disk)"
            )
            for p in projects:
                if p['ProjectID'] != keep['ProjectID']:
                    to_delete.append(p)
        else:
            unresolved.append((dso_key, projects))

    print(f"Scanned Projects: {len(by_dso)} DSO(s), {sum(len(v) for v in by_dso.values())} row(s)")
    print(f"  DSOs with more than one Project: {len(dupes)}")
    print(f"  Resolved:                              {len(to_keep)}")
    print(f"  Unresolved (0 or 2+ distinct folders on disk): {len(unresolved)}")
    print()

    if unresolved:
        print("-- Could not resolve automatically -- left untouched --")
        for dso_key, projects in unresolved:
            reason = "no folder found on disk for any of them" if not any(p['_on_disk'] for p in projects) \
                else "more than one folder still exists on disk"
            print(f"  {dso_key}  ({reason})")
            for p in projects:
                flag = 'exists on disk' if p['_on_disk'] else 'MISSING on disk'
                print(f"    ProjectID={p['ProjectID']:<5} ProjectFolder={p['ProjectFolder'] or '(blank)':40s} {flag}")
        print()

    if not to_delete:
        print("Nothing resolvable to consolidate.")
        conn.close()
        return

    print("-- Planned consolidation --")
    for dso_key, keep in to_keep.items():
        stale = [p for p in to_delete if p['DSOKey'] == dso_key]
        if not stale:
            continue
        print(f"  {dso_key}: {resolutions[dso_key]}")
        for p in stale:
            cur.execute('SELECT COUNT(*) AS "n" FROM Observations WHERE ProjectID = ?', (p['ProjectID'],))
            obs_count = dict(cur.fetchone())['n']
            cur.execute('SELECT COUNT(*) AS "n" FROM ProcessingRuns WHERE ProjectID = ?', (p['ProjectID'],))
            proc_count = dict(cur.fetchone())['n']
            cur.execute('SELECT COUNT(*) AS "n" FROM GalleryImages WHERE ProjectID = ?', (p['ProjectID'],))
            gi_count = dict(cur.fetchone())['n']
            print(f"    delete ProjectID={p['ProjectID']} ({p['ProjectFolder'] or '(blank)'}) "
                  f"-- takes {obs_count} Observations, {proc_count} ProcessingRuns row(s) with it; "
                  f"unlinks {gi_count} GalleryImages row(s) (ProjectID -> NULL, not deleted)")

    if not apply_changes:
        print()
        print("Dry run only -- no DB changes made. Re-run with --apply to execute.")
        conn.close()
        return

    print()
    print("Applying changes...")
    deleted = 0
    errors = []
    for p in to_delete:
        try:
            cur.execute('DELETE FROM Projects WHERE ProjectID = ?', (p['ProjectID'],))
            conn.commit()
            deleted += 1
        except Exception as e:
            conn.rollback()
            errors.append(f"ProjectID={p['ProjectID']} (DSOKey={p['DSOKey']}): {e}")

    print(f"Done. Deleted {deleted} stale Projects row(s).")
    if errors:
        print()
        print("Errors:")
        for e in errors:
            print(f"  {e}")

    if deleted:
        print()
        print("Now re-run Sync Folder (or Audit Observations) in admin for each consolidated")
        print("DSO to rebuild Observations/GalleryImages links from the surviving folder:")
        for dso_key, keep in to_keep.items():
            if any(p['DSOKey'] == dso_key for p in to_delete):
                print(f"  {dso_key}  ({keep['ProjectFolder']})")

    conn.close()


if __name__ == '__main__':
    main()
