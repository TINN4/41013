<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/store.php';
ssms_require_staff(); // secretary/admin only — council members get redirected to Attendance

// --- Export: bundle one session's full record into a downloadable JSON
// file. This is the "safe file" backup — it leaves the database and
// becomes a real file the secretary can save wherever they keep records
// (their own drive, a USB, email to themselves), so it survives even if
// the database itself is ever lost or corrupted.
if (isset($_GET['export']) && ctype_digit($_GET['export'])) {
    $sid = (int)$_GET['export'];
    $session = ssms_find('sessions', $sid);
    if ($session) {
        $members = ssms_read('members');
        $attendance = ssms_where('attendance', 'session_id', $sid);
        $agenda = ssms_where('agenda', 'session_id', $sid);
        usort($agenda, fn($a, $b) => $a['order'] <=> $b['order']);
        $proceedings = ssms_where('proceedings', 'session_id', $sid);
        usort($proceedings, fn($a, $b) => strcmp($a['timestamp'], $b['timestamp']));
        $minutesVersions = ssms_where('minutes', 'session_id', $sid);
        usort($minutesVersions, fn($a, $b) => strcmp($a['generated_at'], $b['generated_at']));

        $bundle = [
            'exported_at' => date('Y-m-d H:i:s'),
            'session' => $session,
            'members_roster_at_export' => $members,
            'attendance' => $attendance,
            'agenda' => $agenda,
            'proceedings' => $proceedings,
            'minutes_versions' => $minutesVersions,
        ];

        $filename = 'session-' . $sid . '-' . preg_replace('/[^a-z0-9]+/i', '-', $session['title']) . '.json';
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit();
    }
}

// --- Full system backup (all sessions, all tables) — for disaster
// recovery, not per-session record-keeping. Same idea as the per-session
// export above, just system-wide. Also written automatically if
// cron_backup.php is set up on a schedule (see that file for setup).
if (isset($_GET['export_all'])) {
    require_once __DIR__ . '/../includes/backup.php';
    $bundle = ssms_full_backup_array();
    $filename = 'ssms-full-backup-' . date('Y-m-d_His') . '.json';
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit();
}

// --- Restore: bring a session back out of the archive into active lists.
if (isset($_POST['action']) && $_POST['action'] === 'restore' && isset($_POST['id'])) {
    ssms_update('sessions', (int)$_POST['id'], ['archived_at' => null]);
    header('Location: archive.php');
    exit();
}

$sessions = ssms_read('sessions');
$archived = array_values(array_filter($sessions, fn($s) => !empty($s['archived_at'])));
usort($archived, fn($a, $b) => strcmp($b['archived_at'], $a['archived_at']));

$selectedId = isset($_GET['session_id']) ? (int)$_GET['session_id'] : ($archived[0]['id'] ?? null);
$currentSession = $selectedId ? ssms_find('sessions', $selectedId) : null;

// --- Filtering: the whole point of "keep everything forever" only works
// if the secretary can still actually find something years from now.
// Search by title/type and narrow by year — no records are hidden
// permanently, this just narrows what's shown in the picker below.
$q = trim($_GET['q'] ?? '');
$year = trim($_GET['year'] ?? '');

$years = array_values(array_unique(array_map(fn($s) => substr($s['date'], 0, 4), $archived)));
rsort($years);

$filtered = $archived;
if ($q !== '') {
    $needle = mb_strtolower($q);
    $filtered = array_values(array_filter($filtered, fn($s) =>
        str_contains(mb_strtolower($s['title']), $needle) || str_contains(mb_strtolower($s['type']), $needle)
    ));
}
if ($year !== '') {
    $filtered = array_values(array_filter($filtered, fn($s) => substr($s['date'], 0, 4) === $year));
}
// If filtering knocked the currently-selected session out of view, fall
// back to the first result still visible so the picker never shows a
// selection that isn't actually in its own option list.
if ($selectedId && !in_array($selectedId, array_column($filtered, 'id'))) {
    $selectedId = $filtered[0]['id'] ?? null;
    $currentSession = $selectedId ? ssms_find('sessions', $selectedId) : null;
}

$members = [];
$attendance = [];
$agenda = [];
$proceedings = [];
$minutesVersions = [];
if ($currentSession) {
    $members = ssms_read('members');
    $attendance = ssms_where('attendance', 'session_id', $selectedId);
    $agenda = ssms_where('agenda', 'session_id', $selectedId);
    usort($agenda, fn($a, $b) => $a['order'] <=> $b['order']);
    $proceedings = ssms_where('proceedings', 'session_id', $selectedId);
    usort($proceedings, fn($a, $b) => strcmp($a['timestamp'], $b['timestamp']));
    $minutesVersions = ssms_where('minutes', 'session_id', $selectedId);
    usort($minutesVersions, fn($a, $b) => strcmp($b['generated_at'], $a['generated_at']));
}
$attByMember = [];
foreach ($attendance as $a) { $attByMember[$a['member_id']] = $a; }

$page_title = 'Session Archive';
$page_sub   = 'Look back at completed and cancelled sessions — read-only record';
$active_page = 'archive';
include __DIR__ . '/../includes/header.php';
?>

<div class="card no-print">
  <div class="card-head">
    <div><h3>Full System Backup</h3><p>Every session, all tables, one file — for disaster recovery, separate from any single session</p></div>
    <a class="btn btn-navy" href="?export_all=1<?= $selectedId ? '&session_id=' . $selectedId : '' ?>"><i class="fa-solid fa-shield-halved"></i> Download Full Backup (.json)</a>
  </div>
  <p style="padding:0 16px 16px;margin:0;font-size:12.5px;color:var(--ink-600);">
    This can also run automatically on a schedule — see <code>cron_backup.php</code> for setup with your hosting's cron jobs.
  </p>
</div>

<div class="card no-print">
  <div class="select-session-bar" style="flex-wrap:wrap;">
    <div class="form-group">
      <label>Search</label>
      <form method="GET" id="archFilter" style="display:flex;gap:8px;flex-wrap:wrap;">
        <?php if ($selectedId): ?><input type="hidden" name="session_id" value="<?= $selectedId ?>"><?php endif; ?>
        <input type="text" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES) ?>" placeholder="Search by title or type&hellip;" style="min-width:200px;">
        <select name="year" onchange="document.getElementById('archFilter').submit()">
          <option value="">All years</option>
          <?php foreach ($years as $y): ?>
            <option value="<?= htmlspecialchars($y, ENT_QUOTES) ?>" <?= $y === $year ? 'selected' : '' ?>><?= htmlspecialchars($y, ENT_QUOTES) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-ghost btn-sm"><i class="fa-solid fa-magnifying-glass"></i></button>
        <?php if ($q !== '' || $year !== ''): ?><a href="archive.php" class="btn btn-ghost btn-sm">Clear</a><?php endif; ?>
      </form>
    </div>
    <div class="form-group">
      <label>Archived Session (<?= count($filtered) ?> of <?= count($archived) ?>)</label>
      <form method="GET" id="archSwitch">
        <input type="hidden" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES) ?>">
        <input type="hidden" name="year" value="<?= htmlspecialchars($year, ENT_QUOTES) ?>">
        <select name="session_id" onchange="document.getElementById('archSwitch').submit()">
          <?php if (!$filtered): ?><option value="">No matching sessions</option><?php endif; ?>
          <?php foreach ($filtered as $s): ?>
            <option value="<?= $s['id'] ?>" <?= $s['id'] == $selectedId ? 'selected' : '' ?>>
              <?= htmlspecialchars($s['title'], ENT_QUOTES) ?> &mdash; <?= htmlspecialchars($s['date'], ENT_QUOTES) ?> (<?= htmlspecialchars($s['status'], ENT_QUOTES) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
    <?php if ($currentSession): ?>
      <a class="btn btn-ghost" href="?export=<?= $selectedId ?>"><i class="fa-solid fa-download"></i> Download Backup File (.json)</a>
      <button onclick="window.print()" class="btn btn-ghost"><i class="fa-solid fa-print"></i> Print</button>
      <form method="POST" onsubmit="return confirm('Restore this session? It will move back into Session Scheduling as an active record.');"><?php ssms_csrf_field(); ?>
        <input type="hidden" name="action" value="restore">
        <input type="hidden" name="id" value="<?= $selectedId ?>">
        <button type="submit" class="btn btn-ghost"><i class="fa-solid fa-rotate-left"></i> Restore</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if (!$currentSession): ?>
  <div class="empty-state">
    <i class="fa-solid fa-box-archive"></i>
    <?php if ($archived && ($q !== '' || $year !== '')): ?>
      No archived sessions match that search. <a href="archive.php">Clear filters</a> to see everything.
    <?php else: ?>
      Nothing archived yet. From <strong>Session Scheduling</strong>, click "Archive" on a Completed or Cancelled session to move its full record here permanently — nothing is ever deleted, and it can be restored anytime.
    <?php endif; ?>
  </div>
<?php else: ?>

<div class="minutes-doc">
  <h2>Archived Record</h2>
  <p class="center" style="margin:0;color:var(--ink-600);">Sangguniang Panlungsod ng San Jose del Monte</p>
  <hr>
  <table>
    <tr><td style="width:160px;"><strong>Session</strong></td><td><?= htmlspecialchars($currentSession['title'], ENT_QUOTES) ?> (<?= htmlspecialchars($currentSession['type'], ENT_QUOTES) ?>)</td></tr>
    <tr><td><strong>Date / Time</strong></td><td><?= htmlspecialchars($currentSession['date'], ENT_QUOTES) ?> &middot; <?= htmlspecialchars($currentSession['time'], ENT_QUOTES) ?></td></tr>
    <tr><td><strong>Venue</strong></td><td><?= htmlspecialchars($currentSession['venue'], ENT_QUOTES) ?></td></tr>
    <tr><td><strong>Final Status</strong></td><td><span class="badge <?= $currentSession['status'] === 'Completed' ? 'badge-ok' : 'badge-bad' ?>"><?= htmlspecialchars($currentSession['status'], ENT_QUOTES) ?></span></td></tr>
  </table>

  <h3 style="font-family:'Fraunces',serif;color:var(--navy-950);">I. Attendance</h3>
  <table>
    <tr><th style="width:33%;">Name</th><th style="width:33%;">Status</th><th style="width:34%;">Time In</th></tr>
    <?php foreach ($members as $m): $st = $attByMember[$m['id']]['status'] ?? 'Absent'; $ti = $attByMember[$m['id']]['time_in'] ?? '—'; ?>
      <tr><td><?= htmlspecialchars($m['name'], ENT_QUOTES) ?></td><td><?= htmlspecialchars($st, ENT_QUOTES) ?></td><td><?= htmlspecialchars($ti, ENT_QUOTES) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$members): ?><tr><td colspan="3">No council members on record.</td></tr><?php endif; ?>
  </table>

  <h3 style="font-family:'Fraunces',serif;color:var(--navy-950);">II. Agenda &amp; Disposition</h3>
  <table>
    <tr><th style="width:8%;">No.</th><th>Agenda Item</th><th style="width:22%;">Presenter</th><th style="width:15%;">Disposition</th></tr>
    <?php foreach ($agenda as $it): ?>
      <tr><td><?= (int)$it['order'] ?></td><td><?= htmlspecialchars($it['item'], ENT_QUOTES) ?></td><td><?= htmlspecialchars($it['presenter'] ?: '—', ENT_QUOTES) ?></td><td><?= htmlspecialchars($it['status'], ENT_QUOTES) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$agenda): ?><tr><td colspan="4">No agenda items recorded.</td></tr><?php endif; ?>
  </table>

  <h3 style="font-family:'Fraunces',serif;color:var(--navy-950);">III. Full Proceedings Log</h3>
  <?php if ($proceedings): ?>
    <?php foreach ($proceedings as $l): ?>
      <p style="margin:0 0 10px;"><strong><?= htmlspecialchars($l['timestamp'], ENT_QUOTES) ?></strong> &mdash; <?= nl2br(htmlspecialchars($l['note'], ENT_QUOTES)) ?></p>
    <?php endforeach; ?>
  <?php else: ?>
    <p>No proceedings were logged for this session.</p>
  <?php endif; ?>

  <h3 style="font-family:'Fraunces',serif;color:var(--navy-950);">IV. Minutes History</h3>
  <?php if ($minutesVersions): ?>
    <p style="font-size:12.5px;color:var(--ink-600);">Every version generated for this session, most recent first. This is the actual revision trail — nothing here has been deleted or overwritten.</p>
    <table>
      <tr><th style="width:30%;">Generated At</th><th>Present / Excused / Absent</th></tr>
      <?php foreach ($minutesVersions as $i => $mv): ?>
        <tr>
          <td><?= htmlspecialchars($mv['generated_at'], ENT_QUOTES) ?><?= $i === 0 ? ' <span class="badge badge-ok" style="font-size:10px;">Latest</span>' : '' ?></td>
          <td><?= count($mv['present']) ?> / <?= count($mv['excused']) ?> / <?= count($mv['absent']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php else: ?>
    <p>No minutes were ever generated for this session.</p>
  <?php endif; ?>

  <hr>
  <p class="center" style="color:var(--ink-600);font-size:12px;">Read-only archived record &middot; Session and Legislative Meeting Management System</p>
</div>

<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
