<?php
// ============================================================
// api_audit_observations.php  —  Audit Observations against myWorks disk
//
// POST { "DSOKey": "IC1805" }                  -- audits EVERY Project of the DSO
// POST { "DSOKey": "IC1805", "ProjectID": 7 }   -- audits just that one
//
// With more than one Project, the script is run once per ProjectFolder and
// the results are merged (list fields concatenated, integrationFilled
// summed). A Project whose run fails doesn't stop the others; its failure
// is reported in "errors": [ {ProjectFolder, error, output?} ]. Only if
// every Project fails is the whole response an error.
//
// Thin wrapper around pythonscripts/audit_observations.py's scoped,
// non-interactive mode (--project-folder=<folder> --json). That script is
// the single source of truth for how disk observation folders map to
// Observations rows (date-boundary FIT matching, ambiguous-match
// detection, IntegrationMins calculation, etc.) -- this endpoint does not
// duplicate any of that logic, it just runs the script for one Project's
// folder and relays its JSON result.
//
// Same local-mode requirement as api_sync_folder.php: this only works
// when WORKS_ROOT (C:\Astronomy\MyWorks) is reachable from wherever PHP
// is actually running, i.e. from Carl's own machine via Laragon, not the
// public-facing site.
//
// Response (success):
// {
//   "success": true,
//   "applied": true,
//   "created":  [ {DSOKey, Top, ObservationFolder} ],
//   "updated":  [ {DSOKey, Top, Sub, Updates} ],
//   "orphaned": [ {DSOKey, Top, ObservationFolder} ],
//   "ambiguous": [ {DSOKey, Top, Sub, Candidates} ],
//   "unrecognized": [ {Top, Sub} ],
//   "legacyNoFolder": [ {DSOKey, Top, ObservationDate} ],
//   "projectedIntegration": [ {DSOKey, Top, CurrentMins, ProjectedMins} ],
//   "integrationFilled": 3
// }
// ============================================================

require_once __DIR__ . '/auth_api.php';
require_once __DIR__ . '/db_logger.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$body    = json_decode(file_get_contents('php://input'), true);
$dso_key = trim($body['DSOKey'] ?? '');

if (!$dso_key) {
    http_response_code(400);
    echo json_encode(['error' => 'DSOKey required']);
    exit;
}

if (!defined('WORKS_ROOT') || !is_dir(WORKS_ROOT)) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Audit Observations needs access to ' .
                   (defined('WORKS_ROOT') ? WORKS_ROOT : 'C:\\Astronomy\\MyWorks') .
                   ' -- run this from your own machine (Laragon), not the public site.'
    ]);
    exit;
}

// Runs audit_observations.py for one ProjectFolder. Returns the decoded
// JSON result, or ['error' => ..., 'output' => ...] on failure.
function run_audit_for_folder(string $project_folder): array {
    $projectRoot  = realpath(__DIR__ . '/../..');
    $pythonDir    = $projectRoot . DIRECTORY_SEPARATOR . 'pythonscripts';
    $pythonScript = $pythonDir . DIRECTORY_SEPARATOR . 'audit_observations.py';

    if (!file_exists($pythonScript)) {
        return ['error' => "Python script not found at: $pythonScript"];
    }

    // Same Windows/venv convention as public/vis/index.php's shell_exec call.
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $ds        = DIRECTORY_SEPARATOR;
        $pythonExe = $pythonDir . $ds . 'venv' . $ds . 'Scripts' . $ds . 'python.exe';
        if (!file_exists($pythonExe)) {
            return ['error' => "Python executable not found at: $pythonExe"];
        }
        $command = sprintf(
            '"%s" "%s" --project-folder=%s --json 2>&1',
            $pythonExe, $pythonScript, escapeshellarg($project_folder)
        );
    } else {
        $venvDir        = $pythonDir . '/venv';
        $activateScript = $venvDir . '/bin/activate';
        if (!is_dir($venvDir) || !file_exists($activateScript)) {
            return ['error' => "Virtual environment not found at: $venvDir"];
        }
        $command = sprintf(
            'bash -c "source %s && python %s --project-folder=%s --json" 2>&1',
            escapeshellarg($activateScript),
            escapeshellarg($pythonScript),
            escapeshellarg($project_folder)
        );
    }

    $output = shell_exec($command);
    if ($output === null || trim($output) === '') {
        return ['error' => 'No output from audit_observations.py'];
    }

    // The script prints exactly one JSON line on success (or on a handled
    // error -- see _fail()/the except block in audit_observations.py). A
    // real crash won't be valid JSON, so surface the raw output instead.
    $result = json_decode(trim($output), true);
    if (!is_array($result)) {
        return ['error' => 'audit_observations.py did not return valid JSON', 'output' => $output];
    }
    if (isset($result['error']) && !($result['success'] ?? false)) {
        return ['error' => $result['error'], 'output' => $result['output'] ?? null];
    }
    return $result;
}

try {
    $db = get_db();

    $stmt = $db->prepare('SELECT ProjectID AS "ProjectID", ProjectFolder AS "ProjectFolder" FROM Projects WHERE DSOKey = ? ORDER BY ProjectID');
    $stmt->execute([$dso_key]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$projects) {
        http_response_code(400);
        echo json_encode(['error' => "No Project exists yet for DSO $dso_key -- add one before auditing."]);
        exit;
    }

    if (isset($body['ProjectID'])) {
        $requested_id = (int)$body['ProjectID'];
        $projects = array_values(array_filter($projects, fn($p) => (int)$p['ProjectID'] === $requested_id));
        if (!$projects) {
            http_response_code(400);
            echo json_encode(['error' => "ProjectID $requested_id doesn't belong to DSO $dso_key."]);
            exit;
        }
    }

    $list_keys = ['created', 'updated', 'deleted', 'orphaned', 'ambiguous',
                  'unrecognized', 'legacyNoFolder', 'projectedIntegration'];
    $merged = array_fill_keys($list_keys, []);
    $merged['integrationFilled'] = 0;
    $errors = [];
    $ok_runs = 0;

    foreach ($projects as $p) {
        $folder = trim((string)$p['ProjectFolder']);
        if ($folder === '') {
            $errors[] = ['ProjectFolder' => "(ProjectID {$p['ProjectID']})",
                         'error' => 'Project has no ProjectFolder set -- nothing to audit against on disk.'];
            continue;
        }
        $r = run_audit_for_folder($folder);
        if (isset($r['error']) && !($r['success'] ?? false)) {
            $errors[] = ['ProjectFolder' => $folder, 'error' => $r['error'], 'output' => $r['output'] ?? null];
            continue;
        }
        $ok_runs++;
        foreach ($list_keys as $k) {
            if (!empty($r[$k]) && is_array($r[$k])) {
                $merged[$k] = array_merge($merged[$k], $r[$k]);
            }
        }
        $merged['integrationFilled'] += (int)($r['integrationFilled'] ?? 0);
    }

    if ($ok_runs === 0) {
        http_response_code(500);
        echo json_encode([
            'error'  => count($errors) === 1 ? $errors[0]['error'] : 'Audit failed for every Project of this DSO.',
            'output' => $errors[0]['output'] ?? null,
            'errors' => $errors,
        ]);
        exit;
    }

    echo json_encode(array_merge(['success' => true, 'applied' => true], $merged, ['errors' => $errors]));

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
