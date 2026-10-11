<?php
require_once __DIR__ . '/includes/auth.php';
$user = ssms_require();
$sessions = ssms_read('sessions');
usort($sessions, fn($a, $b) => strcmp($a['date'] . $a['time'], $b['date'] . $b['time']));
$ongoing = array_values(array_filter($sessions, fn($s) => $s['status'] === 'Ongoing'));
$upcoming = array_values(array_filter($sessions, fn($s) => $s['status'] === 'Scheduled'));
$mods = array_filter(ssms_modules(), fn($m) => ssms_can_any($user, $m['perm']));
$admin = array_filter(ssms_admin_modules(), fn($m) => ssms_can_any($user, $m['perm']));

$todo = [];
if (ssms_can($user, 'minutes.approve')) {
    foreach (ssms_read('minutes') as $m) if (($m['status'] ?? '') === 'PendingReview') $todo[] = 'Minutes awaiting your approval: session #' . (int)$m['session_id'];
}
if (ssms_can($user, 'minutes.submit')) {
    foreach (ssms_read('minutes') as $m) if (($m['status'] ?? '') === 'Draft') $todo[] = 'Draft minutes not yet submitted: session #' . (int)$m['session_id'];
}
$page_title = 'My Dashboard'; $page_sub = ssms_role_label($user['role']); $active_page = 'dashboard';
include __DIR__ . '/includes/header.php';
?>
<?php if (!ssms_user_email_verified($user)): ?>
  <div class="notice-box"><i class="fa-solid fa-envelope"></i> No verified e-mail address is on file for your account. <a href="my_security.php">Add and verify one</a><?= ssms_mfa_policy($user) === 'required' ? ' &mdash; it is required for your role' : ' to use e-mail sign-in codes' ?>.</div>
<?php elseif (ssms_mfa_policy($user) === 'optional' && !ssms_mfa_enabled($user)): ?>
  <div class="notice-box"><i class="fa-solid fa-shield-halved"></i> E-mail sign-in codes are optional for your role but recommended. <a href="my_security.php">Turn them on</a>.</div>
<?php endif; ?>
<div class="grid grid-3">
  <div class="card"><div class="card-head"><div><h3>Now in session</h3></div></div>
    <?php if ($ongoing): foreach ($ongoing as $s): ?><p><span class="badge badge-gold">Ongoing</span> <?= ssms_e($s['title']) ?><br><small><?= ssms_e($s['date']) ?> &middot; <?= ssms_e($s['venue']) ?></small></p><?php endforeach; else: ?><p class="empty-state" style="padding:8px 0;">No session is currently ongoing.</p><?php endif; ?></div>
  <div class="card"><div class="card-head"><div><h3>Upcoming</h3></div></div>
    <?php foreach (array_slice($upcoming, 0, 3) as $s): ?><p><span class="badge badge-blue">Scheduled</span> <?= ssms_e($s['title']) ?><br><small><?= ssms_e($s['date']) ?> &middot; <?= ssms_e($s['time']) ?></small></p><?php endforeach; if (!$upcoming): ?><p class="empty-state" style="padding:8px 0;">Nothing scheduled.</p><?php endif; ?></div>
  <div class="card"><div class="card-head"><div><h3>For your attention</h3></div></div>
    <?php foreach ($todo as $t): ?><p><i class="fa-solid fa-circle-exclamation"></i> <?= ssms_e($t) ?></p><?php endforeach; if (!$todo): ?><p class="empty-state" style="padding:8px 0;">Nothing pending.</p><?php endif; ?></div>
</div>
<div class="card" style="margin-top:18px;"><div class="card-head"><div><h3>What you can open</h3><p>Based on your role: <?= ssms_e(ssms_role_label($user['role'])) ?></p></div></div>
  <div class="grid grid-3">
  <?php foreach (array_merge($mods, $admin) as $m): ?>
    <a class="stat stat-link" href="<?= ssms_e($m['file']) ?>"><div class="num"><i class="<?= ssms_e($m['icon']) ?>"></i></div><div class="lbl"><?= ssms_e($m['label']) ?></div></a>
  <?php endforeach; ?></div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
