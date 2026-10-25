"""
Migration: rewrite the Todos table for the new To Do List UI.

The old To Do List stored one free-form line per item in ItemText, of the
form:

    <Common Name> (<DSO Key>) <Equipment>

e.g.  "Heart Nebula (IC1805) S30"   or   "NGC 6910 (NGC6910) S50P"

The new UI splits that into real fields. This script:

  1. Adds DSOKey / CommonName / Equipment / SessionDate columns to Todos
     (all nullable TEXT -- SessionDate is a plain free-text field, not a
     real date, per how the UI asks for it).
  2. Parses every existing row's ItemText with the pattern above and
     backfills DSOKey / CommonName / Equipment.
       - Rows that match the "Name (KEY) EQUIP" shape: split cleanly.
       - Rows where the trailing token isn't a recognized equipment code
         (S30/S50/S50P) are still split into name+key; the unrecognized
         trailing text is folded back into CommonName so nothing is lost.
       - Rows with no "(...)" at all: the entire ItemText goes into
         CommonName verbatim; DSOKey/Equipment are left blank for you to
         fill in by hand.
  3. Renames Category -> Status (same values, same meaning -- just the
     new column name the rewritten UI expects).
  4. Drops the now-unused ItemText column.

Safe to re-run: every step checks current state first (idempotent).

DRY RUN BY DEFAULT. With no flags this only prints what it would do and
a full parse report -- it makes no changes. Review the report, then run
again with --apply to actually migrate.

    python todo_transform.py                # dry run (no DB changes)
    python todo_transform.py --apply         # do it for real

Connection info is read from shared/db_config.json by default (same file
shared/db.php uses). Override with --pg-host/--pg-port/--pg-db/--pg-user/
--pg-password/--config if you're running this from somewhere else.

NOTE: public/todo_old/ (the preserved copy of the old UI) queries the old
Category/ItemText columns directly. Once this script's --apply step runs,
that old copy will no longer work against the live database -- it's kept
purely as a static reference of the old code, not as a working fallback.

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

EQUIPMENT_CODES = {'S30', 'S50', 'S50P'}

# "<Common Name> (<DSO Key>) <trailing text, maybe Equipment>"
PATTERN = re.compile(r'^(?P<name>.*?)\s*\(\s*(?P<key>[^()]+?)\s*\)\s*(?P<rest>.*)$')


def parse_item_text(text):
    """
    Returns (commonname, dsokey, equipment, matched) for one old ItemText
    value. matched is False only when there was no "(...)" to split on at
    all, in which case commonname==text and dsokey/equipment are None.
    """
    text = (text or '').strip()
    m = PATTERN.match(text)
    if not m:
        return text, None, None, False

    name = m.group('name').strip()
    key = m.group('key').strip()
    rest = m.group('rest').strip()

    if not rest:
        return name, key, None, True

    token = rest.upper()
    if token in EQUIPMENT_CODES:
        return name, key, token, True

    # Trailing text present but not a recognized equipment code -- keep it,
    # fold it back into the name rather than silently dropping it.
    combined_name = f"{name} {rest}".strip()
    return combined_name, key, None, True


def load_config(path):
    with open(path, 'r') as f:
        cfg = json.load(f)
    return cfg


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


def existing_columns(cur):
    cur.execute("""
        SELECT column_name FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'todos'
    """)
    return {r['column_name'] for r in cur.fetchall()}


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--apply', action='store_true', help='Actually perform the migration (default is dry run).')
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
            cols = existing_columns(cur)

            has_itemtext = 'itemtext' in cols
            has_category = 'category' in cols
            has_status = 'status' in cols
            already_added = {'dsokey', 'commonname', 'equipment', 'sessiondate'} <= cols
            already_dropped = not has_itemtext

            if already_dropped and already_added and has_status:
                print("Todos already migrated (no ItemText/Category columns, new columns present). Nothing to do.")
                return

            # Pull current rows to parse & report on.
            if has_itemtext:
                cur.execute('SELECT todoid, itemtext, dsokey, commonname, equipment FROM todos ORDER BY todoid')
                rows = cur.fetchall()
            else:
                rows = []

            parsed = []
            n_full = n_partial = n_fallback = 0
            for row in rows:
                name, key, equip, matched = parse_item_text(row['itemtext'])
                if not matched:
                    n_fallback += 1
                elif equip:
                    n_full += 1
                else:
                    n_partial += 1
                parsed.append((row['todoid'], row['itemtext'], name, key, equip))

            print(f"\n{len(rows)} existing row(s) with ItemText to migrate:")
            print(f"  fully parsed (name + key + equipment): {n_full}")
            print(f"  partially parsed (name + key only):    {n_partial}")
            print(f"  no '(...)' found -- whole text kept as CommonName: {n_fallback}")

            print(f"\n{'TodoID':>6}  {'old ItemText':<45} -> DSOKey / CommonName / Equipment")
            print('-' * 100)
            for todoid, old_text, name, key, equip in parsed:
                shown = old_text if len(old_text) <= 45 else old_text[:42] + '...'
                print(f"{todoid:>6}  {shown:<45} -> {key or '-':<12} {name:<30} {equip or '-'}")

            print()
            print(f"Column plan:")
            print(f"  ADD COLUMN dsokey, commonname, equipment, sessiondate" +
                  (" (already present, skipped)" if already_added else ""))
            print(f"  RENAME COLUMN category -> status" +
                  (" (already renamed, skipped)" if has_status else (" (Category column not found!)" if not has_category else "")))
            print(f"  DROP COLUMN itemtext" +
                  (" (already dropped, skipped)" if not has_itemtext else ""))

            if not args.apply:
                print("\nDry run only -- no changes made. Re-run with --apply to execute this.")
                return

            print("\nApplying...")

            if not already_added:
                cur.execute("""
                    ALTER TABLE todos
                        ADD COLUMN IF NOT EXISTS dsokey TEXT,
                        ADD COLUMN IF NOT EXISTS commonname TEXT,
                        ADD COLUMN IF NOT EXISTS equipment TEXT,
                        ADD COLUMN IF NOT EXISTS sessiondate TEXT
                """)
                print("  columns added.")

            if has_itemtext:
                psycopg2.extras.execute_batch(
                    cur,
                    "UPDATE todos SET dsokey = %s, commonname = %s, equipment = %s WHERE todoid = %s",
                    [(key, name, equip, todoid) for todoid, _, name, key, equip in parsed]
                )
                print(f"  backfilled {len(parsed)} row(s).")

            if has_category and not has_status:
                cur.execute("ALTER TABLE todos RENAME COLUMN category TO status")
                print("  category -> status renamed.")

            if has_itemtext:
                cur.execute("ALTER TABLE todos DROP COLUMN itemtext")
                print("  itemtext column dropped.")

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
