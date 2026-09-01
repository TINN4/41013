    </main>
  </div>
</div>

<?php if (!empty($ssms_guard_bfcache)): ?>
<script>
// If this page is ever restored from the browser's back-forward cache
// (bfcache) after a logout happened in between, force a real reload so
// the server re-checks the session instead of showing this stale snapshot.
window.addEventListener('pageshow', function (event) {
  if (event.persisted) {
    window.location.reload();
  }
});
</script>

<!-- Idle (AFK) timeout warning modal — hidden until 15 minutes of inactivity -->
<div id="ssms-idle-modal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:16px;max-width:380px;width:90%;padding:28px 26px;box-shadow:0 20px 60px rgba(0,0,0,0.3);text-align:center;">
    <div style="font-size:34px;color:var(--gold-500,#c9a227);margin-bottom:10px;"><i class="fa-solid fa-clock"></i></div>
    <h3 style="margin:0 0 8px;font-size:19px;">Still there?</h3>
    <p style="margin:0 0 6px;color:var(--ink-600,#555);font-size:14px;">You've been inactive for a while. For security, you'll be signed out in</p>
    <div id="ssms-idle-countdown" style="font-size:28px;font-weight:700;font-variant-numeric:tabular-nums;margin:8px 0 18px;color:var(--navy-950,#0f172a);">5:00</div>
    <button type="button" id="ssms-idle-stay-btn" class="btn btn-navy" style="width:100%;justify-content:center;"><i class="fa-solid fa-check"></i> Stay signed in</button>
  </div>
</div>

<script>
(function () {
  // Server-side (includes/auth.php) is the real enforcement: it logs out
  // any request that arrives more than 20 minutes after the last one it
  // saw. Everything here is the on-screen half of that same feature — a
  // 15-minute warning with a 5-minute grace countdown, then a client-side
  // redirect to logout.php so the person doesn't just sit on a page that
  // silently stopped working. If JS is disabled, the server-side check
  // above still logs them out on their next click, just without warning.
  const WARN_AFTER_MS = 15 * 60 * 1000;   // show the modal after 15 min idle
  const LOGOUT_AFTER_MS = 20 * 60 * 1000; // total idle time before logout (5 more minutes after the warning)
  const LOGOUT_URL = '<?= $base ?>logout.php?reason=idle';
  const KEEPALIVE_URL = '<?= $base ?>api/keepalive.php';

  const modal = document.getElementById('ssms-idle-modal');
  const countdownEl = document.getElementById('ssms-idle-countdown');
  const stayBtn = document.getElementById('ssms-idle-stay-btn');

  let warnTimer = null;
  let logoutTimer = null;
  let countdownInterval = null;
  let warningShownAt = null;

  function clearAllTimers() {
    clearTimeout(warnTimer);
    clearTimeout(logoutTimer);
    clearInterval(countdownInterval);
  }

  function scheduleTimers() {
    clearAllTimers();
    warnTimer = setTimeout(showWarning, WARN_AFTER_MS);
  }

  function showWarning() {
    warningShownAt = Date.now();
    modal.style.display = 'flex';
    logoutTimer = setTimeout(doLogout, LOGOUT_AFTER_MS - WARN_AFTER_MS);
    countdownInterval = setInterval(updateCountdown, 1000);
    updateCountdown();
  }

  function updateCountdown() {
    const msLeft = (LOGOUT_AFTER_MS - WARN_AFTER_MS) - (Date.now() - warningShownAt);
    if (msLeft <= 0) {
      countdownEl.textContent = '0:00';
      return;
    }
    const totalSeconds = Math.ceil(msLeft / 1000);
    const m = Math.floor(totalSeconds / 60);
    const s = totalSeconds % 60;
    countdownEl.textContent = m + ':' + String(s).padStart(2, '0');
  }

  function doLogout() {
    clearAllTimers();
    window.location.href = LOGOUT_URL;
  }

  // Once the warning is showing, only the explicit "Stay signed in" button
  // counts as proof someone is actually there — passive mouse jiggling or
  // background scroll events while the modal is up don't dismiss it, so
  // the AFK check can't be defeated by something like a screensaver-ish
  // stray input.
  stayBtn.addEventListener('click', function () {
    modal.style.display = 'none';
    clearAllTimers();
    fetch(KEEPALIVE_URL, { credentials: 'same-origin' })
      .catch(function () { /* if this fails, the next normal page load still re-syncs with the server */ });
    scheduleTimers();
  });

  // Before the warning appears, ordinary activity resets the idle clock,
  // same as any typical "keep me logged in while I'm working" behavior.
  // This listener is intentionally NOT active while the modal is showing
  // (see stayBtn above) — that's the "1st warning, 2nd auto-logout" split.
  let lastResetAt = 0;
  function onActivity() {
    if (modal.style.display === 'flex') return; // ignore passive activity once the warning is up
    const now = Date.now();
    if (now - lastResetAt < 5000) return; // throttle — no need to reset on every single mousemove pixel
    lastResetAt = now;
    scheduleTimers();
  }
  ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'click'].forEach(function (evt) {
    window.addEventListener(evt, onActivity, { passive: true });
  });

  scheduleTimers();
})();
</script>
<?php endif; ?>

<script src="<?= $base ?>assets/app.js"></script>
</body>
</html>
