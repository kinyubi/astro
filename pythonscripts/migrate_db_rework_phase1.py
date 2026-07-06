"""
migrate_db_rework_phase1.py

Phase 1 of DB_REWORK_PLAN.md: schema + data migration establishing
"Project is the top of the hierarchy" model. See that file for full
rationale on every change made here.

SUPERSEDES migrate_view_project_fallback.py -- do not run that script.
Instead of a COALESCE fallback in the view, this script creates real
Projects rows for the 11 legacy DSOs that had Objects.ProjectFolder but
no Projects row (e.g. LDN1228), which is the actual fix.

Steps (all in one transaction -- rolls back cleanly on any error):

  1. Objects  -> for any DSO with a legacy ProjectFolder but no Projects
                 row, create one now (before the legacy column is dropped).
  2. Objects  -> drop ProjectFolder, IsMosaic, MostRecentObservation.
  3. Projects -> drop MosaicConfig, Status, TotalGoodLights,
                 TotalIntegrationMins, CreatedDate. IsMosaic becomes a
                 generated column (true if 'mosaic' appears in
                 ProjectFolder, case-insensitive) so it can never drift
                 from the folder name again. Objects.Notes is copied
                 into Projects.Notes for DSOs that have both a Notes
                 value and a Projects row (Objects.Notes is NOT deleted
                 -- this is a copy, both tables keep independent Notes).
  4. GalleryImages -> add ProjectID, backfill it (single-project DSOs
                 match directly; IC1805/NGC1499, the only DSOs with two
                 Projects rows, match by comparing each image's current
                 IsMosaic flag against each candidate project's computed
                 IsMosaic), then drop the now-redundant IsMosaic column.
  5. Observations -> add ObservationFolder, drop RejectedLights,
                 BortleScale (confirmed zero data in both today).
  6. vw_GalleryObjects -> recreated without the Projects.Status
                 dependency (that column is gone) and without the
                 now-unnecessary COALESCE-to-legacy-Objects fallback
                 (every DSO now has a real Projects row if it has a
                 project at all).

Also drops and recreates vw_ObservationSummary, vw_NeedsMoreData, and
vw_ProcessingStatus -- SQLite validates these against the underlying
tables during the rebuild steps above even though they aren't being
changed (except vw_ObservationSummary, which loses RejectedLights/
RejectionPct since that column is gone).

Tested against a sandbox copy of the live DB before being placed here --
verified schema shape, generated-column behavior, LDN1228 getting a real
Projects row, and GalleryImages.ProjectID backfill correctness for both
IC1805 and NGC1499 (the only two multi-project DSOs).

Safe to interrupt before confirming -- nothing is written until you
type 'y' at the prompt. The whole migration runs as a single SQLite
transaction, so if anything fails partway through, none of it is kept.

Usage:
    python migrate_db_rework_phase1.py [path_to_astro.db]
"""

import sqlite3, sys, os

DB_PATH = r"C:\laragon7\www\astro\dsodb\astro.db"
args = sys.argv[1:]
if args:
    DB_PATH = args[0]

if not os.path.exists(DB_PATH):
    print(f"ERROR: Database not found at {DB_PATH}")
    sys.exit(1)

conn = sqlite3.connect(DB_PATH)
conn.row_factory = sqlite3.Row
cur = conn.cursor()

# ------------------------------------------------------------------
# Preview
# ------------------------------------------------------------------
cur.execute("""
    SELECT o.DSOKey, o.CommonName, o.ProjectFolder
    FROM Objects o
    LEFT JOIN Projects p ON o.DSOKey = p.DSOKey
    WHERE p.ProjectID IS NULL AND o.ProjectFolder IS NOT NULL
    ORDER BY o.DSOKey
""")
legacy_only = cur.fetchall()

cur.execute("""
    SELECT DSOKey, Notes FROM Objects
    WHERE Notes IS NOT NULL AND TRIM(Notes) != ''
""")
notes_to_copy = cur.fetchall()

cur.execute("SELECT COUNT(*) FROM GalleryImages")
total_gallery_images = cur.fetchone()[0]

print("=" * 70)
print("DB Rework Phase 1 -- preview")
print("=" * 70)
print(f"\nWill create a new Projects row for {len(legacy_only)} legacy DSO(s):")
for r in legacy_only:
    print(f"  {r['DSOKey']:<12} {r['CommonName'] or '':<35} folder={r['ProjectFolder']}")

print(f"\nWill copy Objects.Notes -> Projects.Notes for {len(notes_to_copy)} DSO(s):")
for r in notes_to_copy:
    print(f"  {r['DSOKey']:<12} {r['Notes'][:60]}")

print(f"\n{total_gallery_images} GalleryImages rows will get a ProjectID backfilled.")
print("\nColumns being dropped:")
print("  Objects:     ProjectFolder, IsMosaic, MostRecentObservation")
print("  Projects:    MosaicConfig, Status, TotalGoodLights, TotalIntegrationMins, CreatedDate")
print("  GalleryImages: IsMosaic (replaced by Projects.IsMosaic via ProjectID join)")
print("  Observations: RejectedLights, BortleScale")
print("\nColumns being added:")
print("  GalleryImages: ProjectID (FK -> Projects)")
print("  Observations:  ObservationFolder")
print("\nProjects.IsMosaic becomes a generated column (derived from ProjectFolder).")

answer = input("\nApply all of the above in one transaction? [y/N] ").strip().lower()
if answer != 'y':
    print("Aborted. No changes made.")
    sys.exit(0)

# ------------------------------------------------------------------
# Migration
# ------------------------------------------------------------------
try:
    cur.execute("PRAGMA foreign_keys = OFF")
    cur.execute("BEGIN TRANSACTION")

    # 0. Drop every view that references Objects/Projects/GalleryImages/
    #    Observations -- SQLite validates these against the underlying
    #    tables during the rebuild steps below, even though the views
    #    themselves aren't being changed (except vw_GalleryObjects and
    #    vw_ObservationSummary, which need updated definitions anyway).
    #    All are recreated at the end of this script.
    cur.execute("DROP VIEW IF EXISTS vw_GalleryObjects")
    cur.execute("DROP VIEW IF EXISTS vw_ObservationSummary")
    cur.execute("DROP VIEW IF EXISTS vw_NeedsMoreData")
    cur.execute("DROP VIEW IF EXISTS vw_ProcessingStatus")

    # 1. Create Projects rows for legacy-only DSOs (before dropping the
    #    legacy Objects columns we're reading here)
    for r in legacy_only:
        cur.execute(
            "INSERT INTO Projects (DSOKey, ProjectFolder) VALUES (?, ?)",
            (r['DSOKey'], r['ProjectFolder'])
        )
    print(f"Created {len(legacy_only)} new Projects row(s) for legacy DSOs.")

    # 2. Rebuild Objects (drop ProjectFolder, IsMosaic, MostRecentObservation)
    cur.execute("""
        CREATE TABLE Objects_new (
            DSOKey          TEXT PRIMARY KEY,
            CommonName      TEXT,
            ObjectTypeID    TEXT REFERENCES ObjectTypes,
            ConstellationID TEXT REFERENCES Constellations,
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
    """)
    cur.execute("""
        INSERT INTO Objects_new
            (DSOKey, CommonName, ObjectTypeID, ConstellationID, RAHours,
             DecDegrees, Magnitude, ObjectSize, DistanceLY, SocialBlurb,
             LastUpdated, WantBetter, SqArcMins, Notes)
        SELECT
            DSOKey, CommonName, ObjectTypeID, ConstellationID, RAHours,
            DecDegrees, Magnitude, ObjectSize, DistanceLY, SocialBlurb,
            LastUpdated, WantBetter, SqArcMins, Notes
        FROM Objects
    """)
    cur.execute("DROP TABLE Objects")
    cur.execute("ALTER TABLE Objects_new RENAME TO Objects")
    print("Objects table rebuilt (ProjectFolder, IsMosaic, MostRecentObservation dropped).")

    # 3. Rebuild Projects (drop 5 columns, IsMosaic -> generated, copy Notes)
    cur.execute("""
        CREATE TABLE Projects_new (
            ProjectID     INTEGER PRIMARY KEY AUTOINCREMENT,
            DSOKey        TEXT NOT NULL,
            ProjectFolder TEXT NOT NULL,
            IsMosaic      INTEGER GENERATED ALWAYS AS
                              (LOWER(ProjectFolder) LIKE '%mosaic%') VIRTUAL,
            Notes         TEXT,
            FOREIGN KEY (DSOKey) REFERENCES Objects(DSOKey)
        )
    """)
    cur.execute("""
        INSERT INTO Projects_new (ProjectID, DSOKey, ProjectFolder, Notes)
        SELECT ProjectID, DSOKey, ProjectFolder, Notes FROM Projects
    """)
    cur.execute("DROP TABLE Projects")
    cur.execute("ALTER TABLE Projects_new RENAME TO Projects")
    print("Projects table rebuilt (5 columns dropped, IsMosaic is now generated).")

    for r in notes_to_copy:
        cur.execute(
            "UPDATE Projects SET Notes = ? WHERE DSOKey = ?",
            (r['Notes'], r['DSOKey'])
        )
    print(f"Copied Notes into Projects for {len(notes_to_copy)} DSO(s).")

    # 4. GalleryImages: add ProjectID, backfill, then drop IsMosaic
    cur.execute("ALTER TABLE GalleryImages ADD COLUMN ProjectID INTEGER REFERENCES Projects(ProjectID)")

    # Single-project DSOs: unambiguous match
    cur.execute("""
        UPDATE GalleryImages
        SET ProjectID = (
            SELECT p.ProjectID FROM Projects p WHERE p.DSOKey = GalleryImages.DSOKey
        )
        WHERE ProjectID IS NULL
          AND (SELECT COUNT(*) FROM Projects p2 WHERE p2.DSOKey = GalleryImages.DSOKey) = 1
    """)

    # Multi-project DSOs: match by comparing the image's current IsMosaic
    # flag to each candidate project's computed IsMosaic
    cur.execute("""
        UPDATE GalleryImages
        SET ProjectID = (
            SELECT p.ProjectID FROM Projects p
            WHERE p.DSOKey = GalleryImages.DSOKey
              AND p.IsMosaic = GalleryImages.IsMosaic
            LIMIT 1
        )
        WHERE ProjectID IS NULL
    """)

    cur.execute("SELECT GalleryImageID, DSOKey, BaseName FROM GalleryImages WHERE ProjectID IS NULL")
    unmatched = cur.fetchall()
    if unmatched:
        raise RuntimeError(
            "ABORTING: could not backfill ProjectID for "
            + ", ".join(f"{r['DSOKey']}/{r['BaseName']} (id={r['GalleryImageID']})" for r in unmatched)
        )
    print("GalleryImages.ProjectID backfilled for all rows.")

    cur.execute("""
        CREATE TABLE GalleryImages_new (
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
            ProjectID       INTEGER REFERENCES Projects(ProjectID),
            FOREIGN KEY (DSOKey)    REFERENCES Objects(DSOKey),
            FOREIGN KEY (PaletteID) REFERENCES PaletteTreatments(PaletteID)
        )
    """)
    cur.execute("""
        INSERT INTO GalleryImages_new
            (GalleryImageID, DSOKey, BaseName, Caption, PaletteID, DateCaptured,
             Copyright, IsOwn, Attribution, SortOrder, IsFeature, Equipment,
             SessionDir, ProjectID)
        SELECT
            GalleryImageID, DSOKey, BaseName, Caption, PaletteID, DateCaptured,
            Copyright, IsOwn, Attribution, SortOrder, IsFeature, Equipment,
            SessionDir, ProjectID
        FROM GalleryImages
    """)
    cur.execute("DROP TABLE GalleryImages")
    cur.execute("ALTER TABLE GalleryImages_new RENAME TO GalleryImages")
    print("GalleryImages table rebuilt (ProjectID added, IsMosaic dropped).")

    # 5. Observations: add ObservationFolder, drop RejectedLights, BortleScale
    cur.execute("""
        CREATE TABLE Observations_new (
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
            FOREIGN KEY (ProjectID) REFERENCES Projects(ProjectID),
            FOREIGN KEY (EquipmentID) REFERENCES Equipment(EquipmentID)
        )
    """)
    cur.execute("""
        INSERT INTO Observations_new
            (ObservationID, ProjectID, EquipmentID, ObservationDate, StartTime,
             EndTime, ExposureTimeSecs, Filter, TotalExposures, GoodLights,
             MosaicPanel, SeeingConditions, Temperature, Humidity,
             NinaWorkflowName, Notes)
        SELECT
            ObservationID, ProjectID, EquipmentID, ObservationDate, StartTime,
            EndTime, ExposureTimeSecs, Filter, TotalExposures, GoodLights,
            MosaicPanel, SeeingConditions, Temperature, Humidity,
            NinaWorkflowName, Notes
        FROM Observations
    """)
    cur.execute("DROP TABLE Observations")
    cur.execute("ALTER TABLE Observations_new RENAME TO Observations")
    print("Observations table rebuilt (ObservationFolder added, RejectedLights/BortleScale dropped).")

    # 6. Recreate vw_GalleryObjects without the Status dependency or legacy fallback
    cur.execute("DROP VIEW IF EXISTS vw_GalleryObjects")
    cur.execute("""
        CREATE VIEW vw_GalleryObjects as
        SELECT
            o.DSOKey,
            o.CommonName,
            c.CatalogID AS PrimaryCatalogID,
            o.ObjectTypeID,
            ot.TypeName AS ObjectTypeName,
            o.ConstellationID,
            con.Name AS ConstellationName,
            o.RAHours,
            o.DecDegrees,
            o.Magnitude,
            o.ObjectSize,
            o.DistanceLY,
            o.SocialBlurb,
            p.ProjectFolder,
            p.IsMosaic,
            (SELECT MAX(ObservationDate) FROM Observations WHERE ProjectID = p.ProjectID) AS MostRecentObservation,
            (SELECT SUM(GoodLights) FROM Observations WHERE ProjectID = p.ProjectID) AS TotalLights,
            (SELECT SUM(GoodLights * ExposureTimeSecs / 60.0) FROM Observations WHERE ProjectID = p.ProjectID) AS TotalIntegrationMins
        FROM Objects o
        LEFT JOIN CatalogIDs c ON o.DSOKey = c.DSOKey AND c.IsPrimary = 1
        LEFT JOIN ObjectTypes ot ON o.ObjectTypeID = ot.ObjectTypeID
        LEFT JOIN ObjectCategories oc ON ot.CategoryID = oc.CategoryID
        LEFT JOIN Constellations con ON o.ConstellationID = con.ConstellationID
        LEFT JOIN Projects p ON o.DSOKey = p.DSOKey
    """)
    print("vw_GalleryObjects recreated (no longer depends on Projects.Status).")
    print("NOTE: for DSOs with more than one Project (IC1805, NGC1499), this view")
    print("still returns multiple rows -- dso.php's single-row fetch() needs the")
    print("Phase 2 'list of projects per DSO' fix before those two DSOs display")
    print("correctly. This is the same non-determinism that already existed before")
    print("this migration, not a new regression.")

    # Recreate the three other views that were dropped in step 0.
    # vw_ObservationSummary: RejectedLights/RejectionPct removed (column is gone).
    cur.execute("""
        CREATE VIEW vw_ObservationSummary AS
        SELECT
            o.ObservationDate,
            p.ProjectFolder,
            obj.CommonName,
            e.EquipmentID,
            o.GoodLights,
            o.ExposureTimeSecs,
            ROUND(o.GoodLights * o.ExposureTimeSecs / 60.0, 1) AS IntegrationMins
        FROM Observations o
        JOIN Projects p ON o.ProjectID = p.ProjectID
        JOIN Objects obj ON p.DSOKey = obj.DSOKey
        JOIN Equipment e ON o.EquipmentID = e.EquipmentID
        ORDER BY o.ObservationDate DESC
    """)

    # vw_NeedsMoreData: unchanged, no dropped columns referenced.
    cur.execute("""
        CREATE VIEW vw_NeedsMoreData AS
        SELECT
            obj.DSOKey,
            obj.CommonName,
            p.ProjectFolder,
            SUM(o.GoodLights) AS TotalLights,
            ROUND(SUM(o.GoodLights * o.ExposureTimeSecs / 60.0), 1) AS TotalIntegrationMins,
            MAX(o.ObservationDate) AS LastObserved
        FROM Objects obj
        JOIN Projects p ON obj.DSOKey = p.DSOKey
        LEFT JOIN Observations o ON p.ProjectID = o.ProjectID
        GROUP BY obj.DSOKey
        HAVING TotalIntegrationMins < 120 OR TotalIntegrationMins IS NULL
        ORDER BY TotalIntegrationMins ASC
    """)

    # vw_ProcessingStatus: unchanged, no dropped columns referenced.
    cur.execute("""
        CREATE VIEW vw_ProcessingStatus as
        SELECT
            obj.CommonName,
            p.ProjectFolder,
            pr.ProcessingDateStart,
            pr.Status,
            pr.HoursSpent,
            (SELECT COUNT(*) FROM Images WHERE ProcessingID = pr.ProcessingID AND IsPublished = 1) AS PublishedImages
        FROM ProcessingRuns pr
        JOIN Projects p ON pr.ProjectID = p.ProjectID
        JOIN Objects obj ON p.DSOKey = obj.DSOKey
        ORDER BY pr.ProcessingDateStart DESC
    """)
    print("vw_ObservationSummary, vw_NeedsMoreData, vw_ProcessingStatus recreated.")

    cur.execute("PRAGMA foreign_key_check")
    fk_problems = cur.fetchall()
    if fk_problems:
        raise RuntimeError(f"ABORTING: foreign_key_check found problems: {fk_problems}")

    conn.commit()
    print("\nCOMMIT successful. Phase 1 migration complete.")

except Exception as e:
    conn.rollback()
    print(f"\nERROR -- rolled back, no changes were kept: {e}")
    sys.exit(1)

finally:
    cur.execute("PRAGMA foreign_keys = ON")
    conn.close()
