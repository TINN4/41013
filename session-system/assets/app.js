// Session and Legislative Meeting Management System — shared front-end behavior
// NOTE: this file only improves usability. All permissions and validation are enforced on the server.

document.addEventListener('DOMContentLoaded', function () {
  var sidebar = document.getElementById('ssms-sidebar');
  var backdrop = document.getElementById('ssms-backdrop');
  var menuBtn = document.getElementById('ssms-menu-btn');
  function openSidebar() { sidebar.classList.add('open'); backdrop.classList.add('show'); }
  function closeSidebar() { sidebar.classList.remove('open'); backdrop.classList.remove('show'); }
  if (menuBtn && sidebar) menuBtn.addEventListener('click', function () {
    sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
  });
  if (backdrop) backdrop.addEventListener('click', closeSidebar);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && sidebar) closeSidebar(); });
  document.querySelectorAll('.nav-item').forEach(function (el) {
    el.addEventListener('click', function () { if (window.innerWidth < 1024) closeSidebar(); });
  });

  // Confirmation before destructive/important actions: <form data-confirm="message">
  // Duplicate-submission guard: the submit button is disabled once the form is accepted.
  var dirty = false;
  document.querySelectorAll('form').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      var msg = f.getAttribute('data-confirm');
      if (msg && !window.confirm(msg)) { e.preventDefault(); return; }
      dirty = false;
      if (f.dataset.submitted === '1') { e.preventDefault(); return; }
      f.dataset.submitted = '1';
      setTimeout(function () {
        f.querySelectorAll('button[type=submit],button:not([type])').forEach(function (b) { b.disabled = true; });
      }, 0);
    });
    if (f.hasAttribute('data-warn-unsaved')) {
      f.addEventListener('input', function () { dirty = true; });
    }
  });
  // Back/forward cache can restore a form in its "submitted" state; reset it.
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) document.querySelectorAll('form').forEach(function (f) {
      f.dataset.submitted = '';
      f.querySelectorAll('button').forEach(function (b) { b.disabled = false; });
    });
  });
  window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

  ssmsStartTimers();
});

// Elapsed-time ticker (data-timer-start = unix seconds recorded by the server)
function ssmsStartTimers() {
  document.querySelectorAll('[data-timer-start]').forEach(function (el) {
    var start = parseInt(el.getAttribute('data-timer-start'), 10) * 1000;
    function tick() {
      var diff = Math.max(0, Date.now() - start);
      var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
      el.textContent = pad(Math.floor(diff / 3600000)) + ':' + pad(Math.floor((diff % 3600000) / 60000)) + ':' + pad(Math.floor((diff % 60000) / 1000));
    }
    tick(); setInterval(tick, 1000);
  });
}

// Poll the live-status API. Shows what the server returned; on failure it says so instead of pretending to be live.
function ssmsPollLive(sessionId, url, intervalMs) {
  var fails = 0, lastOk = null;
  function set(id, text) { var el = document.getElementById(id); if (el) el.textContent = text; }
  function refresh() {
    fetch(url + '?session_id=' + encodeURIComponent(sessionId), { cache: 'no-store', credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) {
        if (r.status === 401) { window.location.href = '../login.php?expired=1'; throw new Error('auth'); }
        if (!r.ok) throw new Error('http ' + r.status);
        return r.json();
      })
      .then(function (d) {
        if (!d.success) throw new Error('bad payload');
        fails = 0; lastOk = new Date();
        set('tr-present', d.present + ' / ' + d.basis);
        set('tr-needed', d.needed);
        set('tr-quorum', d.quorum_label);
        set('tr-agenda', d.agenda_done + ' / ' + d.agenda_total);
        set('tr-sync', 'Updated ' + lastOk.toLocaleTimeString() + '.');
        var badge = document.getElementById('ssms-live-badge');
        if (badge) badge.style.opacity = '1';
        if (d.status !== 'Ongoing') { set('tr-sync', 'This session is now ' + d.status + '. Reload the page to see the final state.'); }
      })
      .catch(function (e) {
        if (e && e.message === 'auth') return;
        fails++;
        var badge = document.getElementById('ssms-live-badge');
        if (badge) badge.style.opacity = '.4';
        set('tr-sync', 'Connection problem - figures may be out of date' + (lastOk ? ' (last updated ' + lastOk.toLocaleTimeString() + ')' : '') + '. Retrying\u2026');
      });
  }
  refresh();
  setInterval(refresh, intervalMs || 6000);
}
