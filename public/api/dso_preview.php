<?php
/**
 * dso_preview.php  —  Returns the URL of a preview image for a DSO key
 *
 * GET /api/dso_preview.php?key=IC1805
 *
 * Searches (in order):
 *   1. The featured gallery image's thumb (GalleryImages, visible,
 *      IsFeature = 1) -> images/thumbs/<BaseName>_thumb.jpg, if on disk
 *   2. public/images/thumbs/        — any file named <dsokey>_... / <dsokey>.ext
 *   3. public/images/other_seestar/ — same naming (images not in our gallery)
 *   4. public/images/visibility/    — same naming
 *
 * File names must be the lowercase DSO key followed by '_' or '.', so
 * M1 never picks up m101_... (case-insensitive).
 *
 * On match: 200 JSON  { "url": "/images/thumbs/ic1805_heart_S30_thumb.jpg" }
 * No match: 404 JSON  { "url": null }
 */

header('Content-Type: application/json');

$key = trim($_GET['key'] ?? '');
if (!$key || !preg_match('/^[A-Za-z0-9_\-\/]+$/', $key)) {
    http_response_code(404);
    echo json_encode(['url' => null]);
    exit;
}

$prefix    = strtolower($key);
$publicDir = dirname(__DIR__);
$imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

function preview_found(string $url): void {
    header('Cache-Control: public, max-age=3600');
    echo json_encode(['url' => $url]);
    exit;
}

// 1. Featured gallery image's thumb (best effort -- the folder scan below
//    still works if the database can't be reached).
try {
    $dbPhp = dirname(__DIR__, 2) . '/shared/db.php';
    if (!is_file($dbPhp)) throw new RuntimeException('no shared/db.php');
    require_once $dbPhp;
    $db   = get_db();
    $stmt = $db->prepare('
        SELECT BaseName AS "BaseName"
        FROM GalleryImages
        WHERE UPPER(DSOKey) = UPPER(?)
          AND COALESCE(IsHidden, 0) = 0
        ORDER BY COALESCE(IsFeature, 0) DESC, SortOrder, GalleryImageID
        LIMIT 1
    ');
    $stmt->execute([$key]);
    $bn = $stmt->fetchColumn();
    if ($bn && is_file($publicDir . '/images/thumbs/' . $bn . '_thumb.jpg')) {
        preview_found('/images/thumbs/' . $bn . '_thumb.jpg');
    }
} catch (Throwable $e) {
    // fall through to the folder scan
}

// 2-4. Folder scan by file-name prefix
$searchDirs = [
    'images/thumbs'        => $publicDir . '/images/thumbs',
    'images/other_seestar' => $publicDir . '/images/other_seestar',
    'images/visibility'    => $publicDir . '/images/visibility',
];

$plen = strlen($prefix);
foreach ($searchDirs as $urlBase => $dir) {
    if (!is_dir($dir)) continue;
    $files = scandir($dir);
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($files as $f) {
        if (strncasecmp($f, $prefix, $plen) !== 0) continue;
        $next = substr($f, $plen, 1);
        if ($next !== '_' && $next !== '.') continue;   // M1 must not match m101_...
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (!in_array($ext, $imageExts, true)) continue;
        preview_found('/' . $urlBase . '/' . rawurlencode($f));
    }
}

http_response_code(404);
echo json_encode(['url' => null]);
exit;
