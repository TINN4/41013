<?php
require_once __DIR__ . '/../includes/auth.php';
$user = ssms_require(['minutes.view_draft', 'minutes.view_approved', 'minutes.view_published']);

$seesDraft = ssms_can($user, 'minutes.view_draft');
$seesApproved = $seesDraft || ssms_can($user, 'minutes.view_approved');
$visible = function (array $m) use ($seesDraft, $seesApproved) {
    if ($seesDraft) return true;
    if ($seesApproved) return in_array($m['status'], ['Approved', 'Published'], true);
    return $m['status'] === 'Published';                                   // observers: published only
};

/** Build the minutes content from SAVED data only. Missing data stays null and is shown as unavailable. */
function ssms_minutes_snapshot(array $s): array {
    $sid = (int)$s['id'];
    $members = ssms_read('members');
    $att = []; foreach (ssms_where('attendance', 'session_id', $sid) as $a) $att[(int)$a['member_id']] = $a;
    $groups = ['present' => [], 'late' => [], 'excused' => [], 'absent' => [], 'unrecorded' => []];
    foreach ($members as $m) {
        $row = $att[(int)$m['id']] ?? null;
        $key = $row ? strtolower($row['status']) : 'unrecorded';
        $label = $m['name'] . ($row && !empty($row['time_out']) ? ' (left ' . $row['time_out'] . ')' : '') . ($row && $row['status'] === 'Late' && $row['time_in'] ? ' (arrived ' . $row['time_in'] . ')' : '');
        $groups[$key][] = $label;
    }
    $q = ssms_quorum($sid);
    $agenda = ssms_where('agenda', 'session_id', $sid); usort($agenda, fn($a, $b) => $a['order'] <=> $b['order']);
    $procs = array_values(array_filter(ssms_where('proceedings', 'session_id', $sid), fn($p) => ($p['status'] ?? 'Draft') !== 'Retracted'));
    usort($procs, fn($a, $b) => strcmp($a['timestamp'], $b['timestamp']) ?: $a['id'] <=> $b['id']);
    return [
        'session' => ['title' => $s['title'], 'type' => $s['type'], 'date' => $s['date'], 'time' => $s['time'], 'end_time' => $s['end_time'] ?? null, 'venue' => $s['venue'],
                      'status' => $s['status'], 'started_at' => $s['started_at'] ?? null, 'ended_at' => $s['ended_at'] ?? null],
        'attendance' => $groups,
        'quorum' => ['status' => $q['status'], 'present' => $q['present'], 'needed' => $q['needed'], 'basis' => $q['basis'], 'recorded' => $q['recorded'], 'rule_confirmed' => $q['rule_confirmed']],
        'agenda' => array_map(fn($a) => ['order' => $a['order'], 'item' => $a['item'], 'presenter' => $a['presenter'], 'status' => $a['status']], $agenda),
        'proceedings' => array_map(fn($p) => ['timestamp' => $p['timestamp'], 'kind' => $p['kind'] ?? 'Discussion', 'speaker' => $p['speaker'] ?? '', 'note' => $p['note'], 'status' => $p['status'] ?? 'Draft', 'agenda_no' => null], $procs),
    ];
}

$sessions = ssms_read('sessions');
usort($sessions, fn($a, $b) => strcmp($b['date'], $a['date']));
$selectedId = ssms_selected_session($sessions, 'Completed');
$currentSession = null; foreach ($sessions as $s) if ((int)$s['id'] === $selectedId) $currentSession = $s;

$action = ssms_post_action();
if ($action !== '') {
    $need = ['generate' => 'minutes.generate', 'save_edit' => 'minutes.edit', 'submit' => 'minutes.submit', 'approve' => 'minutes.approve', 'return' => 'minutes.approve', 'publish' => 'minutes.publish', 'amend' => 'minutes.edit'][$action] ?? null;
    if (!$need || !ssms_can($user, $need)) { ssms_audit('authz.denied', $selectedId, 'denied', ['op' => "minutes.$action"], $user); http_response_code(403); exit('Forbidden'); }
    if (!$currentSession) ssms_done('bad', 'Session not found.', 0, 'minutes.php');
    $sid = (int)$currentSession['id'];
    $fresh = ssms_find('sessions', $sid);
    $back = fn($t, $m) => ssms_done($t, $m, $sid, 'minutes.php');
    $mid = (int)($_POST['id'] ?? 0); $mn = null;
    if ($mid) { foreach (ssms_where('minutes', 'session_id', $sid) as $x) if ((int)$x['id'] === $mid) $mn = $x; if (!$mn) $back('bad', 'Minutes record not found for this session.'); }
    $edit = ['call_to_order' => ssms_text((string)($_POST['call_to_order'] ?? ''), 2000), 'adjournment_text' => ssms_text((string)($_POST['adjournment_text'] ?? ''), 2000), 'notes' => ssms_text((string)($_POST['notes'] ?? ''), 4000)];

    if ($action === 'generate') {
        if (!in_array($fresh['status'], ['Ongoing', 'Completed'], true)) $back('bad', 'Minutes can only be generated for a session that is ongoing or completed.');
        $existing = array_values(array_filter(ssms_where('minutes', 'session_id', $sid), fn($m) => $m['status'] !== 'Superseded'));
        $work = null; foreach ($existing as $m) if (in_array($m['status'], ['Draft', 'PendingReview'], true)) $work = $m;
        foreach ($existing as $m) if (in_array($m['status'], ['Approved', 'Published'], true) && !$work) $back('bad', 'Approved minutes already exist. Use "Start amendment" to revise them.');
        if ($work && $work['status'] === 'PendingReview') $back('bad', 'These minutes are awaiting review. They must be returned for revision before regenerating.');
        $content = ssms_minutes_snapshot($fresh);
        if ($work) {                                                          // refresh the DRAFT, keeping a version of the old one
            ssms_insert('minutes_versions', ['minutes_id' => $work['id'], 'session_id' => $sid, 'content' => $work['content'], 'editable' => $work['editable'], 'at' => ssms_now(), 'by' => $user['id'], 'note' => 'replaced by regeneration']);
            ssms_update('minutes', $work['id'], ['content' => $content, 'generated_at' => ssms_now(), 'generated_by' => $user['name'], 'generated_by_id' => $user['id']]);
            ssms_audit('minutes.regenerated', $work['id'], 'ok', ['session_id' => $sid]);
            $back('ok', 'Draft minutes refreshed from the latest saved records. Your manual text was kept.');
        }
        [$id, $dup] = ssms_insert_unique('minutes', ['session_id' => $sid, 'version' => 1, 'status' => 'Draft', 'content' => $content, 'editable' => ['call_to_order' => '', 'adjournment_text' => '', 'notes' => ''],
            'generated_at' => ssms_now(), 'generated_by' => $user['name'], 'generated_by_id' => $user['id'], 'amendment_of' => null],
            function ($rows) use ($sid) { foreach ($rows as $r) if ((int)$r['session_id'] === $sid && !in_array($r['status'], ['Superseded'], true)) return 'Minutes already exist for this session.'; return null; });
        if ($dup) $back('warn', $dup);
        ssms_audit('minutes.generated', $id, 'ok', ['session_id' => $sid]);
        $back('ok', 'Draft minutes generated. They are NOT approved; review and edit them before submitting.');
    }
    if (!$mn) $back('bad', 'No minutes selected.');
    if ($action === 'save_edit') {
        if ($mn['status'] !== 'Draft') $back('bad', 'Only draft minutes can be edited.');
        ssms_insert('minutes_versions', ['minutes_id' => $mid, 'session_id' => $sid, 'content' => null, 'editable' => $mn['editable'], 'at' => ssms_now(), 'by' => $user['id'], 'note' => 'manual edit']);
        ssms_update('minutes', $mid, ['editable' => $edit]);
        ssms_audit('minutes.edited', $mid, 'ok', ['session_id' => $sid]);
        $back('ok', 'Draft saved.');
    }
    if ($action === 'submit') {
        if ($mn['status'] !== 'Draft') $back('bad', 'Only draft minutes can be submitted for review.');
        if ($fresh['status'] !== 'Completed') $back('bad', 'Complete (adjourn) the session before submitting its minutes for approval.');
        ssms_update('minutes', $mid, ['status' => 'PendingReview', 'submitted_by' => $user['id'], 'submitted_by_name' => $user['name'], 'submitted_at' => ssms_now()]);
        ssms_audit('minutes.submitted', $mid, 'ok', ['session_id' => $sid]);
        $back('ok', 'Submitted for review by the Presiding Officer.');
    }
    if ($action === 'return') {
        $why = ssms_text((string)($_POST['comment'] ?? ''), 400);
        if ($mn['status'] !== 'PendingReview' || $why === '') $back('bad', 'Only minutes under review can be returned, and a comment is required.');
        ssms_update('minutes', $mid, ['status' => 'Draft', 'returned_comment' => $why, 'returned_by' => $user['name']]);
        ssms_audit('minutes.returned', $mid, 'ok', ['comment' => $why]);
        $back('ok', 'Returned to the Secretariat for revision.');
    }
    if ($action === 'approve') {
        if ($mn['status'] !== 'PendingReview') $back('bad', 'Only minutes pending review can be approved.');
        if ($fresh['status'] !== 'Completed') $back('bad', 'The session must be completed before its minutes are approved.');
        if ((int)($mn['submitted_by'] ?? 0) === (int)$user['id'] || (int)($mn['generated_by_id'] ?? 0) === (int)$user['id']) $back('bad', 'You cannot approve minutes you prepared or submitted.');
        $ok = false;
        ssms_mutate('minutes', function ($rows) use ($mid, $sid, $user, &$ok) {            // compare-and-set inside lock
            foreach ($rows as &$r) if ((int)$r['id'] === $mid && $r['status'] === 'PendingReview') { $r['status'] = 'Approved'; $r['approved_by'] = $user['id']; $r['approved_by_name'] = $user['name']; $r['approved_at'] = ssms_now(); $ok = true; }
            unset($r);
            if ($ok) foreach ($rows as &$r) if ((int)$r['session_id'] === $sid && (int)$r['id'] !== $mid && in_array($r['status'], ['Approved', 'Published'], true)) { $r['status'] = 'Superseded'; $r['superseded_by'] = $mid; }
            return $rows;
        });
        if (!$ok) $back('bad', 'These minutes were already acted on by someone else.');
        ssms_audit('minutes.approved', $mid, 'ok', ['session_id' => $sid, 'version' => $mn['version']]);
        $back('ok', 'Minutes approved. They are now locked; changes need a formal amendment.');
    }
    if ($action === 'publish') {
        if ($mn['status'] !== 'Approved') $back('bad', 'Only approved minutes can be published.');
        ssms_update('minutes', $mid, ['status' => 'Published', 'published_by' => $user['name'], 'published_at' => ssms_now()]);
        ssms_audit('minutes.published', $mid, 'ok', ['session_id' => $sid]);
        $back('ok', 'Minutes published.');
    }
    if ($action === 'amend') {
        $reason = ssms_text((string)($_POST['reason'] ?? ''), 400);
        if (!in_array($mn['status'], ['Approved', 'Published'], true)) $back('bad', 'Only approved or published minutes are amended.');
        if ($reason === '') $back('bad', 'A reason for the amendment is required.');
        foreach (ssms_where('minutes', 'session_id', $sid) as $x) if (in_array($x['status'], ['Draft', 'PendingReview'], true)) $back('bad', 'An amendment is already in progress.');
        $id = ssms_insert('minutes', ['session_id' => $sid, 'version' => (int)$mn['version'] + 1, 'status' => 'Draft', 'content' => ssms_minutes_snapshot($fresh), 'editable' => $mn['editable'],
            'generated_at' => ssms_now(), 'generated_by' => $user['name'], 'generated_by_id' => $user['id'], 'amendment_of' => $mid, 'amendment_reason' => $reason]);
        ssms_audit('minutes.amendment_started', $id, 'ok', ['of' => $mid, 'reason' => $reason]);
        $back('ok', 'Amendment draft created. The approved version stays in force until the amendment is approved.');
    }
    http_response_code(400); exit('Unknown action');
}

$all = $currentSession ? array_values(array_filter(ssms_where('minutes', 'session_id', $selectedId), $visible)) : [];
usort($all, fn($a, $b) => $b['version'] <=> $a['version'] ?: $b['id'] <=> $a['id']);
$working = null; $official = null;
foreach ($all as $m) { if (!$working && in_array($m['status'], ['Draft', 'PendingReview'], true)) $working = $m; if (!$official && in_array($m['status'], ['Approved', 'Published'], true)) $official = $m; }
$show = $_GET['show'] ?? '';
$doc = ($show === 'official' && $official) ? $official : ($working ?? $official);
$statusLabel = ['Draft' => 'DRAFT — not reviewed or approved', 'PendingReview' => 'PENDING REVIEW — not yet approved', 'Approved' => 'APPROVED', 'Published' => 'APPROVED & PUBLISHED', 'Superseded' => 'SUPERSEDED'];
$na = '<em>Not available</em>';
$list = fn($a) => $a ? implode('<br>', array_map('ssms_e', $a)) : '&mdash;';

$page_title = 'Minutes Generation';
$page_sub   = 'Compile saved agenda, attendance & proceedings into minutes; review and approve';
$active_page = 'minutes';
include __DIR__ . '/../includes/header.php';
$extra = '';
if ($currentSession && ssms_can($user, 'minutes.generate') && in_array($currentSession['status'], ['Ongoing', 'Completed'], true) && !$official || ($working && ssms_can($user, 'minutes.generate') && $working['status'] === 'Draft')) {
    $extra .= '<form method="POST" class="inline-form">' . ssms_csrf_field() . '<input type="hidden" name="action" value="generate"><input type="hidden" name="session_id" value="' . $selectedId . '"><button type="submit" class="btn btn-navy"><i class="fa-solid fa-wand-magic-sparkles"></i> ' . ($working ? 'Refresh draft from records' : 'Generate Draft Minutes') . '</button></form>';
}
if ($doc) $extra .= '<button type="button" onclick="window.print()" class="btn btn-ghost"><i class="fa-solid fa-print"></i> Print / Save PDF</button>';
ssms_session_picker($sessions, $selectedId, $extra);
?>

<?php if (!$currentSession): ?>
  <div class="empty-state"><i class="fa-solid fa-calendar-xmark"></i>No sessions available.</div>
<?php elseif (!$doc): ?>
  <div class="card"><div class="empty-state"><i class="fa-solid fa-file-signature"></i><?= $seesDraft ? 'No minutes yet for this session. Draft minutes can be generated once the session is ongoing or completed; they are compiled only from saved records.' : 'No approved minutes are available for this session.' ?></div></div>
<?php else:
  $c = $doc['content']; $ed = $doc['editable']; $S = $c['session'];
  $qLabel = ssms_quorum_label($c['quorum']['status']);
?>
<?php if ($seesDraft && ($working && $official)): ?><div class="notice-box no-print">An amendment is in progress. Showing the <?= $doc['id'] === $working['id'] ? 'amendment draft' : 'current official version' ?>. <a href="minutes.php?session_id=<?= $selectedId ?>&amp;show=<?= $doc['id'] === $working['id'] ? 'official' : 'working' ?>">Switch view</a></div><?php endif; ?>
<?php if (!empty($doc['returned_comment']) && $doc['status'] === 'Draft'): ?><div class="alert alert-warn no-print"><i class="fa-solid fa-rotate-left"></i> Returned by <?= ssms_e($doc['returned_by'] ?? '') ?>: <?= ssms_e($doc['returned_comment']) ?></div><?php endif; ?>

<div class="minutes-doc">
  <p class="watermark-draft"><?= ssms_e($statusLabel[$doc['status']] ?? $doc['status']) ?> &middot; version <?= (int)$doc['version'] ?></p>
  <h2>Minutes of the Session</h2>
  <p class="center" style="margin:0;color:var(--ink-600);">Sangguniang Panlungsod ng San Jose del Monte</p>
  <hr>
  <table>
    <tr><td style="width:160px;"><strong>Session</strong></td><td><?= ssms_e($S['title']) ?> (<?= ssms_e($S['type']) ?>)</td></tr>
    <tr><td><strong>Date / Time</strong></td><td><?= ssms_e($S['date']) ?> &middot; <?= ssms_e($S['time']) ?><?= $S['end_time'] ? ' &ndash; ' . ssms_e($S['end_time']) . ' (scheduled)' : '' ?></td></tr>
    <tr><td><strong>Venue</strong></td><td><?= $S['venue'] ? ssms_e($S['venue']) : $na ?></td></tr>
    <tr><td><strong>Called to order</strong></td><td><?= $S['started_at'] ? ssms_e(date('Y-m-d H:i', (int)$S['started_at'])) : $na ?></td></tr>
    <tr><td><strong>Adjourned</strong></td><td><?= $S['ended_at'] ? ssms_e(date('Y-m-d H:i', (int)$S['ended_at'])) : $na ?></td></tr>
    <tr><td><strong>Compiled</strong></td><td><?= ssms_e($doc['generated_at']) ?> by <?= ssms_e($doc['generated_by']) ?></td></tr>
    <tr><td><strong>Review / approval</strong></td><td><?= !empty($doc['submitted_at']) ? 'Submitted ' . ssms_e($doc['submitted_at']) . ' by ' . ssms_e($doc['submitted_by_name']) . '. ' : 'Not yet submitted. ' ?><?= !empty($doc['approved_at']) ? 'Approved ' . ssms_e($doc['approved_at']) . ' by ' . ssms_e($doc['approved_by_name']) . '.' : 'Not approved.' ?><?= !empty($doc['amendment_reason']) ? ' Amendment reason: ' . ssms_e($doc['amendment_reason']) : '' ?></td></tr>
  </table>

  <h3 style="font-family:'Fraunces',serif;color:var(--navy-950);">I. Attendance and Quorum</h3>
  <table>
    <tr><th style="width:25%;">Present (<?= count($c['attendance']['present']) + count($c['attendance']['late']) ?>)</th><th style="width:25%;">Excused (<?= count($c['attendance']['excused']) ?>)</th><th style="width:25%;">Absent (<?= count($c['attendance']['absent']) ?>)</th><th>Not recorded (<?= count($c['attendance']['unrecorded']) ?>)</th></tr>
    <tr><td><?= $list(array_merge($c['attendance']['present'], array_map(fn($n) => $n . ' [late]', $c['attendance']['late']))) ?></td><td><?= $list($c['attendance']['excused']) ?></td><td><?= $list($c['attendance']['absent']) ?></td><td><?= $list($c['attendance']['unrecorded']) ?></td></tr>
  </table>
  <p><strong>Quorum (as at compilation):</strong> <?= ssms_e($qLabel) ?> &mdash; <?= (int)$c['quorum']['present'] ?> present, <?= (int)$c['quorum']['needed'] ?> needed of <?= (int)$c['quorum']['basis'] ?> eligible members.<?= $c['quorum']['rule_confirmed'] ? '' : ' <em>(Quorum rule not yet confirmed by the authorized office.)</em>' ?></p>

  <h3 style="font-family:'Fraunces',serif;color:var(--navy-950);">II. Agenda &amp; Disposition</h3>
  <table>
    <tr><th style="width:8%;">No.</th><th>Agenda Item</th><th style="width:22%;">Presenter</th><th style="width:15%;">Disposition</th></tr>
    <?php foreach ($c['agenda'] as $it): ?><tr><td><?= (int)$it['order'] ?></td><td><?= ssms_e($it['item']) ?></td><td><?= ssms_e($it['presenter'] ?: '—') ?></td><td><?= ssms_e($it['status']) ?></td></tr><?php endforeach; ?>
    <?php if (!$c['agenda']): ?><tr><td colspan="4"><?= $na ?>: no agenda items were recorded.</td></tr><?php endif; ?>
  </table>

  <h3 style="font-family:'Fraunces',serif;color:var(--navy-950);">III. Proceedings</h3>
  <p style="font-size:12px;color:var(--ink-600);">Staff notes compiled from the proceedings log; not a verbatim transcript. Entries marked <em>draft</em> have not been reviewed.</p>
  <?php foreach ($c['proceedings'] as $l): ?>
    <p style="margin:0 0 10px;"><strong><?= ssms_e($l['timestamp']) ?></strong> &mdash; <em><?= ssms_e($l['kind']) ?><?= $l['speaker'] ? ', ' . ssms_e($l['speaker']) : '' ?><?= $l['status'] !== 'Reviewed' ? ', draft' : '' ?>:</em> <?= nl2br(ssms_e($l['note'])) ?></p>
  <?php endforeach; ?>
  <?php if (!$c['proceedings']): ?><p><?= $na ?>: no proceedings were logged for this session.</p><?php endif; ?>

  <?php foreach (['call_to_order' => 'Call to order (secretariat text)', 'adjournment_text' => 'Adjournment', 'notes' => 'Additional notes'] as $k => $lbl): if (!empty($ed[$k]) && !($seesDraft && $doc['status'] === 'Draft' && false)): ?>
    <h3 style="font-family:'Fraunces',serif;color:var(--navy-950);"><?= ssms_e($lbl) ?></h3><p><?= nl2br(ssms_e($ed[$k])) ?></p>
  <?php endif; endforeach; ?>
  <hr>
  <p class="center" style="color:var(--ink-600);font-size:12px;">Compiled by the Session and Legislative Meeting Management System from saved records. <?= in_array($doc['status'], ['Approved', 'Published'], true) ? 'Approved by ' . ssms_e($doc['approved_by_name'] ?? '') . '.' : 'This document has not been approved and is not official.' ?></p>
</div>

<?php if ($seesDraft): ?>
<div class="card no-print" style="margin-top:16px;"><div class="card-head"><div><h3>Workflow</h3><p>Status: <strong><?= ssms_e($doc['status']) ?></strong></p></div></div>
  <?php if ($doc['status'] === 'Draft' && ssms_can($user, 'minutes.edit')): ?>
  <form method="POST" data-warn-unsaved><?= ssms_csrf_field() ?><input type="hidden" name="action" value="save_edit"><input type="hidden" name="id" value="<?= (int)$doc['id'] ?>"><input type="hidden" name="session_id" value="<?= $selectedId ?>">
    <div class="form-group"><label for="m1">Call to order (secretariat text)</label><textarea id="m1" name="call_to_order" maxlength="2000"><?= ssms_e($ed['call_to_order']) ?></textarea></div>
    <div class="form-group"><label for="m2">Adjournment</label><textarea id="m2" name="adjournment_text" maxlength="2000"><?= ssms_e($ed['adjournment_text']) ?></textarea></div>
    <div class="form-group"><label for="m3">Additional notes / corrections</label><textarea id="m3" name="notes" maxlength="4000"><?= ssms_e($ed['notes']) ?></textarea></div>
    <button class="btn btn-ghost" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save draft</button>
  </form><?php endif; ?>
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;">
  <?php if ($doc['status'] === 'Draft' && ssms_can($user, 'minutes.submit')): ?>
    <form method="POST" class="inline-form" data-confirm="Submit these minutes for review by the Presiding Officer?"><?= ssms_csrf_field() ?><input type="hidden" name="action" value="submit"><input type="hidden" name="id" value="<?= (int)$doc['id'] ?>"><input type="hidden" name="session_id" value="<?= $selectedId ?>"><button class="btn btn-navy" type="submit">Submit for review</button></form>
  <?php endif; ?>
  <?php if ($doc['status'] === 'PendingReview' && ssms_can($user, 'minutes.approve')): ?>
    <form method="POST" class="inline-form" data-confirm="Approve these minutes? They will be locked."><?= ssms_csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= (int)$doc['id'] ?>"><input type="hidden" name="session_id" value="<?= $selectedId ?>"><button class="btn btn-gold" type="submit"><i class="fa-solid fa-stamp"></i> Approve minutes</button></form>
    <form method="POST" class="inline-form" style="display:inline-flex;gap:6px;"><?= ssms_csrf_field() ?><input type="hidden" name="action" value="return"><input type="hidden" name="id" value="<?= (int)$doc['id'] ?>"><input type="hidden" name="session_id" value="<?= $selectedId ?>"><label for="rc" style="position:absolute;left:-999px;">Comment</label><input id="rc" name="comment" placeholder="Reason for returning" required maxlength="400" style="padding:7px 10px;border:1px solid var(--line);border-radius:8px;"><button class="btn btn-ghost" type="submit">Return for revision</button></form>
  <?php endif; ?>
  <?php if ($doc['status'] === 'Approved' && ssms_can($user, 'minutes.publish')): ?>
    <form method="POST" class="inline-form" data-confirm="Publish these minutes to authorized viewers?"><?= ssms_csrf_field() ?><input type="hidden" name="action" value="publish"><input type="hidden" name="id" value="<?= (int)$doc['id'] ?>"><input type="hidden" name="session_id" value="<?= $selectedId ?>"><button class="btn btn-navy" type="submit">Publish</button></form>
  <?php endif; ?>
  <?php if (in_array($doc['status'], ['Approved', 'Published'], true) && ssms_can($user, 'minutes.edit') && !$working): ?>
    <form method="POST" class="inline-form" style="display:inline-flex;gap:6px;"><?= ssms_csrf_field() ?><input type="hidden" name="action" value="amend"><input type="hidden" name="id" value="<?= (int)$doc['id'] ?>"><input type="hidden" name="session_id" value="<?= $selectedId ?>"><label for="ar" style="position:absolute;left:-999px;">Amendment reason</label><input id="ar" name="reason" placeholder="Reason for amendment" required maxlength="400" style="padding:7px 10px;border:1px solid var(--line);border-radius:8px;"><button class="btn btn-ghost" type="submit">Start amendment</button></form>
  <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
