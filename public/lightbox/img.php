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
//
// A _wall file name is made from the session's _full, turned to landscape
// (lib.php lb_image_source(): counterclockwise, or clockwise for _cw_).
// Both its preview and its size=full version are cached turned.
// ============================================================

require_once __DIR__ . '/../admin/auth_api.php';
require_once __DIR__ . '/lib.php';

const LB_PREVIEW_MAX = 1200;
const LB_PREVIEW_QUALITY = 85;

$source = lb_image_source((string)($_GET['p'] ?? ''), (string)($_GET['s'] ?? ''), (string)($_GET['f'] ?? ''));
if ($source === null) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'Not found';
    exit;
}
$path   = $source['path'];
$rotate = $source['rotate'];        // degrees clockwise
$full   = ($_GET['size'] ?? '') === 'full';

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

if ($full && !$rotate) {
    lb_send_file($path);
}

$cacheDir = __DIR__ . '/../cache/lightbox';
$key = md5(strtolower($path) . '|' . filemtime($path) . '|' . filesize($path) . '|'
         . ($full ? 'full' : LB_PREVIEW_MAX) . '|r' . $rotate);
$cached = $cacheDir . DIRECTORY_SEPARATOR . $key . '.jpg';

if (is_file($cached)) {
    lb_send_file($cached);
}

$info = @getimagesize($path);
$small = $info && max($info[0], $info[1]) <= LB_PREVIEW_MAX;
if (!function_exists('imagecreatefromjpeg') || !$info || (!$rotate && $small)) {
    lb_send_file($path);          // no GD, unreadable header, or already small and upright
}

@ini_set('memory_limit', '2048M');
@set_time_limit(120);

$dst = @imagecreatefromjpeg($path);
if (!$dst) {
    lb_send_file($path);
}
if (!$full && !$small) {
    [$w, $h] = [imagesx($dst), imagesy($dst)];
    $scale = LB_PREVIEW_MAX / max($w, $h);
    $scaled = imagescale($dst, max(1, (int)round($w * $scale)), max(1, (int)round($h * $scale)), IMG_BICUBIC);
    imagedestroy($dst);
    if (!$scaled) lb_send_file($path);
    $dst = $scaled;
}
if ($rotate) {
    // GD's angle is counterclockwise, ours clockwise.
    $turned = imagerotate($dst, -$rotate, 0);
    imagedestroy($dst);
    if (!$turned) lb_send_file($path);
    $dst = $turned;
}
$quality = $full ? 92 : LB_PREVIEW_QUALITY;

if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
$tmp = $cached . '.' . getmypid() . '.tmp';
if (@imagejpeg($dst, $tmp, $quality) && @rename($tmp, $cached)) {
    imagedestroy($dst);
    lb_send_file($cached);
}
@unlink($tmp);

// Couldn't write the cache — send the preview straight from memory
header('Content-Type: image/jpeg');
header('Cache-Control: private, max-age=3600');
imagejpeg($dst, null, $quality);
imagedestroy($dst);
