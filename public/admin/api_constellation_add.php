<?php
// ============================================================
// api_constellation_add.php  —  AI-assisted constellation lookup & insert
//
// Accepts POST: { "name": "Scorpius" }
// Uses AI to resolve IAU abbreviation, genitive name, center RA/Dec.
// Inserts into Constellations table and returns the new row.
// ============================================================

require_once __DIR__ . '/auth_api.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$name = trim($body['name'] ?? '');

if (!$name) {
    http_response_code(400);
    echo json_encode(['error' => 'name is required']);
    exit;
}

try {
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA foreign_keys = ON');

    // ── Ask AI for the constellation data ──────────────────────
    $prompt = <<<PROMPT
I need precise astronomical data for the constellation "{$name}".

Return ONLY a JSON object with these exact keys — no explanation, no markdown:
{
  "ConstellationID": "3-letter IAU abbreviation, uppercase, e.g. SCO",
  "Name": "Official English name, e.g. Scorpius",
  "GenitiveName": "Latin genitive, e.g. Scorpii",
  "RightAscensionHours": <decimal hours of the approximate center, e.g. 16.9>,
  "DeclinationDegrees": <decimal degrees of the approximate center, e.g. -27.0>
}

Use the IAU-standard 3-letter abbreviation. RA and Dec should be the approximate centroid
of the constellation boundary, to 2 decimal places.
PROMPT;

    $payload = [
        'model'      => ANTHROPIC_MODEL,
        'max_tokens' => 256,
        'messages'   => [
            ['role' => 'user',      'content' => $prompt],
            ['role' => 'assistant', 'content' => '{'],   // prefill — forces pure JSON
        ],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'x-api-key: '            . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ],
    ]);
    $raw      = curl_exec($ch);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($curl_err) throw new Exception('cURL error: ' . $curl_err);

    $ai_response = json_decode($raw, true);
    if (empty($ai_response['content'][0]['text'])) {
        throw new Exception('Unexpected AI response: ' . $raw);
    }

    // The prefill means the response starts mid-object — prepend the brace
    $json_text = '{' . $ai_response['content'][0]['text'];
    $fields    = json_decode($json_text, true);

    if (!$fields || empty($fields['ConstellationID'])) {
        throw new Exception('AI returned unparseable data: ' . $json_text);
    }

    $cid  = strtoupper(trim($fields['ConstellationID']));
    $cname = trim($fields['Name'] ?? $name);
    $gen  = trim($fields['GenitiveName'] ?? null);
    $ra   = isset($fields['RightAscensionHours'])  ? (float)$fields['RightAscensionHours']  : null;
    $dec  = isset($fields['DeclinationDegrees'])    ? (float)$fields['DeclinationDegrees']    : null;

    // ── Check it doesn't already exist ────────────────────────
    $check = $db->prepare('SELECT ConstellationID FROM Constellations WHERE ConstellationID = ?');
    $check->execute([$cid]);
    if ($check->fetch()) {
        // Already exists — just return it so the dropdown can select it
        echo json_encode([
            'success'        => true,
            'already_exists' => true,
            'ConstellationID' => $cid,
            'Name'            => $cname,
        ]);
        exit;
    }

    // ── Insert ─────────────────────────────────────────────────
    $stmt = $db->prepare('
        INSERT INTO Constellations (ConstellationID, Name, GenitiveName, RightAscensionHours, DeclinationDegrees)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([$cid, $cname, $gen, $ra, $dec]);

    echo json_encode([
        'success'              => true,
        'already_exists'       => false,
        'ConstellationID'      => $cid,
        'Name'                 => $cname,
        'GenitiveName'         => $gen,
        'RightAscensionHours'  => $ra,
        'DeclinationDegrees'   => $dec,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
