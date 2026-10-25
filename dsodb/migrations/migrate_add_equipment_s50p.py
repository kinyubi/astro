"""
Migration: add the missing 'S50P' row to the Equipment lookup table.

Observations.EquipmentID has a foreign key to Equipment.EquipmentID
(RESTRICT on delete -- see migrate_sqlite_to_postgres.py's FK list), so any
insert/update that tags an observation as S50P fails with:

    insert or update on table "observations" violates foreign key
    constraint "fk_Observations_EquipmentID"
    DETAIL: Key (equipmentid)=(S50P) is not present in table "equipment".

S30 and S50 rows exist from the original migration, but nothing ever
seeded an S50P row when Seestar S50 Pro support was added elsewhere in the
app (the ToDo list's Equipment dropdown, and the folder-name inference in
audit_observations.py / api_sync_folder.php, both already know about
S50P -- the lookup table just never got the matching row).

Only sets EquipmentID and EquipmentName (matching the display label used
elsewhere in the app -- see the equipment caption map in public/index.php
/ public/admin/index.php: {s50p: 'Seestar S50 Pro'}). The optical/sensor
spec columns (Manufacturer, Model, FocalLengthMM, ApertureMM,
PixelSizeMicrons, SensorWidthPx, SensorHeightPx, ArcSecsPerPixel) are left
NULL rather than guessed -- if anything in the app relies on those for the
S50 Pro (FOV calculations, etc.), copy them in by hand from the existing
S50 row's values (adjusted for whatever's actually different on the Pro:
sensor and dual-band filter, not aperture/focal length, as far as this
script assumes) once you've confirmed them.

Safe to re-run: does nothing if S50P already exists.

    python migrate_add_equipment_s50p.py            # dry run (no DB changes)
    python migrate_add_equipment_s50p.py --apply     # do it for real

Connection info is read from shared/db_config.json by default (same file
shared/db.php and the other migrations here use).

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

NEW_EQUIPMENT_ID = 'S50P'
NEW_EQUIPMENT_NAME = 'Seestar S50 Pro'


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
                "This script targets the live Postgres Equipment table."
            )
        host = cfg['pg_host']
        port = cfg.get('pg_port', 5432)
        db = cfg['pg_dbname']
        user = cfg['pg_user']
        password = cfg['pg_password']

    print(f"Connecting to postgres://{user}@{host}:{port}/{db} ...")
    return psycopg2.connect(host=host, port=port, dbname=db, user=user, password=password)


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--apply', action='store_true', help='Actually insert the row (default is dry run).')
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
            # NOTE: the live schema's actual table/column names are lowercase
            # and unquoted (confirmed by the FK error's own DETAIL text:
            # `table "equipment"`, `Key (equipmentid)=...` -- Postgres prints
            # a quoted mixed-case identifier with its real case preserved, so
            # lowercase in the error means the underlying names really are
            # lowercase). Every other working migration here (todo_transform.py,
            # migrate_widen_todo_notes.py) queries lowercase/unquoted too --
            # the PascalCase you see elsewhere (e.g. "EquipmentID" in the PHP
            # admin queries) is just an `AS "EquipmentID"` alias for the
            # *output* column name, not the real stored identifier.
            cur.execute('SELECT equipmentid, equipmentname FROM equipment WHERE equipmentid = %s', (NEW_EQUIPMENT_ID,))
            existing = cur.fetchone()

            if existing:
                print(f"'{NEW_EQUIPMENT_ID}' already exists in Equipment "
                      f"(EquipmentName={existing['equipmentname']!r}) -- nothing to do.")
                return

            print(f"Plan: INSERT INTO equipment (equipmentid, equipmentname) "
                  f"VALUES ('{NEW_EQUIPMENT_ID}', '{NEW_EQUIPMENT_NAME}')")

            if not args.apply:
                print("\nDry run only -- no changes made. Re-run with --apply to execute this.")
                return

            print("\nApplying...")
            cur.execute(
                'INSERT INTO equipment (equipmentid, equipmentname) VALUES (%s, %s)',
                (NEW_EQUIPMENT_ID, NEW_EQUIPMENT_NAME)
            )
            conn.commit()
            print(f"Done. '{NEW_EQUIPMENT_ID}' added to Equipment. Committed.")

    except Exception:
        conn.rollback()
        print("\nError -- rolled back, no changes were made.", file=sys.stderr)
        raise
    finally:
        conn.close()


if __name__ == '__main__':
    main()
