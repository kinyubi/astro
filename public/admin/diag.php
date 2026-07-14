<?php
// ============================================================
// diag.php  —  Quick diagnostics for the DSO admin tool
// Delete or restrict access to this file on a production server
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_logger.php';

header('Content-Type: text/plain');

echo "=== DSO Admin Diagnostics ===\n\n";

// PHP version
echo "PHP version:       " . PHP_VERSION . "\n";

// Active driver
$driver = db_driver();
echo "DB_DRIVER:          " . $driver . "\n";

// PDO drivers available
$drivers = PDO::getAvailableDrivers();
echo "PDO drivers:        " . implode(', ', $drivers) . "\n";
$needed = $driver === 'pgsql' ? 'pgsql' : 'sqlite';
echo "pdo_$needed loaded: " . (in_array($needed, $drivers) ? "YES ✓" : "NO ✗  <-- PROBLEM") . "\n\n";

if ($driver === 'sqlite') {
    // DB path resolution (SQLite only)
    echo "DB_PATH constant:  " . DB_PATH . "\n";
    echo "File exists:       " . (file_exists(DB_PATH) ? "YES ✓" : "NO ✗  <-- PROBLEM") . "\n";

    if (file_exists(DB_PATH)) {
        echo "File size:         " . number_format(filesize(DB_PATH)) . " bytes\n";
        echo "File readable:     " . (is_readable(DB_PATH) ? "YES ✓" : "NO ✗  <-- PROBLEM") . "\n";
    }
} else {
    echo "PG_HOST:           " . PG_HOST . "\n";
    echo "PG_PORT:           " . PG_PORT . "\n";
    echo "PG_DBNAME:         " . PG_DBNAME . "\n";
    echo "PG_USER:           " . PG_USER . "\n";
}

echo "\n";

// Try connecting and counting rows
if (in_array($needed, $drivers)) {
    try {
        $db = get_db();
        echo "DB connection:     OK ✓\n";

        if ($driver === 'pgsql') {
            $tables = $db->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        }
        echo "Tables found:      " . implode(', ', $tables) . "\n\n";

        if (in_array('Objects', $tables)) {
            $count = $db->query("SELECT COUNT(*) FROM Objects")->fetchColumn();
            echo "Objects rows:      " . $count . "\n";
        }
        if (in_array('CatalogIDs', $tables)) {
            $count = $db->query("SELECT COUNT(*) FROM CatalogIDs")->fetchColumn();
            echo "CatalogIDs rows:   " . $count . "\n";
        }
        if (in_array('Projects', $tables)) {
            $count = $db->query("SELECT COUNT(*) FROM Projects")->fetchColumn();
            echo "Projects rows:     " . $count . "\n";
        }

    } catch (Exception $e) {
        echo "DB connection:     FAILED ✗\n";
        echo "Error:             " . $e->getMessage() . "\n";
    }
}
