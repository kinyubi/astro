<?php
// ============================================================
// api_gallery_rank.php  —  Hand-ranked order of public gallery cards
//
// The public gallery shows one card per Project (a DSO can have a normal
// and a mosaic Project), so the rank lives on Projects.GalleryRank.
// Lower rank = earlier. Projects with no rank come after all ranked ones,
// in the gallery's old alphabetical order. Added by
// dsodb/migrations/migrate_add_project_gallery_rank.py.
//
// GET   -> { cards: [ { ProjectID, DSOKey, CommonName, DisplayName,
//                       IsMosaic, GalleryRank, ImageCount, BaseName } ],
//            attached:   [...],   // orphans just attached to their Project (local only)
//            unassigned: n,       // visible images still with no Project (can't be ranked)
//            unassignedImages: [ {DSOKey, BaseName}, ... ] }
//          Only Projects with at least one unhidden gallery image -- i.e.
//          exactly the cards the public gallery shows -- in current order.
//          BaseName = the image the public card shows first.
//
// POST  { "order": [ProjectID, ...] }
//       Sets GalleryRank = 1..n in that order. Projects not in the list
//       are left as they are.
// ============================================================

require_once __DIR__ . '/auth_api.php';
require_once __DIR__ . '/db_logger.php';
require_once __DIR__ . '/gallery_helpers.php';

header('Content-Type: application/json');

try {
    $db = get_db();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $body  = json_decode(file_get_contents('php://input'), true) ?: [];
        $order = $body['order'] ?? null;
        if (!is_array($order) || !$order) {
            http_response_code(400);
            echo json_encode(['error' => 'order (array of ProjectIDs) required']);
            exit;
        }
        $ids = array_map('intval', $order);
        if (in_array(0, $ids, true) || count($ids) !== count(array_unique($ids))) {
            http_response_code(400);
            echo json_encode(['error' => 'order must be distinct, non-zero ProjectIDs']);
            exit;
        }

        $db->beginTransaction();
        $upd = $db->prepare('UPDATE Projects SET GalleryRank = ? WHERE ProjectID = ?');
        $updated = 0;
        foreach ($ids as $i => $pid) {
            $upd->execute([$i + 1, $pid]);
            $updated += $upd->rowCount();
        }
        $db->commit();

        echo json_encode(['success' => true, 'updated' => $updated]);
        exit;
    }

    // ── GET ──────────────────────────────────────────────────────────────────
    // First make the database follow myWorks: attach any image with no
    // Project to the Project whose folder holds it (local mode only).
    $repair = gallery_attach_orphans($db, null, true);

    $stmt = $db->query("
        SELECT
            p.ProjectID       AS \"ProjectID\",
            p.DSOKey          AS \"DSOKey\",
            p.GalleryRank     AS \"GalleryRank\",
            o.CommonName      AS \"CommonName\",
            gi.GalleryImageID AS \"GalleryImageID\",
            gi.BaseName       AS \"BaseName\",
            gi.IsFeature      AS \"IsFeature\",
            gi.SortOrder      AS \"SortOrder\",
            gi.IsMosaic       AS \"IsMosaic\"
        FROM GalleryImages gi
        JOIN Projects p ON gi.ProjectID = p.ProjectID
        JOIN Objects  o ON gi.DSOKey    = o.DSOKey
        WHERE COALESCE(gi.IsHidden, 0) = 0
    ");

    $cards = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pid = (int)$r['ProjectID'];
        if (!isset($cards[$pid])) {
            $cards[$pid] = [
                'ProjectID'   => $pid,
                'DSOKey'      => $r['DSOKey'],
                'CommonName'  => $r['CommonName'],
                'DisplayName' => ($r['CommonName'] ?? $r['DSOKey']) . ' (' . $r['DSOKey'] . ')',
                'IsMosaic'    => 0,   // set below: the shown image's session
                'GalleryRank' => $r['GalleryRank'] !== null ? (int)$r['GalleryRank'] : null,
                'ImageCount'  => 0,
                '_images'     => [],
            ];
        }
        $cards[$pid]['ImageCount']++;
        $cards[$pid]['_images'][] = [
            'id'   => (int)$r['GalleryImageID'],
            'bn'   => $r['BaseName'],
            'feat' => (int)$r['IsFeature'],
            'sort' => (int)($r['SortOrder'] ?? 0),
            'mos'  => (int)($r['IsMosaic'] ?? 0),
        ];
    }

    // The image the public card shows first: featured, then SortOrder, then ID
    // (same rule as public/index.php).
    foreach ($cards as &$c) {
        usort($c['_images'], fn($a, $b) =>
            $b['feat'] - $a['feat'] ?: ($a['sort'] - $b['sort'] ?: $a['id'] - $b['id'])
        );
        $c['BaseName'] = $c['_images'][0]['bn'];
        $c['IsMosaic'] = $c['_images'][0]['mos'];   // badge: the shown image is a mosaic
        unset($c['_images']);
    }
    unset($c);

    // Current public order: ranked first (by rank), then unranked by
    // display name, then ProjectID.
    usort($cards, function ($a, $b) {
        $ra = $a['GalleryRank']; $rb = $b['GalleryRank'];
        if ($ra !== null || $rb !== null) {
            if ($ra === null) return 1;
            if ($rb === null) return -1;
            if ($ra !== $rb) return $ra - $rb;
        }
        $cmp = strcmp($a['DisplayName'], $b['DisplayName']);
        return $cmp !== 0 ? $cmp : ($a['ProjectID'] - $b['ProjectID']);
    });

    $unassigned_rows = $db->query(
        'SELECT DSOKey AS "DSOKey", BaseName AS "BaseName" FROM GalleryImages
         WHERE ProjectID IS NULL AND COALESCE(IsHidden, 0) = 0 ORDER BY DSOKey, BaseName'
    )->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'cards'            => $cards,
        'attached'         => $repair['attached'],
        'unassigned'       => count($unassigned_rows),
        'unassignedImages' => $unassigned_rows,
    ]);

} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) $db->rollBack();
    http_response_code(500);
    $msg = $e->getMessage();
    if (stripos($msg, 'galleryrank') !== false) {
        $msg = 'Projects.GalleryRank does not exist yet -- run dsodb/migrations/migrate_add_project_gallery_rank.py --apply first. (' . $msg . ')';
    }
    echo json_encode(['error' => $msg]);
}
