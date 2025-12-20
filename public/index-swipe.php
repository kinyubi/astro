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
                height: 100%;
                margin: 0;
                background: black;
                -webkit-touch-callout: none;
                -webkit-user-select: none;
                user-select: none;
            }
            #slideshow {
                position: relative;
                width: 100%;
                height: 100%;
                display: flex;
                align-items: center;
                justify-content: center;
                background: black;
                overflow: hidden;
                touch-action: manipulation;
            }
            #slide {
                max-width: 100%;
                max-height: 100%;
                width: auto;
                height: auto;
                object-fit: contain;
                object-position: center;
                display: block;
                background: black;
            }
        </style>
    </head>
    <body>
    <div id="slideshow" aria-live="polite">
        <img id="slide" src="" alt="Slideshow image">
    </div>

    <script>
        const fullImages = <?php echo $fullJson; ?>;
        const wallImages = <?php echo $wallJson; ?>;
        const FULL_KEY = 'slideshow_full';
        const WALL_KEY = 'slideshow_wall';

        let activeList = [];
        let currentIndex = 0;
        let autoAdvanceTimer = null;
        const AUTO_ADVANCE_DELAY = 5000;
        const SWIPE_THRESHOLD = 10;

        const slideImg = document.getElementById('slide');
        const slideshow = document.getElementById('slideshow');

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
                    if (Array.isArray(parsed) && parsed.length === original.length) {
                        return parsed;
                    }
                }
            } catch (_) {}
            const shuffled = shuffleArray(original);
            try { sessionStorage.setItem(key, JSON.stringify(shuffled)); } catch (_) {}
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

            if (!list.length) {
                slideImg.src = '';
                slideImg.alt = 'No images available';
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

            showImage();
            resetAutoAdvance();
        }

        function showImage() {
            if (!activeList.length) return;
            slideImg.src = activeList[currentIndex];
            slideImg.alt = 'Image ' + (currentIndex + 1) + ' of ' + activeList.length;
        }

        function nextImage() {
            if (!activeList.length) return;
            currentIndex = (currentIndex + 1) % activeList.length;
            showImage();
            resetAutoAdvance();
        }

        function prevImage() {
            if (!activeList.length) return;
            currentIndex = (currentIndex - 1 + activeList.length) % activeList.length;
            showImage();
            resetAutoAdvance();
        }

        function stopAutoAdvance() {
            if (autoAdvanceTimer) {
                clearTimeout(autoAdvanceTimer);
                autoAdvanceTimer = null;
            }
        }

        function resetAutoAdvance() {
            stopAutoAdvance();
            if (activeList.length > 1) {
                autoAdvanceTimer = setTimeout(nextImage, AUTO_ADVANCE_DELAY);
            }
        }

        document.addEventListener('keydown', (e) => {
            if (e.target.isContentEditable) return;
            const tag = e.target.tagName;
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;

            if (e.key === 'ArrowRight' || e.key === 'Right') {
                e.preventDefault();
                nextImage();
            } else if (e.key === 'ArrowLeft' || e.key === 'Left') {
                e.preventDefault();
                prevImage();
            }
        });

        let touchStartX = 0;
        let touchStartY = 0;
        let touchCurrentX = 0;
        let touchCurrentY = 0;
        let touchActive = false;

        slideshow.addEventListener('touchstart', (event) => {
            if (event.touches.length !== 1) return;
            const touch = event.touches[0];
            touchActive = true;
            touchStartX = touchCurrentX = touch.clientX;
            touchStartY = touchCurrentY = touch.clientY;
            stopAutoAdvance();
        }, { passive: true });

        slideshow.addEventListener('touchmove', (event) => {
            if (!touchActive) return;
            const touch = event.touches[0];
            touchCurrentX = touch.clientX;
            touchCurrentY = touch.clientY;
        }, { passive: true });

        function handleSwipeEnd() {
            if (!touchActive) {
                resetAutoAdvance();
                return;
            }
            const deltaX = touchCurrentX - touchStartX;
            const deltaY = touchCurrentY - touchStartY;
            touchActive = false;

            if (Math.abs(deltaX) > Math.abs(deltaY) && Math.abs(deltaX) > SWIPE_THRESHOLD) {
                if (deltaX < 0) {
                    nextImage();
                } else {
                    prevImage();
                }
            } else {
                resetAutoAdvance();
            }
        }

        slideshow.addEventListener('touchend', handleSwipeEnd);
        slideshow.addEventListener('touchcancel', handleSwipeEnd);

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
<?php
