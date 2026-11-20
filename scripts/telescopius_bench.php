<?php
// ============================================================
// telescopius_bench.php  —  How long do Telescopius calls take?
//
// Times each Telescopius call we might use, a few times each, from the
// machine it runs on (run it on the PC and on the VPS -- the network
// path differs; on the PC the calls go through the VPS relay, see
// shared/telescopius.php). For each call it reports the typical time (median),
// fastest and slowest, how much of that is waiting on Telescopius
// (time to first byte) versus connecting (TLS), the response size, how
// many results came back, and how long reading the same answer from our
// disk cache takes instead.
//
// SPENDS REAL CALLS from the 6,000-a-month allowance, so it first shows
// how many it will make and asks. The default run is 10 calls x 3 = 30.
//
//   php telescopius_bench.php                  show the plan, then ask
//   php telescopius_bench.php -a               run without asking
//   php telescopius_bench.php --what-if        show the plan only, call nothing
//                                              (-w, -n and --dry-run also work; wins over -a)
//   php telescopius_bench.php solar weather    just these calls (names below)
//   php telescopius_bench.php -s 5             5 samples per call (default 3)
//   php telescopius_bench.php -k               keep the connection open between
//                                              calls ("warm"); default is a new
//                                              connection each time, which is what
//                                              a web page or a scheduled script gets
//
// Writes, in astro/cache/telescopius/:
//   samples/<call>.json      one full response per call, for designing tables
//   bench_<date>_<host>.json the timings
// Every call is also logged in calls_YYYY-MM.jsonl (the monthly counter).
//
// C:\Astronomy\Apps\pythonScripts\telescopius_bench.cmd runs it with Laragon's PHP.
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Command line only.\n");
}

require_once __DIR__ . '/../shared/telescopius.php';

$dso = ['types' => 'DEEP_SKY_OBJECT'];
$cases = [
    'solar'      => ['Solar-system rise/set times',          '/solar-system/times', Telescopius::site()],
    'events'     => ['Events calendar, next 30 days',         '/events/calendar',    Telescopius::site(['days' => 30])],
    'weather'    => ['Weather forecast with hourly data',     '/weather/forecast',   Telescopius::site(['include_hourly' => 'true'])],
    'highlights' => ['Popular targets now (highlights)',      '/targets/highlights', Telescopius::site($dso)],
    'ne_se_20'   => ['Search: ' . Telescopius::PROFILES['ne_se_20']['label'] . ', up to 120', '/targets/search',
                     Telescopius::profile('ne_se_20', $dso + ['results_per_page' => 120])],
    'open_35'    => ['Search: ' . Telescopius::PROFILES['open_35']['label'] . ', up to 120', '/targets/search',
                     Telescopius::profile('open_35', $dso + ['results_per_page' => 120])],
    'current'    => ['Search: No blocks 35°, with current positions', '/targets/search',
                     Telescopius::profile('open_35', $dso + ['results_per_page' => 120, 'compute_current' => 1])],
    'lookup'     => ['One DSO by exact name (M33)',           '/targets/search',
                     Telescopius::site($dso + ['name' => 'M33', 'name_exact' => 'true'])],
    'ephemeris'  => ['One DSO with 12-month ephemeris (M33)', '/targets/search',
                     Telescopius::site($dso + ['name' => 'M33', 'name_exact' => 'true', 'ephemeris' => 'yearly'])],
    'lists'      => ['My Telescopius target lists',           '/targets/lists',      []],
];

$names = implode(', ', array_keys($cases));
$usage = <<<TXT
Usage: php telescopius_bench.php [CALL ...] [-s N] [-k] [-a | -w] [-h]

  CALL ...           only these calls (default: all). One of:
                     $names
  -s, --samples N    times to make each call (default 3)
  -k, --keep-alive   reuse the connection between calls (default: new each time)
  (no -a or -w)      show the plan, then ask before spending calls
  -a, --apply        run without asking
  -w, --what-if      show the plan only, make no calls (wins over -a;
                     -n/--dry-run also work)
  -h, --help         this help

TXT;

// Parsed by hand, same as sync_gallery.php: options before or after names.
$flags = [];
$only = [];
$samples = 3;
$long = ['apply' => 'a', 'what-if' => 'w', 'dry-run' => 'n', 'keep-alive' => 'k',
         'samples' => 's', 'help' => 'h'];
$args = array_slice($argv, 1);
for ($i = 0; $i < count($args); $i++) {
    $arg = $args[$i];
    if (substr($arg, 0, 2) === '--') {
        [$name, $val] = array_pad(explode('=', substr($arg, 2), 2), 2, null);
        if (!isset($long[$name])) { fwrite(STDERR, "Unknown option: $arg\n\n$usage"); exit(2); }
        if ($long[$name] === 's') { $samples = (int) ($val ?? ($args[++$i] ?? 0)); continue; }
        $flags[$long[$name]] = true;
    } elseif (strlen($arg) > 1 && $arg[0] === '-') {
        foreach (str_split(substr($arg, 1)) as $j => $c) {
            if ($c === 's') {             // -s N or -sN
                $rest = substr($arg, 2 + $j);
                $samples = (int) ($rest !== '' ? $rest : ($args[++$i] ?? 0));
                break;
            }
            if (!in_array($c, ['a', 'w', 'n', 'k', 'h'], true)) {
                fwrite(STDERR, "Unknown option: -$c\n\n$usage"); exit(2);
            }
            $flags[$c] = true;
        }
    } else {
        $only[] = strtolower($arg);
    }
}
if (!empty($flags['h'])) { echo $usage; exit(0); }
if ($samples < 1 || $samples > 20) { fwrite(STDERR, "Samples must be 1-20.\n"); exit(2); }
foreach ($only as $o) {
    if (!isset($cases[$o])) { fwrite(STDERR, "Unknown call: $o\n\n$usage"); exit(2); }
}
if ($only) $cases = array_intersect_key($cases, array_flip($only));
$dry       = !empty($flags['w']) || !empty($flags['n']);
$apply     = !empty($flags['a']) && !$dry;
$keepAlive = !empty($flags['k']);

try {
    $t = new Telescopius();
} catch (TelescopiusException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

// ---- Plan -------------------------------------------------------------
$host    = gethostname();
try {
    $used = $t->callsThisMonth();
} catch (TelescopiusException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
$planned = count($cases) * $samples;
$limit   = Telescopius::MONTHLY_LIMIT;
$pct     = fn($n) => number_format(100 * $n / $limit, 1) . '%';

echo "Telescopius benchmark on $host\n";
$where   = $t->relay() ? 'counted on the VPS' : 'on this machine';
echo "Calls this month ($where): $used of $limit ({$pct($used)})\n";
echo "This run: " . count($cases) . " calls x $samples = $planned calls -> "
   . ($used + $planned) . " ({$pct($used + $planned)})\n";
echo 'Connection: ' . ($keepAlive ? 'kept open between calls (warm)' : 'new for every call (cold)') . "\n";
echo 'Route: ' . ($t->relay() ? 'through the VPS relay (' . $t->relay() . ')' : 'direct to Telescopius') . "\n\n";
foreach ($cases as $c) echo "  - {$c[0]}\n";
echo "\n";

if ($dry) { echo "What-if: no calls made.\n"; exit(0); }
if ($used + $planned > (int) floor($limit * Telescopius::SOFT_CAP)) {
    fwrite(STDERR, "That would go past " . (Telescopius::SOFT_CAP * 100) . "% of the monthly allowance -- not running.\n");
    exit(1);
}
if (!$apply) {
    echo "Spend $planned calls? [y/N] ";
    $answer = strtolower(trim((string) fgets(STDIN)));
    if ($answer !== 'y' && $answer !== 'yes') { echo "Nothing done.\n"; exit(0); }
    echo "\n";
}

// ---- Run --------------------------------------------------------------
// Rounds go through every call once, so a slow minute on the network
// spreads across calls instead of landing on one.
$samplesDir = $t->dataDir() . '/samples';
if (!is_dir($samplesDir)) mkdir($samplesDir, 0775, true);

$runs = [];           // name => list of results
$errors = [];         // name => list of messages
$limitHeaders = [];
$made = 0;
$stopped = null;

for ($round = 1; $round <= $samples && $stopped === null; $round++) {
    foreach ($cases as $name => [$label, $path, $params]) {
        if (!$keepAlive) $t->resetConnection();
        echo "\r" . str_pad("  Round $round of $samples: $label", 80);
        try {
            $r = $t->get($path, $params);     // ttl 0: always a real call
            $made++;
            $runs[$name][] = $r;
            $limitHeaders += $r['limit_headers'];
            if ($round === 1) {
                file_put_contents("$samplesDir/$name.json",
                    json_encode($r['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        } catch (TelescopiusBudgetException $e) {
            $made++;
            $stopped = $e->getMessage();
            break;
        } catch (TelescopiusException $e) {
            $made++;
            $errors[$name][] = $e->getMessage();
        }
    }
}
echo "\r" . str_repeat(' ', 90) . "\r";

// ---- Cache comparison -------------------------------------------------
// Reading the saved answer back = what a cached page pays instead.
$cacheMs = [];
foreach (array_keys($runs) as $name) {
    $f = "$samplesDir/$name.json";
    if (!is_file($f)) continue;
    $best = INF;
    for ($k = 0; $k < 5; $k++) {
        $t0 = hrtime(true);
        json_decode(file_get_contents($f), true);
        $best = min($best, (hrtime(true) - $t0) / 1e6);
    }
    $cacheMs[$name] = $best;
}

// ---- Report -----------------------------------------------------------
function median(array $v): float
{
    sort($v);
    $n = count($v);
    return $n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
}

function result_count($data): string
{
    if (!is_array($data)) return '-';
    foreach (['page_results', 'results', 'items', 'events', 'lists', 'data'] as $k) {
        if (isset($data[$k]) && is_array($data[$k])) {
            $s = (string) count($data[$k]);
            if (isset($data['matched'])) $s .= ' of ' . $data['matched'];
            return $s;
        }
    }
    return array_is_list($data) ? (string) count($data) : '-';
}

$rows = [];
$report = [];
foreach ($cases as $name => [$label, $path, $params]) {
    $rs = $runs[$name] ?? [];
    if (!$rs) {
        $rows[] = [$label, 'failed', '', '', '', '', '', '', '', ''];
        continue;
    }
    $tot  = array_column($rs, 'ms');
    $up   = array_column($rs, 'upstream_ms');
    $ttfb = array_map(fn($r) => $r['timing']['ttfb_ms'], $rs);
    $tls  = array_map(fn($r) => $r['timing']['tls_ms'], $rs);
    $kb   = $rs[0]['bytes'] / 1024;
    $row = [
        $label,
        number_format(median($tot)) . ' ms',
        number_format(min($tot)),
        number_format(max($tot)),
        number_format(median($up)),
        number_format(median($ttfb)),
        number_format(median($tls)),
        number_format($kb, $kb < 10 ? 1 : 0) . ' KB',
        result_count($rs[0]['data']),
        isset($cacheMs[$name]) ? number_format($cacheMs[$name], 2) . ' ms' : '-',
    ];
    $rows[] = $row;
    $report[$name] = [
        'label' => $label, 'path' => $path, 'params' => $params,
        'median_ms' => median($tot), 'min_ms' => min($tot), 'max_ms' => max($tot),
        'median_upstream_ms' => median($up),
        'median_ttfb_ms' => median($ttfb), 'median_tls_ms' => median($tls),
        'bytes' => $rs[0]['bytes'], 'results' => result_count($rs[0]['data']),
        'cache_read_ms' => $cacheMs[$name] ?? null, 'samples' => array_column($rs, 'timing'),
        'errors' => $errors[$name] ?? [],
    ];
}

$head = ['Call', 'Typical', 'Fastest', 'Slowest', 'Telescopius', 'Waiting', 'TLS', 'Size', 'Results', 'From cache'];
$w = array_map(fn($i) => max(array_map(fn($r) => mb_strlen($r[$i]), array_merge([$head], $rows))), array_keys($head));
$line = fn($r) => implode('  ', array_map(fn($v, $i) => $i === 0 ? str_pad($v, $w[$i] + strlen($v) - mb_strlen($v)) : str_pad($v, $w[$i], ' ', STR_PAD_LEFT), $r, array_keys($r)));
echo $line($head) . "\n" . str_repeat('-', array_sum($w) + 2 * (count($w) - 1)) . "\n";
foreach ($rows as $r) echo $line($r) . "\n";

echo "\nTypical/Fastest/Slowest = total time as seen from this machine" . ($t->relay() ? ' (PC -> VPS -> Telescopius and back)' : '') . ".\n";
echo "Telescopius = median time of the call to Telescopius itself, made from the VPS -- what a live page waits.\n";
echo "Waiting = time to first byte of that call (connecting + Telescopius working).\n";
echo "TLS = how much of that was opening the secure connection (DNS + connect + TLS handshake).\n";
echo "From cache = reading the same answer from disk instead.\n";

foreach ($errors as $name => $msgs) {
    echo "\n{$cases[$name][0]}: " . count($msgs) . " failed -- " . $msgs[0] . "\n";
}
if ($stopped) echo "\nStopped early: $stopped\n";
echo "\nRate-limit headers from Telescopius: "
   . ($limitHeaders ? json_encode($limitHeaders) : 'none sent') . "\n";

$file = $t->dataDir() . '/bench_' . date('Ymd_His') . '_' . preg_replace('/[^A-Za-z0-9_-]/', '', $host) . '.json';
file_put_contents($file, json_encode([
    'host' => $host, 'at' => date('c'), 'relay' => $t->relay(), 'samples' => $samples, 'keep_alive' => $keepAlive,
    'calls_made' => $made, 'limit_headers' => $limitHeaders, 'results' => $report,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

if ($t->waited() > 0.5) {
    echo "\nWaited " . number_format($t->waited()) . "s in total for Telescopius's 60-calls-a-minute limit (not counted in the timings).\n";
}

$now = $t->callsThisMonth();
echo "\nMade $made calls. Now $now of $limit this month ($where, {$pct($now)}).\n";
echo "Timings saved to " . basename($file) . "; one sample response per call in samples\\.\n";
