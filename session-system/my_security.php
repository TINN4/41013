<?php
/** "My Security": e-mail address ownership, e-mail sign-in code setting (where policy allows) and password change. */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/authflow.php';
$user = ssms_require(null);
$uid = (int)$user['id'];
$err = [];

/** Sensitive self-service changes need the current password again. */
function ssms_self_reauth(array $user, string $pw): ?string {
    $rl = 'selfsec:u:' . $user['id'];
    if (ssms_rl_blocked($rl) > 0) return 'Too many attempts. Try again later.';
    if (!ssms_verify_password($user, $pw)) { ssms_rl_fail($rl, 5, 600, 900); return 'Current password is incorrect.'; }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $policy = ssms_mfa_policy($user);

    if ($action === 'change_email') {
        $new = ssms_email_for_entry((string)($_POST['new_email'] ?? ''));
        if ($e = ssms_self_reauth($user, (string)($_POST['password'] ?? ''))) $err[] = $e;
        elseif ($new === null) $err[] = 'Enter a valid e-mail address.';
        elseif (ssms_rl_blocked('emailchg:u:' . $uid) > 0) $err[] = 'Too many e-mail changes. Try again later.';
        else {
            ssms_rl_fail('emailchg:u:' . $uid, 5, 3600, 3600);
            $st = ssms_email_begin_verification($uid, $new, $uid, false);
            ssms_audit('email.change_self', $uid, $st === 'sent' ? 'ok' : 'failed', ['status' => $st]);
            if ($st === 'sent' && ssms_user_email_verified($user)) {          // tell the CURRENT address that a change was requested
                ssms_mail_send($user['email'], 'E-mail change requested on your SSMS account',
                    "A request was made to change the sign-in e-mail address of your account ({$user['username']}) to " . ssms_mask_email($new) . ".\n\nYour current address stays active until the new one is confirmed. If this was not you, change your password now and tell the System Administrator." . ssms_mail_footer());
            }
            [$t, $m] = ssms_email_status_message($st, $new);
            ssms_flash($t, $st === 'sent' ? "A confirmation link was sent to " . ssms_mask_email($new) . ". Your current address stays in use until you confirm the new one." : $m);
            ssms_redirect('my_security.php');
        }
    }
    elseif ($action === 'resend_verification' && !empty($user['pending_email'])) {
        if (ssms_rl_blocked('emailchg:u:' . $uid) > 0) $err[] = 'Too many requests. Try again later.';
        else {
            ssms_rl_fail('emailchg:u:' . $uid, 5, 3600, 3600);
            $st = ssms_email_resend_verification($uid);
            [$t, $m] = ssms_email_status_message($st, (string)$user['pending_email']['address']);
            ssms_flash($t, $m); ssms_redirect('my_security.php');
        }
    }
    elseif ($action === 'enable_otp' && $policy === 'optional') {
        if ($e = ssms_self_reauth($user, (string)($_POST['password'] ?? ''))) $err[] = $e;
        elseif (!ssms_user_email_verified($user)) $err[] = 'Verify an e-mail address first.';
        else {
            $m = $user['mfa'] ?? []; $m['email_otp'] = true;
            ssms_update('users', $uid, ['mfa' => $m, 'session_version' => (int)$user['session_version'] + 1]);
            ssms_audit('mfa.optin_enabled', $uid, 'ok');
            ssms_destroy_session(); ssms_start_session();
            ssms_flash('ok', 'E-mail sign-in codes are on. Please sign in again to confirm they reach you.');
            ssms_redirect('login.php');
        }
    }
    elseif ($action === 'disable_otp') {
        if ($policy === 'required') $err[] = 'E-mail sign-in codes are mandatory for your account and cannot be turned off.';
        elseif ($e = ssms_self_reauth($user, (string)($_POST['password'] ?? ''))) $err[] = $e;
        else {
            $m = $user['mfa'] ?? []; $m['email_otp'] = false;
            ssms_update('users', $uid, ['mfa' => $m]);
            ssms_audit('mfa.optin_disabled', $uid, 'ok');
            ssms_flash('ok', 'E-mail sign-in codes are turned off for your account.');
            ssms_redirect('my_security.php');
        }
    }
    elseif ($action === 'change_password' && !empty($user['password_hash'])) {
        $cur = (string)($_POST['current'] ?? ''); $new = (string)($_POST['new'] ?? ''); $conf = (string)($_POST['confirm'] ?? '');
        if ($e = ssms_self_reauth($user, $cur)) { $err[] = $e; ssms_audit('auth.password_change', $uid, 'failed'); }
        elseif ($new !== $conf) $err[] = 'New passwords do not match.';
        elseif ($pe = ssms_password_policy_error($new, $user['username'])) $err[] = $pe;
        else {
            $sv = (int)$user['session_version'] + 1;
            ssms_update('users', $uid, ['password_hash' => password_hash($new, PASSWORD_DEFAULT), 'session_version' => $sv]);
            $_SESSION['sv'] = $sv;                       // keep THIS session, revoke all others
            ssms_audit('auth.password_changed', $uid, 'ok');
            ssms_flash('ok', 'Password changed. Other devices were signed out.');
            ssms_redirect('my_security.php');
        }
    }
}
$user = ssms_user_by_id($uid);
$policy = ssms_mfa_policy($user);
$gate = ssms_mfa_gate($user);
$verified = ssms_user_email_verified($user);
$pe = $user['pending_email']['address'] ?? null;

$page_title = 'My Security'; $page_sub = 'E-mail address, sign-in code and password'; $active_page = '';
include __DIR__ . '/includes/header.php';
?>
<?php foreach ($err as $e): ?><div class="alert alert-bad" role="alert"><i class="fa-solid fa-circle-exclamation"></i> <?= ssms_e($e) ?></div><?php endforeach; ?>

<div class="card">
  <div class="card-head"><div><h3>Sign-in e-mail address</h3>
    <p>Sign-in codes go only to a <strong>verified</strong> address that belongs to you. Policy for your role: <strong><?= $policy === 'required' ? 'Required' : 'Optional' ?></strong>.</p></div></div>
  <p>Verified address: <?= $verified ? ssms_e($user['email']) . ' <span class="badge badge-ok">Verified</span>' : '<span class="badge badge-warn">None</span>' ?></p>
  <?php if ($pe): ?>
    <p>Waiting for confirmation: <strong><?= ssms_e($pe) ?></strong> <span class="badge badge-grey">Unverified</span> &mdash; open the link we e-mailed to that address.</p>
    <form method="POST" style="margin-bottom:14px;"><?= ssms_csrf_field() ?><input type="hidden" name="action" value="resend_verification"><button class="btn btn-ghost btn-sm" type="submit">Send the confirmation link again</button></form>
  <?php endif; ?>
  <form method="POST" autocomplete="off" style="max-width:380px;">
    <?= ssms_csrf_field() ?><input type="hidden" name="action" value="change_email">
    <h4 style="margin:0 0 8px;"><?= $verified ? 'Change' : 'Add' ?> e-mail address</h4>
    <div class="form-group"><label for="ce-new">New e-mail address</label><input type="email" id="ce-new" name="new_email" maxlength="254" required></div>
    <?php if (!empty($user['password_hash'])): ?><div class="form-group"><label for="ce-pw">Current password</label><input type="password" id="ce-pw" name="password" autocomplete="current-password" required></div><?php endif; ?>
    <button class="btn btn-navy" type="submit">Send confirmation link</button>
  </form>
</div>

<div class="card">
  <div class="card-head"><div><h3>E-mail sign-in code</h3>
    <p>Status: <?= $gate === 'otp' ? '<span class="badge badge-ok">On</span>' : ($gate === 'blocked' ? '<span class="badge badge-bad">Blocked: verify your e-mail</span>' : '<span class="badge badge-grey">Off</span>') ?></p></div></div>
  <?php if ($policy === 'required'): ?>
    <p style="font-size:13px;color:var(--ink-600);">A 6-digit code is e-mailed to your verified address every time you sign in. This is mandatory for your role. If you cannot receive e-mail, ask the System Administrator to start the e-mail recovery process.</p>
  <?php elseif (ssms_mfa_optin($user)): ?>
    <form method="POST" autocomplete="off" style="max-width:360px;" data-confirm="Turn off e-mail sign-in codes?">
      <?= ssms_csrf_field() ?><input type="hidden" name="action" value="disable_otp">
      <div class="form-group"><label for="do-pw">Current password</label><input type="password" id="do-pw" name="password" autocomplete="current-password" required></div>
      <button class="btn btn-danger" type="submit">Turn off</button>
    </form>
  <?php elseif ($verified): ?>
    <p style="font-size:13px;">Optional for your role but recommended. You will be signed out and asked for a code at your next sign-in.</p>
    <form method="POST" autocomplete="off" style="max-width:360px;">
      <?= ssms_csrf_field() ?><input type="hidden" name="action" value="enable_otp">
      <div class="form-group"><label for="eo-pw">Current password</label><input type="password" id="eo-pw" name="password" autocomplete="current-password" required></div>
      <button class="btn btn-navy" type="submit">Turn on e-mail sign-in codes</button>
    </form>
  <?php else: ?>
    <p style="font-size:13px;">Verify an e-mail address above to be able to turn this on.</p>
  <?php endif; ?>
</div>

<?php if (!empty($user['password_hash'])): ?>
<div class="card">
  <div class="card-head"><div><h3>Change password</h3><p>Minimum 12 characters. Changing it signs out your other devices.</p></div></div>
  <form method="POST" autocomplete="off" style="max-width:360px;">
    <?= ssms_csrf_field() ?><input type="hidden" name="action" value="change_password">
    <div class="form-group"><label for="cp-cur">Current password</label><input type="password" id="cp-cur" name="current" autocomplete="current-password" required></div>
    <div class="form-group"><label for="cp-new">New password</label><input type="password" id="cp-new" name="new" autocomplete="new-password" minlength="12" required></div>
    <div class="form-group"><label for="cp-conf">Confirm new password</label><input type="password" id="cp-conf" name="confirm" autocomplete="new-password" minlength="12" required></div>
    <button class="btn btn-navy" type="submit">Change password</button>
  </form>
</div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
