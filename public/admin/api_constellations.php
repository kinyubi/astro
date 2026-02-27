<?php
// ============================================================
// api_constellations.php  —  Return all constellations for the dropdown
// ============================================================

require_once __DIR__ . '/auth_api.php';

header('Content-Type: application/json');

try {
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $db->query('SELECT ConstellationID, Name, GenitiveName FROM Constellations ORDER BY Name');
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
