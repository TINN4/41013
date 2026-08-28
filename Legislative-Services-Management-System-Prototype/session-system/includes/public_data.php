<?php
// Helpers shared by the public dashboard (public.php) and its polling
// endpoint (api/public_status.php). Deliberately kept free of anything
// that requires a login/QR session — this file must be safe to use
// for anonymous visitors.

function ssms_public_pick_featured_session() {
    $sessions = ssms_read('sessions');
    if (!$sessions) return null;

    // 1) Prefer a session that's currently ongoing.
    foreach ($sessions as $s) {
        if (($s['status'] ?? '') === 'Ongoing') return $s;
    }
    // 2) Otherwise the soonest upcoming scheduled session.
    $scheduled = array_values(array_filter($sessions, fn($s) => ($s['status'] ?? '') === 'Scheduled'));
    if ($scheduled) {
        usort($scheduled, fn($a, $b) => strcmp($a['date'] . $a['time'], $b['date'] . $b['time']));
        return $scheduled[0];
    }
    // 3) Otherwise the most recently completed session.
    $completed = array_values(array_filter($sessions, fn($s) => ($s['status'] ?? '') === 'Completed'));
    if ($completed) {
        usort($completed, fn($a, $b) => strcmp($b['date'] . $b['time'], $a['date'] . $a['time']));
        return $completed[0];
    }
    return $sessions[0];
}

function ssms_public_upcoming_sessions($excludeId = null, $limit = 5) {
    $sessions = ssms_read('sessions');
    $upcoming = array_values(array_filter($sessions, function ($s) use ($excludeId) {
        return ($s['status'] ?? '') === 'Scheduled' && (int)($s['id'] ?? 0) !== (int)$excludeId;
    }));
    usort($upcoming, fn($a, $b) => strcmp($a['date'] . $a['time'], $b['date'] . $b['time']));
    return array_slice($upcoming, 0, $limit);
}

function ssms_public_agenda_status_class($status) {
    switch ($status) {
        case 'Approved': return 'ok';
        case 'Discussed': return 'warn';
        case 'Pending': default: return 'muted';
    }
}
