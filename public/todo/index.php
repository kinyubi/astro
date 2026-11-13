<?php
// ============================================================
// public/todo/index.php — simple standalone To Do List
// No authentication — personal tool, not sensitive data.
// ============================================================

// Equipment choices come from the Equipment table (S30, S50, S50P, 8SE,
// IP, UNK...): the Seestars first, then the rest alphabetically.
require_once __DIR__ . '/../../shared/db.php';
$equipment_ids = [];
try {
    $equipment_ids = get_db()->query('SELECT EquipmentID AS "id" FROM Equipment')->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    error_log('todo/index.php equipment: ' . $e->getMessage());
}
if (!$equipment_ids) $equipment_ids = ['S30', 'S50', 'S50P'];
usort($equipment_ids, function ($a, $b) {
    $order = ['S30' => 0, 'S50' => 1, 'S50P' => 2];
    return [$order[strtoupper($a)] ?? 9, strtoupper($a)] <=> [$order[strtoupper($b)] ?? 9, strtoupper($b)];
});
$equipment_options = [['value' => '', 'label' => '—']];
foreach ($equipment_ids as $id) {
    $equipment_options[] = ['value' => $id, 'label' => strtoupper($id) === 'S50P' ? 'S50 Pro' : $id];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>To Do List</title>
<link rel="icon" type="image/png" href="/images/favicon.png">
<style>
  :root {
    --bg:       #0d1117;
    --surface:  #161b22;
    --border:   #30363d;
    --accent:   #58a6ff;
    --accent2:  #3fb950;
    --warn:     #d29922;
    --danger:   #f85149;
    --text:     #f1fbff;
    --muted:    #a7b2be;
    --field-text: #c8d6e4;
    --placeholder: #6e7681;
    --radius:   6px;
    --font:     'Segoe UI', system-ui, sans-serif;
    --equip-s30:  #bc8cff;
    --equip-s50:  #58a6ff;
    --equip-s50p: #f778ba;
    --equip-s30-bg:  rgba(188,140,255,0.16);
    --equip-s50-bg:  rgba(88,166,255,0.16);
    --equip-s50p-bg: rgba(247,120,186,0.16);
  }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    font-family: var(--font);
    background: var(--bg);
    color: var(--text);
  }
  header {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 20px;
    border-bottom: 1px solid var(--border);
    background: var(--surface);
  }
  header h1 { font-size: 18px; margin: 0; }
  header .subtitle { color: var(--muted); font-size: 12px; }
  header a {
    margin-left: auto;
    font-size: 12px;
    color: var(--muted);
    text-decoration: none;
  }
  header a:hover { color: var(--accent); }
  .wrap { max-width: 1100px; margin: 24px auto; padding: 0 16px 60px; }

  /* Same column widths as .item below, so the add-row lines up with the
     list -- column 1 (checkbox) is left empty here, and the Add button
     takes the age+delete columns at the end. */
  .add-row {
    display: grid;
    grid-template-columns: 18px 50px 110px minmax(140px, 1fr) 90px 110px 160px 110px 42px 24px;
    column-gap: 10px;
    margin-bottom: 18px;
    align-items: center;
  }
  .add-row input[type=text] {
    width: 100%;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    color: var(--text);
    padding: 9px 12px;
    font-size: 14px;
  }
  .add-row select {
    width: 100%;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    color: var(--text);
    padding: 9px 8px;
    font-size: 14px;
  }
  #new-priority { grid-column: 2; }
  #new-dsokey { grid-column: 3; }
  #new-commonname { grid-column: 4; }
  #new-equipment { grid-column: 5; }
  #new-sessiondate { grid-column: 6; }
  #new-notes { grid-column: 7; }
  #new-status { grid-column: 8; }

  .add-row button {
    grid-column: 9 / 11;
    background: var(--accent2);
    border: none;
    border-radius: var(--radius);
    color: #04160a;
    font-weight: 600;
    padding: 9px 18px;
    cursor: pointer;
    font-size: 14px;
    white-space: nowrap;
  }
  .add-row button:hover { filter: brightness(1.1); }

  .toggle-completed {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: var(--muted);
    margin-bottom: 14px;
    cursor: pointer;
    user-select: none;
  }

  .group { margin-bottom: 22px; }
  .group-title {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--muted);
    margin-bottom: 8px;
    font-weight: 600;
  }

  .item {
    display: grid;
    grid-template-columns: 18px 50px 110px minmax(140px, 1fr) 90px 110px 160px 110px 42px 24px;
    align-items: start;
    column-gap: 10px;
    padding: 8px 10px;
    border-radius: var(--radius);
    border: 1px solid var(--border);
    background: var(--surface);
    margin-bottom: 6px;
  }
  .item.equip-S30  { background: var(--equip-s30-bg); }
  .item.equip-S50  { background: var(--equip-s50-bg); }
  .item.equip-S50P { background: var(--equip-s50p-bg); }
  .item input[type=checkbox] {
    margin-top: 3px;
    width: 16px;
    height: 16px;
    accent-color: var(--accent2);
    cursor: pointer;
    flex-shrink: 0;
  }
  .item .prio {
    flex-shrink: 0;
    font-size: 11px;
    font-weight: 600;
    border-radius: 4px;
    border: 1px solid var(--border);
    padding: 3px 4px;
    background: var(--bg);
    cursor: pointer;
    margin-top: 1px;
  }
  .item .prio.prio-High    { color: var(--danger); border-color: var(--danger); }
  .item .prio.prio-Medium  { color: var(--warn);   border-color: var(--warn); }
  .item .prio.prio-Low     { color: var(--muted);  border-color: var(--border); }
  .item.done .prio { opacity: 0.5; }

  .item .dsokey {
    flex: 0 0 110px;
    font-size: 13px;
    line-height: 1.4;
    cursor: text;
    outline: none;
    padding: 3px 4px;
    border-radius: 4px;
    color: var(--field-text);
  }
  .item .dsokey:focus { background: var(--bg); color: var(--text); }

  .item .commonname {
    flex: 1 1 200px;
    font-size: 14px;
    line-height: 1.4;
    cursor: text;
    outline: none;
    word-break: break-word;
    padding: 3px 4px;
    border-radius: 4px;
  }
  .item .commonname:focus { background: var(--bg); }
  .item.done .commonname { color: var(--muted); text-decoration: line-through; }

  .item .equipment {
    flex-shrink: 0;
    width: 90px;
    font-size: 11px;
    color: var(--muted);
    border-radius: 4px;
    border: 1px solid var(--border);
    padding: 3px 5px;
    background: var(--bg);
    margin-top: 1px;
  }
  .item.done .equipment { opacity: 0.5; }

  .legend {
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
    font-size: 11px;
    color: var(--muted);
    margin-bottom: 14px;
  }
  .legend span { display: flex; align-items: center; gap: 6px; }
  .legend .swatch {
    display: inline-block;
    width: 10px;
    height: 10px;
    border-radius: 2px;
  }

  .item .sessiondate {
    flex: 0 0 110px;
    font-size: 12px;
    color: var(--field-text);
    cursor: text;
    outline: none;
    padding: 3px 4px;
    border-radius: 4px;
  }
  .item .sessiondate:focus { background: var(--bg); color: var(--text); }
  .item .sessiondate:empty::before { content: attr(data-placeholder); color: var(--placeholder); }

  .item .notes {
    flex: 0 0 160px;
    font-size: 12px;
    color: var(--field-text);
    cursor: text;
    outline: none;
    padding: 3px 4px;
    border-radius: 4px;
    word-break: break-word;
  }
  .item .notes:focus { background: var(--bg); color: var(--text); }
  .item .notes:empty::before { content: attr(data-placeholder); color: var(--placeholder); }

  .item .status {
    flex-shrink: 0;
    width: 110px;
    font-size: 11px;
    color: var(--muted);
    border-radius: 4px;
    border: 1px solid var(--border);
    padding: 3px 5px;
    background: var(--bg);
    margin-top: 1px;
  }
  .item.done .status { opacity: 0.5; }

  .item .age {
    flex-shrink: 0;
    font-size: 11px;
    color: var(--muted);
    white-space: nowrap;
    margin-top: 3px;
  }
  .item .age.stale { color: var(--warn); }
  .item.done .age { opacity: 0.5; }

  .item .del {
    background: none;
    border: none;
    color: var(--muted);
    cursor: pointer;
    font-size: 16px;
    line-height: 1;
    padding: 2px 4px;
    flex-shrink: 0;
    margin-left: auto;
  }
  .item .del:hover { color: var(--danger); }

  .empty {
    color: var(--muted);
    font-size: 13px;
    padding: 20px 0;
    text-align: center;
  }
</style>
</head>
<body>

<header>
  <h1>&#128203; To Do List</h1>
  <span class="subtitle">Website &amp; focus items</span>
  <a href="/admin/">&#8592; Admin</a>
</header>

<div class="wrap">

  <div class="add-row">
    <select id="new-priority" title="Priority">
      <option value="High">High</option>
      <option value="Medium" selected>Medium</option>
      <option value="Low">Low</option>
    </select>
    <input type="text" id="new-dsokey" placeholder="DSO Key" autocomplete="off">
    <input type="text" id="new-commonname" placeholder="Common name&#8230;" autocomplete="off">
    <select id="new-equipment" title="Equipment"></select>
    <input type="text" id="new-sessiondate" placeholder="Session date" autocomplete="off">
    <input type="text" id="new-notes" placeholder="Notes" autocomplete="off" maxlength="160">
    <select id="new-status" title="Status"></select>
    <button onclick="addItem()">+ Add</button>
  </div>

  <label class="toggle-completed">
    <input type="checkbox" id="show-completed" checked onchange="render()">
    Show completed
  </label>

  <div class="legend">
    <span><span class="swatch" style="background:var(--equip-s30-bg);border:1px solid var(--equip-s30)"></span>S30</span>
    <span><span class="swatch" style="background:var(--equip-s50-bg);border:1px solid var(--equip-s50)"></span>S50</span>
    <span><span class="swatch" style="background:var(--equip-s50p-bg);border:1px solid var(--equip-s50p)"></span>S50 Pro</span>
  </div>

  <div id="list"></div>

</div>

<script>
let todos = [];

// Fixed pipeline stages for the Status dropdowns (add-row and per-item).
// Order doubles as the intended workflow sequence.
const STATUS_OPTIONS = ['Photograph', 'Download', 'Preprocess', 'Process', 'Render', 'Post', 'Rework'];

// Notes is capped at 160 characters (matches the Notes VARCHAR(160) column).
const NOTES_MAX_LEN = 160;

// Equipment codes are the Equipment table's EquipmentID values (read by
// the PHP at the top) -- S50P displays as "S50 Pro".
const EQUIPMENT_OPTIONS = <?= json_encode($equipment_options) ?>;

async function loadTodos() {
  const res = await fetch('api.php?action=list');
  const data = await res.json();
  todos = data.todos || [];
  render();
}

// <option> list for a Status <select>: the six standard stages, plus
// -- appended, not substituted -- the item's current value if it isn't
// one of them, so a stray/legacy status (anything not yet migrated to
// the standard set) still displays and can be picked back to intentionally
// rather than silently vanishing or forcing a value change.
function statusOptionsHtml(current) {
  const opts = STATUS_OPTIONS.includes(current) ? STATUS_OPTIONS : [...STATUS_OPTIONS, current];
  return opts.map(c => `<option value="${escAttr(c)}" ${c === current ? 'selected' : ''}>${escHtml(c)}</option>`).join('');
}

function equipmentOptionsHtml(current) {
  current = current || '';
  const known = EQUIPMENT_OPTIONS.some(o => o.value === current);
  const opts = known ? EQUIPMENT_OPTIONS : [...EQUIPMENT_OPTIONS, { value: current, label: current }];
  return opts.map(o => `<option value="${escAttr(o.value)}" ${o.value === current ? 'selected' : ''}>${escHtml(o.label)}</option>`).join('');
}

// Saving any field reloads the todos and calls render(), which rebuilds
// the whole list via innerHTML -- that destroys whatever element currently
// has focus (e.g. right after picking a new Equipment value, mid-Tab),
// kicking focus back to the top of the page. Capture which field was
// focused before the rebuild so it can be restored after, keeping Tab
// flow intact.
function captureFocus(list) {
  const active = document.activeElement;
  if (!active || !list.contains(active)) return null;
  const itemEl = active.closest('.item');
  if (!itemEl) return null;
  let selector = null;
  if (active.matches('input[type=checkbox]')) {
    selector = 'input[type=checkbox]';
  } else {
    const cls = ['prio', 'dsokey', 'commonname', 'equipment', 'sessiondate', 'notes', 'status', 'del']
      .find(c => active.classList.contains(c));
    selector = cls ? '.' + cls : null;
  }
  if (!selector) return null;
  return { id: itemEl.dataset.id, selector };
}

function restoreFocus(list, focus) {
  if (!focus) return;
  const itemEl = list.querySelector(`.item[data-id="${focus.id}"]`);
  const field = itemEl && itemEl.querySelector(focus.selector);
  if (field) field.focus();
}

function render() {
  const showCompleted = document.getElementById('show-completed').checked;
  const list = document.getElementById('list');
  const focus = captureFocus(list);

  document.getElementById('new-equipment').innerHTML = equipmentOptionsHtml('');
  document.getElementById('new-status').innerHTML = statusOptionsHtml('Photograph');

  let visible = todos;
  if (!showCompleted) visible = visible.filter(t => !t.IsDone);

  if (visible.length === 0) {
    list.innerHTML = '<div class="empty">Nothing here.</div>';
    return;
  }

  // Group by status, incomplete items first within each group, then by priority
  const prioRank = { High: 0, Medium: 1, Low: 2 };
  const groups = {};
  visible.forEach(t => {
    (groups[t.Status] ||= []).push(t);
  });
  Object.values(groups).forEach(g => g.sort((a, b) =>
    (a.IsDone - b.IsDone) ||
    (prioRank[a.Priority] ?? 1) - (prioRank[b.Priority] ?? 1) ||
    (a.SortOrder - b.SortOrder)
  ));

  // Groups appear in the same order as the Status dropdown (STATUS_OPTIONS),
  // with any legacy/unrecognized status sorted alphabetically after those.
  const groupNames = Object.keys(groups).sort((a, b) => {
    const ai = STATUS_OPTIONS.indexOf(a);
    const bi = STATUS_OPTIONS.indexOf(b);
    if (ai === -1 && bi === -1) return a.localeCompare(b);
    if (ai === -1) return 1;
    if (bi === -1) return -1;
    return ai - bi;
  });

  list.innerHTML = groupNames.map(name => `
    <div class="group">
      <div class="group-title">${escHtml(name)}</div>
      ${groups[name].map(renderItem).join('')}
    </div>
  `).join('');

  restoreFocus(list, focus);
}

function renderItem(t) {
  const prio = t.Priority || 'Medium';
  const equipClass = t.Equipment || 'none';
  return `
    <div class="item ${t.IsDone ? 'done' : ''} equip-${equipClass}" data-id="${t.TodoID}">
      <input type="checkbox" ${t.IsDone ? 'checked' : ''} onchange="toggleItem(${t.TodoID})" title="Mark completed">
      <select class="prio prio-${prio}" onchange="savePriority(${t.TodoID}, this.value)" title="Priority">
        <option value="High" ${prio === 'High' ? 'selected' : ''}>High</option>
        <option value="Medium" ${prio === 'Medium' ? 'selected' : ''}>Med</option>
        <option value="Low" ${prio === 'Low' ? 'selected' : ''}>Low</option>
      </select>
      <div class="dsokey" contenteditable="true" title="DSO Key" onfocus="placeCaretAtStart(this)" onkeydown="handleEditableTab(event)" onblur="saveDsoKey(${t.TodoID}, this.innerText)">${escHtml(t.DSOKey)}</div>
      <div class="commonname" contenteditable="true" title="Common name" onkeydown="handleEditableTab(event)" onblur="saveCommonName(${t.TodoID}, this.innerText)">${escHtml(t.CommonName)}</div>
      <select class="equipment" onchange="saveEquipment(${t.TodoID}, this.value)" title="Equipment">${equipmentOptionsHtml(t.Equipment)}</select>
      <div class="sessiondate" contenteditable="true" title="Session date" data-placeholder="Session date" onfocus="placeCaretAtStart(this)" onkeydown="handleEditableTab(event)" onblur="saveSessionDate(${t.TodoID}, this.innerText)">${escHtml(t.SessionDate)}</div>
      <div class="notes" contenteditable="true" title="Notes (160 char max)" data-placeholder="Notes" onfocus="placeCaretAtStart(this)" onkeydown="handleEditableTab(event)" onblur="saveNotes(${t.TodoID}, this.innerText)">${escHtml(t.Notes)}</div>
      <select class="status" onchange="saveStatus(${t.TodoID}, this.value)" title="Status">${statusOptionsHtml(t.Status)}</select>
      <span class="age ${isStale(t.CreatedDate) ? 'stale' : ''}" title="Added ${escAttr(t.CreatedDate || '')}">${formatAge(t.CreatedDate)}</span>
      <button class="del" onclick="deleteItem(${t.TodoID})" title="Delete">&times;</button>
    </div>
  `;
}

async function addItem() {
  const dsokeyEl = document.getElementById('new-dsokey');
  const commonnameEl = document.getElementById('new-commonname');
  const equipmentEl = document.getElementById('new-equipment');
  const sessiondateEl = document.getElementById('new-sessiondate');
  const notesEl = document.getElementById('new-notes');
  const statusEl = document.getElementById('new-status');
  const priorityEl = document.getElementById('new-priority');

  const commonname = commonnameEl.value.trim();
  if (!commonname) return;

  await fetch('api.php?action=add', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      dsokey: dsokeyEl.value.trim(),
      commonname,
      equipment: equipmentEl.value,
      sessiondate: sessiondateEl.value.trim(),
      notes: notesEl.value.trim().slice(0, NOTES_MAX_LEN),
      status: statusEl.value,
      priority: priorityEl.value,
    })
  });
  dsokeyEl.value = '';
  commonnameEl.value = '';
  sessiondateEl.value = '';
  notesEl.value = '';
  await loadTodos();
  dsokeyEl.focus();
}

async function toggleItem(id) {
  await fetch('api.php?action=toggle', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id })
  });
  await loadTodos();
}

async function savePriority(id, priority) {
  await fetch('api.php?action=update', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id, priority })
  });
  await loadTodos();
}

async function saveStatus(id, newStatus) {
  const t = todos.find(x => x.TodoID === id);
  if (!t || newStatus === t.Status) { return; }
  await fetch('api.php?action=update', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id, status: newStatus })
  });
  await loadTodos();
}

async function saveEquipment(id, newEquipment) {
  const t = todos.find(x => x.TodoID === id);
  if (!t || newEquipment === (t.Equipment || '')) { return; }
  await fetch('api.php?action=update', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id, equipment: newEquipment })
  });
  await loadTodos();
}

async function saveCommonName(id, newText) {
  newText = newText.trim();
  const t = todos.find(x => x.TodoID === id);
  if (!t || newText === (t.CommonName || '') || !newText) { return; }
  await fetch('api.php?action=update', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id, commonname: newText })
  });
  await loadTodos();
}

async function saveSessionDate(id, newText) {
  newText = newText.trim();
  const t = todos.find(x => x.TodoID === id);
  if (!t || newText === (t.SessionDate || '')) { return; }
  await fetch('api.php?action=update', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id, sessiondate: newText })
  });
  await loadTodos();
}

async function saveNotes(id, newText) {
  newText = newText.trim().slice(0, NOTES_MAX_LEN);
  const t = todos.find(x => x.TodoID === id);
  if (!t || newText === (t.Notes || '')) { return; }
  await fetch('api.php?action=update', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id, notes: newText })
  });
  await loadTodos();
}

// DSO Key -> Common Name autofill: only fills Common Name when it's
// currently blank, and never overwrites a name that's already there.
async function saveDsoKey(id, newKey) {
  newKey = newKey.trim();
  const t = todos.find(x => x.TodoID === id);
  if (!t) return;
  if (newKey === (t.DSOKey || '')) { return; }

  const payload = { id, dsokey: newKey };

  if (newKey && !(t.CommonName || '').trim()) {
    const name = await lookupDsoName(newKey);
    if (name) payload.commonname = name;
  }

  await fetch('api.php?action=update', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  });
  await loadTodos();
}

async function lookupDsoName(key) {
  try {
    const res = await fetch('api.php?action=lookup_dso&key=' + encodeURIComponent(key));
    const data = await res.json();
    return data.commonname || null;
  } catch (e) {
    return null;
  }
}

async function deleteItem(id) {
  await fetch('api.php?action=delete', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id })
  });
  await loadTodos();
}

function daysSince(dateStr) {
  if (!dateStr) return null;
  // Postgres timestamps come back as 'YYYY-MM-DD HH:MM:SS' (no timezone)
  // -- swap the space for 'T' so browsers parse it as local time instead
  // of guessing/UTC-defaulting on the space-separated form.
  const d = new Date(dateStr.replace(' ', 'T'));
  if (isNaN(d)) return null;
  const ms = Date.now() - d.getTime();
  return Math.floor(ms / 86400000);
}

function isStale(dateStr) {
  const days = daysSince(dateStr);
  return days !== null && days >= 14;
}

function formatAge(dateStr) {
  const days = daysSince(dateStr);
  if (days === null) return '';
  if (days <= 0) return 'Today';
  if (days === 1) return '1d';
  if (days < 30) return `${days}d`;
  if (days < 365) return `${Math.floor(days / 30)}mo`;
  return `${Math.floor(days / 365)}y`;
}

function escHtml(s) {
  const d = document.createElement('div');
  d.innerText = s == null ? '' : String(s);
  return d.innerHTML;
}
function escAttr(s) {
  return String(s == null ? '' : s).replace(/'/g, "&#39;");
}

// Chrome/Firefox sometimes don't render a blinking caret when Tab (rather
// than a click) focuses an EMPTY contenteditable div -- there's no content
// for the browser to anchor the caret to. Forcing a collapsed selection at
// the start on focus fixes it without inserting any actual content, so the
// :empty-based placeholder styling (see CSS) still works correctly.
function placeCaretAtStart(el) {
  if (el.childNodes.length > 0) return; // has text already; browser handles it fine
  const range = document.createRange();
  range.selectNodeContents(el);
  range.collapse(true);
  const sel = window.getSelection();
  sel.removeAllRanges();
  sel.addRange(range);
}

// Chrome has a long-standing bug where Tab reliably fails to move focus
// from a contenteditable element directly into a <select> (exactly the
// Common Name -> Equipment and Notes -> Status hops here) -- focus falls
// back to <body> instead of the next field. Handle Tab ourselves on the
// contenteditable fields so navigation works regardless of that bug.
function handleEditableTab(e) {
  if (e.key !== 'Tab') return;
  e.preventDefault();
  const focusable = Array.from(document.querySelectorAll(
    'a[href], button, select, input, [contenteditable="true"]'
  )).filter(el => el.offsetParent !== null);
  const idx = focusable.indexOf(e.target);
  if (idx === -1) return;
  const next = focusable[e.shiftKey ? idx - 1 : idx + 1];
  if (next) next.focus();
}

// DSO Key autofill also fires as soon as the add-row DSO Key field loses
// focus, filling the add-row Common Name box if it's still empty.
document.getElementById('new-dsokey').addEventListener('blur', async function () {
  const key = this.value.trim();
  const commonnameEl = document.getElementById('new-commonname');
  if (key && !commonnameEl.value.trim()) {
    const name = await lookupDsoName(key);
    if (name) commonnameEl.value = name;
  }
});

document.getElementById('new-commonname').addEventListener('keydown', e => {
  if (e.key === 'Enter') addItem();
});

loadTodos();
</script>

</body>
</html>
