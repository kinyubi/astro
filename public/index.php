<?php
/**
 * Enhanced Astronomy Landing Page
 * Provides options for slideshow or browsing DSO gallery
 */

$dirFull = __DIR__ . '/images/annotated_full';
$dirWall = __DIR__ . '/images/annotated_wall';
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

// Load DSO information from SQLite database
$dsoInfo = [];
try {
    $dbPath = __DIR__ . '/../dsodb/astro.db';
    if (file_exists($dbPath)) {
        $db = new PDO('sqlite:' . $dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $db->query("
            SELECT
                o.DSOKey,
                o.CommonName,
                o.ConstellationID,
                con.Name AS ConstellationName,
                o.DistanceLY,
                o.ObjectSize,
                o.SocialBlurb,
                c.CatalogID AS PrimaryCatalogID
            FROM Objects o
            LEFT JOIN CatalogIDs c ON o.DSOKey = c.DSOKey AND c.IsPrimary = 1
            LEFT JOIN Constellations con ON o.ConstellationID = con.ConstellationID
        ");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $dsoInfo[$row['DSOKey']] = $row;
        }
        // Also index by every CatalogID so M1, M42 etc. resolve correctly
        $aliasStmt = $db->query("
            SELECT CatalogID, DSOKey FROM CatalogIDs
        ");
        foreach ($aliasStmt->fetchAll(PDO::FETCH_ASSOC) as $alias) {
            $key = strtoupper($alias['CatalogID']);
            if (!isset($dsoInfo[$key]) && isset($dsoInfo[$alias['DSOKey']])) {
                $dsoInfo[$key] = $dsoInfo[$alias['DSOKey']];
            }
        }
    }
} catch (Exception $e) {
    // Fall back to empty array — gallery still works, just no object info
}

$fullImages = gatherImages($dirFull, 'images/annotated_full', $extensions);
$wallImages = gatherImages($dirWall, 'images/annotated_wall', $extensions);
$favImages = gatherImages($dirFav, 'images/fav', $extensions);

/**
 * Extract DSO name from filename (new convention: scientific name at start, terminated by underscore)
 */
function extractDSOName($filename) {
    $name = pathinfo($filename, PATHINFO_FILENAME);
    $parts = explode('_', $name);
    if (count($parts) > 0) {
        $dsoName = strtoupper(trim($parts[0]));
        $dsoName = str_replace(' ', '', $dsoName);
        return $dsoName;
    }
    return null;
}

/**
 * Extract base filename for download paths
 */
function extractBaseName($filename) {
    $name = pathinfo($filename, PATHINFO_FILENAME);
    $suffixes = ['_fav_annotated', '_full_annotated', '_wall_annotated', '_fav', '_full', '_wall'];
    foreach ($suffixes as $suffix) {
        if (str_ends_with($name, $suffix)) {
            return substr($name, 0, -strlen($suffix));
        }
    }
    return $name;
}

/**
 * Look up DSO information from the database-sourced array
 */
function getDSOInfo($dsoKey, $dsoInfo) {
    if (!$dsoKey || !isset($dsoInfo[$dsoKey])) {
        return null;
    }
    return $dsoInfo[$dsoKey];
}

// Create gallery data
$galleryItems = [];
foreach ($fullImages as $imgPath) {
    $filename = basename($imgPath);
    $dsoKey = extractDSOName($filename);
    $baseName = extractBaseName($filename);
    $fullPath = $imgPath;
    $wallpaperPath = str_replace('images/annotated_full', 'images/annotated_wall', $imgPath);
    $wallpaperPath = str_replace('_full_annotated', '_wall_annotated', $wallpaperPath);
    $favPath = str_replace('images/annotated_full', 'images/fav', $imgPath);
    $favPath = str_replace('_full_annotated', '_fav', $favPath);
    
    // Check if 4K wallpaper versions exist (separate check for annotated and normal)
    $wall4kPath = 'images/wall4k/' . $baseName . '_4k.jpg';
    $wall4kAnnotatedPath = 'images/annotated_wall4k/' . $baseName . '_4k_annotated.jpg';
    $has4k = file_exists(__DIR__ . '/' . $wall4kPath);
    $has4kAnnotated = file_exists(__DIR__ . '/' . $wall4kAnnotatedPath);
    
    $info = getDSOInfo($dsoKey, $dsoInfo);
    
    if ($info && isset($info['CommonName'])) {
        $displayName = $info['CommonName'] . ' (' . $dsoKey . ')';
    } else {
        $displayName = $dsoKey;
    }
    
    $thumbPath = 'images/thumbs/' . $baseName . '_thumb.jpg';
    
    $galleryItems[] = [
        'filename' => $filename,
        'baseName' => $baseName,
        'fullPath' => $fullPath,
        'favPath' => $favPath,
        'thumbPath' => $thumbPath,
        'wallpaperPath' => $wallpaperPath,
        'displayName' => $displayName,
        'dsoKey' => $dsoKey,
        'info' => $info,
        'has4k' => $has4k,
        'has4kAnnotated' => $has4kAnnotated
    ];
}

usort($galleryItems, function($a, $b) {
    return strcmp($a['displayName'], $b['displayName']);
});

$fullJson = json_encode($fullImages);
$wallJson = json_encode($wallImages);
$galleryJson = json_encode($galleryItems);

// Solar system image scanning
$solarObjects = [];
$solarDir = __DIR__ . '/images/solar';
if (is_dir($solarDir)) {
    // Order matters: longer suffixes must be checked first
    $sizeOrder = ['full_annotated', 'fav_annotated', 'wall_annotated', 'thumb', 'full', 'fav', 'wall'];
    foreach (scandir($solarDir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (!in_array($ext, $extensions, true)) continue;
        $name = pathinfo($f, PATHINFO_FILENAME);
        $sizeFound = null;
        $baseName = $name;
        foreach ($sizeOrder as $size) {
            if (str_ends_with($name, '_' . $size)) {
                $sizeFound = $size;
                $baseName = substr($name, 0, -(strlen($size) + 1));
                break;
            }
        }
        if (!$sizeFound) continue;
        $objectKey = explode('_', $baseName)[0]; // e.g. "moon", "sun"
        if (!isset($solarObjects[$objectKey])) {
            $solarObjects[$objectKey] = ['object' => $objectKey, 'thumb' => null, 'shots' => []];
        }
        if ($sizeFound === 'thumb') {
            $solarObjects[$objectKey]['thumb'] = 'images/solar/' . $f;
        } else {
            if (!isset($solarObjects[$objectKey]['shots'][$baseName])) {
                $solarObjects[$objectKey]['shots'][$baseName] = ['baseName' => $baseName, 'files' => []];
            }
            $solarObjects[$objectKey]['shots'][$baseName]['files'][$sizeFound] = 'images/solar/' . $f;
        }
    }
}
// Sort shots within each object chronologically (baseName contains date)
foreach ($solarObjects as &$solarObj) {
    ksort($solarObj['shots']);
    $solarObj['shots'] = array_values($solarObj['shots']);
}
unset($solarObj);
ksort($solarObjects); // alphabetical by object key
$solarJson = json_encode(array_values($solarObjects));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <title>Astronomy Gallery</title>
    <link rel="icon" type="image/png" href="/images/favicon.png">

    <link rel="stylesheet" href="/css/style.css?ver=2">
    <link rel="stylesheet" type="text/css" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <style>
        /* Download Button & Dropdown Styles */
        .modal-download-btn {
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1001;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(74, 158, 255, 0.3);
            color: white;
            border: 2px solid #4a9eff;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            font-size: 1.4em;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .modal-download-btn:hover,
        .modal-close:hover {
            background: rgba(74, 158, 255, 0.5);
        }

        /* Override modal-close to match download button */
        .modal-close {
            background: rgba(74, 158, 255, 0.3);
            border: 2px solid #4a9eff;
        }

        .modal-close svg {
            width: 50%;
            height: 50%;
            fill: white;
        }

        .download-dropdown {
            position: fixed;
            top: 80px;
            left: 20px;
            z-index: 1002;
            background: #1a1f3a;
            border: 2px solid #4a9eff;
            border-radius: 10px;
            min-width: 220px;
            display: none;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
            overflow: hidden;
        }

        .download-dropdown.active {
            display: block;
        }

        .download-dropdown-header {
            padding: 12px 16px;
            background: #2a3f5f;
            color: #7ec8ff;
            font-weight: 600;
            font-size: 0.9em;
            border-bottom: 1px solid #4a9eff;
        }

        .download-category {
            border-bottom: 1px solid #2a3f5f;
        }

        .download-category:last-child {
            border-bottom: none;
        }

        .download-category-title {
            padding: 10px 16px;
            color: #4a9eff;
            font-weight: 600;
            font-size: 0.95em;
            background: #151a30;
            cursor: default;
        }

        .download-option {
            display: flex;
            align-items: center;
            padding: 10px 16px 10px 28px;
            color: #e0e0e0;
            cursor: pointer;
            transition: background 0.2s ease;
            font-size: 0.9em;
        }

        .download-option:hover {
            background: #2a3f5f;
        }

        .download-option i {
            margin-right: 10px;
            color: #7ec8ff;
            width: 18px;
            text-align: center;
        }

        .download-option .size-hint {
            margin-left: auto;
            color: #6a7a8a;
            font-size: 0.8em;
        }

        /* Image Loading Spinner */
        .modal-image-container {
            position: relative;
            min-height: 200px;
            background: #000;
        }

        .modal-image-container.loading::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 50px;
            height: 50px;
            margin: -25px 0 0 -25px;
            border: 4px solid rgba(74, 158, 255, 0.3);
            border-top-color: #4a9eff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            z-index: 1;
        }

        .modal-image-container.loading .modal-image {
            opacity: 0;
        }

        .modal-image {
            width: 100%;
            height: auto;
            object-fit: contain;
            opacity: 1;
            transition: opacity 0.3s ease;
            display: block;
        }

        /* Portrait - constrain to 90vh so info peeks through */
        @media (orientation: portrait) {
            .modal-image {
                max-height: 90vh !important;
            }
        }

        /* Landscape - fit to viewport */
        @media (orientation: landscape) {
            .modal-image {
                max-height: 100vh !important;
            }
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Gallery image wrapper - maintains aspect ratio before image loads */
        .gallery-image-wrapper {
            position: relative;
            width: 100%;
            aspect-ratio: 4 / 5; /* Match your fav image aspect ratio */
            background: #151a30;
            overflow: hidden;
        }

        /* Lazy loading placeholder for gallery thumbnails */
        .lazy-image {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .lazy-image.loaded {
            opacity: 1;
        }

        /* Small spinner for gallery thumbnails */
        .gallery-image-wrapper::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 30px;
            height: 30px;
            margin: -15px 0 0 -15px;
            border: 3px solid rgba(74, 158, 255, 0.2);
            border-top-color: #4a9eff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        .gallery-image-wrapper.loaded::before {
            display: none;
        }

        /* Scroll hint floating at bottom */
        .scroll-hint {
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0, 0, 0, 0.7);
            color: #9aa0a6;
            padding: 10px 20px;
            border-radius: 20px;
            font-size: 0.9em;
            z-index: 1003;
            opacity: 0;
            transition: opacity 0.5s ease;
            pointer-events: none;
        }

        .scroll-hint.visible {
            opacity: 1;
        }

        .scroll-hint i {
            margin-right: 8px;
        }

        /* Modal info pane text size adjustments */
        .modal-header h2 {
            font-size: 1.08em !important; /* 1.8em reduced by 40% */
        }

        .modal-info .info-section h3 {
            font-size: 0.8em !important; /* 1.15em reduced by 30% */
        }

        .modal-info .info-section p,
        .modal-info .info-section.fun-facts ul {
            font-size: 0.7em !important; /* 1em reduced by 30% */
        }

        .modal-info .no-info {
            font-size: 0.7em !important;
        }

        .social-blurb-para {
            font-size: 0.7em;
            line-height: 1.6;
            color: #c9d1d9;
            margin: 10px 16px;
        }

        .social-blurb-context {
            color: #8b949e;
            font-style: italic;
            border-top: 1px solid rgba(255,255,255,0.07);
            padding-top: 8px;
            margin-top: 4px;
        }

        /* Gallery Tab Switcher */
        .gallery-tabs {
            display: flex;
            gap: 8px;
        }

        .gallery-tab {
            background: transparent;
            border: 2px solid #2a3f5f;
            color: #8a9aaa;
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 1em;
            cursor: pointer;
            transition: all 0.2s ease;
            font-family: inherit;
            white-space: nowrap;
        }

        .gallery-tab:hover {
            border-color: #4a9eff;
            color: #e0e0e0;
        }

        .gallery-tab.active {
            background: #2a3f5f;
            border-color: #4a9eff;
            color: #4a9eff;
            font-weight: 600;
        }

        /* Solar Modal */
        .solar-modal-body {
            width: 100%;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #000;
            position: relative;
        }

        .solar-modal-body .modal-image {
            max-height: 100vh;
            max-width: 100%;
            width: auto;
            height: auto;
            object-fit: contain;
            display: block;
        }

        @media (orientation: portrait) {
            .solar-modal-body .modal-image {
                max-height: 100vh !important;
            }
        }

        .solar-caption {
            position: absolute;
            bottom: 50px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0, 0, 0, 0.72);
            color: #e0e0e0;
            padding: 8px 22px;
            border-radius: 20px;
            font-size: 0.9em;
            white-space: nowrap;
            pointer-events: none;
            z-index: 5;
        }

        .solar-arrow {
            position: absolute;
            top: 90%;
            transform: translateY(-50%);
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(0, 0, 0, 0.18);
            border-radius: 50%;
            cursor: pointer;
            border: none;
            padding: 0;
            width: calc(8vh + 8px);
            height: calc(8vh + 8px);
            z-index: 10;
            transition: background 0.12s;
        }

        .solar-arrow:hover {
            background: rgba(0, 0, 0, 0.45);
        }

        .solar-arrow img {
            width: 60%;
            height: 60%;
            object-fit: contain;
        }

        .solar-arrow-left  { left:  calc(1.5vw + 2px); }
        .solar-arrow-right { right: calc(1.5vw + 2px); }

        .solar-counter {
            position: absolute;
            top: 18px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0,0,0,0.6);
            color: #9aa0a6;
            padding: 5px 14px;
            border-radius: 12px;
            font-size: 0.82em;
            pointer-events: none;
            z-index: 5;
        }

        /* Solar download dropdown dynamic options */
        .solar-download-option {
            display: flex;
            align-items: center;
            padding: 10px 16px 10px 28px;
            color: #e0e0e0;
            cursor: pointer;
            transition: background 0.2s ease;
            font-size: 0.9em;
        }

        .solar-download-option:hover {
            background: #2a3f5f;
        }

        .solar-download-option i {
            margin-right: 10px;
            color: #7ec8ff;
            width: 18px;
            text-align: center;
        }

        .solar-download-option .size-hint {
            margin-left: auto;
            color: #6a7a8a;
            font-size: 0.8em;
        }

        /* Mobile adjustments */
        /* Floating back button for gallery */
        .gallery-floating-back {
            position: fixed;
            top: 20px;
            right: 20px;
            left: auto;
            z-index: 100;
        }

        @media (max-width: 768px) {
            .modal-download-btn {
                width: 44px;
                height: 44px;
                font-size: 1.2em;
            }

            .download-dropdown {
                left: 10px;
                right: 10px;
                min-width: auto;
            }

            .download-option {
                padding: 14px 16px 14px 28px;
            }
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
            <p>Sit back and enjoy an automated tour of stunning deep sky objects that I have photographed</p>
        </div>
        <div class="option-card" onclick="showGallery()">
            <div class="option-icon">🔭</div>
            <h2>Browse Gallery</h2>
            <p>Read about, view close up or download images of any deep sky image in my gallery.</p>
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
    <button class="back-btn gallery-floating-back" onclick="backToLanding()" aria-label="Back to menu" type="button">
        <svg viewBox="0 0 24 24"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z" fill="white"/></svg>
    </button>
    <div class="gallery-header">
        <div class="gallery-tabs">
            <button class="gallery-tab active" id="tabDso" onclick="switchGalleryTab('dso')">🔭 Deep Sky</button>
            <button class="gallery-tab" id="tabSolar" onclick="switchGalleryTab('solar')">☀️ Solar System</button>
        </div>
        <div class="search-wrapper" id="dsoSearchWrapper">
            <div class="search-input-container">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" class="search-input" id="searchInput" placeholder="Search by name or catalog ID..." autocomplete="off">
            </div>
            <div class="search-dropdown" id="searchDropdown"></div>
        </div>
    </div>
    <div id="dsoSection">
        <div class="gallery-grid" id="galleryGrid"></div>
    </div>
    <div id="solarSection" style="display:none">
        <div class="gallery-grid" id="solarGrid"></div>
    </div>
</div>
<div class="modal" id="modal">
    <div class="scroll-hint" id="scrollHint"><i class="fa-solid fa-chevron-down"></i> Scroll for object information</div>
    <button class="modal-download-btn" id="downloadBtn" onclick="toggleDownloadDropdown(event)" title="Download"><i class="fa-solid fa-download"></i></button>
    <div class="download-dropdown" id="downloadDropdown">
        <div class="download-dropdown-header">Download Image</div>
        <div class="download-category">
            <div class="download-category-title">Annotated</div>
            <div class="download-option" onclick="downloadImage('titled', 'square')">
                <i class="fa-solid fa-square"></i> Square (4:5)
                <span class="size-hint">1080×1350</span>
            </div>
            <div class="download-option" onclick="downloadImage('titled', 'portrait')">
                <i class="fa-solid fa-mobile-screen"></i> Portrait (9:16)
                <span class="size-hint">1080×1920</span>
            </div>
            <div class="download-option" onclick="downloadImage('titled', 'landscape')">
                <i class="fa-solid fa-desktop"></i> Landscape (16:9)
                <span class="size-hint">1920×1080</span>
            </div>
            <div class="download-option download-4k-annotated-option" onclick="downloadImage('titled', 'landscape4k')" style="display:none;">
                <i class="fa-solid fa-tv"></i> 4K Wallpaper
                <span class="size-hint">3840×2160</span>
            </div>
        </div>
        <div class="download-category">
            <div class="download-category-title">Normal</div>
            <div class="download-option" onclick="downloadImage('untitled', 'square')">
                <i class="fa-solid fa-square"></i> Square (4:5)
                <span class="size-hint">1080×1350</span>
            </div>
            <div class="download-option" onclick="downloadImage('untitled', 'portrait')">
                <i class="fa-solid fa-mobile-screen"></i> Portrait (9:16)
                <span class="size-hint">1080×1920</span>
            </div>
            <div class="download-option" onclick="downloadImage('untitled', 'landscape')">
                <i class="fa-solid fa-desktop"></i> Landscape (16:9)
                <span class="size-hint">1920×1080</span>
            </div>
            <div class="download-option download-4k-normal-option" onclick="downloadImage('untitled', 'landscape4k')" style="display:none;">
                <i class="fa-solid fa-tv"></i> 4K Wallpaper
                <span class="size-hint">3840×2160</span>
            </div>
        </div>
    </div>
    <button class="modal-close" onclick="closeModal()" aria-label="Back to gallery">
        <svg viewBox="0 0 24 24"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z" fill="white"/></svg>
    </button>
    <div class="modal-content">
        <div class="modal-image-container loading" id="modalImageContainer">
            <img class="modal-image" id="modalImage" src="" alt="">
        </div>
        <div class="modal-info" id="modalInfo"></div>
    </div>
</div>
<div class="modal" id="solarModal">
    <button class="modal-download-btn" id="solarDownloadBtn" onclick="toggleSolarDownloadDropdown(event)" title="Download"><i class="fa-solid fa-download"></i></button>
    <div class="download-dropdown" id="solarDownloadDropdown">
        <div class="download-dropdown-header">Download Image</div>
        <div id="solarDownloadOptions"></div>
    </div>
    <button class="modal-close" onclick="closeSolarModal()" aria-label="Back to gallery">
        <svg viewBox="0 0 24 24"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z" fill="white"/></svg>
    </button>
    <div class="solar-modal-body" id="solarModalBody">
        <div class="solar-counter" id="solarCounter"></div>
        <img class="modal-image" id="solarModalImage" src="" alt="">
        <div class="solar-caption" id="solarCaption"></div>
        <button class="solar-arrow solar-arrow-left" id="solarPrev" onclick="navigateSolar(-1)" aria-label="Previous">
            <img src="images/left-arrow.png" alt="Previous">
        </button>
        <button class="solar-arrow solar-arrow-right" id="solarNext" onclick="navigateSolar(1)" aria-label="Next">
            <img src="images/right-arrow.png" alt="Next">
        </button>
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
    
    // Current modal item for downloads
    let currentModalItem = null;
    
    // Lazy loading observer - will be initialized after gallery renders
    let lazyImageObserver = null;
    
    // Track if scroll hint has been shown this session
    let scrollHintShown = false;
    let scrollHintTimer = null;

    // Helper function to append palette suffix based on filename
    function getTitleWithPalette(displayName, filename) {
        if (!filename) return displayName;
        const lowerFilename = filename.toLowerCase();
        if (lowerFilename.includes('_hoo_')) return displayName + ' (HOO palette)';
        if (lowerFilename.includes('_hso_')) return displayName + ' (HSO palette)';
        if (lowerFilename.includes('_sho_')) return displayName + ' (SHO palette)';
        if (lowerFilename.includes('_hos_')) return displayName + ' (HOS palette)';
        return displayName;
    }

    // Generate all image paths from base name (including 4K)
    function getImagePaths(baseName) {
        return {
            titled: {
                square: `/images/annotated_fav/${baseName}_fav_annotated.jpg`,
                portrait: `/images/annotated_full/${baseName}_full_annotated.jpg`,
                landscape: `/images/annotated_wall/${baseName}_wall_annotated.jpg`,
                landscape4k: `/images/annotated_wall4k/${baseName}_4k_annotated.jpg`
            },
            untitled: {
                square: `/images/fav/${baseName}_fav.jpg`,
                portrait: `/images/full/${baseName}_full.jpg`,
                landscape: `/images/wall/${baseName}_wall.jpg`,
                landscape4k: `/images/wall4k/${baseName}_4k.jpg`
            }
        };
    }

    // Check if device supports Web Share API with files
    function canShareFiles() {
        return navigator.share && navigator.canShare;
    }

    // Download or share image
    async function downloadImage(type, size) {
        if (!currentModalItem || !currentModalItem.baseName) {
            console.error('No image selected');
            return;
        }

        const paths = getImagePaths(currentModalItem.baseName);
        const imagePath = paths[type][size];
        const filename = imagePath.split('/').pop();

        closeDownloadDropdown();

        try {
            const response = await fetch(imagePath);
            if (!response.ok) throw new Error('Image not found');
            const blob = await response.blob();

            if (canShareFiles()) {
                const file = new File([blob], filename, { type: blob.type });
                if (navigator.canShare({ files: [file] })) {
                    try {
                        await navigator.share({
                            files: [file],
                            title: currentModalItem.displayName
                        });
                        return;
                    } catch (shareErr) {
                        if (shareErr.name === 'AbortError') return;
                    }
                }
            }

            const blobUrl = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = blobUrl;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(blobUrl);

        } catch (error) {
            console.error('Download failed:', error);
            alert('Unable to download image. Please try again.');
        }
    }

    function toggleDownloadDropdown(event) {
        event.stopPropagation();
        const dropdown = document.getElementById('downloadDropdown');
        dropdown.classList.toggle('active');
    }

    function closeDownloadDropdown() {
        document.getElementById('downloadDropdown').classList.remove('active');
    }

    function showSlideshow(){document.getElementById('landingPage').style.display='none';document.getElementById('slideshowContainer').classList.add('active');chooseListByOrientation();}
    function showGallery(){document.getElementById('landingPage').style.display='none';document.getElementById('galleryContainer').classList.add('active');renderGallery();}
    function backToLanding(){document.getElementById('slideshowContainer').classList.remove('active');document.getElementById('galleryContainer').classList.remove('active');document.getElementById('landingPage').style.display='flex';stopAutoAdvance();}

    function renderGallery() {
        const grid = document.getElementById('galleryGrid');
        grid.innerHTML = '';
        
        // Create all cards first
        const cards = [];
        galleryData.forEach((item, idx) => {
            const card = document.createElement('div');
            card.className = 'gallery-item';
            card.onclick = () => openModal(idx);
            
            // Wrapper maintains aspect ratio
            const wrapper = document.createElement('div');
            wrapper.className = 'gallery-image-wrapper';
            
            const img = document.createElement('img');
            img.dataset.src = item.thumbPath;
            img.dataset.wrapperClass = 'gallery-image-wrapper';
            img.src = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
            img.alt = item.displayName;
            img.className = 'lazy-image';
            
            const info = document.createElement('div');
            info.className = 'gallery-item-info';
            const title = document.createElement('h3');
            title.textContent = getTitleWithPalette(item.displayName, item.filename);
            
            info.appendChild(title);
            wrapper.appendChild(img);
            card.appendChild(wrapper);
            card.appendChild(info);
            grid.appendChild(card);
            cards.push({ img, wrapper });
        });
        
        // Wait for layout to complete, then set up observer
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                initLazyLoading(cards);
            });
        });
    }
    
    function initLazyLoading(cards) {
        // Clean up old observer if exists
        if (lazyImageObserver) {
            lazyImageObserver.disconnect();
        }
        
        // Create new observer with strict settings
        lazyImageObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    const wrapper = img.parentElement;
                    
                    // Start loading
                    img.onload = () => {
                        img.classList.add('loaded');
                        wrapper.classList.add('loaded');
                    };
                    img.onerror = () => {
                        wrapper.classList.add('loaded'); // Hide spinner even on error
                    };
                    img.src = img.dataset.src;
                    
                    lazyImageObserver.unobserve(img);
                }
            });
        }, {
            root: null, // viewport
            rootMargin: '0px', // No preloading outside viewport
            threshold: 0.01 // Must be at least 1% visible
        });
        
        // Observe all images
        cards.forEach(({ img }) => {
            lazyImageObserver.observe(img);
        });
    }

    function openModal(idx) {
        const item = galleryData[idx];
        currentModalItem = item;
        const modal = document.getElementById('modal');
        const modalImage = document.getElementById('modalImage');
        const modalImageContainer = document.getElementById('modalImageContainer');
        const modalInfo = document.getElementById('modalInfo');
        const isLandscape = window.innerWidth > window.innerHeight;
        const imageSrc = isLandscape && item.wallpaperPath ? item.wallpaperPath : item.fullPath;
        
        modalImageContainer.classList.add('loading');
        
        modalImage.onload = function() {
            modalImageContainer.classList.remove('loading');
        };
        modalImage.onerror = function() {
            modalImageContainer.classList.remove('loading');
        };
        
        modalImage.src = imageSrc;
        modalImage.alt = item.displayName;
        const titleText = item.displayName || item.dsoKey;
        let h = `<div class="modal-header"><h2>${titleText}</h2></div>`;
        if (item.info) {
            const i = item.info;
            if (i.ConstellationID) h += `<div class="info-section"><h3>Constellation&nbsp;</h3><p>${i.ConstellationID}</p></div>`;
            if (i.DistanceLY)      h += `<div class="info-section"><h3>Distance&nbsp;</h3><p>${i.DistanceLY}</p></div>`;
            if (i.SocialBlurb) {
                // Convert \n\n to paragraph breaks
                const paras = i.SocialBlurb.split(/\n\n+/);
                h += paras.map(p => `<p class="social-blurb-para">${p}</p>`).join('');
            }
            // Injected context paragraph — always built from structured DB fields
            const name = i.CommonName || item.dsoKey;
            const locParts = [];
            if (i.ConstellationName) locParts.push(`located in the constellation ${i.ConstellationName}`);
            if (i.DistanceLY)        locParts.push(`about ${i.DistanceLY}`);
            let contextLine = locParts.length ? `The ${name} is ${locParts.join(', ')}.` : '';
            if (i.ObjectSize) contextLine += (contextLine ? ` It is ${i.ObjectSize}` : `The ${name} is ${i.ObjectSize}`);
            if (contextLine) h += `<p class="social-blurb-para social-blurb-context">${contextLine}</p>`;
        } else {
            h += `<p class="no-info">No information available for this object.</p>`;
        }
        modalInfo.innerHTML = h;
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        closeDownloadDropdown();
        
        // Show/hide 4K options based on availability (separate for annotated and normal)
        document.querySelector('.download-4k-annotated-option').style.display = item.has4kAnnotated ? 'flex' : 'none';
        document.querySelector('.download-4k-normal-option').style.display = item.has4k ? 'flex' : 'none';
        
        // Show scroll hint on first modal open
        if (!scrollHintShown) {
            scrollHintShown = true;
            const hint = document.getElementById('scrollHint');
            // Small delay so it appears after modal opens
            setTimeout(() => {
                hint.classList.add('visible');
                // Hide after 5 seconds
                scrollHintTimer = setTimeout(() => {
                    hint.classList.remove('visible');
                }, 5000);
            }, 500);
        }
    }
    
    function closeModal(){
        document.getElementById('modal').classList.remove('active');
        document.body.style.overflow='';
        closeDownloadDropdown();
        currentModalItem = null;
        // Clear scroll hint timer and hide if visible
        if (scrollHintTimer) {
            clearTimeout(scrollHintTimer);
            scrollHintTimer = null;
        }
        document.getElementById('scrollHint').classList.remove('visible');
    }
    
    document.getElementById('modal').addEventListener('click',e=>{
        if (!e.target.closest('#downloadDropdown') && !e.target.closest('#downloadBtn')) {
            closeDownloadDropdown();
        }
        if(e.target.id==='modal')closeModal();
    });
    
    document.addEventListener('keydown',e=>{
        if(e.key==='Escape') {
            // Only handle DSO modal Escape when it is actually active
            if (!document.getElementById('modal').classList.contains('active')) return;
            if (document.getElementById('downloadDropdown').classList.contains('active')) {
                closeDownloadDropdown();
            } else {
                closeModal();
            }
        }
    });
    
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
    document.addEventListener('keydown',e=>{
        if(document.getElementById('modal').classList.contains('active')) return;
        if(document.getElementById('solarModal').classList.contains('active')) return;
        if(e.key==='ArrowRight')nextImage();
        else if(e.key==='ArrowLeft')prevImage();
        else if(e.key===' '){e.preventDefault();togglePlayPause();}
    });
    function updateModalImageForOrientation() {
        if (!currentModalItem) return;
        const modal = document.getElementById('modal');
        if (!modal.classList.contains('active')) return;
        
        const modalImage = document.getElementById('modalImage');
        const modalImageContainer = document.getElementById('modalImageContainer');
        const isLandscape = window.innerWidth > window.innerHeight;
        const newSrc = isLandscape && currentModalItem.wallpaperPath 
            ? currentModalItem.wallpaperPath 
            : currentModalItem.fullPath;
        
        // Only update if the source actually changed
        if (!modalImage.src.endsWith(newSrc)) {
            modalImageContainer.classList.add('loading');
            modalImage.src = newSrc;
        }
    }

    window.addEventListener('resize', function() {
        chooseListByOrientation();
        updateModalImageForOrientation();
    });
    window.addEventListener('orientationchange', () => setTimeout(function() {
        chooseListByOrientation();
        updateModalImageForOrientation();
    }, 120));
    document.addEventListener('visibilitychange',()=>{if(document.hidden){stopAutoAdvance();}else{resetAutoAdvance();}});
    updatePlayPauseButton();

    // Search functionality
    const searchInput = document.getElementById('searchInput');
    const searchDropdown = document.getElementById('searchDropdown');
    let highlightedIndex = -1;
    let searchResults = [];

    function searchGallery(query) {
        if (query.length < 2) return [];
        const lowerQuery = query.toLowerCase();
        return galleryData.filter(item => {
            const nameMatch = item.displayName && item.displayName.toLowerCase().includes(lowerQuery);
            const dsoMatch = item.dsoKey && item.dsoKey.toLowerCase().includes(lowerQuery);
            return nameMatch || dsoMatch;
        }).slice(0, 8);
    }

    function renderSearchDropdown(results) {
        searchResults = results;
        highlightedIndex = -1;
        
        if (results.length === 0) {
            if (searchInput.value.length >= 2) {
                searchDropdown.innerHTML = '<div class="search-no-results">No matching objects found</div>';
                searchDropdown.classList.add('active');
            } else {
                searchDropdown.classList.remove('active');
            }
            return;
        }

        searchDropdown.innerHTML = results.map((item, idx) => {
            const galleryIdx = galleryData.findIndex(g => g.filename === item.filename);
            return `
                <div class="search-dropdown-item" data-index="${galleryIdx}" data-search-index="${idx}">
                    <img src="${item.favPath}" alt="${item.displayName}" loading="lazy">
                    <div class="search-dropdown-item-info">
                        <div class="search-dropdown-item-name">${getTitleWithPalette(item.displayName, item.filename)}</div>
                        <div class="search-dropdown-item-id">${item.dsoKey || ''}</div>
                    </div>
                </div>
            `;
        }).join('');
        
        searchDropdown.classList.add('active');
        
        searchDropdown.querySelectorAll('.search-dropdown-item').forEach(el => {
            el.addEventListener('click', () => {
                const idx = parseInt(el.dataset.index);
                openModal(idx);
                closeSearchDropdown();
            });
        });
    }

    function closeSearchDropdown() {
        searchDropdown.classList.remove('active');
        searchInput.value = '';
        highlightedIndex = -1;
        searchResults = [];
    }

    function updateHighlight() {
        const items = searchDropdown.querySelectorAll('.search-dropdown-item');
        items.forEach((item, idx) => {
            item.classList.toggle('highlighted', idx === highlightedIndex);
        });
        if (highlightedIndex >= 0 && items[highlightedIndex]) {
            items[highlightedIndex].scrollIntoView({ block: 'nearest' });
        }
    }

    searchInput.addEventListener('input', (e) => {
        const query = e.target.value.trim();
        const results = searchGallery(query);
        renderSearchDropdown(results);
    });

    searchInput.addEventListener('keydown', (e) => {
        if (!searchDropdown.classList.contains('active') || searchResults.length === 0) {
            if (e.key === 'Escape') {
                closeSearchDropdown();
                searchInput.blur();
            }
            return;
        }

        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                highlightedIndex = (highlightedIndex + 1) % searchResults.length;
                updateHighlight();
                break;
            case 'ArrowUp':
                e.preventDefault();
                highlightedIndex = highlightedIndex <= 0 ? searchResults.length - 1 : highlightedIndex - 1;
                updateHighlight();
                break;
            case 'Enter':
                e.preventDefault();
                if (highlightedIndex >= 0) {
                    const galleryIdx = galleryData.findIndex(g => g.filename === searchResults[highlightedIndex].filename);
                    openModal(galleryIdx);
                    closeSearchDropdown();
                }
                break;
            case 'Escape':
                closeSearchDropdown();
                searchInput.blur();
                break;
        }
    });

    document.addEventListener('click', (e) => {
        if (!e.target.closest('.search-wrapper')) {
            searchDropdown.classList.remove('active');
        }
    });

    // =====================================================================
    // SOLAR SYSTEM GALLERY
    // =====================================================================
    const solarData = <?php echo $solarJson; ?>;

    // --- Tab switching ---
    function switchGalleryTab(tab) {
        const dsoSection    = document.getElementById('dsoSection');
        const solarSection  = document.getElementById('solarSection');
        const dsoSearch     = document.getElementById('dsoSearchWrapper');
        const tabDso        = document.getElementById('tabDso');
        const tabSolar      = document.getElementById('tabSolar');

        if (tab === 'dso') {
            dsoSection.style.display   = '';
            solarSection.style.display = 'none';
            dsoSearch.style.display    = '';
            tabDso.classList.add('active');
            tabSolar.classList.remove('active');
        } else {
            dsoSection.style.display   = 'none';
            solarSection.style.display = '';
            dsoSearch.style.display    = 'none';
            tabSolar.classList.add('active');
            tabDso.classList.remove('active');
            renderSolarGallery();
        }
    }

    // --- Caption parser ---
    const TECHNICAL_DESCRIPTORS = ['ha', 'hb', 'oiii', 'sii', 'rgb', 'lrgb', 'nb'];

    function titleCase(str) {
        return str.charAt(0).toUpperCase() + str.slice(1).toLowerCase();
    }

    function formatSolarDate(datePart) {
        // datePart is YYYYMMDD (8 chars), may have trailing letter like '20250506a'
        const d = datePart.substring(0, 8);
        const year  = d.substring(0, 4);
        const month = parseInt(d.substring(4, 6)) - 1;
        const day   = parseInt(d.substring(6, 8));
        return new Date(year, month, day).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function parseSolarCaption(baseName) {
        const parts = baseName.split('_');
        const object = parts[0];

        // Find first segment that looks like a date (8 digits + optional letter)
        let dateIndex = -1;
        for (let i = 1; i < parts.length; i++) {
            if (/^\d{8}[a-z]?$/.test(parts[i])) {
                dateIndex = i;
                break;
            }
        }

        const objectTitle = titleCase(object);
        if (dateIndex === -1) return objectTitle;

        const descriptorParts = parts.slice(1, dateIndex);
        const dateStr = formatSolarDate(parts[dateIndex]);

        if (descriptorParts.length === 0) {
            return `${objectTitle} \u00b7 ${dateStr}`;
        }

        // Single-word technical descriptor?
        const isTechnical = descriptorParts.length === 1 &&
            TECHNICAL_DESCRIPTORS.includes(descriptorParts[0].toLowerCase());

        if (isTechnical) {
            const label = descriptorParts[0].toUpperCase()
                .replace('HA', 'H-Alpha').replace('HB', 'H-Beta')
                .replace('OIII', 'O-III').replace('SII', 'S-II');
            return `${objectTitle} (${label}) \u00b7 ${dateStr}`;
        }

        const descriptor = descriptorParts.map(p => titleCase(p)).join(' ');
        return `${descriptor} ${objectTitle} \u00b7 ${dateStr}`;
    }

    function solarObjectDisplayName(objectKey) {
        const names = { moon: 'Moon', sun: 'Sun', mercury: 'Mercury', venus: 'Venus',
            mars: 'Mars', jupiter: 'Jupiter', saturn: 'Saturn', uranus: 'Uranus', neptune: 'Neptune' };
        return names[objectKey.toLowerCase()] || titleCase(objectKey);
    }

    // --- Solar gallery render ---
    let solarRendered = false;

    function renderSolarGallery() {
        if (solarRendered) return;
        solarRendered = true;

        const grid = document.getElementById('solarGrid');
        grid.innerHTML = '';

        if (!solarData || solarData.length === 0) {
            grid.innerHTML = '<p style="color:#8a9aaa;padding:40px;text-align:center;">No solar system images found.</p>';
            return;
        }

        const cards = [];
        solarData.forEach((obj, idx) => {
            const card = document.createElement('div');
            card.className = 'gallery-item';
            card.onclick = () => openSolarModal(idx);

            const wrapper = document.createElement('div');
            wrapper.className = 'gallery-image-wrapper';

            const img = document.createElement('img');
            img.className = 'lazy-image';
            img.src = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
            img.alt = solarObjectDisplayName(obj.object);

            if (obj.thumb) {
                img.dataset.src = obj.thumb;
            } else if (obj.shots && obj.shots.length > 0) {
                // Fall back to most recent shot's fav, or full
                const lastShot = obj.shots[obj.shots.length - 1];
                img.dataset.src = lastShot.files.fav || lastShot.files.full || Object.values(lastShot.files)[0];
            }

            const info = document.createElement('div');
            info.className = 'gallery-item-info';
            const title = document.createElement('h3');
            title.textContent = solarObjectDisplayName(obj.object);
            const sub = document.createElement('p');
            sub.textContent = `${obj.shots ? obj.shots.length : 0} image${obj.shots && obj.shots.length !== 1 ? 's' : ''}`;

            img.onload = () => { img.classList.add('loaded'); wrapper.classList.add('loaded'); };
            img.onerror = () => { wrapper.classList.add('loaded'); };

            info.appendChild(title);
            info.appendChild(sub);
            wrapper.appendChild(img);
            card.appendChild(wrapper);
            card.appendChild(info);
            grid.appendChild(card);
            cards.push({ img, wrapper });
        });

        requestAnimationFrame(() => requestAnimationFrame(() => {
            cards.forEach(({ img }) => {
                if (img.dataset.src) {
                    img.src = img.dataset.src;
                } else {
                    img.parentElement.classList.add('loaded');
                }
            });
        }));
    }

    // --- Solar modal state ---
    let currentSolarObjectIdx = -1;
    let currentSolarShotIdx   = 0;

    const SIZE_META = {
        fav:            { icon: 'fa-solid fa-square',        label: 'Square (4:5)',    hint: '1080×1350' },
        full:           { icon: 'fa-solid fa-mobile-screen', label: 'Portrait (9:16)', hint: '1080×1920' },
        wall:           { icon: 'fa-solid fa-desktop',       label: 'Landscape (16:9)',hint: '1920×1080' },
        fav_annotated:  { icon: 'fa-solid fa-square',        label: 'Square \u2013 Annotated',    hint: '1080×1350' },
        full_annotated: { icon: 'fa-solid fa-mobile-screen', label: 'Portrait \u2013 Annotated',  hint: '1080×1920' },
        wall_annotated: { icon: 'fa-solid fa-desktop',       label: 'Landscape \u2013 Annotated', hint: '1920×1080' },
    };
    const SIZE_DISPLAY_ORDER = ['fav', 'full', 'wall', 'fav_annotated', 'full_annotated', 'wall_annotated'];

    function openSolarModal(objIdx) {
        currentSolarObjectIdx = objIdx;
        currentSolarShotIdx   = 0;
        document.getElementById('solarModal').classList.add('active');
        document.body.style.overflow = 'hidden';
        renderSolarModalSlide();
    }

    function renderSolarModalSlide() {
        const obj  = solarData[currentSolarObjectIdx];
        const shot = obj.shots[currentSolarShotIdx];
        const total = obj.shots.length;

        const modalImg = document.getElementById('solarModalImage');
        const caption  = document.getElementById('solarCaption');
        const counter  = document.getElementById('solarCounter');
        const prevBtn  = document.getElementById('solarPrev');
        const nextBtn  = document.getElementById('solarNext');

        // Choose best image for current orientation, preferring annotated versions
        const isLandscape = window.innerWidth > window.innerHeight;
        const src = isLandscape
            ? (shot.files.wall_annotated || shot.files.wall || shot.files.full_annotated || shot.files.full || shot.files.fav_annotated || shot.files.fav || Object.values(shot.files)[0])
            : (shot.files.full_annotated || shot.files.full || shot.files.fav_annotated || shot.files.fav || shot.files.wall_annotated || shot.files.wall || Object.values(shot.files)[0]);

        modalImg.src = src;
        modalImg.alt = parseSolarCaption(shot.baseName);
        caption.textContent = '';
        counter.textContent = total > 1 ? `${currentSolarShotIdx + 1} / ${total}` : '';
        prevBtn.style.display = total > 1 ? '' : 'none';
        nextBtn.style.display = total > 1 ? '' : 'none';

        // Build download options for this shot
        const optionsDiv = document.getElementById('solarDownloadOptions');
        optionsDiv.innerHTML = SIZE_DISPLAY_ORDER
            .filter(size => shot.files[size])
            .map(size => {
                const m = SIZE_META[size];
                return `<div class="solar-download-option" onclick="downloadSolarImage('${shot.files[size]}', '${shot.baseName}_${size}.jpg')">
                    <i class="${m.icon}"></i> ${m.label}
                    <span class="size-hint">${m.hint}</span>
                </div>`;
            }).join('');
    }

    function navigateSolar(dir) {
        const obj = solarData[currentSolarObjectIdx];
        currentSolarShotIdx = (currentSolarShotIdx + dir + obj.shots.length) % obj.shots.length;
        renderSolarModalSlide();
    }

    function closeSolarModal() {
        document.getElementById('solarModal').classList.remove('active');
        document.getElementById('solarDownloadDropdown').classList.remove('active');
        document.getElementById('solarModalImage').src = '';
        document.body.style.overflow = '';
        currentSolarObjectIdx = -1;
    }

    function toggleSolarDownloadDropdown(event) {
        event.stopPropagation();
        document.getElementById('solarDownloadDropdown').classList.toggle('active');
    }

    async function downloadSolarImage(path, filename) {
        document.getElementById('solarDownloadDropdown').classList.remove('active');
        try {
            const response = await fetch(path);
            if (!response.ok) throw new Error('Not found');
            const blob = await response.blob();
            if (canShareFiles()) {
                const file = new File([blob], filename, { type: blob.type });
                if (navigator.canShare({ files: [file] })) {
                    try { await navigator.share({ files: [file] }); return; }
                    catch (e) { if (e.name === 'AbortError') return; }
                }
            }
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url; a.download = filename;
            document.body.appendChild(a); a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        } catch (e) {
            console.error('Download failed:', e);
            alert('Unable to download image. Please try again.');
        }
    }

    // Solar modal click / keyboard
    document.getElementById('solarModal').addEventListener('click', e => {
        if (!e.target.closest('#solarDownloadDropdown') && !e.target.closest('#solarDownloadBtn')) {
            document.getElementById('solarDownloadDropdown').classList.remove('active');
        }
    });

    document.addEventListener('keydown', e => {
        const solarModal = document.getElementById('solarModal');
        if (!solarModal.classList.contains('active')) return;
        if (e.key === 'ArrowRight') navigateSolar(1);
        else if (e.key === 'ArrowLeft') navigateSolar(-1);
        else if (e.key === 'Escape') {
            if (document.getElementById('solarDownloadDropdown').classList.contains('active')) {
                document.getElementById('solarDownloadDropdown').classList.remove('active');
            } else {
                closeSolarModal();
            }
        }
    });

    // Re-render slide on orientation change (portrait<->landscape image swap)
    window.addEventListener('orientationchange', () => setTimeout(() => {
        if (currentSolarObjectIdx >= 0) renderSolarModalSlide();
    }, 120));
</script>
</body>
</html>
