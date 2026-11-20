<?php
// ============================================================
// api_populate.php  —  AI field population endpoint
//
// Accepts POST with JSON body: { "dso_id": "NGC1976" }
// Calls the Anthropic API to look up the object from training knowledge
// and returns structured field data as JSON.
//
// Sources first (shared/dso_sources.php): Telescopius's facts and the
// object's Wikipedia article go into the prompt, the AI writes the blurb
// from them only, and Telescopius's RA/Dec, magnitude, constellation,
// size, name and (clear-cut) type replace the AI's in the returned fields.
// The admin page only fills fields that are still empty. The response's
// "telescopius_note" says where things came from, for the admin to show.
// Nothing is saved here except Telescopius's answer (TelescopiusObjects);
// the admin page saves the fields, and api_save.php then recalculates the
// visibility windows if the position changed.
// ============================================================

require_once __DIR__ . '/auth_api.php';
require_once __DIR__ . '/db_logger.php';
require_once __DIR__ . '/../../shared/dso_sources.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$dso_id            = trim($body['dso_id']            ?? '');
$primary_catalog_id = trim($body['primary_catalog_id'] ?? '') ?: $dso_id;
$common_name       = trim($body['common_name']        ?? '');
$constellation     = trim($body['constellation']      ?? '');
$object_size       = trim($body['object_size']        ?? '');
$distance          = trim($body['distance']            ?? '');

if (!$dso_id) {
    http_response_code(400);
    echo json_encode(['error' => 'dso_id is required']);
    exit;
}

// ------------------------------------------------------------------
// Solar guard — Sun/Moon are not standard catalog DSOs and AI lookup
// against training-data astronomy knowledge doesn't apply to them.
// ------------------------------------------------------------------
$object_type_id = trim($body['object_type_id'] ?? '');
if (!$object_type_id) {
    // Fall back to whatever's already saved for this DSOKey
    $db = get_db();
    $stmt = $db->prepare("SELECT ObjectTypeID FROM Objects WHERE DSOKey = ?");
    $stmt->execute([$dso_id]);
    $object_type_id = $stmt->fetchColumn() ?: '';
}
if ($object_type_id === 'SOLAR_SYSTEM') {
    http_response_code(400);
    echo json_encode(['error' => 'AI Populate is not available for solar objects (Sun/Moon). Use the solar pipeline instead.']);
    exit;
}

// ------------------------------------------------------------------
// Comet key normalisation
// DSOKeys matching C\d{4}-[A-Z]{2} (e.g. C2023-YZ) are comets stored in a
// DB-safe format. Convert to the standard IAU designation C/2023 YZ before
// passing to the AI so it can look the object up correctly.
// ------------------------------------------------------------------
$lookup_catalog_id = $primary_catalog_id;
if (preg_match('/^C(\d{4})-([A-Z]{2})$/', $dso_id)) {
    // Transform stored key → IAU comet designation
    $lookup_catalog_id = preg_replace('/^C(\d{4})-([A-Z]{2})$/', 'C/$1 $2', $dso_id);
    // Also apply to primary_catalog_id if it still mirrors the stored key
    if ($primary_catalog_id === $dso_id || $primary_catalog_id === '') {
        $primary_catalog_id = $lookup_catalog_id;
    }
}

// ------------------------------------------------------------------
// Sources first: Telescopius and Wikipedia fill whatever the form left blank
// ------------------------------------------------------------------
$db = get_db();
$form_object_size = $object_size;
$form_distance    = $distance;
$src = dso_sources($db, $dso_id, $primary_catalog_id !== $dso_id ? [$primary_catalog_id] : [], $object_type_id);
$tl = $src['tl'];
$truth = tl_ground_truth($tl['object'], $distance ?: null);
if ($common_name === '' && $src['name'])                     $common_name   = $src['name'];
if ($constellation === '' && isset($truth['constellation'])) $constellation = $truth['constellation'];
if ($object_size === '' && isset($truth['size']))            $object_size   = $truth['size'];
$sources_block = dso_sources_block($tl['object'], $src['wiki'], $common_name ?: $src['name']);

// ------------------------------------------------------------------
// Build the prompt for Claude
// ------------------------------------------------------------------
$common_name_label  = $common_name  ?: '(not set — take it from the SOURCES below, or null)';
$constellation_label = $constellation ?: '(not set — take it from the SOURCES below)';
$distance_label     = $distance     ?: '(not set — only as stated in the Wikipedia article, else null)';
$object_size_label  = $object_size  ?: '(not set — take it from the SOURCES below)';
$blurb_opening      = $common_name ? "The {$common_name} ({$lookup_catalog_id}) is" : "{$lookup_catalog_id} is";
$blurb_rules        = dso_blurb_rules($blurb_opening, $src['wiki'] !== null);

$prompt = <<<PROMPT
You are an astronomy database assistant. Return a JSON object with data about the deep sky object "{$lookup_catalog_id}".

{$sources_block}

GROUND TRUTH — these values are set by the user and must be used exactly as provided. Do not substitute, correct, or infer alternatives for any field that is set:
COMMON NAME : {$common_name_label}
CATALOG ID  : {$lookup_catalog_id}
CONSTELLATION: {$constellation_label}
DISTANCE    : {$distance_label}
OBJECT SIZE : {$object_size_label}

For the SocialBlurb, the first sentence MUST open with exactly: "{$blurb_opening}..."

Return ONLY valid JSON — no markdown fences, no explanation text — in this exact structure:

{
  "CommonName": "string — use the GROUND TRUTH common name above if set, otherwise infer from catalog knowledge",
  "ObjectTypeID": "string — one of: EMISSION_NEBULA, REFLECTION_NEBULA, DARK_NEBULA, PLANETARY_NEBULA, SUPERNOVA_REMNANT, EMISSION_REFLECTION, WOLF_RAYET_BUBBLE, HII_REGION, SPIRAL_GALAXY, BARRED_SPIRAL, ELLIPTICAL_GALAXY, IRREGULAR_GALAXY, INTERACTING_GALAXIES, OPEN_CLUSTER, GLOBULAR_CLUSTER, CLUSTER_NEBULA, SINGLE_STAR, DOUBLE_STAR, VARIABLE_STAR, SOLAR_SYSTEM",
  "ConstellationID": "string — 3-letter IAU abbreviation, e.g. ORI, CYG, CAS",
  "RAHours": number — Right Ascension in decimal hours (0.0–24.0),
  "DecDegrees": number — Declination in decimal degrees (-90.0 to +90.0),
  "Magnitude": number — apparent visual magnitude,
  "ObjectSize": "string — a plain-English size description covering three things: (1) the actual physical size in light-years, (2) the apparent angular size in arcminutes, and (3) a comparison to the full moon's diameter (the full moon is 30 arcminutes across). Example: '70 light-years across with an apparent diameter of 45-50 arcminutes, about 1.5 times the size of the full moon.' Keep it to one sentence.",
  "DistanceLY": "string — human-readable distance, e.g. '~1,350 light-years', ONLY as stated in the Wikipedia article; null if it gives none",
  "SqArcMins": number — apparent area in square arcminutes. Calculate as follows: (1) If the angular size is given as a single diameter d (in any unit), convert to arcminutes then compute π × (d/2)². (2) If the angular size is given as two dimensions (width × height), convert both to arcminutes then compute width × height. Unit conversions: 1 degree = 60 arcminutes; 1 arcsecond = 1/60 arcminute. Round to two decimal places. Use null if angular size is unknown.,
  "SocialBlurb": "string — {$blurb_rules}",
  "CatalogIDs": [
    { "CatalogID": "string — catalog identifier e.g. M42, NGC1976, IC434", "IsPrimary": 1 or 0 }
  ]
}

For CatalogIDs, list all known catalog identifiers for this object. Mark exactly one as IsPrimary: 1 — prefer the most widely recognised identifier (Messier > NGC > IC > other).
If a field cannot be determined with confidence, use null for numbers and null for strings. CatalogIDs should be an empty array [] if unknown.
PROMPT;

// ------------------------------------------------------------------
// Call Anthropic API
// ------------------------------------------------------------------
// If we have a common name and catalog ID, prefill the assistant response
// with the correct opening of the SocialBlurb. The model must continue from
// this exact text and cannot substitute a different name.
$messages = [['role' => 'user', 'content' => $prompt]];

if ($common_name && $primary_catalog_id) {
    // Build a partial JSON response with the blurb already opened correctly.
    // The model will complete the JSON from this point.
    $blurb_opening = 'The ' . $common_name . ' (' . $primary_catalog_id . ') is';
    $prefill = '{' . "\n" .
        '  "CommonName": ' . json_encode($common_name) . ',' . "\n" .
        '  "SocialBlurb": ' . json_encode($blurb_opening, JSON_UNESCAPED_UNICODE);
    // Strip the closing quote so the model continues the string
    $prefill = rtrim($prefill, '"');
    $messages[] = ['role' => 'assistant', 'content' => $prefill];
}

$request_body = json_encode([
    'model'      => ANTHROPIC_MODEL,
    'max_tokens' => 1500,
    'messages'   => $messages,
]);

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $request_body,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . ANTHROPIC_API_KEY,
        'anthropic-version: 2023-06-01',
    ],
    CURLOPT_TIMEOUT => 60,
]);

$response     = curl_exec($ch);
$http_status  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error   = curl_error($ch);
curl_close($ch);

if ($curl_error) {
    http_response_code(502);
    echo json_encode(['error' => 'Curl error: ' . $curl_error]);
    exit;
}

if ($http_status !== 200) {
    http_response_code(502);
    echo json_encode(['error' => 'Anthropic API error ' . $http_status, 'detail' => $response]);
    exit;
}

$api_result = json_decode($response, true);

// Extract text from all content blocks
$full_text = '';
foreach ($api_result['content'] ?? [] as $block) {
    if ($block['type'] === 'text') {
        $full_text .= $block['text'];
    }
}

// If we prefilled the assistant response, prepend it so we have complete JSON
if (!empty($prefill)) {
    $full_text = $prefill . $full_text;
}

// Strip markdown fences if present
$full_text = preg_replace('/```(?:json)?\s*/i', '', $full_text);
$full_text = trim($full_text, " \t\n\r`");

// Try direct decode first
$fields = json_decode($full_text, true);

// If that fails, try to extract just the JSON object from surrounding prose
if (!$fields) {
    if (preg_match('/\{.*\}/s', $full_text, $matches)) {
        $fields = json_decode($matches[0], true);
    }
}

if (!$fields) {
    http_response_code(502);
    echo json_encode([
        'error'       => 'Could not parse JSON from AI response',
        'raw_text'    => $full_text,
        'stop_reason' => $api_result['stop_reason'] ?? null,
        'block_types' => array_column($api_result['content'] ?? [], 'type'),
    ]);
    exit;
}

// ------------------------------------------------------------------
// Enforce correct opening sentence in SocialBlurb
// ------------------------------------------------------------------
if (!empty($fields['SocialBlurb']) && $common_name && $primary_catalog_id) {
    $expected_open = 'The ' . $common_name . ' (' . $primary_catalog_id . ') is';
    $blurb = $fields['SocialBlurb'];

    // Check if blurb starts correctly (case-insensitive)
    if (stripos($blurb, $expected_open) !== 0) {
        // Find where the first sentence ends and strip it
        $first_sentence_end = preg_match('/^.+?[.!?]\s*/s', $blurb, $m) ? strlen($m[0]) : 0;
        $remainder = $first_sentence_end ? substr($blurb, $first_sentence_end) : $blurb;

        // Re-attach with correct opening — AI still wrote the completion after "is"
        // Try to salvage the "is ..." part from the wrong sentence if it exists
        if (preg_match('/\bis\s+(.+)/is', $m[0] ?? '', $is_match)) {
            $fields['SocialBlurb'] = $expected_open . ' ' . $is_match[1]
                . ($remainder ? '\n\n' . trim($remainder) : '');
        } else {
            // AI wrote something completely unusable — prepend correct opener and keep the rest
            $fields['SocialBlurb'] = $expected_open . ' ' . ltrim($remainder);
        }
    }
}

// ------------------------------------------------------------------
// Telescopius values replace the AI's. Not found by the key? Try the
// AI's catalog IDs (cached for 30 days, so a repeat costs nothing).
// ------------------------------------------------------------------
$ai_ids = [];
foreach (is_array($fields['CatalogIDs'] ?? null) ? $fields['CatalogIDs'] : [] as $entry) {
    $cid = strtoupper(trim((string) ($entry['CatalogID'] ?? '')));
    if ($cid !== '' && tl_norm($cid) !== tl_norm($dso_id)) $ai_ids[] = $cid;
}
if (!$tl['found'] && $ai_ids && !str_starts_with($tl['note'], 'Not looked up')
    && !str_starts_with($tl['note'], "Couldn't check")) {
    $retry = dso_sources($db, $dso_id, array_merge([$primary_catalog_id], $ai_ids), $fields['ObjectTypeID'] ?? $object_type_id);
    if ($retry['tl']['found']) { $src = $retry; $tl = $retry['tl']; }
}

// Sources win over the AI for everything they have. (The admin page still
// only fills fields that are empty in the form.)
$kept = [];   // fields only the AI supplied -- named in the note to check
if ($tl['found']) {
    foreach ($tl['facts'] as $col => $v) $fields[$col] = $v;
    $o = $tl['object'];
    if ($form_object_size === '' && !empty($o['major_axis'])) {
        $fields['ObjectSize'] = tl_size_text($fields['DistanceLY'] ?? ($distance ?: null), (float) $o['major_axis'],
            !empty($o['minor_axis']) ? (float) $o['minor_axis'] : (float) $o['major_axis']);
    }
    try { tl_save($db, $dso_id, $o, $tl['by']); } catch (Throwable $e) { /* not essential */ }
} else {
    if ($form_object_size === '') $fields['ObjectSize'] = null;   // no catalog size: not the AI's guess
    foreach (['RAHours' => 'position', 'ConstellationID' => 'constellation', 'Magnitude' => 'magnitude'] as $col => $label) {
        if (($fields[$col] ?? null) !== null && $fields[$col] !== '') $kept[] = $label;
    }
}
if ($src['name']) {
    $fields['CommonName'] = $src['name'];
} elseif (!empty($fields['CommonName']) && $common_name === '') {
    $kept[] = 'name';
}
if ($src['type']) {
    $fields['ObjectTypeID'] = $src['type'];
} elseif (!empty($fields['ObjectTypeID']) && !$tl['found']) {
    $kept[] = 'type';   // with Telescopius found, the AI chose it from Telescopius's classification
}
if (!$src['wiki'] && $form_distance === '') $fields['DistanceLY'] = null;   // no article: a distance would be memory
// Catalog IDs: Telescopius's, in the house format (M31, NGC224, SH2-129); the
// AI's only when Telescopius doesn't know it. (Used only if the form has none.)
$ai_catalog = is_array($fields['CatalogIDs'] ?? null) ? $fields['CatalogIDs'] : [];
$fields['CatalogIDs'] = dso_catalog_ids($dso_id, $tl['found'] ? $tl['object'] : null, $ai_catalog);
if (!$tl['found'] && count($fields['CatalogIDs']) > 1) $kept[] = 'catalog IDs';

echo json_encode(['success' => true, 'fields' => $fields, 'telescopius_note' => dso_sources_note($src, $kept),
                  'wikipedia' => $src['wiki']['url'] ?? null]);
