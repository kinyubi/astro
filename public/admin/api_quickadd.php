<?php
// ============================================================
// api_quickadd.php  —  Public quick-add endpoint for /vis
//
// Accepts POST with JSON body: { "dso_key": "NGC1976" }
// - If the object already exists: returns { "exists": true }
// - If new: AI-populates all fields, saves to DB, clears vis
//   cache, and returns { "success": true, "created": true }
//
// No auth required — only creates new objects, never modifies
// existing ones.
// ============================================================

require_once __DIR__ . '/config.php';

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

try {
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA foreign_keys = ON');

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
    // Ask AI to populate all fields
    // ------------------------------------------------------------------
    $prompt = <<<PROMPT
You are an astronomy database assistant. Return a JSON object with data about the deep sky object "{$dso_key}".

Return ONLY valid JSON — no markdown fences, no explanation text — in this exact structure:

{
  "CommonName": "string — popular name e.g. Orion Nebula",
  "ObjectTypeID": "string — one of: EMISSION_NEBULA, REFLECTION_NEBULA, DARK_NEBULA, PLANETARY_NEBULA, SUPERNOVA_REMNANT, EMISSION_REFLECTION, WOLF_RAYET_BUBBLE, HII_REGION, SPIRAL_GALAXY, BARRED_SPIRAL, ELLIPTICAL_GALAXY, IRREGULAR_GALAXY, INTERACTING_GALAXIES, OPEN_CLUSTER, GLOBULAR_CLUSTER, CLUSTER_NEBULA, SINGLE_STAR, DOUBLE_STAR, VARIABLE_STAR, SOLAR_SYSTEM",
  "ConstellationID": "string — 3-letter IAU abbreviation e.g. ORI, CYG, CAS",
  "RAHours": number,
  "DecDegrees": number,
  "Magnitude": number,
  "ObjectSize": "string — one sentence: physical size in light-years, apparent angular size in arcminutes, and comparison to the full Moon (30 arcmin across)",
  "DistanceLY": "string — e.g. '~1,350 light-years'",
  "SocialBlurb": "string — two short paragraphs (under 200 words total) for a general audience. Paragraph 1: what it is and why it's interesting. Paragraph 2: what makes it a rewarding imaging target. Separated by \\n\\n. No hashtags. No jargon without explanation.",
  "CatalogIDs": [
    { "CatalogID": "string", "IsPrimary": 1 }
  ]
}

Mark exactly one CatalogID as IsPrimary: 1 — prefer Messier > NGC > IC > other.
Use null for any field that cannot be determined with confidence.
PROMPT;

    $messages = [['role' => 'user', 'content' => $prompt]];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'model'      => ANTHROPIC_MODEL,
            'max_tokens' => 1500,
            'messages'   => $messages,
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

    if ($curl_error || $http_status !== 200) {
        http_response_code(502);
        echo json_encode(['error' => 'AI lookup failed: ' . ($curl_error ?: 'HTTP ' . $http_status)]);
        exit;
    }

    $api_result = json_decode($response, true);
    $full_text  = '';
    foreach ($api_result['content'] ?? [] as $block) {
        if ($block['type'] === 'text') $full_text .= $block['text'];
    }

    // Strip markdown fences
    $full_text = preg_replace('/```(?:json)?\s*/i', '', $full_text);
    $full_text = trim($full_text, " \t\n\r`");

    $fields = json_decode($full_text, true);
    if (!$fields) {
        if (preg_match('/\{.*\}/s', $full_text, $m)) {
            $fields = json_decode($m[0], true);
        }
    }

    if (!$fields) {
        http_response_code(502);
        echo json_encode(['error' => 'Could not parse AI response']);
        exit;
    }

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
        $stmt = $db->prepare("
            INSERT OR IGNORE INTO CatalogIDs (CatalogID, DSOKey, IsPrimary)
            VALUES (:cid, :dkey, :primary)
        ");
        foreach ($fields['CatalogIDs'] as $entry) {
            $cid = strtoupper(trim($entry['CatalogID'] ?? ''));
            if (!$cid) continue;
            $stmt->execute([
                ':cid'     => $cid,
                ':dkey'    => $dso_key,
                ':primary' => $entry['IsPrimary'] ?? 0,
            ]);
        }
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

    echo json_encode([
        'success'    => true,
        'created'    => true,
        'DSOKey'     => $dso_key,
        'CommonName' => $fields['CommonName'] ?? null,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
