"""
Migration: add Projects.GalleryRank (INTEGER, NULL allowed).

The public gallery (Browse Gallery) shows one card per Project. GalleryRank
is the hand-picked position of that card, set by dragging cards around on
the /gallery-order/ page. Lower = earlier. NULL = not ranked yet; unranked
cards come after all ranked ones, in the old alphabetical order -- so right
after this migration the gallery looks exactly as it did before.

Safe to re-run: the column is only added if it's missing.

DRY RUN BY DEFAULT. With no flags this only reports what it would do.
Re-run with -a/--apply to make the change.

    python migrate_add_project_gallery_rank.py            # dry run (no DB changes)
    python migrate_add_project_gallery_rank.py --apply    # do it for real

Connection info is read from shared/db_config.json by default (same file
shared/db.php uses). Override with -c/--config, or --pg-host/--pg-port/
--pg-db/--pg-user/--pg-password if running this from somewhere else.

Requires psycopg2:  pip install psycopg2-binary --break-system-packages
"""
import argparse
import json
import sys
from pathlib import Path

try:
    import psycopg2
except ImportError:
    sys.exit(
        "psycopg2 is not installed. Run:\n"
        "    pip install psycopg2-binary --break-system-packages"
    )

DEFAULT_CONFIG = Path(__file__).resolve().parent.parent.parent / 'shared' / 'db_config.json'


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
                "Pass -c/--config <path to db_config.json>, or supply "
                "--pg-host/--pg-port/--pg-db/--pg-user/--pg-password directly."
            )
        with open(cfg_path, 'r') as f:
            cfg = json.load(f)
        if cfg.get('driver') != 'pgsql':
            sys.exit(
                f"{cfg_path} has driver={cfg.get('driver')!r}, expected 'pgsql'. "
                "This script targets the live Postgres Projects table."
            )
        host = cfg['pg_host']
        port = cfg.get('pg_port', 5432)
        db = cfg['pg_dbname']
        user = cfg['pg_user']
        password = cfg['pg_password']

    print(f"Connecting to postgres://{user}@{host}:{port}/{db} ...")
    return psycopg2.connect(host=host, port=port, dbname=db, user=user, password=password)


def has_column(cur):
    cur.execute("""
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'projects' AND column_name = 'galleryrank'
    """)
    return cur.fetchone() is not None


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('-a', '--apply', action='store_true', help='Actually make the change (default is dry run).')
    parser.add_argument('-c', '--config', help='Path to db_config.json (default: shared/db_config.json next to this repo).')
    parser.add_argument('-H', '--pg-host')
    parser.add_argument('-P', '--pg-port', default=5432, type=int)
    parser.add_argument('-d', '--pg-db')
    parser.add_argument('-u', '--pg-user')
    parser.add_argument('-p', '--pg-password')
    args = parser.parse_args()

    conn = get_connection(args)
    conn.autocommit = False
    try:
        with conn.cursor() as cur:
            if has_column(cur):
                print("projects.galleryrank already exists -- nothing to do.")
                return
            print("Plan: ALTER TABLE projects ADD COLUMN galleryrank INTEGER")
            if not args.apply:
                print("\nDry run only -- no changes made. Re-run with --apply to execute this.")
                return
            cur.execute("ALTER TABLE projects ADD COLUMN galleryrank INTEGER")
            conn.commit()
            print("Done. Committed.")
    except Exception:
        conn.rollback()
        print("\nError -- rolled back, no changes were made.", file=sys.stderr)
        raise
    finally:
        conn.close()


if __name__ == '__main__':
    main()
