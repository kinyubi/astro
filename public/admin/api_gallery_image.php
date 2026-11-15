<?php
// ============================================================
// api_gallery_image.php  —  Immediate per-image gallery actions
//
// POST { "GalleryImageID": 12, "action": "hide" | "unhide" | "feature" }
//
//   hide     IsHidden = 1. The row (caption, palette, session dir...) is
//            kept, so Sync Folder won't re-add the image as new. If it was
//            the featured image, the first visible image becomes featured.
//   unhide   IsHidden = 0, placed at the end of the visible images.
//   feature  Makes this (visible) image the featured one: SortOrder 0,
//            the others renumbered 1..n in their existing order.
//   order    POST { "action": "order", "order": [GalleryImageID, ...] }
//            (GalleryImageID may be left out). The visible images of one
//            gallery card (one Project of a DSO) in their new order --
//            the slideshow's filmstrip drag, local only. They are put back
//            into the places they held among the DSO's visible images, so
//            another Project's images don't move. If the DSO's featured
//            image is one of them, the first one becomes featured.
//
// Every action re-applies gallery_normalize() (gallery_helpers.php) and
// returns the DSO's resulting rows, so the admin UI can update in place.
//
// Response: { success, DSOKey, images: [...], promoted: BaseName|null }
//   promoted = the image that newly became featured as a side effect
//   (e.g. hiding the featured image), or null.
// ============================================================

require_once __DIR__ . '/auth_api.php';
require_once __DIR__ . '/db_logger.php';
require_once __DIR__ . '/gallery_helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';
$order  = null;
if ($action === 'order') {
    $order = is_array($body['order'] ?? null) ? array_map('intval', $body['order']) : [];
    if (!$order || in_array(0, $order, true) || count($order) !== count(array_unique($order))) {
        http_response_code(400);
        echo json_encode(['error' => 'order must be a list of distinct GalleryImageIDs']);
        exit;
    }
    $body['GalleryImageID'] = $order[0];
}
$id = (int)($body['GalleryImageID'] ?? 0);

if (!$id || !in_array($action, ['hide', 'unhide', 'feature', 'order'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'GalleryImageID and action (hide|unhide|feature|order) required']);
    exit;
}

try {
    $db = get_db();

    $stmt = $db->prepare('SELECT DSOKey AS "DSOKey", COALESCE(IsHidden, 0) AS "IsHidden" FROM GalleryImages WHERE GalleryImageID = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => "Gallery image $id not found"]);
        exit;
    }
    $dso_key = $row['DSOKey'];

    $db->beginTransaction();

    // Which image is featured before this action (to report a promotion)
    $stmt = $db->prepare('SELECT GalleryImageID FROM GalleryImages WHERE DSOKey = ? AND COALESCE(IsHidden,0) = 0 AND IsFeature = 1');
    $stmt->execute([$dso_key]);
    $before_feature = $stmt->fetchColumn();

    $feature_id = null;
    if ($action === 'hide') {
        $db->prepare('UPDATE GalleryImages SET IsHidden = 1, IsFeature = 0 WHERE GalleryImageID = ?')->execute([$id]);
    } elseif ($action === 'unhide') {
        $stmt = $db->prepare('SELECT COALESCE(MAX(SortOrder), 0) FROM GalleryImages WHERE DSOKey = ?');
        $stmt->execute([$dso_key]);
        $end = (int)$stmt->fetchColumn() + 1;
        $db->prepare('UPDATE GalleryImages SET IsHidden = 0, IsFeature = 0, SortOrder = ? WHERE GalleryImageID = ?')
           ->execute([$end, $id]);
    } elseif ($action === 'order') {
        // The DSO's visible images in their current order.
        $stmt = $db->prepare('SELECT GalleryImageID FROM GalleryImages
                              WHERE DSOKey = ? AND COALESCE(IsHidden,0) = 0
                              ORDER BY COALESCE(IsFeature,0) DESC, SortOrder, GalleryImageID');
        $stmt->execute([$dso_key]);
        $visible = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $missing = array_diff($order, $visible);
        if ($missing) {
            $db->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'These images are hidden or belong to another object: ' . implode(', ', $missing)]);
            exit;
        }
        // Same places, new order.
        $next = 0;
        foreach ($visible as $slot => $vid) {
            if (in_array($vid, $order, true)) $visible[$slot] = $order[$next++];
        }
        $upd = $db->prepare('UPDATE GalleryImages SET SortOrder = ? WHERE GalleryImageID = ?');
        foreach ($visible as $i => $vid) $upd->execute([$i, $vid]);
        if ($before_feature !== false && in_array((int)$before_feature, $order, true)) {
            $feature_id = $order[0];
        }
    } else { // feature
        if ((int)$row['IsHidden']) {
            $db->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'A hidden image can\'t be featured -- unhide it first.']);
            exit;
        }
        $feature_id = $id;
    }

    $images = gallery_normalize($db, $dso_key, $feature_id);
    $db->commit();

    // Thumb for every visible image (e.g. a just-unhidden one); a hidden
    // image's thumb is removed (thumb_helpers.php).
    $thumbs = gallery_thumbs_sync($db, $dso_key);

    $promoted = null;
    foreach ($images as $img) {
        if ($img['IsFeature'] && $img['GalleryImageID'] !== (int)$before_feature && $img['GalleryImageID'] !== $feature_id) {
            $promoted = $img['BaseName'];
        }
    }

    echo json_encode([
        'success'  => true,
        'DSOKey'   => $dso_key,
        'images'   => $images,
        'promoted' => $promoted,
        'thumbs'   => $thumbs,
    ]);

} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) $db->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
