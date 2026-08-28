<?php
session_start();
require_once __DIR__ . '/../includes/store.php';
require_once __DIR__ . '/../includes/geofence.php';

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
// Rotate the session ID on successful login, same reasoning as the
// staff login flow — never carry over a pre-login session ID.
session_regenerate_id(true);
$_SESSION['ssms_user'] = 'qr:' . $user['id'];
$_SESSION['ssms_name'] = $user['name'];
$_SESSION['ssms_role'] = 'member';

// 5. Auto-attendance: if there's a session live right now, scanning in
// IS the roll call — mark this member Present with a time-in stamp so
// the secretary doesn't have to tap it manually during the meeting.
// Members are only ever auto-marked Present, never Excused/Absent —
// those still require the secretary's judgment in Attendance & Quorum.
$autoMarked = false;
if (!empty($user['memberId'])) {
    $sessions = ssms_read('sessions');
    $ongoing = array_values(array_filter($sessions, fn($s) => $s['status'] === 'Ongoing'));
    if ($ongoing) {
        $sessionId = $ongoing[0]['id'];
        $existing = array_values(array_filter(
            ssms_where('attendance', 'session_id', $sessionId),
            fn($a) => (int)$a['member_id'] === (int)$user['memberId']
        ));
        if ($existing) {
            if ($existing[0]['status'] !== 'Present') {
                ssms_update('attendance', $existing[0]['id'], ['status' => 'Present', 'time_in' => date('H:i')]);
            }
        } else {
            ssms_insert('attendance', [
                'session_id' => $sessionId,
                'member_id'  => (int)$user['memberId'],
                'status'     => 'Present',
                'time_in'    => date('H:i'),
            ]);
        }
        $autoMarked = true;
    }
}

echo json_encode(['success' => true, 'name' => $user['name'], 'autoMarkedAttendance' => $autoMarked]);
