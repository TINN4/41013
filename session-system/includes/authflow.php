<?php
/** Login / MFA workflow primitives shared by login.php, mfa.php, qr login API, my_security.php. */
require_once __DIR__ . '/otp.php';

const SSMS_PENDING_TTL = 900;           // first factor -> code entry must complete within 15 min (the code itself lives 5 min)

/** Fixed dummy hash so unknown usernames cost the same time as wrong passwords. */
function ssms_dummy_hash(): string {
    static $h = null; return $h ??= password_hash('not-a-real-password', PASSWORD_DEFAULT);
}

function ssms_password_policy_error(string $pw, string $username = ''): ?string {
    if (mb_strlen($pw) < 12) return 'Password must be at least 12 characters.';
    if (mb_strlen($pw) > 200) return 'Password is too long.';
    if ($username !== '' && stripos($pw, $username) !== false) return 'Password must not contain your username.';
    if (preg_match('/^(.)\1+$/', $pw) || stripos($pw, 'password') !== false || stripos($pw, '123456') !== false) return 'Password is too easy to guess.';
    return null;
}

function ssms_verify_password(?array $user, string $pw): bool {
    $hash = $user['password_hash'] ?? null;
    if (!$hash) { password_verify($pw, ssms_dummy_hash()); return false; }
    return password_verify($pw, $hash);
}

/** Password stage. Returns the user on success, null on any failure (no reason revealed). */
function ssms_password_login(string $username, string $password, string &$publicError): ?array {
    $ip = ssms_ip(); $uk = 'login:u:' . strtolower($username); $ik = 'login:ip:' . $ip;
    $wait = max(ssms_rl_blocked($uk), ssms_rl_blocked($ik));
    if ($wait > 0) {
        ssms_audit('auth.login', $username, 'throttled');
        $publicError = 'Too many failed attempts. Please wait ' . ceil($wait / 60) . ' minute(s) and try again.';
        return null;
    }
    $user = ssms_user_by_username($username);
    $ok = ssms_verify_password($user, $password);
    $usable = $user && !empty($user['active']) && empty($user['locked']);
    if ($ok && $usable) {
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) ssms_update('users', $user['id'], ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        ssms_rl_clear($uk);
        return $user;
    }
    ssms_rl_fail($uk, 5, 900, 900);
    ssms_rl_fail($ik, 20, 900, 900);
    ssms_audit('auth.login', $user['id'] ?? $username, 'failed', ['reason' => $user ? ($ok ? 'inactive_or_locked' : 'bad_credentials') : 'unknown_user'], $user ? ['id' => $user['id'], 'role' => $user['role']] : ['id' => null, 'role' => null]);
    $publicError = 'Invalid username or password.';
    return null;
}

/**
 * After the first factor (password or QR badge) the SERVER decides what happens next from stored account data:
 *   none    -> full session now (only where policy allows password-only sign-in)
 *   otp     -> restricted pending state, code e-mailed to the verified address, no access yet
 *   blocked -> refused (MFA applies but no verified address exists); nothing is issued
 * Returns the page to redirect to.
 */
function ssms_after_first_factor(array $user, string $method, array $extra = []): string {
    ssms_start_session();
    $actor = ['id' => $user['id'], 'role' => $user['role'] ?? null];
    $gate = ssms_mfa_gate($user);
    if ($gate === 'blocked') {
        session_regenerate_id(true);
        $_SESSION = ['csrf' => bin2hex(random_bytes(32))];
        ssms_audit('auth.login', $user['id'], 'blocked', ['via' => $method, 'reason' => 'mfa_required_no_verified_email'], $actor);
        ssms_flash('bad', 'Your account requires a verified e-mail address for sign-in codes, and none is verified yet. Open the verification link sent to your e-mail, or ask the System Administrator to resend it.');
        return 'login.php';
    }
    if ($gate === 'otp') {
        session_regenerate_id(true);
        $txid = bin2hex(random_bytes(16));
        $_SESSION = ['csrf' => bin2hex(random_bytes(32)), 'pending' => [
            'uid' => (int)$user['id'], 'via' => $method, 't' => time(), 'tries' => 0, 'extra' => $extra,
            'txid' => $txid, 'sv' => (int)($user['session_version'] ?? 1), 'resend_at' => 0]];
        ssms_audit('auth.first_factor_ok_mfa_pending', $user['id'], 'ok', ['via' => $method], $actor);
        $r = ssms_otp_issue($user, $txid);
        if (in_array($r['status'], ['sent', 'cooldown'], true)) $_SESSION['pending']['resend_at'] = time() + (int)$r['wait'];
        [$t, $m] = ssms_otp_notice($r, (string)$user['email']);
        ssms_flash($t, $m);
        return 'mfa.php';
    }
    ssms_establish_session($user, $method, $extra, false);
    ssms_audit('auth.login', $user['id'], 'ok', ['via' => $method, 'mfa' => 'not_required_by_policy'], $actor);
    return 'dashboard.php';
}

/** The pending (password-verified, code not yet entered) login, or null if absent/expired/stale. */
function ssms_pending(): ?array {
    ssms_start_session();
    $p = $_SESSION['pending'] ?? null;
    if (!$p) return null;
    if (time() - $p['t'] > SSMS_PENDING_TTL) { unset($_SESSION['pending']); return null; }
    return $p;
}
function ssms_pending_clear(bool $cancelChallenge = true): void {
    if ($cancelChallenge && !empty($_SESSION['pending']['txid'])) ssms_otp_cancel_tx((string)$_SESSION['pending']['txid']);
    unset($_SESSION['pending']);
}

/** Shared HTML shell for pre-login / account pages (same look as the original login). */
function ssms_auth_start(string $title, string $heading, string $sub, string $icon = 'fa-gavel'): void {
    ssms_start_session();
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= ssms_e($title) ?> &mdash; Session and Legislative Meeting Management System</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="login-wrap">
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
  <div class="login-form-col">
    <div class="login-card">
      <div class="lc-seal"><i class="fa-solid <?= ssms_e($icon) ?>"></i></div>
      <h2><?= ssms_e($heading) ?></h2>
      <p class="sub"><?= ssms_e($sub) ?></p>
<?php foreach (ssms_flash_pull() as $f): ?>
      <div class="<?= $f['type'] === 'ok' ? 'alert alert-ok' : 'login-error' ?>" role="alert"><i class="fa-solid <?= $f['type'] === 'ok' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i> <?= ssms_e($f['msg']) ?></div>
<?php endforeach;
}
function ssms_auth_end(): void { ?>
    </div>
  </div>
</div>
<script src="assets/app.js"></script>
</body>
</html>
<?php }
