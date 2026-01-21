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

function executePythonCommand($command): false|string|null
{
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

/**
 * Validate profile name on the PHP side for immediate feedback
 */
function validateProfileName($name) {
    if (empty($name)) {
        return ['valid' => false, 'error' => 'Profile name is required'];
    }
    if (!preg_match('/^[a-z0-9_]+$/', $name)) {
        return ['valid' => false, 'error' => 'Profile name must contain only lowercase letters, numbers, and underscores (no spaces, hyphens, or uppercase characters)'];
    }
    if (strlen($name) > 50) {
        return ['valid' => false, 'error' => 'Profile name must be 50 characters or less'];
    }
    return ['valid' => true, 'error' => null];
}

// Handle form submissions
$message = '';
$messageType = 'info';
$editProfile = null; // Profile being edited

// Check if we're in edit mode
if (isset($_GET['edit']) && !empty($_GET['edit'])) {
    $editProfileName = $_GET['edit'];
    $output = executePythonCommand(sprintf(
        '%s get %s',
        escapeshellarg($profileManagerScript),
        escapeshellarg($editProfileName)
    ));
    $editProfile = json_decode($output, true);
    if (isset($editProfile['error'])) {
        $message = "Profile not found: " . htmlspecialchars($editProfileName);
        $messageType = 'error';
        $editProfile = null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'geocode') {
        // Test geocoding a location
        $location = trim($_POST['location'] ?? '');
        if (empty($location)) {
            $message = "Please enter a location to test";
            $messageType = 'error';
        } else {
            $output = executePythonCommand(sprintf(
                '%s geocode %s',
                escapeshellarg($profileManagerScript),
                escapeshellarg($location)
            ));
            
            $result = json_decode($output, true);
            if ($result && isset($result['success']) && $result['success']) {
                $message = sprintf(
                    "✅ Location found!<br><strong>Display Name:</strong> %s<br><strong>Coordinates:</strong> %.4f, %.4f<br><strong>Timezone:</strong> %s",
                    htmlspecialchars($result['display_name']),
                    $result['latitude'],
                    $result['longitude'],
                    $result['timezone']
                );
                $messageType = 'success';
            } else {
                $errorMsg = $result['error'] ?? "Unknown error";
                $message = "❌ Geocoding failed: " . htmlspecialchars($errorMsg);
                $messageType = 'error';
            }
        }
    } elseif ($action === 'create') {
        // Create new profile
        $profileName = trim($_POST['profile_name'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $minAlt = floatval($_POST['min_altitude'] ?? 18.0);
        $azMin = floatval($_POST['az_min'] ?? 10.0);
        $azMax = floatval($_POST['az_max'] ?? 165.0);
        
        // Validate profile name first
        $validation = validateProfileName($profileName);
        if (!$validation['valid']) {
            $message = "❌ " . $validation['error'];
            $messageType = 'error';
        } elseif (empty($location)) {
            $message = "❌ Location is required";
            $messageType = 'error';
        } else {
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
                $message = sprintf("✅ Profile '<strong>%s</strong>' created successfully!", htmlspecialchars($profileName));
                $messageType = 'success';
            } else {
                $errorMsg = $result['error'] ?? "Unknown error occurred";
                $message = "❌ Failed to create profile: " . htmlspecialchars($errorMsg);
                $messageType = 'error';
            }
        }
    } elseif ($action === 'update') {
        // Update existing profile
        $profileName = trim($_POST['profile_name'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $minAlt = floatval($_POST['min_altitude'] ?? 18.0);
        $azMin = floatval($_POST['az_min'] ?? 10.0);
        $azMax = floatval($_POST['az_max'] ?? 165.0);
        
        if (empty($profileName)) {
            $message = "❌ Profile name is required";
            $messageType = 'error';
        } else {
            $output = executePythonCommand(sprintf(
                '%s update %s --location %s --min-altitude %s --az-min %s --az-max %s',
                escapeshellarg($profileManagerScript),
                escapeshellarg($profileName),
                escapeshellarg($location),
                $minAlt,
                $azMin,
                $azMax
            ));
            
            $result = json_decode($output, true);
            if ($result && isset($result['success']) && $result['success']) {
                $message = sprintf("✅ Profile '<strong>%s</strong>' updated successfully!", htmlspecialchars($profileName));
                $messageType = 'success';
                $editProfile = null; // Exit edit mode
            } else {
                $errorMsg = $result['error'] ?? "Unknown error occurred";
                $message = "❌ Failed to update profile: " . htmlspecialchars($errorMsg);
                $messageType = 'error';
            }
        }
    } elseif ($action === 'delete') {
        $profileName = $_POST['profile_name'] ?? '';
        if (empty($profileName)) {
            $message = "❌ Profile name is required";
            $messageType = 'error';
        } elseif ($profileName === 'default') {
            $message = "❌ Cannot delete the default profile";
            $messageType = 'error';
        } else {
            $output = executePythonCommand(sprintf(
                '%s delete %s',
                escapeshellarg($profileManagerScript),
                escapeshellarg($profileName)
            ));
            
            $result = json_decode($output, true);
            if ($result && isset($result['success']) && $result['success']) {
                $message = sprintf("✅ Profile '<strong>%s</strong>' deleted successfully!", htmlspecialchars($profileName));
                $messageType = 'success';
            } else {
                $errorMsg = $result['error'] ?? "Unknown error occurred";
                $message = "❌ Failed to delete profile: " . htmlspecialchars($errorMsg);
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
    <link rel="icon" type="image/png" href="/images/favicon.png">
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
        .section.edit-mode {
            border: 2px solid #ffd700;
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
        input[type="text"].error,
        input[type="number"].error {
            border-color: #ff6b6b;
        }
        input[type="text"]:read-only {
            background: #1a2a3f;
            color: #888;
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
        .btn-warning {
            background: #5a4a00;
            border-color: #ffd700;
            color: #ffd700;
        }
        .btn-warning:hover {
            background: #6a5a10;
        }
        .btn-danger {
            background: #4a2020;
            border-color: #ff6b6b;
            color: #ff6b6b;
        }
        .btn-danger:hover {
            background: #5a3030;
        }
        .btn-secondary {
            background: #2a3f5f;
            border-color: #888;
            color: #888;
        }
        .btn-secondary:hover {
            background: #3a4f6f;
            color: #aaa;
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
        .profile-card.is-default {
            border-color: #7ec8a3;
        }
        .profile-card h3 {
            margin-top: 0;
            color: #4a9eff;
            border-bottom: 1px solid #4a9eff;
            padding-bottom: 8px;
        }
        .profile-card.is-default h3 {
            color: #7ec8a3;
            border-color: #7ec8a3;
        }
        .profile-card p {
            margin: 8px 0;
            font-size: 0.9em;
        }
        .profile-card .actions {
            margin-top: 15px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .helper-text {
            font-size: 0.85em;
            color: #b8c5d6;
            margin-top: 5px;
        }
        .helper-text.error {
            color: #ff6b6b;
        }
        .two-col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        .validation-rules {
            background: #0a0e27;
            padding: 10px 15px;
            border-radius: 4px;
            margin: 10px 0;
            font-size: 0.85em;
        }
        .validation-rules ul {
            margin: 5px 0;
            padding-left: 20px;
        }
        .validation-rules li {
            margin: 3px 0;
        }
        .validation-rules li.valid {
            color: #7ec8a3;
        }
        .validation-rules li.invalid {
            color: #ff6b6b;
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
    
    <?php if ($editProfile): ?>
    <!-- Edit Profile Section -->
    <div class="section edit-mode">
        <h2>✏️ Edit Profile: <?php echo htmlspecialchars($editProfile['name']); ?></h2>
        
        <form method="POST">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="profile_name" value="<?php echo htmlspecialchars($editProfile['name']); ?>">
            
            <div class="form-group">
                <label>Profile Name</label>
                <input type="text" value="<?php echo htmlspecialchars($editProfile['name']); ?>" readonly>
                <p class="helper-text">Profile names cannot be changed. Create a new profile if you need a different name.</p>
            </div>
            
            <div class="form-group">
                <label for="edit_location">Location *</label>
                <input type="text" id="edit_location" name="location" required
                       value="<?php echo htmlspecialchars($editProfile['location']); ?>"
                       placeholder="e.g., Star, Idaho or New York, NY">
                <p class="helper-text">
                    Current coordinates: <?php echo $editProfile['latitude']; ?>, <?php echo $editProfile['longitude']; ?> 
                    (<?php echo htmlspecialchars($editProfile['timezone']); ?>)
                </p>
            </div>
            
            <div class="two-col">
                <div class="form-group">
                    <label for="edit_min_altitude">Minimum Altitude (degrees)</label>
                    <input type="number" id="edit_min_altitude" name="min_altitude" 
                           value="<?php echo $editProfile['min_altitude']; ?>" min="0" max="90" step="0.1">
                    <p class="helper-text">Objects below this altitude won't be shown</p>
                </div>
                
                <div class="form-group">
                    <label for="edit_az_min">Azimuth Min (degrees)</label>
                    <input type="number" id="edit_az_min" name="az_min" 
                           value="<?php echo $editProfile['az_min']; ?>" min="0" max="360" step="0.1">
                    <p class="helper-text">Minimum azimuth (0° = North)</p>
                </div>
            </div>
            
            <div class="form-group">
                <label for="edit_az_max">Azimuth Max (degrees)</label>
                <input type="number" id="edit_az_max" name="az_max" 
                       value="<?php echo $editProfile['az_max']; ?>" min="0" max="360" step="0.1">
                <p class="helper-text">Maximum azimuth (180° = South)</p>
            </div>
            
            <div style="display: flex; gap: 10px; margin-top: 20px;">
                <button type="submit" class="btn btn-warning">💾 Save Changes</button>
                <a href="/profiles.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
    <?php else: ?>
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
        <form method="POST" id="createForm">
            <input type="hidden" name="action" value="create">
            
            <div class="form-group">
                <label for="profile_name">Profile Name *</label>
                <input type="text" id="profile_name" name="profile_name" required
                       placeholder="e.g., backyard, dark_site, vacation_spot"
                       oninput="validateProfileNameLive(this)">
                <div class="validation-rules" id="validation-rules">
                    <strong>Profile name requirements:</strong>
                    <ul>
                        <li id="rule-lowercase">Use lowercase letters (a-z)</li>
                        <li id="rule-numbers">Numbers (0-9) are allowed</li>
                        <li id="rule-underscore">Underscores (_) are allowed</li>
                        <li id="rule-no-spaces">No spaces or hyphens</li>
                        <li id="rule-no-uppercase">No uppercase letters</li>
                    </ul>
                </div>
            </div>
            
            <div class="form-group">
                <label for="location">Location *</label>
                <input type="text" id="location" name="location" required
                       placeholder="e.g., Star, Idaho or New York, NY">
                <p class="helper-text">City, State or City, Country format works best. Use "Test Geocode" above to verify your location first.</p>
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
            
            <button type="submit" class="btn btn-primary" id="createBtn">✨ Create Profile</button>
        </form>
    </div>
    <?php endif; ?>
    
    <!-- Existing Profiles Section -->
    <div class="section">
        <h2>Existing Profiles (<?php echo count($profiles); ?>)</h2>
        
        <?php if (empty($profiles)): ?>
            <p>No profiles found. Create one above!</p>
        <?php else: ?>
            <div class="profiles-grid">
                <?php foreach ($profiles as $profile): ?>
                    <div class="profile-card <?php echo $profile['name'] === 'default' ? 'is-default' : ''; ?>">
                        <h3>
                            <?php echo htmlspecialchars($profile['name']); ?>
                            <?php if ($profile['name'] === 'default'): ?>
                                <span style="font-size: 0.7em; color: #7ec8a3;">(default)</span>
                            <?php endif; ?>
                        </h3>
                        <p><strong>Location:</strong> <?php echo htmlspecialchars($profile['location']); ?></p>
                        <p><strong>Coordinates:</strong> <?php echo $profile['latitude']; ?>, <?php echo $profile['longitude']; ?></p>
                        <p><strong>Timezone:</strong> <?php echo htmlspecialchars($profile['timezone']); ?></p>
                        <p><strong>Min Altitude:</strong> <?php echo $profile['min_altitude']; ?>°</p>
                        <p><strong>Azimuth:</strong> <?php echo $profile['az_min']; ?>° - <?php echo $profile['az_max']; ?>°</p>
                        
                        <div class="actions">
                            <a href="/vis?profile=<?php echo urlencode($profile['name']); ?>" class="btn">
                                🔭 Use
                            </a>
                            <a href="/profiles.php?edit=<?php echo urlencode($profile['name']); ?>" class="btn btn-warning">
                                ✏️ Edit
                            </a>
                            <?php if ($profile['name'] !== 'default'): ?>
                                <form method="POST" style="display: inline;" 
                                      onsubmit="return confirm('Delete profile \'<?php echo htmlspecialchars($profile['name']); ?>\'? This cannot be undone.')">
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
    
    <script>
        function validateProfileNameLive(input) {
            const name = input.value;
            const rules = {
                'rule-lowercase': /[a-z]/.test(name) || name === '',
                'rule-numbers': true, // Always valid (optional)
                'rule-underscore': true, // Always valid (optional)
                'rule-no-spaces': !/[\s-]/.test(name),
                'rule-no-uppercase': !/[A-Z]/.test(name)
            };
            
            const overallValid = /^[a-z0-9_]*$/.test(name);
            
            // Update visual feedback
            for (const [ruleId, isValid] of Object.entries(rules)) {
                const el = document.getElementById(ruleId);
                if (el) {
                    el.classList.remove('valid', 'invalid');
                    if (name !== '') {
                        el.classList.add(isValid ? 'valid' : 'invalid');
                    }
                }
            }
            
            // Update input styling
            input.classList.toggle('error', !overallValid && name !== '');
            
            // Update button state
            const btn = document.getElementById('createBtn');
            if (btn) {
                btn.disabled = !overallValid && name !== '';
            }
            
            return overallValid;
        }

        document.addEventListener('DOMContentLoaded', function() {
            const createForm = document.getElementById('createForm');
            const profileNameInput = document.getElementById('profile_name');

            if (createForm && profileNameInput) {
                createForm.addEventListener('submit', function(e) {
                    const name = profileNameInput.value.trim();
                    if (!name) {
                        alert('Profile name is required');
                        e.preventDefault();
                        return;
                    }
                    if (!/^[a-z0-9_]+$/.test(name)) {
                        alert('Profile name must contain only lowercase letters, numbers, and underscores.\n\nInvalid characters found. Please fix before submitting.');
                        e.preventDefault();
                        return;
                    }
                });
                
                // Initial validation if there's already a value
                if (profileNameInput.value) {
                    validateProfileNameLive(profileNameInput);
                }
            }
        });
    </script>
</body>
</html>
