<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/geofence.php';

if (!empty($_SESSION['ssms_user'])) {
    $dest = (strpos($_SESSION['ssms_user'], 'qr:') === 0) ? 'modules/attendance.php' : 'modules/scheduling.php';
    header('Location: ' . $dest);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>QR Login &mdash; Session and Legislative Meeting Management System</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="assets/style.css">
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<style>
  .qr-wrap { min-height:100vh; display:flex; align-items:center; justify-content:center; background:var(--navy-950); padding:24px 16px; }
  .qr-card { width:100%; max-width:440px; background:#fff; border-radius:20px; padding:32px 28px; box-shadow:0 20px 50px rgba(10,31,61,.3); }
  .qr-card h2 { font-size:21px; margin:0 0 4px; color:var(--navy-950); }
  .qr-card .sub { color:var(--ink-600); font-size:13px; margin:0 0 22px; }
  #qr-reader { width:100%; border-radius:14px; overflow:hidden; border:1px solid var(--line); background:#000; }
  #qr-reader video { border-radius:14px; }
  .step-row { display:flex; align-items:center; gap:10px; padding:10px 12px; border-radius:10px; background:var(--paper-alt); margin-bottom:8px; font-size:13px; }
  .step-row .dot { width:20px; height:20px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:700; flex-shrink:0; }
  .step-row.pending .dot { background:#E1E7EE; color:var(--ink-600); }
  .step-row.active .dot { background:var(--blue-600); color:#fff; }
  .step-row.done .dot { background:var(--ok-600); color:#fff; }
  .step-row.failed .dot { background:var(--bad-600); color:#fff; }
  .qr-status { margin-top:16px; padding:12px 14px; border-radius:10px; font-size:13px; display:none; }
  .qr-status.show { display:flex; align-items:flex-start; gap:8px; }
  .qr-status.ok { background:var(--ok-100); color:var(--ok-600); }
  .qr-status.err { background:var(--bad-100); color:var(--bad-600); }
  .qr-status.info { background:#E5EEF8; color:var(--blue-700); }
</style>
</head>
<body>

<div class="qr-wrap">
  <div class="qr-card">
    <div class="lc-seal"><i class="fa-solid fa-qrcode"></i></div>
    <h2>Scan Your Badge to Log In</h2>
    <p class="sub">This device will be permanently linked to your account on first login &mdash; and only that device will work afterward.</p>

    <div id="qr-reader"></div>

    <div style="margin-top:18px;">
      <div class="step-row pending" id="step-geo"><div class="dot"><i class="fa-solid fa-location-dot"></i></div><span>Checking you're within office premises&hellip;</span></div>
      <div class="step-row pending" id="step-scan"><div class="dot">2</div><span>Waiting for QR badge scan&hellip;</span></div>
      <div class="step-row pending" id="step-device"><div class="dot">3</div><span>Verifying device&hellip;</span></div>
    </div>

    <div class="qr-status" id="qr-status"></div>

    <a href="login.php" class="back-link"><i class="fa-solid fa-arrow-left"></i> Administrator? Use username &amp; password instead</a>
  </div>
</div>

<script>
const stepGeo = document.getElementById('step-geo');
const stepScan = document.getElementById('step-scan');
const stepDevice = document.getElementById('step-device');
const statusBox = document.getElementById('qr-status');

function setStep(el, state, text) {
  el.className = 'step-row ' + state;
  if (text) el.querySelector('span').textContent = text;
}
function showStatus(type, html) {
  statusBox.className = 'qr-status show ' + type;
  statusBox.innerHTML = html;
}
function escapeHtml(s) {
  // Member names come from Council Members (staff-entered, free text) and
  // are otherwise unrestricted — inserting one raw into innerHTML would
  // let a name containing "<script>" or similar run as real HTML/JS the
  // moment that member scans their badge to log in. Route it through a
  // text node first so the browser treats it as plain text no matter
  // what's in it.
  const d = document.createElement('div');
  d.textContent = s ?? '';
  return d.innerHTML;
}

// Persistent per-device identifier (survives reloads, tied to this browser/device).
function getDeviceId() {
  let id = localStorage.getItem('ssms_device_id');
  if (!id) {
    id = 'dev-' + crypto.randomUUID();
    localStorage.setItem('ssms_device_id', id);
  }
  return id;
}

let coords = null;
let scanLocked = false;

function initGeo() {
  if (!navigator.geolocation) {
    setStep(stepGeo, 'failed', 'Geolocation not supported by this browser.');
    showStatus('err', '<i class="fa-solid fa-circle-exclamation"></i> Your browser does not support location access, which is required for QR login.');
    return;
  }
  setStep(stepGeo, 'active', 'Requesting location permission&hellip;');
  navigator.geolocation.getCurrentPosition(
    (pos) => {
      coords = { lat: pos.coords.latitude, lng: pos.coords.longitude };
      setStep(stepGeo, 'done', 'Location confirmed.');
      setStep(stepScan, 'active', 'Point your camera at your QR badge.');
      initScanner();
    },
    (err) => {
      setStep(stepGeo, 'failed', 'Location permission denied or unavailable.');
      showStatus('err', '<i class="fa-solid fa-circle-exclamation"></i> Location access is required to verify you are on office premises. Please allow location access and reload this page.');
    },
    { enableHighAccuracy: true, timeout: 15000 }
  );
}

function initScanner() {
  const scanner = new Html5Qrcode('qr-reader');
  scanner.start(
    { facingMode: 'environment' },
    { fps: 10, qrbox: 240 },
    (decodedText) => {
      if (scanLocked) return;
      scanLocked = true;
      setStep(stepScan, 'done', 'QR badge scanned.');
      scanner.stop().catch(()=>{});
      submitLogin(decodedText.trim());
    },
    () => { /* per-frame scan errors, ignore */ }
  ).catch((err) => {
    setStep(stepScan, 'failed', 'Camera access denied or unavailable.');
    showStatus('err', '<i class="fa-solid fa-circle-exclamation"></i> Could not access the camera. Please allow camera access and reload this page.');
  });
}

function submitLogin(token) {
  setStep(stepDevice, 'active', 'Verifying device binding&hellip;');
  fetch('api/qr_login.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ token, lat: coords.lat, lng: coords.lng, deviceId: getDeviceId(), deviceLabel: navigator.userAgent })
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      setStep(stepDevice, 'done', 'Device verified.');
      const attMsg = data.autoMarkedAttendance
        ? ' A session is ongoing — you\'ve been marked <strong>Present</strong>.'
        : '';
      showStatus('ok', '<i class="fa-solid fa-circle-check"></i> Welcome, ' + escapeHtml(data.name) + '.' + attMsg + ' Redirecting&hellip;');
      setTimeout(() => { window.location.href = 'modules/attendance.php'; }, 1100);
    } else {
      setStep(stepDevice, 'failed', 'Login rejected.');
      showStatus('err', '<i class="fa-solid fa-circle-exclamation"></i> ' + data.error);
      scanLocked = false;
      setTimeout(() => { setStep(stepScan, 'active', 'Point your camera at your QR badge.'); initScanner(); }, 2500);
    }
  })
  .catch(() => {
    setStep(stepDevice, 'failed', 'Network error.');
    showStatus('err', '<i class="fa-solid fa-circle-exclamation"></i> Could not reach the server. Please try again.');
    scanLocked = false;
  });
}

document.addEventListener('DOMContentLoaded', initGeo);
</script>

</body>
</html>
