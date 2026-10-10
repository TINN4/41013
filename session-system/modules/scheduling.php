<?php
require_once __DIR__ . '/../includes/auth.php';
$user = ssms_require('schedule.view');
$canManage = ssms_can($user, 'schedule.manage');
$canTransition = ssms_can($user, 'session.transition');
$canCancel = ssms_can_any($user, ['schedule.cancel', 'session.transition']);
const SSMS_TYPES = ['Regular', 'Special', 'Executive', 'Committee Hearing'];

/** Validate schedule form input. @return array [fields|null, error|null] */
function ssms_schedule_input(): array {
    $title = ssms_text((string)($_POST['title'] ?? ''), 150);
    $type = (string)($_POST['type'] ?? 'Regular');
    $date = trim((string)($_POST['date'] ?? ''));
    $time = trim((string)($_POST['time'] ?? ''));
    $end = trim((string)($_POST['end_time'] ?? ''));
    $venue = ssms_text((string)($_POST['venue'] ?? ''), 150);
    if ($title === '' || $venue === '') return [null, 'Title and venue are required.'];
    if (!in_array($type, SSMS_TYPES, true)) return [null, 'Choose a valid session type.'];
    if (!ssms_valid_date($date)) return [null, 'Enter a valid date.'];
    if (!ssms_valid_time($time)) return [null, 'Enter a valid start time.'];
    if ($end !== '' && !ssms_valid_time($end)) return [null, 'Enter a valid end time.'];
    if ($end !== '' && ssms_minutes_of($end) <= ssms_minutes_of($time)) return [null, 'End time must be after the start time.'];
    return [['title' => $title, 'type' => $type, 'date' => $date, 'time' => $time, 'end_time' => $end ?: null, 'venue' => $venue], null];
}

$action = ssms_post_action();
if ($action !== '') {
    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'create') {
        if (!ssms_can($user, 'schedule.manage')) { http_response_code(403); exit('Forbidden'); }
        [$f, $err] = ssms_schedule_input();
        if (!$err && $f['date'] < date('Y-m-d')) $err = 'The date is in the past. Sessions can only be scheduled for today or later.';
        if ($err) { ssms_flash('bad', $err); ssms_redirect('scheduling.php'); }
        [$newId, $conflict] = ssms_insert_unique('sessions', $f + [
            'status' => 'Scheduled', 'started_at' => null, 'ended_at' => null, 'current_agenda_id' => null, 'agenda_status' => 'Draft', 'agenda_revision' => 0,
            'cancel_reason' => null, 'created_at' => ssms_now(), 'updated_at' => ssms_now(), 'created_by' => $user['id'],
        ], function ($rows) use ($f) {
            foreach ($rows as $r) if ($r['status'] !== 'Cancelled' && strcasecmp($r['title'], $f['title']) === 0 && $r['date'] === $f['date']) return 'A session with this title already exists on that date.';
            $c = ssms_find_conflict($rows, $f['date'], $f['time'], $f['end_time'], $f['venue']);
            return $c ? 'Schedule conflict: "' . $c['title'] . '" already uses that venue at an overlapping time (' . $c['time'] . ').' : null;
        });
        if ($conflict) { ssms_flash('bad', $conflict); ssms_redirect('scheduling.php'); }
        ssms_audit('session.created', $newId, 'ok', $f);
        ssms_flash('ok', 'Session scheduled successfully.'); ssms_redirect('scheduling.php');
    }
    if ($action === 'update') {
        if (!$canManage) { http_response_code(403); exit('Forbidden'); }
        [$f, $err] = ssms_schedule_input();
        $cur = ssms_find('sessions', $id);
        if (!$cur) $err = 'Session not found.';
        elseif ($cur['status'] !== 'Scheduled') $err = 'Only sessions that are still Scheduled can be edited.';
        if (!$err && $f['date'] < date('Y-m-d')) $err = 'The date is in the past.';
        $msg = null;
        if (!$err) {
            ssms_mutate('sessions', function ($rows) use ($id, $f, &$msg) {
                if ($c = ssms_find_conflict($rows, $f['date'], $f['time'], $f['end_time'], $f['venue'], $id)) { $msg = 'Schedule conflict with "' . $c['title'] . '".'; return $rows; }
                foreach ($rows as $r) if ((int)$r['id'] !== $id && $r['status'] !== 'Cancelled' && strcasecmp($r['title'], $f['title']) === 0 && $r['date'] === $f['date']) { $msg = 'Another session has the same title and date.'; return $rows; }
                foreach ($rows as &$r) if ((int)$r['id'] === $id && $r['status'] === 'Scheduled') { $r = array_merge($r, $f, ['updated_at' => ssms_now()]); }
                return $rows;
            });
            $err = $msg;
        }
        if ($err) { ssms_flash('bad', $err); ssms_redirect('scheduling.php?edit=' . $id); }
        ssms_audit('session.updated', $id, 'ok', ['before' => array_intersect_key($cur, $f), 'after' => $f]);
        ssms_flash('ok', 'Session updated.'); ssms_redirect('scheduling.php');
    }
    if ($action === 'set_status') {
        $to = (string)($_POST['status'] ?? '');
        $needs = $to === 'Cancelled' ? ['schedule.cancel', 'session.transition'] : ['session.transition'];
        if (!ssms_can_any($user, $needs)) { ssms_audit('authz.denied', $id, 'denied', ['op' => "status->$to"], $user); http_response_code(403); exit('Forbidden'); }
        [$ok, $msg] = ssms_session_transition($id, $to, $user, ssms_text((string)($_POST['reason'] ?? ''), 300) ?: null);
        ssms_flash($ok ? 'ok' : 'bad', $msg); ssms_redirect('scheduling.php');
    }
    if ($action === 'delete') {
        if (!$canManage) { http_response_code(403); exit('Forbidden'); }
        $s = ssms_find('sessions', $id);
        $dependents = $s ? (count(ssms_where('agenda', 'session_id', $id)) + count(ssms_where('attendance', 'session_id', $id)) + count(ssms_where('proceedings', 'session_id', $id)) + count(ssms_where('minutes', 'session_id', $id))) : 0;
        if (!$s) ssms_flash('bad', 'Session not found.');
        elseif ($s['status'] !== 'Scheduled') ssms_flash('bad', 'Only sessions that are still Scheduled can be removed. Official records of held or cancelled sessions are kept; use Cancel instead.');
        elseif ($dependents > 0) ssms_flash('bad', 'This session already has agenda, attendance, proceedings or minutes records. Cancel it instead of deleting it.');
        else { ssms_delete('sessions', $id); ssms_audit('session.deleted', $id, 'ok', ['title' => $s['title']]); ssms_flash('ok', 'Session removed.'); }
        ssms_redirect('scheduling.php');
    }
    http_response_code(400); exit('Unknown action');
}

$sessions = ssms_read('sessions');
usort($sessions, fn($a, $b) => strcmp($b['date'] . $b['time'], $a['date'] . $a['time']));
$edit = isset($_GET['edit']) && $canManage ? ssms_find('sessions', (int)$_GET['edit']) : null;
if ($edit && $edit['status'] !== 'Scheduled') $edit = null;

$page_title = 'Session Scheduling';
$page_sub   = 'Create sessions and manage their status through the workflow';
$active_page = 'scheduling';
include __DIR__ . '/../includes/header.php';
$v = fn($k, $d = '') => ssms_e($edit[$k] ?? $d);
?>

<?php if ($canManage): ?>
<div class="card">
  <div class="card-head">
    <div><h3><?= $edit ? 'Edit Session' : 'Schedule a New Session' ?></h3><p><?= $edit ? 'Only Scheduled sessions can be edited.' : 'Fill in the details to add it to the calendar.' ?></p></div>
  </div>
  <form method="POST" data-warn-unsaved>
    <?= ssms_csrf_field() ?>
    <input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>">
    <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
    <div class="form-row two">
      <div class="form-group"><label for="f-title">Session Title</label><input type="text" id="f-title" name="title" maxlength="150" placeholder="e.g. 60th Regular Session" value="<?= $v('title') ?>" required></div>
      <div class="form-group"><label for="f-type">Type</label>
        <select id="f-type" name="type"><?php foreach (SSMS_TYPES as $t): ?><option <?= ($edit['type'] ?? 'Regular') === $t ? 'selected' : '' ?>><?= ssms_e($t) ?></option><?php endforeach; ?></select>
      </div>
    </div>
    <div class="form-row three">
      <div class="form-group"><label for="f-date">Date</label><input type="date" id="f-date" name="date" min="<?= date('Y-m-d') ?>" value="<?= $v('date') ?>" required></div>
      <div class="form-group"><label for="f-time">Start time</label><input type="time" id="f-time" name="time" value="<?= $v('time', '13:00') ?>" required></div>
      <div class="form-group"><label for="f-end">End time (optional)</label><input type="time" id="f-end" name="end_time" value="<?= $v('end_time') ?>"></div>
    </div>
    <div class="form-group"><label for="f-venue">Venue</label><input type="text" id="f-venue" name="venue" maxlength="150" placeholder="Session Hall" value="<?= $v('venue', 'Sangguniang Panlungsod Session Hall') ?>" required></div>
    <button type="submit" class="btn btn-navy"><i class="fa-solid fa-calendar-plus"></i> <?= $edit ? 'Save Changes' : 'Schedule Session' ?></button>
    <?php if ($edit): ?><a class="btn btn-ghost" href="scheduling.php">Cancel</a><?php endif; ?>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-head">
    <div><h3>All Sessions</h3><p>Valid changes: Scheduled &rarr; Ongoing &rarr; Completed, or Scheduled &rarr; Cancelled.</p></div>
  </div>
  <?php if ($sessions): ?>
  <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Title</th><th>Type</th><th>Date</th><th>Time</th><th>Venue</th><th>Agenda</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($sessions as $s): ?>
        <tr>
          <td><?= ssms_e($s['title']) ?><?php if ($s['status'] === 'Cancelled' && !empty($s['cancel_reason'])): ?><div style="font-size:11px;color:var(--ink-600);">Reason: <?= ssms_e($s['cancel_reason']) ?></div><?php endif; ?></td>
          <td><?= ssms_e($s['type']) ?></td>
          <td><?= ssms_e($s['date']) ?></td>
          <td><?= ssms_e($s['time']) ?><?= !empty($s['end_time']) ? ' &ndash; ' . ssms_e($s['end_time']) : '' ?></td>
          <td><?= ssms_e($s['venue']) ?></td>
          <td><span class="badge <?= ($s['agenda_status'] ?? 'Draft') === 'Final' ? 'badge-ok' : 'badge-draft' ?>"><?= ssms_e($s['agenda_status'] ?? 'Draft') ?></span></td>
          <td><span class="badge <?= ssms_session_badge($s['status']) ?>"><?= ssms_e($s['status']) ?></span></td>
          <td>
            <div style="display:flex;gap:6px;flex-wrap:wrap;">
              <?php if ($s['status'] === 'Scheduled'): ?>
                <?php if ($canTransition): ?>
                <form method="POST" class="inline-form"><?= ssms_csrf_field() ?><input type="hidden" name="action" value="set_status"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="status" value="Ongoing"><button class="btn btn-gold btn-sm" type="submit"><i class="fa-solid fa-play"></i> Start</button></form>
                <?php endif; ?>
                <?php if ($canCancel): ?>
                <form method="POST" class="inline-form" data-confirm="Cancel this session? This cannot be undone." style="display:inline-flex;gap:4px;"><?= ssms_csrf_field() ?><input type="hidden" name="action" value="set_status"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="status" value="Cancelled">
                  <label for="rs<?= (int)$s['id'] ?>" style="position:absolute;left:-999px;">Cancellation reason</label><input type="text" id="rs<?= (int)$s['id'] ?>" name="reason" placeholder="Reason" maxlength="300" required style="padding:5px 8px;border:1px solid var(--line);border-radius:7px;font-size:12px;width:110px;">
                  <button class="btn btn-danger btn-sm" type="submit"><i class="fa-solid fa-ban"></i> Cancel</button></form>
                <?php endif; ?>
                <?php if ($canManage): ?>
                  <a class="btn btn-ghost btn-sm" href="scheduling.php?edit=<?= (int)$s['id'] ?>" aria-label="Edit <?= ssms_e($s['title']) ?>"><i class="fa-solid fa-pen"></i> Edit</a>
                  <form method="POST" class="inline-form" data-confirm="Remove this session? Only possible when it has no related records."><?= ssms_csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit" aria-label="Remove <?= ssms_e($s['title']) ?>"><i class="fa-solid fa-trash"></i></button></form>
                <?php endif; ?>
              <?php elseif ($s['status'] === 'Ongoing'): ?>
                <?php if ($canTransition): ?>
                <form method="POST" class="inline-form" data-confirm="Adjourn and mark this session Completed?"><?= ssms_csrf_field() ?><input type="hidden" name="action" value="set_status"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="status" value="Completed"><button class="btn btn-navy btn-sm" type="submit"><i class="fa-solid fa-flag-checkered"></i> Complete</button></form>
                <?php endif; ?>
                <?php if (ssms_can($user, 'tracking.view')): ?><a href="tracking.php?session_id=<?= (int)$s['id'] ?>" class="btn btn-ghost btn-sm"><i class="fa-solid fa-eye"></i> Track</a><?php endif; ?>
              <?php else: ?><span style="font-size:12px;color:var(--ink-600);">Closed</span><?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
    <div class="empty-state"><i class="fa-solid fa-calendar-xmark"></i>No sessions yet.<?= $canManage ? ' Schedule one above.' : '' ?></div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
