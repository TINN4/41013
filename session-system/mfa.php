<?php
session_start();
require_once __DIR__ . '/includes/mfa.php';

if (!empty($_SESSION['ssms_user'])) { header('Location: modules/scheduling.php'); exit(); }
$pending = ssms_pending();
if (!$pending) { header('Location: login.php'); exit(); }
if (isset($_GET['cancel'])) { unset($_SESSION['ssms_pending']); header('Location: login.php'); exit(); }

$uid = $pending['uid']; $error = ''; $info = '';
$wait = mfa_locked_for($uid);

if (empty($pending['sentAt']) && !$wait) {
    [$ok, $msg] = mfa_send_code($uid, $pending['name']);
    if ($ok) $info = $msg; else $error = $msg;
    $pending = $_SESSION['ssms_pending'];
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$wait) {
    if (isset($_POST['resend'])) {
        [$ok, $msg] = mfa_send_code($uid, $pending['name']);
        if ($ok) $info = $msg; else $error = $msg;
        $pending = $_SESSION['ssms_pending'];
    } else {
        $r = mfa_check_code($_POST['code'] ?? '');
        if ($r === 'ok') { mfa_clear_fails($uid); ssms_complete_login(); header('Location: modules/scheduling.php'); exit(); }
        if ($r === 'expired') { $error = 'That code has expired or was already used. Request a new one.'; }
        else { mfa_fail($uid); $wait = mfa_locked_for($uid); $error = $wait ? 'Too many incorrect codes.' : 'That code is not correct. Please check your email and try again.'; }
    }
}
$email = $pending['email'] ?? mfa_email_for($uid);
$cool = max(0, mfa_config()['resend_cooldown'] - (time() - ($pending['sentAt'] ?? 0)));
$demoCode = null;
if (mfa_demo_mode() && is_file(SSMS_DATA_DIR . '/otp_outbox.log')) { $l = file(SSMS_DATA_DIR . '/otp_outbox.log'); $demoCode = trim(end($l)); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Email Verification &mdash; SSMS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,600;9..144,700&family=Inter:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="assets/style.css">
<style>
  .mfa-wrap { min-height:100vh; display:flex; align-items:center; justify-content:center; background:var(--navy-950); padding:24px 16px; }
  .mfa-card { width:100%; max-width:440px; background:#fff; border-radius:20px; padding:32px 28px; box-shadow:0 20px 50px rgba(10,31,61,.3); }
  .mfa-card h2 { font-size:21px; margin:0 0 4px; color:var(--navy-950); }
  .mfa-card .sub { color:var(--ink-600); font-size:13px; margin:0 0 20px; line-height:1.5; }
  .otp-input { width:100%; text-align:center; font-family:'IBM Plex Mono',ui-monospace,monospace; font-size:30px; letter-spacing:.45em; padding:12px 8px 12px calc(8px + .45em); border-radius:12px; border:1px solid var(--line); background:var(--paper-alt); outline:none; }
  .otp-input:focus { border-color:var(--blue-500); box-shadow:0 0 0 3px rgba(59,125,196,.15); background:#fff; }
  .ok-note { background:var(--ok-100); color:var(--ok-600); border-radius:10px; padding:10px 14px; font-size:13px; margin-bottom:16px; display:flex; gap:8px; align-items:flex-start; }
  .demo-note { background:var(--warn-100); color:var(--warn-600); border-radius:10px; padding:10px 14px; font-size:12px; margin-bottom:16px; line-height:1.5; }
  .linkbtn { background:none; border:none; color:var(--blue-700); font-size:13px; cursor:pointer; padding:0; text-decoration:underline; font-family:inherit; }
  .linkbtn:disabled { color:#8A97A5; text-decoration:none; cursor:default; }
  .mfa-foot { display:flex; justify-content:space-between; align-items:center; margin-top:18px; font-size:13px; } .mfa-foot a { color:var(--ink-600); }
</style>
</head>
<body>
<div class="mfa-wrap"><div class="mfa-card">
  <div class="lc-seal"><i class="fa-solid fa-envelope-circle-check"></i></div>
  <h2>Check your email</h2>
  <p class="sub">Hello, <?= htmlspecialchars($pending['name'], ENT_QUOTES) ?>.
    <?php if ($email): ?>Enter the 6-digit code we sent to <strong><?= htmlspecialchars(mfa_mask($email), ENT_QUOTES) ?></strong>. It expires in 5 minutes.<?php endif; ?></p>

  <?php if ($demoCode): ?><div class="demo-note"><strong>Demo mode:</strong> email sending isn't configured yet, so no email was sent. Latest code &mdash; <code><?= htmlspecialchars($demoCode, ENT_QUOTES) ?></code> (also in <code>data/otp_outbox.log</code>). Add Gmail details in <code>includes/mail_config.php</code> to send real emails.</div><?php endif; ?>
  <?php if ($info): ?><div class="ok-note"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($info, ENT_QUOTES) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="login-error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error, ENT_QUOTES) ?></div><?php endif; ?>

  <?php if ($wait): ?>
    <div class="login-error"><i class="fa-solid fa-lock"></i> Locked for <?= ceil($wait/60) ?> more minute(s). Try again later or ask the administrator.</div>
  <?php elseif ($email): ?>
  <form method="POST" id="otpForm" autocomplete="off">
    <input class="otp-input" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="\d{6}" placeholder="••••••" autofocus required>
    <button class="btn-primary" style="margin-top:16px;"><i class="fa-solid fa-unlock"></i> Verify &amp; sign in</button>
  </form>
  <form method="POST" style="margin-top:14px;"><button class="linkbtn" name="resend" value="1" id="resend" <?= $cool ? 'disabled' : '' ?>>Didn't get it? <?= $cool ? 'Resend in <span id="cd">' . $cool . '</span>s' : 'Send a new code' ?></button></form>
  <?php endif; ?>

  <div class="mfa-foot"><a href="mfa.php?cancel=1"><i class="fa-solid fa-arrow-left"></i> Cancel sign-in</a></div>
</div></div>
<script>
(function(){
  var c=document.getElementById('code');
  if(c) c.addEventListener('input',function(){ c.value=c.value.replace(/\D/g,''); if(c.value.length===6) document.getElementById('otpForm').requestSubmit(); });
  var cd=document.getElementById('cd'), b=document.getElementById('resend');
  if(cd){ var n=+cd.textContent, t=setInterval(function(){ n--; if(n<=0){ clearInterval(t); b.disabled=false; b.innerHTML="Didn't get it? Send a new code"; } else cd.textContent=n; },1000); }
})();
</script>
</body>
</html>
