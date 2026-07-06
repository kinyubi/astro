"""
migrate_view_project_fallback.py

Fixes vw_GalleryObjects so that DSOs which predate the Projects/Observations
schema (i.e. have no row in Projects) still show their legacy ProjectFolder,
IsMosaic, and MostRecentObservation values, which are stored directly on the
Objects table.

Root cause: vw_GalleryObjects pulled ProjectFolder / IsMosaic / MostRecentObservation
exclusively from a LEFT JOIN against Projects (and Observations for the date).
For DSOs with no Projects row (e.g. LDN1228 and 10 others), those columns came
back NULL even though Objects.ProjectFolder / Objects.IsMosaic /
Objects.MostRecentObservation were populated. This showed up as an empty
"Observation & Project" section in the DSO info modal (public gallery, admin,
and the /vis report).

Fix: redefine the view with COALESCE(p.<col>, o.<col>) so it falls back to the
legacy Objects columns when no Projects row exists. TotalLights and
TotalIntegrationMins have no legacy equivalent on Objects, so they remain NULL
for these DSOs (no per-session data exists for them) -- unaffected by this fix.

Safe to re-run -- always drops and recreates the view from this script's
definition, so running it twice is a no-op the second time.

Usage:
    python migrate_view_project_fallback.py [path_to_astro.db]
"""

import sqlite3, sys, os

DB_PATH = r"C:\laragon7\www\astro\dsodb\astro.db"
args = sys.argv[1:]
if args:
    DB_PATH = args[0]

if not os.path.exists(DB_PATH):
    print(f"ERROR: Database not found at {DB_PATH}")
    sys.exit(1)

NEW_VIEW_SQL = """
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
    COALESCE(p.ProjectFolder, o.ProjectFolder) AS ProjectFolder,
    COALESCE(p.IsMosaic, o.IsMosaic) AS IsMosaic,
    COALESCE(
        (SELECT MAX(ObservationDate) FROM Observations WHERE ProjectID = p.ProjectID),
        o.MostRecentObservation
    ) AS MostRecentObservation,
    (SELECT SUM(GoodLights) FROM Observations WHERE ProjectID = p.ProjectID) AS TotalLights,
    (SELECT SUM(GoodLights * ExposureTimeSecs / 60.0) FROM Observations WHERE ProjectID = p.ProjectID) AS TotalIntegrationMins
FROM Objects o
LEFT JOIN CatalogIDs c ON o.DSOKey = c.DSOKey AND c.IsPrimary = 1
LEFT JOIN ObjectTypes ot ON o.ObjectTypeID = ot.ObjectTypeID
LEFT JOIN ObjectCategories oc ON ot.CategoryID = oc.CategoryID
LEFT JOIN Constellations con ON o.ConstellationID = con.ConstellationID
LEFT JOIN Projects p ON o.DSOKey = p.DSOKey AND p.Status = 'ACTIVE'
"""

conn = sqlite3.connect(DB_PATH)
cur = conn.cursor()

# Show which DSOs this will affect, for visibility before applying
cur.execute("""
    SELECT o.DSOKey, o.CommonName, o.ProjectFolder, o.MostRecentObservation
    FROM Objects o
    LEFT JOIN Projects p ON o.DSOKey = p.DSOKey
    WHERE p.ProjectID IS NULL AND o.ProjectFolder IS NOT NULL
    ORDER BY o.DSOKey
""")
affected = cur.fetchall()

print(f"DSOs with legacy Objects.ProjectFolder but no Projects row ({len(affected)}):")
for dso_key, common_name, project_folder, most_recent in affected:
    print(f"  {dso_key:<12} {common_name or '':<40} folder={project_folder}  last_obs={most_recent}")

print("\nThis script will DROP and recreate vw_GalleryObjects so these DSOs fall back")
print("to their legacy Objects columns for ProjectFolder / IsMosaic / MostRecentObservation.")

answer = input("\nApply? [y/N] ").strip().lower()
if answer != 'y':
    print("Aborted.")
    sys.exit(0)

cur.execute("DROP VIEW IF EXISTS vw_GalleryObjects")
cur.execute(NEW_VIEW_SQL)
conn.commit()
print("Done — vw_GalleryObjects updated.")
conn.close()
