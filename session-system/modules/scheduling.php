<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/store.php';

$flash = '';

// Create new session
if (isset($_POST['action']) && $_POST['action'] === 'create') {
    $title = trim($_POST['title'] ?? '');
    $type  = trim($_POST['type'] ?? 'Regular');
    $date  = trim($_POST['date'] ?? '');
    $time  = trim($_POST['time'] ?? '');
    $venue = trim($_POST['venue'] ?? '');

    if ($title && $date && $time && $venue) {
        ssms_insert('sessions', [
            'title' => $title, 'type' => $type, 'date' => $date, 'time' => $time,
            'venue' => $venue, 'status' => 'Scheduled', 'started_at' => null,
            'current_agenda_id' => null, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $flash = 'Session scheduled successfully.';
    }
}

// Status transitions
if (isset($_POST['action']) && $_POST['action'] === 'set_status' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    $newStatus = $_POST['status'];
    $changes = ['status' => $newStatus];
    if ($newStatus === 'Ongoing') $changes['started_at'] = time();
    ssms_update('sessions', $id, $changes);
    $flash = 'Session status updated.';
}

if (isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['id'])) {
    ssms_delete('sessions', (int)$_POST['id']);
    $flash = 'Session removed.';
}

$sessions = ssms_read('sessions');
usort($sessions, fn($a, $b) => strcmp($b['date'] . $b['time'], $a['date'] . $a['time']));

$page_title = 'Session Scheduling';
$page_sub   = 'Create sessions and manage their status through the workflow';
$active_page = 'scheduling';
include __DIR__ . '/../includes/header.php';
?>

<?php if ($flash): ?><div class="alert alert-ok"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($flash, ENT_QUOTES) ?></div><?php endif; ?>

<div class="card">
  <div class="card-head">
    <div><h3>Schedule a New Session</h3><p>Fill in the details to add it to the calendar.</p></div>
  </div>
  <form method="POST">
    <input type="hidden" name="action" value="create">
    <div class="form-row two">
      <div class="form-group"><label>Session Title</label><input type="text" name="title" placeholder="e.g. 60th Regular Session" required></div>
      <div class="form-group"><label>Type</label>
        <select name="type">
          <option>Regular</option>
          <option>Special</option>
          <option>Executive</option>
          <option>Committee Hearing</option>
        </select>
      </div>
    </div>
    <div class="form-row three">
      <div class="form-group"><label>Date</label><input type="date" name="date" required></div>
      <div class="form-group"><label>Time</label><input type="time" name="time" value="13:00" required></div>
      <div class="form-group"><label>Venue</label><input type="text" name="venue" placeholder="Session Hall" value="Sangguniang Panlungsod Session Hall" required></div>
    </div>
    <button type="submit" class="btn btn-navy"><i class="fa-solid fa-calendar-plus"></i> Schedule Session</button>
  </form>
</div>

<div class="card">
  <div class="card-head">
    <div><h3>All Sessions</h3><p>Manage status: start, complete, or cancel.</p></div>
  </div>
  <?php if ($sessions): ?>
  <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Title</th><th>Type</th><th>Date</th><th>Time</th><th>Venue</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($sessions as $s):
          $badgeClass = ['Scheduled' => 'badge-blue', 'Ongoing' => 'badge-gold', 'Completed' => 'badge-ok', 'Cancelled' => 'badge-bad'][$s['status']] ?? 'badge-grey';
        ?>
        <tr>
          <td><?= htmlspecialchars($s['title'], ENT_QUOTES) ?></td>
          <td><?= htmlspecialchars($s['type'], ENT_QUOTES) ?></td>
          <td><?= htmlspecialchars($s['date'], ENT_QUOTES) ?></td>
          <td><?= htmlspecialchars($s['time'], ENT_QUOTES) ?></td>
          <td><?= htmlspecialchars($s['venue'], ENT_QUOTES) ?></td>
          <td><span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($s['status'], ENT_QUOTES) ?></span></td>
          <td>
            <div style="display:flex;gap:6px;flex-wrap:wrap;">
              <?php if ($s['status'] === 'Scheduled'): ?>
                <form method="POST" style="display:inline;"><input type="hidden" name="action" value="set_status"><input type="hidden" name="id" value="<?= $s['id'] ?>"><input type="hidden" name="status" value="Ongoing"><button class="btn btn-gold btn-sm"><i class="fa-solid fa-play"></i> Start</button></form>
                <form method="POST" style="display:inline;"><input type="hidden" name="action" value="set_status"><input type="hidden" name="id" value="<?= $s['id'] ?>"><input type="hidden" name="status" value="Cancelled"><button class="btn btn-danger btn-sm"><i class="fa-solid fa-ban"></i> Cancel</button></form>
              <?php elseif ($s['status'] === 'Ongoing'): ?>
                <form method="POST" style="display:inline;"><input type="hidden" name="action" value="set_status"><input type="hidden" name="id" value="<?= $s['id'] ?>"><input type="hidden" name="status" value="Completed"><button class="btn btn-navy btn-sm"><i class="fa-solid fa-flag-checkered"></i> Complete</button></form>
                <a href="tracking.php" class="btn btn-ghost btn-sm"><i class="fa-solid fa-eye"></i> Track</a>
              <?php endif; ?>
              <form method="POST" style="display:inline;" onsubmit="return confirm('Remove this session?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $s['id'] ?>"><button class="btn btn-ghost btn-sm"><i class="fa-solid fa-trash"></i></button></form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
    <div class="empty-state"><i class="fa-solid fa-calendar-xmark"></i>No sessions yet. Schedule one above.</div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
