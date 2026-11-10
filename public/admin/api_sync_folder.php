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
// Hidden images (IsHidden = 1) are still matched to their fav file and
// have their date/equipment refreshed, but are never un-hidden or re-added.
// Rows whose fav file is gone from public/images/fav are deleted. The
// featured image and SortOrder are then re-normalised (gallery_helpers.php):
// the current featured image is kept unless it was removed, new images go
// to the end.
//
// Multiple Projects per DSO: every Project's folder is scanned and each
// image gets the ProjectID of the folder it was actually found in (falling
// back to the row's existing ProjectID, then to the DSO's only live
// Project). An optional {"ProjectID": n} in the body limits the scan to
// that one Project.
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
//   "misnamed":          [ {ProjectFolder, SessionDir, File, reason} ]
//   "duplicatesRemoved": [ {GalleryImageID, BaseName, KeptID} ]
//   "staleProjects":     [ {ProjectID, ProjectFolder} ]  ← folder missing on disk
// }
// ============================================================

require_once __DIR__ . '/auth_api.php';
require_once __DIR__ . '/db_logger.php';
require_once __DIR__ . '/gallery_helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

// ── Local-only guard ─────────────────────────────────────────────────────
// Sync Folder needs WORKS_ROOT (the myWorks folder on disk). On the public VPS it
// isn't reachable, so refuse explicitly (localOnly: true tells the admin UI
// to show its "available only when run locally" dialog) instead of carrying
// on and failing silently.
if (!defined('WORKS_ROOT') || !is_dir(WORKS_ROOT)) {
    http_response_code(409);
    echo json_encode([
        'error'     => 'Sync Folder is available only when the admin panel is run locally.',
        'localOnly' => true,
    ]);
    exit;
}

$body        = json_decode(file_get_contents('php://input'), true);
$dso_key     = trim($body['DSOKey'] ?? '');

// { baseName => sessionDir }. Keyed case-insensitively (see note below) since
// hints round-trip from a prior scan and may not match disk case exactly.
$hint_map = [];
foreach (($body['sessionDirHints'] ?? []) as $hk => $hv) {
    $hint_map[strtolower($hk)] = $hv;
}

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
    // Trailing _S##[P] token, e.g. _S30, _S50, _S50P
    if (preg_match('/_(S\d+P?)$/i', $session_dir, $m)) {
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
function build_works_root_map(string $project_folder, int $project_id,
                              string $dso_key, array &$misnamed): array {
    // Returns [ baseName => ['session'=>..., 'date'=>..., 'equipment'=>...,
    //                        'is_mosaic'=>..., 'project_id'=>..., 'project_folder'=>...] ]
    // Fav files whose name doesn't start with the DSO key are collected in
    // $misnamed: the public/images/fav scan matches on that prefix, so they
    // can never be synced (e.g. a typo like 'ncg6960_...' for NGC6960).
    $proj_path = WORKS_ROOT . DIRECTORY_SEPARATOR . $project_folder;
    if (!is_dir($proj_path)) return [];

    $prefix = strtolower($dso_key);
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
            if (stripos($fm[1], $prefix) !== 0) {
                $misnamed[] = [
                    'ProjectFolder' => $project_folder,
                    'SessionDir'    => $session_dir,
                    'File'          => $file,
                    'reason'        => "name doesn't start with '$prefix' -- Sync Folder can't match it to this DSO",
                ];
                continue;
            }
            // Keyed lowercase: Windows filenames are case-insensitive, so the
            // same image can be cased differently here vs. the web fav/ scan
            // vs. the DB's stored BaseName. Matching must not care which.
            $map[strtolower($fm[1])] = [
                'session'    => $session_dir,
                'date'       => $date,
                'equipment'  => $equip,
                'is_mosaic'  => $mosaic,
                'project_id' => $project_id,
                'project_folder' => $project_folder,
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

    // Get this DSO's Project(s). A DSO can have more than one Project
    // (e.g. a standard framing and a separate mosaic framing); every one is
    // scanned and each image is linked to the Project whose folder it was
    // found in. An explicit {"ProjectID": n} restricts the scan to that one.
    $stmt = $db->prepare("SELECT ProjectID AS \"ProjectID\", ProjectFolder AS \"ProjectFolder\" FROM Projects WHERE DSOKey = ? ORDER BY ProjectID");
    $stmt->execute([$dso_key]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$projects) {
        http_response_code(400);
        echo json_encode(['error' => "No Project exists yet for DSO $dso_key -- add one before syncing."]);
        exit;
    }

    // ── Solar guard ─────────────────────────────────────────────────────
    // Sun/Moon images are routed to public/images/solar/ by the solar
    // pipeline (copy_solar_to_web()), not public/images/fav/, so the fav-dir
    // scan below can never find anything for them. Rather than silently
    // returning zero results, tell the caller explicitly.
    $stmt = $db->prepare("SELECT ObjectTypeID FROM Objects WHERE DSOKey = ?");
    $stmt->execute([$dso_key]);
    $object_type_id = $stmt->fetchColumn();

    if ($object_type_id === 'SOLAR_SYSTEM') {
        http_response_code(400);
        echo json_encode(['error' => "Sync Folder is not available for solar objects (Sun/Moon) -- solar images are handled by the solar pipeline, not the standard fav/ scan."]);
        exit;
    }

    if (!empty($body['ProjectID'])) {
        $requested_id = (int)$body['ProjectID'];
        $projects = array_values(array_filter($projects, fn($p) => (int)$p['ProjectID'] === $requested_id));
        if (!$projects) {
            http_response_code(400);
            echo json_encode(['error' => "ProjectID $requested_id doesn't belong to DSO $dso_key."]);
            exit;
        }
    }

    // Everything from here on writes to GalleryImages (insert/update/delete
    // across potentially several fav files, plus the featured-image
    // promotion at the end). Wrapped in one transaction so a failure
    // partway through -- like the db_last_insert_id() bug that bit IC342 --
    // rolls back cleanly instead of leaving some images synced and others
    // not while still reporting "Sync failed" to the caller.
    $db->beginTransaction();

    // ── Determine mode and build session map across all Projects ──────────
    $works_root_available = defined('WORKS_ROOT') && is_dir(WORKS_ROOT);
    $mode = $works_root_available ? 'local' : 'remote';

    $works_map      = []; // baseName → session metadata (populated in local mode)
    $misnamed       = []; // fav files in a Project folder that can't match the DSO key
    $stale_projects = []; // Projects whose ProjectFolder no longer exists on disk
    $live_projects  = []; // Projects whose folder does exist
    if ($works_root_available) {
        foreach ($projects as $p) {
            $folder = $p['ProjectFolder'];
            if (!$folder || !is_dir(WORKS_ROOT . DIRECTORY_SEPARATOR . $folder)) {
                $stale_projects[] = ['ProjectID' => (int)$p['ProjectID'], 'ProjectFolder' => $folder];
                continue;
            }
            $live_projects[] = $p;
            // First Project wins if the same BaseName somehow appears in two folders.
            $works_map += build_works_root_map($folder, (int)$p['ProjectID'], $dso_key, $misnamed);
        }
    } else {
        $live_projects = $projects;
    }

    // Fallback ProjectID for an image whose folder couldn't be determined:
    // only unambiguous when the DSO has exactly one live Project.
    $default_project = count($live_projects) === 1 ? $live_projects[0]
                     : (count($projects) === 1 ? $projects[0] : null);
    $default_project_id     = $default_project ? (int)$default_project['ProjectID'] : null;
    $default_project_folder = $default_project ? $default_project['ProjectFolder'] : '';

    // ── Scan web fav directory ────────────────────────────────────────────
    $fav_files = find_fav_files_in_web($dso_key);

    // ── Load existing GalleryImages rows for this DSO ─────────────────────
    $stmt = $db->prepare("
        SELECT
            GalleryImageID AS \"GalleryImageID\",
            BaseName       AS \"BaseName\",
            DateCaptured   AS \"DateCaptured\",
            Equipment      AS \"Equipment\",
            PaletteID      AS \"PaletteID\",
            SessionDir     AS \"SessionDir\",
            ProjectID      AS \"ProjectID\",
            IsFeature      AS \"IsFeature\",
            COALESCE(IsHidden, 0) AS \"IsHidden\"
        FROM GalleryImages
        WHERE DSOKey = ?
        ORDER BY SortOrder, GalleryImageID
    ");
    $stmt->execute([$dso_key]);
    // Keyed by lowercased BaseName -- see the case-insensitivity note on
    // build_works_root_map() above. $row still carries the DB's actual
    // original-case BaseName for anything that needs to display/report it.
    //
    // Two rows with the same BaseName (case-insensitively) are duplicates of
    // one image -- e.g. after a rename onto a name another row already had.
    // Keep the featured one (else the oldest, lowest GalleryImageID) and
    // delete the rest; reported as duplicatesRemoved. (The unique index from
    // migrate_unique_gallery_basename.py prevents new ones.)
    $existing           = [];
    $duplicates_removed = [];
    $del_dup = $db->prepare("DELETE FROM GalleryImages WHERE GalleryImageID = ?");
    $rows_by_name = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows_by_name[strtolower($row['BaseName'])][] = $row;
    }
    foreach ($rows_by_name as $key => $group) {
        usort($group, function ($a, $b) {
            $fa = (int)$a['IsFeature'] && !(int)$a['IsHidden'];
            $fb = (int)$b['IsFeature'] && !(int)$b['IsHidden'];
            if ($fa !== $fb) return $fa ? -1 : 1;
            return (int)$a['GalleryImageID'] <=> (int)$b['GalleryImageID'];
        });
        $existing[$key] = $group[0];
        foreach (array_slice($group, 1) as $dup) {
            $del_dup->execute([(int)$dup['GalleryImageID']]);
            $duplicates_removed[] = [
                'GalleryImageID' => (int)$dup['GalleryImageID'],
                'BaseName'       => $dup['BaseName'],
                'KeptID'         => (int)$group[0]['GalleryImageID'],
            ];
        }
    }

    // New rows are appended after everything else (1000+ always sorts after
    // the normalised 0..n range); gallery_normalize() at the end renumbers
    // them and decides which image is featured (keeps the current one).
    $next_sort = 1000;

    $inserted          = [];
    $updated           = [];
    $warnings          = [];
    $needs_session_dir = []; // baseNames we can't resolve SessionDir for

    $disk_basenames = [];

    // ── Process each fav file found in public/images/fav/ ─────────────────
    foreach ($fav_files as $f) {
        $bn     = $f['base_name'];      // case exactly as found on disk in public/images/fav/
        $bn_key = strtolower($bn);      // case-insensitive key for all matching below
        $disk_basenames[$bn_key] = true;

        // Resolve SessionDir via priority chain:
        // 1. WORKS_ROOT map (local mode)
        // 2. Existing DB row
        // 3. User-supplied hint in this request
        // 4. Unknown → defer

        $session_dir = null;
        $source      = null;

        if (isset($works_map[$bn_key])) {
            $session_dir = $works_map[$bn_key]['session'];
            $source      = 'works_root';
        } elseif (!empty($existing[$bn_key]['SessionDir'])) {
            $session_dir = $existing[$bn_key]['SessionDir'];
            $source      = 'db';
        } elseif (!empty($hint_map[$bn_key])) {
            $session_dir = trim($hint_map[$bn_key]);
            $source      = 'hint';
        }

        // ProjectID: the folder it was found in, else what the row already
        // has, else the DSO's single live Project (NULL if ambiguous).
        if (isset($works_map[$bn_key])) {
            $project_id     = $works_map[$bn_key]['project_id'];
            $project_folder = $works_map[$bn_key]['project_folder'];
        } elseif (!empty($existing[$bn_key]['ProjectID'])) {
            $project_id     = (int)$existing[$bn_key]['ProjectID'];
            $project_folder = '';
            foreach ($projects as $p) {
                if ((int)$p['ProjectID'] === $project_id) { $project_folder = $p['ProjectFolder']; break; }
            }
        } else {
            $project_id     = $default_project_id;
            $project_folder = $default_project_folder;
        }

        if ($session_dir === null) {
            // Can't resolve — ask the user (only for new or session-less rows)
            $needs_session_dir[] = [
                'BaseName'  => $bn,
                'paletteId' => $f['palette_id'],
            ];
            // Still upsert with what we know (palette at minimum) if brand new
            if (!isset($existing[$bn_key])) {
                $is_feature = 0;
                $sort = $next_sort++;
                $stmt = $db->prepare("
                    INSERT INTO GalleryImages
                        (DSOKey, BaseName, PaletteID, DateCaptured,
                         Equipment, ProjectID, SessionDir, IsOwn, SortOrder, IsFeature)
                    VALUES (?, ?, ?, NULL, NULL, ?, NULL, 1, ?, ?)
                ");
                $stmt->execute([$dso_key, $bn, $f['palette_id'], $project_id, $sort, $is_feature]);
                $new_id = db_last_insert_id($db, 'GalleryImages', 'GalleryImageID');
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

        if (isset($existing[$bn_key])) {
            // Update existing row, targeted by GalleryImageID rather than
            // BaseName+DSOKey: SQL '=' is case-sensitive in Postgres even
            // though we already resolved this to the same row above via a
            // case-insensitive match, so matching on BaseName here again
            // could miss the row entirely over a pure case difference.
            $existing_id = (int)$existing[$bn_key]['GalleryImageID'];
            $stmt = $db->prepare("
                UPDATE GalleryImages
                SET DateCaptured = ?,
                    Equipment    = ?,
                    PaletteID    = ?,
                    SessionDir   = ?,
                    ProjectID    = ?
                WHERE GalleryImageID = ?
            ");
            $stmt->execute([
                $date_captured, $equipment,
                $palette_id, $session_dir, $project_id, $existing_id
            ]);

            $updated[] = [
                'GalleryImageID' => $existing_id,
                'BaseName'       => $existing[$bn_key]['BaseName'],
                'DateCaptured'   => $date_captured,
                'Equipment'      => $equipment,
                'IsMosaic'       => $is_mosaic,
                'PaletteID'      => $palette_id,
                'SessionDir'     => $session_dir,
                'IsHidden'       => (int)$existing[$bn_key]['IsHidden'],
            ];
        } else {
            // Insert new row
            $is_feature = 0;
            $sort = $next_sort++;

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
            $new_id = db_last_insert_id($db, 'GalleryImages', 'GalleryImageID');

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
    // $existing and $disk_basenames are both keyed by lowercased BaseName
    // (see notes above), so this comparison is already case-insensitive --
    // a file that's genuinely still on disk under different casing will not
    // be wrongly deleted here.
    foreach ($existing as $bn_key => $row) {
        if (!isset($disk_basenames[$bn_key])) {
            $del = $db->prepare("DELETE FROM GalleryImages WHERE GalleryImageID = ?");
            $del->execute([(int)$row['GalleryImageID']]);
            $warnings[] = [
                'GalleryImageID' => (int)$row['GalleryImageID'],
                'BaseName'       => $row['BaseName'],
                'reason'         => 'fav file not found in public/images/fav/ — removed from DB',
            ];
        }
    }

    // Featured image / SortOrder rule (keeps the current featured image
    // unless it was just removed; then the first visible one is promoted).
    $images = gallery_normalize($db, $dso_key);
    $feat_by_id = [];
    foreach ($images as $img) $feat_by_id[$img['GalleryImageID']] = $img['IsFeature'];
    foreach ($inserted as &$ins) {
        $ins['IsFeature'] = $feat_by_id[(int)$ins['GalleryImageID']] ?? 0;
    }
    unset($ins);

    $db->commit();

    // Thumbs for new/visible images; drop thumbs of removed or hidden
    // ones (thumb_helpers.php).
    $thumbs = gallery_thumbs_sync($db, $dso_key);

    echo json_encode([
        'success'           => true,
        'mode'              => $mode,
        'projectFolder'     => implode(', ', array_column($live_projects, 'ProjectFolder')),
        'inserted'          => $inserted,
        'updated'           => $updated,
        'warnings'          => $warnings,
        'needs_session_dir' => $needs_session_dir,
        'misnamed'          => $misnamed,
        'duplicatesRemoved' => $duplicates_removed,
        'staleProjects'     => $stale_projects,
        'thumbs'            => $thumbs,
    ]);

} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
