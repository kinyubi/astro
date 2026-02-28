<?php
/**
 * DSO Visibility Report Handler with Caching
 * Route: /vis or /vis?date=YYYY-MM-DD
 * Force rebuild: /vis?rebuild=1 or /vis?date=YYYY-MM-DD&rebuild=1
 */

// Set execution time limit (Python script may take 30-60 seconds)
set_time_limit(120);

// Get date parameter from query string, default to today in local timezone
// Without this, date() uses server UTC which causes the wrong date after ~5pm Mountain Time
date_default_timezone_set('America/Boise');
$date = isset($_GET['date']) ? (string)$_GET['date'] : date('Y-m-d');

// Get profile parameter from query string, default to 'default'
$profile = isset($_GET['profile']) ? (string)$_GET['profile'] : 'default';

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

// Root of the project: public/vis/ -> public/ -> astro/
$projectRoot = dirname(dirname(__DIR__));

// Cache directory — lives in public/vis/cache/
$cacheDir = __DIR__ . DIRECTORY_SEPARATOR . 'cache';
if (!is_dir($cacheDir)) {
    if (!mkdir($cacheDir, 0755, true)) {
        error_log("Failed to create cache directory: $cacheDir");
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
} else {

    // Generate new report
    header('X-Cache-Status: MISS');
    if ($forceRebuild) {
        header('X-Cache-Rebuild: FORCED');
    }

    // Paths — pythonscripts is at projectRoot/pythonscripts
    $pythonDir = $projectRoot . DIRECTORY_SEPARATOR . 'pythonscripts';
    $pythonScript = $pythonDir . DIRECTORY_SEPARATOR . 'todays_dsos_web.py';
    if (!file_exists($pythonScript)) {
        http_response_code(500);
        echo "<!DOCTYPE html><html><body><h1>Error</h1><p>Python script not found at: $pythonScript</p><p>OS: " . PHP_OS . "</p></body></html>";
        exit;
    }

    // Detect operating system and set Python path accordingly
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $ds = DIRECTORY_SEPARATOR;
        $pythonExe = $pythonDir . $ds . 'venv' . $ds . 'Scripts' . $ds . 'python.exe';
        if (!file_exists($pythonExe)) {
            http_response_code(500);
            echo "<!DOCTYPE html><html><body><h1>Error</h1><p>Python executable not found at: $pythonExe</p><p>OS: " . PHP_OS . "</p></body></html>";
            exit;
        }
        $command = sprintf('"%s" "%s" --date %s --profile %s 2>&1', $pythonExe, $pythonScript, $date, $profile);
        $output = shell_exec($command);

        if ($output === null || trim($output) === '') {
            http_response_code(500);
            echo "<!DOCTYPE html><html><body><h1>Error</h1><p>No output from Python script. Command: <pre>" . htmlspecialchars($command) . "</pre></p></body></html>";
            exit;
        }
        if (stripos($output, 'Traceback') !== false || stripos($output, 'Error:') !== false) {
            http_response_code(500);
            echo "<!DOCTYPE html><html><body><h1>Python Error</h1><pre>" . htmlspecialchars($output) . "</pre></body></html>";
            exit;
        }
    } else {
        $venvDir = $pythonDir . '/venv';
        $activateScript = $venvDir . '/bin/activate';

        if (!is_dir($venvDir) || !file_exists($activateScript)) {
            http_response_code(500);
            echo "<!DOCTYPE html><html><body><h1>Error</h1><p>Virtual environment not found at: $venvDir</p>";
            echo "<p><strong>Solution:</strong> SSH to your server and run:<br>";
            echo "<code>cd " . htmlspecialchars($pythonDir) . " && python3 -m venv venv && source venv/bin/activate && pip install -r requirements.txt</code></p>";
            echo "</body></html>";
            exit;
        }

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
        if (stripos($output, 'Traceback') !== false || stripos($output, 'ModuleNotFoundError') !== false) {
            http_response_code(500);
            echo "<!DOCTYPE html><html><body><h1>Python Error</h1><pre>" . htmlspecialchars($output) . "</pre></body></html>";
            exit;
        }
    }

    // Cache the output
    if (is_dir($cacheDir) && is_writable($cacheDir)) {
        if (file_put_contents($cacheFile, $output) === false) {
            error_log("Failed to write cache file: $cacheFile");
        }
    }
}

// Build cache status footer
if ($useCache) {
    $ageMinutes = round($cacheAge / 60);
    $cacheStatus = sprintf(
        '<div class="info" style="margin-top: 20px; padding: 15px; border-left: 4px solid #7ec8a3; background-color: rgba(126, 200, 163, 0.1); border-radius: 4px;">'
        . '<p style="margin: 0 0 10px 0; color: inherit;"><strong>⚡ Cache Status:</strong> Served from cache (generated %s ago)</p>'
        . '<p style="margin: 0;"><a href="?date=%s&profile=%s&rebuild=1" class="btn" style="display: inline-block; margin: 5px 10px 5px 0; padding: 8px 16px; font-size: 0.9em; background-color: #4a9eff; color: white; text-decoration: none; border-radius: 4px;">🔄 Force Rebuild</a> '
        . '<a href="/cache-manager" class="btn" style="display: inline-block; margin: 5px 10px 5px 0; padding: 8px 16px; font-size: 0.9em; background-color: #4a9eff; color: white; text-decoration: none; border-radius: 4px;">📊 Cache Manager</a> '
        . '<a href="/profiles" class="btn" style="display: inline-block; margin: 5px 10px 5px 0; padding: 8px 16px; font-size: 0.9em; background-color: #4a9eff; color: white; text-decoration: none; border-radius: 4px;">📍 Profiles</a></p>'
        . '</div>',
        $ageMinutes < 60 ? "$ageMinutes minutes" : round($ageMinutes / 60, 1) . ' hours',
        $date,
        $profile
    );
} else {
    $cacheStatus = sprintf(
        '<div class="info" style="margin-top: 20px; padding: 15px; border-left: 4px solid #ffd700; background-color: rgba(255, 215, 0, 0.1); border-radius: 4px;">'
        . '<p style="margin: 0 0 10px 0; color: inherit;"><strong>🔥 Cache Status:</strong> Freshly generated%s</p>'
        . '<p style="margin: 0;"><a href="/cache-manager" class="btn" style="display: inline-block; margin: 5px 10px 5px 0; padding: 8px 16px; font-size: 0.9em; background-color: #4a9eff; color: white; text-decoration: none; border-radius: 4px;">📊 Cache Manager</a> '
        . '<a href="/profiles" class="btn" style="display: inline-block; margin: 5px 10px 5px 0; padding: 8px 16px; font-size: 0.9em; background-color: #4a9eff; color: white; text-decoration: none; border-radius: 4px;">📍 Profiles</a></p>'
        . '</div>',
        $forceRebuild ? ' (forced rebuild)' : ''
    );
}

// Add profile info banner if not using default profile
if ($profile !== 'default') {
    $profileInfo = '<div class="info" style="margin-top: 20px; padding: 15px; border-left: 4px solid #9370db; background-color: rgba(147, 112, 219, 0.1); border-radius: 4px;">'
        . '<p style="margin: 0; color: inherit;"><strong>📍 Profile:</strong> ' . htmlspecialchars($profile) . '</p>'
        . '</div>';
    $output = str_replace('</body>', $profileInfo . '</body>', $output);
}

// Get list of available profiles
$profilesDir = $projectRoot . DIRECTORY_SEPARATOR . 'pythonscripts' . DIRECTORY_SEPARATOR . 'profiles';
$availableProfiles = [];
if (is_dir($profilesDir)) {
    foreach (array_diff(scandir($profilesDir), ['.', '..']) as $p) {
        if (is_file($profilesDir . DIRECTORY_SEPARATOR . $p) && pathinfo($p, PATHINFO_EXTENSION) === 'json') {
            $availableProfiles[] = pathinfo($p, PATHINFO_FILENAME);
        }
    }
}

// Build date/profile controls
$controlsHtml = '<div class="controls"><label for="report-date">Date:</label>'
    . '<input type="date" id="report-date" value="' . $date . '">'
    . '<label for="report-profile">Profile:</label>'
    . '<select id="report-profile">';
foreach ($availableProfiles as $profileName) {
    $selected = ($profileName === $profile) ? ' selected' : '';
    $controlsHtml .= '<option value="' . htmlspecialchars($profileName) . '"' . $selected . '>' . htmlspecialchars($profileName) . '</option>';
}
$controlsHtml .= '</select></div>';

$controlsHtml .= <<<'JS'
<script>
(function() {
    const dateInput    = document.getElementById('report-date');
    const profileSelect = document.getElementById('report-profile');

    function updateReport() {
        const url = '/vis?date=' + encodeURIComponent(dateInput.value)
                  + '&profile=' + encodeURIComponent(profileSelect.value);
        window.location.href = url;
    }

    window.forceRebuild = function() {
        const url = '/vis?date=' + encodeURIComponent(dateInput.value)
                  + '&profile=' + encodeURIComponent(profileSelect.value)
                  + '&rebuild=1';
        window.location.href = url;
    };

    dateInput.addEventListener('change', updateReport);
    profileSelect.addEventListener('change', updateReport);
})();
</script>
JS;

// Inject controls after <h1> and cache status before </body>
$output = preg_replace(
    '/(<h1[^>]*>.*?DSO Visibility Report.*?<\/h1>)/is',
    '$1' . $controlsHtml,
    $output,
    1
);
$output = str_replace('</body>', $cacheStatus . '</body>', $output);

header('Content-Type: text/html; charset=utf-8');
echo $output;
