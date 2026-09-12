#!/usr/bin/env python3
"""
fix_todo_categories.py

One-time cleanup: renames two legacy Todo category values to match the
new fixed dropdown in public/todo/index.php:
    'PreProcess' -> 'Preprocess'
    'Processing' -> 'Process'

Safe to re-run -- if nothing matches, it just reports 0 rows updated.

Usage:
    python fix_todo_categories.py            # dry run (default) -- shows counts
    python fix_todo_categories.py --apply    # actually commit the renames
"""
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from db_connect import get_connection  # noqa: E402

RENAMES = [
    ('PreProcess', 'Preprocess'),
    ('Processing', 'Process'),
]


def main():
    apply_changes = '--apply' in sys.argv

    conn = get_connection()
    cur = conn.cursor()

    any_matches = False
    for old, new in RENAMES:
        cur.execute('SELECT COUNT(*) AS "n" FROM Todos WHERE Category = ?', (old,))
        n = cur.fetchone()['n']
        print(f"{old!r:15s} -> {new!r:12s}  {n} row(s)")
        if n:
            any_matches = True

    if not any_matches:
        print("\nNothing to update.")
        conn.close()
        return

    if not apply_changes:
        print("\nDry run only -- no DB changes made. Re-run with --apply to execute.")
        conn.close()
        return

    for old, new in RENAMES:
        cur.execute('UPDATE Todos SET Category = ? WHERE Category = ?', (new, old))
    conn.commit()
    print("\nDone.")
    conn.close()


if __name__ == '__main__':
    main()
