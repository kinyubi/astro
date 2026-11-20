<?php
// ============================================================
// shared/telescopius_lookup.php -- catalog facts for one DSO from Telescopius
//
// Used when a DSO is added or AI-populated (admin/api_quickadd.php,
// admin/api_populate.php) so its position and catalog numbers come from
// Telescopius, not from the AI's memory. The AI still writes the text
// (blurb, distance); ObjectSize is rewritten from Telescopius's size.
//
//     $r = tl_lookup($db, 'B150', ['B 150', 'LDN 1235']);
//     $r['found']  true / false
//     $r['facts']  ['RAHours' => .., 'DecDegrees' => .., 'Magnitude' => ..,
//                   'ConstellationID' => .., 'SqArcMins' => ..]  (only what Telescopius has)
//     $r['object'] Telescopius's 'object' block, $r['by'] the name that found it
//     $r['note']   one human-readable line for the admin ("Position from Telescopius (B 150).")
//
// Never throws: if Telescopius can't be reached, is over budget or doesn't
// know the object, found = false and note says why, so the caller keeps the
// AI's values. Same matching as scripts/telescopius_enrich.php (exact name
// for the key and each catalog ID, then one loose search), same 30-day cache,
// about one call per new DSO.
// ============================================================

require_once __DIR__ . '/telescopius.php';

const TL_CACHE_TTL = 30 * 86400;

function tl_norm(string $id): string
{
    return strtoupper(str_replace(' ', '', $id));
}

/** NGC7380 -> NGC 7380, B150 -> B 150; hyphenated keys (SH2-129) stay as they are. */
function tl_spaced(string $key): string
{
    $key = strtoupper(trim($key));
    if (str_contains($key, '-') || str_contains($key, ' ')) return $key;
    return preg_replace('/^([A-Z]+)(\d.*)$/', '$1 $2', $key);
}

/** The result whose IDs include one of ours, or null. */
function tl_pick(array $results, array $ours): ?array
{
    foreach ($results as $r) {
        $o = $r['object'] ?? [];
        $ids = array_merge([$o['main_id'] ?? ''], $o['ids'] ?? [], $o['alt_ids'] ?? []);
        if (array_intersect(array_map('tl_norm', $ids), $ours)) return $o;
    }
    return null;
}

/** Skip reasons telescopius_enrich uses: solar system objects and comets. */
function tl_skip_reason(string $key, ?string $objectTypeId): ?string
{
    if (strtoupper((string) $objectTypeId) === 'SOLAR_SYSTEM') return 'solar system object';
    if (preg_match('/^C\d{4}/i', $key) || stripos((string) $objectTypeId, 'COMET') !== false) return 'comet';
    return null;
}

/** "2.5", "10" -- one decimal below 10, whole numbers above. */
function tl_fmt_size(float $v): string
{
    return $v < 10 ? rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.') : number_format(round($v));
}

/** Light-years from DistanceLY text ("~1,300 light-years", "2.73 million", "5,000–6,000"); null if unusable. */
function tl_parse_distance(?string $text): ?float
{
    if ($text === null || trim($text) === '') return null;
    $t = str_replace(',', '', strtolower($text));
    if (!preg_match_all('/\d+(?:\.\d+)?/', $t, $m) || count($m[0]) > 2) return null;
    $mult = str_contains($t, 'billion') ? 1e9 : (str_contains($t, 'million') ? 1e6 : (str_contains($t, 'thousand') ? 1e3 : 1));
    $nums = array_map(fn($x) => (float) $x * $mult, $m[0]);
    $d = array_sum($nums) / count($nums);
    return $d > 0 ? $d : null;
}

/**
 * ObjectSize in the house style from Telescopius's axes (arcseconds), e.g.
 * "~2.5 light-years across; ~10 arcminutes—~0.3× the full Moon's diameter."
 * Light-years from DistanceLY when it's a usable number (same rule as
 * telescopius_enrich.php); otherwise the angular part only.
 */
function tl_size_text(?string $distanceText, float $majArcsec, float $minArcsec): string
{
    $a = $majArcsec / 60;
    $b = $minArcsec / 60;
    if ($a >= 120) {
        $angular = abs($a - $b) < 6 ? '~' . tl_fmt_size($a / 60) . '°' : '~' . tl_fmt_size($a / 60) . '°×' . tl_fmt_size($b / 60) . '°';
    } else {
        $angular = abs($a - $b) < 0.5 ? '~' . tl_fmt_size($a) . ' arcminutes' : '~' . tl_fmt_size($a) . '×' . tl_fmt_size($b) . ' arcminutes';
    }
    $moon = '~' . tl_fmt_size($a / 31) . "× the full Moon's diameter";
    $ly = null;
    if (($d = tl_parse_distance($distanceText)) !== null) {
        $lyVal = $d * deg2rad($a / 60);
        $ly = '~' . ($lyVal < 10 ? tl_fmt_size($lyVal) : number_format(round($lyVal, -(int) floor(log10($lyVal)) + 1))) . ' light-years across';
    }
    return ($ly ? "$ly; " : '') . "{$angular}—{$moon}.";
}

/** Objects-column values Telescopius can supply for this object. */
function tl_facts(PDO $db, array $o): array
{
    $facts = [];
    if (isset($o['ra'], $o['dec'])) {
        $facts['RAHours'] = (float) $o['ra'];
        $facts['DecDegrees'] = (float) $o['dec'];
    }
    if (isset($o['visual_mag']) && $o['visual_mag'] !== '') $facts['Magnitude'] = (float) $o['visual_mag'];
    if (!empty($o['con'])) {
        $stmt = $db->prepare('SELECT constellationid FROM constellations WHERE upper(constellationid) = upper(?)');
        $stmt->execute([$o['con']]);
        if (($cid = $stmt->fetchColumn()) !== false) $facts['ConstellationID'] = $cid;
    }
    $maj = isset($o['major_axis']) ? (float) $o['major_axis'] : 0.0;
    $min = isset($o['minor_axis']) && (float) $o['minor_axis'] > 0 ? (float) $o['minor_axis'] : $maj;
    if ($maj > 0) $facts['SqArcMins'] = round(($maj / 60) * ($min / 60), 2);
    return $facts;
}

/** Save Telescopius's answer in TelescopiusObjects (only once the Objects row exists). */
function tl_save(PDO $db, string $key, array $o, string $by): void
{
    $exists = $db->prepare('SELECT 1 FROM objects WHERE dsokey = ?');
    $exists->execute([$key]);
    if (!$exists->fetchColumn()) return;
    $json = fn($v) => $v === null ? null : json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $db->prepare("
        INSERT INTO telescopiusobjects
            (dsokey, matchstatus, matchedby, telescopiusid, mainname, names, allids, types, morphclass,
             rahours, decdegrees, constellation, visualmag, surfacebrightness, majorarcsec, minorarcsec,
             positionangle, popularity, url, thumbnailurl, imageurl, raw, fetchedat)
        VALUES
            (:dsokey, 'matched', :matchedby, :telescopiusid, :mainname, :names, :allids, :types, :morphclass,
             :rahours, :decdegrees, :constellation, :visualmag, :surfacebrightness, :majorarcsec, :minorarcsec,
             :positionangle, :popularity, :url, :thumbnailurl, :imageurl, :raw, now())
        ON CONFLICT (dsokey) DO UPDATE SET
            matchstatus = EXCLUDED.matchstatus, matchedby = EXCLUDED.matchedby,
            telescopiusid = EXCLUDED.telescopiusid, mainname = EXCLUDED.mainname, names = EXCLUDED.names,
            allids = EXCLUDED.allids, types = EXCLUDED.types, morphclass = EXCLUDED.morphclass,
            rahours = EXCLUDED.rahours, decdegrees = EXCLUDED.decdegrees, constellation = EXCLUDED.constellation,
            visualmag = EXCLUDED.visualmag, surfacebrightness = EXCLUDED.surfacebrightness,
            majorarcsec = EXCLUDED.majorarcsec, minorarcsec = EXCLUDED.minorarcsec,
            positionangle = EXCLUDED.positionangle, popularity = EXCLUDED.popularity, url = EXCLUDED.url,
            thumbnailurl = EXCLUDED.thumbnailurl, imageurl = EXCLUDED.imageurl, raw = EXCLUDED.raw,
            fetchedat = now()
    ")->execute([
        ':dsokey' => $key, ':matchedby' => $by,
        ':telescopiusid' => $o['main_id'] ?? null, ':mainname' => $o['main_name'] ?? null,
        ':names' => $json($o['names'] ?? []),
        ':allids' => $json(array_values(array_unique(array_merge($o['ids'] ?? [], $o['alt_ids'] ?? [])))),
        ':types' => $json($o['types'] ?? []), ':morphclass' => $o['morph_class'] ?? null,
        ':rahours' => $o['ra'] ?? null, ':decdegrees' => $o['dec'] ?? null, ':constellation' => $o['con'] ?? null,
        ':visualmag' => $o['visual_mag'] ?? null, ':surfacebrightness' => $o['subr'] ?? null,
        ':majorarcsec' => $o['major_axis'] ?? null, ':minorarcsec' => $o['minor_axis'] ?? null,
        ':positionangle' => $o['pa_east'] ?? null, ':popularity' => $o['popularity'] ?? null,
        ':url' => $o['url'] ?? null, ':thumbnailurl' => $o['thumbnail_url'] ?? null,
        ':imageurl' => $o['main_image_url'] ?? null, ':raw' => $json($o),
    ]);
}

/**
 * Look a DSO up on Telescopius. $catalogIds: other names to try (e.g. the
 * AI's catalog IDs). See the header for what comes back.
 */
function tl_lookup(PDO $db, string $key, array $catalogIds = [], ?string $objectTypeId = null): array
{
    $key = strtoupper(trim($key));
    if (($why = tl_skip_reason($key, $objectTypeId)) !== null) {
        return ['found' => false, 'facts' => [], 'object' => null, 'by' => null,
                'note' => "Not looked up on Telescopius ($why)."];
    }
    $ours = array_values(array_unique(array_map('tl_norm', array_merge([$key], $catalogIds))));
    $tries = array_values(array_unique(array_map('tl_spaced', array_merge([$key], $catalogIds))));
    try {
        $t = new Telescopius();
        $plan = array_map(fn($n) => [$n, true], $tries);
        $plan[] = [tl_spaced($key), false];
        foreach ($plan as [$name, $exact]) {
            $r = $t->get('/targets/search', Telescopius::site([
                'types' => 'DEEP_SKY_OBJECT', 'name' => $name,
                'name_exact' => $exact ? 'true' : 'false', 'results_per_page' => $exact ? 5 : 10,
            ]), TL_CACHE_TTL);
            $hit = tl_pick($r['data']['page_results'] ?? [], $ours);
            if ($hit) {
                $loose = $exact ? '' : ', by a loose search -- worth a check';
                return ['found' => true, 'facts' => tl_facts($db, $hit), 'object' => $hit, 'by' => $name,
                        'note' => "Position and catalog numbers from Telescopius ($name$loose)."];
            }
        }
        return ['found' => false, 'facts' => [], 'object' => null, 'by' => null,
                'note' => 'Telescopius doesn\'t know ' . implode(' or ', $tries) . ' -- position is the AI\'s, please check it.'];
    } catch (Throwable $e) {
        return ['found' => false, 'facts' => [], 'object' => null, 'by' => null,
                'note' => 'Couldn\'t check Telescopius (' . $e->getMessage() . ') -- position is the AI\'s, please check it.'];
    }
}

/**
 * Facts from a Telescopius match, worded for an AI prompt's GROUND TRUTH
 * block, so the blurb is written around them: ['constellation' => 'Cepheus',
 * 'size' => '~60×20 arcminutes—~1.9× the full Moon's diameter.', 'magnitude' => '6.4'].
 * Only what Telescopius has. Telescopius has no description text to use instead.
 */
function tl_ground_truth(?array $o, ?string $distanceText = null): array
{
    if (!$o) return [];
    $g = [];
    if (!empty($o['con_name'])) $g['constellation'] = $o['con_name'];
    elseif (!empty($o['con'])) $g['constellation'] = $o['con'];
    if (!empty($o['major_axis'])) {
        $min = !empty($o['minor_axis']) ? (float) $o['minor_axis'] : (float) $o['major_axis'];
        $g['size'] = tl_size_text($distanceText, (float) $o['major_axis'], $min);
    }
    if (isset($o['visual_mag']) && $o['visual_mag'] !== '' && $o['visual_mag'] !== null) {
        $g['magnitude'] = (string) $o['visual_mag'];
    }
    return $g;
}
