<?php


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

$blogsDir = __DIR__ . '/../blogs';
$posts    = [];

if (is_dir($blogsDir)) {
    foreach (scandir($blogsDir) as $slug) {
        if ($slug === '.' || $slug === '..') continue;
        $mdFile = $blogsDir . '/' . $slug . '/index.md';
        if (!is_file($mdFile)) continue;
        $raw  = file_get_contents($mdFile);
        $meta = parseFrontMatter($raw);
        $posts[] = [
            'slug'      => $slug,
            'title'     => $meta['title']     ?? ucwords(str_replace('-', ' ', $slug)),
            'date'      => $meta['date']       ?? '',
            'summary'   => $meta['summary']    ?? '',
            'thumbnail' => $meta['thumbnail']  ?? '',
            'tags'      => isset($meta['tags']) ? array_map('trim', explode(',', trim($meta['tags'], '[]'))) : [],
        ];
    }
}

// Sort newest first
usort($posts, function($a, $b) { return strcmp($b['date'], $a['date']); });
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Astrophotography Blog</title>
    <link rel="icon" type="image/png" href="/images/favicon.png">
    <link rel="stylesheet" href="/css/style.css?ver=2">
    <link rel="stylesheet" href="blog-manager.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
</head>
<body>
<div class="blog-page">
    <div class="blog-header">
        <a class="blog-back-btn" href="/" title="Back to gallery">
            <i class="fa-solid fa-reply"></i>
        </a>
        <h1><i class="fa-solid fa-pen-nib"></i> Astrophotography Blog</h1>
        <p>Thoughts, techniques, and stories from behind the eyepiece</p>
    </div>

    <?php if (empty($posts)): ?>
        <p class="blog-empty">No posts found. Add a folder with an <code>index.md</code> inside <code>public/blogs/</code>.</p>
    <?php else: ?>
    <div class="blog-grid">
        <?php foreach ($posts as $post): ?>
        <a class="blog-card" href="post.php?slug=<?= urlencode($post['slug']) ?>">
            <?php if ($post['thumbnail']): ?>
            <div class="blog-card-thumb">
                <img src="/images/fav/<?= htmlspecialchars($post['thumbnail']) ?>"
                     alt="<?= htmlspecialchars($post['title']) ?>"
                     onerror="this.parentElement.style.display='none'">
            </div>
            <?php else: ?>
            <div class="blog-card-thumb blog-card-thumb--placeholder">
                <i class="fa-solid fa-star-half-stroke"></i>
            </div>
            <?php endif; ?>
            <div class="blog-card-body">
                <?php if ($post['date']): ?>
                <div class="blog-card-date">
                    <?= date('F j, Y', strtotime($post['date'])) ?>
                </div>
                <?php endif; ?>
                <h2 class="blog-card-title"><?= htmlspecialchars($post['title']) ?></h2>
                <?php if ($post['summary']): ?>
                <p class="blog-card-summary"><?= htmlspecialchars($post['summary']) ?></p>
                <?php endif; ?>
                <?php if (!empty($post['tags'])): ?>
                <div class="blog-card-tags">
                    <?php foreach ($post['tags'] as $tag): ?>
                    <span class="blog-tag"><?= htmlspecialchars(trim($tag)) ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <span class="blog-read-more">Read more <i class="fa-solid fa-arrow-right"></i></span>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
