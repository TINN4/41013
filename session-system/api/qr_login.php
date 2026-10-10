<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/domain.php';
require_once __DIR__ . '/../includes/authflow.php';
require_once __DIR__ . '/../includes/geofence.php';
ssms_start_session();
header('Content-Type: application/json');

function qr_reject(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') qr_reject('Method not allowed.', 405);
// Login-CSRF defence: JSON content type (not sendable by a plain cross-site form) + same-origin check.
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) qr_reject('Unsupported request.', 415);
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) qr_reject('Forbidden.', 403);

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in)) qr_reject('Invalid request.');
$token = trim((string)($in['token'] ?? ''));
$deviceId = trim((string)($in['deviceId'] ?? ''));
$deviceSecret = trim((string)($in['deviceSecret'] ?? ''));
$deviceLabel = ssms_text((string)($in['deviceLabel'] ?? ''), 160);
$generic = 'This badge could not be accepted. If you believe this is an error, contact the System Administrator.';

if ($token === '' || strlen($token) > 128 || !preg_match('/^[A-Za-z0-9_-]{16,128}$/', $token)) qr_reject($generic);
if (!preg_match('/^dev-[A-Za-z0-9-]{16,64}$/', $deviceId)) qr_reject('Could not identify this device. Reload and try again.');

// Throttle by IP and by badge, so tokens cannot be brute-forced or hammered.
$th = hash('sha256', $token);
$keys = ['qr:ip:' . ssms_ip(), 'qr:tok:' . $th];
foreach ($keys as $k) if (ssms_rl_blocked($k) > 0) { ssms_audit('qr.login', null, 'throttled'); qr_reject('Too many attempts. Please wait a few minutes.', 429); }
$fail = function (string $reason, ?array $u = null) use ($keys, $generic) {
    ssms_rl_fail($keys[0], 15, 900, 600); ssms_rl_fail($keys[1], 5, 900, 900);
    ssms_audit('qr.login', $u['id'] ?? null, 'failed', ['reason' => $reason], $u ? ['id' => $u['id'], 'role' => $u['role']] : ['id' => null, 'role' => null]);
    qr_reject($generic, 401);
};

// 1. Geofence (policy control; client-reported - see includes/geofence.php)
if (!ssms_within_geofence($in['lat'] ?? null, $in['lng'] ?? null)) {
    ssms_audit('qr.login', null, 'failed', ['reason' => 'outside_geofence']);
    qr_reject('You must be within office premises to use QR login.', 403);
}

// 2. Badge lookup by hash. Tokens are never stored in plaintext.
$badge = null;
foreach (ssms_read('badges') as $b) if (hash_equals($b['token_hash'], $th)) { $badge = $b; break; }
if (!$badge || !empty($badge['revoked']) || strtotime($badge['expires_at']) < time()) $fail('unknown_revoked_or_expired');
$user = ssms_user_by_id((int)$badge['user_id']);
if (!$user || empty($user['active']) || !empty($user['locked'])) $fail('account_inactive', $user);

// 3. Device binding
$newSecret = null;
if (empty($badge['device_id'])) {
    if (strtotime($badge['enroll_expires_at']) < time()) $fail('enrollment_window_closed', $user);
    $newSecret = bin2hex(random_bytes(32));
    $bound = false;
    ssms_mutate('badges', function (array $rows) use ($badge, $deviceId, $deviceLabel, $newSecret, &$bound) {
        foreach ($rows as &$r) {
            if ((int)$r['id'] === (int)$badge['id'] && empty($r['device_id'])) {   // check-and-set: first writer wins
                $r['device_id'] = $deviceId; $r['device_secret_hash'] = hash('sha256', $newSecret);
                $r['device_label'] = $deviceLabel; $r['bound_at'] = ssms_now(); $bound = true;
            }
        }
        return $rows;
    });
    if (!$bound) $fail('lost_bind_race', $user);
    ssms_audit('device.bound', $badge['id'], 'ok', ['user_id' => $user['id']], ['id' => $user['id'], 'role' => $user['role']]);
} else {
    if (!hash_equals((string)$badge['device_id'], $deviceId) || $deviceSecret === '' || !hash_equals((string)$badge['device_secret_hash'], hash('sha256', $deviceSecret))) {
        $fail('device_mismatch', $user);
    }
}
ssms_update('badges', $badge['id'], ['last_login_at' => ssms_now()]);
foreach ($keys as $k) ssms_rl_clear($k);

// 4. First factor only. Same MFA gate as password login; no name/role is revealed yet.
$next = ssms_after_first_factor($user, 'qr', ['badge_id' => (int)$badge['id'], 'device_id' => $deviceId]);
$out = ['success' => true, 'next' => $next];
if ($newSecret) $out['deviceSecret'] = $newSecret;        // shown to the browser exactly once
echo json_encode($out);
