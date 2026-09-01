<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/store.php';

if (empty($_SESSION['login_csrf'])) {
    $_SESSION['login_csrf'] = bin2hex(random_bytes(32));
}

// Staff accounts (secretary/admin) now live in the database, not in this
// file. On a brand-new install the staff_users table is empty, so we seed
// one account here automatically the first time the page loads — after
// that this block does nothing. Change the password any time by updating
// the staff_users row (or add a "change password" form later).
$staffUsers = ssms_read('staff_users');
if (!$staffUsers) {
    ssms_insert('staff_users', [
        'username'      => 'admin',
        'password_hash' => password_hash('admin123', PASSWORD_DEFAULT),
        'name'          => "Secretary's Office",
    ]);
    $staffUsers = ssms_read('staff_users');
}

$error = '';
$lockoutUntilTimestamp = null; // fed to JS below for a live, reload-proof countdown
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// Brute-force lockout: 5 wrong attempts locks that IP out of the login
// form for 10 minutes, tracked in the login_throttle table (auto-created,
// same pattern as every other table here). This check runs on EVERY page
// load — not just after a failed POST — so if the page is reloaded, or
// opened fresh, mid-lockout, the remaining time shown is still accurate:
// the source of truth is the locked_until timestamp stored server-side,
// never anything kept only in the browser.
$throttle = ssms_read('login_throttle');
$entry = null;
foreach ($throttle as $t) { if ($t['ip'] === $ip) { $entry = $t; break; } }

$lockedUntil = $entry['locked_until'] ?? null;
$isLockedOut = $lockedUntil && strtotime($lockedUntil) > time();
if ($isLockedOut) {
    $minutesLeft = (int)ceil((strtotime($lockedUntil) - time()) / 60);
    $error = "Too many failed attempts. Please try again in {$minutesLeft} minute" . ($minutesLeft === 1 ? '' : 's') . '.';
    $lockoutUntilTimestamp = strtotime($lockedUntil);
}

if (isset($_POST['login']) && !$isLockedOut) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!hash_equals($_SESSION['login_csrf'], $_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please try again.';
        $_SESSION['login_csrf'] = bin2hex(random_bytes(32));
    } else {
        $match = null;
        foreach ($staffUsers as $u) {
            if (hash_equals((string)$u['username'], $username)) { $match = $u; break; }
        }

        if ($match && password_verify($password, $match['password_hash'])) {
            if ($entry) ssms_delete('login_throttle', $entry['id']); // reset on success
            // Rotate the session ID on every successful login so a session ID
            // that existed before authentication can never be reused to hijack
            // the now-authenticated session (session fixation).
            session_regenerate_id(true);
            $_SESSION['ssms_user'] = $match['username'];
            $_SESSION['ssms_name'] = $match['name'];
            header('Location: modules/scheduling.php');
            exit();
        } else {
            $attempts = ($entry['attempts'] ?? 0) + 1;
            $fields = ['ip' => $ip, 'attempts' => $attempts, 'last_attempt' => date('Y-m-d H:i:s')];
            if ($attempts >= 5) {
                $newLockedUntil = date('Y-m-d H:i:s', time() + 10 * 60);
                $fields['locked_until'] = $newLockedUntil;
                $error = 'Too many failed attempts. Please try again in 10 minutes.';
                $lockoutUntilTimestamp = strtotime($newLockedUntil);
            } else {
                $fields['locked_until'] = null;
                $error = 'Invalid username or password. ' . (5 - $attempts) . ' attempt' . ((5 - $attempts) === 1 ? '' : 's') . ' remaining before a temporary lockout.';
            }
            if ($entry) { ssms_update('login_throttle', $entry['id'], $fields); }
            else { ssms_insert('login_throttle', $fields); }
        }
    }
}

// Already logged in? go straight to dashboard.
if (!empty($_SESSION['ssms_user'])) {
    header('Location: modules/scheduling.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login &mdash; Session and Legislative Meeting Management System</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="assets/style.css">
<script>
(function () {
  try {
    var saved = localStorage.getItem('ssms_theme');
    if (saved === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
  } catch (e) { /* localStorage unavailable — default to light */ }
})();
</script>
</head>
<body>

<div class="login-wrap">

  <!-- LEFT: brand panel (desktop only) -->
  <div class="login-side">
    <div class="login-side-inner">
      <span class="eyebrow"><i class="fa-solid fa-landmark-dome"></i> Sangguniang Panlungsod ng San Jose del Monte</span>
      <h1>Session and Legislative Meeting Management System</h1>
      <p>A dedicated workspace for scheduling sessions, preparing agendas, tracking attendance and quorum, documenting proceedings, and generating official minutes &mdash; in real time.</p>
    </div>
  </div>

  <!-- RIGHT: login form -->
  <div class="login-form-col">
    <div class="login-card">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;">
        <div class="lc-seal"><i class="fa-solid fa-gavel"></i></div>
        <button type="button" class="theme-toggle" id="ssms-theme-toggle" title="Toggle dark mode" aria-label="Toggle dark mode">
          <i class="fa-solid fa-moon"></i><i class="fa-solid fa-sun"></i>
        </button>
      </div>
      <h2>Welcome back</h2>
      <p class="sub">Sign in to manage sessions, agendas, and minutes.</p>

      <?php if ($error): ?>
        <div class="login-error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
      <?php elseif (($_GET['reason'] ?? '') === 'idle'): ?>
        <div class="login-error" style="background:#eef2ff;border-color:#c7d2fe;color:#3730a3;"><i class="fa-solid fa-clock"></i> You were signed out after being inactive for a while. Please log in again.</div>
      <?php endif; ?>

      <form method="POST" action="login.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['login_csrf'], ENT_QUOTES, 'UTF-8') ?>">
        <div class="field">
          <label for="username">Username</label>
          <div class="input-wrap">
            <i class="fa-solid fa-user"></i>
            <input type="text" id="username" name="username" placeholder="admin" autocomplete="username" required>
          </div>
        </div>
        <div class="field">
          <label for="password">Password</label>
          <div class="input-wrap">
            <i class="fa-solid fa-lock"></i>
            <input type="password" id="password" name="password" placeholder="********" autocomplete="current-password" required>
            <button type="button" id="pw-toggle" onclick="ssmsTogglePw()" aria-label="Show password" style="background:none;border:0;cursor:pointer;color:var(--ink-600);padding:0 4px;"><i class="fa-solid fa-eye"></i></button>
          </div>
        </div>
        <button type="submit" name="login" class="btn-primary" <?= $isLockedOut ? 'disabled' : '' ?>><i class="fa-solid fa-right-to-bracket"></i> Login to the Portal</button>
      </form>

      <a href="qr_login.php" class="btn-primary" style="margin-top:14px; background:linear-gradient(135deg, var(--gold-500), var(--gold-400)); color:var(--navy-950); box-shadow:0 10px 24px rgba(201,162,39,0.25);"><i class="fa-solid fa-qrcode"></i> Council Member / Staff? Scan QR Badge</a>

      <a href="../index.html" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to main portal</a>
    </div>
  </div>

</div>

<script>
function ssmsTogglePw() {
  const input = document.getElementById('password');
  const icon = document.querySelector('#pw-toggle i');
  const isHidden = input.type === 'password';
  input.type = isHidden ? 'text' : 'password';
  icon.className = isHidden ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
}

(function () {
  const themeBtn = document.getElementById('ssms-theme-toggle');
  if (!themeBtn) return;
  themeBtn.addEventListener('click', function () {
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    if (isDark) { document.documentElement.removeAttribute('data-theme'); }
    else { document.documentElement.setAttribute('data-theme', 'dark'); }
    try { localStorage.setItem('ssms_theme', isDark ? 'light' : 'dark'); } catch (e) { /* private browsing — theme still applies for this view */ }
  });
})();

<?php if ($lockoutUntilTimestamp): ?>
// Live countdown for the lockout message. The source of truth is always
// the server (login_throttle.locked_until) — this timer is purely
// cosmetic, so reloading the page, closing the tab, or the countdown
// hitting 0 early due to clock drift never lets anyone log in early;
// the PHP check above re-verifies the real remaining time on every load.
(function () {
  const unlockAt = <?= (int)$lockoutUntilTimestamp ?> * 1000; // ms, server-computed
  const errorBox = document.querySelector('.login-error');
  if (!errorBox) return;

  function tick() {
    const msLeft = unlockAt - Date.now();
    if (msLeft <= 0) {
      errorBox.innerHTML = '<i class="fa-solid fa-circle-check"></i> You can try logging in again now.';
      const btn = document.querySelector('button[name="login"]');
      if (btn) btn.disabled = false;
      return; // stop counting; if the user submits early due to clock drift, the server re-checks anyway
    }
    const totalSeconds = Math.ceil(msLeft / 1000);
    const m = Math.floor(totalSeconds / 60);
    const s = totalSeconds % 60;
    errorBox.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> Too many failed attempts. Please try again in '
      + m + ':' + String(s).padStart(2, '0') + '.';
    setTimeout(tick, 1000);
  }
  tick();
})();
<?php endif; ?>
</script>

</body>
</html>
