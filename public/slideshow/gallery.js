/*
 * gallery.js -- the Browse Gallery view of the new gallery app.
 *
 * Grid: one card per Project in the hand-ranked gallery order, showing the
 * featured image, with a search box. Clicking a card opens the viewer
 * (#gallery/<card key>), so the browser Back button closes it again.
 *
 * Viewer: the image fills the screen with the caption overlay (overlay.js),
 * rotated on landscape screens like the Slideshow; arrows / swipe / the
 * filmstrip step through the card's images. The i button (or I) opens
 * the information window: object details and the current image's line
 * (caption, palette, photographer). It stays open while stepping.
 *
 * Downloads are made on the fly from the plain _full and _fav files:
 * plain or captioned, portrait (9:16), landscape (16:9, the _full rotated)
 * or square (the _fav). When publish_images.py made a 4K wallpaper there
 * are also 4K Landscape (the file as is) and 4K Portrait (the wallpaper
 * turned back upright, 2160 x 3840).
 *
 * The orientation dropdown (overlay.js) forces Landscape or Portrait
 * layout regardless of the screen's shape; Auto follows the screen.
 *
 * Viewer keys: Right/Left = next/previous image, C = caption on/off,
 * I = information window, D = downloads menu, Esc = close.
 *
 * The Solar System view (#solar, #solar/<object>) uses the same grid and
 * viewer with the solar cards from data.php (fav images only, no object
 * details).
 *
 * Run locally (window.GALLERY_EDIT, set by index.php) the gallery can be
 * reordered by dragging: the cards (saved to Projects.GalleryRank through
 * admin/api_gallery_rank.php) and, in the viewer, the filmstrip (the
 * card's image order, admin/api_gallery_image.php action "order"; the
 * first image becomes the card's picture).
 */
(function (global) {
    'use strict';

    var O = global.AstroOverlay;
    var cards = [], solarCards = [], byKey = {};
    var el = {};
    var cur = null, imgIdx = 0;
    var visible = false, openedFromGrid = false, built = {};
    var overlayOn = true;
    var canEdit = !!global.GALLERY_EDIT;
    var ADMIN = '../admin/';

    function $(id) { return document.getElementById(id); }

    // Small preview for an image: thumbnail, else the fav, else the full
    // image; if none loads, onGiveUp() runs (and the img is hidden).
    function setPreview(img, im, onGiveUp) {
        var srcs = [im.thumb, im.fav, im.full];
        var n = 0;
        img.onerror = function () {
            n++;
            if (n < srcs.length) { img.src = srcs[n]; return; }
            img.onerror = null;
            img.style.visibility = 'hidden';
            if (onGiveUp) onGiveUp();
        };
        img.src = srcs[0];
    }

    function node(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = text;
        return n;
    }

    // ── Grid ─────────────────────────────────────────────────────────

    // view: 'gallery' or 'solar' -- which grid to fill, and the URL prefix.
    function buildGrid(view) {
        if (built[view]) return;
        built[view] = true;
        var list = view === 'solar' ? solarCards : cards;
        var grid = view === 'solar' ? el.solarGrid : el.grid;
        var frag = document.createDocumentFragment();
        list.forEach(function (card) {
            var feat = card.images[0];
            if (!feat) return;

            var a = node('a', 'g-card');
            a.href = '#' + view + '/' + card.key;
            a.setAttribute('data-search',
                [card.name, card.dsoKey, card.info.catalogId || '', card.displayName].join(' ').toLowerCase());
            a.setAttribute('data-key', card.key);
            a.addEventListener('click', function () { openedFromGrid = true; });

            var thumb = node('div', 'g-thumb');
            var img = node('img');
            img.alt = card.displayName;
            img.draggable = false;
            img.loading = 'lazy';
            img.decoding = 'async';
            img.onload = function () { thumb.classList.add('loaded'); };
            setPreview(img, card.cover ? { thumb: card.cover, fav: feat.fav, full: feat.full } : feat,
                       function () { thumb.classList.add('loaded'); });
            thumb.appendChild(img);

            if (card.images.length > 1) thumb.appendChild(node('span', 'g-badge g-count', card.images.length + ' images'));
            if (card.isMosaic) thumb.appendChild(node('span', 'g-badge g-mosaic', 'Mosaic'));
            // 4K: any of the card's images has a 4K wallpaper to download.
            if (card.images.some(function (im) { return im.wall4k; })) {
                var k4 = node('span', 'g-badge g-4k', '4K');
                k4.title = '4K wallpaper download available';
                thumb.appendChild(k4);
            }

            a.appendChild(thumb);
            a.appendChild(node('h3', 'g-title', card.displayName));
            // Cards without a Project (key dso_...) have no rank to save.
            if (canEdit && view === 'gallery' && card.key.charAt(0) === 'p') {
                makeDraggable(a, el.grid, saveCardOrder, function () {
                    if (!el.search.value.trim()) return true;
                    toast('Clear the search box first, so every card is in view.', 'error');
                    return false;
                });
            }
            frag.appendChild(a);
        });
        grid.appendChild(frag);
        if (view === 'solar' && !list.length) grid.appendChild(node('p', 'g-none', 'No solar system images yet.'));
    }

    function filter() {
        var q = el.search.value.trim().toLowerCase();
        var shown = 0;
        Array.prototype.forEach.call(el.grid.children, function (a) {
            var hit = !q || a.getAttribute('data-search').indexOf(q) !== -1;
            a.hidden = !hit;
            if (hit) shown++;
        });
        el.none.hidden = shown > 0;
    }

    // ── Viewer ───────────────────────────────────────────────────────

    function curImage() { return cur ? cur.images[imgIdx] : null; }

    function layout() {
        var im = curImage();
        if (!im || el.modal.hidden) return;
        // Sun and Moon: full width, centred, top and bottom clipped, never rotated.
        O.applyLayout(el.img, el.overlay, el.stage, O.isClockwise(im.base),
                      { fillWidth: !!cur.solar, flip: O.isFlipped(im.base) });
    }

    function showImage() {
        var im = curImage();
        if (!im) return;
        var src = im.hasFull ? im.full : im.fav;

        el.counter.textContent = cur.images.length > 1 ? (imgIdx + 1) + ' / ' + cur.images.length : '';
        Array.prototype.forEach.call(el.strip.children, function (t, i) {
            t.classList.toggle('active', i === imgIdx);
            // Scroll only the strip sideways (scrollIntoView would also
            // scroll the viewer down to the strip).
            if (i === imgIdx) el.strip.scrollLeft = t.offsetLeft - (el.strip.clientWidth - t.offsetWidth) / 2;
        });
        renderImageLine(im);
        // Downloads this image can make: portrait needs the _full; square
        // needs the _fav; landscape rotates whichever exists (a rotated
        // 4:5 fav comes out 5:4) -- except for the Sun and Moon, which are
        // never rotated, so they get square only.
        el.favOpts.forEach(function (o) { o.hidden = !im.hasFav; });
        el.fullOpts.forEach(function (o) { o.hidden = !im.hasFull; });
        el.landOpts.forEach(function (o) { o.hidden = !!cur.solar; });
        el.landHints.forEach(function (h) { h.textContent = im.hasFull ? '16:9' : '5:4'; });
        // 4K wallpaper: only when publish_images.py made one for this image.
        el.wallOpts.forEach(function (o) { o.hidden = !im.wall4k; });
        closeMenu();

        O.renderOverlay(el.overlay, O.metaFor(cur, im), O.signatureFor(im));
        if (el.img.getAttribute('src') === src && el.img.complete) {
            layout();
            return;
        }
        el.modal.classList.add('loading');
        el.img.onload = function () { layout(); el.modal.classList.remove('loading'); };
        el.img.onerror = function () { el.modal.classList.remove('loading'); };
        el.img.alt = cur.displayName;
        el.img.src = src;
    }

    function step(d) {
        if (!cur || cur.images.length < 2) return;
        imgIdx = (imgIdx + d + cur.images.length) % cur.images.length;
        showImage();
    }

    function buildStrip() {
        el.strip.textContent = '';
        el.strip.hidden = cur.images.length < 2;
        el.prev.hidden = el.next.hidden = cur.images.length < 2;
        el.more.hidden = cur.images.length < 2;     // only the filmstrip is below the picture
        cur.images.forEach(function (im, i) {
            var b = node('button', 'gm-thumb');
            b.type = 'button';
            b.setAttribute('aria-label', 'Image ' + (i + 1));
            b.setAttribute('data-id', im.id);
            var t = node('img');
            t.alt = '';
            t.loading = 'lazy';
            t.draggable = false;
            setPreview(t, im);
            b.appendChild(t);
            b.addEventListener('click', function () {
                imgIdx = Array.prototype.indexOf.call(el.strip.children, b);
                showImage();
            });
            if (canEdit && !cur.solar && im.id) makeDraggable(b, el.strip, saveImageOrder);
            el.strip.appendChild(b);
        });
    }

    // ── Reordering (local only) ──────────────────────────────────────

    var dragItem = null, toastTimer = null;

    function toast(text, state) {
        if (!el.toast) {
            el.toast = node('div', 'g-toast');
            el.toast.setAttribute('role', 'status');
            document.body.appendChild(el.toast);
        }
        clearTimeout(toastTimer);
        el.toast.textContent = text;
        el.toast.className = 'g-toast show' + (state ? ' ' + state : '');
        if (state !== 'saving') {
            toastTimer = setTimeout(function () { el.toast.classList.remove('show'); }, state === 'error' ? 6000 : 1800);
        }
    }

    function clearDropMarks() {
        Array.prototype.forEach.call(document.querySelectorAll('.drop-before, .drop-after'), function (x) {
            x.classList.remove('drop-before', 'drop-after');
        });
    }

    // Drop on the right half of an item = after it (grid and strip both
    // run left to right).
    function isAfter(item, e) {
        var r = item.getBoundingClientRect();
        return e.clientX > r.left + r.width / 2;
    }

    // Drag item within container; onChange() runs after the DOM has moved.
    // canStart() may refuse the drag.
    function makeDraggable(item, container, onChange, canStart) {
        item.draggable = true;
        item.classList.add('can-drag');
        item.addEventListener('dragstart', function (e) {
            if (canStart && !canStart()) { e.preventDefault(); return; }
            dragItem = item;
            item.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', '');
        });
        item.addEventListener('dragend', function () {
            item.classList.remove('dragging');
            clearDropMarks();
            dragItem = null;
        });
        item.addEventListener('dragover', function (e) {
            if (!dragItem || dragItem.parentNode !== container) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            if (dragItem === item) { clearDropMarks(); return; }
            var after = isAfter(item, e);
            if (!item.classList.contains(after ? 'drop-after' : 'drop-before')) {
                clearDropMarks();
                item.classList.add(after ? 'drop-after' : 'drop-before');
            }
        });
        item.addEventListener('drop', function (e) {
            if (!dragItem || dragItem.parentNode !== container) return;
            e.preventDefault();
            var moved = dragItem;
            clearDropMarks();
            if (moved === item) return;
            var ref = isAfter(item, e) ? item.nextSibling : item;
            if (ref === moved || moved.nextSibling === ref) return;   // same place
            container.insertBefore(moved, ref);
            onChange(moved);
        });
    }

    function postJson(url, body) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        }).then(function (r) {
            return r.json().catch(function () { return { error: 'HTTP ' + r.status }; }).then(function (d) {
                if (!r.ok || d.error || !d.success) throw new Error(d.error || ('HTTP ' + r.status));
                return d;
            });
        });
    }

    var cardSeq = 0;

    // The grid's DOM order is the new order: put cards[] in step and save
    // the rank of every Project card (1..n).
    function saveCardOrder(moved) {
        var pos = {};
        Array.prototype.forEach.call(el.grid.children, function (a, i) { pos[a.getAttribute('data-key')] = i; });
        cards.sort(function (a, b) { return (pos[a.key] || 0) - (pos[b.key] || 0); });
        var order = cards.filter(function (c) { return c.key.charAt(0) === 'p'; })
                         .map(function (c) { return parseInt(c.key.slice(1), 10); });
        moved.classList.add('just-moved');
        setTimeout(function () { moved.classList.remove('just-moved'); }, 1200);

        var seq = ++cardSeq;
        toast('Saving the gallery order…', 'saving');
        postJson(ADMIN + 'api_gallery_rank.php', { order: order })
            .then(function () { if (seq === cardSeq) toast('✓ Gallery order saved', 'saved'); })
            .catch(function (e) { if (seq === cardSeq) toast('⚠ Gallery order not saved: ' + e.message, 'error'); });
    }

    // The strip's DOM order is the card's new image order. The image on
    // screen stays on screen; the first image becomes the card's picture.
    function saveImageOrder() {
        var card = cur, shown = curImage();
        var byId = {};
        card.images.forEach(function (im) { byId[im.id] = im; });
        card.images = Array.prototype.map.call(el.strip.children, function (b, i) {
            b.setAttribute('aria-label', 'Image ' + (i + 1));
            return byId[b.getAttribute('data-id')];
        });
        imgIdx = Math.max(0, card.images.indexOf(shown));
        showImage();
        updateCardThumb(card);

        toast('Saving the image order…', 'saving');
        postJson(ADMIN + 'api_gallery_image.php', {
            action: 'order',
            order: card.images.map(function (im) { return im.id; })
        }).then(function (d) {
            var feat = {};
            (d.images || []).forEach(function (r) { feat[r.GalleryImageID] = r.IsFeature; });
            card.images.forEach(function (im) { if (im.id in feat) im.isFeature = feat[im.id]; });
            card.isMosaic = card.images[0] ? card.images[0].isMosaic : 0;
            updateCardThumb(card);
            toast('✓ Image order saved', 'saved');
        }).catch(function (e) {
            toast('⚠ Image order not saved: ' + e.message, 'error');
        });
    }

    // The grid card shows the card's first image (and its mosaic badge).
    function updateCardThumb(card) {
        var a = el.grid.querySelector('.g-card[data-key="' + card.key + '"]');
        var first = card.images[0];
        if (!a || !first) return;
        var img = a.querySelector('.g-thumb img');
        if (img) { img.style.visibility = ''; setPreview(img, first); }
        var thumb = a.querySelector('.g-thumb');
        var badge = thumb.querySelector('.g-mosaic');
        if (card.isMosaic && !badge) thumb.appendChild(node('span', 'g-badge g-mosaic', 'Mosaic'));
        if (!card.isMosaic && badge) badge.remove();
    }

    // Per-image line under the strip: palette, caption, mosaic, credit.
    function renderImageLine(im) {
        var line = el.imgLine;
        line.textContent = '';
        function add(cls, label, value) {
            if (!value) return;
            var s = node('span', cls);
            if (label) s.appendChild(node('span', 'g-lbl', label + ' '));
            s.appendChild(document.createTextNode(value));
            line.appendChild(s);
        }
        if (im.caption) add('g-caption', '', im.caption);
        if (im.palette) add('g-palette', '', im.palette + ' Palette');
        if (im.isMosaic) add('g-mosaic-tag', '', 'Mosaic');
        if (im.isOwn) {
            add('g-credit', 'Photographer:', 'Carl Baker');
        } else {
            add('g-credit', 'Photographer:', im.attribution);
            if (im.copyright) add('g-credit', '', '© ' + im.copyright);
            if (!im.attribution && !im.copyright) add('g-credit', '', 'Image credit: third party');
        }
        line.hidden = !line.firstChild;
    }

    function pad(n, w) { return String(Math.floor(n)).padStart(w, '0'); }

    function coordText(ra, dec) {
        if (ra === null || ra === undefined || ra === '' || dec === null || dec === undefined || dec === '') return '';
        ra = parseFloat(ra); dec = parseFloat(dec);
        if (isNaN(ra) || isNaN(dec)) return '';
        var raH = Math.floor(ra), raM = Math.floor((ra - raH) * 60), raS = Math.round(((ra - raH) * 60 - raM) * 60);
        var sign = dec < 0 ? '−' : '+', a = Math.abs(dec);
        var dD = Math.floor(a), dM = Math.floor((a - dD) * 60), dS = Math.round(((a - dD) * 60 - dM) * 60);
        return 'RA ' + pad(raH, 2) + 'h ' + pad(raM, 2) + 'm ' + pad(raS, 2) + 's, Dec ' +
               sign + pad(dD, 2) + '° ' + pad(dM, 2) + '′ ' + pad(dS, 2) + '″ (J2000)';
    }

    function renderInfo() {
        var box = el.info;
        box.textContent = '';
        box.appendChild(node('h2', 'gm-title', cur.displayName));

        if (cur.solar) return;   // no object details for the Sun, Moon and planets

        var i = cur.info || {};
        if (i.blurb) {
            String(i.blurb).split(/\n\s*\n/).forEach(function (p) {
                if (p.trim()) box.appendChild(node('p', 'gm-blurb', p.trim()));
            });
        }
        var facts = [];
        if (i.objectSize) facts.push('The ' + (cur.name || cur.dsoKey) + ' is ' + i.objectSize);
        var coords = coordText(i.ra, i.dec);
        if (coords) facts.push('Coordinates: ' + coords);
        if (facts.length) box.appendChild(node('p', 'gm-facts', facts.join('. ') + '.'));
        if (!i.blurb && !facts.length) box.appendChild(node('p', 'gm-facts', 'No information available for this object yet.'));

        if (cur.links && cur.links.length) {
            var links = node('div', 'gm-links');
            cur.links.forEach(function (l) {
                var a = node('a', 'gm-link', l.label);
                a.href = l.url;
                a.target = '_blank';
                a.rel = 'noopener';
                links.appendChild(a);
            });
            box.appendChild(links);
        }
    }

    function open(key) {
        var card = byKey[key];
        if (!card) { location.hash = 'gallery'; return; }
        if (cur === card && !el.modal.hidden) return;
        cur = card;
        imgIdx = 0;
        el.modal.hidden = false;
        document.body.classList.add('no-scroll');
        el.modal.scrollTop = 0;
        el.img.removeAttribute('src');
        buildStrip();
        renderInfo();
        showImage();
        el.close.focus({ preventScroll: true });
    }

    function close() {
        if (el.modal.hidden) return;
        el.modal.hidden = true;
        document.body.classList.remove('no-scroll');
        closeMenu();
        closePanel();
        cur = null;
        openedFromGrid = false;
    }

    // Close button / Esc: step back in history if we came from the grid,
    // otherwise (opened from a link) go to the grid.
    function requestClose() {
        var grid = cur && cur.solar ? 'solar' : 'gallery';
        if (openedFromGrid) { openedFromGrid = false; history.back(); }
        else location.hash = grid;
    }

    function toggleOverlay() {
        overlayOn = !overlayOn;
        el.modal.classList.toggle('no-overlay', !overlayOn);
        try { localStorage.setItem('ss.overlay', overlayOn ? '1' : '0'); } catch (e) { /* ignore */ }
    }

    // ── Downloads ────────────────────────────────────────────────────

    function toggleMenu() { el.menu.hidden = !el.menu.hidden; if (!el.menu.hidden) closePanel(); }
    function closeMenu() { if (el.menu) el.menu.hidden = true; }

    // Information window (same corner as the downloads menu, so one at a time).
    function togglePanel() {
        el.panel.hidden = !el.panel.hidden;
        if (!el.panel.hidden) { closeMenu(); el.panel.scrollTop = 0; }
    }
    function closePanel() { if (el.panel) el.panel.hidden = true; }

    function loadImage(src) {
        return new Promise(function (resolve, reject) {
            var i = new Image();
            i.onload = function () { resolve(i); };
            i.onerror = function () { reject(new Error('Could not load ' + src)); };
            i.src = src;
        });
    }

    function deliver(blob, filename) {
        // Phones: offer the share sheet (save to photos etc.); else download.
        if (navigator.canShare && navigator.share) {
            try {
                var file = new File([blob], filename, { type: blob.type || 'image/jpeg' });
                if (navigator.canShare({ files: [file] }) && /Android|iPhone|iPad|iPod/i.test(navigator.userAgent)) {
                    return navigator.share({ files: [file], title: cur ? cur.displayName : filename })
                        .catch(function (e) { if (e.name !== 'AbortError') saveBlob(blob, filename); });
                }
            } catch (e) { /* fall through to a normal download */ }
        }
        saveBlob(blob, filename);
        return Promise.resolve();
    }

    function saveBlob(blob, filename) {
        var url = URL.createObjectURL(blob);
        var a = node('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
    }

    // kind: 'plain' | 'captioned';  shape: 'portrait' | 'landscape' | 'square'
    function download(kind, shape) {
        var im = curImage();
        if (!im) return;
        closeMenu();
        var wall = shape === 'wall4k' || shape === 'wall4k-portrait';
        var src = wall ? im.wall4k : (shape === 'square' || !im.hasFull ? im.fav : im.full);
        var suffix = shape === 'wall4k' ? '_4k' : shape === 'wall4k-portrait' ? '_4k_portrait'
                   : shape === 'square' ? '_fav' : (shape === 'landscape' ? '_landscape' : '_full');
        if (!src) return;
        var filename = im.base + suffix + (kind === 'captioned' ? '_captioned' : '') + '.jpg';

        // The 4K wallpaper of a portrait _full was turned to landscape
        // (counter-clockwise, or clockwise for _cw_), so 4K Portrait turns it
        // back the other way. One that was also flipped (_fw_) is put back
        // upright by turning it the same way and flipping it again. A
        // landscape _full's wallpaper isn't turned, so it's turned (and
        // flipped) the way the gallery does it on a portrait screen.
        var clockwise = O.isClockwise(im.base);
        var flip = O.isFlipped(im.base);
        if (shape === 'wall4k-portrait' && im.fullPortrait && !flip) clockwise = !clockwise;
        var orientation = shape === 'square' ? null : shape === 'wall4k-portrait' ? 'portrait' : shape;

        el.modal.classList.add('busy');
        var work;
        if (kind === 'plain' && shape !== 'landscape' && shape !== 'wall4k-portrait') {   // portrait, square or 4K landscape: the file as is
            // The file itself, untouched.
            work = fetch(src).then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.blob();
            });
        } else {
            work = Promise.all([loadImage(src), kind === 'captioned' ? O.loadFonts() : null]).then(function (res) {
                var cv = O.renderCanvas(res[0], {
                    orientation: orientation,
                    clockwise: clockwise,
                    flip: flip,
                    caption: kind === 'captioned',
                    meta: O.metaFor(cur, im),
                    signature: O.signatureFor(im)
                });
                return new Promise(function (resolve, reject) {
                    cv.toBlob(function (b) { if (b) resolve(b); else reject(new Error('Could not create image')); },
                              'image/jpeg', 0.92);
                });
            });
        }
        work.then(function (blob) { return deliver(blob, filename); })
            .catch(function (e) {
                console.error('Download failed:', e);
                alert('Sorry, that download did not work. Please try again.');
            })
            .then(function () { el.modal.classList.remove('busy'); });
    }

    // ── Events ───────────────────────────────────────────────────────

    function onKey(e) {
        if (!visible) return;
        if (e.target && e.target.tagName === 'SELECT') return;   // the orientation dropdown
        if (!el.modal.hidden) {
            switch (e.key) {
                case 'ArrowRight': step(1); break;
                case 'ArrowLeft':  step(-1); break;
                case 'c': case 'C': toggleOverlay(); break;
                case 'd': case 'D': toggleMenu(); break;
                case 'i': case 'I': togglePanel(); break;
                case 'Escape':
                    if (!el.menu.hidden) closeMenu();
                    else if (!el.panel.hidden) closePanel();
                    else requestClose();
                    break;
                default: return;
            }
            e.preventDefault();
        } else if (e.key === 'Escape') {
            if (!el.view.hidden && el.search.value) { el.search.value = ''; filter(); }
            else if (global.SlideshowApp) global.SlideshowApp.home();
        } else if (e.key === '/' && !el.view.hidden && document.activeElement !== el.search) {
            e.preventDefault();
            el.search.focus();
        }
    }

    var touchX = 0, touchY = 0;

    function init(cardData, solarData) {
        cards = cardData || [];
        solarCards = solarData || [];
        cards.concat(solarCards).forEach(function (c) { byKey[c.key] = c; });

        el.view    = $('gallery');
        el.grid    = $('g-grid');
        el.solar   = $('solar');
        el.solarGrid = $('s-grid');
        el.search  = $('g-search');
        el.none    = $('g-none');
        el.modal   = $('gm');
        el.stage   = $('gm-stage');
        el.img     = $('gm-img');
        el.overlay = $('gm-overlay');
        el.counter = $('gm-counter');
        el.strip   = $('gm-strip');
        el.imgLine = $('gm-imgline');
        el.info    = $('gm-info');
        el.menu    = $('gm-menu');
        el.panel   = $('gm-panel');
        el.more    = $('gm-more');
        el.close   = $('gm-close');
        el.prev    = $('gm-prev');
        el.next    = $('gm-next');
        el.favOpts = Array.prototype.slice.call(document.querySelectorAll('#gm-menu [data-shape="square"]'));
        el.fullOpts = Array.prototype.slice.call(document.querySelectorAll('#gm-menu [data-shape="portrait"]'));
        el.landOpts = Array.prototype.slice.call(document.querySelectorAll('#gm-menu [data-shape="landscape"]'));
        el.landHints = Array.prototype.slice.call(document.querySelectorAll('#gm-menu [data-shape="landscape"] .hint'));
        el.wallOpts = Array.prototype.slice.call(document.querySelectorAll('#gm-menu .gm-wall'));

        try { overlayOn = localStorage.getItem('ss.overlay') !== '0'; } catch (e) { overlayOn = true; }
        el.modal.classList.toggle('no-overlay', !overlayOn);

        el.search.addEventListener('input', filter);
        ['g-home', 's-home'].forEach(function (id) {
            $(id).addEventListener('click', function (e) {
                e.preventDefault();
                if (global.SlideshowApp) global.SlideshowApp.home();
            });
        });
        el.close.addEventListener('click', requestClose);
        el.prev.addEventListener('click', function () { step(-1); });
        el.next.addEventListener('click', function () { step(1); });
        $('gm-caption').addEventListener('click', toggleOverlay);
        $('gm-download').addEventListener('click', function (e) { e.stopPropagation(); toggleMenu(); });
        $('gm-infobtn').addEventListener('click', function (e) { e.stopPropagation(); togglePanel(); });
        $('gm-panel-close').addEventListener('click', closePanel);
        $('gm-more').addEventListener('click', function () {
            el.modal.scrollTo({ top: el.stage.offsetHeight - 40, behavior: 'smooth' });
        });
        Array.prototype.forEach.call(el.menu.querySelectorAll('[data-kind]'), function (b) {
            b.addEventListener('click', function () { download(b.getAttribute('data-kind'), b.getAttribute('data-shape')); });
        });
        el.modal.addEventListener('click', function (e) {
            if (!el.menu.hidden && !el.menu.contains(e.target)) closeMenu();
            // A click outside the information window closes it -- except on
            // the arrows and the filmstrip, so it can stay open while stepping.
            if (!el.panel.hidden && !el.panel.contains(e.target) && !el.prev.contains(e.target)
                && !el.next.contains(e.target) && !el.strip.contains(e.target)) closePanel();
        });

        el.stage.addEventListener('touchstart', function (e) {
            touchX = e.touches[0].clientX; touchY = e.touches[0].clientY;
        }, { passive: true });
        el.stage.addEventListener('touchend', function (e) {
            var dx = e.changedTouches[0].clientX - touchX, dy = e.changedTouches[0].clientY - touchY;
            if (Math.abs(dx) >= 40 && Math.abs(dx) > Math.abs(dy) * 1.5) step(dx < 0 ? 1 : -1);
        }, { passive: true });

        document.addEventListener('keydown', onKey);
        var rt = null;
        window.addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(layout, 120); });
        window.addEventListener('orientationchange', function () { setTimeout(layout, 120); });
        window.addEventListener('astro-orientation', layout);
        // Clicking the dropdown shouldn't close the downloads menu's owner etc.
        Array.prototype.forEach.call(el.modal.querySelectorAll('.orient-select'), function (s) {
            s.addEventListener('click', function (e) { e.stopPropagation(); });
        });
    }

    // view: 'gallery' (default) or 'solar'
    function show(view) {
        view = view === 'solar' ? 'solar' : 'gallery';
        buildGrid(view);
        el.view.hidden = view !== 'gallery';
        el.solar.hidden = view !== 'solar';
        visible = true;
    }

    function hide() {
        close();
        el.view.hidden = true;
        el.solar.hidden = true;
        visible = false;
    }

    global.Gallery = { init: init, show: show, hide: hide, open: open, close: close };
})(window);
