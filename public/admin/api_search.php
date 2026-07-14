<?php
// ============================================================
// api_search.php  —  Search objects in the database
//
// GET ?q=<search_term>
// Returns matching Objects + their primary CatalogID,
// plus GalleryImages and DSOLinks for each result.
// ============================================================

require_once __DIR__ . '/auth_api.php';
require_once __DIR__ . '/db_logger.php';

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
$searching = strlen($q) >= 1;

try {
    $db = get_db();

    $like = '%' . $q . '%';

    // NOTE on the AS "MixedCase" aliases throughout this file: the live
    // Postgres schema stores table/column names in lowercase (see
    // migrate_lowercase_postgres_identifiers.py), so an unquoted column
    // reference resolves correctly but Postgres *also* labels the output
    // column in lowercase. SQLite preserves whatever case you write. Since
    // all the PHP below (and the admin JS consuming this JSON) expects
    // exact mixed-case keys, every SELECT column needs an explicit quoted
    // alias so both engines return the same casing.

    if ($searching) {
        // LOWER() on both sides keeps this case-insensitive on both SQLite
        // (whose LIKE is case-insensitive by default for ASCII) and Postgres
        // (whose LIKE is case-sensitive) without branching per driver.
        $stmt = $db->prepare("
            SELECT
                o.DSOKey          AS \"DSOKey\",
                o.CommonName      AS \"CommonName\",
                o.ObjectTypeID    AS \"ObjectTypeID\",
                o.ConstellationID AS \"ConstellationID\",
                o.RAHours         AS \"RAHours\",
                o.DecDegrees      AS \"DecDegrees\",
                o.Magnitude       AS \"Magnitude\",
                o.ObjectSize      AS \"ObjectSize\",
                o.SqArcMins       AS \"SqArcMins\",
                o.DistanceLY      AS \"DistanceLY\",
                o.SocialBlurb     AS \"SocialBlurb\",
                o.WantBetter      AS \"WantBetter\",
                o.Notes           AS \"Notes\",
                o.LastUpdated     AS \"LastUpdated\",
                c.CatalogID       AS \"PrimaryCatalogID\"
            FROM Objects o
            LEFT JOIN CatalogIDs c ON o.DSOKey = c.DSOKey AND c.IsPrimary = 1
            WHERE
                LOWER(o.DSOKey)     LIKE LOWER(:q) OR
                LOWER(o.CommonName) LIKE LOWER(:q) OR
                EXISTS (
                    SELECT 1 FROM CatalogIDs ac
                    WHERE ac.DSOKey = o.DSOKey AND LOWER(ac.CatalogID) LIKE LOWER(:q)
                )
            ORDER BY o.DSOKey
            LIMIT 50
        ");
        $stmt->execute([':q' => $like]);
    } else {
        $stmt = $db->prepare("
            SELECT
                o.DSOKey          AS \"DSOKey\",
                o.CommonName      AS \"CommonName\",
                o.ObjectTypeID    AS \"ObjectTypeID\",
                o.ConstellationID AS \"ConstellationID\",
                o.RAHours         AS \"RAHours\",
                o.DecDegrees      AS \"DecDegrees\",
                o.Magnitude       AS \"Magnitude\",
                o.ObjectSize      AS \"ObjectSize\",
                o.SqArcMins       AS \"SqArcMins\",
                o.DistanceLY      AS \"DistanceLY\",
                o.SocialBlurb     AS \"SocialBlurb\",
                o.WantBetter      AS \"WantBetter\",
                o.Notes           AS \"Notes\",
                o.LastUpdated     AS \"LastUpdated\",
                c.CatalogID       AS \"PrimaryCatalogID\"
            FROM Objects o
            LEFT JOIN CatalogIDs c ON o.DSOKey = c.DSOKey AND c.IsPrimary = 1
            ORDER BY o.DSOKey
            LIMIT 200
        ");
        $stmt->execute();
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Collect DSOKeys for batch sub-queries
    $keys = array_column($rows, 'DSOKey');

    if ($keys) {
        $placeholders = implode(',', array_fill(0, count($keys), '?'));

        // ── CatalogIDs ────────────────────────────────────────────────────────
        $cat_stmt = $db->prepare("
            SELECT
                CatalogID AS \"CatalogID\",
                DSOKey    AS \"DSOKey\",
                IsPrimary AS \"IsPrimary\"
            FROM CatalogIDs
            WHERE DSOKey IN ($placeholders)
            ORDER BY IsPrimary DESC, CatalogID
        ");
        $cat_stmt->execute($keys);
        $all_cats = $cat_stmt->fetchAll(PDO::FETCH_ASSOC);

        $cats_by_key = [];
        foreach ($all_cats as $cat) {
            $cats_by_key[$cat['DSOKey']][] = $cat;
        }

        // ── GalleryImages ─────────────────────────────────────────────────────
        $gi_stmt = $db->prepare("
            SELECT
                gi.GalleryImageID AS \"GalleryImageID\",
                gi.DSOKey         AS \"DSOKey\",
                gi.BaseName       AS \"BaseName\",
                gi.Caption        AS \"Caption\",
                gi.PaletteID      AS \"PaletteID\",
                pt.PaletteName    AS \"PaletteName\",
                gi.DateCaptured   AS \"DateCaptured\",
                gi.Copyright      AS \"Copyright\",
                gi.IsOwn          AS \"IsOwn\",
                gi.Attribution    AS \"Attribution\",
                gi.Equipment      AS \"Equipment\",
                gi.ProjectID      AS \"ProjectID\",
                p.ProjectFolder   AS \"ProjectFolder\",
                p.IsMosaic        AS \"IsMosaic\",
                gi.SessionDir     AS \"SessionDir\",
                gi.SortOrder      AS \"SortOrder\",
                gi.IsFeature      AS \"IsFeature\"
            FROM GalleryImages gi
            LEFT JOIN PaletteTreatments pt ON gi.PaletteID = pt.PaletteID
            LEFT JOIN Projects p ON gi.ProjectID = p.ProjectID
            WHERE gi.DSOKey IN ($placeholders)
            ORDER BY gi.DSOKey, gi.SortOrder, gi.GalleryImageID
        ");
        $gi_stmt->execute($keys);
        $all_gi = $gi_stmt->fetchAll(PDO::FETCH_ASSOC);

        $gi_by_key = [];
        foreach ($all_gi as $gi) {
            $gi_by_key[$gi['DSOKey']][] = $gi;
        }

        // ── DSOLinks ──────────────────────────────────────────────────────────
        $lnk_stmt = $db->prepare("
            SELECT
                LinkID    AS \"LinkID\",
                DSOKey    AS \"DSOKey\",
                Label     AS \"Label\",
                URL       AS \"URL\",
                SortOrder AS \"SortOrder\"
            FROM DSOLinks
            WHERE DSOKey IN ($placeholders)
            ORDER BY DSOKey, SortOrder, LinkID
        ");
        $lnk_stmt->execute($keys);
        $all_links = $lnk_stmt->fetchAll(PDO::FETCH_ASSOC);

        $links_by_key = [];
        foreach ($all_links as $lnk) {
            $links_by_key[$lnk['DSOKey']][] = $lnk;
        }

        // ── Projects (informational; full Project-editing UI is Phase 2) ──────
        $proj_stmt = $db->prepare("
            SELECT
                p.ProjectID     AS \"ProjectID\",
                p.DSOKey        AS \"DSOKey\",
                p.ProjectFolder AS \"ProjectFolder\",
                p.IsMosaic      AS \"IsMosaic\",
                p.Notes         AS \"Notes\",
                (SELECT MAX(ObservationDate) FROM Observations WHERE ProjectID = p.ProjectID) AS \"MostRecentObservation\",
                (SELECT SUM(GoodLights) FROM Observations WHERE ProjectID = p.ProjectID) AS \"TotalLights\",
                (SELECT SUM(IntegrationMins)
                    FROM Observations WHERE ProjectID = p.ProjectID) AS \"TotalIntegrationMins\"
            FROM Projects p
            WHERE p.DSOKey IN ($placeholders)
            ORDER BY p.DSOKey, p.ProjectID
        ");
        $proj_stmt->execute($keys);
        $all_projects = $proj_stmt->fetchAll(PDO::FETCH_ASSOC);

        // ── Observations (nested read-only list under each Project) ───────────
        $obs_stmt = $db->prepare("
            SELECT
                o.ObservationID     AS \"ObservationID\",
                o.ProjectID         AS \"ProjectID\",
                o.ObservationDate   AS \"ObservationDate\",
                o.ObservationFolder AS \"ObservationFolder\",
                o.StartTime         AS \"StartTime\",
                o.EndTime           AS \"EndTime\",
                o.ExposureTimeSecs  AS \"ExposureTimeSecs\",
                o.Filter            AS \"Filter\",
                o.TotalExposures    AS \"TotalExposures\",
                o.GoodLights        AS \"GoodLights\",
                o.IntegrationMins   AS \"IntegrationMins\",
                o.Notes             AS \"Notes\"
            FROM Observations o
            JOIN Projects p ON o.ProjectID = p.ProjectID
            WHERE p.DSOKey IN ($placeholders)
            ORDER BY o.ProjectID, o.ObservationDate DESC
        ");
        $obs_stmt->execute($keys);
        $all_obs = $obs_stmt->fetchAll(PDO::FETCH_ASSOC);

        $obs_by_project = [];
        foreach ($all_obs as $obs) {
            $obs_by_project[$obs['ProjectID']][] = $obs;
        }
        foreach ($all_projects as &$proj) {
            $proj['Observations'] = $obs_by_project[$proj['ProjectID']] ?? [];
        }
        unset($proj);

        $projects_by_key = [];
        foreach ($all_projects as $proj) {
            $projects_by_key[$proj['DSOKey']][] = $proj;
        }

        // ── Merge into rows ───────────────────────────────────────────────────
        foreach ($rows as &$row) {
            $k = $row['DSOKey'];
            $row['CatalogIDs']    = $cats_by_key[$k]     ?? [];
            $row['GalleryImages'] = $gi_by_key[$k]       ?? [];
            $row['DSOLinks']      = $links_by_key[$k]    ?? [];
            $row['Projects']      = $projects_by_key[$k] ?? [];
        }
    }

    echo json_encode($rows);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
