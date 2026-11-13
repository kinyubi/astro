/*
 * overlay.js -- shared on-the-fly annotation for the slideshow app.
 *
 * Replaces the pre-rendered _annotated files: the caption (name, DSO key,
 * equipment, date) and signature are drawn over the plain image in the
 * browser -- on screen as HTML (renderOverlay), and into downloaded files
 * on a canvas (renderCanvas), so both always match.
 *
 * Also handles fitting an image to the screen, rotating it 90 degrees when
 * the image and screen orientations differ (counterclockwise by default,
 * clockwise when the BaseName has a "_cw_" token).
 */
(function (global) {
    'use strict';

    var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                  'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    var SIGNATURE = 'C Baker';
    var SEPARATOR = '  ·  ';

    // Caption look, shared by the HTML overlay (slideshow.css) and the
    // canvas version below. Keep the two in step.
    var CAPTION = {
        family:    'Inter',
        sigFamily: 'Herr Von Muellerhoff',
        nameWeight: 310,
        sepWeight:  310,
        detWeight:  230,
        nameColor: 'rgba(255, 255, 255, 0.9)',
        textColor: 'rgba(255, 255, 255, 0.74)',
        sigColor:  'rgba(255, 255, 255, 0.9)',
        sigScale:  2.4,
        letterSpacing: 0.03,
        lineHeight: 1.35
    };

    // Equipment code -> display name. index.php supplies the full list
    // (window.EQUIPMENT_NAMES: Seestar labels, the Equipment table, and
    // 8SE = Celestron NexStar 8SE); these are the fallback.
    var EQUIPMENT = global.EQUIPMENT_NAMES || {
        s30:  'Seestar S30',
        s50:  'Seestar S50',
        s50p: 'Seestar S50 Pro',
        '8se': 'Celestron NexStar 8SE'
    };

    // "2026-10-04" (or a timestamp starting with it) -> "4 Oct 2026".
    // Built from a fixed month list, not the browser locale, so it reads
    // the same everywhere and matches canvas downloads.
    function formatDate(value) {
        if (!value) return '';
        var s = String(value);
        if (s.length < 10 || s.charAt(4) !== '-' || s.charAt(7) !== '-') return '';
        var y = s.substring(0, 4);
        var m = parseInt(s.substring(5, 7), 10);
        var d = parseInt(s.substring(8, 10), 10);
        if (!(m >= 1 && m <= 12) || !(d >= 1 && d <= 31)) return '';
        return d + ' ' + MONTHS[m - 1] + ' ' + y;
    }

    // UNK (unknown equipment) is left out of the caption altogether.
    function equipmentLabel(code) {
        if (!code) return '';
        var c = String(code).trim();
        if (c.toLowerCase() === 'unk') return '';
        return EQUIPMENT[c.toLowerCase()] || c;
    }

    // True when any "_"-separated token of the base name is "cw".
    function isClockwise(base) {
        var parts = String(base || '').toLowerCase().split('_');
        for (var i = 0; i < parts.length; i++) {
            if (parts[i] === 'cw') return true;
        }
        return false;
    }

    // Caption fields for a gallery card + image (data.php shapes). Solar
    // shots carry their own title ("Waning Gibbous Moon") and no DSO key.
    function metaFor(card, im) {
        return { name: im.title || card.name, key: card.dsoKey, equipment: im.equipment, date: im.date };
    }

    // Your images get the signature; another photographer's image in the
    // gallery (IsOwn = 0) gets their name instead (blank if none recorded).
    function signatureFor(im) {
        if (!im || im.isOwn === undefined || im.isOwn) return undefined;
        return im.attribution || '';
    }

    // meta: { name, key, equipment, date }
    function overlayParts(meta) {
        var parts = [meta.name, meta.key, equipmentLabel(meta.equipment), formatDate(meta.date)];
        var out = [];
        for (var i = 0; i < parts.length; i++) {
            if (parts[i] && out.indexOf(parts[i]) === -1) out.push(parts[i]);
        }
        return out;
    }

    function overlayText(meta) {
        return overlayParts(meta).join(SEPARATOR);
    }

    /*
     * Fit an image of natW x natH into a boxW x boxH area (contain), rotated
     * 90 degrees when the image is portrait and the box landscape or vice
     * versa. Returns the element size (before rotation), the rotation in
     * degrees, and the visible rectangle (after rotation) within the box.
     */
    function fitImage(natW, natH, boxW, boxH, clockwise) {
        var rotate = 0;
        if (natW !== natH && (natH > natW) !== (boxH > boxW)) {
            rotate = clockwise ? 90 : -90;
        }
        var visNatW = rotate ? natH : natW;
        var visNatH = rotate ? natW : natH;
        var scale = Math.min(boxW / visNatW, boxH / visNatH);
        var visW = visNatW * scale;
        var visH = visNatH * scale;
        return {
            rotate: rotate,
            elW: natW * scale,
            elH: natH * scale,
            visW: visW,
            visH: visH,
            left: (boxW - visW) / 2,
            top: (boxH - visH) / 2
        };
    }

    // Overlay text size from the visible image width: small and steady.
    function overlaySize(visW) {
        return Math.max(11, Math.min(22, visW * 0.0125));
    }

    /*
     * Fill the box's full width, never rotating; when that makes the image
     * taller than the box, equal amounts are clipped off the top and bottom
     * (the box hides the overflow). Used for the Sun and Moon. Returns the
     * same shape as fitImage(), with the visible rectangle clipped to the box.
     */
    function fitWidth(natW, natH, boxW, boxH) {
        var scale = boxW / natW;
        var h = natH * scale;
        var visH = Math.min(h, boxH);
        return {
            rotate: 0,
            elW: boxW,
            elH: h,
            visW: boxW,
            visH: visH,
            left: 0,
            top: (boxH - visH) / 2
        };
    }

    /*
     * Size/rotate img to fill box, and pin the overlay element to the
     * bottom edge of the visible picture. The overlay itself never rotates.
     * opts.fillWidth: use fitWidth() (full width, top/bottom clipped)
     * instead of fitting the whole image, rotated if need be.
     */
    function applyLayout(img, overlayEl, box, clockwise, opts) {
        if (!img.naturalWidth || !img.naturalHeight) return null;
        var boxW = box.clientWidth, boxH = box.clientHeight;
        var L = opts && opts.fillWidth
            ? fitWidth(img.naturalWidth, img.naturalHeight, boxW, boxH)
            : fitImage(img.naturalWidth, img.naturalHeight, boxW, boxH, clockwise);

        img.style.width = L.elW + 'px';
        img.style.height = L.elH + 'px';
        img.style.left = '50%';
        img.style.top = '50%';
        img.style.transform = 'translate(-50%, -50%) rotate(' + L.rotate + 'deg)';

        if (overlayEl) {
            overlayEl.style.left = L.left + 'px';
            overlayEl.style.width = L.visW + 'px';
            overlayEl.style.bottom = (boxH - L.top - L.visH) + 'px';
            overlayEl.style.fontSize = overlaySize(L.visW) + 'px';
        }
        return L;
    }

    function div(cls, text) {
        var d = document.createElement(text === undefined ? 'div' : 'span');
        d.className = cls;
        if (text !== undefined) d.textContent = text;
        return d;
    }

    /*
     * Fill an overlay element. One caption line on the left -- the object
     * name at normal weight, then key / equipment / date in a lighter
     * weight (the separator dots stay at normal weight) -- and the
     * signature on the right.
     * Uses textContent only (no HTML from the database).
     */
    function renderOverlay(el, meta, signature) {
        el.textContent = '';
        var parts = overlayParts(meta);
        var name = meta.name && parts[0] === meta.name ? parts.shift() : '';

        var line = div('ov-line');
        if (name) line.appendChild(div('ov-name', name));
        for (var i = 0; i < parts.length; i++) {
            if (name || i > 0) line.appendChild(div('ov-sep', SEPARATOR));
            line.appendChild(div('ov-det', parts[i]));
        }
        el.appendChild(line);

        var sigText = signature === undefined ? SIGNATURE : signature;
        if (sigText) el.appendChild(div('ov-sig', sigText));
    }

    // ── Canvas version (downloads) ────────────────────────────────────

    // Make sure the caption fonts are ready before drawing on a canvas
    // (a canvas silently falls back to another font if they aren't).
    function loadFonts() {
        if (!document.fonts || !document.fonts.load) return Promise.resolve();
        var C = CAPTION;
        return Promise.all([
            document.fonts.load('italic ' + C.nameWeight + ' 20px "' + C.family + '"'),
            document.fonts.load('italic ' + C.detWeight + ' 20px "' + C.family + '"'),
            document.fonts.load('400 20px "' + C.sigFamily + '"')
        ]).catch(function () { /* draw with whatever is available */ });
    }

    function captionFont(weight, size) {
        return 'italic ' + weight + ' ' + size + 'px "' + CAPTION.family + '", system-ui, sans-serif';
    }

    // Draw the caption and signature along the bottom of a W x H canvas.
    function drawCaption(c, W, H, meta, signature) {
        var C = CAPTION;
        var fs = Math.max(14, Math.round(W * 0.016));
        var padX = 1.3 * fs, padTop = 3 * fs, padBottom = 0.9 * fs;
        var lh = C.lineHeight * fs;
        var spacing = (C.letterSpacing * fs) + 'px';

        var sigText = signature === undefined ? SIGNATURE : signature;
        var sigSize = C.sigScale * fs;
        var sigFont = '400 ' + sigSize + 'px "' + C.sigFamily + '", cursive';
        var sigW = 0;
        if (sigText) { c.font = sigFont; c.letterSpacing = '0px'; sigW = c.measureText(sigText).width; }
        var avail = W - 2 * padX - (sigW ? sigW + 1.2 * fs : 0);

        // Runs: name, then (separator + detail) groups; wrap between groups.
        var parts = overlayParts(meta);
        var name = meta.name && parts[0] === meta.name ? parts.shift() : '';
        var groups = [];
        if (name) groups.push([{ text: name, weight: C.nameWeight, color: C.nameColor }]);
        parts.forEach(function (p, i) {
            var g = [];
            if (name || i > 0) g.push({ text: SEPARATOR, weight: C.sepWeight, color: C.textColor, sep: true });
            g.push({ text: p, weight: C.detWeight, color: C.textColor });
            groups.push(g);
        });

        c.letterSpacing = spacing;
        function width(run) { c.font = captionFont(run.weight, fs); return c.measureText(run.text).width; }

        var lines = [[]], x = 0;
        groups.forEach(function (g) {
            var gw = 0;
            g.forEach(function (r) { r.w = width(r); gw += r.w; });
            if (x > 0 && x + gw > avail) {
                lines.push([]);
                x = 0;
                if (g[0].sep) g = g.slice(1);   // no dot at the start of a line
            }
            g.forEach(function (r) { lines[lines.length - 1].push(r); x += r.w; });
        });

        // Soft dark gradient behind the caption.
        var bandH = padTop + lines.length * lh + padBottom;
        var grad = c.createLinearGradient(0, H, 0, H - bandH);
        grad.addColorStop(0, 'rgba(0, 0, 0, 0.55)');
        grad.addColorStop(1, 'rgba(0, 0, 0, 0)');
        c.fillStyle = grad;
        c.fillRect(0, H - bandH, W, bandH);

        c.save();
        c.shadowColor = 'rgba(0, 0, 0, 0.9)';
        c.shadowBlur = Math.max(2, fs / 8);
        c.shadowOffsetY = Math.max(1, fs / 16);
        c.textBaseline = 'alphabetic';
        c.textAlign = 'left';

        var baseBottom = H - padBottom - 0.3 * fs;
        lines.forEach(function (line, i) {
            var y = baseBottom - (lines.length - 1 - i) * lh;
            var lx = padX;
            line.forEach(function (r) {
                c.font = captionFont(r.weight, fs);
                c.fillStyle = r.color;
                c.fillText(r.text, lx, y);
                lx += r.w;
            });
        });

        if (sigText) {
            c.letterSpacing = '0px';
            c.font = sigFont;
            c.fillStyle = C.sigColor;
            c.textAlign = 'right';
            c.fillText(sigText, W - padX, baseBottom + 0.05 * fs);
        }
        c.restore();
    }

    /*
     * Draw img onto a new canvas, rotated to the requested orientation
     * ('portrait' / 'landscape'; anything else keeps it as is), with the
     * caption when opts.caption is true. Returns the canvas.
     *   opts: { orientation, clockwise, caption, meta, signature }
     */
    function renderCanvas(img, opts) {
        opts = opts || {};
        var w = img.naturalWidth, h = img.naturalHeight;
        var rot = 0;
        if ((opts.orientation === 'portrait' || opts.orientation === 'landscape') && w !== h) {
            var isPortrait = h > w;
            if ((opts.orientation === 'portrait') !== isPortrait) rot = opts.clockwise ? 90 : -90;
        }
        var W = rot ? h : w, H = rot ? w : h;
        var cv = document.createElement('canvas');
        cv.width = W;
        cv.height = H;
        var c = cv.getContext('2d');
        c.save();
        c.translate(W / 2, H / 2);
        c.rotate(rot * Math.PI / 180);
        c.drawImage(img, -w / 2, -h / 2);
        c.restore();
        if (opts.caption && opts.meta) drawCaption(c, W, H, opts.meta, opts.signature);
        return cv;
    }

    global.AstroOverlay = {
        SIGNATURE: SIGNATURE,
        formatDate: formatDate,
        equipmentLabel: equipmentLabel,
        isClockwise: isClockwise,
        metaFor: metaFor,
        signatureFor: signatureFor,
        overlayParts: overlayParts,
        overlayText: overlayText,
        fitImage: fitImage,
        fitWidth: fitWidth,
        overlaySize: overlaySize,
        applyLayout: applyLayout,
        renderOverlay: renderOverlay,
        loadFonts: loadFonts,
        renderCanvas: renderCanvas
    };
})(window);
