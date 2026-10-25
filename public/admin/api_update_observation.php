<?php
// ============================================================
// api_update_observation.php  —  Edit hand-maintained Observation fields
//
// POST { "ObservationID": 42, "Filter": "LP", "IntegrationMins": 95.5, "Notes": "..." }
//
// Only the fields present in the body are updated. An empty string (or
// null) clears the field to NULL.
//
// Editable fields and why only these:
//   Filter, Notes     -- hand-entered only; audit_observations.py never
//                        writes them (they're in its MANUAL_DATA_FIELDS, so
//                        a row carrying them is also never auto-deleted).
//   IntegrationMins   -- the audit only fills it when it's NULL and never
//                        touches it once it has a value, so an edit here
//                        sticks. Clearing it lets the next audit recompute it
//                        (GoodLights x ExposureTimeSecs / 60).
// TotalExposures (#Exp) is intentionally NOT editable: the audit resets it
// from the observation folder name every run (the folder name is the
// source of truth -- rename the folder to change it).
//
// DB-only, so this works remotely as well as locally.
// ============================================================

require_once __DIR__ . '/auth_api.php';
require_once __DIR__ . '/db_logger.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$obs_id = isset($body['ObservationID']) ? (int)$body['ObservationID'] : 0;

if ($obs_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'ObservationID required']);
    exit;
}

$sets   = [];
$params = [];

foreach (['Filter' => 50, 'Notes' => 2000] as $field => $max_len) {
    if (!array_key_exists($field, $body)) continue;
    $val = $body[$field];
    $val = ($val === null) ? '' : trim((string)$val);
    if (mb_strlen($val) > $max_len) {
        http_response_code(400);
        echo json_encode(['error' => "$field is too long (max $max_len characters)."]);
        exit;
    }
    $sets[]   = "$field = ?";
    $params[] = ($val === '') ? null : $val;
}

if (array_key_exists('IntegrationMins', $body)) {
    $val = $body['IntegrationMins'];
    $val = ($val === null) ? '' : trim((string)$val);
    if ($val === '') {
        $sets[]   = 'IntegrationMins = ?';
        $params[] = null;
    } elseif (!is_numeric($val) || (float)$val < 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Integ must be a number of minutes (0 or more), or blank.']);
        exit;
    } else {
        $sets[]   = 'IntegrationMins = ?';
        $params[] = round((float)$val, 1);
    }
}

if (!$sets) {
    http_response_code(400);
    echo json_encode(['error' => 'Nothing to update -- send Filter, IntegrationMins and/or Notes.']);
    exit;
}

try {
    $db = get_db();

    $params[] = $obs_id;
    $stmt = $db->prepare('UPDATE Observations SET ' . implode(', ', $sets) . ' WHERE ObservationID = ?');
    $stmt->execute($params);

    if ($stmt->rowCount() === 0) {
        // rowCount() is 0 both for "no such row" and (on some drivers) for
        // "no value actually changed" -- check which.
        $chk = $db->prepare('SELECT 1 FROM Observations WHERE ObservationID = ?');
        $chk->execute([$obs_id]);
        if (!$chk->fetchColumn()) {
            http_response_code(404);
            echo json_encode(['error' => "Observation $obs_id not found."]);
            exit;
        }
    }

    $stmt = $db->prepare('
        SELECT ObservationID   AS "ObservationID",
               Filter          AS "Filter",
               IntegrationMins AS "IntegrationMins",
               Notes           AS "Notes"
        FROM Observations WHERE ObservationID = ?
    ');
    $stmt->execute([$obs_id]);

    echo json_encode(['success' => true, 'observation' => $stmt->fetch(PDO::FETCH_ASSOC)]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
