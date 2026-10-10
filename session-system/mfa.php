<?php
/** Second step of login. Reachable only while a short-lived, server-side pending state exists. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/authflow.php';
ssms_start_session();

$pending = ssms_pending();
if (!$pending) {
    if (ssms_current_user()) ssms_redirect('dashboard.php');
    ssms_flash('bad', 'Your sign-in expired. Please start again.');
    ssms_redirect('login.php');
}
$user = ssms_user_by_id($pending['uid']);
if (!$user || empty($user['active']) || !empty($user['locked'])) { unset($_SESSION['pending']); ssms_redirect('login.php'); }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ssms_csrf_check();
    $rlKey = 'mfa:u:' . $user['id'];
    $wait = ssms_rl_blocked($rlKey);
    $mode = ($_POST['mode'] ?? 'totp') === 'recovery' ? 'recovery' : 'totp';
    $code = ssms_text((string)($_POST['code'] ?? ''), 32);
    if ($wait > 0) {
        $error = 'Too many incorrect codes. Please wait ' . ceil($wait / 60) . ' minute(s).';
        ssms_audit('mfa.challenge', $user['id'], 'throttled', [], ['id' => $user['id'], 'role' => $user['role']]);
    } else {
        $ok = false; $remaining = null; $cfgErr = false;
        if ($mode === 'recovery') {
            $remaining = ssms_mfa_consume_recovery($user['id'], $code);
            $ok = $remaining !== null;
        } else {
            $r = ssms_mfa_consume_totp($user['id'], $code);
            if ($r === null) $cfgErr = true; else $ok = $r;
        }
        $actor = ['id' => $user['id'], 'role' => $user['role']];
        if ($cfgErr) {
            $error = 'Your authenticator could not be verified because of a server configuration problem. Contact the System Administrator.';
            ssms_audit('mfa.challenge', $user['id'], 'error', ['reason' => 'secret_undecryptable'], $actor);
        } elseif ($ok) {
            ssms_rl_clear($rlKey);
            $via = $pending['via']; $extra = $pending['extra'] ?? [];
            ssms_audit($mode === 'recovery' ? 'mfa.recovery_code_used' : 'mfa.challenge', $user['id'], 'ok', $mode === 'recovery' ? ['remaining' => $remaining] : [], $actor);
            ssms_establish_session(ssms_user_by_id($user['id']), $via, $extra);
            $_SESSION['mfa_passed'] = true;
            if ($mode === 'recovery') ssms_flash('warn', "You signed in with a recovery code ($remaining left). Consider regenerating your recovery codes.");
            ssms_audit('auth.login', $user['id'], 'ok', ['via' => $via, 'mfa' => $mode], $actor);
            ssms_redirect('dashboard.php');
        } else {
            ssms_rl_fail($rlKey, SSMS_MFA_MAX_TRIES, 600, 900);
            $_SESSION['pending']['tries']++;
            ssms_audit('mfa.challenge', $user['id'], 'failed', ['mode' => $mode], $actor);
            if ($_SESSION['pending']['tries'] >= SSMS_MFA_MAX_TRIES) {
                unset($_SESSION['pending']);
                ssms_flash('bad', 'Too many incorrect codes. Please sign in again.');
                ssms_redirect('login.php');
            }
            $error = 'That code is not valid or has already been used.';
        }
    }
}
$recoveryMode = (($_POST['mode'] ?? $_GET['mode'] ?? '') === 'recovery');

ssms_auth_start('Verify', 'Two-step verification', $recoveryMode ? 'Enter one of your unused recovery codes.' : 'Enter the 6-digit code from your authenticator app.', 'fa-shield-halved');
?>
      <?php if ($error): ?><div class="login-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i> <?= ssms_e($error) ?></div><?php endif; ?>
      <form method="POST" action="mfa.php" autocomplete="off">
        <?= ssms_csrf_field() ?>
        <input type="hidden" name="mode" value="<?= $recoveryMode ? 'recovery' : 'totp' ?>">
        <div class="field">
          <label for="code"><?= $recoveryMode ? 'Recovery code' : 'Authenticator code' ?></label>
          <div class="input-wrap">
            <i class="fa-solid fa-key" aria-hidden="true"></i>
            <input type="text" id="code" name="code" inputmode="<?= $recoveryMode ? 'text' : 'numeric' ?>" autocomplete="one-time-code" maxlength="<?= $recoveryMode ? 16 : 7 ?>" placeholder="<?= $recoveryMode ? 'XXXXX-XXXXX' : '123456' ?>" required autofocus>
          </div>
        </div>
        <button type="submit" class="btn-primary"><i class="fa-solid fa-unlock"></i> Verify</button>
      </form>
      <p style="margin-top:14px;font-size:13px;">
        <?php if ($recoveryMode): ?><a href="mfa.php">Use my authenticator app instead</a>
        <?php else: ?><a href="mfa.php?mode=recovery">Lost your device? Use a recovery code</a><?php endif; ?>
      </p>
      <form method="POST" action="logout.php"><?= ssms_csrf_field() ?><button type="submit" class="back-link" style="background:none;border:0;cursor:pointer;"><i class="fa-solid fa-arrow-left"></i> Cancel and return to login</button></form>
<?php ssms_auth_end();
