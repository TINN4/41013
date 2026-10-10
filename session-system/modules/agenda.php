<?php
require_once __DIR__ . '/../includes/auth.php';
$user = ssms_require(['agenda.view', 'agenda.view_final']);
$fullView = ssms_can($user, 'agenda.view');

$all = ssms_read('sessions');
usort($all, fn($a, $b) => strcmp($b['date'], $a['date']));
// Observers only see agendas that have been finalized.
$sessions = $fullView ? $all : array_values(array_filter($all, fn($s) => ($s['agenda_status'] ?? 'Draft') === 'Final' && $s['status'] !== 'Cancelled'));
$selectedId = ssms_selected_session($sessions);
$currentSession = null; foreach ($sessions as $s) if ((int)$s['id'] === $selectedId) $currentSession = $s;

$ITEM_STATUSES = ['Pending', 'Discussed', 'Approved', 'Deferred'];
$editableState = fn($s) => $s && in_array($s['agenda_status'] ?? 'Draft', ['Draft', 'Revision'], true) && in_array($s['status'], ['Scheduled', 'Ongoing'], true);

$action = ssms_post_action();
if ($action !== '') {
    $s = $currentSession;
    if (!$s) { ssms_flash('bad', 'Session not found.'); ssms_redirect('agenda.php'); }
    $sid = (int)$s['id'];
    $itemId = (int)($_POST['id'] ?? 0);
    $item = null; foreach (ssms_where('agenda', 'session_id', $sid) as $it) if ((int)$it['id'] === $itemId) $item = $it;   // item MUST belong to this session (IDOR)
    $need = ['add_item' => 'agenda.edit', 'edit_item' => 'agenda.edit', 'move' => 'agenda.edit', 'delete' => 'agenda.edit', 'finalize' => 'agenda.finalize', 'revise' => 'agenda.revise', 'set_status' => 'tracking.update_current'][$action] ?? null;
    if (!$need || !ssms_can($user, $need)) { ssms_audit('authz.denied', $sid, 'denied', ['op' => "agenda.$action"], $user); http_response_code(403); exit('Forbidden'); }
    $fresh = ssms_find('sessions', $sid);

    if (in_array($action, ['add_item', 'edit_item', 'move', 'delete'], true) && !$editableState($fresh)) {
        ssms_done('bad', ($fresh['agenda_status'] ?? 'Draft') === 'Final' ? 'This agenda is finalized. Start a revision (with a reason) to change it.' : 'The agenda of a ' . strtolower($fresh['status']) . ' session cannot be changed.', $sid, 'agenda.php');
    }
    if ($action === 'add_item' || $action === 'edit_item') {
        $title = ssms_text((string)($_POST['item'] ?? ''), 300);
        $pres = ssms_text((string)($_POST['presenter'] ?? ''), 120);
        $desc = ssms_text((string)($_POST['description'] ?? ''), 1000);
        if ($title === '') ssms_done('bad', 'Agenda item title is required.', $sid, 'agenda.php');
        if ($action === 'add_item') {
            [$newId, $dup] = ssms_insert_unique('agenda', ['session_id' => $sid, 'order' => 0, 'item' => $title, 'presenter' => $pres, 'description' => $desc, 'status' => 'Pending'], function ($rows) use ($sid, $title) {
                foreach ($rows as $r) if ((int)$r['session_id'] === $sid && strcasecmp($r['item'], $title) === 0) return 'That agenda item already exists for this session.'; return null; });
            if ($dup) ssms_done('bad', $dup, $sid, 'agenda.php');
            ssms_mutate('agenda', function ($rows) use ($sid, $newId) {          // order = max+1 (not count+1, which collided after deletes)
                $max = 0; foreach ($rows as $r) if ((int)$r['session_id'] === $sid && (int)$r['id'] !== $newId) $max = max($max, (int)$r['order']);
                foreach ($rows as &$r) if ((int)$r['id'] === $newId) $r['order'] = $max + 1;
                return $rows;
            });
            ssms_audit('agenda.item_added', $newId, 'ok', ['session_id' => $sid, 'item' => $title]);
            ssms_done('ok', 'Agenda item added.', $sid, 'agenda.php');
        }
        if (!$item) ssms_done('bad', 'Agenda item not found for this session.', $sid, 'agenda.php');
        ssms_update('agenda', $itemId, ['item' => $title, 'presenter' => $pres, 'description' => $desc]);
        ssms_audit('agenda.item_edited', $itemId, 'ok', ['session_id' => $sid, 'before' => $item['item'], 'after' => $title]);
        ssms_done('ok', 'Agenda item updated.', $sid, 'agenda.php');
    }
    if ($action === 'move') {
        $dir = ($_POST['dir'] ?? '') === 'up' ? -1 : 1;
        if (!$item) ssms_done('bad', 'Agenda item not found for this session.', $sid, 'agenda.php');
        ssms_mutate('agenda', function ($rows) use ($sid, $itemId, $dir) {
            $idx = []; foreach ($rows as $i => $r) if ((int)$r['session_id'] === $sid) $idx[] = $i;
            usort($idx, fn($a, $b) => $rows[$a]['order'] <=> $rows[$b]['order']);
            foreach ($idx as $n => $i) $rows[$i]['order'] = $n + 1;                  // heal any duplicate/gapped numbering first
            foreach ($idx as $n => $i) if ((int)$rows[$i]['id'] === $itemId && isset($idx[$n + $dir])) {
                $j = $idx[$n + $dir]; [$rows[$i]['order'], $rows[$j]['order']] = [$rows[$j]['order'], $rows[$i]['order']];
                break;
            }
            return $rows;
        });
        ssms_audit('agenda.item_moved', $itemId, 'ok', ['dir' => $dir]);
        ssms_redirect('agenda.php?session_id=' . $sid);
    }
    if ($action === 'delete') {
        if (!$item) ssms_done('bad', 'Agenda item not found for this session.', $sid, 'agenda.php');
        ssms_delete('agenda', $itemId);
        ssms_mutate('agenda', function ($rows) use ($sid) {                          // keep numbering contiguous
            $idx = []; foreach ($rows as $i => $r) if ((int)$r['session_id'] === $sid) $idx[] = $i;
            usort($idx, fn($a, $b) => $rows[$a]['order'] <=> $rows[$b]['order']);
            foreach ($idx as $n => $i) $rows[$i]['order'] = $n + 1;
            return $rows;
        });
        if ((int)($fresh['current_agenda_id'] ?? 0) === $itemId) ssms_update('sessions', $sid, ['current_agenda_id' => null]);
        ssms_audit('agenda.item_deleted', $itemId, 'ok', ['session_id' => $sid, 'item' => $item['item']]);
        ssms_done('ok', 'Agenda item removed.', $sid, 'agenda.php');
    }
    if ($action === 'set_status') {
        $st = (string)($_POST['status'] ?? '');
        if (!$item || !in_array($st, $ITEM_STATUSES, true)) ssms_done('bad', 'Invalid agenda item or status.', $sid, 'agenda.php');
        if ($fresh['status'] !== 'Ongoing') ssms_done('bad', 'Item dispositions are recorded while the session is ongoing.', $sid, 'agenda.php');
        ssms_update('agenda', $itemId, ['status' => $st]);
        ssms_audit('agenda.disposition_set', $itemId, 'ok', ['before' => $item['status'], 'after' => $st]);
        ssms_done('ok', 'Agenda item updated.', $sid, 'agenda.php');
    }
    if ($action === 'finalize') {
        $items = ssms_where('agenda', 'session_id', $sid);
        if (!$items) ssms_done('bad', 'Add at least one agenda item before finalizing.', $sid, 'agenda.php');
        if (!in_array($fresh['status'], ['Scheduled', 'Ongoing'], true) || ($fresh['agenda_status'] ?? 'Draft') === 'Final') ssms_done('bad', 'This agenda cannot be finalized in its current state.', $sid, 'agenda.php');
        ssms_update('sessions', $sid, ['agenda_status' => 'Final', 'updated_at' => ssms_now()]);
        ssms_audit('agenda.finalized', $sid, 'ok', ['revision' => (int)$fresh['agenda_revision'], 'items' => count($items)]);
        ssms_done('ok', 'Agenda finalized. Further changes require a documented revision.', $sid, 'agenda.php');
    }
    if ($action === 'revise') {
        $reason = ssms_text((string)($_POST['reason'] ?? ''), 400);
        if (($fresh['agenda_status'] ?? 'Draft') !== 'Final' || !in_array($fresh['status'], ['Scheduled', 'Ongoing'], true)) ssms_done('bad', 'Only a finalized agenda of an open session can be revised.', $sid, 'agenda.php');
        if ($reason === '') ssms_done('bad', 'A reason for the revision is required.', $sid, 'agenda.php');
        $rev = (int)$fresh['agenda_revision'] + 1;
        ssms_insert('agenda_revisions', ['session_id' => $sid, 'revision' => $rev, 'reason' => $reason, 'by' => $user['id'], 'by_name' => $user['name'], 'at' => ssms_now(), 'snapshot_before' => ssms_where('agenda', 'session_id', $sid)]);
        ssms_update('sessions', $sid, ['agenda_status' => 'Revision', 'agenda_revision' => $rev, 'updated_at' => ssms_now()]);
        ssms_audit('agenda.revision_opened', $sid, 'ok', ['revision' => $rev, 'reason' => $reason]);
        ssms_done('ok', "Agenda reopened as revision $rev. The previous version is preserved in the history. Finalize again when done.", $sid, 'agenda.php');
    }
    http_response_code(400); exit('Unknown action');
}

$agendaItems = $currentSession ? ssms_where('agenda', 'session_id', $selectedId) : [];
usort($agendaItems, fn($a, $b) => $a['order'] <=> $b['order']);
$revisions = $currentSession && $fullView ? array_reverse(ssms_where('agenda_revisions', 'session_id', $selectedId)) : [];
$agStatus = $currentSession['agenda_status'] ?? 'Draft';
$canEditNow = $currentSession && $editableState($currentSession) && ssms_can($user, 'agenda.edit');
$canDispose = $currentSession && $currentSession['status'] === 'Ongoing' && ssms_can($user, 'tracking.update_current');
$editItem = null;
if ($canEditNow && isset($_GET['edit'])) foreach ($agendaItems as $it) if ((int)$it['id'] === (int)$_GET['edit']) $editItem = $it;

$page_title = 'Agenda Preparation';
$page_sub   = 'Build and order the agenda for a session';
$active_page = 'agenda';
include __DIR__ . '/../includes/header.php';
ssms_session_picker($sessions, $selectedId);
?>

<?php if (!$currentSession): ?>
  <div class="empty-state"><i class="fa-solid fa-calendar-xmark"></i><?= $fullView ? 'No sessions available.' : 'No finalized agendas have been published yet.' ?></div>
<?php else: ?>

<div class="card">
  <div class="card-head"><div><h3><?= ssms_e($currentSession['title']) ?></h3>
    <p>Agenda status: <span class="badge <?= $agStatus === 'Final' ? 'badge-ok' : 'badge-draft' ?>"><?= ssms_e($agStatus === 'Revision' ? 'Revision ' . (int)$currentSession['agenda_revision'] . ' (draft)' : $agStatus) ?></span>
       &middot; Session: <span class="badge <?= ssms_session_badge($currentSession['status']) ?>"><?= ssms_e($currentSession['status']) ?></span></p></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <?php if (in_array($agStatus, ['Draft', 'Revision'], true) && ssms_can($user, 'agenda.finalize') && in_array($currentSession['status'], ['Scheduled', 'Ongoing'], true)): ?>
      <form method="POST" class="inline-form" data-confirm="Finalize this agenda? Further changes will need a documented revision."><?= ssms_csrf_field() ?><input type="hidden" name="action" value="finalize"><input type="hidden" name="session_id" value="<?= $selectedId ?>"><button class="btn btn-navy btn-sm" type="submit"><i class="fa-solid fa-lock"></i> Finalize agenda</button></form>
    <?php endif; ?>
    <?php if ($agStatus === 'Final' && ssms_can($user, 'agenda.revise') && in_array($currentSession['status'], ['Scheduled', 'Ongoing'], true)): ?>
      <form method="POST" class="inline-form" data-confirm="Reopen the finalized agenda for revision?" style="display:inline-flex;gap:6px;"><?= ssms_csrf_field() ?><input type="hidden" name="action" value="revise"><input type="hidden" name="session_id" value="<?= $selectedId ?>">
        <label for="rev-reason" style="position:absolute;left:-999px;">Reason for revision</label><input type="text" id="rev-reason" name="reason" placeholder="Reason for revision" maxlength="400" required style="padding:6px 9px;border:1px solid var(--line);border-radius:7px;font-size:12px;">
        <button class="btn btn-gold btn-sm" type="submit"><i class="fa-solid fa-pen-to-square"></i> Revise</button></form>
    <?php endif; ?>
    </div></div>
</div>

<?php if ($canEditNow): ?>
<div class="card">
  <div class="card-head"><div><h3><?= $editItem ? 'Edit Agenda Item' : 'Add Agenda Item' ?></h3><p>For: <?= ssms_e($currentSession['title']) ?></p></div></div>
  <form method="POST" data-warn-unsaved>
    <?= ssms_csrf_field() ?>
    <input type="hidden" name="action" value="<?= $editItem ? 'edit_item' : 'add_item' ?>">
    <input type="hidden" name="session_id" value="<?= $selectedId ?>">
    <?php if ($editItem): ?><input type="hidden" name="id" value="<?= (int)$editItem['id'] ?>"><?php endif; ?>
    <div class="form-row two">
      <div class="form-group"><label for="ai-item">Agenda Item</label><input type="text" id="ai-item" name="item" maxlength="300" placeholder="e.g. Second reading of proposed ordinance" value="<?= ssms_e($editItem['item'] ?? '') ?>" required></div>
      <div class="form-group"><label for="ai-pres">Presenter / Sponsor / Committee</label><input type="text" id="ai-pres" name="presenter" maxlength="120" placeholder="e.g. Hon. D. Santos" value="<?= ssms_e($editItem['presenter'] ?? '') ?>"></div>
    </div>
    <div class="form-group"><label for="ai-desc">Description (optional)</label><textarea id="ai-desc" name="description" maxlength="1000"><?= ssms_e($editItem['description'] ?? '') ?></textarea></div>
    <button type="submit" class="btn btn-navy"><i class="fa-solid fa-<?= $editItem ? 'floppy-disk' : 'plus' ?>"></i> <?= $editItem ? 'Save item' : 'Add to Agenda' ?></button>
    <?php if ($editItem): ?><a class="btn btn-ghost" href="agenda.php?session_id=<?= $selectedId ?>">Cancel</a><?php endif; ?>
  </form>
</div>
<?php elseif ($fullView && ssms_can($user, 'agenda.edit') && $agStatus === 'Final'): ?>
  <div class="notice-box"><i class="fa-solid fa-lock"></i> This agenda is finalized and locked. Use <strong>Revise</strong> to change it; the previous version is kept in the history.</div>
<?php endif; ?>

<div class="card">
  <div class="card-head"><div><h3>Agenda Order</h3><p><?= count($agendaItems) ?> item(s)<?= $canEditNow ? ' &mdash; reorder with the arrows.' : '' ?><?= $canDispose ? ' Record each item\'s disposition as the session proceeds.' : '' ?></p></div></div>
  <?php if ($agendaItems): ?>
  <div class="agenda-list">
    <?php foreach ($agendaItems as $item):
      $statusBadge = ['Pending' => 'badge-grey', 'Discussed' => 'badge-blue', 'Approved' => 'badge-ok', 'Deferred' => 'badge-warn'][$item['status']] ?? 'badge-grey';
      $isCurrent = (int)($currentSession['current_agenda_id'] ?? 0) === (int)$item['id'] && $currentSession['status'] === 'Ongoing';
    ?>
    <div class="agenda-item <?= $isCurrent ? 'current' : '' ?>">
      <div class="num"><?= (int)$item['order'] ?></div>
      <div class="body">
        <p class="title"><?= ssms_e($item['item']) ?> <?php if ($isCurrent): ?><span class="badge badge-gold" style="margin-left:6px;">Now Discussing</span><?php endif; ?></p>
        <p class="meta">Presenter: <?= ssms_e($item['presenter'] ?: '—') ?></p>
        <?php if (!empty($item['description'])): ?><p class="meta"><?= nl2br(ssms_e($item['description'])) ?></p><?php endif; ?>
      </div>
      <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
        <span class="badge <?= $statusBadge ?>"><?= ssms_e($item['status']) ?></span>
        <?php if ($canDispose): ?>
        <form method="POST" class="inline-form"><?= ssms_csrf_field() ?><input type="hidden" name="action" value="set_status"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><input type="hidden" name="session_id" value="<?= $selectedId ?>">
          <label for="st<?= (int)$item['id'] ?>" style="position:absolute;left:-999px;">Disposition of item <?= (int)$item['order'] ?></label>
          <select id="st<?= (int)$item['id'] ?>" name="status" onchange="this.form.submit()" style="padding:5px 8px;border-radius:7px;border:1px solid var(--line);font-size:11.5px;">
            <?php foreach ($ITEM_STATUSES as $st): ?><option <?= $item['status'] === $st ? 'selected' : '' ?>><?= $st ?></option><?php endforeach; ?>
          </select></form>
        <?php endif; ?>
        <?php if ($canEditNow): ?>
        <a class="btn btn-ghost btn-sm" href="agenda.php?session_id=<?= $selectedId ?>&amp;edit=<?= (int)$item['id'] ?>" aria-label="Edit item <?= (int)$item['order'] ?>"><i class="fa-solid fa-pen"></i></a>
        <?php foreach (['up' => 'arrow-up', 'down' => 'arrow-down'] as $d => $ic): ?>
        <form method="POST" class="inline-form"><?= ssms_csrf_field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><input type="hidden" name="dir" value="<?= $d ?>"><input type="hidden" name="session_id" value="<?= $selectedId ?>"><button class="btn btn-ghost btn-sm" type="submit" aria-label="Move item <?= (int)$item['order'] ?> <?= $d ?>"><i class="fa-solid fa-<?= $ic ?>"></i></button></form>
        <?php endforeach; ?>
        <form method="POST" class="inline-form" data-confirm="Remove this item?"><?= ssms_csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><input type="hidden" name="session_id" value="<?= $selectedId ?>"><button class="btn btn-ghost btn-sm" type="submit" aria-label="Remove item <?= (int)$item['order'] ?>"><i class="fa-solid fa-trash"></i></button></form>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
    <div class="empty-state"><i class="fa-solid fa-list"></i>No agenda items yet for this session.</div>
  <?php endif; ?>
</div>

<?php if ($revisions): ?>
<div class="card"><div class="card-head"><div><h3>Revision history</h3><p>Each entry preserves the agenda as it was before the revision.</p></div></div>
  <?php foreach ($revisions as $r): ?>
    <details style="margin-bottom:8px;"><summary>Revision <?= (int)$r['revision'] ?> &mdash; <?= ssms_e($r['at']) ?> by <?= ssms_e($r['by_name']) ?>: <?= ssms_e($r['reason']) ?></summary>
      <ol><?php foreach ($r['snapshot_before'] as $it): ?><li><?= ssms_e($it['item']) ?> <small>(<?= ssms_e($it['presenter'] ?: '—') ?>)</small></li><?php endforeach; ?></ol></details>
  <?php endforeach; ?></div>
<?php endif; ?>

<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
