<?php
// ============================================================
// api_delete.php  —  Delete a DSO object from the database
//
// Accepts POST with JSON body: { "DSOKey": "NGC1234" }
// Deletes from CatalogIDs first (FK child), then Objects.
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
        // treat it as success rather than a 404, so confirming "Remove" always
        // just quietly finishes the job instead of surfacing a delete error.
        echo json_encode(['success' => true, 'GalleryImageID' => $gallery_img_id]);
    } catch (Exception $e) {
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

try {
    $db = get_db();

    $db->beginTransaction();

    // Delete child rows first to satisfy FK constraints
    $stmt = $db->prepare('DELETE FROM CatalogIDs WHERE DSOKey = :key');
    $stmt->execute([':key' => $dso_key]);

    // Delete the object itself
    $stmt = $db->prepare('DELETE FROM Objects WHERE DSOKey = :key');
    $stmt->execute([':key' => $dso_key]);

    if ($stmt->rowCount() === 0) {
        $db->rollBack();
        http_response_code(404);
        echo json_encode(['error' => "Object '$dso_key' not found"]);
        exit;
    }

    $db->commit();
    echo json_encode(['success' => true, 'DSOKey' => $dso_key]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
