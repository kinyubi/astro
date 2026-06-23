<?php
require_once __DIR__ . '/../admin/auth.php';

$dbPath  = __DIR__ . '/../../dsodb/astro.db';
$favDir  = __DIR__ . '/../images/fav';
$adminUrl = '/admin/';

$errors = [];
$favBaseNames = [];
$dbBaseNames  = [];
$dbRows       = []; // baseName => DSOKey

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
if (!file_exists($dbPath)) {
    $errors[] = "Database not found: $dbPath";
} else {
    try {
        $db = new PDO('sqlite:' . $dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $db->query("SELECT gi.BaseName, gi.DSOKey, o.CommonName
                            FROM GalleryImages gi
                            LEFT JOIN Objects o ON gi.DSOKey = o.DSOKey
                            ORDER BY gi.DSOKey, gi.BaseName");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $dbBaseNames[$row['BaseName']] = true;
            $dbRows[$row['BaseName']] = [
                'dsoKey'     => $row['DSOKey'],
                'commonName' => $row['CommonName'] ?? '',
            ];
        }
    } catch (Exception $e) {
        $errors[] = "DB error: " . $e->getMessage();
    }
}

// ── 3. Compute diffs ──────────────────────────────────────────────────────────
// Fav files with no GalleryImages row
$missingFromDb = array_diff_key($favBaseNames, $dbBaseNames);
ksort($missingFromDb);

// GalleryImages rows whose fav file is absent
$missingFavFile = array_diff_key($dbBaseNames, $favBaseNames);
ksort($missingFavFile);

$allClear = empty($missingFromDb) && empty($missingFavFile) && empty($errors);
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
                // Infer DSOKey: first token(s) before a likely session/date segment
                // e.g. "ic1805_heart_20241108_sho" → try to match against known DB keys
                // Simple heuristic: uppercase first segment(s) until we hit a digit-heavy token
                $parts = explode('_', $base);
                $keyParts = [];
                foreach ($parts as $p) {
                    if (preg_match('/^\d{4,}/', $p)) break; // hit a date/session number
                    $keyParts[] = $p;
                    // Stop after 2 parts — most DSO keys are 1–2 segments (e.g. "ic1805", "ngc7000")
                    if (count($keyParts) >= 2) break;
                }
                $inferredKey = strtoupper(implode('', $keyParts));
              ?>
              <tr>
                <td><span class="mono"><?= htmlspecialchars($base) ?></span></td>
                <td><span class="name"><?= htmlspecialchars($inferredKey) ?></span></td>
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

  <?php endif; ?>

</div>
</body>
</html>
