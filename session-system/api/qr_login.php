<?php
session_start();
require_once __DIR__ . '/../includes/store.php';
require_once __DIR__ . '/../includes/geofence.php';
require_once __DIR__ . '/../includes/mfa.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$token = trim($input['token'] ?? '');
$lat = $input['lat'] ?? null;
$lng = $input['lng'] ?? null;
$deviceId = trim($input['deviceId'] ?? '');
$deviceLabel = trim($input['deviceLabel'] ?? '');

function reject($msg) {
    echo json_encode(['success' => false, 'error' => $msg]);
    exit();
}

if (!$token) reject('No QR token received. Please try scanning again.');
if (!$deviceId) reject('Could not identify this device. Please reload and try again.');

// 1. Geofence check — must be within the allowed radius of the office.
if (!ssms_within_geofence($lat, $lng)) {
    reject('You must be within office premises to log in. You appear to be outside the allowed area.');
}

// 2. Look up the account by badge token.
$users = ssms_read('qr_users');
$user = null;
foreach ($users as $u) {
    if (hash_equals($u['token'], $token)) { $user = $u; break; }
}
if (!$user) reject('QR badge not recognized. Please contact the administrator.');

// 3. Strict one-device enforcement — bind on first use, reject any other device after that.
if ($user['deviceId'] === null) {
    // First-ever login for this account: bind this device permanently.
    ssms_update('qr_users', $user['id'], [
        'deviceId' => $deviceId,
        'deviceLabel' => $deviceLabel,
        'boundAt' => date('Y-m-d H:i:s'),
        'lastLoginAt' => date('Y-m-d H:i:s'),
    ]);
} elseif ($user['deviceId'] === $deviceId) {
    // Same device as before — allowed.
    ssms_update('qr_users', $user['id'], ['lastLoginAt' => date('Y-m-d H:i:s')]);
} else {
    // A different device tried to use this account. Reject — do NOT auto-swap.
    reject('This account is already registered to another device. If your device was lost, stolen, or replaced, ask the administrator to reset your device binding.');
}

// 4. All checks passed — log in.
// 5. Badge + device + location verified -> still require the OTP second factor.
ssms_begin_mfa('qr:' . $user['id'], $user['name'], 'member', $user['name']);

echo json_encode(['success' => true, 'name' => $user['name'], 'mfa' => true]);
