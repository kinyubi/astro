<?php
// ============================================================
// api_sync_folder.php  —  Sync GalleryImages from disk
//
// POST { "DSOKey": "IC1805" }
//   or with session hints for remote mode:
// POST { "DSOKey": "IC1805", "sessionDirHints": { "basename": "sessiondir", ... } }
//
// Strategy:
//   1. Scan public/images/fav/ for files matching <dsokey_lower>*_fav.jpg
//   2. For each found fav file, derive BaseName (strip _fav.jpg)
//   3. Try to resolve SessionDir (and thus DateCaptured/Equipment/IsMosaic) via:
//      a. WORKS_ROOT walk (if WORKS_ROOT is accessible) — local mode
//      b. Existing GalleryImages DB row for that BaseName — already known
//      c. sessionDirHints supplied in the POST body — user-provided
//      d. None of the above → returned in needs_session_dir[] for the UI to ask
//   4. Upsert GalleryImages rows; return inserted/updated/warnings/needs_session_dir
//
// DateCaptured is always derived from SessionDir: first 8 chars = YYYYMMDD.
//
// Response (success):
// {
//   "success": true,
//   "mode": "local"|"remote",
//   "projectFolder": "ic1805_heart_nebula",
//   "inserted":          [ {GalleryImageID, BaseName, DateCaptured, ...} ],
//   "updated":           [ {GalleryImageID, BaseName, DateCaptured, ...} ],
//   "warnings":          [ {GalleryImageID, BaseName, reason} ],
//   "needs_session_dir": [ {BaseName, paletteId} ]   ← only in remote mode
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

$body        = json_decode(file_get_contents('php://input'), true);
$dso_key     = trim($body['DSOKey'] ?? '');
$hint_map    = $body['sessionDirHints'] ?? []; // { baseName => sessionDir }

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

function date_from_session_dir(string $session_dir): ?string {
    if (preg_match('/^(\d{4})(\d{2})(\d{2})/', $session_dir, $m)) {
        return "{$m[1]}-{$m[2]}-{$m[3]}";
    }
    return null;
}

// ── Walk WORKS_ROOT to map BaseName → session metadata ────────────────────
function build_works_root_map(string $project_folder): array {
    // Returns [ baseName => ['session'=>..., 'date'=>..., 'equipment'=>..., 'is_mosaic'=>...] ]
    $proj_path = WORKS_ROOT . DIRECTORY_SEPARATOR . $project_folder;
    if (!is_dir($proj_path)) return [];

    $map = [];
    foreach (scandir($proj_path) as $session_dir) {
        if ($session_dir === '.' || $session_dir === '..') continue;
        $session_path = $proj_path . DIRECTORY_SEPARATOR . $session_dir;
        if (!is_dir($session_path)) continue;
        if (!preg_match('/^\d{8}/', $session_dir)) continue;

        $date  = date_from_session_dir($session_dir);
        $equip = infer_equipment($session_dir);
        $mosaic = infer_is_mosaic($session_dir, $project_folder);

        foreach (scandir($session_path) as $file) {
            if (!preg_match('/^(.+)_fav\.jpg$/i', $file, $fm)) continue;
            if (stripos($file, '_annotated') !== false) continue;
            $map[$fm[1]] = [
                'session'    => $session_dir,
                'date'       => $date,
                'equipment'  => $equip,
                'is_mosaic'  => $mosaic,
            ];
        }
    }
    return $map;
}

// ── Scan public/images/fav/ for fav files belonging to this DSO ───────────
function find_fav_files_in_web(string $dso_key): array {
    // Returns [ ['file' => filename, 'base_name' => baseName, 'palette_id' => int] ]
    $fav_dir = __DIR__ . '/../../public/images/fav';
    if (!is_dir($fav_dir)) {
        // Fallback: fav dir is relative to this file's location in admin/
        $fav_dir = __DIR__ . '/../images/fav';
    }
    if (!is_dir($fav_dir)) return [];

    $prefix  = strtolower($dso_key);
    $results = [];

    foreach (scandir($fav_dir) as $file) {
        if (!preg_match('/^(.+)_fav\.jpg$/i', $file, $fm)) continue;
        if (stripos($file, '_annotated') !== false) continue;
        $bn = $fm[1];
        // Only include files that start with the DSO key (case-insensitive)
        if (stripos($bn, $prefix) !== 0) continue;
        $results[] = [
            'file'       => $file,
            'base_name'  => $bn,
            'palette_id' => infer_palette($file, $bn),
        ];
    }
    return $results;
}

// ── Main ──────────────────────────────────────────────────────────────────
try {
    $db = get_db();

    // Get this DSO's Project(s). ProjectFolder now lives on Projects, not
    // Objects, since a DSO can have more than one Project (e.g. a standard
    // framing and a separate mosaic framing). If there's exactly one, use
    // it. If there's more than one, the caller must specify which via
    // {"ProjectID": n} in the POST body -- a proper per-project sync picker
    // in the admin UI is planned for Phase 2 of DB_REWORK_PLAN.md.
    $stmt = $db->prepare("SELECT ProjectID, ProjectFolder FROM Projects WHERE DSOKey = ?");
    $stmt->execute([$dso_key]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$projects) {
        http_response_code(400);
        echo json_encode(['error' => "No Project exists yet for DSO $dso_key -- add one before syncing."]);
        exit;
    }

    if (count($projects) === 1) {
        $project_id     = (int)$projects[0]['ProjectID'];
        $project_folder = $projects[0]['ProjectFolder'];
    } else {
        $requested_id = isset($body['ProjectID']) ? (int)$body['ProjectID'] : null;
        $match = null;
        foreach ($projects as $p) {
            if ((int)$p['ProjectID'] === $requested_id) { $match = $p; break; }
        }
        if (!$match) {
            http_response_code(400);
            echo json_encode([
                'error'    => "DSO $dso_key has multiple Projects -- specify which via ProjectID.",
                'projects' => $projects,
            ]);
            exit;
        }
        $project_id     = (int)$match['ProjectID'];
        $project_folder = $match['ProjectFolder'];
    }

    // ── Determine mode and build session map ──────────────────────────────
    $works_root_available = defined('WORKS_ROOT') && is_dir(WORKS_ROOT);
    $mode = $works_root_available ? 'local' : 'remote';

    $works_map = []; // baseName → session metadata (populated in local mode)
    if ($works_root_available && $project_folder) {
        $proj_path = WORKS_ROOT . DIRECTORY_SEPARATOR . $project_folder;
        if (is_dir($proj_path)) {
            $works_map = build_works_root_map($project_folder);
        }
        // Note: if project folder doesn't exist locally we still fall through
        // to the remote logic — no hard error, just no WORKS_ROOT data
    }

    // ── Scan web fav directory ────────────────────────────────────────────
    $fav_files = find_fav_files_in_web($dso_key);

    // ── Load existing GalleryImages rows for this DSO ─────────────────────
    $stmt = $db->prepare("
        SELECT GalleryImageID, BaseName, DateCaptured, Equipment,
               PaletteID, SessionDir, IsFeature
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

    $inserted          = [];
    $updated           = [];
    $warnings          = [];
    $needs_session_dir = []; // baseNames we can't resolve SessionDir for

    $disk_basenames = [];

    // ── Process each fav file found in public/images/fav/ ─────────────────
    foreach ($fav_files as $f) {
        $bn = $f['base_name'];
        $disk_basenames[$bn] = true;

        // Resolve SessionDir via priority chain:
        // 1. WORKS_ROOT map (local mode)
        // 2. Existing DB row
        // 3. User-supplied hint in this request
        // 4. Unknown → defer

        $session_dir = null;
        $source      = null;

        if (isset($works_map[$bn])) {
            $session_dir = $works_map[$bn]['session'];
            $source      = 'works_root';
        } elseif (!empty($existing[$bn]['SessionDir'])) {
            $session_dir = $existing[$bn]['SessionDir'];
            $source      = 'db';
        } elseif (!empty($hint_map[$bn])) {
            $session_dir = trim($hint_map[$bn]);
            $source      = 'hint';
        }

        if ($session_dir === null) {
            // Can't resolve — ask the user (only for new or session-less rows)
            $needs_session_dir[] = [
                'BaseName'  => $bn,
                'paletteId' => $f['palette_id'],
            ];
            // Still upsert with what we know (palette at minimum) if brand new
            if (!isset($existing[$bn])) {
                $is_feature = (!$has_feature) ? 1 : 0;
                if ($is_feature) $has_feature = true;
                $sort = count($existing) + count($inserted);
                $stmt = $db->prepare("
                    INSERT INTO GalleryImages
                        (DSOKey, BaseName, PaletteID, DateCaptured,
                         Equipment, ProjectID, SessionDir, IsOwn, SortOrder, IsFeature)
                    VALUES (?, ?, ?, NULL, NULL, ?, NULL, 1, ?, ?)
                ");
                $stmt->execute([$dso_key, $bn, $f['palette_id'], $project_id, $sort, $is_feature]);
                $new_id = (int)$db->lastInsertId();
                $inserted[] = [
                    'GalleryImageID' => $new_id,
                    'BaseName'       => $bn,
                    'DateCaptured'   => null,
                    'Equipment'      => null,
                    'IsMosaic'       => 0,
                    'PaletteID'      => $f['palette_id'],
                    'SessionDir'     => null,
                    'IsFeature'      => $is_feature,
                    'needs_session'  => true,
                ];
            }
            continue;
        }

        // We have a SessionDir — derive everything from it
        $date_captured = date_from_session_dir($session_dir);
        $equipment     = infer_equipment($session_dir);
        $is_mosaic     = infer_is_mosaic($session_dir, $project_folder);
        $palette_id    = $f['palette_id'];

        if (isset($existing[$bn])) {
            // Update existing row
            $stmt = $db->prepare("
                UPDATE GalleryImages
                SET DateCaptured = ?,
                    Equipment    = ?,
                    PaletteID    = ?,
                    SessionDir   = ?,
                    ProjectID    = ?
                WHERE BaseName = ? AND DSOKey = ?
            ");
            $stmt->execute([
                $date_captured, $equipment,
                $palette_id, $session_dir, $project_id, $bn, $dso_key
            ]);

            $updated[] = [
                'GalleryImageID' => (int)$existing[$bn]['GalleryImageID'],
                'BaseName'       => $bn,
                'DateCaptured'   => $date_captured,
                'Equipment'      => $equipment,
                'IsMosaic'       => $is_mosaic,
                'PaletteID'      => $palette_id,
                'SessionDir'     => $session_dir,
            ];
        } else {
            // Insert new row
            $is_feature = (!$has_feature) ? 1 : 0;
            if ($is_feature) $has_feature = true;
            $sort = count($existing) + count($inserted);

            $stmt = $db->prepare("
                INSERT INTO GalleryImages
                    (DSOKey, BaseName, PaletteID, DateCaptured,
                     Equipment, ProjectID, SessionDir, IsOwn, SortOrder, IsFeature)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
            ");
            $stmt->execute([
                $dso_key, $bn, $palette_id, $date_captured,
                $equipment, $project_id, $session_dir, $sort, $is_feature
            ]);
            $new_id = (int)$db->lastInsertId();

            $inserted[] = [
                'GalleryImageID' => $new_id,
                'BaseName'       => $bn,
                'DateCaptured'   => $date_captured,
                'Equipment'      => $equipment,
                'IsMosaic'       => $is_mosaic,
                'PaletteID'      => $palette_id,
                'SessionDir'     => $session_dir,
                'IsFeature'      => $is_feature,
            ];
        }
    }

    // ── Remove DB rows whose fav file is no longer in public/images/fav/ ──────
    $removed_feature = false;
    foreach ($existing as $bn => $row) {
        if (!isset($disk_basenames[$bn])) {
            $del = $db->prepare("DELETE FROM GalleryImages WHERE GalleryImageID = ?");
            $del->execute([(int)$row['GalleryImageID']]);
            if ($row['IsFeature']) $removed_feature = true;
            $warnings[] = [
                'GalleryImageID' => (int)$row['GalleryImageID'],
                'BaseName'       => $bn,
                'reason'         => 'fav file not found in public/images/fav/ — removed from DB',
            ];
        }
    }

    // If the featured image was removed, promote the first remaining DB row
    if ($removed_feature) {
        $stmt = $db->prepare("
            SELECT GalleryImageID FROM GalleryImages
            WHERE DSOKey = ?
            ORDER BY SortOrder, GalleryImageID
            LIMIT 1
        ");
        $stmt->execute([$dso_key]);
        $next = $stmt->fetchColumn();
        if ($next) {
            $db->prepare("UPDATE GalleryImages SET IsFeature = 1 WHERE GalleryImageID = ?")
               ->execute([$next]);
        }
    }

    echo json_encode([
        'success'           => true,
        'mode'              => $mode,
        'projectFolder'     => $project_folder,
        'inserted'          => $inserted,
        'updated'           => $updated,
        'warnings'          => $warnings,
        'needs_session_dir' => $needs_session_dir,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
