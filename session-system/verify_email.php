<?php
/**
 * Confirm ownership of an e-mail address. The link in the e-mail only DISPLAYS this page; the address is
 * trusted when the visitor presses the button (POST + CSRF), so mail scanners that pre-fetch links cannot verify it.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/authflow.php';
ssms_start_session();

if (isset($_GET['token'])) {                              // keep the token out of the address bar / history / referrers
    $_SESSION['ev_token'] = preg_match('/^[a-f0-9]{64}$/', (string)$_GET['token']) ? $_GET['token'] : '';
    ssms_redirect('verify_email.php');
}
$token = (string)($_SESSION['ev_token'] ?? '');
$h = hash('sha256', $token);
$pendingFor = null;
if ($token !== '') foreach (ssms_read('users') as $u) {
    $p = $u['pending_email'] ?? null;
    if ($p && !empty($p['token_hash']) && hash_equals($p['token_hash'], $h) && ($p['expires'] ?? 0) > time() && !empty($u['active'])) $pendingFor = [$u, $p['address']];
}
$error = ''; $done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ssms_csrf_check();
    $rl = 'emailverify:ip:' . ssms_ip();
    if (ssms_rl_blocked($rl) > 0) $error = 'Too many attempts. Please wait a few minutes.';
    else {
        [$u, $st] = ssms_email_verify_commit($token);
        if ($st === 'ok') {
            unset($_SESSION['ev_token']); $done = true; $pendingFor = null;
            ssms_audit('email.verified', $u['id'], 'ok', [], ['id' => $u['id'], 'role' => $u['role']]);
        } else {
            ssms_rl_fail($rl, 10, 900, 900);
            $error = $st === 'conflict' ? 'This address is already used by another account. Ask the System Administrator.' : 'This link is invalid, expired, or already used.';
            ssms_audit('email.verify', null, 'failed', ['reason' => $st]);
        }
    }
}
ssms_auth_start('Verify e-mail', $done ? 'E-mail confirmed' : ($pendingFor ? 'Confirm your e-mail address' : 'Link not valid'),
    $done ? 'This address will now receive your sign-in codes.' : ($pendingFor ? 'Confirm that ' . ssms_mask_email($pendingFor[1]) . ' is your own inbox.' : 'Ask the System Administrator to send a new link.'), 'fa-envelope-circle-check');
if ($error): ?><div class="login-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i> <?= ssms_e($error) ?></div><?php endif;
if ($pendingFor): ?>
      <form method="POST" action="verify_email.php">
        <?= ssms_csrf_field() ?>
        <button type="submit" class="btn-primary"><i class="fa-solid fa-check"></i> Yes, this is my address</button>
      </form>
<?php endif; ?>
      <a href="login.php" class="back-link"><i class="fa-solid fa-arrow-left"></i> Go to login</a>
<?php ssms_auth_end();
