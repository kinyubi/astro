"""
Migration: add the VisibilityWindows and PlanetVisibilityWindows tables and
the vw_VisibilityEndingSoon view.

VisibilityWindows holds one row per DSO, visibility profile and run of
consecutive visible nights: the first and last night (the local evening
date) the DSO meets the profile. Filled by pythonscripts/visibility_windows.py.
Rows go away with their DSO (ON DELETE CASCADE) and follow a DSOKey rename
(ON UPDATE CASCADE).

PlanetVisibilityWindows is the same for the planets (Mercury to Neptune),
keyed by planet name instead of DSOKey -- planets aren't in Objects.

StartsBeforeRange / RunsPastRange mark a window that was cut off by the edge
of the dates calculated, so its real first or last night is earlier or later
than the one stored.

vw_VisibilityEndingSoon lists the windows that are open tonight (America/
Boise date), with the nights left, when the DSO's next window opens, and
WantBetter / Project count to help pick what's most urgent. Sort it
yourself, e.g.

    SELECT * FROM vw_VisibilityEndingSoon
    WHERE profile = 'default' ORDER BY nightsleft, dsokey;

Safe to re-run: each table and the view are only created if missing, so
running it again after the first version (no planet table) adds just that.

Shows the plan and asks before making any change. -a/--apply makes it
without asking; --what-if only reports.

    python migrate_add_visibility_windows.py             # show plan, ask
    python migrate_add_visibility_windows.py -a          # just do it
    python migrate_add_visibility_windows.py --what-if   # report only

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

CREATE_TABLE = """
CREATE TABLE visibilitywindows (
    dsokey            TEXT NOT NULL
                      REFERENCES objects (dsokey) ON DELETE CASCADE ON UPDATE CASCADE,
    profile           TEXT NOT NULL,      -- profile name, e.g. 'default', 'backyard35'
    firstnight        DATE NOT NULL,      -- local evening date of the first visible night
    lastnight         DATE NOT NULL,      -- local evening date of the last visible night
    nights            INTEGER NOT NULL,   -- lastnight - firstnight + 1
    startsbeforerange BOOLEAN NOT NULL DEFAULT FALSE,  -- visible on the first night calculated
    runspastrange     BOOLEAN NOT NULL DEFAULT FALSE,  -- visible on the last night calculated
    rangefrom         DATE NOT NULL,      -- first night calculated in the run that wrote this row
    rangeto           DATE NOT NULL,      -- last night calculated
    computedat        TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (dsokey, profile, firstnight),
    CHECK (lastnight >= firstnight)
)
"""
CREATE_PLANET_TABLE = """
CREATE TABLE planetvisibilitywindows (
    planet            TEXT NOT NULL,      -- 'Mercury' .. 'Neptune'
    profile           TEXT NOT NULL,
    firstnight        DATE NOT NULL,
    lastnight         DATE NOT NULL,
    nights            INTEGER NOT NULL,
    startsbeforerange BOOLEAN NOT NULL DEFAULT FALSE,
    runspastrange     BOOLEAN NOT NULL DEFAULT FALSE,
    rangefrom         DATE NOT NULL,
    rangeto           DATE NOT NULL,
    computedat        TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (planet, profile, firstnight),
    CHECK (lastnight >= firstnight)
)
"""

CREATE_INDEX = "CREATE INDEX idx_visibilitywindows_profile_last ON visibilitywindows (profile, lastnight)"

CREATE_VIEW = """
CREATE VIEW vw_visibilityendingsoon AS
SELECT v.profile,
       o.dsokey,
       o.commonname,
       v.firstnight,
       v.lastnight,
       v.lastnight - t.tonight + 1                    AS nightsleft,
       v.runspastrange,
       nx.firstnight                                  AS nextopens,
       o.wantbetter,
       (SELECT count(*) FROM projects p WHERE p.dsokey = o.dsokey) AS projects
FROM visibilitywindows v
JOIN objects o ON o.dsokey = v.dsokey
CROSS JOIN (SELECT (now() AT TIME ZONE 'America/Boise')::date AS tonight) t
LEFT JOIN LATERAL (
    SELECT min(n.firstnight) AS firstnight
    FROM visibilitywindows n
    WHERE n.dsokey = v.dsokey AND n.profile = v.profile AND n.firstnight > v.lastnight
) nx ON TRUE
WHERE t.tonight BETWEEN v.firstnight AND v.lastnight
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


def relation_exists(cur, name):
    cur.execute("SELECT to_regclass(%s) IS NOT NULL", ('public.' + name,))
    return cur.fetchone()[0]


def ask(question):
    try:
        return input(question + ' [y/N] ').strip().lower() in ('y', 'yes')
    except EOFError:
        return False


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('-a', '--apply', action='store_true', help='Make the change without asking.')
    parser.add_argument('--what-if', action='store_true', help='Only report what would be done.')
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
            if not relation_exists(cur, 'objects') or not relation_exists(cur, 'projects'):
                sys.exit("No objects/projects tables in this database -- wrong database?")
            steps = []
            if not relation_exists(cur, 'visibilitywindows'):
                steps.append(("Create table VisibilityWindows (one row per DSO, profile and visibility window)",
                              [CREATE_TABLE, CREATE_INDEX]))
            if not relation_exists(cur, 'planetvisibilitywindows'):
                steps.append(("Create table PlanetVisibilityWindows (the same for the planets, Mercury to Neptune)",
                              [CREATE_PLANET_TABLE]))
            if not relation_exists(cur, 'vw_visibilityendingsoon'):
                steps.append(("Create view vw_VisibilityEndingSoon (windows open tonight, with nights left)",
                              [CREATE_VIEW]))
            if not steps:
                print("VisibilityWindows, PlanetVisibilityWindows and vw_VisibilityEndingSoon already exist -- nothing to do.")
                return
            print("Plan:")
            for label, _ in steps:
                print(f"  - {label}")
            if args.what_if:
                print("\nReport only -- no changes made.")
                return
            if not args.apply and not ask("\nApply?"):
                print("No changes made.")
                return
            for label, sqls in steps:
                for sql in sqls:
                    cur.execute(sql)
            conn.commit()
            for label, _ in steps:
                print(f"Done: {label}")
    except Exception:
        conn.rollback()
        print("\nError -- rolled back, no changes were made.", file=sys.stderr)
        raise
    finally:
        conn.close()


if __name__ == '__main__':
    main()
