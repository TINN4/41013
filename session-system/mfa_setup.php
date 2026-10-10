<?php
/** "My Security": TOTP enrollment, recovery codes, MFA disable (where policy allows), password change. */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/authflow.php';
$user = ssms_require(null, true);                 // reachable while MFA enrollment is still mandatory
$uid = (int)$user['id'];
$policy = ssms_mfa_policy($user);
$enabled = ssms_mfa_enabled($user);
$newCodes = null; $setupSecret = null; $err = [];

if (!ssms_app_key()) {
    $err[] = 'MFA cannot be configured: SSMS_APP_KEY is missing on the server. Run "php tools/setup.php" or set it in config/.env.';
}

/** Re-verify password (if the account has one) and a current TOTP code for sensitive operations. */
function ssms_reverify(array $user, string $pw, string $code): ?string {
    if (!empty($user['password_hash']) && !password_verify($pw, $user['password_hash'])) return 'Current password is incorrect.';
    $wait = ssms_rl_blocked('mfa:u:' . $user['id']);
    if ($wait > 0) return 'Too many attempts. Try again later.';
    $r = ssms_mfa_consume_totp((int)$user['id'], $code);
    if (!$r) { ssms_rl_fail('mfa:u:' . $user['id'], 5, 600, 900); return 'Authenticator code is not valid.'; }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ssms_app_key()) {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'begin' && !$enabled) {
        $_SESSION['mfa_setup_secret'] = ssms_b32_encode(random_bytes(20));        // 160-bit CSPRNG secret
        ssms_audit('mfa.enroll_started', $uid, 'ok');
    }
    elseif ($action === 'cancel_setup') { unset($_SESSION['mfa_setup_secret']); }
    elseif ($action === 'confirm' && !$enabled && !empty($_SESSION['mfa_setup_secret'])) {
        $b32 = $_SESSION['mfa_setup_secret'];
        $rl = 'mfa:u:' . $uid;
        if (ssms_rl_blocked($rl) > 0) $err[] = 'Too many attempts. Try again later.';
        else {
            $c = ssms_totp_verify(ssms_b32_decode($b32), (string)($_POST['code'] ?? ''));
            if ($c === null) { ssms_rl_fail($rl, 5, 600, 900); $err[] = 'That code did not match. Check the time on your phone and try again.'; ssms_audit('mfa.enroll_confirm', $uid, 'failed'); }
            else {
                [$plain, $stored] = ssms_recovery_generate(10);
                $enc = ssms_secret_encrypt(ssms_b32_decode($b32));
                ssms_update('users', $uid, ['mfa' => ['enabled' => true, 'secret_enc' => $enc, 'last_counter' => $c, 'recovery' => $stored, 'enrolled_at' => ssms_now()]]);
                unset($_SESSION['mfa_setup_secret'], $_SESSION['mfa_enroll_only']);
                $_SESSION['mfa_passed'] = true;
                session_regenerate_id(true);
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                ssms_audit('mfa.enrolled', $uid, 'ok');
                $newCodes = $plain; $enabled = true;
                $user = ssms_user_by_id($uid);
            }
        }
    }
    elseif ($action === 'regenerate' && $enabled) {
        $e = ssms_reverify($user, (string)($_POST['password'] ?? ''), (string)($_POST['code'] ?? ''));
        if ($e) { $err[] = $e; ssms_audit('mfa.recovery_regen', $uid, 'failed'); }
        else {
            [$plain, $stored] = ssms_recovery_generate(10);
            $m = $user['mfa']; $m['recovery'] = $stored;
            ssms_update('users', $uid, ['mfa' => $m]);
            $newCodes = $plain; ssms_audit('mfa.recovery_regenerated', $uid, 'ok');
        }
    }
    elseif ($action === 'disable' && $enabled) {
        if ($policy === 'required') $err[] = 'MFA is mandatory for your account and cannot be turned off.';
        else {
            $e = ssms_reverify($user, (string)($_POST['password'] ?? ''), (string)($_POST['code'] ?? ''));
            if ($e) { $err[] = $e; ssms_audit('mfa.disable', $uid, 'failed'); }
            else {
                ssms_update('users', $uid, ['mfa' => ['enabled' => false, 'secret_enc' => null, 'last_counter' => 0, 'recovery' => [], 'enrolled_at' => null]]);
                $_SESSION['mfa_passed'] = false;
                ssms_audit('mfa.disabled_by_user', $uid, 'ok');
                ssms_flash('ok', 'Two-step verification has been turned off.');
                ssms_redirect('mfa_setup.php');
            }
        }
    }
    elseif ($action === 'change_password' && !empty($user['password_hash'])) {
        $cur = (string)($_POST['current'] ?? ''); $new = (string)($_POST['new'] ?? ''); $conf = (string)($_POST['confirm'] ?? '');
        if (!password_verify($cur, $user['password_hash'])) { $err[] = 'Current password is incorrect.'; ssms_audit('auth.password_change', $uid, 'failed'); }
        elseif ($new !== $conf) $err[] = 'New passwords do not match.';
        elseif ($pe = ssms_password_policy_error($new, $user['username'])) $err[] = $pe;
        else {
            $sv = (int)$user['session_version'] + 1;
            ssms_update('users', $uid, ['password_hash' => password_hash($new, PASSWORD_DEFAULT), 'session_version' => $sv]);
            $_SESSION['sv'] = $sv;                       // keep THIS session, revoke all others
            ssms_audit('auth.password_changed', $uid, 'ok');
            ssms_flash('ok', 'Password changed. Other devices were signed out.');
            ssms_redirect('mfa_setup.php');
        }
    }
}
$setupSecret = $_SESSION['mfa_setup_secret'] ?? null;
$user = ssms_user_by_id($uid);
$remaining = $enabled ? count(array_filter($user['mfa']['recovery'] ?? [], fn($r) => $r['used_at'] === null)) : 0;

$page_title = 'My Security'; $page_sub = 'Two-step verification, recovery codes and password'; $active_page = '';
include __DIR__ . '/includes/header.php';
?>
<?php foreach ($err as $e): ?><div class="alert alert-bad" role="alert"><i class="fa-solid fa-circle-exclamation"></i> <?= ssms_e($e) ?></div><?php endforeach; ?>

<?php if (!empty($_SESSION['mfa_enroll_only'])): ?>
  <div class="alert alert-warn" role="alert"><i class="fa-solid fa-shield-halved"></i> Two-step verification is required for your account. Finish enrollment below to continue to the system.</div>
<?php endif; ?>

<?php if ($newCodes): ?>
<div class="card">
  <div class="card-head"><div><h3>Your recovery codes</h3><p>Shown <strong>once</strong>. Store them somewhere safe and offline. Each code works one time.</p></div></div>
  <div class="recovery-grid" id="recovery-codes"><?php foreach ($newCodes as $c): ?><span><?= ssms_e($c) ?></span><?php endforeach; ?></div>
  <p style="margin-top:14px;"><a class="btn btn-navy" href="dashboard.php">I have saved my codes &mdash; continue</a></p>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-head"><div><h3>Two-step verification (authenticator app)</h3>
    <p>Status: <?= $enabled ? '<span class="badge badge-ok">Enabled</span>' : '<span class="badge badge-warn">Not enabled</span>' ?>
       &middot; Policy for your role: <strong><?= $policy === 'required' ? 'Required' : 'Optional' ?></strong></p></div></div>

<?php if (!$enabled && !$setupSecret && ssms_app_key()): ?>
  <p>Use an authenticator app such as Google Authenticator, Microsoft Authenticator, Aegis or 1Password.</p>
  <form method="POST"><?= ssms_csrf_field() ?><input type="hidden" name="action" value="begin"><button class="btn btn-navy" type="submit"><i class="fa-solid fa-qrcode"></i> Begin enrollment</button></form>

<?php elseif (!$enabled && $setupSecret): ?>
  <ol style="line-height:1.7;padding-left:18px;">
    <li>Scan this QR code with your authenticator app.</li>
    <li>Or enter this key manually (time-based, 6 digits):</li>
  </ol>
  <div id="mfa-qr" data-uri="<?= ssms_e(ssms_totp_uri($setupSecret, $user['username'])) ?>" role="img" aria-label="QR code for authenticator enrollment" style="width:200px;margin:8px 0;"></div>
  <div class="code-box" id="manual-key" aria-label="Manual entry key"><?= ssms_e(implode(' ', str_split($setupSecret, 4))) ?></div>
  <form method="POST" style="margin-top:16px;" autocomplete="off">
    <?= ssms_csrf_field() ?><input type="hidden" name="action" value="confirm">
    <div class="form-group" style="max-width:260px;"><label for="code">Enter the 6-digit code to confirm</label>
      <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required></div>
    <button class="btn btn-navy" type="submit"><i class="fa-solid fa-check"></i> Verify and enable</button>
  </form>
  <form method="POST" style="margin-top:8px;"><?= ssms_csrf_field() ?><input type="hidden" name="action" value="cancel_setup"><button class="btn btn-ghost btn-sm" type="submit">Cancel</button></form>
  <script src="assets/vendor/qrcode.js"></script>
  <script>
    (function () {
      var el = document.getElementById('mfa-qr'); if (!el || typeof qrcode === 'undefined') return;
      var q = qrcode(0, 'M'); q.addData(el.getAttribute('data-uri')); q.make();
      el.innerHTML = q.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
    })();
  </script>

<?php elseif ($enabled): ?>
  <p>Recovery codes remaining: <strong><?= $remaining ?></strong>.</p>
  <form method="POST" autocomplete="off" style="max-width:360px;margin-bottom:18px;">
    <?= ssms_csrf_field() ?><input type="hidden" name="action" value="regenerate">
    <h4 style="margin:0 0 8px;">Regenerate recovery codes</h4>
    <p style="font-size:12.5px;margin:0 0 8px;">Re-enter your credentials. Old codes stop working immediately.</p>
    <?php if (!empty($user['password_hash'])): ?><div class="form-group"><label for="rg-pw">Current password</label><input type="password" id="rg-pw" name="password" autocomplete="current-password" required></div><?php endif; ?>
    <div class="form-group"><label for="rg-code">Authenticator code</label><input type="text" id="rg-code" name="code" inputmode="numeric" maxlength="7" required></div>
    <button class="btn btn-ghost" type="submit"><i class="fa-solid fa-rotate"></i> Generate new codes</button>
  </form>
  <?php if ($policy !== 'required'): ?>
  <form method="POST" autocomplete="off" style="max-width:360px;" data-confirm="Turn off two-step verification?">
    <?= ssms_csrf_field() ?><input type="hidden" name="action" value="disable">
    <h4 style="margin:0 0 8px;">Turn off two-step verification</h4>
    <?php if (!empty($user['password_hash'])): ?><div class="form-group"><label for="dis-pw">Current password</label><input type="password" id="dis-pw" name="password" required></div><?php endif; ?>
    <div class="form-group"><label for="dis-code">Authenticator code</label><input type="text" id="dis-code" name="code" inputmode="numeric" maxlength="7" required></div>
    <button class="btn btn-danger" type="submit">Turn off</button>
  </form>
  <?php else: ?>
  <p style="font-size:12.5px;color:var(--ink-600);">MFA is mandatory for your role. If you lose your device, use a recovery code, or ask the System Administrator to start the approved MFA recovery process.</p>
  <?php endif; ?>
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
