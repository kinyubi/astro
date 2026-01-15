<?php
/**
 * Cache Management Utility
 * Route: /cache-manager.php
 * 
 * View and manage cached DSO reports
 */

// Set headers
header('Content-Type: text/html; charset=utf-8');

$cacheDir = __DIR__ . DIRECTORY_SEPARATOR . 'cache';
$action = isset($_GET['action']) ? $_GET['action'] : '';
$file = isset($_GET['file']) ? basename($_GET['file']) : ''; // Sanitize filename

// Handle actions
$message = '';
if ($action === 'delete' && $file) {
    $filePath = $cacheDir . DIRECTORY_SEPARATOR . $file;
    if (file_exists($filePath) && strpos($file, 'dso_report_') === 0) {
        if (unlink($filePath)) {
            $message = "<div class='success'>✓ Deleted: $file</div>";
        } else {
            $message = "<div class='error'>✗ Failed to delete: $file</div>";
        }
    }
} elseif ($action === 'clear_all') {
    $deleted = 0;
    if (is_dir($cacheDir)) {
        foreach (glob($cacheDir . DIRECTORY_SEPARATOR . 'dso_report_*.html') as $file) {
            if (unlink($file)) {
                $deleted++;
            }
        }
    }
    $message = "<div class='success'>✓ Cleared $deleted cache file(s)</div>";
}

// Get cache files
$cacheFiles = [];
if (is_dir($cacheDir)) {
    foreach (glob($cacheDir . DIRECTORY_SEPARATOR . 'dso_report_*.html') as $file) {
        $basename = basename($file);
        // Extract date from filename (format: dso_report_YYYY-MM-DD.html)
        preg_match('/dso_report_(\d{4}-\d{2}-\d{2})\.html/', $basename, $matches);
        $date = isset($matches[1]) ? $matches[1] : 'Unknown';
        
        $cacheFiles[] = [
            'filename' => $basename,
            'date' => $date,
            'size' => filesize($file),
            'age' => time() - filemtime($file),
            'modified' => filemtime($file)
        ];
    }
    // Sort by date descending
    usort($cacheFiles, function($a, $b) {
        return strcmp($b['date'], $a['date']);
    });
}

function formatBytes($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
}

function formatAge($seconds) {
    if ($seconds < 60) return $seconds . ' sec';
    if ($seconds < 3600) return round($seconds / 60) . ' min';
    if ($seconds < 86400) return round($seconds / 3600, 1) . ' hours';
    return round($seconds / 86400, 1) . ' days';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cache Manager - DSO Reports</title>
    <link rel="icon" type="image/png" href="favicon.png">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            background: #0a0e27;
            color: #e0e0e0;
        }
        h1 {
            color: #4a9eff;
            border-bottom: 2px solid #4a9eff;
            padding-bottom: 10px;
        }
        .info {
            background: #1a1f3a;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            border-left: 4px solid #4a9eff;
        }
        .success {
            background: #1a3a1f;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            border-left: 4px solid #7ec8a3;
            color: #7ec8a3;
        }
        .error {
            background: #3a1a1f;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            border-left: 4px solid #ff6b6b;
            color: #ff6b6b;
        }
        .actions {
            margin: 20px 0;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .btn {
            padding: 10px 20px;
            background: #2a3f5f;
            color: #4a9eff;
            text-decoration: none;
            border-radius: 4px;
            border: 1px solid #4a9eff;
            cursor: pointer;
            display: inline-block;
            transition: background 0.2s;
        }
        .btn:hover {
            background: #3a4f6f;
        }
        .btn-danger {
            background: #4a2020;
            border-color: #ff6b6b;
            color: #ff6b6b;
        }
        .btn-danger:hover {
            background: #5a3030;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background: #1a1f3a;
            border-radius: 8px;
            overflow: hidden;
        }
        th {
            background: #2a3f5f;
            color: #4a9eff;
            padding: 12px;
            text-align: left;
            font-weight: 600;
        }
        td {
            padding: 10px 12px;
            border-bottom: 1px solid #2a3f5f;
        }
        tr:hover {
            background: #243447;
        }
        .empty {
            text-align: center;
            padding: 40px;
            color: #7a7a7a;
        }
        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #4a9eff;
            text-decoration: none;
        }
        .back-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <a href="/vis" class="back-link">← Back to DSO Visibility Report</a>
    
    <h1>Cache Manager</h1>
    
    <?php if ($message): ?>
        <?php echo $message; ?>
    <?php endif; ?>
    
    <div class="info">
        <p><strong>Cache Directory:</strong> <?php echo htmlspecialchars($cacheDir); ?></p>
        <p><strong>Total Cache Files:</strong> <?php echo count($cacheFiles); ?></p>
        <p><strong>Cache Max Age:</strong> 24 hours</p>
    </div>
    
    <div class="actions">
        <a href="?action=clear_all" class="btn btn-danger" 
           onclick="return confirm('Are you sure you want to clear all cached reports?')">
            Clear All Cache
        </a>
    </div>
    
    <?php if (empty($cacheFiles)): ?>
        <div class="empty">
            <p>No cached reports found.</p>
            <p>Visit <a href="/vis" class="btn">DSO Visibility Report</a> to generate a report.</p>
        </div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>File Size</th>
                    <th>Age</th>
                    <th>Last Modified</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cacheFiles as $file): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($file['date']); ?></strong></td>
                        <td><?php echo formatBytes($file['size']); ?></td>
                        <td><?php echo formatAge($file['age']); ?></td>
                        <td><?php echo date('Y-m-d H:i:s', $file['modified']); ?></td>
                        <td>
                            <a href="/vis?date=<?php echo urlencode($file['date']); ?>" 
                               class="btn" style="font-size: 0.9em; padding: 5px 10px;">View</a>
                            <a href="/vis?date=<?php echo urlencode($file['date']); ?>&rebuild=1" 
                               class="btn" style="font-size: 0.9em; padding: 5px 10px;">Rebuild</a>
                            <a href="?action=delete&file=<?php echo urlencode($file['filename']); ?>" 
                               class="btn btn-danger" style="font-size: 0.9em; padding: 5px 10px;"
                               onclick="return confirm('Delete cache for <?php echo htmlspecialchars($file['date']); ?>?')">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</body>
</html>
