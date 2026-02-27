"""
Migration: Add SqArcMins column to Objects table and populate from ObjectSize.
Run once: python migrate_add_sqarcmins.py
"""
import sqlite3, re
from pathlib import Path

DB_PATH = Path(__file__).parent / 'astro.db'

def parse_sqarcmins(size_text):
    """
    Extract SqArcMins from an ObjectSize prose string.
    Returns float or None if unparseable.
    Rules:
      - Two-dimension arcminutes (e.g. 18×12 arcminutes) -> d1 * d2
      - Single arcminute value (e.g. ~45 arcminutes)     -> d * d
      - Degree value (e.g. ~3°)                          -> (d*60) * (d*60)
    """
    if not size_text:
        return None

    # Two-dimension arcminutes: 6×4 or 18x12 arcminutes
    m = re.search(r'~?(\d+\.?\d*)[×x](\d+\.?\d*)\s*arcminutes?', size_text, re.I)
    if m:
        return float(m.group(1)) * float(m.group(2))

    # Single arcminute value
    m = re.search(r'~?(\d+\.?\d*)\s*arcminutes?', size_text, re.I)
    if m:
        d = float(m.group(1))
        return d * d

    # Degree value
    m = re.search(r'~?(\d+\.?\d*)\s*[°º]', size_text)
    if m:
        d = float(m.group(1)) * 60
        return d * d

    return None

conn = sqlite3.connect(DB_PATH)
cur = conn.cursor()

# Add column if not already present
cur.execute("PRAGMA table_info(Objects)")
cols = [r[1] for r in cur.fetchall()]
if 'SqArcMins' in cols:
    print("SqArcMins column already exists — skipping ALTER TABLE.")
else:
    cur.execute("ALTER TABLE Objects ADD COLUMN SqArcMins REAL")
    print("Added SqArcMins column.")

# Populate from ObjectSize
cur.execute("SELECT DSOKey, ObjectSize FROM Objects")
rows = cur.fetchall()

updated = 0
skipped = []
zeroed  = []

for dsokey, size in rows:
    # Stars and comets get 0
    if dsokey in ('HD34078', 'C2025-A6', 'Vega', 'Rigel', 'Sirius',
                  'Deneb', 'Capella', 'Gamma Cassiopeia', 'Spica', 'Polaris'):
        cur.execute("UPDATE Objects SET SqArcMins = 0 WHERE DSOKey = ?", (dsokey,))
        zeroed.append(dsokey)
        updated += 1
        continue

    val = parse_sqarcmins(size)
    if val is not None:
        cur.execute("UPDATE Objects SET SqArcMins = ? WHERE DSOKey = ?", (round(val, 2), dsokey))
        updated += 1
    else:
        skipped.append((dsokey, (size or '')[:70]))

conn.commit()
conn.close()

print(f"\nUpdated {updated} rows.")

if zeroed:
    print(f"\nZeroed (stars/comets): {', '.join(zeroed)}")

if skipped:
    print(f"\nCould not parse ({len(skipped)}) — set to NULL, update manually:")
    for k, s in skipped:
        print(f"  {k:<20} {s}")

print("\nDone.")
