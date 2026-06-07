"""
migrate_gallery_images.py

Creates GalleryImages and DSOLinks tables in astro.db, then auto-populates
GalleryImages from the existing Images -> ProcessingRuns -> Projects chain.

Run once. Safe to re-run: skips creation if tables already exist, and will
not insert duplicate rows (checks BaseName uniqueness before inserting).

Usage:
    python migrate_gallery_images.py [path_to_astro.db]

Default DB path: C:\\laragon7\\www\\astro\\dsodb\\astro.db
"""

import sqlite3
import sys
import os

DB_PATH = r"C:\laragon7\www\astro\dsodb\astro.db"
if len(sys.argv) > 1:
    DB_PATH = sys.argv[1]

if not os.path.exists(DB_PATH):
    print(f"ERROR: Database not found at {DB_PATH}")
    sys.exit(1)

conn = sqlite3.connect(DB_PATH)
conn.row_factory = sqlite3.Row
cur = conn.cursor()

# ── 1. Create GalleryImages ───────────────────────────────────────────────────

cur.execute("""
    CREATE TABLE IF NOT EXISTS GalleryImages (
        GalleryImageID  INTEGER PRIMARY KEY AUTOINCREMENT,
        DSOKey          TEXT    NOT NULL,
        BaseName        TEXT    NOT NULL,
        Caption         TEXT,
        PaletteID       INTEGER DEFAULT 0,
        DateCaptured    TEXT,
        Copyright       TEXT,
        IsOwn           INTEGER DEFAULT 1,
        Attribution     TEXT,
        SortOrder       INTEGER DEFAULT 0,
        IsFeature       INTEGER DEFAULT 0,
        FOREIGN KEY (DSOKey)    REFERENCES Objects(DSOKey),
        FOREIGN KEY (PaletteID) REFERENCES PaletteTreatments(PaletteID)
    )
""")
print("GalleryImages table: ready")

# ── 2. Create DSOLinks ────────────────────────────────────────────────────────

cur.execute("""
    CREATE TABLE IF NOT EXISTS DSOLinks (
        LinkID      INTEGER PRIMARY KEY AUTOINCREMENT,
        DSOKey      TEXT NOT NULL,
        Label       TEXT NOT NULL,
        URL         TEXT NOT NULL,
        SortOrder   INTEGER DEFAULT 0,
        FOREIGN KEY (DSOKey) REFERENCES Objects(DSOKey)
    )
""")
print("DSOLinks table: ready")

# ── 3. Derive render groups from existing Images table ────────────────────────
#
# A "render group" = one processed version of a DSO (e.g. natural, or a
# specific palette). We identify them by looking at fav, non-annotated,
# published images and stripping the _fav.jpg suffix to get the BaseName.
#
# For DSOs that have multiple render groups (e.g. IC1848 Natural + HSO),
# the one with the lowest PaletteID gets IsFeature=1 and SortOrder=0;
# the rest get incrementing SortOrder values.

cur.execute("""
    SELECT
        p.DSOKey,
        i.PaletteID,
        REPLACE(i.Filename, '_fav.jpg', '') AS BaseName,
        pr.ProcessingDateEnd                AS DateCaptured
    FROM Images i
    JOIN ProcessingRuns pr ON i.ProcessingID = pr.ProcessingID
    JOIN Projects       p  ON pr.ProjectID   = p.ProjectID
    WHERE i.ImageTypeID  = 'fav'
      AND i.IsAnnotated  = 0
      AND i.IsPublished  = 1
    ORDER BY p.DSOKey, i.PaletteID, i.Filename
""")
rows = cur.fetchall()

# Group by DSOKey to assign IsFeature and SortOrder
from collections import defaultdict
by_dso = defaultdict(list)
for row in rows:
    by_dso[row["DSOKey"]].append(dict(row))

inserted = 0
skipped  = 0

for dso_key, renders in sorted(by_dso.items()):
    for sort_idx, render in enumerate(renders):
        base   = render["BaseName"]
        pal_id = render["PaletteID"]
        date   = render["DateCaptured"]  # likely None

        # Check for duplicate
        cur.execute(
            "SELECT GalleryImageID FROM GalleryImages WHERE BaseName = ?",
            (base,)
        )
        if cur.fetchone():
            print(f"  SKIP (already exists): {base}")
            skipped += 1
            continue

        is_feature = 1 if sort_idx == 0 else 0

        cur.execute("""
            INSERT INTO GalleryImages
                (DSOKey, BaseName, PaletteID, DateCaptured, IsOwn, SortOrder, IsFeature)
            VALUES (?, ?, ?, ?, 1, ?, ?)
        """, (dso_key, base, pal_id, date, sort_idx, is_feature))

        flag = " ★ FEATURED" if is_feature else ""
        print(f"  INSERT: [{dso_key}] {base} (palette={pal_id}, order={sort_idx}){flag}")
        inserted += 1

conn.commit()

# ── 4. Summary ────────────────────────────────────────────────────────────────

cur.execute("SELECT COUNT(*) FROM GalleryImages")
total = cur.fetchone()[0]

cur.execute("""
    SELECT g.DSOKey, COUNT(*) as cnt
    FROM GalleryImages g
    GROUP BY g.DSOKey
    HAVING cnt > 1
    ORDER BY g.DSOKey
""")
multi = cur.fetchall()

print()
print(f"Done. Inserted: {inserted}, Skipped: {skipped}, Total rows: {total}")
if multi:
    print(f"\nDSOs with multiple render groups ({len(multi)}):")
    for row in multi:
        print(f"  {row['DSOKey']}: {row['cnt']} images")

print("""
Next steps:
  - Open admin panel and fill in DateCaptured / Caption for each image
  - Add DSOLinks rows for Wikipedia, Cloudy Nights, etc.
  - Update api_search.php and api_save.php to serve GalleryImages & DSOLinks
  - Update index.php gallery to use the new data
""")

conn.close()
