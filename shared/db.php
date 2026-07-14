<?php
// ============================================================
// shared/db.php — App-wide DB connection, driver-aware (SQLite/Postgres)
//
// Provides get_db(), which returns a PDO connection to whichever backend
// DB_DRIVER (shared/config.php) points at — 'sqlite' or 'pgsql' — with
// error mode set to exceptions. Used by every PHP entry point that
// touches the database: admin panel, public gallery, /api/dso.php,
// check-missing, todo.
//
// SQLite mode also installs a PDOStatement subclass that logs every
// INSERT/UPDATE/DELETE it executes to remote.log — but ONLY when the
// request originates from a non-local address (i.e. this is the remote
// deployment). Local (Laragon) runs never write to remote.log. This
// logging only applies in SQLite mode; once the app is fully on Postgres
// there's a single shared live DB and the local/remote divergence problem
// this was built for no longer applies.
//
// remote.log entries are raw, executable SQL statements with the
// bound parameter values substituted in, one per line, preceded by a
// timestamp comment — e.g.:
//
//   -- 2026-07-04 14:32:10
//   UPDATE Objects SET CommonName = 'Fighting Dragons of Cepheus' WHERE DSOKey = 'LDN1228';
//
// Also provides:
//   db_driver()        — returns the active driver string ('sqlite'|'pgsql')
//   db_last_insert_id($db, $table, $pk_col) — portable "ID of row I just
//       inserted" helper. SQLite: PDO::lastInsertId(). Postgres: reads
//       back via the sequence associated with $table.$pk_col, which is
//       safer than relying on PDO_PGSQL's lastInsertId() semantics.
//
// Usage — any file that writes to or reads from the DB:
//
//     require_once __DIR__ . '/../../shared/db.php';   // adjust depth
//     $db = get_db();
// ============================================================

require_once __DIR__ . '/config.php';

define('REMOTE_LOG_PATH', __DIR__ . '/../remote.log');

function db_driver(): string
{
    return defined('DB_DRIVER') ? DB_DRIVER : 'sqlite';
}

function db_is_local(): bool
{
    static $is_local = null;
    if ($is_local === null) {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        $is_local = in_array($remote, ['127.0.0.1', '::1', 'localhost'], true);
    }
    return $is_local;
}

class LoggingPDOStatement extends PDOStatement
{
    protected PDO $conn;

    protected function __construct(PDO $conn)
    {
        $this->conn = $conn;
    }

    public function execute(?array $params = null): bool
    {
        if (db_driver() === 'sqlite' && !db_is_local() && $this->isWriteStatement()) {
            $this->logStatement($params);
        }
        return parent::execute($params);
    }

    private function isWriteStatement(): bool
    {
        return (bool) preg_match('/^\s*(INSERT|UPDATE|DELETE)\b/i', $this->queryString);
    }

    private function logStatement(?array $params): void
    {
        $sql  = $this->interpolate($this->queryString, $params ?? []);
        $line = '-- ' . date('Y-m-d H:i:s') . "\n" . rtrim(trim($sql), "; \t\n\r") . ";\n\n";
        @file_put_contents(REMOTE_LOG_PATH, $line, FILE_APPEND | LOCK_EX);
    }

    private function interpolate(string $sql, array $params): string
    {
        if (!$params) return $sql;

        $isAssoc = array_keys($params) !== range(0, count($params) - 1);

        if ($isAssoc) {
            // Replace longest keys first so ":Foo" can't clobber inside ":FooBar"
            $keys = array_keys($params);
            usort($keys, fn($a, $b) => strlen($b) - strlen($a));
            foreach ($keys as $key) {
                $token = ($key[0] === ':') ? $key : ':' . $key;
                $sql   = str_replace($token, $this->quote($params[$key]), $sql);
            }
            return $sql;
        }

        // Positional (?) placeholders, in order
        $values = array_values($params);
        $i = 0;
        return preg_replace_callback('/\?/', function () use (&$i, $values) {
            $v = $this->quote($values[$i] ?? null);
            $i++;
            return $v;
        }, $sql);
    }

    private function quote($value): string
    {
        if ($value === null) return 'NULL';
        if (is_bool($value)) return $value ? '1' : '0';
        if (is_int($value) || is_float($value)) return (string) $value;
        return $this->conn->quote((string) $value);
    }
}

function get_db(): PDO
{
    if (db_driver() === 'pgsql') {
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            PG_HOST, PG_PORT, PG_DBNAME
        );
        $db = new PDO($dsn, PG_USER, PG_PASSWORD);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Postgres enforces foreign keys unconditionally — no pragma needed.
        return $db;
    }

    // SQLite (default)
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_STATEMENT_CLASS, ['LoggingPDOStatement', [$db]]);
    $db->exec('PRAGMA foreign_keys = ON');
    return $db;
}

/**
 * Portable "get the ID of the row I just inserted" helper.
 *
 * SQLite: PDO::lastInsertId() is reliable as-is.
 * Postgres: PDO_PGSQL's lastInsertId() depends on lastval(), which is only
 * safe immediately after the exact INSERT on the exact same connection —
 * fragile if anything else executes in between. Reading back via the
 * table's own default sequence (pg_get_serial_sequence) is more robust
 * and works the same way regardless of call order.
 *
 * @param PDO    $db     connection from get_db()
 * @param string $table  table name the INSERT just ran against
 * @param string $pkCol  primary key / serial column name
 */
function db_last_insert_id(PDO $db, string $table, string $pkCol): int
{
    if (db_driver() === 'pgsql') {
        $stmt = $db->prepare("SELECT currval(pg_get_serial_sequence(?, ?))");
        $stmt->execute([$table, $pkCol]);
        return (int) $stmt->fetchColumn();
    }
    return (int) $db->lastInsertId();
}
