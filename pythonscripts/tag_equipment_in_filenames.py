"""
tag_equipment_in_filenames.py

Historically the app didn't encode which Seestar model (S30/S50/S50P) was
used directly in an image's filename/BaseName -- only in the myWorks
session-directory name (e.g. '20250928_162x60s_S30'). This backfills that.

For every GalleryImages row whose BaseName doesn't already end with its
equipment code, it:

  1. Computes NewBaseName = BaseName + '_' + EquipmentID
  2. Renames every file derived from BaseName in public/images
     (fav/full/wall/wall4k/annotated_fav/annotated_full/annotated_wall/
     annotated_wall4k/thumbs)
  3. Renames the matching source files in
     myWorks/<ProjectFolder>/<SessionDir>/ (fav/full/wall/4k/raw and
     their _annotated versions). Files inside a 'first/' subfolder are
     deliberately left untouched -- treated as an archived earlier pass,
     not the live counterpart.
  4. Updates GalleryImages.BaseName to NewBaseName

IMPORTANT -- why the DB update and file renames happen together:
public/index.php and public/admin/index.php build every image URL/path
directly from GalleryImages.BaseName at request time
(e.g. 'images/fav/' . BaseName . '_fav.jpg'). Renaming files without
updating BaseName -- or updating BaseName without renaming the files --
breaks the live gallery (404s). Each row is done as one unit: if any
step for a row fails, files already renamed for that row are rolled
back and its DB row is left untouched. Other rows are unaffected.

SAFE BY DEFAULT: prints every planned change and touches nothing on
disk or in the DB until you pass --apply. Always run without --apply
first and read the output carefully -- especially the "Warnings" and
"skipped" sections -- before re-running with --apply.

Equipment source: uses GalleryImages.Equipment when set; otherwise
falls back to inferring it from SessionDir's trailing _S30/_S50/_S50P
token (same regex as backfill_date_captured.py / api_sync_folder.php).
Rows where neither is available are skipped and listed for manual
follow-up -- never guessed at.

Usage:
    python tag_equipment_in_filenames.py            # dry run (default)
    python tag_equipment_in_filenames.py --apply     # actually rename + update DB
"""
import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))  # pythonscripts/, for db_connect
from db_connect import get_connection  # noqa: E402

WORKS_ROOT = Path(r"C:\Astronomy\myWorks")
WEB_IMAGES = Path(r"C:\laragon7\www\astro\public\images")

# (public/images subdirectory, filename suffix inserted right after BaseName)
PUBLIC_TARGETS = [
    ('fav',              '_fav'),
    ('full',             '_full'),
    ('wall',             '_wall'),
    ('wall4k',           '_4k'),
    ('annotated_fav',    '_fav_annotated'),
    ('annotated_full',   '_full_annotated'),
    ('annotated_wall',   '_wall_annotated'),
    ('annotated_wall4k', '_4k_annotated'),
    ('thumbs',           '_thumb'),
]

# myWorks source-file suffixes, relative to the session directory itself
# (NOT its 'first/' subfolder, which is deliberately skipped)
MYWORKS_SUFFIXES = [
    '_fav', '_full', '_wall', '_4k', '_raw',
    '_fav_annotated', '_full_annotated', '_wall_annotated',
    '_4k_annotated', '_raw_annotated',
]

VALID_EQUIPMENT = {'S30', 'S50', 'S50P'}
_EQUIP_RE = re.compile(r'_(S\d+P?)$', re.IGNORECASE)
_IMG_EXTS = ('.jpg', '.jpeg', '.png')


def infer_equipment_from_session_dir(session_dir):
    if not session_dir:
        return None
    m = _EQUIP_RE.search(session_dir)
    return m.group(1).upper() if m else None


def already_tagged(base_name, equipment):
    return base_name.upper().endswith('_' + equipment)


def find_image(dir_path, stem):
    """Return the Path for stem + a known image extension inside dir_path,
    or None if none of those exist."""
    if not dir_path.is_dir():
        return None
    for ext in _IMG_EXTS:
        p = dir_path / (stem + ext)
        if p.exists():
            return p
    return None


_MOSAIC_SUFFIX = '_mosaic'


def resolve_session_path(project_folder, session_dir):
    """
    Returns the myWorks session directory Path for (project_folder,
    session_dir), or None if it can't be found.

    Some project folders were renamed on disk at some point to drop a
    redundant trailing '_mosaic' -- since the individual session
    directories inside them already carry '_mosaic' when relevant (e.g.
    '20251102_207x60s_mosaic_S30'), keeping it on the parent folder too
    was redundant, and it prevented one project folder from holding both
    mosaic and non-mosaic sessions (see e.g. ic1805_heart_nebula, which
    now has both a plain session and a '_mosaic' session side by side).
    Projects.ProjectFolder in the DB wasn't always updated when that
    happened, so this falls back to the folder name with '_mosaic'
    stripped before giving up. Run fix_mosaic_project_folders.py to
    correct the DB directly -- this is just a safety net for rows it
    hasn't reached (or hasn't been run) yet.
    """
    direct = WORKS_ROOT / project_folder / session_dir
    if direct.is_dir():
        return direct
    if project_folder.lower().endswith(_MOSAIC_SUFFIX):
        stripped = WORKS_ROOT / project_folder[:-len(_MOSAIC_SUFFIX)] / session_dir
        if stripped.is_dir():
            return stripped
    return None


def main():
    apply_changes = '--apply' in sys.argv

    conn = get_connection()
    cur = conn.cursor()

    cur.execute("""
        SELECT gi.GalleryImageID AS "GalleryImageID",
               gi.BaseName       AS "BaseName",
               gi.SessionDir     AS "SessionDir",
               gi.Equipment      AS "Equipment",
               p.ProjectFolder   AS "ProjectFolder"
        FROM GalleryImages gi
        LEFT JOIN Projects p ON gi.ProjectID = p.ProjectID
        ORDER BY gi.GalleryImageID
    """)
    rows = cur.fetchall()

    planned = []
    skipped_tagged = []
    skipped_no_equipment = []
    skipped_no_project = []
    warnings = []

    for row in rows:
        old_base = row['BaseName']
        if not old_base:
            continue

        equipment = (row['Equipment'] or '').strip().upper() or None
        if not equipment:
            equipment = infer_equipment_from_session_dir(row['SessionDir'])

        if not equipment:
            skipped_no_equipment.append(row)
            continue

        if equipment not in VALID_EQUIPMENT:
            warnings.append(
                f"GalleryImageID {row['GalleryImageID']}: unrecognized "
                f"equipment code {equipment!r} -- skipped"
            )
            continue

        if already_tagged(old_base, equipment):
            skipped_tagged.append(row)
            continue

        if not row['ProjectFolder'] or not row['SessionDir']:
            skipped_no_project.append(row)
            continue

        new_base = f"{old_base}_{equipment}"
        file_renames = []

        # -- public/images --
        for subdir, suffix in PUBLIC_TARGETS:
            src = find_image(WEB_IMAGES / subdir, old_base + suffix)
            if not src:
                continue  # that variant doesn't exist for this image -- fine
            dst = src.with_name(new_base + suffix + src.suffix)
            if dst.exists():
                warnings.append(
                    f"GalleryImageID {row['GalleryImageID']}: rename target "
                    f"already exists, leaving source as-is: {dst}"
                )
                continue
            file_renames.append((src, dst))

        # -- myWorks source --
        session_path = resolve_session_path(row['ProjectFolder'], row['SessionDir'])
        if not session_path:
            tried = f"{row['ProjectFolder']}\\{row['SessionDir']}"
            if row['ProjectFolder'].lower().endswith(_MOSAIC_SUFFIX):
                tried += " (and its non-'_mosaic' form)"
            warnings.append(
                f"GalleryImageID {row['GalleryImageID']}: myWorks session dir "
                f"not found, skipping myWorks side -- tried: {tried}"
            )
        else:
            for suffix in MYWORKS_SUFFIXES:
                src = find_image(session_path, old_base + suffix)
                if not src:
                    continue
                dst = src.with_name(new_base + suffix + src.suffix)
                if dst.exists():
                    warnings.append(
                        f"GalleryImageID {row['GalleryImageID']}: myWorks "
                        f"rename target already exists, leaving source "
                        f"as-is: {dst}"
                    )
                    continue
                file_renames.append((src, dst))

        planned.append((row, old_base, new_base, equipment, file_renames))

    # ---------------------------------------------------------------- report
    print(f"Found {len(rows)} GalleryImages rows.")
    print(f"  Already tagged (skipped):        {len(skipped_tagged)}")
    print(f"  No equipment known (skipped):    {len(skipped_no_equipment)}")
    print(f"  No Project/SessionDir (skipped): {len(skipped_no_project)}")
    print(f"  Planned renames:                 {len(planned)}")
    print()

    if skipped_no_equipment:
        print("-- Rows with no equipment known (need manual follow-up) --")
        for row in skipped_no_equipment:
            print(f"  GalleryImageID {row['GalleryImageID']}: "
                  f"BaseName={row['BaseName']!r} SessionDir={row['SessionDir']!r}")
        print()

    if skipped_no_project:
        print("-- Rows with equipment but no linked Project/SessionDir --")
        for row in skipped_no_project:
            print(f"  GalleryImageID {row['GalleryImageID']}: "
                  f"BaseName={row['BaseName']!r} Equipment={row['Equipment']!r}")
        print()

    if warnings:
        print("-- Warnings --")
        for w in warnings:
            print(f"  {w}")
        print()

    print("-- Planned renames --")
    for row, old_base, new_base, equipment, file_renames in planned:
        print(f"  GalleryImageID {row['GalleryImageID']}: "
              f"{old_base!r} -> {new_base!r}  ({len(file_renames)} files)")
        for src, dst in file_renames:
            print(f"      {src}")
            print(f"        -> {dst}")

    if not apply_changes:
        print()
        print("Dry run only -- no files renamed, no DB changes made.")
        print("Review the output above, then re-run with --apply to execute.")
        conn.close()
        return

    # ----------------------------------------------------------------- apply
    print()
    print("Applying changes...")
    applied_rows = 0
    applied_files = 0
    errors = []

    for row, old_base, new_base, equipment, file_renames in planned:
        renamed_so_far = []
        try:
            for src, dst in file_renames:
                src.rename(dst)
                renamed_so_far.append((src, dst))
                applied_files += 1

            cur.execute(
                'UPDATE GalleryImages SET BaseName = ? WHERE GalleryImageID = ?',
                (new_base, row['GalleryImageID'])
            )
            conn.commit()
            applied_rows += 1
        except Exception as e:
            # Roll back any files already renamed for this row so it's never
            # left half-done, then move on to the next row.
            for src, dst in reversed(renamed_so_far):
                try:
                    dst.rename(src)
                except Exception:
                    pass
            errors.append(f"GalleryImageID {row['GalleryImageID']}: {e}")

    print(f"Done. Updated {applied_rows} rows, renamed {applied_files} files.")
    if errors:
        print()
        print("Errors (row left unchanged, any renamed files for it rolled back):")
        for e in errors:
            print(f"  {e}")

    conn.close()


if __name__ == '__main__':
    main()
