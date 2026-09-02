<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/store.php';
require_once __DIR__ . '/../includes/geofence.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$token = trim($input['token'] ?? '');
$lat = $input['lat'] ?? null;
$lng = $input['lng'] ?? null;
$deviceId = trim($input['deviceId'] ?? '');
$deviceLabel = trim($input['deviceLabel'] ?? '');

function reject($msg, $context = []) {
    ssms_log_login_attempt(array_merge($context, ['result' => 'rejected', 'reason' => $msg]));
    echo json_encode(['success' => false, 'error' => $msg]);
    exit();
}

// Every attempt — success or rejection — gets a row here so staff can
// review login activity later (Manage Devices > Recent Login Activity).
// This can't stop someone from faking their phone's GPS coordinates —
// no browser-based system can — but it makes that kind of attempt
// visible after the fact: repeated rejections, logins right at the edge
// of the radius, or a badge suddenly being used from a wildly different
// distance than usual are all things a secretary can now actually see
// and act on, instead of the system silently allowing or blocking with
// no record either way.
function ssms_log_login_attempt($fields) {
    $defaults = [
        'token_partial' => '',
        'member_name'   => null,
        'lat'           => null,
        'lng'           => null,
        'distance_m'    => null,
        'device_id'     => null,
        'ip'            => $_SERVER['REMOTE_ADDR'] ?? null,
        'result'        => 'rejected',
        'reason'        => '',
        'at'            => date('Y-m-d H:i:s'),
    ];
    ssms_insert('login_audit', array_merge($defaults, $fields));
}

if (!$token) reject('No QR token received. Please try scanning again.');
if (!$deviceId) reject('Could not identify this device. Please reload and try again.');

$tokenPartial = strlen($token) > 6 ? substr($token, 0, 6) . '…' : $token;
$dist = ($lat !== null && $lng !== null) ? round(ssms_distance_meters((float)$lat, (float)$lng, SSMS_OFFICE_LAT, SSMS_OFFICE_LNG)) : null;
$auditBase = ['token_partial' => $tokenPartial, 'lat' => $lat, 'lng' => $lng, 'distance_m' => $dist, 'device_id' => $deviceId];

// 1. Geofence check — must be within the allowed radius of the office.
if (!ssms_within_geofence($lat, $lng)) {
    reject('You must be within office premises to log in. You appear to be outside the allowed area.', $auditBase);
}

// 2. Look up the account by badge token.
$users = ssms_read('qr_users');
$user = null;
foreach ($users as $u) {
    if (hash_equals($u['token'], $token)) { $user = $u; break; }
}
if (!$user) reject('QR badge not recognized. Please contact the administrator.', $auditBase);
$auditBase['member_name'] = $user['name'];

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
    reject('This account is already registered to another device. If your device was lost, stolen, or replaced, ask the administrator to reset your device binding.', $auditBase);
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
                ssms_update('attendance', $existing[0]['id'], ['status' => 'Present', 'time_in' => date('H:i'), 'marked_by' => $user['name'] . ' (QR self check-in)']);
            }
        } else {
            ssms_insert('attendance', [
                'session_id' => $sessionId,
                'member_id'  => (int)$user['memberId'],
                'status'     => 'Present',
                'time_in'    => date('H:i'),
                'marked_by'  => $user['name'] . ' (QR self check-in)',
            ]);
        }
        $autoMarked = true;
    }
}

echo json_encode(['success' => true, 'name' => $user['name'], 'autoMarkedAttendance' => $autoMarked]);
ssms_log_login_attempt(array_merge($auditBase, ['result' => 'success', 'reason' => $autoMarked ? 'Auto-marked present' : 'Login OK']));
