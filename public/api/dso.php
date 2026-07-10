<?php
/**
 * DSO Lookup API
 *
 * Returns DSO-level fields plus a list of that DSO's Projects (each with
 * its own ProjectFolder/IsMosaic/MostRecentObservation/TotalLights/
 * TotalIntegrationMins) as JSON. A DSO can have more than one Project
 * (e.g. a standard framing and a separate mosaic framing) -- Project is
 * the top of the hierarchy, not Object; see DB_REWORK_PLAN.md.
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
 *   200  { "success": true, "data": { ...DSO fields..., "Projects": [ {...}, ... ] } }
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
// DSO-level fields (Object only -- no Project data here; a DSO can have
// more than one Project, fetched separately below)
// ---------------------------------------------------------------------------
try {
    $stmt = $db->prepare("
        SELECT
            o.DSOKey,
            o.CommonName,
            c.CatalogID AS PrimaryCatalogID,
            o.ObjectTypeID,
            ot.TypeName AS ObjectTypeName,
            o.ConstellationID,
            con.Name AS ConstellationName,
            o.RAHours,
            o.DecDegrees,
            o.Magnitude,
            o.ObjectSize,
            o.DistanceLY,
            o.SocialBlurb,
            o.Notes,
            o.WantBetter,
            o.SqArcMins
        FROM Objects o
        LEFT JOIN CatalogIDs c ON o.DSOKey = c.DSOKey AND c.IsPrimary = 1
        LEFT JOIN ObjectTypes ot ON o.ObjectTypeID = ot.ObjectTypeID
        LEFT JOIN Constellations con ON o.ConstellationID = con.ConstellationID
        WHERE UPPER(o.DSOKey) = UPPER(:key)
           OR o.DSOKey IN (
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

// ---------------------------------------------------------------------------
// This DSO's Project(s) -- zero, one, or more. Each gets its own computed
// MostRecentObservation/TotalLights/TotalIntegrationMins from Observations.
// ---------------------------------------------------------------------------
try {
    $pstmt = $db->prepare("
        SELECT
            p.ProjectID,
            p.ProjectFolder,
            p.IsMosaic,
            p.Notes AS ProjectNotes,
            (SELECT MAX(ObservationDate) FROM Observations WHERE ProjectID = p.ProjectID) AS MostRecentObservation,
            (SELECT SUM(GoodLights) FROM Observations WHERE ProjectID = p.ProjectID) AS TotalLights,
            (SELECT SUM(IntegrationMins) FROM Observations WHERE ProjectID = p.ProjectID) AS TotalIntegrationMins
        FROM Projects p
        WHERE p.DSOKey = :dsokey
        ORDER BY p.IsMosaic ASC, p.ProjectID ASC
    ");
    $pstmt->execute([':dsokey' => $row['DSOKey']]);
    $projects = $pstmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Query error: ' . $e->getMessage()]);
    exit;
}

// Cast numeric columns that SQLite returns as strings
foreach (['RAHours', 'DecDegrees', 'Magnitude'] as $col) {
    if (isset($row[$col]) && $row[$col] !== null) {
        $row[$col] = (float) $row[$col];
    }
}
foreach ($projects as &$p) {
    foreach (['TotalLights', 'TotalIntegrationMins'] as $col) {
        if (isset($p[$col]) && $p[$col] !== null) {
            $p[$col] = (float) $p[$col];
        }
    }
}
unset($p);

$row['Projects'] = $projects;

echo json_encode(['success' => true, 'data' => $row]);
