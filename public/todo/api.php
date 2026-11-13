<?php
// ============================================================
// public/todo/api.php — To Do List API
// No authentication — personal tool, not sensitive data.
//
// action=list        (GET)  -> { todos: [...], statuses: [...] }
// action=add         (POST) -> { id }            body: {dsokey?, commonname, equipment?, sessiondate?, notes?, status?, priority?}
// action=toggle      (POST) -> { id, is_done }   body: {id}
// action=update      (POST) -> { id }            body: {id, dsokey?, commonname?, equipment?, sessiondate?, notes?, status?, priority?}
// action=delete      (POST) -> { deleted }       body: {id}
// action=lookup_dso  (GET)  -> { commonname }    query: key
// ============================================================

require_once __DIR__ . '/../../shared/db.php';

header('Content-Type: application/json');

/**
 * The Equipment table's spelling of $code (any case), or null if it isn't
 * an EquipmentID there.
 */
function todo_equipment_id(PDO $db, string $code): ?string {
    $stmt = $db->prepare('SELECT EquipmentID FROM Equipment WHERE UPPER(EquipmentID) = UPPER(?)');
    $stmt->execute([$code]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (string)$id : null;
}

try {
    $db = get_db();

    $action = $_REQUEST['action'] ?? 'list';
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    switch ($action) {

        case 'list':
            // LOWER(Status) instead of SQLite's "COLLATE NOCASE" -- NOCASE
            // is a SQLite built-in collation name that doesn't exist in
            // Postgres; LOWER() gives the same case-insensitive ordering
            // on both engines without branching per driver.
            //
            // AS "MixedCase" aliases: Postgres' live schema is lowercase
            // (see migrate_lowercase_postgres_identifiers.py); the todo
            // frontend JS expects these exact mixed-case JSON keys, and
            // unquoted columns would otherwise come back all-lowercase.
            $stmt = $db->query("
                SELECT
                    TodoID        AS \"TodoID\",
                    DSOKey        AS \"DSOKey\",
                    CommonName    AS \"CommonName\",
                    Equipment     AS \"Equipment\",
                    SessionDate   AS \"SessionDate\",
                    Notes         AS \"Notes\",
                    Status        AS \"Status\",
                    IsDone        AS \"IsDone\",
                    Priority      AS \"Priority\",
                    SortOrder     AS \"SortOrder\",
                    CreatedDate   AS \"CreatedDate\",
                    CompletedDate AS \"CompletedDate\"
                FROM Todos
                ORDER BY
                    IsDone ASC,
                    CASE Priority WHEN 'High' THEN 0 WHEN 'Medium' THEN 1 WHEN 'Low' THEN 2 ELSE 1 END ASC,
                    LOWER(Status) ASC,
                    SortOrder ASC,
                    TodoID ASC
            ");
            $todos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $status_stmt = $db->query("SELECT DISTINCT Status AS \"Status\", LOWER(Status) AS status_sort FROM Todos ORDER BY status_sort ASC");
            $statuses = array_column($status_stmt->fetchAll(PDO::FETCH_ASSOC), 'Status');

            echo json_encode(['todos' => $todos, 'statuses' => $statuses]);
            break;

        case 'lookup_dso':
            $key = trim($_GET['key'] ?? '');
            if ($key === '') {
                echo json_encode(['commonname' => null]);
                break;
            }
            // Case-insensitive: DSO keys are conventionally upper (IC1805),
            // but don't force the caller to get casing exactly right.
            $stmt = $db->prepare("SELECT CommonName AS \"CommonName\" FROM Objects WHERE UPPER(DSOKey) = UPPER(?)");
            $stmt->execute([$key]);
            $name = $stmt->fetchColumn();
            echo json_encode(['commonname' => $name !== false ? $name : null]);
            break;

        case 'add':
            $dsokey = trim($body['dsokey'] ?? '') ?: null;
            $commonname = trim($body['commonname'] ?? '');
            $equipment = trim($body['equipment'] ?? '') ?: null;
            $sessiondate = trim($body['sessiondate'] ?? '') ?: null;
            $notes = mb_substr(trim($body['notes'] ?? ''), 0, 160) ?: null;
            $status = trim($body['status'] ?? '') ?: 'Photograph';
            $priority = trim($body['priority'] ?? '') ?: 'Medium';
            if (!in_array($priority, ['High', 'Medium', 'Low'], true)) {
                $priority = 'Medium';
            }
            if ($equipment !== null) {
                $equipment = todo_equipment_id($db, $equipment);
            }
            if ($commonname === '') {
                http_response_code(400);
                echo json_encode(['error' => 'commonname is required']);
                break;
            }
            $stmt = $db->prepare("INSERT INTO Todos (DSOKey, CommonName, Equipment, SessionDate, Notes, Status, Priority) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$dsokey, $commonname, $equipment, $sessiondate, $notes, $status, $priority]);
            echo json_encode(['id' => db_last_insert_id($db, 'Todos', 'TodoID')]);
            break;

        case 'toggle':
            $id = (int)($body['id'] ?? 0);
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'id is required']);
                break;
            }
            $row = $db->prepare("SELECT IsDone FROM Todos WHERE TodoID = ?");
            $row->execute([$id]);
            $current = $row->fetchColumn();
            if ($current === false) {
                http_response_code(404);
                echo json_encode(['error' => 'not found']);
                break;
            }
            $newVal = $current ? 0 : 1;
            $stmt = $db->prepare("UPDATE Todos SET IsDone = ?, CompletedDate = ? WHERE TodoID = ?");
            $stmt->execute([$newVal, $newVal ? date('Y-m-d H:i:s') : null, $id]);
            echo json_encode(['id' => $id, 'is_done' => $newVal]);
            break;

        case 'update':
            $id = (int)($body['id'] ?? 0);
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'id is required']);
                break;
            }
            $fields = [];
            $params = [];
            if (isset($body['dsokey'])) {
                $fields[] = 'DSOKey = ?';
                $params[] = trim($body['dsokey']) ?: null;
            }
            if (isset($body['commonname'])) {
                $t = trim($body['commonname']);
                if ($t === '') {
                    http_response_code(400);
                    echo json_encode(['error' => 'commonname cannot be empty']);
                    break;
                }
                $fields[] = 'CommonName = ?';
                $params[] = $t;
            }
            if (isset($body['equipment'])) {
                $e = trim($body['equipment']);
                if ($e !== '') {
                    $known = todo_equipment_id($db, $e);
                    if ($known === null) {
                        http_response_code(400);
                        echo json_encode(['error' => "equipment must be one of the codes in the Equipment table (not $e)"]);
                        break;
                    }
                    $e = $known;
                }
                $fields[] = 'Equipment = ?';
                $params[] = $e ?: null;
            }
            if (isset($body['sessiondate'])) {
                $fields[] = 'SessionDate = ?';
                $params[] = trim($body['sessiondate']) ?: null;
            }
            if (isset($body['notes'])) {
                $fields[] = 'Notes = ?';
                $params[] = mb_substr(trim($body['notes']), 0, 160) ?: null;
            }
            if (isset($body['status'])) {
                $fields[] = 'Status = ?';
                $params[] = trim($body['status']) ?: 'Photograph';
            }
            if (isset($body['priority'])) {
                $p = trim($body['priority']);
                if (!in_array($p, ['High', 'Medium', 'Low'], true)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'priority must be High, Medium, or Low']);
                    break;
                }
                $fields[] = 'Priority = ?';
                $params[] = $p;
            }
            if (!$fields) {
                echo json_encode(['id' => $id]);
                break;
            }
            $params[] = $id;
            $stmt = $db->prepare("UPDATE Todos SET " . implode(', ', $fields) . " WHERE TodoID = ?");
            $stmt->execute($params);
            echo json_encode(['id' => $id]);
            break;

        case 'delete':
            $id = (int)($body['id'] ?? $_REQUEST['id'] ?? 0);
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'id is required']);
                break;
            }
            $stmt = $db->prepare("DELETE FROM Todos WHERE TodoID = ?");
            $stmt->execute([$id]);
            echo json_encode(['deleted' => $id]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
