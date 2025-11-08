<?php
// Prevent aggressive caching of the generated HTML/JS so deployments take effect immediately.
// Send conservative cache headers and an ETag based on this script's contents.
if (!headers_sent()) {
    header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    // Add an ETag to help intermediate caches validate the response quickly.
    if (is_readable(__FILE__)) {
        $etag = '"' . md5_file(__FILE__) . '"';
        header('ETag: ' . $etag);
    }
}

// public/index_prev.php
$dirFull = __DIR__ . '/annotated_full';
$dirWall = __DIR__ . '/annotated_wallpaper';
$extensions = ['jpg','jpeg','png','gif','webp'];

function gatherImages($dir, $prefix, $extensions) {
    $images = [];
    if (!is_dir($dir)) return $images;
    $files = scandir($dir);
    foreach ($files as $f) {
        if ($f === '.' || $f === '..') continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (in_array($ext, $extensions, true)) {
            $images[] = $prefix . '/' . $f;
        }
    }
    sort($images, SORT_NATURAL|SORT_FLAG_CASE);
    return array_values($images);
}

$fullImages = gatherImages($dirFull, 'annotated_full', $extensions);
$wallImages = gatherImages($dirWall, 'annotated_wallpaper', $extensions);

if (empty($fullImages) && empty($wallImages)) {
    echo '<!DOCTYPE html><html><body>No images found in `annotated_full` or `annotated_wallpaper`.</body></html>';
    exit;
}

$fullJson = json_encode($fullImages);
$wallJson = json_encode($wallImages);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <title>Slideshow</title>
    <style>
        html,body { height:100%; margin:0; background:black; -webkit-touch-callout:none; -webkit-user-select:none; user-select:none; }
        #slideshow { position:relative; width:100%; height:100%; display:flex; align-items:center; justify-content:center; background:black; overflow:hidden; touch-action: none}

        /* Fix: use max-width/max-height so images never exceed viewport and keep bottom annotations visible */
        #slide {
            max-width: 100%;
            max-height: 100%;
            width: auto;
            height: auto;
            object-fit: contain;           /* scale to fit without cropping */
            object-position: center bottom;/* keep bottom aligned so annotation stays visible */
            display: block;
            background: black;
            z-index: 1;
            pointer-events: none;
        }

        /* small, discreet light-gray filled triangles inside circular translucent button */
        .arrow-btn {
            position:absolute;
            top:50%;
            transform:translateY(-50%);
            width:calc(6vh + 6px);
            height:calc(6vh + 6px);
            display:flex;
            align-items:center;
            justify-content:center;
            background:rgba(0,0,0,0.18);
            border-radius:50%;
            cursor:pointer;
            z-index:100;
            transition:background .12s, transform .06s, opacity .12s;
            touch-action: manipulation;
            -webkit-tap-highlight-color: transparent;
            border: none;
            padding:0;
        }
        .arrow-btn:active { transform:translateY(-50%) scale(.98); }
        .arrow-left { left: calc(3.5vw + 4px); }
        .arrow-right { right: calc(3.5vw + 4px); }

        .arrow-btn svg { width:60%; height:60%; fill: rgba(211,211,211,0.95); filter: drop-shadow(0 1px 1px rgba(0,0,0,0.6)); }

        @media (min-width:768px) {
            .arrow-btn { width:calc(7vh + 8px); height:calc(7vh + 8px); }
            .arrow-left { left: calc(2.0vw + 4px); }
            .arrow-right { right: calc(2.0vw + 4px); }
        }

        .arrow-btn.hidden { display:none; }
    </style>
</head>
<body>
<div id="slideshow" aria-live="polite">
    <button id="prevBtn" class="arrow-btn arrow-left" aria-label="Previous image" type="button">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z"/>
        </svg>
    </button>

    <img id="slide" src="" alt="Slideshow image">

    <button id="nextBtn" class="arrow-btn arrow-right" aria-label="Next image" type="button">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6z"/>
        </svg>
    </button>
</div>

<script>
    const fullImages = <?php echo $fullJson; ?>;
    const wallImages = <?php echo $wallJson; ?>;
    const FULL_KEY = 'slideshow_full';
    const WALL_KEY = 'slideshow_wall';

    let activeList = [];
    let currentIndex = 0;
    const slideImg = document.getElementById('slide');
    const prevBtn = document.getElementById('prevBtn');
    const nextBtn = document.getElementById('nextBtn');

    // Fisher-Yates shuffle
    function shuffleArray(arr) {
        const a = arr.slice();
        for (let i = a.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [a[i], a[j]] = [a[j], a[i]];
        }
        return a;
    }

    // Load shuffled list from sessionStorage or create and store one
    function loadShuffled(key, original) {
        if (!original || !original.length) return [];
        try {
            const stored = sessionStorage.getItem(key);
            if (stored) {
                const parsed = JSON.parse(stored);
                if (Array.isArray(parsed) && parsed.length === original.length) {
                    return parsed;
                }
            }
        } catch (e) {
            // ignore parse errors and fall back to reshuffle
        }
        const shuffled = shuffleArray(original);
        try { sessionStorage.setItem(key, JSON.stringify(shuffled)); } catch (e) { /* ignore storage errors */ }
        return shuffled;
    }

    function chooseListByOrientation() {
        const isPortrait = window.innerHeight > window.innerWidth;
        // prefer portrait -> full, landscape -> wall
        const candidateOriginal = isPortrait ? fullImages : wallImages;
        const fallbackOriginal = isPortrait ? wallImages : fullImages;
        const candidateKey = isPortrait ? FULL_KEY : WALL_KEY;
        const fallbackKey = isPortrait ? WALL_KEY : FULL_KEY;

        let list = loadShuffled(candidateKey, candidateOriginal);
        if (!list.length) list = loadShuffled(fallbackKey, fallbackOriginal);

        if (!list.length) {
            slideImg.src = '';
            slideImg.alt = 'No images available';
            prevBtn.classList.add('hidden');
            nextBtn.classList.add('hidden');
            activeList = [];
            currentIndex = 0;
            return;
        }

        const prevSrc = activeList.length ? activeList[currentIndex] : null;
        activeList = list;
        if (prevSrc) {
            const idx = activeList.indexOf(prevSrc);
            currentIndex = idx >= 0 ? idx : 0;
        } else {
            currentIndex = 0;
        }
        updateControls();
        showImage();
        adjustArrows();
    }

    function addNoCache(url) {
        const sep = url.includes('?') ? '&' : '?';
        return url + sep + '_=' + Date.now();
    }

    function showImage() {
        if (!activeList || !activeList.length) return;
        // append cache-busting query so browser (and intermediate caches) re-fetch each time
        slideImg.src = addNoCache(activeList[currentIndex]);
        slideImg.alt = 'Image ' + (currentIndex + 1) + ' of ' + activeList.length;
    }

    function nextImage() {
        if (!activeList || !activeList.length) return;
        currentIndex = (currentIndex + 1) % activeList.length;
        showImage();
    }
    function prevImage() {
        if (!activeList || !activeList.length) return;
        currentIndex = (currentIndex - 1 + activeList.length) % activeList.length;
        showImage();
    }

    function updateControls() {
        if (!activeList || activeList.length < 2) {
            prevBtn.classList.add('hidden');
            nextBtn.classList.add('hidden');
        } else {
            prevBtn.classList.remove('hidden');
            nextBtn.classList.remove('hidden');
        }
    }

    // Faster, unified pointer handling with de-duplication to avoid delayed or double events.
    let _lastAction = 0;
    function invokeAction(fn) {
        const now = Date.now();
        if (now - _lastAction < 350) return;
        _lastAction = now;
        fn();
    }
    if (window.PointerEvent) {
        nextBtn.addEventListener('pointerdown', (e) => { if (e.pointerType !== 'mouse') e.preventDefault(); invokeAction(nextImage); }, {passive:false});
        prevBtn.addEventListener('pointerdown', (e) => { if (e.pointerType !== 'mouse') e.preventDefault(); invokeAction(prevImage); }, {passive:false});
    } else {
        // fallback for older WebKit: touchstart + click
        nextBtn.addEventListener('touchstart', (e) => { e.preventDefault(); invokeAction(nextImage); }, {passive:false});
        prevBtn.addEventListener('touchstart', (e) => { e.preventDefault(); invokeAction(prevImage); }, {passive:false});
    }
    // still allow click for desktop; de-duplication prevents double firing on touch+click
    nextBtn.addEventListener('click', () => invokeAction(nextImage));
    prevBtn.addEventListener('click', () => invokeAction(prevImage));

    document.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowRight') nextImage();
        else if (e.key === 'ArrowLeft') prevImage();
    });

    function adjustArrows() {
        if (window.innerWidth < 420) {
            document.querySelector('.arrow-left').style.left = '4.5vw';
            document.querySelector('.arrow-right').style.right = '4.5vw';
        } else {
            document.querySelector('.arrow-left').style.left = '';
            document.querySelector('.arrow-right').style.right = '';
        }
    }

    window.addEventListener('resize', chooseListByOrientation);
    window.addEventListener('orientationchange', () => setTimeout(chooseListByOrientation, 120));

    chooseListByOrientation();
</script>
</body>
</html>
