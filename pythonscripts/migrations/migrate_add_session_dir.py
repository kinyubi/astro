"""
migrate_add_session_dir.py

Adds SessionDir (TEXT) column to the GalleryImages table if it doesn't
already exist, then backfills it by scanning MyWorks for each BaseName's
fav file and recording the containing session subdirectory name.

Safe to re-run — skips rows that already have SessionDir set.

Usage:
    python migrate_add_session_dir.py [path_to_astro.db] [--force]
"""

import sqlite3, sys, os, re
from collections import defaultdict

DB_PATH    = r"C:\laragon7\www\astro\dsodb\astro.db"
WORKS_ROOT = r"C:\Astronomy\MyWorks"
FORCE      = '--force' in sys.argv
args       = [a for a in sys.argv[1:] if not a.startswith('--')]
if args:
    DB_PATH = args[0]

if not os.path.exists(DB_PATH):
    print(f"ERROR: Database not found at {DB_PATH}")
    sys.exit(1)

conn = sqlite3.connect(DB_PATH)
conn.row_factory = sqlite3.Row
cur  = conn.cursor()

# ── 1. Add column if missing ──────────────────────────────────────────────────
cur.execute("PRAGMA table_info(GalleryImages)")
existing = {row[1] for row in cur.fetchall()}
if 'SessionDir' not in existing:
    cur.execute("ALTER TABLE GalleryImages ADD COLUMN SessionDir TEXT")
    conn.commit()
    print("Added column: SessionDir")
else:
    print("SessionDir column already exists")

# ── 2. Fetch rows to backfill ─────────────────────────────────────────────────
if FORCE:
    cur.execute("""
        SELECT gi.GalleryImageID, gi.DSOKey, gi.BaseName, p.ProjectFolder
        FROM GalleryImages gi
        JOIN Projects p ON gi.DSOKey = p.DSOKey
        ORDER BY gi.DSOKey, gi.BaseName
    """)
else:
    cur.execute("""
        SELECT gi.GalleryImageID, gi.DSOKey, gi.BaseName, p.ProjectFolder
        FROM GalleryImages gi
        JOIN Projects p ON gi.DSOKey = p.DSOKey
        WHERE gi.SessionDir IS NULL OR gi.SessionDir = ''
        ORDER BY gi.DSOKey, gi.BaseName
    """)

rows = cur.fetchall()
if not rows:
    print("Nothing to backfill.")
    sys.exit(0)

# Group project folders per GalleryImageID
by_id = defaultdict(lambda: {'DSOKey':'','BaseName':'','folders':[]})
for row in rows:
    gid = row['GalleryImageID']
    by_id[gid]['GalleryImageID'] = gid
    by_id[gid]['DSOKey']         = row['DSOKey']
    by_id[gid]['BaseName']       = row['BaseName']
    if row['ProjectFolder'] not in by_id[gid]['folders']:
        by_id[gid]['folders'].append(row['ProjectFolder'])

# ── 3. Find session dir for each BaseName ─────────────────────────────────────
def find_session_dir(base_name, project_folders):
    fav_target = (base_name + '_fav.jpg').lower()
    for folder in project_folders:
        proj_path = os.path.join(WORKS_ROOT, folder)
        if not os.path.isdir(proj_path):
            continue
        for session_dir in os.listdir(proj_path):
            if not re.match(r'^\d{8}', session_dir):
                continue
            session_path = os.path.join(proj_path, session_dir)
            if not os.path.isdir(session_path):
                continue
            try:
                entries = [e.lower() for e in os.listdir(session_path)]
            except PermissionError:
                continue
            if fav_target in entries:
                return session_dir
    return None

updates = []
skipped = []

for gid, info in sorted(by_id.items()):
    session_dir = find_session_dir(info['BaseName'], info['folders'])
    if session_dir:
        updates.append((session_dir, gid, info['DSOKey'], info['BaseName']))
    else:
        skipped.append((info['DSOKey'], info['BaseName'], 'fav not found'))

# ── 4. Preview and confirm ────────────────────────────────────────────────────
if updates:
    print(f"\nProposed SessionDir updates ({len(updates)}):")
    print(f"  {'ID':<6} {'DSOKey':<14} {'SessionDir':<36} BaseName")
    print("  " + "-"*90)
    for session_dir, gid, dso_key, base_name in updates:
        print(f"  {gid:<6} {dso_key:<14} {session_dir:<36} {base_name}")

if skipped:
    print(f"\nSkipped ({len(skipped)}):")
    for dso_key, base_name, reason in skipped:
        print(f"  [{dso_key}] {base_name} — {reason}")

print(f"\n{len(updates)} rows will be updated, {len(skipped)} skipped.")

if not updates:
    sys.exit(0)

answer = input("\nApply? [y/N] ").strip().lower()
if answer != 'y':
    print("Aborted.")
    sys.exit(0)

cur.executemany(
    "UPDATE GalleryImages SET SessionDir = ? WHERE GalleryImageID = ?",
    [(s, gid) for s, gid, *_ in updates]
)
conn.commit()
print(f"Done — {len(updates)} rows updated.")
conn.close()
