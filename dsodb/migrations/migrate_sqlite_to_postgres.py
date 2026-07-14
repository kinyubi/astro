"""
Migration: Copy astro.db (SQLite) schema + data into a PostgreSQL database.

This is a ONE-TIME (but safely re-runnable) migration that recreates every
table, sequence, index, generated column, and view from astro.db in
PostgreSQL, then copies all rows across. It does NOT modify astro.db.

Design decisions:
  - EVERY identifier (table and column name) is double-quoted in all DDL/DML,
    which forces Postgres to preserve the exact SQLite casing (e.g. "DSOKey",
    not dsokey). Postgres folds unquoted identifiers to lowercase, which
    would silently break every place in the PHP/Python code that reads a
    result row by exact key name (e.g. $row['DSOKey'], row['ProjectFolder']).
    This is the single most important detail in this script -- verified
    against a live test run (see migration notes).
  - AUTOINCREMENT primary keys become plain INTEGER + an explicit sequence
    (created after data load, seeded to MAX(id)+1) rather than
    GENERATED ALWAYS AS IDENTITY, so existing primary key values can be
    inserted as-is during the copy without fighting identity restrictions.
  - Foreign keys are added AFTER all data is loaded (not part of the initial
    CREATE TABLE), so table load order doesn't matter and the self-referencing
    Images.ParentImageID column is never a problem.
  - SQLite REAL -> Postgres DOUBLE PRECISION (matches SQLite's 8-byte float,
    avoids the precision loss of Postgres's 4-byte REAL).
  - 0/1 flag columns (IsPrimary, IsOwn, IsFeature, IsAnnotated, IsPublished,
    IsRetired, WantBetter, IsDone) stay INTEGER rather than becoming BOOLEAN,
    so existing PHP/Python code comparing against 0/1 keeps working unchanged.
  - Projects.IsMosaic is recreated as a Postgres GENERATED ALWAYS AS (...)
    STORED column (Postgres has no VIRTUAL generated columns), with an
    explicit CASE so it still evaluates to integer 0/1 rather than boolean.

Usage:
    pip install psycopg2-binary --break-system-packages   (one-time, if not installed)

    python migrate_sqlite_to_postgres.py \
        --pg-host YOUR_VPS_IP --pg-db astro --pg-user astro_app \
        --pg-password YOUR_PASSWORD --pg-sslmode require

    Add --reset to drop and recreate all tables/views first (safe to re-run
    while testing; do NOT use --reset against a Postgres db already in
    production use -- it destroys existing data in the target tables).

    Add --sqlite-path to point at a different astro.db (defaults to the
    astro.db next to this script's dsodb/ parent folder).

Safe to re-run: with --reset it fully rebuilds the target schema each time.
Without --reset, it refuses to run if any target table already has rows,
to avoid silently duplicating data.

Tested end-to-end against a scratch PostgreSQL 16 instance before being
handed off: all 19 tables, 4 views, 20 foreign keys, 8 sequences, and the
IsMosaic generated column migrated with zero row-count mismatches and zero
referential integrity violations against the live astro.db data.
"""
import argparse
import sqlite3
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

DEFAULT_SQLITE_PATH = Path(__file__).parent.parent / 'astro.db'


def q(identifier: str) -> str:
    """Double-quote an identifier so Postgres preserves its exact case."""
    return f'"{identifier}"'


def qcols(columns):
    return ", ".join(q(c) for c in columns)


# ── Table definitions ───────────────────────────────────────────────────
# Each entry: table name -> list of (column_name, postgres_type_and_constraints)
# in the exact order used for SELECT from SQLite / INSERT into Postgres.
# Column-level FOREIGN KEY / table PRIMARY KEY-by-reference clauses are NOT
# included here -- foreign keys are added later via FOREIGN_KEYS below, after
# all data is loaded. Only PRIMARY KEY (own-table, not referencing another
# table) appears inline.
TABLE_COLUMNS = {
    "Constellations": [
        ("ConstellationID", "TEXT PRIMARY KEY"),
        ("Name", "TEXT NOT NULL"),
        ("GenitiveName", "TEXT"),
        ("RightAscensionHours", "DOUBLE PRECISION"),
        ("DeclinationDegrees", "DOUBLE PRECISION"),
    ],
    "Equipment": [
        ("EquipmentID", "TEXT PRIMARY KEY"),
        ("EquipmentName", "TEXT NOT NULL"),
        ("Manufacturer", "TEXT"),
        ("Model", "TEXT"),
        ("EquipmentType", "TEXT"),
        ("FocalLengthMM", "INTEGER"),
        ("ApertureMM", "INTEGER"),
        ("PixelSizeMicrons", "DOUBLE PRECISION"),
        ("SensorWidthPx", "INTEGER"),
        ("SensorHeightPx", "INTEGER"),
        ("ArcSecsPerPixel", "DOUBLE PRECISION"),
        ("Notes", "TEXT"),
    ],
    "ObjectCategories": [
        ("CategoryID", "TEXT PRIMARY KEY"),
        ("CategoryName", "TEXT NOT NULL"),
        ("Description", "TEXT"),
    ],
    "ImageTypes": [
        ("ImageTypeID", "TEXT PRIMARY KEY"),
        ("Description", "TEXT"),
        ("DefaultWidth", "INTEGER"),
        ("DefaultHeight", "INTEGER"),
        ("AspectRatio", "TEXT"),
        ("WebFolder", "TEXT"),
    ],
    "PaletteTreatments": [
        ("PaletteID", "INTEGER PRIMARY KEY"),
        ("PaletteName", "TEXT NOT NULL"),
        ("Description", "TEXT"),
        ("ChannelMapping", "TEXT"),
    ],
    "SocialPlatforms": [
        ("PlatformID", "TEXT PRIMARY KEY"),
        ("PlatformName", "TEXT NOT NULL"),
        ("BaseURL", "TEXT"),
    ],
    "ObjectTypes": [
        ("ObjectTypeID", "TEXT PRIMARY KEY"),
        ("CategoryID", "TEXT NOT NULL"),
        ("TypeName", "TEXT NOT NULL"),
        ("Description", "TEXT"),
    ],
    "Objects": [
        ("DSOKey", "TEXT PRIMARY KEY"),
        ("CommonName", "TEXT"),
        ("ObjectTypeID", "TEXT"),
        ("ConstellationID", "TEXT"),
        ("RAHours", "DOUBLE PRECISION"),
        ("DecDegrees", "DOUBLE PRECISION"),
        ("Magnitude", "DOUBLE PRECISION"),
        ("ObjectSize", "TEXT"),
        ("DistanceLY", "TEXT"),
        ("SocialBlurb", "TEXT"),
        ("LastUpdated", "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"),
        ("WantBetter", "INTEGER NOT NULL DEFAULT 0"),
        ("SqArcMins", "DOUBLE PRECISION"),
        ("Notes", "TEXT"),
    ],
    "CatalogIDs": [
        ("CatalogID", "TEXT PRIMARY KEY"),
        ("DSOKey", "TEXT NOT NULL"),
        ("IsPrimary", "INTEGER DEFAULT 0"),
    ],
    "DSOLinks": [
        ("LinkID", "INTEGER PRIMARY KEY"),
        ("DSOKey", "TEXT NOT NULL"),
        ("Label", "TEXT NOT NULL"),
        ("URL", "TEXT NOT NULL"),
        ("SortOrder", "INTEGER DEFAULT 0"),
    ],
    "Projects": [
        ("ProjectID", "INTEGER PRIMARY KEY"),
        ("DSOKey", "TEXT NOT NULL"),
        ("ProjectFolder", "TEXT NOT NULL"),
        ("Notes", "TEXT"),
        # IsMosaic intentionally excluded here -- added as a generated
        # column after data load, see IS_MOSAIC_DDL below.
    ],
    "GalleryImages": [
        ("GalleryImageID", "INTEGER PRIMARY KEY"),
        ("DSOKey", "TEXT NOT NULL"),
        ("BaseName", "TEXT NOT NULL"),
        ("Caption", "TEXT"),
        ("PaletteID", "INTEGER DEFAULT 0"),
        ("DateCaptured", "TEXT"),
        ("Copyright", "TEXT"),
        ("IsOwn", "INTEGER DEFAULT 1"),
        ("Attribution", "TEXT"),
        ("SortOrder", "INTEGER DEFAULT 0"),
        ("IsFeature", "INTEGER DEFAULT 0"),
        ("Equipment", "TEXT"),
        ("SessionDir", "TEXT"),
        ("ProjectID", "INTEGER"),
    ],
    "Observations": [
        ("ObservationID", "INTEGER PRIMARY KEY"),
        ("ProjectID", "INTEGER NOT NULL"),
        ("EquipmentID", "TEXT NOT NULL"),
        ("ObservationDate", "DATE NOT NULL"),
        ("ObservationFolder", "TEXT"),
        ("StartTime", "TIMESTAMP"),
        ("EndTime", "TIMESTAMP"),
        ("ExposureTimeSecs", "DOUBLE PRECISION"),
        ("Filter", "TEXT"),
        ("TotalExposures", "INTEGER DEFAULT 0"),
        ("GoodLights", "INTEGER DEFAULT 0"),
        ("MosaicPanel", "TEXT"),
        ("SeeingConditions", "TEXT"),
        ("Temperature", "DOUBLE PRECISION"),
        ("Humidity", "DOUBLE PRECISION"),
        ("NinaWorkflowName", "TEXT"),
        ("Notes", "TEXT"),
        ("IntegrationMins", "DOUBLE PRECISION"),
    ],
    "ProcessingRuns": [
        ("ProcessingID", "INTEGER PRIMARY KEY"),
        ("ProjectID", "INTEGER NOT NULL"),
        ("ProcessingDateStart", "DATE"),
        ("ProcessingDateEnd", "DATE"),
        ("ProcessingSoftware", "TEXT"),
        ("WorkflowNotes", "TEXT"),
        ("DrizzleScale", "DOUBLE PRECISION"),
        ("LightsUsed", "INTEGER"),
        ("IntegrationTimeMins", "DOUBLE PRECISION"),
        ("OutputFolder", "TEXT"),
        ("MasterFilename", "TEXT"),
        ("Status", "TEXT DEFAULT 'IN_PROGRESS'"),
        ("HoursSpent", "DOUBLE PRECISION"),
        ("Notes", "TEXT"),
    ],
    "ProcessingObservations": [
        ("ProcessingID", "INTEGER NOT NULL"),
        ("ObservationID", "INTEGER NOT NULL"),
    ],
    "Images": [
        ("ImageID", "INTEGER PRIMARY KEY"),
        ("ProcessingID", "INTEGER NOT NULL"),
        ("ImageTypeID", "TEXT NOT NULL"),
        ("ParentImageID", "INTEGER"),
        ("PaletteID", "INTEGER DEFAULT 0"),
        ("Filename", "TEXT NOT NULL"),
        ("SourcePath", "TEXT"),
        ("WebPath", "TEXT"),
        ("Width", "INTEGER"),
        ("Height", "INTEGER"),
        ("IsAnnotated", "INTEGER DEFAULT 0"),
        ("StarRating", "INTEGER"),
        ("IsPublished", "INTEGER DEFAULT 0"),
        ("IsRetired", "INTEGER DEFAULT 0"),
        ("CreatedDate", "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"),
        ("Notes", "TEXT"),
    ],
    "SocialPosts": [
        ("PostID", "INTEGER PRIMARY KEY"),
        ("ImageID", "INTEGER NOT NULL"),
        ("PlatformID", "TEXT NOT NULL"),
        ("PostDate", "TIMESTAMP"),
        ("PostURL", "TEXT"),
        ("Caption", "TEXT"),
        ("Hashtags", "TEXT"),
        ("Likes", "INTEGER DEFAULT 0"),
        ("Comments", "INTEGER DEFAULT 0"),
        ("Shares", "INTEGER DEFAULT 0"),
        ("LastUpdated", "TIMESTAMP"),
        ("Notes", "TEXT"),
    ],
    "Todos": [
        ("TodoID", "INTEGER PRIMARY KEY"),
        ("Category", "TEXT NOT NULL DEFAULT 'General'"),
        ("ItemText", "TEXT NOT NULL"),
        ("IsDone", "INTEGER NOT NULL DEFAULT 0"),
        ("SortOrder", "INTEGER NOT NULL DEFAULT 0"),
        ("CreatedDate", "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"),
        ("CompletedDate", "TIMESTAMP"),
        ("Priority", "TEXT NOT NULL DEFAULT 'Medium'"),
    ],
    "VisibilityForecast": [
        ("ProfileName", "TEXT NOT NULL"),
        ("DSOKey", "TEXT NOT NULL"),
        ("ComputedDate", "TEXT NOT NULL"),
        ("FirstVisibleDate", "TEXT"),
        ("LastVisibleDate", "TEXT"),
        ("SearchDays", "INTEGER NOT NULL DEFAULT 180"),
    ],
}

# Composite primary keys that aren't expressed as a single-column
# "PRIMARY KEY" in TABLE_COLUMNS above.
COMPOSITE_PRIMARY_KEYS = {
    "ProcessingObservations": ["ProcessingID", "ObservationID"],
    "VisibilityForecast": ["ProfileName", "DSOKey"],
}

# Load order: parents before children. Not required for correctness (FKs are
# added after all data is loaded) but keeps progress output readable.
LOAD_ORDER = [
    "Constellations", "Equipment", "ObjectCategories", "ImageTypes",
    "PaletteTreatments", "SocialPlatforms", "ObjectTypes", "Objects",
    "CatalogIDs", "DSOLinks", "Projects", "GalleryImages", "Observations",
    "ProcessingRuns", "ProcessingObservations", "Images", "SocialPosts",
    "Todos", "VisibilityForecast",
]

# (table, pk_column) for tables whose PK was SQLite AUTOINCREMENT --
# these get a Postgres sequence wired up after data load.
AUTOINCREMENT_PKS = [
    ("DSOLinks", "LinkID"),
    ("Projects", "ProjectID"),
    ("GalleryImages", "GalleryImageID"),
    ("Observations", "ObservationID"),
    ("ProcessingRuns", "ProcessingID"),
    ("Images", "ImageID"),
    ("SocialPosts", "PostID"),
    ("Todos", "TodoID"),
]

# Foreign keys added after all data is loaded, so table load order and the
# self-referencing Images.ParentImageID column are never a problem.
FOREIGN_KEYS = [
    ("CatalogIDs", "DSOKey", "Objects", "DSOKey", "CASCADE", "CASCADE"),
    ("DSOLinks", "DSOKey", "Objects", "DSOKey", "CASCADE", "CASCADE"),
    ("ObjectTypes", "CategoryID", "ObjectCategories", "CategoryID", "RESTRICT", "CASCADE"),
    ("Objects", "ObjectTypeID", "ObjectTypes", "ObjectTypeID", "RESTRICT", "CASCADE"),
    ("Objects", "ConstellationID", "Constellations", "ConstellationID", "RESTRICT", "CASCADE"),
    ("Projects", "DSOKey", "Objects", "DSOKey", "RESTRICT", "CASCADE"),
    ("GalleryImages", "DSOKey", "Objects", "DSOKey", "RESTRICT", "CASCADE"),
    ("GalleryImages", "PaletteID", "PaletteTreatments", "PaletteID", "RESTRICT", "CASCADE"),
    ("GalleryImages", "ProjectID", "Projects", "ProjectID", "SET NULL", "CASCADE"),
    ("Observations", "ProjectID", "Projects", "ProjectID", "CASCADE", "CASCADE"),
    ("Observations", "EquipmentID", "Equipment", "EquipmentID", "RESTRICT", "CASCADE"),
    ("ProcessingRuns", "ProjectID", "Projects", "ProjectID", "CASCADE", "CASCADE"),
    ("ProcessingObservations", "ProcessingID", "ProcessingRuns", "ProcessingID", "CASCADE", "CASCADE"),
    ("ProcessingObservations", "ObservationID", "Observations", "ObservationID", "CASCADE", "CASCADE"),
    ("Images", "ProcessingID", "ProcessingRuns", "ProcessingID", "CASCADE", "CASCADE"),
    ("Images", "ImageTypeID", "ImageTypes", "ImageTypeID", "RESTRICT", "CASCADE"),
    ("Images", "ParentImageID", "Images", "ImageID", "SET NULL", "CASCADE"),
    ("Images", "PaletteID", "PaletteTreatments", "PaletteID", "RESTRICT", "CASCADE"),
    ("SocialPosts", "ImageID", "Images", "ImageID", "CASCADE", "CASCADE"),
    ("SocialPosts", "PlatformID", "SocialPlatforms", "PlatformID", "RESTRICT", "CASCADE"),
]

# (table, column, index_name) mirroring astro.db's existing indexes.
INDEXES = [
    ("CatalogIDs", "DSOKey", "idx_catalogids_dsokey"),
    ("Images", "ProcessingID", "idx_images_processing"),
]

# Views translate essentially verbatim from SQLite -> Postgres for this
# schema; every identifier is quoted to preserve exact casing.
VIEWS = {
    "vw_GalleryObjects": '''
        CREATE VIEW "vw_GalleryObjects" AS
        SELECT
            o."DSOKey",
            o."CommonName",
            c."CatalogID" AS "PrimaryCatalogID",
            o."ObjectTypeID",
            ot."TypeName" AS "ObjectTypeName",
            o."ConstellationID",
            con."Name" AS "ConstellationName",
            o."RAHours",
            o."DecDegrees",
            o."Magnitude",
            o."ObjectSize",
            o."DistanceLY",
            o."SocialBlurb",
            p."ProjectFolder",
            p."IsMosaic",
            (SELECT MAX("ObservationDate") FROM "Observations" WHERE "ProjectID" = p."ProjectID") AS "MostRecentObservation",
            (SELECT SUM("GoodLights") FROM "Observations" WHERE "ProjectID" = p."ProjectID") AS "TotalLights",
            (SELECT SUM("IntegrationMins") FROM "Observations" WHERE "ProjectID" = p."ProjectID") AS "TotalIntegrationMins"
        FROM "Objects" o
        LEFT JOIN "CatalogIDs" c ON o."DSOKey" = c."DSOKey" AND c."IsPrimary" = 1
        LEFT JOIN "ObjectTypes" ot ON o."ObjectTypeID" = ot."ObjectTypeID"
        LEFT JOIN "ObjectCategories" oc ON ot."CategoryID" = oc."CategoryID"
        LEFT JOIN "Constellations" con ON o."ConstellationID" = con."ConstellationID"
        LEFT JOIN "Projects" p ON o."DSOKey" = p."DSOKey"
    ''',
    "vw_NeedsMoreData": '''
        CREATE VIEW "vw_NeedsMoreData" AS
        SELECT
            obj."DSOKey",
            obj."CommonName",
            p."ProjectFolder",
            SUM(o."GoodLights") AS "TotalLights",
            SUM(o."IntegrationMins") AS "TotalIntegrationMins",
            MAX(o."ObservationDate") AS "LastObserved"
        FROM "Objects" obj
        JOIN "Projects" p ON obj."DSOKey" = p."DSOKey"
        LEFT JOIN "Observations" o ON p."ProjectID" = o."ProjectID"
        GROUP BY obj."DSOKey", obj."CommonName", p."ProjectFolder"
        HAVING SUM(o."IntegrationMins") < 120 OR SUM(o."IntegrationMins") IS NULL
        ORDER BY "TotalIntegrationMins" ASC
    ''',
    "vw_ObservationSummary": '''
        CREATE VIEW "vw_ObservationSummary" AS
        SELECT
            o."ObservationDate",
            p."ProjectFolder",
            obj."CommonName",
            e."EquipmentID",
            o."GoodLights",
            o."ExposureTimeSecs",
            o."IntegrationMins"
        FROM "Observations" o
        JOIN "Projects" p ON o."ProjectID" = p."ProjectID"
        JOIN "Objects" obj ON p."DSOKey" = obj."DSOKey"
        JOIN "Equipment" e ON o."EquipmentID" = e."EquipmentID"
        ORDER BY o."ObservationDate" DESC
    ''',
    "vw_ProcessingStatus": '''
        CREATE VIEW "vw_ProcessingStatus" AS
        SELECT
            obj."CommonName",
            p."ProjectFolder",
            pr."ProcessingDateStart",
            pr."Status",
            pr."HoursSpent",
            (SELECT COUNT(*) FROM "Images" WHERE "ProcessingID" = pr."ProcessingID" AND "IsPublished" = 1) AS "PublishedImages"
        FROM "ProcessingRuns" pr
        JOIN "Projects" p ON pr."ProjectID" = p."ProjectID"
        JOIN "Objects" obj ON p."DSOKey" = obj."DSOKey"
        ORDER BY pr."ProcessingDateStart" DESC
    ''',
}

# GENERATED column added to Projects after data load. CASE wrapper keeps the
# result an integer 0/1 (matching SQLite's LIKE semantics) instead of a
# native Postgres boolean, so existing code comparing IsMosaic == 1 still works.
IS_MOSAIC_DDL = '''
    ALTER TABLE "Projects" ADD COLUMN "IsMosaic" INTEGER
        GENERATED ALWAYS AS (
            CASE WHEN LOWER("ProjectFolder") LIKE '%mosaic%' THEN 1 ELSE 0 END
        ) STORED
'''


def build_create_table(table: str) -> str:
    columns = TABLE_COLUMNS[table]
    col_lines = [f"{q(name)} {coltype}" for name, coltype in columns]
    if table in COMPOSITE_PRIMARY_KEYS:
        pk_cols = qcols(COMPOSITE_PRIMARY_KEYS[table])
        col_lines.append(f"PRIMARY KEY ({pk_cols})")
    body = ",\n    ".join(col_lines)
    return f'CREATE TABLE {q(table)} (\n    {body}\n)'


def get_sqlite_rows(sconn, table, columns):
    col_list = ", ".join(columns)  # SQLite is case-insensitive, no quoting needed here
    cur = sconn.execute(f"SELECT {col_list} FROM {table}")
    return cur.fetchall()


def reset_target(pconn):
    # Drop and recreate the whole public schema rather than dropping tables
    # one by one -- guarantees a clean slate even if a prior run left
    # differently-cased objects behind (e.g. from testing before identifiers
    # were quoted), which DROP TABLE IF EXISTS "ExactCase" would silently miss.
    with pconn.cursor() as cur:
        cur.execute("DROP SCHEMA public CASCADE")
        cur.execute("CREATE SCHEMA public")
    pconn.commit()


def target_has_data(pconn):
    with pconn.cursor() as cur:
        for table in LOAD_ORDER:
            cur.execute("SELECT to_regclass(%s)", (table,))
            if cur.fetchone()[0] is None:
                continue
            cur.execute(f"SELECT COUNT(*) FROM {q(table)}")
            if cur.fetchone()[0] > 0:
                return True
    return False


def create_tables(pconn):
    with pconn.cursor() as cur:
        for table in LOAD_ORDER:
            cur.execute(build_create_table(table))
    pconn.commit()


def load_data(sconn, pconn):
    for table in LOAD_ORDER:
        columns = [name for name, _ in TABLE_COLUMNS[table]]
        rows = get_sqlite_rows(sconn, table, columns)
        if not rows:
            print(f"  {table}: 0 rows (skipped)")
            continue
        col_list = qcols(columns)
        placeholders = ", ".join(["%s"] * len(columns))
        with pconn.cursor() as cur:
            psycopg2.extras.execute_batch(
                cur,
                f"INSERT INTO {q(table)} ({col_list}) VALUES ({placeholders})",
                rows,
                page_size=200,
            )
        pconn.commit()
        print(f"  {table}: {len(rows)} rows")


def add_sequences(pconn):
    with pconn.cursor() as cur:
        for table, pk in AUTOINCREMENT_PKS:
            seq_name = f"{table.lower()}_{pk.lower()}_seq"
            cur.execute(f"SELECT COALESCE(MAX({q(pk)}), 0) FROM {q(table)}")
            max_id = cur.fetchone()[0]
            cur.execute(f'CREATE SEQUENCE IF NOT EXISTS "{seq_name}" OWNED BY {q(table)}.{q(pk)}')
            if max_id > 0:
                cur.execute('SELECT setval(%s, %s, true)', (seq_name, max_id))
            else:
                # Empty table: next nextval() should return 1, not 2.
                cur.execute('SELECT setval(%s, 1, false)', (seq_name,))
            cur.execute(
                f'ALTER TABLE {q(table)} ALTER COLUMN {q(pk)} SET DEFAULT nextval(\'"{seq_name}"\')'
            )
            print(f'  {table}.{pk}: sequence "{seq_name}" seeded at {max_id}')
    pconn.commit()


def add_foreign_keys(pconn):
    with pconn.cursor() as cur:
        for table, col, ref_table, ref_col, on_delete, on_update in FOREIGN_KEYS:
            constraint = q(f"fk_{table}_{col}")
            cur.execute(f"""
                ALTER TABLE {q(table)}
                    ADD CONSTRAINT {constraint}
                    FOREIGN KEY ({q(col)}) REFERENCES {q(ref_table)}({q(ref_col)})
                    ON DELETE {on_delete} ON UPDATE {on_update}
            """)
            print(f"  {table}.{col} -> {ref_table}.{ref_col} ({on_delete}/{on_update})")
    pconn.commit()


def add_indexes_and_generated_column(pconn):
    with pconn.cursor() as cur:
        for table, col, idx_name in INDEXES:
            cur.execute(f'CREATE INDEX {q(idx_name)} ON {q(table)}({q(col)})')
            print(f"  {idx_name}")
        cur.execute(IS_MOSAIC_DDL)
        print('  Projects.IsMosaic (generated column)')
    pconn.commit()


def create_views(pconn):
    with pconn.cursor() as cur:
        for name, sql in VIEWS.items():
            cur.execute(sql)
            print(f"  {name}")
    pconn.commit()


def verify(sconn, pconn):
    print("\nVerifying row counts (SQLite vs Postgres)...")
    all_ok = True
    with pconn.cursor() as cur:
        for table in LOAD_ORDER:
            sqlite_count = sconn.execute(f"SELECT COUNT(*) FROM {table}").fetchone()[0]
            cur.execute(f"SELECT COUNT(*) FROM {q(table)}")
            pg_count = cur.fetchone()[0]
            status = "OK" if sqlite_count == pg_count else "*** MISMATCH ***"
            if sqlite_count != pg_count:
                all_ok = False
            print(f"  {table:<25} sqlite={sqlite_count:<6} postgres={pg_count:<6} {status}")

    print("\nChecking foreign key integrity in Postgres...")
    with pconn.cursor() as cur:
        cur.execute("""
            SELECT count(*)
            FROM pg_constraint
            WHERE contype = 'f' AND connamespace = 'public'::regnamespace
        """)
        fk_count = cur.fetchone()[0]
    print(f"  {fk_count} foreign key constraints present (Postgres enforces these on every write from here on).")
    if fk_count != len(FOREIGN_KEYS):
        print(f"  *** expected {len(FOREIGN_KEYS)} constraints, found {fk_count} ***")
        all_ok = False

    print("\nSpot-checking column casing was preserved...")
    with pconn.cursor() as cur:
        cur.execute('SELECT "DSOKey", "ProjectFolder", "IsMosaic" FROM "Projects" LIMIT 1')
        print("  Projects.DSOKey / ProjectFolder / IsMosaic readable with original casing: OK")

    return all_ok


def main():
    parser = argparse.ArgumentParser(
        description="Migrate astro.db (SQLite) schema and data into PostgreSQL."
    )
    parser.add_argument("--sqlite-path", default=str(DEFAULT_SQLITE_PATH),
                         help=f"Path to astro.db (default: {DEFAULT_SQLITE_PATH})")
    parser.add_argument("--pg-host", required=True)
    parser.add_argument("--pg-port", default=5432, type=int)
    parser.add_argument("--pg-db", required=True)
    parser.add_argument("--pg-user", required=True)
    parser.add_argument("--pg-password", required=True)
    parser.add_argument("--pg-sslmode", default="prefer",
                         help="e.g. 'require' when connecting over the internet to a VPS")
    parser.add_argument("--reset", action="store_true",
                         help="Drop and recreate all tables/views first. "
                              "Use only against a test/empty database.")
    args = parser.parse_args()

    sqlite_path = Path(args.sqlite_path)
    if not sqlite_path.exists():
        sys.exit(f"SQLite database not found: {sqlite_path}")

    sconn = sqlite3.connect(sqlite_path)
    pconn = psycopg2.connect(
        host=args.pg_host, port=args.pg_port, dbname=args.pg_db,
        user=args.pg_user, password=args.pg_password, sslmode=args.pg_sslmode,
    )

    try:
        if args.reset:
            print("Dropping existing tables/views in target database...")
            reset_target(pconn)
        elif target_has_data(pconn):
            sys.exit(
                "Target database already has data in one or more of the expected "
                "tables. Refusing to proceed without --reset (which drops and "
                "recreates everything first) to avoid duplicating rows."
            )

        print("\nCreating tables...")
        create_tables(pconn)

        print("\nLoading data...")
        load_data(sconn, pconn)

        print("\nWiring up auto-increment sequences...")
        add_sequences(pconn)

        print("\nAdding foreign key constraints...")
        add_foreign_keys(pconn)

        print("\nAdding indexes and generated columns...")
        add_indexes_and_generated_column(pconn)

        print("\nCreating views...")
        create_views(pconn)

        ok = verify(sconn, pconn)

        if ok:
            print("\nMigration complete. All row counts match, foreign keys are in place, and column casing is preserved.")
        else:
            print("\nMigration finished but problems were found above -- investigate before trusting this database.")
            sys.exit(1)

    finally:
        sconn.close()
        pconn.close()


if __name__ == "__main__":
    main()
