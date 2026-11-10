<?php
// ============================================================
// thumb_helpers.php  —  public/images/thumbs upkeep for the admin
//
// The public gallery shows a thumb for every visible GalleryImages row
// (grid card = the featured image's thumb, modal filmstrip = each
// image's thumb), named images/thumbs/<BaseName>_thumb.jpg and made from
// images/fav/<BaseName>_fav.jpg -- the same rule titling.py uses: fit
// inside 300x375 keeping the fav's own ratio, never enlarged, JPEG
// quality 70, progressive.
//
// gallery_thumbs_sync() runs after api_gallery_image.php, api_save.php
// and api_sync_folder.php change a DSO's gallery:
//   * makes the thumb for each visible image of the DSO when it's
//     missing or older than its fav (so featuring, unhiding or adding an
//     image always leaves it with a thumb)
//   * deletes this DSO's thumbs (files named <dsokey>_...) whose BaseName
//     isn't a visible GalleryImages row of any DSO -- hidden images,
//     old/renamed BaseNames, stray copies. Unhiding recreates the thumb.
// Best effort: thumb trouble is reported, never fails the request.
// ============================================================

const THUMB_MAX_W   = 300;
const THUMB_MAX_H   = 375;
const THUMB_QUALITY = 70;

function thumb_images_dir(): string {
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'images';
}

/** Write $thumb_path from $fav_path (GD). Returns true on success. */
function thumb_make(string $fav_path, string $thumb_path): bool {
    if (!function_exists('imagecreatefromjpeg')) return false;
    $src = @imagecreatefromjpeg($fav_path);
    if (!$src) return false;
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(THUMB_MAX_W / $w, THUMB_MAX_H / $h, 1.0);
    $tw = max(1, (int)round($w * $scale));
    $th = max(1, (int)round($h * $scale));
    $dst = imagecreatetruecolor($tw, $th);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
    imageinterlace($dst, true); // progressive JPEG
    $tmp = $thumb_path . '.tmp';
    $ok = imagejpeg($dst, $tmp, THUMB_QUALITY);
    if ($ok) $ok = @rename($tmp, $thumb_path);
    if (!$ok && is_file($tmp)) @unlink($tmp);
    return $ok;
}

/**
 * Bring one DSO's thumbs in line with its visible gallery images.
 * @return array ['created' => [file...], 'removed' => [file...],
 *                'failed' => [file...]] (+ 'error' => msg on a DB error)
 */
function gallery_thumbs_sync(PDO $db, string $dso_key): array {
    $result = ['created' => [], 'removed' => [], 'failed' => []];
    $fav_dir   = thumb_images_dir() . DIRECTORY_SEPARATOR . 'fav';
    $thumb_dir = thumb_images_dir() . DIRECTORY_SEPARATOR . 'thumbs';
    if (!is_dir($fav_dir) || !is_dir($thumb_dir) || $dso_key === '') return $result;

    try {
        $visible = []; // lower(BaseName) => true, visible rows of every DSO
        $mine    = []; // this DSO's visible BaseNames
        $stmt = $db->query('SELECT DSOKey AS "DSOKey", BaseName AS "BaseName"
                            FROM GalleryImages WHERE COALESCE(IsHidden, 0) = 0');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $bn = trim((string)$r['BaseName']);
            if ($bn === '') continue;
            $visible[strtolower($bn)] = true;
            if (strcasecmp((string)$r['DSOKey'], $dso_key) === 0) $mine[] = $bn;
        }

        foreach ($mine as $bn) {
            $fav   = $fav_dir   . DIRECTORY_SEPARATOR . $bn . '_fav.jpg';
            $thumb = $thumb_dir . DIRECTORY_SEPARATOR . $bn . '_thumb.jpg';
            if (!is_file($fav)) continue;
            if (is_file($thumb) && filemtime($thumb) >= filemtime($fav)) continue;
            if (thumb_make($fav, $thumb)) $result['created'][] = basename($thumb);
            else                          $result['failed'][]  = basename($thumb);
        }

        // '<dsokey>_' prefix: M1's sweep never reaches m101_... files
        $prefix = strtolower($dso_key) . '_';
        foreach (scandir($thumb_dir) as $file) {
            $path = $thumb_dir . DIRECTORY_SEPARATOR . $file;
            if (!is_file($path)) continue;
            if (strncasecmp($file, $prefix, strlen($prefix)) !== 0) continue;
            if (preg_match('/^(.+)_thumb\.jpg$/i', $file, $m) && isset($visible[strtolower($m[1])])) continue;
            if (@unlink($path)) $result['removed'][] = $file;
            else                $result['failed'][]  = $file;
        }
    } catch (Throwable $e) {
        $result['error'] = $e->getMessage();
    }
    return $result;
}
