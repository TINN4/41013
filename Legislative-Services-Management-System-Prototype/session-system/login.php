<?php
session_start();
require_once __DIR__ . '/includes/store.php';

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
if (isset($_POST['login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $match = null;
    foreach ($staffUsers as $u) {
        if (hash_equals((string)$u['username'], $username)) { $match = $u; break; }
    }

    if ($match && password_verify($password, $match['password_hash'])) {
        // Rotate the session ID on every successful login so a session ID
        // that existed before authentication can never be reused to hijack
        // the now-authenticated session (session fixation).
        session_regenerate_id(true);
        $_SESSION['ssms_user'] = $match['username'];
        $_SESSION['ssms_name'] = $match['name'];
        header('Location: modules/scheduling.php');
        exit();
    } else {
        $error = 'Invalid username or password.';
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
</head>
<body>

<div class="login-wrap">

  <!-- LEFT: brand panel (desktop only) -->
  <div class="login-side">
    <div class="login-side-inner">
      <span class="eyebrow"><i class="fa-solid fa-landmark-dome"></i> Sangguniang Panlungsod ng San Jose del Monte</span>
      <h1>Session and Legislative Meeting Management System</h1>
      <p>A dedicated workspace for scheduling sessions, preparing agendas, tracking attendance and quorum, documenting proceedings, and generating official minutes &mdash; in real time.</p>
      <ul>
        <li><i class="fa-solid fa-calendar-check"></i> Session Scheduling</li>
        <li><i class="fa-solid fa-list-check"></i> Agenda Preparation</li>
        <li><i class="fa-solid fa-user-check"></i> Attendance &amp; Quorum Monitoring</li>
        <li><i class="fa-solid fa-file-lines"></i> Proceedings Documentation</li>
        <li><i class="fa-solid fa-file-signature"></i> Minutes Generation</li>
        <li><i class="fa-solid fa-tower-broadcast"></i> Real-Time Session Tracking</li>
      </ul>
    </div>
  </div>

  <!-- RIGHT: login form -->
  <div class="login-form-col">
    <div class="login-card">
      <div class="lc-seal"><i class="fa-solid fa-gavel"></i></div>
      <h2>Welcome back</h2>
      <p class="sub">Sign in to manage sessions, agendas, and minutes.</p>

      <?php if ($error): ?>
        <div class="login-error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <form method="POST" action="login.php">
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
          </div>
        </div>
        <button type="submit" name="login" class="btn-primary"><i class="fa-solid fa-right-to-bracket"></i> Login to the Portal</button>
      </form>

      <a href="qr_login.php" class="btn-primary" style="margin-top:14px; background:linear-gradient(135deg, var(--gold-500), var(--gold-400)); color:var(--navy-950); box-shadow:0 10px 24px rgba(201,162,39,0.25);"><i class="fa-solid fa-qrcode"></i> Council Member / Staff? Scan QR Badge</a>

      <a href="../index.html" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to main portal</a>
    </div>
  </div>

</div>

</body>
</html>
