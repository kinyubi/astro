<?php
// ============================================================
// api_save.php  —  Save object fields to the database
//
// Accepts POST with JSON body containing DSOKey + any Object fields.
// Also handles:
//   - CatalogIDs    (array of {CatalogID, IsPrimary}). With
//                     "CatalogIDsComplete": true the array is the DSO's full
//                     list: IDs no longer in it are deleted and IsPrimary is
//                     updated. Without it, IDs are only ever added.
//   - GalleryImages (array of gallery image rows). Existing rows (with a
//                     GalleryImageID) are updated, new ones inserted. Rows
//                     are NEVER deleted here -- images are hidden/unhidden
//                     via api_gallery_image.php, and rows whose fav file is
//                     gone are removed by Sync Folder. IsHidden is never
//                     changed here. Featured/SortOrder are re-normalised
//                     afterwards (gallery_helpers.php).
//   - DSOLinks      (array of link rows; omitted IDs are deleted)
//
// New GalleryImages / DSOLinks rows may carry a client-side "_tmp" key;
// the response maps it to the new database ID so the admin page (which
// autosaves) never inserts the same new row twice:
//   { success, DSOKey, newGalleryImageIds: {tmp: id}, newLinkIds: {tmp: id},
//     gallery: [ {GalleryImageID, SortOrder, IsFeature, IsHidden}, ... ] }
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

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}

$dso_key = trim($body['DSOKey'] ?? '');
if (!$dso_key) {
    http_response_code(400);
    echo json_encode(['error' => 'DSOKey is required']);
    exit;
}

$new_gallery_ids = [];
$new_link_ids    = [];
$gallery_state   = null;

try {
    $db = get_db();
    $db->beginTransaction();

    // ── Objects table ─────────────────────────────────────────────────────────

    $allowed_cols = [
        'CommonName', 'ObjectTypeID', 'ConstellationID',
        'RAHours', 'DecDegrees', 'Magnitude',
        'ObjectSize', 'SqArcMins', 'DistanceLY', 'SocialBlurb', 'WantBetter',
        'Notes',
    ];

    $updates = [];
    $params  = [];
    foreach ($allowed_cols as $col) {
        if (array_key_exists($col, $body)) {
            $updates[] = "$col = :$col";
            $params[":$col"] = $body[$col] === '' ? null : $body[$col];
        }
    }
    $params[':DSOKey'] = $dso_key;

    if ($updates) {
        $updates[] = "LastUpdated = CURRENT_TIMESTAMP";
        $sql = "UPDATE Objects SET " . implode(', ', $updates) . " WHERE DSOKey = :DSOKey";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            $not_null_defaults = ['WantBetter' => 0];
            $cols   = array_merge(['DSOKey'], $allowed_cols);
            $values = array_merge([':DSOKey'], array_map(fn($c) => ":$c", $allowed_cols));
            $insert_params = [':DSOKey' => $dso_key];
            foreach ($allowed_cols as $col) {
                $val = isset($body[$col]) && $body[$col] !== '' ? $body[$col] : null;
                if ($val === null && isset($not_null_defaults[$col])) {
                    $val = $not_null_defaults[$col];
                }
                $insert_params[":$col"] = $val;
            }
            $sql = "INSERT INTO Objects (" . implode(',', $cols) . ") VALUES (" . implode(',', $values) . ")";
            $db->prepare($sql)->execute($insert_params);
        }
    }

    // ── CatalogIDs ────────────────────────────────────────────────────────────

    if (!empty($body['CatalogIDsComplete']) && isset($body['CatalogIDs']) && is_array($body['CatalogIDs'])) {
        $keep = [];
        foreach ($body['CatalogIDs'] as $entry) {
            $cid = strtoupper(trim($entry['CatalogID'] ?? ''));
            if ($cid) $keep[$cid] = (int)($entry['IsPrimary'] ?? 0);
        }
        if ($keep) {
            $ph = implode(',', array_fill(0, count($keep), '?'));
            $db->prepare("DELETE FROM CatalogIDs WHERE DSOKey = ? AND CatalogID NOT IN ($ph)")
               ->execute(array_merge([$dso_key], array_keys($keep)));
        } else {
            $db->prepare("DELETE FROM CatalogIDs WHERE DSOKey = ?")->execute([$dso_key]);
        }
        $upd = $db->prepare("UPDATE CatalogIDs SET IsPrimary = ? WHERE CatalogID = ? AND DSOKey = ?");
        foreach ($keep as $cid => $prim) $upd->execute([$prim, $cid, $dso_key]);
    }

    if (!empty($body['CatalogIDs']) && is_array($body['CatalogIDs'])) {
        // "ON CONFLICT DO NOTHING" (no target column) is valid on both
        // SQLite 3.24+ and Postgres and mirrors SQLite's old "INSERT OR
        // IGNORE" — suppresses the insert on any constraint violation.
        $stmt = $db->prepare("
            INSERT INTO CatalogIDs (CatalogID, DSOKey, IsPrimary)
            VALUES (:cid, :dkey, :primary)
            ON CONFLICT DO NOTHING
        ");
        foreach ($body['CatalogIDs'] as $entry) {
            $cid = strtoupper(trim($entry['CatalogID'] ?? ''));
            if (!$cid) continue;
            $stmt->execute([
                ':cid'     => $cid,
                ':dkey'    => $dso_key,
                ':primary' => $entry['IsPrimary'] ?? 0,
            ]);
        }
    }

    // ── GalleryImages ─────────────────────────────────────────────────────────
    // Update rows that have a GalleryImageID (scoped to this DSO, so a row
    // Sync Folder deleted meanwhile is never resurrected), insert the rest.
    // Never deletes and never touches IsHidden -- see header.

    if (array_key_exists('GalleryImages', $body) && is_array($body['GalleryImages'])) {
        $incoming = $body['GalleryImages'];

        $update = $db->prepare("
            UPDATE GalleryImages SET
                BaseName     = :base,
                Caption      = :caption,
                PaletteID    = :palette,
                DateCaptured = :date,
                Copyright    = :copyright,
                IsOwn        = :isown,
                Attribution  = :attribution,
                Equipment    = :equipment,
                ProjectID    = :projectid,
                SessionDir   = :sessiondir,
                SortOrder    = :sort,
                IsFeature    = :feature
            WHERE GalleryImageID = :id AND DSOKey = :dso
        ");

        $insert_new = $db->prepare("
            INSERT INTO GalleryImages
                (DSOKey, BaseName, Caption, PaletteID,
                 DateCaptured, Copyright, IsOwn, Attribution,
                 Equipment, ProjectID, SessionDir, SortOrder, IsFeature, IsHidden)
            VALUES
                (:dso, :base, :caption, :palette,
                 :date, :copyright, :isown, :attribution,
                 :equipment, :projectid, :sessiondir, :sort, :feature, 0)
        ");

        // Resolve ProjectID for GalleryImages rows: use an explicit ProjectID
        // from the payload if given, otherwise fall back to this DSO's only
        // Project when there's exactly one.
        $proj_stmt = $db->prepare("SELECT ProjectID FROM Projects WHERE DSOKey = ?");
        $proj_stmt->execute([$dso_key]);
        $dso_project_ids = $proj_stmt->fetchAll(PDO::FETCH_COLUMN);
        $default_project_id = count($dso_project_ids) === 1 ? $dso_project_ids[0] : null;

        // Existing ProjectIDs, so an update without an explicit ProjectID
        // keeps the link Sync Folder set rather than overwriting it.
        $cur_proj = [];
        $stmt = $db->prepare('SELECT GalleryImageID AS "id", ProjectID AS "pid" FROM GalleryImages WHERE DSOKey = ?');
        $stmt->execute([$dso_key]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $cur_proj[(int)$r['id']] = $r['pid'];

        foreach ($incoming as $img) {
            $base = trim($img['BaseName'] ?? '');
            if (!$base) continue;
            $id = !empty($img['GalleryImageID']) ? (int)$img['GalleryImageID'] : null;

            $project_id = !empty($img['ProjectID']) ? (int)$img['ProjectID']
                        : (($id && !empty($cur_proj[$id])) ? (int)$cur_proj[$id] : $default_project_id);

            $params = [
                ':dso'         => $dso_key,
                ':base'        => $base,
                ':caption'     => $img['Caption']      ?? null,
                ':palette'     => $img['PaletteID']    ?? 0,
                ':date'        => $img['DateCaptured'] ?? null,
                ':copyright'   => $img['Copyright']    ?? null,
                ':isown'       => isset($img['IsOwn'])      ? (int)$img['IsOwn']      : 1,
                ':attribution' => $img['Attribution']  ?? null,
                ':equipment'   => $img['Equipment']    ?? null,
                ':projectid'   => $project_id,
                ':sessiondir'  => $img['SessionDir']   ?? null,
                ':sort'        => isset($img['SortOrder'])  ? (int)$img['SortOrder']  : 1000,
                ':feature'     => isset($img['IsFeature'])  ? (int)$img['IsFeature']  : 0,
            ];

            if ($id) {
                $params[':id'] = $id;
                $update->execute($params);
            } else {
                $insert_new->execute($params);
                $new_id = db_last_insert_id($db, 'GalleryImages', 'GalleryImageID');
                if (isset($img['_tmp'])) $new_gallery_ids[(string)$img['_tmp']] = $new_id;
            }
        }

        $gallery_state = gallery_normalize($db, $dso_key);
    }

    // ── DSOLinks ──────────────────────────────────────────────────────────────
    // Same full-replace strategy as GalleryImages.

    if (array_key_exists('DSOLinks', $body) && is_array($body['DSOLinks'])) {
        $incoming = $body['DSOLinks'];

        $incoming_ids = array_filter(array_column($incoming, 'LinkID'));

        if ($incoming_ids) {
            $ph = implode(',', array_fill(0, count($incoming_ids), '?'));
            $del_params = array_merge([$dso_key], array_values($incoming_ids));
            $db->prepare("
                DELETE FROM DSOLinks
                WHERE DSOKey = ? AND LinkID NOT IN ($ph)
            ")->execute($del_params);
        } else {
            $db->prepare("DELETE FROM DSOLinks WHERE DSOKey = ?")->execute([$dso_key]);
        }

        $upsert = $db->prepare("
            INSERT INTO DSOLinks (LinkID, DSOKey, Label, URL, SortOrder)
            VALUES (:id, :dso, :label, :url, :sort)
            ON CONFLICT(LinkID) DO UPDATE SET
                Label     = excluded.Label,
                URL       = excluded.URL,
                SortOrder = excluded.SortOrder
        ");

        $insert_new = $db->prepare("
            INSERT INTO DSOLinks (DSOKey, Label, URL, SortOrder)
            VALUES (:dso, :label, :url, :sort)
        ");

        foreach ($incoming as $lnk) {
            $label = trim($lnk['Label'] ?? '');
            $url   = trim($lnk['URL']   ?? '');
            if (!$label || !$url) continue;

            $params = [
                ':dso'   => $dso_key,
                ':label' => $label,
                ':url'   => $url,
                ':sort'  => isset($lnk['SortOrder']) ? (int)$lnk['SortOrder'] : 0,
            ];

            if (!empty($lnk['LinkID'])) {
                $params[':id'] = (int)$lnk['LinkID'];
                $upsert->execute($params);
            } else {
                $insert_new->execute($params);
                $new_id = db_last_insert_id($db, 'DSOLinks', 'LinkID');
                if (isset($lnk['_tmp'])) $new_link_ids[(string)$lnk['_tmp']] = $new_id;
            }
        }
    }

    $db->commit();

    // Featured/BaseName/new rows may have changed: keep thumbs in step
    // (thumb_helpers.php). Only when this save touched GalleryImages.
    if ($gallery_state !== null) gallery_thumbs_sync($db, $dso_key);

    echo json_encode([
        'success'            => true,
        'DSOKey'             => $dso_key,
        'newGalleryImageIds' => (object)$new_gallery_ids,
        'newLinkIds'         => (object)$new_link_ids,
        'gallery'            => $gallery_state,
    ]);

} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) $db->rollBack();
    // Unique index ux_galleryimages_dso_basename (DSOKey, lower(BaseName)):
    // say which name clashes instead of a raw SQLSTATE message.
    $msg = $e->getMessage();
    $is_dup_name = $e instanceof PDOException && (
        stripos($msg, 'ux_galleryimages_dso_basename') !== false ||
        (str_contains($msg, 'UNIQUE constraint failed') && stripos($msg, 'galleryimages') !== false)
    );
    if ($is_dup_name) {
        http_response_code(409);
        echo json_encode(['error' => 'Another gallery image of this DSO already has that Base Name -- each image needs a unique Base Name.']);
        exit;
    }
    http_response_code(500);
    echo json_encode(['error' => $msg]);
}
