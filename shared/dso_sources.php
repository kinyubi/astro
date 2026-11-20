<?php
// ============================================================
// shared/dso_sources.php -- what a new DSO's fields and blurb are built from
//
// Used by admin/api_quickadd.php and admin/api_populate.php. A new DSO's
// values are trusted in this order:
//   1. Telescopius (shared/telescopius_lookup.php): position, constellation,
//      magnitude, size, name (when it has a real one), and the object type
//      when its classification is clear-cut
//   2. Wikipedia (shared/wikipedia.php): the article the blurb and distance
//      are written from; its title is the name when Telescopius has none
//   3. The AI: only what neither has -- and the blurb rules below forbid it
//      from adding facts the sources don't give.
// ============================================================

require_once __DIR__ . '/telescopius_lookup.php';
require_once __DIR__ . '/wikipedia.php';

/** Telescopius type codes, in words (the ones that describe what an object is). */
const DSO_TYPE_WORDS = [
    'eneb' => 'emission nebula', 'h2r' => 'H II region', 'rneb' => 'reflection nebula',
    'dneb' => 'dark nebula', 'drkn' => 'dark nebula', 'dkneb' => 'dark nebula', 'darkn' => 'dark nebula',
    'pn' => 'planetary nebula', 'plneb' => 'planetary nebula', 'snr' => 'supernova remnant',
    'opcl' => 'open cluster', 'glcl' => 'globular cluster', 'stass' => 'stellar association',
    'scd' => 'star cloud', 'sgx' => 'spiral galaxy', 'egx' => 'elliptical galaxy', 'igx' => 'irregular galaxy',
    'lgx' => 'lenticular galaxy', 'gxy' => 'galaxy', 'gpair' => 'pair of galaxies', 'ggroup' => 'group of galaxies',
    'agn' => 'active galactic nucleus', 'neb' => 'nebula',
];

/** "emission nebula, H II region, open cluster" -- Telescopius's classification in words. */
function dso_type_words(?array $o): string
{
    $words = [];
    foreach ($o['types'] ?? [] as $code) {
        if (isset(DSO_TYPE_WORDS[$code])) $words[] = DSO_TYPE_WORDS[$code];
    }
    $words = array_values(array_unique($words));
    // "nebula" / "galaxy" add nothing next to a more specific word
    if (count($words) > 1) $words = array_values(array_diff($words, ['nebula', 'galaxy']));
    return implode(', ', $words);
}

/** Objects.ObjectTypeID from Telescopius, only when the classification is clear-cut; else null. */
function dso_object_type(?array $o): ?string
{
    $t = $o['types'] ?? [];
    if (!$t) return null;
    $has = fn(array $codes) => (bool) array_intersect($codes, $t);
    $nebula = $has(['eneb', 'h2r', 'rneb', 'neb', 'dineb', 'dneb', 'drkn', 'dkneb', 'darkn']);
    if ($has(['pn', 'plneb'])) return 'PLANETARY_NEBULA';
    if ($has(['snr'])) return 'SUPERNOVA_REMNANT';
    if ($has(['glcl'])) return 'GLOBULAR_CLUSTER';
    if ($has(['sgx']) && !$has(['gpair', 'ggroup'])) return 'SPIRAL_GALAXY';
    if ($has(['egx'])) return 'ELLIPTICAL_GALAXY';
    if ($has(['opcl']) && !$nebula) return 'OPEN_CLUSTER';
    if ($has(['dneb', 'drkn', 'dkneb', 'darkn']) && !$has(['eneb', 'h2r', 'rneb'])) return 'DARK_NEBULA';
    if ($has(['rneb']) && !$has(['eneb', 'h2r', 'opcl'])) return 'REFLECTION_NEBULA';
    return null;   // mixed (e.g. nebula + cluster): the AI picks, told Telescopius's words
}

/** Is this name only a catalog designation (M 71, VDB 81, NGC 6882 3, Sh 2-129)? */
function dso_is_designation(string $name, array $ids): bool
{
    if (in_array(tl_norm($name), array_map('tl_norm', $ids), true)) return true;
    return (bool) preg_match('/^(M|NGC|IC|B|LDN|LBN|VDB|SH ?2|GUM|CED|HEN|CR|MEL|STOCK|TR|PGC|UGC|ABELL|C|CALDWELL|MESSIER|BARNARD)[\s\-]*\d[\w\s\-\.]*$/i', trim($name));
}

/**
 * The common name, and where it came from: Telescopius's when it has a real
 * one, else the Wikipedia article's title, else null (the caller keeps the
 * AI's and says so).
 */
function dso_name(?array $o, ?array $wiki, array $ids): array
{
    $ids = array_merge($ids, $o['ids'] ?? [], $o['alt_ids'] ?? [], [$o['main_id'] ?? '']);
    if (!empty($o['main_name']) && !dso_is_designation($o['main_name'], $ids)) {
        return [trim($o['main_name'], " \"'"), 'Telescopius'];
    }
    if ($wiki && !dso_is_designation($wiki['title'], $ids)) {
        return [preg_replace('/\s*\(.*\)$/', '', $wiki['title']), 'Wikipedia'];
    }
    return [null, null];
}

/** The SOURCES section of the AI prompt: Telescopius's facts and the Wikipedia text. */
function dso_sources_block(?array $o, ?array $wiki, ?string $name): string
{
    $s = "SOURCES -- the only facts you may use.\n";
    if ($o) {
        $truth = tl_ground_truth($o);
        $s .= "CATALOG FACTS (Telescopius -- correct; copy them exactly):\n";
        if ($name)                          $s .= "  NAME         : $name\n";
        if (($w = dso_type_words($o)) !== '') $s .= "  TYPE         : $w\n";
        if (isset($truth['constellation'])) $s .= "  CONSTELLATION: {$truth['constellation']}\n";
        if (isset($truth['size']))          $s .= "  APPARENT SIZE: {$truth['size']}\n";
        if (isset($truth['magnitude']))     $s .= "  MAGNITUDE    : {$truth['magnitude']}\n";
    } elseif ($name) {
        $s .= "NAME: $name\n";
    }
    if ($wiki) {
        $s .= "WIKIPEDIA ARTICLE \"{$wiki['title']}\":\n<<<\n{$wiki['text']}\n>>>\n";
    } else {
        $s .= "WIKIPEDIA: no article found for this object.\n";
    }
    return $s;
}

/** Instructions for the SocialBlurb field, shared by Quick Add and AI Populate. */
function dso_blurb_rules(string $opening, bool $haveWiki): string
{
    $source = $haveWiki
        ? 'Use only facts stated in the SOURCES above (catalog facts and the Wikipedia article). If a fact -- distance, physical size, age, discoverer, date, what lights it -- is not stated there, leave it out. Never fill a gap with a typical or likely value.'
        : 'There is no Wikipedia article, so use only the catalog facts above. Write a shorter blurb (under 90 words) and do not add anything they do not state -- no distance, age, discoverer or history from memory.';
    return "two paragraphs of plain, factual prose for a curious adult with no astronomy background -- matter-of-fact, like a knowledgeable friend, not a press release or nature documentary. "
        . "It must begin with exactly: '{$opening}'. "
        . "$source "
        . "Where the article and the CATALOG FACTS disagree (apparent size, constellation, magnitude), use the CATALOG FACTS. Give the apparent size from the APPARENT SIZE figures, in plain words (e.g. 'about 20 arcminutes, a little over half the width of the full Moon') -- no '~' signs. "
        . "Paragraph 1: what kind of object it is, its constellation, and -- only if the sources give them -- its distance and physical size, plus its apparent size compared to the full Moon. "
        . "Paragraph 2: the one or two most specific facts the sources give about THIS object: its history, who catalogued it, its structure, how it got its name, or something unusual about it. Nothing that would be equally true of any nebula, galaxy or cluster. "
        . "FORBIDDEN: the words 'nursery', 'birthplace', 'cradle', 'star factory' and 'stellar nursery'. Mention star formation only if the Wikipedia article says stars are forming in this object, and then once, plainly. "
        . "Also forbidden: stunning, breathtaking, magnificent, spectacular, striking, dramatic, majestic, ethereal, mesmerizing, captivating, awe-inspiring, incredible, remarkable, iconic, beautiful, massive, vast, giant, glowing (say what makes it shine), jewel, gem, treasure, tapestry, testament, cosmic, celestial, nestled, boasts, showcases, delve, haunting, ghostly, spooky, poetic. "
        . "No 'favorite target', no imaging advice, no closing sentence about the universe, creation or humanity. "
        . "Under 180 words; no sentence over 25 words; do not start two sentences with the same word. Two paragraphs separated by a blank line (\\n\\n).";
}

/** Look everything up for a new DSO: Telescopius, then Wikipedia (by its name or the IDs). */
function dso_sources(PDO $db, string $key, array $ids = [], ?string $objectTypeId = null): array
{
    $tl = tl_lookup($db, $key, $ids, $objectTypeId);
    $o = $tl['object'];
    $allIds = array_values(array_unique(array_merge([$key], $ids)));
    [$name] = dso_name($o, null, $allIds);
    $wikiIds = array_merge($allIds, $o ? array_merge([$o['main_id'] ?? ''], $o['ids'] ?? []) : []);
    $wiki = tl_skip_reason($key, $objectTypeId) === null ? wp_article($name ? [$name] : [], array_filter($wikiIds)) : null;
    [$name, $nameFrom] = dso_name($o, $wiki, $allIds);
    return ['tl' => $tl, 'wiki' => $wiki, 'name' => $name, 'name_from' => $nameFrom,
            'type' => dso_object_type($o), 'ids' => $allIds];
}

/** One line for the admin saying where the values came from. */
function dso_sources_note(array $src, array $keptFromAi): string
{
    $parts = [];
    if ($src['tl']['found']) {
        $from = ['position', 'constellation', 'size'];
        if ($src['name_from'] === 'Telescopius') array_unshift($from, 'name');
        if ($src['type']) $from[] = 'type';
        $parts[] = 'From Telescopius: ' . implode(', ', $from) . '.';
    } else {
        $parts[] = $src['tl']['note'];
    }
    if ($src['name_from'] === 'Wikipedia') $parts[] = 'Name from Wikipedia.';
    $parts[] = $src['wiki'] ? "Blurb written from Wikipedia (\"{$src['wiki']['title']}\")."
                            : 'No Wikipedia article -- short blurb from catalog facts only.';
    if ($keptFromAi) $parts[] = "AI's guess, please check: " . implode(', ', $keptFromAi) . '.';
    return implode(' ', $parts);
}

/** Catalogs kept in CatalogIDs (survey numbers like PGC, UGC, MCG, HD are left out). */
const DSO_CATALOG_RE = '/^(NGC|IC|SH2-|LDN|LBN|VDB|CED|GUM|RCW|ABELL|MEL|CR|STOCK|TR|M|B)\s*(\d+[A-Z]?)$/';

/** "M 33" -> "M33", "Sh 2-129" -> "SH2-129", "Barnard 150" -> "B150"; null for catalogs not kept. */
function dso_catalog_id(string $id): ?string
{
    $s = strtoupper(trim($id));
    $s = preg_replace(['/^MESSIER\s*/', '/^BARNARD\s*/', '/^SH\s*2\s*-\s*/'], ['M', 'B', 'SH2-'], $s);
    return preg_match(DSO_CATALOG_RE, $s, $m) ? $m[1] . $m[2] : null;
}

/**
 * CatalogIDs rows for a new DSO, in the house format (M31, NGC224, SH2-129):
 * the key plus Telescopius's IDs from the kept catalogs -- or, only when
 * Telescopius doesn't know it, the AI's. Primary: Messier, else NGC, else IC,
 * else the key.
 */
function dso_catalog_ids(string $key, ?array $o, array $aiIds = []): array
{
    $keyId = dso_catalog_id($key) ?? strtoupper(str_replace(' ', '', trim($key)));
    $list = [$keyId => true];
    $from = $o ? array_merge([$o['main_id'] ?? ''], $o['ids'] ?? [], $o['alt_ids'] ?? [])
               : array_map(fn($e) => is_array($e) ? (string) ($e['CatalogID'] ?? '') : (string) $e, $aiIds);
    foreach ($from as $id) {
        if (($c = dso_catalog_id($id)) !== null) $list[$c] = true;
    }
    $primary = $keyId;
    foreach (['M', 'NGC', 'IC'] as $p) {
        $hit = array_values(array_filter(array_keys($list), fn($c) => preg_match('/^' . $p . '\d/', $c)));
        if ($hit) { $primary = $hit[0]; break; }
    }
    $rows = [];
    foreach (array_keys($list) as $c) $rows[] = ['CatalogID' => $c, 'IsPrimary' => $c === $primary ? 1 : 0];
    return $rows;
}
