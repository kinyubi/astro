<?php
// ============================================================
// api/telescopius.php  —  Telescopius relay (runs on the VPS)
//
// Telescopius only accepts calls from the VPS's IP address, so the PC's
// copy of the app (shared/telescopius.php with TELESCOPIUS_RELAY set in
// .env.local) sends its calls here and this page makes the real call.
// Logging, caching and the monthly budget all happen here, so the VPS
// keeps the one call counter for both machines.
//
// POST only, JSON body, and the X-Relay-Key header must match
// TELESCOPIUS_RELAY_KEY in .env.prod -- without it, strangers could spend
// the 6,000 calls a month.
//
//   {"path": "/targets/search", "params": {...}, "ttl": 0, "essential": false}
//       -> {"ok": true, "data": ..., "cached": false, "upstream_ms": 412.3,
//           "timing": {...}, "bytes": ..., "status": 200, ...}
//   {"action": "usage"}
//       -> {"ok": true, "calls_this_month": 47, "limit": 6000}   (no Telescopius call)
//
// Errors: {"ok": false, "error": "..."}; "budget": true when the monthly
// limit stopped the call; "rate_limited": true + "retry_after" (seconds)
// when Telescopius's 60-a-minute limit did (the PC's client then waits
// and tries again).
// Only the GET endpoints in Telescopius::RELAY_PATHS are passed on.
// ============================================================

require_once __DIR__ . '/../../shared/telescopius.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function relay_out(int $code, array $body): never
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    relay_out(405, ['ok' => false, 'error' => 'POST only']);
}

$expected = Telescopius::setting('TELESCOPIUS_RELAY_KEY');
$given    = $_SERVER['HTTP_X_RELAY_KEY'] ?? '';
if ($expected === null) {
    relay_out(500, ['ok' => false, 'error' => 'Relay not set up: TELESCOPIUS_RELAY_KEY missing from .env.prod on the server']);
}
if (!hash_equals($expected, $given)) {
    relay_out(403, ['ok' => false, 'error' => 'Wrong or missing relay key']);
}

$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) {
    relay_out(400, ['ok' => false, 'error' => 'Body must be JSON']);
}

try {
    $t = new Telescopius(null, null, false);   // always direct: never relay to itself

    if (($in['action'] ?? '') === 'usage') {
        relay_out(200, ['ok' => true, 'calls_this_month' => $t->callsThisMonth(), 'limit' => Telescopius::MONTHLY_LIMIT]);
    }

    $path = (string) ($in['path'] ?? '');
    if (!in_array($path, Telescopius::RELAY_PATHS, true)) {
        relay_out(400, ['ok' => false, 'error' => "Not a relayed endpoint: $path"]);
    }
    $params = array_filter(
        is_array($in['params'] ?? null) ? $in['params'] : [],
        fn($v) => is_scalar($v)
    );

    $r = $t->get($path, $params, max(0, (int) ($in['ttl'] ?? 0)), !empty($in['essential']));
    relay_out(200, ['ok' => true] + $r);
} catch (TelescopiusRateLimitException $e) {
    relay_out(429, ['ok' => false, 'rate_limited' => true, 'retry_after' => $e->retryAfter, 'error' => $e->getMessage()]);
} catch (TelescopiusBudgetException $e) {
    relay_out(429, ['ok' => false, 'budget' => true, 'error' => $e->getMessage()]);
} catch (TelescopiusException $e) {
    relay_out(502, ['ok' => false, 'error' => $e->getMessage()]);
}
