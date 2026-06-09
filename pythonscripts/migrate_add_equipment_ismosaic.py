"""
migrate_add_equipment_ismosaic.py

Adds Equipment (TEXT) and IsMosaic (INTEGER DEFAULT 0) columns to the
GalleryImages table if they don't already exist.

Safe to re-run.

Usage:
    python migrate_add_equipment_ismosaic.py [path_to_astro.db]
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
cur  = conn.cursor()

cur.execute("PRAGMA table_info(GalleryImages)")
existing_cols = {row[1] for row in cur.fetchall()}

added = []

if 'Equipment' not in existing_cols:
    cur.execute("ALTER TABLE GalleryImages ADD COLUMN Equipment TEXT")
    added.append('Equipment')

if 'IsMosaic' not in existing_cols:
    cur.execute("ALTER TABLE GalleryImages ADD COLUMN IsMosaic INTEGER DEFAULT 0")
    added.append('IsMosaic')

if added:
    conn.commit()
    print(f"Added columns: {', '.join(added)}")
else:
    print("Columns already exist — nothing to do.")

conn.close()
