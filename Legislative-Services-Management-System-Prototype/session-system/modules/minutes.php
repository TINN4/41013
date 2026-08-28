<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/store.php';
ssms_require_staff(); // secretary/admin only — council members get redirected to Attendance

$sessions = array_values(array_filter(ssms_read('sessions'), fn($s) => empty($s['archived_at']))); // archived sessions live in the Archive page instead
usort($sessions, fn($a, $b) => strcmp($b['date'], $a['date']));

$selectedId = isset($_GET['session_id']) ? (int)$_GET['session_id'] : ($_POST['session_id'] ?? null);
if (!$selectedId && $sessions) {
    $selectedId = $sessions[0]['id'];
}
$selectedId = (int)$selectedId;
$currentSession = ssms_find('sessions', $selectedId);

$generated = null;
if (isset($_POST['action']) && $_POST['action'] === 'generate' && $currentSession) {
    $members = ssms_read('members');
    $attendance = ssms_where('attendance', 'session_id', $selectedId);
    $agenda = ssms_where('agenda', 'session_id', $selectedId);
    usort($agenda, fn($a, $b) => $a['order'] <=> $b['order']);
    $logs = ssms_where('proceedings', 'session_id', $selectedId);
    usort($logs, fn($a, $b) => strcmp($a['timestamp'], $b['timestamp']));

    $attByMember = [];
    foreach ($attendance as $a) { $attByMember[$a['member_id']] = $a; }
    $presentNames = []; $absentNames = []; $excusedNames = [];
    foreach ($members as $m) {
        $st = $attByMember[$m['id']]['status'] ?? 'Absent';
        if ($st === 'Present') $presentNames[] = $m['name'];
        elseif ($st === 'Excused') $excusedNames[] = $m['name'];
        else $absentNames[] = $m['name'];
    }

    $id = ssms_insert('minutes', [
        'session_id' => $selectedId,
        'generated_at' => date('Y-m-d H:i:s'),
        'present' => $presentNames,
        'absent' => $absentNames,
        'excused' => $excusedNames,
        'agenda' => $agenda,
        'log' => $logs,
    ]);
    $generated = ssms_find('minutes', $id);
}

// Show most recent generated minutes for this session if any
$allMinutes = ssms_where('minutes', 'session_id', $selectedId);
usort($allMinutes, fn($a, $b) => strcmp($b['generated_at'], $a['generated_at']));
$latest = $generated ?? ($allMinutes[0] ?? null);

$page_title = 'Minutes Generation';
$page_sub   = 'Auto-compile agenda, attendance & proceedings into official minutes';
$active_page = 'minutes';
include __DIR__ . '/../includes/header.php';
?>

<div class="card no-print">
  <div class="select-session-bar">
    <div class="form-group">
      <label>Session</label>
      <form method="GET" id="sessSwitch">
        <select name="session_id" onchange="document.getElementById('sessSwitch').submit()">
          <?php foreach ($sessions as $s): ?>
            <option value="<?= $s['id'] ?>" <?= $s['id'] == $selectedId ? 'selected' : '' ?>>
              <?= htmlspecialchars($s['title'], ENT_QUOTES) ?> &mdash; <?= htmlspecialchars($s['date'], ENT_QUOTES) ?> (<?= htmlspecialchars($s['status'], ENT_QUOTES) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
    <?php if ($currentSession): ?>
    <form method="POST"><?php ssms_csrf_field(); ?>
      <input type="hidden" name="action" value="generate">
      <input type="hidden" name="session_id" value="<?= $selectedId ?>">
      <button type="submit" class="btn btn-navy"><i class="fa-solid fa-wand-magic-sparkles"></i> Generate Minutes</button>
    </form>
    <?php if ($latest): ?>
      <button onclick="window.print()" class="btn btn-ghost"><i class="fa-solid fa-print"></i> Print / Save PDF</button>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php if (!$currentSession): ?>
  <div class="empty-state"><i class="fa-solid fa-calendar-xmark"></i>No sessions available. Create one in Session Scheduling first.</div>
<?php elseif (!$latest): ?>
  <div class="card"><div class="empty-state"><i class="fa-solid fa-file-signature"></i>No minutes generated yet for this session. Click "Generate Minutes" above — it will pull together the agenda, attendance, and proceedings log automatically.</div></div>
<?php else: ?>

<div class="minutes-doc">
  <h2>Minutes of the Session</h2>
  <p class="center" style="margin:0;color:var(--ink-600);">Sangguniang Panlungsod ng San Jose del Monte</p>
  <hr>
  <table>
    <tr><td style="width:160px;"><strong>Session</strong></td><td><?= htmlspecialchars($currentSession['title'], ENT_QUOTES) ?> (<?= htmlspecialchars($currentSession['type'], ENT_QUOTES) ?>)</td></tr>
    <tr><td><strong>Date / Time</strong></td><td><?= htmlspecialchars($currentSession['date'], ENT_QUOTES) ?> &middot; <?= htmlspecialchars($currentSession['time'], ENT_QUOTES) ?></td></tr>
    <tr><td><strong>Venue</strong></td><td><?= htmlspecialchars($currentSession['venue'], ENT_QUOTES) ?></td></tr>
    <tr><td><strong>Minutes Generated</strong></td><td><?= htmlspecialchars($latest['generated_at'], ENT_QUOTES) ?></td></tr>
  </table>

  <h3 style="font-family:'Fraunces',serif;color:var(--navy-950);">I. Attendance</h3>
  <table>
    <tr><th style="width:33%;">Present (<?= count($latest['present']) ?>)</th><th style="width:33%;">Excused (<?= count($latest['excused']) ?>)</th><th style="width:34%;">Absent (<?= count($latest['absent']) ?>)</th></tr>
    <tr>
      <td><?= $latest['present'] ? implode('<br>', array_map(fn($n) => htmlspecialchars($n, ENT_QUOTES), $latest['present'])) : '&mdash;' ?></td>
      <td><?= $latest['excused'] ? implode('<br>', array_map(fn($n) => htmlspecialchars($n, ENT_QUOTES), $latest['excused'])) : '&mdash;' ?></td>
      <td><?= $latest['absent'] ? implode('<br>', array_map(fn($n) => htmlspecialchars($n, ENT_QUOTES), $latest['absent'])) : '&mdash;' ?></td>
    </tr>
  </table>

  <h3 style="font-family:'Fraunces',serif;color:var(--navy-950);">II. Agenda &amp; Disposition</h3>
  <table>
    <tr><th style="width:8%;">No.</th><th>Agenda Item</th><th style="width:22%;">Presenter</th><th style="width:15%;">Disposition</th></tr>
    <?php foreach ($latest['agenda'] as $it): ?>
    <tr>
      <td><?= (int)$it['order'] ?></td>
      <td><?= htmlspecialchars($it['item'], ENT_QUOTES) ?></td>
      <td><?= htmlspecialchars($it['presenter'] ?: '—', ENT_QUOTES) ?></td>
      <td><?= htmlspecialchars($it['status'], ENT_QUOTES) ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$latest['agenda']): ?><tr><td colspan="4">No agenda items recorded.</td></tr><?php endif; ?>
  </table>

  <h3 style="font-family:'Fraunces',serif;color:var(--navy-950);">III. Proceedings</h3>
  <?php if ($latest['log']): ?>
    <?php foreach ($latest['log'] as $l): ?>
      <p style="margin:0 0 10px;"><strong><?= htmlspecialchars($l['timestamp'], ENT_QUOTES) ?></strong> &mdash; <?= nl2br(htmlspecialchars($l['note'], ENT_QUOTES)) ?></p>
    <?php endforeach; ?>
  <?php else: ?>
    <p>No proceedings were logged for this session.</p>
  <?php endif; ?>

  <hr>
  <p class="center" style="color:var(--ink-600);font-size:12px;">Prepared by the Secretary's Office &middot; Generated automatically by the Session and Legislative Meeting Management System</p>
</div>

<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
