<?php
/** Business rules shared by modules and APIs: settings, quorum, session state machine, conflicts. */

function ssms_settings(): array {
    $s = ssms_read_obj('settings');
    $s += ['default_session_minutes' => 180, 'require_final_agenda_to_start' => true];
    $s['quorum_rule'] = ($s['quorum_rule'] ?? []) + ['basis' => 'eligible_members', 'type' => 'majority', 'numerator' => 1, 'denominator' => 2, 'fixed' => null, 'confirmed_by_office' => false];
    return $s;
}

const SSMS_ATT_STATUSES   = ['Present', 'Late', 'Excused', 'Absent'];
const SSMS_ATT_COUNTS_PRESENT = ['Present', 'Late'];

/** Members counted in the quorum basis (configurable per member via eligible_for_quorum). */
function ssms_quorum_members(): array {
    return array_values(array_filter(ssms_read('members'), fn($m) => ($m['eligible_for_quorum'] ?? true) !== false));
}

function ssms_quorum_needed(int $basis, array $rule): int {
    switch ($rule['type'] ?? 'majority') {
        case 'fraction':
            $d = max(1, (int)($rule['denominator'] ?? 2)); $n = max(1, (int)($rule['numerator'] ?? 1));
            return (int)ceil($basis * $n / $d);
        case 'fixed':
            return max(1, (int)($rule['fixed'] ?? 1));
        default:                                    // majority of the basis
            return intdiv($basis, 2) + 1;
    }
}

/** Attendance rows currently counting as present (Present/Late, not departed). */
function ssms_present_rows(array $attendance): array {
    return array_values(array_filter($attendance, fn($a) => in_array($a['status'], SSMS_ATT_COUNTS_PRESENT, true) && empty($a['time_out'])));
}

/**
 * Quorum state for a session. status is one of:
 *  achieved | not_achieved | not_determined
 * "not_determined" = roll call incomplete (some eligible members have no record) and the
 * threshold has not yet been reached; the system never assumes absence for unrecorded members.
 */
function ssms_quorum(int $sessionId): array {
    $set = ssms_settings(); $rule = $set['quorum_rule'];
    $eligible = ssms_quorum_members();
    $eligibleIds = array_column($eligible, 'id');
    $att = array_values(array_filter(ssms_where('attendance', 'session_id', $sessionId), fn($a) => in_array((int)$a['member_id'], array_map('intval', $eligibleIds), true)));
    $basis = count($eligible);
    $needed = ssms_quorum_needed($basis, $rule);
    $present = count(ssms_present_rows($att));
    $recorded = count($att);
    if ($basis === 0) $status = 'not_determined';
    elseif ($present >= $needed) $status = 'achieved';
    elseif ($recorded >= $basis) $status = 'not_achieved';
    else $status = 'not_determined';
    return ['basis' => $basis, 'needed' => $needed, 'present' => $present, 'recorded' => $recorded, 'status' => $status,
            'rule_confirmed' => !empty($rule['confirmed_by_office']), 'rule' => $rule];
}
function ssms_quorum_label(string $s): string {
    return ['achieved' => 'Quorum achieved', 'not_achieved' => 'Quorum not achieved', 'not_determined' => 'Quorum not yet determined'][$s] ?? $s;
}

/* -------------------------------------------------------- session state machine */
const SSMS_TRANSITIONS = ['Scheduled' => ['Ongoing', 'Cancelled'], 'Ongoing' => ['Completed'], 'Completed' => [], 'Cancelled' => []];

function ssms_transition_allowed(string $from, string $to): bool { return in_array($to, SSMS_TRANSITIONS[$from] ?? [], true); }

function ssms_session_badge(string $status): string {
    return ['Scheduled' => 'badge-blue', 'Ongoing' => 'badge-gold', 'Completed' => 'badge-ok', 'Cancelled' => 'badge-bad'][$status] ?? 'badge-grey';
}

/* ------------------------------------------------------------- scheduling */
function ssms_valid_date(string $d): bool { $x = DateTime::createFromFormat('Y-m-d', $d); return $x && $x->format('Y-m-d') === $d; }
function ssms_valid_time(string $t): bool { return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t); }
function ssms_minutes_of(string $t): int { [$h, $m] = explode(':', $t); return (int)$h * 60 + (int)$m; }

/** Returns a conflicting session row (same venue, same date, overlapping time) or null. */
function ssms_find_conflict(array $rows, string $date, string $time, ?string $end, string $venue, int $ignoreId = 0): ?array {
    $dur = (int)ssms_settings()['default_session_minutes'];
    $s1 = ssms_minutes_of($time); $e1 = $end ? ssms_minutes_of($end) : $s1 + $dur;
    foreach ($rows as $r) {
        if ((int)$r['id'] === $ignoreId || ($r['status'] ?? '') === 'Cancelled') continue;
        if ($r['date'] !== $date || strcasecmp(trim($r['venue']), trim($venue)) !== 0) continue;
        $s2 = ssms_minutes_of($r['time']); $e2 = !empty($r['end_time']) ? ssms_minutes_of($r['end_time']) : $s2 + $dur;
        if ($s1 < $e2 && $s2 < $e1) return $r;
    }
    return null;
}

function ssms_selected_session(array $sessions, string $prefer = 'Ongoing'): int {
    $id = (int)($_GET['session_id'] ?? $_POST['session_id'] ?? 0);
    foreach ($sessions as $s) if ((int)$s['id'] === $id) return $id;       // only an id that exists
    $pref = array_values(array_filter($sessions, fn($s) => $s['status'] === $prefer));
    return (int)($pref ? $pref[0]['id'] : ($sessions[0]['id'] ?? 0));
}

function ssms_member_of(array $user): ?array {
    return empty($user['member_id']) ? null : ssms_find('members', (int)$user['member_id']);
}

function ssms_text(string $s, int $max): string {
    $s = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s));
    return mb_substr($s, 0, $max);
}

/**
 * Apply a session status change atomically. The current status is re-checked INSIDE the lock
 * (compare-and-set), so two simultaneous requests cannot both succeed or skip the state machine.
 * @return array [bool ok, string message]
 */
function ssms_session_transition(int $id, string $to, array $actor, ?string $reason = null): array {
    $msg = 'Session not found.'; $ok = false; $before = null;
    $settings = ssms_settings();
    ssms_mutate('sessions', function (array $rows) use ($id, $to, $reason, $settings, &$msg, &$ok, &$before) {
        foreach ($rows as &$s) {
            if ((int)$s['id'] !== $id) continue;
            $before = $s['status'];
            if (!ssms_transition_allowed($s['status'], $to)) { $msg = "A {$s['status']} session cannot be changed to $to."; return $rows; }
            if ($to === 'Ongoing') {
                if ($s['date'] > date('Y-m-d')) { $msg = 'This session is scheduled for a future date. Edit the schedule before starting it.'; return $rows; }
                if (!empty($settings['require_final_agenda_to_start'])) {
                    $items = array_filter(ssms_read('agenda'), fn($a) => (int)$a['session_id'] === $id);
                    if (($s['agenda_status'] ?? 'Draft') !== 'Final' || !$items) { $msg = 'The agenda must be finalized (with at least one item) before the session can start.'; return $rows; }
                }
                $s['started_at'] = time();
            }
            if ($to === 'Completed') $s['ended_at'] = time();
            if ($to === 'Cancelled') {
                if (!$reason) { $msg = 'A reason is required to cancel a session.'; return $rows; }
                $s['cancel_reason'] = $reason;
            }
            $s['status'] = $to; $s['updated_at'] = ssms_now(); $ok = true; $msg = "Session marked $to.";
        }
        return $rows;
    });
    ssms_audit('session.status_changed', $id, $ok ? 'ok' : 'failed', ['from' => $before, 'to' => $to, 'reason' => $reason, 'msg' => $ok ? '' : $msg], $actor);
    return [$ok, $msg];
}

/** Standard "choose a session" bar (labelled for accessibility). */
function ssms_session_picker(array $sessions, int $selectedId, string $extra = ''): void {
    echo '<div class="card no-print"><div class="select-session-bar"><div class="form-group"><label for="ssms-session-select">Session</label>'
       . '<form method="GET" id="sessSwitch"><select name="session_id" id="ssms-session-select" onchange="this.form.submit()">';
    foreach ($sessions as $s) {
        echo '<option value="' . (int)$s['id'] . '"' . ((int)$s['id'] === $selectedId ? ' selected' : '') . '>'
           . ssms_e($s['title']) . ' &mdash; ' . ssms_e($s['date']) . ' (' . ssms_e($s['status']) . ')</option>';
    }
    echo '</select><noscript><button class="btn btn-ghost btn-sm" type="submit">Go</button></noscript></form></div>' . $extra . '</div></div>';
}

function ssms_post_action(): string { return $_SERVER['REQUEST_METHOD'] === 'POST' ? (string)($_POST['action'] ?? '') : ''; }

/** Post/Redirect/Get helper: remembers the selected session. */
function ssms_done(string $type, string $msg, int $sessionId, string $file): void {
    ssms_flash($type, $msg);
    ssms_redirect($file . '?session_id=' . $sessionId);
}

/** True when minutes for the session are approved/published (official records then locked). */
function ssms_minutes_locked(int $sessionId): bool {
    foreach (ssms_where('minutes', 'session_id', $sessionId) as $m) if (in_array($m['status'], ['Approved', 'Published'], true)) return true;
    return false;
}
