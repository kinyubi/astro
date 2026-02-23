"""
Migration: Add WantBetter column to Objects table
Run once from any directory:  python migrate_add_wantbetter.py
"""
import sqlite3
from pathlib import Path

DB_PATH = Path(__file__).parent / 'astro.db'

# WantBetter values from the Google Sheets watchlist (TRUE entries only)
# Add any additional DSOKeys here if needed before running
WANT_BETTER = [
    # Set these based on your current watchlist WantBetter=TRUE entries
    # e.g. 'M31', 'IC1805'
    # Leave empty if you want to set them manually in the DB afterward
]

conn = sqlite3.connect(DB_PATH)
cur = conn.cursor()

# Check if column already exists
cur.execute("PRAGMA table_info(Objects)")
cols = [r[1] for r in cur.fetchall()]

if 'WantBetter' in cols:
    print("WantBetter column already exists — nothing to do.")
else:
    cur.execute("ALTER TABLE Objects ADD COLUMN WantBetter INTEGER NOT NULL DEFAULT 0")
    print("Added WantBetter column.")

# Set TRUE values if any defined above
if WANT_BETTER:
    for key in WANT_BETTER:
        cur.execute("UPDATE Objects SET WantBetter = 1 WHERE DSOKey = ?", (key,))
        if cur.rowcount:
            print(f"  Set WantBetter=1 for {key}")
        else:
            print(f"  WARNING: {key} not found in Objects table")

conn.commit()
conn.close()
print("Done.")
