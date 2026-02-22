<?php require_once __DIR__ . '/auth.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>DSO Admin</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/png" href="favicon.png">
<style>
  /* ── Reset / Base ── */
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --bg:       #0d1117;
    --surface:  #161b22;
    --border:   #30363d;
    --accent:   #58a6ff;
    --accent2:  #3fb950;
    --warn:     #d29922;
    --danger:   #f85149;
    --text:     #c9d1d9;
    --muted:    #8b949e;
    --radius:   6px;
    --font:     'Segoe UI', system-ui, sans-serif;
  }
  body { background: var(--bg); color: var(--text); font-family: var(--font); font-size: 14px; line-height: 1.5; }

  /* ── Layout ── */
  header { background: var(--surface); border-bottom: 1px solid var(--border); padding: 12px 24px; display: flex; align-items: center; gap: 12px; }
  header h1 { font-size: 18px; font-weight: 600; color: var(--accent); }
  header .subtitle { color: var(--muted); font-size: 13px; }

  .app { display: grid; grid-template-columns: 320px 1fr; height: calc(100vh - 52px); }

  /* ── Sidebar ── */
  .sidebar { background: var(--surface); border-right: 1px solid var(--border); display: flex; flex-direction: column; overflow: hidden; }
  .sidebar-search { padding: 12px; border-bottom: 1px solid var(--border); }
  .sidebar-search input {
    width: 100%; background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius);
    color: var(--text); padding: 7px 10px; font-size: 13px; outline: none;
  }
  .sidebar-search input:focus { border-color: var(--accent); }
  .sidebar-search .hint { color: var(--muted); font-size: 11px; margin-top: 5px; padding: 0 2px; }

  .object-list { overflow-y: auto; flex: 1; }
  .object-item {
    padding: 9px 14px; border-bottom: 1px solid var(--border); cursor: pointer;
    display: flex; justify-content: space-between; align-items: center; gap: 8px;
    transition: background 0.1s;
  }
  .object-item:hover { background: rgba(88,166,255,0.07); }
  .object-item.active { background: rgba(88,166,255,0.13); border-left: 3px solid var(--accent); }
  .object-item .obj-key { font-weight: 600; font-size: 13px; color: var(--accent); }
  .object-item .obj-name { color: var(--text); font-size: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .object-item .obj-type { color: var(--muted); font-size: 11px; flex-shrink: 0; }
  .object-item .completeness { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
  .completeness.full  { background: var(--accent2); }
  .completeness.partial { background: var(--warn); }
  .completeness.empty { background: var(--danger); }

  .sidebar-footer { padding: 10px 14px; border-top: 1px solid var(--border); }
  .btn-new {
    width: 100%; background: var(--accent); border: none; border-radius: var(--radius);
    color: #000; font-weight: 600; font-size: 13px; padding: 8px; cursor: pointer;
  }
  .btn-new:hover { opacity: 0.85; }

  /* ── Main / Form panel ── */
  .main { overflow-y: auto; padding: 24px; display: flex; flex-direction: column; gap: 20px; }

  .empty-state { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; color: var(--muted); gap: 8px; }
  .empty-state .icon { font-size: 40px; opacity: 0.3; }

  .form-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; }
  .form-title { font-size: 20px; font-weight: 700; color: var(--text); }
  .form-title span { color: var(--muted); font-size: 14px; font-weight: 400; margin-left: 8px; }
  .form-actions { display: flex; gap: 8px; flex-wrap: wrap; }

  .btn {
    border: 1px solid var(--border); border-radius: var(--radius);
    padding: 6px 14px; font-size: 13px; font-weight: 500; cursor: pointer;
    background: var(--surface); color: var(--text); transition: background 0.1s, border-color 0.1s;
    white-space: nowrap;
  }
  .btn:hover { border-color: var(--accent); color: var(--accent); }
  .btn.primary { background: var(--accent); border-color: var(--accent); color: #000; }
  .btn.primary:hover { opacity: 0.85; color: #000; }
  .btn.success { background: var(--accent2); border-color: var(--accent2); color: #000; }
  .btn.success:hover { opacity: 0.85; color: #000; }
  .btn.warn { background: var(--warn); border-color: var(--warn); color: #000; }
  .btn:disabled { opacity: 0.4; cursor: default; }

  /* ── Form sections ── */
  .section { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
  .section-header {
    background: rgba(255,255,255,0.03); border-bottom: 1px solid var(--border);
    padding: 10px 16px; font-weight: 600; font-size: 13px; color: var(--muted);
    text-transform: uppercase; letter-spacing: 0.05em;
    display: flex; justify-content: space-between; align-items: center;
  }
  .section-body { padding: 16px; display: grid; gap: 12px; }

  .grid-2 { grid-template-columns: 1fr 1fr; }
  .grid-3 { grid-template-columns: 1fr 1fr 1fr; }
  .span-2 { grid-column: span 2; }
  .span-3 { grid-column: span 3; }

  .field { display: flex; flex-direction: column; gap: 4px; }
  .field label { font-size: 12px; color: var(--muted); font-weight: 500; }
  .field input, .field select, .field textarea {
    background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius);
    color: var(--text); padding: 7px 10px; font-size: 13px; font-family: var(--font);
    outline: none; transition: border-color 0.15s; width: 100%;
  }
  .field input:focus, .field select:focus, .field textarea:focus { border-color: var(--accent); }
  .field textarea { resize: vertical; min-height: 80px; line-height: 1.6; }
  .field .note { font-size: 11px; color: var(--muted); margin-top: 2px; }

  /* social blurb gets more height */
  #f_SocialBlurb { min-height: 160px; }

  /* Catalog IDs mini-table */
  .cat-table { width: 100%; border-collapse: collapse; font-size: 12px; }
  .cat-table th { color: var(--muted); font-weight: 500; text-align: left; padding: 4px 8px; border-bottom: 1px solid var(--border); }
  .cat-table td { padding: 5px 8px; border-bottom: 1px solid rgba(48,54,61,0.5); vertical-align: middle; }
  .cat-table input { background: var(--bg); border: 1px solid var(--border); border-radius: 4px; color: var(--text); padding: 3px 6px; font-size: 12px; width: 100%; }
  .cat-table input:focus { border-color: var(--accent); outline: none; }
  .cat-badge { display: inline-block; background: rgba(88,166,255,0.15); color: var(--accent); border-radius: 4px; padding: 1px 6px; font-size: 11px; }
  .btn-icon { background: none; border: none; cursor: pointer; color: var(--danger); font-size: 14px; padding: 2px 6px; border-radius: 4px; }
  .btn-icon:hover { background: rgba(248,81,73,0.15); }
  .btn-add-cat { background: none; border: 1px dashed var(--border); border-radius: var(--radius); color: var(--muted); padding: 5px 10px; font-size: 12px; cursor: pointer; margin-top: 8px; width: 100%; }
  .btn-add-cat:hover { border-color: var(--accent); color: var(--accent); }

  /* ── Toast / Status ── */
  #toast {
    position: fixed; bottom: 24px; right: 24px; padding: 10px 18px;
    border-radius: var(--radius); font-size: 13px; font-weight: 500;
    opacity: 0; transition: opacity 0.25s; pointer-events: none; z-index: 1000;
  }
  #toast.show { opacity: 1; }
  #toast.ok   { background: var(--accent2); color: #000; }
  #toast.err  { background: var(--danger);  color: #fff; }
  #toast.info { background: var(--accent);  color: #000; }

  /* ── Spinner ── */
  .spinner { display: inline-block; width: 14px; height: 14px; border: 2px solid rgba(0,0,0,0.3); border-top-color: #000; border-radius: 50%; animation: spin 0.6s linear infinite; vertical-align: middle; margin-right: 5px; }
  @keyframes spin { to { transform: rotate(360deg); } }

  /* ── Responsive collapse ── */
  @media (max-width: 900px) {
    .app { grid-template-columns: 1fr; }
    .sidebar { height: 250px; }
    .grid-3 { grid-template-columns: 1fr 1fr; }
    .span-3 { grid-column: span 2; }
  }
  @media (max-width: 600px) {
    .grid-2, .grid-3 { grid-template-columns: 1fr; }
    .span-2, .span-3 { grid-column: span 1; }
  }
</style>
</head>
<body>

<header>
  <h1>⭐ DSO Admin</h1>
  <span class="subtitle">Deep Sky Object Database Maintenance</span>
  <a href="logout.php" style="margin-left:auto; font-size:12px; color:var(--muted); text-decoration:none;" onmouseover="this.style.color='var(--accent)'" onmouseout="this.style.color='var(--muted)'">Sign out</a>
</header>

<div class="app">

  <!-- ── Sidebar ── -->
  <aside class="sidebar">
    <div class="sidebar-search">
      <input type="text" id="search" placeholder="Search by catalog ID, name…" autocomplete="off">
      <div class="hint" id="search-hint">Type to search, or leave blank to list all</div>
    </div>
    <div class="object-list" id="object-list">
      <!-- populated by JS -->
    </div>
    <div class="sidebar-footer">
      <button class="btn-new" onclick="newObject()">+ New Object</button>
    </div>
  </aside>

  <!-- ── Main panel ── -->
  <main class="main" id="main-panel">
    <div class="empty-state" id="empty-state">
      <div class="icon">🔭</div>
      <div>Select an object or create a new one</div>
    </div>

    <!-- Object editor (hidden until an object is selected/created) -->
    <div id="editor" style="display:none; display:none;" class="editor-wrap">

      <div class="form-header">
        <div>
          <div class="form-title" id="editor-title">New Object <span id="editor-subtitle"></span></div>
        </div>
        <div class="form-actions">
          <button class="btn warn" id="btn-ai" onclick="aiPopulate()">🤖 AI Populate</button>
          <button class="btn success" onclick="saveObject()">💾 Save</button>
        </div>
      </div>

      <!-- Identity -->
      <div class="section">
        <div class="section-header">Identity</div>
        <div class="section-body grid-3">
          <div class="field">
            <label>DSO Key <em style="color:var(--danger)">*</em></label>
            <input type="text" id="f_DSOKey" placeholder="e.g. NGC1976" style="text-transform:uppercase">
            <div class="note">Canonical primary key — usually the primary catalog ID</div>
          </div>
          <div class="field">
            <label>Common Name</label>
            <input type="text" id="f_CommonName" placeholder="e.g. Orion Nebula">
          </div>
          <div class="field">
            <label>Object Type</label>
            <select id="f_ObjectTypeID">
              <option value="">— loading —</option>
            </select>
          </div>
          <div class="field">
            <label>Constellation</label>
            <select id="f_ConstellationID">
              <option value="">— select —</option>
              <option value="AND">Andromeda</option><option value="AQL">Aquila</option>
              <option value="AQR">Aquarius</option><option value="ARI">Aries</option>
              <option value="AUR">Auriga</option><option value="CMA">Canis Major</option>
              <option value="CMI">Canis Minor</option><option value="CNC">Cancer</option>
              <option value="CAS">Cassiopeia</option><option value="CEP">Cepheus</option>
              <option value="CVN">Canes Venatici</option><option value="CYG">Cygnus</option>
              <option value="DOR">Dorado</option><option value="ERI">Eridanus</option>
              <option value="FOR">Fornax</option><option value="GEM">Gemini</option>
              <option value="HER">Hercules</option><option value="LEO">Leo</option>
              <option value="LYR">Lyra</option><option value="MON">Monoceros</option>
              <option value="ORI">Orion</option><option value="PEG">Pegasus</option>
              <option value="PER">Perseus</option><option value="PSC">Pisces</option>
              <option value="SGR">Sagittarius</option><option value="SER">Serpens</option>
              <option value="TAU">Taurus</option><option value="TRI">Triangulum</option>
              <option value="UMA">Ursa Major</option><option value="UMI">Ursa Minor</option>
              <option value="VIR">Virgo</option><option value="VUL">Vulpecula</option>
            </select>
          </div>
          <div class="field">
            <label>Distance</label>
            <input type="text" id="f_DistanceLY" placeholder="e.g. ~1,350 light-years">
          </div>
          <div class="field">
            <!-- spacer -->
          </div>
        </div>
      </div>

      <!-- Astrometrics -->
      <div class="section">
        <div class="section-header">Astrometrics</div>
        <div class="section-body grid-3">
          <div class="field">
            <label>RA (decimal hours)</label>
            <input type="number" id="f_RAHours" step="0.0001" min="0" max="24" placeholder="e.g. 5.5912">
            <div class="note">0.0000 – 24.0000</div>
          </div>
          <div class="field">
            <label>Dec (decimal degrees)</label>
            <input type="number" id="f_DecDegrees" step="0.0001" min="-90" max="90" placeholder="e.g. -5.3897">
            <div class="note">−90.0000 – +90.0000</div>
          </div>
          <div class="field">
            <label>Magnitude</label>
            <input type="number" id="f_Magnitude" step="0.1" placeholder="e.g. 4.0">
          </div>
          <div class="field span-3">
            <label>Object Size</label>
            <input type="text" id="f_ObjectSize" placeholder="e.g. 70 light-years across with an apparent diameter of 45-50 arcminutes, about 1.5× the full moon">
            <div class="note">Physical size, angular size, and moon comparison in one plain-English sentence.</div>
          </div>
        </div>
      </div>

      <!-- Observation & Project -->
      <div class="section">
        <div class="section-header">Observation &amp; Project</div>
        <div class="section-body grid-3">
          <div class="field span-2">
            <label>Project Folder</label>
            <input type="text" id="f_ProjectFolder" placeholder="e.g. NGC1976_OrionNebula">
            <div class="note">Folder name under C:\Astronomy\myWorks</div>
          </div>
          <div class="field">
            <label>Most Recent Observation</label>
            <input type="date" id="f_MostRecentObservation">
          </div>
          <div class="field">
            <label>Is Mosaic?</label>
            <select id="f_IsMosaic">
              <option value="0">No</option>
              <option value="1">Yes</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Social Blurb -->
      <div class="section">
        <div class="section-header">
          Social Blurb
          <button class="btn" style="font-size:11px; padding:3px 10px;" onclick="aiGenerateBlurb()">🤖 Regenerate with AI</button>
        </div>
        <div class="section-body">
          <div class="field">
            <textarea id="f_SocialBlurb" placeholder="Two paragraphs of engaging prose describing the object — what it is, its physical nature, distance, and what makes it special to image. This is the basis for social media captions."></textarea>
            <div class="note">Used as the basis for social media posts. Paragraph break = blank line.</div>
          </div>
        </div>
      </div>

      <!-- Catalog IDs -->
      <div class="section">
        <div class="section-header">Catalog IDs</div>
        <div class="section-body">
          <table class="cat-table" id="cat-table">
            <thead>
              <tr>
                <th>Catalog ID</th>
                <th>Primary?</th>
                <th></th>
              </tr>
            </thead>
            <tbody id="cat-tbody">
            </tbody>
          </table>
          <button class="btn-add-cat" onclick="addCatRow()">+ Add Catalog ID</button>
        </div>
      </div>

    </div><!-- /editor -->
  </main>
</div><!-- /app -->

<div id="toast"></div>

<script>
// ──────────────────────────────────────────────
// State
// ──────────────────────────────────────────────
let currentObject = null;   // The object currently loaded in the editor
let searchTimer   = null;
let blurbWatchSnapshot = {}; // Snapshot of fields that affect SocialBlurb

const BLURB_WATCH = ['CommonName', 'ObjectSize', 'ConstellationID'];

function snapshotBlurbFields() {
  const snap = {};
  BLURB_WATCH.forEach(f => {
    const el = document.getElementById('f_' + f);
    snap[f] = el ? el.value.trim() : '';
  });
  blurbWatchSnapshot = snap;
}

function blurbFieldsChanged() {
  return BLURB_WATCH.some(f => {
    const el = document.getElementById('f_' + f);
    return el && el.value.trim() !== (blurbWatchSnapshot[f] ?? '');
  });
}

const fields = ['DSOKey','CommonName','ObjectTypeID','ConstellationID',
                'RAHours','DecDegrees','Magnitude','ObjectSize','DistanceLY',
                'SocialBlurb','ProjectFolder','IsMosaic','MostRecentObservation'];

// ──────────────────────────────────────────────
// Fetch wrapper — redirects to login on 401
// ──────────────────────────────────────────────
async function apiFetch(url, options = {}) {
  // Ensure session cookie is always sent with same-origin requests
  options.credentials = 'same-origin';
  const res = await fetch(url, options);
  if (res.status === 401) {
    window.location.href = 'index.php?expired=1';
    // Return a promise that never resolves so the calling code stops cleanly
    return new Promise(() => {});
  }
  return res;
}

// ──────────────────────────────────────────────
// Toast helper
// ──────────────────────────────────────────────
function toast(msg, type = 'ok', duration = 3000) {
  const el = document.getElementById('toast');
  el.textContent = msg;
  el.className = 'show ' + type;
  clearTimeout(el._t);
  el._t = setTimeout(() => el.className = '', duration);
}

// ──────────────────────────────────────────────
// Search / List
// ──────────────────────────────────────────────
document.getElementById('search').addEventListener('input', function () {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => fetchList(this.value.trim()), 250);
});

async function fetchList(q = '') {
  const res  = await apiFetch('api_search.php?q=' + encodeURIComponent(q));
  const rows = await res.json();
  renderList(rows);
  const count = Array.isArray(rows) ? rows.length : 0;
  document.getElementById('search-hint').textContent = Array.isArray(rows)
    ? count + ' object' + (count === 1 ? '' : 's') + ' found'
    : 'Error loading objects — check console';
}

function renderList(rows) {
  const ul = document.getElementById('object-list');
  ul.innerHTML = '';
  if (!Array.isArray(rows) || !rows.length) {
    ul.innerHTML = '<div style="padding:16px; color:var(--muted); font-size:13px;">' + (Array.isArray(rows) ? 'No objects found' : 'Error loading objects') + '</div>';
    return;
  }
  rows.forEach(row => {
    const div = document.createElement('div');
    div.className = 'object-item' + (currentObject?.DSOKey === row.DSOKey ? ' active' : '');
    div.dataset.key = row.DSOKey;

    // Completeness dot
    const filled = [row.RAHours, row.DecDegrees, row.Magnitude, row.ObjectSize, row.SocialBlurb].filter(v => v !== null && v !== '').length;
    const cls    = filled >= 4 ? 'full' : filled >= 2 ? 'partial' : 'empty';

    div.innerHTML = `
      <div style="min-width:0; flex:1">
        <div class="obj-key">${row.DSOKey}</div>
        <div class="obj-name">${row.CommonName || '—'}</div>
      </div>
      <div style="display:flex; align-items:center; gap:6px; flex-shrink:0">
        <span class="obj-type">${row.ConstellationID || ''}</span>
        <span class="completeness ${cls}" title="Field completeness"></span>
      </div>
    `;
    div.addEventListener('click', () => loadObject(row));
    ul.appendChild(div);
  });
}

// ──────────────────────────────────────────────
// Load ObjectTypes dropdown from DB
// ──────────────────────────────────────────────
async function loadObjectTypes(selectedValue = '') {
  const sel = document.getElementById('f_ObjectTypeID');
  try {
    const res  = await apiFetch('api_object_types.php');
    const data = await res.json();
    if (data.error) throw new Error(data.error);

    sel.innerHTML = '<option value="">— select —</option>';
    Object.entries(data).forEach(([category, types]) => {
      const grp = document.createElement('optgroup');
      grp.label = category;
      types.forEach(t => {
        const opt = document.createElement('option');
        opt.value = t.id;
        opt.textContent = t.name;
        if (t.id === selectedValue) opt.selected = true;
        grp.appendChild(opt);
      });
      sel.appendChild(grp);
    });
  } catch (e) {
    sel.innerHTML = '<option value="">— error loading —</option>';
    console.error('Failed to load object types:', e);
  }
}

// Populate list on load
fetchList('');
loadObjectTypes();

// ──────────────────────────────────────────────
// Load object into editor
// ──────────────────────────────────────────────
function loadObject(row) {
  currentObject = row;
  document.querySelectorAll('.object-item').forEach(el => {
    el.classList.toggle('active', el.dataset.key === row.DSOKey);
  });
  showEditor();
  document.getElementById('editor-title').childNodes[0].textContent = row.DSOKey + ' ';
  document.getElementById('editor-subtitle').textContent = row.CommonName || '';
  document.getElementById('f_DSOKey').value = row.DSOKey;
  document.getElementById('f_DSOKey').disabled = true; // can't change key of existing object

  fields.filter(f => f !== 'DSOKey' && f !== 'ObjectTypeID').forEach(f => {
    const el = document.getElementById('f_' + f);
    if (el) el.value = row[f] ?? '';
  });
  // Re-render dropdown with this object's type selected
  loadObjectTypes(row.ObjectTypeID || '');

  renderCatalogTable(row.CatalogIDs || []);
  snapshotBlurbFields();
}

function newObject() {
  currentObject = null;
  document.querySelectorAll('.object-item').forEach(el => el.classList.remove('active'));
  showEditor();
  document.getElementById('editor-title').textContent = 'New Object';
  document.getElementById('editor-subtitle').textContent = '';
  fields.forEach(f => {
    const el = document.getElementById('f_' + f);
    if (el) { el.value = ''; el.disabled = false; }
  });
  document.getElementById('f_DSOKey').disabled = false;
  renderCatalogTable([]);
  snapshotBlurbFields();
}

function showEditor() {
  document.getElementById('empty-state').style.display  = 'none';
  document.getElementById('editor').style.display       = 'flex';
  document.getElementById('editor').style.flexDirection = 'column';
  document.getElementById('editor').style.gap           = '20px';
}

// ──────────────────────────────────────────────
// Catalog ID mini-table
// ──────────────────────────────────────────────
function renderCatalogTable(cats) {
  const tbody = document.getElementById('cat-tbody');
  tbody.innerHTML = '';
  cats.forEach((cat, i) => addCatRow(cat));
}

function addCatRow(cat = {}) {
  const tbody = document.getElementById('cat-tbody');
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td><input type="text" placeholder="e.g. M42" value="${cat.CatalogID || ''}" class="cat-id" style="text-transform:uppercase"></td>
    <td style="text-align:center"><input type="checkbox" class="cat-primary" ${cat.IsPrimary ? 'checked' : ''}></td>
    <td><button class="btn-icon" onclick="this.closest('tr').remove()" title="Remove">✕</button></td>
  `;
  tbody.appendChild(tr);
}

function getCatalogRows() {
  return Array.from(document.querySelectorAll('#cat-tbody tr')).map(tr => ({
    CatalogID: tr.querySelector('.cat-id').value.trim().toUpperCase(),
    IsPrimary: tr.querySelector('.cat-primary').checked ? 1 : 0,
  })).filter(r => r.CatalogID);
}

// ──────────────────────────────────────────────
// Save
// ──────────────────────────────────────────────
async function saveObject(silent = false) {
  const dsoKey = document.getElementById('f_DSOKey').value.trim().toUpperCase();
  if (!dsoKey) { toast('DSO Key is required', 'err'); return; }

  // If any blurb-affecting fields changed, regenerate before saving
  if (blurbFieldsChanged() && document.getElementById('f_SocialBlurb').value.trim() !== '') {
    toast('Key fields changed — regenerating Social Blurb…', 'info', 6000);
    await aiGenerateBlurb();
  }

  const payload = { DSOKey: dsoKey };
  fields.filter(f => f !== 'DSOKey').forEach(f => {
    const el = document.getElementById('f_' + f);
    if (!el) return;
    let v = el.value.trim();
    // Convert numeric fields
    if (['RAHours','DecDegrees','Magnitude'].includes(f)) {
      payload[f] = v === '' ? null : parseFloat(v);
    } else {
      payload[f] = v === '' ? null : v;
    }
  });
  payload.CatalogIDs = getCatalogRows();

  try {
    const res  = await apiFetch('api_save.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
    const data = await res.json();
    if (data.success) {
      if (!silent) toast('Saved successfully', 'ok');
      snapshotBlurbFields(); // Reset watch so next save doesn't re-trigger
      // Refresh the list and keep current selection
      const q = document.getElementById('search').value.trim();
      await fetchList(q);
    } else {
      toast('Save failed: ' + (data.error || 'Unknown error'), 'err', 5000);
    }
  } catch (e) {
    toast('Network error: ' + e.message, 'err', 5000);
  }
}

// ──────────────────────────────────────────────
// AI: Populate all fields
// ──────────────────────────────────────────────
async function aiPopulate() {
  const dsoId = document.getElementById('f_DSOKey').value.trim().toUpperCase();
  if (!dsoId) { toast('Enter a DSO Key first', 'err'); return; }

  const btn = document.getElementById('btn-ai');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Searching…';
  toast('Asking AI about ' + dsoId + '…', 'info', 15000);

  // Pass current form values so the AI uses them as ground truth for the blurb
  const primaryRow = Array.from(document.querySelectorAll('#cat-tbody tr'))
    .find(tr => tr.querySelector('.cat-primary')?.checked);
  const primaryCatalogID = primaryRow ? primaryRow.querySelector('.cat-id').value.trim().toUpperCase() : dsoId;

  const context = {
    dso_id:             dsoId,
    primary_catalog_id: primaryCatalogID,
    common_name:        document.getElementById('f_CommonName').value.trim(),
    constellation:      document.getElementById('f_ConstellationID').value.trim(),
    object_size:        document.getElementById('f_ObjectSize').value.trim(),
    distance:           document.getElementById('f_DistanceLY').value.trim(),
  };

  try {
    const res  = await apiFetch('api_populate.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(context) });
    const data = await res.json();

    if (!data.success) {
      const detail = data.detail ? JSON.parse(data.detail)?.error?.message : null;
      toast('AI error: ' + (detail || data.error || 'Unknown error'), 'err', 10000);
      console.error('AI populate error:', data);
      return;
    }

    const f = data.fields;
    // Fields to populate — only fill if currently empty, EXCEPT SocialBlurb which always gets regenerated
    const fieldMap = {
      CommonName:      'f_CommonName',
      ObjectTypeID:    'f_ObjectTypeID',
      ConstellationID: 'f_ConstellationID',
      RAHours:         'f_RAHours',
      DecDegrees:      'f_DecDegrees',
      Magnitude:       'f_Magnitude',
      ObjectSize:      'f_ObjectSize',
      DistanceLY:      'f_DistanceLY',
    };
    let populated = 0;
    Object.entries(fieldMap).forEach(([key, elId]) => {
      const el = document.getElementById(elId);
      const aiVal = f[key];
      // Only fill if AI returned a value AND the field is currently empty
      if (aiVal !== null && aiVal !== undefined && aiVal !== '' && el.value.trim() === '') {
        el.value = aiVal;
        populated++;
      }
    });
    // Always regenerate SocialBlurb
    if (f.SocialBlurb) {
      document.getElementById('f_SocialBlurb').value = f.SocialBlurb;
      populated++;
    }

    // If AI returned CatalogIDs and the table is currently empty, populate it
    if (Array.isArray(f.CatalogIDs) && f.CatalogIDs.length > 0 && getCatalogRows().length === 0) {
      renderCatalogTable(f.CatalogIDs);
      populated++;
    }

    // Auto-save to DB before displaying so data isn't lost if user navigates away
    toast('Auto-saving…', 'info', 3000);
    await saveObject(silent = true);
    toast('AI filled ' + populated + ' field(s) and saved — edit and save again if needed', 'ok', 5000);

  } catch (e) {
    toast('Network error: ' + e.message, 'err', 5000);
  } finally {
    btn.disabled = false;
    btn.innerHTML = '🤖 AI Populate';
  }
}

// ──────────────────────────────────────────────
// AI: Regenerate SocialBlurb only
// ──────────────────────────────────────────────
async function aiGenerateBlurb() {
  const dsoId = document.getElementById('f_DSOKey').value.trim().toUpperCase();
  if (!dsoId) { toast('Enter a DSO Key first', 'err'); return; }

  // Pass current form values so the AI uses them as ground truth
  // Find the primary catalog ID from the catalog table, fall back to DSOKey
  const primaryRow = Array.from(document.querySelectorAll('#cat-tbody tr'))
    .find(tr => tr.querySelector('.cat-primary')?.checked);
  const primaryCatalogID = primaryRow ? primaryRow.querySelector('.cat-id').value.trim().toUpperCase() : dsoId;

  const context = {
    dso_id:            dsoId,
    primary_catalog_id: primaryCatalogID,
    common_name:       document.getElementById('f_CommonName').value.trim(),
    constellation:     document.getElementById('f_ConstellationID').value.trim(),
    object_size:       document.getElementById('f_ObjectSize').value.trim(),
    distance:          document.getElementById('f_DistanceLY').value.trim(),
  };

  toast('Generating social blurb for ' + dsoId + '…', 'info', 15000);

  try {
    const res  = await apiFetch('api_populate.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(context) });
    const data = await res.json();

    if (!data.success) { toast('AI error: ' + (data.error || ''), 'err', 6000); return; }

    if (data.fields?.SocialBlurb) {
      document.getElementById('f_SocialBlurb').value = data.fields.SocialBlurb;
      toast('Blurb generated — review and save', 'ok');
    } else {
      toast('AI did not return a blurb', 'err');
    }
  } catch (e) {
    toast('Network error: ' + e.message, 'err', 5000);
  }
}

// Auto-uppercase DSO Key as user types
document.getElementById('f_DSOKey').addEventListener('input', function () {
  const pos = this.selectionStart;
  this.value = this.value.toUpperCase();
  this.setSelectionRange(pos, pos);
});
</script>
</body>
</html>
