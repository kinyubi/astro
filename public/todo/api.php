<?php
// ============================================================
// public/todo/api.php — To Do List API
// No authentication — personal tool, not sensitive data.
//
// action=list    (GET)  -> { todos: [...], categories: [...] }
// action=add     (POST) -> { id }            body: {item_text, category, priority?}
// action=toggle  (POST) -> { id, is_done }   body: {id}
// action=update  (POST) -> { id }            body: {id, item_text?, category?, priority?}
// action=delete  (POST) -> { deleted }       body: {id}
// ============================================================

require_once __DIR__ . '/../../shared/db.php';

header('Content-Type: application/json');

try {
    $db = get_db();

    $action = $_REQUEST['action'] ?? 'list';
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    switch ($action) {

        case 'list':
            // LOWER(Category) instead of SQLite's "COLLATE NOCASE" -- NOCASE
            // is a SQLite built-in collation name that doesn't exist in
            // Postgres; LOWER() gives the same case-insensitive ordering
            // on both engines without branching per driver.
            $stmt = $db->query("
                SELECT TodoID, Category, ItemText, IsDone, Priority, SortOrder, CreatedDate, CompletedDate
                FROM Todos
                ORDER BY
                    IsDone ASC,
                    CASE Priority WHEN 'High' THEN 0 WHEN 'Medium' THEN 1 WHEN 'Low' THEN 2 ELSE 1 END ASC,
                    LOWER(Category) ASC,
                    SortOrder ASC,
                    TodoID ASC
            ");
            $todos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $cat_stmt = $db->query("SELECT DISTINCT Category FROM Todos ORDER BY LOWER(Category) ASC");
            $categories = array_column($cat_stmt->fetchAll(PDO::FETCH_ASSOC), 'Category');

            echo json_encode(['todos' => $todos, 'categories' => $categories]);
            break;

        case 'add':
            $text = trim($body['item_text'] ?? '');
            $category = trim($body['category'] ?? '') ?: 'General';
            $priority = trim($body['priority'] ?? '') ?: 'Medium';
            if (!in_array($priority, ['High', 'Medium', 'Low'], true)) {
                $priority = 'Medium';
            }
            if ($text === '') {
                http_response_code(400);
                echo json_encode(['error' => 'item_text is required']);
                break;
            }
            $stmt = $db->prepare("INSERT INTO Todos (Category, ItemText, Priority) VALUES (?, ?, ?)");
            $stmt->execute([$category, $text, $priority]);
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
            if (isset($body['item_text'])) {
                $t = trim($body['item_text']);
                if ($t === '') {
                    http_response_code(400);
                    echo json_encode(['error' => 'item_text cannot be empty']);
                    break;
                }
                $fields[] = 'ItemText = ?';
                $params[] = $t;
            }
            if (isset($body['category'])) {
                $fields[] = 'Category = ?';
                $params[] = trim($body['category']) ?: 'General';
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
