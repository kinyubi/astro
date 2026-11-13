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

// public/images subfolder for each type, for the "on site" badge
const LB_SITE_DIRS = [
    'fav'  => 'fav',
    'full' => 'full',
    'wall' => 'wall',
    '4k'   => 'wall4k',
];

const LB_PALETTES = ['sho', 'hoo', 'hso', 'hos'];

function lb_root(): string
{
    return rtrim(WORKS_ROOT, '\\/');
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
    $dir = lb_join(lb_root(), $project);
    $out = [];
    foreach (@scandir($dir) ?: [] as $name) {
        if (lb_is_session_dir($name) && is_dir(lb_join($dir, $name))) $out[] = $name;
    }
    sort($out, SORT_STRING);
    return $out;
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
    foreach (lb_sessions($project) as $s) {
        if (lb_is_ignored($s)) continue;
        $sessions++;
        foreach (@scandir(lb_join(lb_root(), $project, $s)) ?: [] as $f) {
            $c = lb_classify($f);
            if ($c && !$c['annotated'] && !isset($hidden[$c['base']])) $counts[$c['type']]++;
        }
    }
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
    $images = [];
    $sessions = [];
    foreach (lb_sessions($project) as $s) {
        $meta = lb_session_meta($s);
        if ($meta['ignored'] && !$showHidden) continue;
        $found = 0;
        $hiddenN = 0;
        $dir = lb_join($root, $project, $s);
        $files = @scandir($dir) ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $f) {
            $c = lb_classify($f);
            // Old _annotated renders still sit in some sessions; they're never shown.
            if (!$c || $c['type'] !== $type || $c['annotated']) continue;
            $isHidden = isset($hidden[$c['base']]);
            if ($isHidden && !$showHidden) { $hiddenN++; continue; }
            $path = lb_join($dir, $f);
            $size = @getimagesize($path);
            $siteDir = LB_SITE_DIRS[$type];
            $images[] = $meta + [
                'file'    => $f,
                'base'    => $c['base'],
                'palette' => $c['palette'],
                'hidden'  => $isHidden,
                'width'   => $size[0] ?? null,
                'height'  => $size[1] ?? null,
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

/** Absolute path of a session image, or null if the request is bad. */
function lb_safe_path(string $project, string $session, string $file): ?string
{
    if (!lb_clean_name($project) || !lb_clean_name($session) || !lb_clean_name($file)) return null;
    if (!lb_is_session_dir($session) || !lb_classify($file)) return null;
    $path = lb_join(lb_root(), $project, $session, $file);
    $real = realpath($path);
    $root = realpath(lb_root());
    if ($real === false || $root === false || !is_file($real)) return null;
    $prefix = rtrim($root, '\\/') . DIRECTORY_SEPARATOR;
    if (strncasecmp($real, $prefix, strlen($prefix)) !== 0) return null;
    return $real;
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
        $out[$r['SessionDir'] . '/' . $r['FileName']] = [
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
