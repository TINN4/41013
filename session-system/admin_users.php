<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/authflow.php';
$admin = ssms_require('users.manage');

function ssms_admin_count_after(array $users, int $excludeId): int {
    return count(array_filter($users, fn($u) => $u['role'] === 'sysadmin' && !empty($u['active']) && empty($u['locked']) && (int)$u['id'] !== $excludeId));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string)($_POST['action'] ?? '');
    $tid = (int)($_POST['id'] ?? 0);
    $users = ssms_read('users');
    $target = null; foreach ($users as $x) if ((int)$x['id'] === $tid) $target = $x;
    $back = function (string $type, string $msg) { ssms_flash($type, $msg); ssms_redirect('admin_users.php'); };
    $mailBack = function (string $st, string $addr = '') use ($back) { [$t, $m] = ssms_email_status_message($st, $addr); $back($t, $m); };

    // Every change here is a sensitive operation: the administrator must re-enter their own password.
    if (!ssms_verify_password($admin, (string)($_POST['confirm_password'] ?? ''))) {
        ssms_audit('admin.user_op', $tid, 'denied', ['op' => $a, 'reason' => 'reauth_failed']);
        $back('bad', 'Your password was not correct. Nothing was changed.');
    }

    if ($a === 'create') {
        $username = strtolower(ssms_text((string)($_POST['username'] ?? ''), 40));
        $name = ssms_text((string)($_POST['name'] ?? ''), 100);
        $role = (string)($_POST['role'] ?? '');
        $email = ssms_email_normalize((string)($_POST['email'] ?? ''));
        $member = (int)($_POST['member_id'] ?? 0) ?: null;
        if (!preg_match('/^[a-z0-9_.-]{3,40}$/', $username)) $back('bad', 'Username must be 3-40 characters: letters, digits, dot, dash, underscore.');
        if ($name === '') $back('bad', 'Full name is required.');
        if (!ssms_valid_role($role)) $back('bad', 'Choose a valid role.');
        if ($email === null) $back('bad', 'Enter the account holder\'s own, valid e-mail address.');
        if ($member && !ssms_find('members', $member)) $back('bad', 'Unknown council member.');
        [$id, $dup] = ssms_insert_unique('users', [
            'username' => $username, 'name' => $name, 'role' => $role, 'member_id' => $member, 'password_hash' => null, 'active' => true,
            'email' => null, 'email_verified' => false, 'email_verified_at' => null, 'pending_email' => null, 'mfa' => ['email_otp' => false],
            'failed_count' => 0, 'session_version' => 1, 'created_at' => ssms_now(), 'last_login_at' => null,
        ], function ($rows) use ($username, $email) {
            foreach ($rows as $r) if (strcasecmp($r['username'], $username) === 0) return 'That username already exists.';
            return ssms_email_taken($rows, $email) ? 'That e-mail address already belongs to another account. Each account needs its own address.' : null;
        });
        if ($dup) $back('bad', $dup);
        ssms_audit('user.created', $id, 'ok', ['username' => $username, 'role' => $role]);
        ssms_email_begin_verification($id, $email, (int)$admin['id'], false, false);     // address stays UNTRUSTED until its owner follows the e-mailed link
        $st = ssms_send_password_link($id, 'invite');
        ssms_audit('user.invitation', $id, $st === 'sent' ? 'ok' : 'failed', ['status' => $st]);
        if ($st === 'sent') $back('ok', "Account created. An invitation was e-mailed to " . ssms_mask_email($email) . ". The e-mail address becomes trusted for sign-in codes only after its owner sets a password through that link.");
        $back('warn', 'Account created, but the invitation could not be delivered (' . ssms_email_status_message($st)[1] . ') Use "Send password link" on that account once e-mail works.');
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
        if ($a === 'deactivate') ssms_otp_cancel_user($tid);
        ssms_audit($a === 'activate' ? 'user.activated' : 'user.deactivated', $tid, 'ok');
        $back('ok', $a === 'activate' ? 'Account activated.' : 'Account deactivated and signed out everywhere.');
    }
    elseif ($a === 'lock' || $a === 'unlock') {
        if ($a === 'lock' && (int)$target['id'] === (int)$admin['id']) $back('bad', 'You cannot lock your own account.');
        if ($a === 'lock' && $target['role'] === 'sysadmin' && ssms_admin_count_after($users, (int)$target['id']) < 1) $back('bad', 'This is the last usable administrator.');
        ssms_update('users', $tid, ['locked' => $a === 'lock', 'session_version' => (int)$target['session_version'] + 1]);
        if ($a === 'lock') ssms_otp_cancel_user($tid);
        if ($a === 'unlock') { ssms_rl_clear('login:u:' . strtolower($target['username'])); ssms_rl_clear('mfa:u:' . $tid); ssms_rl_clear('otp:send:u:' . $tid); }
        ssms_audit($a === 'lock' ? 'user.locked' : 'user.unlocked', $tid, 'ok');
        $back('ok', $a === 'lock' ? 'Account locked.' : 'Account unlocked and sign-in throttling cleared.');
    }
    elseif ($a === 'send_link') {                       // password setup / reset: e-mailed to the account holder, never shown to the administrator
        $st = ssms_send_password_link($tid, !empty($target['password_hash']) ? 'reset' : 'invite');
        ssms_audit('user.password_link_sent', $tid, $st === 'sent' ? 'ok' : 'failed', ['status' => $st]);
        $mailBack($st, (string)($target['email'] ?? $target['pending_email']['address'] ?? ''));
    }
    elseif ($a === 'resend_verification') {
        $st = ssms_email_resend_verification($tid);
        ssms_audit('email.verification_resent', $tid, $st === 'sent' ? 'ok' : 'failed', ['status' => $st]);
        $mailBack($st, (string)($target['pending_email']['address'] ?? ''));
    }
    elseif ($a === 'set_email') {
        $email = ssms_email_normalize((string)($_POST['new_email'] ?? ''));
        $reason = ssms_text((string)($_POST['reason'] ?? ''), 300);
        $revoke = !empty($_POST['revoke_current']);
        if ($email === null) $back('bad', 'Enter a valid e-mail address.');
        if ($reason === '') $back('bad', 'A reason is required (e.g. "old address no longer accessible, identity confirmed in person").');
        if (ssms_email_taken($users, $email, $tid)) $back('bad', 'That e-mail address already belongs to another account.');
        if (ssms_mfa_policy($target) === 'required') {  // privileged account: two different administrators, like any other recovery action
            ssms_update('users', $tid, ['email_change_request' => ['by' => (int)$admin['id'], 'at' => ssms_now(), 'reason' => $reason, 'address' => $email, 'revoke' => $revoke]]);
            ssms_audit('email.change_requested', $tid, 'ok', ['reason' => $reason, 'revoke_current' => $revoke]);
            $back('ok', 'E-mail change requested for a privileged account. A DIFFERENT administrator must approve it.');
        }
        $st = ssms_email_begin_verification($tid, $email, (int)$admin['id'], $revoke);
        ssms_audit('email.change_applied', $tid, $st === 'sent' ? 'ok' : 'failed', ['status' => $st, 'reason' => $reason, 'revoke_current' => $revoke]);
        $mailBack($st, $email);
    }
    elseif ($a === 'approve_email_change') {
        $rq = $target['email_change_request'] ?? null;
        if (!$rq) $back('bad', 'No pending request.');
        if ((int)$rq['by'] === (int)$admin['id']) { ssms_audit('email.change_approved', $tid, 'denied', ['reason' => 'self_approval']); $back('bad', 'You cannot approve your own request. Ask another administrator.'); }
        ssms_update('users', $tid, ['email_change_request' => null]);
        $st = ssms_email_begin_verification($tid, $rq['address'], (int)$admin['id'], !empty($rq['revoke']));
        ssms_audit('email.change_approved', $tid, $st === 'sent' ? 'ok' : 'failed', ['requested_by' => $rq['by'], 'status' => $st, 'revoke_current' => !empty($rq['revoke'])]);
        $mailBack($st, $rq['address']);
    }
    elseif ($a === 'cancel_email_change') {
        ssms_update('users', $tid, ['email_change_request' => null]);
        ssms_audit('email.change_cancelled', $tid, 'ok');
        $back('ok', 'Request cancelled.');
    }
}

$users = ssms_read('users');
$members = ssms_read('members');
$page_title = 'User Accounts'; $page_sub = 'Roles, access status, e-mail ownership and recovery (administrators cannot approve legislative records)'; $active_page = 'admin_users';
include __DIR__ . '/includes/header.php';
$mailOk = ssms_mail_configured() && ssms_app_url() !== null && ssms_otp_key() !== null;
?>
<?php if (!$mailOk): ?>
<div class="alert alert-warn" role="alert"><i class="fa-solid fa-triangle-exclamation"></i> E-mail delivery is not fully configured (SMTP settings, SSMS_APP_URL or SSMS_APP_KEY). Invitations and sign-in codes cannot be sent, so accounts that require a code cannot sign in. See docs/EMAIL_OTP_SETUP.md.</div>
<?php endif; ?>

<div class="card">
  <div class="card-head"><div><h3>Create account</h3><p>Enter the account holder's <strong>own</strong> e-mail address. No password is set by you: an invitation is e-mailed to that address, and the holder chooses a password through it. Sign-in codes are only ever sent to an address its owner has confirmed.</p></div></div>
  <form method="POST" autocomplete="off">
    <?= ssms_csrf_field() ?><input type="hidden" name="action" value="create">
    <div class="form-row three">
      <div class="form-group"><label for="nu">Username</label><input id="nu" name="username" maxlength="40" required></div>
      <div class="form-group"><label for="nn">Full name</label><input id="nn" name="name" maxlength="100" required></div>
      <div class="form-group"><label for="ne">E-mail (account holder's own)</label><input id="ne" name="email" type="email" maxlength="254" required></div>
    </div>
    <div class="form-row three">
      <div class="form-group"><label for="nr">Role</label><select id="nr" name="role"><?php foreach (SSMS_ROLES as $k => $l): ?><option value="<?= ssms_e($k) ?>"><?= ssms_e($l) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label for="nm">Linked council member (councilors / presiding officer)</label><select id="nm" name="member_id"><option value="">None</option><?php foreach ($members as $m): ?><option value="<?= (int)$m['id'] ?>"><?= ssms_e($m['name']) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label for="np">Your password (required)</label><input type="password" id="np" name="confirm_password" autocomplete="current-password" required></div>
    </div>
    <button class="btn btn-navy" type="submit"><i class="fa-solid fa-user-plus"></i> Create account and e-mail invitation</button>
  </form>
</div>

<div class="card">
  <div class="card-head"><div><h3>All accounts</h3><p><?= count($users) ?> account(s). Dev/test accounts are marked and should be removed before real use.</p></div></div>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>User</th><th>Role</th><th>Status</th><th>E-mail &amp; sign-in code</th><th>Last sign-in</th><th>Manage</th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): $self = (int)$u['id'] === (int)$admin['id']; $rq = $u['email_change_request'] ?? null; $gate = ssms_mfa_gate($u); $pe = $u['pending_email']['address'] ?? null; ?>
      <tr>
        <td><strong><?= ssms_e($u['name']) ?></strong><div class="mono" style="font-size:11px;"><?= ssms_e($u['username']) ?><?= !empty($u['is_dev_account']) ? ' &middot; DEV' : '' ?></div></td>
        <td><?= ssms_e(ssms_role_label($u['role'])) ?></td>
        <td><?php if (empty($u['active'])): ?><span class="badge badge-bad">Deactivated</span><?php elseif (!empty($u['locked'])): ?><span class="badge badge-warn">Locked</span><?php else: ?><span class="badge badge-ok">Active</span><?php endif; ?>
            <?php if (empty($u['password_hash'])): ?><div style="font-size:11px;">Awaiting password setup</div><?php endif; ?></td>
        <td style="font-size:12px;">
          <?php if (ssms_user_email_verified($u)): ?><?= ssms_e($u['email']) ?> <span class="badge badge-ok">Verified</span>
          <?php else: ?><span class="badge badge-warn">No verified e-mail</span><?php endif; ?>
          <?php if ($pe): ?><div>Pending: <?= ssms_e($pe) ?> <span class="badge badge-grey">Unverified</span></div><?php endif; ?>
          <div style="margin-top:3px;"><?= $gate === 'otp' ? '<span class="badge badge-ok">E-mail code required</span>' : ($gate === 'blocked' ? '<span class="badge badge-bad">Sign-in blocked until e-mail verified</span>' : '<span class="badge badge-grey">Password only</span>') ?>
            <span style="font-size:11px;">policy: <?= ssms_e(ssms_mfa_policy($u)) ?></span></div>
          <?php if ($rq): ?><div style="color:var(--warn-600);">E-mail change requested &rarr; <?= ssms_e($rq['address']) ?><?= !empty($rq['revoke']) ? ' (current address revoked)' : '' ?>: <?= ssms_e($rq['reason']) ?></div><?php endif; ?></td>
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
            <button class="btn btn-ghost btn-sm" name="action" value="send_link" title="E-mails a one-time link to the account holder">E-mail password link</button>
            <?php if ($pe): ?><button class="btn btn-ghost btn-sm" name="action" value="resend_verification">Resend verification</button><?php endif; ?>
            <?php if ($rq): ?>
              <button class="btn btn-gold btn-sm" name="action" value="approve_email_change">Approve e-mail change</button>
              <button class="btn btn-ghost btn-sm" name="action" value="cancel_email_change">Cancel request</button>
            <?php else: ?>
              <input type="email" name="new_email" maxlength="254" placeholder="New e-mail" aria-label="New e-mail address for <?= ssms_e($u['name']) ?>" style="padding:5px 8px;border:1px solid var(--line);border-radius:7px;font-size:12px;width:160px;">
              <input type="text" name="reason" maxlength="300" placeholder="Reason" aria-label="Reason for e-mail change" style="padding:5px 8px;border:1px solid var(--line);border-radius:7px;font-size:12px;width:130px;">
              <label style="font-size:11px;"><input type="checkbox" name="revoke_current" value="1"> current address lost</label>
              <button class="btn btn-ghost btn-sm" name="action" value="set_email">Change e-mail</button>
            <?php endif; ?>
          </form>
          <?php else: ?><span style="font-size:12px;color:var(--ink-600);">This is you &mdash; manage your e-mail under My Security.</span><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <p style="font-size:12px;color:var(--ink-600);margin-top:10px;">A new or changed address is trusted only after its owner confirms it from their inbox. Changing the address of a Secretary, System Administrator or Presiding Officer needs a request by one administrator and approval by a different one. If no administrator can sign in, use the server-side tool described in docs/EMAIL_OTP_SETUP.md (recovery section).</p>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
