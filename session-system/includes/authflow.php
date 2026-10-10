<?php
/** Login / MFA workflow primitives shared by login.php, mfa.php, qr login API, mfa_setup.php. */
require_once __DIR__ . '/mfa.php';

const SSMS_PENDING_TTL = 300;           // password/QR stage -> MFA stage must complete within 5 min
const SSMS_MFA_MAX_TRIES = 5;

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

/** After the first factor: either complete login (no MFA) or park in a restricted pending state. */
function ssms_after_first_factor(array $user, string $method, array $extra = []): string {
    ssms_start_session();
    if (ssms_mfa_enabled($user)) {
        session_regenerate_id(true);
        $_SESSION = ['csrf' => bin2hex(random_bytes(32)), 'pending' => ['uid' => (int)$user['id'], 'via' => $method, 't' => time(), 'tries' => 0, 'extra' => $extra]];
        ssms_audit('auth.first_factor_ok_mfa_pending', $user['id'], 'ok', ['via' => $method], ['id' => $user['id'], 'role' => $user['role']]);
        return 'mfa.php';
    }
    ssms_establish_session($user, $method, $extra);
    ssms_audit('auth.login', $user['id'], 'ok', ['via' => $method, 'mfa' => 'not_enrolled'], ['id' => $user['id'], 'role' => $user['role']]);
    return !empty($_SESSION['mfa_enroll_only']) ? 'mfa_setup.php' : 'dashboard.php';
}

function ssms_pending(): ?array {
    ssms_start_session();
    $p = $_SESSION['pending'] ?? null;
    if (!$p) return null;
    if (time() - $p['t'] > SSMS_PENDING_TTL) { unset($_SESSION['pending']); return null; }
    return $p;
}

/** Atomically verify + record a TOTP code (prevents replay even with concurrent requests). */
function ssms_mfa_consume_totp(int $uid, string $code): ?bool {
    $result = false; $misconfigured = false;
    ssms_mutate('users', function (array $rows) use ($uid, $code, &$result, &$misconfigured) {
        foreach ($rows as &$r) {
            if ((int)$r['id'] !== $uid || empty($r['mfa']['enabled'])) continue;
            $secret = ssms_secret_decrypt((string)$r['mfa']['secret_enc']);
            if ($secret === null) { $misconfigured = true; break; }
            $c = ssms_totp_verify($secret, $code, (int)($r['mfa']['last_counter'] ?? 0));
            if ($c !== null) { $r['mfa']['last_counter'] = $c; $result = true; }
        }
        return $rows;
    });
    return $misconfigured ? null : $result;
}

/** Consume a recovery code. Returns remaining unused count on success, null on failure. */
function ssms_mfa_consume_recovery(int $uid, string $code): ?int {
    $remaining = null;
    $h = ssms_recovery_hash($code);
    ssms_mutate('users', function (array $rows) use ($uid, $h, &$remaining) {
        foreach ($rows as &$r) {
            if ((int)$r['id'] !== $uid) continue;
            foreach ($r['mfa']['recovery'] ?? [] as $i => $rc) {
                if ($rc['used_at'] === null && hash_equals($rc['h'], $h)) {
                    $r['mfa']['recovery'][$i]['used_at'] = ssms_now();
                    $remaining = count(array_filter($r['mfa']['recovery'], fn($x) => $x['used_at'] === null));
                    break;
                }
            }
        }
        return $rows;
    });
    return $remaining;
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
