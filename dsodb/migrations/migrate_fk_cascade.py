"""
Migration: Add ON DELETE / ON UPDATE actions to all foreign keys in astro.db.

SQLite can't ALTER a foreign key's ON DELETE/ON UPDATE clause in place, so
this rebuilds each affected table (12-step pattern: rename -> create new ->
copy data -> drop old -> recreate any indexes) inside a single transaction,
verifies row counts and FK integrity before committing, and rolls back
automatically if anything looks wrong.

Run once from any directory:  python migrate_fk_cascade.py

Safe to re-run: it checks each table's foreign_key_list and skips any
table whose FKs are already fully configured with explicit actions.
"""
import shutil
import sqlite3
import sys
from datetime import datetime
from pathlib import Path

DB_PATH = Path(__file__).parent.parent / 'astro.db'

# ── Rebuild specs ────────────────────────────────────────────────────────
# Each entry: table name -> (new CREATE TABLE sql, explicit column list for
# the INSERT ... SELECT copy -- excludes any GENERATED ALWAYS columns).
REBUILDS = {
    "CatalogIDs": (
        """
        CREATE TABLE CatalogIDs (
            CatalogID TEXT NOT NULL,
            DSOKey TEXT NOT NULL,
            IsPrimary INTEGER DEFAULT 0,
            PRIMARY KEY (CatalogID),
            FOREIGN KEY (DSOKey) REFERENCES Objects(DSOKey)
                ON DELETE CASCADE ON UPDATE CASCADE
        )
        """,
        ["CatalogID", "DSOKey", "IsPrimary"],
    ),
    "DSOLinks": (
        """
        CREATE TABLE DSOLinks (
            LinkID INTEGER PRIMARY KEY AUTOINCREMENT,
            DSOKey TEXT NOT NULL,
            Label TEXT NOT NULL,
            URL TEXT NOT NULL,
            SortOrder INTEGER DEFAULT 0,
            FOREIGN KEY (DSOKey) REFERENCES Objects(DSOKey)
                ON DELETE CASCADE ON UPDATE CASCADE
        )
        """,
        ["LinkID", "DSOKey", "Label", "URL", "SortOrder"],
    ),
    "ObjectTypes": (
        """
        CREATE TABLE ObjectTypes (
            ObjectTypeID TEXT PRIMARY KEY,
            CategoryID TEXT NOT NULL,
            TypeName TEXT NOT NULL,
            Description TEXT,
            FOREIGN KEY (CategoryID) REFERENCES ObjectCategories(CategoryID)
                ON DELETE RESTRICT ON UPDATE CASCADE
        )
        """,
        ["ObjectTypeID", "CategoryID", "TypeName", "Description"],
    ),
    "Objects": (
        """
        CREATE TABLE Objects (
            DSOKey          TEXT PRIMARY KEY,
            CommonName      TEXT,
            ObjectTypeID    TEXT REFERENCES ObjectTypes(ObjectTypeID)
                                ON DELETE RESTRICT ON UPDATE CASCADE,
            ConstellationID TEXT REFERENCES Constellations(ConstellationID)
                                ON DELETE RESTRICT ON UPDATE CASCADE,
            RAHours         REAL,
            DecDegrees      REAL,
            Magnitude       REAL,
            ObjectSize      TEXT,
            DistanceLY      TEXT,
            SocialBlurb     TEXT,
            LastUpdated     DATETIME DEFAULT CURRENT_TIMESTAMP,
            WantBetter      INTEGER NOT NULL DEFAULT 0,
            SqArcMins       REAL,
            Notes           TEXT
        )
        """,
        ["DSOKey", "CommonName", "ObjectTypeID", "ConstellationID", "RAHours",
         "DecDegrees", "Magnitude", "ObjectSize", "DistanceLY", "SocialBlurb",
         "LastUpdated", "WantBetter", "SqArcMins", "Notes"],
    ),
    "Projects": (
        """
        CREATE TABLE Projects (
            ProjectID     INTEGER PRIMARY KEY AUTOINCREMENT,
            DSOKey        TEXT NOT NULL,
            ProjectFolder TEXT NOT NULL,
            IsMosaic      INTEGER GENERATED ALWAYS AS
                              (LOWER(ProjectFolder) LIKE '%mosaic%') VIRTUAL,
            Notes         TEXT,
            FOREIGN KEY (DSOKey) REFERENCES Objects(DSOKey)
                ON DELETE RESTRICT ON UPDATE CASCADE
        )
        """,
        ["ProjectID", "DSOKey", "ProjectFolder", "Notes"],  # IsMosaic is generated
    ),
    "Observations": (
        """
        CREATE TABLE Observations (
            ObservationID       INTEGER PRIMARY KEY AUTOINCREMENT,
            ProjectID           INTEGER NOT NULL,
            EquipmentID         TEXT NOT NULL,
            ObservationDate     DATE NOT NULL,
            ObservationFolder   TEXT,
            StartTime           DATETIME,
            EndTime             DATETIME,
            ExposureTimeSecs    REAL,
            Filter              TEXT,
            TotalExposures      INTEGER DEFAULT 0,
            GoodLights          INTEGER DEFAULT 0,
            MosaicPanel         TEXT,
            SeeingConditions    TEXT,
            Temperature         REAL,
            Humidity            REAL,
            NinaWorkflowName    TEXT,
            Notes               TEXT,
            IntegrationMins REAL,
            FOREIGN KEY (ProjectID) REFERENCES Projects(ProjectID)
                ON DELETE CASCADE ON UPDATE CASCADE,
            FOREIGN KEY (EquipmentID) REFERENCES Equipment(EquipmentID)
                ON DELETE RESTRICT ON UPDATE CASCADE
        )
        """,
        ["ObservationID", "ProjectID", "EquipmentID", "ObservationDate",
         "ObservationFolder", "StartTime", "EndTime", "ExposureTimeSecs",
         "Filter", "TotalExposures", "GoodLights", "MosaicPanel",
         "SeeingConditions", "Temperature", "Humidity", "NinaWorkflowName",
         "Notes", "IntegrationMins"],
    ),
    "ProcessingRuns": (
        """
        CREATE TABLE ProcessingRuns (
            ProcessingID INTEGER PRIMARY KEY AUTOINCREMENT,
            ProjectID INTEGER NOT NULL,
            ProcessingDateStart DATE,
            ProcessingDateEnd DATE,
            ProcessingSoftware TEXT,
            WorkflowNotes TEXT,
            DrizzleScale REAL,
            LightsUsed INTEGER,
            IntegrationTimeMins REAL,
            OutputFolder TEXT,
            MasterFilename TEXT,
            Status TEXT DEFAULT 'IN_PROGRESS',
            HoursSpent REAL,
            Notes TEXT,
            FOREIGN KEY (ProjectID) REFERENCES Projects(ProjectID)
                ON DELETE CASCADE ON UPDATE CASCADE
        )
        """,
        ["ProcessingID", "ProjectID", "ProcessingDateStart", "ProcessingDateEnd",
         "ProcessingSoftware", "WorkflowNotes", "DrizzleScale", "LightsUsed",
         "IntegrationTimeMins", "OutputFolder", "MasterFilename", "Status",
         "HoursSpent", "Notes"],
    ),
    "ProcessingObservations": (
        """
        CREATE TABLE ProcessingObservations (
            ProcessingID INTEGER NOT NULL,
            ObservationID INTEGER NOT NULL,
            PRIMARY KEY (ProcessingID, ObservationID),
            FOREIGN KEY (ProcessingID) REFERENCES ProcessingRuns(ProcessingID)
                ON DELETE CASCADE ON UPDATE CASCADE,
            FOREIGN KEY (ObservationID) REFERENCES Observations(ObservationID)
                ON DELETE CASCADE ON UPDATE CASCADE
        )
        """,
        ["ProcessingID", "ObservationID"],
    ),
    "Images": (
        """
        CREATE TABLE Images (
            ImageID INTEGER PRIMARY KEY AUTOINCREMENT,
            ProcessingID INTEGER NOT NULL,
            ImageTypeID TEXT NOT NULL,
            ParentImageID INTEGER,
            PaletteID INTEGER DEFAULT 0,
            Filename TEXT NOT NULL,
            SourcePath TEXT,
            WebPath TEXT,
            Width INTEGER,
            Height INTEGER,
            IsAnnotated INTEGER DEFAULT 0,
            StarRating INTEGER,
            IsPublished INTEGER DEFAULT 0,
            IsRetired INTEGER DEFAULT 0,
            CreatedDate DATETIME DEFAULT CURRENT_TIMESTAMP,
            Notes TEXT,
            FOREIGN KEY (ProcessingID) REFERENCES ProcessingRuns(ProcessingID)
                ON DELETE CASCADE ON UPDATE CASCADE,
            FOREIGN KEY (ImageTypeID) REFERENCES ImageTypes(ImageTypeID)
                ON DELETE RESTRICT ON UPDATE CASCADE,
            FOREIGN KEY (ParentImageID) REFERENCES Images(ImageID)
                ON DELETE SET NULL ON UPDATE CASCADE,
            FOREIGN KEY (PaletteID) REFERENCES PaletteTreatments(PaletteID)
                ON DELETE RESTRICT ON UPDATE CASCADE
        )
        """,
        ["ImageID", "ProcessingID", "ImageTypeID", "ParentImageID", "PaletteID",
         "Filename", "SourcePath", "WebPath", "Width", "Height", "IsAnnotated",
         "StarRating", "IsPublished", "IsRetired", "CreatedDate", "Notes"],
    ),
    "GalleryImages": (
        """
        CREATE TABLE GalleryImages (
            GalleryImageID  INTEGER PRIMARY KEY AUTOINCREMENT,
            DSOKey          TEXT    NOT NULL,
            BaseName        TEXT    NOT NULL,
            Caption         TEXT,
            PaletteID       INTEGER DEFAULT 0,
            DateCaptured    TEXT,
            Copyright       TEXT,
            IsOwn           INTEGER DEFAULT 1,
            Attribution     TEXT,
            SortOrder       INTEGER DEFAULT 0,
            IsFeature       INTEGER DEFAULT 0,
            Equipment       TEXT,
            SessionDir      TEXT,
            ProjectID       INTEGER REFERENCES Projects(ProjectID)
                                ON DELETE SET NULL ON UPDATE CASCADE,
            FOREIGN KEY (DSOKey)    REFERENCES Objects(DSOKey)
                ON DELETE RESTRICT ON UPDATE CASCADE,
            FOREIGN KEY (PaletteID) REFERENCES PaletteTreatments(PaletteID)
                ON DELETE RESTRICT ON UPDATE CASCADE
        )
        """,
        ["GalleryImageID", "DSOKey", "BaseName", "Caption", "PaletteID",
         "DateCaptured", "Copyright", "IsOwn", "Attribution", "SortOrder",
         "IsFeature", "Equipment", "SessionDir", "ProjectID"],
    ),
    "SocialPosts": (
        """
        CREATE TABLE SocialPosts (
            PostID INTEGER PRIMARY KEY AUTOINCREMENT,
            ImageID INTEGER NOT NULL,
            PlatformID TEXT NOT NULL,
            PostDate DATETIME,
            PostURL TEXT,
            Caption TEXT,
            Hashtags TEXT,
            Likes INTEGER DEFAULT 0,
            Comments INTEGER DEFAULT 0,
            Shares INTEGER DEFAULT 0,
            LastUpdated DATETIME,
            Notes TEXT,
            FOREIGN KEY (ImageID) REFERENCES Images(ImageID)
                ON DELETE CASCADE ON UPDATE CASCADE,
            FOREIGN KEY (PlatformID) REFERENCES SocialPlatforms(PlatformID)
                ON DELETE RESTRICT ON UPDATE CASCADE
        )
        """,
        ["PostID", "ImageID", "PlatformID", "PostDate", "PostURL", "Caption",
         "Hashtags", "Likes", "Comments", "Shares", "LastUpdated", "Notes"],
    ),
}

# Indexes that must be recreated after rebuilding their table
# (SQLite drops indexes automatically when the underlying table is dropped)
INDEXES = [
    ("idx_catalogids_dsokey", "CREATE INDEX idx_catalogids_dsokey ON CatalogIDs(DSOKey)"),
    ("idx_images_processing", "CREATE INDEX idx_images_processing ON Images(ProcessingID)"),
]

# Rebuild order: parents before children isn't strictly required since
# foreign_keys is OFF for the whole operation, but keeping this order makes
# the intent readable and matches dependency direction.
ORDER = ["ObjectTypes", "Objects", "CatalogIDs", "DSOLinks", "Projects",
         "Observations", "ProcessingRuns", "ProcessingObservations",
         "Images", "GalleryImages", "SocialPosts"]

# Views that reference the tables above. SQLite's ALTER TABLE RENAME
# auto-rewrites view bodies that reference the renamed table, but that
# rewrite isn't cleaned up when the table is later dropped and recreated
# under its original name -- it leaves the view pointing at a dangling
# "<table>__old". So these are dropped up front and recreated verbatim
# (from sqlite_master) after all table rebuilds are done.
VIEW_NAMES = ["vw_ProcessingStatus", "vw_GalleryObjects",
              "vw_ObservationSummary", "vw_NeedsMoreData"]


def table_needs_rebuild(conn, table):
    """True if any FK on this table is still NO ACTION/NO ACTION."""
    cur = conn.execute(f"PRAGMA foreign_key_list({table})")
    rows = cur.fetchall()
    if not rows:
        return False
    # columns: id, seq, table, from, to, on_update, on_delete, match
    return any(r[6] == "NO ACTION" and r[5] == "NO ACTION" for r in rows)


def main():
    if not DB_PATH.exists():
        sys.exit(f"astro.db not found at {DB_PATH}")

    backup_path = DB_PATH.parent / f"astro_pre_fk_migration_{datetime.now():%Y%m%d_%H%M%S}.db"
    shutil.copy2(DB_PATH, backup_path)
    print(f"Backup written: {backup_path.name}")

    conn = sqlite3.connect(DB_PATH)
    conn.isolation_level = None  # manual transaction control
    conn.execute("PRAGMA foreign_keys = OFF")

    to_rebuild = [t for t in ORDER if table_needs_rebuild(conn, t)]
    if not to_rebuild:
        print("All foreign keys already have explicit ON DELETE/ON UPDATE actions. Nothing to do.")
        conn.execute("PRAGMA foreign_keys = ON")
        conn.close()
        backup_path.unlink()  # no changes made, remove the unused backup
        return

    print(f"Tables to rebuild: {', '.join(to_rebuild)}")

    pre_counts = {t: conn.execute(f"SELECT COUNT(*) FROM {t}").fetchone()[0] for t in to_rebuild}

    # Capture existing view definitions verbatim before dropping them
    view_sql = {}
    for name in VIEW_NAMES:
        row = conn.execute(
            "SELECT sql FROM sqlite_master WHERE type='view' AND name=?", (name,)
        ).fetchone()
        if row:
            view_sql[name] = row[0]

    try:
        conn.execute("BEGIN")

        for name in view_sql:
            conn.execute(f"DROP VIEW {name}")
            print(f"  dropped view {name} (will recreate after rebuild)")

        for table in to_rebuild:
            create_sql, cols = REBUILDS[table]
            col_list = ", ".join(cols)
            print(f"  rebuilding {table} ...")
            conn.execute(f"ALTER TABLE {table} RENAME TO {table}__old")
            conn.execute(create_sql)
            conn.execute(f"INSERT INTO {table} ({col_list}) SELECT {col_list} FROM {table}__old")
            conn.execute(f"DROP TABLE {table}__old")

        for name, sql in INDEXES:
            conn.execute(sql)
            print(f"  recreated index {name}")

        for name, sql in view_sql.items():
            conn.execute(sql)
            print(f"  recreated view {name}")

        # ── Verify row counts unchanged ──────────────────────────────
        for table in to_rebuild:
            post_count = conn.execute(f"SELECT COUNT(*) FROM {table}").fetchone()[0]
            if post_count != pre_counts[table]:
                raise RuntimeError(
                    f"Row count mismatch on {table}: had {pre_counts[table]}, now {post_count}"
                )
        print("Row counts verified unchanged for all rebuilt tables.")

        # ── Verify referential integrity ─────────────────────────────
        violations = conn.execute("PRAGMA foreign_key_check").fetchall()
        if violations:
            raise RuntimeError(f"foreign_key_check found {len(violations)} violation(s): {violations}")
        print("PRAGMA foreign_key_check: clean.")

        conn.execute("COMMIT")
        print("Migration committed.")

    except Exception:
        conn.execute("ROLLBACK")
        conn.close()
        print("ERROR — migration rolled back, astro.db unchanged.")
        print(f"(Backup still available at {backup_path.name} if you need to compare.)")
        raise

    conn.execute("PRAGMA foreign_keys = ON")

    # sqlite_sequence bookkeeping: after ALTER TABLE RENAME, SQLite updates
    # the sqlite_sequence row to the new table name automatically, but we
    # double check here and repair if anything looks off.
    for table in to_rebuild:
        cur = conn.execute("PRAGMA table_info(%s)" % table)
        pk_cols = [r[1] for r in cur.fetchall() if r[5] == 1]
        if len(pk_cols) != 1:
            continue
        cur = conn.execute(f"SELECT sql FROM sqlite_master WHERE name=?", (table,))
        sql = cur.fetchone()[0]
        if "AUTOINCREMENT" not in sql.upper():
            continue
        max_id = conn.execute(f"SELECT COALESCE(MAX({pk_cols[0]}), 0) FROM {table}").fetchone()[0]
        seq_row = conn.execute("SELECT seq FROM sqlite_sequence WHERE name=?", (table,)).fetchone()
        if seq_row is None or seq_row[0] < max_id:
            conn.execute("INSERT OR REPLACE INTO sqlite_sequence (name, seq) VALUES (?, ?)", (table, max_id))
            print(f"  sqlite_sequence for {table} set to {max_id}")

    conn.close()
    print("Done. astro.db has been rebuilt with explicit FK actions.")
    print(f"If you need to revert: copy {backup_path.name} back over astro.db.")


if __name__ == "__main__":
    main()
