<?php
/**
 * DSO Lookup API
 *
 * Returns a single row from vw_GalleryObjects as JSON.
 *
 * Usage:
 *   GET /api/dso.php?key=NGC1976
 *   GET /api/dso.php?key=M42
 *
 * The `key` parameter is matched (case-insensitively) against:
 *   1. Objects.DSOKey
 *   2. Any entry in CatalogIDs.CatalogID (resolves to its DSOKey)
 *
 * Responses:
 *   200  { "success": true, "data": { ...vw_GalleryObjects row... } }
 *   400  { "success": false, "error": "key parameter is required" }
 *   404  { "success": false, "error": "DSO not found: <key>" }
 *   500  { "success": false, "error": "Database error: <message>" }
 */

header('Content-Type: application/json');

// ---------------------------------------------------------------------------
// Only allow GET requests
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'GET required']);
    exit;
}

// ---------------------------------------------------------------------------
// Validate input
// ---------------------------------------------------------------------------
$key = trim($_GET['key'] ?? '');
if ($key === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'key parameter is required']);
    exit;
}

// ---------------------------------------------------------------------------
// Open database
// ---------------------------------------------------------------------------
$dbPath = __DIR__ . '/../../dsodb/astro.db';
if (!file_exists($dbPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database not found']);
    exit;
}

try {
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    exit;
}

// ---------------------------------------------------------------------------
// Query — match on DSOKey directly, or via CatalogIDs alias
// ---------------------------------------------------------------------------
try {
    $stmt = $db->prepare("
        SELECT *
        FROM vw_GalleryObjects
        WHERE UPPER(DSOKey) = UPPER(:key)
           OR DSOKey IN (
               SELECT DSOKey FROM CatalogIDs WHERE UPPER(CatalogID) = UPPER(:key)
           )
        LIMIT 1
    ");
    $stmt->execute([':key' => $key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Query error: ' . $e->getMessage()]);
    exit;
}

if (!$row) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'DSO not found: ' . $key]);
    exit;
}

// Cast numeric columns that SQLite returns as strings
foreach (['RAHours', 'DecDegrees', 'Magnitude', 'TotalLights', 'TotalIntegrationMins'] as $col) {
    if (isset($row[$col]) && $row[$col] !== null) {
        $row[$col] = (float) $row[$col];
    }
}

echo json_encode(['success' => true, 'data' => $row]);
