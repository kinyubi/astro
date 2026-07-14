<?php
// ============================================================
// shared/config.php — App-wide DB configuration
//
// Included by every PHP entry point that touches the database
// (admin panel, public gallery, /api/dso.php, check-missing,
// todo) so DB_DRIVER is a single app-wide toggle, not something
// only the admin panel respects.
//
// DB_DRIVER and connection details are read from db_config.json in
// this same folder -- the SAME file the Python side (pythonscripts/
// db_connect.py) reads. This is deliberate: having the driver toggle
// live in two places (a PHP constant AND a Python constant) is
// exactly the kind of split-brain that caused /vis to silently read
// stale SQLite data while the admin panel wrote to Postgres. One
// file, one toggle, both languages.
// ============================================================

$_db_config_path = __DIR__ . '/db_config.json';
if (!file_exists($_db_config_path)) {
    die('shared/db_config.json not found.');
}
$_db_config = json_decode(file_get_contents($_db_config_path), true);
if (!is_array($_db_config)) {
    die('shared/db_config.json is not valid JSON.');
}

// Which database backend the app connects to: 'sqlite' or 'pgsql'.
// Toggle in shared/db_config.json to switch the ENTIRE app (PHP and
// Python both) back to SQLite instantly if Postgres has an issue.
define('DB_DRIVER', $_db_config['driver']);

// Path to the SQLite database (used when DB_DRIVER === 'sqlite')
define('DB_PATH', $_db_config['sqlite_path']);

// Postgres connection details (used when DB_DRIVER === 'pgsql')
define('PG_HOST', $_db_config['pg_host']);
define('PG_PORT', $_db_config['pg_port']);
define('PG_DBNAME', $_db_config['pg_dbname']);
define('PG_USER', $_db_config['pg_user']);
define('PG_PASSWORD', $_db_config['pg_password']);

// secrets.php lives at C:\laragon7\www\astro\secrets.php -- still
// holds things unrelated to DB connectivity: the Anthropic API key
// and admin login credentials.
$_secrets_file = __DIR__ . '/../secrets.php';
if (file_exists($_secrets_file)) {
    require_once $_secrets_file;
} else {
    die('secrets.php not found. Create C:\\laragon7\\www\\astro\\secrets.php with the required constants.');
}
