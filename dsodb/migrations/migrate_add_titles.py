"""
Migration: add the Titles table and the GalleryImageTitles view.

Titles holds custom caption titles (they replace titling.py's name.txt
files). A title applies to a whole DSO, to one session folder of it, or
to one image, depending on which of SessionDir / BaseName are filled in
('' means "any"):

    DSOKey  SessionDir              BaseName                     Title
    M42     ''                      ''                           Great Orion Nebula
    M42     20261105_439x20s_S50P   ''                           Orion Nebula and Running Man
    M42     ''                      m42_orion_nebula_261105_RR   Orion Nebula in H-alpha

The most specific one wins: the image's own title, then its session's,
then the DSO's; with none, the caption uses the DSO's common name.
Matching ignores letter case. Rows go away with their DSO (ON DELETE
CASCADE) and follow a DSOKey rename (ON UPDATE CASCADE).

GalleryImageTitles gives every GalleryImages row its title by that rule
(GalleryImageID, Title, TitleScope = 'image' | 'session' | 'dso' | NULL),
so the website (slideshow/data.php) and the Python captions (captions.py:
Pixstar frame, make_wall -t) can't disagree. Managed with titles.py.

Safe to re-run: the table is only created if it's missing; the view is
always (re)created.

DRY RUN BY DEFAULT. With no flags this only reports what it would do.
Re-run with -a/--apply to make the change.

    python migrate_add_titles.py            # dry run (no DB changes)
    python migrate_add_titles.py --apply    # do it for real

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

CREATE_TABLE_SQL = """
CREATE TABLE titles (
    titleid     SERIAL PRIMARY KEY,
    dsokey      TEXT NOT NULL
                REFERENCES objects (dsokey) ON DELETE CASCADE ON UPDATE CASCADE,
    sessiondir  TEXT NOT NULL DEFAULT '',   -- '' = every session
    basename    TEXT NOT NULL DEFAULT '',   -- '' = every image (of the session)
    title       TEXT NOT NULL CHECK (btrim(title) <> ''),
    updatedat   TIMESTAMPTZ NOT NULL DEFAULT now()
)
"""

# One title per scope, whatever the letter case.
CREATE_INDEX_SQL = """
CREATE UNIQUE INDEX titles_scope_uq
    ON titles (lower(dsokey), lower(sessiondir), lower(basename))
"""

VIEW_SQL = """
CREATE OR REPLACE VIEW galleryimagetitles AS
SELECT gi.galleryimageid,
       COALESCE(ti.title, ts.title, td.title) AS title,
       CASE WHEN ti.title IS NOT NULL THEN 'image'
            WHEN ts.title IS NOT NULL THEN 'session'
            WHEN td.title IS NOT NULL THEN 'dso'
       END AS titlescope
FROM galleryimages gi
LEFT JOIN titles ti
       ON ti.basename <> ''
      AND lower(ti.basename) = lower(gi.basename)
      AND lower(ti.dsokey) = lower(gi.dsokey)
LEFT JOIN titles ts
       ON ts.basename = '' AND ts.sessiondir <> ''
      AND lower(ts.sessiondir) = lower(COALESCE(gi.sessiondir, ''))
      AND lower(ts.dsokey) = lower(gi.dsokey)
LEFT JOIN titles td
       ON td.basename = '' AND td.sessiondir = ''
      AND lower(td.dsokey) = lower(gi.dsokey)
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
            for t in ('objects', 'galleryimages'):
                if not table_exists(cur, t):
                    sys.exit(f"No {t} table in this database -- wrong database?")
            make_table = not table_exists(cur, 'titles')
            if make_table:
                print("Plan: CREATE TABLE titles (custom caption titles: per DSO, session or image)")
            else:
                print("titles already exists -- left as it is.")
            print("Plan: CREATE OR REPLACE VIEW galleryimagetitles (each gallery image's title)")
            if not args.apply:
                print("\nDry run only -- no changes made. Re-run with --apply to execute this.")
                return
            if make_table:
                cur.execute(CREATE_TABLE_SQL)
                cur.execute(CREATE_INDEX_SQL)
            cur.execute(VIEW_SQL)
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
