"""
Migration: one GalleryImages row per image name per DSO.

Adds a unique index on GalleryImages (DSOKey, lower(BaseName)) so the
database refuses a second row with the same Base Name for a DSO (case-
insensitive, matching how Windows file names and Sync Folder treat them).
Before Oct 2026 nothing prevented that, and Sync Folder couldn't see such
duplicates (e.g. NGC6992 had two 'ngc6992_eastern_veil_S30' rows).

Steps:
  1. Find every DSOKey + BaseName (case-insensitive) with more than one row.
  2. For each group keep one row -- the visible featured one if any, else
     the oldest (lowest GalleryImageID) -- and delete the others.
  3. Re-normalise featured image / SortOrder for the affected DSOs (same
     rule as DSO Admin: one visible featured image at SortOrder 0, others
     1..n, hidden ones after).
  4. CREATE UNIQUE INDEX ux_galleryimages_dso_basename
         ON galleryimages (dsokey, lower(basename)).

Safe to re-run: with no duplicates and the index present it does nothing.

Requires the IsHidden column (run migrate_add_gallery_hidden.py first).

DRY RUN BY DEFAULT. With no flags this only lists what it would do.
Re-run with -a/--apply to make the changes.

    python migrate_unique_gallery_basename.py            # dry run
    python migrate_unique_gallery_basename.py --apply    # do it for real

Connection info is read from shared/db_config.json by default. Override with
-c/--config or --pg-host/--pg-port/--pg-db/--pg-user/--pg-password.

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
INDEX_NAME = 'ux_galleryimages_dso_basename'


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
            sys.exit(
                f"{cfg_path} has driver={cfg.get('driver')!r}, expected 'pgsql'. "
                "This script targets the live Postgres GalleryImages table."
            )
        host = cfg['pg_host']
        port = cfg.get('pg_port', 5432)
        db = cfg['pg_dbname']
        user = cfg['pg_user']
        password = cfg['pg_password']

    print(f"Connecting to postgres://{user}@{host}:{port}/{db} ...")
    return psycopg2.connect(host=host, port=port, dbname=db, user=user, password=password)


def index_exists(cur):
    cur.execute("SELECT 1 FROM pg_indexes WHERE schemaname = 'public' AND indexname = %s", (INDEX_NAME,))
    return cur.fetchone() is not None


def has_ishidden(cur):
    cur.execute("""
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'galleryimages' AND column_name = 'ishidden'
    """)
    return cur.fetchone() is not None


def find_duplicates(cur):
    """Return [(dsokey, keep_row, [delete_rows])] for every duplicated name."""
    cur.execute("""
        SELECT galleryimageid AS id, dsokey, basename,
               COALESCE(isfeature, 0) AS isfeature, COALESCE(ishidden, 0) AS ishidden,
               caption
        FROM galleryimages
        WHERE (dsokey, lower(basename)) IN (
            SELECT dsokey, lower(basename) FROM galleryimages
            GROUP BY dsokey, lower(basename) HAVING COUNT(*) > 1
        )
        ORDER BY dsokey, lower(basename), galleryimageid
    """)
    groups = {}
    for r in cur.fetchall():
        groups.setdefault((r['dsokey'], r['basename'].lower()), []).append(r)
    out = []
    for (dso, _), rows in groups.items():
        rows.sort(key=lambda r: (0 if (r['isfeature'] and not r['ishidden']) else 1, r['id']))
        out.append((dso, rows[0], rows[1:]))
    return out


def normalise(cur, dso):
    """Same rule as gallery_helpers.php::gallery_normalize()."""
    cur.execute("""
        SELECT galleryimageid AS id, COALESCE(sortorder, 0) AS sortorder,
               COALESCE(isfeature, 0) AS isfeature, COALESCE(ishidden, 0) AS ishidden
        FROM galleryimages WHERE dsokey = %s ORDER BY sortorder, galleryimageid
    """, (dso,))
    rows = cur.fetchall()
    visible = [r for r in rows if not r['ishidden']]
    hidden = [r for r in rows if r['ishidden']]
    featured = next((r for r in visible if r['isfeature']), visible[0] if visible else None)
    ordered = ([featured] if featured else []) + [r for r in visible if r is not featured] + hidden
    for i, r in enumerate(ordered):
        feat = 1 if r is featured else 0
        if r['sortorder'] != i or r['isfeature'] != feat:
            cur.execute("UPDATE galleryimages SET sortorder = %s, isfeature = %s WHERE galleryimageid = %s",
                        (i, feat, r['id']))


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('-a', '--apply', action='store_true', help='Actually make the changes (default is dry run).')
    parser.add_argument('-c', '--config', help='Path to db_config.json (default: shared/db_config.json next to this repo).')
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
            if not has_ishidden(cur):
                sys.exit("galleryimages.ishidden not found -- run migrate_add_gallery_hidden.py --apply first.")

            dups = find_duplicates(cur)
            if dups:
                print(f"Duplicate gallery rows ({sum(len(d) for _, _, d in dups)} to delete):")
                for dso, keep, delete in dups:
                    print(f"  {dso:<10} {keep['basename']}")
                    print(f"      keep   #{keep['id']}" + ("  (featured)" if keep['isfeature'] else "")
                          + (f"  caption: {keep['caption']!r}" if keep['caption'] else ""))
                    for r in delete:
                        print(f"      delete #{r['id']}" + (f"  caption: {r['caption']!r}" if r['caption'] else ""))
            else:
                print("No duplicate gallery rows.")

            idx = index_exists(cur)
            if idx:
                print(f"\nIndex {INDEX_NAME} already exists.")
            else:
                print(f"\nPlan: CREATE UNIQUE INDEX {INDEX_NAME} ON galleryimages (dsokey, lower(basename))")

            if not dups and idx:
                print("\nNothing to do.")
                return

            if not args.apply:
                print("\nDry run only -- no changes made. Re-run with --apply to execute this.")
                return

            print("\nApplying...")
            for dso, _keep, delete in dups:
                for r in delete:
                    cur.execute("DELETE FROM galleryimages WHERE galleryimageid = %s", (r['id'],))
                normalise(cur, dso)
            if not idx:
                cur.execute(f"CREATE UNIQUE INDEX {INDEX_NAME} ON galleryimages (dsokey, lower(basename))")
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
