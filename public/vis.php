<?php
/**
 * DSO Visibility Report Handler with Caching
 * Route: /vis or /vis?date=YYYY-MM-DD
 * Force rebuild: /vis?rebuild=1 or /vis?date=YYYY-MM-DD&rebuild=1
 */

// Set execution time limit (Python script may take 30-60 seconds)
set_time_limit(120);

// Get date parameter from query string, default to today
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// Get profile parameter from query string, default to 'default'
$profile = isset($_GET['profile']) ? $_GET['profile'] : 'default';

// Check if force rebuild is requested
$forceRebuild = isset($_GET['rebuild']) && $_GET['rebuild'] == '1';

// Sanitize profile name (alphanumeric, hyphens, underscores only)
if (!preg_match('/^[a-zA-Z0-9_-]+$/', $profile)) {
    http_response_code(400);
    echo "<!DOCTYPE html><html><body><h1>Error</h1><p>Invalid profile name. Use alphanumeric characters, hyphens, or underscores only.</p></body></html>";
    exit;
}

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo "<!DOCTYPE html><html><body><h1>Error</h1><p>Invalid date format. Use YYYY-MM-DD</p></body></html>";
    exit;
}

// Cache directory setup
$cacheDir = __DIR__ . DIRECTORY_SEPARATOR . 'cache';
if (!is_dir($cacheDir)) {
    if (!mkdir($cacheDir, 0755, true)) {
        error_log("Failed to create cache directory: $cacheDir");
        // Continue without caching rather than failing completely
    }
}

// Cache file path (include profile in cache key)
$cacheFile = $cacheDir . DIRECTORY_SEPARATOR . 'dso_report_' . $profile . '_' . $date . '.html';
$cacheMaxAge = 86400; // 24 hours in seconds

// Check if we should use cached version
$useCache = false;
$cacheAge = 0;
if (!$forceRebuild && file_exists($cacheFile)) {
    $cacheAge = time() - filemtime($cacheFile);
    if ($cacheAge < $cacheMaxAge) {
        $useCache = true;
    }
}

// Serve cached version if available
if ($useCache) {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Cache-Status: HIT');
    header('X-Cache-Age: ' . round($cacheAge / 60) . ' minutes');
    $output = file_get_contents($cacheFile);
    // Will inject cache status footer below
} else {

// Generate new report
header('X-Cache-Status: MISS');
if ($forceRebuild) {
    header('X-Cache-Rebuild: FORCED');
}

// Paths
$pythonDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'pythonscripts';
$pythonScript = $pythonDir . DIRECTORY_SEPARATOR . 'todays_dsos_web.py';
if (!file_exists($pythonScript)) {
    http_response_code(500);
    echo "<!DOCTYPE html><html><body><h1>Error</h1><p>Python script not found at: $pythonScript</p><p>OS: " . PHP_OS . "</p></body></html>";
    exit;
}

// Detect operating system and set Python path accordingly
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    // Windows environment (local development)
    $ds = DIRECTORY_SEPARATOR;
    $pythonExe = $pythonDir . $ds . 'venv' . $ds . 'Scripts' . $ds . 'python.exe';
    if (!file_exists($pythonExe)) {
        http_response_code(500);
        echo "<!DOCTYPE html><html><body><h1>Error</h1><p>Python executable not found at: $pythonExe</p><p>OS: " . PHP_OS . "</p></body></html>";
        exit;
    }
    $command = sprintf('"%s" "%s" --date %s --profile %s 2>&1', $pythonExe, $pythonScript, $date, $profile);
    $output = shell_exec($command);

    // Check if we got output
    if ($output === null || trim($output) === '') {
        http_response_code(500);
        echo "<!DOCTYPE html><html><body><h1>Error</h1><p>No output from Python script. Command: <pre>" . htmlspecialchars($command) . "</pre></p></body></html>";
        exit;
    }

    // Check for Python errors in output
    if (stripos($output, 'Traceback') !== false || stripos($output, 'Error:') !== false) {
        http_response_code(500);
        echo "<!DOCTYPE html><html><body><h1>Python Error</h1><pre>" . htmlspecialchars($output) . "</pre></body></html>";
        exit;
    }
} else {
    // Linux/Unix environment (production server)
    $venvDir = $pythonDir . '/venv';
    $activateScript = $venvDir . '/bin/activate';

    // Check if venv exists
    if (!is_dir($venvDir) || !file_exists($activateScript)) {
        http_response_code(500);
        echo "<!DOCTYPE html><html><body><h1>Error</h1><p>Virtual environment not found at: $venvDir</p></body></html>";
        exit;
    }

    // Build command that activates venv and runs Python script
    $command = sprintf(
        'bash -c "source %s && python %s --date %s --profile %s" 2>&1',
        escapeshellarg($activateScript),
        escapeshellarg($pythonScript),
        escapeshellarg($date),
        escapeshellarg($profile)
    );

    $output = shell_exec($command);

    if ($output === null || trim($output) === '') {
        http_response_code(500);
        echo "<!DOCTYPE html><html><body><h1>Error</h1><p>No output from Python script.</p><p>Command: <pre>" . htmlspecialchars($command) . "</pre></p></body></html>";
        exit;
    }

    // Check for Python errors
    if (stripos($output, 'Traceback') !== false || stripos($output, 'ModuleNotFoundError') !== false) {
        http_response_code(500);
        echo "<!DOCTYPE html><html><body><h1>Python Error</h1><pre>" . htmlspecialchars($output) . "</pre></body></html>";
        exit;
    }
}

    // Cache the output if we have a valid cache directory
    if (is_dir($cacheDir) && is_writable($cacheDir)) {
        if (file_put_contents($cacheFile, $output) === false) {
            error_log("Failed to write cache file: $cacheFile");
            // Continue anyway, just without caching
        }
    }
}

// Add cache status footer to output
$cacheStatus = '';
if ($useCache) {
    $ageMinutes = round($cacheAge / 60);
    $cacheStatus = sprintf(
        '<div class="info" style="margin-top: 20px; border-left-color: #7ec8a3;">'
        . '<p><strong>⚡ Cache Status:</strong> Served from cache (generated %s ago)</p>'
        . '<p><a href="?date=%s&profile=%s&rebuild=1" class="btn" style="display: inline-block; margin-top: 10px; padding: 8px 16px; font-size: 0.9em;">🔄 Force Rebuild</a> '
        . '<a href="/cache-manager.php" class="btn" style="display: inline-block; margin-top: 10px; padding: 8px 16px; font-size: 0.9em;">📊 Cache Manager</a> '
        . '<a href="/profiles.php" class="btn" style="display: inline-block; margin-top: 10px; padding: 8px 16px; font-size: 0.9em;">📍 Profiles</a></p>'
        . '</div>',
        $ageMinutes < 60 ? "$ageMinutes minutes" : round($ageMinutes / 60, 1) . ' hours',
        $date,
        $profile
    );
} else {
    $cacheStatus = sprintf(
        '<div class="info" style="margin-top: 20px; border-left-color: #ffd700;">'
        . '<p><strong>🔥 Cache Status:</strong> Freshly generated%s</p>'
        . '<p><a href="/cache-manager.php" class="btn" style="display: inline-block; margin-top: 10px; padding: 8px 16px; font-size: 0.9em;">📊 Cache Manager</a> '
        . '<a href="/profiles.php" class="btn" style="display: inline-block; margin-top: 10px; padding: 8px 16px; font-size: 0.9em;">📍 Profiles</a></p>'
        . '</div>',
        $forceRebuild ? ' (forced rebuild)' : ''
    );
}

// Add profile info to the output if not using default profile
if ($profile !== 'default') {
    $profileInfo = '<div class="info" style="margin-top: 20px; border-left-color: #9370db;">'
        . '<p><strong>📍 Profile:</strong> ' . htmlspecialchars($profile) . '</p>'
        . '<p><a href="/profiles.php" class="btn" style="display: inline-block; margin-top: 10px; padding: 8px 16px; font-size: 0.9em;">Manage Profiles</a></p>'
        . '</div>';
    $output = str_replace('</body>', $profileInfo . '</body>', $output);
}

// Inject cache status before closing body tag
$output = str_replace('</body>', $cacheStatus . '</body>', $output);

// Set content type to HTML
header('Content-Type: text/html; charset=utf-8');

// Output the HTML from Python script
echo $output;
