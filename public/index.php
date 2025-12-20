<?php
$dirFull = __DIR__ . '/annotated_full';
$dirWall = __DIR__ . '/annotated_wallpaper';
$extensions = ['jpg','jpeg','png','gif','webp'];

function gatherImages($dir, $prefix, $extensions) {
    $images = [];
    if (!is_dir($dir)) return $images;
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (in_array($ext, $extensions, true)) {
            $images[] = $prefix . '/' . $f;
        }
    }
    sort($images, SORT_NATURAL | SORT_FLAG_CASE);
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
        html, body {
            height: 100%; margin: 0; background: black;
            -webkit-touch-callout: none; -webkit-user-select: none; user-select: none;
        }
        #slideshow {
            position: relative; width: 100%; height: 100%;
            display: flex; align-items: center; justify-content: center;
            background: black; overflow: hidden;
        }
        #slide {
            max-width: 100%; max-height: 100%;
            width: auto; height: auto;
            object-fit: contain; object-position: center;
            display: block;
        }
        .arrow-btn, .play-pause-btn {
            position: absolute;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(0,0,0,0.18);
            border-radius: 50%;
            cursor: pointer;
            z-index: 100;
            transition: background .12s, transform .06s;
            touch-action: manipulation;
            -webkit-tap-highlight-color: transparent;
            border: none;
            padding: 0;
            pointer-events: auto;
        }
        .arrow-btn {
            top: 90%;
            transform: translateY(-50%);
            width: calc(8vh + 8px);
            height: calc(8vh + 8px);
        }
        .arrow-btn:active {
            transform: translateY(-50%) scale(.98);
            background: rgba(0,0,0,0.4);
        }
        .arrow-left { left: calc(1.5vw + 2px); }
        .arrow-right { right: calc(1.5vw + 2px); }

        .arrow-btn img {
            width: 60%; height: 60%;
            object-fit: contain;
            pointer-events: none;
        }
        .play-pause-btn {
            bottom: 3vh;
            left: 50%;
            transform: translateX(-50%);
            width: calc(5vh + 5px);
            height: calc(5vh + 5px);
        }
        .play-pause-btn:active {
            transform: translateX(-50%) scale(.98);
            background: rgba(0,0,0,0.4);
        }
        .play-pause-btn svg {
            width: 50%;
            height: 50%;
            fill: white;
            pointer-events: none;
        }
        .hidden { display: none; }

        @media (min-width:768px) {
            .arrow-btn {
                width: calc(9vh + 10px);
                height: calc(9vh + 10px);
            }
            .arrow-left { left: calc(1.0vw + 2px); }
            .arrow-right { right: calc(1.0vw + 2px); }
            .play-pause-btn {
                width: calc(6vh + 6px);
                height: calc(6vh + 6px);
            }
        }
    </style>
</head>
<body>
<div id="slideshow" aria-live="polite">
    <button id="prevBtn" class="arrow-btn arrow-left" aria-label="Previous image" type="button">
        <img src="left-arrow.png" alt="Previous">
    </button>
    <img id="slide" src="" alt="Slideshow image">
    <button id="nextBtn" class="arrow-btn arrow-right" aria-label="Next image" type="button">
        <img src="right-arrow.png" alt="Next">
    </button>
    <button id="playPauseBtn" class="play-pause-btn" aria-label="Pause or resume slideshow" type="button">
        <svg id="pauseIcon" viewBox="0 0 24 24"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"></path></svg>
        <svg id="playIcon" class="hidden" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"></path></svg>
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
    let isPaused = false;
    const AUTO_ADVANCE_DELAY = 5000;

    const slideImg = document.getElementById('slide');
    const prevBtn = document.getElementById('prevBtn');
    const nextBtn = document.getElementById('nextBtn');
    const playPauseBtn = document.getElementById('playPauseBtn');
    const pauseIcon = document.getElementById('pauseIcon');
    const playIcon = document.getElementById('playIcon');

    function shuffleArray(arr) {
        const a = arr.slice();
        for (let i = a.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [a[i], a[j]] = [a[j], a[i]];
        }
        return a;
    }

    function loadShuffled(key, original) {
        if (!original || !original.length) return [];
        try {
            const stored = sessionStorage.getItem(key);
            if (stored) {
                const parsed = JSON.parse(stored);
                if (Array.isArray(parsed) && parsed.length === original.length) return parsed;
            }
        } catch (e) { /* ignore */ }
        const shuffled = shuffleArray(original);
        try { sessionStorage.setItem(key, JSON.stringify(shuffled)); } catch (e) { /* ignore */ }
        return shuffled;
    }

    function chooseListByOrientation() {
        const isPortrait = window.innerHeight > window.innerWidth;
        const candidateOriginal = isPortrait ? fullImages : wallImages;
        const fallbackOriginal = isPortrait ? wallImages : fullImages;
        const candidateKey = isPortrait ? FULL_KEY : WALL_KEY;
        const fallbackKey = isPortrait ? WALL_KEY : FULL_KEY;

        let list = loadShuffled(candidateKey, candidateOriginal);
        if (!list.length) list = loadShuffled(fallbackKey, fallbackOriginal);

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
        resetAutoAdvance();
    }

    function showImage() {
        if (!activeList.length) {
            slideImg.src = '';
            slideImg.alt = 'No images available';
            return;
        }
        slideImg.src = activeList[currentIndex];
        slideImg.alt = `Image ${currentIndex + 1} of ${activeList.length}`;
    }

    function nextImage() {
        if (activeList.length < 2) return;
        currentIndex = (currentIndex + 1) % activeList.length;
        showImage();
        resetAutoAdvance();
    }

    function prevImage() {
        if (activeList.length < 2) return;
        currentIndex = (currentIndex - 1 + activeList.length) % activeList.length;
        showImage();
        resetAutoAdvance();
    }

    function updateControls() {
        const show = activeList && activeList.length > 1;
        prevBtn.classList.toggle('hidden', !show);
        nextBtn.classList.toggle('hidden', !show);
        playPauseBtn.classList.toggle('hidden', !show);
    }

    function stopAutoAdvance() {
        if (autoAdvanceTimer) clearTimeout(autoAdvanceTimer);
        autoAdvanceTimer = null;
    }

    function resetAutoAdvance() {
        stopAutoAdvance();
        if (!isPaused && activeList.length > 1) {
            autoAdvanceTimer = setTimeout(nextImage, AUTO_ADVANCE_DELAY);
        }
    }

    function togglePlayPause() {
        isPaused = !isPaused;
        if (isPaused) {
            stopAutoAdvance();
        } else {
            resetAutoAdvance();
        }
        updatePlayPauseButton();
    }

    function updatePlayPauseButton() {
        pauseIcon.classList.toggle('hidden', isPaused);
        playIcon.classList.toggle('hidden', !isPaused);
        playPauseBtn.setAttribute('aria-label', isPaused ? 'Resume slideshow' : 'Pause slideshow');
    }

    prevBtn.addEventListener('click', prevImage);
    nextBtn.addEventListener('click', nextImage);
    playPauseBtn.addEventListener('click', togglePlayPause);

    document.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowRight') nextImage();
        else if (e.key === 'ArrowLeft') prevImage();
        else if (e.key === ' ') {
            e.preventDefault();
            togglePlayPause();
        }
    });

    window.addEventListener('resize', chooseListByOrientation);
    window.addEventListener('orientationchange', () => setTimeout(chooseListByOrientation, 120));

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
