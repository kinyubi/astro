<?php
// ============================================================
// config.php  —  Local configuration for the DSO Admin tool
// ============================================================

// Path to the SQLite database
define('DB_PATH', __DIR__ . '/../../dsodb/astro.db');

// Anthropic API key — loaded from secrets.php which lives outside
// the public folder and should never be committed or deployed.
// secrets.php lives at C:\laragon7\www\astro\secrets.php
$_secrets_file = __DIR__ . '/../../secrets.php';
if (file_exists($_secrets_file)) {
    require_once $_secrets_file;
} else {
    die('secrets.php not found. Create C:\\laragon7\\www\\astro\\secrets.php with: define(\'ANTHROPIC_API_KEY\', \'your-key-here\');');
}

// Anthropic model to use for AI field population
// Haiku is ~10x cheaper than Sonnet and much faster for straightforward lookup tasks
define('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001');
