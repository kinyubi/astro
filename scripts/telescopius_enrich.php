<?php
// ============================================================
// telescopius_enrich.php  —  Fetch Telescopius data for our DSOs
//
// Looks each DSO up on Telescopius once and saves what it knows (names,
// every catalog ID, types, position, magnitude, surface brightness, size,
// popularity, links) in the TelescopiusObjects table. Then compares it with
// the hand-kept Objects row and proposes:
//   * FILLS -- blank Objects fields Telescopius can fill: Common Name,
//     Constellation, RA / Dec, Magnitude, SqArcMins
//   * REPLACEMENTS -- numbered, for values that differ noticeably from
//     Telescopius (Position by > 1', Magnitude by >= 0.3, SqArcMins by
//     > 15%, Constellation). Common Name is never replaced.
// SqArcMins from Telescopius = major x minor axis in arcminutes (a single
// axis is squared), the same rule as migrate_add_sqarcmins.py.
// A SqArcMins change comes with ObjectSize rewritten to match, as one
// choice, in the house style ("~2.5 light-years across; ~10 arcminutes--
// ~0.3x the full Moon's diameter."): angular size from Telescopius, Moon
// = 31', light-years = DistanceLY x angular size (or the old text's
// light-years when DistanceLY isn't a usable number).
//
// SHOWS THE PROPOSALS, THEN ASKS:
//     Save the Telescopius data and fill the blank fields? [y/N]
//     Which replacements? Numbers (1,3,5-7), "all", or Enter for none:
// Everything is written in one transaction.
//
// AFTERWARDS: any DSO whose RA/Dec was filled or replaced gets its
// visibility windows (VisibilityWindows, the /vis/span/ page) worked out
// again by pythonscripts/visibility_windows.py, run with the astro
// project's venv Python (pythonscripts\venv\Scripts\python.exe).
//
//   php telescopius_enrich.php               every DSO with no Telescopius data yet
//   php telescopius_enrich.php m33 ngc7380   just these (DSO key or any catalog ID);
//                                            already-fetched ones are re-compared
//                                            from saved data -- no Telescopius call
//   php telescopius_enrich.php -r            look everything up again (also retries
//                                            DSOs saved as not found)
//   php telescopius_enrich.php -a            save + fill without asking (scheduled
//                                            tasks); replacements only with -o too
//   php telescopius_enrich.php --what-if     report only, never ask, change nothing
//                                            (-w, -n and --dry-run also work; wins over -a)
//
// Skipped: the Sun/Moon/planets (SOLAR_SYSTEM), comets (they move), and
// stars (SqArcMins 0). Telescopius answers are cached on the VPS for 30
// days, so answering "no" and running again costs no extra calls (except
// with -r). About 1 call per DSO, 2-4 for ones that don't match at first.
//
// Needs: the TelescopiusObjects table (dsodb/migrations/
// migrate_add_telescopius_objects.py), the Postgres tunnel, and on the PC
// the Telescopius relay (.env.local).
// C:\Astronomy\Apps\pythonScripts\telescopius_enrich.cmd runs it with Laragon's PHP.
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Command line only.\n");
}

require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/telescopius.php';

const CACHE_TTL = 30 * 86400;

$usage = <<<TXT
Usage: php telescopius_enrich.php [DSO ...] [-r] [-a [-o] | -w] [-h]

  DSO ...          only these DSOs (default: every DSO with no Telescopius data
                   yet). DSO key or any of its catalog IDs (M33 or NGC598).
  -r, --refresh    look DSOs up on Telescopius again even if already saved
                   (also retries ones saved as not found)
  (no -a or -w)    show the proposals, then ask what to apply
  -a, --apply      save the Telescopius data and fill blanks without asking
  -o, --overwrite  with -a: also make every proposed replacement
  -w, --what-if    report only, never ask, change nothing (wins over -a;
                   -n/--dry-run also work)
  -h, --help       this help

Options can go before or after the DSOs, and short ones can be combined (-ao).

TXT;

// ---- Options (parsed by hand, same as sync_gallery.php) ----------------
$flags = [];
$named = [];
$long = ['apply' => 'a', 'what-if' => 'w', 'dry-run' => 'n', 'refresh' => 'r',
         'overwrite' => 'o', 'help' => 'h'];
foreach (array_slice($argv, 1) as $arg) {
    if (substr($arg, 0, 2) === '--') {
        $name = substr($arg, 2);
        if (!isset($long[$name])) { fwrite(STDERR, "Unknown option: $arg\n\n$usage"); exit(2); }
        $flags[$long[$name]] = true;
    } elseif (strlen($arg) > 1 && $arg[0] === '-') {
        foreach (str_split(substr($arg, 1)) as $c) {
            if (!in_array($c, ['a', 'w', 'n', 'r', 'o', 'h'], true)) {
                fwrite(STDERR, "Unknown option: -$c\n\n$usage"); exit(2);
            }
            $flags[$c] = true;
        }
    } else {
        $named[] = $arg;
    }
}
if (!empty($flags['h'])) { echo $usage; exit(0); }
$dry       = !empty($flags['w']) || !empty($flags['n']);
$apply     = !empty($flags['a']) && !$dry;
$overwrite = !empty($flags['o']);
$refresh   = !empty($flags['r']);
$ask       = !$apply && !$dry;

// ---- Database ----------------------------------------------------------
try {
    $db = get_db();
} catch (Exception $e) {
    fwrite(STDERR, "Can't open the database: " . $e->getMessage() . "\n(Is the tunnel up?)\n");
    exit(1);
}
if (db_driver() !== 'pgsql') {
    fwrite(STDERR, "This script needs the Postgres database (db_config.json driver is '" . db_driver() . "').\n");
    exit(1);
}
if ($db->query("SELECT to_regclass('public.telescopiusobjects')")->fetchColumn() === null) {
    fwrite(STDERR, "No TelescopiusObjects table yet -- run dsodb/migrations/migrate_add_telescopius_objects.py -a first.\n");
    exit(1);
}

$objCols = $db->query("SELECT column_name FROM information_schema.columns
                       WHERE table_schema = 'public' AND table_name = 'objects'")->fetchAll(PDO::FETCH_COLUMN);
$missing = array_diff(['dsokey', 'commonname', 'objecttypeid', 'constellationid', 'rahours', 'decdegrees', 'magnitude', 'sqarcmins'], $objCols);
if ($missing) {
    fwrite(STDERR, 'The objects table has no ' . implode(', ', $missing) . " column -- not what this script expects.\n");
    exit(1);
}
$touch = in_array('lastupdated', $objCols, true) ? ', lastupdated = CURRENT_TIMESTAMP' : '';
$hasSize = in_array('objectsize', $objCols, true) && in_array('distancely', $objCols, true);
$sizeCols = $hasSize ? 'o.objectsize, o.distancely,' : '';

$objects = [];
foreach ($db->query("
    SELECT o.dsokey, o.commonname, o.objecttypeid, o.constellationid,
           o.rahours, o.decdegrees, o.magnitude, o.sqarcmins, $sizeCols
           t.matchstatus, t.raw, t.telescopiusid, t.matchedby
    FROM objects o
    LEFT JOIN telescopiusobjects t ON t.dsokey = o.dsokey
    ORDER BY o.dsokey
") as $row) {
    $objects[$row['dsokey']] = $row;
}
$catalog = [];   // dsokey => [catalog IDs], primary first
foreach ($db->query("SELECT dsokey, catalogid FROM catalogids ORDER BY isprimary DESC, catalogid") as $row) {
    $catalog[$row['dsokey']][] = $row['catalogid'];
}
$constellations = [];
foreach ($db->query("SELECT constellationid, name FROM constellations") as $row) {
    $constellations[strtoupper($row['constellationid'])] = [$row['constellationid'], $row['name']];
}

// ---- Which DSOs --------------------------------------------------------
$byKey = [];
foreach ($objects as $k => $_) $byKey[strtoupper($k)] = $k;
foreach ($catalog as $k => $ids) foreach ($ids as $id) $byKey[strtoupper(str_replace(' ', '', $id))] ??= $k;

$targets = [];
$unknown = [];
foreach ($named as $n) {
    $k = $byKey[strtoupper(str_replace(' ', '', $n))] ?? null;
    if ($k === null) $unknown[] = $n; else $targets[$k] = true;
}
if ($unknown) echo 'Not in the database: ' . implode(', ', $unknown) . "\n\n";
if ($named && !$targets) exit(1);

function skip_reason(array $o): ?string
{
    if (strtoupper((string) $o['objecttypeid']) === 'SOLAR_SYSTEM') return 'solar system object';
    if (preg_match('/^C\d{4}/i', $o['dsokey']) || stripos((string) $o['objecttypeid'], 'COMET') !== false) return 'comet';
    if ($o['sqarcmins'] !== null && (float) $o['sqarcmins'] == 0.0) return 'star (SqArcMins 0)';
    return null;
}

$skipped = [];
$work = [];      // dsokey => 'fetch' | 'stored'
foreach ($objects as $k => $o) {
    if ($named && !isset($targets[$k])) continue;
    if (($why = skip_reason($o)) !== null) {
        if ($named) $skipped[] = "$k ($why)";
        continue;
    }
    $have = $o['matchstatus'] !== null;
    if ($refresh || !$have) {
        $work[$k] = 'fetch';
    } elseif ($named && $o['matchstatus'] === 'matched') {
        $work[$k] = 'stored';           // re-compare from saved data, no call
    } elseif ($named) {
        $work[$k] = 'fetch';            // named + saved as not found: try again
    }
}
if ($skipped) echo 'Skipped: ' . implode(', ', $skipped) . "\n\n";
if (!$work) {
    echo $named ? "Nothing to look up.\n" : "Every DSO already has Telescopius data (use -r to look them up again).\n";
    exit(0);
}

// ---- Look them up ------------------------------------------------------
function norm(string $id): string
{
    return strtoupper(str_replace(' ', '', $id));
}

/** NGC7380 -> NGC 7380, M33 -> M 33; hyphenated keys (SH2-129) stay as they are. */
function spaced(string $key): string
{
    $key = strtoupper(trim($key));
    if (str_contains($key, '-') || str_contains($key, ' ')) return $key;
    return preg_replace('/^([A-Z]+)(\d.*)$/', '$1 $2', $key);
}

/** The result whose IDs include one of ours, or null. */
function pick(array $results, array $ours): ?array
{
    foreach ($results as $r) {
        $o = $r['object'] ?? [];
        $ids = array_merge([$o['main_id'] ?? ''], $o['ids'] ?? [], $o['alt_ids'] ?? []);
        if (array_intersect(array_map('norm', $ids), $ours)) return $o;
    }
    return null;
}

$fetchCount = count(array_filter($work, fn($w) => $w === 'fetch'));
$t = null;
if ($fetchCount) {
    try {
        $t = new Telescopius();
    } catch (TelescopiusException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
    echo "Looking up $fetchCount DSO" . ($fetchCount === 1 ? '' : 's') . " on Telescopius"
       . ($refresh ? ' (fresh, not from cache)' : '') . "...\n";
}

$found = [];      // dsokey => ['object' => [...], 'by' => name, 'fresh' => bool]
$notFound = [];   // dsokey => [names tried]
$failed = [];     // dsokey => message
$calls = 0;
$cacheHits = 0;
$stoppedBy = null;
$i = 0;
foreach ($work as $k => $how) {
    $o = $objects[$k];
    if ($how === 'stored') {
        $found[$k] = ['object' => json_decode($o['raw'], true), 'by' => $o['matchedby'], 'fresh' => false];
        continue;
    }
    if ($stoppedBy !== null) { $failed[$k] = 'not looked up (stopped early)'; continue; }
    $i++;
    echo "\r" . str_pad("  $i of $fetchCount: $k", 60);

    $ours = array_unique(array_map('norm', array_merge([$k], $catalog[$k] ?? [])));
    $tries = array_values(array_unique(array_map('spaced', array_merge([$k], $catalog[$k] ?? []))));
    $tried = [];
    try {
        // Exact name for the key and each catalog ID, then one loose search.
        $plan = array_map(fn($n) => [$n, true], $tries);
        $plan[] = [spaced($k), false];
        foreach ($plan as [$name, $exact]) {
            $tried[] = $exact ? $name : "$name (loose)";
            $r = $t->get('/targets/search', Telescopius::site([
                'types' => 'DEEP_SKY_OBJECT', 'name' => $name,
                'name_exact' => $exact ? 'true' : 'false', 'results_per_page' => $exact ? 5 : 10,
            ]), $refresh ? 0 : CACHE_TTL);
            if ($r['cached']) $cacheHits++; else $calls++;
            $hit = pick($r['data']['page_results'] ?? [], $ours);
            if ($hit) {
                $found[$k] = ['object' => $hit, 'by' => $name, 'fresh' => true, 'loose' => !$exact];
                continue 2;
            }
        }
        $notFound[$k] = $tried;
    } catch (TelescopiusBudgetException $e) {
        $stoppedBy = $e->getMessage();
        $failed[$k] = 'not looked up (stopped early)';
    } catch (TelescopiusException $e) {
        $failed[$k] = $e->getMessage();
    }
}
if ($fetchCount) echo "\r" . str_repeat(' ', 60) . "\r";

// ---- Compare with Objects ----------------------------------------------
function fmt_ra(?float $h): string
{
    if ($h === null) return 'blank';
    $s = (int) round($h * 3600);
    return sprintf('%dh%02dm%02ds', intdiv($s, 3600) % 24, intdiv($s % 3600, 60), $s % 60);
}
function fmt_dec(?float $d): string
{
    if ($d === null) return 'blank';
    $m = (int) round(abs($d) * 60);
    return sprintf('%s%d°%02d′', $d < 0 ? '-' : '+', intdiv($m, 60), $m % 60);
}
function fmt_num(?float $v, int $dec = 1): string
{
    return $v === null ? 'blank' : number_format($v, $dec);
}
function num($v): ?float
{
    return $v === null || $v === '' ? null : (float) $v;
}

/** "2.5", "10", "0.3" -- one decimal below 10, whole numbers above. */
function fmt_size(float $v): string
{
    return $v < 10 ? rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.') : number_format(round($v));
}

/** Light-years from DistanceLY text ("~1,300 light-years", "2.73 million", "5,000–6,000"); null if unusable. */
function parse_distance(?string $text): ?array
{
    if ($text === null || trim($text) === '') return null;
    $t = str_replace(',', '', strtolower($text));
    if (!preg_match_all('/\d+(?:\.\d+)?/', $t, $m) || count($m[0]) > 2) return null;
    $mult = str_contains($t, 'billion') ? 1e9 : (str_contains($t, 'million') ? 1e6 : (str_contains($t, 'thousand') ? 1e3 : 1));
    $nums = array_map(fn($x) => (float) $x * $mult, $m[0]);
    $d = array_sum($nums) / count($nums);
    return $d > 0 ? [$d, count($nums) === 2] : null;
}

/**
 * New ObjectSize text in the house style, e.g.
 *   "~2.5 light-years across; ~10 arcminutes—~0.3× the full Moon's diameter."
 * Angular size and Moon comparison come from Telescopius (Moon = 31′);
 * light-years = DistanceLY x the new angular size, or the old text's
 * light-years phrase when DistanceLY isn't a usable number.
 * Returns [text, note-or-null].
 */
function size_text(string $old, ?string $distanceText, float $majArcsec, float $minArcsec): array
{
    $a = $majArcsec / 60;
    $b = $minArcsec / 60;
    if ($a >= 120) {
        $angular = abs($a - $b) < 6 ? '~' . fmt_size($a / 60) . '°' : '~' . fmt_size($a / 60) . '°×' . fmt_size($b / 60) . '°';
    } else {
        $angular = abs($a - $b) < 0.5 ? '~' . fmt_size($a) . ' arcminutes' : '~' . fmt_size($a) . '×' . fmt_size($b) . ' arcminutes';
    }
    $moon = '~' . fmt_size($a / 31) . "× the full Moon's diameter";

    $note = null;
    $ly = null;
    $dist = parse_distance($distanceText);
    if ($dist !== null) {
        $lyVal = $dist[0] * deg2rad($a / 60);
        $ly = '~' . ($lyVal < 10 ? fmt_size($lyVal) : number_format(round($lyVal, -(int) floor(log10($lyVal)) + 1))) . ' light-years across';
        $note = 'light-years from DistanceLY "' . trim($distanceText) . '"' . ($dist[1] ? ' (middle of the range)' : '');
    } elseif (preg_match('/~?[\d.,]+(?:\s*(?:–|-|to)\s*[\d.,]+)?\s*(?:thousand |million )?light-years?(?: across)?/iu', $old, $m)) {
        $ly = $m[0];
        $note = 'light-years kept from the old text -- DistanceLY isn\'t a single number';
    } elseif ($old !== '') {
        $note = 'no light-years: DistanceLY isn\'t a usable number';
    }
    return [($ly ? "$ly; " : '') . "{$angular}—{$moon}.", $note];
}

$fills = [];         // list of [dsokey, [col => value], text]
$replacements = [];  // numbered list of [dsokey, [col => value], text]
$lines = [];         // dsokey => report lines

foreach ($found as $k => $f) {
    $o = $objects[$k];
    $t_ = $f['object'];
    $out = [];

    $tName = $t_['main_name'] ?? null;
    $tRa   = num($t_['ra'] ?? null);
    $tDec  = num($t_['dec'] ?? null);
    $tMag  = num($t_['visual_mag'] ?? null);
    $maj   = num($t_['major_axis'] ?? null);
    $min   = num($t_['minor_axis'] ?? null) ?? $maj;
    $tSq   = $maj !== null ? round(($maj / 60) * ($min / 60), 2) : null;
    $tCon  = isset($t_['con']) ? ($constellations[strtoupper($t_['con'])] ?? null) : null;

    $ra = num($o['rahours']); $dec = num($o['decdegrees']);
    $mag = num($o['magnitude']); $sq = num($o['sqarcmins']);

    // Common Name: fill only
    if (trim((string) $o['commonname']) === '' && $tName && norm($tName) !== norm($t_['main_id'] ?? '')) {
        $fills[] = [$k, ['commonname' => $tName], "Common Name     blank -> $tName"];
        $out[] = "    fill      Common Name     blank -> $tName";
    }
    // Constellation
    if ($tCon) {
        if ($o['constellationid'] === null || $o['constellationid'] === '') {
            $fills[] = [$k, ['constellationid' => $tCon[0]], "Constellation   blank -> {$tCon[0]} ({$tCon[1]})"];
            $out[] = "    fill      Constellation   blank -> {$tCon[0]} ({$tCon[1]})";
        } elseif (strtoupper($o['constellationid']) !== strtoupper($tCon[0])) {
            $n = count($replacements) + 1;
            $replacements[] = [$k, ['constellationid' => $tCon[0]], "Constellation   {$o['constellationid']} -> {$tCon[0]} ({$tCon[1]})"];
            $out[] = sprintf('  %-4s replace  Constellation   %s -> %s (%s)', "[$n]", $o['constellationid'], $tCon[0], $tCon[1]);
        }
    }
    // Position (RA + Dec together)
    if ($tRa !== null && $tDec !== null) {
        $to = fmt_ra($tRa) . ' / ' . fmt_dec($tDec);
        if ($ra === null && $dec === null) {
            $fills[] = [$k, ['rahours' => $tRa, 'decdegrees' => $tDec], "RA / Dec        blank -> $to"];
            $out[] = "    fill      RA / Dec        blank -> $to";
        } else {
            $dRa  = ($ra === null ? 999 : ($tRa - $ra) * 15 * cos(deg2rad($tDec)));
            $dDec = ($dec === null ? 999 : $tDec - $dec);
            $sepArcmin = sqrt($dRa ** 2 + $dDec ** 2) * 60;
            if ($sepArcmin > 1) {
                $from = fmt_ra($ra) . ' / ' . fmt_dec($dec);
                $away = ($ra === null || $dec === null) ? 'one was blank'
                      : ($sepArcmin >= 120 ? number_format($sepArcmin / 60) . '° apart'
                      : number_format($sepArcmin, $sepArcmin < 10 ? 1 : 0) . '′ apart');
                $n = count($replacements) + 1;
                $replacements[] = [$k, ['rahours' => $tRa, 'decdegrees' => $tDec], "RA / Dec        $from -> $to"];
                $out[] = sprintf('  %-4s replace  RA / Dec        %s -> %s  (%s)', "[$n]", $from, $to, $away);
            }
        }
    }
    // Magnitude
    if ($tMag !== null) {
        if ($mag === null) {
            $fills[] = [$k, ['magnitude' => $tMag], 'Magnitude       blank -> ' . fmt_num($tMag)];
            $out[] = '    fill      Magnitude       blank -> ' . fmt_num($tMag);
        } elseif (abs($mag - $tMag) >= 0.3) {
            $n = count($replacements) + 1;
            $replacements[] = [$k, ['magnitude' => $tMag], 'Magnitude       ' . fmt_num($mag) . ' -> ' . fmt_num($tMag)];
            $out[] = sprintf('  %-4s replace  Magnitude       %s -> %s', "[$n]", fmt_num($mag), fmt_num($tMag));
        }
    }
    // SqArcMins -- with ObjectSize rewritten to match, as one choice
    if ($tSq !== null && $tSq > 0) {
        $dims = number_format($maj / 60, $maj < 600 ? 1 : 0) . "′ x " . number_format($min / 60, $min < 600 ? 1 : 0) . "′";
        $oldSize = $hasSize ? trim((string) $o['objectsize']) : '';
        [$newSize, $sizeNote] = $hasSize ? size_text($oldSize, $o['distancely'], $maj, $min) : [null, null];
        $sizeLine = fn($from) => "                ObjectSize      " . ($from === '' ? 'blank' : $from) . "\n"
                               . "                             -> $newSize" . ($sizeNote ? "\n                                ($sizeNote)" : '');
        if ($sq === null) {
            $cols = ['sqarcmins' => $tSq];
            $text = 'SqArcMins       blank -> ' . number_format($tSq) . " ($dims)";
            $line = '    fill      SqArcMins       blank -> ' . number_format($tSq) . "  ($dims)";
            if ($newSize !== null && $oldSize === '') {
                $cols['objectsize'] = $newSize;
                $text .= "; ObjectSize -> $newSize";
                $line .= "\n" . $sizeLine('');
            }
            $fills[] = [$k, $cols, $text];
            $out[] = $line;
            if ($newSize !== null && $oldSize !== '' && $oldSize !== $newSize) {
                $n = count($replacements) + 1;
                $replacements[] = [$k, ['objectsize' => $newSize], "ObjectSize      $oldSize -> $newSize"];
                $out[] = sprintf('  %-4s replace  ObjectSize to match', "[$n]") . "\n" . $sizeLine($oldSize);
            }
        } elseif ($sq > 0 && max($sq, $tSq) / min($sq, $tSq) > 1.15) {
            $n = count($replacements) + 1;
            $cols = ['sqarcmins' => $tSq];
            $text = 'SqArcMins       ' . number_format($sq) . ' -> ' . number_format($tSq);
            $line = sprintf('  %-4s replace  SqArcMins       %s -> %s  (%s)', "[$n]", number_format($sq), number_format($tSq), $dims);
            if ($newSize !== null && $oldSize !== $newSize) {
                $cols['objectsize'] = $newSize;
                $text .= "; ObjectSize -> $newSize";
                $line .= "\n" . $sizeLine($oldSize);
            }
            $replacements[] = [$k, $cols, $text];
            $out[] = $line;
        }
    }

    $title = trim($k . ' ' . ($o['commonname'] ?? ''));
    $how = $f['fresh'] ? 'matched "' . $f['by'] . '"' : 'saved data (matched "' . $f['by'] . '")';
    if (!empty($f['loose'])) $how .= ' by a loose search -- worth a check';
    elseif (norm($f['by']) !== norm(spaced($k)) && $f['fresh']) $how .= ' via catalog ID';
    $label = ($t_['main_name'] ?? '') !== '' ? " -- {$t_['main_name']}" : '';
    if ($out || $f['fresh']) {
        $lines[$k] = $out ? array_merge(["$title: $how$label"], $out) : ["$title: $how$label -- agrees with Objects"];
    }
}

// ---- Report ------------------------------------------------------------
foreach ($lines as $ls) echo implode("\n", $ls) . "\n\n";
foreach ($notFound as $k => $tried) {
    $title = trim($k . ' ' . ($objects[$k]['commonname'] ?? ''));
    echo "$title: not found on Telescopius (tried " . implode(', ', $tried) . ") -- check it on telescopius.com\n";
}
foreach ($failed as $k => $msg) echo "$k: $msg\n";
if ($notFound || $failed) echo "\n";
if ($stoppedBy) echo "Stopped early: $stoppedBy\n\n";

$fresh = array_filter($found, fn($f) => $f['fresh']);
$toSave = count($fresh) + count($notFound);
if ($toSave) {
    echo "Telescopius data to save: $toSave DSO" . ($toSave === 1 ? '' : 's')
       . ($notFound ? ' (' . count($fresh) . ' matched, ' . count($notFound) . ' not found)' : '') . "\n";
}
if ($fills)        echo 'Blank fields to fill: ' . count($fills) . "\n";
if ($replacements) echo 'Replacements offered: ' . count($replacements) . "\n";
if ($t) {
    echo "Telescopius calls this run: $calls" . ($cacheHits ? " (+ $cacheHits answered from the 30-day cache)" : '') . "\n";
}
echo "\n";

if (!$toSave && !$fills && !$replacements) { echo "Nothing to change -- Objects already agrees with Telescopius.\n"; exit(0); }
if ($dry) { echo "What-if: nothing saved.\n"; exit(0); }

// ---- Decide ------------------------------------------------------------
$chosen = [];
if ($ask) {
    if ($toSave || $fills) {
        echo ($fills ? 'Save the Telescopius data and fill the blank fields?' : 'Save the Telescopius data?') . ' [y/N] ';
        $yes = in_array(strtolower(trim((string) fgets(STDIN))), ['y', 'yes'], true);
        if (!$yes) { $toSave = 0; $fresh = []; $notFound = []; $fills = []; }
    }
    if ($replacements) {
        echo 'Which replacements? Numbers (1,3,5-7), "all", or Enter for none: ';
        $answer = strtolower(trim((string) fgets(STDIN)));
        if ($answer === 'all') {
            $chosen = array_keys($replacements);
        } elseif ($answer !== '') {
            foreach (preg_split('/[\s,]+/', $answer) as $part) {
                if (preg_match('/^(\d+)-(\d+)$/', $part, $m)) {
                    foreach (range((int) $m[1], (int) $m[2]) as $n) $chosen[] = $n - 1;
                } elseif (ctype_digit($part)) {
                    $chosen[] = (int) $part - 1;
                } else {
                    echo "  ignored \"$part\"\n";
                }
            }
            $chosen = array_values(array_unique(array_filter($chosen, fn($n) => isset($replacements[$n]))));
        }
    }
} else {   // -a
    if ($overwrite) $chosen = array_keys($replacements);
}
$doReplace = array_map(fn($n) => $replacements[$n], $chosen);

if (!$fresh && !$notFound && !$fills && !$doReplace) { echo "\nNothing done.\n"; exit(0); }

// ---- Apply (one transaction) -------------------------------------------
$ins = $db->prepare("
    INSERT INTO telescopiusobjects
        (dsokey, matchstatus, matchedby, telescopiusid, mainname, names, allids, types, morphclass,
         rahours, decdegrees, constellation, visualmag, surfacebrightness, majorarcsec, minorarcsec,
         positionangle, popularity, url, thumbnailurl, imageurl, raw, fetchedat)
    VALUES
        (:dsokey, :matchstatus, :matchedby, :telescopiusid, :mainname, :names, :allids, :types, :morphclass,
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
");
$json = fn($v) => $v === null ? null : json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$allowed = ['commonname', 'constellationid', 'rahours', 'decdegrees', 'magnitude', 'sqarcmins', 'objectsize'];

try {
    $db->beginTransaction();
    foreach ($fresh as $k => $f) {
        $o = $f['object'];
        $ins->execute([
            ':dsokey' => $k, ':matchstatus' => 'matched', ':matchedby' => $f['by'],
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
    foreach ($notFound as $k => $tried) {
        $ins->execute([
            ':dsokey' => $k, ':matchstatus' => 'not_found', ':matchedby' => 'tried: ' . implode(', ', $tried),
            ':telescopiusid' => null, ':mainname' => null, ':names' => null, ':allids' => null, ':types' => null,
            ':morphclass' => null, ':rahours' => null, ':decdegrees' => null, ':constellation' => null,
            ':visualmag' => null, ':surfacebrightness' => null, ':majorarcsec' => null, ':minorarcsec' => null,
            ':positionangle' => null, ':popularity' => null, ':url' => null, ':thumbnailurl' => null,
            ':imageurl' => null, ':raw' => null,
        ]);
    }
    foreach (array_merge($fills, $doReplace) as [$k, $cols]) {
        $set = [];
        $params = [':k' => $k];
        foreach ($cols as $col => $v) {
            if (!in_array($col, $allowed, true)) continue;
            $set[] = "$col = :$col";
            $params[":$col"] = $v;
        }
        $db->prepare('UPDATE objects SET ' . implode(', ', $set) . $touch . ' WHERE dsokey = :k')
           ->execute($params);
    }
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, "\nError -- rolled back, nothing was changed: " . $e->getMessage() . "\n");
    exit(1);
}

// ---- What was done -----------------------------------------------------
echo "\n";
if ($fresh || $notFound) {
    echo 'Saved Telescopius data for ' . (count($fresh) + count($notFound)) . ' DSO'
       . (count($fresh) + count($notFound) === 1 ? '' : 's')
       . ($notFound ? ' (' . count($notFound) . ' as not found)' : '') . ".\n";
}
if ($fills) {
    echo 'Filled ' . count($fills) . ' blank field' . (count($fills) === 1 ? '' : 's') . ":\n";
    foreach ($fills as [$k, , $text]) echo "  $k  $text\n";
}
if ($doReplace) {
    echo 'Replaced ' . count($doReplace) . ' value' . (count($doReplace) === 1 ? '' : 's') . ":\n";
    foreach ($doReplace as [$k, , $text]) echo "  $k  $text\n";
}

// ---- Follow-on: visibility windows for DSOs whose position changed -----
// A filled or replaced RA/Dec changes when the DSO is visible, so its rows
// in VisibilityWindows (the /vis/span/ page) are worked out again now,
// by pythonscripts/visibility_windows.py in the astro project's own venv.
$moved = [];
foreach (array_merge($fills, $doReplace) as [$k, $cols]) {
    if (array_key_exists('rahours', $cols)) $moved[$k] = true;
}
if ($moved) {
    $moved = array_keys($moved);
    if ($db->query("SELECT to_regclass('public.visibilitywindows')")->fetchColumn() === null) {
        echo "\nNo VisibilityWindows table yet, so no visibility windows to update.\n";
    } else {
        $pyDir  = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'pythonscripts';
        $python = PHP_OS_FAMILY === 'Windows'
            ? $pyDir . '\\venv\\Scripts\\python.exe'
            : $pyDir . '/venv/bin/python';
        $script = $pyDir . DIRECTORY_SEPARATOR . 'visibility_windows.py';
        echo "\nUpdating visibility windows for " . implode(', ', $moved) . ":\n";
        if (!is_file($python) || !is_file($script)) {
            echo "  Can't: " . (!is_file($python) ? "no Python at $python" : "no script at $script") . "\n"
               . "  Run visibility_windows.py " . implode(' ', $moved) . " yourself.\n";
        } else {
            // Array form: no shell, so no quoting problems with paths or keys.
            $proc = proc_open(array_merge([$python, $script], $moved, ['-a']),
                              [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
            $rc = is_resource($proc) ? proc_close($proc) : -1;
            if ($rc !== 0) {
                echo "  That didn't finish (exit code $rc). Run visibility_windows.py " . implode(' ', $moved) . " yourself.\n";
            }
        }
    }
}
