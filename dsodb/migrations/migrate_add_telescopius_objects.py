"""
Migration: add the TelescopiusObjects table (one row per DSO).

Holds what Telescopius knows about each DSO -- its names, every catalog ID,
types, position, magnitude, surface brightness, size, popularity, links and
the full raw answer -- next to (not inside) the hand-curated Objects row.
Filled by scripts/telescopius_enrich.php. Rows go away with their DSO
(ON DELETE CASCADE) and follow a DSOKey rename (ON UPDATE CASCADE).

MatchStatus is 'matched' or 'not_found'. Not-found rows are kept so the
enrich script doesn't spend Telescopius calls on them every run.

Safe to re-run: the table is only created if it's missing.

DRY RUN BY DEFAULT. With no flags this only reports what it would do.
Re-run with -a/--apply to make the change.

    python migrate_add_telescopius_objects.py            # dry run (no DB changes)
    python migrate_add_telescopius_objects.py --apply    # do it for real

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

CREATE_SQL = """
CREATE TABLE telescopiusobjects (
    dsokey            TEXT PRIMARY KEY
                      REFERENCES objects (dsokey) ON DELETE CASCADE ON UPDATE CASCADE,
    matchstatus       TEXT NOT NULL CHECK (matchstatus IN ('matched', 'not_found')),
    matchedby         TEXT,             -- the name that found it, e.g. 'M 33' or 'NGC 598'
    telescopiusid     TEXT,             -- Telescopius main_id, e.g. 'M 33'
    mainname          TEXT,             -- e.g. 'Triangulum Galaxy'
    names             JSONB,            -- all common names
    allids            JSONB,            -- ids + alt_ids
    types             JSONB,            -- e.g. ["sgx", "gxy", ...]
    morphclass        TEXT,
    rahours           DOUBLE PRECISION,
    decdegrees        DOUBLE PRECISION,
    constellation     TEXT,             -- IAU abbreviation, e.g. 'TRI'
    visualmag         REAL,
    surfacebrightness REAL,             -- Telescopius 'subr'
    majorarcsec       REAL,
    minorarcsec       REAL,
    positionangle     REAL,
    popularity        INTEGER,
    url               TEXT,
    thumbnailurl      TEXT,
    imageurl          TEXT,
    raw               JSONB,            -- the whole 'object' block as Telescopius sent it
    fetchedat         TIMESTAMPTZ NOT NULL DEFAULT now()
)
"""


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
                "This script targets the live Postgres database."
            )
        host = cfg['pg_host']
        port = cfg.get('pg_port', 5432)
        db = cfg['pg_dbname']
        user = cfg['pg_user']
        password = cfg['pg_password']

    print(f"Connecting to postgres://{user}@{host}:{port}/{db} ...")
    return psycopg2.connect(host=host, port=port, dbname=db, user=user, password=password)


def table_exists(cur, name):
    cur.execute("""
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = 'public' AND table_name = %s
    """, (name,))
    return cur.fetchone() is not None


def objects_has_dsokey_key(cur):
    """objects.dsokey must be the primary key for the foreign key to work."""
    cur.execute("""
        SELECT 1
        FROM information_schema.table_constraints tc
        JOIN information_schema.key_column_usage k
          ON k.constraint_name = tc.constraint_name AND k.table_schema = tc.table_schema
        WHERE tc.table_schema = 'public' AND tc.table_name = 'objects'
          AND tc.constraint_type IN ('PRIMARY KEY', 'UNIQUE') AND k.column_name = 'dsokey'
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
            if table_exists(cur, 'telescopiusobjects'):
                print("telescopiusobjects already exists -- nothing to do.")
                return
            if not table_exists(cur, 'objects'):
                sys.exit("No objects table in this database -- wrong database?")
            if not objects_has_dsokey_key(cur):
                sys.exit("objects.dsokey isn't a primary key here -- not creating the table. Check the schema first.")
            print("Plan: CREATE TABLE telescopiusobjects (one row per DSO, linked to objects.dsokey)")
            if not args.apply:
                print("\nDry run only -- no changes made. Re-run with --apply to execute this.")
                return
            cur.execute(CREATE_SQL)
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
