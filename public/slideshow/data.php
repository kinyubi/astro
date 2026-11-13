<?php
/**
 * Gallery data for the new slideshow / gallery app (public/slideshow/).
 *
 * Returns one card per Project (same grouping and order as the legacy
 * public/index.php): hand-ranked cards first (Projects.GalleryRank, set on
 * /gallery-order/), then unranked by display name, non-mosaic before mosaic.
 * Images within a card: featured first, then SortOrder, then GalleryImageID.
 * Hidden images (GalleryImages.IsHidden) are left out.
 *
 * Only the plain _full and _fav variants (plus thumbs) are used -- the
 * overlay annotation is drawn in the browser, so annotated/wall/4k files
 * are ignored. Image URLs are relative to /slideshow/ ("../images/...").
 */

require_once __DIR__ . '/../../shared/db.php';

function ss_load_cards(): array
{
    $imgRoot = __DIR__ . '/../images';
    $imgUrl  = '../images';

    try {
        $db = get_db();

        $sql = "
            SELECT
                {RANK} AS \"GalleryRank\",
                gi.GalleryImageID AS \"GalleryImageID\",
                gi.DSOKey         AS \"DSOKey\",
                gi.ProjectID      AS \"ProjectID\",
                gi.IsMosaic       AS \"IsMosaic\",
                gi.BaseName       AS \"BaseName\",
                gi.Caption        AS \"Caption\",
                gi.PaletteID      AS \"PaletteID\",
                pt.PaletteName    AS \"PaletteName\",
                gi.DateCaptured   AS \"DateCaptured\",
                gi.Copyright      AS \"Copyright\",
                gi.IsOwn          AS \"IsOwn\",
                gi.Attribution    AS \"Attribution\",
                gi.Equipment      AS \"Equipment\",
                gi.SortOrder      AS \"SortOrder\",
                gi.IsFeature      AS \"IsFeature\",
                o.CommonName      AS \"CommonName\",
                con.Name          AS \"ConstellationName\",
                o.DistanceLY      AS \"DistanceLY\",
                o.ObjectSize      AS \"ObjectSize\",
                o.SocialBlurb     AS \"SocialBlurb\",
                o.RAHours         AS \"RAHours\",
                o.DecDegrees      AS \"DecDegrees\",
                c.CatalogID       AS \"PrimaryCatalogID\"
            FROM GalleryImages gi
            JOIN Objects o ON gi.DSOKey = o.DSOKey
            LEFT JOIN Projects p           ON gi.ProjectID      = p.ProjectID
            LEFT JOIN PaletteTreatments pt ON gi.PaletteID      = pt.PaletteID
            LEFT JOIN CatalogIDs c         ON o.DSOKey          = c.DSOKey AND c.IsPrimary = 1
            LEFT JOIN Constellations con   ON o.ConstellationID = con.ConstellationID
            WHERE COALESCE(gi.IsHidden, 0) = 0
            ORDER BY o.CommonName, o.DSOKey, gi.ProjectID, gi.SortOrder, gi.GalleryImageID
        ";
        try {
            $rows = $db->query(str_replace('{RANK}', 'p.GalleryRank', $sql))->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // GalleryRank migration not run: alphabetical order instead.
            $rows = $db->query(str_replace('{RANK}', 'NULL', $sql))->fetchAll(PDO::FETCH_ASSOC);
        }

        $links = [];
        $lst = $db->query("
            SELECT DSOKey AS \"DSOKey\", Label AS \"Label\", URL AS \"URL\"
            FROM DSOLinks
            ORDER BY DSOKey, SortOrder, LinkID
        ");
        foreach ($lst->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $links[$l['DSOKey']][] = ['label' => $l['Label'], 'url' => $l['URL']];
        }

        $cards = [];
        foreach ($rows as $r) {
            $key = $r['ProjectID'] !== null ? ('p' . $r['ProjectID']) : ('dso_' . $r['DSOKey']);
            if (!isset($cards[$key])) {
                $name = $r['CommonName'] ?: $r['DSOKey'];
                $cards[$key] = [
                    'key'         => $key,
                    'dsoKey'      => $r['DSOKey'],
                    'name'        => $name,
                    'displayName' => $name . ' (' . $r['DSOKey'] . ')',
                    'isMosaic'    => 0,   // set below: the featured image's session
                    'rank'        => $r['GalleryRank'] !== null ? (int)$r['GalleryRank'] : null,
                    'info'        => [
                        'constellation' => $r['ConstellationName'],
                        'distanceLY'    => $r['DistanceLY'],
                        'objectSize'    => $r['ObjectSize'],
                        'blurb'         => $r['SocialBlurb'],
                        'ra'            => $r['RAHours'],
                        'dec'           => $r['DecDegrees'],
                        'catalogId'     => $r['PrimaryCatalogID'],
                    ],
                    'links'       => $links[$r['DSOKey']] ?? [],
                    'images'      => [],
                ];
            }
            $bn = $r['BaseName'];
            $cards[$key]['images'][] = [
                'id'          => (int)$r['GalleryImageID'],
                'base'        => $bn,
                'caption'     => $r['Caption'],
                'palette'     => ((int)$r['PaletteID'] > 0) ? $r['PaletteName'] : null,
                'date'        => $r['DateCaptured'],
                'equipment'   => $r['Equipment'],
                'isOwn'       => (int)($r['IsOwn'] ?? 1),
                'attribution' => $r['Attribution'],
                'copyright'   => $r['Copyright'],
                'isFeature'   => (int)$r['IsFeature'],
                'isMosaic'    => (int)($r['IsMosaic'] ?? 0),   // session folder has a mosaic token
                'sortOrder'   => (int)($r['SortOrder'] ?? 0),
                'full'        => "$imgUrl/full/{$bn}_full.jpg",
                'fav'         => "$imgUrl/fav/{$bn}_fav.jpg",
                'thumb'       => "$imgUrl/thumbs/{$bn}_thumb.jpg",
                'hasFull'     => is_file("$imgRoot/full/{$bn}_full.jpg"),
                'hasFav'      => is_file("$imgRoot/fav/{$bn}_fav.jpg"),
            ];
        }

        uasort($cards, function ($a, $b) {
            if ($a['rank'] !== null || $b['rank'] !== null) {
                if ($a['rank'] === null) return 1;
                if ($b['rank'] === null) return -1;
                if ($a['rank'] !== $b['rank']) return $a['rank'] - $b['rank'];
            }
            $cmp = strcmp($a['displayName'], $b['displayName']);
            return $cmp !== 0 ? $cmp : strcmp($a['key'], $b['key']);
        });
        foreach ($cards as &$card) {
            usort($card['images'], fn($a, $b) =>
                $b['isFeature'] - $a['isFeature']
                ?: ($a['sortOrder'] - $b['sortOrder'] ?: $a['id'] - $b['id'])
            );
            // Card badge: the featured image's session is a mosaic.
            $card['isMosaic'] = $card['images'][0]['isMosaic'] ?? 0;
        }
        unset($card);

        return array_values($cards);
    } catch (Throwable $e) {
        error_log('slideshow/data.php: ' . $e->getMessage());
        return [];
    }
}

/**
 * Equipment display names, keyed by lower-case code ('s30' => 'Seestar S30').
 *
 * The Seestar labels the gallery has always used come first, then anything
 * else in the Equipment table (EquipmentID => EquipmentName), and 8SE is
 * always the Celestron NexStar 8SE.
 */
function ss_equipment_names(): array
{
    $names = [
        's30'  => 'Seestar S30',
        's50'  => 'Seestar S50',
        's50p' => 'Seestar S50 Pro',
    ];
    try {
        $rows = get_db()->query('SELECT EquipmentID AS "id", EquipmentName AS "name" FROM Equipment')
                        ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $id = strtolower(trim((string)$r['id']));
            if ($id !== '' && !isset($names[$id]) && trim((string)$r['name']) !== '') {
                $names[$id] = trim((string)$r['name']);
            }
        }
    } catch (Throwable $e) {
        error_log('slideshow/data.php equipment: ' . $e->getMessage());
    }
    $names['8se'] = 'Celestron NexStar 8SE';
    return $names;
}

/**
 * Solar System images (public/images/solar), shaped like gallery cards so
 * the Browse Gallery viewer can show them: one card per object (Sun, Moon,
 * planets), one image per shot. There is no database row for these, so
 * everything comes from the file name, e.g.
 *
 *     waning_gibbous_moon_1206_20251206_8SE_fav.jpg
 *       title "Waning Gibbous Moon", date 2025-12-06, equipment 8SE
 *
 * Only the _fav (4:5) version is used; _annotated copies are ignored. An
 * equipment code is any _-separated token that is a known equipment code
 * ($equipment, from ss_equipment_names()). A "cw" token rotates clockwise,
 * as in the main gallery.
 */
function ss_load_solar(array $equipment): array
{
    $dir    = __DIR__ . '/../images/solar';
    $url    = '../images/solar';
    $order  = ['sun', 'moon', 'mercury', 'venus', 'mars', 'jupiter', 'saturn', 'uranus', 'neptune'];
    $titles = ['sun' => 'Sun', 'moon' => 'Moon', 'mercury' => 'Mercury', 'venus' => 'Venus',
               'mars' => 'Mars', 'jupiter' => 'Jupiter', 'saturn' => 'Saturn',
               'uranus' => 'Uranus', 'neptune' => 'Neptune'];
    if (!is_dir($dir)) return [];

    $cards = [];
    foreach (scandir($dir) as $f) {
        if (!preg_match('/^(.+)_fav\.(jpe?g|png|webp)$/i', $f, $m)) continue;
        $base  = $m[1];
        $parts = explode('_', $base);

        $object = null; $date = null; $equip = null; $words = [];
        foreach ($parts as $p) {
            $lp = strtolower($p);
            if ($object === null && isset($titles[$lp])) { $object = $lp; continue; }
            if ($date === null && preg_match('/^(\d{4})(\d{2})(\d{2})$/', $p, $d)) {
                $date = "$d[1]-$d[2]-$d[3]";
                continue;
            }
            if ($equip === null && isset($equipment[$lp])) { $equip = strtoupper($p); continue; }
            if ($object === null && !ctype_digit($p) && $lp !== 'cw') $words[] = ucfirst($lp);
        }
        if ($object === null) continue;

        $key = 'solar-' . $object;
        if (!isset($cards[$key])) {
            $cover = is_file("$dir/{$object}_thumb.jpg") ? "$url/{$object}_thumb.jpg" : null;
            $cards[$key] = [
                'key'         => $key,
                'solar'       => true,
                'dsoKey'      => '',
                'name'        => $titles[$object],
                'displayName' => $titles[$object],
                'isMosaic'    => 0,
                'rank'        => array_search($object, $order, true),
                'cover'       => $cover,
                'info'        => [],
                'links'       => [],
                'images'      => [],
            ];
        }
        $title = trim(implode(' ', $words) . ' ' . $titles[$object]);
        $cards[$key]['images'][] = [
            'id'          => count($cards[$key]['images']),
            'base'        => $base,
            'title'       => $title,
            'caption'     => null,
            'palette'     => null,
            'date'        => $date,
            'equipment'   => $equip,
            'isOwn'       => 1,
            'attribution' => null,
            'copyright'   => null,
            'isFeature'   => 0,
            'isMosaic'    => 0,
            'sortOrder'   => 0,
            'full'        => "$url/$f",
            'fav'         => "$url/$f",
            'thumb'       => "$url/$f",
            'hasFull'     => false,
            'hasFav'      => true,
        ];
    }

    // Objects in Sun, Moon, planets order; each object's shots newest first.
    uasort($cards, fn($a, $b) => $a['rank'] - $b['rank']);
    foreach ($cards as &$c) {
        usort($c['images'], fn($a, $b) => strcmp((string)$b['date'], (string)$a['date']) ?: strcmp($a['base'], $b['base']));
        foreach ($c['images'] as $i => &$im) $im['id'] = $i;
        unset($im);
    }
    unset($c);
    return array_values($cards);
}
