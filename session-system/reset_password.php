<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/authflow.php';
ssms_start_session();

// Keep the token out of the address bar/history/referrers: stash in the server session, redirect to a clean URL.
if (isset($_GET['token'])) {
    $_SESSION['pw_token'] = preg_match('/^[a-f0-9]{64}$/', (string)$_GET['token']) ? $_GET['token'] : '';
    ssms_redirect('reset_password.php');
}
$token = (string)($_SESSION['pw_token'] ?? '');
function ssms_find_by_token(string $t): ?array {
    if ($t === '') return null;
    $h = hash('sha256', $t);
    foreach (ssms_read('users') as $u)
        if (!empty($u['pwreset']['hash']) && hash_equals($u['pwreset']['hash'], $h) && $u['pwreset']['expires'] > time() && !empty($u['active'])) return $u;
    return null;
}
$user = ssms_find_by_token($token);
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ssms_csrf_check();
    $rl = 'pwreset:ip:' . ssms_ip();
    if (ssms_rl_blocked($rl) > 0) $error = 'Too many attempts. Please wait.';
    elseif (!$user) { ssms_rl_fail($rl, 10, 900, 900); $error = 'This link is invalid, expired, or already used.'; }
    else {
        $new = (string)($_POST['new'] ?? '');
        if ($new !== (string)($_POST['confirm'] ?? '')) $error = 'Passwords do not match.';
        elseif ($pe = ssms_password_policy_error($new, $user['username'])) $error = $pe;
        else {
            $ok = false;
            ssms_mutate('users', function (array $rows) use ($user, $new, &$ok) {   // single-use: consume under lock
                foreach ($rows as &$r) if ((int)$r['id'] === (int)$user['id'] && !empty($r['pwreset']['hash'])) {
                    $r['password_hash'] = password_hash($new, PASSWORD_DEFAULT); $r['pwreset'] = null;
                    $r['session_version'] = (int)$r['session_version'] + 1; $ok = true;
                }
                return $rows;
            });
            if ($ok) {
                unset($_SESSION['pw_token']);
                ssms_audit('auth.password_reset_completed', $user['id'], 'ok', [], ['id' => $user['id'], 'role' => $user['role']]);
                ssms_flash('ok', 'Password set. Please sign in.');
                ssms_redirect('login.php');
            }
            $error = 'This link has already been used.';
        }
    }
}
ssms_auth_start('Set password', $user ? 'Choose a password' : 'Link not valid', $user ? 'Minimum 12 characters.' : 'Ask the System Administrator for a new link.', 'fa-key');
if ($error): ?><div class="login-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i> <?= ssms_e($error) ?></div><?php endif;
if ($user): ?>
      <form method="POST" action="reset_password.php" autocomplete="off">
        <?= ssms_csrf_field() ?>
        <div class="field"><label for="new">New password</label><div class="input-wrap"><i class="fa-solid fa-lock" aria-hidden="true"></i><input type="password" id="new" name="new" minlength="12" autocomplete="new-password" required></div></div>
        <div class="field"><label for="confirm">Confirm password</label><div class="input-wrap"><i class="fa-solid fa-lock" aria-hidden="true"></i><input type="password" id="confirm" name="confirm" minlength="12" autocomplete="new-password" required></div></div>
        <button type="submit" class="btn-primary"><i class="fa-solid fa-check"></i> Save password</button>
      </form>
<?php endif; ?>
      <a href="login.php" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to login</a>
<?php ssms_auth_end();
