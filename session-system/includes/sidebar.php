<?php
// Navigation shows only what the signed-in role may open. This is a usability aid;
// the real enforcement is ssms_require() on every page and API.
$base = ssms_base();
$__u = ssms_current_user();
$__nav = []; $__admin = [];
if ($__u) {
    foreach (ssms_modules() as $k => $m)       if (ssms_can_any($__u, $m['perm'])) $__nav[$k] = $m;
    foreach (ssms_admin_modules() as $k => $m) if (ssms_can_any($__u, $m['perm'])) $__admin[$k] = $m;
}
?>
<div class="sidebar-backdrop" id="ssms-backdrop"></div>
<aside class="sidebar" id="ssms-sidebar">
  <a class="sidebar-brand" href="<?= $base ?>dashboard.php" style="cursor:pointer;">
    <div class="seal"><i class="fa-solid fa-landmark-dome"></i></div>
    <div class="txt">
      <p>SP San Jose del Monte</p>
      <p>Session Management</p>
    </div>
  </a>

  <nav class="sidebar-nav" aria-label="Main navigation">
    <?php if ($__u): ?>
    <a class="nav-item <?= ($active_page ?? '') === 'dashboard' ? 'active' : '' ?>" href="<?= $base ?>dashboard.php"><i class="fa-solid fa-gauge"></i> My Dashboard</a>
    <?php endif; ?>
    <?php foreach ($__nav as $key => $item): ?>
      <a class="nav-item <?= ($active_page ?? '') === $key ? 'active' : '' ?>" href="<?= $base . $item['file'] ?>"<?= ($active_page ?? '') === $key ? ' aria-current="page"' : '' ?>>
        <i class="<?= $item['icon'] ?>"></i> <?= ssms_e($item['label']) ?>
      </a>
    <?php endforeach; ?>
    <?php if ($__admin): ?>
      <div class="sidebar-section-label">Administration</div>
      <?php foreach ($__admin as $key => $item): ?>
        <a class="nav-item <?= ($active_page ?? '') === $key ? 'active' : '' ?>" href="<?= $base . $item['file'] ?>"<?= ($active_page ?? '') === $key ? ' aria-current="page"' : '' ?>>
          <i class="<?= $item['icon'] ?>"></i> <?= ssms_e($item['label']) ?>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>
  </nav>

  <div class="sidebar-foot">
    <div class="sidebar-user">
      <div class="av"><?= ssms_e(strtoupper(substr($__u['name'] ?? 'U', 0, 1))) ?></div>
      <div>
        <p class="name"><?= ssms_e($__u['name'] ?? '') ?></p>
        <p class="role"><?= ssms_e(ssms_role_label($__u['role'] ?? '')) ?></p>
      </div>
      <a class="logout-link" href="<?= $base ?>my_security.php" title="My security (e-mail, sign-in code &amp; password)" aria-label="My security settings" style="margin-left:auto;margin-right:4px;"><i class="fa-solid fa-shield-halved"></i></a>
      <form method="POST" action="<?= $base ?>logout.php" style="display:inline;margin:0;">
        <?= ssms_csrf_field() ?>
        <button type="submit" class="logout-link" title="Log out" aria-label="Log out" style="background:none;border:0;cursor:pointer;"><i class="fa-solid fa-arrow-right-from-bracket"></i></button>
      </form>
    </div>
  </div>
</aside>
