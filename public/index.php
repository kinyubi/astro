<?php
/**
 * Enhanced Astronomy Landing Page
 * Provides options for slideshow or browsing DSO gallery
 */

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

// Load DSO information
$dsoInfoPath = __DIR__ . '/dso_watchlist_info.json';
$dsoInfo = [];
if (file_exists($dsoInfoPath)) {
    $dsoInfo = json_decode(file_get_contents($dsoInfoPath), true) ?: [];
}

$fullImages = gatherImages($dirFull, 'annotated_full', $extensions);
$wallImages = gatherImages($dirWall, 'annotated_wallpaper', $extensions);

// Map images to DSO catalog numbers
function extractDSOName($filename) {
    $name = pathinfo($filename, PATHINFO_FILENAME);
    $name = str_replace('_full_annotated', '', $name);
    $name = str_replace('_wallpaper_annotated', '', $name);

    if (preg_match('/_(m\d+)_/i', $name, $matches)) return strtoupper($matches[1]);
    if (preg_match('/_(ngc\d+)_/i', $name, $matches)) return strtoupper($matches[1]);
    if (preg_match('/_(ic\d+)_/i', $name, $matches)) return strtoupper($matches[1]);
    if (preg_match('/_(sh2-\d+)_/i', $name, $matches)) return strtoupper($matches[1]);

    return null;
}

// Create gallery data
$galleryItems = [];
foreach ($fullImages as $imgPath) {
    $filename = basename($imgPath);
    $dsoKey = extractDSOName($filename);

    $displayName = str_replace('_', ' ', pathinfo($filename, PATHINFO_FILENAME));
    $displayName = str_replace(' full annotated', '', $displayName);
    $displayName = ucwords($displayName);

    $info = ($dsoKey && isset($dsoInfo[$dsoKey])) ? $dsoInfo[$dsoKey] : null;

    $galleryItems[] = [
        'filename' => $filename,
        'path' => $imgPath,
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
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { height: 100%; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #0a0e27; color: #e0e0e0; }
        .landing-page { min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 20px; }
        .landing-header { text-align: center; margin-bottom: 60px; }
        .landing-header h1 { font-size: 3em; color: #4a9eff; margin-bottom: 10px; text-shadow: 0 0 20px rgba(74, 158, 255, 0.3); }
        .landing-header p { font-size: 1.2em; color: #b8c5d6; }
        .options-container { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 30px; max-width: 800px; width: 100%; }
        .option-card { background: linear-gradient(145deg, #1a1f3a, #2a3f5f); border: 2px solid #4a9eff; border-radius: 15px; padding: 40px 30px; text-align: center; cursor: pointer; transition: all 0.3s ease; position: relative; overflow: hidden; }
        .option-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: linear-gradient(145deg, rgba(74, 158, 255, 0.1), transparent); opacity: 0; transition: opacity 0.3s ease; }
        .option-card:hover { transform: translateY(-5px); box-shadow: 0 10px 30px rgba(74, 158, 255, 0.3); border-color: #7ec8ff; }
        .option-card:hover::before { opacity: 1; }
        .option-icon { font-size: 4em; margin-bottom: 20px; }
        .option-card h2 { color: #4a9eff; font-size: 1.8em; margin-bottom: 15px; }
        .option-card p { color: #b8c5d6; line-height: 1.6; }
        .slideshow-container { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: black; }
        .slideshow-container.active { display: block; }
        #slideshow { position: relative; width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; overflow: hidden; }
        #slide { max-width: 100%; max-height: 100%; width: auto; height: auto; object-fit: contain; object-position: center; display: block; }
        .arrow-btn, .play-pause-btn, .back-btn { position: absolute; display: flex; align-items: center; justify-content: center; background: rgba(0,0,0,0.18); border-radius: 50%; cursor: pointer; z-index: 100; transition: background .12s, transform .06s; border: none; padding: 0; }
        .arrow-btn { top: 90%; transform: translateY(-50%); width: calc(8vh + 8px); height: calc(8vh + 8px); }
        .arrow-btn:active { transform: translateY(-50%) scale(.98); background: rgba(0,0,0,0.4); }
        .arrow-left { left: calc(1.5vw + 2px); }
        .arrow-right { right: calc(1.5vw + 2px); }
        .arrow-btn img { width: 60%; height: 60%; object-fit: contain; }
        .play-pause-btn { top: 90%; left: 50%; transform: translate(-50%, -50%); width: calc(5vh + 5px); height: calc(5vh + 5px); }
        .play-pause-btn:active { transform: translate(-50%, -50%) scale(.98); background: rgba(0,0,0,0.4); }
        .play-pause-btn svg { width: 50%; height: 50%; fill: white; }
        .back-btn { top: 20px; left: 20px; width: 50px; height: 50px; background: rgba(74, 158, 255, 0.3); border: 2px solid #4a9eff; }
        .back-btn:hover { background: rgba(74, 158, 255, 0.5); }
        .back-btn svg { width: 50%; height: 50%; fill: white; }
        .gallery-container { display: none; min-height: 100vh; padding: 20px; }
        .gallery-container.active { display: block; }
        .gallery-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; padding: 20px; background: #1a1f3a; border-radius: 10px; }
        .gallery-header h1 { color: #4a9eff; font-size: 2em; }
        .gallery-back-btn { background: #4a9eff; color: white; border: none; padding: 12px 24px; border-radius: 8px; font-size: 1em; cursor: pointer; transition: all 0.3s ease; }
        .gallery-back-btn:hover { background: #7ec8ff; transform: translateY(-2px); }
        .gallery-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 25px; max-width: 1400px; margin: 0 auto; }
        .gallery-item { background: #1a1f3a; border-radius: 10px; overflow: hidden; cursor: pointer; transition: all 0.3s ease; border: 2px solid transparent; }
        .gallery-item:hover { transform: translateY(-5px); border-color: #4a9eff; box-shadow: 0 10px 30px rgba(74, 158, 255, 0.3); }
        .gallery-item img { width: 100%; height: 200px; object-fit: cover; display: block; }
        .gallery-item-info { padding: 15px; }
        .gallery-item-info h3 { color: #4a9eff; font-size: 1.1em; margin-bottom: 5px; }
        .gallery-item-info p { color: #b8c5d6; font-size: 0.9em; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.95); z-index: 1000; overflow-y: auto; }
        .modal.active { display: flex; align-items: flex-start; justify-content: center; padding: 20px; }
        .modal-content { background: #1a1f3a; border-radius: 15px; max-width: 1200px; width: 100%; margin: 40px auto; position: relative; }
        .modal-close { position: absolute; top: 15px; right: 15px; background: #4a9eff; color: white; border: none; width: 40px; height: 40px; border-radius: 50%; font-size: 24px; cursor: pointer; z-index: 10; transition: all 0.3s ease; }
        .modal-close:hover { background: #7ec8ff; transform: rotate(90deg); }
        .modal-image { width: 100%; border-radius: 15px 15px 0 0; display: block; }
        .modal-info { padding: 30px; }
        .modal-info h2 { color: #4a9eff; font-size: 2em; margin-bottom: 20px; }
        .info-section { margin-bottom: 20px; }
        .info-section h3 { color: #7ec8ff; font-size: 1.3em; margin-bottom: 10px; }
        .info-section p, .info-section ul { color: #e0e0e0; line-height: 1.8; font-size: 1.05em; }
        .info-section ul { list-style-position: inside; padding-left: 20px; }
        .info-section li { margin-bottom: 8px; }
        .hidden { display: none; }
        @media (max-width: 768px) {
            .landing-header h1 { font-size: 2em; }
            .options-container { grid-template-columns: 1fr; }
            .gallery-grid { grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px; }
            .modal-content { margin: 0; }
        }
    </style>
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
            <img src="left-arrow.png" alt="Previous">
        </button>
        <img id="slide" src="" alt="Slideshow image">
        <button id="nextBtn" class="arrow-btn arrow-right" aria-label="Next image" type="button">
            <img src="right-arrow.png" alt="Next">
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
        <button class="gallery-back-btn" onclick="backToLanding()">← Back to Menu</button>
    </div>
    <div class="gallery-grid" id="galleryGrid"></div>
</div>
<div class="modal" id="modal">
    <div class="modal-content">
        <button class="modal-close" onclick="closeModal()">×</button>
        <img class="modal-image" id="modalImage" src="" alt="">
        <div class="modal-info" id="modalInfo"></div>
    </div>
</div>
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
    function renderGallery(){const grid=document.getElementById('galleryGrid');grid.innerHTML='';galleryData.forEach((item,idx)=>{const card=document.createElement('div');card.className='gallery-item';card.onclick=()=>openModal(idx);const img=document.createElement('img');img.src=item.path;img.alt=item.displayName;img.loading='lazy';const info=document.createElement('div');info.className='gallery-item-info';const title=document.createElement('h3');title.textContent=item.displayName;const subtitle=document.createElement('p');if(item.info&&item.info.Constellation){subtitle.textContent=item.info.Constellation;}else if(item.dsoKey){subtitle.textContent=item.dsoKey;}info.appendChild(title);info.appendChild(subtitle);card.appendChild(img);card.appendChild(info);grid.appendChild(card);});}
    function openModal(idx){const item=galleryData[idx];const modal=document.getElementById('modal');const modalImage=document.getElementById('modalImage');const modalInfo=document.getElementById('modalInfo');modalImage.src=item.path;modalImage.alt=item.displayName;let h=`<h2>${item.displayName}</h2>`;if(item.info){const i=item.info;if(i.OtherNames&&i.OtherNames.length>0)h+=`<div class="info-section"><h3>Also Known As</h3><p>${i.OtherNames.join(', ')}</p></div>`;if(i.Constellation)h+=`<div class="info-section"><h3>Constellation</h3><p>${i.Constellation}</p></div>`;if(i.Type)h+=`<div class="info-section"><h3>Type</h3><p>${i.Type}</p></div>`;if(i.Distance)h+=`<div class="info-section"><h3>Distance</h3><p>${i.Distance}</p></div>`;if(i.Size)h+=`<div class="info-section"><h3>Size</h3><p>${i.Size}</p></div>`;if(i.Composition)h+=`<div class="info-section"><h3>Composition</h3><p>${i.Composition}</p></div>`;if(i.FunFacts&&i.FunFacts.length>0){h+=`<div class="info-section"><h3>Fun Facts</h3><ul>`;i.FunFacts.forEach(f=>h+=`<li>${f}</li>`);h+=`</ul></div>`;}}else{h+=`<p>Detailed information for this object is being compiled.</p>`;}modalInfo.innerHTML=h;modal.classList.add('active');document.body.style.overflow='hidden';}
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
