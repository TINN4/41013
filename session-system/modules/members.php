<?php
/**
 * Council Members: add a member AND the member's sign-in account in one step.
 * The account role is fixed to "councilor" on the server (this page can never grant any other role), the e-mail
 * must be the member's own and is trusted only after its owner confirms it from the inbox.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authflow.php';
$user = ssms_require('members.manage');

$back = function (string $type, string $msg) { ssms_flash($type, $msg); ssms_redirect('members.php'); };
$action = ssms_post_action();
if ($action !== '') {
    if (!ssms_verify_password($user, (string)($_POST['confirm_password'] ?? ''))) {
        ssms_audit('member.op', null, 'denied', ['op' => $action, 'reason' => 'reauth_failed']);
        $back('bad', 'Your password was not correct. Nothing was changed.');
    }
    if ($action === 'add') {
        $name = ssms_text((string)($_POST['name'] ?? ''), 100);
        $position = ssms_text((string)($_POST['position'] ?? ''), 100) ?: 'City Councilor';
        $committee = ssms_text((string)($_POST['committee'] ?? ''), 100);
        $username = strtolower(ssms_text((string)($_POST['username'] ?? ''), 40));
        $email = ssms_email_for_entry((string)($_POST['email'] ?? ''));
        $quorum = !empty($_POST['eligible_for_quorum']);
        if ($name === '') $back('bad', 'Full name is required.');
        if (!preg_match('/^[a-z0-9_.-]{3,40}$/', $username)) $back('bad', 'Username must be 3-40 characters: letters, digits, dot, dash, underscore.');
        if ($email === null) $back('bad', 'Enter the member\'s own, valid e-mail address (its domain must exist and accept mail).');
        foreach (ssms_read('members') as $m) if (strcasecmp($m['name'], $name) === 0) $back('bad', 'A member with that name already exists.');
        $uRows = ssms_read('users');
        foreach ($uRows as $r) if (strcasecmp($r['username'], $username) === 0) $back('bad', 'That username already exists.');
        if (ssms_email_taken($uRows, $email)) $back('bad', 'That e-mail address already belongs to another account. Each member needs their own address.');

        $mid = ssms_insert('members', ['name' => $name, 'position' => $position, 'committee' => $committee, 'eligible_for_quorum' => $quorum]);
        [$uid, $dup] = ssms_insert_unique('users', [
            'username' => $username, 'name' => $name, 'role' => 'councilor', 'member_id' => $mid, 'password_hash' => null, 'active' => true,
            'email' => null, 'email_verified' => false, 'email_verified_at' => null, 'pending_email' => null, 'mfa' => ['email_otp' => false],
            'failed_count' => 0, 'session_version' => 1, 'created_at' => ssms_now(), 'last_login_at' => null,
        ], function ($rows) use ($username, $email) {
            foreach ($rows as $r) if (strcasecmp($r['username'], $username) === 0) return 'That username already exists.';
            return ssms_email_taken($rows, $email) ? 'That e-mail address already belongs to another account.' : null;
        });
        if ($dup) { ssms_delete('members', $mid); $back('bad', $dup); }          // lost a race: leave nothing half-created
        ssms_audit('member.added', $mid, 'ok', ['user_id' => $uid, 'role' => 'councilor']);
        ssms_audit('user.created', $uid, 'ok', ['username' => $username, 'role' => 'councilor', 'via' => 'members']);
        ssms_email_begin_verification($uid, $email, (int)$user['id'], false, false);
        $st = ssms_send_password_link($uid, 'invite');
        ssms_audit('user.invitation', $uid, $st === 'sent' ? 'ok' : 'failed', ['status' => $st]);
        if ($st === 'sent') $back('ok', "Member added. An invitation was e-mailed to " . ssms_mask_email($email) . ". The e-mail counts as verified only after the member opens it and sets a password.");
        $back('warn', 'Member added, but the invitation could not be delivered (' . ssms_email_status_message($st)[1] . ') Use "Resend invitation" once e-mail works.');
    }
    $target = ssms_find('users', (int)($_POST['user_id'] ?? 0));
    if (!$target || ($target['role'] ?? '') !== 'councilor' || empty($target['member_id'])) $back('bad', 'Unknown member account.');   // this page only ever touches councilor accounts
    if ($action === 'resend') {
        $st = ssms_send_password_link((int)$target['id'], !empty($target['password_hash']) ? 'reset' : 'invite');
        ssms_audit('user.password_link_sent', $target['id'], $st === 'sent' ? 'ok' : 'failed', ['status' => $st, 'via' => 'members']);
        [$t, $m] = ssms_email_status_message($st, (string)($target['email'] ?? $target['pending_email']['address'] ?? '')); $back($t, $m);
    } elseif ($action === 'change_email') {
        $email = ssms_email_for_entry((string)($_POST['new_email'] ?? ''));
        if ($email === null) $back('bad', 'Enter a valid e-mail address (its domain must exist and accept mail).');
        $st = ssms_email_begin_verification((int)$target['id'], $email, (int)$user['id'], !empty($_POST['revoke_current']));
        ssms_audit('email.change_applied', $target['id'], $st === 'sent' ? 'ok' : 'failed', ['status' => $st, 'via' => 'members']);
        [$t, $m] = ssms_email_status_message($st, $email); $back($t, $m);
    }
    $back('bad', 'Unknown action.');
}

$members = ssms_read('members'); $byMember = [];
foreach (ssms_read('users') as $u) if (!empty($u['member_id'])) $byMember[(int)$u['member_id']] = $u;
$page_title = 'Council Members'; $page_sub = 'Add a member together with their own sign-in e-mail'; $active_page = 'members';
include __DIR__ . '/../includes/header.php';
$mailOk = ssms_mail_configured() && ssms_app_url() !== null;
?>
<?php if (!$mailOk): ?><div class="alert alert-warn" role="alert"><i class="fa-solid fa-triangle-exclamation"></i> E-mail delivery is not configured, so invitations cannot be sent. See docs/EMAIL_OTP_SETUP.md.</div><?php endif; ?>
<div class="card">
  <div class="card-head"><div><h3>Add council member</h3><p>Enter the member's <strong>own</strong> e-mail. A real, existing domain is required, and the address only becomes trusted once the member opens the invitation sent there. The account is created as <strong>Council Member</strong>; no other role can be chosen here.</p></div></div>
  <form method="POST" autocomplete="off">
    <?= ssms_csrf_field() ?><input type="hidden" name="action" value="add">
    <div class="form-row three">
      <div class="form-group"><label for="m-name">Full name</label><input id="m-name" name="name" maxlength="100" placeholder="Hon. Juan Dela Cruz" required></div>
      <div class="form-group"><label for="m-pos">Position</label><input id="m-pos" name="position" maxlength="100" value="City Councilor"></div>
      <div class="form-group"><label for="m-com">Committee</label><input id="m-com" name="committee" maxlength="100"></div>
    </div>
    <div class="form-row three">
      <div class="form-group"><label for="m-user">Username</label><input id="m-user" name="username" maxlength="40" required></div>
      <div class="form-group"><label for="m-mail">E-mail (member's own)</label><input id="m-mail" name="email" type="email" maxlength="254" required></div>
      <div class="form-group"><label for="m-pw">Your password (required)</label><input id="m-pw" name="confirm_password" type="password" autocomplete="current-password" required></div>
    </div>
    <label style="font-size:13px;"><input type="checkbox" name="eligible_for_quorum" value="1" checked> Counts toward quorum</label><br><br>
    <button class="btn btn-navy" type="submit"><i class="fa-solid fa-user-plus"></i> Add member and e-mail invitation</button>
  </form>
</div>
<div class="card">
  <div class="card-head"><div><h3>Members</h3><p><?= count($members) ?> member(s)</p></div></div>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Name</th><th>Position / committee</th><th>Account &amp; e-mail</th><th>Manage</th></tr></thead><tbody>
    <?php foreach ($members as $m): $u = $byMember[(int)$m['id']] ?? null; ?>
      <tr>
        <td><strong><?= ssms_e($m['name']) ?></strong></td>
        <td><?= ssms_e($m['position'] ?? '') ?><div style="font-size:11px;"><?= ssms_e($m['committee'] ?? '') ?></div></td>
        <td style="font-size:12px;">
          <?php if (!$u): ?><span class="badge badge-grey">No account</span>
          <?php else: ?><span class="mono"><?= ssms_e($u['username']) ?></span> &middot; <?= ssms_e(ssms_role_label($u['role'])) ?><br>
            <?= ssms_user_email_verified($u) ? ssms_e($u['email']) . ' <span class="badge badge-ok">Verified</span>' : '<span class="badge badge-warn">No verified e-mail</span>' ?>
            <?php if (!empty($u['pending_email']['address'])): ?><div>Pending: <?= ssms_e($u['pending_email']['address']) ?></div><?php endif; ?>
            <?php if (empty($u['password_hash'])): ?><div>Awaiting password setup</div><?php endif; ?><?php endif; ?></td>
        <td>
          <?php if ($u && $u['role'] === 'councilor'): ?>
          <form method="POST" style="display:flex;flex-wrap:wrap;gap:5px;align-items:center;">
            <?= ssms_csrf_field() ?><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
            <input type="password" name="confirm_password" placeholder="Your password" required autocomplete="current-password" aria-label="Your password" style="padding:5px 8px;border:1px solid var(--line);border-radius:7px;font-size:12px;width:120px;">
            <button class="btn btn-ghost btn-sm" name="action" value="resend">Resend invitation</button>
            <input type="email" name="new_email" maxlength="254" placeholder="New e-mail" aria-label="New e-mail" style="padding:5px 8px;border:1px solid var(--line);border-radius:7px;font-size:12px;width:150px;">
            <label style="font-size:11px;"><input type="checkbox" name="revoke_current" value="1"> old address lost</label>
            <button class="btn btn-ghost btn-sm" name="action" value="change_email">Change e-mail</button>
          </form><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?></tbody></table></div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
