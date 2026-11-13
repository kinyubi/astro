<?php
/**
 * New gallery app: landing tiles, Slideshow (phase 1), Browse Gallery
 * (phase 2), Solar System (phase 3).
 *
 * Lives at /slideshow/ alongside the legacy app (public/index.php), which is
 * untouched. Uses only the plain _full / _fav images; captions are drawn in
 * the browser by overlay.js (on screen, and into downloads). Other
 * Astrophotography is a later phase.
 *
 * Routes (URL hash): ""            menu
 *                    #slideshow    slideshow
 *                    #gallery      gallery grid
 *                    #gallery/<k>  viewer for card <k> (e.g. p12)
 *                    #solar        Solar System grid
 *                    #solar/<k>    viewer for a solar object (e.g. solar-moon)
 */
require_once __DIR__ . '/data.php';

$jflags    = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;
$equipment = ss_equipment_names();
$json      = json_encode(ss_load_cards(), $jflags);
$solarJson = json_encode(ss_load_solar($equipment), $jflags);
$equipJson = json_encode((object)$equipment, $jflags);
$ver       = 9;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <title>Deep Sky Gallery</title>
    <link rel="icon" type="image/png" href="../images/favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Herr+Von+Muellerhoff&family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&display=swap">
    <link rel="stylesheet" href="slideshow.css?ver=<?= $ver ?>">
    <link rel="stylesheet" href="gallery.css?ver=<?= $ver ?>">
</head>
<body>

<main class="home" id="home">
    <header class="home-header">
        <h1>Deep Sky Gallery</h1>
        <p>Explore the hidden wonders of the night sky</p>
    </header>
    <nav class="tiles">
        <a class="tile" href="#slideshow">
            <span class="tile-icon" aria-hidden="true">&#127916;</span>
            <h2>Slideshow</h2>
            <p>Sit back and enjoy an automated tour of the deep sky objects I have photographed.</p>
        </a>
        <a class="tile" href="#gallery">
            <span class="tile-icon" aria-hidden="true">&#128301;</span>
            <h2>Browse Gallery</h2>
            <p>Read about, view close up or download any image in my gallery.</p>
        </a>
        <a class="tile" href="#solar">
            <span class="tile-icon" aria-hidden="true">&#9728;&#65039;</span>
            <h2>Solar System</h2>
            <p>The Sun, the Moon and the planets.</p>
        </a>
        <div class="tile soon" aria-disabled="true">
            <span class="tile-icon" aria-hidden="true">&#127756;</span>
            <h2>Other Astrophotography</h2>
            <p>Favorite images by other astrophotographers.</p>
            <span class="soon-tag">Coming soon</span>
        </div>
        <a class="tile" href="../blog-manager/">
            <span class="tile-icon" aria-hidden="true">&#128221;</span>
            <h2>Blog</h2>
            <p>Articles and stories about astrophotography techniques, equipment, and the objects I've captured.</p>
        </a>
    </nav>
</main>

<!-- ── Slideshow ─────────────────────────────────────────────────────── -->
<section class="slideshow" id="slideshow" aria-label="Slideshow">
    <div class="ss-stage" id="ss-stage">
        <img id="ss-img" class="ss-img" alt="">
        <div id="ss-overlay" class="ss-overlay" aria-live="polite"></div>
    </div>
    <p class="ss-empty" id="ss-empty" hidden>No images available.</p>

    <div class="ss-ctl ss-top-left">
        <button class="ss-btn" id="ss-back" type="button" aria-label="Back to menu" title="Back (Esc)">
            <svg viewBox="0 0 24 24"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/></svg>
        </button>
    </div>
    <div class="ss-ctl ss-top-right">
        <span class="ss-counter" id="ss-counter"></span>
        <button class="ss-btn" id="ss-feat" type="button" aria-label="Showing featured images only" title="Featured images only (A)">
            <svg class="ic-feat" viewBox="0 0 24 24"><path d="M12 17.27 18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>
            <svg class="ic-all" viewBox="0 0 24 24"><path d="M4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6zm16-4H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H8V4h12v12z"/></svg>
        </button>
        <button class="ss-btn" id="ss-info" type="button" aria-label="Show or hide caption" title="Caption (I)">
            <svg viewBox="0 0 24 24"><path d="M11 7h2v2h-2zm0 4h2v6h-2zm1-9C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8z"/></svg>
        </button>
        <button class="ss-btn" id="ss-play" type="button" aria-label="Pause slideshow" title="Pause (Space)">
            <svg class="ic-pause" viewBox="0 0 24 24"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>
            <svg class="ic-play" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
        </button>
        <button class="ss-btn" id="ss-full" type="button" aria-label="Full screen" title="Full screen (F)">
            <svg viewBox="0 0 24 24"><path d="M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z"/></svg>
        </button>
    </div>
    <button class="ss-ctl ss-arrow ss-arrow-left" id="ss-prev" type="button" aria-label="Previous image" title="Previous">
        <svg viewBox="0 0 24 24"><path d="M15.41 7.41 14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg>
    </button>
    <button class="ss-ctl ss-arrow ss-arrow-right" id="ss-next" type="button" aria-label="Next image" title="Next">
        <svg viewBox="0 0 24 24"><path d="M8.59 16.59 10 18l6-6-6-6-1.41 1.41L13.17 12z"/></svg>
    </button>
</section>

<!-- ── Browse Gallery: grid ──────────────────────────────────────────── -->
<section class="gallery" id="gallery" aria-label="Browse Gallery" hidden>
    <header class="g-head">
        <a class="g-back" id="g-home" href="./" aria-label="Back to menu" title="Back (Esc)">
            <svg viewBox="0 0 24 24"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/></svg>
        </a>
        <h1>Browse Gallery</h1>
        <input class="g-search" id="g-search" type="search" placeholder="Search by name or catalog ID" autocomplete="off" aria-label="Search the gallery">
    </header>
    <div class="g-grid" id="g-grid"></div>
    <p class="g-none" id="g-none" hidden>No matching objects.</p>
</section>

<!-- ── Solar System: grid (uses the same viewer) ─────────────────────── -->
<section class="gallery" id="solar" aria-label="Solar System" hidden>
    <header class="g-head">
        <a class="g-back" id="s-home" href="./" aria-label="Back to menu" title="Back (Esc)">
            <svg viewBox="0 0 24 24"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/></svg>
        </a>
        <h1>Solar System</h1>
    </header>
    <div class="g-grid" id="s-grid"></div>
</section>

<!-- ── Browse Gallery: image viewer ──────────────────────────────────── -->
<div class="gm" id="gm" role="dialog" aria-modal="true" aria-label="Image viewer" hidden>
    <div class="gm-stage" id="gm-stage">
        <img id="gm-img" class="gm-img" alt="">
        <div id="gm-overlay" class="ss-overlay"></div>

        <div class="gm-ctl gm-top-left">
            <button class="ss-btn" id="gm-close" type="button" aria-label="Back to gallery" title="Back to gallery (Esc)">
                <svg viewBox="0 0 24 24"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/></svg>
            </button>
        </div>
        <span class="gm-counter" id="gm-counter"></span>
        <div class="gm-ctl gm-top-right">
            <button class="ss-btn" id="gm-caption" type="button" aria-label="Show or hide caption" title="Caption (I)">
                <svg viewBox="0 0 24 24"><path d="M11 7h2v2h-2zm0 4h2v6h-2zm1-9C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8z"/></svg>
            </button>
            <button class="ss-btn" id="gm-download" type="button" aria-label="Download" title="Download (D)">
                <svg viewBox="0 0 24 24"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>
            </button>
        </div>
        <div class="gm-menu" id="gm-menu" hidden>
            <h4>With caption</h4>
            <button type="button" data-kind="captioned" data-shape="portrait">Portrait<span class="hint">9:16</span></button>
            <button type="button" data-kind="captioned" data-shape="landscape">Landscape<span class="hint">16:9</span></button>
            <button type="button" data-kind="captioned" data-shape="square">Square<span class="hint">4:5</span></button>
            <h4>Plain</h4>
            <button type="button" data-kind="plain" data-shape="portrait">Portrait<span class="hint">9:16</span></button>
            <button type="button" data-kind="plain" data-shape="landscape">Landscape<span class="hint">16:9</span></button>
            <button type="button" data-kind="plain" data-shape="square">Square<span class="hint">4:5</span></button>
        </div>

        <button class="gm-ctl ss-arrow ss-arrow-left" id="gm-prev" type="button" aria-label="Previous image" title="Previous">
            <svg viewBox="0 0 24 24"><path d="M15.41 7.41 14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg>
        </button>
        <button class="gm-ctl ss-arrow ss-arrow-right" id="gm-next" type="button" aria-label="Next image" title="Next">
            <svg viewBox="0 0 24 24"><path d="M8.59 16.59 10 18l6-6-6-6-1.41 1.41L13.17 12z"/></svg>
        </button>
        <button class="gm-more" id="gm-more" type="button" aria-label="Scroll to object details" title="Details">
            <svg viewBox="0 0 24 24"><path d="M16.59 8.59 12 13.17 7.41 8.59 6 10l6 6 6-6z"/></svg>
        </button>
    </div>
    <div class="gm-strip" id="gm-strip"></div>
    <div class="gm-imgline" id="gm-imgline"></div>
    <div class="gm-info" id="gm-info"></div>
</div>

<script>
window.GALLERY_CARDS   = <?= $json ?>;
window.SOLAR_CARDS     = <?= $solarJson ?>;
window.EQUIPMENT_NAMES = <?= $equipJson ?>;
</script>
<script src="overlay.js?ver=<?= $ver ?>"></script>
<script src="slideshow.js?ver=<?= $ver ?>"></script>
<script src="gallery.js?ver=<?= $ver ?>"></script>
<script>
(function () {
    'use strict';
    var homeEl = document.getElementById('home');
    var current = null;

    Slideshow.init(window.GALLERY_CARDS || []);
    Gallery.init(window.GALLERY_CARDS || [], window.SOLAR_CARDS || []);

    // Hash routing so the browser Back button works (see header comment).
    function route() {
        var parts = location.hash.replace('#', '').split('/');
        var view = parts[0];

        if (current === 'slideshow' && view !== 'slideshow') Slideshow.stop();
        homeEl.hidden = (view === 'slideshow' || view === 'gallery' || view === 'solar');

        if (view === 'slideshow' && current !== 'slideshow') Slideshow.start();

        if (view === 'gallery' || view === 'solar') {
            Gallery.show(view);
            if (parts[1]) Gallery.open(decodeURIComponent(parts[1]));
            else Gallery.close();
        } else {
            Gallery.hide();
        }
        if (view !== current) window.scrollTo(0, 0);
        current = view;
    }

    window.SlideshowApp = {
        home: function () {
            if (location.hash) history.pushState(null, '', location.pathname + location.search);
            route();
        }
    };
    document.getElementById('ss-back').addEventListener('click', window.SlideshowApp.home);
    window.addEventListener('hashchange', route);
    window.addEventListener('popstate', route);
    route();
})();
</script>
</body>
</html>
