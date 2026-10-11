<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/authflow.php';
ssms_start_session();

if (ssms_current_user()) ssms_redirect('dashboard.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ssms_csrf_check();
    $username = ssms_text((string)($_POST['username'] ?? ''), 100);
    $password = (string)($_POST['password'] ?? '');
    $user = ssms_password_login($username, $password, $error);
    if ($user) ssms_redirect(ssms_after_first_factor($user, 'password'));
}
if (isset($_GET['expired'])) $error = $error ?: 'Your session has ended. Please sign in again.';

ssms_auth_start('Login', 'Welcome back', 'Sign in to manage sessions, agendas, and minutes.');
?>
      <?php if ($error): ?>
        <div class="login-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i> <?= ssms_e($error) ?></div>
      <?php endif; ?>

      <form method="POST" action="login.php" autocomplete="on">
        <?= ssms_csrf_field() ?>
        <div class="field">
          <label for="username">Username</label>
          <div class="input-wrap">
            <i class="fa-solid fa-user" aria-hidden="true"></i>
            <input type="text" id="username" name="username" placeholder="Username" autocomplete="username" maxlength="100" required autofocus>
          </div>
        </div>
        <div class="field">
          <label for="password">Password</label>
          <div class="input-wrap">
            <i class="fa-solid fa-lock" aria-hidden="true"></i>
            <input type="password" id="password" name="password" placeholder="********" autocomplete="current-password" maxlength="200" required>
          </div>
        </div>
        <button type="submit" name="login" class="btn-primary"><i class="fa-solid fa-right-to-bracket"></i> Login to the Portal</button>
      </form>

      <a href="qr_login.php" class="btn-primary" style="margin-top:14px; background:linear-gradient(135deg, var(--gold-500), var(--gold-400)); color:var(--navy-950); box-shadow:0 10px 24px rgba(201,162,39,0.25);"><i class="fa-solid fa-qrcode"></i> Council Member / Staff? Scan QR Badge</a>

      <a href="../index.html" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to main portal</a>
<?php ssms_auth_end();
