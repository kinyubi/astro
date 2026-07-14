<?php
// ============================================================
// shared/config.php — App-wide DB configuration
//
// Included by every PHP entry point that touches the database
// (admin panel, public gallery, /api/dso.php, check-missing,
// todo) so DB_DRIVER is a single app-wide toggle, not something
// only the admin panel respects.
// ============================================================

// Which database backend the app connects to: 'sqlite' or 'pgsql'.
// Toggle here to switch the ENTIRE app back to SQLite instantly if
// Postgres has an issue.
define('DB_DRIVER', 'pgsql');

// Path to the SQLite database (used when DB_DRIVER === 'sqlite')
define('DB_PATH', __DIR__ . '/../dsodb/astro.db');

// secrets.php lives at C:\laragon7\www\astro\secrets.php — provides
// PG_HOST / PG_PORT / PG_DBNAME / PG_USER / PG_PASSWORD plus the
// Anthropic key and admin credentials.
$_secrets_file = __DIR__ . '/../secrets.php';
if (file_exists($_secrets_file)) {
    require_once $_secrets_file;
} else {
    die('secrets.php not found. Create C:\\laragon7\\www\\astro\\secrets.php with the required constants.');
}
