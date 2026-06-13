<?php
/**
 * dso_preview.php  —  Returns the URL of a preview image for a DSO key
 *
 * GET /api/dso_preview.php?key=IC1805
 *
 * Searches (in order):
 *   1. public/images/thumbs/     — any file starting with <dsokey_lower>
 *   2. public/images/visibility/ — any file starting with <dsokey_lower>
 *
 * On match: 200 JSON  { "url": "/images/thumbs/ic1805_heart.jpg" }
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

$searchDirs = [
    'images/thumbs'     => $publicDir . '/images/thumbs',
    'images/visibility' => $publicDir . '/images/visibility',
];

foreach ($searchDirs as $urlBase => $dir) {
    if (!is_dir($dir)) continue;
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        if (stripos($f, $prefix) !== 0) continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (!in_array($ext, $imageExts, true)) continue;
        header('Cache-Control: public, max-age=3600');
        echo json_encode(['url' => '/' . $urlBase . '/' . $f]);
        exit;
    }
}

http_response_code(404);
echo json_encode(['url' => null]);
exit;
