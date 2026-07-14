<?php
// ============================================================
// api_object_types.php  —  Returns ObjectTypes grouped by category
// ============================================================

require_once __DIR__ . '/auth_api.php';
require_once __DIR__ . '/db_logger.php';

header('Content-Type: application/json');

try {
    $db = get_db();

    $stmt = $db->query("
        SELECT
            ot.ObjectTypeID AS \"ObjectTypeID\",
            ot.TypeName     AS \"TypeName\",
            oc.CategoryID   AS \"CategoryID\",
            oc.CategoryName AS \"CategoryName\"
        FROM ObjectTypes ot
        JOIN ObjectCategories oc ON ot.CategoryID = oc.CategoryID
        ORDER BY oc.CategoryName, ot.TypeName
    ");

    // Group by category
    $grouped = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cat = $row['CategoryName'];
        if (!isset($grouped[$cat])) {
            $grouped[$cat] = [];
        }
        $grouped[$cat][] = ['id' => $row['ObjectTypeID'], 'name' => $row['TypeName']];
    }

    echo json_encode($grouped);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
