<?php
// ============================================================
// db_logger.php — Compatibility shim
//
// The actual DB connection logic (get_db(), db_driver(),
// db_last_insert_id(), remote-log-on-write) now lives in
// shared/db.php so it's shared app-wide, not admin-only. This file
// just forwards to it so existing `require_once __DIR__ .
// '/db_logger.php'` calls throughout admin/ keep working unchanged.
// ============================================================

require_once __DIR__ . '/../../shared/db.php';
