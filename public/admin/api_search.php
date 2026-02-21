<?php
// ============================================================
// api_search.php  —  Search objects in the database
//
// GET ?q=<search_term>
// Returns matching Objects + their primary CatalogID
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

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
                o.AngularSize,
                o.DistanceLY,
                o.SocialBlurb,
                o.LastUpdated,
                c.CatalogID AS PrimaryCatalogID
            FROM Objects o
            LEFT JOIN CatalogIDs c ON o.DSOKey = c.DSOKey AND c.IsPrimary = 1
            WHERE
                o.DSOKey      LIKE :q OR
                o.CommonName  LIKE :q OR
                c.CatalogID   LIKE :q
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
                o.AngularSize,
                o.DistanceLY,
                o.SocialBlurb,
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

    // Also fetch all catalog IDs for each result
    $keys = array_column($rows, 'DSOKey');
    if ($keys) {
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $cat_stmt = $db->prepare("
            SELECT CatalogID, DSOKey, CatalogName, IsPrimary
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
        foreach ($rows as &$row) {
            $row['CatalogIDs'] = $cats_by_key[$row['DSOKey']] ?? [];
        }
    }

    echo json_encode($rows);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
