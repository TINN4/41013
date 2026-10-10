<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/store.php';
require_once __DIR__ . '/includes/mfa.php';

// Only the admin (username/password login) may manage device bindings —
// QR-badge (member) sessions are blocked from this page.
if (strpos($_SESSION['ssms_user'], 'qr:') === 0) {
    http_response_code(403);
    die('Only administrators can manage device bindings. <a href="modules/scheduling.php">Back to system</a>');
}

$flash = '';
if (isset($_POST['action']) && $_POST['action'] === 'reset_device' && isset($_POST['id'])) {
    ssms_update('qr_users', (int)$_POST['id'], ['deviceId' => null, 'deviceLabel' => null, 'boundAt' => null]);
    $flash = 'Device binding reset. That account can now bind to a new device on its next QR scan.';
}
if (isset($_POST['action']) && $_POST['action'] === 'regen_token' && isset($_POST['id'])) {
    ssms_update('qr_users', (int)$_POST['id'], ['token' => bin2hex(random_bytes(12)), 'deviceId' => null, 'deviceLabel' => null, 'boundAt' => null]);
    $flash = 'New QR badge generated. The old badge no longer works, and the device binding was cleared.';
}

if (isset($_POST['action']) && $_POST['action'] === 'set_email' && isset($_POST['id'])) {
    $em = trim($_POST['email'] ?? '');
    if ($em === '' || filter_var($em, FILTER_VALIDATE_EMAIL)) { ssms_update('qr_users', (int)$_POST['id'], ['email' => $em]); mfa_clear_fails('qr:' . (int)$_POST['id']); $flash = 'Email saved. Login codes for this member will be sent there.'; }
    else { $flash = 'That email address is not valid.'; }
}
$users = ssms_read('qr_users');

$page_title = 'Manage QR Badges & Devices';
$page_sub   = 'Admin only — issue badges, review device bindings, reset lost or stolen devices';
$active_page = '';
include __DIR__ . '/includes/header.php';
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

<?php if ($flash): ?><div class="alert alert-ok"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($flash, ENT_QUOTES) ?></div><?php endif; ?>

<div class="alert alert-warn"><i class="fa-solid fa-shield-halved"></i> Each QR badge is bound to exactly one device on first scan. If a device is lost, stolen, or replaced, use <strong>Reset Device</strong> below to unbind it before the member can log in on a new device.</div>

<div class="card">
  <div class="card-head"><div><h3>Council Members &amp; Staff Badges</h3><p><?= count($users) ?> account(s)</p></div></div>
  <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Name</th><th>Position</th><th>Device Status</th><th>Login Email (OTP)</th><th>Last Login</th><th>QR Badge</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($users as $u): ?>
        <tr>
          <td><?= htmlspecialchars($u['name'], ENT_QUOTES) ?></td>
          <td><span class="text-xs" style="font-size:12px;color:var(--ink-600);"><?= htmlspecialchars($u['position'], ENT_QUOTES) ?></span></td>
          <td>
            <?php if ($u['deviceId']): ?>
              <span class="badge badge-ok"><i class="fa-solid fa-lock"></i> Bound</span>
              <div style="font-size:11px;color:var(--ink-600);margin-top:4px;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($u['deviceLabel']??'', ENT_QUOTES) ?>"><?= htmlspecialchars($u['deviceLabel']??'', ENT_QUOTES) ?></div>
              <div style="font-size:11px;color:var(--ink-600);">since <?= htmlspecialchars($u['boundAt'], ENT_QUOTES) ?></div>
            <?php else: ?>
              <span class="badge badge-grey"><i class="fa-solid fa-unlock"></i> Not yet registered</span>
            <?php endif; ?>
          </td>
          <td>
            <form method="POST" style="display:flex;gap:6px;align-items:center;min-width:230px;">
              <input type="hidden" name="action" value="set_email"><input type="hidden" name="id" value="<?= $u['id'] ?>">
              <input type="email" name="email" value="<?= htmlspecialchars($u['email'] ?? '', ENT_QUOTES) ?>" placeholder="name@gmail.com" style="flex:1;padding:6px 8px;border:1px solid var(--line);border-radius:8px;font-size:12px;<?= empty($u['email']) ? 'border-color:var(--warn-600);' : '' ?>">
              <button class="btn btn-ghost btn-sm" title="Save email"><i class="fa-solid fa-floppy-disk"></i></button>
            </form>
            <?php if (empty($u['email'])): ?><div style="font-size:11px;color:var(--warn-600);margin-top:3px;">Required to log in</div><?php endif; ?>
          </td>
          <td><span style="font-size:12px;color:var(--ink-600);"><?= htmlspecialchars($u['lastLoginAt'] ?: '—', ENT_QUOTES) ?></span></td>
          <td>
            <div id="qr-<?= $u['id'] ?>" style="width:80px;height:80px;"></div>
            <button class="btn btn-ghost btn-sm" style="margin-top:6px;" onclick="downloadQR('<?= $u['id'] ?>','<?= htmlspecialchars(preg_replace('/[^A-Za-z0-9]+/','_',$u['name']), ENT_QUOTES) ?>')"><i class="fa-solid fa-download"></i> Save</button>
          </td>
          <td>
            <div style="display:flex;flex-direction:column;gap:6px;">
              <?php if ($u['deviceId']): ?>
              <form method="POST" onsubmit="return confirm('Reset device binding for <?= htmlspecialchars($u['name'], ENT_QUOTES) ?>? They will need to scan their badge again to bind a new device.');">
                <input type="hidden" name="action" value="reset_device"><input type="hidden" name="id" value="<?= $u['id'] ?>">
                <button class="btn btn-danger btn-sm" style="width:100%;"><i class="fa-solid fa-unlock-keyhole"></i> Reset Device</button>
              </form>
              <?php endif; ?>
              <form method="POST" onsubmit="return confirm('Generate a new QR badge for <?= htmlspecialchars($u['name'], ENT_QUOTES) ?>? The old badge will stop working immediately.');">
                <input type="hidden" name="action" value="regen_token"><input type="hidden" name="id" value="<?= $u['id'] ?>">
                <button class="btn btn-ghost btn-sm" style="width:100%;"><i class="fa-solid fa-arrows-rotate"></i> New Badge</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>


<script>
<?php foreach ($users as $u): ?>
new QRCode(document.getElementById('qr-<?= $u['id'] ?>'), { text: '<?= addslashes($u['token']) ?>', width: 80, height: 80 });
<?php endforeach; ?>

function downloadQR(id, name) {
  const canvas = document.querySelector('#qr-' + id + ' canvas');
  if (!canvas) return;
  const link = document.createElement('a');
  link.download = 'badge-' + name + '.png';
  link.href = canvas.toDataURL('image/png');
  link.click();
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
