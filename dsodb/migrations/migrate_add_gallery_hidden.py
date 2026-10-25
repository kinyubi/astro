"""
Migration: add GalleryImages.IsHidden (INTEGER NOT NULL DEFAULT 0).

Replaces deleting a gallery image in DSO Admin with hiding it. A hidden
image keeps its row (caption, palette, session dir, sort order) so Sync
Folder doesn't re-add it as new, and it can be un-hidden later. The public
gallery and the admin card list skip rows with IsHidden = 1.

Also normalises each DSO's featured image / sort order once, to match the
rule DSO Admin now enforces: exactly one featured image per DSO (if it has
any), with SortOrder 0, and the rest numbered 1..n in their existing order.

Safe to re-run: the column is only added if it's missing, and the
normalisation only changes rows that don't already match the rule.

DRY RUN BY DEFAULT. With no flags this only reports what it would do.
Re-run with -a/--apply to make the changes.

    python migrate_add_gallery_hidden.py            # dry run (no DB changes)
    python migrate_add_gallery_hidden.py --apply    # do it for real

Connection info is read from shared/db_config.json by default (same file
shared/db.php uses). Override with --pg-host/--pg-port/--pg-db/--pg-user/
--pg-password/--config if running this from somewhere else.

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


def has_ishidden(cur):
    cur.execute("""
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'galleryimages' AND column_name = 'ishidden'
    """)
    return cur.fetchone() is not None


def plan_normalisation(cur, hidden_col):
    """Return [(GalleryImageID, DSOKey, BaseName, old_sort, new_sort, old_feat, new_feat)] for rows that change."""
    hidden_expr = "COALESCE(ishidden, 0)" if hidden_col else "0"
    cur.execute(f"""
        SELECT galleryimageid AS id, dsokey, basename,
               COALESCE(sortorder, 0) AS sortorder, COALESCE(isfeature, 0) AS isfeature,
               {hidden_expr} AS ishidden
        FROM galleryimages
        ORDER BY dsokey, sortorder, galleryimageid
    """)
    by_dso = {}
    for r in cur.fetchall():
        by_dso.setdefault(r['dsokey'], []).append(r)

    changes = []
    for dso, rows in by_dso.items():
        visible = [r for r in rows if not r['ishidden']]
        hidden = [r for r in rows if r['ishidden']]
        featured = next((r for r in visible if r['isfeature']), visible[0] if visible else None)
        ordered = ([featured] if featured else []) + [r for r in visible if r is not featured]
        target = {}
        for i, r in enumerate(ordered):
            target[r['id']] = (i, 1 if r is featured else 0)
        for j, r in enumerate(hidden):
            target[r['id']] = (len(ordered) + j, 0)
        for r in rows:
            new_sort, new_feat = target[r['id']]
            if new_sort != r['sortorder'] or new_feat != r['isfeature']:
                changes.append((r['id'], dso, r['basename'], r['sortorder'], new_sort, r['isfeature'], new_feat))
    return changes


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
            col_exists = has_ishidden(cur)
            if col_exists:
                print("galleryimages.ishidden already exists -- column step skipped.")
            else:
                print("Plan: ALTER TABLE galleryimages ADD COLUMN ishidden INTEGER NOT NULL DEFAULT 0")

            changes = plan_normalisation(cur, col_exists)
            if changes:
                print(f"\nPlan: normalise featured image / sort order on {len(changes)} row(s):")
                for gid, dso, bn, os_, ns, of, nf in changes:
                    feat = f"  featured {of}->{nf}" if of != nf else ""
                    print(f"  {dso:<10} #{gid:<5} {bn:<50} sort {os_}->{ns}{feat}")
            else:
                print("\nFeatured image / sort order already normalised for every DSO.")

            if not args.apply:
                print("\nDry run only -- no changes made. Re-run with --apply to execute this.")
                return

            print("\nApplying...")
            if not col_exists:
                cur.execute("ALTER TABLE galleryimages ADD COLUMN ishidden INTEGER NOT NULL DEFAULT 0")
            for gid, _dso, _bn, _os, ns, _of, nf in changes:
                cur.execute(
                    "UPDATE galleryimages SET sortorder = %s, isfeature = %s WHERE galleryimageid = %s",
                    (ns, nf, gid),
                )
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
