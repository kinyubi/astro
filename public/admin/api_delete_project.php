<?php
// ============================================================
// api_delete_project.php  —  Delete a Project whose folder is gone
//
// POST { "DSOKey": "NGC6960", "ProjectID": 43 }
//
// Only deletes when ALL of these hold (otherwise 400, nothing changed):
//   * running locally with WORKS_ROOT (myWorks) readable -- so an
//     unplugged drive can never look like "every folder is gone"
//   * the Project belongs to that DSO
//   * its ProjectFolder no longer exists under WORKS_ROOT
//
// Before deleting, its GalleryImages are moved to a live Project of the
// same DSO: the only one if there's just one, otherwise the one whose
// folder contains the image's SessionDir. Images that can't be matched
// are left with ProjectID NULL (still attached to the DSO).
//
// Deleting the Projects row cascades (migrate_fk_cascade.py) to its
// Observations, ProcessingRuns and their ProcessingObservations. The
// admin's automatic Audit Observations rebuilds Observations from disk
// for the remaining Project(s).
//
// Response: { success, ProjectID, ProjectFolder, observationsDeleted,
//             imagesMoved: [ {GalleryImageID, BaseName, ToProjectID} ] }
// ============================================================

require_once __DIR__ . '/auth_api.php';
require_once __DIR__ . '/db_logger.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

if (!defined('WORKS_ROOT') || !is_dir(WORKS_ROOT)) {
    http_response_code(409);
    echo json_encode([
        'error'     => 'Deleting a Project is available only when the admin panel is run locally (myWorks must be reachable).',
        'localOnly' => true,
    ]);
    exit;
}

$body       = json_decode(file_get_contents('php://input'), true) ?: [];
$dso_key    = trim($body['DSOKey'] ?? '');
$project_id = (int)($body['ProjectID'] ?? 0);

if (!$dso_key || !$project_id) {
    http_response_code(400);
    echo json_encode(['error' => 'DSOKey and ProjectID required']);
    exit;
}

function folder_exists(?string $folder): bool {
    return $folder !== null && $folder !== '' && is_dir(WORKS_ROOT . DIRECTORY_SEPARATOR . $folder);
}

try {
    $db = get_db();

    $stmt = $db->prepare('SELECT ProjectID AS "ProjectID", ProjectFolder AS "ProjectFolder" FROM Projects WHERE DSOKey = ? ORDER BY ProjectID');
    $stmt->execute([$dso_key]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $target = null;
    $live   = [];
    foreach ($projects as $p) {
        if ((int)$p['ProjectID'] === $project_id) $target = $p;
        elseif (folder_exists($p['ProjectFolder'])) $live[] = $p;
    }

    if (!$target) {
        http_response_code(400);
        echo json_encode(['error' => "Project $project_id doesn't belong to DSO $dso_key."]);
        exit;
    }
    if (folder_exists($target['ProjectFolder'])) {
        http_response_code(400);
        echo json_encode(['error' => "Project $project_id's folder '{$target['ProjectFolder']}' still exists in myWorks -- not deleting it."]);
        exit;
    }

    $db->beginTransaction();

    // ── Move this Project's gallery images to a live Project ─────────────
    $stmt = $db->prepare('SELECT GalleryImageID AS "GalleryImageID", BaseName AS "BaseName", SessionDir AS "SessionDir" FROM GalleryImages WHERE ProjectID = ?');
    $stmt->execute([$project_id]);
    $images = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $move  = $db->prepare('UPDATE GalleryImages SET ProjectID = ? WHERE GalleryImageID = ?');
    $moved = [];
    foreach ($images as $img) {
        $to = null;
        if (count($live) === 1) {
            $to = (int)$live[0]['ProjectID'];
        } elseif ($img['SessionDir']) {
            foreach ($live as $p) {
                if (is_dir(WORKS_ROOT . DIRECTORY_SEPARATOR . $p['ProjectFolder'] . DIRECTORY_SEPARATOR . $img['SessionDir'])) {
                    $to = (int)$p['ProjectID'];
                    break;
                }
            }
        }
        $move->execute([$to, (int)$img['GalleryImageID']]);
        $moved[] = ['GalleryImageID' => (int)$img['GalleryImageID'], 'BaseName' => $img['BaseName'], 'ToProjectID' => $to];
    }

    $stmt = $db->prepare('SELECT COUNT(*) FROM Observations WHERE ProjectID = ?');
    $stmt->execute([$project_id]);
    $obs_count = (int)$stmt->fetchColumn();

    // Cascades to Observations / ProcessingRuns / ProcessingObservations.
    $db->prepare('DELETE FROM Projects WHERE ProjectID = ?')->execute([$project_id]);

    $db->commit();

    echo json_encode([
        'success'             => true,
        'ProjectID'           => $project_id,
        'ProjectFolder'       => $target['ProjectFolder'],
        'observationsDeleted' => $obs_count,
        'imagesMoved'         => $moved,
    ]);

} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) $db->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
