// Session and Legislative Meeting Management System — shared front-end behavior

document.addEventListener('DOMContentLoaded', function () {
  var sidebar = document.getElementById('ssms-sidebar');
  var backdrop = document.getElementById('ssms-backdrop');
  var menuBtn = document.getElementById('ssms-menu-btn');

  function openSidebar() {
    sidebar.classList.add('open');
    backdrop.classList.add('show');
  }
  function closeSidebar() {
    sidebar.classList.remove('open');
    backdrop.classList.remove('show');
  }
  if (menuBtn) menuBtn.addEventListener('click', function () {
    sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
  });
  if (backdrop) backdrop.addEventListener('click', closeSidebar);

  // Auto-close sidebar on nav click (mobile)
  document.querySelectorAll('.nav-item').forEach(function (el) {
    el.addEventListener('click', function () {
      if (window.innerWidth < 1024) closeSidebar();
    });
  });
});

// Elapsed-time ticker for Real-Time Session Tracking (data-started="unix timestamp")
function ssmsStartTimers() {
  document.querySelectorAll('[data-timer-start]').forEach(function (el) {
    var start = parseInt(el.getAttribute('data-timer-start'), 10) * 1000;
    function tick() {
      var diff = Math.max(0, Date.now() - start);
      var h = Math.floor(diff / 3600000);
      var m = Math.floor((diff % 3600000) / 60000);
      var s = Math.floor((diff % 60000) / 1000);
      var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
      el.textContent = pad(h) + ':' + pad(m) + ':' + pad(s);
    }
    tick();
    setInterval(tick, 1000);
  });
}
document.addEventListener('DOMContentLoaded', ssmsStartTimers);

// Poll the live-status API for Real-Time Session Tracking module and refresh a fragment
function ssmsPollLive(sessionId, targetSelector, intervalMs) {
  var target = document.querySelector(targetSelector);
  if (!target) return;
  function refresh() {
    fetch('api/live_status.php?session_id=' + encodeURIComponent(sessionId), { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var badge = document.getElementById('ssms-live-badge');
        if (badge) {
          badge.textContent = data.status === 'Ongoing' ? 'Live' : data.status;
        }
        var quorumEl = document.getElementById('ssms-live-quorum');
        if (quorumEl) {
          quorumEl.textContent = data.present + ' / ' + data.total + ' present';
        }
      })
      .catch(function () { /* silent — demo polling */ });
  }
  refresh();
  setInterval(refresh, intervalMs || 6000);
}
