<?php
require_once __DIR__ . '/../includes/auth.php';
$user = ssms_require_api('tracking.view');
header('Cache-Control: no-store');

$sid = (int)($_GET['session_id'] ?? 0);
$session = $sid ? ssms_find('sessions', $sid) : null;
if (!$session) { http_response_code(404); echo json_encode(['success' => false, 'error' => 'Session not found.']); exit; }
$q = ssms_quorum($sid);
$agenda = ssms_where('agenda', 'session_id', $sid);
echo json_encode([
    'success' => true, 'session_id' => $sid, 'status' => $session['status'],
    'present' => $q['present'], 'basis' => $q['basis'], 'needed' => $q['needed'],
    'quorum_status' => $q['status'], 'quorum_label' => ssms_quorum_label($q['status']),
    'current_agenda_id' => $session['current_agenda_id'] ?? null,
    'agenda_done' => count(array_filter($agenda, fn($a) => $a['status'] !== 'Pending')), 'agenda_total' => count($agenda),
    'server_time' => date('c'),
]);
