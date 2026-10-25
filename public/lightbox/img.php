<?php
// ============================================================
// lightbox/img.php — serves a MyWorks session image
//
//   ?p=<project>&s=<session>&f=<file>             cached preview (long side 1200px)
//   ?p=<project>&s=<session>&f=<file>&size=full   the original file
//
// Previews are built with GD and cached in public/cache/lightbox/ (git-
// ignored), keyed by path + mtime + size, so a re-rendered image gets a
// fresh preview automatically. If GD isn't available or the image is too
// big to decode, the original is served instead.
// ============================================================

require_once __DIR__ . '/../admin/auth_api.php';
require_once __DIR__ . '/lib.php';

const LB_PREVIEW_MAX = 1200;
const LB_PREVIEW_QUALITY = 85;

$path = lb_safe_path((string)($_GET['p'] ?? ''), (string)($_GET['s'] ?? ''), (string)($_GET['f'] ?? ''));
if ($path === null) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'Not found';
    exit;
}

function lb_send_file(string $file): void
{
    $mtime = filemtime($file);
    $etag  = '"' . md5($file . '|' . $mtime . '|' . filesize($file)) . '"';
    header('Content-Type: image/jpeg');
    header('Cache-Control: private, max-age=86400');
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        exit;
    }
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}

if (($_GET['size'] ?? '') === 'full') {
    lb_send_file($path);
}

$cacheDir = __DIR__ . '/../cache/lightbox';
$key = md5(strtolower($path) . '|' . filemtime($path) . '|' . filesize($path) . '|' . LB_PREVIEW_MAX);
$cached = $cacheDir . DIRECTORY_SEPARATOR . $key . '.jpg';

if (is_file($cached)) {
    lb_send_file($cached);
}

$info = @getimagesize($path);
if (!function_exists('imagecreatefromjpeg') || !$info || max($info[0], $info[1]) <= LB_PREVIEW_MAX) {
    lb_send_file($path);          // no GD, unreadable header, or already small
}

@ini_set('memory_limit', '2048M');
@set_time_limit(120);

$src = @imagecreatefromjpeg($path);
if (!$src) {
    lb_send_file($path);
}
[$w, $h] = [imagesx($src), imagesy($src)];
$scale = LB_PREVIEW_MAX / max($w, $h);
$dst = imagescale($src, max(1, (int)round($w * $scale)), max(1, (int)round($h * $scale)), IMG_BICUBIC);
imagedestroy($src);
if (!$dst) {
    lb_send_file($path);
}

if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
$tmp = $cached . '.' . getmypid() . '.tmp';
if (@imagejpeg($dst, $tmp, LB_PREVIEW_QUALITY) && @rename($tmp, $cached)) {
    imagedestroy($dst);
    lb_send_file($cached);
}
@unlink($tmp);

// Couldn't write the cache — send the preview straight from memory
header('Content-Type: image/jpeg');
header('Cache-Control: private, max-age=3600');
imagejpeg($dst, null, LB_PREVIEW_QUALITY);
imagedestroy($dst);
