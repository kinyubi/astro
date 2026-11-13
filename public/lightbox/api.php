<?php
// ============================================================
// lightbox/api.php — JSON API for the Lightbox page
//
//   GET  ?action=dsos                       all project folders + per-type counts
//   GET  ?action=resolve&q=andro            titling.py-style fragment match
//   GET  ?action=images&project=<folder>&type=fav[&hidden=1]
//        hidden=1 includes IGNORE/bust sessions and GalleryImages.IsHidden images
//   POST ?action=rate   JSON {project, session, file, rating (1-5|null), note}
// ============================================================

require_once __DIR__ . '/../admin/auth_api.php';
require_once __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');

function lb_out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $action = $_GET['action'] ?? '';

    if (!is_dir(lb_root())) {
        lb_out(['error' => 'MyWorks folder not found: ' . lb_root()], 500);
    }

    switch ($action) {

    case 'dsos':
        $db = lb_try_db();
        $names = lb_common_names($db);
        $hidden = lb_hidden_bases($db);
        $out = [];
        foreach (lb_project_dirs() as $folder) {
            $c = lb_counts($folder, $hidden);
            if ($c['sessions'] === 0 && count(lb_sessions($folder)) === 0) continue;
            $token = lb_token($folder);
            $out[] = [
                'folder'   => $folder,
                'token'    => $token,
                'name'     => $names[str_replace(' ', '', $token)] ?? null,
                'counts'   => $c['counts'],
                'sessions' => $c['sessions'],
            ];
        }
        lb_out(['dsos' => $out]);

    case 'resolve':
        lb_out(lb_resolve((string)($_GET['q'] ?? '')));

    case 'images':
        $project = (string)($_GET['project'] ?? '');
        $type    = (string)($_GET['type'] ?? 'fav');
        if (!lb_clean_name($project) || !is_dir(lb_join(lb_root(), $project))) {
            lb_out(['error' => "Unknown DSO folder: $project"], 404);
        }
        if (!in_array($type, LB_TYPES, true)) lb_out(['error' => "Unknown type: $type"], 400);

        $db = lb_try_db();
        $showHidden = !empty($_GET['hidden']) || !empty($_GET['ignored']);   // 'ignored' = old name
        $res = lb_images($project, $type, $showHidden, lb_hidden_bases($db));
        $token = lb_token($project);

        $ratings = [];
        $ratingsError = null;
        if ($db) {
            try {
                $ratings = lb_ratings($db, $token);
            } catch (Throwable $e) {
                $ratingsError = lb_is_missing_table($e)
                    ? 'The ImageRatings table doesn\'t exist yet — run dsodb/migrations/migrate_add_image_ratings.py'
                    : 'Ratings unavailable: ' . $e->getMessage();
            }
        } else {
            $ratingsError = 'Database not reachable — ratings are read-only until it is.';
        }
        foreach ($res['images'] as &$img) {
            $r = $ratings[$img['session'] . '/' . $img['file']] ?? null;
            $img['rating'] = $r['rating'] ?? null;
            $img['note']   = $r['note'] ?? '';
        }
        unset($img);

        $names = lb_common_names($db);
        lb_out([
            'project'      => $project,
            'token'        => $token,
            'name'         => $names[str_replace(' ', '', $token)] ?? null,
            'type'         => $type,
            'images'       => $res['images'],
            'sessions'     => $res['sessions'],
            'ratingsError' => $ratingsError,
        ]);

    case 'rate':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') lb_out(['error' => 'POST required'], 405);
        $in = json_decode(file_get_contents('php://input'), true);
        if (!is_array($in)) lb_out(['error' => 'Bad JSON'], 400);
        $project = (string)($in['project'] ?? '');
        $session = (string)($in['session'] ?? '');
        $file    = (string)($in['file'] ?? '');
        if (!lb_safe_path($project, $session, $file)) lb_out(['error' => 'Image not found'], 404);
        $rating = $in['rating'] ?? null;
        if ($rating !== null) {
            $rating = (int)$rating;
            if ($rating < 1 || $rating > 5) lb_out(['error' => 'Rating must be 1-5'], 400);
        }
        $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 2000);
        $db = get_db();
        try {
            lb_save_rating($db, $project, $session, $file, $rating, $note);
        } catch (Throwable $e) {
            if (lb_is_missing_table($e)) {
                lb_out(['error' => 'Run dsodb/migrations/migrate_add_image_ratings.py first'], 500);
            }
            throw $e;
        }
        lb_out(['ok' => true, 'rating' => $rating, 'note' => $note]);

    default:
        lb_out(['error' => 'Unknown action'], 400);
    }
} catch (Throwable $e) {
    lb_out(['error' => $e->getMessage()], 500);
}
