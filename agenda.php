<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/store.php';
ssms_require_staff(); // secretary/admin only — council members get redirected to Attendance

$sessions = array_values(array_filter(ssms_read('sessions'), fn($s) => empty($s['archived_at']))); // archived sessions live in the Archive page instead
usort($sessions, fn($a, $b) => strcmp($b['date'], $a['date']));

$selectedId = isset($_GET['session_id']) ? (int)$_GET['session_id'] : ($_POST['session_id'] ?? null);
if (!$selectedId && $sessions) {
    // default to Ongoing session, else the first
    $ongoing = array_values(array_filter($sessions, fn($s) => $s['status'] === 'Ongoing'));
    $selectedId = $ongoing ? $ongoing[0]['id'] : $sessions[0]['id'];
}
$selectedId = (int)$selectedId;

$flash = '';

if (isset($_POST['action']) && $_POST['action'] === 'add_item') {
    $existing = ssms_where('agenda', 'session_id', $selectedId);
    $nextOrder = count($existing) + 1;
    ssms_insert('agenda', [
        'session_id' => $selectedId,
        'order' => $nextOrder,
        'item' => trim($_POST['item'] ?? ''),
        'presenter' => trim($_POST['presenter'] ?? ''),
        'status' => 'Pending',
    ]);
    $flash = 'Agenda item added.';
}

if (isset($_POST['action']) && $_POST['action'] === 'set_status') {
    ssms_update('agenda', (int)$_POST['id'], ['status' => $_POST['status']]);
    $flash = 'Agenda item updated.';
}

if (isset($_POST['action']) && $_POST['action'] === 'move' && isset($_POST['id'], $_POST['dir'])) {
    $items = ssms_where('agenda', 'session_id', $selectedId);
    usort($items, fn($a, $b) => $a['order'] <=> $b['order']);
    $idx = null;
    foreach ($items as $i => $it) { if ($it['id'] == $_POST['id']) { $idx = $i; break; } }
    if ($idx !== null) {
        $swapWith = $_POST['dir'] === 'up' ? $idx - 1 : $idx + 1;
        if ($swapWith >= 0 && $swapWith < count($items)) {
            $a = $items[$idx]; $b = $items[$swapWith];
            ssms_update('agenda', $a['id'], ['order' => $b['order']]);
            ssms_update('agenda', $b['id'], ['order' => $a['order']]);
        }
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'delete') {
    ssms_delete('agenda', (int)$_POST['id']);
    $flash = 'Agenda item removed.';
}

$agendaItems = ssms_where('agenda', 'session_id', $selectedId);
usort($agendaItems, fn($a, $b) => $a['order'] <=> $b['order']);
$currentSession = ssms_find('sessions', $selectedId);

$page_title = 'Agenda Preparation';
$page_sub   = 'Build and order the agenda for a session';
$active_page = 'agenda';
include __DIR__ . '/../includes/header.php';
?>

<?php if ($flash): ?><div class="alert alert-ok"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($flash, ENT_QUOTES) ?></div><?php endif; ?>

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

<div class="card">
  <div class="card-head">
    <div><h3>Add Agenda Item</h3><p>For: <?= htmlspecialchars($currentSession['title'], ENT_QUOTES) ?></p></div>
  </div>
  <form method="POST"><?php ssms_csrf_field(); ?>
    <input type="hidden" name="action" value="add_item">
    <input type="hidden" name="session_id" value="<?= $selectedId ?>">
    <div class="form-row two">
      <div class="form-group"><label>Agenda Item</label><input type="text" name="item" placeholder="e.g. Second reading of proposed ordinance" required></div>
      <div class="form-group"><label>Presenter / Sponsor</label><input type="text" name="presenter" placeholder="e.g. Hon. D. Santos"></div>
    </div>
    <button type="submit" class="btn btn-navy"><i class="fa-solid fa-plus"></i> Add to Agenda</button>
  </form>
</div>

<div class="card">
  <div class="card-head">
    <div><h3>Agenda Order</h3><p><?= count($agendaItems) ?> item(s) &mdash; reorder with the arrows, update status as the session proceeds.</p></div>
  </div>
  <?php if ($agendaItems): ?>
  <div class="agenda-list">
    <?php foreach ($agendaItems as $item):
      $statusBadge = ['Pending' => 'badge-grey', 'Discussed' => 'badge-blue', 'Approved' => 'badge-ok', 'Deferred' => 'badge-warn'][$item['status']] ?? 'badge-grey';
      $isCurrent = $currentSession['current_agenda_id'] == $item['id'];
    ?>
    <div class="agenda-item <?= $isCurrent ? 'current' : '' ?>">
      <div class="num"><?= $item['order'] ?></div>
      <div class="body">
        <p class="title"><?= htmlspecialchars($item['item'], ENT_QUOTES) ?> <?php if ($isCurrent): ?><span class="badge badge-gold" style="margin-left:6px;">Now Discussing</span><?php endif; ?></p>
        <p class="meta">Presenter: <?= htmlspecialchars($item['presenter'] ?: '—', ENT_QUOTES) ?></p>
      </div>
      <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
        <span class="badge <?= $statusBadge ?>"><?= htmlspecialchars($item['status'], ENT_QUOTES) ?></span>
        <form method="POST" style="display:inline;"><?php ssms_csrf_field(); ?><input type="hidden" name="action" value="set_status"><input type="hidden" name="id" value="<?= $item['id'] ?>"><input type="hidden" name="session_id" value="<?= $selectedId ?>">
          <select name="status" onchange="this.form.submit()" style="padding:5px 8px;border-radius:7px;border:1px solid var(--line);font-size:11.5px;">
            <option <?= $item['status']==='Pending'?'selected':'' ?>>Pending</option>
            <option <?= $item['status']==='Discussed'?'selected':'' ?>>Discussed</option>
            <option <?= $item['status']==='Approved'?'selected':'' ?>>Approved</option>
            <option <?= $item['status']==='Deferred'?'selected':'' ?>>Deferred</option>
          </select>
        </form>
        <form method="POST" style="display:inline;"><?php ssms_csrf_field(); ?><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= $item['id'] ?>"><input type="hidden" name="dir" value="up"><input type="hidden" name="session_id" value="<?= $selectedId ?>"><button class="btn btn-ghost btn-sm"><i class="fa-solid fa-arrow-up"></i></button></form>
        <form method="POST" style="display:inline;"><?php ssms_csrf_field(); ?><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= $item['id'] ?>"><input type="hidden" name="dir" value="down"><input type="hidden" name="session_id" value="<?= $selectedId ?>"><button class="btn btn-ghost btn-sm"><i class="fa-solid fa-arrow-down"></i></button></form>
        <form method="POST" style="display:inline;" onsubmit="return confirm('Remove this item?');"><?php ssms_csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $item['id'] ?>"><input type="hidden" name="session_id" value="<?= $selectedId ?>"><button class="btn btn-ghost btn-sm"><i class="fa-solid fa-trash"></i></button></form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
    <div class="empty-state"><i class="fa-solid fa-list"></i>No agenda items yet for this session.</div>
  <?php endif; ?>
</div>

<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
