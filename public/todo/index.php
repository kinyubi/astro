<?php
// ============================================================
// public/todo/index.php — simple standalone To Do List
// No authentication — personal tool, not sensitive data.
// ============================================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>To Do List</title>
<style>
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
  .wrap { max-width: 720px; margin: 24px auto; padding: 0 16px 60px; }

  .add-row {
    display: flex;
    gap: 8px;
    margin-bottom: 18px;
    flex-wrap: wrap;
  }
  .add-row input[type=text] {
    flex: 1 1 260px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    color: var(--text);
    padding: 9px 12px;
    font-size: 14px;
  }
  .add-row input[list] {
    width: 150px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    color: var(--text);
    padding: 9px 12px;
    font-size: 14px;
  }
  .add-row button {
    background: var(--accent2);
    border: none;
    border-radius: var(--radius);
    color: #04160a;
    font-weight: 600;
    padding: 9px 18px;
    cursor: pointer;
    font-size: 14px;
  }
  .add-row button:hover { filter: brightness(1.1); }

  .filters {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 20px;
  }
  .chip {
    border: 1px solid var(--border);
    border-radius: 999px;
    padding: 4px 12px;
    font-size: 12px;
    color: var(--muted);
    cursor: pointer;
    background: var(--surface);
    user-select: none;
  }
  .chip.active {
    border-color: var(--accent);
    color: var(--accent);
    background: rgba(88,166,255,0.1);
  }

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
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 8px 10px;
    border-radius: var(--radius);
    border: 1px solid var(--border);
    background: var(--surface);
    margin-bottom: 6px;
  }
  .item input[type=checkbox] {
    margin-top: 3px;
    width: 16px;
    height: 16px;
    accent-color: var(--accent2);
    cursor: pointer;
    flex-shrink: 0;
  }
  .item .text {
    flex: 1;
    font-size: 14px;
    line-height: 1.4;
    cursor: text;
    outline: none;
    word-break: break-word;
  }
  .item.done .text {
    color: var(--muted);
    text-decoration: line-through;
  }
  .item .del {
    background: none;
    border: none;
    color: var(--muted);
    cursor: pointer;
    font-size: 16px;
    line-height: 1;
    padding: 2px 4px;
    flex-shrink: 0;
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
    <input type="text" id="new-text" placeholder="Add an item&#8230;" autocomplete="off">
    <input type="text" id="new-category" list="category-options" placeholder="Category" autocomplete="off" value="General">
    <datalist id="category-options"></datalist>
    <button onclick="addItem()">+ Add</button>
  </div>

  <div class="filters" id="filters"></div>

  <label class="toggle-completed">
    <input type="checkbox" id="show-completed" checked onchange="render()">
    Show completed
  </label>

  <div id="list"></div>

</div>

<script>
let todos = [];
let categories = [];
let activeFilter = 'All';

async function loadTodos() {
  const res = await fetch('api.php?action=list');
  const data = await res.json();
  todos = data.todos || [];
  categories = data.categories || [];
  renderFilters();
  renderCategoryOptions();
  render();
}

function renderCategoryOptions() {
  const dl = document.getElementById('category-options');
  dl.innerHTML = categories.map(c => `<option value="${escHtml(c)}">`).join('');
}

function renderFilters() {
  const el = document.getElementById('filters');
  const all = ['All', ...categories];
  el.innerHTML = all.map(c => `
    <div class="chip ${c === activeFilter ? 'active' : ''}" onclick="setFilter('${escAttr(c)}')">${escHtml(c)}</div>
  `).join('');
}

function setFilter(cat) {
  activeFilter = cat;
  renderFilters();
  render();
}

function render() {
  const showCompleted = document.getElementById('show-completed').checked;
  const list = document.getElementById('list');

  let visible = todos.filter(t => activeFilter === 'All' || t.Category === activeFilter);
  if (!showCompleted) visible = visible.filter(t => !t.IsDone);

  if (visible.length === 0) {
    list.innerHTML = '<div class="empty">Nothing here.</div>';
    return;
  }

  // Group by category, incomplete items first within each group
  const groups = {};
  visible.forEach(t => {
    (groups[t.Category] ||= []).push(t);
  });
  Object.values(groups).forEach(g => g.sort((a, b) => (a.IsDone - b.IsDone) || (a.SortOrder - b.SortOrder)));

  const catNames = Object.keys(groups).sort((a, b) => a.localeCompare(b));

  list.innerHTML = catNames.map(cat => `
    <div class="group">
      ${activeFilter === 'All' ? `<div class="group-title">${escHtml(cat)}</div>` : ''}
      ${groups[cat].map(renderItem).join('')}
    </div>
  `).join('');
}

function renderItem(t) {
  return `
    <div class="item ${t.IsDone ? 'done' : ''}" data-id="${t.TodoID}">
      <input type="checkbox" ${t.IsDone ? 'checked' : ''} onchange="toggleItem(${t.TodoID})">
      <div class="text" contenteditable="true" onblur="saveText(${t.TodoID}, this.innerText)">${escHtml(t.ItemText)}</div>
      <button class="del" onclick="deleteItem(${t.TodoID})" title="Delete">&times;</button>
    </div>
  `;
}

async function addItem() {
  const textEl = document.getElementById('new-text');
  const catEl = document.getElementById('new-category');
  const text = textEl.value.trim();
  const category = catEl.value.trim() || 'General';
  if (!text) return;

  await fetch('api.php?action=add', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ item_text: text, category })
  });
  textEl.value = '';
  await loadTodos();
  textEl.focus();
}

async function toggleItem(id) {
  await fetch('api.php?action=toggle', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id })
  });
  await loadTodos();
}

async function saveText(id, newText) {
  newText = newText.trim();
  const t = todos.find(x => x.TodoID === id);
  if (!t || newText === t.ItemText || !newText) { render(); return; }
  await fetch('api.php?action=update', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id, item_text: newText })
  });
  await loadTodos();
}

async function deleteItem(id) {
  await fetch('api.php?action=delete', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id })
  });
  await loadTodos();
}

function escHtml(s) {
  const d = document.createElement('div');
  d.innerText = s == null ? '' : String(s);
  return d.innerHTML;
}
function escAttr(s) {
  return String(s).replace(/'/g, "&#39;");
}

document.getElementById('new-text').addEventListener('keydown', e => {
  if (e.key === 'Enter') addItem();
});

loadTodos();
</script>

</body>
</html>
