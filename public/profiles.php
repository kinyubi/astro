<?php
/**
 * Profile Management Interface
 * Create, edit, and delete location profiles for DSO visibility reports
 */

// Paths
$pythonDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'pythonscripts';
$profileManagerScript = $pythonDir . DIRECTORY_SEPARATOR . 'profile_cli.py';

// Detect OS and set Python path
$isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
if ($isWindows) {
    $pythonExe = $pythonDir . DIRECTORY_SEPARATOR . 'venv' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe';
} else {
    $venvDir = $pythonDir . '/venv';
    $activateScript = $venvDir . '/bin/activate';
}

function executePythonCommand($command) {
    global $pythonExe, $activateScript, $isWindows;
    
    if ($isWindows) {
        $fullCommand = sprintf('"%s" %s 2>&1', $pythonExe, $command);
    } else {
        $fullCommand = sprintf(
            'bash -c "source %s && python %s" 2>&1',
            escapeshellarg($activateScript),
            $command
        );
    }
    
    $output = shell_exec($fullCommand);
    return $output;
}

// Handle form submissions
$message = '';
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'geocode') {
        // Test geocoding a location
        $location = $_POST['location'] ?? '';
        if (!empty($location)) {
            $output = executePythonCommand(sprintf(
                '%s geocode %s',
                escapeshellarg($profileManagerScript),
                escapeshellarg($location)
            ));
            
            $result = json_decode($output, true);
            if ($result && isset($result['success']) && $result['success']) {
                $message = sprintf(
                    "Location found: %s<br>Coordinates: %.4f, %.4f<br>Timezone: %s",
                    htmlspecialchars($result['display_name']),
                    $result['latitude'],
                    $result['longitude'],
                    $result['timezone']
                );
                $messageType = 'success';
            } else {
                $message = "Could not geocode location. Please try a more specific address (e.g., 'New York, NY' or 'London, UK')";
                $messageType = 'error';
            }
        }
    } elseif ($action === 'create') {
        // Create new profile
        $profileName = $_POST['profile_name'] ?? '';
        $location = $_POST['location'] ?? '';
        $minAlt = floatval($_POST['min_altitude'] ?? 18.0);
        $azMin = floatval($_POST['az_min'] ?? 10.0);
        $azMax = floatval($_POST['az_max'] ?? 165.0);
        
        if (!empty($profileName) && !empty($location)) {
            $output = executePythonCommand(sprintf(
                '%s create %s %s --min-altitude %s --az-min %s --az-max %s',
                escapeshellarg($profileManagerScript),
                escapeshellarg($profileName),
                escapeshellarg($location),
                $minAlt,
                $azMin,
                $azMax
            ));
            
            $result = json_decode($output, true);
            if ($result && isset($result['success']) && $result['success']) {
                $message = sprintf("Profile '%s' created successfully!", htmlspecialchars($profileName));
                $messageType = 'success';
            } else {
                $message = $result['error'] ?? "Failed to create profile. Check location name.";
                $messageType = 'error';
            }
        }
    } elseif ($action === 'delete') {
        $profileName = $_POST['profile_name'] ?? '';
        if (!empty($profileName) && $profileName !== 'default') {
            $output = executePythonCommand(sprintf(
                '%s delete %s',
                escapeshellarg($profileManagerScript),
                escapeshellarg($profileName)
            ));
            
            $result = json_decode($output, true);
            if ($result && isset($result['success']) && $result['success']) {
                $message = sprintf("Profile '%s' deleted successfully!", htmlspecialchars($profileName));
                $messageType = 'success';
            } else {
                $message = "Failed to delete profile.";
                $messageType = 'error';
            }
        }
    }
}

// Get list of profiles
$profilesOutput = executePythonCommand(sprintf('%s list', escapeshellarg($profileManagerScript)));
$profiles = json_decode($profilesOutput, true);

if (!is_array($profiles)) {
    $profiles = [];
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile Manager - DSO Visibility</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            background: #0a0e27;
            color: #e0e0e0;
        }
        h1, h2 {
            color: #4a9eff;
            border-bottom: 2px solid #4a9eff;
            padding-bottom: 10px;
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
        .message {
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            border-left: 4px solid;
        }
        .message.success {
            background: #1a3a1f;
            border-color: #7ec8a3;
            color: #7ec8a3;
        }
        .message.error {
            background: #3a1a1f;
            border-color: #ff6b6b;
            color: #ff6b6b;
        }
        .message.info {
            background: #1a1f3a;
            border-color: #4a9eff;
            color: #4a9eff;
        }
        .section {
            background: #1a1f3a;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .form-group {
            margin: 15px 0;
        }
        label {
            display: block;
            color: #4a9eff;
            margin-bottom: 5px;
            font-weight: 600;
        }
        input[type="text"],
        input[type="number"],
        select {
            width: 100%;
            max-width: 400px;
            padding: 10px;
            background: #2a3f5f;
            border: 1px solid #4a9eff;
            border-radius: 4px;
            color: #e0e0e0;
            font-size: 14px;
        }
        input[type="text"]:focus,
        input[type="number"]:focus,
        select:focus {
            outline: none;
            border-color: #7ec8a3;
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
            font-size: 14px;
        }
        .btn:hover {
            background: #3a4f6f;
        }
        .btn-primary {
            background: #4a9eff;
            color: #0a0e27;
            border-color: #4a9eff;
        }
        .btn-primary:hover {
            background: #5aaeff;
        }
        .btn-danger {
            background: #4a2020;
            border-color: #ff6b6b;
            color: #ff6b6b;
        }
        .btn-danger:hover {
            background: #5a3030;
        }
        .profiles-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        .profile-card {
            background: #2a3f5f;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #4a9eff;
        }
        .profile-card h3 {
            margin-top: 0;
            color: #4a9eff;
            border-bottom: 1px solid #4a9eff;
            padding-bottom: 8px;
        }
        .profile-card p {
            margin: 8px 0;
            font-size: 0.9em;
        }
        .profile-card .actions {
            margin-top: 15px;
            display: flex;
            gap: 10px;
        }
        .helper-text {
            font-size: 0.85em;
            color: #b8c5d6;
            margin-top: 5px;
        }
        .two-col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        @media (max-width: 768px) {
            .two-col {
                grid-template-columns: 1fr;
            }
            .profiles-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <a href="/vis" class="back-link">← Back to DSO Report</a>
    
    <h1>📍 Profile Manager</h1>
    
    <?php if ($message): ?>
        <div class="message <?php echo $messageType; ?>">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>
    
    <!-- Create New Profile Section -->
    <div class="section">
        <h2>Create New Profile</h2>
        
        <!-- Geocode Test -->
        <form method="POST" style="margin-bottom: 30px; padding-bottom: 20px; border-bottom: 1px solid #4a9eff;">
            <input type="hidden" name="action" value="geocode">
            <div class="form-group">
                <label for="test_location">Test Location Lookup</label>
                <input type="text" id="test_location" name="location" 
                       placeholder="e.g., New York, NY or London, UK">
                <p class="helper-text">Enter a location to verify geocoding works before creating a profile</p>
            </div>
            <button type="submit" class="btn">🔍 Test Geocode</button>
        </form>
        
        <!-- Create Profile Form -->
        <form method="POST">
            <input type="hidden" name="action" value="create">
            
            <div class="form-group">
                <label for="profile_name">Profile Name *</label>
                <input type="text" id="profile_name" name="profile_name" required
                       pattern="[a-zA-Z0-9_-]+" 
                       placeholder="e.g., backyard, dark-site, vacation-spot">
                <p class="helper-text">Use letters, numbers, hyphens, or underscores only</p>
            </div>
            
            <div class="form-group">
                <label for="location">Location *</label>
                <input type="text" id="location" name="location" required
                       placeholder="e.g., Star, Idaho or New York, NY">
                <p class="helper-text">City, State or City, Country format works best. Latitude, longitude, and timezone will be looked up automatically.</p>
            </div>
            
            <div class="two-col">
                <div class="form-group">
                    <label for="min_altitude">Minimum Altitude (degrees)</label>
                    <input type="number" id="min_altitude" name="min_altitude" 
                           value="18" min="0" max="90" step="0.1">
                    <p class="helper-text">Objects below this altitude won't be shown</p>
                </div>
                
                <div class="form-group">
                    <label for="az_min">Azimuth Min (degrees)</label>
                    <input type="number" id="az_min" name="az_min" 
                           value="10" min="0" max="360" step="0.1">
                    <p class="helper-text">Minimum azimuth (0° = North)</p>
                </div>
            </div>
            
            <div class="form-group">
                <label for="az_max">Azimuth Max (degrees)</label>
                <input type="number" id="az_max" name="az_max" 
                       value="165" min="0" max="360" step="0.1">
                <p class="helper-text">Maximum azimuth (180° = South)</p>
            </div>
            
            <button type="submit" class="btn btn-primary">✨ Create Profile</button>
        </form>
    </div>
    
    <!-- Existing Profiles Section -->
    <div class="section">
        <h2>Existing Profiles (<?php echo count($profiles); ?>)</h2>
        
        <?php if (empty($profiles)): ?>
            <p>No profiles found. Create one above!</p>
        <?php else: ?>
            <div class="profiles-grid">
                <?php foreach ($profiles as $profile): ?>
                    <div class="profile-card">
                        <h3><?php echo htmlspecialchars($profile['name']); ?></h3>
                        <p><strong>Location:</strong> <?php echo htmlspecialchars($profile['location']); ?></p>
                        <p><strong>Coordinates:</strong> <?php echo $profile['latitude']; ?>, <?php echo $profile['longitude']; ?></p>
                        <p><strong>Timezone:</strong> <?php echo htmlspecialchars($profile['timezone']); ?></p>
                        <p><strong>Min Altitude:</strong> <?php echo $profile['min_altitude']; ?>°</p>
                        <p><strong>Azimuth:</strong> <?php echo $profile['az_min']; ?>° - <?php echo $profile['az_max']; ?>°</p>
                        
                        <div class="actions">
                            <a href="/vis?profile=<?php echo urlencode($profile['name']); ?>" class="btn">
                                🔭 Use Profile
                            </a>
                            <?php if ($profile['name'] !== 'default'): ?>
                                <form method="POST" style="display: inline;" 
                                      onsubmit="return confirm('Delete profile \'<?php echo htmlspecialchars($profile['name']); ?>\'?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="profile_name" value="<?php echo htmlspecialchars($profile['name']); ?>">
                                    <button type="submit" class="btn btn-danger">🗑️ Delete</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
