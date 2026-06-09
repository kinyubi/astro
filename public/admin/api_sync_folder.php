<?php
// ============================================================
// api_sync_folder.php  —  Sync GalleryImages from disk
//
// POST { "DSOKey": "IC1805" }
//
// Walks WORKS_ROOT/<ProjectFolder>/<YYYYMMDD_*>/ looking for
// *_fav.jpg files (non-annotated). For each found:
//   - If a GalleryImages row exists for that BaseName: update
//     DateCaptured, Equipment, IsMosaic, PaletteID
//   - If no row exists: insert with inferred values;
//     set IsFeature=1 if no featured image exists for this DSO
//
// Also checks existing GalleryImages rows to see if their fav
// file is still on disk. Missing files are returned as warnings
// but NOT deleted. The client can confirm deletion separately.
//
// Response:
// {
//   "success": true,
//   "inserted": [ {GalleryImageID, BaseName, DateCaptured, ...} ],
//   "updated":  [ {GalleryImageID, BaseName, DateCaptured, ...} ],
//   "warnings": [ {GalleryImageID, BaseName, reason} ],
//   "projectFolder": "ic1805_heart_nebula"
// }
// ============================================================

require_once __DIR__ . '/auth_api.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$dso_key = trim($body['DSOKey'] ?? '');
if (!$dso_key) {
    http_response_code(400);
    echo json_encode(['error' => 'DSOKey required']);
    exit;
}

// ── Palette token → PaletteID ─────────────────────────────────────────────
const PALETTE_MAP = [
    'sho'      => 1,
    'hoo'      => 2,
    'hso'      => 3,
    'ohs'      => 4,
    'hos'      => 5,
    'starless' => 6,
    'mono'     => 7,
];

function infer_palette(string $fav_filename, string $base_name): int {
    // Strip base_name prefix and _fav.jpg suffix → middle tokens
    $lower     = strtolower($fav_filename);
    $bn_lower  = strtolower($base_name);
    $remainder = $lower;
    if (str_starts_with($remainder, $bn_lower)) {
        $remainder = substr($remainder, strlen($bn_lower));
    }
    $remainder = str_replace('_fav.jpg', '', $remainder);
    $remainder = trim($remainder, '_');
    $tokens    = explode('_', $remainder);
    foreach (PALETTE_MAP as $token => $pid) {
        if (in_array($token, $tokens, true)) return $pid;
    }
    return 0; // Natural
}

function infer_equipment(string $session_dir): ?string {
    if (preg_match('/_(S\d+)$/i', $session_dir, $m)) {
        return strtoupper($m[1]);
    }
    return null;
}

function infer_is_mosaic(string $session_dir, string $project_folder): int {
    return (stripos($session_dir, 'mosaic') !== false ||
            stripos($project_folder, 'mosaic') !== false) ? 1 : 0;
}

function find_fav_files(string $project_folder): array {
    // Returns array of [ 'file' => filename, 'session' => dir_name,
    //                    'date' => YYYY-MM-DD, 'equipment' => S30|null,
    //                    'is_mosaic' => 0|1 ]
    $proj_path = WORKS_ROOT . DIRECTORY_SEPARATOR . $project_folder;
    if (!is_dir($proj_path)) return [];

    $results = [];
    foreach (scandir($proj_path) as $session_dir) {
        if ($session_dir === '.' || $session_dir === '..') continue;
        $session_path = $proj_path . DIRECTORY_SEPARATOR . $session_dir;
        if (!is_dir($session_path)) continue;

        // Must start with YYYYMMDD
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})/', $session_dir, $dm)) continue;

        $date_str = "{$dm[1]}-{$dm[2]}-{$dm[3]}";
        $equip    = infer_equipment($session_dir);
        $is_mos   = infer_is_mosaic($session_dir, $project_folder);

        foreach (scandir($session_path) as $file) {
            // Match non-annotated fav files only
            if (!preg_match('/^(.+)_fav\.jpg$/i', $file, $fm)) continue;
            if (stripos($file, '_annotated') !== false) continue;

            $results[] = [
                'file'       => $file,
                'base_name'  => $fm[1],
                'session'    => $session_dir,
                'date'       => $date_str,
                'equipment'  => $equip,
                'is_mosaic'  => $is_mos,
                'palette_id' => infer_palette($file, $fm[1]),
                'full_path'  => $session_path . DIRECTORY_SEPARATOR . $file,
            ];
        }
    }
    return $results;
}

// ── Main ──────────────────────────────────────────────────────────────────
try {
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA foreign_keys = ON');

    // Get ProjectFolder for this DSO
    $stmt = $db->prepare("SELECT ProjectFolder FROM Objects WHERE DSOKey = ?");
    $stmt->execute([$dso_key]);
    $obj = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$obj || !$obj['ProjectFolder']) {
        http_response_code(400);
        echo json_encode(['error' => "No ProjectFolder set for $dso_key"]);
        exit;
    }
    $project_folder = $obj['ProjectFolder'];

    // ── Verify project folder exists; suggest candidates if not ─────────────
    $proj_path = WORKS_ROOT . DIRECTORY_SEPARATOR . $project_folder;
    if (!is_dir($proj_path)) {
        // Scan WORKS_ROOT for similar directory names
        $candidates = [];
        $dso_lower  = strtolower($dso_key);  // e.g. 'ic1848'
        // Strip catalog prefix to get the object identifier (e.g. 'ic1848' → '1848')
        $dso_num    = preg_replace('/^[a-z]+/i', '', strtolower($dso_key));
        $pf_lower   = strtolower($project_folder);

        foreach (scandir(WORKS_ROOT) as $dir) {
            if ($dir === '.' || $dir === '..') continue;
            if (!is_dir(WORKS_ROOT . DIRECTORY_SEPARATOR . $dir)) continue;
            $dir_lower = strtolower($dir);
            // Match if dir contains the DSO number OR shares significant prefix with ProjectFolder
            $pf_prefix = substr($pf_lower, 0, min(10, strlen($pf_lower)));
            if (str_contains($dir_lower, $dso_num) ||
                str_starts_with($dir_lower, $pf_prefix) ||
                similar_text($pf_lower, $dir_lower) / max(strlen($pf_lower), 1) > 0.6) {
                $candidates[] = $dir;
            }
        }
        sort($candidates);

        echo json_encode([
            'success'        => false,
            'folder_not_found' => true,
            'projectFolder'  => $project_folder,
            'candidates'     => $candidates,
        ]);
        exit;
    }
    $fav_files = find_fav_files($project_folder);
    $disk_basenames = array_column($fav_files, 'base_name', 'base_name'); // keyed set

    // Load existing GalleryImages for this DSO
    $stmt = $db->prepare("
        SELECT GalleryImageID, BaseName, DateCaptured, Equipment,
               IsMosaic, PaletteID, IsFeature
        FROM GalleryImages
        WHERE DSOKey = ?
        ORDER BY SortOrder, GalleryImageID
    ");
    $stmt->execute([$dso_key]);
    $existing = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $existing[$row['BaseName']] = $row;
    }

    // Check if any featured image currently exists
    $has_feature = false;
    foreach ($existing as $row) {
        if ($row['IsFeature']) { $has_feature = true; break; }
    }

    $inserted = [];
    $updated  = [];
    $warnings = [];

    // ── Process fav files found on disk ──────────────────────────────────
    foreach ($fav_files as $f) {
        $bn = $f['base_name'];

        if (isset($existing[$bn])) {
            // Update existing row
            $stmt = $db->prepare("
                UPDATE GalleryImages
                SET DateCaptured = ?,
                    Equipment    = ?,
                    IsMosaic     = ?,
                    PaletteID    = ?,
                    SessionDir   = ?
                WHERE BaseName = ? AND DSOKey = ?
            ");
            $stmt->execute([
                $f['date'], $f['equipment'], $f['is_mosaic'],
                $f['palette_id'], $f['session'], $bn, $dso_key
            ]);

            $updated[] = [
                'GalleryImageID' => (int)$existing[$bn]['GalleryImageID'],
                'BaseName'       => $bn,
                'DateCaptured'   => $f['date'],
                'Equipment'      => $f['equipment'],
                'IsMosaic'       => $f['is_mosaic'],
                'PaletteID'      => $f['palette_id'],
                'SessionDir'     => $f['session'],
                'session'        => $f['session'],
            ];
        } else {
            // Insert new row
            // Auto-feature if no featured image exists yet
            $is_feature = (!$has_feature) ? 1 : 0;
            if ($is_feature) $has_feature = true; // only first insert gets it

            $sort = count($existing) + count($inserted);

            $stmt = $db->prepare("
                INSERT INTO GalleryImages
                    (DSOKey, BaseName, PaletteID, DateCaptured,
                     Equipment, IsMosaic, SessionDir, IsOwn, SortOrder, IsFeature)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
            ");
            $stmt->execute([
                $dso_key, $bn, $f['palette_id'], $f['date'],
                $f['equipment'], $f['is_mosaic'], $f['session'], $sort, $is_feature
            ]);
            $new_id = (int)$db->lastInsertId();

            $inserted[] = [
                'GalleryImageID' => $new_id,
                'BaseName'       => $bn,
                'DateCaptured'   => $f['date'],
                'Equipment'      => $f['equipment'],
                'IsMosaic'       => $f['is_mosaic'],
                'PaletteID'      => $f['palette_id'],
                'SessionDir'     => $f['session'],
                'IsFeature'      => $is_feature,
                'session'        => $f['session'],
            ];
        }
    }

    // ── Check existing rows for missing fav files ─────────────────────────
    foreach ($existing as $bn => $row) {
        if (!isset($disk_basenames[$bn])) {
            $warnings[] = [
                'GalleryImageID' => (int)$row['GalleryImageID'],
                'BaseName'       => $bn,
                'reason'         => 'fav file not found on disk',
            ];
        }
    }

    echo json_encode([
        'success'       => true,
        'projectFolder' => $project_folder,
        'inserted'      => $inserted,
        'updated'       => $updated,
        'warnings'      => $warnings,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
