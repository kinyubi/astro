<?php
// ============================================================
// diag.php  —  Quick diagnostics for the DSO admin tool
// Delete or restrict access to this file on a production server
// ============================================================

require_once __DIR__ . '/config.php';

header('Content-Type: text/plain');

echo "=== DSO Admin Diagnostics ===\n\n";

// PHP version
echo "PHP version:       " . PHP_VERSION . "\n";

// PDO drivers available
$drivers = PDO::getAvailableDrivers();
echo "PDO drivers:       " . implode(', ', $drivers) . "\n";
echo "pdo_sqlite loaded: " . (in_array('sqlite', $drivers) ? "YES ✓" : "NO ✗  <-- PROBLEM") . "\n\n";

// DB path resolution
echo "DB_PATH constant:  " . DB_PATH . "\n";
echo "File exists:       " . (file_exists(DB_PATH) ? "YES ✓" : "NO ✗  <-- PROBLEM") . "\n";

if (file_exists(DB_PATH)) {
    echo "File size:         " . number_format(filesize(DB_PATH)) . " bytes\n";
    echo "File readable:     " . (is_readable(DB_PATH) ? "YES ✓" : "NO ✗  <-- PROBLEM") . "\n";
}

echo "\n";

// Try connecting and counting rows
if (in_array('sqlite', $drivers) && file_exists(DB_PATH)) {
    try {
        $db = new PDO('sqlite:' . DB_PATH);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        echo "DB connection:     OK ✓\n";

        $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
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
