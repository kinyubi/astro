<?php
/**
 * Blog post renderer — reads a blog's index.md and renders it as HTML
 */

require_once __DIR__ . '/Parsedown.php';

function parseFrontMatter($raw) {
    $meta    = [];
    $content = $raw;
    if (preg_match('/^---\s*\n(.*?)\n---\s*\n/s', $raw, $m)) {
        $content = substr($raw, strlen($m[0]));
        foreach (explode("\n", $m[1]) as $line) {
            if (preg_match('/^(\w+):\s*"?(.+?)"?\s*$/', trim($line), $lm)) {
                $meta[strtolower($lm[1])] = $lm[2];
            }
        }
    }
    $meta['_content'] = $content;
    return $meta;
}

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($_GET['slug'] ?? ''));
if (!$slug) { header('Location: index.php'); exit; }

$mdFile = __DIR__ . '/../blogs/' . $slug . '/index.md';
if (!is_file($mdFile)) {
    http_response_code(404);
    echo '<h1>Post not found</h1><p><a href="index.php">Back to blog</a></p>';
    exit;
}

$raw  = file_get_contents($mdFile);
$meta = parseFrontMatter($raw);

$title     = $meta['title']    ?? ucwords(str_replace('-', ' ', $slug));
$date      = $meta['date']     ?? '';
$tags      = isset($meta['tags']) ? array_map('trim', explode(',', trim($meta['tags'], '[]'))) : [];

// Post-process images: convert ![alt](src "caption") → <figure> with <figcaption>
// Parsedown renders the title attr on <img>; we promote it to a figcaption
$Parsedown = new Parsedown();
$Parsedown->setMarkupEscaped(false);
$Parsedown->setBreaksEnabled(false);
$html = $Parsedown->text($meta['_content']);

// Wrap <img ... title="..."> in <figure><figcaption>
$html = preg_replace_callback(
    '/<img([^>]*?)title="([^"]+)"([^>]*?)>/',
    function($m) {
        $attrs   = $m[1] . $m[3];
        $caption = htmlspecialchars_decode($m[2]);
        return '<figure><img' . $attrs . '><figcaption>' . htmlspecialchars($caption) . '</figcaption></figure>';
    },
    $html
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title) ?> — Astrophotography Blog</title>
    <link rel="icon" type="image/png" href="/images/favicon.png">
    <link rel="stylesheet" href="/css/style.css?ver=2">
    <link rel="stylesheet" href="/blog-manager/blog-manager.css?ver=1.2">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
</head>
<body>
<div class="blog-page">
    <div class="post-nav">
        <a href="index.php" class="blog-back-btn" title="Back to blog">
            <i class="fa-solid fa-reply"></i>
        </a>
    </div>

    <article class="blog-post">
        <header class="post-header">
            <?php if ($date): ?>
            <div class="post-date"><?= date('F j, Y', strtotime($date)) ?></div>
            <?php endif; ?>
            <h1 class="post-title"><?= htmlspecialchars($title) ?></h1>
            <?php if (!empty($tags)): ?>
            <div class="blog-card-tags">
                <?php foreach ($tags as $tag): ?>
                <span class="blog-tag"><?= htmlspecialchars(trim($tag)) ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </header>

        <div class="post-body">
            <?= $html ?>
        </div>

        <footer class="post-footer">
            <a href="index.php" class="post-back-link">
                <i class="fa-solid fa-arrow-left"></i> Back to all posts
            </a>
        </footer>
    </article>
</div>
</body>
</html>
