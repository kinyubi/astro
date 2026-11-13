<?php
/**
 * Caption Lab -- a scratch page for choosing the slideshow caption style and
 * signature. Not linked from anywhere; open /slideshow/caption-lab.php.
 * Safe to delete once a style is chosen.
 */
require_once __DIR__ . '/data.php';
$cards = ss_load_cards();
$json  = json_encode($cards, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Caption Lab</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Alex+Brush&family=Allura&family=Cormorant+Garamond:ital,wght@0,500;1,500&family=Dancing+Script:wght@500&family=Great+Vibes&family=Herr+Von+Muellerhoff&family=Homemade+Apple&family=Mrs+Saint+Delafield&family=Parisienne&family=Pinyon+Script&family=Sacramento&display=swap">
<style>
:root { --bg:#0a0d18; --panel:#121831; --line:#2a3f5f; --accent:#4a9eff; --text:#e0e6f0; --muted:#8a9aaa; }
* { box-sizing: border-box; }
body { margin:0; background:var(--bg); color:var(--text); font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; }
header { position:sticky; top:0; z-index:5; display:flex; flex-wrap:wrap; gap:12px 20px; align-items:center;
         padding:12px 20px; background:rgba(10,13,24,.95); border-bottom:1px solid var(--line); }
header h1 { margin:0; font-size:1.1em; }
header label { font-size:.85em; color:var(--muted); display:flex; align-items:center; gap:6px; }
select { background:var(--panel); color:var(--text); border:1px solid var(--line); border-radius:6px; padding:5px 8px; max-width:340px; }
main { padding:20px; max-width:1400px; margin:0 auto; }
h2 { font-size:1em; color:var(--accent); margin:28px 0 12px; }
.note { color:var(--muted); font-size:.85em; margin:0 0 14px; }
.grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(560px,1fr)); gap:18px; }
@media (max-width:620px){ .grid { grid-template-columns:1fr; } }
.cell h3 { margin:0 0 6px; font-size:.9em; font-weight:600; }
.cell h3 span { color:var(--muted); font-weight:400; }

/* Preview frame: a 16:9 window onto the image, like a landscape screen. */
.frame { position:relative; aspect-ratio:16/9; background:#000; overflow:hidden; border-radius:6px; container-type:inline-size; }
.frame img.pic { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }
.cap { position:absolute; left:0; right:0; bottom:0; display:flex; align-items:flex-end; justify-content:space-between;
       gap:1.2em; font-size:clamp(9px,1.25cqw,22px); color:rgba(255,255,255,.85); pointer-events:none; }
.cap .sig { flex-shrink:0; position:relative; line-height:1; white-space:nowrap; color:rgba(255,255,255,.9);
            text-shadow:0 1px 3px rgba(0,0,0,.8); }
.cap .sig svg { position:absolute; left:-4%; bottom:-.32em; width:108%; height:.55em; overflow:visible; }
.cap .sig svg path { fill:none; stroke:rgba(255,255,255,.75); stroke-width:1.4; stroke-linecap:round; }

/* A -- current */
.v-a { padding:2.4em 1.1em .7em; background:linear-gradient(to top,rgba(0,0,0,.55),rgba(0,0,0,.25) 55%,rgba(0,0,0,0)); text-shadow:0 1px 2px rgba(0,0,0,.85); }
.v-a .txt { letter-spacing:.03em; white-space:pre-wrap; }

/* B -- gallery labels (like the Browse Gallery caption bar) */
.v-b { padding:.55em 1.1em; background:rgba(15,20,35,.72); border-top:1px solid rgba(255,255,255,.06); align-items:center; }
.v-b .txt { display:flex; flex-wrap:wrap; gap:.3em 1.2em; align-items:baseline; }
.v-b .nm { color:#7ec8ff; font-weight:600; }
.v-b .key { color:#c9d1d9; }
.v-b .lbl { color:#6e7681; }
.v-b .val { color:#9aa0a6; }

/* C -- title over details */
.v-c { padding:3.4em 1.3em .9em; background:linear-gradient(to top,rgba(0,0,0,.6),rgba(0,0,0,0)); text-shadow:0 1px 3px rgba(0,0,0,.9); }
.v-c .nm { font-family:"Cormorant Garamond",Georgia,serif; font-style:italic; font-size:1.75em; line-height:1.1; color:#fff; }
.v-c .det { margin-top:.35em; font-size:.72em; letter-spacing:.18em; text-transform:uppercase; color:rgba(255,255,255,.7); }

/* D -- museum label */
.v-d { padding:2.6em 1.3em 1em; background:linear-gradient(to top,rgba(0,0,0,.5),rgba(0,0,0,0)); text-shadow:0 1px 2px rgba(0,0,0,.9); }
.v-d .lab { border-left:2px solid rgba(126,200,255,.8); padding-left:.8em; }
.v-d .nm { font-variant:small-caps; letter-spacing:.06em; font-size:1.15em; color:#fff; }
.v-d .key { color:#7ec8ff; font-size:.85em; margin-left:.5em; }
.v-d .det { margin-top:.2em; font-size:.8em; color:rgba(255,255,255,.65); }

/* E -- whisper (no band, just shadowed text) */
.v-e { padding:0 1.1em .8em; text-shadow:0 0 4px rgba(0,0,0,.95),0 1px 2px rgba(0,0,0,.95); }
.v-e .txt { font-size:.82em; letter-spacing:.06em; color:rgba(255,255,255,.62); white-space:pre-wrap; }
.v-e .sig { opacity:.75; }

/* F -- name over a hairline rule */
.v-f { padding:3em 1.3em .9em; background:linear-gradient(to top,rgba(0,0,0,.55),rgba(0,0,0,0)); text-shadow:0 1px 2px rgba(0,0,0,.9); }
.v-f .nm { font-family:"Cormorant Garamond",Georgia,serif; font-size:1.45em; color:#fff; letter-spacing:.02em; }
.v-f .rule { height:1px; width:min(18em,100%); margin:.35em 0 .4em; background:linear-gradient(to right,rgba(255,255,255,.7),rgba(255,255,255,0)); }
.v-f .det { font-size:.78em; letter-spacing:.08em; color:rgba(255,255,255,.7); }

/* Signature swatches */
.sigs { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:14px; }
.swatch { background:#000 center/cover; border:2px solid var(--line); border-radius:8px; height:120px; position:relative;
          display:flex; align-items:center; justify-content:center; cursor:pointer; overflow:hidden; }
.swatch::before { content:""; position:absolute; inset:0; background:rgba(0,0,0,.45); }
.swatch.sel { border-color:var(--accent); }
.swatch .sig { position:relative; font-size:46px; color:rgba(255,255,255,.92); text-shadow:0 1px 3px rgba(0,0,0,.8); line-height:1; }
.swatch .sig svg { position:absolute; left:-4%; bottom:-.32em; width:108%; height:.55em; overflow:visible; }
.swatch .sig svg path { fill:none; stroke:rgba(255,255,255,.75); stroke-width:1.4; stroke-linecap:round; }
.swatch .tag { position:absolute; left:8px; top:6px; font-size:12px; color:var(--muted); }
</style>
</head>
<body>
<header>
    <h1>Caption Lab</h1>
    <label>Image <select id="pick"></select></label>
    <label>Signature text <select id="sigtext"><option>C Baker</option><option>C. Baker</option><option>Carl Baker</option></select></label>
    <label><input type="checkbox" id="flourish"> Flourish under signature</label>
</header>
<main>
    <h2>Signatures</h2>
    <p class="note">Click one to use it in the caption previews below.</p>
    <div class="sigs" id="sigs"></div>

    <h2>Caption styles</h2>
    <p class="note">Each frame is a 16:9 window onto the image, like a landscape screen. Resize the window to see the text scale.</p>
    <div class="grid" id="grid"></div>
</main>

<script src="overlay.js"></script>
<script>
(function () {
    'use strict';
    var cards = <?= $json ?>;
    var O = window.AstroOverlay;

    var FONTS = [
        ['1', 'Dancing Script', 'casual, current'],
        ['2', 'Great Vibes', 'formal swashes'],
        ['3', 'Allura', 'elegant, even'],
        ['4', 'Pinyon Script', 'copperplate'],
        ['5', 'Alex Brush', 'brush, bold'],
        ['6', 'Sacramento', 'thin monoline'],
        ['7', 'Parisienne', 'flowing'],
        ['8', 'Mrs Saint Delafield', 'tall, dramatic'],
        ['9', 'Herr Von Muellerhoff', 'quick pen'],
        ['10', 'Homemade Apple', 'real handwriting']
    ];
    var FLOURISH = '<svg viewBox="0 0 100 10" preserveAspectRatio="none"><path d="M2 6 C 25 9, 55 1, 78 5 S 96 8, 99 3"/></svg>';

    var images = [];
    cards.forEach(function (c) {
        c.images.forEach(function (im) {
            if (im.hasFull) images.push({ card: c, im: im });
        });
    });

    var pick = document.getElementById('pick');
    images.forEach(function (x, i) {
        var o = document.createElement('option');
        o.value = i;
        o.textContent = x.card.name + ' (' + x.card.dsoKey + ') ' + O.equipmentLabel(x.im.equipment) + ', ' + O.formatDate(x.im.date);
        pick.appendChild(o);
    });

    var state = { img: 0, font: 'Dancing Script', text: 'C Baker', flourish: false };

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    }

    function sigHtml(font, size) {
        return '<div class="sig" style="font-family:\'' + font + '\',cursive' + (size ? ';font-size:' + size : '') + '">' +
               esc(state.text) + (state.flourish ? FLOURISH : '') + '</div>';
    }

    function meta() {
        var x = images[state.img];
        return {
            name: x.card.name, key: x.card.dsoKey,
            equip: O.equipmentLabel(x.im.equipment), date: O.formatDate(x.im.date),
            src: x.im.full
        };
    }

    var STYLES = [
        ['A', 'Current', 'single line over a soft gradient', function (m) {
            return '<div class="txt">' + esc([m.name, m.key, m.equip, m.date].filter(Boolean).join('  ·  ')) + '</div>';
        }],
        ['B', 'Gallery labels', 'like the Browse Gallery caption bar, on a solid band', function (m) {
            return '<div class="txt"><span class="nm">' + esc(m.name) + '</span><span class="key">' + esc(m.key) + '</span>' +
                   (m.equip ? '<span><span class="lbl">Equipment </span><span class="val">' + esc(m.equip) + '</span></span>' : '') +
                   (m.date ? '<span><span class="lbl">Date Taken </span><span class="val">' + esc(m.date) + '</span></span>' : '') + '</div>';
        }],
        ['C', 'Title over details', 'serif italic name, spaced capitals below', function (m) {
            return '<div><div class="nm">' + esc(m.name) + '</div><div class="det">' +
                   esc([m.key, m.equip, m.date].filter(Boolean).join('  ·  ')) + '</div></div>';
        }],
        ['D', 'Museum label', 'small caps with an accent bar', function (m) {
            return '<div class="lab"><div><span class="nm">' + esc(m.name) + '</span><span class="key">' + esc(m.key) + '</span></div>' +
                   '<div class="det">' + esc([m.equip, m.date].filter(Boolean).join('  ·  ')) + '</div></div>';
        }],
        ['E', 'Whisper', 'no band at all, faint shadowed text', function (m) {
            return '<div class="txt">' + esc([m.name, m.key, m.equip, m.date].filter(Boolean).join('   ')) + '</div>';
        }],
        ['F', 'Name over a hairline', 'serif name, fading rule, details below', function (m) {
            return '<div><div class="nm">' + esc(m.name) + '</div><div class="rule"></div><div class="det">' +
                   esc([m.key, m.equip, m.date].filter(Boolean).join('  ·  ')) + '</div></div>';
        }]
    ];

    function renderSigs() {
        var m = meta();
        document.getElementById('sigs').innerHTML = FONTS.map(function (f) {
            return '<div class="swatch' + (f[1] === state.font ? ' sel' : '') + '" data-font="' + esc(f[1]) +
                   '" style="background-image:url(\'' + esc(m.src) + '\')"><span class="tag">' + f[0] + '. ' + esc(f[1]) +
                   ' – ' + esc(f[2]) + '</span>' + sigHtml(f[1]) + '</div>';
        }).join('');
        Array.prototype.forEach.call(document.querySelectorAll('.swatch'), function (el) {
            el.onclick = function () { state.font = el.getAttribute('data-font'); render(); };
        });
    }

    function renderGrid() {
        var m = meta();
        document.getElementById('grid').innerHTML = STYLES.map(function (s) {
            return '<div class="cell"><h3>' + s[0] + '. ' + s[1] + ' <span>– ' + s[2] + '</span></h3>' +
                   '<div class="frame"><img class="pic" src="' + esc(m.src) + '" alt="">' +
                   '<div class="cap v-' + s[0].toLowerCase() + '">' + s[3](m) + sigHtml(state.font, '1.9em') + '</div></div></div>';
        }).join('');
    }

    function render() { renderSigs(); renderGrid(); }

    pick.onchange = function () { state.img = +pick.value; render(); };
    document.getElementById('sigtext').onchange = function (e) { state.text = e.target.value; render(); };
    document.getElementById('flourish').onchange = function (e) { state.flourish = e.target.checked; render(); };

    if (images.length) render();
    else document.getElementById('grid').textContent = 'No images found.';
})();
</script>
</body>
</html>
