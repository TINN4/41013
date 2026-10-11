<?php
/**
 * SSMS bootstrap: configuration, hardened session handling, CSRF, flash messages,
 * rate limiting and the request guards used by every protected page/API.
 * Every entry point requires this file (directly or via includes/auth.php).
 */
declare(strict_types=1);

define('SSMS_ROOT', dirname(__DIR__));
define('SSMS_DATA_DIR', getenv('SSMS_DATA_DIR') ?: SSMS_ROOT . '/data');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/domain.php';
require_once __DIR__ . '/useremail.php';
require_once __DIR__ . '/otp.php';

if (ssms_cfg('SSMS_ENV', 'development') === 'production') {
    ini_set('display_errors', '0');
} else {
    ini_set('display_errors', '0');          // never leak traces to the browser, even in dev
}
ini_set('log_errors', '1');
error_reporting(E_ALL);

set_exception_handler(function (Throwable $e) {
    error_log('[SSMS] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    if (!headers_sent()) { http_response_code(500); }
    $json = (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
         || (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/api/') !== false);
    if ($json) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'A server error occurred. Nothing was saved.']);
    } else {
        echo '<!DOCTYPE html><meta charset="utf-8"><title>Error</title><body style="font-family:sans-serif;padding:40px">'
           . '<h2>Something went wrong</h2><p>The request could not be completed and no changes were saved. '
           . 'Please try again or contact the system administrator.</p></body>';
    }
    exit;
});

function ssms_is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' && ssms_cfg('SSMS_TRUST_PROXY', 'false') === 'true');
}

function ssms_security_headers(): void {
    if (headers_sent()) return;
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');
    header('Permissions-Policy: geolocation=(self), camera=(self)');
    if (ssms_is_https()) header('Strict-Transport-Security: max-age=31536000');
}

function ssms_start_session(): void {
    if (session_status() !== PHP_SESSION_NONE) return;
    ssms_security_headers();
    session_name('SSMS_SID');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'secure' => ssms_is_https(),
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

/* ------------------------------------------------------------- helpers */
function ssms_e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function ssms_now(): string { return date('Y-m-d H:i:s'); }
function ssms_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45); }

function ssms_base(): string {
    $s = $_SERVER['SCRIPT_NAME'] ?? '';
    return (strpos($s, '/modules/') !== false || strpos($s, '/api/') !== false) ? '../' : '';
}

/** Redirect to a same-site relative path only (prevents open redirects). */
function ssms_redirect(string $path): void {
    if (preg_match('#^(?:[a-z][a-z0-9+.-]*:|//|\\\\)#i', $path)) { $path = 'login.php'; }
    header('Location: ' . $path);
    exit;
}

/* ----------------------------------------------------------------- CSRF */
function ssms_csrf_token(): string {
    ssms_start_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function ssms_csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . ssms_e(ssms_csrf_token()) . '">';
}
function ssms_csrf_check(): void {
    $sent = (string)($_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    $have = (string)($_SESSION['csrf'] ?? '');
    if ($have === '' || !hash_equals($have, $sent)) {
        ssms_audit('security.csrf_rejected', null, 'denied', ['path' => basename($_SERVER['SCRIPT_NAME'] ?? '')]);
        http_response_code(403);
        exit('Invalid or expired form token. Go back, reload the page and try again.');
    }
}

/* ---------------------------------------------------------------- flash */
function ssms_flash(string $type, string $msg): void {
    ssms_start_session();
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}
function ssms_flash_pull(): array {
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* -------------------------------------------------------- rate limiting */
/** Returns seconds to wait if $key is currently blocked, else 0. */
function ssms_rl_blocked(string $key): int {
    $all = ssms_read_obj('ratelimit');
    $e = $all[$key] ?? null;
    if ($e && !empty($e['until']) && $e['until'] > time()) return (int)$e['until'] - time();
    return 0;
}
function ssms_rl_fail(string $key, int $max, int $windowSec, int $lockSec): void {
    ssms_mutate_obj('ratelimit', function (array $all) use ($key, $max, $windowSec, $lockSec) {
        $now = time();
        foreach ($all as $k => $v) {                       // prune stale entries
            if (($v['until'] ?? 0) < $now && ($v['first'] ?? 0) < $now - 86400) unset($all[$k]);
        }
        $e = $all[$key] ?? ['n' => 0, 'first' => $now, 'until' => 0, 'locks' => 0];
        if ($now - $e['first'] > $windowSec) { $e['n'] = 0; $e['first'] = $now; }
        $e['n']++;
        if ($e['n'] >= $max) {                              // progressive lockout
            $e['locks'] = ($e['locks'] ?? 0) + 1;
            $e['until'] = $now + $lockSec * min(8, 2 ** ($e['locks'] - 1));
            $e['n'] = 0; $e['first'] = $now;
        }
        $all[$key] = $e;
        return $all;
    });
}
function ssms_rl_clear(string $key): void {
    ssms_mutate_obj('ratelimit', function (array $all) use ($key) { unset($all[$key]); return $all; });
}

/* ------------------------------------------------------ users / session */
function ssms_user_by_id(int $id): ?array { return ssms_find('users', $id); }
function ssms_user_by_username(string $u): ?array {
    foreach (ssms_read('users') as $r) if (strcasecmp($r['username'], $u) === 0) return $r;
    return null;
}

/**
 * MFA policy. Mandatory roles are a built-in floor: configuration can ADD roles but can never remove
 * these, so a typo or empty SSMS_MFA_REQUIRED_ROLES cannot silently switch MFA off for privileged accounts.
 */
const SSMS_MFA_MANDATORY_ROLES = ['sysadmin', 'secretary', 'presiding'];
function ssms_mfa_required_roles(): array {
    $extra = array_filter(array_map('trim', explode(',', (string)ssms_cfg('SSMS_MFA_REQUIRED_ROLES', ''))), 'ssms_valid_role');
    return array_values(array_unique(array_merge(SSMS_MFA_MANDATORY_ROLES, $extra)));
}
/** MFA obligation for this user: 'required' | 'optional'. Unknown/malformed role => required (and login is refused elsewhere). */
function ssms_mfa_policy(array $user): string {
    $role = (string)($user['role'] ?? '');
    if (!ssms_valid_role($role) || in_array($role, ssms_mfa_required_roles(), true)) return 'required';
    return ssms_cfg('SSMS_MFA_ORDINARY_POLICY', 'optional') === 'required' ? 'required' : 'optional';
}
function ssms_mfa_optin(array $user): bool { return !empty($user['mfa']['email_otp']); }

/**
 * What the server demands for this account at sign-in, from trusted stored data only:
 *   'otp'     e-mail code required        'none'  password (or badge) alone is the configured policy
 *   'blocked' sign-in refused: MFA applies but there is no verified address to send a code to (or the role is invalid)
 */
function ssms_mfa_gate(array $user): string {
    if (!ssms_valid_role((string)($user['role'] ?? ''))) return 'blocked';
    $verified = ssms_user_email_verified($user);
    $mandatory = in_array($user['role'], ssms_mfa_required_roles(), true);
    if ($mandatory || ssms_mfa_optin($user)) return $verified ? 'otp' : 'blocked';
    if (ssms_mfa_policy($user) === 'required') {                          // ordinary role, policy "required": grace period only
        if ($verified) return 'otp';
        $grace = ssms_cfg_int('SSMS_MFA_GRACE_DAYS', 0, 0, 365);
        return strtotime((string)($user['created_at'] ?? 'now')) + $grace * 86400 > time() ? 'none' : 'blocked';
    }
    return 'none';
}
function ssms_mfa_enabled(array $user): bool { return ssms_mfa_gate($user) === 'otp'; }

/** Establish the fully-authenticated session. Call ONLY after every required factor passed. */
function ssms_establish_session(array $user, string $method, array $extra = [], bool $mfaPassed = false): void {
    ssms_start_session();
    session_regenerate_id(true);                            // fixation defence
    $_SESSION = [];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    $_SESSION['uid'] = (int)$user['id'];
    $_SESSION['sv'] = (int)($user['session_version'] ?? 1);  // bumped on role change / deactivate / reset
    $_SESSION['login_method'] = $method;                     // 'password' | 'qr'
    $_SESSION['mfa_passed'] = $mfaPassed;                    // true ONLY after an e-mail code was verified in this login
    $_SESSION['t0'] = time();
    $_SESSION['last'] = time();
    foreach ($extra as $k => $v) $_SESSION[$k] = $v;
    ssms_update('users', (int)$user['id'], ['last_login_at' => ssms_now(), 'failed_count' => 0]);
}

/**
 * Resolve the current authenticated user from TRUSTED server-side data.
 * Returns null if no valid, current, fully-authenticated session exists.
 * Role/permissions always come from users.json - never from the browser.
 */
function ssms_current_user(): ?array {
    static $cache = false;
    if ($cache !== false) return $cache;
    ssms_start_session();
    if (empty($_SESSION['uid'])) return $cache = null;

    $idle = (int)ssms_cfg('SSMS_IDLE_TIMEOUT_MIN', '30') * 60;
    $abs  = (int)ssms_cfg('SSMS_ABSOLUTE_TIMEOUT_MIN', '480') * 60;
    $now = time();
    if ($now - ($_SESSION['last'] ?? 0) > $idle || $now - ($_SESSION['t0'] ?? 0) > $abs) {
        ssms_audit('auth.session_expired', (int)$_SESSION['uid'], 'ok');
        ssms_destroy_session();
        return $cache = null;
    }
    $u = ssms_user_by_id((int)$_SESSION['uid']);
    if (!$u || empty($u['active']) || !empty($u['locked']) || (int)($u['session_version'] ?? 1) !== (int)($_SESSION['sv'] ?? -1)) {
        ssms_destroy_session();                              // deactivated / role changed / reset
        return $cache = null;
    }
    // Re-evaluated from stored account data on EVERY request: a session without a completed e-mail code is
    // useless for any account that requires one, and an account that is now blocked loses its session.
    $gate = ssms_mfa_gate($u);
    if ($gate === 'blocked' || ($gate === 'otp' && empty($_SESSION['mfa_passed']))) { ssms_destroy_session(); return $cache = null; }
    // QR sessions die immediately if the badge/device was revoked.
    if (($_SESSION['login_method'] ?? '') === 'qr') {
        $b = ssms_find('badges', (int)($_SESSION['badge_id'] ?? 0));
        if (!$b || !empty($b['revoked']) || ($b['device_id'] ?? null) !== ($_SESSION['device_id'] ?? '')) {
            ssms_audit('auth.qr_session_revoked', (int)$u['id'], 'ok');
            ssms_destroy_session();
            return $cache = null;
        }
    }
    $_SESSION['last'] = $now;
    return $cache = $u;
}

function ssms_destroy_session(): void {
    ssms_start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
}

/**
 * Guard for protected HTML pages. $perm may be a permission or array (any-of).
 * - not logged in      -> login page
 * - lacks permission   -> 403 page (deny by default)
 * - POST               -> CSRF verified automatically
 */
function ssms_require(string|array|null $perm = null): array {
    $u = ssms_current_user();
    $base = ssms_base();
    if (!$u) ssms_redirect($base . 'login.php');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') ssms_csrf_check();
    if ($perm !== null && !ssms_can_any($u, (array)$perm)) {
        ssms_audit('authz.denied', null, 'denied', ['need' => implode('|', (array)$perm), 'path' => basename($_SERVER['SCRIPT_NAME'] ?? '')], $u);
        http_response_code(403);
        $page_title = 'Access denied'; $page_sub = ''; $active_page = '';
        include __DIR__ . '/header.php';
        echo '<div class="alert alert-bad" role="alert"><i class="fa-solid fa-ban"></i> You do not have permission to open this page or perform this action. '
           . 'Your role (' . ssms_e(ssms_role_label($u['role'])) . ') does not include it. Contact the System Administrator if you believe this is a mistake.</div>';
        include __DIR__ . '/footer.php';
        exit;
    }
    return $u;
}

/** Same for JSON endpoints: never redirects, returns proper status codes. */
function ssms_require_api(string|array $perm): array {
    header('Content-Type: application/json');
    $u = ssms_current_user();
    if (!$u) { http_response_code(401); echo json_encode(['success' => false, 'error' => 'Not signed in.']); exit; }
    if (!ssms_can_any($u, (array)$perm)) {
        ssms_audit('authz.denied', null, 'denied', ['need' => implode('|', (array)$perm), 'path' => basename($_SERVER['SCRIPT_NAME'] ?? '')], $u);
        http_response_code(403); echo json_encode(['success' => false, 'error' => 'Forbidden.']); exit;
    }
    return $u;
}

/** Re-verify the password for sensitive actions (recent-auth window = 5 min). */
function ssms_fresh_auth_ok(): bool { return (time() - (int)($_SESSION['fresh_at'] ?? 0)) < 300; }
