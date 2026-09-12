<?php
// ============================================================
// api_check_project.php  —  Check whether a DSO has an unregistered
// myWorks folder that needs a Projects row created for it.
//
// GET ?DSOKey=IC342
//
// Read-only check used by the admin UI (openObject() in index.php) to
// offer "Add Project?" right when a DSO is opened, instead of requiring
// the "+ Add Project" button to be found and clicked separately.
//
// Local-mode only (WORKS_ROOT) -- a remote deployment can't see the
// disk, so this always reports needsProject: false there, same as
// api_sync_folder.php's mode detection.
//
// Matching logic mirrors pythonscripts/sync_projects.py: a folder's
// leading underscore-delimited token, uppercased, is compared against
// this DSO's CatalogIDs (and DSOKey itself) case-insensitively; folders
// already registered as ANY Project's ProjectFolder (case-insensitively)
// are excluded. 'sun'/'moon'/planets are skipped -- they never use the
// Projects/GalleryImages pipeline.
//
// This is a convenience check, not a hard requirement: any failure here
// returns needsProject: false (with an 'error' field for debugging)
// rather than a non-200 response, so it can never block opening a DSO.
// ============================================================

require_once __DIR__ . '/auth_api.php';
require_once __DIR__ . '/db_logger.php';

header('Content-Type: application/json');

$dso_key = trim($_GET['DSOKey'] ?? '');
if (!$dso_key) {
    http_response_code(400);
    echo json_encode(['error' => 'DSOKey required']);
    exit;
}

$skip_folders = [
    'sun', 'moon', 'scenery',
    'mercury', 'venus', 'mars', 'jupiter', 'saturn', 'uranus', 'neptune',
];

try {
    if (!defined('WORKS_ROOT') || !is_dir(WORKS_ROOT)) {
        echo json_encode(['needsProject' => false, 'mode' => 'remote']);
        exit;
    }

    $db = get_db();

    // This DSO's own key plus every CatalogID it's known under, uppercased.
    $stmt = $db->prepare('SELECT CatalogID FROM CatalogIDs WHERE DSOKey = ?');
    $stmt->execute([$dso_key]);
    $catalog_ids   = array_map('strtoupper', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $catalog_ids[] = strtoupper($dso_key);
    $catalog_ids   = array_unique($catalog_ids);

    // Every folder already registered to ANY Project, lowercased, as a
    // lookup set.
    $stmt = $db->query('SELECT ProjectFolder FROM Projects');
    $registered = array_flip(array_map('strtolower', array_filter($stmt->fetchAll(PDO::FETCH_COLUMN))));

    $candidates = [];
    foreach (scandir(WORKS_ROOT) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = WORKS_ROOT . DIRECTORY_SEPARATOR . $entry;
        if (!is_dir($path)) continue;
        if (in_array(strtolower($entry), $skip_folders, true)) continue;
        if (isset($registered[strtolower($entry)])) continue;

        $entry_catalog_id = strtoupper(explode('_', $entry)[0]);
        if (in_array($entry_catalog_id, $catalog_ids, true)) {
            $candidates[] = $entry;
        }
    }

    echo json_encode([
        'needsProject' => count($candidates) > 0,
        'mode'         => 'local',
        'folders'      => $candidates,
    ]);

} catch (Exception $e) {
    // Fail quiet -- see header note. Never a hard error for the caller.
    echo json_encode(['needsProject' => false, 'error' => $e->getMessage()]);
}
