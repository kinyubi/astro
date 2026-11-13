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
// DRY RUN BY DEFAULT: everything runs inside a transaction that is rolled
// back, so the report is exactly what -a would do. Images whose _full is
// gone from public/images/full are only removed with -d/--delete.
//
//   php sync_gallery.php              report only
//   php sync_gallery.php -a           apply (images without a _full are still only listed)
//   php sync_gallery.php -a -d        apply and remove those images from the gallery
//   php sync_gallery.php -k M31       just one DSO
//   php sync_gallery.php -v           also list DSOs with nothing to report
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
Usage: php sync_gallery.php [-a] [-d] [-k DSOKEY] [-v] [-h]

  -a, --apply      make the changes (default is a dry run)
  -d, --delete     also remove images whose _full is gone from the website
  -k, --key KEY    only this DSO
  -v, --verbose    also list DSOs with nothing to report
  -h, --help       this help

TXT;

$o = getopt('adk:vh', ['apply', 'delete', 'key:', 'verbose', 'help'], $rest);
if (isset($o['h']) || isset($o['help'])) { echo $usage; exit(0); }
if ($rest < count($argv)) {
    fwrite(STDERR, "Unexpected argument: {$argv[$rest]}\n\n$usage");
    exit(2);
}
$apply   = isset($o['a']) || isset($o['apply']);
$delete  = isset($o['d']) || isset($o['delete']);
$only    = $o['k'] ?? $o['key'] ?? null;
$verbose = isset($o['v']) || isset($o['verbose']);

if (!gs_works_root_available()) {
    fwrite(STDERR, "MyWorks not found (" . (defined('WORKS_ROOT') ? WORKS_ROOT : 'WORKS_ROOT undefined') . ") -- run this on the PC that has it.\n");
    exit(1);
}

$db = get_db();
echo ($apply ? "Syncing" : "Dry run --") . " gallery images" . ($only ? " for $only" : " for every DSO") . "\n\n";

try {
    $all = gallery_sync_all($db, ['apply' => $apply, 'delete' => $delete, 'only' => $only]);
} catch (GallerySyncError $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$tot = ['inserted' => 0, 'updated' => 0, 'deleted' => 0, 'toDelete' => 0, 'notes' => 0,
        'errors' => 0, 'quiet' => 0];
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
$would = $apply ? '' : 'would be ';
echo "$n DSOs checked, {$tot['quiet']} already up to date. ";
echo "{$tot['inserted']} image(s) {$would}added, {$tot['updated']} {$would}updated";
echo $delete ? ", {$tot['deleted']} {$would}removed" : '';
echo ".\n";
if (!$delete && $tot['toDelete']) {
    echo "{$tot['toDelete']} image(s) no longer have a _full on the website; add -d to remove them from the gallery.\n";
}
if ($tot['errors']) echo "{$tot['errors']} DSO(s) couldn't be synced (see ERROR above).\n";
if (!$apply) echo "This was a dry run -- nothing was changed. Run again with -a to make these changes.\n";
