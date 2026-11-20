<?php
// ============================================================
// api_quickadd.php  —  Public quick-add endpoint for /vis
//
// Accepts POST with JSON body: { "dso_key": "NGC1976" }
// - If the object already exists: returns { "exists": true }
// - If new: gathers the facts (shared/dso_sources.php), has the AI write
//   the rest, saves to DB, clears vis cache, starts the visibility-window
//   recalculation in the background, and returns
//   { "success": true, "created": true, "note": "From Telescopius: ..." }
//
// Everything about a new DSO is checked against real sources, because the
// AI regularly gets names, constellations and positions wrong:
//   * Telescopius: position, constellation, magnitude, size (ObjectSize is
//     rewritten from it), name when it has a real one, type when clear-cut
//   * Wikipedia: the article the blurb and distance are written from, and
//     the name when Telescopius has none
//   * Catalog IDs: the key plus Telescopius's (dso_catalog_ids)
//   * The AI: writes the blurb from those sources only, and fills what
//     neither source has -- "note" lists those fields as needing a check.
//
// No auth required — only creates new objects, never modifies
// existing ones.
// ============================================================

// config.php (not auth_api.php -- this endpoint is deliberately
// unauthenticated for /vis) defines ANTHROPIC_MODEL. db_logger.php only
// pulls in shared/config.php, which does NOT define it, so without this
// line the AI call hits an undefined-constant fatal and PHP emits an
// HTML error page instead of JSON.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_logger.php';
require_once __DIR__ . '/../../shared/dso_sources.php';
require_once __DIR__ . '/../../shared/visibility_refresh.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$body    = json_decode(file_get_contents('php://input'), true);
$dso_key = strtoupper(trim($body['dso_key'] ?? ''));

if (!$dso_key) {
    http_response_code(400);
    echo json_encode(['error' => 'dso_key is required']);
    exit;
}

/** Ask the AI; returns the decoded JSON fields or an error string. */
function quickadd_ask_ai(string $prompt): array|string
{
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'model'      => ANTHROPIC_MODEL,
            'max_tokens' => 1500,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT => 60,
    ]);
    $response    = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error  = curl_error($ch);
    curl_close($ch);
    if ($curl_error || $http_status !== 200) return 'AI lookup failed: ' . ($curl_error ?: 'HTTP ' . $http_status);

    $full_text = '';
    foreach (json_decode($response, true)['content'] ?? [] as $block) {
        if ($block['type'] === 'text') $full_text .= $block['text'];
    }
    $full_text = trim(preg_replace('/```(?:json)?\s*/i', '', $full_text), " \t\n\r`");
    $fields = json_decode($full_text, true);
    if (!$fields && preg_match('/\{.*\}/s', $full_text, $m)) $fields = json_decode($m[0], true);
    return is_array($fields) ? $fields : 'Could not parse AI response';
}

try {
    $db = get_db();

    // ------------------------------------------------------------------
    // Check if already exists
    // ------------------------------------------------------------------
    $chk = $db->prepare('SELECT 1 FROM Objects WHERE DSOKey = ? LIMIT 1');
    $chk->execute([$dso_key]);
    if ($chk->fetchColumn()) {
        echo json_encode(['exists' => true, 'DSOKey' => $dso_key]);
        exit;
    }

    // ------------------------------------------------------------------
    // Sources first: Telescopius, then Wikipedia
    // ------------------------------------------------------------------
    $src = dso_sources($db, $dso_key);
    $o = $src['tl']['object'];
    $catalog_id = $o['main_id'] ?? tl_spaced($dso_key);
    $opening = ($src['name'] ? "The {$src['name']} ({$catalog_id}) is" : "{$catalog_id} is");

    $prompt = "You are an astronomy database assistant. Return a JSON object describing the deep sky object \"{$dso_key}\".\n\n"
        . dso_sources_block($o, $src['wiki'], $src['name']) . "\n"
        . <<<PROMPT
Return ONLY valid JSON — no markdown fences, no explanation text — in this exact structure:

{
  "CommonName": "string — the NAME above if given; otherwise the object's popular name only if the Wikipedia article states it, else null",
  "ObjectTypeID": "string — one of: EMISSION_NEBULA, REFLECTION_NEBULA, DARK_NEBULA, PLANETARY_NEBULA, SUPERNOVA_REMNANT, EMISSION_REFLECTION, WOLF_RAYET_BUBBLE, HII_REGION, SPIRAL_GALAXY, BARRED_SPIRAL, ELLIPTICAL_GALAXY, IRREGULAR_GALAXY, INTERACTING_GALAXIES, OPEN_CLUSTER, GLOBULAR_CLUSTER, CLUSTER_NEBULA, SINGLE_STAR, DOUBLE_STAR, VARIABLE_STAR, SOLAR_SYSTEM — chosen from the TYPE above and the article",
  "ConstellationID": "string — 3-letter IAU abbreviation of the CONSTELLATION above",
  "RAHours": number or null,
  "DecDegrees": number or null,
  "Magnitude": number or null,
  "DistanceLY": "string — e.g. '~1,350 light-years', ONLY as stated in the Wikipedia article; null if it gives none",
  "SocialBlurb": "string — {BLURB_RULES}",
  "CatalogIDs": [
    { "CatalogID": "string — e.g. M42, NGC1976, IC434, B150, SH2-129 (no spaces)", "IsPrimary": 1 }
  ]
}

List every catalog ID the sources give. Mark exactly one as IsPrimary: 1 — prefer Messier > NGC > IC > other.
Use null for any field the sources don't support.
PROMPT;
    $prompt = str_replace('{BLURB_RULES}', dso_blurb_rules($opening, $src['wiki'] !== null), $prompt);

    $fields = quickadd_ask_ai($prompt);
    if (is_string($fields)) {
        http_response_code(502);
        echo json_encode(['error' => $fields]);
        exit;
    }

    // ------------------------------------------------------------------
    // Telescopius not found by the key? Try the AI's catalog IDs (cached
    // for 30 days, so a repeat costs nothing).
    // ------------------------------------------------------------------
    $ai_ids = [];
    foreach (is_array($fields['CatalogIDs'] ?? null) ? $fields['CatalogIDs'] : [] as $entry) {
        $cid = strtoupper(trim((string) ($entry['CatalogID'] ?? '')));
        if ($cid !== '' && tl_norm($cid) !== tl_norm($dso_key)) $ai_ids[] = $cid;
    }
    $note_tl = $src['tl']['note'];
    if (!$src['tl']['found'] && $ai_ids && !str_starts_with($note_tl, 'Not looked up') && !str_starts_with($note_tl, "Couldn't check")) {
        $retry = dso_sources($db, $dso_key, $ai_ids, $fields['ObjectTypeID'] ?? null);
        if ($retry['tl']['found']) $src = $retry;   // facts now from Telescopius; the blurb stays as written
    }

    // ------------------------------------------------------------------
    // Sources win over the AI for everything they have
    // ------------------------------------------------------------------
    $kept = [];   // fields only the AI supplied -- listed in the note to check
    $o = $src['tl']['object'];
    if ($src['tl']['found']) {
        foreach ($src['tl']['facts'] as $col => $v) $fields[$col] = $v;
        if (!empty($o['major_axis'])) {
            $fields['ObjectSize'] = tl_size_text($fields['DistanceLY'] ?? null, (float) $o['major_axis'],
                !empty($o['minor_axis']) ? (float) $o['minor_axis'] : (float) $o['major_axis']);
        }
    } else {
        $fields['ObjectSize'] = null;   // no catalog size: don't keep the AI's guess
        foreach (['RAHours' => 'position', 'ConstellationID' => 'constellation', 'Magnitude' => 'magnitude'] as $col => $label) {
            if (($fields[$col] ?? null) !== null && $fields[$col] !== '') $kept[] = $label;
        }
        $kept = array_values(array_unique($kept));
    }
    if ($src['name']) {
        $fields['CommonName'] = $src['name'];
    } elseif (!empty($fields['CommonName'])) {
        $kept[] = 'name';
    }
    if ($src['type']) {
        $fields['ObjectTypeID'] = $src['type'];
    } elseif (!empty($fields['ObjectTypeID']) && !$src['tl']['found']) {
        $kept[] = 'type';   // with Telescopius found, the AI chose it from Telescopius's classification
    }
    if (!$src['wiki'] && !empty($fields['DistanceLY'])) {
        $fields['DistanceLY'] = null;   // with no article, a distance can only be the AI's memory
    }
    // Catalog IDs: Telescopius's, in the house format (M31, NGC224, SH2-129);
    // the AI's only when Telescopius doesn't know the object
    $ai_catalog = is_array($fields['CatalogIDs'] ?? null) ? $fields['CatalogIDs'] : [];
    $fields['CatalogIDs'] = dso_catalog_ids($dso_key, $src['tl']['found'] ? $o : null, $ai_catalog);
    if (!$src['tl']['found'] && count($fields['CatalogIDs']) > 1) $kept[] = 'catalog IDs';

    // ------------------------------------------------------------------
    // Save the new object
    // ------------------------------------------------------------------
    $not_null_defaults = ['WantBetter' => 0];

    $allowed_cols = [
        'CommonName', 'ObjectTypeID', 'ConstellationID',
        'RAHours', 'DecDegrees', 'Magnitude',
        'ObjectSize', 'SqArcMins', 'DistanceLY', 'SocialBlurb', 'WantBetter',
    ];

    $col_list = implode(', ', array_merge(['DSOKey'], $allowed_cols));
    $val_list = implode(', ', array_merge([':DSOKey'], array_map(fn($c) => ":$c", $allowed_cols)));

    $params = [':DSOKey' => $dso_key];
    foreach ($allowed_cols as $col) {
        $val = isset($fields[$col]) && $fields[$col] !== '' ? $fields[$col] : null;
        if ($val === null && isset($not_null_defaults[$col])) {
            $val = $not_null_defaults[$col];
        }
        $params[":$col"] = $val;
    }

    $db->prepare("INSERT INTO Objects ($col_list) VALUES ($val_list)")->execute($params);

    // Catalog IDs
    if (!empty($fields['CatalogIDs']) && is_array($fields['CatalogIDs'])) {
        // "ON CONFLICT DO NOTHING" (no target column) is valid on both
        // SQLite 3.24+ and Postgres — portable replacement for SQLite's
        // old "INSERT OR IGNORE".
        $stmt = $db->prepare("
            INSERT INTO CatalogIDs (CatalogID, DSOKey, IsPrimary)
            VALUES (:cid, :dkey, :primary)
            ON CONFLICT DO NOTHING
        ");
        foreach ($fields['CatalogIDs'] as $entry) {
            $cid = $entry['CatalogID'];
            if (!$cid) continue;
            $stmt->execute([
                ':cid'     => $cid,
                ':dkey'    => $dso_key,
                ':primary' => $entry['IsPrimary'] ?? 0,
            ]);
        }
    }

    // Keep what Telescopius sent (TelescopiusObjects), as telescopius_enrich does
    if ($src['tl']['found']) {
        try { tl_save($db, $dso_key, $o, $src['tl']['by']); } catch (Throwable $e) { /* not essential */ }
    }

    // ------------------------------------------------------------------
    // Clear vis cache so next /vis?rebuild=1 regenerates cleanly
    // ------------------------------------------------------------------
    $cache_dir = __DIR__ . '/../vis/cache';
    if (is_dir($cache_dir)) {
        foreach (glob($cache_dir . '/dso_report_*.html') as $f) {
            @unlink($f);
        }
    }

    // Visibility windows for /vis/span/, in the background (~30 s)
    $visibility = ($params[':RAHours'] !== null && $params[':DecDegrees'] !== null) && vr_start([$dso_key]);

    echo json_encode([
        'success'          => true,
        'created'          => true,
        'DSOKey'           => $dso_key,
        'CommonName'       => $fields['CommonName'] ?? null,
        'position_checked' => $src['tl']['found'],
        'wikipedia'        => $src['wiki']['url'] ?? null,
        'note'             => dso_sources_note($src, $kept) . ($visibility ? ' Visibility windows are being worked out.' : ''),
    ]);

} catch (Throwable $e) {
    // Throwable (not Exception) so PHP Errors -- undefined constants,
    // type errors, etc. -- still come back as JSON rather than an HTML
    // fatal-error page the caller can't parse.
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
