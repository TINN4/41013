<?php
require_once __DIR__ . '/../includes/auth.php';
$user = ssms_require('proceedings.view');
$seeDraft = ssms_can($user, 'proceedings.view_draft');
const SSMS_KINDS = ['Discussion', 'Motion', 'Response', 'Decision', 'Correction', 'Other'];

$sessions = ssms_read('sessions');
usort($sessions, fn($a, $b) => strcmp($b['date'], $a['date']));
$selectedId = ssms_selected_session($sessions);
$currentSession = null; foreach ($sessions as $s) if ((int)$s['id'] === $selectedId) $currentSession = $s;

$action = ssms_post_action();
if ($action !== '') {
    $need = ['add_log' => 'proceedings.draft', 'edit_log' => 'proceedings.draft', 'retract' => 'proceedings.draft', 'review' => 'proceedings.review', 'amend' => 'proceedings.amend'][$action] ?? null;
    if (!$need || !ssms_can($user, $need)) { ssms_audit('authz.denied', $selectedId, 'denied', ['op' => "proceedings.$action"], $user); http_response_code(403); exit('Forbidden'); }
    if (!$currentSession) ssms_done('bad', 'Session not found.', 0, 'proceedings.php');
    $sid = (int)$currentSession['id'];
    $fresh = ssms_find('sessions', $sid);
    $back = fn($t, $m) => ssms_done($t, $m, $sid, 'proceedings.php');
    if (!in_array($fresh['status'], ['Ongoing', 'Completed'], true)) $back('bad', 'Proceedings can only be recorded for a session that is ongoing or completed.');
    if (ssms_minutes_locked($sid)) $back('bad', 'Minutes for this session are approved, so proceedings are locked. Start a minutes amendment first.');
    $entry = null; $eid = (int)($_POST['id'] ?? 0);
    if ($eid) { foreach (ssms_where('proceedings', 'session_id', $sid) as $e) if ((int)$e['id'] === $eid) $entry = $e; if (!$entry) $back('bad', 'Entry not found for this session.'); }

    $kind = (string)($_POST['kind'] ?? 'Discussion'); if (!in_array($kind, SSMS_KINDS, true)) $kind = 'Discussion';
    $note = ssms_text((string)($_POST['note'] ?? ''), 4000);
    $speaker = ssms_text((string)($_POST['speaker'] ?? ''), 120);
    $agendaId = (int)($_POST['agenda_id'] ?? 0) ?: null;
    if ($agendaId) { $ok = false; foreach (ssms_where('agenda', 'session_id', $sid) as $a) if ((int)$a['id'] === $agendaId) $ok = true; if (!$ok) $back('bad', 'That agenda item does not belong to this session.'); }
    $snap = fn($e) => ['note' => $e['note'], 'kind' => $e['kind'], 'speaker' => $e['speaker'], 'agenda_id' => $e['agenda_id'], 'status' => $e['status'], 'version' => $e['version']];
    $isOwnerOrReviewer = fn($e) => (int)($e['author_id'] ?? 0) === (int)$user['id'] || ssms_can($user, 'proceedings.review');

    if ($action === 'add_log') {
        if ($note === '') $back('bad', 'Please enter what happened.');
        [$id, $dup] = ssms_insert_unique('proceedings', ['session_id' => $sid, 'timestamp' => date('Y-m-d H:i'), 'author' => $user['name'], 'author_id' => $user['id'], 'kind' => $kind, 'speaker' => $speaker, 'agenda_id' => $agendaId, 'note' => $note, 'status' => 'Draft', 'version' => 1],
            function ($rows) use ($sid, $note, $user) { foreach ($rows as $r) if ((int)$r['session_id'] === $sid && ($r['author_id'] ?? 0) == $user['id'] && $r['note'] === $note && $r['status'] !== 'Retracted' && substr($r['timestamp'], 0, 13) === date('Y-m-d H')) return 'This exact entry was just saved (duplicate submission ignored).'; return null; });
        if ($dup) $back('warn', $dup);
        ssms_audit('proceedings.added', $id, 'ok', ['session_id' => $sid, 'kind' => $kind]);
        $back('ok', 'Draft entry saved.');
    }
    if (!$entry) $back('bad', 'Entry not found.');
    if ($entry['status'] === 'Retracted') $back('bad', 'That entry was retracted.');

    if ($action === 'edit_log') {
        if ($entry['status'] !== 'Draft') $back('bad', 'Reviewed entries cannot be edited; use an amendment with a reason.');
        if (!$isOwnerOrReviewer($entry)) $back('bad', 'You can only edit your own draft entries.');
        if ($note === '') $back('bad', 'The note cannot be empty.');
        ssms_insert('proceedings_history', ['proceeding_id' => $eid, 'session_id' => $sid, 'version' => $entry['version'], 'snapshot' => $snap($entry), 'by' => $user['id'], 'by_name' => $user['name'], 'at' => ssms_now(), 'reason' => 'draft edit']);
        ssms_update('proceedings', $eid, ['note' => $note, 'kind' => $kind, 'speaker' => $speaker, 'agenda_id' => $agendaId, 'version' => $entry['version'] + 1]);
        ssms_audit('proceedings.edited', $eid, 'ok', ['session_id' => $sid, 'version' => $entry['version'] + 1]);
        $back('ok', 'Draft entry updated (previous version kept).');
    }
    if ($action === 'retract') {
        $reason = ssms_text((string)($_POST['reason'] ?? ''), 300);
        if ($entry['status'] !== 'Draft') $back('bad', 'Only draft entries can be retracted. Reviewed entries are amended, never deleted.');
        if (!$isOwnerOrReviewer($entry)) $back('bad', 'You can only retract your own draft entries.');
        if ($reason === '') $back('bad', 'A reason is required.');
        ssms_insert('proceedings_history', ['proceeding_id' => $eid, 'session_id' => $sid, 'version' => $entry['version'], 'snapshot' => $snap($entry), 'by' => $user['id'], 'by_name' => $user['name'], 'at' => ssms_now(), 'reason' => 'retracted: ' . $reason]);
        ssms_update('proceedings', $eid, ['status' => 'Retracted']);
        ssms_audit('proceedings.retracted', $eid, 'ok', ['reason' => $reason]);
        $back('ok', 'Entry retracted (kept in the record, excluded from minutes).');
    }
    if ($action === 'review') {
        if ($entry['status'] !== 'Draft') $back('bad', 'Only draft entries can be marked reviewed.');
        if ((int)($entry['author_id'] ?? 0) === (int)$user['id']) $back('bad', 'You cannot review your own entry. Another authorized person must.');
        ssms_update('proceedings', $eid, ['status' => 'Reviewed', 'reviewed_by' => $user['id'], 'reviewed_at' => ssms_now()]);
        ssms_audit('proceedings.reviewed', $eid, 'ok', ['session_id' => $sid]);
        $back('ok', 'Entry marked Reviewed.');
    }
    if ($action === 'amend') {
        $reason = ssms_text((string)($_POST['reason'] ?? ''), 300);
        if ($entry['status'] !== 'Reviewed') $back('bad', 'Only reviewed entries are amended.');
        if ($reason === '' || $note === '') $back('bad', 'Amended text and a reason are required.');
        ssms_insert('proceedings_history', ['proceeding_id' => $eid, 'session_id' => $sid, 'version' => $entry['version'], 'snapshot' => $snap($entry), 'by' => $user['id'], 'by_name' => $user['name'], 'at' => ssms_now(), 'reason' => 'amendment: ' . $reason]);
        ssms_update('proceedings', $eid, ['note' => $note, 'version' => $entry['version'] + 1, 'amended' => true]);
        ssms_audit('proceedings.amended', $eid, 'ok', ['reason' => $reason, 'version' => $entry['version'] + 1]);
        $back('ok', 'Amendment recorded; the earlier version is preserved.');
    }
    http_response_code(400); exit('Unknown action');
}

$logs = $currentSession ? array_values(array_filter(ssms_where('proceedings', 'session_id', $selectedId), fn($l) => $seeDraft || ($l['status'] ?? '') === 'Reviewed')) : [];
usort($logs, fn($a, $b) => strcmp($b['timestamp'], $a['timestamp']) ?: $b['id'] <=> $a['id']);
$agenda = $currentSession ? ssms_where('agenda', 'session_id', $selectedId) : []; usort($agenda, fn($a, $b) => $a['order'] <=> $b['order']);
$agendaName = []; foreach ($agenda as $a) $agendaName[(int)$a['id']] = $a['order'] . '. ' . $a['item'];
$hist = []; foreach (ssms_read('proceedings_history') as $h) if ((int)$h['session_id'] === $selectedId) $hist[(int)$h['proceeding_id']][] = $h;
$canDraft = $currentSession && ssms_can($user, 'proceedings.draft') && in_array($currentSession['status'], ['Ongoing', 'Completed'], true) && !ssms_minutes_locked($selectedId);
$editEntry = null; if ($canDraft && isset($_GET['edit'])) foreach ($logs as $l) if ((int)$l['id'] === (int)$_GET['edit'] && $l['status'] === 'Draft') $editEntry = $l;

$page_title = 'Proceedings Documentation';
$page_sub   = 'Log what happens during the session as it happens';
$active_page = 'proceedings';
include __DIR__ . '/../includes/header.php';
ssms_session_picker($sessions, $selectedId);
?>
<?php if (!$currentSession): ?>
  <div class="empty-state"><i class="fa-solid fa-calendar-xmark"></i>No sessions available.</div>
<?php else: ?>
<div class="notice-box">Entries are staff notes of the proceedings, not a verified verbatim transcript. <strong>Draft</strong> entries are working notes; only <strong>Reviewed</strong> entries are treated as checked. Nothing is deleted &mdash; corrections create a new version.</div>

<?php if ($canDraft): ?>
<div class="card">
  <div class="card-head"><div><h3><?= $editEntry ? 'Edit Draft Entry' : 'Add Proceedings Entry' ?></h3><p>For: <?= ssms_e($currentSession['title']) ?></p></div></div>
  <form method="POST" data-warn-unsaved>
    <?= ssms_csrf_field() ?>
    <input type="hidden" name="action" value="<?= $editEntry ? 'edit_log' : 'add_log' ?>"><input type="hidden" name="session_id" value="<?= $selectedId ?>">
    <?php if ($editEntry): ?><input type="hidden" name="id" value="<?= (int)$editEntry['id'] ?>"><?php endif; ?>
    <div class="form-row three">
      <div class="form-group"><label for="pk">Type</label><select id="pk" name="kind"><?php foreach (SSMS_KINDS as $k): ?><option <?= ($editEntry['kind'] ?? 'Discussion') === $k ? 'selected' : '' ?>><?= $k ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label for="pa">Agenda item (optional)</label><select id="pa" name="agenda_id"><option value="">&mdash; none &mdash;</option><?php foreach ($agenda as $a): ?><option value="<?= (int)$a['id'] ?>" <?= (int)($editEntry['agenda_id'] ?? 0) === (int)$a['id'] ? 'selected' : '' ?>><?= ssms_e($a['order'] . '. ' . mb_substr($a['item'], 0, 60)) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label for="ps">Speaker (optional)</label><input id="ps" name="speaker" maxlength="120" value="<?= ssms_e($editEntry['speaker'] ?? '') ?>"></div>
    </div>
    <div class="form-group"><label for="pn">What just happened?</label><textarea id="pn" name="note" maxlength="4000" placeholder="e.g. Motion to approve the ordinance was seconded and carried." required><?= ssms_e($editEntry['note'] ?? '') ?></textarea></div>
    <button type="submit" class="btn btn-navy"><i class="fa-solid fa-<?= $editEntry ? 'floppy-disk' : 'plus' ?>"></i> <?= $editEntry ? 'Save changes' : 'Add Entry' ?></button>
    <?php if ($editEntry): ?><a class="btn btn-ghost" href="proceedings.php?session_id=<?= $selectedId ?>">Cancel</a><?php endif; ?>
  </form>
</div>
<?php elseif (ssms_can($user, 'proceedings.draft')): ?>
  <div class="notice-box">Entries can be added only while the session is ongoing or completed, and not after its minutes are approved.</div>
<?php endif; ?>

<div class="card">
  <div class="card-head"><div><h3>Session Log</h3><p><?= count($logs) ?> entr<?= count($logs) === 1 ? 'y' : 'ies' ?>, most recent first.<?= $seeDraft ? '' : ' Only reviewed entries are shown to your role.' ?></p></div></div>
  <?php if ($logs): ?>
  <div class="log-feed">
    <?php foreach ($logs as $l): $st = $l['status'] ?? 'Draft'; ?>
    <div class="log-entry"><div class="dot"></div>
      <div class="body">
        <div class="meta"><?= ssms_e($l['timestamp']) ?> &middot; <?= ssms_e($l['author']) ?> &middot; <span class="badge <?= $st === 'Reviewed' ? 'badge-ok' : ($st === 'Retracted' ? 'badge-bad' : 'badge-draft') ?>"><?= ssms_e($st) ?></span>
          <span class="badge badge-grey"><?= ssms_e($l['kind'] ?? 'Discussion') ?></span><?php if (($l['version'] ?? 1) > 1): ?> <span class="badge badge-blue">v<?= (int)$l['version'] ?></span><?php endif; ?>
          <?php if (!empty($l['agenda_id']) && isset($agendaName[(int)$l['agenda_id']])): ?> &middot; Item <?= ssms_e($agendaName[(int)$l['agenda_id']]) ?><?php endif; ?>
          <?php if (!empty($l['speaker'])): ?> &middot; Speaker: <?= ssms_e($l['speaker']) ?><?php endif; ?></div>
        <div class="txt"<?= $st === 'Retracted' ? ' style="text-decoration:line-through;opacity:.6;"' : '' ?>><?= nl2br(ssms_e($l['note'])) ?></div>
        <?php if ($canDraft && $st === 'Draft' && ((int)($l['author_id'] ?? 0) === (int)$user['id'] || ssms_can($user, 'proceedings.review'))): ?>
          <div style="margin-top:6px;display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
            <a class="btn btn-ghost btn-sm" href="proceedings.php?session_id=<?= $selectedId ?>&amp;edit=<?= (int)$l['id'] ?>">Edit</a>
            <form method="POST" class="inline-form" data-confirm="Retract this draft entry? It stays on record but is excluded from minutes." style="display:inline-flex;gap:4px;"><?= ssms_csrf_field() ?><input type="hidden" name="action" value="retract"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><input type="hidden" name="session_id" value="<?= $selectedId ?>">
              <label for="rt<?= (int)$l['id'] ?>" style="position:absolute;left:-999px;">Retraction reason</label><input id="rt<?= (int)$l['id'] ?>" name="reason" placeholder="Reason" required maxlength="300" style="padding:4px 8px;border:1px solid var(--line);border-radius:7px;font-size:12px;"><button class="btn btn-ghost btn-sm" type="submit">Retract</button></form>
          </div>
        <?php endif; ?>
        <?php if ($st === 'Draft' && ssms_can($user, 'proceedings.review') && (int)($l['author_id'] ?? 0) !== (int)$user['id'] && $currentSession['status'] !== 'Scheduled' && !ssms_minutes_locked($selectedId)): ?>
          <form method="POST" class="inline-form" style="margin-top:6px;"><?= ssms_csrf_field() ?><input type="hidden" name="action" value="review"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><input type="hidden" name="session_id" value="<?= $selectedId ?>"><button class="btn btn-gold btn-sm" type="submit"><i class="fa-solid fa-check"></i> Mark reviewed</button></form>
        <?php endif; ?>
        <?php if ($st === 'Reviewed' && ssms_can($user, 'proceedings.amend') && !ssms_minutes_locked($selectedId) && in_array($currentSession['status'], ['Ongoing', 'Completed'], true)): ?>
          <details style="margin-top:6px;"><summary style="cursor:pointer;font-size:12px;">Amend (reason required)</summary>
            <form method="POST" data-warn-unsaved><?= ssms_csrf_field() ?><input type="hidden" name="action" value="amend"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><input type="hidden" name="session_id" value="<?= $selectedId ?>">
              <div class="form-group"><label for="am<?= (int)$l['id'] ?>">Corrected text</label><textarea id="am<?= (int)$l['id'] ?>" name="note" maxlength="4000" required><?= ssms_e($l['note']) ?></textarea></div>
              <div class="form-group"><label for="ar<?= (int)$l['id'] ?>">Reason</label><input id="ar<?= (int)$l['id'] ?>" name="reason" maxlength="300" required></div>
              <button class="btn btn-navy btn-sm" type="submit">Record amendment</button></form></details>
        <?php endif; ?>
        <?php if ($seeDraft && !empty($hist[(int)$l['id']])): ?>
          <details style="margin-top:6px;"><summary style="cursor:pointer;font-size:12px;">Version history (<?= count($hist[(int)$l['id']]) ?>)</summary>
            <?php foreach ($hist[(int)$l['id']] as $h): ?><p style="font-size:12px;margin:4px 0;"><strong>v<?= (int)$h['version'] ?></strong> &middot; <?= ssms_e($h['at']) ?> &middot; <?= ssms_e($h['by_name']) ?> (<?= ssms_e($h['reason']) ?>)<br><?= nl2br(ssms_e($h['snapshot']['note'])) ?></p><?php endforeach; ?></details>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
    <div class="empty-state"><i class="fa-solid fa-file-lines"></i>No proceedings logged yet for this session.</div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
