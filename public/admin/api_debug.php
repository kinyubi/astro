<?php
require_once __DIR__ . '/auth_api.php';
header('Content-Type: application/json');
try {
    $db = new PDO('sqlite:' . DB_PATH);
    $counts = [];
    foreach (['Objects','CatalogIDs','Projects','Observations','Constellations','ObjectTypes'] as $t) {
        $counts[$t] = $db->query("SELECT COUNT(*) FROM $t")->fetchColumn();
    }
    // Sample first 3 objects if any
    $sample = $db->query("SELECT DSOKey, CommonName FROM Objects LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['counts' => $counts, 'sample' => $sample]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
