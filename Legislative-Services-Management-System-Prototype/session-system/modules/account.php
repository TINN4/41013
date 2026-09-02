<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/store.php';
ssms_require_staff(); // secretary/admin only — council members get redirected to Attendance

$flash = '';
$formError = '';

// --- Change my own password --------------------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    $staffUsers = ssms_read('staff_users');
    $me = null;
    foreach ($staffUsers as $u) {
        if ($u['username'] === $_SESSION['ssms_user']) { $me = $u; break; }
    }

    if (!$me || !password_verify($current, $me['password_hash'])) {
        $formError = 'Your current password is incorrect.';
    } elseif (strlen($new) < 8) {
        $formError = 'New password must be at least 8 characters.';
    } elseif ($new !== $confirm) {
        $formError = 'New password and confirmation do not match.';
    } else {
        ssms_update('staff_users', $me['id'], ['password_hash' => password_hash($new, PASSWORD_DEFAULT)]);
        $flash = 'Your password was updated.';
    }
}

// --- Add a new staff account --------------------------------------------
// Admin-only: a Secretary account can do the day-to-day session work, but
// can't create new logins for other people.
if (isset($_POST['action']) && $_POST['action'] === 'add_staff' && ssms_is_admin()) {
    $username = trim($_POST['username'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'secretary';

    $staffUsers = ssms_read('staff_users');
    $taken = array_filter($staffUsers, fn($u) => strcasecmp($u['username'], $username) === 0);

    if (!$username || !$name) {
        $formError = 'Username and display name are required.';
    } elseif (!preg_match('/^[a-zA-Z0-9._-]{3,32}$/', $username)) {
        $formError = 'Username must be 3-32 characters: letters, numbers, dot, underscore, or hyphen only.';
    } elseif ($taken) {
        $formError = 'That username is already taken.';
    } elseif (strlen($password) < 8) {
        $formError = 'Password must be at least 8 characters.';
    } else {
        ssms_insert('staff_users', [
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'name' => $name,
            'role' => $role,
        ]);
        $flash = 'Staff account "' . $username . '" created as ' . ($role === 'admin' ? 'an Admin' : 'a Secretary') . '.';
    }
}

// --- Reset a locked-out colleague's password (Admin-only) ---------------
// This is the actual fix for "the secretary forgot their password": any
// OTHER logged-in Admin can generate a fresh temporary password for them
// without needing to know their old one. The generated password is shown
// once, right here, for the admin to relay to that person directly — it's
// never emailed or stored in plain text anywhere.
if (isset($_POST['action']) && $_POST['action'] === 'reset_password' && ssms_is_admin() && isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    $target = null;
    foreach (ssms_read('staff_users') as $u) { if ((int)$u['id'] === $id) { $target = $u; break; } }
    if (!$target) {
        $formError = 'Account not found.';
    } else {
        $tempPassword = bin2hex(random_bytes(5)); // 10 hex chars, easy enough to read aloud/type once
        ssms_update('staff_users', $id, ['password_hash' => password_hash($tempPassword, PASSWORD_DEFAULT)]);
        // Plain text on purpose — $flash is rendered through
        // htmlspecialchars() (see the alert div below), which is
        // deliberate defense-in-depth against future edits accidentally
        // introducing unescaped data. Keeping this message plain text
        // rather than reaching for bold/monospace styling respects that.
        $flash = 'New temporary password for "' . $target['username'] . '": ' . $tempPassword
                . ' — copy this now and give it to them directly. It only shows once; they should change it themselves from this same page after logging in.';
    }
}

// --- Promote/demote a staff account's role (Admin-only) ------------------
if (isset($_POST['action']) && $_POST['action'] === 'change_role' && ssms_is_admin() && isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    $staffUsers = ssms_read('staff_users');
    $target = null;
    foreach ($staffUsers as $u) { if ((int)$u['id'] === $id) { $target = $u; break; } }
    $newRole = ($_POST['new_role'] ?? '') === 'admin' ? 'admin' : 'secretary';

    if (!$target) {
        $formError = 'Account not found.';
    } else {
        $currentAdminCount = count(array_filter($staffUsers, fn($u) => ($u['role'] ?? 'admin') === 'admin'));
        $targetIsCurrentlyAdmin = ($target['role'] ?? 'admin') === 'admin';
        // The one hard rule: the system must always have at least one
        // Admin. Demoting the only remaining Admin — even themselves —
        // would mean nobody left could create accounts, reset a forgotten
        // password, or promote anyone back. Block only that specific case;
        // any other promote/demote is allowed freely, including an Admin
        // stepping down from their own role if other Admins still exist.
        if ($targetIsCurrentlyAdmin && $newRole === 'secretary' && $currentAdminCount <= 1) {
            $formError = 'Cannot remove the last Admin — promote someone else to Admin first.';
        } else {
            ssms_update('staff_users', $id, ['role' => $newRole]);
            $flash = $target['name'] . ' is now ' . ($newRole === 'admin' ? 'an Admin' : 'a Secretary') . '.';
        }
    }
}

// --- Remove a staff account ----------------------------------------------
// Admin-only, same reasoning as add_staff above.
if (isset($_POST['action']) && $_POST['action'] === 'remove_staff' && ssms_is_admin() && isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    $staffUsers = ssms_read('staff_users');
    $target = null;
    foreach ($staffUsers as $u) { if ((int)$u['id'] === $id) { $target = $u; break; } }

    if (!$target) {
        $formError = 'Account not found.';
    } elseif ($target['username'] === $_SESSION['ssms_user']) {
        $formError = 'You cannot remove the account you are currently logged in as.';
    } elseif (count($staffUsers) <= 1) {
        $formError = 'Cannot remove the last remaining staff account — the system would become inaccessible.';
    } else {
        $targetIsAdmin = ($target['role'] ?? 'admin') === 'admin';
        $adminCount = count(array_filter($staffUsers, fn($u) => ($u['role'] ?? 'admin') === 'admin'));
        if ($targetIsAdmin && $adminCount <= 1) {
            // Same "always keep one Admin" rule as change_role above —
            // removing the account entirely is just a more permanent
            // version of demoting it, so it needs the same guard.
            $formError = 'Cannot remove the last remaining Admin — promote someone else to Admin first.';
        } else {
            ssms_delete('staff_users', $id);
            $flash = 'Staff account removed.';
        }
    }
}

$staffUsers = ssms_read('staff_users');
usort($staffUsers, fn($a, $b) => strcmp($a['username'], $b['username']));
$adminCount = count(array_filter($staffUsers, fn($u) => ($u['role'] ?? 'admin') === 'admin'));
$isAdmin = ssms_is_admin();

$page_title = 'Account Settings';
$page_sub   = 'Manage your password and staff accounts';
$active_page = 'account';
include __DIR__ . '/../includes/header.php';
?>

<?php if ($flash): ?><div class="alert alert-ok"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($flash, ENT_QUOTES) ?></div><?php endif; ?>
<?php if ($formError): ?><div class="alert alert-warn"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($formError, ENT_QUOTES) ?></div><?php endif; ?>

<div class="card">
  <div class="card-head"><div><h3>Change My Password</h3><p>Signed in as <strong><?= htmlspecialchars($_SESSION['ssms_user'], ENT_QUOTES) ?></strong> &middot; <?= $isAdmin ? 'Admin' : 'Secretary' ?></p></div></div>
  <form method="POST" style="padding:16px;">
    <?php ssms_csrf_field(); ?>
    <input type="hidden" name="action" value="change_password">
    <div class="form-row three">
      <div class="form-group"><label>Current Password</label><input type="password" name="current_password" autocomplete="current-password" required></div>
      <div class="form-group"><label>New Password</label><input type="password" name="new_password" autocomplete="new-password" minlength="8" required></div>
      <div class="form-group"><label>Confirm New Password</label><input type="password" name="confirm_password" autocomplete="new-password" minlength="8" required></div>
    </div>
    <button type="submit" class="btn btn-navy"><i class="fa-solid fa-key"></i> Update Password</button>
  </form>
</div>

<?php if (!$isAdmin): ?>
<div class="card">
  <div class="empty-state">
    <i class="fa-solid fa-lock"></i>
    Only Admin accounts can add, remove, or manage other staff accounts.<br>
    If you're locked out of something or need a colleague's password reset, ask an Admin.
  </div>
</div>
<?php else: ?>

<div class="card">
  <div class="card-head"><div><h3>Add a Staff Account</h3><p>For another secretary, assistant, or administrator</p></div></div>
  <form method="POST" style="padding:16px;">
    <?php ssms_csrf_field(); ?>
    <input type="hidden" name="action" value="add_staff">
    <div class="form-row three">
      <div class="form-group"><label>Username</label><input type="text" name="username" placeholder="e.g. secretary2" required></div>
      <div class="form-group"><label>Display Name</label><input type="text" name="name" placeholder="e.g. Assistant Secretary's Office" required></div>
      <div class="form-group"><label>Temporary Password</label><input type="password" name="password" minlength="8" placeholder="At least 8 characters" required></div>
    </div>
    <div class="form-group" style="max-width:260px;margin-top:10px;">
      <label>Role</label>
      <select name="role">
        <option value="secretary">Secretary &mdash; day-to-day session work</option>
        <option value="admin">Admin &mdash; can also manage staff accounts</option>
      </select>
    </div>
    <button type="submit" class="btn btn-navy" style="margin-top:10px;"><i class="fa-solid fa-user-plus"></i> Create Account</button>
    <p style="font-size:12px;color:var(--ink-600);margin-top:8px;">Give them this username and temporary password directly — they should change it from this same page after their first login.</p>
  </form>
</div>

<div class="card">
  <div class="card-head"><div><h3>Existing Staff Accounts</h3><p><?= count($staffUsers) ?> account<?= count($staffUsers) === 1 ? '' : 's' ?> &middot; <?= $adminCount ?> Admin<?= $adminCount === 1 ? '' : 's' ?></p></div></div>
  <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Username</th><th>Display Name</th><th>Role</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($staffUsers as $u): $uIsAdmin = ($u['role'] ?? 'admin') === 'admin'; $isSelf = $u['username'] === $_SESSION['ssms_user']; ?>
        <tr>
          <td><?= htmlspecialchars($u['username'], ENT_QUOTES) ?><?= $isSelf ? ' <span class="badge badge-blue" style="font-size:10px;">You</span>' : '' ?></td>
          <td><?= htmlspecialchars($u['name'], ENT_QUOTES) ?></td>
          <td><span class="badge <?= $uIsAdmin ? 'badge-gold' : 'badge-blue' ?>"><?= $uIsAdmin ? 'Admin' : 'Secretary' ?></span></td>
          <td style="white-space:nowrap;">
            <form method="POST" style="display:inline;" onsubmit="return confirm('Generate a new temporary password for &quot;<?= htmlspecialchars(addslashes($u['username']), ENT_QUOTES) ?>&quot;? Their current password will stop working immediately.');">
              <?php ssms_csrf_field(); ?>
              <input type="hidden" name="action" value="reset_password">
              <input type="hidden" name="id" value="<?= $u['id'] ?>">
              <button class="btn btn-ghost btn-sm" title="Reset password"><i class="fa-solid fa-key"></i></button>
            </form>
            <?php if (!($uIsAdmin && $adminCount <= 1)): ?>
            <form method="POST" style="display:inline;" onsubmit="return confirm('<?= $uIsAdmin ? 'Demote' : 'Promote' ?> &quot;<?= htmlspecialchars(addslashes($u['name']), ENT_QUOTES) ?>&quot; to <?= $uIsAdmin ? 'Secretary' : 'Admin' ?>?');">
              <?php ssms_csrf_field(); ?>
              <input type="hidden" name="action" value="change_role">
              <input type="hidden" name="id" value="<?= $u['id'] ?>">
              <input type="hidden" name="new_role" value="<?= $uIsAdmin ? 'secretary' : 'admin' ?>">
              <button class="btn btn-ghost btn-sm" title="<?= $uIsAdmin ? 'Demote to Secretary' : 'Promote to Admin' ?>"><i class="fa-solid <?= $uIsAdmin ? 'fa-arrow-down' : 'fa-arrow-up' ?>"></i></button>
            </form>
            <?php endif; ?>
            <?php if (!$isSelf): ?>
            <form method="POST" style="display:inline;" onsubmit="return confirm('Remove staff account &quot;<?= htmlspecialchars(addslashes($u['username']), ENT_QUOTES) ?>&quot;? They will no longer be able to log in.');">
              <?php ssms_csrf_field(); ?>
              <input type="hidden" name="action" value="remove_staff">
              <input type="hidden" name="id" value="<?= $u['id'] ?>">
              <button class="btn btn-ghost btn-sm" title="Remove account"><i class="fa-solid fa-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p style="font-size:12px;color:var(--ink-600);padding:0 16px 16px;">The last remaining Admin can't be demoted or removed — promote someone else to Admin first if you need to step down.</p>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
