<?php
// ============================================================
// lightbox/lib.php — shared helpers for the image Lightbox
//
// Scans C:\Astronomy\MyWorks (WORKS_ROOT, admin/config.php) for a DSO's
// observation sessions and the typed images in each session folder:
//
//   MyWorks/<project>/<YYYYMMDD_...>/<base>_<type>[_annotated].jpg
//   type = fav | full | wall | 4k
//
// Wall images aren't files: there are none in MyWorks (only the odd one
// downloaded from the gallery) or in public/images. The Wall type is made
// on the fly from each session's _full, turned to landscape the way the
// gallery turns it (slideshow/overlay.js): 90 degrees counterclockwise, or
// clockwise when the name has a "_cw_" token, then flipped left to right
// when the name has a "_fw_" token. A landscape _full is left as is. Each
// one is listed under a virtual name, <base>_wall.jpg, so its rating is
// kept apart from the _full's. Real _wall files are ignored.
//
// Hosted mode (lb_hosted()): on the hosted site there is no MyWorks, so the
// published copies in public/images (fav/, full/, wall4k/) are shown
// instead, grouped into the same project/session folders by the
// .published.json manifest there (GalleryImages + Projects for any file it
// doesn't list). Names are lower case in that mode. Add ?site=1 to see the
// same view locally. Ratings match on DSO token + session + file name
// without regard to case, so both modes share them.
//
// Only the session folder itself is scanned (not master/ or work/), the
// same as titling.py. Sessions whose name contains "ignore" or "bust" are
// flagged as ignored (titling.py skips them), and images whose base name
// matches a GalleryImages row with IsHidden <> 0 are flagged as hidden.
// Both are left out unless the page's "Show hidden" box is ticked.
//
// Fragment matching mirrors titling.py's resolve_input_path():
//   1. exact folder name (case-insensitive)
//   2. substring match, where 'mosaic' in the fragment must agree with
//      'mosaic' in the folder name (ngc7380 vs ngc7380_..._mosaic)
//   first alphabetical match wins; the other matches are returned too.
//
// Ratings live in the ImageRatings table (dsodb/migrations/
// migrate_add_image_ratings.py), keyed by (DSOToken, SessionDir, FileName).
// DSOToken is the folder's first '_' token, uppercased (M31, SH2-101) —
// it survives a project-folder rename, unlike the folder name itself.
// ============================================================

require_once __DIR__ . '/../admin/config.php';
require_once __DIR__ . '/../../shared/db.php';

const LB_TYPES = ['fav', 'full', 'wall', '4k'];

// public/images subfolder for each type, for the "on site" badge.
// A wall is on site when its _full is.
const LB_SITE_DIRS = [
    'fav'  => 'fav',
    'full' => 'full',
    'wall' => 'full',
    '4k'   => 'wall4k',
];

const LB_PALETTES = ['sho', 'hoo', 'hso', 'hos'];

function lb_root(): string
{
    return rtrim(WORKS_ROOT, '\\/');
}

/** public/images, where the published copies live. */
function lb_site_root(): ?string
{
    static $root = false;
    if ($root === false) $root = realpath(__DIR__ . '/../images') ?: null;
    return $root;
}

/**
 * Hosted mode: there's no MyWorks to read (the hosted site), or the
 * request asks for the published images with ?site=1.
 */
function lb_hosted(): bool
{
    static $hosted = null;
    if ($hosted === null) $hosted = !empty($_GET['site']) || !is_dir(lb_root());
    return $hosted;
}

/**
 * Lower-case BaseName => "projectfolder/sessiondir" from GalleryImages,
 * for published files the .published.json manifest doesn't list.
 */
function lb_site_db_sessions(): array
{
    static $map = null;
    if ($map !== null) return $map;
    $map = [];
    $db = lb_try_db();
    if (!$db) return $map;
    try {
        $rows = $db->query('SELECT gi.BaseName AS "BaseName", gi.SessionDir AS "SessionDir",
                                   p.ProjectFolder AS "ProjectFolder"
                            FROM GalleryImages gi JOIN Projects p ON gi.ProjectID = p.ProjectID')
                   ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $map;
    }
    foreach ($rows as $r) {
        $folder  = trim((string)$r['ProjectFolder']);
        $session = trim((string)$r['SessionDir']);
        if ($folder !== '' && $session !== '') {
            $map[strtolower(trim((string)$r['BaseName']))] = strtolower($folder . '/' . $session);
        }
    }
    return $map;
}

/**
 * The published images as project => session => [file names], all lower
 * case, built from what is really in public/images fav/, full/ and wall4k/.
 */
function lb_site_index(): array
{
    static $idx = null;
    if ($idx !== null) return $idx;
    $idx = [];
    $root = lb_site_root();
    if (!$root) return $idx;
    $manifest = json_decode((string)@file_get_contents(lb_join($root, '.published.json')), true);
    $manifest = is_array($manifest) ? array_change_key_case($manifest, CASE_LOWER) : [];
    foreach (['fav', 'full', 'wall4k'] as $dir) {
        foreach (@scandir(lb_join($root, $dir)) ?: [] as $f) {
            $c = lb_classify($f);
            if (!$c || $c['annotated'] || $c['type'] === 'wall' || LB_SITE_DIRS[$c['type']] !== $dir) continue;
            $where = $manifest[strtolower($dir . '/' . $f)] ?? (lb_site_db_sessions()[$c['base']] ?? null);
            $parts = is_string($where) ? explode('/', strtolower($where)) : [];
            if (count($parts) !== 2 || !lb_clean_name($parts[0]) || !lb_is_session_dir($parts[1])) continue;
            $idx[$parts[0]][$parts[1]][] = $f;
        }
    }
    ksort($idx, SORT_STRING);
    return $idx;
}

function lb_join(string ...$parts): string
{
    return implode(DIRECTORY_SEPARATOR, $parts);
}

function lb_is_session_dir(string $name): bool
{
    return strlen($name) >= 8 && ctype_digit(substr($name, 0, 8));
}

function lb_is_ignored(string $name): bool
{
    $l = strtolower($name);
    return str_contains($l, 'ignore') || str_contains($l, 'bust');
}

function lb_token(string $folder): string
{
    return strtoupper(explode('_', $folder)[0]);
}

/** A single path component that can't climb out of its parent. */
function lb_clean_name(string $name): bool
{
    return $name !== '' && $name !== '.' && $name !== '..'
        && !preg_match('#[\\\\/:\x00]#', $name);
}

/** All project folders under MyWorks, sorted the way Python's sorted() does. */
function lb_project_dirs(): array
{
    if (lb_hosted()) return array_map('strval', array_keys(lb_site_index()));
    $root = lb_root();
    $out = [];
    foreach (@scandir($root) ?: [] as $name) {
        if ($name[0] === '.') continue;
        if (is_dir(lb_join($root, $name))) $out[] = $name;
    }
    sort($out, SORT_STRING);
    return $out;
}

/**
 * titling.py-style fragment resolution.
 * Returns ['match' => folder|null, 'candidates' => [folders...]].
 */
function lb_resolve(string $q, ?array $dirs = null): array
{
    $dirs = $dirs ?? lb_project_dirs();
    $q = strtolower(trim($q));
    if ($q === '') return ['match' => null, 'candidates' => []];

    foreach ($dirs as $d) {
        if (strtolower($d) === $q) return ['match' => $d, 'candidates' => [$d]];
    }

    $wantMosaic = str_contains($q, 'mosaic');
    $cands = [];
    foreach ($dirs as $d) {
        $l = strtolower($d);
        if (str_contains($l, $q) && str_contains($l, 'mosaic') === $wantMosaic) $cands[] = $d;
    }
    return ['match' => $cands[0] ?? null, 'candidates' => $cands];
}

/**
 * Classify a file name. Returns null if it isn't a typed JPEG, else
 * ['type' => fav|full|wall|4k, 'annotated' => bool, 'base' => stem-without-suffixes,
 *  'palette' => 'HOO'|null].
 */
function lb_classify(string $file): ?array
{
    if (!preg_match('/\.jpe?g$/i', $file)) return null;
    $stem = strtolower(preg_replace('/\.jpe?g$/i', '', $file));
    $annotated = false;
    if (str_ends_with($stem, '_annotated')) {
        $annotated = true;
        $stem = substr($stem, 0, -strlen('_annotated'));
    }
    foreach (LB_TYPES as $t) {
        $suffix = '_' . $t;
        if (str_ends_with($stem, $suffix)) {
            $base = substr($stem, 0, -strlen($suffix));
            $palette = null;
            foreach (array_reverse(explode('_', $base)) as $seg) {
                if (in_array($seg, LB_PALETTES, true)) { $palette = strtoupper($seg); break; }
            }
            return ['type' => $t, 'annotated' => $annotated, 'base' => $base, 'palette' => $palette];
        }
    }
    return null;
}

/** True when a "_"-separated token of the name is "cw" (as overlay.js isClockwise()). */
function lb_is_clockwise(string $name): bool
{
    $stem = strtolower(preg_replace('/\.jpe?g$/i', '', $name));
    return in_array('cw', explode('_', $stem), true);
}

/** True when a "_"-separated token of the name is "fw": flipped left to right
 *  once turned (as overlay.js isFlipped()). */
function lb_is_flipped(string $name): bool
{
    $stem = strtolower(preg_replace('/\.jpe?g$/i', '', $name));
    return in_array('fw', explode('_', $stem), true);
}

/** "X_full.jpg" -> "X_wall.jpg" (the virtual wall name), and back. */
function lb_wall_name(string $fullFile): string
{
    return preg_replace('/_full(\.jpe?g)$/i', '_wall$1', $fullFile);
}

function lb_full_name(string $wallFile): string
{
    return preg_replace('/_wall(\.jpe?g)$/i', '_full$1', $wallFile);
}

/**
 * Degrees clockwise to turn a _full of $w x $h into a landscape wall:
 * 0 if it's already landscape (or square), 90 for _cw_, else -90.
 */
function lb_wall_rotation(string $fullFile, ?int $w, ?int $h): int
{
    if (!$w || !$h || $h <= $w) return 0;
    return lb_is_clockwise($fullFile) ? 90 : -90;
}

/** Facts about a session folder that can be read from its name. */
function lb_session_meta(string $session): array
{
    $d = DateTime::createFromFormat('Ymd', substr($session, 0, 8));
    $meta = [
        'session'  => $session,
        'date'     => $d ? $d->format('Y-m-d') : null,
        'ignored'  => lb_is_ignored($session),
        'rig'      => null,
        'frames'   => null,
        'exposure' => null,
        'minutes'  => null,
        'mosaic'   => str_contains(strtolower($session), 'mosaic'),
    ];
    foreach (explode('_', $session) as $seg) {
        if (preg_match('/^S\d+P?$/i', $seg)) $meta['rig'] = strtoupper($seg);
    }
    if (preg_match('/(\d+)x(\d+)s/i', $session, $m)) {
        $meta['frames']   = (int)$m[1];
        $meta['exposure'] = (int)$m[2];
        $meta['minutes']  = (int)round($m[1] * $m[2] / 60);
    }
    return $meta;
}

/** Session folder names for a project, oldest first. */
function lb_sessions(string $project): array
{
    if (lb_hosted()) {
        $out = array_map('strval', array_keys(lb_site_index()[$project] ?? []));
        sort($out, SORT_STRING);
        return $out;
    }
    $dir = lb_join(lb_root(), $project);
    $out = [];
    foreach (@scandir($dir) ?: [] as $name) {
        if (lb_is_session_dir($name) && is_dir(lb_join($dir, $name))) $out[] = $name;
    }
    sort($out, SORT_STRING);
    return $out;
}

/**
 * Lowercased BaseName => position in the gallery order set in DSO Admin
 * (the same order the gallery shows: featured first, then SortOrder, then
 * GalleryImageID; hidden images last). Empty if the DB isn't reachable.
 */
function lb_gallery_order(?PDO $db): array
{
    if (!$db) return [];
    $sql = 'SELECT BaseName AS "BaseName" FROM GalleryImages
            ORDER BY %s COALESCE(IsFeature, 0) DESC, COALESCE(SortOrder, 0), GalleryImageID';
    try {
        $rows = $db->query(sprintf($sql, 'COALESCE(IsHidden, 0),'))->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        try {
            $rows = $db->query(sprintf($sql, ''))->fetchAll(PDO::FETCH_COLUMN);   // no IsHidden column yet
        } catch (Throwable $e2) {
            return [];
        }
    }
    $out = [];
    foreach ($rows as $i => $b) {
        $k = strtolower(trim((string)$b));
        if (!isset($out[$k])) $out[$k] = $i;
    }
    return $out;
}

/** File names in one session (MyWorks folder, or its published copies), sorted. */
function lb_session_files(string $project, string $session): array
{
    $files = lb_hosted()
        ? (lb_site_index()[$project][$session] ?? [])
        : (@scandir(lb_join(lb_root(), $project, $session)) ?: []);
    sort($files, SORT_STRING);
    return $files;
}

/** Where a session's file is on disk (not checked). */
function lb_file_path(string $project, string $session, string $file): string
{
    if (!lb_hosted()) return lb_join(lb_root(), $project, $session, $file);
    $c = lb_classify($file);
    return lb_join((string)lb_site_root(), LB_SITE_DIRS[$c['type'] ?? 'fav'], $file);
}

/**
 * Lowercased BaseNames of gallery images hidden in DSO Admin
 * (GalleryImages.IsHidden <> 0), as a set: ['m31_hoo' => true, ...].
 * Empty if the DB isn't reachable or the column doesn't exist yet.
 */
function lb_hidden_bases(?PDO $db): array
{
    if (!$db) return [];
    try {
        $rows = $db->query('SELECT BaseName AS "BaseName" FROM GalleryImages WHERE COALESCE(IsHidden, 0) <> 0')
                   ->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $b) $out[strtolower(trim((string)$b))] = true;
    return $out;
}

/**
 * Count of plain (non-annotated) images per type in non-ignored sessions,
 * leaving out images hidden in GalleryImages.
 * Cheap: directory listings only, no image headers read.
 */
function lb_counts(string $project, array $hidden = []): array
{
    $counts = array_fill_keys(LB_TYPES, 0);
    $sessions = 0;
    $pairs = [];
    foreach (lb_sessions($project) as $s) {
        if (lb_is_ignored($s)) continue;
        $sessions++;
        foreach (lb_session_files($project, $s) as $f) {
            $c = lb_classify($f);
            if (!$c || $c['annotated'] || isset($hidden[$c['base']])) continue;
            $counts[$c['type']]++;
            if ($c['type'] === 'fav' || $c['type'] === 'full') $pairs[$s . '/' . $c['base']] = true;
        }
    }
    $counts['wall'] = $counts['full'];   // walls are made from the _full files
    $counts['all']  = count($pairs);     // the All tab: one pairing per image (its fav + full + wall)
    return ['counts' => $counts, 'sessions' => $sessions];
}

/**
 * Every image of one type across a project's sessions.
 * $showHidden includes ignored sessions and images whose base name is in $hidden.
 */
function lb_images(string $project, string $type, bool $showHidden, array $hidden = []): array
{
    $root = lb_root();
    $siteRoot = realpath(__DIR__ . '/../images');
    $srcType = $type === 'wall' ? 'full' : $type;
    $images = [];
    $sessions = [];
    foreach (lb_sessions($project) as $s) {
        $meta = lb_session_meta($s);
        if ($meta['ignored'] && !$showHidden) continue;
        $found = 0;
        $hiddenN = 0;
        foreach (lb_session_files($project, $s) as $f) {
            $c = lb_classify($f);
            // Old _annotated renders still sit in some sessions; they're never shown.
            if (!$c || $c['type'] !== $srcType || $c['annotated']) continue;
            $isHidden = isset($hidden[$c['base']]);
            if ($isHidden && !$showHidden) { $hiddenN++; continue; }
            $path = lb_file_path($project, $s, $f);
            $size = @getimagesize($path);
            $w = $size[0] ?? null;
            $h = $size[1] ?? null;
            $file = $f;
            $rotate = 0;
            $flip = false;
            if ($type === 'wall') {
                $file = lb_wall_name($f);
                $rotate = lb_wall_rotation($f, $w, $h);
                $flip = $rotate !== 0 && lb_is_flipped($f);
                if ($rotate) [$w, $h] = [$h, $w];
            }
            $siteDir = LB_SITE_DIRS[$type];
            $images[] = $meta + [
                'type'    => $type,
                'file'    => $file,        // the rating key; a virtual _wall name for walls
                'source'  => $f,           // the file on disk
                'rotate'  => $rotate,      // degrees clockwise img.php turns it
                'flip'    => $flip,        // then flipped left to right (_fw_)
                'base'    => $c['base'],
                'palette' => $c['palette'],
                'hidden'  => $isHidden,
                'width'   => $w,
                'height'  => $h,
                'bytes'   => @filesize($path) ?: null,
                'mtime'   => @filemtime($path) ?: null,
                'onSite'  => $siteRoot ? is_file(lb_join($siteRoot, $siteDir, $f)) : false,
            ];
            $found++;
        }
        $sessions[] = $meta + ['count' => $found, 'hidden' => $hiddenN];
    }
    return ['images' => $images, 'sessions' => $sessions];
}

/**
 * The All tab: every fav, full and wall image of a project in one list (each
 * has its 'type'); the page pairs them up by session + base name.
 */
function lb_all_images(string $project, bool $showHidden, array $hidden = []): array
{
    $images = [];
    $sessions = [];
    foreach (['fav', 'full', 'wall'] as $type) {
        $one = lb_images($project, $type, $showHidden, $hidden);
        $images = array_merge($images, $one['images']);
        foreach ($one['sessions'] as $s) {
            $k = $s['session'];
            if (!isset($sessions[$k])) { $sessions[$k] = $s; continue; }
            $sessions[$k]['count']  = max($sessions[$k]['count'], $s['count']);
            $sessions[$k]['hidden'] = max($sessions[$k]['hidden'], $s['hidden']);
        }
    }
    return ['images' => $images, 'sessions' => array_values($sessions)];
}

/** Absolute path of a session image, or null if the request is bad. */
function lb_safe_path(string $project, string $session, string $file): ?string
{
    if (!lb_clean_name($project) || !lb_clean_name($session) || !lb_clean_name($file)) return null;
    if (!lb_is_session_dir($session) || !lb_classify($file)) return null;
    if (lb_hosted()) {
        if (!in_array($file, lb_site_index()[$project][$session] ?? [], true)) return null;
        $real = realpath(lb_file_path($project, $session, $file));
        return ($real !== false && is_file($real)) ? $real : null;
    }
    $path = lb_join(lb_root(), $project, $session, $file);
    $real = realpath($path);
    $root = realpath(lb_root());
    if ($real === false || $root === false || !is_file($real)) return null;
    $prefix = rtrim($root, '\\/') . DIRECTORY_SEPARATOR;
    if (strncasecmp($real, $prefix, strlen($prefix)) !== 0) return null;
    return $real;
}

/**
 * Where a Lightbox image's pixels come from, or null if the request is bad:
 * ['path' => absolute path, 'rotate' => degrees clockwise, 'flip' => flipped
 * left to right after turning]. A _wall name is always made from the
 * session's _full (see the top of this file).
 */
function lb_image_source(string $project, string $session, string $file): ?array
{
    $c = lb_classify($file);
    if (!$c) return null;
    if ($c['type'] === 'wall') {
        if ($c['annotated']) return null;
        $full = lb_safe_path($project, $session, lb_full_name($file));
        if ($full === null) return null;
        $size = @getimagesize($full);
        $rotate = lb_wall_rotation(basename($full), $size[0] ?? null, $size[1] ?? null);
        return ['path' => $full, 'rotate' => $rotate,
                'flip' => $rotate !== 0 && lb_is_flipped(basename($full))];
    }
    $path = lb_safe_path($project, $session, $file);
    return $path === null ? null : ['path' => $path, 'rotate' => 0, 'flip' => false];
}

/**
 * DSO token => CommonName, resolved through Objects and CatalogIDs
 * (same idea as check-missing's infer_dso_key). Empty array if the DB
 * isn't reachable — names are a nicety, not required.
 */
function lb_common_names(?PDO $db): array
{
    if (!$db) return [];
    $norm = fn($s) => strtoupper(str_replace(' ', '', (string)$s));
    $names = [];
    try {
        $rows = $db->query('SELECT DSOKey AS "DSOKey", CommonName AS "CommonName" FROM Objects')
                   ->fetchAll(PDO::FETCH_ASSOC);
        $byKey = [];
        foreach ($rows as $r) {
            $byKey[$r['DSOKey']] = $r['CommonName'];
            $names[$norm($r['DSOKey'])] = $r['CommonName'];
        }
        foreach ($db->query('SELECT CatalogID AS "CatalogID", DSOKey AS "DSOKey" FROM CatalogIDs')
                    ->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $k = $norm($c['CatalogID']);
            if (!isset($names[$k]) && isset($byKey[$c['DSOKey']])) $names[$k] = $byKey[$c['DSOKey']];
        }
    } catch (Throwable $e) {
        return [];
    }
    return $names;
}

function lb_try_db(): ?PDO
{
    try {
        return get_db();
    } catch (Throwable $e) {
        return null;
    }
}

/** True if the PDOException means "ImageRatings doesn't exist yet". */
function lb_is_missing_table(Throwable $e): bool
{
    $m = strtolower($e->getMessage());
    return str_contains($m, 'imageratings') &&
        (str_contains($m, 'no such table') || str_contains($m, 'does not exist') || str_contains($m, 'undefined table'));
}

/** "Session/File" => ['rating'=>, 'note'=>, 'updated'=>] for one DSO token. */
function lb_ratings(PDO $db, string $token): array
{
    $stmt = $db->prepare('SELECT SessionDir AS "SessionDir", FileName AS "FileName", Rating AS "Rating",
                                 Note AS "Note", UpdatedAt AS "UpdatedAt"
                          FROM ImageRatings WHERE DSOToken = ?');
    $stmt->execute([$token]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        // lower case: published copies and MyWorks names differ only in case
        $out[strtolower($r['SessionDir'] . '/' . $r['FileName'])] = [
            'rating'  => $r['Rating'] !== null ? (int)$r['Rating'] : null,
            'note'    => $r['Note'] ?? '',
            'updated' => $r['UpdatedAt'],
        ];
    }
    return $out;
}

/** Upsert a rating/note; an empty rating AND empty note deletes the row. */
function lb_save_rating(PDO $db, string $project, string $session, string $file,
                        ?int $rating, string $note): void
{
    $token = lb_token($project);
    // Published copies are lower case, MyWorks names aren't: reuse the row's
    // own spelling, so an image has one rating wherever it is rated.
    $find = $db->prepare('SELECT SessionDir AS "SessionDir", FileName AS "FileName" FROM ImageRatings
                          WHERE DSOToken = ? AND LOWER(SessionDir) = LOWER(?) AND LOWER(FileName) = LOWER(?)');
    $find->execute([$token, $session, $file]);
    if ($row = $find->fetch(PDO::FETCH_ASSOC)) {
        $session = $row['SessionDir'];
        $file    = $row['FileName'];
    }
    $type  = lb_classify($file)['type'];
    if ($rating === null && trim($note) === '') {
        $stmt = $db->prepare('DELETE FROM ImageRatings WHERE DSOToken = ? AND SessionDir = ? AND FileName = ?');
        $stmt->execute([$token, $session, $file]);
        return;
    }
    $stmt = $db->prepare(
        'INSERT INTO ImageRatings (DSOToken, SessionDir, FileName, ProjectFolder, ImageType, Rating, Note, UpdatedAt)
         VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
         ON CONFLICT (DSOToken, SessionDir, FileName) DO UPDATE SET
             ProjectFolder = excluded.ProjectFolder,
             ImageType     = excluded.ImageType,
             Rating        = excluded.Rating,
             Note          = excluded.Note,
             UpdatedAt     = CURRENT_TIMESTAMP'
    );
    $stmt->execute([$token, $session, $file, $project, $type, $rating, $note === '' ? null : $note]);
}
