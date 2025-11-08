<?php
// public/index.php
// Simple slideshow: no cache-busting, no shuffling, no sessionStorage.

// directories and allowed extensions
$dirFull = __DIR__ . '/annotated_full';
$dirWall = __DIR__ . '/annotated_wallpaper';
$extensions = ['jpg','jpeg','png','gif','webp'];

function gatherImages($dir, $webPrefix): array
{
    $out = [];
    if (!is_dir($dir)) return $out;
    $files = scandir($dir);
    foreach ($files as $f) {
        if ($f === '.' || $f === '..') continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if ($ext != 'jpg') continue;
        // URL-encode filename portion
        $out[] = rtrim($webPrefix, '/') . '/' . rawurlencode($f);
    }
    // keep server-side deterministic ordering
//    sort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values($out);
}

$fullImages = gatherImages($dirFull, 'annotated_full');
$wallImages = gatherImages($dirWall, 'annotated_wallpaper');

if (empty($fullImages) && empty($wallImages)) {
    echo '<!DOCTYPE html><html><body>No images found in `annotated_full` or `annotated_wallpaper`.</body></html>';
    exit;
}

$fullJson = json_encode($fullImages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$wallJson = json_encode($wallImages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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

            /* Keep images within viewport and bottom-aligned */
            #slide {
                max-width:100%;
                max-height:100%;
                width:auto;
                height:auto;
                object-fit:contain;
                object-position:center bottom;
                display:block;
                background:black;
                z-index:1;
                pointer-events:none; /* don't intercept button clicks */
            }

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
                -webkit-tap-highlight-color: transparent;
                border:none;
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
                <path d="M15.5 5l-9 7 9 7V5z"/>
            </svg>
        </button>

        <img id="slide" src="" alt="Slideshow image">

        <button id="nextBtn" class="arrow-btn arrow-right" aria-label="Next image" type="button">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M8.5 5v14l9-7-9-7z"/>
            </svg>
        </button>
    </div>

    <script>
        const fullImages = <?php echo $fullJson ?? '[]'; ?>;
        const wallImages = <?php echo $wallJson ?? '[]'; ?>;

        const FULL_KEY = 'slideshow_full';
        const WALL_KEY = 'slideshow_wall';

        let activeList = [];
        let currentIndex = 0;
        const slideImg = document.getElementById('slide');
        const prevBtn = document.getElementById('prevBtn');
        const nextBtn = document.getElementById('nextBtn');

        function chooseListByOrientation() {
            const isPortrait = window.innerHeight > window.innerWidth;
            let list = isPortrait ? fullImages.slice() : wallImages.slice();
            if (!list || !list.length) list = isPortrait ? wallImages.slice() : fullImages.slice();

            if (!list || !list.length) {
                slideImg.src = '';
                slideImg.alt = 'No images available';
                prevBtn.classList.add('hidden');
                nextBtn.classList.add('hidden');
                activeList = [];
                currentIndex = 0;
                return;
            }

            activeList = list;
            currentIndex = 0;
            updateControls();
            showImage();
            adjustArrows();
        }

        function showImage() {
            if (!activeList || !activeList.length) return;
            slideImg.src = activeList[currentIndex];
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

        // Plain click handlers only (no touch/pointer handlers)
        nextBtn.addEventListener('click', nextImage);
        prevBtn.addEventListener('click', prevImage);

        // document.addEventListener('keydown', (e) => {
        //     if (e.key === 'ArrowRight') nextImage();
        //     else if (e.key === 'ArrowLeft') prevImage();
        // });

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
<?php
