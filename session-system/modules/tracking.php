<?php
require_once __DIR__ . '/../includes/auth.php';
$user = ssms_require('tracking.view');
$seeDraft = ssms_can($user, 'proceedings.view_draft');

$sessions = ssms_read('sessions');
usort($sessions, fn($a, $b) => strcmp($b['date'], $a['date']));
$selectedId = ssms_selected_session($sessions);
$currentSession = null; foreach ($sessions as $s) if ((int)$s['id'] === $selectedId) $currentSession = $s;

if (ssms_post_action() === 'set_current') {
    if (!ssms_can($user, 'tracking.update_current')) { ssms_audit('authz.denied', $selectedId, 'denied', ['op' => 'tracking.set_current'], $user); http_response_code(403); exit('Forbidden'); }
    $fresh = $currentSession ? ssms_find('sessions', $selectedId) : null;
    $aid = (int)($_POST['agenda_id'] ?? 0); $ok = false;
    if ($fresh) foreach (ssms_where('agenda', 'session_id', $selectedId) as $a) if ((int)$a['id'] === $aid) $ok = true;       // must belong to this session
    if (!$fresh || $fresh['status'] !== 'Ongoing') ssms_done('bad', 'The current agenda item can only be changed while the session is ongoing.', $selectedId, 'tracking.php');
    if (!$ok) ssms_done('bad', 'That agenda item does not belong to this session.', $selectedId, 'tracking.php');
    ssms_update('sessions', $selectedId, ['current_agenda_id' => $aid, 'updated_at' => ssms_now()]);
    ssms_audit('session.current_agenda_set', $selectedId, 'ok', ['agenda_id' => $aid]);
    ssms_done('ok', 'Current agenda item updated.', $selectedId, 'tracking.php');
}

$q = $currentSession ? ssms_quorum($selectedId) : null;
$agenda = $currentSession ? ssms_where('agenda', 'session_id', $selectedId) : []; usort($agenda, fn($a, $b) => $a['order'] <=> $b['order']);
$logs = $currentSession ? array_values(array_filter(ssms_where('proceedings', 'session_id', $selectedId), fn($l) => ($seeDraft ? ($l['status'] ?? 'Draft') !== 'Retracted' : ($l['status'] ?? '') === 'Reviewed'))) : [];
usort($logs, fn($a, $b) => strcmp($b['timestamp'], $a['timestamp']) ?: $b['id'] <=> $a['id']); $logs = array_slice($logs, 0, 5);
$ongoing = $currentSession && $currentSession['status'] === 'Ongoing';
$canSet = $ongoing && ssms_can($user, 'tracking.update_current');

$page_title = 'Real-Time Session Tracking';
$page_sub   = 'Live status of the selected session';
$active_page = 'tracking';
$show_live_badge = $ongoing;
include __DIR__ . '/../includes/header.php';
ssms_session_picker($sessions, $selectedId);
?>
<?php if (!$currentSession): ?>
  <div class="card"><div class="empty-state"><i class="fa-solid fa-tower-broadcast"></i>No sessions available.</div></div>
<?php else: ?>

<div class="tracker-live-box" id="tracker-box">
  <div class="tracker-live-head">
    <div>
      <div id="tr-label" style="font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:var(--gold-400);margin-bottom:4px;"><?= $ongoing ? 'Now In Session' : 'Session &mdash; ' . ssms_e($currentSession['status']) ?></div>
      <div style="font-family:'Fraunces',serif;font-size:20px;"><?= ssms_e($currentSession['title']) ?></div>
      <div style="font-size:12.5px;color:rgba(255,255,255,0.75);margin-top:3px;"><?= ssms_e($currentSession['venue']) ?></div>
    </div>
    <?php if ($ongoing && $currentSession['started_at']): ?>
    <div style="text-align:right;"><div style="font-size:11px;color:rgba(255,255,255,0.6);text-transform:uppercase;letter-spacing:.06em;">Elapsed</div>
      <div class="tracker-timer" data-timer-start="<?= (int)$currentSession['started_at'] ?>">00:00:00</div></div>
    <?php elseif ($currentSession['status'] === 'Completed' && $currentSession['started_at'] && $currentSession['ended_at']): ?>
    <div style="text-align:right;"><div style="font-size:11px;color:rgba(255,255,255,0.6);text-transform:uppercase;">Duration</div><div class="tracker-timer"><?= ssms_e(gmdate('H:i:s', max(0, (int)$currentSession['ended_at'] - (int)$currentSession['started_at']))) ?></div></div>
    <?php endif; ?>
  </div>
  <div class="tracker-live-body">
    <div class="grid grid-3">
      <div class="stat"><div class="num" id="tr-present"><?= (int)$q['present'] ?> / <?= (int)$q['basis'] ?></div><div class="lbl">Present (need <span id="tr-needed"><?= (int)$q['needed'] ?></span>)</div></div>
      <div class="stat"><div class="num" id="tr-quorum" style="font-size:20px;"><?= ssms_e(ssms_quorum_label($q['status'])) ?></div><div class="lbl">Quorum Status</div></div>
      <div class="stat"><div class="num" id="tr-agenda"><?= count(array_filter($agenda, fn($a) => $a['status'] !== 'Pending')) ?> / <?= count($agenda) ?></div><div class="lbl">Agenda Progress</div></div>
    </div>
    <p id="tr-sync" style="font-size:11.5px;margin:10px 0 0;color:rgba(255,255,255,.7);" role="status">Loaded <?= ssms_e(date('H:i:s')) ?><?= $ongoing ? '' : ' (session is not live)' ?>.</p>
  </div>
</div>

<div class="grid grid-2" style="margin-top:18px;">
  <div class="card" style="margin-bottom:0;">
    <div class="card-head"><div><h3>Agenda<?= $canSet ? ' &mdash; mark what\'s being discussed' : '' ?></h3><p><?= $canSet ? 'Click "Set as Current" to update the live view.' : 'Items in order.' ?></p></div></div>
    <div class="agenda-list">
      <?php foreach ($agenda as $item): $isCurrent = $ongoing && (int)($currentSession['current_agenda_id'] ?? 0) === (int)$item['id']; ?>
        <div class="agenda-item <?= $isCurrent ? 'current' : '' ?>" data-agenda-id="<?= (int)$item['id'] ?>">
          <div class="num"><?= (int)$item['order'] ?></div>
          <div class="body"><p class="title"><?= ssms_e($item['item']) ?></p><p class="meta"><?= ssms_e($item['status']) ?></p></div>
          <?php if ($isCurrent): ?><span class="badge badge-gold">Live Now</span>
          <?php elseif ($canSet): ?><form method="POST" class="inline-form"><?= ssms_csrf_field() ?><input type="hidden" name="action" value="set_current"><input type="hidden" name="session_id" value="<?= $selectedId ?>"><input type="hidden" name="agenda_id" value="<?= (int)$item['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">Set as Current</button></form><?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if (!$agenda): ?><div class="empty-state"><i class="fa-solid fa-list"></i>No agenda items yet.</div><?php endif; ?>
    </div>
  </div>
  <div class="card" style="margin-bottom:0;">
    <div class="card-head"><div><h3>Latest Proceedings</h3><p>Most recent entries logged<?= $seeDraft ? '' : ' (reviewed only)' ?>.</p></div></div>
    <?php if ($logs): ?><div class="log-feed"><?php foreach ($logs as $l): ?>
      <div class="log-entry"><div class="dot"></div><div class="body"><div class="meta"><?= ssms_e($l['timestamp']) ?><?= ($l['status'] ?? 'Draft') === 'Draft' ? ' &middot; draft' : '' ?></div><div class="txt"><?= nl2br(ssms_e($l['note'])) ?></div></div></div>
    <?php endforeach; ?></div>
    <?php else: ?><div class="empty-state"><i class="fa-solid fa-file-lines"></i>Nothing logged yet.</div><?php endif; ?>
    <?php if (ssms_can($user, 'proceedings.draft')): ?><a href="proceedings.php?session_id=<?= $selectedId ?>" class="btn btn-ghost btn-sm" style="margin-top:12px;"><i class="fa-solid fa-pen"></i> Add Log Entry</a><?php endif; ?>
  </div>
</div>

<?php if ($ongoing): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  ssmsPollLive(<?= (int)$selectedId ?>, '../api/live_status.php', 6000);
});
</script>
<?php endif; ?>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
