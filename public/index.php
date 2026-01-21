<?php
/**
 * Enhanced Astronomy Landing Page
 * Provides options for slideshow or browsing DSO gallery
 */

$dirFull = __DIR__ . '/images/annotated_full';
$dirWall = __DIR__ . '/images/annotated_wallpaper';
$dirFav = __DIR__ . '/images/fav';
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

// Load DSO information
$dsoInfoPath = __DIR__ . '/dso_watchlist_info.json';
$dsoInfo = [];
if (file_exists($dsoInfoPath)) {
    $dsoInfo = json_decode(file_get_contents($dsoInfoPath), true) ?: [];
}

$fullImages = gatherImages($dirFull, 'images/annotated_full', $extensions);
$wallImages = gatherImages($dirWall, 'images/annotated_wallpaper', $extensions);
$favImages = gatherImages($dirFav, 'images/fav', $extensions);

/**
 * Extract DSO name from filename (new convention: scientific name at start, terminated by underscore)
 * Examples: 
 *   "M1_20250113_annotated_full.jpg" -> "M1"
 *   "NGC7000_20250113_annotated_wallpaper.jpg" -> "NGC7000"
 *   "SH2-308_20250113_annotated_full.jpg" -> "SH2-308"
 */
function extractDSOName($filename) {
    $name = pathinfo($filename, PATHINFO_FILENAME);
    
    // Extract everything before the first underscore
    $parts = explode('_', $name);
    if (count($parts) > 0) {
        $dsoName = strtoupper(trim($parts[0]));
        // Clean up common variations
        $dsoName = str_replace(' ', '', $dsoName);
        return $dsoName;
    }
    
    return null;
}

/**
 * Look up DSO information with "See" redirection support
 * If the entry has a "See" field, follow it to get the actual info
 */
function getDSOInfo($dsoKey, $dsoInfo) {
    if (!$dsoKey || !isset($dsoInfo[$dsoKey])) {
        return null;
    }
    
    $entry = $dsoInfo[$dsoKey];
    
    // Check if this entry has a "See" field (redirect to another entry)
    if (isset($entry['See']) && !empty($entry['See'])) {
        $redirectKey = $entry['See'];
        if (isset($dsoInfo[$redirectKey])) {
            return $dsoInfo[$redirectKey];
        }
    }
    
    // Check if this entry has actual content (not just a redirect)
    // An entry with content should have more than just CommonName and See
    if (count($entry) > 2 || (count($entry) === 2 && !isset($entry['See']))) {
        return $entry;
    }
    
    return null;
}

// Create gallery data
$galleryItems = [];
foreach ($fullImages as $imgPath) {
    $filename = basename($imgPath);
    $dsoKey = extractDSOName($filename);
    $fullPath = $imgPath;

    // Find corresponding wallpaper image - replace both directory and filename
    $wallpaperPath = str_replace('images/annotated_full', 'images/annotated_wallpaper', $imgPath);
    $wallpaperPath = str_replace('_full_annotated', '_wall_annotated', $wallpaperPath);
    $favPath = str_replace('images/annotated_full', 'images/fav', $imgPath);
    $favPath = str_replace('_full_annotated', '_fav', $favPath);
    
    // Create display name from filename
//    $displayName = pathinfo($filename, PATHINFO_FILENAME);
//    $displayName = str_replace('_annotated_full', '', $displayName);
//    $displayName = str_replace('_annotated_wallpaper', '', $displayName);
//    $displayName = str_replace('_full', '', $displayName);
//    $displayName = str_replace('_wallpaper', '', $displayName);
//    $displayName = str_replace('_', ' ', $displayName);
//    $displayName = ucwords(strtolower($displayName));
    
    // Look up info with "See" redirection support
    $info = getDSOInfo($dsoKey, $dsoInfo);
    
    // If we have info and a CommonName, use that for display
    if ($info && isset($info['CommonName'])) {
        $displayName = $info['CommonName'];
    } else {
        $displayName = 'Unknown';
    }
    
    $galleryItems[] = [
        'filename' => $filename,
        'fullPath' => $fullPath,
        'favPath' => $favPath,
        'wallpaperPath' => $wallpaperPath,
        'displayName' => $displayName,
        'dsoKey' => $dsoKey,
        'info' => $info
    ];
}

usort($galleryItems, function($a, $b) {
    return strcmp($a['displayName'], $b['displayName']);
});

$fullJson = json_encode($fullImages);
$wallJson = json_encode($wallImages);
$galleryJson = json_encode($galleryItems);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <title>Astronomy Gallery</title>
    <link rel="icon" type="image/png" href="/images/favicon.png">

    <link rel="stylesheet" href="/css/style.css?ver=1">
    <link rel="stylesheet" type="text/css" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
</head>
<body>
<div class="landing-page" id="landingPage">
    <div class="landing-header">
        <h1>🌌 Deep Sky Gallery</h1>
        <p>Explore the hidden wonders of the night sky</p>
    </div>
    <div class="options-container">
        <div class="option-card" onclick="showSlideshow()">
            <div class="option-icon">🎬</div>
            <h2>Slideshow</h2>
            <p>Sit back and enjoy an automated tour through stunning deep sky images</p>
        </div>
        <div class="option-card" onclick="showGallery()">
            <div class="option-icon">🔭</div>
            <h2>Browse Gallery</h2>
            <p>Explore individual objects with detailed information and high-resolution images</p>
        </div>
    </div>
</div>
<div class="slideshow-container" id="slideshowContainer">
    <button class="back-btn" onclick="backToLanding()" aria-label="Back to menu" type="button">
        <svg viewBox="0 0 24 24"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z" fill="white"/></svg>
    </button>
    <div id="slideshow" aria-live="polite">
        <button id="prevBtn" class="arrow-btn arrow-left" aria-label="Previous image" type="button">
            <img src="images/left-arrow.png" alt="Previous">
        </button>
        <img id="slide" src="" alt="Slideshow image">
        <button id="nextBtn" class="arrow-btn arrow-right" aria-label="Next image" type="button">
            <img src="images/right-arrow.png" alt="Next">
        </button>
        <button id="playPauseBtn" class="play-pause-btn" aria-label="Pause or resume slideshow" type="button">
            <svg id="pauseIcon" viewBox="0 0 24 24"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"></path></svg>
            <svg id="playIcon" class="hidden" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M5.23331,0.493645 C6.8801,-0.113331 8.6808,-0.161915 10.3579,0.355379 C11.4019,0.6773972 12.361984,1.20757325 13.1838415,1.90671757 L13.4526,2.14597 L14.2929,1.30564 C14.8955087,0.703065739 15.9071843,1.0850774 15.994017,1.89911843 L16,2.01275 L16,6.00002 L12.0127,6.00002 C11.1605348,6.00002 10.7153321,5.01450817 11.2294893,4.37749065 L11.3056,4.29291 L12.0372,3.56137 C11.389,2.97184 10.6156,2.52782 9.76845,2.26653 C8.5106,1.87856 7.16008,1.915 5.92498,2.37023 C4.68989,2.82547 3.63877,3.67423 2.93361,4.78573 C2.22844,5.89723 1.90836,7.20978 2.02268,8.52112 C2.13701,9.83246 2.6794,11.0698 3.56627,12.0425 C4.45315,13.0152 5.63528,13.6693 6.93052,13.9039 C8.22576,14.1385 9.56221,13.9407 10.7339,13.3409 C11.9057,12.7412 12.8476,11.7727 13.4147,10.5848 C13.6526,10.0864 14.2495,9.8752 14.748,10.1131 C15.2464,10.351 15.4575,10.948 15.2196,11.4464 C14.4635,13.0302 13.2076,14.3215 11.6453,15.1213 C10.0829,15.921 8.30101,16.1847 6.57402,15.8719 C4.84704,15.559 3.27086,14.687 2.08836,13.39 C0.905861,12.0931 0.182675,10.4433 0.0302394,8.69483 C-0.122195,6.94637 0.304581,5.1963 1.2448,3.7143 C2.18503,2.2323 3.58652,1.10062 5.23331,0.493645 Z M6,5.46077 C6,5.09472714 6.37499031,4.86235811 6.69509872,5.0000726 L6.7678,5.03853 L10.7714,7.57776 C11.0528545,7.75626909 11.0784413,8.14585256 10.8481603,8.36273881 L10.7714,8.42224 L6.7678,10.9615 C6.45867857,11.1575214 6.06160816,10.965274 6.00646097,10.6211914 L6,10.5392 L6,5.46077 Z"/></svg>
        </button>
    </div>
</div>
<div class="gallery-container" id="galleryContainer">
    <div class="gallery-header">
        <h1>🔭 Deep Sky Objects</h1>
        <button class="gallery-back-btn" title="Home" onclick="backToLanding()"><i class="fa-solid fa-house"></i></button>
    </div>
    <div class="gallery-grid" id="galleryGrid"></div>
</div>
<div class="modal" id="modal">
    <button class="modal-close" onclick="closeModal()"><i class="fa-solid fa-arrow-left"></i></button>
    <div class="modal-content">
        <img class="modal-image" id="modalImage" src="" alt="">
        <div class="modal-info" id="modalInfo"></div>
    </div>
</div>
<!--<script src="https://kit.fontawesome.com/12c5ce46e9.js" crossorigin="anonymous"></script>-->
<script>
    const fullImages=<?php echo $fullJson;?>;
    const wallImages=<?php echo $wallJson;?>;
    const galleryData=<?php echo $galleryJson;?>;
    const FULL_KEY='slideshow_full',WALL_KEY='slideshow_wall';
    let activeList=[],currentIndex=0,autoAdvanceTimer=null,isPaused=false;
    const AUTO_ADVANCE_DELAY=5000;
    const slideImg=document.getElementById('slide'),prevBtn=document.getElementById('prevBtn'),nextBtn=document.getElementById('nextBtn'),playPauseBtn=document.getElementById('playPauseBtn'),pauseIcon=document.getElementById('pauseIcon'),playIcon=document.getElementById('playIcon');
    function showSlideshow(){document.getElementById('landingPage').style.display='none';document.getElementById('slideshowContainer').classList.add('active');chooseListByOrientation();}
    function showGallery(){document.getElementById('landingPage').style.display='none';document.getElementById('galleryContainer').classList.add('active');renderGallery();}
    function backToLanding(){document.getElementById('slideshowContainer').classList.remove('active');document.getElementById('galleryContainer').classList.remove('active');document.getElementById('landingPage').style.display='flex';stopAutoAdvance();}

    function renderGallery() {
        const grid = document.getElementById('galleryGrid');
        grid.innerHTML = '';
        galleryData.forEach((item, idx) => {
            const card = document.createElement('div');
            card.className = 'gallery-item';
            card.onclick = () => openModal(idx);
            const img = document.createElement('img');
            img.src = item.favPath;
            img.alt = item.displayName;
            img.loading = 'lazy';
            const info = document.createElement('div');
            info.className = 'gallery-item-info';
            const title = document.createElement('h3');
            title.textContent = item.displayName;
            const subtitle = document.createElement('p');
            // if (item.info && item.info.Constellation) {
            //     subtitle.textContent = 'Constellation ' + item.info.Constellation;
            // } else if (item.dsoKey) {
            //     subtitle.textContent = item.dsoKey;
            // }
            info.appendChild(title);
            // info.appendChild(subtitle);
            card.appendChild(img);
            card.appendChild(info);
            grid.appendChild(card);
        });
    }

    function openModal(idx) {
        const item = galleryData[idx];
        const modal = document.getElementById('modal');
        const modalImage = document.getElementById('modalImage');
        const modalInfo = document.getElementById('modalInfo');
        const isLandscape = window.innerWidth > window.innerHeight;
        const imageSrc = isLandscape && item.wallpaperPath ? item.wallpaperPath : item.fullPath;
        modalImage.src = imageSrc;
        modalImage.alt = item.displayName;
        const titleText = item.info && item.info.CommonName ? item.info.CommonName : item.displayName;
        let h = `<div class="modal-header"><h2>${titleText}</h2></div>`;
        if (item.info) {
            const i = item.info;
            if (i.OtherNames && i.OtherNames.length > 0) h += `<div class="info-section"><h3>Also Known As</h3> <p>${i.OtherNames.join(', ')}</p></div>`;
            if (i.Constellation) h += `<div class="info-section"><h3>Constellation</h3> <p>${i.Constellation}</p></div>`;
            if (i.Type) h += `<div class="info-section"><h3>Type</h3> <p>${i.Type}</p></div>`;
            if (i.Distance) h += `<div class="info-section"><h3>Distance</h3> <p>${i.Distance}</p></div>`;
            if (i.Size) h += `<div class="info-section"><h3>Size</h3> <p>${i.Size}</p></div>`;
            if (i.Composition) h += `<div class="info-section"><h3>Composition</h3> <p>${i.Composition}</p></div>`;
            if (i.FunFacts && i.FunFacts.length > 0) {
                h += `<div class="info-section fun-facts"><h3>Fun Facts</h3><ul>`;
                i.FunFacts.forEach(f => h += `<li>${f}</li>`);
                h += `</ul></div>`;
            }
        } else {
            h += `<p class="no-info">No information found for this object.</p>`;
        }
        modalInfo.innerHTML = h;
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    function closeModal(){document.getElementById('modal').classList.remove('active');document.body.style.overflow='';}
    document.getElementById('modal').addEventListener('click',e=>{if(e.target.id==='modal')closeModal();});
    document.addEventListener('keydown',e=>{if(e.key==='Escape')closeModal();});
    function shuffleArray(a){const r=a.slice();for(let i=r.length-1;i>0;i--){const j=Math.floor(Math.random()*(i+1));[r[i],r[j]]=[r[j],r[i]];}return r;}
    function loadShuffled(k,o){if(!o||!o.length)return[];try{const s=sessionStorage.getItem(k);if(s){const p=JSON.parse(s);if(Array.isArray(p)&&p.length===o.length)return p;}}catch(e){}const sh=shuffleArray(o);try{sessionStorage.setItem(k,JSON.stringify(sh));}catch(e){}return sh;}
    function chooseListByOrientation(){const isP=window.innerHeight>window.innerWidth;const cO=isP?fullImages:wallImages;const fO=isP?wallImages:fullImages;const cK=isP?FULL_KEY:WALL_KEY;const fK=isP?WALL_KEY:FULL_KEY;let list=loadShuffled(cK,cO);if(!list.length)list=loadShuffled(fK,fO);const pS=activeList.length?activeList[currentIndex]:null;activeList=list;if(pS){const idx=activeList.indexOf(pS);currentIndex=idx>=0?idx:0;}else{currentIndex=0;}updateControls();showImage();resetAutoAdvance();}
    function showImage(){if(!activeList.length){slideImg.src='';slideImg.alt='No images available';return;}slideImg.src=activeList[currentIndex];slideImg.alt=`Image ${currentIndex+1} of ${activeList.length}`;}
    function nextImage(){if(activeList.length<2)return;currentIndex=(currentIndex+1)%activeList.length;showImage();resetAutoAdvance();}
    function prevImage(){if(activeList.length<2)return;currentIndex=(currentIndex-1+activeList.length)%activeList.length;showImage();resetAutoAdvance();}
    function updateControls(){const hasM=activeList&&activeList.length>1;playPauseBtn.classList.toggle('hidden',!hasM);prevBtn.classList.toggle('hidden',!isPaused||!hasM);nextBtn.classList.toggle('hidden',!isPaused||!hasM);}
    function stopAutoAdvance(){if(autoAdvanceTimer)clearTimeout(autoAdvanceTimer);autoAdvanceTimer=null;}
    function resetAutoAdvance(){stopAutoAdvance();if(!isPaused&&activeList.length>1){autoAdvanceTimer=setTimeout(nextImage,AUTO_ADVANCE_DELAY);}}
    function togglePlayPause(){isPaused=!isPaused;if(isPaused){stopAutoAdvance();}else{resetAutoAdvance();}updatePlayPauseButton();updateControls();}
    function updatePlayPauseButton(){pauseIcon.classList.toggle('hidden',isPaused);playIcon.classList.toggle('hidden',!isPaused);playPauseBtn.setAttribute('aria-label',isPaused?'Resume slideshow':'Pause slideshow');}
    prevBtn.addEventListener('click',prevImage);
    nextBtn.addEventListener('click',nextImage);
    playPauseBtn.addEventListener('click',togglePlayPause);
    document.addEventListener('keydown',e=>{if(e.key==='ArrowRight')nextImage();else if(e.key==='ArrowLeft')prevImage();else if(e.key===' '){e.preventDefault();togglePlayPause();}});
    window.addEventListener('resize',chooseListByOrientation);
    window.addEventListener('orientationchange',()=>setTimeout(chooseListByOrientation,120));
    document.addEventListener('visibilitychange',()=>{if(document.hidden){stopAutoAdvance();}else{resetAutoAdvance();}});
    updatePlayPauseButton();
</script>
</body>
</html>
