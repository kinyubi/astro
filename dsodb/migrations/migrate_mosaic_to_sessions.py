"""
Migration: make "mosaic" a property of a session, not of a Project.

A mosaic is something you shoot in a particular session -- the same Project
folder can hold both mosaic and non-mosaic sessions side by side, e.g.

    ic1805_heart_nebula\\
        20250927_162x60s_S30              <- not a mosaic
        20251102_207x60s_mosaic_S30       <- mosaic

so the flag now comes from the session folder name instead of the Project
folder name. Following "the database follows the disk", both new columns
are computed by Postgres from the folder names (GENERATED ... STORED), so
they can never drift from what's on disk and nothing ever writes them:

  1. observations.ismosaic  -- 1 when ObservationFolder has a "mosaic" token
  2. galleryimages.ismosaic -- 1 when SessionDir has a "mosaic" token
  3. projects.ismosaic is dropped. It was computed from ProjectFolder, and
     the "_mosaic" suffix has since moved off the Project folders onto the
     session folders, so it read 0 for every Project. Views that select it
     (vw_galleryobjects) are recreated without it.
  4. Data fix: M31's session folder 20261006_404x30s_AW_S50P was renamed on
     disk to 20261006_404x30s_AW_mosaic_S50P; its GalleryImages.SessionDir
     is updated to match.

A "mosaic token" means "mosaic" as a whole _-separated part of the name
(20251102_207x60s_mosaic_S30, lights_mosaic_S50P), matched case-insensitively.

Safe to re-run: each step is skipped if it has already been done.

DRY RUN BY DEFAULT. With no flags this only reports what it would do.
Re-run with -a/--apply to make the changes.

    python migrate_mosaic_to_sessions.py            # dry run (no DB changes)
    python migrate_mosaic_to_sessions.py --apply    # do it for real

Connection info is read from shared/db_config.json by default (same file
shared/db.php uses). Override with -c/--config, or --pg-host/--pg-port/
--pg-db/--pg-user/--pg-password if running this from somewhere else.

Requires psycopg2:  pip install psycopg2-binary --break-system-packages
"""
import argparse
import json
import re
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

# Postgres regex for a whole "mosaic" token in a _-separated folder name.
MOSAIC_TOKEN = r'(^|_)mosaic(_|$)'

NEW_COLUMNS = [
    # (table, new column, source column)
    ('observations', 'ismosaic', 'observationfolder'),
    ('galleryimages', 'ismosaic', 'sessiondir'),
]

SESSION_RENAMES = [
    # (dsokey, old SessionDir, new SessionDir) -- folders renamed on disk
    ('M31', '20261006_404x30s_AW_S50P', '20261006_404x30s_AW_mosaic_S50P'),
]


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
                "Pass -c/--config <path to db_config.json>, or supply "
                "--pg-host/--pg-port/--pg-db/--pg-user/--pg-password directly."
            )
        cfg = load_config(cfg_path)
        if cfg.get('driver') != 'pgsql':
            sys.exit(f"{cfg_path} has driver={cfg.get('driver')!r}, expected 'pgsql'.")
        host = cfg['pg_host']
        port = cfg.get('pg_port', 5432)
        db = cfg['pg_dbname']
        user = cfg['pg_user']
        password = cfg['pg_password']

    print(f"Connecting to postgres://{user}@{host}:{port}/{db} ...")
    return psycopg2.connect(host=host, port=port, dbname=db, user=user, password=password)


def column_exists(cur, table, column):
    cur.execute("""
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = %s AND column_name = %s
    """, (table, column))
    return cur.fetchone() is not None


def generated_ddl(table, column, source):
    return (
        f"ALTER TABLE {table} ADD COLUMN {column} INTEGER "
        f"GENERATED ALWAYS AS (CASE WHEN COALESCE({source}, '') ~* '{MOSAIC_TOKEN}' "
        f"THEN 1 ELSE 0 END) STORED"
    )


def views_using_project_ismosaic(cur):
    """[(view name, current definition)] for every view that selects projects.ismosaic."""
    cur.execute("""
        SELECT DISTINCT v.relname AS name, pg_get_viewdef(v.oid, true) AS def
        FROM pg_depend d
        JOIN pg_rewrite r   ON r.oid = d.objid
        JOIN pg_class v     ON v.oid = r.ev_class
        JOIN pg_class t     ON t.oid = d.refobjid
        JOIN pg_namespace n ON n.oid = t.relnamespace
        JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = d.refobjsubid
        WHERE n.nspname = 'public' AND t.relname = 'projects'
          AND a.attname = 'ismosaic' AND v.relname <> 'projects'
        ORDER BY v.relname
    """)
    return [(r['name'], r['def']) for r in cur.fetchall()]


# One select-list line such as "    p.ismosaic," or "    p.ismosaic AS foo,".
_ISMOSAIC_LINE = re.compile(r'^[ \t]*\w+\.ismosaic(?:[ \t]+AS[ \t]+\w+)?,[ \t]*\n', re.M | re.I)


def strip_ismosaic(view_name, definition):
    new_def, n = _ISMOSAIC_LINE.subn('', definition)
    if n != 1:
        raise RuntimeError(
            f"Couldn't safely remove ismosaic from view {view_name} "
            f"(matched {n} select-list lines, expected 1). Nothing was changed. "
            f"Current definition:\n{definition}"
        )
    return new_def


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('-a', '--apply', action='store_true', help='Actually make the changes (default is dry run).')
    parser.add_argument('-c', '--config', help='Path to db_config.json (default: shared/db_config.json next to this repo).')
    parser.add_argument('-H', '--pg-host')
    parser.add_argument('-P', '--pg-port', default=5432, type=int)
    parser.add_argument('-d', '--pg-db')
    parser.add_argument('-u', '--pg-user')
    parser.add_argument('-p', '--pg-password')
    args = parser.parse_args()

    conn = get_connection(args)
    conn.autocommit = False
    steps = []          # (description, callable(cur))

    try:
        with conn.cursor(cursor_factory=psycopg2.extras.RealDictCursor) as cur:

            # ── 1-2. New computed columns ──────────────────────────────────
            for table, column, source in NEW_COLUMNS:
                if column_exists(cur, table, column):
                    print(f"{table}.{column} already exists -- skipped.")
                    continue
                ddl = generated_ddl(table, column, source)
                print(f"Plan: {ddl}")
                steps.append((f"add {table}.{column}", lambda c, ddl=ddl: c.execute(ddl)))

            # ── 3. Drop projects.ismosaic (and rebuild views that use it) ──
            if column_exists(cur, 'projects', 'ismosaic'):
                views = views_using_project_ismosaic(cur)
                rebuilt = [(name, strip_ismosaic(name, d)) for name, d in views]
                for name, _ in rebuilt:
                    print(f"Plan: recreate view {name} without projects.ismosaic")
                print("Plan: ALTER TABLE projects DROP COLUMN ismosaic")

                def drop_project_flag(c, rebuilt=rebuilt):
                    for name, _ in rebuilt:
                        c.execute(f"DROP VIEW {name}")
                    c.execute("ALTER TABLE projects DROP COLUMN ismosaic")
                    for name, new_def in rebuilt:
                        c.execute(f"CREATE VIEW {name} AS {new_def}")
                steps.append(("drop projects.ismosaic", drop_project_flag))
            else:
                print("projects.ismosaic already gone -- skipped.")

            # ── 4. Session folders renamed on disk ─────────────────────────
            for dso, old, new in SESSION_RENAMES:
                cur.execute(
                    "SELECT galleryimageid, basename FROM galleryimages WHERE dsokey = %s AND sessiondir = %s",
                    (dso, old),
                )
                rows = cur.fetchall()
                if not rows:
                    print(f"{dso}: no images with SessionDir {old!r} -- skipped.")
                    continue
                for r in rows:
                    print(f"Plan: {dso} #{r['galleryimageid']} {r['basename']}: SessionDir {old} -> {new}")
                steps.append((
                    f"{dso} SessionDir {old} -> {new}",
                    lambda c, dso=dso, old=old, new=new: c.execute(
                        "UPDATE galleryimages SET sessiondir = %s WHERE dsokey = %s AND sessiondir = %s",
                        (new, dso, old),
                    ),
                ))

            if not steps:
                print("\nNothing to do -- already migrated.")
                return

            if not args.apply:
                print("\nDry run only -- no changes made. Re-run with --apply to execute this.")
                return

            print("\nApplying...")
            for desc, fn in steps:
                fn(cur)
                print(f"  done: {desc}")

            # Report what the new flags say, as a sanity check.
            cur.execute("SELECT observationfolder FROM observations WHERE ismosaic = 1 ORDER BY observationfolder")
            obs = [r['observationfolder'] for r in cur.fetchall()]
            cur.execute("SELECT dsokey, basename, sessiondir FROM galleryimages WHERE ismosaic = 1 ORDER BY dsokey, basename")
            imgs = cur.fetchall()
            print(f"\nMosaic sessions ({len(obs)}):")
            for o in obs:
                print(f"  {o}")
            print(f"Mosaic gallery images ({len(imgs)}):")
            for r in imgs:
                print(f"  {r['dsokey']:<10} {r['basename']:<50} {r['sessiondir']}")

            conn.commit()
            print("\nDone. Committed.")

    except Exception:
        conn.rollback()
        print("\nError -- rolled back, no changes were made.", file=sys.stderr)
        raise
    finally:
        conn.close()


if __name__ == '__main__':
    main()
