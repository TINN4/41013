<?php
// Expects: $page_title, $page_sub, $active_page (before include)
$base = (strpos($_SERVER['SCRIPT_NAME'], '/modules/') !== false) ? '../' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($page_title ?? 'Session Management', ENT_QUOTES, 'UTF-8') ?> &mdash; SSMS</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="<?= $base ?>assets/style.css?v=<?= filemtime(__DIR__ . '/../assets/style.css') ?>">
<script>
// Applying the saved theme here, before <body> and before the CSS has
// even finished loading, means the page never flashes light-then-dark
// on load — this runs synchronously, ahead of first paint. The actual
// toggle click handler lives in assets/app.js, loaded normally at the
// bottom of the page; it doesn't need to run this early.
(function () {
  try {
    var saved = localStorage.getItem('ssms_theme');
    if (saved === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
  } catch (e) { /* localStorage unavailable (private mode, etc.) — just default to light */ }
})();
</script>
</head>
<body>
<div class="app-shell">
  <?php include __DIR__ . '/sidebar.php'; ?>

  <div class="main-col">
    <header class="topbar">
      <button class="menu-btn" id="ssms-menu-btn"><i class="fa-solid fa-bars"></i></button>
      <div>
        <div class="page-title"><?= htmlspecialchars($page_title ?? '', ENT_QUOTES, 'UTF-8') ?></div>
        <div class="page-sub"><?= htmlspecialchars($page_sub ?? '', ENT_QUOTES, 'UTF-8') ?></div>
      </div>
      <div class="spacer"></div>
      <?php if (!empty($show_live_badge)): ?>
        <span class="badge-live" id="ssms-live-badge-wrap"><span class="pulse-dot"></span> <span id="ssms-live-badge">Live</span></span>
      <?php endif; ?>
      <button class="theme-toggle" id="ssms-theme-toggle" title="Toggle dark mode" aria-label="Toggle dark mode">
        <i class="fa-solid fa-moon"></i><i class="fa-solid fa-sun"></i>
      </button>
    </header>

    <main class="content">
