<?php
// ============================================================
// api_add_project.php  —  Manually create a Projects row for a DSO
//
// Accepts POST: { "DSOKey": "IC342", "ProjectFolder": "ic342_hidden_galaxy" }
//
// This is the single-DSO, in-admin equivalent of pythonscripts/
// sync_projects.py's job: nothing in the app auto-creates a Projects
// row when a new myWorks folder shows up, so Sync Folder refuses with
// "No Project exists yet" until one exists. This endpoint lets that be
// fixed from the admin UI directly instead of requiring a script run.
//
// Matching is case-insensitive against existing ProjectFolder values,
// since Windows folder names are case-insensitive and could otherwise
// let a near-duplicate row through (see the case-insensitivity notes in
// api_sync_folder.php for the same concern).
//
// IsMosaic is intentionally left out of the INSERT -- it has a DB-side
// default (see DB_REWORK_PLAN.md), so this never sets it directly,
// matching sync_projects.py's approach.
// ============================================================

require_once __DIR__ . '/auth_api.php';
require_once __DIR__ . '/db_logger.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$body           = json_decode(file_get_contents('php://input'), true);
$dso_key        = trim($body['DSOKey'] ?? '');
$project_folder = trim($body['ProjectFolder'] ?? '');

if (!$dso_key) {
    http_response_code(400);
    echo json_encode(['error' => 'DSOKey required']);
    exit;
}
if (!$project_folder) {
    http_response_code(400);
    echo json_encode(['error' => 'ProjectFolder required']);
    exit;
}

try {
    $db = get_db();

    // ── DSO must exist ───────────────────────────────────────────────────
    $stmt = $db->prepare('SELECT ObjectTypeID FROM Objects WHERE DSOKey = ?');
    $stmt->execute([$dso_key]);
    $object_type_id = $stmt->fetchColumn();

    if ($object_type_id === false) {
        http_response_code(400);
        echo json_encode(['error' => "DSO $dso_key does not exist -- add it via Quick Add first."]);
        exit;
    }

    // Sun/Moon use a separate hardcoded pipeline (see the solar guard in
    // api_sync_folder.php) and never go through Projects/GalleryImages, so
    // a Project row for them would just be dead weight.
    if ($object_type_id === 'SOLAR_SYSTEM') {
        http_response_code(400);
        echo json_encode(['error' => "Projects aren't used for solar objects (Sun/Moon) -- they're handled by the solar pipeline."]);
        exit;
    }

    // ── Reject a case-insensitive duplicate ProjectFolder ─────────────────
    // ProjectFolder values are unique in practice (one on-disk folder =
    // one row), and Windows folder names are case-insensitive, so compare
    // that way rather than risking two rows for what's really one folder.
    $stmt = $db->prepare('
        SELECT ProjectID AS "ProjectID", DSOKey AS "DSOKey"
        FROM Projects
        WHERE LOWER(ProjectFolder) = LOWER(?)
    ');
    $stmt->execute([$project_folder]);
    $dupe = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($dupe) {
        http_response_code(400);
        echo json_encode([
            'error' => "ProjectFolder '$project_folder' is already registered to DSO {$dupe['DSOKey']} (ProjectID {$dupe['ProjectID']})."
        ]);
        exit;
    }

    // ── Insert ─────────────────────────────────────────────────────────
    $stmt = $db->prepare('INSERT INTO Projects (DSOKey, ProjectFolder) VALUES (?, ?)');
    $stmt->execute([$dso_key, $project_folder]);
    $new_id = db_last_insert_id($db, 'Projects', 'ProjectID');

    echo json_encode([
        'success'       => true,
        'ProjectID'     => $new_id,
        'DSOKey'        => $dso_key,
        'ProjectFolder' => $project_folder,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
