"""
Migration: widen Todos.Notes from VARCHAR(40) to VARCHAR(160).

The To Do List UI's Notes field cap was raised from 40 to 160 characters
(public/todo/index.php NOTES_MAX_LEN, public/todo/api.php mb_substr calls).
This script widens the underlying Postgres column to match, so notes
longer than 40 chars aren't silently truncated by the database itself.

Safe to re-run: checks the column's current length first and does nothing
if it's already >= 160.

DRY RUN BY DEFAULT. With no flags this only prints the current column
size and what it would do -- it makes no changes. Re-run with --apply to
actually widen the column.

    python migrate_widen_todo_notes.py            # dry run (no DB changes)
    python migrate_widen_todo_notes.py --apply     # do it for real

Connection info is read from shared/db_config.json by default (same file
shared/db.php and todo_transform.py use). Override with --pg-host/--pg-port/
--pg-db/--pg-user/--pg-password/--config if running this from somewhere else.

Requires psycopg2:  pip install psycopg2-binary --break-system-packages
"""
import argparse
import json
import sys
from pathlib import Path

try:
    import psycopg2
    import psycopg2.extras
except ImportError:
    sys.exit(
        "psycopg2 is not installed. Run:\n"
        "    pip install psycopg2-binary --break-system-packages"
    )

DEFAULT_CONFIG = Path(__file__).resolve().parent.parent.parent / 'shared' / 'db_config.json'
NEW_LEN = 160


def load_config(path):
    with open(path, 'r') as f:
        return json.load(f)


def get_connection(args):
    if args.pg_host and args.pg_db and args.pg_user and args.pg_password:
        host, port, db, user, password = (
            args.pg_host, args.pg_port, args.pg_db, args.pg_user, args.pg_password
        )
    else:
        cfg_path = Path(args.config) if args.config else DEFAULT_CONFIG
        if not cfg_path.exists():
            sys.exit(
                f"Config file not found: {cfg_path}\n"
                "Pass --config <path to db_config.json>, or supply "
                "--pg-host/--pg-port/--pg-db/--pg-user/--pg-password directly."
            )
        cfg = load_config(cfg_path)
        if cfg.get('driver') != 'pgsql':
            sys.exit(
                f"{cfg_path} has driver={cfg.get('driver')!r}, expected 'pgsql'. "
                "This script targets the live Postgres Todos table."
            )
        host = cfg['pg_host']
        port = cfg.get('pg_port', 5432)
        db = cfg['pg_dbname']
        user = cfg['pg_user']
        password = cfg['pg_password']

    print(f"Connecting to postgres://{user}@{host}:{port}/{db} ...")
    return psycopg2.connect(host=host, port=port, dbname=db, user=user, password=password)


def current_notes_length(cur):
    cur.execute("""
        SELECT character_maximum_length FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'todos' AND column_name = 'notes'
    """)
    row = cur.fetchone()
    if row is None:
        return None  # column doesn't exist
    return row['character_maximum_length']


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--apply', action='store_true', help='Actually widen the column (default is dry run).')
    parser.add_argument('--config', help='Path to db_config.json (default: shared/db_config.json next to this repo).')
    parser.add_argument('--pg-host')
    parser.add_argument('--pg-port', default=5432, type=int)
    parser.add_argument('--pg-db')
    parser.add_argument('--pg-user')
    parser.add_argument('--pg-password')
    args = parser.parse_args()

    conn = get_connection(args)
    conn.autocommit = False

    try:
        with conn.cursor(cursor_factory=psycopg2.extras.RealDictCursor) as cur:
            current_len = current_notes_length(cur)

            if current_len is None:
                sys.exit("todos.notes column not found -- nothing to widen.")

            print(f"Current todos.notes length: VARCHAR({current_len})")

            if current_len >= NEW_LEN:
                print(f"Already >= {NEW_LEN} -- nothing to do.")
                return

            print(f"Plan: ALTER TABLE todos ALTER COLUMN notes TYPE VARCHAR({NEW_LEN})")

            if not args.apply:
                print("\nDry run only -- no changes made. Re-run with --apply to execute this.")
                return

            print("\nApplying...")
            cur.execute(f"ALTER TABLE todos ALTER COLUMN notes TYPE VARCHAR({NEW_LEN})")
            conn.commit()
            print(f"Done. todos.notes is now VARCHAR({NEW_LEN}). Committed.")

    except Exception:
        conn.rollback()
        print("\nError -- rolled back, no changes were made.", file=sys.stderr)
        raise
    finally:
        conn.close()


if __name__ == '__main__':
    main()
