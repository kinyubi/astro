<?php
/**
 * DSO Visibility Report Handler
 * Route: /vis or /vis?date=YYYY-MM-DD
 */

// Set execution time limit (Python script may take 30-60 seconds)
set_time_limit(120);

// Get date parameter from query string, default to today
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo "<!DOCTYPE html><html><body><h1>Error</h1><p>Invalid date format. Use YYYY-MM-DD</p></body></html>";
    exit;
}

// Paths
$pythonDir = dirname(__DIR__) . DIRECTORY_SEPARATOR .  'pythonscripts';
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
    $command = sprintf('"%s" "%s" 2>&1', $pythonExe, $pythonScript);
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
    // Use bash to source activate and then run python
    $command = sprintf(
        'bash -c "source %s && python %s" 2>&1',
        escapeshellarg($activateScript),
        escapeshellarg($pythonScript)
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

// Set content type to HTML
header('Content-Type: text/html; charset=utf-8');

// Output the HTML from Python script
echo $output;
