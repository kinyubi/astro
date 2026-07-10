"""
fix_bootes_constellation_id.py

One-off fix: the "+ Add new constellation" AI lookup for Boötes returned a
ConstellationID containing a diaeresis ("BOö") instead of the correct
IAU-standard plain-ASCII code ("BOO"). PHP's strtoupper() isn't multibyte-safe
so it didn't catch this. api_constellation_add.php has been patched to
prevent this going forward -- this script corrects the bad row already in
the live DB.

Corrects:
  1. Constellations.ConstellationID  'BOö' -> 'BOO'
  2. Objects.ConstellationID         'BOö' -> 'BOO'  (currently just ARCTURUS)

Run from: C:\\laragon7\\www\\astro\\pythonscripts
    python fix_bootes_constellation_id.py
"""

import sqlite3
import os

DB_PATH = os.path.join(os.path.dirname(__file__), '..', 'dsodb', 'astro.db')
DB_PATH = os.path.abspath(DB_PATH)

BAD_ID  = 'BO\u00f6'   # "BOö"
GOOD_ID = 'BOO'


def main():
    print(f"Database: {DB_PATH}")
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    cur = conn.cursor()

    cur.execute("SELECT ConstellationID, Name, GenitiveName FROM Constellations WHERE ConstellationID = ?", (BAD_ID,))
    const_row = cur.fetchone()

    cur.execute("SELECT DSOKey, CommonName FROM Objects WHERE ConstellationID = ?", (BAD_ID,))
    obj_rows = cur.fetchall()

    if not const_row and not obj_rows:
        print(f"Nothing to fix -- no rows found with ConstellationID = '{BAD_ID}'.")
        conn.close()
        return

    print("\nFound the following affected rows:")
    if const_row:
        print(f"  Constellations: {dict(const_row)}")
    for row in obj_rows:
        print(f"  Objects:        {dict(row)}")

    # Check GOOD_ID doesn't already exist (would cause a PK collision)
    cur.execute("SELECT ConstellationID FROM Constellations WHERE ConstellationID = ?", (GOOD_ID,))
    if cur.fetchone() and const_row:
        print(f"\nERROR: '{GOOD_ID}' already exists in Constellations -- cannot rename without a collision.")
        print("Manual cleanup needed. Aborting without making changes.")
        conn.close()
        return

    confirm = input(f"\nApply fix: rename ConstellationID '{BAD_ID}' -> '{GOOD_ID}' in {1 if const_row else 0} Constellations row and {len(obj_rows)} Objects row(s)? [y/N] ")
    if confirm.strip().lower() != 'y':
        print("Aborted -- no changes made.")
        conn.close()
        return

    if const_row:
        cur.execute("UPDATE Constellations SET ConstellationID = ? WHERE ConstellationID = ?", (GOOD_ID, BAD_ID))
    if obj_rows:
        cur.execute("UPDATE Objects SET ConstellationID = ? WHERE ConstellationID = ?", (GOOD_ID, BAD_ID))

    conn.commit()
    print(f"\nDone. Updated {cur.rowcount if obj_rows else 0} Objects row(s) and {'1' if const_row else '0'} Constellations row.")
    conn.close()


if __name__ == '__main__':
    main()
