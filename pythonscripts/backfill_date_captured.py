"""
backfill_date_captured.py

For each GalleryImages row, locates the image file under:
    C:\\Astronomy\\MyWorks\\<ProjectFolder>\\<YYYYMMDD_*>\\<BaseName>_fav.jpg

From the containing session directory it infers and updates:
    DateCaptured  — YYYY-MM-DD from the 8-digit dir prefix
    Equipment     — e.g. "S30" or "S50" from trailing _S## token in dir name
    IsMosaic      — 1 if "mosaic" appears in session dir or project folder name
    PaletteID     — inferred from palette token in fav filename (_hso_, _sho_,
                    _hoo_, _hos_, _ohs_, _starless_, _mono_); 0 = Natural

Only overwrites fields that are currently NULL / 0-default, UNLESS --force
is passed, in which case all fields are re-inferred for every row.

Prints a preview before writing.

Usage:
    python backfill_date_captured.py [path_to_astro.db] [--force]
"""

import sqlite3
import sys
import os
import re
from collections import defaultdict

DB_PATH    = r"C:\laragon7\www\astro\dsodb\astro.db"
WORKS_ROOT = r"C:\Astronomy\MyWorks"
FORCE      = '--force' in sys.argv

args = [a for a in sys.argv[1:] if not a.startswith('--')]
if args:
    DB_PATH = args[0]

if not os.path.exists(DB_PATH):
    print(f"ERROR: Database not found at {DB_PATH}")
    sys.exit(1)

# ── Palette token → PaletteID mapping ────────────────────────────────────────
PALETTE_TOKENS = {
    'sho':      1,
    'hoo':      2,
    'hso':      3,
    'ohs':      4,
    'hos':      5,
    'starless': 6,
    'mono':     7,
}

def infer_palette(fav_filename, base_name):
    """
    Looks for a palette token between BaseName and _fav.jpg in the filename.
    e.g. ic1848_soul_mosaic_1108_hso_fav.jpg  → 'hso' → 3
         ngc2244_rosette_nebula_fav.jpg        → Natural → 0
    """
    # Strip base_name prefix and _fav.jpg suffix, leaving only the middle token(s)
    remainder = fav_filename.lower()
    bn_lower  = base_name.lower()
    if remainder.startswith(bn_lower):
        remainder = remainder[len(bn_lower):]   # e.g. '_hso_fav.jpg'
    remainder = remainder.replace('_fav.jpg', '').strip('_')
    # Check each known token
    for token, pid in PALETTE_TOKENS.items():
        if token in remainder.split('_'):
            return pid
    return 0  # Natural

def infer_equipment(session_dir_name):
    """
    Extracts equipment code from trailing _S## token.
    e.g. '20251108_165x60s_S30' → 'S30'
         '20260118_35x30s_S50'  → 'S50'
    Returns None if not found.
    """
    m = re.search(r'_(S\d+)$', session_dir_name, re.IGNORECASE)
    return m.group(1).upper() if m else None

def infer_is_mosaic(session_dir_name, project_folder):
    """
    Returns 1 if 'mosaic' appears in the session dir name or project folder name.
    """
    combined = (session_dir_name + '_' + project_folder).lower()
    return 1 if 'mosaic' in combined else 0

def find_session_for_image(base_name, project_folders):
    """
    Walks MyWorks/<project_folder>/<YYYYMMDD_*>/ looking for <base_name>_fav.jpg
    (case-insensitive). Returns dict with all inferred fields, or None.
    """
    for folder in project_folders:
        proj_path = os.path.join(WORKS_ROOT, folder)
        if not os.path.isdir(proj_path):
            continue

        for session_dir in os.listdir(proj_path):
            session_path = os.path.join(proj_path, session_dir)
            if not os.path.isdir(session_path):
                continue

            m = re.match(r'^(\d{4})(\d{2})(\d{2})', session_dir)
            if not m:
                continue

            try:
                entries = {e.lower(): e for e in os.listdir(session_path)}
            except PermissionError:
                continue

            fav_target = (base_name + '_fav.jpg').lower()

            # Also check for palette variants:
            # e.g. base_name = 'ic1848_soul_mosaic_1108' but file is
            # 'ic1848_soul_mosaic_1108_hso_fav.jpg' — we want to find ALL
            # fav files whose name starts with base_name and ends with _fav.jpg
            matching_favs = [
                orig for lower, orig in entries.items()
                if lower.startswith(base_name.lower()) and lower.endswith('_fav.jpg')
            ]

            # The plain fav (no palette token) takes priority for date/equipment/mosaic
            # since all palette variants share the same session
            if not matching_favs:
                continue

            yyyy, mm, dd = m.groups()
            date_str  = f"{yyyy}-{mm}-{dd}"
            equipment = infer_equipment(session_dir)
            is_mosaic = infer_is_mosaic(session_dir, folder)

            # For palette: find the specific fav file for this base_name
            # The exact match (base_name + _fav.jpg) = Natural
            # base_name + _<token>_fav.jpg = that palette
            palette_id = infer_palette(
                entries.get(fav_target, base_name + '_fav.jpg'),
                base_name
            )
            # If exact fav not found, this base_name itself has a palette suffix
            if fav_target not in entries:
                # The base_name already includes the palette token
                # (e.g. 'ic1848_soul_mosaic_1108_hso')
                palette_id = infer_palette(base_name + '_fav.jpg', base_name)

            return {
                'DateCaptured': date_str,
                'Equipment':    equipment,
                'IsMosaic':     is_mosaic,
                'PaletteID':    palette_id,
                'session_dir':  session_dir,
                'project_folder': folder,
            }

    return None

# ── Load GalleryImages ────────────────────────────────────────────────────────
conn = sqlite3.connect(DB_PATH)
conn.row_factory = sqlite3.Row
cur  = conn.cursor()

# Verify new columns exist
cur.execute("PRAGMA table_info(GalleryImages)")
cols = {row[1] for row in cur.fetchall()}
missing = [c for c in ('Equipment', 'IsMosaic') if c not in cols]
if missing:
    print(f"ERROR: Column(s) {missing} missing from GalleryImages.")
    print("Run migrate_add_equipment_ismosaic.py first.")
    sys.exit(1)

if FORCE:
    cur.execute("""
        SELECT gi.GalleryImageID, gi.DSOKey, gi.BaseName,
               gi.DateCaptured, gi.Equipment, gi.IsMosaic, gi.PaletteID,
               p.ProjectFolder
        FROM GalleryImages gi
        JOIN Projects p ON gi.DSOKey = p.DSOKey
        ORDER BY gi.DSOKey, gi.BaseName
    """)
else:
    cur.execute("""
        SELECT gi.GalleryImageID, gi.DSOKey, gi.BaseName,
               gi.DateCaptured, gi.Equipment, gi.IsMosaic, gi.PaletteID,
               p.ProjectFolder
        FROM GalleryImages gi
        JOIN Projects p ON gi.DSOKey = p.DSOKey
        WHERE gi.DateCaptured IS NULL OR gi.DateCaptured = ''
           OR gi.Equipment    IS NULL OR gi.Equipment    = ''
           OR gi.IsMosaic     IS NULL
        ORDER BY gi.DSOKey, gi.BaseName
    """)

rows = cur.fetchall()

if not rows:
    print("Nothing to update — all rows already have DateCaptured, Equipment, and IsMosaic.")
    sys.exit(0)

# Group by GalleryImageID (multiple project folders possible per DSO)
by_id = defaultdict(lambda: {'DSOKey':'','BaseName':'','folders':[],'current':{}})
for row in rows:
    gid = row['GalleryImageID']
    by_id[gid]['GalleryImageID'] = gid
    by_id[gid]['DSOKey']         = row['DSOKey']
    by_id[gid]['BaseName']       = row['BaseName']
    by_id[gid]['current']        = {
        'DateCaptured': row['DateCaptured'],
        'Equipment':    row['Equipment'],
        'IsMosaic':     row['IsMosaic'],
        'PaletteID':    row['PaletteID'],
    }
    if row['ProjectFolder'] not in by_id[gid]['folders']:
        by_id[gid]['folders'].append(row['ProjectFolder'])

print(f"Processing {len(by_id)} GalleryImages rows...\n")

# ── Infer and build updates ───────────────────────────────────────────────────
updates = []
skipped = []

for gid, info in sorted(by_id.items()):
    result = find_session_for_image(info['BaseName'], info['folders'])

    if not result:
        skipped.append((info['DSOKey'], info['BaseName'],
                        'fav file not found in any MyWorks session dir'))
        continue

    cur_vals = info['current']

    # Only overwrite fields that are NULL/empty (unless --force)
    new_date      = result['DateCaptured'] if (FORCE or not cur_vals['DateCaptured']) else cur_vals['DateCaptured']
    new_equipment = result['Equipment']    if (FORCE or not cur_vals['Equipment'])    else cur_vals['Equipment']
    new_ismosaic  = result['IsMosaic']     if (FORCE or cur_vals['IsMosaic'] is None) else cur_vals['IsMosaic']
    new_palette   = result['PaletteID']    if (FORCE or cur_vals['PaletteID'] is None) else cur_vals['PaletteID']

    updates.append({
        'GalleryImageID': gid,
        'DSOKey':         info['DSOKey'],
        'BaseName':       info['BaseName'],
        'DateCaptured':   new_date,
        'Equipment':      new_equipment,
        'IsMosaic':       new_ismosaic,
        'PaletteID':      new_palette,
        'session_dir':    result['session_dir'],
    })

# ── Preview ───────────────────────────────────────────────────────────────────
if updates:
    w = 28  # BaseName column width
    print(f"  {'ID':<5} {'DSOKey':<14} {'Date':<12} {'Equip':<6} {'Mos':<4} {'Pal':<4} {'Session Dir':<32} BaseName")
    print("  " + "-" * 105)
    for u in updates:
        print(
            f"  {u['GalleryImageID']:<5} {u['DSOKey']:<14} {u['DateCaptured']:<12} "
            f"{str(u['Equipment'] or ''):<6} {str(u['IsMosaic']):<4} {str(u['PaletteID']):<4} "
            f"{u['session_dir']:<32} {u['BaseName']}"
        )

if skipped:
    print(f"\nSkipped ({len(skipped)}):")
    for dso_key, base_name, reason in skipped:
        print(f"  [{dso_key}] {base_name} — {reason}")

print(f"\n{len(updates)} rows will be updated, {len(skipped)} skipped.")

if not updates:
    print("Nothing to write.")
    sys.exit(0)

answer = input("\nApply these updates? [y/N] ").strip().lower()
if answer != 'y':
    print("Aborted — no changes made.")
    sys.exit(0)

# ── Write ─────────────────────────────────────────────────────────────────────
cur.executemany("""
    UPDATE GalleryImages
    SET DateCaptured = ?,
        Equipment    = ?,
        IsMosaic     = ?,
        PaletteID    = ?
    WHERE GalleryImageID = ?
""", [
    (u['DateCaptured'], u['Equipment'], u['IsMosaic'], u['PaletteID'], u['GalleryImageID'])
    for u in updates
])
conn.commit()
print(f"Done — {len(updates)} rows updated.")
conn.close()
