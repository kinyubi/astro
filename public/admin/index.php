<?php
require_once __DIR__ . '/auth.php';
// Always serve fresh — prevents stale JS/HTML after deployments
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

// Local mode = this PHP instance can see the myWorks folder on disk
// (i.e. running under Laragon on Carl's machine, not the public VPS).
// Same test the API endpoints use (api_sync_folder.php, api_check_project.php,
// api_audit_observations.php). Features that need it show a dialog instead
// of failing silently when this is false.
$is_local_mode = defined('WORKS_ROOT') && is_dir(WORKS_ROOT);
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
  .gi-dims {
    position: absolute; right: 6px; bottom: 6px; padding: 1px 6px;
    background: rgba(5,7,10,0.75); border-radius: 4px;
    color: var(--muted); font-size: 11px; font-variant-numeric: tabular-nums;
    pointer-events: none;
  }
  .gi-dims:empty, .gi-preview.missing .gi-dims { display: none; }
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
  .gi-hidden { margin-top: 14px; border: 1px solid var(--border); border-radius: var(--radius); padding: 8px 12px; }
  .gi-hidden summary { cursor: pointer; font-size: 12px; color: var(--muted); }
  .gi-hidden-list { display: grid; gap: 6px; margin-top: 8px; }
  .gi-hidden-item { display: flex; align-items: center; gap: 10px; font-size: 12px; }
  .gi-hidden-item img { width: 48px; height: 60px; object-fit: contain; background: #05070a; border-radius: 4px; }
  .gi-hidden-item .name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .save-status { font-size: 12px; color: var(--muted); align-self: center; margin-right: 6px; white-space: nowrap; }
  .save-status.saving { color: var(--accent); }
  .save-status.error  { color: var(--danger); }
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

  /* ── Inline-editable Observation cells ── */
  td.obs-edit { cursor: text; }
  td.obs-edit:hover { outline: 1px dashed var(--border); outline-offset: -2px; }
  td.obs-edit input { width: 100%; min-width: 60px; background: var(--bg); border: 1px solid var(--accent); border-radius: 3px; color: var(--text); font-size: 11px; padding: 1px 4px; font-family: inherit; }
  td.obs-edit.saving { opacity: 0.5; }

  /* ── Local-only dialog ── */
  #local-only-dialog { background: var(--surface); color: var(--text); border: 1px solid var(--border); border-radius: var(--radius); padding: 20px 24px; max-width: 440px; width: calc(100% - 32px); box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
  #local-only-dialog::backdrop { background: rgba(0,0,0,0.6); }
  #local-only-dialog h3 { font-size: 16px; font-weight: 600; color: var(--warn); margin-bottom: 10px; }
  #local-only-dialog p { font-size: 13px; margin-bottom: 10px; }
  #local-only-dialog code { font-family: monospace; font-size: 12px; background: var(--bg); border: 1px solid var(--border); border-radius: 4px; padding: 1px 5px; }
  #local-only-dialog .dialog-actions { display: flex; justify-content: flex-end; margin-top: 16px; }
</style>
</head>
<body>

<header>
  <h1>&#11088; DSO Admin</h1>
  <span class="subtitle">Deep Sky Object Database Maintenance</span>
  <nav style="margin-left:auto; display:flex; gap:10px; align-items:center;">
    <a href="/vis/" style="font-size:12px; color:var(--muted); text-decoration:none; border:1px solid var(--border); border-radius:var(--radius); padding:5px 12px;" onmouseover="this.style.color='var(--accent)'; this.style.borderColor='var(--accent)'" onmouseout="this.style.color='var(--muted)'; this.style.borderColor='var(--border)'">Visibility</a>
    <a href="/" style="font-size:12px; color:var(--muted); text-decoration:none; border:1px solid var(--border); border-radius:var(--radius); padding:5px 12px;" onmouseover="this.style.color='var(--accent)'; this.style.borderColor='var(--accent)'" onmouseout="this.style.color='var(--muted)'; this.style.borderColor='var(--border)'">Slideshow</a>
    <a href="/todo/" style="font-size:12px; color:var(--muted); text-decoration:none; border:1px solid var(--border); border-radius:var(--radius); padding:5px 12px;" onmouseover="this.style.color='var(--accent)'; this.style.borderColor='var(--accent)'" onmouseout="this.style.color='var(--muted)'; this.style.borderColor='var(--border)'">To Do</a>
    <a href="/check-missing/" style="font-size:12px; color:var(--muted); text-decoration:none; border:1px solid var(--border); border-radius:var(--radius); padding:5px 12px;" onmouseover="this.style.color='var(--accent)'; this.style.borderColor='var(--accent)'" onmouseout="this.style.color='var(--muted)'; this.style.borderColor='var(--border)'">Complete?</a>
    <a href="logout.php" style="font-size:12px; color:var(--muted); text-decoration:none; border:1px solid var(--border); border-radius:var(--radius); padding:5px 12px;" onmouseover="this.style.color='var(--accent)'; this.style.borderColor='var(--accent)'" onmouseout="this.style.color='var(--muted)'; this.style.borderColor='var(--border)'">Sign out</a>
  </nav>
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
      <button class="btn-new" onclick="newObject()" title="Open a blank form to enter a new DSO's details yourself. For AI-assisted entry from just a catalog ID, use Quick Add DSO instead.">+ Add DSO Manually</button>
      <button class="btn-new" style="margin-top:6px; background:var(--accent2);" onclick="quickAddDSO()" title="Create a new DSO by catalog ID (e.g. NGC1976) and let AI fill in its name, type, coordinates, size, distance, and blurb.">&#129302; Quick Add DSO</button>
    </div>
  </aside>

  <!-- ── Main panel ── -->
  <main class="main" id="main-panel">
    <div class="empty-state" id="empty-state">
      <div class="icon">&#128301;</div>
      <div>Select a DSO, or add a new one</div>
    </div>

    <!-- Object editor (hidden until an object is selected/created) -->
    <div id="editor" style="display:none; display:none;" class="editor-wrap">

      <div class="form-header">
        <div>
          <div class="form-title" id="editor-title">New DSO <span id="editor-subtitle"></span></div>
        </div>
        <div class="form-actions">
          <button class="btn warn" id="btn-ai" onclick="aiPopulate()" title="Fill in this DSO's fields (name, type, coordinates, size, distance, blurb) using AI, based on the DSO Key / catalog ID above. Overwrites whatever is currently in those fields.">&#129302; AI Populate</button>
          <span id="solar-guard-note" style="display:none; color:var(--muted); font-size:11px; align-self:center; margin-right:8px;">Solar object &mdash; AI Populate &amp; Sync Folder use the dedicated solar pipeline instead</span>
          <span id="save-status" class="save-status" title="Changes save automatically"></span>
          <button class="btn success" id="btn-create" onclick="createObject()" style="display:none;" title="Create this new DSO. After that, every change saves automatically.">&#10133; Create DSO</button>
          <button class="btn danger" id="btn-delete" onclick="deleteObject()" disabled title="Permanently delete this DSO from the database. Only works if it has no Project registered yet &mdash; remove the Project first. Files on disk are never touched.">&#128465;&#65039; Delete DSO</button>
        </div>
      </div>

      <!-- Identity -->
      <div class="section">
        <div class="section-header">Identity</div>
        <div class="section-body grid-3">
          <div class="field">
            <label>DSO Key <em style="color:var(--danger)">*</em></label>
            <input type="text" id="f_DSOKey" placeholder="e.g. NGC1976" style="text-transform:uppercase" autocomplete="off">
            <div class="note">Canonical primary key &mdash; usually the primary catalog ID</div>
          </div>
          <div class="field">
            <label>Common Name</label>
            <input type="text" id="f_CommonName" placeholder="e.g. Orion Nebula" autocomplete="off">
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
            <input type="text" id="f_DistanceLY" placeholder="e.g. ~1,350 light-years" autocomplete="off">
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
            <label>RA (J2000 &mdash; hh mm ss.s)</label>
            <input type="text" id="f_RAHours_fmt" placeholder="e.g. 05 35 17.3" autocomplete="off"
                   oninput="syncRAFromFormatted(this)" onblur="normalizeRADisplay(this)">
            <input type="hidden" id="f_RAHours">
            <div class="note" id="f_RAHours_note">0.0000 &ndash; 24.0000 h</div>
          </div>
          <div class="field">
            <label>Dec (J2000 &mdash; &plusmn;dd mm ss.s)</label>
            <input type="text" id="f_DecDegrees_fmt" placeholder="e.g. -05 23 28" autocomplete="off"
                   oninput="syncDecFromFormatted(this)" onblur="normalizeDecDisplay(this)">
            <input type="hidden" id="f_DecDegrees">
            <div class="note" id="f_DecDegrees_note">&minus;90 &ndash; +90 &deg;</div>
          </div>
          <div class="field">
            <label>Magnitude</label>
            <input type="number" id="f_Magnitude" step="0.1" placeholder="e.g. 4.0" autocomplete="off">
          </div>
          <div class="field span-2">
            <label>Object Size</label>
            <input type="text" id="f_ObjectSize" placeholder="e.g. 70 light-years across with an apparent diameter of 45-50 arcminutes, about 1.5&#215; the full moon" autocomplete="off">
            <div class="note">Physical size, angular size, and moon comparison in one plain-English sentence.</div>
          </div>
          <div class="field">
            <label>Sq Arc Mins</label>
            <input type="number" id="f_SqArcMins" step="0.01" min="0" placeholder="e.g. 1600" autocomplete="off">
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
        <div class="section-header">
          Observation &amp; Project
          <button class="btn" id="btn-add-project" style="font-size:11px; padding:3px 10px;" onclick="addProject()" title="Register a myWorks folder on disk as this DSO's Project. Needed once, the first time you image something new, before Sync Folder or Audit Observations will work.">+ Add Project</button>
          <button class="btn" id="btn-audit-observations" style="font-size:11px; padding:3px 10px;" onclick="auditObservations()" title="Scan this DSO's myWorks folder for new or changed observation sessions and add/update them here">&#x21bb; Audit Observations</button>
        </div>
        <div class="section-body">
          <div id="projects-list" style="display:grid; gap:10px;"></div>
          <div id="obs-audit-result" style="display:none; margin-top:10px;"></div>
          <div class="note" style="margin-top:4px;">&ldquo;+ Add Project&rdquo; registers a myWorks folder as this DSO's Project (needed once, the first time you image something new, before Sync Folder or Audit Observations will work). &ldquo;Audit Observations&rdquo; scans that folder on disk and adds/updates session rows below to match, and deletes any session row no longer backed by a folder on disk (keeping a 1-to-1 match between disk and database) &mdash; unless that row has hand-entered notes/conditions data, in which case it's kept and flagged in red for you to review instead. When running locally it also runs automatically each time you open a DSO. Filt, Integ and Notes can be edited by clicking them; #Exp comes from the folder name. Sync Folder (under Gallery Images) runs this automatically after syncing pictures, or click it here any time. Otherwise read-only &mdash; a full Project management UI is planned.</div>
          <div class="field" style="margin-top:12px;">
            <label>Notes</label>
            <textarea id="f_Notes" placeholder="Personal notes about this object, imaging sessions, equipment used, etc." autocomplete="off"></textarea>
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
            <textarea id="f_SocialBlurb" placeholder="Two paragraphs of engaging prose describing the object &mdash; what it is, its physical nature, distance, and what makes it special to image. This is the basis for social media captions." autocomplete="off"></textarea>
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
          <button class="btn-add-cat" onclick="addCatRow()" title="Add another catalog identifier this DSO is known by (e.g. both M42 and NGC1976 for the same object)">+ Add Catalog ID</button>
        </div>
      </div>

      <!-- Gallery Images -->
      <div class="section">
        <div class="section-header">
          Gallery Images
          <button class="btn" id="btn-sync-folder" style="font-size:11px; padding:3px 10px;" onclick="syncFolder()" title="Scan this DSO's Project folder for new/changed processed images (_fav files) and add or update their Gallery Image cards below. Also runs Audit Observations automatically afterward.">&#x21bb; Sync Folder</button>
          <button class="btn" style="font-size:11px; padding:3px 10px;" onclick="addGalleryImageCard()" title="Manually add a Gallery Image card for this DSO, without scanning disk">+ Add Image</button>
        </div>
        <div class="section-body">
          <div class="gi-list" id="gi-list"></div>
          <div id="gi-empty" style="color:var(--muted); font-size:12px; padding:4px 0; display:none;">No gallery images yet &mdash; click &ldquo;Sync Folder&rdquo; or &ldquo;+ Add Image&rdquo;.</div>
          <details id="gi-hidden" class="gi-hidden" style="display:none;">
            <summary>Hidden images (<span id="gi-hidden-count">0</span>) &mdash; not shown in the public gallery; Sync Folder leaves them hidden</summary>
            <div id="gi-hidden-list" class="gi-hidden-list"></div>
          </details>
          <div id="gi-sync-result" style="display:none; margin-top:10px;"></div>
        </div>
      </div>

      <!-- DSO Links -->
      <div class="section">
        <div class="section-header">
          DSO Links
          <button class="btn" style="font-size:11px; padding:3px 10px;" onclick="addLinkRow()" title="Add a labeled external link for this DSO (e.g. a blog post, gallery page, or reference article)">+ Add Link</button>
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

<dialog id="local-only-dialog">
  <h3>&#x26A0; Available only when run locally</h3>
  <p><strong id="local-only-action">This action</strong> needs direct access to the myWorks folder
     (<code><?= htmlspecialchars(defined('WORKS_ROOT') ? WORKS_ROOT : 'C:\\Astronomy\\MyWorks') ?></code>),
     which this server can't see.</p>
  <p style="color:var(--muted);">Open the admin panel from your own machine (Laragon) to use it.</p>
  <div class="dialog-actions">
    <button class="btn primary" onclick="document.getElementById('local-only-dialog').close()">OK</button>
  </div>
</dialog>

<script>
// ──────────────────────────────────────────────
// State
// ──────────────────────────────────────────────
let currentObject = null;
let searchTimer   = null;

// True when PHP can see WORKS_ROOT (see top of file). Sync Folder,
// Add Project and Audit Observations only work in local mode.
const LOCAL_MODE = <?= json_encode($is_local_mode) ?>;

// Shows the "available only when run locally" dialog for actionName.
function showLocalOnlyDialog(actionName) {
  const dlg = document.getElementById('local-only-dialog');
  document.getElementById('local-only-action').textContent = actionName;
  if (typeof dlg.showModal === 'function') {
    if (!dlg.open) dlg.showModal();
  } else {
    alert(actionName + ' is available only when the admin panel is run locally.');
  }
}

// Guard for local-only actions: returns true if OK to proceed,
// otherwise shows the dialog and returns false.
// Observations the last audit kept even though no folder exists on disk
// (they carry hand-entered data). Used to flag them in the table.
// { dsoKey, folders: Set<lowercased ObservationFolder>, dates: Set<'YYYY-MM-DD'> }
let obsHeldForReview = { dsoKey: null, folders: new Set(), dates: new Set() };

// ObservationID -> observation row, rebuilt on every table render; used by
// the inline editor.
const obsById = new Map();

function requireLocal(actionName) {
  if (LOCAL_MODE) return true;
  showLocalOnlyDialog(actionName);
  return false;
}
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
                'SocialBlurb','WantBetter','Notes'];

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
    div.addEventListener('click', () => openObject(row));
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

function isSolarObjectType(typeId) {
  return typeId === 'SOLAR_SYSTEM';
}

function updateSolarGuard() {
  const typeId = document.getElementById('f_ObjectTypeID').value;
  const solar  = isSolarObjectType(typeId);

  const aiBtn = document.getElementById('btn-ai');
  aiBtn.disabled = solar;
  aiBtn.title = solar ? 'Not available for Sun/Moon \u2014 handled by the solar pipeline.' : '';

  const syncBtn = document.getElementById('btn-sync-folder');
  syncBtn.disabled = solar;
  syncBtn.title = solar ? 'Not available for Sun/Moon \u2014 handled by the solar pipeline.' : '';

  const note = document.getElementById('solar-guard-note');
  if (note) note.style.display = solar ? '' : 'none';
}

document.getElementById('f_ObjectTypeID').addEventListener('change', updateSolarGuard);

fetchList('');
loadObjectTypes();
loadConstellations();

// ?load=<DSOKey> opens that DSO straight away (used by the Complete?
// page's "Open in Admin" links).
(async () => {
  const key = new URLSearchParams(location.search).get('load');
  if (!key) return;
  const row = await fetchOneByKey(key.trim().toUpperCase());
  if (row) openObject(row);
  else toast('DSO ' + key + ' not found', 'err', 6000);
})();

// ──────────────────────────────────────────────
// Load object into editor
// ──────────────────────────────────────────────

// Entry point for clicking a DSO in the sidebar: checks whether there's
// an unregistered myWorks folder for it (api_check_project.php) and, if
// so, offers to create the Project right then -- before the DSO is
// actually displayed -- rather than requiring "+ Add Project" to be
// found and clicked separately every time. Local-mode only; on a remote
// deployment (or if the check itself fails) this just falls through to
// loadObject(row) unchanged, since a broken convenience check must never
// block opening a DSO.
async function openObject(row) {
  // Don't lose a pending autosave of the DSO we're leaving.
  await flushSave();

  try {
    const res  = await apiFetch('api_check_project.php?DSOKey=' + encodeURIComponent(row.DSOKey));
    const data = await res.json();
    let projectsChanged = false;

    // Existing Projects of this DSO whose folder is gone from myWorks.
    const stale = Array.isArray(data.staleProjects) ? [...data.staleProjects] : [];
    const live  = Array.isArray(data.liveProjects)  ? [...data.liveProjects]  : [];

    if (data.needsProject && Array.isArray(data.folders) && data.folders.length) {
      // A missing folder next to a new unregistered one is most likely a
      // rename: offer to repoint the old Project first, so it keeps its
      // history instead of turning into a duplicate.
      for (const folder of data.folders) {
        let payload = null;
        if (stale.length) {
          const sp = stale[0];
          const repoint = confirm(
            'DSO ' + row.DSOKey + ' has an unregistered myWorks folder:\n\n' + folder +
            '\n\nIts existing Project #' + sp.ProjectID + ' points to "' + sp.ProjectFolder +
            '", which no longer exists on disk.\n\nRepoint that Project to "' + folder +
            '" instead of creating a new one?'
          );
          if (repoint) {
            payload = { DSOKey: row.DSOKey, ProjectFolder: folder, ReplaceProjectID: sp.ProjectID };
            stale.shift();
          }
        }
        if (!payload) {
          const ok = confirm(
            'DSO ' + row.DSOKey + ' has an unregistered myWorks folder:\n\n' +
            folder + '\n\nCreate a new Project for it now?'
          );
          if (!ok) continue;
          payload = { DSOKey: row.DSOKey, ProjectFolder: folder };
        }

        const addRes  = await apiFetch('api_add_project.php', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        });
        const addData = await addRes.json();
        if (addData.success) {
          toast((addData.repointed ? 'Project repointed: ' : 'Project added: ') + folder, 'ok');
          live.push({ ProjectID: addData.ProjectID, ProjectFolder: folder });
          projectsChanged = true;
        } else {
          toast('Add Project failed: ' + (addData.error || 'unknown error'), 'err', 8000);
        }
      }
    }

    // Any remaining Project whose folder is gone: offer to delete it.
    for (const sp of stale) {
      let where;
      if (live.length === 1) {
        where = 'moved to Project #' + live[0].ProjectID + ' (' + live[0].ProjectFolder + ')';
      } else if (live.length > 1) {
        where = 'moved to whichever Project folder holds their session folder';
      } else {
        where = 'kept on the DSO but no longer linked to a Project';
      }
      const msg =
        'Project #' + sp.ProjectID + ' of ' + row.DSOKey + ' points to folder:\n\n' +
        (sp.ProjectFolder || '(none)') + '\n\nwhich is no longer in myWorks. Delete this Project?\n\n' +
        '• ' + sp.ObservationCount + ' observation row(s) will be deleted' +
        (live.length ? ' (Audit Observations rebuilds them from disk for the remaining Project)' : '') + '\n' +
        '• ' + sp.ImageCount + ' gallery image(s) will be ' + where;
      if (!confirm(msg)) continue;

      const delRes  = await apiFetch('api_delete_project.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ DSOKey: row.DSOKey, ProjectID: sp.ProjectID }),
      });
      const delData = await delRes.json();
      if (delData.success) {
        toast('Project #' + sp.ProjectID + ' deleted', 'ok');
        projectsChanged = true;
      } else {
        toast('Delete Project failed: ' + (delData.error || 'unknown error'), 'err', 8000);
      }
    }

    if (projectsChanged) {
      const fresh = await fetchOneByKey(row.DSOKey);
      if (fresh) row = fresh;
    }
  } catch (e) {
    // Convenience check only -- fall through and display the DSO regardless.
    console.warn('Project check failed:', e);
  }

  dismissObsAuditResult();
  obsHeldForReview = { dsoKey: row.DSOKey, folders: new Set(), dates: new Set() };
  loadObject(row);

  // Keep Observations in step with myWorks on disk: audit automatically
  // every time a DSO is opened locally (silent unless it changes something
  // or fails). Skipped when there's no Project yet -- nothing to audit.
  if (LOCAL_MODE && Array.isArray(row.Projects) && row.Projects.length > 0) {
    auditObservations({ silent: true });
  }
}

function loadObject(row) {
  currentObject = row;
  document.querySelectorAll('.object-item').forEach(el => {
    el.classList.toggle('active', el.dataset.key === row.DSOKey);
  });
  const activeEl = document.querySelector('.object-item.active');
  if (activeEl) activeEl.scrollIntoView({ block: 'nearest' });
  showEditor();
  dismissSyncResult();
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
  // Populate formatted RA/Dec display fields
  setRADisplay(row.RAHours);
  setDecDisplay(row.DecDegrees);
  loadObjectTypes(row.ObjectTypeID || '').then(updateSolarGuard);

  renderCatalogTable(row.CatalogIDs || []);
  renderGalleryImages(row.GalleryImages || []);
  renderDSOLinks(row.DSOLinks || []);
  renderProjects(row.Projects || []);
  snapshotBlurbFields();
  listFieldsSnapshot = listFieldsNow();
  autosaveEnabled = true;
  document.getElementById('btn-create').style.display = 'none';
  setSaveStatus('idle');
}

async function newObject() {
  await flushSave();
  autosaveEnabled = false;
  savePending = false;
  currentObject = null;
  document.querySelectorAll('.object-item').forEach(el => el.classList.remove('active'));
  showEditor();
  dismissSyncResult();
  document.getElementById('editor-title').childNodes[0].textContent = 'New DSO ';
  document.getElementById('editor-subtitle').textContent = '';
  const selectDefaults = { WantBetter: '0' };
  fields.forEach(f => {
    const el = document.getElementById('f_' + f);
    if (!el) return;
    el.value = selectDefaults[f] ?? '';
    el.disabled = false;
  });
  // Clear formatted RA/Dec display fields
  setRADisplay(null);
  setDecDisplay(null);
  document.getElementById('f_DSOKey').disabled = false;
  document.getElementById('btn-delete').disabled = true;
  loadObjectTypes('').then(updateSolarGuard);
  loadConstellations('');
  renderCatalogTable([]);
  renderGalleryImages([]);
  renderDSOLinks([]);
  renderProjects([]);
  snapshotBlurbFields();
  document.getElementById('btn-create').style.display = '';
  setSaveStatus('idle');
}

function showEditor() {
  document.getElementById('empty-state').style.display  = 'none';
  document.getElementById('editor').style.display       = 'flex';
  document.getElementById('editor').style.flexDirection = 'column';
  document.getElementById('editor').style.gap           = '20px';
}

// ──────────────────────────────────────────────
// Quick Add DSO -- same api_quickadd.php endpoint /vis uses, one prompt
// instead of filling the New Object form by hand. AI-populates and saves
// in one round trip; only creates the Objects/CatalogIDs row -- still
// needs "+ Add Project" below before Sync Folder will work for it.
// ──────────────────────────────────────────────
async function quickAddDSO() {
  const dsoKey = (prompt('DSO key or catalog ID to add (e.g. NGC1976, M42, SH2-129):') || '').trim().toUpperCase();
  if (!dsoKey) return;

  toast('Asking AI about ' + dsoKey + '\u2026', 'info', 15000);

  try {
    const res  = await apiFetch('api_quickadd.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ dso_key: dsoKey }),
    });
    // Read as text first so a non-JSON server response (PHP fatal/warning
    // page) shows what the server actually said instead of a bare
    // "invalid JSON" parse error.
    const raw = await res.text();
    let data;
    try {
      data = JSON.parse(raw);
    } catch (_) {
      const snippet = raw.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300);
      toast('Quick Add failed (HTTP ' + res.status + '): ' + (snippet || 'empty response'), 'err', 12000);
      return;
    }

    if (data.exists) {
      toast(dsoKey + ' already exists \u2014 opening it.', 'info');
      await flushSave();
      await fetchList('');
      const row = await fetchOneByKey(dsoKey);
      if (row) loadObject(row);
      return;
    }

    if (!data.success) {
      toast('Quick Add failed: ' + (data.error || 'unknown error'), 'err', 8000);
      return;
    }

    toast((data.CommonName ? data.CommonName + ' (' + dsoKey + ')' : dsoKey) + ' added.', 'ok');
    await flushSave();
    await fetchList('');
    const row = await fetchOneByKey(dsoKey);
    if (row) loadObject(row);

  } catch (e) {
    toast('Network error: ' + e.message, 'err', 8000);
  }
}

// Fetches a single object's full row (with Projects/GalleryImages/etc.)
// by exact DSOKey, for re-loading into the editor after Quick Add or
// Add Project. api_search.php's :q matches substrings, so filter to the
// exact key rather than assuming the first result is the right one.
async function fetchOneByKey(dsoKey) {
  const res  = await apiFetch('api_search.php?q=' + encodeURIComponent(dsoKey));
  const rows = await res.json();
  if (!Array.isArray(rows)) return null;
  return rows.find(r => r.DSOKey.toUpperCase() === dsoKey.toUpperCase()) || null;
}

// ──────────────────────────────────────────────
// Projects (read-only display for now)
// ──────────────────────────────────────────────
function renderObservationsTable(obs) {
  if (!obs || obs.length === 0) {
    return '<div style="color:var(--muted); font-size:11px; padding:6px 0 2px;">No observations logged yet.</div>';
  }
  const rows = obs.map(o => {
    obsById.set(Number(o.ObservationID), o);
    const held = obsHeldForReview.dsoKey === (currentObject && currentObject.DSOKey) && (
      (o.ObservationFolder && obsHeldForReview.folders.has(o.ObservationFolder.toLowerCase())) ||
      (!o.ObservationFolder && obsHeldForReview.dates.has(o.ObservationDate))
    );
    const noFolder = !o.ObservationFolder;
    const flagTitle = 'No matching folder in myWorks \u2014 kept because it has hand-entered data (Filter/Notes/etc).';
    const folderCell = o.ObservationFolder
      ? `<span${held ? ` style="color:var(--danger);" title="${escHtml(flagTitle)}"` : ` title="${escHtml(o.ObservationFolder)}"`}>${held ? '&#x26A0; ' : ''}${escHtml(o.ObservationFolder)}</span>`
      : `<span style="color:var(--danger);" title="${escHtml(held ? flagTitle : 'No folder linked')}">&#x26A0; no folder</span>`;
    const id = Number(o.ObservationID);
    const exp    = o.ExposureTimeSecs ? escHtml(String(o.ExposureTimeSecs)) + 's' : '&mdash;';
    const integ  = o.IntegrationMins ? parseFloat(o.IntegrationMins).toFixed(0) + 'm' : '&mdash;';
    const start  = o.StartTime ? escHtml(o.StartTime) : '&mdash;';
    const end    = o.EndTime ? escHtml(o.EndTime) : '&mdash;';
    const notesFull = o.Notes || '';
    const notesShort = notesFull.length > 24 ? notesFull.slice(0, 24) + '\u2026' : notesFull;
    return `
      <tr style="border-bottom:1px solid var(--border);${held || noFolder ? ' background:rgba(248,81,73,0.08);' : ''}">
        <td style="padding:3px 6px; white-space:nowrap;">${o.ObservationDate ? escHtml(o.ObservationDate) : '&mdash;'}</td>
        <td style="padding:3px 6px; max-width:140px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${folderCell}</td>
        <td style="padding:3px 6px; white-space:nowrap;">${start}</td>
        <td style="padding:3px 6px; white-space:nowrap;">${end}</td>
        <td style="padding:3px 6px; white-space:nowrap; text-align:right;">${exp}</td>
        <td class="obs-edit" data-id="${id}" data-field="Filter" title="Click to edit" style="padding:3px 6px; white-space:nowrap;">${o.Filter ? escHtml(o.Filter) : '&mdash;'}</td>
        <td style="padding:3px 6px; white-space:nowrap; text-align:right;">${o.TotalExposures ?? '&mdash;'}</td>
        <td class="obs-edit" data-id="${id}" data-field="IntegrationMins" title="Click to edit (minutes). Clear it to let the next audit recalculate." style="padding:3px 6px; white-space:nowrap; text-align:right;">${integ}</td>
        <td class="obs-edit" data-id="${id}" data-field="Notes" title="${escHtml(notesFull ? notesFull + '\n\n' : '')}Click to edit" style="padding:3px 6px; max-width:160px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${notesShort ? escHtml(notesShort) : '&mdash;'}</td>
      </tr>`;
  }).join('');
  return `
    <div style="overflow-x:auto; margin-top:8px; border-top:1px solid var(--border); padding-top:6px;">
      <table style="width:100%; border-collapse:collapse; font-size:11px;">
        <thead>
          <tr style="color:var(--muted); text-align:left; border-bottom:1px solid var(--border);">
            <th style="padding:3px 6px; font-weight:600;" title="Observation Date">Date</th>
            <th style="padding:3px 6px; font-weight:600;" title="Observation Folder">Folder</th>
            <th style="padding:3px 6px; font-weight:600;" title="Start Time">Start</th>
            <th style="padding:3px 6px; font-weight:600;" title="End Time">End</th>
            <th style="padding:3px 6px; font-weight:600; text-align:right;" title="Exposure Time (seconds)">Exp</th>
            <th style="padding:3px 6px; font-weight:600;" title="Filter">Filt</th>
            <th style="padding:3px 6px; font-weight:600; text-align:right;" title="Total Exposures">#Exp</th>
            <th style="padding:3px 6px; font-weight:600; text-align:right;" title="Integration Time (minutes)">Integ</th>
            <th style="padding:3px 6px; font-weight:600;" title="Notes">Notes</th>
          </tr>
        </thead>
        <tbody>${rows}</tbody>
      </table>
    </div>
  `;
}

function renderProjects(projects) {
  obsById.clear();
  const list = document.getElementById('projects-list');
  if (!projects || projects.length === 0) {
    list.innerHTML = '<div style="color:var(--muted); font-size:12px;">No project yet for this DSO.</div>';
    return;
  }
  list.innerHTML = projects.map((p, idx) => {
    const integStr = p.TotalIntegrationMins ? (parseFloat(p.TotalIntegrationMins) / 60).toFixed(1) + ' hrs' : '&mdash;';
    const heading = projects.length > 1
      ? `Project ${idx + 1} of ${projects.length}${p.IsMosaic ? ' &mdash; Mosaic' : ''}`
      : (p.IsMosaic ? 'Project &mdash; Mosaic' : 'Project');
    return `
      <div style="background:var(--bg); border:1px solid var(--border); border-radius:var(--radius); padding:10px 14px;">
        <div style="font-weight:600; font-size:12px; color:var(--muted); margin-bottom:8px; text-transform:uppercase; letter-spacing:0.05em;">${heading}</div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; font-size:13px;">
          <div><span style="color:var(--muted); font-size:11px; display:block;">Project Folder</span>${p.ProjectFolder ? escHtml(p.ProjectFolder) : '&mdash;'}</div>
          <div><span style="color:var(--muted); font-size:11px; display:block;">Last Observed</span>${p.MostRecentObservation ? escHtml(p.MostRecentObservation) : '&mdash;'}</div>
          <div><span style="color:var(--muted); font-size:11px; display:block;">Total Lights</span>${p.TotalLights ?? '&mdash;'}</div>
          <div><span style="color:var(--muted); font-size:11px; display:block;">Integration Time</span>${integStr}</div>
        </div>
        ${renderObservationsTable(p.Observations)}
      </div>
    `;
  }).join('');
}

// ──────────────────────────────────────────────
// Inline editing of Observation Filter / Integ / Notes
// (api_update_observation.php). #Exp stays read-only -- the audit resets it
// from the folder name. An edited Integ is never overwritten by the audit;
// clearing it lets the next audit recalculate it.
// ──────────────────────────────────────────────
document.getElementById('projects-list').addEventListener('click', e => {
  const td = e.target.closest('td.obs-edit');
  if (!td || td.querySelector('input')) return;
  startObsEdit(td);
});

function startObsEdit(td) {
  const id    = Number(td.dataset.id);
  const field = td.dataset.field;
  const o     = obsById.get(id);
  if (!o) return;

  const original = o[field] ?? '';
  const input = document.createElement('input');
  input.type  = 'text';
  input.value = field === 'IntegrationMins' && original !== ''
    ? String(parseFloat(original))
    : String(original);
  if (field === 'IntegrationMins') input.inputMode = 'decimal';
  if (field === 'Notes') td.style.maxWidth = 'none';

  const savedHtml = td.innerHTML;
  td.innerHTML = '';
  td.appendChild(input);
  input.focus();
  input.select();

  let done = false;
  const cancel = () => { if (done) return; done = true; td.innerHTML = savedHtml; td.style.maxWidth = ''; };
  const commit = async () => {
    if (done) return;
    const val = input.value.trim();
    const unchanged = field === 'IntegrationMins'
      ? (val === '' ? original === '' || original === null : parseFloat(val) === parseFloat(original))
      : val === String(original);
    if (unchanged) { cancel(); return; }
    if (field === 'IntegrationMins' && val !== '' && (isNaN(Number(val)) || Number(val) < 0)) {
      toast('Integ must be a number of minutes (or blank).', 'err', 5000);
      input.focus();
      return;
    }
    done = true;
    td.classList.add('saving');
    input.disabled = true;
    try {
      const res  = await apiFetch('api_update_observation.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ObservationID: id, [field]: val }),
      });
      const data = await res.json();
      if (!data.success) throw new Error(data.error || 'unknown error');
      Object.assign(o, data.observation);
      toast('Saved', 'ok', 1500);
      // Re-fetch so project totals (Integration Time) reflect the edit.
      const dsoKey = currentObject && currentObject.DSOKey;
      const row = dsoKey ? await fetchOneByKey(dsoKey) : null;
      if (row && currentObject && currentObject.DSOKey === dsoKey) {
        currentObject.Projects = row.Projects;
        renderProjects(row.Projects || []);
      }
    } catch (err) {
      td.classList.remove('saving');
      td.innerHTML = savedHtml;
      toast('Save failed: ' + err.message, 'err', 8000);
    }
  };

  input.addEventListener('keydown', ev => {
    if (ev.key === 'Enter')  { ev.preventDefault(); commit(); }
    if (ev.key === 'Escape') { ev.preventDefault(); cancel(); }
  });
  input.addEventListener('blur', commit);
}

// Creates a Projects row for the currently-loaded DSO -- the in-admin
// equivalent of running pythonscripts/sync_projects.py for just this one
// DSO. Nothing auto-detects new myWorks folders, so this (or that script)
// is the only way a new folder gets a Project row at all; Sync Folder
// refuses with "No Project exists yet" until one does.
async function addProject() {
  if (!currentObject || !currentObject.DSOKey) {
    toast('Create this DSO first, then add a Project.', 'err');
    return;
  }
  if (!requireLocal('Add Project')) return;
  const folder = (prompt('myWorks folder name for this Project (e.g. ic342_hidden_galaxy):') || '').trim();
  if (!folder) return;

  const btn = document.getElementById('btn-add-project');
  btn.disabled = true;

  try {
    const res  = await apiFetch('api_add_project.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ DSOKey: currentObject.DSOKey, ProjectFolder: folder }),
    });
    const data = await res.json();

    if (data.localOnly) { showLocalOnlyDialog('Add Project'); return; }
    if (!data.success) {
      toast('Add Project failed: ' + (data.error || 'unknown error'), 'err', 8000);
      return;
    }

    toast('Project added: ' + folder, 'ok');
    await flushSave();
    const row = await fetchOneByKey(currentObject.DSOKey);
    if (row) loadObject(row);

  } catch (e) {
    toast('Network error: ' + e.message, 'err', 8000);
  } finally {
    btn.disabled = false;
  }
}

// Scans this DSO's myWorks Project folder on disk and adds/updates
// Observations rows to match (see pythonscripts/audit_observations.py,
// which this just wraps -- api_audit_observations.php runs it scoped to
// one Project and non-interactively). Safe to run any time: it never
// deletes a row, only flags ones no longer found on disk ("orphaned"),
// and it skips anything ambiguous rather than guessing. Called both from
// this button directly and automatically at the end of Sync Folder.
async function auditObservations({ silent = false } = {}) {
  if (!currentObject || !currentObject.DSOKey) {
    if (!silent) toast('Create this DSO first, then audit observations.', 'err');
    return;
  }
  if (!LOCAL_MODE) {
    if (!silent) showLocalOnlyDialog('Audit Observations');
    return;
  }
  const dsoKey = currentObject.DSOKey;

  const panel = document.getElementById('obs-audit-result');
  const btn   = document.getElementById('btn-audit-observations');
  if (btn) btn.disabled = true;
  if (!silent) {
    panel.style.display = '';
    panel.innerHTML = '<span style="color:var(--muted); font-size:12px;">Scanning myWorks folder for observations&hellip;</span>';
  }

  try {
    const res  = await apiFetch('api_audit_observations.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ DSOKey: dsoKey }),
    });
    const data = await res.json();

    // User moved on to a different DSO while this was running -- don't
    // paint this DSO's result over the one now on screen.
    if (!currentObject || currentObject.DSOKey !== dsoKey) return;

    if (data.localOnly) { showLocalOnlyDialog('Audit Observations'); return; }
    if (data.error) {
      const err = new Error(data.error);
      err.rawOutput = data.output || null;  // audit_observations.py's raw stdout/stderr, when the wrapper captured it
      throw err;
    }

    const created      = data.created ?? [];
    const updated      = data.updated ?? [];
    const deleted      = data.deleted ?? [];
    const orphaned     = data.orphaned ?? [];
    const legacy       = data.legacyNoFolder ?? [];
    const ambiguous    = data.ambiguous ?? [];
    const unrecognized = data.unrecognized ?? [];
    const runErrors    = data.errors ?? [];   // per-Project failures when a DSO has several

    obsHeldForReview = {
      dsoKey,
      folders: new Set(orphaned.map(o => (o.ObservationFolder || '').toLowerCase()).filter(Boolean)),
      dates:   new Set(legacy.map(o => o.ObservationDate).filter(Boolean)),
    };

    const nothingChanged = created.length === 0 && updated.length === 0 && deleted.length === 0
      && orphaned.length === 0 && legacy.length === 0 && ambiguous.length === 0 && unrecognized.length === 0;

    // In silent (auto, post-Sync-Folder) mode, stay quiet unless there's
    // actually something worth surfacing -- an automatic step shouldn't
    // pop up a panel every time to say "nothing changed".
    // Rows kept for review are flagged in the table itself, so on their own
    // they don't warrant popping the panel open on every DSO open.
    const silentWorthy = created.length || updated.length || deleted.length
      || ambiguous.length || runErrors.length;
    if (silent && !silentWorthy) {
      if (orphaned.length || legacy.length) renderProjects(currentObject.Projects || []);
      return;
    }

    panel.style.display = '';
    let html = `<div style="font-size:12px; border:1px solid var(--border); border-radius:var(--radius); padding:10px 14px; background:var(--bg);">`;
    html += `<div style="font-weight:600; margin-bottom:8px;">Observation audit result</div>`;

    if (nothingChanged && runErrors.length === 0) {
      html += `<div style="color:var(--muted);">No changes &mdash; observations already match disk.</div>`;
    }

    if (runErrors.length > 0) {
      html += `<div style="color:var(--danger); margin-bottom:4px;">&#x2716; Audit failed for ${runErrors.length} Project${runErrors.length > 1 ? 's' : ''}:</div>`;
      html += `<ul style="margin:0 0 8px 16px; padding:0;">`;
      runErrors.forEach(r => { html += `<li>${escHtml(r.ProjectFolder || '')} &mdash; ${escHtml(r.error || 'unknown error')}</li>`; });
      html += `</ul>`;
    }

    if (created.length > 0) {
      html += `<div style="color:var(--accent2); margin-bottom:4px;">&#x2714; Logged ${created.length} new observation${created.length > 1 ? 's' : ''}:</div>`;
      html += `<ul style="margin:0 0 8px 16px; padding:0;">`;
      created.forEach(c => { html += `<li>${escHtml(c.ObservationFolder)}</li>`; });
      html += `</ul>`;
    }

    if (updated.length > 0) {
      html += `<div style="color:var(--accent); margin-bottom:4px;">&#x21bb; Updated ${updated.length} observation${updated.length > 1 ? 's' : ''}:</div>`;
      html += `<ul style="margin:0 0 8px 16px; padding:0;">`;
      updated.forEach(u => { html += `<li>${escHtml(u.Sub)}</li>`; });
      html += `</ul>`;
    }

    if (ambiguous.length > 0) {
      html += `<div style="color:var(--warn); margin-bottom:4px;">&#x26A0; ${ambiguous.length} ambiguous match${ambiguous.length > 1 ? 'es' : ''} &mdash; more than one existing observation shares the same night; fix by hand, then re-run:</div>`;
      html += `<ul style="margin:0 0 8px 16px; padding:0;">`;
      ambiguous.forEach(a => { html += `<li>${escHtml(a.Sub)}</li>`; });
      html += `</ul>`;
    }

    if (deleted.length > 0) {
      html += `<div style="color:var(--danger); margin-bottom:4px;">&#x1F5D1; Deleted ${deleted.length} observation${deleted.length > 1 ? 's' : ''} no longer on disk:</div>`;
      html += `<ul style="margin:0 0 8px 16px; padding:0;">`;
      deleted.forEach(d => { html += `<li>${escHtml(d.ObservationFolder || d.ObservationDate)}</li>`; });
      html += `</ul>`;
    }

    // Held back rather than auto-deleted, because they carry hand-entered
    // data (Filter/Notes/SeeingConditions/etc) that a disk re-sync can never
    // regenerate -- see MANUAL_DATA_FIELDS in audit_observations.py.
    if (orphaned.length > 0 || legacy.length > 0) {
      const held = [...orphaned, ...legacy];
      html += `<div style="color:var(--warn); margin-bottom:4px;">&#x26A0; ${held.length} observation${held.length > 1 ? 's' : ''} no longer on disk but kept for review (has manual notes/conditions data attached):</div>`;
      html += `<ul style="margin:0 0 8px 16px; padding:0;">`;
      held.forEach(o => {
        const label = o.ObservationFolder || o.ObservationDate;
        const fields = (o.ManualFields || []).join(', ');
        html += `<li>${escHtml(o.DSOKey)} ${escHtml(label)} &mdash; ${escHtml(fields)}</li>`;
      });
      html += `</ul>`;
    }

    if (unrecognized.length > 0) {
      html += `<div style="color:var(--muted); margin-bottom:4px;">${unrecognized.length} subfolder${unrecognized.length > 1 ? 's' : ''} skipped (name didn't match a known pattern):</div>`;
      html += `<ul style="margin:0 0 8px 16px; padding:0;">`;
      unrecognized.forEach(u => { html += `<li>${escHtml(u.Sub)}</li>`; });
      html += `</ul>`;
    }

    html += `<button class="btn" style="font-size:11px; padding:2px 10px; margin-top:4px;" onclick="dismissObsAuditResult()">Dismiss</button>`;
    html += `</div>`;
    panel.innerHTML = html;

    if (created.length > 0 || updated.length > 0 || deleted.length > 0) {
      // Only the Projects/Observations section changed -- repaint just that,
      // so a background audit never re-renders fields being edited.
      const row = await fetchOneByKey(dsoKey);
      if (row && currentObject && currentObject.DSOKey === dsoKey) {
        currentObject.Projects = row.Projects || [];
        renderProjects(currentObject.Projects);
      }
    } else if (orphaned.length || legacy.length) {
      renderProjects(currentObject.Projects || []);  // repaint with review flags
    }

  } catch (e) {
    // Failures are shown even for the automatic (silent) run -- a silent
    // audit that quietly fails is exactly how stale rows went unnoticed.
    if (currentObject && currentObject.DSOKey === dsoKey) {
      panel.style.display = '';
      let html = `<span style="color:var(--danger); font-size:12px;">Audit failed: ${escHtml(e.message)}</span>`;
      // Surface the script's raw stdout/stderr (e.g. a Python traceback) when
      // the wrapper captured one -- without this, a crash before the JSON
      // print just shows the generic "did not return valid JSON" message
      // with no way to see what actually broke.
      if (e.rawOutput) {
        html += `<pre style="margin-top:8px; padding:8px; background:var(--bg); border:1px solid var(--border); border-radius:var(--radius); font-size:11px; white-space:pre-wrap; max-height:240px; overflow:auto;">${escHtml(e.rawOutput)}</pre>`;
      }
      panel.innerHTML = html;
    }
  } finally {
    if (btn) btn.disabled = false;
  }
}

function dismissObsAuditResult() {
  const p = document.getElementById('obs-audit-result');
  p.style.display = 'none';
  p.innerHTML = '';
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
    <td><input type="text" placeholder="e.g. M42" value="${cat.CatalogID || ''}" class="cat-id" style="text-transform:uppercase" autocomplete="off"></td>
    <td style="text-align:center"><input type="checkbox" class="cat-primary" ${cat.IsPrimary ? 'checked' : ''}></td>
    <td><button class="btn-icon" onclick="removeRowAndSave(this)" title="Remove">&#x2715;</button></td>
  `;
  tbody.appendChild(tr);
}

function removeRowAndSave(btn) {
  btn.closest('tr').remove();
  scheduleSave(0);
}

function getCatalogRows() {
  return Array.from(document.querySelectorAll('#cat-tbody tr')).map(tr => ({
    CatalogID: tr.querySelector('.cat-id').value.trim().toUpperCase(),
    IsPrimary: tr.querySelector('.cat-primary').checked ? 1 : 0,
  })).filter(r => r.CatalogID);
}

// ──────────────────────────────────────────────
// Save -- automatic. Every edit in the editor schedules a save of the
// whole DSO (debounced); there is no Save button. A brand-new DSO is
// created once with "Create DSO", after which autosave takes over.
// Gallery Hide/Unhide/Featured are separate immediate actions
// (api_gallery_image.php). Observation cells save themselves.
// ──────────────────────────────────────────────
let autosaveEnabled = false;   // true while an existing DSO is loaded
let saveTimer   = null;
let savePending = false;
let saveInFlight = null;
let tmpKeyCounter = 0;
let listFieldsSnapshot = '';   // sidebar-visible fields at last save

function nextTmpKey(prefix) { return prefix + (++tmpKeyCounter); }

function listFieldsNow() {
  return ['CommonName', 'ConstellationID', 'WantBetter']
    .map(f => (document.getElementById('f_' + f) || {}).value || '').join('|');
}

function setSaveStatus(state, detail) {
  const el = document.getElementById('save-status');
  if (!el) return;
  el.className = 'save-status' + (state === 'saving' || state === 'pending' ? ' saving' : state === 'error' ? ' error' : '');
  el.textContent = {
    pending: 'Unsaved changes…',
    saving:  'Saving…',
    saved:   '✓ All changes saved',
    error:   '⚠ Not saved — ' + (detail || 'error'),
    idle:    '',
  }[state] || '';
}

function scheduleSave(delay = 800) {
  if (!autosaveEnabled) return;
  savePending = true;
  setSaveStatus('pending');
  clearTimeout(saveTimer);
  saveTimer = setTimeout(runSave, delay);
}

async function runSave() {
  clearTimeout(saveTimer);
  saveTimer = null;
  if (saveInFlight) { await saveInFlight; }
  if (!savePending) return;
  savePending = false;
  saveInFlight = saveObject(true).finally(() => { saveInFlight = null; });
  await saveInFlight;
}

// Save anything pending right now (before switching DSO, running an
// action that re-renders, etc.).
async function flushSave() {
  if (savePending || saveTimer) await runSave();
  else if (saveInFlight) await saveInFlight;
}

async function saveNow() {
  savePending = true;
  await runSave();
}

async function createObject() {
  await saveNow();
}

// Edits anywhere in the editor autosave, except areas that save themselves
// or aren't DSO fields (observation cells, sync-result inputs, hidden list,
// the Featured tick-box, the image Order field -- moveGalleryCard() saves
// that once the field loses focus).
const AUTOSAVE_SKIP = '#projects-list, #obs-audit-result, #gi-sync-result, #gi-hidden, .gi-isfeature, .gi-order-input, #f_DSOKey';
function wireAutosave() {
  const ed = document.getElementById('editor');
  if (!ed) return;
  ed.addEventListener('input',  e => { if (!e.target.closest(AUTOSAVE_SKIP)) scheduleSave(800); });
  ed.addEventListener('change', e => { if (!e.target.closest(AUTOSAVE_SKIP)) scheduleSave(300); });
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', wireAutosave);
else wireAutosave();
window.addEventListener('beforeunload', e => {
  if (savePending || saveTimer || saveInFlight) {
    runSave();
    e.preventDefault();
    e.returnValue = '';
  }
});

async function saveObject(silent = false) {
  const dsoKey = document.getElementById('f_DSOKey').value.trim().toUpperCase();
  if (!dsoKey) { toast('DSO Key is required', 'err'); setSaveStatus('error', 'DSO Key is required'); return; }
  const isNew = !currentObject;

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
  payload.CatalogIDs         = getCatalogRows();
  payload.CatalogIDsComplete = !isNew;   // full list -> removals/primary changes persist
  payload.GalleryImages      = getGalleryImageRows();
  payload.DSOLinks           = getDSOLinkRows();

  setSaveStatus('saving');
  try {
    const res  = await apiFetch('api_save.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
    const data = await res.json();
    if (!data.success) {
      setSaveStatus('error', data.error || 'unknown error');
      toast('Save failed: ' + (data.error || 'Unknown error'), 'err', 6000);
      return;
    }

    // Give new rows their database IDs so the next save updates them
    // instead of inserting them again.
    const gMap = data.newGalleryImageIds || {};
    document.querySelectorAll('#gi-list .gi-card').forEach(card => {
      if (!card.dataset.galleryImageId && card.dataset.tmp && gMap[card.dataset.tmp]) {
        card.dataset.galleryImageId = gMap[card.dataset.tmp];
        delete card.dataset.tmp;
        const btn = card.querySelector('.gi-hide-btn');
        if (btn) btn.textContent = 'Hide';
      }
    });
    const lMap = data.newLinkIds || {};
    document.querySelectorAll('#lnk-tbody tr').forEach(tr => {
      if (!tr.dataset.linkId && tr.dataset.tmp && lMap[tr.dataset.tmp]) {
        tr.dataset.linkId = lMap[tr.dataset.tmp];
        delete tr.dataset.tmp;
      }
    });
    if (data.gallery) applyGalleryState(data.gallery);

    snapshotBlurbFields();
    setSaveStatus(savePending ? 'pending' : 'saved');

    if (isNew) {
      if (!silent) toast(dsoKey + ' created', 'ok');
      await fetchList(document.getElementById('search').value.trim());
      const row = await fetchOneByKey(dsoKey);
      if (row) loadObject(row);
    } else if (listFieldsNow() !== listFieldsSnapshot) {
      listFieldsSnapshot = listFieldsNow();
      await fetchList(document.getElementById('search').value.trim());
    }
  } catch (e) {
    setSaveStatus('error', e.message);
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

  // A pending autosave after the delete would re-create the DSO.
  autosaveEnabled = false;
  clearTimeout(saveTimer); saveTimer = null; savePending = false;
  if (saveInFlight) await saveInFlight;

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
      autosaveEnabled = true;
      toast('Delete failed: ' + (data.error || 'Unknown error'), 'err', 5000);
    }
  } catch (e) {
    autosaveEnabled = true;
    toast('Network error: ' + e.message, 'err', 5000);
  }
}

// ──────────────────────────────────────────────
// AI: Populate all fields
// ──────────────────────────────────────────────
async function aiPopulate() {
  const dsoId = document.getElementById('f_DSOKey').value.trim().toUpperCase();
  if (!dsoId) { toast('Enter a DSO Key first', 'err'); return; }
  if (isSolarObjectType(document.getElementById('f_ObjectTypeID').value)) {
    toast('AI Populate is disabled for Sun/Moon \u2014 use the solar pipeline.', 'err');
    return;
  }

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
    object_type_id:     document.getElementById('f_ObjectTypeID').value,
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

    // Constellation needs special handling: the AI-returned code might not
    // exist yet as a dropdown option if this constellation has never been
    // used before. In that case, auto-add it (same lookup as "+ Add new
    // constellation") instead of silently failing to populate.
    if (f.ConstellationID && document.getElementById('f_ConstellationID').value.trim() === '') {
      const sel = document.getElementById('f_ConstellationID');
      sel.value = f.ConstellationID;
      if (sel.value === f.ConstellationID) {
        populated++;
      } else {
        try {
          const cRes  = await apiFetch('api_constellation_add.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name: f.ConstellationID }),
          });
          const cData = await cRes.json();
          if (cData.success) {
            await loadConstellations(cData.ConstellationID);
            populated++;
          } else {
            console.warn('Could not auto-add constellation:', cData.error);
          }
        } catch (e) {
          console.warn('Constellation auto-add failed:', e);
        }
      }
    }

    // Refresh the formatted RA/Dec display boxes -- AI Populate only fills
    // the underlying hidden decimal inputs, so without this the formatted
    // text stays blank until the object is reselected.
    setRADisplay(document.getElementById('f_RAHours').value || null);
    setDecDisplay(document.getElementById('f_DecDegrees').value || null);

    if (f.SocialBlurb) {
      document.getElementById('f_SocialBlurb').value = f.SocialBlurb;
      populated++;
    }
    if (Array.isArray(f.CatalogIDs) && f.CatalogIDs.length > 0 && getCatalogRows().length === 0) {
      renderCatalogTable(f.CatalogIDs);
      populated++;
    }

    toast('Auto-saving&#8230;', 'info', 3000);
    await saveNow();
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
      toast('Blurb generated &mdash; saved', 'ok');
      scheduleSave(0);
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
  if (!dsoKey || !currentObject) { toast('Create the DSO first before syncing.', 'err'); return; }
  await flushSave();   // so the re-render below doesn't drop unsaved edits
  if (isSolarObjectType(document.getElementById('f_ObjectTypeID').value)) {
    toast('Sync Folder is disabled for Sun/Moon \u2014 use the solar pipeline.', 'err');
    return;
  }
  if (!requireLocal('Sync Folder')) return;

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
    if (data.localOnly) {
      dismissSyncResult();
      showLocalOnlyDialog('Sync Folder');
      return;
    }
    if (data.error) throw new Error(data.error);

    const { inserted, updated, warnings, needs_session_dir, projectFolder, mode } = data;
    const misnamed      = data.misnamed || [];
    const dupsRemoved   = data.duplicatesRemoved || [];
    const staleProjects = data.staleProjects || [];

    let html = `<div style="font-size:12px; border:1px solid var(--border); border-radius:var(--radius); padding:10px 14px; background:var(--bg);">`;
    const modeLabel = mode === 'local' ? '(local &mdash; WORKS_ROOT)' : '(remote &mdash; web images only)';
    html += `<div style="font-weight:600; margin-bottom:8px;">Sync result <span style="color:var(--muted); font-weight:400; font-size:11px;">${modeLabel}</span></div>`;

    if (inserted.length === 0 && updated.length === 0 && warnings.length === 0 && (!needs_session_dir || needs_session_dir.length === 0) && misnamed.length === 0 && staleProjects.length === 0 && dupsRemoved.length === 0) {
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
      html += `<div style="color:var(--danger); margin-bottom:4px;">&#x2716; Deleted ${warnings.length} image${warnings.length > 1 ? 's' : ''} whose fav file is no longer in public/images/fav/:</div>`;
      html += `<ul style="margin:0 0 8px 16px; padding:0;">`;
      warnings.forEach(w => {
        html += `<li>${escHtml(w.BaseName)}</li>`;
      });
      html += `</ul>`;
    }

    if (dupsRemoved.length > 0) {
      html += `<div style="color:var(--warn); margin-bottom:4px;">&#x2716; Removed ${dupsRemoved.length} duplicate gallery row${dupsRemoved.length > 1 ? 's' : ''} (same Base Name as another row):</div>`;
      html += `<ul style="margin:0 0 8px 16px; padding:0;">`;
      dupsRemoved.forEach(d => {
        html += `<li>${escHtml(d.BaseName)} &mdash; row #${escHtml(String(d.GalleryImageID))} deleted, kept #${escHtml(String(d.KeptID))}</li>`;
      });
      html += `</ul>`;
    }

    if (misnamed.length > 0) {
      html += `<div style="color:var(--warn); margin-bottom:4px;">&#x26A0; ${misnamed.length} fav file${misnamed.length > 1 ? 's' : ''} in myWorks can't be synced &mdash; name doesn't start with ${escHtml(dsoKey.toLowerCase())}:</div>`;
      html += `<ul style="margin:0 0 8px 16px; padding:0;">`;
      misnamed.forEach(m => {
        html += `<li>${escHtml(m.ProjectFolder)}\\${escHtml(m.SessionDir)}\\<strong>${escHtml(m.File)}</strong></li>`;
      });
      html += `</ul>`;
    }

    if (staleProjects.length > 0) {
      html += `<div style="color:var(--warn); margin-bottom:4px;">&#x26A0; Project folder${staleProjects.length > 1 ? 's' : ''} missing from myWorks (skipped):</div>`;
      html += `<ul style="margin:0 0 8px 16px; padding:0;">`;
      staleProjects.forEach(sp => {
        html += `<li>Project #${escHtml(String(sp.ProjectID))} &mdash; ${escHtml(sp.ProjectFolder || '(none)')}</li>`;
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

    {
      const searchRes  = await apiFetch(`api_search.php?q=${encodeURIComponent(dsoKey)}`);
      const searchData = await searchRes.json();
      const row = Array.isArray(searchData)
        ? searchData.find(r => r.DSOKey === dsoKey)
        : null;
      if (row) renderGalleryImages(row.GalleryImages || []);
    }

    // Resyncing pictures is exactly when new observation sessions are
    // likely to exist too, so audit them automatically here -- local mode
    // only, since Audit Observations needs the same WORKS_ROOT access
    // Sync Folder just used. Silent unless it actually finds something,
    // so a routine sync doesn't pop up an empty "nothing changed" panel.
    if (mode === 'local') {
      auditObservations({ silent: true });
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
  const segments = String(baseName).toLowerCase().split('_').reverse();
  for (const seg of segments) {
    if (PALETTE_SUFFIX_MAP[seg] !== undefined) return PALETTE_SUFFIX_MAP[seg];
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

// Pixel size + aspect ratio of a loaded image, e.g. "1080×1350 · 4:5".
// Named when within 2% of a common ratio (fav is normally 4:5, full 9:16,
// wall 16:9, but some favs are deliberately 1:1 or 5:4); otherwise the
// raw w:h as a decimal.
const COMMON_RATIOS = [[4,5],[1,1],[5,4],[9,16],[16,9],[3,4],[4,3],[2,3],[3,2]];
function aspectLabel(w, h) {
  if (!w || !h) return '';
  const r = w / h;
  for (const [a, b] of COMMON_RATIOS) {
    if (Math.abs(r / (a / b) - 1) <= 0.02) return `${w}\u00d7${h} \u00b7 ${a}:${b}`;
  }
  return `${w}\u00d7${h} \u00b7 ${r.toFixed(2)}:1`;
}

function showGalleryDims(card) {
  const img  = card.querySelector('.gi-preview-img');
  const dims = card.querySelector('.gi-dims');
  if (img && dims) dims.textContent = aspectLabel(img.naturalWidth, img.naturalHeight);
}

function updateGalleryPreview(card, baseName) {
  const preview = card.querySelector('.gi-preview');
  const img = card.querySelector('.gi-preview-img');
  const empty = card.querySelector('.gi-preview-empty');
  const url = galleryFavUrl(baseName);

  card.querySelector('.gi-dims').textContent = '';
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
  list.innerHTML = '';
  images.filter(img => !parseInt(img.IsHidden || 0)).forEach(img => addGalleryImageCard(img));
  renderHiddenGalleryImages(images.filter(img => parseInt(img.IsHidden || 0)));
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
      <span class="gi-dims"></span>
    </div>
    <div class="gi-card-fields">
      <div class="field">
        <label>Base Name <em style="color:var(--danger)">*</em></label>
        <input type="text" class="gi-basename-input" placeholder="e.g. m42_orion_a1228"
               value="${escHtml(baseName)}" autocomplete="off">
      </div>
      <div class="field">
        <label>Palette</label>
        <select class="gi-palette">${paletteOptions(paletteId)}</select>
      </div>
      <div class="field">
        <label>Date Captured</label>
        <input type="date" class="gi-date" value="${escHtml(img.DateCaptured || '')}" autocomplete="off">
      </div>
      <div class="field">
        <label>Caption</label>
        <input type="text" class="gi-caption" placeholder="Optional display caption"
               value="${escHtml(img.Caption || '')}" autocomplete="off">
      </div>
      <div class="field">
        <label>Copyright</label>
        <input type="text" class="gi-copyright" placeholder="e.g. Carl Smith"
               value="${escHtml(img.Copyright || '')}" autocomplete="off">
      </div>
      <div class="field">
        <label>Equipment</label>
        <input type="text" class="gi-equipment" placeholder="e.g. S30"
               value="${escHtml(equipment)}" autocomplete="off">
      </div>
      <div class="field">
        <label>Session Directory</label>
        <input type="text" class="gi-sessiondir" placeholder="e.g. 20251108_165x60s_S30"
               value="${escHtml(sessionDir)}" autocomplete="off">
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
               value="${escHtml(img.Attribution || '')}" autocomplete="off">
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
             style="width:60px; font-size:12px; padding:3px 6px;" autocomplete="off"
             title="Type a position (or use the arrows), then press Enter or click away to move the image there">
      <button class="btn gi-hide-btn" style="font-size:11px; padding:3px 10px; border-color:var(--warn); color:var(--warn);"
              onclick="hideOrRemoveGalleryCard(this)"
              title="Hide this image from the public gallery. It stays in the database (and Sync Folder leaves it hidden); unhide it from the Hidden images list below.">${img.GalleryImageID ? 'Hide' : 'Remove'}</button>
    </div>
  `;

  const previewImg = card.querySelector('.gi-preview-img');
  previewImg.addEventListener('load', () => showGalleryDims(card));
  if (previewImg.complete && previewImg.naturalWidth) showGalleryDims(card);

  card.querySelector('.gi-basename-input').addEventListener('input', function() {
    card.querySelector('.gi-basename').textContent = this.value || 'New Image';
    updateGalleryPreview(card, this.value);
    const palSel = card.querySelector('.gi-palette');
    const inferred = paletteIdFromBaseName(this.value);
    if (inferred !== 0) palSel.value = inferred;
  });

  card.querySelector('.gi-isfeature').addEventListener('change', function() {
    featureGalleryCard(card, this);
  });

  // Order is applied when the field loses focus (or on Enter), not on every
  // keystroke/arrow click -- see moveGalleryCard(). Escape puts it back.
  const orderInput = card.querySelector('.gi-order-input');
  orderInput.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); this.blur(); }
    else if (e.key === 'Escape') {
      this.value = card.querySelector('.gi-sortorder').value;
      this.blur();
    }
  });
  orderInput.addEventListener('blur', function() {
    const was = parseInt(card.querySelector('.gi-sortorder').value);
    const now = parseInt(this.value);
    if (Number.isNaN(now) || now === was) { this.value = was; return; }
    moveGalleryCard(card, now);
  });

  list.appendChild(card);
  updateGiEmpty();
}

function toggleAttribution(sel) {
  const row = sel.closest('.gi-card').querySelector('.gi-attribution-row');
  row.classList.toggle('visible', sel.value === '0');
}

// ── Gallery image actions (immediate; api_gallery_image.php) ──────────

// Saved card -> Hide. A new card that was never saved -> just drop it.
async function hideOrRemoveGalleryCard(btn) {
  const card = btn.closest('.gi-card');
  if (!card.dataset.galleryImageId) {
    await flushSave();                       // it may have just been saved
  }
  if (!card.dataset.galleryImageId) {
    card.remove();
    updateGiEmpty();
    return;
  }
  await galleryAction(card.dataset.galleryImageId, 'hide');
}

async function unhideGalleryImage(id) {
  await galleryAction(id, 'unhide');
}

// Featured is one-of-many: ticking a card makes it the featured image
// (SortOrder 0, the others renumbered); unticking the featured one is
// refused -- feature a different image instead.
async function featureGalleryCard(card, cb) {
  if (!cb.checked) {
    cb.checked = true;
    toast('To change the featured image, tick Featured on another image.', 'info', 4000);
    return;
  }
  await flushSave();                         // a new card needs its ID first
  if (!card.dataset.galleryImageId) {
    cb.checked = false;
    toast('Enter a Base Name first.', 'err');
    return;
  }
  await galleryAction(card.dataset.galleryImageId, 'feature');
}

async function galleryAction(id, action) {
  await flushSave();
  const dsoKey = currentObject && currentObject.DSOKey;
  try {
    const res  = await apiFetch('api_gallery_image.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ GalleryImageID: parseInt(id), action }),
    });
    const data = await res.json();
    if (!data.success) throw new Error(data.error || 'unknown error');

    if (action === 'feature') {
      applyGalleryState(data.images);
    } else {
      // hide/unhide move a card between lists -- re-render from the DB.
      const fresh = dsoKey ? await fetchOneByKey(dsoKey) : null;
      if (fresh && currentObject && currentObject.DSOKey === dsoKey) {
        renderGalleryImages(fresh.GalleryImages || []);
      }
    }
    const name = (data.images.find(i => String(i.GalleryImageID) === String(id)) || {}).BaseName || '';
    const verb = { hide: 'Hidden', unhide: 'Unhidden', feature: 'Featured' }[action];
    toast(verb + (name ? ': ' + name : '') + (data.promoted ? ' \u2014 ' + data.promoted + ' is now featured' : ''), 'ok', 4000);
  } catch (e) {
    toast(action + ' failed: ' + e.message, 'err', 6000);
    const fresh = dsoKey ? await fetchOneByKey(dsoKey) : null;
    if (fresh) renderGalleryImages(fresh.GalleryImages || []);
  }
}

// Move a card to position `pos` among the visible images and renumber
// everything, so no two images ever share an order number (a shared number
// is what made the server's tie-break -- GalleryImageID -- put a "1" in
// slot 2, or send a change straight back). Position 0 is the featured
// image's slot: it's changed with the Featured tick-box, not here.
function moveGalleryCard(card, pos) {
  const list  = document.getElementById('gi-list');
  const input = card.querySelector('.gi-order-input');
  const cards = Array.from(list.querySelectorAll('.gi-card'));
  const featured = cards.find(c => c.querySelector('.gi-isfeature').checked) || null;

  if (card === featured) {
    input.value = card.querySelector('.gi-sortorder').value;
    toast('The featured image is always first. Tick Featured on another image to replace it.', 'info', 4000);
    return;
  }
  const others = cards.filter(c => c !== card && c !== featured);
  const first  = featured ? 1 : 0;
  if (pos < first) {
    if (featured) toast('Position 0 is the featured image — tick Featured to feature this one.', 'info', 4000);
    pos = first;
  }
  const idx = Math.min(Math.max(pos - first, 0), others.length);
  others.splice(idx, 0, card);
  const ordered = featured ? [featured, ...others] : others;

  ordered.forEach((c, i) => {
    c.querySelector('.gi-order-input').value = i;
    c.querySelector('.gi-sortorder').value   = i;
    list.appendChild(c);
  });
  card.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  scheduleSave(0);
}

// Apply server-side SortOrder/IsFeature (after a save or feature action)
// to the visible cards and put them in that order.
function applyGalleryState(images) {
  if (!Array.isArray(images)) return;
  const list  = document.getElementById('gi-list');
  const byId  = {};
  images.forEach(i => { byId[String(i.GalleryImageID)] = i; });
  const cards = Array.from(list.querySelectorAll('.gi-card'));
  cards.forEach(card => {
    const st = byId[card.dataset.galleryImageId];
    if (!st) return;
    const oi = card.querySelector('.gi-order-input');
    if (document.activeElement !== oi) oi.value = st.SortOrder;
    card.querySelector('.gi-sortorder').value   = st.SortOrder;
    setCardFeatured(card, !!st.IsFeature);
  });
  const orderOf = c => { const st = byId[c.dataset.galleryImageId]; return st ? st.SortOrder : 1e6; };
  const sorted  = [...cards].sort((a, b) => orderOf(a) - orderOf(b));
  // Only move cards when the order actually changed -- moving the card
  // being typed in would take focus away from it.
  if (sorted.some((c, i) => c !== cards[i])) sorted.forEach(c => list.appendChild(c));
}

function setCardFeatured(card, on) {
  card.classList.toggle('is-feature', on);
  card.querySelector('.gi-isfeature').checked = on;
  const badge = card.querySelector('.gi-feature-badge');
  if (on && !badge) {
    const span = document.createElement('span');
    span.className = 'gi-feature-badge';
    span.innerHTML = '&#9733; Featured';
    card.querySelector('.gi-card-header').appendChild(span);
  } else if (!on && badge) {
    badge.remove();
  }
}

function renderHiddenGalleryImages(images) {
  const wrap = document.getElementById('gi-hidden');
  const list = document.getElementById('gi-hidden-list');
  document.getElementById('gi-hidden-count').textContent = images.length;
  wrap.style.display = images.length ? '' : 'none';
  list.innerHTML = images.map(img => {
    const url = galleryFavUrl(img.BaseName);
    return `<div class="gi-hidden-item">
      <img src="${escHtml(url)}" alt="" loading="lazy" onerror="this.style.visibility='hidden'">
      <span class="name" title="${escHtml(img.BaseName)}">${escHtml(img.BaseName)}${img.Caption ? ' &mdash; ' + escHtml(img.Caption) : ''}</span>
      <button class="btn" style="font-size:11px; padding:3px 10px;" onclick="unhideGalleryImage(${parseInt(img.GalleryImageID)})">Unhide</button>
    </div>`;
  }).join('');
}

function getGalleryImageRows() {
  return Array.from(document.querySelectorAll('#gi-list .gi-card')).map(card => ({
    GalleryImageID: card.dataset.galleryImageId || null,
    _tmp:           card.dataset.galleryImageId ? undefined : (card.dataset.tmp || (card.dataset.tmp = nextTmpKey('g'))),
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
    SortOrder:      parseInt(card.querySelector('.gi-sortorder').value) || 0,
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
               value="${escHtml(lnk.Label || '')}" autocomplete="off"></td>
    <td><input type="url"  class="lnk-url"   placeholder="https://"
               value="${escHtml(lnk.URL || '')}" autocomplete="off"></td>
    <td><input type="number" class="lnk-order" value="${lnk.SortOrder || 0}"
               min="0" step="1" style="width:54px;" autocomplete="off"></td>
    <td><button class="btn-icon" onclick="removeRowAndSave(this)" title="Remove">&#x2715;</button></td>
  `;
  tbody.appendChild(tr);
}

function getDSOLinkRows() {
  return Array.from(document.querySelectorAll('#lnk-tbody tr')).map(tr => ({
    LinkID:    tr.dataset.linkId || null,
    _tmp:      tr.dataset.linkId ? undefined : (tr.dataset.tmp || (tr.dataset.tmp = nextTmpKey('l'))),
    Label:     tr.querySelector('.lnk-label').value.trim(),
    URL:       tr.querySelector('.lnk-url').value.trim(),
    SortOrder: parseInt(tr.querySelector('.lnk-order').value) || 0,
  })).filter(r => r.LinkID || (r.Label && r.URL));   // a saved link being retyped is kept, not deleted
}

// ──────────────────────────────────────────────
// RA / Dec  J2000 sexagesimal helpers
// ──────────────────────────────────────────────

/** Decimal hours  →  "hh mm ss.s" */
function raDecimalToHMS(h) {
  if (h === null || h === undefined || h === '' || isNaN(h)) return '';
  h = parseFloat(h);
  const hh = Math.floor(h);
  const rem1 = (h - hh) * 60;
  const mm = Math.floor(rem1);
  const ss = (rem1 - mm) * 60;
  return String(hh).padStart(2,'0') + ' '
       + String(mm).padStart(2,'0') + ' '
       + ss.toFixed(1).padStart(4,'0');
}

/** Decimal degrees  →  "±dd mm ss.s" */
function decDecimalToDMS(d) {
  if (d === null || d === undefined || d === '' || isNaN(d)) return '';
  d = parseFloat(d);
  const sign = d < 0 ? '-' : '+';
  const abs = Math.abs(d);
  const dd = Math.floor(abs);
  const rem1 = (abs - dd) * 60;
  const mm = Math.floor(rem1);
  const ss = (rem1 - mm) * 60;
  return sign
       + String(dd).padStart(2,'0') + ' '
       + String(mm).padStart(2,'0') + ' '
       + ss.toFixed(1).padStart(4,'0');
}

/** "hh mm ss.s"  →  decimal hours (null on parse failure) */
function hmsToDecimal(str) {
  str = str.trim().replace(/[hH°'"]/g, ' ').replace(/\s+/g,' ').trim();
  const parts = str.split(' ');
  if (parts.length < 2) return null;
  const hh = parseFloat(parts[0]);
  const mm = parseFloat(parts[1]) || 0;
  const ss = parseFloat(parts[2]) || 0;
  if (isNaN(hh) || isNaN(mm) || isNaN(ss)) return null;
  const val = hh + mm/60 + ss/3600;
  if (val < 0 || val > 24) return null;
  return val;
}

/** "±dd mm ss.s"  →  decimal degrees (null on parse failure) */
function dmsToDecimal(str) {
  str = str.trim().replace(/[°'"]/g, ' ').replace(/\s+/g,' ').trim();
  const sign = str.startsWith('-') ? -1 : 1;
  str = str.replace(/^[+\-]/, '').trim();
  const parts = str.split(' ');
  if (parts.length < 1) return null;
  const dd = parseFloat(parts[0]);
  const mm = parseFloat(parts[1]) || 0;
  const ss = parseFloat(parts[2]) || 0;
  if (isNaN(dd)) return null;
  const val = sign * (dd + mm/60 + ss/3600);
  if (val < -90 || val > 90) return null;
  return val;
}

function setRADisplay(decVal) {
  const fmt  = document.getElementById('f_RAHours_fmt');
  const hid  = document.getElementById('f_RAHours');
  const note = document.getElementById('f_RAHours_note');
  if (decVal === null || decVal === undefined || decVal === '') {
    fmt.value  = '';
    hid.value  = '';
    fmt.style.borderColor = '';
    note.textContent = '0 – 24 h';
  } else {
    const v = parseFloat(decVal);
    hid.value  = v;
    fmt.value  = raDecimalToHMS(v);
    fmt.style.borderColor = '';
    note.textContent = 'decimal: ' + v.toFixed(6) + ' h';
  }
}

function setDecDisplay(decVal) {
  const fmt  = document.getElementById('f_DecDegrees_fmt');
  const hid  = document.getElementById('f_DecDegrees');
  const note = document.getElementById('f_DecDegrees_note');
  if (decVal === null || decVal === undefined || decVal === '') {
    fmt.value  = '';
    hid.value  = '';
    fmt.style.borderColor = '';
    note.textContent = '−90 – +90 °';
  } else {
    const v = parseFloat(decVal);
    hid.value  = v;
    fmt.value  = decDecimalToDMS(v);
    fmt.style.borderColor = '';
    note.textContent = 'decimal: ' + v.toFixed(6) + '°';
  }
}

function syncRAFromFormatted(input) {
  const hid  = document.getElementById('f_RAHours');
  const note = document.getElementById('f_RAHours_note');
  const dec = hmsToDecimal(input.value);
  if (dec === null) {
    hid.value = '';
    input.style.borderColor = (input.value.trim() === '') ? '' : 'var(--danger)';
    note.textContent = 'Enter hh mm ss.s (0 – 24 h)';
  } else {
    hid.value = dec;
    input.style.borderColor = 'var(--accent2)';
    note.textContent = 'decimal: ' + dec.toFixed(6) + ' h';
  }
}

function normalizeRADisplay(input) {
  const dec = hmsToDecimal(input.value);
  if (dec !== null) {
    input.value = raDecimalToHMS(dec);
    input.style.borderColor = '';
  }
}

function syncDecFromFormatted(input) {
  const hid  = document.getElementById('f_DecDegrees');
  const note = document.getElementById('f_DecDegrees_note');
  const dec = dmsToDecimal(input.value);
  if (dec === null) {
    hid.value = '';
    input.style.borderColor = (input.value.trim() === '') ? '' : 'var(--danger)';
    note.textContent = 'Enter ±dd mm ss.s (−90 – +90°)';
  } else {
    hid.value = dec;
    input.style.borderColor = 'var(--accent2)';
    note.textContent = 'decimal: ' + dec.toFixed(6) + '°';
  }
}

function normalizeDecDisplay(input) {
  const dec = dmsToDecimal(input.value);
  if (dec !== null) {
    input.value = decDecimalToDMS(dec);
    input.style.borderColor = '';
  }
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
