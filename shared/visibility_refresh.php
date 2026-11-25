<?php
// ============================================================
// shared/visibility_refresh.php -- recalculate visibility windows in the background
//
//     vr_start(['B150']);          // these DSOs, every profile  (~15-30 s)
//     vr_start_profile('default'); // every DSO and planet, one profile (~30-60 s)
//     vr_forget_profile('cabin');  // a deleted profile: remove its windows
//
// Starts pythonscripts/visibility_windows.py ... -a with the project's
// venv Python (the same one /vis uses), detached, so a web request never
// waits for it. Called when a DSO is added or its RA/Dec changes
// (admin/api_quickadd.php, admin/api_save.php) and when a profile is
// created, edited or deleted (profiles/index.php), so /vis/span/ is right
// without remembering to run anything. Output goes to
// cache/visibility_refresh.log (kept to the last ~200 KB).
// Overlapping runs are safe: visibility_windows.py saves under a database lock.
// ============================================================

/** Path of the venv Python, or null if there isn't one. */
function vr_python(): ?string
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'pythonscripts';
    $py = PHP_OS_FAMILY === 'Windows'
        ? $dir . '\\venv\\Scripts\\python.exe'
        : $dir . '/venv/bin/python';
    return is_file($py) ? $py : null;
}

/** Start visibility_windows.py with these arguments (plus -a), detached. */
function vr_launch(array $args, string $label): bool
{
    $root   = dirname(__DIR__);
    $script = $root . DIRECTORY_SEPARATOR . 'pythonscripts' . DIRECTORY_SEPARATOR . 'visibility_windows.py';
    $logDir = $root . DIRECTORY_SEPARATOR . 'cache';
    $log    = $logDir . DIRECTORY_SEPARATOR . 'visibility_refresh.log';
    if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
    if (is_file($log) && filesize($log) > 200000) {
        @file_put_contents($log, substr((string) file_get_contents($log), -100000), LOCK_EX);
    }
    $py = vr_python();
    $stamp = '--- ' . (new DateTime('now', new DateTimeZone('America/Boise')))->format('Y-m-d H:i:s')
           . ' ' . $label . "\n";
    if ($py === null || !is_file($script)) {
        @file_put_contents($log, $stamp . "Not started: " . ($py === null ? 'no venv Python' : 'no visibility_windows.py') . "\n", FILE_APPEND | LOCK_EX);
        return false;
    }
    @file_put_contents($log, $stamp, FILE_APPEND | LOCK_EX);

    $argStr = implode(' ', array_map('escapeshellarg', $args));
    if (PHP_OS_FAMILY === 'Windows') {
        // start /B: detached from this request. Quoted the way cmd.exe wants.
        $cmd = 'start "" /B "' . $py . '" "' . $script . '" ' . $argStr . ' -a >> "' . $log . '" 2>&1';
        $h = @popen($cmd, 'r');
        if ($h === false) return false;
        pclose($h);
        return true;
    }
    $cmd = 'nohup ' . escapeshellarg($py) . ' ' . escapeshellarg($script) . ' ' . $argStr
         . ' -a >> ' . escapeshellarg($log) . ' 2>&1 < /dev/null &';
    @exec($cmd, $out, $rc);
    return $rc === 0;
}

/** Recalculate these DSO keys (every profile). False (and a log line) if it couldn't start. */
function vr_start(array $keys): bool
{
    $keys = array_values(array_unique(array_filter(array_map(
        fn($k) => strtoupper(trim((string) $k)), $keys))));
    // Keys go on a command line: allow only what DSO keys use.
    $keys = array_values(array_filter($keys, fn($k) => preg_match('/^[A-Z0-9][A-Z0-9 _+.\-]*$/', $k)));
    if (!$keys) return false;
    return vr_launch($keys, implode(' ', $keys));
}

/** Recalculate every DSO and planet for one profile (after it's created or edited). */
function vr_start_profile(string $profile): bool
{
    if (!preg_match('/^[a-z0-9_]+$/', $profile)) return false;   // same rule as profile names
    return vr_launch(['-p', $profile], "profile $profile");
}

/** Remove a deleted profile's windows from both tables. Never throws. */
function vr_forget_profile(string $profile): bool
{
    try {
        require_once __DIR__ . '/db.php';
        $db = get_db();
        foreach (['visibilitywindows', 'planetvisibilitywindows'] as $table) {
            if ($db->query("SELECT to_regclass('public.$table')")->fetchColumn() === null) continue;
            $db->prepare("DELETE FROM $table WHERE profile = ?")->execute([$profile]);
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}
