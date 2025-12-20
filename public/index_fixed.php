<?php
// Prevent aggressive caching of the generated HTML/JS so deployments take effect immediately.
//if (!headers_sent()) {
//    header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
//    header('Pragma: no-cache');
//    header('Expires: 0');
//    if (is_readable(__FILE__)) {
//        $etag = '"' . md5_file(__FILE__) . '"';
//        header('ETag: ' . $etag);
//    }
//}

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
        #slideshow { position:relative; width:100%; height:100%; display:flex; align-items:center; justify-content:center; background:black; overflow:hidden; }

        #slide {
            max-width: 100%;
            max-height: 100%;
            width: auto;
            height: auto;
            object-fit: contain;
            object-position: center bottom;
            display: block;
            background: black;
        }

        .arrow-btn {
            position:absolute;
            top:50%;
            transform:translateY(-50%);
            width:calc(10vh + 10px);
            height:calc(10vh + 10px);
            display:flex;
            align-items:center;
            justify-content:center;
            background:rgba(0,0,0,0.18);
            border-radius:50%;
            cursor:pointer;
            z-index:9999;
            transition:background .12s, transform .06s, opacity .12s;
            touch-action: manipulation;
            -webkit-tap-highlight-color: transparent;
            border: none;
            padding:0;
            pointer-events: auto;
        }
        .arrow-btn:active { transform:translateY(-50%) scale(.98); }
        .arrow-left { left: calc(3.5vw + 4px); }
        .arrow-right { right: calc(3.5vw + 4px); }

        .arrow-btn img { width:60%; height:60%; object-fit:contain; }

        @media (min-width:768px) {
            .arrow-btn { width:calc(11vh + 12px); height:calc(11vh + 12px); }
            .arrow-left { left: calc(2.0vw + 4px); }
            .arrow-right { right: calc(2.0vw + 4px); }
        }

        .arrow-btn.hidden { display:none; }
    </style>
</head>
<body>
<div id="slideshow" aria-live="polite">
    <button id="prevBtn" class="arrow-btn arrow-left" aria-label="Previous image" type="button">
        <img src="left-arrow.png" alt="" aria-hidden="true">
    </button>

    <img id="slide" src="" alt="Slideshow image">

    <button id="nextBtn" class="arrow-btn arrow-right" aria-label="Next image" type="button">
        <img src="right-arrow.png" alt="" aria-hidden="true">
    </button>
</div>

<script>
    const fullImages = <?php echo $fullJson; ?>;
    const wallImages = <?php echo $wallJson; ?>;
    const FULL_KEY = 'slideshow_full';
    const WALL_KEY = 'slideshow_wall';

    let activeList = [];
    let currentIndex = 0;
    let autoAdvanceTimer = null;
    const AUTO_ADVANCE_DELAY = 5000; // 5 seconds
    
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
        // Portrait -> full, Landscape -> wall
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
            stopAutoAdvance();
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
        resetAutoAdvance();
    }

    function addNoCache(url) {
        return url
        const sep = url.includes('?') ? '&' : '?';
        return url + sep + '_=' + Date.now();
    }

    function showImage() {
        if (!activeList || !activeList.length) return;
        slideImg.src = addNoCache(activeList[currentIndex]);
        slideImg.alt = 'Image ' + (currentIndex + 1) + ' of ' + activeList.length;
    }

    function nextImage() {
        if (!activeList || !activeList.length) return;
        currentIndex = (currentIndex + 1) % activeList.length;
        showImage();
        resetAutoAdvance();
    }
    
    function prevImage() {
        if (!activeList || !activeList.length) return;
        currentIndex = (currentIndex - 1 + activeList.length) % activeList.length;
        showImage();
        resetAutoAdvance();
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

    // Auto-advance functions
    function stopAutoAdvance() {
        if (autoAdvanceTimer) {
            clearTimeout(autoAdvanceTimer);
            autoAdvanceTimer = null;
        }
    }

    function resetAutoAdvance() {
        stopAutoAdvance();
        if (activeList && activeList.length > 1) {
            autoAdvanceTimer = setTimeout(() => {
                nextImage();
            }, AUTO_ADVANCE_DELAY);
        }
    }

    // Simple click handlers only - this is what works!
    nextBtn.addEventListener('click', nextImage);
    prevBtn.addEventListener('click', prevImage);

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

    // Pause auto-advance when page is hidden, resume when visible
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            stopAutoAdvance();
        } else {
            resetAutoAdvance();
        }
    });

    chooseListByOrientation();
</script>
</body>
</html>
