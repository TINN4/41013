<?php
// Expects: $page_title, $page_sub, $active_page (before include)
$base = ssms_base();
$__flash = ssms_flash_pull();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= ssms_e($page_title ?? 'Session Management') ?> &mdash; SSMS</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="<?= $base ?>assets/style.css">
</head>
<body>
<a class="skip-link" href="#ssms-main">Skip to main content</a>
<div class="app-shell">
  <?php include __DIR__ . '/sidebar.php'; ?>

  <div class="main-col">
    <header class="topbar">
      <button class="menu-btn" id="ssms-menu-btn" type="button" aria-label="Toggle navigation menu" aria-controls="ssms-sidebar"><i class="fa-solid fa-bars"></i></button>
      <div>
        <div class="page-title"><?= ssms_e($page_title ?? '') ?></div>
        <div class="page-sub"><?= ssms_e($page_sub ?? '') ?></div>
      </div>
      <div class="spacer"></div>
      <?php if (!empty($show_live_badge)): ?>
        <span class="badge-live" id="ssms-live-badge"><span class="pulse-dot"></span> Live</span>
      <?php endif; ?>
    </header>

    <main class="content" id="ssms-main">
    <div aria-live="polite">
    <?php foreach ($__flash as $f): ?>
      <div class="alert alert-<?= $f['type'] === 'ok' ? 'ok' : ($f['type'] === 'warn' ? 'warn' : 'bad') ?>" role="<?= $f['type'] === 'bad' ? 'alert' : 'status' ?>"><i class="fa-solid <?= $f['type'] === 'ok' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i> <?= ssms_e($f['msg']) ?></div>
    <?php endforeach; ?>
    </div>
