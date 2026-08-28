<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/store.php';
ssms_require_staff(); // secretary/admin only — council members get redirected to Attendance

$flash = '';

if (isset($_POST['action']) && $_POST['action'] === 'create') {
    $name      = trim($_POST['name'] ?? '');
    $position  = trim($_POST['position'] ?? '');
    $committee = trim($_POST['committee'] ?? '');

    if ($name && $position) {
        ssms_insert('members', ['name' => $name, 'position' => $position, 'committee' => $committee ?: null]);
        $flash = 'Member added.';
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'update' && isset($_POST['id'])) {
    $id        = (int)$_POST['id'];
    $name      = trim($_POST['name'] ?? '');
    $position  = trim($_POST['position'] ?? '');
    $committee = trim($_POST['committee'] ?? '');

    if ($name && $position) {
        ssms_update('members', $id, ['name' => $name, 'position' => $position, 'committee' => $committee ?: null]);
        $flash = 'Member updated.';
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['id'])) {
    $memberId = (int)$_POST['id'];
    ssms_delete('members', $memberId);
    // A removed council member must also lose their QR badge — otherwise
    // it keeps working forever and they could still scan in, get marked
    // Present, and count toward quorum after leaving the council.
    $badges = array_values(array_filter(ssms_read('qr_users'), fn($u) => (int)($u['memberId'] ?? 0) === $memberId));
    foreach ($badges as $b) {
        ssms_delete('qr_users', $b['id']);
    }
    $flash = 'Member removed' . ($badges ? ' and their QR badge access was revoked.' : '.');
}

$members = ssms_read('members');
usort($members, fn($a, $b) => strcmp($a['name'], $b['name']));

$page_title = 'Council Members';
$page_sub   = 'Add, edit, or remove council members — used across Attendance, Minutes, and Tracking';
$active_page = 'members';
include __DIR__ . '/../includes/header.php';
?>

<?php if ($flash): ?><div class="alert alert-ok"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($flash, ENT_QUOTES) ?></div><?php endif; ?>

<div class="card">
  <div class="card-head">
    <div><h3>Add a Council Member</h3><p>They'll immediately show up in Attendance roll call.</p></div>
  </div>
  <form method="POST"><?php ssms_csrf_field(); ?>
    <input type="hidden" name="action" value="create">
    <div class="form-row three">
      <div class="form-group"><label>Full Name</label><input type="text" name="name" placeholder="e.g. Hon. Juan Dela Cruz" required></div>
      <div class="form-group"><label>Position</label><input type="text" name="position" placeholder="e.g. Councilor, Vice Mayor" required></div>
      <div class="form-group"><label>Committee (optional)</label><input type="text" name="committee" placeholder="e.g. Committee on Finance"></div>
    </div>
    <button type="submit" class="btn btn-navy"><i class="fa-solid fa-user-plus"></i> Add Member</button>
  </form>
</div>

<div class="card">
  <div class="card-head">
    <div><h3>All Members</h3><p><?= count($members) ?> member<?= count($members) === 1 ? '' : 's' ?> on record.</p></div>
  </div>
  <?php if ($members): ?>
  <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Name</th><th>Position</th><th>Committee</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($members as $m): $fid = 'member-edit-' . $m['id']; ?>
        <tr>
          <form method="POST" id="<?= $fid ?>">
            <?php ssms_csrf_field(); ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= $m['id'] ?>">
          </form>
          <td><input form="<?= $fid ?>" type="text" name="name" value="<?= htmlspecialchars($m['name'], ENT_QUOTES) ?>" required style="width:100%;border:1px solid transparent;background:transparent;padding:4px 6px;border-radius:6px;" onfocus="this.style.border='1px solid var(--line)';this.style.background='#fff';"></td>
          <td><input form="<?= $fid ?>" type="text" name="position" value="<?= htmlspecialchars($m['position'], ENT_QUOTES) ?>" required style="width:100%;border:1px solid transparent;background:transparent;padding:4px 6px;border-radius:6px;" onfocus="this.style.border='1px solid var(--line)';this.style.background='#fff';"></td>
          <td><input form="<?= $fid ?>" type="text" name="committee" value="<?= htmlspecialchars($m['committee'] ?? '', ENT_QUOTES) ?>" style="width:100%;border:1px solid transparent;background:transparent;padding:4px 6px;border-radius:6px;" onfocus="this.style.border='1px solid var(--line)';this.style.background='#fff';"></td>
          <td>
            <div style="display:flex;gap:6px;">
              <button form="<?= $fid ?>" type="submit" class="btn btn-ghost btn-sm" title="Save changes"><i class="fa-solid fa-floppy-disk"></i></button>
              <form method="POST" style="display:inline;" onsubmit="return confirm('Remove <?= htmlspecialchars(addslashes($m['name']), ENT_QUOTES) ?>? This also revokes their QR badge if they have one — their past attendance records are kept.');"><?php ssms_csrf_field(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $m['id'] ?>">
                <button class="btn btn-ghost btn-sm" title="Remove member"><i class="fa-solid fa-trash"></i></button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
    <div class="empty-state"><i class="fa-solid fa-user-slash"></i>No members yet. Add the council roster above — Attendance and Minutes need this list to work.</div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
