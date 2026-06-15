<?php
require_once __DIR__ . '/auth.php';
// Always serve fresh — prevents stale JS/HTML after deployments
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
?>
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
  .btn.danger { background: var(--danger); border-color: var(--danger); color: #fff; }
  .btn.danger:hover { opacity: 0.85; color: #fff; }
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

  /* notes — fixed 3-line height */
  #f_Notes { min-height: 62px; max-height: 62px; resize: none; }

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

  /* ── Gallery Images cards ── */
  .gi-list { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
  .gi-card {
    background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius);
    padding: 12px 14px; position: relative;
  }
  .gi-card.is-feature { border-color: var(--accent2); }
  .gi-card-header {
    display: flex; align-items: center; gap: 10px; margin-bottom: 10px;
    font-size: 12px; color: var(--muted);
  }
  .gi-card-header .gi-basename {
    font-weight: 600; color: var(--text); font-size: 13px; flex: 1;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  }
  .gi-feature-badge {
    background: rgba(63,185,80,0.15); color: var(--accent2);
    border-radius: 4px; padding: 1px 7px; font-size: 11px; font-weight: 600;
    white-space: nowrap;
  }
  .gi-preview {
    aspect-ratio: 4 / 5; width: 100%; margin-bottom: 12px;
    border: 1px solid rgba(48,54,61,0.85); border-radius: var(--radius);
    background: #05070a; position: relative; overflow: hidden;
    display: flex; align-items: center; justify-content: center;
  }
  .gi-preview img { width: 100%; height: 100%; object-fit: contain; display: block; }
  .gi-preview-empty {
    color: var(--muted); font-size: 12px; text-align: center;
    padding: 12px; line-height: 1.4; display: none;
  }
  .gi-preview.missing img { display: none; }
  .gi-preview.missing .gi-preview-empty { display: block; }
  .gi-preview.missing .gi-preview-empty { color: var(--danger); }
  .gi-card-fields { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
  .gi-card-fields .field label { font-size: 11px; }
  .gi-card-fields .field input,
  .gi-card-fields .field select { font-size: 12px; padding: 5px 8px; }
  .gi-card-actions {
    display: flex; gap: 8px; align-items: center; margin-top: 10px;
    padding-top: 10px; border-top: 1px solid var(--border);
  }
  .gi-attribution-row { display: none; }
  .gi-attribution-row.visible { display: contents; }

  /* ── DSO Links table ── */
  .lnk-table { width: 100%; border-collapse: collapse; font-size: 12px; }
  .lnk-table th { color: var(--muted); font-weight: 500; text-align: left; padding: 4px 8px; border-bottom: 1px solid var(--border); }
  .lnk-table td { padding: 5px 8px; border-bottom: 1px solid rgba(48,54,61,0.5); vertical-align: middle; }
  .lnk-table input { background: var(--bg); border: 1px solid var(--border); border-radius: 4px; color: var(--text); padding: 3px 6px; font-size: 12px; width: 100%; }
  .lnk-table input:focus { border-color: var(--accent); outline: none; }

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

  /* Tablet portrait (600px-900px): keep two columns but narrow the sidebar */
  @media (max-width: 900px) {
    .app { grid-template-columns: 220px 1fr; }
    .grid-3 { grid-template-columns: 1fr 1fr; }
    .span-3 { grid-column: span 2; }
    .gi-list { grid-template-columns: 1fr; }
  }

  /* Phone (<600px): stack sidebar above form */
  @media (max-width: 600px) {
    .app { grid-template-columns: 1fr; }
    .sidebar { height: 280px; }
    .grid-2, .grid-3 { grid-template-columns: 1fr; }
    .span-2, .span-3 { grid-column: span 1; }
    .gi-card-fields { grid-template-columns: 1fr; }
    .gi-card-actions { align-items: flex-start; flex-wrap: wrap; }
  }
</style>
</head>
<body>

<header>
  <h1>&#11088; DSO Admin</h1>
  <span class="subtitle">Deep Sky Object Database Maintenance</span>
  <a href="logout.php" style="margin-left:auto; font-size:12px; color:var(--muted); text-decoration:none;" onmouseover="this.style.color='var(--accent)'" onmouseout="this.style.color='var(--muted)'">Sign out</a>
</header>

<div class="app">

  <!-- ── Sidebar ── -->
  <aside class="sidebar">
    <div class="sidebar-search">
      <input type="text" id="search" placeholder="Search by catalog ID, name&#8230;" autocomplete="off">
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
      <div class="icon">&#128301;</div>
      <div>Select an object or create a new one</div>
    </div>

    <!-- Object editor (hidden until an object is selected/created) -->
    <div id="editor" style="display:none; display:none;" class="editor-wrap">

      <div class="form-header">
        <div>
          <div class="form-title" id="editor-title">New Object <span id="editor-subtitle"></span></div>
        </div>
        <div class="form-actions">
          <button class="btn warn" id="btn-ai" onclick="aiPopulate()">&#129302; AI Populate</button>
          <button class="btn success" onclick="saveObject()">&#128190; Save</button>
          <button class="btn danger" id="btn-delete" onclick="deleteObject()" disabled>&#128465;&#65039; Delete Object</button>
        </div>
      </div>

      <!-- Identity -->
      <div class="section">
        <div class="section-header">Identity</div>
        <div class="section-body grid-3">
          <div class="field">
            <label>DSO Key <em style="color:var(--danger)">*</em></label>
            <input type="text" id="f_DSOKey" placeholder="e.g. NGC1976" style="text-transform:uppercase">
            <div class="note">Canonical primary key &mdash; usually the primary catalog ID</div>
          </div>
          <div class="field">
            <label>Common Name</label>
            <input type="text" id="f_CommonName" placeholder="e.g. Orion Nebula">
          </div>
          <div class="field">
            <label>Object Type</label>
            <select id="f_ObjectTypeID">
              <option value="">&mdash; loading &mdash;</option>
            </select>
          </div>
          <div class="field">
            <label>Constellation</label>
            <select id="f_ConstellationID" onchange="handleConstellationChange(this)">
              <option value="">&mdash; loading &mdash;</option>
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
            <div class="note">0.0000 &ndash; 24.0000</div>
          </div>
          <div class="field">
            <label>Dec (decimal degrees)</label>
            <input type="number" id="f_DecDegrees" step="0.0001" min="-90" max="90" placeholder="e.g. -5.3897">
            <div class="note">&minus;90.0000 &ndash; +90.0000</div>
          </div>
          <div class="field">
            <label>Magnitude</label>
            <input type="number" id="f_Magnitude" step="0.1" placeholder="e.g. 4.0">
          </div>
          <div class="field span-2">
            <label>Object Size</label>
            <input type="text" id="f_ObjectSize" placeholder="e.g. 70 light-years across with an apparent diameter of 45-50 arcminutes, about 1.5&#215; the full moon">
            <div class="note">Physical size, angular size, and moon comparison in one plain-English sentence.</div>
          </div>
          <div class="field">
            <label>Sq Arc Mins</label>
            <input type="number" id="f_SqArcMins" step="0.01" min="0" placeholder="e.g. 1600">
            <div class="note">Apparent area (arcmin&#178;). Single dim: d&#178;. Two dims: d1 &#215; d2.</div>
          </div>
          <div class="field">
            <label>Want Better Data? &#9733;</label>
            <select id="f_WantBetter">
              <option value="0">No</option>
              <option value="1">Yes &mdash; priority target</option>
            </select>
            <div class="note">Flags object in the visibility report as a priority.</div>
          </div>
        </div>
      </div>

      <!-- Observation & Project -->
      <div class="section">
        <div class="section-header">Observation &amp; Project</div>
        <div class="section-body grid-3">
          <div class="field">
            <label>Project Folder</label>
            <input type="text" id="f_ProjectFolder" placeholder="e.g. NGC1976_OrionNebula">
            <div class="note">Folder name under C:\Astronomy\myWorks</div>
          </div>
          <div class="field">
            <label>Most Recent Observation</label>
            <input type="date" id="f_MostRecentObservation">
          </div>
          <div class="field span-3">
            <label>Notes</label>
            <textarea id="f_Notes" placeholder="Personal notes about this object, imaging sessions, equipment used, etc."></textarea>
          </div>
        </div>
      </div>

      <!-- Social Blurb -->
      <div class="section">
        <div class="section-header">
          Social Blurb
          <button class="btn" style="font-size:11px; padding:3px 10px;" onclick="aiGenerateBlurb()">&#129302; Regenerate with AI</button>
        </div>
        <div class="section-body">
          <div class="field">
            <textarea id="f_SocialBlurb" placeholder="Two paragraphs of engaging prose describing the object &mdash; what it is, its physical nature, distance, and what makes it special to image. This is the basis for social media captions."></textarea>
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

      <!-- Gallery Images -->
      <div class="section">
        <div class="section-header">
          Gallery Images
          <button class="btn" style="font-size:11px; padding:3px 10px;" onclick="syncFolder()">&#x21bb; Sync Folder</button>
          <button class="btn" style="font-size:11px; padding:3px 10px;" onclick="addGalleryImageCard()">+ Add Image</button>
        </div>
        <div class="section-body">
          <div class="gi-list" id="gi-list"></div>
          <div id="gi-empty" style="color:var(--muted); font-size:12px; padding:4px 0; display:none;">No gallery images yet &mdash; click &ldquo;Sync Folder&rdquo; or &ldquo;+ Add Image&rdquo;.</div>
          <div id="gi-sync-result" style="display:none; margin-top:10px;"></div>
        </div>
      </div>

      <!-- DSO Links -->
      <div class="section">
        <div class="section-header">
          DSO Links
          <button class="btn" style="font-size:11px; padding:3px 10px;" onclick="addLinkRow()">+ Add Link</button>
        </div>
        <div class="section-body">
          <table class="lnk-table" id="lnk-table">
            <thead>
              <tr>
                <th style="width:22%">Label</th>
                <th>URL</th>
                <th style="width:70px">Order</th>
                <th style="width:36px"></th>
              </tr>
            </thead>
            <tbody id="lnk-tbody"></tbody>
          </table>
          <button class="btn-add-cat" onclick="addLinkRow()" style="margin-top:8px;">+ Add Link</button>
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
let currentObject = null;
let searchTimer   = null;
let blurbWatchSnapshot = {};

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
                'RAHours','DecDegrees','Magnitude','ObjectSize','SqArcMins','DistanceLY',
                'SocialBlurb','ProjectFolder','MostRecentObservation','WantBetter','Notes'];

// ──────────────────────────────────────────────
// Fetch wrapper
// ──────────────────────────────────────────────
async function apiFetch(url, options = {}) {
  options.credentials = 'same-origin';
  const res = await fetch(url, options);
  if (res.status === 401) {
    window.location.href = 'index.php?expired=1';
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
    : 'Error loading objects';
}

function renderList(rows) {
  const ul = document.getElementById('object-list');
  const scrollTop = ul.scrollTop;
  ul.innerHTML = '';
  if (!Array.isArray(rows) || !rows.length) {
    ul.innerHTML = '<div style="padding:16px; color:var(--muted); font-size:13px;">' + (Array.isArray(rows) ? 'No objects found' : 'Error loading objects') + '</div>';
    return;
  }
  rows.forEach(row => {
    const div = document.createElement('div');
    div.className = 'object-item' + (currentObject?.DSOKey === row.DSOKey ? ' active' : '');
    div.dataset.key = row.DSOKey;

    const filled = [row.RAHours, row.DecDegrees, row.Magnitude, row.ObjectSize, row.SocialBlurb].filter(v => v !== null && v !== '').length;
    const cls    = filled >= 4 ? 'full' : filled >= 2 ? 'partial' : 'empty';

    div.innerHTML = `
      <div style="min-width:0; flex:1">
        <div class="obj-key">${row.DSOKey}</div>
        <div class="obj-name">${row.CommonName || '&mdash;'}</div>
      </div>
      <div style="display:flex; align-items:center; gap:6px; flex-shrink:0">
        <span class="obj-type">${row.ConstellationID || ''}</span>
        <span class="completeness ${cls}" title="Field completeness"></span>
      </div>
    `;
    div.addEventListener('click', () => loadObject(row));
    ul.appendChild(div);
  });
  ul.scrollTop = scrollTop;
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

    sel.innerHTML = '<option value="">&mdash; select &mdash;</option>';
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
    sel.innerHTML = '<option value="">&mdash; error loading &mdash;</option>';
    console.error('Failed to load object types:', e);
  }
}

fetchList('');
loadObjectTypes();
loadConstellations();

// ──────────────────────────────────────────────
// Load object into editor
// ──────────────────────────────────────────────
function loadObject(row) {
  currentObject = row;
  document.querySelectorAll('.object-item').forEach(el => {
    el.classList.toggle('active', el.dataset.key === row.DSOKey);
  });
  const activeEl = document.querySelector('.object-item.active');
  if (activeEl) activeEl.scrollIntoView({ block: 'nearest' });
  showEditor();
  document.getElementById('editor-title').childNodes[0].textContent = row.DSOKey + ' ';
  document.getElementById('editor-subtitle').textContent = row.CommonName || '';
  document.getElementById('f_DSOKey').value = row.DSOKey;
  document.getElementById('f_DSOKey').disabled = true;
  document.getElementById('btn-delete').disabled = false;

  loadConstellations(row.ConstellationID || '');

  fields.filter(f => f !== 'DSOKey' && f !== 'ObjectTypeID' && f !== 'ConstellationID').forEach(f => {
    const el = document.getElementById('f_' + f);
    if (el) el.value = row[f] ?? '';
  });
  loadObjectTypes(row.ObjectTypeID || '');

  renderCatalogTable(row.CatalogIDs || []);
  renderGalleryImages(row.GalleryImages || []);
  renderDSOLinks(row.DSOLinks || []);
  snapshotBlurbFields();
}

function newObject() {
  currentObject = null;
  document.querySelectorAll('.object-item').forEach(el => el.classList.remove('active'));
  showEditor();
  document.getElementById('editor-title').childNodes[0].textContent = 'New Object ';
  document.getElementById('editor-subtitle').textContent = '';
  const selectDefaults = { WantBetter: '0' };
  fields.forEach(f => {
    const el = document.getElementById('f_' + f);
    if (!el) return;
    el.value = selectDefaults[f] ?? '';
    el.disabled = false;
  });
  document.getElementById('f_DSOKey').disabled = false;
  document.getElementById('btn-delete').disabled = true;
  loadObjectTypes('');
  loadConstellations('');
  renderCatalogTable([]);
  renderGalleryImages([]);
  renderDSOLinks([]);
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
    <td><button class="btn-icon" onclick="this.closest('tr').remove()" title="Remove">&#x2715;</button></td>
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

  const payload = { DSOKey: dsoKey };
  fields.filter(f => f !== 'DSOKey').forEach(f => {
    const el = document.getElementById('f_' + f);
    if (!el) return;
    let v = el.value.trim();
    if (['RAHours','DecDegrees','Magnitude'].includes(f)) {
      payload[f] = v === '' ? null : parseFloat(v);
    } else {
      payload[f] = v === '' ? null : v;
    }
  });
  payload.CatalogIDs    = getCatalogRows();
  payload.GalleryImages  = getGalleryImageRows();
  payload.DSOLinks       = getDSOLinkRows();

  try {
    const res  = await apiFetch('api_save.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
    const data = await res.json();
    if (data.success) {
      if (!silent) toast('Saved successfully', 'ok');
      snapshotBlurbFields();
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
// Delete Object
// ──────────────────────────────────────────────
async function deleteObject() {
  if (!currentObject) { toast('No object selected', 'err'); return; }
  const key = currentObject.DSOKey;
  if (!confirm(`Permanently delete "${key}"?\n\nThis will remove the object and all its catalog IDs. This cannot be undone.`)) return;

  try {
    const res  = await apiFetch('api_delete.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ DSOKey: key }) });
    const data = await res.json();
    if (data.success) {
      toast(key + ' deleted', 'ok');
      currentObject = null;
      document.getElementById('editor').style.display       = 'none';
      document.getElementById('empty-state').style.display  = '';
      await fetchList(document.getElementById('search').value.trim());
    } else {
      toast('Delete failed: ' + (data.error || 'Unknown error'), 'err', 5000);
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
  btn.innerHTML = '<span class="spinner"></span> Searching&#8230;';
  toast('Asking AI about ' + dsoId + '&#8230;', 'info', 15000);

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
    const fieldMap = {
      CommonName:      'f_CommonName',
      ObjectTypeID:    'f_ObjectTypeID',
      ConstellationID: 'f_ConstellationID',
      RAHours:         'f_RAHours',
      DecDegrees:      'f_DecDegrees',
      Magnitude:       'f_Magnitude',
      ObjectSize:      'f_ObjectSize',
      DistanceLY:      'f_DistanceLY',
      SqArcMins:       'f_SqArcMins',
    };
    let populated = 0;
    Object.entries(fieldMap).forEach(([key, elId]) => {
      const el = document.getElementById(elId);
      const aiVal = f[key];
      if (aiVal !== null && aiVal !== undefined && aiVal !== '' && el.value.trim() === '') {
        el.value = aiVal;
        populated++;
      }
    });
    if (f.SocialBlurb) {
      document.getElementById('f_SocialBlurb').value = f.SocialBlurb;
      populated++;
    }
    if (Array.isArray(f.CatalogIDs) && f.CatalogIDs.length > 0 && getCatalogRows().length === 0) {
      renderCatalogTable(f.CatalogIDs);
      populated++;
    }

    toast('Auto-saving&#8230;', 'info', 3000);
    await saveObject(silent = true);
    toast('AI filled ' + populated + ' field(s) and saved', 'ok', 5000);

  } catch (e) {
    toast('Network error: ' + e.message, 'err', 5000);
  } finally {
    btn.disabled = false;
    btn.innerHTML = '&#129302; AI Populate';
  }
}

// ──────────────────────────────────────────────
// AI: Regenerate SocialBlurb only
// ──────────────────────────────────────────────
async function aiGenerateBlurb() {
  const dsoId = document.getElementById('f_DSOKey').value.trim().toUpperCase();
  if (!dsoId) { toast('Enter a DSO Key first', 'err'); return; }

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

  toast('Generating social blurb for ' + dsoId + '&#8230;', 'info', 15000);

  try {
    const res  = await apiFetch('api_populate.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(context) });
    const data = await res.json();

    if (!data.success) { toast('AI error: ' + (data.error || ''), 'err', 6000); return; }

    if (data.fields?.SocialBlurb) {
      document.getElementById('f_SocialBlurb').value = data.fields.SocialBlurb;
      toast('Blurb generated &mdash; review and save', 'ok');
    } else {
      toast('AI did not return a blurb', 'err');
    }
  } catch (e) {
    toast('Network error: ' + e.message, 'err', 5000);
  }
}

// ──────────────────────────────────────────────
// Gallery Images -- Sync Folder
// ──────────────────────────────────────────────

async function syncFolder(extraHints = {}) {
  const dsoKey = document.getElementById('f_DSOKey').value.trim();
  if (!dsoKey) { toast('Save the DSO first before syncing.', 'err'); return; }

  const resultPanel = document.getElementById('gi-sync-result');
  resultPanel.style.display = '';
  resultPanel.innerHTML = '<span style="color:var(--muted); font-size:12px;">Scanning folder&hellip;</span>';

  try {
    const res  = await apiFetch('api_sync_folder.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ DSOKey: dsoKey, sessionDirHints: extraHints }),
    });
    const data = await res.json();
    if (data.error) throw new Error(data.error);

    const { inserted, updated, warnings, needs_session_dir, projectFolder, mode } = data;

    let html = `<div style="font-size:12px; border:1px solid var(--border); border-radius:var(--radius); padding:10px 14px; background:var(--bg);">`;
    const modeLabel = mode === 'local' ? '(local &mdash; WORKS_ROOT)' : '(remote &mdash; web images only)';
    html += `<div style="font-weight:600; margin-bottom:8px;">Sync result <span style="color:var(--muted); font-weight:400; font-size:11px;">${modeLabel}</span></div>`;

    if (inserted.length === 0 && updated.length === 0 && warnings.length === 0 && (!needs_session_dir || needs_session_dir.length === 0)) {
      html += `<div style="color:var(--muted);">No changes &mdash; already up to date.</div>`;
    }

    if (inserted.length > 0) {
      html += `<div style="color:var(--accent2); margin-bottom:4px;">&#x2714; Inserted ${inserted.length} new image${inserted.length > 1 ? 's' : ''}:</div>`;
      html += `<ul style="margin:0 0 8px 16px; padding:0;">`;
      inserted.forEach(r => {
        const dateStr = r.DateCaptured || (r.needs_session ? '<em style="color:var(--warn)">needs session dir</em>' : '');
        html += `<li>${escHtml(r.BaseName)} &mdash; ${dateStr} ${escHtml(r.Equipment || '')}${r.IsMosaic ? ' &middot; Mosaic' : ''}${r.IsFeature ? ' <strong>&#9733; Featured</strong>' : ''}</li>`;
      });
      html += `</ul>`;
    }

    if (updated.length > 0) {
      html += `<div style="color:var(--accent); margin-bottom:4px;">&#x21bb; Updated ${updated.length} image${updated.length > 1 ? 's' : ''}:</div>`;
      html += `<ul style="margin:0 0 8px 16px; padding:0;">`;
      updated.forEach(r => {
        html += `<li>${escHtml(r.BaseName)} &mdash; ${escHtml(r.DateCaptured || '')} ${escHtml(r.Equipment || '')}${r.IsMosaic ? ' &middot; Mosaic' : ''}</li>`;
      });
      html += `</ul>`;
    }

    if (warnings.length > 0) {
      html += `<div style="color:var(--danger); margin-bottom:4px;">&#x26A0; ${warnings.length} image${warnings.length > 1 ? 's' : ''} not found in public/images/fav/:</div>`;
      html += `<ul style="margin:0 0 8px 16px; padding:0;">`;
      warnings.forEach(w => {
        html += `<li>${escHtml(w.BaseName)}
          <button class="btn" style="font-size:10px; padding:1px 7px; margin-left:8px; border-color:var(--danger); color:var(--danger);"
            onclick="confirmRemoveGalleryImage(${w.GalleryImageID}, '${escHtml(w.BaseName)}', this)">Remove</button>
        </li>`;
      });
      html += `</ul>`;
    }

    if (needs_session_dir && needs_session_dir.length > 0) {
      html += `<div style="color:var(--warn); margin-bottom:6px; margin-top:4px;">&#x26A0; ${needs_session_dir.length} image${needs_session_dir.length > 1 ? 's' : ''} need a Session Directory to set date &amp; equipment:</div>`;
      html += `<div id="session-dir-inputs" style="display:grid; gap:6px; margin-bottom:8px;">`;
      needs_session_dir.forEach(item => {
        html += `<div style="display:flex; align-items:center; gap:8px;">`;
        html += `<span style="font-size:12px; flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${escHtml(item.BaseName)}">${escHtml(item.BaseName)}</span>`;
        html += `<input type="text" data-basename="${escHtml(item.BaseName)}" placeholder="e.g. 20251108_165x60s_S30"
          style="flex:2; background:var(--bg); border:1px solid var(--border); border-radius:4px; color:var(--text); padding:4px 8px; font-size:12px; font-family:monospace;">`;
        html += `</div>`;
      });
      html += `</div>`;
      html += `<button class="btn primary" style="font-size:11px; padding:4px 12px; margin-bottom:8px;"
        onclick="submitSessionDirHints()">Apply Session Dirs &amp; Re-sync</button> `;
    }

    html += `<button class="btn" style="font-size:11px; padding:2px 10px; margin-top:4px;" onclick="dismissSyncResult()">Dismiss</button>`;
    html += `</div>`;
    resultPanel.innerHTML = html;

    if (inserted.length > 0 || updated.length > 0) {
      const searchRes  = await apiFetch(`api_search.php?q=${encodeURIComponent(dsoKey)}`);
      const searchData = await searchRes.json();
      const row = Array.isArray(searchData)
        ? searchData.find(r => r.DSOKey === dsoKey)
        : null;
      if (row) renderGalleryImages(row.GalleryImages || []);
    }

  } catch (e) {
    resultPanel.innerHTML = `<span style="color:var(--danger); font-size:12px;">Sync failed: ${escHtml(e.message)}</span>`;
  }
}

async function submitSessionDirHints() {
  const inputs = document.querySelectorAll('#session-dir-inputs input[data-basename]');
  const hints  = {};
  let   filled = 0;
  inputs.forEach(inp => {
    const val = inp.value.trim();
    if (val) { hints[inp.dataset.basename] = val; filled++; }
  });
  if (filled === 0) { toast('Enter at least one Session Directory first', 'err'); return; }
  await syncFolder(hints);
}

async function confirmRemoveGalleryImage(id, baseName, btn) {
  if (!confirm(`Remove "${baseName}" from the gallery?\nThis cannot be undone.`)) return;
  try {
    const res  = await apiFetch('api_delete.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ GalleryImageID: id }),
    });
    const data = await res.json();
    if (data.error) throw new Error(data.error);
    btn.closest('li').style.textDecoration = 'line-through';
    btn.remove();
    const card = document.querySelector(`.gi-card[data-gallery-image-id="${id}"]`);
    if (card) card.remove();
    updateGiEmpty();
    toast(`Removed: ${baseName}`, 'ok');
  } catch (e) {
    toast('Delete failed: ' + e.message, 'err');
  }
}

function dismissSyncResult() {
  const p = document.getElementById('gi-sync-result');
  p.style.display = 'none';
  p.innerHTML = '';
}

// ──────────────────────────────────────────────
// Gallery Images -- cards
// ──────────────────────────────────────────────

const PALETTES = [
  { id: 0, name: 'Natural' },
  { id: 1, name: 'SHO' },
  { id: 2, name: 'HOO' },
  { id: 3, name: 'HSO' },
  { id: 4, name: 'OHS' },
  { id: 5, name: 'HOS' },
  { id: 6, name: 'Starless' },
  { id: 7, name: 'Mono' },
];

const PALETTE_SUFFIX_MAP = { sho: 1, hoo: 2, hso: 3, ohs: 4, hos: 5 };

function paletteIdFromBaseName(baseName) {
  const lower = String(baseName).toLowerCase();
  for (const [suffix, id] of Object.entries(PALETTE_SUFFIX_MAP)) {
    if (lower.endsWith('_' + suffix)) return id;
  }
  return 0;
}

function paletteOptions(selectedId) {
  return PALETTES.map(p =>
    `<option value="${p.id}" ${parseInt(selectedId) === p.id ? 'selected' : ''}>${p.name}</option>`
  ).join('');
}

function galleryFavUrl(baseName) {
  const trimmed = String(baseName || '').trim();
  return trimmed ? `/images/fav/${encodeURIComponent(trimmed)}_fav.jpg` : '';
}

function updateGalleryPreview(card, baseName) {
  const preview = card.querySelector('.gi-preview');
  const img = card.querySelector('.gi-preview-img');
  const empty = card.querySelector('.gi-preview-empty');
  const url = galleryFavUrl(baseName);

  preview.classList.remove('missing');
  if (!url) {
    img.removeAttribute('src');
    img.alt = '';
    preview.classList.add('missing');
    empty.textContent = 'Enter a base name to preview the fav image';
    return;
  }

  img.src = url;
  img.alt = `${baseName} fav image`;
  empty.textContent = 'Image not found';
}

function renderGalleryImages(images) {
  const list  = document.getElementById('gi-list');
  const empty = document.getElementById('gi-empty');
  list.innerHTML = '';
  images.forEach(img => addGalleryImageCard(img));
  updateGiEmpty();
}

function updateGiEmpty() {
  const list  = document.getElementById('gi-list');
  const empty = document.getElementById('gi-empty');
  empty.style.display = list.children.length === 0 ? '' : 'none';
}

function addGalleryImageCard(img = {}) {
  const list = document.getElementById('gi-list');
  const card = document.createElement('div');
  const isFeature  = img.IsFeature  ? 1 : 0;
  const isOwn      = img.IsOwn !== undefined ? parseInt(img.IsOwn) : 1;
  const paletteId  = (img.PaletteID !== undefined && parseInt(img.PaletteID) !== 0)
    ? parseInt(img.PaletteID)
    : paletteIdFromBaseName(img.BaseName || '');
  const sortOrder  = img.SortOrder !== undefined ? parseInt(img.SortOrder) : list.children.length;
  const isMosaic   = img.IsMosaic  ? 1 : 0;
  const equipment  = img.Equipment  || '';
  const sessionDir = img.SessionDir || '';
  const baseName   = img.BaseName || '';
  const favUrl     = galleryFavUrl(baseName);

  card.className = 'gi-card' + (isFeature ? ' is-feature' : '');
  card.dataset.galleryImageId = img.GalleryImageID || '';

  card.innerHTML = `
    <div class="gi-card-header">
      <span class="gi-basename">${escHtml(baseName || 'New Image')}</span>
      ${equipment  ? `<span style="font-size:11px; color:var(--muted);">${escHtml(equipment)}</span>` : ''}
      ${isMosaic  ? '<span style="font-size:11px; color:var(--warn);">Mosaic</span>' : ''}
      ${isFeature ? '<span class="gi-feature-badge">&#9733; Featured</span>' : ''}
    </div>
    <div class="gi-preview ${favUrl ? '' : 'missing'}">
      <img class="gi-preview-img" ${favUrl ? `src="${escHtml(favUrl)}"` : ''} alt="${escHtml(baseName ? baseName + ' fav image' : '')}"
           loading="lazy" onerror="this.closest('.gi-preview').classList.add('missing')">
      <div class="gi-preview-empty">${favUrl ? 'Image not found' : 'Enter a base name to preview the fav image'}</div>
    </div>
    <div class="gi-card-fields">
      <div class="field">
        <label>Base Name <em style="color:var(--danger)">*</em></label>
        <input type="text" class="gi-basename-input" placeholder="e.g. m42_orion_a1228"
               value="${escHtml(baseName)}">
      </div>
      <div class="field">
        <label>Palette</label>
        <select class="gi-palette">${paletteOptions(paletteId)}</select>
      </div>
      <div class="field">
        <label>Date Captured</label>
        <input type="date" class="gi-date" value="${escHtml(img.DateCaptured || '')}">
      </div>
      <div class="field">
        <label>Caption</label>
        <input type="text" class="gi-caption" placeholder="Optional display caption"
               value="${escHtml(img.Caption || '')}">
      </div>
      <div class="field">
        <label>Copyright</label>
        <input type="text" class="gi-copyright" placeholder="e.g. Carl Smith"
               value="${escHtml(img.Copyright || '')}">
      </div>
      <div class="field">
        <label>Equipment</label>
        <input type="text" class="gi-equipment" placeholder="e.g. S30"
               value="${escHtml(equipment)}">
      </div>
      <div class="field">
        <label>Session Directory</label>
        <input type="text" class="gi-sessiondir" placeholder="e.g. 20251108_165x60s_S30"
               value="${escHtml(sessionDir)}">
      </div>
      <div class="field">
        <label>Photographer</label>
        <select class="gi-isown" onchange="toggleAttribution(this)">
          <option value="1" ${isOwn === 1 ? 'selected' : ''}>Mine</option>
          <option value="0" ${isOwn === 0 ? 'selected' : ''}>Other</option>
        </select>
      </div>
      <div class="field gi-attribution-row ${isOwn === 0 ? 'visible' : ''}">
        <label>Attribution</label>
        <input type="text" class="gi-attribution" placeholder="Credit line for display"
               value="${escHtml(img.Attribution || '')}">
      </div>
    </div>
    <div class="gi-card-actions">
      <input type="hidden" class="gi-sortorder" value="${sortOrder}">
      <label style="font-size:12px; color:var(--muted); display:flex; align-items:center; gap:6px; cursor:pointer;">
        <input type="checkbox" class="gi-ismosaic" ${isMosaic ? 'checked' : ''}>
        Mosaic
      </label>
      <label style="font-size:12px; color:var(--muted); display:flex; align-items:center; gap:6px; cursor:pointer;">
        <input type="checkbox" class="gi-isfeature" ${isFeature ? 'checked' : ''}>
        Featured image (gallery card)
      </label>
      <div style="flex:1"></div>
      <label style="font-size:12px; color:var(--muted);">Order</label>
      <input type="number" class="gi-order-input" value="${sortOrder}" min="0" step="1"
             style="width:60px; font-size:12px; padding:3px 6px;"
             onchange="this.closest('.gi-card').querySelector('.gi-sortorder').value = this.value">
      <button class="btn" style="font-size:11px; padding:3px 10px; border-color:var(--danger); color:var(--danger);"
              onclick="removeGalleryImageCard(this)">Remove</button>
    </div>
  `;

  card.querySelector('.gi-basename-input').addEventListener('input', function() {
    card.querySelector('.gi-basename').textContent = this.value || 'New Image';
    updateGalleryPreview(card, this.value);
    const palSel = card.querySelector('.gi-palette');
    const inferred = paletteIdFromBaseName(this.value);
    if (inferred !== 0) palSel.value = inferred;
  });

  card.querySelector('.gi-isfeature').addEventListener('change', function() {
    card.classList.toggle('is-feature', this.checked);
    const badge = card.querySelector('.gi-feature-badge');
    if (this.checked) {
      if (!badge) {
        const span = document.createElement('span');
        span.className = 'gi-feature-badge';
        span.innerHTML = '&#9733; Featured';
        card.querySelector('.gi-card-header').appendChild(span);
      }
    } else {
      if (badge) badge.remove();
    }
  });

  list.appendChild(card);
  updateGiEmpty();
}

function toggleAttribution(sel) {
  const row = sel.closest('.gi-card').querySelector('.gi-attribution-row');
  row.classList.toggle('visible', sel.value === '0');
}

function removeGalleryImageCard(btn) {
  btn.closest('.gi-card').remove();
  updateGiEmpty();
}

function getGalleryImageRows() {
  return Array.from(document.querySelectorAll('.gi-card')).map(card => ({
    GalleryImageID: card.dataset.galleryImageId || null,
    BaseName:       card.querySelector('.gi-basename-input').value.trim(),
    Caption:        card.querySelector('.gi-caption').value.trim()        || null,
    PaletteID:      parseInt(card.querySelector('.gi-palette').value),
    DateCaptured:   card.querySelector('.gi-date').value                  || null,
    Copyright:      card.querySelector('.gi-copyright').value.trim()      || null,
    IsOwn:          parseInt(card.querySelector('.gi-isown').value),
    Attribution:    card.querySelector('.gi-attribution').value.trim()    || null,
    Equipment:      card.querySelector('.gi-equipment').value.trim()      || null,
    IsMosaic:       card.querySelector('.gi-ismosaic').checked ? 1 : 0,
    SessionDir:     card.querySelector('.gi-sessiondir').value.trim()     || null,
    SortOrder:      parseInt(card.querySelector('.gi-order-input').value) || 0,
    IsFeature:      card.querySelector('.gi-isfeature').checked ? 1 : 0,
  })).filter(r => r.BaseName);
}

// ──────────────────────────────────────────────
// DSO Links
// ──────────────────────────────────────────────

function renderDSOLinks(links) {
  const tbody = document.getElementById('lnk-tbody');
  tbody.innerHTML = '';
  links.forEach(lnk => addLinkRow(lnk));
}

function addLinkRow(lnk = {}) {
  const tbody = document.getElementById('lnk-tbody');
  const tr = document.createElement('tr');
  tr.dataset.linkId = lnk.LinkID || '';
  tr.innerHTML = `
    <td><input type="text" class="lnk-label" placeholder="e.g. Wikipedia"
               value="${escHtml(lnk.Label || '')}"></td>
    <td><input type="url"  class="lnk-url"   placeholder="https://"
               value="${escHtml(lnk.URL || '')}"></td>
    <td><input type="number" class="lnk-order" value="${lnk.SortOrder || 0}"
               min="0" step="1" style="width:54px;"></td>
    <td><button class="btn-icon" onclick="this.closest('tr').remove()" title="Remove">&#x2715;</button></td>
  `;
  tbody.appendChild(tr);
}

function getDSOLinkRows() {
  return Array.from(document.querySelectorAll('#lnk-tbody tr')).map(tr => ({
    LinkID:    tr.dataset.linkId || null,
    Label:     tr.querySelector('.lnk-label').value.trim(),
    URL:       tr.querySelector('.lnk-url').value.trim(),
    SortOrder: parseInt(tr.querySelector('.lnk-order').value) || 0,
  })).filter(r => r.Label && r.URL);
}

// ──────────────────────────────────────────────
// Utility
// ──────────────────────────────────────────────
function escHtml(str) {
  return String(str).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

document.getElementById('f_DSOKey').addEventListener('input', function () {
  const pos = this.selectionStart;
  this.value = this.value.toUpperCase();
  this.setSelectionRange(pos, pos);
});

// ──────────────────────────────────────────────
// Constellation dropdown
// ──────────────────────────────────────────────
async function loadConstellations(selectedValue = '') {
  const sel = document.getElementById('f_ConstellationID');
  try {
    const res  = await apiFetch('api_constellations.php');
    const data = await res.json();
    if (data.error) throw new Error(data.error);
    renderConstellationOptions(data, selectedValue);
  } catch (e) {
    sel.innerHTML = '<option value="">&mdash; error loading &mdash;</option>';
    console.error('Failed to load constellations:', e);
  }
}

function renderConstellationOptions(constellations, selectedValue = '') {
  const sel = document.getElementById('f_ConstellationID');
  sel.innerHTML = '<option value="">&mdash; select &mdash;</option>';
  constellations.forEach(c => {
    const opt = document.createElement('option');
    opt.value       = c.ConstellationID;
    opt.textContent = c.Name;
    if (c.ConstellationID === selectedValue) opt.selected = true;
    sel.appendChild(opt);
  });
  const addOpt = document.createElement('option');
  addOpt.value       = '__ADD__';
  addOpt.textContent = '+ Add new constellation...';
  addOpt.style.color = 'var(--accent)';
  sel.appendChild(addOpt);
}

async function handleConstellationChange(sel) {
  if (sel.value !== '__ADD__') return;
  sel.value = '';

  const name = prompt('Enter the constellation name (e.g. Scorpius):');
  if (!name || !name.trim()) return;

  toast('Looking up constellation data for "' + name.trim() + '"...', 'info', 10000);

  try {
    const res  = await apiFetch('api_constellation_add.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ name: name.trim() }),
    });
    const data = await res.json();

    if (!data.success) {
      toast('Error: ' + (data.error || 'Unknown error'), 'err', 6000);
      return;
    }

    await loadConstellations(data.ConstellationID);

    if (data.already_exists) {
      toast('"' + data.Name + '" (' + data.ConstellationID + ') was already in the database.', 'info', 4000);
    } else {
      toast('Added ' + data.Name + ' (' + data.ConstellationID + ') - RA ' + data.RightAscensionHours + 'h, Dec ' + data.DeclinationDegrees + 'deg', 'ok', 5000);
    }
  } catch (e) {
    toast('Network error: ' + e.message, 'err', 5000);
  }
}
</script>
</body>
</html>
