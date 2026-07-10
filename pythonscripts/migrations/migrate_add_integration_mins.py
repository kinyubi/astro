"""
migrate_add_integration_mins.py

SUPERSEDES migrate_add_manual_integration_override.py -- do not run that
script; this one replaces it with a simpler design per Carl's correction.

Adds Observations.IntegrationMins (nullable REAL) as a real, persisted
field -- not a computed-on-read expression, and not a separate "override"
column layered on top of a calculation. The rule is simple:

  - If IntegrationMins is blank (NULL), it gets calculated and the field is
    populated. Normally GoodLights * ExposureTimeSecs / 60.0; if GoodLights
    is 0 or missing, falls back to TotalExposures * ExposureTimeSecs / 60.0
    instead of writing zero.
  - If IntegrationMins already has a value, it is left alone -- this is
    what lets Carl manually correct it (e.g. when an observation folder
    includes FIT files carried over from a previous night, inflating the
    naive calculation beyond what was actually shot that night).

This script does two things:
  1. Adds the column (skipped if it already exists).
  2. Runs the fill-if-blank calculation as a single bulk UPDATE, separate
     from any per-row insert/update logic elsewhere (e.g.
     audit_observations.py) -- per Carl's instruction to take the
     calculation out of the row-level update SQL and do it as a distinct
     "post update" step instead.

Also recreates vw_GalleryObjects, vw_ObservationSummary, and
vw_NeedsMoreData to simply read/sum IntegrationMins directly -- no more
inline GoodLights * ExposureTimeSecs computation or COALESCE in the views
themselves; the field is always either already correct or freshly filled
by this script's bulk step before the views are queried.

Reporting: each row to be filled shows its own calculated integration
minutes, and a per-project "Projected Total Integration Time" summary shows
each touched project's current total alongside what it will become once
this run's fills are applied.

Tested against a sandbox DB before being placed here -- verified the
column adds cleanly, the fill step correctly computes and stores values
only for blank rows, a manually-set value survives a second run untouched,
rows missing both GoodLights/TotalExposures and ExposureTimeSecs correctly
stay blank, and the GoodLights-zero-falls-back-to-TotalExposures case.

Safe to re-run -- ADD COLUMN is skipped if it already exists; the fill
step only ever touches rows where IntegrationMins IS NULL, so re-running
never disturbs a value you've manually set.

Usage:
    python migrate_add_integration_mins.py [path_to_astro.db]
"""

import sqlite3
import sys
import os

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

cur.execute("PRAGMA table_info(Observations)")
existing_cols = {row[1] for row in cur.fetchall()}
needs_column = 'IntegrationMins' not in existing_cols


def calc_mins(good_lights, total_exposures, exposure_secs):
    """Same fallback rule as audit_observations.py: GoodLights normally,
    TotalExposures if GoodLights is 0/missing. None if not calculable."""
    if exposure_secs is None:
        return None
    if good_lights:
        return round(good_lights * exposure_secs / 60.0, 1)
    if total_exposures:
        return round(total_exposures * exposure_secs / 60.0, 1)
    return None


base_where = "o.ExposureTimeSecs IS NOT NULL AND ((o.GoodLights IS NOT NULL AND o.GoodLights > 0) OR (o.TotalExposures IS NOT NULL AND o.TotalExposures > 0))"

cur.execute(f"""
    SELECT o.ObservationID, o.ProjectID, o.ObservationFolder, o.ObservationDate,
           p.ProjectFolder, p.DSOKey, o.GoodLights, o.TotalExposures, o.ExposureTimeSecs
    FROM Observations o
    JOIN Projects p ON o.ProjectID = p.ProjectID
    WHERE {base_where} {"AND o.IntegrationMins IS NULL" if not needs_column else ""}
""")
to_fill = cur.fetchall()

# Current per-project totals, straight from the DB, before this run's fill.
# (If the column doesn't exist yet, there's nothing to sum -- every project
# starts at 0.)
current_totals = {}
if not needs_column:
    cur.execute("""
        SELECT p.ProjectID, p.ProjectFolder, p.DSOKey, SUM(o.IntegrationMins) AS total
        FROM Projects p
        LEFT JOIN Observations o ON o.ProjectID = p.ProjectID
        GROUP BY p.ProjectID
    """)
    for row in cur.fetchall():
        current_totals[row['ProjectID']] = row['total'] or 0.0

project_deltas = {}   # ProjectID -> [ProjectFolder, DSOKey, delta_minutes]
print("=" * 70)
print("Add Observations.IntegrationMins -- preview")
print("=" * 70)
print(f"\nAdd column Observations.IntegrationMins (REAL, nullable): "
      f"{'yes' if needs_column else 'already exists -- skipping'}")
print(f"\nRows to calculate and fill (currently blank, and either GoodLights"
      f" or TotalExposures usable, plus ExposureTimeSecs present): {len(to_fill)}")
for r in to_fill[:20]:
    mins = calc_mins(r['GoodLights'], r['TotalExposures'], r['ExposureTimeSecs'])
    obs_label = r['ObservationFolder'] if r['ObservationFolder'] else f"observed {r['ObservationDate']}"
    if r['GoodLights']:
        basis = f"{r['GoodLights']} (GoodLights)"
    else:
        basis = f"{r['TotalExposures']} (TotalExposures fallback, GoodLights was 0/blank)"
    print(f"  {r['ProjectFolder']} / {obs_label}: "
          f"{basis} x {r['ExposureTimeSecs']}s -> {mins} min")
if len(to_fill) > 20:
    print(f"  ... and {len(to_fill) - 20} more")

for r in to_fill:
    mins = calc_mins(r['GoodLights'], r['TotalExposures'], r['ExposureTimeSecs'])
    if mins is None:
        continue
    pid = r['ProjectID']
    if pid not in project_deltas:
        project_deltas[pid] = [r['ProjectFolder'], r['DSOKey'], 0.0]
    project_deltas[pid][2] += mins

print(f"\nProjected Total Integration Time by project ({len(project_deltas)} touched):")
for pid, (project_folder, dso_key, delta) in project_deltas.items():
    before = round(current_totals.get(pid, 0.0), 1)
    after = round(before + delta, 1)
    print(f"  {dso_key} ({project_folder}): {before} min -> {after} min")

print("\nWill recreate these views to read IntegrationMins directly (no more")
print("inline calculation or COALESCE in the view itself):")
print("  vw_GalleryObjects (TotalIntegrationMins)")
print("  vw_ObservationSummary (IntegrationMins)")
print("  vw_NeedsMoreData (TotalIntegrationMins)")

answer = input("\nApply? [y/N] ").strip().lower()
if answer != 'y':
    print("Aborted. No changes made.")
    sys.exit(0)

try:
    cur.execute("PRAGMA foreign_keys = OFF")
    cur.execute("BEGIN TRANSACTION")

    if needs_column:
        cur.execute("ALTER TABLE Observations ADD COLUMN IntegrationMins REAL")
        print("Added Observations.IntegrationMins.")

    # Normally GoodLights * ExposureTimeSecs / 60. Falls back to
    # TotalExposures * ExposureTimeSecs / 60 when GoodLights is 0 or NULL,
    # so a real observation never gets silently recorded as zero minutes.
    cur.execute("""
        UPDATE Observations
        SET IntegrationMins = ROUND(
            CASE
                WHEN GoodLights IS NOT NULL AND GoodLights > 0
                    THEN GoodLights * ExposureTimeSecs
                ELSE TotalExposures * ExposureTimeSecs
            END / 60.0, 1)
        WHERE IntegrationMins IS NULL
          AND ExposureTimeSecs IS NOT NULL
          AND (
                (GoodLights IS NOT NULL AND GoodLights > 0)
                OR (TotalExposures IS NOT NULL AND TotalExposures > 0)
          )
    """)
    print(f"Filled IntegrationMins for {cur.rowcount} row(s).")

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
            (SELECT SUM(IntegrationMins) FROM Observations WHERE ProjectID = p.ProjectID) AS TotalIntegrationMins
        FROM Objects o
        LEFT JOIN CatalogIDs c ON o.DSOKey = c.DSOKey AND c.IsPrimary = 1
        LEFT JOIN ObjectTypes ot ON o.ObjectTypeID = ot.ObjectTypeID
        LEFT JOIN ObjectCategories oc ON ot.CategoryID = oc.CategoryID
        LEFT JOIN Constellations con ON o.ConstellationID = con.ConstellationID
        LEFT JOIN Projects p ON o.DSOKey = p.DSOKey
    """)

    cur.execute("DROP VIEW IF EXISTS vw_ObservationSummary")
    cur.execute("""
        CREATE VIEW vw_ObservationSummary AS
        SELECT
            o.ObservationDate,
            p.ProjectFolder,
            obj.CommonName,
            e.EquipmentID,
            o.GoodLights,
            o.ExposureTimeSecs,
            o.IntegrationMins
        FROM Observations o
        JOIN Projects p ON o.ProjectID = p.ProjectID
        JOIN Objects obj ON p.DSOKey = obj.DSOKey
        JOIN Equipment e ON o.EquipmentID = e.EquipmentID
        ORDER BY o.ObservationDate DESC
    """)

    cur.execute("DROP VIEW IF EXISTS vw_NeedsMoreData")
    cur.execute("""
        CREATE VIEW vw_NeedsMoreData AS
        SELECT
            obj.DSOKey,
            obj.CommonName,
            p.ProjectFolder,
            SUM(o.GoodLights) AS TotalLights,
            SUM(o.IntegrationMins) AS TotalIntegrationMins,
            MAX(o.ObservationDate) AS LastObserved
        FROM Objects obj
        JOIN Projects p ON obj.DSOKey = p.DSOKey
        LEFT JOIN Observations o ON p.ProjectID = o.ProjectID
        GROUP BY obj.DSOKey
        HAVING TotalIntegrationMins < 120 OR TotalIntegrationMins IS NULL
        ORDER BY TotalIntegrationMins ASC
    """)

    conn.commit()
    print("\nCOMMIT successful.")
except Exception as e:
    conn.rollback()
    print(f"\nERROR -- rolled back, no changes were kept: {e}")
    sys.exit(1)
finally:
    cur.execute("PRAGMA foreign_keys = ON")
    conn.close()
