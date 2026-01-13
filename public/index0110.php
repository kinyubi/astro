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
            top: 90%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: calc(5vh + 5px);
            height: calc(5vh + 5px);
        }
        .play-pause-btn:active {
            transform: translate(-50%, -50%) scale(.98);
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
        <svg id="playIcon" class="hidden" viewBox="0 0 16 16">
            <path fill-rule="evenodd" d="M5.23331,0.493645 C6.8801,-0.113331 8.6808,-0.161915 10.3579,0.355379 C11.4019,0.6773972 12.361984,1.20757325 13.1838415,1.90671757 L13.4526,2.14597 L14.2929,1.30564 C14.8955087,0.703065739 15.9071843,1.0850774 15.994017,1.89911843 L16,2.01275 L16,6.00002 L12.0127,6.00002 C11.1605348,6.00002 10.7153321,5.01450817 11.2294893,4.37749065 L11.3056,4.29291 L12.0372,3.56137 C11.389,2.97184 10.6156,2.52782 9.76845,2.26653 C8.5106,1.87856 7.16008,1.915 5.92498,2.37023 C4.68989,2.82547 3.63877,3.67423 2.93361,4.78573 C2.22844,5.89723 1.90836,7.20978 2.02268,8.52112 C2.13701,9.83246 2.6794,11.0698 3.56627,12.0425 C4.45315,13.0152 5.63528,13.6693 6.93052,13.9039 C8.22576,14.1385 9.56221,13.9407 10.7339,13.3409 C11.9057,12.7412 12.8476,11.7727 13.4147,10.5848 C13.6526,10.0864 14.2495,9.8752 14.748,10.1131 C15.2464,10.351 15.4575,10.948 15.2196,11.4464 C14.4635,13.0302 13.2076,14.3215 11.6453,15.1213 C10.0829,15.921 8.30101,16.1847 6.57402,15.8719 C4.84704,15.559 3.27086,14.687 2.08836,13.39 C0.905861,12.0931 0.182675,10.4433 0.0302394,8.69483 C-0.122195,6.94637 0.304581,5.1963 1.2448,3.7143 C2.18503,2.2323 3.58652,1.10062 5.23331,0.493645 Z M6,5.46077 C6,5.09472714 6.37499031,4.86235811 6.69509872,5.0000726 L6.7678,5.03853 L10.7714,7.57776 C11.0528545,7.75626909 11.0784413,8.14585256 10.8481603,8.36273881 L10.7714,8.42224 L6.7678,10.9615 C6.45867857,11.1575214 6.06160816,10.965274 6.00646097,10.6211914 L6,10.5392 L6,5.46077 Z"/>
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
    let autoAdvanceTimer = null;
    let isPaused = false; // Start in a playing state
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
        const hasMultipleImages = activeList && activeList.length > 1;
        // Show play/pause if there are multiple images
        playPauseBtn.classList.toggle('hidden', !hasMultipleImages);
        // Show arrows only if paused and there are multiple images
        prevBtn.classList.toggle('hidden', !isPaused || !hasMultipleImages);
        nextBtn.classList.toggle('hidden', !isPaused || !hasMultipleImages);
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
        updateControls(); // Update arrow visibility
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
    updatePlayPauseButton(); // Set initial button icon
</script>
</body>
</html>
