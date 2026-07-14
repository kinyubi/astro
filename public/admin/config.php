<?php
// ============================================================
// config.php  —  Local configuration for the DSO Admin tool
// ============================================================

// DB_DRIVER, DB_PATH, and secrets.php (PG_*, ANTHROPIC_API_KEY,
// ADMIN_USERNAME/PASSWORD) are all pulled in from the shared,
// app-wide config so every entry point (admin, gallery, /api/dso.php,
// check-missing, todo) agrees on which database backend is active.
require_once __DIR__ . '/../../shared/config.php';

// Path to the astrophotography works directory (session subdirs live here)
define('WORKS_ROOT', 'C:\\Astronomy\\MyWorks');

// Anthropic model to use for AI field population
// Haiku is ~10x cheaper than Sonnet and much faster for straightforward lookup tasks
define('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001');

// Admin session inactivity timeout (seconds) — shared by auth.php and auth_api.php
define('ADMIN_SESSION_TIMEOUT', 7200); // 2 hours
