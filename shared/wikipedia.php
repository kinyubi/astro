<?php
// ============================================================
// shared/wikipedia.php -- a DSO's English Wikipedia article, as plain text
//
//     $w = wp_article(['Barnard 150', 'B 150'], ['B150', 'LDN1082']);
//     $w === null                     no article found
//     $w['title']  'Seahorse Nebula'  $w['url']  https://en.wikipedia.org/wiki/Seahorse_Nebula
//     $w['text']   the article body as plain text, without See also /
//                  References / External links, cut to about 6,000 characters
//
// Used so the AI writes a DSO's blurb from the article instead of from
// memory (admin/api_quickadd.php, admin/api_populate.php). Tries each name
// as a page title first (Wikipedia's redirects turn "Barnard 150" into
// "Seahorse Nebula", "NGC 7000" into "North America Nebula"), skipping
// disambiguation pages, then one search. A search hit is only accepted if
// the article mentions one of the DSO's IDs or names, so a near-miss
// article is never used. Answers are cached on disk for 30 days
// (cache/wikipedia/). Never throws: any failure is just "no article".
// ============================================================

const WP_API = 'https://en.wikipedia.org/w/api.php';
const WP_CACHE_TTL = 30 * 86400;
const WP_MAX_CHARS = 6000;

/** "M31" -> "Messier 31", "B150" -> "Barnard 150", "SH2-129" -> "Sh 2-129", "NGC7000" -> "NGC 7000". */
function wp_title_for_id(string $id): string
{
    $id = strtoupper(trim($id));
    if (preg_match('/^SH\s*2\s*-\s*(\d+)$/', $id, $m)) return "Sh 2-{$m[1]}";
    if (preg_match('/^M\s*(\d+)$/', $id, $m)) return "Messier {$m[1]}";
    if (preg_match('/^B\s*(\d+)$/', $id, $m)) return "Barnard {$m[1]}";
    if (preg_match('/^C\s*(\d+)$/', $id, $m)) return "Caldwell {$m[1]}";
    if (str_contains($id, ' ') || str_contains($id, '-')) return $id;
    return preg_replace('/^([A-Z]+)(\d.*)$/', '$1 $2', $id);
}

/** GET the Wikipedia API (cached). Null on any failure. */
function wp_get(array $params): ?array
{
    $params += ['format' => 'json', 'formatversion' => '2'];
    ksort($params);
    $dir = dirname(__DIR__) . '/cache/wikipedia';
    $file = $dir . '/' . sha1(http_build_query($params)) . '.json';
    if (is_file($file) && time() - filemtime($file) < WP_CACHE_TTL) {
        $d = json_decode((string) file_get_contents($file), true);
        if (is_array($d)) return $d;
    }
    $ch = curl_init(WP_API . '?' . http_build_query($params));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_ENCODING => '',
        // Wikipedia asks API users to say who they are
        CURLOPT_USERAGENT => 'AstroWiibopp/1.0 (https://astro.wiibopp.com; carl@wiibopp.com)',
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $status !== 200) return null;
    $d = json_decode($body, true);
    if (!is_array($d)) return null;
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    @file_put_contents($file, $body, LOCK_EX);
    return $d;
}

/** One page by title (following redirects), or null if missing / a disambiguation page. */
function wp_page(string $title): ?array
{
    $d = wp_get(['action' => 'query', 'titles' => $title, 'redirects' => '1',
                 'prop' => 'extracts|info|pageprops', 'explaintext' => '1', 'exsectionformat' => 'wiki',
                 'inprop' => 'url', 'ppprop' => 'disambiguation']);
    $p = $d['query']['pages'][0] ?? null;
    if (!$p || !empty($p['missing']) || isset($p['pageprops']['disambiguation']) || empty($p['extract'])) return null;
    return ['title' => $p['title'], 'url' => $p['fullurl'] ?? ('https://en.wikipedia.org/wiki/' . rawurlencode(str_replace(' ', '_', $p['title']))),
            'text' => $p['extract']];
}

/** Article body without the reference sections, cut at a paragraph near WP_MAX_CHARS. */
function wp_clean(string $text): string
{
    $text = preg_split('/\n==+\s*(See also|References|Notes|External links|Further reading|Gallery|Sources)\s*==+/i', $text)[0];
    $text = preg_replace('/\n{3,}/', "\n\n", trim($text));
    if (strlen($text) > WP_MAX_CHARS) {
        $cut = strrpos(substr($text, 0, WP_MAX_CHARS), "\n");
        $text = substr($text, 0, $cut ?: WP_MAX_CHARS) . "\n[...]";
    }
    return $text;
}

/** Does the text mention one of these IDs/names (spacing ignored)? */
function wp_mentions(string $text, array $ids): bool
{
    $flat = strtoupper(preg_replace('/\s+/', '', $text));
    foreach ($ids as $id) {
        $v = strtoupper(preg_replace('/\s+/', '', $id));
        if (strlen($v) >= 3 && str_contains($flat, $v)) return true;
    }
    return false;
}

/**
 * The DSO's article. $names: common names to try first (e.g. Telescopius's);
 * $ids: catalog IDs (B150, NGC7000 ...), tried as titles and used to check a search hit.
 */
function wp_article(array $names, array $ids): ?array
{
    $titles = [];
    foreach ($names as $n) if (trim((string) $n) !== '') $titles[] = trim($n);
    foreach ($ids as $id) if (trim((string) $id) !== '') $titles[] = wp_title_for_id($id);
    $titles = array_values(array_unique($titles));
    $check = array_merge($ids, array_map('wp_title_for_id', $ids), $names);

    foreach ($titles as $t) {
        if (($p = wp_page($t)) !== null) return ['title' => $p['title'], 'url' => $p['url'], 'text' => wp_clean($p['text'])];
    }
    if (!$titles) return null;
    $s = wp_get(['action' => 'query', 'list' => 'search', 'srsearch' => '"' . $titles[0] . '"', 'srlimit' => '3']);
    foreach ($s['query']['search'] ?? [] as $hit) {
        $p = wp_page($hit['title']);
        if ($p && wp_mentions(substr($p['text'], 0, 3000) . ' ' . $p['title'], $check)) {
            return ['title' => $p['title'], 'url' => $p['url'], 'text' => wp_clean($p['text'])];
        }
    }
    return null;
}
