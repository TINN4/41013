<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/store.php';

$sessions = array_values(array_filter(ssms_read('sessions'), fn($s) => empty($s['archived_at']))); // archived sessions live in the Archive page instead

// Allow marking which agenda item is "now discussing" while tracking.
// Council members can watch this page live, but only staff can drive it.
if (isset($_POST['action']) && $_POST['action'] === 'set_current' && isset($_POST['session_id'], $_POST['agenda_id']) && ssms_is_staff()) {
    ssms_update('sessions', (int)$_POST['session_id'], ['current_agenda_id' => (int)$_POST['agenda_id']]);
}

$ongoingSessions = array_values(array_filter($sessions, fn($s) => $s['status'] === 'Ongoing'));
$selectedId = isset($_GET['session_id']) ? (int)$_GET['session_id'] : ($_POST['session_id'] ?? ($ongoingSessions[0]['id'] ?? null));
$currentSession = $selectedId ? ssms_find('sessions', (int)$selectedId) : null;

$members = ssms_read('members');
$attendance = $currentSession ? ssms_where('attendance', 'session_id', $currentSession['id']) : [];
$presentCount = count(array_filter($attendance, fn($a) => $a['status'] === 'Present'));
$quorumNeeded = intdiv(count($members), 2) + 1;
$quorumMet = $presentCount >= $quorumNeeded;

$agenda = $currentSession ? ssms_where('agenda', 'session_id', $currentSession['id']) : [];
usort($agenda, fn($a, $b) => $a['order'] <=> $b['order']);

$logs = $currentSession ? ssms_where('proceedings', 'session_id', $currentSession['id']) : [];
usort($logs, fn($a, $b) => strcmp($b['timestamp'], $a['timestamp']));
$logs = array_slice($logs, 0, 5);

$page_title = 'Real-Time Session Tracking';
$page_sub   = 'Live status of the ongoing session';
$active_page = 'tracking';
$show_live_badge = (bool)($currentSession && $currentSession['status'] === 'Ongoing');
include __DIR__ . '/../includes/header.php';
?>

<?php if (!$currentSession): ?>
  <div class="card">
    <div class="empty-state">
      <i class="fa-solid fa-tower-broadcast"></i>
      No session is currently ongoing.
      <div style="margin-top:14px;"><a href="scheduling.php" class="btn btn-navy btn-sm"><i class="fa-solid fa-play"></i> Start a Session</a></div>
    </div>
  </div>
<?php else: ?>

<div class="tracker-live-box">
  <div class="tracker-live-head">
    <div>
      <div style="font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:var(--gold-400);margin-bottom:4px;">
        <?= $currentSession['status'] === 'Ongoing' ? 'Now In Session' : 'Session — ' . htmlspecialchars($currentSession['status'], ENT_QUOTES) ?>
      </div>
      <div style="font-family:'Fraunces',serif;font-size:20px;"><?= htmlspecialchars($currentSession['title'], ENT_QUOTES) ?></div>
      <div style="font-size:12.5px;color:rgba(255,255,255,0.75);margin-top:3px;"><?= htmlspecialchars($currentSession['venue'], ENT_QUOTES) ?></div>
    </div>
    <?php if ($currentSession['status'] === 'Ongoing' && $currentSession['started_at']): ?>
    <div style="text-align:right;">
      <div style="font-size:11px;color:rgba(255,255,255,0.6);text-transform:uppercase;letter-spacing:.06em;">Elapsed</div>
      <div class="tracker-timer" data-timer-start="<?= (int)$currentSession['started_at'] ?>">00:00:00</div>
    </div>
    <?php endif; ?>
  </div>

  <div class="tracker-live-body">
    <div class="grid grid-3">
      <div class="stat">
        <div class="num" id="ssms-live-quorum"><?= $presentCount ?> / <?= count($members) ?></div>
        <div class="lbl">Present (need <?= $quorumNeeded ?>)</div>
      </div>
      <div class="stat">
        <div class="num"><?= $quorumMet ? 'Met' : 'Not Met' ?></div>
        <div class="lbl">Quorum Status</div>
      </div>
      <div class="stat">
        <div class="num"><?= count(array_filter($agenda, fn($a) => $a['status'] !== 'Pending')) ?> / <?= count($agenda) ?></div>
        <div class="lbl">Agenda Progress</div>
      </div>
    </div>
  </div>
</div>

<div class="grid grid-2" style="margin-top:18px;">
  <div class="card" style="margin-bottom:0;">
    <div class="card-head"><div><h3>Agenda &mdash; mark what's being discussed</h3><p>Click "Set as Current" to update the live view.</p></div></div>
    <div class="agenda-list">
      <?php foreach ($agenda as $item): $isCurrent = $currentSession['current_agenda_id'] == $item['id']; ?>
        <div class="agenda-item <?= $isCurrent ? 'current' : '' ?>">
          <div class="num"><?= $item['order'] ?></div>
          <div class="body">
            <p class="title"><?= htmlspecialchars($item['item'], ENT_QUOTES) ?></p>
            <p class="meta"><?= htmlspecialchars($item['status'], ENT_QUOTES) ?></p>
          </div>
          <?php if (!$isCurrent && ssms_is_staff()): ?>
          <form method="POST"><?php ssms_csrf_field(); ?><input type="hidden" name="action" value="set_current"><input type="hidden" name="session_id" value="<?= $currentSession['id'] ?>"><input type="hidden" name="agenda_id" value="<?= $item['id'] ?>"><button class="btn btn-ghost btn-sm">Set as Current</button></form>
          <?php elseif ($isCurrent): ?>
            <span class="badge badge-gold">Live Now</span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if (!$agenda): ?><div class="empty-state"><i class="fa-solid fa-list"></i>No agenda items yet.</div><?php endif; ?>
    </div>
  </div>

  <div class="card" style="margin-bottom:0;">
    <div class="card-head"><div><h3>Latest Proceedings</h3><p>Most recent entries logged.</p></div></div>
    <?php if ($logs): ?>
    <div class="log-feed">
      <?php foreach ($logs as $l): ?>
      <div class="log-entry">
        <div class="dot"></div>
        <div class="body">
          <div class="meta"><?= htmlspecialchars($l['timestamp'], ENT_QUOTES) ?></div>
          <div class="txt"><?= nl2br(htmlspecialchars($l['note'], ENT_QUOTES)) ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
      <div class="empty-state"><i class="fa-solid fa-file-lines"></i>Nothing logged yet.</div>
    <?php endif; ?>
    <a href="proceedings.php?session_id=<?= $currentSession['id'] ?>" class="btn btn-ghost btn-sm" style="margin-top:12px;"><i class="fa-solid fa-pen"></i> Add Log Entry</a>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  ssmsPollLive(<?= (int)$currentSession['id'] ?>, '.tracker-live-box', 6000);
});
</script>

<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
