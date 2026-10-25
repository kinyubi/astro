<?php
require_once __DIR__ . '/../admin/auth.php';
require_once __DIR__ . '/../../shared/db.php';

$favDir  = __DIR__ . '/../images/fav';
$adminUrl = '/admin/';

$errors = [];
$favBaseNames = [];
$dbBaseNames  = [];
$dbRows       = []; // baseName => DSOKey
$dupGroups    = []; // "DSOKEY|basename_lower" => [ ['id'=>, 'baseName'=>, 'dsoKey'=>, 'hidden'=>], ... ]
$catalogToDso = []; // UPPER(CatalogID) => DSOKey, for inferring a fav file's DSO

// ── 1. Scan fav directory ─────────────────────────────────────────────────────
if (!is_dir($favDir)) {
    $errors[] = "Fav directory not found: $favDir";
} else {
    foreach (scandir($favDir) as $f) {
        if (str_ends_with($f, '_fav.jpg')) {
            $base = substr($f, 0, -strlen('_fav.jpg'));
            $favBaseNames[$base] = true;
        }
    }
}

// ── 2. Query GalleryImages ────────────────────────────────────────────────────
try {
    $db = get_db();
    $stmt = $db->query("SELECT gi.GalleryImageID AS \"GalleryImageID\", gi.BaseName AS \"BaseName\",
                               gi.DSOKey AS \"DSOKey\", o.CommonName AS \"CommonName\",
                               COALESCE(gi.IsHidden, 0) AS \"IsHidden\"
                        FROM GalleryImages gi
                        LEFT JOIN Objects o ON gi.DSOKey = o.DSOKey
                        ORDER BY gi.DSOKey, gi.BaseName, gi.GalleryImageID");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $dbBaseNames[$row['BaseName']] = true;
        $dbRows[$row['BaseName']] = [
            'dsoKey'     => $row['DSOKey'],
            'commonName' => $row['CommonName'] ?? '',
        ];
        $dupGroups[strtoupper($row['DSOKey']) . '|' . strtolower($row['BaseName'])][] = [
            'id'       => (int)$row['GalleryImageID'],
            'baseName' => $row['BaseName'],
            'dsoKey'   => $row['DSOKey'],
            'hidden'   => (int)$row['IsHidden'],
        ];
    }
    $dupGroups = array_filter($dupGroups, fn($g) => count($g) > 1);

    foreach ($db->query('SELECT CatalogID AS "CatalogID", DSOKey AS "DSOKey" FROM CatalogIDs')->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $catalogToDso[strtoupper($c['CatalogID'])] = $c['DSOKey'];
    }
    foreach ($db->query('SELECT DSOKey AS "DSOKey" FROM Objects')->fetchAll(PDO::FETCH_COLUMN) as $k) {
        $catalogToDso[strtoupper($k)] = $k;
    }
} catch (Throwable $e) {
    $errors[] = "DB error: " . $e->getMessage();
}

// A fav file's DSO is its first underscore-separated token (ngc6992_..., sh2-103_...),
// resolved through CatalogIDs in case the DSO is keyed under another catalog ID.
function infer_dso_key(string $base, array $catalogToDso): array {
    $token = strtoupper(explode('_', $base)[0]);
    return isset($catalogToDso[$token]) ? [$catalogToDso[$token], true] : [$token, false];
}

// ── 3. Compute diffs ──────────────────────────────────────────────────────────
// Fav files with no GalleryImages row
$missingFromDb = array_diff_key($favBaseNames, $dbBaseNames);
ksort($missingFromDb);

// GalleryImages rows whose fav file is absent
$missingFavFile = array_diff_key($dbBaseNames, $favBaseNames);
ksort($missingFavFile);

$allClear = empty($missingFromDb) && empty($missingFavFile) && empty($dupGroups) && empty($errors);
$totalFav = count($favBaseNames);
$totalDb  = count($dbBaseNames);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Gallery Check — Missing Entries</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --bg:      #0d1117;
    --surface: #161b22;
    --border:  #30363d;
    --accent:  #58a6ff;
    --accent2: #3fb950;
    --warn:    #d29922;
    --danger:  #f85149;
    --text:    #c9d1d9;
    --muted:   #8b949e;
    --radius:  6px;
    --font:    'Segoe UI', system-ui, sans-serif;
  }
  body {
    background: var(--bg); color: var(--text);
    font-family: var(--font); font-size: 14px; line-height: 1.6;
    min-height: 100vh; padding: 0 0 60px;
  }

  header {
    background: var(--surface); border-bottom: 1px solid var(--border);
    padding: 14px 28px; display: flex; align-items: center; gap: 14px;
    position: sticky; top: 0; z-index: 10;
  }
  header h1 { font-size: 17px; font-weight: 600; color: var(--accent); }
  header .subtitle { color: var(--muted); font-size: 13px; }
  header a.back {
    margin-left: auto; color: var(--muted); text-decoration: none; font-size: 13px;
    display: flex; align-items: center; gap: 5px;
  }
  header a.back:hover { color: var(--accent); }

  .container { max-width: 860px; margin: 0 auto; padding: 28px 24px; }

  /* Stats bar */
  .stats {
    display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 28px;
  }
  .stat-pill {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 20px; padding: 6px 16px; font-size: 13px; color: var(--muted);
  }
  .stat-pill strong { color: var(--text); }

  /* Section cards */
  .section {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 8px; margin-bottom: 24px; overflow: hidden;
  }
  .section-header {
    padding: 12px 18px; display: flex; align-items: center; gap: 10px;
    border-bottom: 1px solid var(--border);
  }
  .section-header h2 { font-size: 14px; font-weight: 600; }
  .badge {
    display: inline-block; padding: 2px 10px; border-radius: 12px;
    font-size: 12px; font-weight: 600; margin-left: auto;
  }
  .badge-warn  { background: rgba(210,153,34,0.15); color: var(--warn);   border: 1px solid rgba(210,153,34,0.3); }
  .badge-ok    { background: rgba(63,185,80,0.12);  color: var(--accent2); border: 1px solid rgba(63,185,80,0.25); }
  .badge-danger{ background: rgba(248,81,73,0.12);  color: var(--danger);  border: 1px solid rgba(248,81,73,0.25); }

  table { width: 100%; border-collapse: collapse; }
  th {
    text-align: left; padding: 9px 18px; font-size: 12px; font-weight: 600;
    color: var(--muted); text-transform: uppercase; letter-spacing: 0.04em;
    border-bottom: 1px solid var(--border); background: rgba(255,255,255,0.02);
  }
  td { padding: 9px 18px; border-bottom: 1px solid var(--border); font-size: 13px; }
  tr:last-child td { border-bottom: none; }
  tr:hover td { background: rgba(88,166,255,0.04); }

  .mono { font-family: 'Cascadia Code', 'Consolas', monospace; font-size: 12px; color: var(--accent); }
  .name { color: var(--text); }
  .muted { color: var(--muted); font-size: 12px; }

  a.admin-link {
    color: var(--accent); text-decoration: none; font-size: 12px;
    border: 1px solid rgba(88,166,255,0.3); border-radius: 4px;
    padding: 2px 9px; white-space: nowrap; transition: background 0.15s;
  }
  a.admin-link:hover { background: rgba(88,166,255,0.12); }

  .all-clear {
    text-align: center; padding: 48px 24px; color: var(--accent2); font-size: 15px;
  }
  .all-clear .icon { font-size: 40px; margin-bottom: 12px; }

  .error-box {
    background: rgba(248,81,73,0.1); border: 1px solid var(--danger);
    border-radius: 6px; padding: 12px 16px; color: var(--danger);
    margin-bottom: 20px; font-size: 13px;
  }

  .empty-row td { color: var(--muted); font-style: italic; text-align: center; padding: 20px; }

  .reload-btn {
    background: transparent; border: 1px solid var(--border); border-radius: var(--radius);
    color: var(--muted); font-size: 13px; padding: 5px 14px; cursor: pointer;
    font-family: var(--font); transition: border-color 0.15s, color 0.15s;
  }
  .reload-btn:hover { border-color: var(--accent); color: var(--accent); }
</style>
</head>
<body>

<header>
  <div>🔭</div>
  <div>
    <h1>Gallery Check</h1>
    <div class="subtitle">Fav directory vs GalleryImages database</div>
  </div>
  <a class="back" href="<?= $adminUrl ?>">← Admin panel</a>
</header>

<div class="container">

  <?php if ($errors): ?>
    <?php foreach ($errors as $e): ?>
      <div class="error-box">⚠ <?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>
  <?php endif; ?>

  <!-- Stats -->
  <div class="stats">
    <div class="stat-pill">Fav files found: <strong><?= $totalFav ?></strong></div>
    <div class="stat-pill">GalleryImages rows: <strong><?= $totalDb ?></strong></div>
    <div class="stat-pill">Missing from DB: <strong style="color:<?= count($missingFromDb) ? 'var(--warn)' : 'var(--accent2)' ?>"><?= count($missingFromDb) ?></strong></div>
    <div class="stat-pill">Missing fav file: <strong style="color:<?= count($missingFavFile) ? 'var(--danger)' : 'var(--accent2)' ?>"><?= count($missingFavFile) ?></strong></div>
    <button class="reload-btn" onclick="location.reload()">↻ Refresh</button>
  </div>

  <?php if ($allClear): ?>
    <div class="all-clear">
      <div class="icon">✅</div>
      <div>All clear — every fav file has a GalleryImages entry and every DB entry has a fav file.</div>
    </div>
  <?php else: ?>

    <!-- Section 1: Fav files with no GalleryImages row -->
    <div class="section">
      <div class="section-header">
        <h2>⚠ Fav files missing from GalleryImages</h2>
        <span class="badge <?= count($missingFromDb) ? 'badge-warn' : 'badge-ok' ?>">
          <?= count($missingFromDb) ?>
        </span>
      </div>
      <table>
        <thead>
          <tr>
            <th>BaseName</th>
            <th>Inferred DSOKey</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($missingFromDb)): ?>
            <tr class="empty-row"><td colspan="3">None — all fav files are registered.</td></tr>
          <?php else: ?>
            <?php foreach ($missingFromDb as $base => $_): ?>
              <?php
                [$inferredKey, $knownKey] = infer_dso_key($base, $catalogToDso);
              ?>
              <tr>
                <td><span class="mono"><?= htmlspecialchars($base) ?></span></td>
                <td><span class="name"><?= htmlspecialchars($inferredKey) ?></span><?php if (!$knownKey): ?> <span class="muted">(no such DSO)</span><?php endif; ?></td>
                <td>
                  <a class="admin-link" href="<?= $adminUrl ?>?load=<?= urlencode($inferredKey) ?>" target="_blank">
                    Open in Admin →
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Section 2: GalleryImages rows whose fav file is missing -->
    <div class="section">
      <div class="section-header">
        <h2>✗ GalleryImages entries with no fav file</h2>
        <span class="badge <?= count($missingFavFile) ? 'badge-danger' : 'badge-ok' ?>">
          <?= count($missingFavFile) ?>
        </span>
      </div>
      <table>
        <thead>
          <tr>
            <th>BaseName</th>
            <th>DSOKey</th>
            <th>Common Name</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($missingFavFile)): ?>
            <tr class="empty-row"><td colspan="4">None — all DB entries have a fav file.</td></tr>
          <?php else: ?>
            <?php foreach ($missingFavFile as $base => $_): ?>
              <?php $row = $dbRows[$base] ?? ['dsoKey' => '?', 'commonName' => '']; ?>
              <tr>
                <td><span class="mono"><?= htmlspecialchars($base) ?></span></td>
                <td><span class="name"><?= htmlspecialchars($row['dsoKey']) ?></span></td>
                <td><span class="muted"><?= htmlspecialchars($row['commonName']) ?></span></td>
                <td>
                  <a class="admin-link" href="<?= $adminUrl ?>?load=<?= urlencode($row['dsoKey']) ?>" target="_blank">
                    Open in Admin →
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Section 3: duplicate BaseNames within one DSO -->
    <div class="section">
      <div class="section-header">
        <h2>⧉ Duplicate GalleryImages rows (same Base Name, same DSO)</h2>
        <span class="badge <?= count($dupGroups) ? 'badge-warn' : 'badge-ok' ?>">
          <?= count($dupGroups) ?>
        </span>
      </div>
      <table>
        <thead>
          <tr>
            <th>BaseName</th>
            <th>DSOKey</th>
            <th>Rows</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($dupGroups)): ?>
            <tr class="empty-row"><td colspan="4">None — every image name is used once per DSO.</td></tr>
          <?php else: ?>
            <?php foreach ($dupGroups as $g): ?>
              <tr>
                <td><span class="mono"><?= htmlspecialchars($g[0]['baseName']) ?></span></td>
                <td><span class="name"><?= htmlspecialchars($g[0]['dsoKey']) ?></span></td>
                <td><span class="muted"><?= implode(', ', array_map(fn($r) => '#' . $r['id'] . ($r['hidden'] ? ' (hidden)' : ''), $g)) ?></span></td>
                <td>
                  <a class="admin-link" href="<?= $adminUrl ?>?load=<?= urlencode($g[0]['dsoKey']) ?>" target="_blank"
                     title="Sync Folder on this DSO merges the duplicates">
                    Open in Admin →
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

  <?php endif; ?>

</div>
</body>
</html>
