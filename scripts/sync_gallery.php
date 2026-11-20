<?php
// ============================================================
// sync_gallery.php  —  Sync Folder for every DSO, from the command line
//
// Runs the same sync as the DSO Admin's Sync Folder button
// (public/admin/gallery_sync.php) for every DSO that has a Project
// (Sun/Moon left out), then lists:
//   * web fav/full files no DSO claims
//   * "forgot to publish": MyWorks _fav/_full renders missing from the
//     website or newer than the web copy (run publish_images.py), and
//     names used in more than one session
//
// The report is grouped by DSO and session folder, in plain words.
//
// SHOWS THE CHANGES, THEN ASKS. Every DSO is synced inside one database
// transaction; the report shows what that did, and then
//     Apply these changes? [y/N]
// commits it (y) or rolls it all back (anything else). The slow scan runs
// once -- answering y doesn't run it again. Thumbs are made after the
// commit. Images whose _full is gone from public/images/full are only
// removed with -d/--delete.
//
// After a yes (or when the gallery needed no changes), if the "forgot to
// publish" list isn't empty it also asks
//     Publish them now (publish_images -A -a)? [y/N]
// and runs publish_images.cmd -A -a on a yes. Newly published images join
// the gallery on the next sync.
//
//   php sync_gallery.php              show the changes, then ask
//   php sync_gallery.php -a           apply without asking (scheduled tasks; never publishes)
//   php sync_gallery.php --what-if    report only, never ask, change nothing
//                                     (-w, -n and --dry-run also work; wins over -a)
//   php sync_gallery.php -d           also remove from database images without a web _full
//   php sync_gallery.php m31 wizard   just these DSOs (one or more; -k M31 also works)
//   php sync_gallery.php -v           also list DSOs with nothing to report
//
// Without -a or --what-if it always asks. Run where nobody can answer (a
// scheduled task with no keyboard), the question gets no answer, which
// counts as no -- nothing is changed.
//
// Local only: needs C:\Astronomy\MyWorks (WORKS_ROOT in admin/config.php).
// C:\Astronomy\Apps\pythonScripts\sync_gallery.cmd runs it with Laragon's PHP.
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Command line only.\n");
}

require_once __DIR__ . '/../public/admin/config.php';
require_once __DIR__ . '/../public/admin/gallery_sync.php';

$usage = <<<TXT
Usage: php sync_gallery.php [DSO ...] [-a | -w] [-d] [-v] [-h]

  DSO ...          only these DSOs (default: every DSO with a Project). Each is a
                   DSO key or any of its catalog IDs (M42 or NGC1976), a Project
                   folder (m42_orion_nebula) or a fragment of one: an exact match
                   wins, then the folder's first token (m42), then any folder
                   containing it. -k DSO also works.
  (no -a or -w)    show the changes, then ask whether to apply them
  -a, --apply      apply without asking
  -w, --what-if    report only, never ask, change nothing (wins over -a;
                   -n/--dry-run also work)
  -d, --delete     also remove from database images whose _full is gone from the
                   website (no files are deleted)
  -v, --verbose    also list DSOs with nothing to report
  -h, --help       this help

Options can go before or after the DSOs, and short ones can be combined (-ad).

TXT;

// Parsed by hand: getopt() stops at the first DSO, so "m42 -a" would ignore -a.
$flags = [];
$targets = [];
$long = ['apply' => 'a', 'what-if' => 'w', 'dry-run' => 'n', 'delete' => 'd',
         'verbose' => 'v', 'help' => 'h', 'key' => 'k'];
$args = array_slice($argv, 1);
for ($i = 0; $i < count($args); $i++) {
    $arg = $args[$i];
    if (substr($arg, 0, 2) === '--') {
        [$name, $val] = array_pad(explode('=', substr($arg, 2), 2), 2, null);
        if (!isset($long[$name])) { fwrite(STDERR, "Unknown option: $arg\n\n$usage"); exit(2); }
        if ($long[$name] === 'k') { $targets[] = $val ?? ($args[++$i] ?? ''); continue; }
        $flags[$long[$name]] = true;
    } elseif (strlen($arg) > 1 && $arg[0] === '-') {
        foreach (str_split(substr($arg, 1)) as $j => $c) {
            if ($c === 'k') {             // -k DSO or -kDSO
                $rest = substr($arg, 2 + $j);
                $targets[] = $rest !== '' ? $rest : ($args[++$i] ?? '');
                break;
            }
            if (!in_array($c, ['a', 'w', 'n', 'd', 'v', 'h'], true)) {
                fwrite(STDERR, "Unknown option: -$c\n\n$usage"); exit(2);
            }
            $flags[$c] = true;
        }
    } else {
        $targets[] = $arg;
    }
}
if (!empty($flags['h'])) { echo $usage; exit(0); }
$apply   = !empty($flags['a']);
$dry     = !empty($flags['w']) || !empty($flags['n']);
$delete  = !empty($flags['d']);
$verbose = !empty($flags['v']);
if ($dry) $apply = false;      // --what-if wins over -a
$targets = array_values(array_filter(array_map('trim', $targets), 'strlen'));
$console = function_exists('stream_isatty') ? stream_isatty(STDIN) && stream_isatty(STDOUT) : false;
$ask     = !$apply && !$dry;   // always ask; with no keyboard the answer reads as "no"

if (!gs_works_root_available()) {
    fwrite(STDERR, "MyWorks not found (" . (defined('WORKS_ROOT') ? WORKS_ROOT : 'WORKS_ROOT undefined') . ") -- run this on the PC that has it.\n");
    exit(1);
}

$db = get_db();

/**
 * The DSO key for a target, matched as publish_images.py matches a project:
 * an exact DSO key, catalog ID (CatalogIDs: NGC1976 -> M42) or Project folder,
 * then the folder's first token (so "m1" is M1, not M101), then any folder
 * containing the target. Exits with the choices when it's ambiguous, or when
 * nothing matches.
 */
function sg_resolve(array $projects, array $aliases, string $target): string {
    $t = strtolower($target);
    $alias_key = $aliases[$t] ?? null;     // catalog ID -> DSO key
    $tiers = [
        fn($p) => strtolower($p['DSOKey']) === $t || strtolower($p['ProjectFolder']) === $t
                  || ($alias_key !== null && strcasecmp($p['DSOKey'], $alias_key) === 0),
        fn($p) => strtolower(explode('_', $p['ProjectFolder'])[0]) === $t,
        fn($p) => strpos(strtolower($p['ProjectFolder']), $t) !== false,
    ];
    foreach ($tiers as $match) {
        $hits = array_values(array_filter($projects, $match));
        $keys = array_values(array_unique(array_map(fn($p) => $p['DSOKey'], $hits)));
        if (count($keys) === 1) return $keys[0];
        if (count($keys) > 1) {
            fwrite(STDERR, "\"$target\" matches more than one DSO -- be more specific:\n");
            foreach ($hits as $p) fwrite(STDERR, "  {$p['DSOKey']}  (folder {$p['ProjectFolder']})\n");
            exit(2);
        }
    }
    fwrite(STDERR, "No DSO with a Project matches \"$target\".\n");
    exit(2);
}

$only_keys = [];
if ($targets) {
    $projects = $db->query('SELECT DSOKey AS "DSOKey", ProjectFolder AS "ProjectFolder" FROM Projects')
                   ->fetchAll(PDO::FETCH_ASSOC);
    $projects = array_values(array_filter($projects, fn($p) => $p['DSOKey'] && $p['ProjectFolder']));
    $aliases = [];      // lower-case catalog ID (and spacing-free form) -> DSO key
    foreach ($db->query('SELECT DSOKey AS "DSOKey", CatalogID AS "CatalogID" FROM CatalogIDs')
                ->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (!$c['CatalogID'] || !$c['DSOKey']) continue;
        $aliases[strtolower($c['CatalogID'])] = $c['DSOKey'];
        $aliases[strtolower(str_replace(' ', '', $c['CatalogID']))] = $c['DSOKey'];
    }
    foreach ($targets as $t) {
        $k = sg_resolve($projects, $aliases, $t);
        if (!in_array($k, $only_keys, true)) $only_keys[] = $k;
    }
}

echo ($apply ? "Syncing" : ($ask ? "Checking" : "What-if --")) . " gallery images"
   . ($only_keys ? " for " . implode(', ', $only_keys) : " for every DSO") . "\n\n";

// One transaction around every DSO (each runs in its own savepoint), so the
// report is what committing would do, and committing needs no second run.
$progress = $console ? function ($key) { fwrite(STDERR, "\r  checking $key ...        "); } : null;
$db->beginTransaction();
try {
    if (!$only_keys) {
        $all = gallery_sync_all($db, ['apply' => true, 'delete' => $delete], $progress);
    } else {
        // One DSO at a time (gallery_sync_all takes one), merged into one report.
        $all = ['results' => [], 'unclaimed' => [], 'unpublished' => null];
        $seen_dups = [];
        foreach ($only_keys as $k) {
            $one = gallery_sync_all($db, ['apply' => true, 'delete' => $delete, 'only' => $k], $progress);
            $all['results'] += $one['results'];
            if ($one['unpublished'] !== null) {
                $all['unpublished'] ??= ['missing' => [], 'outdated' => [], 'duplicates' => []];
                $all['unpublished']['missing']  = array_merge($all['unpublished']['missing'], $one['unpublished']['missing']);
                $all['unpublished']['outdated'] = array_merge($all['unpublished']['outdated'], $one['unpublished']['outdated']);
                foreach ($one['unpublished']['duplicates'] as $d) {
                    if (isset($seen_dups[strtolower($d['File'])])) continue;
                    $seen_dups[strtolower($d['File'])] = true;
                    $all['unpublished']['duplicates'][] = $d;
                }
            }
        }
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    if ($progress) fwrite(STDERR, "\r" . str_repeat(' ', 40) . "\r");
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
if ($progress) fwrite(STDERR, "\r" . str_repeat(' ', 40) . "\r");

$tot = ['inserted' => 0, 'updated' => 0, 'deleted' => 0, 'toDelete' => 0, 'notes' => 0,
        'errors' => 0, 'quiet' => 0, 'changed' => 0];
$mark = ['add' => '+', 'update' => '~', 'delete' => '-', 'warn' => '!', 'info' => '*'];

foreach ($all['results'] as $key => $r) {
    if (empty($r['success'])) {
        echo "$key\n  ERROR: {$r['error']}\n\n";
        $tot['errors']++;
        continue;
    }
    $tot['inserted'] += count($r['inserted']);
    $tot['updated']  += count($r['updated']);
    $tot['deleted']  += count($r['deleted']);
    $tot['toDelete'] += count($r['toDelete']);
    $tot['changed']  += $r['changed'] ? 1 : 0;

    if (!$r['report']) {
        $tot['quiet']++;
        if ($verbose) echo "$key  {$r['commonName']}: up to date\n";
        continue;
    }
    echo "$key  {$r['commonName']}   (folder {$r['projectFolder']})\n";
    $by_session = [];
    foreach ($r['report'] as $line) {
        if ($line['session'] === null) {
            echo "  {$mark[$line['kind']]} {$line['text']}\n";
            $tot['notes'] += $line['kind'] === 'warn' ? 1 : 0;
        } else {
            $by_session[$line['session']][] = $line;
        }
    }
    ksort($by_session);
    foreach ($by_session as $session => $lines) {
        echo "  Session $session\n";
        foreach ($lines as $line) {
            echo "    {$mark[$line['kind']]} {$line['text']}\n";
            $tot['notes'] += $line['kind'] === 'warn' ? 1 : 0;
        }
    }
    echo "\n";
}

if ($all['unclaimed']) {
    echo "On the website but not in the gallery:\n";
    foreach ($all['unclaimed'] as $u) echo "  ! {$u['message']}\n";
    echo "\n";
}

$up = $all['unpublished'];
if ($up && ($up['missing'] || $up['outdated'] || $up['duplicates'])) {
    echo "Forgot to publish? (run publish_images.py)\n";
    foreach ($up['missing'] as $m) {
        echo "  {$m['Project']}\\{$m['SessionDir']}\\{$m['File']}: not on the website" . (isset($m['Note']) ? " ({$m['Note']})" : '') . "\n";
    }
    foreach ($up['outdated'] as $m) {
        echo "  {$m['Project']}\\{$m['SessionDir']}\\{$m['File']}: newer than the copy on the website" . (isset($m['Note']) ? " ({$m['Note']})" : '') . "\n";
    }
    foreach ($up['duplicates'] as $d) {
        echo "  {$d['File']}: the same name is in more than one session, so it isn't published -- rename one:\n";
        foreach ($d['Sessions'] as $s) echo "      $s\n";
    }
    echo "\n";
}

$n = count($all['results']);
$verb = $apply ? '' : ($ask ? 'to be ' : 'would be ');
echo "$n DSOs checked, {$tot['quiet']} already up to date. ";
echo "{$tot['inserted']} image(s) {$verb}added, {$tot['updated']} {$verb}updated";
echo $delete ? ", {$tot['deleted']} {$verb}removed" : '';
echo ".\n";
if (!$delete && $tot['toDelete']) {
    echo "{$tot['toDelete']} image(s) no longer have a _full on the website; add -d to remove them from the database (no files are deleted).\n";
}
if ($tot['errors']) echo "{$tot['errors']} DSO(s) couldn't be synced (see ERROR above); nothing is changed for them.\n";

const SG_PUBLISH_CMD = 'C:\\Astronomy\\Apps\\pythonScripts\\publish_images.cmd';

/** Commit, then make the thumbs for every DSO that was synced. */
function sg_commit(PDO $db, array $results): void {
    $db->commit();
    foreach ($results as $key => $r) {
        if (empty($r['success'])) continue;
        try {
            gallery_thumbs_sync($db, $key);
        } catch (Throwable $e) {
            echo "  ! $key: thumbs not updated: {$e->getMessage()}\n";
        }
    }
}

function sg_yes(string $question): bool {
    echo "\n$question [y/N] ";
    $answer = strtolower(trim((string)fgets(STDIN)));
    return $answer === 'y' || $answer === 'yes';
}

/** Offer to run publish_images -A -a when MyWorks has unpublished renders. */
function sg_offer_publish(?array $up): void {
    $count = $up ? count($up['missing']) + count($up['outdated']) : 0;
    if (!$count) return;
    if (!is_file(SG_PUBLISH_CMD)) {
        echo "\n$count render(s) need publishing, but " . SG_PUBLISH_CMD . " wasn't found -- run publish_images yourself.\n";
        return;
    }
    if (!sg_yes("$count render(s) in MyWorks aren't on the website or are newer than it. Publish them now (publish_images -A -a)?")) return;
    echo "\n";
    passthru(SG_PUBLISH_CMD . ' -A -a', $code);
    if ($code !== 0) {
        echo "\npublish_images ended with an error (code $code).\n";
    } else {
        echo "\nPublished. Run sync_gallery again to add any new images to the gallery.\n";
    }
}

if (!$tot['changed']) {
    $db->rollBack();
    echo "Nothing to change in the gallery.\n";
    if ($ask) sg_offer_publish($up);
    exit($tot['errors'] ? 1 : 0);
}

if ($apply) {
    sg_commit($db, $all['results']);
    echo "Changes applied.\n";
} elseif ($ask) {
    if (sg_yes('Apply these changes?')) {
        sg_commit($db, $all['results']);
        echo "Changes applied.\n";
        sg_offer_publish($up);
    } else {
        $db->rollBack();
        echo "Nothing was changed.\n";
    }
} else {
    $db->rollBack();
    echo "What-if only -- nothing was changed.\n";
}
exit($tot['errors'] ? 1 : 0);
