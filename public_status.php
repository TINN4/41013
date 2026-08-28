<?php
// Deliberately does NOT require auth.php — this endpoint is for the public
// dashboard and must work for anonymous visitors with no login/QR device.
// Only non-sensitive, already-public information is exposed here:
// session status, current agenda item, and aggregate quorum counts.
// No member names, no proceedings notes, no minutes drafts, no documents.

require_once __DIR__ . '/../includes/store.php';
require_once __DIR__ . '/../includes/public_data.php';

header('Content-Type: application/json');

$sessionId = isset($_GET['session_id']) ? (int)$_GET['session_id'] : null;
$session = $sessionId ? ssms_find('sessions', $sessionId) : ssms_public_pick_featured_session();

if (!$session) {
    echo json_encode(['found' => false, 'server_time' => date('H:i:s')]);
    exit();
}

$members = ssms_read('members');
$attendance = ssms_where('attendance', 'session_id', $session['id']);
$present = count(array_filter($attendance, fn($a) => $a['status'] === 'Present'));

$agenda = ssms_where('agenda', 'session_id', $session['id']);
usort($agenda, fn($a, $b) => $a['order'] <=> $b['order']);
$agendaPublic = array_map(fn($a) => [
    'order' => $a['order'],
    'item' => $a['item'],
    'presenter' => $a['presenter'],
    'status' => $a['status'],
    'is_current' => (int)($session['current_agenda_id'] ?? 0) === (int)$a['id'],
], $agenda);

echo json_encode([
    'found' => true,
    'session' => [
        'id' => $session['id'],
        'title' => $session['title'],
        'type' => $session['type'],
        'date' => $session['date'],
        'time' => $session['time'],
        'venue' => $session['venue'],
        'status' => $session['status'],
    ],
    'quorum' => [
        'present' => $present,
        'total' => count($members),
        'needed' => intdiv(count($members), 2) + 1,
    ],
    'agenda' => $agendaPublic,
    'server_time' => date('H:i:s'),
]);
