<?php
// ============================================================
// api_delete.php  —  Delete a DSO object (or one GalleryImages row)
//
// POST { "DSOKey": "NGC1234" }                  -> delete the DSO
// POST { "DSOKey": "NGC1234", "Confirm": true } -> delete it even if it
//                                                  has Projects / images
// POST { "GalleryImageID": 42 }                 -> delete one gallery row
//
// Projects.DSOKey and GalleryImages.DSOKey reference Objects with
// ON DELETE RESTRICT (on purpose -- a stray delete must never wipe
// imaging history). So a DSO with any of those is first answered with
// 409 { needsConfirm: true, counts: {...} } and nothing is changed; the
// admin shows the counts and re-sends with Confirm: true. Then, in one
// transaction: GalleryImages, Projects (cascades to Observations,
// ProcessingRuns, ProcessingObservations, Images), DSOLinks, CatalogIDs,
// then Objects. Nothing on disk (myWorks or public/images) is touched.
// ============================================================

require_once __DIR__ . '/auth_api.php';
require_once __DIR__ . '/db_logger.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}

$dso_key        = trim($body['DSOKey']        ?? '');
$gallery_img_id = isset($body['GalleryImageID']) ? (int)$body['GalleryImageID'] : null;
$confirmed      = !empty($body['Confirm']);

// ── Delete a single GalleryImages row ────────────────────────────────────────
if ($gallery_img_id) {
    try {
        $db = get_db();
        $stmt = $db->prepare('DELETE FROM GalleryImages WHERE GalleryImageID = ?');
        $stmt->execute([$gallery_img_id]);
        // Idempotent: Sync Folder already removes rows for fav files it can't
        // find on disk (see api_sync_folder.php's "removed from DB" pass), but
        // it still surfaces those rows in its "warnings" list with a Remove
        // button so Carl can clear them from the results panel. By the time
        // that button is clicked the row is usually already gone, so a
        // rowCount of 0 here just means the housekeeping is already done --
        // treat it as success rather than a 404.
        echo json_encode(['success' => true, 'GalleryImageID' => $gallery_img_id]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── Delete a whole DSO object ─────────────────────────────────────────────────
if (!$dso_key) {
    http_response_code(400);
    echo json_encode(['error' => 'DSOKey is required']);
    exit;
}

function count_of(PDO $db, string $sql, string $key): int {
    $stmt = $db->prepare($sql);
    $stmt->execute([$key]);
    return (int)$stmt->fetchColumn();
}

try {
    $db = get_db();

    $counts = [
        'Projects'      => count_of($db, 'SELECT COUNT(*) FROM Projects WHERE DSOKey = ?', $dso_key),
        'Observations'  => count_of($db, 'SELECT COUNT(*) FROM Observations o JOIN Projects p ON o.ProjectID = p.ProjectID WHERE p.DSOKey = ?', $dso_key),
        'GalleryImages' => count_of($db, 'SELECT COUNT(*) FROM GalleryImages WHERE DSOKey = ?', $dso_key),
        'DSOLinks'      => count_of($db, 'SELECT COUNT(*) FROM DSOLinks WHERE DSOKey = ?', $dso_key),
        'CatalogIDs'    => count_of($db, 'SELECT COUNT(*) FROM CatalogIDs WHERE DSOKey = ?', $dso_key),
    ];

    if (!$confirmed && ($counts['Projects'] > 0 || $counts['GalleryImages'] > 0)) {
        http_response_code(409);
        echo json_encode(['needsConfirm' => true, 'DSOKey' => $dso_key, 'counts' => $counts]);
        exit;
    }

    $db->beginTransaction();

    // Children first: these two are ON DELETE RESTRICT against Objects.
    // Deleting Projects cascades to Observations / ProcessingRuns /
    // ProcessingObservations / Images.
    $db->prepare('DELETE FROM GalleryImages WHERE DSOKey = ?')->execute([$dso_key]);
    $db->prepare('DELETE FROM Projects      WHERE DSOKey = ?')->execute([$dso_key]);
    // These cascade anyway; explicit so the order doesn't depend on FK setup.
    $db->prepare('DELETE FROM DSOLinks      WHERE DSOKey = ?')->execute([$dso_key]);
    $db->prepare('DELETE FROM CatalogIDs    WHERE DSOKey = ?')->execute([$dso_key]);

    $stmt = $db->prepare('DELETE FROM Objects WHERE DSOKey = ?');
    $stmt->execute([$dso_key]);

    if ($stmt->rowCount() === 0) {
        $db->rollBack();
        http_response_code(404);
        echo json_encode(['error' => "Object '$dso_key' not found"]);
        exit;
    }

    $db->commit();
    echo json_encode(['success' => true, 'DSOKey' => $dso_key, 'deleted' => $counts]);

} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) $db->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
