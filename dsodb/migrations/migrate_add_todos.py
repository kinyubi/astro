"""
Migration: Add Todos table.

Simple additive migration (no existing table touched), following the same
project pattern as migrate_add_wantbetter.py.

Run once from any directory:  python migrate_add_todos.py
Safe to re-run: uses CREATE TABLE IF NOT EXISTS.
"""
import sqlite3
from pathlib import Path

DB_PATH = Path(__file__).parent.parent / 'astro.db'

SQL = """
CREATE TABLE IF NOT EXISTS Todos (
    TodoID        INTEGER PRIMARY KEY AUTOINCREMENT,
    Category      TEXT NOT NULL DEFAULT 'General',
    ItemText      TEXT NOT NULL,
    IsDone        INTEGER NOT NULL DEFAULT 0,
    SortOrder     INTEGER NOT NULL DEFAULT 0,
    CreatedDate   DATETIME DEFAULT CURRENT_TIMESTAMP,
    CompletedDate DATETIME
)
"""


def main():
    if not DB_PATH.exists():
        raise SystemExit(f"astro.db not found at {DB_PATH}")

    conn = sqlite3.connect(DB_PATH)
    existed_before = conn.execute(
        "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='Todos'"
    ).fetchone()[0]

    conn.execute(SQL)
    conn.commit()

    if existed_before:
        print("Todos table already existed — nothing changed.")
    else:
        print("Todos table created.")
    conn.close()


if __name__ == "__main__":
    main()
