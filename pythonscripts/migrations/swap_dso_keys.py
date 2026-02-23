#!/usr/bin/env python3
"""
swap_dso_keys.py
Promotes M-catalog aliases to become the primary DSOKey in astro.db.

For each object where an M-catalog ID (M1, M42, etc.) exists as an alias
pointing to an NGC/IC/other DSOKey, this script swaps them so the M-designation
becomes the DSOKey and the old key becomes an alias.

Example:
  Before: Objects.DSOKey = 'NGC1952',  CatalogIDs: M1→NGC1952, NGC1952→NGC1952*
  After:  Objects.DSOKey = 'M1',       CatalogIDs: M1→M1*,     NGC1952→M1

Tables updated: Objects, CatalogIDs, Projects (if populated)

A timestamped backup of astro.db is created before any changes are made.

Usage:
    python swap_dso_keys.py              # swap all M-alias candidates
    python swap_dso_keys.py --dry-run    # preview without making changes
    python swap_dso_keys.py M1 M42 M97  # swap specific objects only
"""

import argparse
import shutil
import sqlite3
import sys
from datetime import datetime
from pathlib import Path

# ── Configure ────────────────────────────────────────────────────────────────
DB_PATH = Path(r"C:\laragon7\www\astro\dsodb\astro.db")

# ── Helpers ──────────────────────────────────────────────────────────────────

def backup_db(db_path: Path) -> Path:
    """Copy the database to a timestamped backup file alongside the original."""
    ts = datetime.now().strftime("%Y%m%d_%H%M%S")
    backup_path = db_path.with_name(f"{db_path.stem}_backup_{ts}{db_path.suffix}")
    shutil.copy2(db_path, backup_path)
    return backup_path


def find_candidates(cur: sqlite3.Cursor, filter_keys: list[str] = None) -> list[dict]:
    """
    Find objects where an M-catalog alias exists but is not yet the DSOKey.
    Returns list of dicts with old_key and new_key (the M-designation).
    """
    cur.execute("""
        SELECT c.CatalogID AS m_key, c.DSOKey AS old_key
        FROM CatalogIDs c
        WHERE c.CatalogID GLOB 'M[0-9]*'
          AND c.DSOKey != c.CatalogID
        ORDER BY CAST(SUBSTR(c.CatalogID, 2) AS INTEGER)
    """)
    candidates = [{"new_key": row[0], "old_key": row[1]} for row in cur.fetchall()]

    if filter_keys:
        filter_upper = [k.upper() for k in filter_keys]
        candidates = [c for c in candidates if c["new_key"] in filter_upper]

    return candidates


def swap_key(cur: sqlite3.Cursor, old_key: str, new_key: str):
    """
    Swap DSOKey for one object from old_key to new_key.
    Must be called within a transaction with foreign_keys OFF.
    """
    # 1. Copy the Objects row under the new key
    cur.execute("""
        INSERT INTO Objects (
            DSOKey, CommonName, ObjectTypeID, ConstellationID,
            RAHours, DecDegrees, Magnitude, ObjectSize, DistanceLY,
            SocialBlurb, ProjectFolder, IsMosaic, MostRecentObservation,
            LastUpdated
        )
        SELECT
            ?, CommonName, ObjectTypeID, ConstellationID,
            RAHours, DecDegrees, Magnitude, ObjectSize, DistanceLY,
            SocialBlurb, ProjectFolder, IsMosaic, MostRecentObservation,
            LastUpdated
        FROM Objects WHERE DSOKey = ?
    """, (new_key, old_key))

    # 2. Re-point all CatalogIDs rows to the new DSOKey
    cur.execute("UPDATE CatalogIDs SET DSOKey = ? WHERE DSOKey = ?", (new_key, old_key))

    # 3. Re-point Projects rows (may be empty, but handle anyway)
    cur.execute("UPDATE Projects SET DSOKey = ? WHERE DSOKey = ?", (new_key, old_key))

    # 4. Delete the old Objects row
    cur.execute("DELETE FROM Objects WHERE DSOKey = ?", (old_key,))

    # 5. Mark the M-key as primary, demote the old key
    cur.execute("UPDATE CatalogIDs SET IsPrimary = 1 WHERE CatalogID = ? AND DSOKey = ?", (new_key, new_key))
    cur.execute("UPDATE CatalogIDs SET IsPrimary = 0 WHERE CatalogID = ? AND DSOKey = ?", (old_key, new_key))

    # 6. Ensure the old key itself exists as an alias (it should, but guard anyway)
    cur.execute("""
        INSERT OR IGNORE INTO CatalogIDs (CatalogID, DSOKey, IsPrimary)
        VALUES (?, ?, 0)
    """, (old_key, new_key))


# ── Main ─────────────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(description="Promote M-catalog IDs to primary DSOKey")
    parser.add_argument("keys", nargs="*", help="Optional: specific M-keys to swap (e.g. M1 M42)")
    parser.add_argument("--dry-run", action="store_true", help="Preview changes without modifying the database")
    args = parser.parse_args()

    if not DB_PATH.exists():
        print(f"ERROR: Database not found at {DB_PATH}", file=sys.stderr)
        sys.exit(1)

    con = sqlite3.connect(DB_PATH)
    con.row_factory = sqlite3.Row
    cur = con.cursor()

    candidates = find_candidates(cur, args.keys if args.keys else None)

    if not candidates:
        print("No M-alias candidates found" + (f" matching {args.keys}" if args.keys else "") + ".")
        con.close()
        return

    print(f"{'DRY RUN — ' if args.dry_run else ''}Found {len(candidates)} candidate(s):\n")
    for c in candidates:
        print(f"  {c['old_key']:15s}  →  {c['new_key']}")

    if args.dry_run:
        print("\nDry run complete — no changes made.")
        con.close()
        return

    # Backup before touching anything
    backup_path = backup_db(DB_PATH)
    print(f"\nBackup created: {backup_path.name}")

    # Disable FK enforcement for the duration of the swap, then re-enable
    con.execute("PRAGMA foreign_keys = OFF")

    ok = err = 0
    for c in candidates:
        old_key = c["old_key"]
        new_key = c["new_key"]
        try:
            con.execute("BEGIN")
            swap_key(cur, old_key, new_key)
            con.execute("COMMIT")
            print(f"  ✓  {old_key:15s}  →  {new_key}")
            ok += 1
        except Exception as e:
            con.execute("ROLLBACK")
            print(f"  ✗  {old_key:15s}  →  {new_key}  ERROR: {e}")
            err += 1

    con.execute("PRAGMA foreign_keys = ON")
    con.close()

    print(f"\nDone — {ok} swapped, {err} failed.")
    if err:
        print(f"Backup available at: {backup_path}")


if __name__ == "__main__":
    main()
