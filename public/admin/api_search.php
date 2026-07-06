<?php
// ============================================================
// api_search.php  —  Search objects in the database
//
// GET ?q=<search_term>
// Returns matching Objects + their primary CatalogID,
// plus GalleryImages and DSOLinks for each result.
// ============================================================

require_once __DIR__ . '/auth_api.php';

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
$searching = strlen($q) >= 1;

try {
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $like = '%' . $q . '%';

    if ($searching) {
        $stmt = $db->prepare("
            SELECT
                o.DSOKey,
                o.CommonName,
                o.ObjectTypeID,
                o.ConstellationID,
                o.RAHours,
                o.DecDegrees,
                o.Magnitude,
                o.ObjectSize,
                o.SqArcMins,
                o.DistanceLY,
                o.SocialBlurb,
                o.WantBetter,
                o.Notes,
                o.LastUpdated,
                c.CatalogID AS PrimaryCatalogID
            FROM Objects o
            LEFT JOIN CatalogIDs c ON o.DSOKey = c.DSOKey AND c.IsPrimary = 1
            WHERE
                o.DSOKey     LIKE :q OR
                o.CommonName LIKE :q OR
                EXISTS (
                    SELECT 1 FROM CatalogIDs ac
                    WHERE ac.DSOKey = o.DSOKey AND ac.CatalogID LIKE :q
                )
            ORDER BY o.DSOKey
            LIMIT 50
        ");
        $stmt->execute([':q' => $like]);
    } else {
        $stmt = $db->prepare("
            SELECT
                o.DSOKey,
                o.CommonName,
                o.ObjectTypeID,
                o.ConstellationID,
                o.RAHours,
                o.DecDegrees,
                o.Magnitude,
                o.ObjectSize,
                o.SqArcMins,
                o.DistanceLY,
                o.SocialBlurb,
                o.WantBetter,
                o.Notes,
                o.LastUpdated,
                c.CatalogID AS PrimaryCatalogID
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
            SELECT CatalogID, DSOKey, IsPrimary
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
                gi.GalleryImageID,
                gi.DSOKey,
                gi.BaseName,
                gi.Caption,
                gi.PaletteID,
                pt.PaletteName,
                gi.DateCaptured,
                gi.Copyright,
                gi.IsOwn,
                gi.Attribution,
                gi.Equipment,
                gi.ProjectID,
                p.ProjectFolder,
                p.IsMosaic,
                gi.SessionDir,
                gi.SortOrder,
                gi.IsFeature
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
            SELECT LinkID, DSOKey, Label, URL, SortOrder
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
            SELECT ProjectID, DSOKey, ProjectFolder, IsMosaic, Notes
            FROM Projects
            WHERE DSOKey IN ($placeholders)
            ORDER BY DSOKey, ProjectID
        ");
        $proj_stmt->execute($keys);
        $all_projects = $proj_stmt->fetchAll(PDO::FETCH_ASSOC);

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
