<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/store.php';

header('Content-Type: application/json');

$sessionId = (int)($_GET['session_id'] ?? 0);
$session = ssms_find('sessions', $sessionId);
$members = ssms_read('members');
$attendance = ssms_where('attendance', 'session_id', $sessionId);
$present = count(array_filter($attendance, fn($a) => $a['status'] === 'Present'));

echo json_encode([
    'session_id' => $sessionId,
    'status' => $session['status'] ?? 'Unknown',
    'present' => $present,
    'total' => count($members),
    'quorum_needed' => intdiv(count($members), 2) + 1,
    'current_agenda_id' => $session['current_agenda_id'] ?? null,
    'server_time' => date('H:i:s'),
]);
