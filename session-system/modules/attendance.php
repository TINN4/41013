<?php
require_once __DIR__ . '/../includes/auth.php';
$user = ssms_require(['attendance.view_all', 'attendance.view_own', 'quorum.view']);
$viewAll = ssms_can($user, 'attendance.view_all');
$ownMember = ssms_member_of($user);

$sessions = ssms_read('sessions');
usort($sessions, fn($a, $b) => strcmp($b['date'], $a['date']));
$selectedId = ssms_selected_session($sessions);
$currentSession = null; foreach ($sessions as $s) if ((int)$s['id'] === $selectedId) $currentSession = $s;
$members = ssms_read('members');

$action = ssms_post_action();
if ($action !== '') {
    if (!ssms_can($user, 'attendance.record')) { ssms_audit('authz.denied', $selectedId, 'denied', ['op' => 'attendance.' . $action], $user); http_response_code(403); exit('Forbidden'); }
    if (!$currentSession) ssms_done('bad', 'Session not found.', 0, 'attendance.php');
    $sid = (int)$currentSession['id'];
    $fresh = ssms_find('sessions', $sid);
    $mid = (int)($_POST['member_id'] ?? 0);
    $member = null; foreach ($members as $m) if ((int)$m['id'] === $mid) $member = $m;
    if (!$member) ssms_done('bad', 'Unknown member.', $sid, 'attendance.php');
    $reason = ssms_text((string)($_POST['reason'] ?? ''), 300);
    $completed = $fresh['status'] === 'Completed';
    if (!in_array($fresh['status'], ['Scheduled', 'Ongoing', 'Completed'], true)) ssms_done('bad', 'Attendance cannot be recorded for a ' . strtolower($fresh['status']) . ' session.', $sid, 'attendance.php');
    if ($completed && !ssms_can($user, 'attendance.correct')) ssms_done('bad', 'You are not permitted to correct attendance after a session is completed.', $sid, 'attendance.php');
    if ($completed && $reason === '') ssms_done('bad', 'A reason is required to correct attendance of a completed session.', $sid, 'attendance.php');
    foreach (ssms_where('minutes', 'session_id', $sid) as $mn) if (in_array($mn['status'], ['Approved', 'Published'], true)) ssms_done('bad', 'Minutes for this session are already approved; attendance is locked. Start a minutes amendment first.', $sid, 'attendance.php');

    $changed = false; $old = null; $newRow = null; $err = null;
    if ($action === 'mark') {
        $status = (string)($_POST['status'] ?? '');
        if (!in_array($status, SSMS_ATT_STATUSES, true)) ssms_done('bad', 'Invalid attendance status.', $sid, 'attendance.php');
        ssms_mutate('attendance', function ($rows) use ($sid, $mid, $status, $user, &$old, &$newRow, &$changed) {
            $time = in_array($status, SSMS_ATT_COUNTS_PRESENT, true) ? date('H:i') : null;
            foreach ($rows as &$r) if ((int)$r['session_id'] === $sid && (int)$r['member_id'] === $mid) {       // one record per member per session
                $old = $r; if ($r['status'] === $status) return $rows;
                $r['status'] = $status; $r['time_in'] = $time ?? null; $r['time_out'] = null; $r['recorded_by'] = $user['id']; $r['updated_at'] = ssms_now();
                $newRow = $r; $changed = true; return $rows;
            }
            unset($r);
            $newRow = ['id' => ssms_next_id($rows), 'session_id' => $sid, 'member_id' => $mid, 'status' => $status, 'time_in' => $time, 'time_out' => null, 'recorded_by' => $user['id'], 'updated_at' => ssms_now()];
            $rows[] = $newRow; $changed = true; return $rows;
        });
    } elseif ($action === 'depart' || $action === 'return') {
        if ($fresh['status'] !== 'Ongoing') ssms_done('bad', 'Departures are recorded while the session is ongoing.', $sid, 'attendance.php');
        ssms_mutate('attendance', function ($rows) use ($sid, $mid, $action, $user, &$old, &$newRow, &$changed, &$err) {
            foreach ($rows as &$r) if ((int)$r['session_id'] === $sid && (int)$r['member_id'] === $mid) {
                if (!in_array($r['status'], SSMS_ATT_COUNTS_PRESENT, true)) { $err = 'Only a member recorded present can be marked as departed.'; return $rows; }
                $old = $r; $r['time_out'] = $action === 'depart' ? date('H:i') : null; $r['recorded_by'] = $user['id']; $r['updated_at'] = ssms_now();
                $newRow = $r; $changed = true; return $rows;
            }
            $err = 'No attendance record exists for that member yet.'; return $rows;
        });
        if ($err) ssms_done('bad', $err, $sid, 'attendance.php');
    }
    if ($changed) {
        ssms_insert('attendance_history', ['session_id' => $sid, 'member_id' => $mid, 'attendance_id' => $newRow['id'], 'before' => $old ? ['status' => $old['status'], 'time_in' => $old['time_in'], 'time_out' => $old['time_out'] ?? null] : null,
            'after' => ['status' => $newRow['status'], 'time_in' => $newRow['time_in'], 'time_out' => $newRow['time_out']], 'by' => $user['id'], 'by_name' => $user['name'], 'at' => ssms_now(), 'reason' => $reason ?: null]);
        ssms_audit($old ? 'attendance.corrected' : 'attendance.recorded', $newRow['id'], 'ok', ['session_id' => $sid, 'member_id' => $mid, 'status' => $newRow['status'], 'reason' => $reason]);
        $q = ssms_quorum($sid);
        ssms_done('ok', 'Saved: ' . $member['name'] . ' - ' . $newRow['status'] . '. ' . ssms_quorum_label($q['status']) . '.', $sid, 'attendance.php');
    }
    ssms_done('ok', 'No change: that is already the recorded status.', $sid, 'attendance.php');
}

$attendance = $currentSession ? ssms_where('attendance', 'session_id', $selectedId) : [];
$attByMember = []; foreach ($attendance as $a) $attByMember[(int)$a['member_id']] = $a;
$q = $currentSession ? ssms_quorum($selectedId) : null;
$bannerClass = $q ? ['achieved' => 'met', 'not_achieved' => 'notmet', 'not_determined' => 'undetermined'][$q['status']] : '';
$icon = $q ? ['achieved' => 'fa-circle-check', 'not_achieved' => 'fa-triangle-exclamation', 'not_determined' => 'fa-hourglass-half'][$q['status']] : '';
$canRecord = $currentSession && ssms_can($user, 'attendance.record') && in_array($currentSession['status'], ['Scheduled', 'Ongoing', 'Completed'], true);
$history = $currentSession && $viewAll ? array_slice(array_reverse(ssms_where('attendance_history', 'session_id', $selectedId)), 0, 10) : [];
$memberName = []; foreach ($members as $m) $memberName[(int)$m['id']] = $m['name'];
$rule = $q['rule'] ?? [];
$ruleText = ($rule['type'] ?? 'majority') === 'fixed' ? ('at least ' . (int)$rule['fixed'] . ' members') : (($rule['type'] ?? '') === 'fraction' ? ((int)$rule['numerator'] . '/' . (int)$rule['denominator'] . ' of members') : 'a majority of members');

$page_title = 'Attendance & Quorum Monitoring';
$page_sub   = 'Mark attendance and track quorum in real time';
$active_page = 'attendance';
include __DIR__ . '/../includes/header.php';
ssms_session_picker($sessions, $selectedId);
?>

<?php if (!$currentSession): ?>
  <div class="empty-state"><i class="fa-solid fa-calendar-xmark"></i>No sessions available.</div>
<?php else: ?>

<div class="quorum-banner <?= $bannerClass ?>" id="quorum-banner" role="status">
  <div class="qnum"><?= (int)$q['present'] ?> / <?= (int)$q['basis'] ?></div>
  <div class="qlabel">
    <p style="margin:0;font-weight:700;"><i class="fa-solid <?= $icon ?>"></i> <?= ssms_e(ssms_quorum_label($q['status'])) ?></p>
    <p style="margin:0;font-size:12.5px;color:var(--ink-600);">Rule applied: <?= ssms_e($ruleText) ?> of <?= (int)$q['basis'] ?> eligible &rArr; <?= (int)$q['needed'] ?> needed. Attendance recorded for <?= (int)$q['recorded'] ?> of <?= (int)$q['basis'] ?> members.<?= $q['status'] === 'not_determined' ? ' Roll call is incomplete, so absence is not assumed.' : '' ?></p>
    <?php if (!$q['rule_confirmed']): ?><p style="margin:2px 0 0;font-size:12px;color:var(--warn-600);"><i class="fa-solid fa-circle-info"></i> This quorum rule is a default and has <strong>not been confirmed by the authorized office</strong>.</p><?php endif; ?>
  </div>
</div>

<div class="card" style="margin-top:16px;">
  <div class="card-head">
    <div><h3>Roll Call &mdash; <?= ssms_e($currentSession['title']) ?></h3>
      <p><?= $canRecord ? 'Tap a status to record each member\'s attendance. Changes are logged.' : ($viewAll ? 'Read-only view.' : 'You can see your own attendance record.') ?>
      <?= $currentSession['status'] === 'Completed' && $canRecord ? ' <strong>Session is completed: corrections need a reason.</strong>' : '' ?></p></div>
  </div>
  <div class="attend-list">
    <?php foreach ($members as $m):
      if (!$viewAll && (!$ownMember || (int)$ownMember['id'] !== (int)$m['id'])) continue;     // record-level restriction
      $row = $attByMember[(int)$m['id']] ?? null; $status = $row['status'] ?? null; $timeIn = $row['time_in'] ?? null; $timeOut = $row['time_out'] ?? null;
    ?>
    <div class="attend-row">
      <div class="who">
        <p><?= ssms_e($m['name']) ?></p>
        <p><?= ssms_e($m['position']) ?><?= $timeIn ? ' &middot; In at ' . ssms_e($timeIn) : '' ?><?= $timeOut ? ' &middot; Left at ' . ssms_e($timeOut) : '' ?><?= $status === null ? ' &middot; <em>not yet recorded</em>' : '' ?><?= ($m['eligible_for_quorum'] ?? true) === false ? ' &middot; not counted for quorum' : '' ?></p>
      </div>
      <?php if ($canRecord): ?>
      <form method="POST" class="seg" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
        <?= ssms_csrf_field() ?><input type="hidden" name="session_id" value="<?= $selectedId ?>"><input type="hidden" name="member_id" value="<?= (int)$m['id'] ?>">
        <?php if ($currentSession['status'] === 'Completed'): ?><label for="rr<?= (int)$m['id'] ?>" style="position:absolute;left:-999px;">Reason for correction</label><input type="text" id="rr<?= (int)$m['id'] ?>" name="reason" placeholder="Reason" maxlength="300" required style="padding:5px 8px;border:1px solid var(--line);border-radius:7px;font-size:12px;width:120px;"><?php endif; ?>
        <input type="hidden" name="action" value="mark">
        <?php foreach (SSMS_ATT_STATUSES as $st): ?>
          <button name="status" value="<?= $st ?>" type="submit" class="<?= $status === $st ? 'on ' . strtolower($st === 'Late' ? 'present' : $st) : '' ?>" aria-pressed="<?= $status === $st ? 'true' : 'false' ?>"><?= $st ?></button>
        <?php endforeach; ?>
      </form>
      <?php if ($currentSession['status'] === 'Ongoing' && in_array($status, SSMS_ATT_COUNTS_PRESENT, true)): ?>
      <form method="POST" class="inline-form"><?= ssms_csrf_field() ?><input type="hidden" name="session_id" value="<?= $selectedId ?>"><input type="hidden" name="member_id" value="<?= (int)$m['id'] ?>">
        <button class="btn btn-ghost btn-sm" name="action" value="<?= $timeOut ? 'return' : 'depart' ?>" type="submit"><?= $timeOut ? 'Returned' : 'Left' ?></button></form>
      <?php endif; ?>
      <?php else: ?>
        <span class="badge <?= ['Present' => 'badge-ok', 'Late' => 'badge-warn', 'Excused' => 'badge-blue', 'Absent' => 'badge-bad'][$status] ?? 'badge-grey' ?>"><?= ssms_e($status ?? 'Not recorded') ?></span>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php if (!$viewAll && !$ownMember): ?><div class="empty-state">Your account is not linked to a council member record, so there is no personal attendance to show.</div><?php endif; ?>
  </div>
</div>

<?php if ($history): ?>
<div class="card"><div class="card-head"><div><h3>Recent attendance changes</h3><p>Who changed what, and when.</p></div></div>
  <div class="table-scroll"><table class="data-table"><thead><tr><th>When</th><th>Member</th><th>Change</th><th>By</th><th>Reason</th></tr></thead><tbody>
  <?php foreach ($history as $h): ?><tr><td class="mono" style="font-size:11px;"><?= ssms_e($h['at']) ?></td><td><?= ssms_e($memberName[(int)$h['member_id']] ?? '#' . $h['member_id']) ?></td>
    <td><?= ssms_e(($h['before']['status'] ?? '—') . ' → ' . $h['after']['status']) ?></td><td><?= ssms_e($h['by_name']) ?></td><td><?= ssms_e($h['reason'] ?? '') ?></td></tr><?php endforeach; ?>
  </tbody></table></div></div>
<?php endif; ?>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
