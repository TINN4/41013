<?php
/**
 * Second step of login: enter the 6-digit code e-mailed to the account's verified address.
 * Reachable only while a short-lived, server-side pending state exists; that state grants no access.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/authflow.php';
ssms_start_session();

$pending = ssms_pending();
if (!$pending) {
    if (ssms_current_user()) ssms_redirect('dashboard.php');
    ssms_flash('bad', 'Your sign-in expired. Please start again.');
    ssms_redirect('login.php');
}
$user = ssms_user_by_id((int)$pending['uid']);
// Account disabled, locked, or changed (password / role / e-mail) since the password step => start over.
if (!$user || empty($user['active']) || !empty($user['locked']) || (int)($user['session_version'] ?? 1) !== (int)$pending['sv'] || ssms_mfa_gate($user) !== 'otp') {
    ssms_pending_clear();
    ssms_flash('bad', 'Your sign-in could not be completed. Please sign in again.');
    ssms_redirect('login.php');
}
$actor = ['id' => $user['id'], 'role' => $user['role']];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ssms_csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $allowed = ['csrf', 'action'] + ($action === 'verify' ? [2 => 'code'] : []);
    if (array_diff(array_keys($_POST), $allowed) || !in_array($action, ['verify', 'resend'], true)) {
        http_response_code(400);
        ssms_audit('otp.request', $user['id'], 'denied', ['reason' => 'unexpected_fields'], $actor);
        exit('Bad request.');
    }
    if ($action === 'resend') {
        if (time() < (int)$pending['resend_at']) {
            $error = 'Please wait ' . ((int)$pending['resend_at'] - time()) . ' second(s) before requesting another code.';
        } else {
            $r = ssms_otp_issue($user, $pending['txid']);
            $_SESSION['pending']['resend_at'] = in_array($r['status'], ['sent', 'cooldown'], true) ? time() + (int)$r['wait'] : 0;
            $_SESSION['pending']['tries'] = 0;
            [$t, $m] = ssms_otp_notice($r, (string)$user['email']);
            if ($t === 'ok') ssms_flash('ok', $m); else $error = $m;
            if ($r['status'] === 'rate_limited') { ssms_pending_clear(); ssms_flash('bad', $m); ssms_redirect('login.php'); }
        }
    } else {
        $rlKey = 'mfa:u:' . $user['id'];
        $wait = ssms_rl_blocked($rlKey);
        $code = preg_replace('/\s+/', '', (string)($_POST['code'] ?? ''));
        if ($wait > 0) {
            $error = 'Too many incorrect codes. Please wait ' . ceil($wait / 60) . ' minute(s).';
            ssms_audit('otp.verify', $user['id'], 'throttled', [], $actor);
        } elseif (!preg_match('/^\d{6}$/', $code)) {
            $error = 'Enter the 6-digit code from your e-mail.';
        } else {
            $r = ssms_otp_verify($user, $pending['txid'], $code);
            if ($r['status'] === 'ok') {
                ssms_rl_clear($rlKey);
                $fresh = ssms_user_by_id((int)$user['id']);
                if (!$fresh || empty($fresh['active']) || !empty($fresh['locked']) || ssms_mfa_gate($fresh) !== 'otp') { ssms_pending_clear(false); ssms_redirect('login.php'); }
                $via = $pending['via']; $extra = $pending['extra'] ?? [];
                ssms_audit('otp.verify', $user['id'], 'ok', [], $actor);
                ssms_establish_session($fresh, $via, $extra, true);             // regenerates the session id, clears pending state
                ssms_audit('auth.login', $user['id'], 'ok', ['via' => $via, 'mfa' => 'email_otp'], $actor);
                ssms_redirect('dashboard.php');
            }
            ssms_audit('otp.verify', $user['id'], 'failed', ['result' => $r['status']], $actor);
            if ($r['status'] === 'invalid' || $r['status'] === 'exhausted') ssms_rl_fail($rlKey, 10, 900, 900);
            if ($r['status'] === 'exhausted') {
                ssms_pending_clear(false);
                ssms_flash('bad', 'Too many incorrect codes. That code is no longer valid. Please sign in again.');
                ssms_redirect('login.php');
            }
            $error = match ($r['status']) {
                'invalid' => 'That code is not correct. ' . $r['left'] . ' attempt(s) left for this code.',
                'expired' => 'That code has expired. Press "Send a new code".',
                default   => 'No valid code is active for this sign-in. Press "Send a new code".',
            };
        }
    }
}
$waitResend = max(0, (int)($_SESSION['pending']['resend_at'] ?? 0) - time());

ssms_auth_start('Verify', 'Check your e-mail', 'Enter the 6-digit sign-in code sent to ' . ssms_mask_email((string)$user['email']) . '.', 'fa-envelope-circle-check');
?>
      <?php if ($error): ?><div class="login-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i> <?= ssms_e($error) ?></div><?php endif; ?>
      <form method="POST" action="mfa.php" autocomplete="off">
        <?= ssms_csrf_field() ?>
        <input type="hidden" name="action" value="verify">
        <div class="field">
          <label for="code">Verification code</label>
          <div class="input-wrap">
            <i class="fa-solid fa-key" aria-hidden="true"></i>
            <input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code" maxlength="6" placeholder="123456" required autofocus>
          </div>
        </div>
        <button type="submit" class="btn-primary"><i class="fa-solid fa-unlock"></i> Verify</button>
      </form>
      <form method="POST" action="mfa.php" style="margin-top:12px;">
        <?= ssms_csrf_field() ?>
        <input type="hidden" name="action" value="resend">
        <button type="submit" class="back-link" style="background:none;border:0;cursor:pointer;" <?= $waitResend > 0 ? 'disabled' : '' ?>><i class="fa-solid fa-paper-plane"></i> Send a new code<?= $waitResend > 0 ? ' (available in ' . $waitResend . 's)' : '' ?></button>
      </form>
      <p style="margin-top:10px;font-size:12.5px;">The code expires in <?= (int)(ssms_otp_ttl() / 60) ?> minutes and works once. A new code cancels the previous one. Do not share it with anyone.</p>
      <form method="POST" action="logout.php"><?= ssms_csrf_field() ?><button type="submit" class="back-link" style="background:none;border:0;cursor:pointer;"><i class="fa-solid fa-arrow-left"></i> Cancel and return to login</button></form>
<?php ssms_auth_end();
