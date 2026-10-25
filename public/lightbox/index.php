<?php
// ============================================================
// lightbox/index.php — compare and rate a DSO's images across sessions
// Route: /lightbox, /lightbox/<fragment>, /lightbox/<fragment>/<type>
//   (rewrite in public/.htaccess)
//
// Shows every session's _fav / _full / _wall / _4k image for one DSO side
// by side in a grid, with 1–5 stars and a note per image (ImageRatings
// table). The DSO is picked with a titling.py-style fragment (andro, m31,
// wizard, ngc7380_mosaic). Data comes from api.php, pixels from img.php.
// ============================================================

require_once __DIR__ . '/../admin/auth.php';

$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$init = [
    'base' => $base,
    'q'    => (string)($_GET['q'] ?? ''),
    'type' => (string)($_GET['type'] ?? ''),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Lightbox</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --bg:      #0d1117;
    --surface: #161b22;
    --raised:  #1c2129;
    --border:  #30363d;
    --accent:  #58a6ff;
    --gold:    #e3b341;
    --danger:  #f85149;
    --ok:      #3fb950;
    --text:    #c9d1d9;
    --muted:   #8b949e;
    --radius:  6px;
    --tile:    300px;
  }
  html, body { background: var(--bg); color: var(--text); }
  body { font-family: 'Segoe UI', system-ui, sans-serif; font-size: 14px; min-height: 100vh; }
  button, input, select, textarea { font: inherit; color: inherit; }

  /* ── Top bar ─────────────────────────────────────────── */
  .bar {
    position: sticky; top: 0; z-index: 20;
    display: flex; flex-wrap: wrap; align-items: center; gap: 10px 18px;
    padding: 10px 18px; background: rgba(22,27,34,0.96);
    border-bottom: 1px solid var(--border); backdrop-filter: blur(6px);
  }
  .brand { color: var(--accent); font-weight: 600; font-size: 15px; text-decoration: none; white-space: nowrap; }
  .picker { display: flex; align-items: center; gap: 6px; }
  .navbtn {
    width: 32px; height: 34px; border-radius: var(--radius); cursor: pointer;
    background: var(--raised); border: 1px solid var(--border); font-size: 16px; line-height: 1;
  }
  .navbtn:hover { border-color: var(--accent); }
  .search { position: relative; }
  .search input {
    width: 300px; height: 34px; padding: 0 12px; border-radius: var(--radius);
    background: var(--bg); border: 1px solid var(--border); outline: none;
  }
  .search input:focus { border-color: var(--accent); }
  .suggest {
    position: absolute; top: 38px; left: 0; width: 420px; max-height: 60vh; overflow-y: auto;
    list-style: none; background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius); box-shadow: 0 10px 30px rgba(0,0,0,0.5); display: none;
  }
  .suggest.open { display: block; }
  .suggest li {
    display: flex; justify-content: space-between; gap: 12px; align-items: baseline;
    padding: 7px 12px; cursor: pointer; border-bottom: 1px solid rgba(48,54,61,0.5);
  }
  .suggest li:last-child { border-bottom: 0; }
  .suggest li.active, .suggest li:hover { background: rgba(88,166,255,0.12); }
  .suggest .s-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .suggest .s-name small { color: var(--muted); margin-left: 6px; }
  .suggest .s-count { color: var(--muted); font-size: 12px; white-space: nowrap; }
  .suggest .s-count b { color: var(--text); font-weight: 600; }
  .suggest .pick { color: var(--accent); font-size: 11px; margin-left: 6px; }

  .types { display: flex; border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
  .types button {
    padding: 7px 14px; background: var(--raised); border: 0; border-right: 1px solid var(--border);
    cursor: pointer; white-space: nowrap;
  }
  .types button:last-child { border-right: 0; }
  .types button .n { color: var(--muted); font-size: 12px; margin-left: 4px; }
  .types button.on { background: var(--accent); color: #000; font-weight: 600; }
  .types button.on .n { color: rgba(0,0,0,0.6); }
  .types button.empty:not(.on) { color: var(--muted); }

  .opts { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; color: var(--muted); font-size: 13px; }
  .opts label { display: flex; align-items: center; gap: 5px; cursor: pointer; white-space: nowrap; }
  .opts select {
    background: var(--raised); border: 1px solid var(--border); border-radius: var(--radius); padding: 4px 6px;
  }
  .opts input[type=range] { width: 110px; accent-color: var(--accent); }

  /* ── Heading ─────────────────────────────────────────── */
  .head { padding: 18px 18px 6px; display: flex; flex-wrap: wrap; align-items: baseline; gap: 6px 16px; }
  .head h1 { font-size: 22px; font-weight: 600; color: #e6edf3; }
  .head .sub { color: var(--muted); }
  .head .sub code { color: var(--text); background: var(--raised); padding: 1px 6px; border-radius: 4px; font-size: 12px; }
  .banner {
    margin: 8px 18px 0; padding: 8px 12px; border-radius: var(--radius); font-size: 13px;
    border: 1px solid rgba(210,153,34,0.6); background: rgba(210,153,34,0.1); color: #e3b341;
  }
  .missing { padding: 4px 18px 0; color: var(--muted); font-size: 13px; }
  .missing span { white-space: nowrap; }

  /* ── Grid ────────────────────────────────────────────── */
  .grid {
    display: grid; gap: 16px; padding: 14px 18px 40px;
    grid-template-columns: repeat(auto-fill, minmax(min(var(--tile), 100%), 1fr));
    align-items: start;
  }
  .tile {
    background: var(--surface); border: 1px solid var(--border); border-radius: 8px; overflow: hidden;
    transition: border-color 0.15s;
  }
  .tile:hover { border-color: #484f58; }
  .tile.top { border-color: var(--gold); box-shadow: 0 0 0 1px var(--gold); }
  .frame { background: #000; cursor: zoom-in; position: relative; }
  .frame img { display: block; width: 100%; height: 100%; object-fit: contain; }
  .frame .badge {
    position: absolute; top: 8px; left: 8px; background: var(--gold); color: #000;
    font-size: 11px; font-weight: 700; padding: 2px 7px; border-radius: 10px; display: none;
  }
  .tile.top .badge { display: block; }
  .meta { padding: 10px 12px 12px; display: flex; flex-direction: column; gap: 5px; }
  .row1 { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
  .date { font-weight: 600; color: #e6edf3; margin-right: 2px; }
  .chip {
    font-size: 11px; padding: 1px 7px; border-radius: 10px; border: 1px solid var(--border); color: var(--muted);
  }
  .chip.rig  { color: var(--accent); border-color: rgba(88,166,255,0.4); }
  .chip.pal  { color: #d2a8ff; border-color: rgba(210,168,255,0.4); }
  .chip.site { color: var(--ok); border-color: rgba(63,185,80,0.4); }
  .chip.ign  { color: var(--danger); border-color: rgba(248,81,73,0.4); }
  .row2 { color: var(--muted); font-size: 12px; }
  .file { color: #6e7681; font-size: 11px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

  .stars { display: flex; align-items: center; gap: 2px; }
  .stars button {
    background: none; border: 0; cursor: pointer; font-size: 20px; line-height: 1; color: #3d444d; padding: 0 1px;
  }
  .stars button.lit { color: var(--gold); }
  .stars:not(.ro) button:hover { transform: scale(1.15); }
  .stars.ro button { cursor: default; }
  .stars .st { margin-left: 8px; font-size: 11px; color: var(--muted); }
  .stars .st.err { color: var(--danger); }
  .note {
    width: 100%; min-height: 34px; resize: vertical; padding: 6px 8px; font-size: 13px;
    background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius); outline: none;
  }
  .note:focus { border-color: var(--accent); }

  .empty { padding: 60px 18px; color: var(--muted); text-align: center; grid-column: 1 / -1; }
  .keys { padding: 0 18px 24px; color: #6e7681; font-size: 12px; }
  kbd {
    font-family: inherit; font-size: 11px; padding: 0 5px; border-radius: 4px;
    border: 1px solid var(--border); background: var(--raised); color: var(--text);
  }

  /* ── Viewer ──────────────────────────────────────────── */
  .viewer {
    position: fixed; inset: 0; z-index: 50; background: rgba(1,4,9,0.97);
    display: grid; grid-template-rows: auto 1fr auto;
  }
  .viewer[hidden] { display: none; }
  .v-top, .v-bot {
    display: flex; align-items: center; gap: 14px; padding: 10px 16px; flex-wrap: wrap;
    background: rgba(22,27,34,0.9);
  }
  .v-top { border-bottom: 1px solid var(--border); }
  .v-bot { border-top: 1px solid var(--border); }
  .v-title { font-weight: 600; color: #e6edf3; }
  .v-info { color: var(--muted); font-size: 13px; flex: 1; min-width: 200px; }
  .v-count { color: var(--muted); font-size: 13px; }
  .v-btn {
    background: var(--raised); border: 1px solid var(--border); border-radius: var(--radius);
    padding: 5px 11px; cursor: pointer; color: var(--text);
  }
  .v-btn:hover { border-color: var(--accent); }
  .v-stage { position: relative; overflow: hidden; min-height: 0; display: flex; align-items: center; justify-content: center; }
  .v-stage img { max-width: 100%; max-height: 100%; object-fit: contain; cursor: zoom-in; display: block; }
  .v-stage.zoom { overflow: auto; display: block; }
  .v-stage.zoom img { max-width: none; max-height: none; cursor: zoom-out; margin: auto; }
  .v-arrow {
    position: absolute; top: 50%; transform: translateY(-50%); z-index: 2;
    width: 44px; height: 70px; border-radius: var(--radius); border: 0; cursor: pointer;
    background: rgba(22,27,34,0.6); font-size: 26px; color: var(--text);
  }
  .v-arrow:hover { background: rgba(88,166,255,0.25); }
  .v-arrow.l { left: 10px; } .v-arrow.r { right: 10px; }
  .v-stage.zoom .v-arrow { position: fixed; top: 50%; }
  .v-bot .note { flex: 1; min-width: 240px; min-height: 34px; height: 34px; }
  .v-loading { position: absolute; top: 10px; right: 14px; font-size: 12px; color: var(--muted); }

  @media (max-width: 700px) {
    .search input { width: 200px; }
    .suggest { width: calc(100vw - 32px); }
  }
</style>
</head>
<body>

<header class="bar">
  <a class="brand" href="<?= htmlspecialchars($base) ?>/">🔭 Lightbox</a>
  <div class="picker">
    <button class="navbtn" id="prevDso" title="Previous DSO (←)">‹</button>
    <div class="search">
      <input id="q" type="text" autocomplete="off" spellcheck="false"
             placeholder="DSO fragment — andro, m31, wizard…">
      <ul class="suggest" id="suggest"></ul>
    </div>
    <button class="navbtn" id="nextDso" title="Next DSO (→)">›</button>
  </div>
  <nav class="types" id="types"></nav>
  <div class="opts">
    <label title="Show the _annotated versions (a)"><input type="checkbox" id="optAnn"> Annotated</label>
    <label title="Include IGNORE / bust sessions (i)"><input type="checkbox" id="optIgn"> Ignored sessions</label>
    <label>Sort
      <select id="optSort">
        <option value="date">Oldest first</option>
        <option value="date-desc">Newest first</option>
        <option value="rating">Rating</option>
      </select>
    </label>
    <label title="Tile size">Size <input type="range" id="optSize" min="160" max="900" step="10"></label>
  </div>
</header>

<section class="head">
  <h1 id="title">Loading…</h1>
  <div class="sub" id="sub"></div>
</section>
<div class="banner" id="banner" hidden></div>
<div class="missing" id="missing"></div>
<main class="grid" id="grid"></main>
<div class="keys">
  <kbd>←</kbd> <kbd>→</kbd> DSO &nbsp; <kbd>↑</kbd> <kbd>↓</kbd> image type &nbsp; <kbd>/</kbd> search &nbsp;
  <kbd>a</kbd> annotated &nbsp; <kbd>i</kbd> ignored sessions &nbsp;·&nbsp; in the viewer:
  <kbd>←</kbd> <kbd>→</kbd> image &nbsp; <kbd>1</kbd>–<kbd>5</kbd> rate &nbsp; <kbd>0</kbd> clear &nbsp;
  <kbd>z</kbd> 100% &nbsp; <kbd>n</kbd> note &nbsp; <kbd>Esc</kbd> close
</div>

<div class="viewer" id="viewer" hidden>
  <div class="v-top">
    <span class="v-title" id="vTitle"></span>
    <span class="v-info" id="vInfo"></span>
    <span class="v-count" id="vCount"></span>
    <button class="v-btn" id="vZoom" title="Toggle 100% (z)">100%</button>
    <a class="v-btn" id="vOpen" target="_blank" title="Open the original in a new tab" style="text-decoration:none">Original ↗</a>
    <button class="v-btn" id="vClose" title="Close (Esc)">✕</button>
  </div>
  <div class="v-stage" id="vStage">
    <button class="v-arrow l" id="vPrev" title="Previous (←)">‹</button>
    <img id="vImg" alt="">
    <button class="v-arrow r" id="vNext" title="Next (→)">›</button>
    <span class="v-loading" id="vLoading"></span>
  </div>
  <div class="v-bot">
    <div class="stars" id="vStars"></div>
    <textarea class="note" id="vNote" placeholder="Note…"></textarea>
  </div>
</div>

<script>
const INIT = <?= json_encode($init, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const BASE = INIT.base;
const TYPES = ['fav', 'full', 'wall', '4k'];
const TYPE_LABEL = { fav: 'Fav', full: 'Full', wall: 'Wall', '4k': '4K' };
const TYPE_RATIO = { fav: '4 / 5', full: '9 / 16', wall: '16 / 9', '4k': '16 / 9' };
const TYPE_TILE  = { fav: 300, full: 240, wall: 520, '4k': 520 };
const RATIOS = [[4,5],[1,1],[5,4],[9,16],[16,9],[3,4],[4,3],[2,3],[3,2]];

const $ = id => document.getElementById(id);
const store = {
  get(k, d) { try { const v = localStorage.getItem('lightbox.' + k); return v === null ? d : JSON.parse(v); } catch { return d; } },
  set(k, v) { try { localStorage.setItem('lightbox.' + k, JSON.stringify(v)); } catch {} },
};

const state = {
  dsos: [],          // server order (Python sorted()) — used for titling-style resolution
  nav: [],           // case-insensitive order — used for prev/next and the list
  folder: null,
  type: TYPES.includes(INIT.type) ? INIT.type : store.get('type', 'fav'),
  annotated: store.get('annotated', false),
  ignored: store.get('ignored', false),
  sort: store.get('sort', 'date'),
  data: null,
  view: [],          // images in display order
  vIndex: -1,
  zoom: false,
  reqId: 0,
};

const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

async function api(params, body) {
  const url = BASE + '/api.php?' + new URLSearchParams(params);
  const opts = body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) } : {};
  const r = await fetch(url, opts);
  const j = await r.json().catch(() => ({ error: 'Bad response (' + r.status + ')' }));
  if (!r.ok || j.error) throw new Error(j.error || ('HTTP ' + r.status));
  return j;
}

const imgUrl = (img, full) => BASE + '/img.php?' + new URLSearchParams({
  p: state.data.project, s: img.session, f: img.file, ...(full ? { size: 'full' } : {}), v: img.mtime || 0,
});

// ── Formatting ─────────────────────────────────────────────
function fmtDate(iso) {
  if (!iso) return '';
  return new Date(iso + 'T12:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}
function fmtMinutes(m) {
  if (!m) return '';
  const h = Math.floor(m / 60), r = m % 60;
  return h ? `${h}h ${String(r).padStart(2, '0')}m` : `${r}m`;
}
function fmtBytes(b) {
  if (!b) return '';
  return b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.round(b / 1024) + ' KB';
}
function aspect(w, h) {
  if (!w || !h) return '';
  const r = w / h;
  for (const [a, b] of RATIOS) if (Math.abs(r / (a / b) - 1) <= 0.02) return `${w}×${h} ${a}:${b}`;
  return `${w}×${h} ${r.toFixed(2)}:1`;
}
function dsoLabel(d) { return d ? (d.name ? `${d.token} · ${d.name}` : d.folder) : ''; }

// ── titling.py-style resolution ────────────────────────────
function resolve(q) {
  q = q.trim().toLowerCase();
  if (!q) return { match: null, candidates: [] };
  const exact = state.dsos.find(d => d.folder.toLowerCase() === q);
  if (exact) return { match: exact.folder, candidates: [exact.folder] };
  const wantMosaic = q.includes('mosaic');
  const c = state.dsos.filter(d => {
    const l = d.folder.toLowerCase();
    return l.includes(q) && l.includes('mosaic') === wantMosaic;
  }).map(d => d.folder);
  return { match: c[0] || null, candidates: c };
}
const byFolder = f => state.dsos.find(d => d.folder === f);

// ── Suggestions ────────────────────────────────────────────
let sugItems = [], sugActive = -1;

function buildSuggest() {
  const q = $('q').value.trim().toLowerCase();
  const pick = resolve(q).match;
  let list;
  if (!q) {
    list = state.nav.slice();
  } else {
    // every word of the fragment must appear in the folder or common name ("wizard mosaic", "orion neb")
    const terms = q.split(/[\s_]+/).filter(Boolean);
    list = state.nav.filter(d => {
      const hay = (d.folder + ' ' + (d.name || '')).toLowerCase();
      return d.folder === pick || terms.every(t => hay.includes(t));
    });
    if (pick) list.sort((a, b) => (b.folder === pick) - (a.folder === pick));
  }
  sugItems = list;
  sugActive = list.length ? 0 : -1;
  const ul = $('suggest');
  ul.innerHTML = list.length ? list.map((d, i) => `
    <li data-i="${i}" class="${i === sugActive ? 'active' : ''}">
      <span class="s-name">${esc(d.folder)}${d.name ? `<small>${esc(d.name)}</small>` : ''}${d.folder === pick && q ? '<span class="pick">↵</span>' : ''}</span>
      <span class="s-count"><b>${d.counts[state.type]}</b> ${TYPE_LABEL[state.type]} · ${d.sessions} sess</span>
    </li>`).join('') : '<li style="cursor:default;color:var(--muted)">No DSO folder matches</li>';
  ul.classList.add('open');
}
function moveSuggest(delta) {
  if (!sugItems.length) return;
  sugActive = (sugActive + delta + sugItems.length) % sugItems.length;
  [...$('suggest').children].forEach((li, i) => li.classList.toggle('active', i === sugActive));
  $('suggest').children[sugActive].scrollIntoView({ block: 'nearest' });
}
function closeSuggest() { $('suggest').classList.remove('open'); }

$('q').addEventListener('focus', () => { $('q').select(); buildSuggest(); });
$('q').addEventListener('input', buildSuggest);
$('q').addEventListener('keydown', e => {
  if (e.key === 'ArrowDown') { e.preventDefault(); moveSuggest(1); }
  else if (e.key === 'ArrowUp') { e.preventDefault(); moveSuggest(-1); }
  else if (e.key === 'Escape') { closeSuggest(); $('q').value = dsoLabel(byFolder(state.folder)); $('q').blur(); }
  else if (e.key === 'Enter') {
    e.preventDefault();
    const d = sugItems[sugActive];
    if (d) { closeSuggest(); $('q').blur(); loadDso(d.folder); }
  }
});
$('suggest').addEventListener('mousedown', e => {
  const li = e.target.closest('li[data-i]');
  if (!li) return;
  e.preventDefault();
  closeSuggest(); $('q').blur();
  loadDso(sugItems[+li.dataset.i].folder);
});
$('q').addEventListener('blur', () => setTimeout(() => {
  closeSuggest();
  if (document.activeElement !== $('q')) $('q').value = dsoLabel(byFolder(state.folder));
}, 120));

// ── DSO / type switching ───────────────────────────────────
function stepDso(delta) {
  if (!state.nav.length) return;
  let i = state.nav.findIndex(d => d.folder === state.folder);
  for (let n = 0; n < state.nav.length; n++) {
    i = (i + delta + state.nav.length) % state.nav.length;
    if (state.nav[i].counts[state.type] > 0 || state.ignored) return loadDso(state.nav[i].folder);
  }
}
function stepType(delta) {
  const i = TYPES.indexOf(state.type);
  setType(TYPES[(i + delta + TYPES.length) % TYPES.length]);
}
function setType(t) {
  if (t === state.type) return;
  state.type = t; store.set('type', t);
  applyTileSize();
  load();
}
function loadDso(folder) {
  if (!folder) return;
  state.folder = folder;
  store.set('folder', folder);
  $('q').value = dsoLabel(byFolder(folder));
  load();
}

function renderTypes() {
  const d = byFolder(state.folder);
  $('types').innerHTML = TYPES.map(t => {
    const n = d ? d.counts[t] : 0;
    return `<button data-t="${t}" class="${t === state.type ? 'on' : ''} ${n ? '' : 'empty'}">${TYPE_LABEL[t]}<span class="n">${n}</span></button>`;
  }).join('');
}
$('types').addEventListener('click', e => { const b = e.target.closest('button'); if (b) setType(b.dataset.t); });
$('prevDso').addEventListener('click', () => stepDso(-1));
$('nextDso').addEventListener('click', () => stepDso(1));

// ── Options ────────────────────────────────────────────────
function applyTileSize() {
  const sizes = store.get('tile', {});
  const px = sizes[state.type] || TYPE_TILE[state.type];
  document.documentElement.style.setProperty('--tile', px + 'px');
  $('optSize').value = px;
}
$('optSize').addEventListener('input', e => {
  const sizes = store.get('tile', {});
  sizes[state.type] = +e.target.value;
  store.set('tile', sizes);
  document.documentElement.style.setProperty('--tile', e.target.value + 'px');
});
$('optAnn').checked = state.annotated;
$('optIgn').checked = state.ignored;
$('optSort').value = state.sort;
$('optAnn').addEventListener('change', e => { state.annotated = e.target.checked; store.set('annotated', state.annotated); load(); });
$('optIgn').addEventListener('change', e => { state.ignored = e.target.checked; store.set('ignored', state.ignored); load(); });
$('optSort').addEventListener('change', e => { state.sort = e.target.value; store.set('sort', state.sort); render(); });

// ── Loading & rendering ────────────────────────────────────
async function load() {
  renderTypes();
  const id = ++state.reqId;
  history.replaceState(null, '', `${BASE}/${encodeURIComponent(state.folder)}/${state.type}`);
  const d = byFolder(state.folder);
  $('title').textContent = d ? (d.name ? `${d.token} — ${d.name}` : d.folder) : state.folder;
  document.title = `Lightbox · ${d ? d.token : state.folder} · ${TYPE_LABEL[state.type]}`;
  $('grid').innerHTML = '<div class="empty">Loading…</div>';
  try {
    const data = await api({ action: 'images', project: state.folder, type: state.type,
                             annotated: state.annotated ? 1 : 0, ignored: state.ignored ? 1 : 0 });
    if (id !== state.reqId) return;
    state.data = data;
    render();
  } catch (err) {
    if (id !== state.reqId) return;
    state.data = null;
    $('grid').innerHTML = `<div class="empty">${esc(err.message)}</div>`;
    $('sub').textContent = ''; $('missing').textContent = '';
  }
}

function sortedImages() {
  const imgs = state.data.images.slice();
  const byDate = (a, b) => (a.session < b.session ? -1 : a.session > b.session ? 1 : a.file.localeCompare(b.file));
  if (state.sort === 'date-desc') imgs.sort((a, b) => -byDate(a, b));
  else if (state.sort === 'rating') imgs.sort((a, b) => (b.rating || 0) - (a.rating || 0) || byDate(a, b));
  else imgs.sort(byDate);
  return imgs;
}

function render() {
  const data = state.data;
  if (!data) return;
  const ro = !!data.ratingsError;
  $('banner').hidden = !ro;
  $('banner').textContent = data.ratingsError || '';

  const withImg = data.sessions.filter(s => s.count > 0).length;
  const suffix = `_${data.type}${state.annotated ? '_annotated' : ''}`;
  $('sub').innerHTML = `<code>${esc(data.project)}</code> &nbsp; ${data.sessions.length} session${data.sessions.length === 1 ? '' : 's'}, ${withImg} with a ${esc(suffix)}`;
  const missing = data.sessions.filter(s => s.count === 0);
  $('missing').innerHTML = missing.length
    ? 'No ' + esc(suffix) + ' in: ' + missing.map(s => `<span>${esc(s.session)}${s.ignored ? ' (ignored)' : ''}</span>`).join(', ')
    : '';

  state.view = sortedImages();
  const best = Math.max(0, ...state.view.map(i => i.rating || 0));
  if (!state.view.length) {
    $('grid').innerHTML = `<div class="empty">No ${esc(suffix)} images for this DSO.</div>`;
    return;
  }
  $('grid').innerHTML = state.view.map((img, i) => `
    <article class="tile ${best && img.rating === best ? 'top' : ''}" data-i="${i}">
      <div class="frame" style="aspect-ratio:${TYPE_RATIO[data.type]}">
        <span class="badge">★ Best</span>
        <img loading="lazy" src="${esc(imgUrl(img))}" alt="${esc(img.file)}">
      </div>
      <div class="meta">
        <div class="row1">
          <span class="date">${esc(fmtDate(img.date))}</span>
          ${img.rig ? `<span class="chip rig">${esc(img.rig)}</span>` : ''}
          ${img.mosaic ? `<span class="chip">mosaic</span>` : ''}
          ${img.palette ? `<span class="chip pal">${esc(img.palette)}</span>` : ''}
          ${img.onSite ? `<span class="chip site" title="Same file name is in public/images">on site</span>` : ''}
          ${img.ignored ? `<span class="chip ign">ignored</span>` : ''}
        </div>
        <div class="row2">${[
          img.frames ? `${img.frames} × ${img.exposure}s` : '',
          fmtMinutes(img.minutes), aspect(img.width, img.height), fmtBytes(img.bytes),
        ].filter(Boolean).map(esc).join(' · ')}</div>
        <div class="file" title="${esc(img.session + '\\' + img.file)}">${esc(img.file)}</div>
        ${starsHtml(img.rating, ro)}
        <textarea class="note" rows="1" placeholder="Note…" ${ro ? 'readonly' : ''}>${esc(img.note)}</textarea>
      </div>
    </article>`).join('');
}

function starsHtml(rating, ro) {
  let h = `<div class="stars ${ro ? 'ro' : ''}">`;
  for (let n = 1; n <= 5; n++) h += `<button data-n="${n}" class="${rating >= n ? 'lit' : ''}" title="${n} star${n > 1 ? 's' : ''}" tabindex="-1">★</button>`;
  return h + '<span class="st"></span></div>';
}

// ── Saving ratings & notes ─────────────────────────────────
const noteTimers = new Map();

async function save(img, statusEl) {
  if (state.data.ratingsError) return;
  const body = { project: state.data.project, session: img.session, file: img.file, rating: img.rating ?? null, note: img.note || '' };
  if (statusEl) { statusEl.className = 'st'; statusEl.textContent = 'saving…'; }
  try {
    await api({ action: 'rate' }, body);
    if (statusEl) { statusEl.textContent = 'saved'; setTimeout(() => { if (statusEl.textContent === 'saved') statusEl.textContent = ''; }, 1500); }
  } catch (err) {
    if (statusEl) { statusEl.className = 'st err'; statusEl.textContent = err.message; }
  }
}

function setRating(img, n) {
  img.rating = img.rating === n ? null : n;
  const i = state.view.indexOf(img);
  refreshTile(i);
  refreshViewerMeta();
  const tile = $('grid').querySelector(`.tile[data-i="${i}"]`);
  save(img, (state.vIndex === i && !$('viewer').hidden) ? $('vStars').querySelector('.st') : tile?.querySelector('.st'));
}

function setNote(img, text, statusEl) {
  img.note = text;
  clearTimeout(noteTimers.get(img));
  noteTimers.set(img, setTimeout(() => save(img, statusEl), 700));
}

function refreshTile(i) {
  const best = Math.max(0, ...state.view.map(x => x.rating || 0));
  $('grid').querySelectorAll('.tile').forEach(t => {
    const img = state.view[+t.dataset.i];
    t.classList.toggle('top', !!best && img.rating === best);
    t.querySelectorAll('.stars button').forEach(b => b.classList.toggle('lit', (img.rating || 0) >= +b.dataset.n));
    const ta = t.querySelector('.note');
    if (document.activeElement !== ta && ta.value !== (img.note || '')) ta.value = img.note || '';
  });
}

$('grid').addEventListener('click', e => {
  const tile = e.target.closest('.tile');
  if (!tile) return;
  const img = state.view[+tile.dataset.i];
  const star = e.target.closest('.stars button');
  if (star) { if (!state.data.ratingsError) setRating(img, +star.dataset.n); return; }
  if (e.target.closest('.frame')) openViewer(+tile.dataset.i);
});
$('grid').addEventListener('input', e => {
  if (!e.target.classList.contains('note')) return;
  const tile = e.target.closest('.tile');
  setNote(state.view[+tile.dataset.i], e.target.value, tile.querySelector('.st'));
});

// ── Viewer ─────────────────────────────────────────────────
function openViewer(i) {
  state.vIndex = i;
  state.zoom = false;
  $('viewer').hidden = false;
  document.body.style.overflow = 'hidden';
  showViewerImage();
}
function closeViewer() {
  $('viewer').hidden = true;
  document.body.style.overflow = '';
  $('vImg').removeAttribute('src');
  const t = $('grid').querySelector(`.tile[data-i="${state.vIndex}"]`);
  if (t) t.scrollIntoView({ block: 'nearest' });
  state.vIndex = -1;
}
function stepViewer(delta) {
  if (!state.view.length) return;
  state.vIndex = (state.vIndex + delta + state.view.length) % state.view.length;
  showViewerImage();
}
function showViewerImage() {
  const img = state.view[state.vIndex];
  const vi = $('vImg');
  setZoom(false);
  // show the cached preview at once, swap in the original when it arrives
  vi.src = imgUrl(img);
  $('vLoading').textContent = 'loading original…';
  const full = new Image();
  const want = state.vIndex;
  full.onload = () => { if (state.vIndex === want) { vi.src = full.src; $('vLoading').textContent = ''; } };
  full.onerror = () => { if (state.vIndex === want) $('vLoading').textContent = 'original failed to load'; };
  full.src = imgUrl(img, true);
  $('vOpen').href = imgUrl(img, true);
  const d = byFolder(state.folder);
  $('vTitle').textContent = `${d ? d.token : ''} · ${fmtDate(img.date)}`;
  $('vInfo').textContent = [img.rig, img.palette, img.frames ? `${img.frames} × ${img.exposure}s` : '',
                            fmtMinutes(img.minutes), aspect(img.width, img.height), img.file].filter(Boolean).join(' · ');
  $('vCount').textContent = `${state.vIndex + 1} / ${state.view.length}`;
  refreshViewerMeta(true);
}
function refreshViewerMeta(force) {
  if ($('viewer').hidden || state.vIndex < 0) return;
  const img = state.view[state.vIndex];
  const ro = !!state.data.ratingsError;
  if (force || !$('vStars').firstChild) $('vStars').outerHTML = starsHtml(img.rating, ro).replace('class="stars', 'id="vStars" class="stars');
  $('vStars').querySelectorAll('button').forEach(b => b.classList.toggle('lit', (img.rating || 0) >= +b.dataset.n));
  const vn = $('vNote');
  vn.readOnly = ro;
  if (force || document.activeElement !== vn) vn.value = img.note || '';
}
function setZoom(on, ev) {
  state.zoom = on;
  const stage = $('vStage');
  const vi = $('vImg');
  let fx = 0.5, fy = 0.5;
  if (on && ev) {
    const r = vi.getBoundingClientRect();
    fx = (ev.clientX - r.left) / r.width; fy = (ev.clientY - r.top) / r.height;
  }
  stage.classList.toggle('zoom', on);
  $('vZoom').textContent = on ? 'Fit' : '100%';
  if (on) {
    requestAnimationFrame(() => {
      stage.scrollLeft = vi.offsetWidth * fx - stage.clientWidth / 2;
      stage.scrollTop = vi.offsetHeight * fy - stage.clientHeight / 2;
    });
  }
}

$('vImg').addEventListener('click', e => setZoom(!state.zoom, e));
$('vZoom').addEventListener('click', () => setZoom(!state.zoom));
$('vClose').addEventListener('click', closeViewer);
$('vPrev').addEventListener('click', e => { e.stopPropagation(); stepViewer(-1); });
$('vNext').addEventListener('click', e => { e.stopPropagation(); stepViewer(1); });
$('viewer').addEventListener('click', e => {
  const star = e.target.closest('#vStars button');
  if (star && !state.data.ratingsError) setRating(state.view[state.vIndex], +star.dataset.n);
});
$('vNote').addEventListener('input', e => {
  const img = state.view[state.vIndex];
  setNote(img, e.target.value, $('vStars').querySelector('.st'));
  refreshTile(state.vIndex);
});

// drag-to-pan when zoomed
(() => {
  const stage = $('vStage');
  let drag = null;
  stage.addEventListener('mousedown', e => {
    if (!state.zoom || e.target.closest('.v-arrow')) return;
    drag = { x: e.clientX, y: e.clientY, l: stage.scrollLeft, t: stage.scrollTop, moved: false };
    e.preventDefault();
  });
  window.addEventListener('mousemove', e => {
    if (!drag) return;
    const dx = e.clientX - drag.x, dy = e.clientY - drag.y;
    if (Math.abs(dx) + Math.abs(dy) > 4) drag.moved = true;
    stage.scrollLeft = drag.l - dx; stage.scrollTop = drag.t - dy;
  });
  window.addEventListener('mouseup', () => { if (drag && drag.moved) stage.dataset.dragged = '1'; drag = null; });
  stage.addEventListener('click', e => {
    if (stage.dataset.dragged) { delete stage.dataset.dragged; e.stopPropagation(); }
  }, true);
})();

// ── Keyboard ───────────────────────────────────────────────
document.addEventListener('keydown', e => {
  const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName)
                 && document.activeElement.type !== 'checkbox' && document.activeElement.type !== 'range';
  if (e.ctrlKey || e.metaKey || e.altKey) return;

  if (!$('viewer').hidden) {
    if (e.key === 'Escape') { if (typing) document.activeElement.blur(); else closeViewer(); e.preventDefault(); return; }
    if (typing) return;
    const img = state.view[state.vIndex];
    if (e.key === 'ArrowLeft') stepViewer(-1);
    else if (e.key === 'ArrowRight') stepViewer(1);
    else if (/^[1-5]$/.test(e.key) && !state.data.ratingsError) { img.rating = null; setRating(img, +e.key); }
    else if (e.key === '0' && !state.data.ratingsError) { if (img.rating) setRating(img, img.rating); }  // same star again = clear
    else if (e.key === 'z') setZoom(!state.zoom);
    else if (e.key === 'n') { $('vNote').focus(); }
    else return;
    e.preventDefault();
    return;
  }

  if (typing) {
    if (e.key === 'Escape' && document.activeElement.classList.contains('note')) document.activeElement.blur();
    return;
  }
  if (e.key === 'ArrowLeft') stepDso(-1);
  else if (e.key === 'ArrowRight') stepDso(1);
  else if (e.key === 'ArrowUp') stepType(-1);
  else if (e.key === 'ArrowDown') stepType(1);
  else if (e.key === '/') $('q').focus();
  else if (e.key === 'a') $('optAnn').click();
  else if (e.key === 'i') $('optIgn').click();
  else return;
  e.preventDefault();
});

// ── Start ──────────────────────────────────────────────────
(async () => {
  applyTileSize();
  try {
    state.dsos = (await api({ action: 'dsos' })).dsos;
  } catch (err) {
    $('title').textContent = 'Lightbox';
    $('grid').innerHTML = `<div class="empty">${esc(err.message)}</div>`;
    return;
  }
  state.nav = state.dsos.slice().sort((a, b) => a.folder.toLowerCase().localeCompare(b.folder.toLowerCase()));

  let folder = null;
  if (INIT.q) {
    const r = resolve(INIT.q);
    folder = r.match;
    if (!folder) {
      $('title').textContent = `Nothing matches “${INIT.q}”`;
      $('grid').innerHTML = '<div class="empty">Type a fragment in the search box (or press <kbd>/</kbd>).</div>';
      renderTypes();
      $('q').focus();
      return;
    }
  }
  folder = folder || (byFolder(store.get('folder', '')) ? store.get('folder', '') : null)
                  || (state.nav.find(d => d.counts[state.type] > 0) || state.nav[0])?.folder;
  if (folder) loadDso(folder);
})();
</script>
</body>
</html>
