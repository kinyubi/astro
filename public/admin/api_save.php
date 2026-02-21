<?php
// ============================================================
// api_save.php  —  Save object fields to the SQLite database
//
// Accepts POST with JSON body containing DSOKey + any Object fields.
// Also handles adding CatalogID entries.
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

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

$dso_key = trim($body['DSOKey'] ?? '');
if (!$dso_key) {
    http_response_code(400);
    echo json_encode(['error' => 'DSOKey is required']);
    exit;
}

try {
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA foreign_keys = ON');

    // Allowed Object table columns (never let caller set DSOKey via this path)
    $allowed_cols = [
        'CommonName', 'ObjectTypeID', 'ConstellationID',
        'RAHours', 'DecDegrees', 'Magnitude',
        'AngularSize', 'DistanceLY', 'SocialBlurb',
    ];

    $updates = [];
    $params  = [];
    foreach ($allowed_cols as $col) {
        if (array_key_exists($col, $body)) {
            $updates[] = "$col = :$col";
            $params[":$col"] = $body[$col] === '' ? null : $body[$col];
        }
    }
    $params[':DSOKey'] = $dso_key;

    if ($updates) {
        $updates[] = "LastUpdated = CURRENT_TIMESTAMP";
        $sql = "UPDATE Objects SET " . implode(', ', $updates) . " WHERE DSOKey = :DSOKey";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            // Object doesn't exist yet — INSERT it
            $cols   = array_merge(['DSOKey'], $allowed_cols);
            $values = array_merge([':DSOKey'], array_map(fn($c) => ":$c", $allowed_cols));
            $insert_params = [':DSOKey' => $dso_key];
            foreach ($allowed_cols as $col) {
                $insert_params[":$col"] = isset($body[$col]) && $body[$col] !== '' ? $body[$col] : null;
            }
            $sql = "INSERT INTO Objects (" . implode(',', $cols) . ") VALUES (" . implode(',', $values) . ")";
            $db->prepare($sql)->execute($insert_params);
        }
    }

    // Handle catalog ID additions
    if (!empty($body['CatalogIDs']) && is_array($body['CatalogIDs'])) {
        $stmt = $db->prepare("
            INSERT OR IGNORE INTO CatalogIDs (CatalogID, DSOKey, CatalogName, IsPrimary)
            VALUES (:cid, :dkey, :cname, :primary)
        ");
        foreach ($body['CatalogIDs'] as $entry) {
            $cid = strtoupper(trim($entry['CatalogID'] ?? ''));
            if (!$cid) continue;
            $stmt->execute([
                ':cid'     => $cid,
                ':dkey'    => $dso_key,
                ':cname'   => $entry['CatalogName'] ?? null,
                ':primary' => $entry['IsPrimary'] ?? 0,
            ]);
        }
    }

    echo json_encode(['success' => true, 'DSOKey' => $dso_key]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
