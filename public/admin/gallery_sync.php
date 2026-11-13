<?php
// ============================================================
// gallery_sync.php  —  GalleryImages <-> disk sync, shared by
//
//   * api_sync_folder.php   Sync Folder button (one DSO)
//   * api_sync_all.php      Sync All button in DSO Admin (every DSO)
//   * scripts/sync_gallery.php   command line (every DSO, or -k one)
//
// so the button and the script can never disagree.
//
// The image is the _full. A gallery row exists for every
// public/images/full/<dsokey>_..._full.jpg (publish_images.py always puts
// a fav beside it, making one from the _full when a session has none):
//
//   1. Scan public/images/full/ for <dsokey_lower>_*_full.jpg.
//   2. BaseName = file name minus _full.jpg.
//   3. SessionDir (and so DateCaptured/Equipment) comes from, in order:
//        a. WORKS_ROOT: the session folder holding <BaseName>_full.jpg
//           or _fav.jpg (local mode)
//        b. the existing GalleryImages row
//        c. sessionDirHints from the caller
//        d. none -> needs_session_dir (the UI asks for it)
//   4. Insert new rows, update rows whose date/equipment/palette/session/
//      Project changed.
//   5. Rows whose _full is gone from public/images/full are listed in
//      toDelete and only deleted when the caller passes delete => true.
//
// A web fav with no web full is listed in favWithoutFull (publish the
// _full, or remove the fav) and gets no row.
//
// Hidden images (IsHidden = 1) are matched and refreshed but never
// un-hidden. Rows with the same BaseName (any case) are duplicates of one
// image: the featured/oldest is kept and the others removed
// (duplicatesRemoved) -- that always happens, it loses nothing.
//
// Everything for one DSO runs in one transaction. With apply => false it
// is rolled back at the end, so a dry run reports exactly what a real run
// would do, and thumbs are left alone.
// ============================================================

require_once __DIR__ . '/../../shared/db.php';
require_once __DIR__ . '/gallery_helpers.php';

/** A problem with the request itself (unknown DSO, no Project, solar). */
class GallerySyncError extends RuntimeException {}

// ── Palette token → PaletteID ─────────────────────────────────────────────
const GS_PALETTE_MAP = [
    'sho'      => 1,
    'hoo'      => 2,
    'hso'      => 3,
    'ohs'      => 4,
    'hos'      => 5,
    'starless' => 6,
    'mono'     => 7,
];

const GS_SOLAR_OBJECTS = ['sun', 'moon', 'mercury', 'venus', 'mars', 'jupiter', 'saturn', 'uranus', 'neptune'];
const GS_SKIP_PROJECTS = ['scenery'];

function gs_images_dir(): string {
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'images';
}

function gs_works_root_available(): bool {
    return defined('WORKS_ROOT') && is_dir(WORKS_ROOT);
}

/**
 * True for the Sun, Moon and planets, whose images go to images/solar
 * through publish_images.py's solar pipeline instead of the gallery.
 * Not "ObjectTypeID = SOLAR_SYSTEM": comets have that type too, but their
 * images are ordinary fav/full gallery images.
 */
function gs_uses_solar_pipeline(string $dso_key): bool {
    return in_array(strtolower(trim($dso_key)), GS_SOLAR_OBJECTS, true);
}

/**
 * PaletteID from a palette token in the BaseName (..._hoo_S30), or null
 * when the name has none -- then a new row is Natural (0) and an existing
 * row keeps the palette it has (it may have been set by hand in the admin).
 */
function infer_palette(string $base_name): ?int {
    $tokens = explode('_', strtolower($base_name));
    foreach (GS_PALETTE_MAP as $token => $pid) {
        if (in_array($token, $tokens, true)) return $pid;
    }
    return null;
}

/**
 * Equipment from the session folder name: its last part, when that is a
 * code in the Equipment table (S30, S50, S50P, 6SE, 8SE, IP, UNK...),
 * returned as the table spells it. Same rule as audit_observations.py.
 * Before gs_labels() has read the table, only Seestar codes are known.
 */
function infer_equipment(string $session_dir): ?string {
    $last  = strtolower((string)substr((string)strrchr('_' . $session_dir, '_'), 1));
    $codes = $GLOBALS['GS_EQUIPMENT_CODES'] ?? null;
    if ($codes) return $codes[$last] ?? null;
    return preg_match('/^s\d+p?$/', $last) ? strtoupper($last) : null;
}

// Mosaic is a property of the session: 1 when the session folder name has
// a whole "mosaic" token. Same rule as the computed IsMosaic columns.
// Report only -- nothing writes IsMosaic.
function infer_is_mosaic(string $session_dir): int {
    return preg_match('/(^|_)mosaic(_|$)/i', $session_dir) ? 1 : 0;
}

function date_from_session_dir(string $session_dir): ?string {
    if (preg_match('/^(\d{4})(\d{2})(\d{2})/', $session_dir, $m)) {
        return "{$m[1]}-{$m[2]}-{$m[3]}";
    }
    return null;
}

// ── Human-readable labels for the reports ────────────────────────────────

const GS_MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/** '2026-03-19' -> '19 Mar 2026' ('' for none). */
function gs_date_label(?string $iso): string {
    if (!$iso || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m)) return '';
    return (int)$m[3] . ' ' . GS_MONTHS[(int)$m[2] - 1] . ' ' . $m[1];
}

/** ['palette' => [id => name], 'equipment' => [lower code => name]], read once. */
function gs_labels(PDO $db): array {
    static $labels = null;
    if ($labels !== null) return $labels;
    $palette = [0 => 'Natural', 1 => 'SHO', 2 => 'HOO', 3 => 'HSO', 4 => 'OHS', 5 => 'HOS', 6 => 'Starless', 7 => 'Mono'];
    try {
        foreach ($db->query('SELECT PaletteID AS "id", PaletteName AS "name" FROM PaletteTreatments')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (trim((string)$r['name']) !== '') $palette[(int)$r['id']] = trim($r['name']);
        }
    } catch (Throwable $e) { /* built-in names above */ }
    $equipment = ['s30' => 'Seestar S30', 's50' => 'Seestar S50', 's50p' => 'Seestar S50 Pro'];
    try {
        foreach ($db->query('SELECT EquipmentID AS "id", EquipmentName AS "name" FROM Equipment')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $code = strtolower(trim((string)$r['id']));
            if ($code !== '') $GLOBALS['GS_EQUIPMENT_CODES'][$code] = trim((string)$r['id']);
            if ($code !== '' && !isset($equipment[$code]) && trim((string)$r['name']) !== '') $equipment[$code] = trim($r['name']);
        }
    } catch (Throwable $e) { /* Seestar names above */ }
    $equipment['8se'] = 'Celestron NexStar 8SE';
    return $labels = ['palette' => $palette, 'equipment' => $equipment];
}

function gs_palette_label(PDO $db, $id): string {
    $p = gs_labels($db)['palette'];
    return $p[(int)$id] ?? "palette $id";
}

function gs_equipment_label(PDO $db, ?string $code): string {
    if ($code === null || trim($code) === '') return 'blank';
    return gs_labels($db)['equipment'][strtolower(trim($code))] ?? $code;
}

/** Session folder names that publish_images.py skips. */
function gs_session_ignored(string $session_dir): bool {
    return stripos($session_dir, 'ignore') !== false || stripos($session_dir, 'bust') !== false;
}

/** True if $base belongs to $dso_key: '<dsokey>_...' (so M1 never claims m101_...). */
function gs_belongs(string $base, string $dso_key): bool {
    $prefix = strtolower($dso_key) . '_';
    return strncasecmp($base, $prefix, strlen($prefix)) === 0;
}

// ── Walk WORKS_ROOT to map BaseName → session metadata ────────────────────
// [ lower(baseName) => ['session','date','equipment','is_mosaic',
//                       'project_id','project_folder'] ]
// A _fav or _full in the session folder places the image. _fav/_full files
// whose name doesn't start with '<dsokey>_' go to $misnamed: the web scan
// matches on that prefix, so they can never be synced.
function build_works_root_map(string $project_folder, int $project_id,
                              string $dso_key, array &$misnamed): array {
    $proj_path = WORKS_ROOT . DIRECTORY_SEPARATOR . $project_folder;
    if (!is_dir($proj_path)) return [];

    $prefix = strtolower($dso_key) . '_';
    $map = [];
    $reported = [];
    foreach (scandir($proj_path) as $session_dir) {
        if ($session_dir === '.' || $session_dir === '..') continue;
        $session_path = $proj_path . DIRECTORY_SEPARATOR . $session_dir;
        if (!is_dir($session_path)) continue;
        if (!preg_match('/^\d{8}/', $session_dir)) continue;

        $info = [
            'session'        => $session_dir,
            'date'           => date_from_session_dir($session_dir),
            'equipment'      => infer_equipment($session_dir),
            'is_mosaic'      => infer_is_mosaic($session_dir),
            'project_id'     => $project_id,
            'project_folder' => $project_folder,
        ];
        foreach (scandir($session_path) as $file) {
            if (!preg_match('/^(.+)_(fav|full)\.jpe?g$/i', $file, $fm)) continue;
            if (stripos($file, '_annotated') !== false) continue;
            $key = strtolower($fm[1]);
            if (!gs_belongs($fm[1], $dso_key)) {
                // Ignored sessions are never published, so their names don't matter.
                if (!gs_session_ignored($session_dir) && !isset($reported[$session_dir . '/' . $key])) {
                    $reported[$session_dir . '/' . $key] = true;
                    $misnamed[] = [
                        'ProjectFolder' => $project_folder,
                        'SessionDir'    => $session_dir,
                        'File'          => $file,
                        'reason'        => "name doesn't start with '$prefix' -- Sync can't match it to this DSO",
                    ];
                }
                continue;
            }
            // Keyed lowercase: Windows names are case-insensitive. A session
            // that isn't ignored wins over an ignored one with the same name.
            if (!isset($map[$key]) || (gs_session_ignored($map[$key]['session']) && !gs_session_ignored($session_dir))) {
                $map[$key] = $info;
            }
        }
    }
    return $map;
}

/**
 * Projects whose folder has gone from MyWorks. The database follows the
 * disk: when exactly one MyWorks folder starts with '<dsokey>_' and no
 * other Project of this DSO uses it, the folder was renamed, so the
 * Project is pointed at it ($fixes). Anything else is explained in
 * $stale (plain text in 'message').
 * @return array the Projects, with repaired folders
 */
function gs_repair_project_folders(PDO $db, string $dso_key, array $projects, array &$fixes, array &$stale): array {
    $prefix = strtolower($dso_key) . '_';
    $on_disk = [];
    foreach (scandir(WORKS_ROOT) as $d) {
        if ($d[0] === '.' || !is_dir(WORKS_ROOT . DIRECTORY_SEPARATOR . $d)) continue;
        if (strncasecmp($d, $prefix, strlen($prefix)) === 0) $on_disk[] = $d;
    }
    $exists = fn($f) => $f && is_dir(WORKS_ROOT . DIRECTORY_SEPARATOR . $f);
    $used = [];
    foreach ($projects as $p) if ($exists($p['ProjectFolder'])) $used[strtolower($p['ProjectFolder'])] = $p['ProjectFolder'];

    $upd = $db->prepare('UPDATE Projects SET ProjectFolder = ? WHERE ProjectID = ?');
    foreach ($projects as &$p) {
        $old = (string)$p['ProjectFolder'];
        if ($exists($old)) continue;
        $free = array_values(array_filter($on_disk, fn($d) => !isset($used[strtolower($d)])));
        if (count($free) === 1) {
            $new = $free[0];
            $upd->execute([$new, (int)$p['ProjectID']]);
            $used[strtolower($new)] = $new;
            $p['ProjectFolder'] = $new;
            $fixes[] = ['from' => $old, 'to' => $new,
                        'message' => "Project folder was renamed on disk from $old to $new; the Project is updated to match"];
        } elseif ($used) {
            $stale[] = ['ProjectFolder' => $old,
                        'message' => "Project folder $old is gone from MyWorks; this DSO's images are in "
                                   . implode(' and ', array_values($used))
                                   . ". Delete the old Project in DSO Admin (its images move to the other Project)."];
        } elseif ($free) {
            $stale[] = ['ProjectFolder' => $old,
                        'message' => "Project folder $old is gone from MyWorks, and more than one folder could be its new name ("
                                   . implode(', ', $free) . "). Fix the Project's folder in DSO Admin."];
        } else {
            $stale[] = ['ProjectFolder' => $old,
                        'message' => "Project folder $old is gone from MyWorks, and no folder starts with $prefix"];
        }
    }
    unset($p);
    return $projects;
}

/** [lower(base) => base] for public/images/<kind>/<dsokey>_..._<kind>.jpg. */
function gs_web_bases(string $kind, string $dso_key): array {
    $dir = gs_images_dir() . DIRECTORY_SEPARATOR . $kind;
    if (!is_dir($dir)) return [];
    $out = [];
    foreach (scandir($dir) as $file) {
        if (!preg_match('/^(.+)_' . $kind . '\.jpg$/i', $file, $m)) continue;
        if (stripos($file, '_annotated') !== false) continue;
        if (!gs_belongs($m[1], $dso_key)) continue;
        $out[strtolower($m[1])] = $m[1];
    }
    return $out;
}

/**
 * Sync one DSO's GalleryImages with public/images/full (and WORKS_ROOT).
 *
 * @param array $opts  apply      bool  commit (true) or roll back (false); default true
 *                     delete     bool  delete rows whose _full is gone; default false
 *                     project_id ?int  only this Project's folder
 *                     hints      array [baseName => sessionDir] from the UI
 * @return array  see the keys at the end; 'changed' is true when anything
 *                was (or in a dry run would be) inserted/updated/deleted.
 * @throws GallerySyncError for a DSO that can't be synced
 */
function gallery_sync_dso(PDO $db, string $dso_key, array $opts = []): array {
    $apply  = $opts['apply']  ?? true;
    $delete = $opts['delete'] ?? false;
    $hint_map = [];
    foreach (($opts['hints'] ?? []) as $hk => $hv) $hint_map[strtolower($hk)] = $hv;

    $stmt = $db->prepare('SELECT ProjectID AS "ProjectID", ProjectFolder AS "ProjectFolder" FROM Projects WHERE DSOKey = ? ORDER BY ProjectID');
    $stmt->execute([$dso_key]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$projects) {
        throw new GallerySyncError("No Project exists yet for DSO $dso_key -- add one before syncing.");
    }

    if (gs_uses_solar_pipeline($dso_key)) {
        throw new GallerySyncError("Sync is not available for the Sun, Moon and planets -- their images are handled by the solar pipeline.");
    }

    if (!empty($opts['project_id'])) {
        $requested_id = (int)$opts['project_id'];
        $projects = array_values(array_filter($projects, fn($p) => (int)$p['ProjectID'] === $requested_id));
        if (!$projects) throw new GallerySyncError("ProjectID $requested_id doesn't belong to DSO $dso_key.");
    }

    $stmt = $db->prepare('SELECT CommonName FROM Objects WHERE DSOKey = ?');
    $stmt->execute([$dso_key]);
    $common_name = (string)($stmt->fetchColumn() ?: '');

    gs_labels($db);   // read the palette/equipment names before the transaction

    // One transaction for everything below; committed only when applying.
    $db->beginTransaction();
    try {
        $local = gs_works_root_available();
        $mode  = $local ? 'local' : 'remote';

        $works_map      = [];
        $misnamed       = [];
        $stale_projects = [];
        $folder_fixes   = [];
        $live_projects  = [];
        if ($local) {
            $projects = gs_repair_project_folders($db, $dso_key, $projects, $folder_fixes, $stale_projects);
            foreach ($projects as $p) {
                $folder = $p['ProjectFolder'];
                if (!$folder || !is_dir(WORKS_ROOT . DIRECTORY_SEPARATOR . $folder)) {
                    continue;   // reported by gs_repair_project_folders()
                }
                $live_projects[] = $p;
                // First Project wins if a BaseName appears in two folders.
                $works_map += build_works_root_map($folder, (int)$p['ProjectID'], $dso_key, $misnamed);
            }
        } else {
            $live_projects = $projects;
        }

        $project_folders = [];
        foreach ($projects as $p) $project_folders[(int)$p['ProjectID']] = $p['ProjectFolder'];

        $default_project = count($live_projects) === 1 ? $live_projects[0]
                         : (count($projects) === 1 ? $projects[0] : null);
        $default_project_id = $default_project ? (int)$default_project['ProjectID'] : null;

        $fulls = gs_web_bases('full', $dso_key);
        $favs  = gs_web_bases('fav',  $dso_key);
        $fav_without_full = array_values(array_diff_key($favs, $fulls));
        sort($fav_without_full, SORT_STRING | SORT_FLAG_CASE);
        $full_without_fav = array_values(array_diff_key($fulls, $favs));

        // Existing rows, keyed by lower(BaseName); duplicates removed.
        $stmt = $db->prepare('
            SELECT GalleryImageID AS "GalleryImageID", BaseName AS "BaseName",
                   DateCaptured AS "DateCaptured", Equipment AS "Equipment",
                   PaletteID AS "PaletteID", SessionDir AS "SessionDir",
                   ProjectID AS "ProjectID", IsFeature AS "IsFeature",
                   COALESCE(IsHidden, 0) AS "IsHidden"
            FROM GalleryImages WHERE DSOKey = ?
            ORDER BY SortOrder, GalleryImageID
        ');
        $stmt->execute([$dso_key]);
        $rows_by_name = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows_by_name[strtolower($row['BaseName'])][] = $row;
        }
        $existing = [];
        $duplicates_removed = [];
        $del = $db->prepare('DELETE FROM GalleryImages WHERE GalleryImageID = ?');
        foreach ($rows_by_name as $key => $group) {
            usort($group, function ($a, $b) {
                $fa = (int)$a['IsFeature'] && !(int)$a['IsHidden'];
                $fb = (int)$b['IsFeature'] && !(int)$b['IsHidden'];
                if ($fa !== $fb) return $fa ? -1 : 1;
                return (int)$a['GalleryImageID'] <=> (int)$b['GalleryImageID'];
            });
            $existing[$key] = $group[0];
            foreach (array_slice($group, 1) as $dup) {
                $del->execute([(int)$dup['GalleryImageID']]);
                $duplicates_removed[] = [
                    'GalleryImageID' => (int)$dup['GalleryImageID'],
                    'BaseName'       => $dup['BaseName'],
                    'KeptID'         => (int)$group[0]['GalleryImageID'],
                ];
            }
        }

        $next_sort = 1000;   // after everything; gallery_normalize() renumbers
        $inserted = $updated = $needs_session_dir = $to_delete = $deleted = [];
        $ins = $db->prepare('
            INSERT INTO GalleryImages
                (DSOKey, BaseName, PaletteID, DateCaptured, Equipment,
                 ProjectID, SessionDir, IsOwn, SortOrder, IsFeature)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, 0)
        ');
        $upd = $db->prepare('
            UPDATE GalleryImages
            SET DateCaptured = ?, Equipment = ?, PaletteID = ?, SessionDir = ?, ProjectID = ?
            WHERE GalleryImageID = ?
        ');

        foreach ($fulls as $bn_key => $bn) {
            $row = $existing[$bn_key] ?? null;

            $session_dir = null;
            if (isset($works_map[$bn_key]))          $session_dir = $works_map[$bn_key]['session'];
            elseif (!empty($row['SessionDir']))      $session_dir = $row['SessionDir'];
            elseif (!empty($hint_map[$bn_key]))      $session_dir = trim($hint_map[$bn_key]);

            if (isset($works_map[$bn_key]))          $project_id = $works_map[$bn_key]['project_id'];
            elseif (!empty($row['ProjectID']))       $project_id = (int)$row['ProjectID'];
            else                                     $project_id = $default_project_id;

            $palette_id = infer_palette($bn) ?? ($row !== null ? (int)$row['PaletteID'] : 0);

            if ($session_dir === null) {
                $needs_session_dir[] = ['BaseName' => $bn, 'paletteId' => $palette_id];
                if ($row === null) {
                    $ins->execute([$dso_key, $bn, $palette_id, null, null, $project_id, null, $next_sort++]);
                    $inserted[] = [
                        'GalleryImageID' => db_last_insert_id($db, 'GalleryImages', 'GalleryImageID'),
                        'BaseName' => $bn, 'DateCaptured' => null, 'Equipment' => null,
                        'IsMosaic' => 0, 'PaletteID' => $palette_id, 'SessionDir' => null,
                        'IsFeature' => 0, 'needs_session' => true,
                        'changes' => ['new image -- no session folder found for it in MyWorks, so no date or equipment'],
                    ];
                }
                continue;
            }

            $date      = date_from_session_dir($session_dir);
            $equipment = infer_equipment($session_dir);
            $is_mosaic = infer_is_mosaic($session_dir);

            if ($row !== null) {
                $id = (int)$row['GalleryImageID'];
                $before = [substr((string)$row['DateCaptured'], 0, 10), (string)$row['Equipment'],
                           (int)$row['PaletteID'], (string)$row['SessionDir'], (int)$row['ProjectID']];
                $after  = [(string)$date, (string)$equipment, $palette_id, (string)$session_dir, (int)$project_id];
                if ($before === $after) continue;
                $upd->execute([$date, $equipment, $palette_id, $session_dir, $project_id, $id]);
                $changes = [];
                if ($before[3] !== $after[3]) {
                    $changes[] = $before[3] === ''
                        ? "session set to $session_dir (date " . gs_date_label($date) . ")"
                        : "moved from session {$before[3]} (" . (gs_date_label($before[0]) ?: 'no date') . ") to $session_dir (" . gs_date_label($date) . ")";
                } elseif ($before[0] !== $after[0]) {
                    $changes[] = "date set to " . (gs_date_label($date) ?: 'blank') . " (was " . (gs_date_label($before[0]) ?: 'blank') . ")";
                }
                if ($before[1] !== $after[1]) {
                    $changes[] = "equipment set to " . gs_equipment_label($db, $equipment) . " (was " . gs_equipment_label($db, $before[1]) . ")";
                }
                if ($before[2] !== $after[2]) {
                    $changes[] = "palette set to " . gs_palette_label($db, $palette_id) . " (was " . gs_palette_label($db, $before[2]) . ")";
                }
                if ($before[4] !== $after[4]) {
                    $folder_of = fn($id) => $project_folders[$id] ?? ($id ? "Project $id" : 'no Project');
                    $changes[] = "moved to Project folder " . $folder_of($after[4]) . " (was " . $folder_of($before[4]) . ")";
                }
                $updated[] = [
                    'changes'        => $changes,
                    'GalleryImageID' => $id,
                    'BaseName'       => $row['BaseName'],
                    'DateCaptured'   => $date,
                    'Equipment'      => $equipment,
                    'IsMosaic'       => $is_mosaic,
                    'PaletteID'      => $palette_id,
                    'SessionDir'     => $session_dir,
                    'ProjectID'      => (int)$project_id,
                    'IsHidden'       => (int)$row['IsHidden'],
                    'was'            => array_combine(['DateCaptured', 'Equipment', 'PaletteID', 'SessionDir', 'ProjectID'], $before),
                ];
            } else {
                $ins->execute([$dso_key, $bn, $palette_id, $date, $equipment, $project_id, $session_dir, $next_sort++]);
                $inserted[] = [
                    'GalleryImageID' => db_last_insert_id($db, 'GalleryImages', 'GalleryImageID'),
                    'BaseName'       => $bn,
                    'DateCaptured'   => $date,
                    'Equipment'      => $equipment,
                    'IsMosaic'       => $is_mosaic,
                    'PaletteID'      => $palette_id,
                    'SessionDir'     => $session_dir,
                    'IsFeature'      => 0,
                    'changes'        => ['new image, ' . (gs_date_label($date) ?: 'no date') . ', '
                                         . gs_equipment_label($db, $equipment) . ', ' . gs_palette_label($db, $palette_id)],
                ];
            }
        }

        // Rows whose _full is gone: report, and delete only when asked.
        foreach ($existing as $bn_key => $row) {
            if (isset($fulls[$bn_key])) continue;
            $item = [
                'GalleryImageID' => (int)$row['GalleryImageID'],
                'BaseName'       => $row['BaseName'],
                'SessionDir'     => $row['SessionDir'],
                'IsHidden'       => (int)$row['IsHidden'],
                'IsFeature'      => (int)$row['IsFeature'],
                'hasFav'         => isset($favs[$bn_key]),
            ];
            if ($delete) {
                $del->execute([(int)$row['GalleryImageID']]);
                $deleted[] = $item;
            } else {
                $to_delete[] = $item;
            }
        }

        $images = gallery_normalize($db, $dso_key);
        $feat_by_id = [];
        foreach ($images as $img) $feat_by_id[$img['GalleryImageID']] = $img['IsFeature'];
        foreach ($inserted as &$i) $i['IsFeature'] = $feat_by_id[(int)$i['GalleryImageID']] ?? 0;
        unset($i);

        if ($apply) $db->commit();
        else        $db->rollBack();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

    $thumbs = $apply ? gallery_thumbs_sync($db, $dso_key) : null;

    $result = [
        'success'           => true,
        'dsoKey'            => $dso_key,
        'commonName'        => $common_name,
        'applied'           => $apply,
        'mode'              => $mode,
        'projectFolder'     => implode(', ', array_column($live_projects, 'ProjectFolder')),
        'inserted'          => $inserted,
        'updated'           => $updated,
        'deleted'           => $deleted,
        'toDelete'          => $to_delete,
        'needs_session_dir' => $needs_session_dir,
        'misnamed'          => $misnamed,
        'duplicatesRemoved' => $duplicates_removed,
        'staleProjects'     => $stale_projects,
        'projectFolderFixes'=> $folder_fixes,
        'favWithoutFull'    => $fav_without_full,
        'fullWithoutFav'    => $full_without_fav,
        'thumbs'            => $thumbs,
        'changed'           => (bool)($inserted || $updated || $deleted || $duplicates_removed || $folder_fixes),
    ];
    $result['report'] = gs_report($result);
    return $result;
}

/**
 * Plain-language report of one gallery_sync_dso() result, for the command
 * line and the admin alike:
 *   [ ['session' => 'yyyymmdd_...'|null, 'kind' => add|update|delete|warn|info,
 *      'text' => 'm42_orion_S30: equipment set to Seestar S30 (was blank)'], ... ]
 * 'session' null = about the DSO or the website, not one session folder.
 */
function gs_report(array $r): array {
    $out = [];
    $add = function (?string $session, string $kind, string $text) use (&$out) {
        $out[] = ['session' => $session ?: null, 'kind' => $kind, 'text' => $text];
    };
    foreach ($r['projectFolderFixes'] as $f) $add(null, 'info', $f['message']);
    foreach ($r['staleProjects'] as $p)      $add(null, 'warn', $p['message']);

    $new_names = [];
    foreach ($r['inserted'] as $i) {
        $new_names[strtolower($i['BaseName'])] = true;
        $add($i['SessionDir'], 'add', $i['BaseName'] . ': ' . implode('; ', $i['changes']));
    }
    foreach ($r['updated'] as $u) {
        $add($u['SessionDir'], 'update', $u['BaseName'] . ': ' . implode('; ', $u['changes']));
    }
    foreach ($r['deleted'] as $d) {
        $add($d['SessionDir'], 'delete', $d['BaseName'] . ': deleted from the gallery -- its _full is no longer on the website');
    }
    foreach ($r['toDelete'] as $d) {
        $add($d['SessionDir'], 'delete', $d['BaseName'] . ': its _full is no longer on the website (images/full)'
             . ($d['IsFeature'] ? ' -- it is the featured image' : '') . ($d['IsHidden'] ? ' -- it is hidden' : '')
             . '; remove it from the gallery');
    }
    foreach ($r['needs_session_dir'] as $n) {
        if (isset($new_names[strtolower($n['BaseName'])])) continue;
        $add(null, 'warn', $n['BaseName'] . ': no session folder in MyWorks has this image, so its date and equipment can\'t be set');
    }
    foreach ($r['duplicatesRemoved'] as $d) {
        $add(null, 'info', $d['BaseName'] . ': the gallery had it twice; the extra entry was removed');
    }
    foreach ($r['misnamed'] as $m) {
        $add($m['SessionDir'], 'warn', $m['File'] . ': name doesn\'t start with ' . strtolower($r['dsoKey'])
             . '_, so it can\'t be matched to ' . $r['dsoKey'] . ' -- rename it');
    }
    foreach ($r['favWithoutFull'] as $b) {
        $add(null, 'warn', "{$b}_fav.jpg is on the website without a _full, so it isn't in the gallery -- publish the _full or remove the fav");
    }
    return $out;
}

// ============================================================
// Every DSO
// ============================================================

/**
 * DSOs to sync: every DSO with a Project, except the Sun, Moon and planets
 * (comets are included).
 * @return string[] DSOKeys
 */
function gallery_sync_dso_keys(PDO $db): array {
    $rows = $db->query('
        SELECT DISTINCT p.DSOKey AS "DSOKey"
        FROM Projects p JOIN Objects o ON o.DSOKey = p.DSOKey
        ORDER BY p.DSOKey
    ')->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_filter(array_map('strval', $rows), fn($k) => !gs_uses_solar_pipeline($k)));
}

/**
 * Web fav/full files that no synced DSO claims ('<dsokey>_' prefix), so
 * nothing on the website goes unnoticed.
 * @return array [['File' => 'fav/xyz_fav.jpg', 'message' => '...'], ...]
 */
function gallery_unclaimed_web_files(array $dso_keys): array {
    $prefixes = array_map(fn($k) => strtolower($k) . '_', $dso_keys);
    $out = [];
    foreach (['full', 'fav'] as $kind) {
        $dir = gs_images_dir() . DIRECTORY_SEPARATOR . $kind;
        if (!is_dir($dir)) continue;
        foreach (scandir($dir) as $file) {
            if (!preg_match('/^(.+)_' . $kind . '\.jpg$/i', $file, $m)) continue;
            if (stripos($file, '_annotated') !== false) continue;
            $lower = strtolower($m[1]);
            $claimed = false;
            foreach ($prefixes as $p) {
                if (strncmp($lower, $p, strlen($p)) === 0) { $claimed = true; break; }
            }
            if (!$claimed) {
                $key = strtoupper(explode('_', $m[1])[0]);
                $out[] = ['File' => "$kind/$file",
                          'message' => "images/$kind/$file: no DSO with a Project has the key $key, so it isn't in the gallery"];
            }
        }
    }
    return $out;
}

/**
 * Run gallery_sync_dso() for every DSO with a Project.
 * @return array ['results' => [dsoKey => result|['error'=>msg]], 'unclaimed' => [...],
 *                'unpublished' => gallery_unpublished()]
 */
function gallery_sync_all(PDO $db, array $opts = [], ?callable $progress = null): array {
    $keys = gallery_sync_dso_keys($db);
    if (!empty($opts['only'])) {
        $keys = array_values(array_filter($keys, fn($k) => strcasecmp($k, $opts['only']) === 0));
        if (!$keys) throw new GallerySyncError("No DSO with a Project matches {$opts['only']}.");
    }
    $results = [];
    foreach ($keys as $key) {
        if ($progress) $progress($key);
        try {
            $results[$key] = gallery_sync_dso($db, $key, $opts);
        } catch (Throwable $e) {
            $results[$key] = ['success' => false, 'dsoKey' => $key, 'error' => $e->getMessage()];
        }
    }
    return [
        'results'     => $results,
        'unclaimed'   => empty($opts['only']) ? gallery_unclaimed_web_files(gallery_sync_dso_keys($db)) : [],
        'unpublished' => gs_works_root_available() ? gallery_unpublished($opts['only'] ?? null) : null,
    ];
}

// ============================================================
// "Forgot to publish": MyWorks renders not on the website, or newer
// than the web copy. Same rules as publish_images.py.
// ============================================================

/** Solar web names, preferred first (mirrors publish_images.py solar_dest_names). */
function gs_solar_dest_names(string $base, string $obj, string $date8): array {
    $toks = explode('_', $base);
    $has_obj = false;
    foreach ($toks as $t) if (in_array(strtolower($t), GS_SOLAR_OBJECTS, true)) { $has_obj = true; break; }
    if (!$has_obj) {
        $i = count($toks);
        foreach ($toks as $n => $t) if (ctype_digit($t)) { $i = $n; break; }
        array_splice($toks, $i, 0, [$obj]);
    }
    $name = implode('_', $toks);
    if ($date8 !== '' && !in_array($date8, $toks, true)) $name .= "_$date8";
    $names = [$name];
    if ($date8 !== '' && "{$base}_{$date8}" !== $name) $names[] = "{$base}_{$date8}";
    return $names;
}

/**
 * @param string|null $only_dso  limit to project folders whose first token is this DSO key
 * @return array ['missing' => [...], 'outdated' => [...], 'duplicates' => [...]]
 *   each item ['Project','SessionDir','File','Web'] (+ 'Note');
 *   duplicates: ['File' => lower name, 'Sessions' => ['proj\\session', ...]]
 */
function gallery_unpublished(?string $only_dso = null): array {
    $out = ['missing' => [], 'outdated' => [], 'duplicates' => []];
    if (!gs_works_root_available()) return $out;
    $web = gs_images_dir();

    // Every deep-sky _fav/_full in a non-ignored session, for the duplicate rule.
    $sessions = [];     // [project, session, path]
    foreach (scandir(WORKS_ROOT) as $project) {
        if ($project === '.' || $project === '..') continue;
        $pp = WORKS_ROOT . DIRECTORY_SEPARATOR . $project;
        if (!is_dir($pp) || in_array(strtolower($project), GS_SKIP_PROJECTS, true)) continue;
        foreach (scandir($pp) as $s) {
            $sp = $pp . DIRECTORY_SEPARATOR . $s;
            if ($s === '.' || $s === '..' || !is_dir($sp)) continue;
            if (!preg_match('/^\d{8}/', $s) || gs_session_ignored($s)) continue;
            $sessions[] = [$project, $s, $sp];
        }
    }
    $index = [];
    foreach ($sessions as [$project, $s, $sp]) {
        if (in_array(strtolower($project), GS_SOLAR_OBJECTS, true)) continue;
        foreach (scandir($sp) as $f) {
            if (preg_match('/^.+_(fav|full)\.jpe?g$/i', $f)) $index[strtolower($f)][] = "$project\\$s";
        }
    }
    $only = $only_dso !== null ? strtolower($only_dso) : null;
    foreach ($index as $name => $where) {
        if (count($where) < 2) continue;
        if ($only !== null) {
            $mine = array_filter($where, fn($w) => strtolower(explode('_', explode('\\', $w)[0])[0]) === $only);
            if (!$mine) continue;
        }
        $out['duplicates'][] = ['File' => $name, 'Sessions' => $where];
    }

    foreach ($sessions as [$project, $s, $sp]) {
        $obj = strtolower($project);
        $solar = in_array($obj, GS_SOLAR_OBJECTS, true);
        if ($only !== null && strtolower(explode('_', $project)[0]) !== $only) continue;

        $files = [];   // lower(base) => ['fav' => path, 'full' => path, 'base' => base]
        foreach (scandir($sp) as $f) {
            if (!preg_match('/^(.+)_(fav|full)\.jpe?g$/i', $f, $m)) continue;
            $files[strtolower($m[1])]['base'] = $m[1];
            $files[strtolower($m[1])][strtolower($m[2])] = $sp . DIRECTORY_SEPARATOR . $f;
        }

        foreach ($files as $info) {
            $base = $info['base'];
            $check = function (string $src, string $dest_rel, string $note = '') use (&$out, $project, $s, $web) {
                $dest = $web . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $dest_rel);
                $item = ['Project' => $project, 'SessionDir' => $s, 'File' => basename($src), 'Web' => $dest_rel];
                if ($note !== '') $item['Note'] = $note;
                if (!is_file($dest))                          $out['missing'][]  = $item;
                elseif (filemtime($src) > filemtime($dest))   $out['outdated'][] = $item;
            };

            if ($solar) {
                if (empty($info['fav'])) continue;
                $names = gs_solar_dest_names($base, $obj, substr($s, 0, 8));
                $dest_rel = "solar/{$names[0]}_fav.jpg";
                foreach (array_slice($names, 1) as $n) {
                    if (!is_file("$web/solar/{$n}_fav.jpg")) continue;
                    if (!is_file($web . '/' . $dest_rel)) $dest_rel = "solar/{$n}_fav.jpg";
                }
                $check($info['fav'], $dest_rel);
                continue;
            }

            $dup = false;
            foreach (['fav', 'full'] as $k) {
                if (!empty($info[$k]) && count($index[strtolower(basename($info[$k]))] ?? []) > 1) $dup = true;
            }
            if ($dup) continue;     // listed under duplicates; publish_images.py won't copy it

            if (!empty($info['fav'])) {
                $dest = "$web/fav/{$base}_fav.jpg";
                // A web fav made from the _full carries the _full's timestamp;
                // the real _fav replaces it on the next publish.
                if (!empty($info['full']) && is_file($dest)
                    && filemtime($dest) === filemtime($info['full'])
                    && filemtime($info['fav']) <= filemtime($dest)) {
                    $out['outdated'][] = ['Project' => $project, 'SessionDir' => $s,
                        'File' => basename($info['fav']), 'Web' => "fav/{$base}_fav.jpg",
                        'Note' => 'web fav was made from the _full'];
                } else {
                    $check($info['fav'], "fav/{$base}_fav.jpg");
                }
            }
            if (!empty($info['full'])) {
                $check($info['full'], "full/{$base}_full.jpg");
                if (empty($info['fav'])) {
                    $check($info['full'], "fav/{$base}_fav.jpg", 'fav to be made from the _full');
                }
            }
        }
    }
    return $out;
}
