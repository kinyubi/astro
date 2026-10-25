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
// ============================================================

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
