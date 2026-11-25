<?php
// ============================================================
// /vis/span/ -- when each DSO and planet is visible, and what's ending soon
//
// Reads VisibilityWindows and PlanetVisibilityWindows (filled by
// pythonscripts/visibility_windows.py) for two profiles and shows:
//   1. Ending soon: every DSO and planet visible tonight under either
//      profile, with nights left and when it comes back, one line each.
//   2. A timeline from tonight through the next 12 months, one row per
//      object, each profile's windows in its own colour on the same row.
//
// Query string: ?a=<profile>&b=<profile> (defaults: default, backyard35)
//               &date=YYYY-MM-DD to start from another night (default tonight)
// Nights are local evening dates (America/Boise), same as the tables.
// Stars (SINGLE/DOUBLE/VARIABLE_STAR) are left out, as on /vis.
// /vis/ending/ redirects here. The /vis report's "Visibility span" section
// links here with ?a=<its profile>&date=<its date>.
// ============================================================
header('Cache-Control: no-store, no-cache, must-revalidate');
date_default_timezone_set('America/Boise');

$root = dirname(__DIR__, 3);
require_once $root . '/shared/db.php';

// Profiles: names and settings from pythonscripts/profiles/*.json
$profiles = [];
foreach (glob($root . '/pythonscripts/profiles/*.json') ?: [] as $f) {
    $p = json_decode(file_get_contents($f), true);
    if (!is_array($p)) continue;
    $name = pathinfo($f, PATHINFO_FILENAME);
    $az = ((float)($p['az_min'] ?? 0) <= 0 && (float)($p['az_max'] ?? 360) >= 360)
        ? 'no blockage'
        : sprintf('az %g°–%g°', $p['az_min'], $p['az_max']);
    $profiles[$name] = sprintf('%s · %g° · %s', $name, $p['min_altitude'] ?? 0, $az);
}
ksort($profiles);

$pick = function (string $param, string $fallback) use ($profiles): string {
    $v = isset($_GET[$param]) ? (string)$_GET[$param] : $fallback;
    return isset($profiles[$v]) ? $v : $fallback;
};
$profA = $pick('a', 'default');
$profB = $pick('b', 'backyard35');

// The night to start from: ?date=, within the range the tables cover
// (visibility_windows.py works out 183 days back to 370 days ahead)
$today   = date('Y-m-d');
$minDate = date('Y-m-d', strtotime('-180 days'));
$maxDate = date('Y-m-d', strtotime('+360 days'));
$tonight = $today;
if (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date'])
    && strtotime($_GET['date']) !== false) {
    $tonight = max($minDate, min($maxDate, (string)$_GET['date']));
}
$isToday = $tonight === $today;
$until   = date('Y-m-d', strtotime($tonight . ' +1 year -1 day'));
$noStars = "COALESCE(o.objecttypeid, '') NOT IN ('SOLAR_SYSTEM', 'SINGLE_STAR', 'DOUBLE_STAR', 'VARIABLE_STAR')";
const PLANETS = ['Mercury', 'Venus', 'Mars', 'Jupiter', 'Saturn', 'Uranus', 'Neptune'];

$error = null;
$rows = [];
$planetRows = [];
$planetTable = false;
$neverSeen = [];   // profile => ["Name (KEY)", ...]
$computedAt = null;
try {
    $db = get_db();
    $stmt = $db->prepare('
        SELECT v.dsokey            AS "Key",
               o.commonname        AS "Name",
               v.profile           AS "Profile",
               v.firstnight        AS "FirstNight",
               v.lastnight         AS "LastNight",
               v.startsbeforerange AS "StartsBefore",
               v.runspastrange     AS "RunsPast",
               o.wantbetter        AS "WantBetter",
               (SELECT count(*) FROM projects p WHERE p.dsokey = o.dsokey) AS "Projects"
        FROM visibilitywindows v
        JOIN objects o ON o.dsokey = v.dsokey
        WHERE v.profile IN (?, ?) AND v.lastnight >= ? AND ' . $noStars . '
        ORDER BY v.dsokey, v.profile, v.firstnight');
    $stmt->execute([$profA, $profB, $tonight]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // DSOs with a position but no window at all in the 12 months shown
    $stmt = $db->prepare('
        SELECT p.profile AS "Profile", o.dsokey AS "Key", o.commonname AS "Name"
        FROM objects o
        CROSS JOIN (SELECT CAST(? AS TEXT) AS profile UNION SELECT CAST(? AS TEXT)) p
        WHERE o.rahours IS NOT NULL AND o.decdegrees IS NOT NULL AND ' . $noStars . '
          AND NOT EXISTS (SELECT 1 FROM visibilitywindows v
                          WHERE v.dsokey = o.dsokey AND v.profile = p.profile
                            AND v.lastnight >= ? AND v.firstnight <= ?)
        ORDER BY 1, 2');
    $stmt->execute([$profA, $profB, $tonight, $until]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $neverSeen[$r['Profile']][] = ($r['Name'] ?: $r['Key']) . ($r['Name'] && $r['Name'] !== $r['Key'] ? " ({$r['Key']})" : '');
    }
    $computedAt = $db->query('SELECT max(computedat) FROM visibilitywindows')->fetchColumn();

    $planetTable = $db->query("SELECT to_regclass('public.planetvisibilitywindows')")->fetchColumn() !== null;
    if ($planetTable) {
        $stmt = $db->prepare('
            SELECT planet AS "Key", profile AS "Profile", firstnight AS "FirstNight", lastnight AS "LastNight",
                   startsbeforerange AS "StartsBefore", runspastrange AS "RunsPast"
            FROM planetvisibilitywindows
            WHERE profile IN (?, ?) AND lastnight >= ?
            ORDER BY planet, profile, firstnight');
        $stmt->execute([$profA, $profB, $tonight]);
        $planetRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $error = (stripos($e->getMessage(), 'visibilitywindows') !== false)
        ? 'The VisibilityWindows table is missing. Run dsodb/migrations/migrate_add_visibility_windows.py, then pythonscripts/visibility_windows.py.'
        : 'Database error: ' . $e->getMessage();
}

// One entry per object with its windows per profile
$objs = [];
$add = function (array $r, bool $planet) use (&$objs, $profA, $profB) {
    $k = ($planet ? 'planet:' : '') . $r['Key'];
    $objs[$k] ??= [
        'key' => $r['Key'],
        'name' => $planet ? $r['Key'] : ($r['Name'] ?: $r['Key']),
        'planet' => $planet,
        'want' => !$planet && (int)$r['WantBetter'] === 1,
        'projects' => $planet ? null : (int)$r['Projects'],
        'w' => [$profA => [], $profB => []],
    ];
    $objs[$k]['w'][$r['Profile']][] = [
        's' => $r['FirstNight'], 'e' => $r['LastNight'],
        'sb' => (bool)$r['StartsBefore'], 'rp' => (bool)$r['RunsPast'],
    ];
};
foreach ($rows as $r) $add($r, false);
foreach ($planetRows as $r) $add($r, true);

// Planets with no window in the next year under either profile
$planetsShown = array_unique(array_column($planetRows, 'Key'));
$planetsNever = $planetTable ? array_values(array_diff(PLANETS, $planetsShown)) : [];

$data = [
    'tonight' => $tonight,
    'until' => $until,
    'a' => ['id' => $profA, 'label' => $profiles[$profA] ?? $profA],
    'b' => ['id' => $profB, 'label' => $profiles[$profB] ?? $profB],
    'objs' => array_values($objs),
];
$computedText = $computedAt ? date('j M Y, g:i a', strtotime($computedAt)) : null;
// ?embed=1: shown inside the /vis report (an iframe) -- no page title or
// date picker (the date follows /vis), and the page reports its height to /vis.
$embed = !empty($_GET['embed']);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Visibility Span</title>
<link rel="icon" type="image/png" href="/images/favicon.png">
<?php if ($embed): ?><base target="_top"><?php endif; ?>
<style>
  :root {
    --bg: #0a0e27; --card: #1a1f3a; --line: #2a3f5f; --grid: #232a4a;
    --month-line: #4a5a8a; --month-band: #ffffff0a;
    --text: #e0e0e0; --text2: #a9b1c7; --muted: #7a839c; --accent: #4a9eff;
    --ca: #3987e5; --cb: #d95926;      /* profile A blue, profile B orange */
  }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--bg); color: var(--text);
    font: 14px/1.4 system-ui, -apple-system, "Segoe UI", sans-serif; }
  .wrap { max-width: 1200px; margin: 0 auto; padding: 20px 18px 48px; }
  body.embed { background: transparent; }
  body.embed .wrap { max-width: none; padding: 0; }
  h1 { color: var(--accent); font-size: 24px; margin: 0 0 4px; }
  h2 { font-size: 17px; margin: 0 0 4px; }
  .sub { color: var(--text2); margin: 0 0 14px; }
  .sub a { color: var(--accent); }
  .controls { display: flex; flex-wrap: wrap; gap: 10px 18px; align-items: center; margin: 0 0 16px; }
  .controls label { color: var(--text2); font-size: 13px; }
  input[type="date"] { background: var(--bg); color: var(--text); border: 1px solid var(--accent); border-radius: 4px; padding: 4px 8px; font: inherit; color-scheme: dark; }
  .controls .today, .nav a { color: var(--accent); font-size: 13px; }
  .nav { margin: 0 0 6px; }
  select { background: var(--bg); color: var(--text); border: 1px solid var(--accent); border-radius: 4px; padding: 5px 8px; font: inherit; }
  .sw { display: inline-block; width: 12px; height: 12px; border-radius: 3px; vertical-align: -1px; margin-right: 5px; }
  .card { background: var(--card); border: 1px solid var(--line); border-radius: 8px; padding: 14px 16px; margin-bottom: 18px; }
  .meta { color: var(--text2); font-size: 13px; margin: 0 0 10px; }
  .error { border-color: #f85149; color: #f8a49e; }
  table { border-collapse: collapse; width: 100%; font-size: 13.5px; }
  th, td { text-align: left; padding: 6px 10px 6px 0; border-bottom: 1px solid var(--line); white-space: nowrap; }
  th { color: var(--text2); font-weight: 560; font-size: 12.5px; cursor: pointer; user-select: none; }
  th[aria-sort] { color: var(--text); }
  th[aria-sort="ascending"]::after { content: " ▲"; font-size: 10px; }
  th[aria-sort="descending"]::after { content: " ▼"; font-size: 10px; }
  td.num { font-variant-numeric: tabular-nums; }
  .key { color: var(--muted); margin-left: 6px; }
  .tag { display: inline-block; margin-left: 6px; padding: 0 6px; border: 1px solid var(--muted); border-radius: 8px; color: var(--text2); font-size: 11px; }
  .left { display: inline-block; min-width: 2.6em; padding: 1px 7px; border-radius: 10px; color: #fff; font-weight: 600; text-align: center; font-variant-numeric: tabular-nums; }
  .left.a { background: var(--ca); } .left.b { background: var(--cb); }
  .left.urgent { box-shadow: 0 0 0 2px #ffd700; }
  .dash { color: var(--muted); }
  .back { color: var(--text2); font-size: 12.5px; }
  .star { color: #ffd700; }
  .filters { display: flex; gap: 16px; flex-wrap: wrap; margin: 0 0 10px; color: var(--text2); font-size: 13px; }
  .filters input { accent-color: var(--accent); }
  .note { color: var(--muted); font-size: 12.5px; margin: 8px 0 0; }
  svg { display: block; }
  .lbl { fill: var(--text); font-size: 12.5px; }
  .lbl .k { fill: var(--muted); }
  .grp { fill: var(--accent); font-size: 12px; font-weight: 650; letter-spacing: .04em; }
  .axis-t { fill: var(--text2); font-size: 11.5px; font-weight: 560; }
  .on-bar { fill: #fff; font-size: 10.5px; font-weight: 600; pointer-events: none; }
  .today-l { stroke: #ffd700; stroke-width: 1.5; }
  .month-l { stroke: var(--month-line); stroke-width: 1; }
  .month-band { fill: var(--month-band); }
  .row-l { stroke: var(--grid); }
  #tl-head { position: sticky; top: 0; z-index: 5; background: var(--card); border-bottom: 1px solid var(--month-line); }
  #tip { position: fixed; pointer-events: none; background: var(--card); color: var(--text);
    border: 1px solid var(--accent); border-radius: 6px; padding: 7px 10px; font-size: 13px;
    display: none; z-index: 10; max-width: 320px; box-shadow: 0 4px 16px #0008; }
  #tip .m { color: var(--text2); }
  .empty { color: var(--muted); font-style: italic; padding: 8px 0; }
</style>
</head>
<body<?= $embed ? ' class="embed"' : '' ?>>
<div class="wrap">
<?php if (!$embed): ?>
  <h1>Visibility Span</h1>
  <p class="nav"><a href="/vis?date=<?= $h($tonight) ?>&amp;profile=<?= $h($profA) ?>">&larr; DSO Visibility</a></p>
  <p class="sub"><?= $isToday ? 'From tonight, ' . $h(date('j M Y')) : 'From the night of <strong>' . $h(date('j M Y', strtotime($tonight))) . '</strong>' ?>. A DSO counts as visible on nights it spends at least 60 minutes in the profile's sky during astronomical darkness; a planet, on nights it's in that sky at all. Same rules as <a href="/vis">DSO Visibility</a>.<?= $computedText ? ' Windows calculated ' . $h($computedText) . '.' : '' ?></p>
<?php else: ?>
  <p class="sub">Windows calculated <?= $h($computedText ?? 'never') ?>. <a href="/vis/span/?a=<?= $h($profA) ?>&amp;b=<?= $h($profB) ?>&amp;date=<?= $h($tonight) ?>">Open as its own page &rarr;</a></p>
<?php endif; ?>

  <form class="controls" method="get" target="_self">
    <label><span class="sw" style="background:var(--ca)"></span>Profile 1
      <select name="a" onchange="this.form.submit()">
        <?php foreach ($profiles as $id => $label): ?>
          <option value="<?= $h($id) ?>"<?= $id === $profA ? ' selected' : '' ?>><?= $h($label) ?></option>
        <?php endforeach; ?>
      </select></label>
    <label><span class="sw" style="background:var(--cb)"></span>Profile 2
      <select name="b" onchange="this.form.submit()">
        <?php foreach ($profiles as $id => $label): ?>
          <option value="<?= $h($id) ?>"<?= $id === $profB ? ' selected' : '' ?>><?= $h($label) ?></option>
        <?php endforeach; ?>
      </select></label>
<?php if ($embed): ?>
    <input type="hidden" name="date" value="<?= $h($tonight) ?>"><input type="hidden" name="embed" value="1">
<?php else: ?>
    <label>Starting night
      <input type="date" name="date" value="<?= $h($tonight) ?>" min="<?= $h($minDate) ?>" max="<?= $h($maxDate) ?>" onchange="this.form.submit()"></label>
    <?php if (!$isToday): ?><a class="today" href="?a=<?= $h($profA) ?>&amp;b=<?= $h($profB) ?>">Back to tonight</a><?php endif; ?>
<?php endif; ?>
  </form>

<?php if ($error): ?>
  <div class="card error"><?= $h($error) ?></div>
<?php else: ?>
  <div class="card">
    <h2>Ending soon</h2>
    <p class="meta">DSOs and planets visible <?= $isToday ? 'tonight' : 'that night' ?> under either profile. Nights left counts <?= $isToday ? 'tonight' : 'that night' ?>; ringed in gold when 14 or fewer. Click a heading to sort.</p>
    <div class="filters">
      <label><input type="checkbox" id="f-noproj"> Only DSOs with no Project yet</label>
      <label><input type="checkbox" id="f-want"> Only Want Better</label>
      <label><input type="checkbox" id="f-planets" checked> Include planets</label>
    </div>
    <div style="overflow-x:auto"><table id="ending"></table></div>
  </div>

  <div class="card">
    <h2>Coming up</h2>
    <p class="meta">Not visible <?= $isToday ? 'tonight' : 'that night' ?> under either profile, in the order they become visible, with how many nights the window lasts.</p>
    <div class="filters">
      <label>Opening within
        <select id="f-within">
          <option value="30">30 days</option>
          <option value="90" selected>90 days</option>
          <option value="365">a year</option>
        </select></label>
    </div>
    <div style="overflow-x:auto"><table id="coming"></table></div>
  </div>

  <div class="card">
    <h2>Timeline, <?= $isToday ? 'next 12 months' : 'the 12 months from ' . $h(date('j M Y', strtotime($tonight))) ?></h2>
    <p class="meta"><span class="sw" style="background:var(--ca)"></span><?= $h($data['a']['label']) ?> &nbsp; <span class="sw" style="background:var(--cb)"></span><?= $h($data['b']['label']) ?> &nbsp;·&nbsp; gold line = <?= $isToday ? 'tonight' : 'the starting night' ?> · square end = continues past the chart</p>
    <div class="filters">
      <label><input type="radio" name="tl-set" value="tonight" checked> Visible <?= $isToday ? 'tonight' : 'that night' ?>, most urgent first</label>
      <label><input type="radio" name="tl-set" value="all"> Everything, by name</label>
    </div>
    <div id="tl-head"></div>
    <div id="timeline"></div>
    <?php foreach ([$profA, $profB] as $i => $pid): if ($i === 1 && $profB === $profA) break; if (empty($neverSeen[$pid])) continue; ?>
      <p class="note">Not visible at all in these 12 months under <strong><?= $h($pid) ?></strong>: <?= $h(implode(', ', $neverSeen[$pid])) ?>.</p>
    <?php endforeach; ?>
    <?php if (!$planetTable): ?>
      <p class="note">No planets yet: run dsodb/migrations/migrate_add_visibility_windows.py again to add the planet table, then visibility_windows.py.</p>
    <?php elseif ($planetsNever): ?>
      <p class="note">Not shown: <?= $h(implode(' and ', $planetsNever)) ?> — never in either profile's sky during full darkness in the next year.</p>
    <?php endif; ?>
  </div>
<?php endif; ?>
</div>
<div id="tip"></div>

<script>
const DATA = <?= json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
(function () {
  if (!document.getElementById('ending')) return;
  const A = DATA.a.id, B = DATA.b.id, SAME = A === B;
  const MON = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  const toD = s => new Date(s + 'T00:00:00Z');
  const T0 = toD(DATA.tonight), T1 = toD(DATA.until);
  const DAYS = Math.round((T1 - T0) / 864e5) + 1;
  const dayOf = s => Math.round((toD(s) - T0) / 864e5);
  const fmt = s => { const d = toD(s); return d.getUTCDate() + ' ' + MON[d.getUTCMonth()]; };
  const fmtY = s => { const d = toD(s); return d.getUTCDate() + ' ' + MON[d.getUTCMonth()] + ' ' + d.getUTCFullYear(); };
  const esc = s => String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  const tip = document.getElementById('tip');
  const showTip = (ev, html) => {
    tip.innerHTML = html; tip.style.display = 'block';
    tip.style.left = Math.min(ev.clientX + 14, innerWidth - tip.offsetWidth - 8) + 'px';
    tip.style.top = (ev.clientY + 14) + 'px';
  };
  const hideTip = () => { tip.style.display = 'none'; };

  // Per object and profile: the window open tonight, and the next one after it
  for (const d of DATA.objs) {
    d.now = {}; d.next = {};
    for (const p of [A, B]) {
      const ws = d.w[p] || [];
      const cur = ws.find(w => w.s <= DATA.tonight && w.e >= DATA.tonight);
      d.now[p] = cur ? { ...cur, left: dayOf(cur.e) + 1 } : null;
      const after = ws.filter(w => w.s > (cur ? cur.e : DATA.tonight));
      d.next[p] = after.length ? after[0].s : null;
      d.nextW = d.nextW || {};
      d.nextW[p] = after.length ? after[0] : null;
    }
    const lefts = [A, B].map(p => d.now[p] && !d.now[p].rp ? d.now[p].left : (d.now[p] ? 1e6 : null)).filter(v => v !== null);
    d.minLeft = lefts.length ? Math.min(...lefts) : null;
  }
  const nameCell = d => `${esc(d.name)}${d.planet ? '<span class="tag">planet</span>' : `<span class="key">${esc(d.key)}</span>`}`;

  // ── Ending soon table ─────────────────────────────────────────────
  const tbl = document.getElementById('ending');
  let sortKey = 'min', sortDir = 1;
  const cols = [
    { k: 'name', t: 'Object' },
    { k: 'a', t: 'Nights left · ' + A, cls: 'a' },
    { k: 'aback', t: 'Back' },
    ...(SAME ? [] : [{ k: 'b', t: 'Nights left · ' + B, cls: 'b' }, { k: 'bback', t: 'Back' }]),
    { k: 'want', t: 'Want better' },
    { k: 'proj', t: 'Projects' },
  ];
  const leftVal = (d, p) => d.now[p] ? (d.now[p].rp ? 1e6 : d.now[p].left) : 2e6;
  const sorters = {
    min: d => d.minLeft ?? 2e6, name: d => d.name.toLowerCase(),
    a: d => leftVal(d, A), b: d => leftVal(d, B),
    aback: d => d.next[A] || '9999', bback: d => d.next[B] || '9999',
    want: d => d.want ? 0 : 1, proj: d => d.projects ?? -1,
  };
  const leftCell = (d, p, cls) => {
    const n = d.now[p];
    if (!n) return '<span class="dash">not visible</span>';
    const txt = n.rp ? `${n.left}+` : n.left;
    return `<span class="left ${cls}${!n.rp && n.left <= 14 ? ' urgent' : ''}" title="until ${fmtY(n.e)}${n.rp ? ' or later' : ''}">${txt}</span> <span class="back">to ${fmt(n.e)}</span>`;
  };
  const backCell = (d, p) => d.next[p] ? `<span class="back">${fmt(d.next[p])}${toD(d.next[p]).getUTCFullYear() !== T0.getUTCFullYear() ? ' ' + toD(d.next[p]).getUTCFullYear() : ''}</span>`
                                       : (d.now[p] && d.now[p].rp ? '<span class="dash">—</span>' : '<span class="dash">not within a year</span>');
  function renderTable() {
    const noproj = document.getElementById('f-noproj').checked, want = document.getElementById('f-want').checked;
    const planets = document.getElementById('f-planets').checked;
    let list = DATA.objs.filter(d => d.minLeft !== null
      && (d.planet ? planets && !noproj && !want : (!noproj || d.projects === 0) && (!want || d.want)));
    const f = sorters[sortKey];
    list.sort((x, y) => { const a = f(x), b = f(y); return (a < b ? -1 : a > b ? 1 : 0) * sortDir || x.name.localeCompare(y.name); });
    tbl.innerHTML = '<thead><tr>' + cols.map(c =>
      `<th data-k="${c.k}"${sortKey === c.k ? ` aria-sort="${sortDir > 0 ? 'ascending' : 'descending'}"` : ''}>${c.cls ? `<span class="sw" style="background:var(--c${c.cls})"></span>` : ''}${esc(c.t)}</th>`).join('') + '</tr></thead><tbody>' +
      (list.length ? list.map(d => '<tr>' +
        `<td>${nameCell(d)}</td>` +
        `<td class="num">${leftCell(d, A, 'a')}</td><td>${backCell(d, A)}</td>` +
        (SAME ? '' : `<td class="num">${leftCell(d, B, 'b')}</td><td>${backCell(d, B)}</td>`) +
        `<td>${d.want ? '<span class="star">★</span>' : ''}</td>` +
        `<td class="num">${d.planet ? '' : (d.projects || '<span class="dash">0</span>')}</td></tr>`).join('')
        : `<tr><td colspan="${cols.length}" class="empty">Nothing matches.</td></tr>`) + '</tbody>';
  }
  tbl.addEventListener('click', e => {
    const th = e.target.closest('th'); if (!th) return;
    const k = th.dataset.k;
    if (sortKey === k) sortDir = -sortDir; else { sortKey = k; sortDir = 1; }
    renderTable();
  });
  ['f-noproj', 'f-want', 'f-planets'].forEach(id => document.getElementById(id).addEventListener('change', renderTable));
  renderTable();

  // ── Coming up: not visible that night, in the order they appear ───────
  const coming = document.getElementById('coming');
  const opensCell = (d, p, cls) => {
    const w = d.nextW[p];
    if (!w) return '<span class="dash">not within a year</span>';
    const len = w.rp ? `${dayOf(w.e) - dayOf(w.s) + 1}+` : dayOf(w.e) - dayOf(w.s) + 1;
    return `<span class="left ${cls}" title="${fmtY(w.s)} – ${fmtY(w.e)}${w.rp ? ' or later' : ''}">${fmt(w.s)}</span> <span class="back">${len} nights</span>`;
  };
  function renderComing() {
    const within = +document.getElementById('f-within').value;
    const firstOpen = d => Math.min(...[A, B].map(p => d.nextW[p] ? dayOf(d.nextW[p].s) : 1e9));
    const list = DATA.objs.filter(d => d.minLeft === null && firstOpen(d) <= within)
      .sort((x, y) => firstOpen(x) - firstOpen(y) || x.name.localeCompare(y.name));
    coming.innerHTML = '<thead><tr><th>Object</th>'
      + `<th><span class="sw" style="background:var(--ca)"></span>Opens · ${esc(A)}</th>`
      + (SAME ? '' : `<th><span class="sw" style="background:var(--cb)"></span>Opens · ${esc(B)}</th>`)
      + '<th>Want better</th><th>Projects</th></tr></thead><tbody>'
      + (list.length ? list.map(d => '<tr>'
          + `<td>${nameCell(d)}</td><td>${opensCell(d, A, 'a')}</td>`
          + (SAME ? '' : `<td>${opensCell(d, B, 'b')}</td>`)
          + `<td>${d.want ? '<span class="star">★</span>' : ''}</td>`
          + `<td class="num">${d.planet ? '' : (d.projects || '<span class="dash">0</span>')}</td></tr>`).join('')
        : `<tr><td colspan="5" class="empty">Nothing opens within ${within} days.</td></tr>`) + '</tbody>';
  }
  document.getElementById('f-within').addEventListener('change', renderComing);
  renderComing();

  // ── Timeline ──────────────────────────────────────────────────────
  const NS = 'http://www.w3.org/2000/svg';
  const el = (n, a, p) => { const e = document.createElementNS(NS, n); for (const k in a) e.setAttribute(k, a[k]); if (p) p.appendChild(e); return e; };
  const host = document.getElementById('timeline'), head = document.getElementById('tl-head');
  const meas = el('svg', { style: 'position:absolute;visibility:hidden;width:0;height:0' }); document.body.appendChild(meas);
  const tw = (s, cls) => { const t = el('text', { class: cls }, meas); t.textContent = s; const w = t.getComputedTextLength(); t.remove(); return w; };
  function bar(x, y, w, h, rl, rr) {
    const r = Math.min(3, h / 2, w / 2), a = rl ? r : 0, b = rr ? r : 0;
    return `M${x+a},${y}H${x+w-b}` + (b ? `a${b},${b} 0 0 1 ${b},${b}V${y+h-b}a${b},${b} 0 0 1 -${b},${b}` : `V${y+h}`) +
           `H${x+a}` + (a ? `a${a},${a} 0 0 1 -${a},-${a}V${y+a}a${a},${a} 0 0 1 ${a},-${a}` : `V${y}`) + 'Z';
  }
  // Month boundaries inside the chart: [{day offset of the 1st, label}], plus the end
  function months() {
    const out = [];
    for (let i = 0; i <= 13; i++) {
      const m = new Date(Date.UTC(T0.getUTCFullYear(), T0.getUTCMonth() + i, 1));
      const d = Math.round((m - T0) / 864e5);
      if (d > DAYS) break;
      out.push({ d: Math.max(0, d), mon: m.getUTCMonth(), year: m.getUTCFullYear() });
    }
    return out;
  }
  function renderTimeline() {
    const set = document.querySelector('input[name="tl-set"]:checked').value;
    const byUrgency = (x, y) => (x.minLeft - y.minLeft) || x.name.localeCompare(y.name);
    const byName = (x, y) => x.name.localeCompare(y.name);
    const pick = list => set === 'all' ? list.slice().sort(byName) : list.filter(d => d.minLeft !== null).sort(byUrgency);
    const groups = [['PLANETS', pick(DATA.objs.filter(d => d.planet))], ['DEEP-SKY OBJECTS', pick(DATA.objs.filter(d => !d.planet))]]
      .filter(([, l]) => l.length);
    host.innerHTML = ''; head.innerHTML = '';
    if (!groups.length) { host.innerHTML = '<div class="empty">Nothing to show.</div>'; return; }
    const all = groups.flatMap(([, l]) => l);
    const profs = SAME ? [A] : [A, B];
    const nameW = Math.ceil(Math.max(...all.map(d => tw(d.name + '  ' + (d.planet ? '' : d.key), 'lbl')))) + 16;
    const W = Math.max(host.clientWidth, 600), PR = 6, SUB = 14, GAP = 2, PAD = 4, GH = 22;
    const ROW = PAD * 2 + profs.length * SUB + (profs.length - 1) * GAP;
    const H = groups.length * GH + all.length * ROW + 4;
    const x = day => nameW + (day / DAYS) * (W - nameW - PR);
    const ms = months();

    // Sticky month header: names centred in each month, a tick at each boundary
    const HH = 24;
    const hs = el('svg', { width: W, height: HH, viewBox: `0 0 ${W} ${HH}` }, head);
    ms.forEach((m, i) => {
      const end = i + 1 < ms.length ? ms[i + 1].d : DAYS;
      if (i % 2) el('rect', { x: x(m.d), y: 0, width: x(end) - x(m.d), height: HH, class: 'month-band' }, hs);
      if (m.d > 0) el('line', { x1: x(m.d), x2: x(m.d), y1: 0, y2: HH, class: 'month-l' }, hs);
      const wpx = x(end) - x(m.d);
      const label = MON[m.mon] + (m.mon === 0 || i === 0 ? ' ' + m.year : '');
      if (wpx >= tw(label, 'axis-t') + 6) {
        el('text', { x: (x(m.d) + x(end)) / 2, y: 16, 'text-anchor': 'middle', class: 'axis-t' }, hs).textContent = label;
      }
    });

    const svg = el('svg', { width: W, height: H, viewBox: `0 0 ${W} ${H}`, role: 'img', 'aria-label': 'Visibility timeline' }, host);
    // Month bands and boundary lines, full height
    ms.forEach((m, i) => {
      const end = i + 1 < ms.length ? ms[i + 1].d : DAYS;
      if (i % 2) el('rect', { x: x(m.d), y: 0, width: x(end) - x(m.d), height: H, class: 'month-band' }, svg);
      if (m.d > 0) el('line', { x1: x(m.d), x2: x(m.d), y1: 0, y2: H, class: 'month-l' }, svg);
    });

    let y = 0;
    for (const [title, list] of groups) {
      el('text', { x: 0, y: y + 15, class: 'grp' }, svg).textContent = title;
      y += GH;
      list.forEach(d => {
        const y0 = y;
        el('line', { x1: 0, x2: W, y1: y0 + ROW, y2: y0 + ROW, class: 'row-l' }, svg);
        const n = el('text', { x: 0, y: y0 + ROW / 2 + 4, class: 'lbl' }, svg);
        el('tspan', {}, n).textContent = d.name;
        if (!d.planet && d.key !== d.name) { const k = el('tspan', { class: 'k', dx: 6 }, n); k.textContent = d.key; }
        profs.forEach((p, j) => {
          const by = y0 + PAD + j * (SUB + GAP);
          const ws = (d.w[p] || []).filter(w => w.e >= DATA.tonight && w.s <= DATA.until);
          ws.forEach((w, wi) => {
            const s = Math.max(0, dayOf(w.s)), e = Math.min(DAYS - 1, dayOf(w.e));
            const xs = x(s), xe = x(e + 1), bw = Math.max(xe - xs, 3);
            const clipL = dayOf(w.s) <= 0, clipR = dayOf(w.e) >= DAYS - 1 || w.rp;
            el('path', { d: bar(xs, by, bw, SUB, !clipL, !clipR), fill: j === 0 ? 'var(--ca)' : 'var(--cb)' }, svg);
            const nextX = wi + 1 < ws.length ? x(Math.max(0, dayOf(ws[wi + 1].s))) : W;
            const long = clipL && clipR ? 'all year' : clipL ? `until ${fmt(w.e)}` : clipR ? `from ${fmt(w.s)}` : `${fmt(w.s)} – ${fmt(w.e)}`;
            if (tw(long, 'on-bar') + 10 <= bw) {
              el('text', { x: xs + 5, y: by + SUB / 2 + 4, class: 'on-bar' }, svg).textContent = long;
            } else if (xe + 5 + tw(long, 'on-bar') <= nextX - 4) {
              el('text', { x: xe + 5, y: by + SUB / 2 + 4, class: 'on-bar', style: 'fill:var(--text2)' }, svg).textContent = long;
            }
            const hit = el('rect', { x: xs, y: by - 1, width: Math.max(bw, 8), height: SUB + 2, fill: 'transparent' }, svg);
            const label = p === A ? DATA.a.label : DATA.b.label;
            hit.addEventListener('mousemove', ev => showTip(ev,
              `<b>${esc(d.name)}</b> <span class="m">${d.planet ? 'planet' : esc(d.key)}</span><br>${w.sb ? 'before ' : ''}${fmtY(w.s)} – ${fmtY(w.e)}${w.rp ? ' or later' : ''}<br><span class="m">${esc(label)}</span>`));
            hit.addEventListener('mouseleave', hideTip);
          });
        });
        y += ROW;
      });
    }
    el('line', { x1: x(0), x2: x(0), y1: 0, y2: H, class: 'today-l' }, svg);
  }
  document.querySelectorAll('input[name="tl-set"]').forEach(r => r.addEventListener('change', renderTimeline));
  renderTimeline();
  let rt; addEventListener('resize', () => { clearTimeout(rt); rt = setTimeout(renderTimeline, 150); });

  // Inside /vis: tell it how tall this page is, whenever that changes
  if (<?= $embed ? 'true' : 'false' ?> && window.parent !== window) {
    const tell = () => parent.postMessage({ visSpanHeight: Math.ceil(document.body.getBoundingClientRect().height) }, location.origin);
    new ResizeObserver(tell).observe(document.body);
    tell();
  }
})();
</script>
</body>
</html>
