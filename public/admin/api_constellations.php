<?php
// ============================================================
// api_constellations.php  —  Return all constellations for the dropdown
// ============================================================

require_once __DIR__ . '/auth_api.php';
require_once __DIR__ . '/db_logger.php';

header('Content-Type: application/json');

try {
    $db = get_db();

    $stmt = $db->query('SELECT ConstellationID, Name, GenitiveName FROM Constellations ORDER BY Name');
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
