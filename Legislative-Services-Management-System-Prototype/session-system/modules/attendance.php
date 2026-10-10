<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/store.php';

$sessions = array_values(array_filter(ssms_read('sessions'), fn($s) => empty($s['archived_at']))); // archived sessions live in the Archive page instead
usort($sessions, fn($a, $b) => strcmp($b['date'], $a['date']));

$selectedId = isset($_GET['session_id']) ? (int)$_GET['session_id'] : ($_POST['session_id'] ?? null);
if (!$selectedId && $sessions) {
    $ongoing = array_values(array_filter($sessions, fn($s) => $s['status'] === 'Ongoing'));
    $selectedId = $ongoing ? $ongoing[0]['id'] : $sessions[0]['id'];
}
$selectedId = (int)$selectedId;
$selectedSession = ssms_find('sessions', $selectedId);
// Same principle as Minutes Generation: a session existing in the
// database isn't the same as it having actually happened. Marking who
// attended a meeting that's still "Scheduled" (hasn't started) or was
// "Cancelled" (never happened) doesn't make sense as a record.
$canMarkAttendance = $selectedSession && in_array($selectedSession['status'], ['Ongoing', 'Completed'], true);

if (isset($_POST['action']) && $_POST['action'] === 'mark' && ssms_is_staff() && $canMarkAttendance) {
    // Staff-only: this page is also the landing page council members see
    // (they can view live quorum status here), but manual marking must
    // stay a secretary decision — see api/qr_login.php's auto-attendance,
    // which only ever marks a member Present, never Absent/Excused, for
    // exactly this reason. Without this check, a signed-in council
    // member could edit the hidden member_id field in this same form
    // (or just POST directly) and mark ANY member's attendance, or mark
    // themselves present without ever being near the geofenced venue.
    $memberId = (int)$_POST['member_id'];
    $status = $_POST['status'];
    $allowedStatuses = ['Present', 'Absent', 'Excused'];
    if (in_array($status, $allowedStatuses, true)) {
        $existing = array_values(array_filter(ssms_where('attendance', 'session_id', $selectedId), fn($a) => $a['member_id'] == $memberId));
        $changes = ['status' => $status, 'time_in' => $status === 'Present' ? date('H:i') : null, 'marked_by' => $_SESSION['ssms_name'] ?? 'Unknown'];
        if ($existing) {
            ssms_update('attendance', $existing[0]['id'], $changes);
        } else {
            ssms_insert('attendance', array_merge(['session_id' => $selectedId, 'member_id' => $memberId], $changes));
        }
    }
}
$isStaffUser = ssms_is_staff();

$members = ssms_read('members');
$attendance = ssms_where('attendance', 'session_id', $selectedId);
$attByMember = [];
foreach ($attendance as $a) { $attByMember[$a['member_id']] = $a; }

$presentCount = count(array_filter($attendance, fn($a) => $a['status'] === 'Present'));
$totalMembers = count($members);
$quorumNeeded = intdiv($totalMembers, 2) + 1;
$quorumMet = $presentCount >= $quorumNeeded;
$currentSession = ssms_find('sessions', $selectedId);

$page_title = 'Attendance & Quorum Monitoring';
$page_sub   = 'Mark attendance and track quorum in real time';
$active_page = 'attendance';
include __DIR__ . '/../includes/header.php';
?>

<div class="card">
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
  </div>
</div>

<?php if (!$currentSession): ?>
  <div class="empty-state"><i class="fa-solid fa-calendar-xmark"></i>No sessions available. Create one in Session Scheduling first.</div>
<?php else: ?>

<div class="quorum-banner <?= $quorumMet ? 'met' : 'notmet' ?>">
  <div class="qnum"><?= $presentCount ?> / <?= $totalMembers ?></div>
  <div class="qlabel">
    <p style="margin:0;font-weight:700;"><i class="fa-solid <?= $quorumMet ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>"></i> Quorum <?= $quorumMet ? 'Met' : 'Not Met' ?></p>
    <p style="margin:0;font-size:12.5px;color:var(--ink-600);">Requires <?= $quorumNeeded ?> of <?= $totalMembers ?> members present (majority)</p>
  </div>
</div>

<div class="card">
  <div class="card-head">
    <div><h3>Roll Call &mdash; <?= htmlspecialchars($currentSession['title'], ENT_QUOTES) ?></h3><p>Tap a status to mark each member's attendance.</p></div>
  </div>
  <div class="attend-list">
    <?php foreach ($members as $m):
      $status = $attByMember[$m['id']]['status'] ?? null;
      $timeIn = $attByMember[$m['id']]['time_in'] ?? null;
    ?>
    <div class="attend-row">
      <div class="who">
        <p><?= htmlspecialchars($m['name'], ENT_QUOTES) ?></p>
        <p><?= htmlspecialchars($m['position'], ENT_QUOTES) ?><?= $timeIn ? ' &middot; In at ' . htmlspecialchars($timeIn, ENT_QUOTES) : '' ?></p>
      </div>
      <div class="seg">
        <?php if ($isStaffUser && $canMarkAttendance): ?>
        <form method="POST" style="display:inline;"><?php ssms_csrf_field(); ?><input type="hidden" name="action" value="mark"><input type="hidden" name="session_id" value="<?= $selectedId ?>"><input type="hidden" name="member_id" value="<?= $m['id'] ?>"><input type="hidden" name="status" value="Present"><button class="<?= $status==='Present'?'on present':'' ?>">Present</button></form>
        <form method="POST" style="display:inline;"><?php ssms_csrf_field(); ?><input type="hidden" name="action" value="mark"><input type="hidden" name="session_id" value="<?= $selectedId ?>"><input type="hidden" name="member_id" value="<?= $m['id'] ?>"><input type="hidden" name="status" value="Excused"><button class="<?= $status==='Excused'?'on excused':'' ?>">Excused</button></form>
        <form method="POST" style="display:inline;"><?php ssms_csrf_field(); ?><input type="hidden" name="action" value="mark"><input type="hidden" name="session_id" value="<?= $selectedId ?>"><input type="hidden" name="member_id" value="<?= $m['id'] ?>"><input type="hidden" name="status" value="Absent"><button class="<?= $status==='Absent'?'on absent':'' ?>">Absent</button></form>
        <?php else: ?>
          <!-- Either a council member viewing (can't mark attendance at
               all — see ssms_is_staff() above), or this session hasn't
               started yet / already ended in a way that blocks marking
               (see $canMarkAttendance above) — either way, read-only. -->
          <span class="badge <?= ['Present'=>'badge-ok','Excused'=>'badge-warn','Absent'=>'badge-bad'][$status] ?? 'badge-grey' ?>"><?= htmlspecialchars($status ?? 'Not marked', ENT_QUOTES) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php if ($isStaffUser && !$canMarkAttendance && $selectedSession): ?>
    <p class="no-print" style="font-size:12.5px;color:var(--ink-600);padding:10px 4px 0;"><i class="fa-solid fa-circle-info"></i> Attendance can only be marked once this session has started (it's currently "<?= htmlspecialchars($selectedSession['status'], ENT_QUOTES) ?>"). Start it from Session Scheduling first.</p>
  <?php endif; ?>
</div>

<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
