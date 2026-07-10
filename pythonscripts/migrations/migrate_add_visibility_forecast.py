"""
migrate_add_visibility_forecast.py

Creates the VisibilityForecast table, used to cache the "next visible
window" calculation for DSOs that aren't visible on the current report
date. One row per (ProfileName, DSOKey).

Safe to re-run — uses CREATE TABLE IF NOT EXISTS.

Usage:
    python migrate_add_visibility_forecast.py [path_to_astro.db]
"""

import sqlite3, sys, os

DB_PATH = r"C:\laragon7\www\astro\dsodb\astro.db"
args    = [a for a in sys.argv[1:] if not a.startswith('--')]
if args:
    DB_PATH = args[0]

if not os.path.exists(DB_PATH):
    print(f"ERROR: Database not found at {DB_PATH}")
    sys.exit(1)

conn = sqlite3.connect(DB_PATH)
cur  = conn.cursor()

cur.execute("""
    CREATE TABLE IF NOT EXISTS VisibilityForecast (
        ProfileName      TEXT NOT NULL,
        DSOKey           TEXT NOT NULL,
        ComputedDate     TEXT NOT NULL,
        FirstVisibleDate TEXT,
        LastVisibleDate  TEXT,
        SearchDays       INTEGER NOT NULL DEFAULT 180,
        PRIMARY KEY (ProfileName, DSOKey)
    )
""")
conn.commit()

cur.execute("PRAGMA table_info(VisibilityForecast)")
cols = [row[1] for row in cur.fetchall()]
print("VisibilityForecast table ready. Columns:", cols)

conn.close()
