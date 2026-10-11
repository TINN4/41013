<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/authflow.php';
$admin = ssms_require('devices.manage');

$issued = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $bid = (int)($_POST['id'] ?? 0);
    $uidT = (int)($_POST['user_id'] ?? 0);
    if (!ssms_verify_password($admin, (string)($_POST['confirm_password'] ?? ''))) {
        ssms_audit('device.admin_action', $bid ?: $uidT, 'denied', ['reason' => 'reauth_failed', 'op' => $action]);
        ssms_flash('bad', 'Your password was not correct. Nothing was changed.');
        ssms_redirect('manage_devices.php');
    }
    if ($action === 'issue') {
        $target = ssms_user_by_id($uidT);
        if (!$target) { ssms_flash('bad', 'Unknown account.'); ssms_redirect('manage_devices.php'); }
        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');          // 192-bit CSPRNG
        ssms_mutate('badges', function (array $rows) use ($uidT) {                         // old badges die
            foreach ($rows as &$r) if ((int)$r['user_id'] === $uidT && empty($r['revoked'])) { $r['revoked'] = true; $r['revoked_at'] = ssms_now(); }
            return $rows;
        });
        $id = ssms_insert('badges', [
            'user_id' => $uidT, 'token_hash' => hash('sha256', $token), 'created_at' => ssms_now(), 'issued_by' => $admin['id'],
            'enroll_expires_at' => date('Y-m-d H:i:s', time() + 3600 * (int)ssms_cfg('SSMS_BADGE_ENROLL_HOURS', '72')),
            'expires_at' => date('Y-m-d H:i:s', time() + 86400 * (int)ssms_cfg('SSMS_BADGE_VALID_DAYS', '180')),
            'device_id' => null, 'device_secret_hash' => null, 'device_label' => null, 'bound_at' => null, 'last_login_at' => null, 'revoked' => false,
        ]);
        ssms_update('users', $uidT, ['session_version' => (int)$target['session_version'] + 1]);   // end any session on old badge
        ssms_audit('badge.issued', $id, 'ok', ['user_id' => $uidT]);
        $issued = ['token' => $token, 'name' => $target['name']];
    } elseif ($action === 'revoke' && $bid) {
        ssms_update('badges', $bid, ['revoked' => true, 'revoked_at' => ssms_now()]);
        ssms_audit('badge.revoked', $bid, 'ok');
        ssms_flash('ok', 'Badge revoked. Any session using it ends on its next request.');
        ssms_redirect('manage_devices.php');
    } elseif ($action === 'reset_device' && $bid) {
        ssms_update('badges', $bid, ['device_id' => null, 'device_secret_hash' => null, 'device_label' => null, 'bound_at' => null,
            'enroll_expires_at' => date('Y-m-d H:i:s', time() + 3600 * (int)ssms_cfg('SSMS_BADGE_ENROLL_HOURS', '72'))]);
        ssms_audit('device.reset', $bid, 'ok');
        ssms_flash('ok', 'Device binding cleared. The member can bind a new device within the enrollment window.');
        ssms_redirect('manage_devices.php');
    }
}

$users = array_values(array_filter(ssms_read('users'), fn($u) => $u['active'] ?? false));
$badges = []; foreach (ssms_read('badges') as $b) if (empty($b['revoked'])) $badges[(int)$b['user_id']] = $b;
$page_title = 'QR Badges & Devices'; $page_sub = 'Issue badges, review device bindings, revoke lost devices'; $active_page = 'devices';
include __DIR__ . '/includes/header.php';
?>
<div class="alert alert-warn"><i class="fa-solid fa-shield-halved"></i> A badge QR is only an identifier. It works only on the one device that bound it first (within the enrollment window), only inside the configured geofence, and still requires two-step verification where policy demands. Geofence coordinates are reported by the browser and are not proof of presence.</div>

<?php if ($issued): ?>
<div class="card" id="issued-card">
  <div class="card-head"><div><h3>New badge for <?= ssms_e($issued['name']) ?></h3><p>Shown <strong>once</strong>. Deliver it privately; it is not stored in readable form.</p></div></div>
  <div id="issued-qr" style="width:180px;" role="img" aria-label="New badge QR code"></div>
  <p class="mono" style="font-size:12px;" id="issued-token" data-token="<?= ssms_e($issued['token']) ?>">Token: <?= ssms_e($issued['token']) ?></p>
  <button class="btn btn-ghost btn-sm" type="button" id="issued-save"><i class="fa-solid fa-download"></i> Save image</button>
</div>
<script src="assets/vendor/qrcode.js?v=2"></script>
<script>
(function () {
  var box = document.getElementById('issued-qr');
  var t = document.getElementById('issued-token').getAttribute('data-token');
  var saveBtn = document.getElementById('issued-save');
  try {
    if (typeof qrcode !== 'function') throw new Error('QR library not loaded');
    var q = qrcode(0, 'M'); q.addData(t); q.make();
    box.innerHTML = q.createSvgTag({ cellSize: 5, margin: 2, scalable: true });
  } catch (err) {
    box.innerHTML = '<p style="color:#b42318;font-size:13px;">The QR image could not be drawn (' + err.message + '). Reload this page with Ctrl+F5 and issue the badge again.</p>';
    saveBtn.disabled = true;
  }
  function download(href, name) {
    var a = document.createElement('a'); a.download = name; a.href = href;
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
  }
  saveBtn.addEventListener('click', function () {
    var el = box.querySelector('svg');
    if (!el) return;
    var svg = el.outerHTML.replace('<svg ', '<svg width="600" height="600" ');
    var uri = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
    var img = new Image();
    img.onload = function () {            // PNG is the most compatible format for chat apps and phones
      var c = document.createElement('canvas'); c.width = 600; c.height = 600;
      var x = c.getContext('2d'); x.fillStyle = '#ffffff'; x.fillRect(0, 0, 600, 600); x.drawImage(img, 0, 0, 600, 600);
      download(c.toDataURL('image/png'), 'badge.png');
    };
    img.onerror = function () { download(uri, 'badge.svg'); };
    img.src = uri;
  });
})();
</script>
<?php endif; ?>

<div class="card">
  <div class="card-head"><div><h3>Accounts &amp; Badges</h3><p><?= count($users) ?> active account(s). Re-enter your password for every change.</p></div></div>
  <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Name</th><th>Role</th><th>Badge</th><th>Device</th><th>Last QR login</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): $b = $badges[(int)$u['id']] ?? null; ?>
        <tr>
          <td><?= ssms_e($u['name']) ?></td>
          <td><span class="badge badge-grey"><?= ssms_e(ssms_role_label($u['role'])) ?></span></td>
          <td><?php if ($b): ?><span class="badge badge-ok">Issued</span><div class="mono" style="font-size:11px;">valid until <?= ssms_e($b['expires_at']) ?></div><?php else: ?><span class="badge badge-grey">None</span><?php endif; ?></td>
          <td><?php if ($b && $b['device_id']): ?><span class="badge badge-ok"><i class="fa-solid fa-lock"></i> Bound</span>
                <div style="font-size:11px;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= ssms_e($b['device_label']) ?>"><?= ssms_e($b['device_label']) ?></div>
                <div style="font-size:11px;">since <?= ssms_e($b['bound_at']) ?></div>
              <?php elseif ($b): ?><span class="badge badge-warn">Awaiting first scan</span><div style="font-size:11px;">enroll by <?= ssms_e($b['enroll_expires_at']) ?></div>
              <?php else: ?>&mdash;<?php endif; ?></td>
          <td><?= ssms_e($b['last_login_at'] ?? '—') ?></td>
          <td>
            <form method="POST" class="inline-form" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;" data-confirm="Apply this change for <?= ssms_e($u['name']) ?>?">
              <?= ssms_csrf_field() ?>
              <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="id" value="<?= (int)($b['id'] ?? 0) ?>">
              <label class="sr-only" for="cp-<?= (int)$u['id'] ?>" style="position:absolute;left:-999px;">Your password</label>
              <input type="password" id="cp-<?= (int)$u['id'] ?>" name="confirm_password" placeholder="Your password" autocomplete="current-password" required style="padding:5px 8px;border:1px solid var(--line);border-radius:7px;font-size:12px;width:130px;">
              <button class="btn btn-navy btn-sm" name="action" value="issue" type="submit"><i class="fa-solid fa-id-badge"></i> <?= $b ? 'Re-issue' : 'Issue' ?></button>
              <?php if ($b && $b['device_id']): ?><button class="btn btn-ghost btn-sm" name="action" value="reset_device" type="submit">Reset device</button><?php endif; ?>
              <?php if ($b): ?><button class="btn btn-danger btn-sm" name="action" value="revoke" type="submit">Revoke</button><?php endif; ?>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
