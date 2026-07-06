<?php
// ============================================================
// db_logger.php — Central DB connection with remote change logging
//
// Provides get_db(), which returns a PDO connection to DB_PATH with
// PRAGMA foreign_keys = ON already set, and installs a PDOStatement
// subclass that logs every INSERT/UPDATE/DELETE it executes to
// remote.log — but ONLY when the request originates from a non-local
// address (i.e. this is the remote deployment). Local (Laragon) runs
// never write to remote.log.
//
// remote.log entries are raw, executable SQL statements with the
// bound parameter values substituted in, one per line, preceded by a
// timestamp comment — e.g.:
//
//   -- 2026-07-04 14:32:10
//   UPDATE Objects SET CommonName = 'Fighting Dragons of Cepheus' WHERE DSOKey = 'LDN1228';
//
// Purpose: when local and remote astro.db copies diverge, Carl can
// read remote.log and manually re-apply the remote-only changes to
// the local DB rather than running a full (and lossy) two-way sync.
// Carl manages log growth/rotation manually.
//
// Usage — replace this pattern in any admin API file that writes to
// the DB:
//
//     $db = new PDO('sqlite:' . DB_PATH);
//     $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
//     $db->exec('PRAGMA foreign_keys = ON');
//
// with:
//
//     require_once __DIR__ . '/db_logger.php';
//     $db = get_db();
//
// No other code changes needed — logging is transparent to callers.
// ============================================================

require_once __DIR__ . '/config.php';

define('REMOTE_LOG_PATH', __DIR__ . '/../../remote.log');

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
        if (!db_is_local() && $this->isWriteStatement()) {
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
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_STATEMENT_CLASS, ['LoggingPDOStatement', [$db]]);
    $db->exec('PRAGMA foreign_keys = ON');
    return $db;
}
