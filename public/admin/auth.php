<?php
// ============================================================
// auth.php  —  Session-based authentication for DSO Admin
// Include at the top of every admin page via require_once
// ============================================================

require_once __DIR__ . '/config.php';

// Allow unauthenticated access from localhost — matches the bypass already
// used in auth_api.php. Requests from any other origin still require login.
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
$is_local = in_array($remote, ['127.0.0.1', '::1', 'localhost'], true);

if ($is_local) {
    return;
}

session_name('dso_admin');
session_set_cookie_params(['path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
session_start();

// Already logged in — nothing to do (unless session has timed out)
if (!empty($_SESSION['authenticated'])) {
    if (!empty($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > ADMIN_SESSION_TIMEOUT)) {
        // Session expired — clear and force re-login
        $_SESSION = [];
        session_destroy();
        header('Location: ' . strtok($_SERVER['PHP_SELF'], '?') . '?expired=1');
        exit;
    }
    $_SESSION['LAST_ACTIVITY'] = time();
    return;
}

// Handle login POST
$login_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'], $_POST['password'])) {
    if ($_POST['username'] === ADMIN_USERNAME && $_POST['password'] === ADMIN_PASSWORD) {
        $_SESSION['authenticated'] = true;
        $_SESSION['username']      = ADMIN_USERNAME;
        $_SESSION['LAST_ACTIVITY'] = time();
        // Redirect to GET to prevent form resubmission on refresh
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } else {
        $login_error = 'Invalid username or password.';
    }
}

// Not authenticated — show login form and stop
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>DSO Admin — Login</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --bg:      #0d1117;
    --surface: #161b22;
    --border:  #30363d;
    --accent:  #58a6ff;
    --danger:  #f85149;
    --text:    #c9d1d9;
    --muted:   #8b949e;
    --radius:  6px;
  }
  body {
    background: var(--bg); color: var(--text);
    font-family: 'Segoe UI', system-ui, sans-serif; font-size: 14px;
    min-height: 100vh; display: flex; align-items: center; justify-content: center;
  }
  .card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 10px; padding: 36px 40px; width: 100%; max-width: 380px;
  }
  .logo { text-align: center; font-size: 32px; margin-bottom: 8px; }
  h1 { text-align: center; font-size: 18px; color: var(--accent); margin-bottom: 4px; }
  .subtitle { text-align: center; color: var(--muted); font-size: 13px; margin-bottom: 28px; }
  .field { display: flex; flex-direction: column; gap: 5px; margin-bottom: 16px; }
  .field label { font-size: 12px; color: var(--muted); font-weight: 500; }
  .field input {
    background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius);
    color: var(--text); padding: 9px 12px; font-size: 14px; outline: none; width: 100%;
    transition: border-color 0.15s;
  }
  .field input:focus { border-color: var(--accent); }
  .error {
    background: rgba(248,81,73,0.1); border: 1px solid var(--danger);
    border-radius: var(--radius); color: var(--danger);
    padding: 8px 12px; font-size: 13px; margin-bottom: 16px;
  }
  .btn-login {
    width: 100%; background: var(--accent); border: none; border-radius: var(--radius);
    color: #000; font-weight: 600; font-size: 14px; padding: 10px; cursor: pointer;
    margin-top: 4px;
  }
  .btn-login:hover { opacity: 0.85; }
</style>
</head>
<body>
<div class="card">
  <div class="logo">🔭</div>
  <h1>DSO Admin</h1>
  <div class="subtitle">Deep Sky Object Database</div>

  <?php if (isset($_GET['expired'])): ?>
    <div class="error" style="background:rgba(210,153,34,0.1); border-color:var(--warn); color:var(--warn);">Your session expired. Please sign in again.</div>
  <?php endif; ?>
  <?php if ($login_error): ?>
    <div class="error"><?= htmlspecialchars($login_error) ?></div>
  <?php endif; ?>

  <form method="POST" action="" autocomplete="on">
    <div class="field">
      <label for="username">Username</label>
      <input
        type="text"
        id="username"
        name="username"
        autocomplete="username"
        placeholder="Username"
        autofocus
        required
      >
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input
        type="password"
        id="password"
        name="password"
        autocomplete="current-password"
        placeholder="Password"
        required
      >
    </div>
    <button type="submit" class="btn-login">Sign In</button>
  </form>
</div>
</body>
</html>
<?php
// Stop executing the calling page — login form has been rendered
exit;
