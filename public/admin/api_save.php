<?php
// ============================================================
// api_save.php  —  Save object fields to the SQLite database
//
// Accepts POST with JSON body containing DSOKey + any Object fields.
// Also handles:
//   - CatalogIDs   (array of {CatalogID, IsPrimary})
//   - GalleryImages (array of gallery image rows; omitted IDs are deleted)
//   - DSOLinks      (array of link rows; omitted IDs are deleted)
// ============================================================

require_once __DIR__ . '/auth_api.php';

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

try {
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA foreign_keys = ON');

    // ── Objects table ─────────────────────────────────────────────────────────

    $allowed_cols = [
        'CommonName', 'ObjectTypeID', 'ConstellationID',
        'RAHours', 'DecDegrees', 'Magnitude',
        'ObjectSize', 'SqArcMins', 'DistanceLY', 'SocialBlurb', 'WantBetter',
        'ProjectFolder', 'IsMosaic', 'MostRecentObservation', 'Notes',
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

    if (!empty($body['CatalogIDs']) && is_array($body['CatalogIDs'])) {
        $stmt = $db->prepare("
            INSERT OR IGNORE INTO CatalogIDs (CatalogID, DSOKey, IsPrimary)
            VALUES (:cid, :dkey, :primary)
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
    // Strategy: full replace for this DSOKey.
    // Rows with a GalleryImageID are UPSERTed; rows without get INSERTed.
    // Any GalleryImageID currently in the DB but absent from the payload is deleted.

    if (array_key_exists('GalleryImages', $body) && is_array($body['GalleryImages'])) {
        $incoming = $body['GalleryImages'];

        // Collect IDs present in the payload
        $incoming_ids = array_filter(array_column($incoming, 'GalleryImageID'));

        // Delete rows for this DSO that are no longer in the payload
        if ($incoming_ids) {
            $ph = implode(',', array_fill(0, count($incoming_ids), '?'));
            $del_params = array_merge([$dso_key], array_values($incoming_ids));
            $db->prepare("
                DELETE FROM GalleryImages
                WHERE DSOKey = ? AND GalleryImageID NOT IN ($ph)
            ")->execute($del_params);
        } else {
            // No IDs at all — delete everything for this DSO and re-insert
            $db->prepare("DELETE FROM GalleryImages WHERE DSOKey = ?")->execute([$dso_key]);
        }

        $upsert = $db->prepare("
            INSERT INTO GalleryImages
                (GalleryImageID, DSOKey, BaseName, Caption, PaletteID,
                 DateCaptured, Copyright, IsOwn, Attribution, SortOrder, IsFeature)
            VALUES
                (:id, :dso, :base, :caption, :palette,
                 :date, :copyright, :isown, :attribution, :sort, :feature)
            ON CONFLICT(GalleryImageID) DO UPDATE SET
                BaseName     = excluded.BaseName,
                Caption      = excluded.Caption,
                PaletteID    = excluded.PaletteID,
                DateCaptured = excluded.DateCaptured,
                Copyright    = excluded.Copyright,
                IsOwn        = excluded.IsOwn,
                Attribution  = excluded.Attribution,
                SortOrder    = excluded.SortOrder,
                IsFeature    = excluded.IsFeature
        ");

        $insert_new = $db->prepare("
            INSERT INTO GalleryImages
                (DSOKey, BaseName, Caption, PaletteID,
                 DateCaptured, Copyright, IsOwn, Attribution, SortOrder, IsFeature)
            VALUES
                (:dso, :base, :caption, :palette,
                 :date, :copyright, :isown, :attribution, :sort, :feature)
        ");

        foreach ($incoming as $img) {
            $base = trim($img['BaseName'] ?? '');
            if (!$base) continue;

            $params = [
                ':dso'         => $dso_key,
                ':base'        => $base,
                ':caption'     => $img['Caption']      ?? null,
                ':palette'     => $img['PaletteID']    ?? 0,
                ':date'        => $img['DateCaptured'] ?? null,
                ':copyright'   => $img['Copyright']    ?? null,
                ':isown'       => isset($img['IsOwn'])  ? (int)$img['IsOwn']    : 1,
                ':attribution' => $img['Attribution']  ?? null,
                ':sort'        => isset($img['SortOrder']) ? (int)$img['SortOrder'] : 0,
                ':feature'     => isset($img['IsFeature']) ? (int)$img['IsFeature'] : 0,
            ];

            if (!empty($img['GalleryImageID'])) {
                $params[':id'] = (int)$img['GalleryImageID'];
                $upsert->execute($params);
            } else {
                $insert_new->execute($params);
            }
        }
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
            }
        }
    }

    echo json_encode(['success' => true, 'DSOKey' => $dso_key]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
