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
//   php sync_gallery.php -n           report only, never ask, change nothing
//   php sync_gallery.php -d           also remove from database images without a web _full
//   php sync_gallery.php -k M31       just one DSO
//   php sync_gallery.php -v           also list DSOs with nothing to report
//
// Without -a or -n it always asks. Run where nobody can answer (a
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
Usage: php sync_gallery.php [-a | -n] [-d] [-k DSOKEY] [-v] [-h]

  (no -a or -n)    show the changes, then ask whether to apply them
  -a, --apply      apply without asking
  -n, --dry-run    report only, never ask, change nothing
  -d, --delete     also remove from database images whose _full is gone from the
                   website (no files are deleted)
  -k, --key KEY    only this DSO
  -v, --verbose    also list DSOs with nothing to report
  -h, --help       this help

TXT;

$o = getopt('andk:vh', ['apply', 'dry-run', 'delete', 'key:', 'verbose', 'help'], $rest);
if (isset($o['h']) || isset($o['help'])) { echo $usage; exit(0); }
if ($rest < count($argv)) {
    fwrite(STDERR, "Unexpected argument: {$argv[$rest]}\n\n$usage");
    exit(2);
}
$apply   = isset($o['a']) || isset($o['apply']);
$dry     = isset($o['n']) || isset($o['dry-run']);
$delete  = isset($o['d']) || isset($o['delete']);
$only    = $o['k'] ?? $o['key'] ?? null;
$verbose = isset($o['v']) || isset($o['verbose']);
if ($apply && $dry) {
    fwrite(STDERR, "-a and -n can't be used together.\n\n$usage");
    exit(2);
}
$console = function_exists('stream_isatty') ? stream_isatty(STDIN) && stream_isatty(STDOUT) : false;
$ask     = !$apply && !$dry;   // always ask; with no keyboard the answer reads as "no"

if (!gs_works_root_available()) {
    fwrite(STDERR, "MyWorks not found (" . (defined('WORKS_ROOT') ? WORKS_ROOT : 'WORKS_ROOT undefined') . ") -- run this on the PC that has it.\n");
    exit(1);
}

$db = get_db();
echo ($apply ? "Syncing" : ($ask ? "Checking" : "Dry run --")) . " gallery images"
   . ($only ? " for $only" : " for every DSO") . "\n\n";

// One transaction around every DSO (each runs in its own savepoint), so the
// report is what committing would do, and committing needs no second run.
$progress = $console ? function ($key) { fwrite(STDERR, "\r  checking $key ...        "); } : null;
$db->beginTransaction();
try {
    $all = gallery_sync_all($db, ['apply' => true, 'delete' => $delete, 'only' => $only], $progress);
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
    echo "Report only -- nothing was changed.\n";
}
exit($tot['errors'] ? 1 : 0);
