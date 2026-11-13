/*
 * slideshow.js -- the Slideshow view of the new gallery app.
 *
 * Plays every visible gallery image's plain _full file in the hand-ranked
 * gallery order, with the overlay drawn on top (overlay.js). On a landscape
 * screen the portrait image is rotated 90 degrees to fill it.
 *
 * By default only each card's featured image is shown; the star button (or
 * A) switches to every image. The choice is remembered.
 *
 * Keys: Right/Left = next/previous, Space = pause/play, I = overlay on/off,
 * A = featured only / all images, F = fullscreen, Esc = back to the menu.
 */
(function (global) {
    'use strict';

    var DELAY = 5000;   // ms each slide stays up
    var FADE  = 350;    // ms, matches the CSS opacity transition
    var IDLE  = 3000;   // ms before controls and cursor hide

    var O = global.AstroOverlay;
    var cards = [];
    var featuredOnly = true;
    var slides = [];
    var idx = 0, paused = false, running = false;
    var timer = null, idleTimer = null, fadeTimer = null;
    var overlayOn = true;
    var failures = 0;

    var el = {};

    // Featured only: one image per card -- the featured one (always sorted
    // first), or the first image with a _full file if that one has none.
    function buildSlides() {
        var out = [];
        cards.forEach(function (card) {
            var taken = false;
            card.images.forEach(function (im) {
                if (!im.hasFull || (featuredOnly && taken)) return;
                taken = true;
                out.push({
                    src: im.full,
                    clockwise: O.isClockwise(im.base),
                    meta: O.metaFor(card, im),
                    signature: O.signatureFor(im)
                });
            });
        });
        return out;
    }

    function layout() {
        if (!slides.length) return;
        O.applyLayout(el.img, el.overlay, el.stage, slides[idx].clockwise);
    }

    function show(i) {
        if (!slides.length) return;
        idx = (i + slides.length) % slides.length;
        var s = slides[idx];
        clearTimeout(timer);
        clearTimeout(fadeTimer);
        el.root.classList.add('fading');
        fadeTimer = setTimeout(function () {
            el.img.onload = function () {
                failures = 0;
                O.renderOverlay(el.overlay, s.meta, s.signature);
                layout();
                el.root.classList.remove('fading');
                el.counter.textContent = (idx + 1) + ' / ' + slides.length;
                schedule();
                preload(idx + 1);
            };
            el.img.onerror = function () {
                // Missing or broken file: skip it (but never loop forever).
                if (++failures < slides.length) show(idx + 1);
            };
            el.img.alt = s.meta.name || '';
            el.img.src = s.src;
        }, el.img.getAttribute('src') ? FADE : 0);
    }

    function preload(i) {
        if (slides.length < 2) return;
        var p = new Image();
        p.src = slides[(i + slides.length) % slides.length].src;
    }

    function schedule() {
        clearTimeout(timer);
        if (running && !paused && slides.length > 1) {
            timer = setTimeout(function () { show(idx + 1); }, DELAY);
        }
    }

    function next() { if (slides.length > 1) show(idx + 1); }
    function prev() { if (slides.length > 1) show(idx - 1); }

    function setPaused(p) {
        paused = p;
        el.root.classList.toggle('paused', paused);
        el.playBtn.setAttribute('aria-label', paused ? 'Play slideshow' : 'Pause slideshow');
        el.playBtn.title = paused ? 'Play (Space)' : 'Pause (Space)';
        schedule();
    }

    function toggleOverlay() {
        overlayOn = !overlayOn;
        el.root.classList.toggle('no-overlay', !overlayOn);
        try { localStorage.setItem('ss.overlay', overlayOn ? '1' : '0'); } catch (e) { /* ignore */ }
    }

    function setFeaturedOnly(on) {
        featuredOnly = on;
        try { localStorage.setItem('ss.featuredOnly', on ? '1' : '0'); } catch (e) { /* ignore */ }
        el.root.classList.toggle('all-images', !on);
        el.featBtn.setAttribute('aria-label', on ? 'Showing featured images only' : 'Showing all images');
        el.featBtn.title = on ? 'Featured images only (A)' : 'All images (A)';
    }

    // Switch featured/all, staying on the current picture if it's still in.
    function toggleFeatured() {
        var cur = slides.length ? slides[idx].src : null;
        setFeaturedOnly(!featuredOnly);
        slides = buildSlides();
        if (!running) return;
        var at = 0;
        for (var i = 0; i < slides.length; i++) {
            if (slides[i].src === cur) { at = i; break; }
        }
        if (slides.length && slides[at].src === cur) {
            idx = at;
            el.counter.textContent = (idx + 1) + ' / ' + slides.length;
            schedule();
        } else if (slides.length) {
            show(at);
        }
    }

    function toggleFullscreen() {
        var d = document;
        if (d.fullscreenElement) {
            if (d.exitFullscreen) d.exitFullscreen();
        } else if (el.root.requestFullscreen) {
            el.root.requestFullscreen().catch(function () { /* not allowed */ });
        }
    }

    function wake() {
        el.root.classList.remove('idle');
        clearTimeout(idleTimer);
        idleTimer = setTimeout(function () { el.root.classList.add('idle'); }, IDLE);
    }

    function onKey(e) {
        if (!running) return;
        switch (e.key) {
            case 'ArrowRight': next(); break;
            case 'ArrowLeft':  prev(); break;
            case ' ':          e.preventDefault(); setPaused(!paused); break;
            case 'i': case 'I': toggleOverlay(); break;
            case 'a': case 'A': toggleFeatured(); break;
            case 'f': case 'F': toggleFullscreen(); break;
            case 'Escape':
                if (!document.fullscreenElement && global.SlideshowApp && global.SlideshowApp.home) {
                    global.SlideshowApp.home();
                }
                break;
            default: return;
        }
        wake();
    }

    var touchX = 0, touchY = 0;
    function onTouchStart(e) {
        touchX = e.touches[0].clientX;
        touchY = e.touches[0].clientY;
    }
    function onTouchEnd(e) {
        var dx = e.changedTouches[0].clientX - touchX;
        var dy = e.changedTouches[0].clientY - touchY;
        if (Math.abs(dx) >= 40 && Math.abs(dx) > Math.abs(dy) * 1.5) {
            if (dx < 0) next(); else prev();
        }
        wake();
    }

    var resizeTimer = null;
    function onResize() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(layout, 120);
    }

    function init(cardData) {
        el.root    = document.getElementById('slideshow');
        el.stage   = document.getElementById('ss-stage');
        el.img     = document.getElementById('ss-img');
        el.overlay = document.getElementById('ss-overlay');
        el.counter = document.getElementById('ss-counter');
        el.playBtn = document.getElementById('ss-play');
        el.empty   = document.getElementById('ss-empty');
        el.featBtn = document.getElementById('ss-feat');

        cards = cardData || [];
        try { featuredOnly = localStorage.getItem('ss.featuredOnly') !== '0'; } catch (e) { featuredOnly = true; }
        setFeaturedOnly(featuredOnly);
        slides = buildSlides();

        try { overlayOn = localStorage.getItem('ss.overlay') !== '0'; } catch (e) { overlayOn = true; }
        el.root.classList.toggle('no-overlay', !overlayOn);

        document.getElementById('ss-prev').addEventListener('click', function () { prev(); wake(); });
        document.getElementById('ss-next').addEventListener('click', function () { next(); wake(); });
        el.playBtn.addEventListener('click', function () { setPaused(!paused); wake(); });
        document.getElementById('ss-info').addEventListener('click', function () { toggleOverlay(); wake(); });
        el.featBtn.addEventListener('click', function () { toggleFeatured(); wake(); });
        document.getElementById('ss-full').addEventListener('click', function () { toggleFullscreen(); wake(); });

        el.root.addEventListener('mousemove', wake);
        el.root.addEventListener('click', wake);
        el.stage.addEventListener('touchstart', onTouchStart, { passive: true });
        el.stage.addEventListener('touchend', onTouchEnd, { passive: true });
        document.addEventListener('keydown', onKey);
        window.addEventListener('resize', onResize);
        window.addEventListener('orientationchange', function () { setTimeout(layout, 120); });
        document.addEventListener('fullscreenchange', function () { setTimeout(layout, 50); });
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) clearTimeout(timer); else schedule();
        });
    }

    // Every visit starts at the #1 card, playing.
    function start() {
        running = true;
        el.root.classList.add('active');
        el.empty.hidden = slides.length > 0;
        setPaused(false);
        wake();
        el.img.removeAttribute('src');
        if (slides.length) show(0);
    }

    function stop() {
        running = false;
        clearTimeout(timer);
        clearTimeout(fadeTimer);
        clearTimeout(idleTimer);
        el.root.classList.remove('active');
        if (document.fullscreenElement && document.exitFullscreen) document.exitFullscreen();
    }

    global.Slideshow = { init: init, start: start, stop: stop };
})(window);
