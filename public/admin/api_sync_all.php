<?php
// ============================================================
// api_sync_all.php  —  Sync All button in DSO Admin
//
// POST { }                 sync every DSO with a Project (solar left out);
//                          rows whose _full is gone are only reported
// POST { "delete": true }  the same, and delete those rows
// POST { "dryRun": true }  report only, change nothing
//
// Same work as Sync Folder for each DSO (gallery_sync_all() in
// gallery_sync.php, shared with scripts/sync_gallery.php), plus:
//   unclaimed    web fav/full files no DSO with a Project claims
//   unpublished  MyWorks renders missing from the website or newer than
//                the web copy, and duplicate names (publish_images.py rules)
// ============================================================

require_once __DIR__ . '/auth_api.php';
require_once __DIR__ . '/gallery_sync.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}
if (!gs_works_root_available()) {
    http_response_code(409);
    echo json_encode([
        'error'     => 'Sync All is available only when the admin panel is run locally.',
        'localOnly' => true,
    ]);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
@set_time_limit(300);

try {
    echo json_encode(['success' => true] + gallery_sync_all(get_db(), [
        'apply'  => empty($body['dryRun']),
        'delete' => !empty($body['delete']),
    ]));
} catch (GallerySyncError $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
