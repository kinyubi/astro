"""
Migration: Add Priority column to Todos.

Simple additive column (High/Medium/Low, default Medium). No FK/rebuild
needed since Todos has no foreign keys.

Run once from any directory:  python migrate_add_todo_priority.py
Safe to re-run: checks for the column first.
"""
import sqlite3
from pathlib import Path

DB_PATH = Path(__file__).parent.parent / 'astro.db'


def main():
    if not DB_PATH.exists():
        raise SystemExit(f"astro.db not found at {DB_PATH}")

    conn = sqlite3.connect(DB_PATH)
    cols = [r[1] for r in conn.execute("PRAGMA table_info(Todos)").fetchall()]

    if 'Priority' in cols:
        print("Priority column already exists — nothing changed.")
        conn.close()
        return

    conn.execute("ALTER TABLE Todos ADD COLUMN Priority TEXT NOT NULL DEFAULT 'Medium'")
    conn.commit()
    print("Priority column added to Todos (default 'Medium').")
    conn.close()


if __name__ == "__main__":
    main()
