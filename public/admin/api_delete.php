<?php
// ============================================================
// api_delete.php  —  Delete a DSO object from the database
//
// Accepts POST with JSON body: { "DSOKey": "NGC1234" }
// Deletes from CatalogIDs first (FK child), then Objects.
// ============================================================

require_once __DIR__ . '/auth_api.php';

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
        $db = new PDO('sqlite:' . DB_PATH);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $db->prepare('DELETE FROM GalleryImages WHERE GalleryImageID = ?');
        $stmt->execute([$gallery_img_id]);
        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['error' => "GalleryImage $gallery_img_id not found"]);
            exit;
        }
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
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA foreign_keys = ON');

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
