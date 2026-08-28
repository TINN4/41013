<?php
// Public dashboard — intentionally does NOT include auth.php.
// Anyone with this link can view it, no login and no QR device needed.
// Read-only: no forms, no edit buttons, no admin sidebar/nav.
require_once __DIR__ . '/includes/store.php';
require_once __DIR__ . '/includes/public_data.php';

$session = ssms_public_pick_featured_session();
$agenda = $session ? ssms_where('agenda', 'session_id', $session['id']) : [];
usort($agenda, fn($a, $b) => $a['order'] <=> $b['order']);

$members = ssms_read('members');
$attendance = $session ? ssms_where('attendance', 'session_id', $session['id']) : [];
$present = count(array_filter($attendance, fn($a) => $a['status'] === 'Present'));
$quorumNeeded = intdiv(count($members), 2) + 1;

$upcoming = ssms_public_upcoming_sessions($session['id'] ?? null, 5);

function ssms_public_status_badge($status) {
    $map = [
        'Ongoing'   => 'badge-ok',
        'Scheduled' => 'badge-blue',
        'Completed' => 'badge-grey',
        'Approved'  => 'badge-ok',
        'Discussed' => 'badge-warn',
        'Pending'   => 'badge-grey',
    ];
    $cls = $map[$status] ?? 'badge-grey';
    return '<span class="badge ' . $cls . '">' . ssms_e($status) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Public Session Dashboard &mdash; SP San Jose del Monte</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="assets/style.css">
<style>
  body { background: var(--paper-alt); }
  .pub-shell { max-width: 880px; margin: 0 auto; padding: 28px 18px 60px; }
  .pub-header { display:flex; align-items:center; gap:14px; margin-bottom: 22px; }
  .pub-header .seal { width:46px; height:46px; border-radius:12px; background: linear-gradient(135deg, var(--blue-700), var(--navy-950)); color:#fff; display:flex; align-items:center; justify-content:center; font-size:20px; flex:none; }
  .pub-header h1 { font-family:'Fraunces', serif; font-size:19px; margin:0; color: var(--navy-950); }
  .pub-header p { margin:2px 0 0; font-size:12.5px; color: var(--ink-600); }
  .agenda-row { display:flex; align-items:center; gap:12px; padding:12px 4px; border-bottom:1px solid var(--line); }
  .agenda-row:last-child { border-bottom:none; }
  .agenda-row .ord { width:26px; height:26px; border-radius:50%; background:var(--paper-alt); color:var(--ink-600); font-size:12px; font-weight:700; display:flex; align-items:center; justify-content:center; flex:none; }
  .agenda-row.current .ord { background: var(--gold-500); color: var(--navy-950); }
  .agenda-row .item { flex:1; }
  .agenda-row .item .t { font-size:13.5px; color:var(--ink-900); font-weight:600; }
  .agenda-row .item .p { font-size:12px; color:var(--ink-600); margin-top:2px; }
  .agenda-row.current .item .t { color: var(--navy-950); }
  .now-flag { font-size:10.5px; font-weight:700; letter-spacing:.04em; color:#8A6A0F; text-transform:uppercase; margin-left:6px; }
  .quorum-bar { display:flex; align-items:center; gap:10px; margin-top:8px; }
  .quorum-track { flex:1; height:8px; border-radius:999px; background: var(--paper-alt); overflow:hidden; }
  .quorum-fill { height:100%; background: linear-gradient(135deg, var(--blue-600), var(--blue-500)); }
  .upcoming-item { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:11px 4px; border-bottom:1px solid var(--line); }
  .upcoming-item:last-child { border-bottom:none; }
  .upcoming-item .t { font-size:13.5px; font-weight:600; color:var(--ink-900); }
  .upcoming-item .d { font-size:12px; color:var(--ink-600); margin-top:2px; }
  .refresh-note { text-align:center; font-size:11.5px; color:var(--ink-600); margin-top:10px; }
</style>
</head>
<body>
<div class="pub-shell">

  <div class="pub-header">
    <div class="seal"><i class="fa-solid fa-landmark-dome"></i></div>
    <div>
      <h1>SP San Jose del Monte — Public Session Dashboard</h1>
      <p>Live agenda and session status. No account needed.</p>
    </div>
  </div>

  <?php if (!$session): ?>
    <div class="card"><div class="empty-state"><i class="fa-solid fa-calendar-xmark"></i>No sessions have been scheduled yet.</div></div>
  <?php else: ?>

    <div class="card" id="pub-session-card">
      <div class="card-head">
        <div>
          <h3><?= ssms_e($session['title']) ?> <span id="pub-status-badge"><?= ssms_public_status_badge($session['status']) ?></span></h3>
          <p><i class="fa-regular fa-calendar"></i> <?= ssms_e(date('F j, Y', strtotime($session['date']))) ?> &middot; <?= ssms_e(date('g:i A', strtotime($session['time']))) ?> &middot; <?= ssms_e($session['venue']) ?></p>
        </div>
        <?php if ($session['status'] === 'Ongoing'): ?>
          <span class="badge-live" style="display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border-radius:999px;background:var(--ok-100);color:var(--ok-600);font-size:11.5px;font-weight:700;"><span class="pulse-dot"></span> Live now</span>
        <?php endif; ?>
      </div>

      <div style="margin-bottom:14px;">
        <div style="font-size:12px;color:var(--ink-600);display:flex;justify-content:space-between;">
          <span>Quorum</span>
          <span id="pub-quorum-text"><?= $present ?> of <?= count($members) ?> present &middot; <?= $quorumNeeded ?> needed</span>
        </div>
        <div class="quorum-bar"><div class="quorum-track"><div class="quorum-fill" id="pub-quorum-fill" style="width:<?= count($members) ? round($present / count($members) * 100) : 0 ?>%"></div></div></div>
      </div>

      <div id="pub-agenda-list">
        <?php foreach ($agenda as $a): $isCurrent = (int)($session['current_agenda_id'] ?? 0) === (int)$a['id']; ?>
          <div class="agenda-row <?= $isCurrent ? 'current' : '' ?>">
            <div class="ord"><?= $a['order'] ?></div>
            <div class="item">
              <div class="t"><?= ssms_e($a['item']) ?><?= $isCurrent ? '<span class="now-flag">Now discussing</span>' : '' ?></div>
              <div class="p"><?= ssms_e($a['presenter']) ?></div>
            </div>
            <?= ssms_public_status_badge($a['status']) ?>
          </div>
        <?php endforeach; ?>
        <?php if (!$agenda): ?><div class="empty-state" style="padding:16px;"><i class="fa-solid fa-list-check"></i>Agenda not yet published for this session.</div><?php endif; ?>
      </div>
      <div class="refresh-note" id="pub-refresh-note">Updates automatically every 15 seconds &middot; server time <span id="pub-server-time"><?= date('g:i:s A') ?></span></div>
    </div>

  <?php endif; ?>

  <?php if ($upcoming): ?>
    <div class="card">
      <div class="card-head"><div><h3>Upcoming Sessions</h3><p>Scheduled and not yet started</p></div></div>
      <?php foreach ($upcoming as $u): ?>
        <div class="upcoming-item">
          <div>
            <div class="t"><?= ssms_e($u['title']) ?></div>
            <div class="d"><?= ssms_e($u['type']) ?> &middot; <?= ssms_e($u['venue']) ?></div>
          </div>
          <div class="d" style="text-align:right;">
            <?= ssms_e(date('M j, Y', strtotime($u['date']))) ?><br><?= ssms_e(date('g:i A', strtotime($u['time']))) ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div>

<?php if ($session): ?>
<script>
// Poll the public, no-login status endpoint so the badge / current agenda
// item / quorum bar stay live without a full page reload or any login.
const SSMS_PUBLIC_SESSION_ID = <?= (int)$session['id'] ?>;

function ssmsPublicRefresh() {
  fetch('api/public_status.php?session_id=' + SSMS_PUBLIC_SESSION_ID)
    .then(r => r.json())
    .then(data => {
      if (!data.found) return;
      document.getElementById('pub-server-time').textContent = data.server_time;

      const q = data.quorum;
      document.getElementById('pub-quorum-text').textContent =
        q.present + ' of ' + q.total + ' present · ' + q.needed + ' needed';
      document.getElementById('pub-quorum-fill').style.width =
        (q.total ? Math.round(q.present / q.total * 100) : 0) + '%';

      // Re-render the agenda list so the "Now discussing" flag and any
      // status changes made by the secretary/admin show up here live.
      const list = document.getElementById('pub-agenda-list');
      list.innerHTML = data.agenda.map(a => `
        <div class="agenda-row ${a.is_current ? 'current' : ''}">
          <div class="ord">${a.order}</div>
          <div class="item">
            <div class="t">${ssmsPublicEscape(a.item)}${a.is_current ? '<span class="now-flag">Now discussing</span>' : ''}</div>
            <div class="p">${ssmsPublicEscape(a.presenter)}</div>
          </div>
          ${ssmsPublicBadge(a.status)}
        </div>`).join('') || '<div class="empty-state" style="padding:16px;"><i class="fa-solid fa-list-check"></i>Agenda not yet published for this session.</div>';
    })
    .catch(() => { /* silent — keep showing last known state */ });
}

function ssmsPublicEscape(s) {
  const d = document.createElement('div');
  d.textContent = s ?? '';
  return d.innerHTML;
}

function ssmsPublicBadge(status) {
  const map = { Ongoing:'badge-ok', Scheduled:'badge-blue', Completed:'badge-grey', Approved:'badge-ok', Discussed:'badge-warn', Pending:'badge-grey' };
  const cls = map[status] || 'badge-grey';
  return `<span class="badge ${cls}">${ssmsPublicEscape(status)}</span>`;
}

setInterval(ssmsPublicRefresh, 15000);
</script>
<?php endif; ?>
</body>
</html>
