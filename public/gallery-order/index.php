<?php
// Gallery Order -- drag the public gallery's cards into the order you want.
// One card per Project (what the public gallery shows), saved to
// Projects.GalleryRank through admin/api_gallery_rank.php.
require_once __DIR__ . '/../admin/auth.php';
header('Cache-Control: no-store, no-cache, must-revalidate');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Gallery Order</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/png" href="/admin/favicon.png">
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
  body { background: var(--bg); color: var(--text); font-family: var(--font); font-size: 14px; line-height: 1.5; min-height: 100vh; }

  header {
    background: var(--surface); border-bottom: 1px solid var(--border);
    padding: 12px 24px; display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
    position: sticky; top: 0; z-index: 10;
  }
  header h1 { font-size: 18px; font-weight: 600; color: var(--accent); }
  header .subtitle { color: var(--muted); font-size: 13px; }
  .hdr-right { margin-left: auto; display: flex; gap: 10px; align-items: center; }
  .status { font-size: 12px; color: var(--muted); white-space: nowrap; }
  .status.saving { color: var(--accent); }
  .status.saved  { color: var(--accent2); }
  .status.error  { color: var(--danger); }
  .btn, a.btn {
    border: 1px solid var(--border); border-radius: var(--radius); background: var(--surface);
    color: var(--muted); font-size: 12px; padding: 5px 12px; cursor: pointer; text-decoration: none;
    font-family: var(--font); white-space: nowrap;
  }
  .btn:hover { border-color: var(--accent); color: var(--accent); }

  .help { color: var(--muted); font-size: 12px; padding: 12px 24px 0; }
  .note { color: var(--warn); font-size: 12px; padding: 6px 24px 0; }
  .error-box { margin: 16px 24px; padding: 12px 16px; border: 1px solid var(--danger); border-radius: var(--radius); color: var(--danger); background: rgba(248,81,73,0.08); }

  .grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
    gap: 16px; padding: 16px 24px 60px;
  }
  .card {
    background: var(--surface); border: 1px solid var(--border); border-radius: 8px;
    overflow: hidden; cursor: grab; user-select: none; position: relative;
    transition: border-color 0.12s, opacity 0.12s, transform 0.12s;
  }
  .card:hover { border-color: var(--accent); }
  .card.dragging { opacity: 0.35; }
  .card.drop-before { box-shadow: -4px 0 0 0 var(--accent); }
  .card.drop-after  { box-shadow:  4px 0 0 0 var(--accent); }
  .card.just-moved { border-color: var(--accent2); }
  .thumb { aspect-ratio: 4 / 5; background: #05070a; display: flex; align-items: center; justify-content: center; }
  .thumb img { width: 100%; height: 100%; object-fit: cover; display: block; pointer-events: none; }
  .cap { padding: 8px 10px 9px; display: flex; gap: 8px; align-items: flex-start; }
  .cap-text { flex: 1; min-width: 0; }
  .cap-name { font-size: 13px; font-weight: 600; color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .cap-key  { font-size: 11px; color: var(--muted); }
  .pos {
    width: 46px; flex-shrink: 0; background: var(--bg); border: 1px solid var(--border); border-radius: 4px;
    color: var(--text); font-size: 13px; font-weight: 600; text-align: center; padding: 3px 2px;
    font-family: var(--font);
  }
  .pos:focus { border-color: var(--accent); outline: none; }
  .badge {
    position: absolute; top: 6px; padding: 1px 8px; border-radius: 10px; font-size: 11px; font-weight: 600;
    pointer-events: none;
  }
  .badge.mosaic { left: 6px; background: rgba(240,160,48,0.88); color: #1a1200; }
  .badge.count  { right: 6px; background: rgba(0,0,0,0.65); color: #7ec8ff; border: 1px solid rgba(74,158,255,0.4); }
  .badge.unranked { left: 6px; bottom: 6px; top: auto; background: rgba(0,0,0,0.65); color: var(--muted); font-weight: 400; }
</style>
</head>
<body>

<header>
  <div>
    <h1>&#8645; Gallery Order</h1>
    <div class="subtitle">Order of the cards in Browse Gallery</div>
  </div>
  <div class="hdr-right">
    <span id="status" class="status"></span>
    <button class="btn" onclick="sortAlphabetical()" title="Put every card back in alphabetical order (and save)">Sort A&ndash;Z</button>
    <a class="btn" href="/" target="_blank" title="Open the public gallery in a new tab">View gallery</a>
    <a class="btn" href="/admin/">&larr; Admin</a>
  </div>
</header>

<div class="help">
  Drag a card to a new spot, or type a position number in its box and press Enter. Changes save as soon as you drop.
  Each Project is its own card, so a DSO with a separate mosaic Project appears twice.
</div>
<div id="note" class="note" style="display:none;"></div>
<div id="error" class="error-box" style="display:none;"></div>
<div id="grid" class="grid"></div>

<script>
const API = '/admin/api_gallery_rank.php';
let cards = [];          // current order, as returned/rearranged
let dragEl = null;
let saveSeq = 0;

function escHtml(s) {
  return String(s ?? '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function setStatus(state, text) {
  const el = document.getElementById('status');
  el.className = 'status' + (state ? ' ' + state : '');
  el.textContent = text || '';
}

async function load() {
  try {
    const res  = await fetch(API, { credentials: 'same-origin' });
    if (res.status === 401) { location.href = '/admin/?expired=1'; return; }
    const data = await res.json();
    if (data.error) throw new Error(data.error);
    cards = data.cards || [];
    const notes = [];
    if (data.attached && data.attached.length) {
      notes.push('Attached ' + data.attached.length + ' image' + (data.attached.length === 1 ? '' : 's') +
        ' that had no Project to the Project whose folder holds ' + (data.attached.length === 1 ? 'it' : 'them') + ': ' +
        data.attached.map(a => a.BaseName + ' → ' + a.ProjectFolder).join(', ') + '.');
    }
    if (data.unassigned) {
      notes.push(data.unassigned + ' visible gallery image' + (data.unassigned === 1 ? ' has' : 's have') +
        ' no Project and no matching folder in myWorks, so ' + (data.unassigned === 1 ? 'it' : 'they') +
        ' can’t be ranked: ' +
        (data.unassignedImages || []).map(u => u.DSOKey + ' ' + u.BaseName).join(', ') + '.');
    }
    if (notes.length) {
      const n = document.getElementById('note');
      n.textContent = notes.join(' ');
      n.style.display = '';
    }
    const unranked = cards.filter(c => c.GalleryRank === null).length;
    setStatus('', cards.length + ' cards' + (unranked ? ' · ' + unranked + ' not ranked yet' : ''));
    render();
  } catch (e) {
    const box = document.getElementById('error');
    box.textContent = 'Could not load: ' + e.message;
    box.style.display = '';
  }
}

function render() {
  const grid = document.getElementById('grid');
  grid.innerHTML = '';
  cards.forEach((c, i) => grid.appendChild(cardEl(c, i)));
}

function cardEl(c, i) {
  const el = document.createElement('div');
  el.className = 'card';
  el.draggable = true;
  el.dataset.pid = c.ProjectID;
  const name = c.CommonName || c.DSOKey;
  el.innerHTML = `
    <div class="thumb"><img src="/images/fav/${encodeURIComponent(c.BaseName)}_fav.jpg" alt="" loading="lazy"
         onerror="this.src='/images/thumbs/${encodeURIComponent(c.BaseName)}_thumb.jpg'; this.onerror=null;"></div>
    ${c.IsMosaic ? '<span class="badge mosaic">Mosaic</span>' : ''}
    ${c.ImageCount > 1 ? `<span class="badge count">${c.ImageCount} images</span>` : ''}
    <div class="cap">
      <div class="cap-text">
        <div class="cap-name" title="${escHtml(c.DisplayName)}">${escHtml(name)}</div>
        <div class="cap-key">${escHtml(c.DSOKey)}</div>
      </div>
      <input class="pos" type="number" min="1" max="${cards.length}" value="${i + 1}"
             title="Type a position and press Enter to move this card there" draggable="false">
    </div>`;

  el.addEventListener('dragstart', e => {
    dragEl = el;
    el.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', String(c.ProjectID));
  });
  el.addEventListener('dragend', () => {
    el.classList.remove('dragging');
    clearDropMarks();
    dragEl = null;
  });
  el.addEventListener('dragover', e => {
    if (!dragEl || dragEl === el) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    const after = isAfter(el, e);
    clearDropMarks();
    el.classList.add(after ? 'drop-after' : 'drop-before');
  });
  el.addEventListener('drop', e => {
    if (!dragEl || dragEl === el) return;
    e.preventDefault();
    const from = indexOfPid(dragEl.dataset.pid);
    let to = indexOfPid(el.dataset.pid) + (isAfter(el, e) ? 1 : 0);
    if (from < to) to--;
    clearDropMarks();
    moveCard(from, to);
  });

  const pos = el.querySelector('.pos');
  pos.addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); pos.blur(); }
    else if (e.key === 'Escape') { pos.value = indexOfPid(c.ProjectID) + 1; pos.blur(); }
  });
  pos.addEventListener('blur', () => {
    const from = indexOfPid(c.ProjectID);
    let to = parseInt(pos.value, 10);
    if (Number.isNaN(to)) { pos.value = from + 1; return; }
    to = Math.min(Math.max(to, 1), cards.length) - 1;
    if (to === from) { pos.value = from + 1; return; }
    moveCard(from, to);
  });
  pos.addEventListener('mousedown', e => e.stopPropagation());
  return el;
}

// Drop on the right half of a card = after it (grid flows left-to-right).
function isAfter(el, e) {
  const r = el.getBoundingClientRect();
  return e.clientX > r.left + r.width / 2;
}

function clearDropMarks() {
  document.querySelectorAll('.drop-before, .drop-after').forEach(x => x.classList.remove('drop-before', 'drop-after'));
}

function indexOfPid(pid) {
  return cards.findIndex(c => String(c.ProjectID) === String(pid));
}

function moveCard(from, to) {
  if (from < 0 || to < 0 || from === to) return;
  const [c] = cards.splice(from, 1);
  cards.splice(to, 0, c);
  render();
  const el = document.querySelector('.card[data-pid="' + c.ProjectID + '"]');
  if (el) {
    el.classList.add('just-moved');
    el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    setTimeout(() => el.classList.remove('just-moved'), 1200);
  }
  save();
}

function sortAlphabetical() {
  if (!confirm('Put all ' + cards.length + ' cards back in alphabetical order?')) return;
  cards.sort((a, b) => (a.DisplayName < b.DisplayName ? -1 : a.DisplayName > b.DisplayName ? 1 : a.ProjectID - b.ProjectID));
  render();
  save();
}

// Saves the whole order (ranks 1..n). Only the latest save's result is shown.
async function save() {
  const seq = ++saveSeq;
  setStatus('saving', 'Saving…');
  try {
    const res  = await fetch(API, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ order: cards.map(c => c.ProjectID) }),
    });
    if (res.status === 401) { location.href = '/admin/?expired=1'; return; }
    const data = await res.json();
    if (!data.success) throw new Error(data.error || 'unknown error');
    cards.forEach((c, i) => { c.GalleryRank = i + 1; });
    if (seq === saveSeq) setStatus('saved', '✓ Order saved');
  } catch (e) {
    if (seq === saveSeq) setStatus('error', '⚠ Not saved — ' + e.message);
  }
}

load();
</script>
</body>
</html>
