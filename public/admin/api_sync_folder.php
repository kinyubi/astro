<?php
// ============================================================
// api_sync_folder.php  —  Sync Folder button (one DSO)
//
// POST { "DSOKey": "IC1805" }
//      [ "ProjectID": n ]                      only that Project's folder
//      [ "sessionDirHints": { base: session } ] for images with no session
//      [ "delete": true ]                       also delete rows whose _full is gone
//
// The work is gallery_sync_dso() in gallery_sync.php (shared with Sync All
// and scripts/sync_gallery.php). Rows whose _full has gone from
// public/images/full come back in toDelete and are only deleted when the
// request says "delete": true -- the admin asks first.
//
// Response: gallery_sync_dso()'s result (inserted, updated, toDelete,
// deleted, needs_session_dir, misnamed, duplicatesRemoved, staleProjects,
// favWithoutFull, fullWithoutFav, thumbs, mode, projectFolder).
// ============================================================

require_once __DIR__ . '/auth_api.php';
require_once __DIR__ . '/gallery_sync.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

// Needs WORKS_ROOT (myWorks on disk); on the VPS it isn't reachable, so
// refuse (localOnly tells the admin UI to show its "run locally" dialog).
if (!gs_works_root_available()) {
    http_response_code(409);
    echo json_encode([
        'error'     => 'Sync Folder is available only when the admin panel is run locally.',
        'localOnly' => true,
    ]);
    exit;
}

$body    = json_decode(file_get_contents('php://input'), true) ?: [];
$dso_key = trim($body['DSOKey'] ?? '');
if ($dso_key === '') {
    http_response_code(400);
    echo json_encode(['error' => 'DSOKey required']);
    exit;
}

try {
    echo json_encode(gallery_sync_dso(get_db(), $dso_key, [
        'apply'      => true,
        'delete'     => !empty($body['delete']),
        'project_id' => $body['ProjectID'] ?? null,
        'hints'      => $body['sessionDirHints'] ?? [],
    ]));
} catch (GallerySyncError $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
