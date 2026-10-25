<?php
// ============================================================
// api_add_project.php  —  Manually create a Projects row for a DSO
//
// Accepts POST: { "DSOKey": "IC342", "ProjectFolder": "ic342_hidden_galaxy" }
//   or, to repoint an existing Project whose folder was renamed on disk:
//         { "DSOKey": "IC342", "ProjectFolder": "...", "ReplaceProjectID": 12 }
//   (only allowed when that Project belongs to the DSO and its current
//   ProjectFolder no longer exists under WORKS_ROOT)
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

// ── Local-only guard ─────────────────────────────────────────────────────
// Add Project needs WORKS_ROOT (the myWorks folder on disk). On the public VPS it
// isn't reachable, so refuse explicitly (localOnly: true tells the admin UI
// to show its "available only when run locally" dialog) instead of carrying
// on and failing silently.
if (!defined('WORKS_ROOT') || !is_dir(WORKS_ROOT)) {
    http_response_code(409);
    echo json_encode([
        'error'     => 'Add Project is available only when the admin panel is run locally.',
        'localOnly' => true,
    ]);
    exit;
}

$body           = json_decode(file_get_contents('php://input'), true);
$dso_key        = trim($body['DSOKey'] ?? '');
$project_folder = trim($body['ProjectFolder'] ?? '');
$replace_id     = !empty($body['ReplaceProjectID']) ? (int)$body['ReplaceProjectID'] : null;

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

    if (!is_dir(WORKS_ROOT . DIRECTORY_SEPARATOR . $project_folder)) {
        http_response_code(400);
        echo json_encode(['error' => "Folder '$project_folder' doesn't exist under " . WORKS_ROOT . "."]);
        exit;
    }

    // ── Repoint an existing (stale) Project instead of inserting ─────────
    if ($replace_id !== null) {
        $stmt = $db->prepare('SELECT DSOKey AS "DSOKey", ProjectFolder AS "ProjectFolder" FROM Projects WHERE ProjectID = ?');
        $stmt->execute([$replace_id]);
        $old = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$old || strcasecmp($old['DSOKey'], $dso_key) !== 0) {
            http_response_code(400);
            echo json_encode(['error' => "ProjectID $replace_id doesn't belong to DSO $dso_key."]);
            exit;
        }
        if ($old['ProjectFolder'] && is_dir(WORKS_ROOT . DIRECTORY_SEPARATOR . $old['ProjectFolder'])) {
            http_response_code(400);
            echo json_encode(['error' => "Project $replace_id's folder '{$old['ProjectFolder']}' still exists on disk -- not repointing it."]);
            exit;
        }
        $stmt = $db->prepare('UPDATE Projects SET ProjectFolder = ? WHERE ProjectID = ?');
        $stmt->execute([$project_folder, $replace_id]);
        echo json_encode([
            'success'        => true,
            'repointed'      => true,
            'ProjectID'      => $replace_id,
            'DSOKey'         => $dso_key,
            'ProjectFolder'  => $project_folder,
            'OldFolder'      => $old['ProjectFolder'],
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

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
