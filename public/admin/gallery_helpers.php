<?php
// ============================================================
// gallery_helpers.php  —  Shared GalleryImages ordering rules
//
// One rule, applied everywhere a DSO's gallery images can change
// (api_gallery_image.php, api_save.php, api_sync_folder.php):
//
//   * Exactly one visible (IsHidden = 0) image is featured, if the DSO
//     has any visible images. Hidden images are never featured.
//   * The featured image has SortOrder 0; the other visible images are
//     numbered 1..n in their existing order (SortOrder, then
//     GalleryImageID); hidden images follow after them.
//
// Requires the GalleryImages.IsHidden column
// (dsodb/migrations/migrate_add_gallery_hidden.py).
//
// Thumbs (public/images/thumbs) are kept in step by
// gallery_thumbs_sync() in thumb_helpers.php.
// ============================================================

require_once __DIR__ . '/thumb_helpers.php';

/**
 * Re-apply the featured/order rule for one DSO and return its rows.
 *
 * @param int|null $feature_id  Make this image the featured one (if it
 *                              exists, belongs to the DSO and is visible).
 *                              Otherwise the current visible featured
 *                              image is kept, or the first visible image
 *                              is promoted if there isn't one.
 * @return array  [ ['GalleryImageID'=>, 'BaseName'=>, 'SortOrder'=>,
 *                   'IsFeature'=>, 'IsHidden'=>], ... ] in display order
 */
function gallery_normalize(PDO $db, string $dso_key, ?int $feature_id = null): array {
    $stmt = $db->prepare('
        SELECT GalleryImageID AS "GalleryImageID",
               BaseName       AS "BaseName",
               COALESCE(SortOrder, 0) AS "SortOrder",
               COALESCE(IsFeature, 0) AS "IsFeature",
               COALESCE(IsHidden, 0)  AS "IsHidden"
        FROM GalleryImages
        WHERE DSOKey = ?
        ORDER BY SortOrder, GalleryImageID
    ');
    $stmt->execute([$dso_key]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $visible = array_values(array_filter($rows, fn($r) => !(int)$r['IsHidden']));
    $hidden  = array_values(array_filter($rows, fn($r) =>  (int)$r['IsHidden']));

    $featured = null;
    if ($feature_id !== null) {
        foreach ($visible as $r) {
            if ((int)$r['GalleryImageID'] === $feature_id) { $featured = $r; break; }
        }
    }
    if ($featured === null) {
        foreach ($visible as $r) {
            if ((int)$r['IsFeature']) { $featured = $r; break; }
        }
    }
    if ($featured === null && $visible) $featured = $visible[0];

    $ordered = [];
    if ($featured) $ordered[] = $featured;
    foreach ($visible as $r) {
        if ($featured && (int)$r['GalleryImageID'] === (int)$featured['GalleryImageID']) continue;
        $ordered[] = $r;
    }
    foreach ($hidden as $r) $ordered[] = $r;

    $upd = $db->prepare('UPDATE GalleryImages SET SortOrder = ?, IsFeature = ? WHERE GalleryImageID = ?');
    $out = [];
    foreach ($ordered as $i => $r) {
        $is_feat = ($featured && (int)$r['GalleryImageID'] === (int)$featured['GalleryImageID']) ? 1 : 0;
        if ((int)$r['SortOrder'] !== $i || (int)$r['IsFeature'] !== $is_feat) {
            $upd->execute([$i, $is_feat, (int)$r['GalleryImageID']]);
        }
        $out[] = [
            'GalleryImageID' => (int)$r['GalleryImageID'],
            'BaseName'       => $r['BaseName'],
            'SortOrder'      => $i,
            'IsFeature'      => $is_feat,
            'IsHidden'       => (int)$r['IsHidden'],
        ];
    }
    return $out;
}

/**
 * Keep GalleryImages.ProjectID in step with myWorks on disk: attach every
 * image with no Project (ProjectID NULL -- e.g. left behind when an old
 * Project row was deleted) to the DSO's Project whose folder holds it.
 *
 *   1. the Project whose folder contains the image's SessionDir
 *      (WORKS_ROOT/<ProjectFolder>/<SessionDir> exists), else
 *   2. the DSO's only Project, if its folder exists and it has just one.
 *
 * Images that match neither are left alone and reported as unresolved.
 * Local mode only (needs WORKS_ROOT); elsewhere it does nothing, since a
 * server that can't see the disk mustn't guess.
 *
 * @param string|null $dso_key  One DSO, or null for all of them.
 * @param bool        $apply    false = report only.
 * @return array ['attached' => [...], 'unresolved' => [...]], each item
 *               ['GalleryImageID','DSOKey','BaseName','SessionDir'] plus
 *               'ProjectID','ProjectFolder','Match' for attached ones.
 */
function gallery_attach_orphans(PDO $db, ?string $dso_key = null, bool $apply = true): array {
    $result = ['attached' => [], 'unresolved' => []];
    if (!defined('WORKS_ROOT') || !is_dir(WORKS_ROOT)) return $result;

    $sql = 'SELECT GalleryImageID AS "GalleryImageID", DSOKey AS "DSOKey",
                   BaseName AS "BaseName", SessionDir AS "SessionDir"
            FROM GalleryImages WHERE ProjectID IS NULL';
    $params = [];
    if ($dso_key !== null) { $sql .= ' AND DSOKey = ?'; $params[] = $dso_key; }
    $stmt = $db->prepare($sql . ' ORDER BY DSOKey, GalleryImageID');
    $stmt->execute($params);
    $orphans = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$orphans) return $result;

    $proj_stmt = $db->prepare('SELECT ProjectID AS "ProjectID", ProjectFolder AS "ProjectFolder" FROM Projects WHERE DSOKey = ? ORDER BY ProjectID');
    $upd       = $db->prepare('UPDATE GalleryImages SET ProjectID = ? WHERE GalleryImageID = ? AND ProjectID IS NULL');
    $live_by_dso = [];

    foreach ($orphans as $img) {
        $key = $img['DSOKey'];
        if (!isset($live_by_dso[$key])) {
            $proj_stmt->execute([$key]);
            $live_by_dso[$key] = array_values(array_filter(
                $proj_stmt->fetchAll(PDO::FETCH_ASSOC),
                fn($p) => $p['ProjectFolder'] !== null && $p['ProjectFolder'] !== ''
                       && is_dir(WORKS_ROOT . DIRECTORY_SEPARATOR . $p['ProjectFolder'])
            ));
        }
        $live = $live_by_dso[$key];

        $to = null; $match = null;
        $session = trim((string)($img['SessionDir'] ?? ''));
        if ($session !== '') {
            foreach ($live as $p) {
                if (is_dir(WORKS_ROOT . DIRECTORY_SEPARATOR . $p['ProjectFolder'] . DIRECTORY_SEPARATOR . $session)) {
                    $to = $p; $match = 'session folder'; break;
                }
            }
        }
        if ($to === null && count($live) === 1) { $to = $live[0]; $match = 'only Project'; }

        $item = [
            'GalleryImageID' => (int)$img['GalleryImageID'],
            'DSOKey'         => $key,
            'BaseName'       => $img['BaseName'],
            'SessionDir'     => $img['SessionDir'],
        ];
        if ($to === null) { $result['unresolved'][] = $item; continue; }

        if ($apply) $upd->execute([(int)$to['ProjectID'], (int)$img['GalleryImageID']]);
        $item['ProjectID']     = (int)$to['ProjectID'];
        $item['ProjectFolder'] = $to['ProjectFolder'];
        $item['Match']         = $match;
        $result['attached'][]  = $item;
    }
    return $result;
}
