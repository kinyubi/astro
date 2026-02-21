<?php
// ============================================================
// api_populate.php  —  AI field population endpoint
//
// Accepts POST with JSON body: { "dso_id": "NGC1976" }
// Calls the Anthropic API to look up the object from training knowledge
// and returns structured field data as JSON.
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$dso_id = trim($body['dso_id'] ?? '');

if (!$dso_id) {
    http_response_code(400);
    echo json_encode(['error' => 'dso_id is required']);
    exit;
}

// ------------------------------------------------------------------
// Build the prompt for Claude
// ------------------------------------------------------------------
$prompt = <<<PROMPT
You are an astronomy database assistant with extensive knowledge of deep sky objects. Return a JSON object with data about the deep sky object "{$dso_id}" using your training knowledge of astronomical catalogs including SIMBAD, NASA, NGC/IC, and Sharpless.

Return ONLY valid JSON — no markdown fences, no explanation text — in this exact structure:

{
  "CommonName": "string — popular name, e.g. Orion Nebula",
  "ObjectTypeID": "string — one of: EMISSION_NEBULA, REFLECTION_NEBULA, DARK_NEBULA, PLANETARY_NEBULA, SUPERNOVA_REMNANT, EMISSION_REFLECTION, WOLF_RAYET_BUBBLE, HII_REGION, SPIRAL_GALAXY, BARRED_SPIRAL, ELLIPTICAL_GALAXY, IRREGULAR_GALAXY, INTERACTING_GALAXIES, OPEN_CLUSTER, GLOBULAR_CLUSTER, CLUSTER_NEBULA, SINGLE_STAR, DOUBLE_STAR, VARIABLE_STAR, SOLAR_SYSTEM",
  "ConstellationID": "string — 3-letter IAU abbreviation, e.g. ORI, CYG, CAS",
  "RAHours": number — Right Ascension in decimal hours (0.0–24.0),
  "DecDegrees": number — Declination in decimal degrees (-90.0 to +90.0),
  "Magnitude": number — apparent visual magnitude,
  "AngularSize": "string — the apparent angular dimensions as seen from Earth, expressed in arcminutes. Give LINEAR dimensions only (NOT area). Largest axis first, e.g. '90×40' for a 90 by 40 arcminute object, or '45' for a circular object ~45 arcminutes across. Do NOT compute or return area (sq arcmin).",
  "DistanceLY": "string — human-readable distance, e.g. '~1,350 light-years'",
  "SocialBlurb": "string — two paragraphs of warm, conversational prose for a social media post aimed at everyday people who love space and pretty astronomy images, not scientists. Avoid jargon, technical terms, and dry scientific language. Write like an enthusiastic friend sharing something amazing they just photographed. Paragraph 1: paint a vivid picture of what this object is and how far away it is — make the scale feel real and wondrous. Paragraph 2: two or three genuinely fascinating or surprising facts that make this object special, told in a way that would make someone say 'wow, I had no idea'. No hashtags. Separate paragraphs with the newline character \\n\\n."
}

If a field cannot be determined with confidence, use null for numbers and null for strings (not an empty string).
PROMPT;

// ------------------------------------------------------------------
// Call Anthropic API
// ------------------------------------------------------------------
$request_body = json_encode([
    'model'      => ANTHROPIC_MODEL,
    'max_tokens' => 1500,
    'messages' => [
        ['role' => 'user', 'content' => $prompt]
    ],
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

// Extract text from all content blocks (tool use + text may be interleaved)
$full_text = '';
foreach ($api_result['content'] ?? [] as $block) {
    if ($block['type'] === 'text') {
        $full_text .= $block['text'];
    }
}

// Strip markdown fences if present
$full_text = preg_replace('/```(?:json)?\s*/i', '', $full_text);
$full_text = trim($full_text, " \t\n\r`");

// Try direct decode first
$fields = json_decode($full_text, true);

// If that fails, try to extract just the JSON object from surrounding prose
if (!$fields) {
    if (preg_match('/\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/s', $full_text, $matches)) {
        $fields = json_decode($matches[0], true);
    }
}

if (!$fields) {
    http_response_code(502);
    echo json_encode([
        'error'    => 'Could not parse JSON from AI response',
        'raw_text' => $full_text,
        'stop_reason' => $api_result['stop_reason'] ?? null,
        'block_types' => array_column($api_result['content'] ?? [], 'type'),
    ]);
    exit;
}

echo json_encode(['success' => true, 'fields' => $fields]);
