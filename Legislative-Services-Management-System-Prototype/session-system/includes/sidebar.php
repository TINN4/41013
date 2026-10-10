<?php
// Expects $active_page to be set by the including page (e.g. 'scheduling')
$nav_items = [
    'scheduling'   => ['icon' => 'fa-solid fa-calendar-check', 'label' => 'Session Scheduling',        'file' => 'modules/scheduling.php'],
    'agenda'       => ['icon' => 'fa-solid fa-list-check',     'label' => 'Agenda Preparation',        'file' => 'modules/agenda.php'],
    'members'      => ['icon' => 'fa-solid fa-users',          'label' => 'Council Members',           'file' => 'modules/members.php'],
    'attendance'   => ['icon' => 'fa-solid fa-user-check',     'label' => 'Attendance & Quorum',       'file' => 'modules/attendance.php'],
    'proceedings'  => ['icon' => 'fa-solid fa-file-lines',     'label' => 'Proceedings Documentation', 'file' => 'modules/proceedings.php'],
    'minutes'      => ['icon' => 'fa-solid fa-file-signature', 'label' => 'Minutes Generation',        'file' => 'modules/minutes.php'],
    'tracking'     => ['icon' => 'fa-solid fa-tower-broadcast','label' => 'Real-Time Tracking',        'file' => 'modules/tracking.php'],
    'archive'      => ['icon' => 'fa-solid fa-box-archive',    'label' => 'Session Archive',           'file' => 'modules/archive.php'],
];
$base = (strpos($_SERVER['SCRIPT_NAME'], '/modules/') !== false) ? '../' : '';
$isStaff = strpos($_SESSION['ssms_user'] ?? '', 'qr:') !== 0;
// Council members only get the pages relevant to attending a session —
// everything staff-only is hidden from the menu (and still blocked by
// ssms_require_staff() even if someone types the URL directly).
$visibleKeys = $isStaff
    ? ['scheduling','agenda','members','attendance','proceedings','minutes','tracking','archive']
    : ['attendance','tracking'];
$homeFile = $isStaff ? 'modules/scheduling.php' : 'modules/attendance.php';
?>
<div class="sidebar-backdrop" id="ssms-backdrop"></div>
<aside class="sidebar" id="ssms-sidebar">
  <a class="sidebar-brand" href="<?= $base . $homeFile ?>" style="cursor:pointer;">
    <div class="seal"><i class="fa-solid fa-landmark-dome"></i></div>
    <div class="txt">
      <p>SP San Jose del Monte</p>
      <p>Session Management</p>
    </div>
  </a>

  <nav class="sidebar-nav">
    <?php foreach ($visibleKeys as $key): $item = $nav_items[$key]; ?>
      <a class="nav-item <?= $active_page === $key ? 'active' : '' ?>" href="<?= $base . $item['file'] ?>">
        <i class="<?= $item['icon'] ?>"></i> <?= $item['label'] ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar-foot">
    <div class="sidebar-user">
      <div class="av"><?= strtoupper(substr($_SESSION['ssms_name'] ?? 'A', 0, 1)) ?></div>
      <div>
        <p class="name"><?= htmlspecialchars($_SESSION['ssms_name'] ?? 'Admin', ENT_QUOTES, 'UTF-8') ?></p>
        <p class="role"><?= (strpos($_SESSION['ssms_user'] ?? '', 'qr:') === 0) ? 'Council Member' : 'Session Administrator' ?></p>
      </div>
      <?php if (strpos($_SESSION['ssms_user'] ?? '', 'qr:') !== 0): ?>
        <a class="logout-link" href="<?= $base ?>manage_devices.php" title="Manage QR Badges & Devices" style="margin-left:auto;margin-right:4px;"><i class="fa-solid fa-qrcode"></i></a>
        <a class="logout-link" href="<?= $base ?>modules/account.php" title="Account Settings" style="margin-right:4px;"><i class="fa-solid fa-gear"></i></a>
      <?php endif; ?>
      <a class="logout-link" href="<?= $base ?>logout.php" title="Log out" style="<?= (strpos($_SESSION['ssms_user'] ?? '', 'qr:') === 0) ? 'margin-left:auto;' : '' ?>"><i class="fa-solid fa-arrow-right-from-bracket"></i></a>
    </div>
  </div>
</aside>
