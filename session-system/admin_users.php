<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/authflow.php';
$admin = ssms_require('users.manage');

function ssms_admin_count_after(array $users, int $excludeId): int {
    return count(array_filter($users, fn($u) => $u['role'] === 'sysadmin' && !empty($u['active']) && empty($u['locked']) && (int)$u['id'] !== $excludeId));
}
function ssms_issue_pw_token(int $uid): string {
    $t = bin2hex(random_bytes(32));
    ssms_update('users', $uid, ['pwreset' => ['hash' => hash('sha256', $t), 'expires' => time() + 86400]]);
    return $t;
}

$link = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string)($_POST['action'] ?? '');
    $tid = (int)($_POST['id'] ?? 0);
    $users = ssms_read('users');
    $target = null; foreach ($users as $x) if ((int)$x['id'] === $tid) $target = $x;
    $back = function (string $type, string $msg) { ssms_flash($type, $msg); ssms_redirect('admin_users.php'); };

    // Every change here is a sensitive operation: the administrator must re-enter their own password.
    if (!ssms_verify_password($admin, (string)($_POST['confirm_password'] ?? ''))) {
        ssms_audit('admin.user_op', $tid, 'denied', ['op' => $a, 'reason' => 'reauth_failed']);
        $back('bad', 'Your password was not correct. Nothing was changed.');
    }

    if ($a === 'create') {
        $username = strtolower(ssms_text((string)($_POST['username'] ?? ''), 40));
        $name = ssms_text((string)($_POST['name'] ?? ''), 100);
        $role = (string)($_POST['role'] ?? '');
        $member = (int)($_POST['member_id'] ?? 0) ?: null;
        if (!preg_match('/^[a-z0-9_.-]{3,40}$/', $username)) $back('bad', 'Username must be 3-40 characters: letters, digits, dot, dash, underscore.');
        if ($name === '') $back('bad', 'Full name is required.');
        if (!ssms_valid_role($role)) $back('bad', 'Choose a valid role.');
        if ($member && !ssms_find('members', $member)) $back('bad', 'Unknown council member.');
        [$id, $dup] = ssms_insert_unique('users', [
            'username' => $username, 'name' => $name, 'role' => $role, 'member_id' => $member, 'password_hash' => null, 'active' => true,
            'mfa' => ['enabled' => false, 'secret_enc' => null, 'last_counter' => 0, 'recovery' => [], 'enrolled_at' => null],
            'failed_count' => 0, 'session_version' => 1, 'created_at' => ssms_now(), 'last_login_at' => null,
        ], function ($rows) use ($username) { foreach ($rows as $r) if (strcasecmp($r['username'], $username) === 0) return 'That username already exists.'; return null; });
        if ($dup) $back('bad', $dup);
        ssms_audit('user.created', $id, 'ok', ['username' => $username, 'role' => $role]);
        $link = ['name' => $name, 'url' => ssms_issue_pw_token($id), 'kind' => 'setup'];
    }
    elseif (!$target) $back('bad', 'Unknown account.');
    elseif ($a === 'change_role') {
        $role = (string)($_POST['role'] ?? '');
        if (!ssms_valid_role($role)) $back('bad', 'Invalid role.');
        if ((int)$target['id'] === (int)$admin['id']) $back('bad', 'You cannot change your own role.');
        if ($target['role'] === 'sysadmin' && $role !== 'sysadmin' && ssms_admin_count_after($users, (int)$target['id']) < 1) $back('bad', 'This is the last usable administrator. Create or activate another administrator first.');
        ssms_update('users', $tid, ['role' => $role, 'session_version' => (int)$target['session_version'] + 1]);
        ssms_audit('user.role_changed', $tid, 'ok', ['before' => $target['role'], 'after' => $role]);
        $back('ok', 'Role updated. The user\'s active sessions were ended and the new role applies at next sign-in.');
    }
    elseif ($a === 'deactivate' || $a === 'activate') {
        if ((int)$target['id'] === (int)$admin['id']) $back('bad', 'You cannot deactivate your own account.');
        if ($a === 'deactivate' && $target['role'] === 'sysadmin' && ssms_admin_count_after($users, (int)$target['id']) < 1) $back('bad', 'This is the last usable administrator.');
        ssms_update('users', $tid, ['active' => $a === 'activate', 'session_version' => (int)$target['session_version'] + 1]);
        ssms_audit($a === 'activate' ? 'user.activated' : 'user.deactivated', $tid, 'ok');
        $back('ok', $a === 'activate' ? 'Account activated.' : 'Account deactivated and signed out everywhere.');
    }
    elseif ($a === 'lock' || $a === 'unlock') {
        if ($a === 'lock' && (int)$target['id'] === (int)$admin['id']) $back('bad', 'You cannot lock your own account.');
        if ($a === 'lock' && $target['role'] === 'sysadmin' && ssms_admin_count_after($users, (int)$target['id']) < 1) $back('bad', 'This is the last usable administrator.');
        ssms_update('users', $tid, ['locked' => $a === 'lock', 'session_version' => (int)$target['session_version'] + 1]);
        if ($a === 'unlock') { ssms_rl_clear('login:u:' . strtolower($target['username'])); ssms_rl_clear('mfa:u:' . $tid); }
        ssms_audit($a === 'lock' ? 'user.locked' : 'user.unlocked', $tid, 'ok');
        $back('ok', $a === 'lock' ? 'Account locked.' : 'Account unlocked and sign-in throttling cleared.');
    }
    elseif ($a === 'issue_reset') {
        $link = ['name' => $target['name'], 'url' => ssms_issue_pw_token($tid), 'kind' => 'reset'];
        ssms_audit('user.password_reset_issued', $tid, 'ok');
    }
    elseif ($a === 'request_mfa_reset') {
        $reason = ssms_text((string)($_POST['reason'] ?? ''), 300);
        if ($reason === '') $back('bad', 'A reason is required (e.g. identity verified in person after lost device).');
        ssms_update('users', $tid, ['mfa_reset_request' => ['by' => (int)$admin['id'], 'at' => ssms_now(), 'reason' => $reason]]);
        ssms_audit('mfa.reset_requested', $tid, 'ok', ['reason' => $reason]);
        $back('ok', 'MFA reset requested. A DIFFERENT administrator must approve it.');
    }
    elseif ($a === 'approve_mfa_reset') {
        $rq = $target['mfa_reset_request'] ?? null;
        if (!$rq) $back('bad', 'No pending request.');
        if ((int)$rq['by'] === (int)$admin['id']) { ssms_audit('mfa.reset_approved', $tid, 'denied', ['reason' => 'self_approval']); $back('bad', 'You cannot approve your own request. Ask another administrator.'); }
        ssms_update('users', $tid, ['mfa' => ['enabled' => false, 'secret_enc' => null, 'last_counter' => 0, 'recovery' => [], 'enrolled_at' => null], 'mfa_reset_request' => null, 'session_version' => (int)$target['session_version'] + 1]);
        ssms_audit('mfa.reset_approved', $tid, 'ok', ['requested_by' => $rq['by']]);
        $back('ok', 'MFA reset. The user must enroll a new authenticator at next sign-in (if required by policy).');
    }
    elseif ($a === 'cancel_mfa_reset') {
        ssms_update('users', $tid, ['mfa_reset_request' => null]);
        ssms_audit('mfa.reset_cancelled', $tid, 'ok');
        $back('ok', 'Request cancelled.');
    }
}

$users = ssms_read('users');
$members = ssms_read('members');
$page_title = 'User Accounts'; $page_sub = 'Roles, access status and recovery (administrators cannot approve legislative records)'; $active_page = 'admin_users';
include __DIR__ . '/includes/header.php';
?>
<?php if ($link): ?>
<div class="card"><div class="card-head"><div><h3><?= $link['kind'] === 'setup' ? 'Account created' : 'Password reset link' ?> &mdash; <?= ssms_e($link['name']) ?></h3>
  <p>Shown <strong>once</strong>; valid 24 hours; single use. Give it to the user privately. Do not email it unless your mail channel is trusted.</p></div></div>
  <div class="code-box" id="reset-link"><?= ssms_e((ssms_is_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/reset_password.php?token=' . $link['url']) ?></div></div>
<?php endif; ?>

<div class="card">
  <div class="card-head"><div><h3>Create account</h3><p>No password is set by the administrator: the user chooses their own through a one-time link.</p></div></div>
  <form method="POST" autocomplete="off">
    <?= ssms_csrf_field() ?><input type="hidden" name="action" value="create">
    <div class="form-row three">
      <div class="form-group"><label for="nu">Username</label><input id="nu" name="username" maxlength="40" required></div>
      <div class="form-group"><label for="nn">Full name</label><input id="nn" name="name" maxlength="100" required></div>
      <div class="form-group"><label for="nr">Role</label><select id="nr" name="role"><?php foreach (SSMS_ROLES as $k => $l): ?><option value="<?= ssms_e($k) ?>"><?= ssms_e($l) ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="form-row two">
      <div class="form-group"><label for="nm">Linked council member (for councilors / presiding officer)</label><select id="nm" name="member_id"><option value="">None</option><?php foreach ($members as $m): ?><option value="<?= (int)$m['id'] ?>"><?= ssms_e($m['name']) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label for="np">Your password (required)</label><input type="password" id="np" name="confirm_password" autocomplete="current-password" required></div>
    </div>
    <button class="btn btn-navy" type="submit"><i class="fa-solid fa-user-plus"></i> Create account</button>
  </form>
</div>

<div class="card">
  <div class="card-head"><div><h3>All accounts</h3><p><?= count($users) ?> account(s). Dev/test accounts are marked and should be removed before real use.</p></div></div>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>User</th><th>Role</th><th>Status</th><th>MFA</th><th>Last sign-in</th><th>Manage</th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): $self = (int)$u['id'] === (int)$admin['id']; $rq = $u['mfa_reset_request'] ?? null; ?>
      <tr>
        <td><strong><?= ssms_e($u['name']) ?></strong><div class="mono" style="font-size:11px;"><?= ssms_e($u['username']) ?><?= !empty($u['is_dev_account']) ? ' &middot; DEV' : '' ?></div></td>
        <td><?= ssms_e(ssms_role_label($u['role'])) ?></td>
        <td><?php if (empty($u['active'])): ?><span class="badge badge-bad">Deactivated</span><?php elseif (!empty($u['locked'])): ?><span class="badge badge-warn">Locked</span><?php else: ?><span class="badge badge-ok">Active</span><?php endif; ?></td>
        <td><?= ssms_mfa_enabled($u) ? '<span class="badge badge-ok">Enabled</span>' : '<span class="badge badge-grey">Not enrolled</span>' ?><div style="font-size:11px;">policy: <?= ssms_e(ssms_mfa_policy($u)) ?></div>
            <?php if ($rq): ?><div style="font-size:11px;color:var(--warn-600);">Reset requested: <?= ssms_e($rq['reason']) ?></div><?php endif; ?></td>
        <td style="font-size:12px;"><?= ssms_e($u['last_login_at'] ?? '—') ?></td>
        <td>
          <?php if (!$self): ?>
          <form method="POST" data-confirm="Apply this change to <?= ssms_e($u['name']) ?>?" style="display:flex;flex-wrap:wrap;gap:5px;align-items:center;">
            <?= ssms_csrf_field() ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <label for="r<?= (int)$u['id'] ?>" style="position:absolute;left:-999px;">Role for <?= ssms_e($u['name']) ?></label>
            <select id="r<?= (int)$u['id'] ?>" name="role" style="padding:5px;border:1px solid var(--line);border-radius:7px;font-size:12px;"><?php foreach (SSMS_ROLES as $k => $l): ?><option value="<?= ssms_e($k) ?>" <?= $k === $u['role'] ? 'selected' : '' ?>><?= ssms_e($l) ?></option><?php endforeach; ?></select>
            <label for="p<?= (int)$u['id'] ?>" style="position:absolute;left:-999px;">Your password</label>
            <input type="password" id="p<?= (int)$u['id'] ?>" name="confirm_password" placeholder="Your password" required autocomplete="current-password" style="padding:5px 8px;border:1px solid var(--line);border-radius:7px;font-size:12px;width:120px;">
            <button class="btn btn-ghost btn-sm" name="action" value="change_role">Set role</button>
            <?php if (!empty($u['active'])): ?><button class="btn btn-danger btn-sm" name="action" value="deactivate">Deactivate</button><?php else: ?><button class="btn btn-ghost btn-sm" name="action" value="activate">Activate</button><?php endif; ?>
            <?php if (!empty($u['locked'])): ?><button class="btn btn-ghost btn-sm" name="action" value="unlock">Unlock</button><?php else: ?><button class="btn btn-ghost btn-sm" name="action" value="lock">Lock</button><?php endif; ?>
            <button class="btn btn-ghost btn-sm" name="action" value="issue_reset">Password link</button>
            <?php if ($rq): ?>
              <button class="btn btn-gold btn-sm" name="action" value="approve_mfa_reset">Approve MFA reset</button>
              <button class="btn btn-ghost btn-sm" name="action" value="cancel_mfa_reset">Cancel request</button>
            <?php elseif (ssms_mfa_enabled($u)): ?>
              <input type="text" name="reason" maxlength="300" placeholder="Reason for MFA reset" aria-label="Reason for MFA reset" style="padding:5px 8px;border:1px solid var(--line);border-radius:7px;font-size:12px;width:170px;">
              <button class="btn btn-ghost btn-sm" name="action" value="request_mfa_reset">Request MFA reset</button>
            <?php endif; ?>
          </form>
          <?php else: ?><span style="font-size:12px;color:var(--ink-600);">This is you</span><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <p style="font-size:12px;color:var(--ink-600);margin-top:10px;">MFA reset needs two different administrators (request + approval). If only one administrator exists or all are locked out, use the server-side break-glass tool described in docs/SETUP_AND_SECURITY.md.</p>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
