<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/store.php';

// Only the admin (username/password login) may manage device bindings —
// QR-badge (member) sessions are blocked from this page.
if (strpos($_SESSION['ssms_user'], 'qr:') === 0) {
    http_response_code(403);
    die('Only administrators can manage device bindings. <a href="modules/scheduling.php">Back to system</a>');
}

$flash = '';
$formError = '';
if (isset($_POST['action']) && $_POST['action'] === 'create_badge') {
    $memberId = (int)($_POST['member_id'] ?? 0);
    $member = $memberId ? ssms_find('members', $memberId) : null;

    if (!$member) {
        $formError = 'Please select a valid council member.';
    } else {
        $already = array_filter(ssms_read('qr_users'), fn($u) => (int)($u['memberId'] ?? 0) === $memberId);
        if ($already) {
            $formError = $member['name'] . ' already has a QR badge issued.';
        } else {
            ssms_insert('qr_users', [
                'memberId'     => $memberId,
                'name'         => $member['name'],
                'position'     => $member['position'],
                'token'        => bin2hex(random_bytes(12)),
                'deviceId'     => null,
                'deviceLabel'  => null,
                'boundAt'      => null,
                'lastLoginAt'  => null,
            ]);
            $flash = 'QR badge created for ' . $member['name'] . '. Download it below and hand it to them — it will bind to whichever device scans it first.';
        }
    }
}
if (isset($_POST['action']) && $_POST['action'] === 'reset_device' && isset($_POST['id'])) {
    ssms_update('qr_users', (int)$_POST['id'], ['deviceId' => null, 'deviceLabel' => null, 'boundAt' => null]);
    $flash = 'Device binding reset. That account can now bind to a new device on its next QR scan.';
}
if (isset($_POST['action']) && $_POST['action'] === 'regen_token' && isset($_POST['id'])) {
    ssms_update('qr_users', (int)$_POST['id'], ['token' => bin2hex(random_bytes(12)), 'deviceId' => null, 'deviceLabel' => null, 'boundAt' => null]);
    $flash = 'New QR badge generated. The old badge no longer works, and the device binding was cleared.';
}

$users = ssms_read('qr_users');
$allMembers = ssms_read('members');
$badgedMemberIds = array_map(fn($u) => (int)($u['memberId'] ?? 0), $users);
$membersWithoutBadge = array_values(array_filter($allMembers, fn($m) => !in_array((int)$m['id'], $badgedMemberIds, true)));

$page_title = 'Manage QR Badges & Devices';
$page_sub   = 'Admin only — issue badges, review device bindings, reset lost or stolen devices';
$active_page = '';
include __DIR__ . '/includes/header.php';
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

<?php if ($flash): ?><div class="alert alert-ok"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($flash, ENT_QUOTES) ?></div><?php endif; ?>
<?php if ($formError): ?><div class="alert alert-warn"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($formError, ENT_QUOTES) ?></div><?php endif; ?>

<div class="card" style="margin-bottom:16px;">
  <div class="card-head"><div><h3>Issue a New QR Badge</h3><p>Only council members without an existing badge are listed</p></div></div>
  <div style="padding:16px;">
    <?php if (!$membersWithoutBadge): ?>
      <p style="font-size:13px;color:var(--ink-600);">Every council member already has a badge. Add a new member in <strong>Council Members</strong> first, then come back here to issue their badge.</p>
    <?php else: ?>
      <form method="POST" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;"><?php ssms_csrf_field(); ?>
        <input type="hidden" name="action" value="create_badge">
        <div class="field" style="flex:1;min-width:220px;margin:0;">
          <label for="member_id">Council Member</label>
          <div class="input-wrap">
            <i class="fa-solid fa-user-plus"></i>
            <select id="member_id" name="member_id" required>
              <option value="">Select a member&hellip;</option>
              <?php foreach ($membersWithoutBadge as $m): ?>
                <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['name'], ENT_QUOTES) ?> &mdash; <?= htmlspecialchars($m['position'], ENT_QUOTES) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <button class="btn-primary" type="submit" style="height:44px;"><i class="fa-solid fa-qrcode"></i> Generate Badge</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="alert alert-warn"><i class="fa-solid fa-shield-halved"></i> Each QR badge is bound to exactly one device on first scan. If a device is lost, stolen, or replaced, use <strong>Reset Device</strong> below to unbind it before the member can log in on a new device.</div>

<div class="card">
  <div class="card-head"><div><h3>Council Members &amp; Staff Badges</h3><p><?= count($users) ?> account(s)</p></div></div>
  <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Name</th><th>Position</th><th>Device Status</th><th>Last Login</th><th>QR Badge</th><th>Actions</th></tr></thead>
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
          <td><span style="font-size:12px;color:var(--ink-600);"><?= htmlspecialchars($u['lastLoginAt'] ?: '—', ENT_QUOTES) ?></span></td>
          <td>
            <div id="qr-<?= $u['id'] ?>" style="width:80px;height:80px;"></div>
            <button class="btn btn-ghost btn-sm" style="margin-top:6px;" onclick="downloadQR('<?= $u['id'] ?>','<?= htmlspecialchars(preg_replace('/[^A-Za-z0-9]+/','_',$u['name']), ENT_QUOTES) ?>')"><i class="fa-solid fa-download"></i> Save</button>
          </td>
          <td>
            <div style="display:flex;flex-direction:column;gap:6px;">
              <?php if ($u['deviceId']): ?>
              <form method="POST" onsubmit="return confirm('Reset device binding for <?= htmlspecialchars($u['name'], ENT_QUOTES) ?>? They will need to scan their badge again to bind a new device.');"><?php ssms_csrf_field(); ?>
                <input type="hidden" name="action" value="reset_device"><input type="hidden" name="id" value="<?= $u['id'] ?>">
                <button class="btn btn-danger btn-sm" style="width:100%;"><i class="fa-solid fa-unlock-keyhole"></i> Reset Device</button>
              </form>
              <?php endif; ?>
              <form method="POST" onsubmit="return confirm('Generate a new QR badge for <?= htmlspecialchars($u['name'], ENT_QUOTES) ?>? The old badge will stop working immediately.');"><?php ssms_csrf_field(); ?>
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
