/**
 * Legislative Services Management System — Prototype
 * app.js — Application bootstrap, hash router, navigation, search, notifications, dark mode.
 *
 * This is the single entry point (loaded as an ES6 module from index.html).
 * It wires together the store, mock data, the UI toolkit, charts, and every
 * page module, then drives a lightweight hash-based SPA router.
 *
 *  — No backend, no build step. Open index.html with Live Server and it runs. —
 */

import { initStore, onAny, getAll, getSettings, saveSettings, markAllRead, pushNotification } from './store.js';
import { seedData } from './data.js';
import { renderIcons, icon, toast, breadcrumbs } from './ui.js';
import { destroyAll } from './charts.js';

import { renderDashboard }    from './pages/dashboard.js';
import { renderOrdinances }   from './pages/ordinances.js';
import { renderSessions }     from './pages/sessions.js';
import { renderAgenda }       from './pages/agenda.js';
import { renderCommittees }   from './pages/committees.js';
import { renderVoting }       from './pages/voting.js';
import { renderRecords }      from './pages/records.js';
import { renderHearings }     from './pages/hearings.js';
import { renderArchives }     from './pages/archives.js';
import { renderResearch }     from './pages/research.js';
import { renderEngagement }   from './pages/engagement.js';
import { renderReports }      from './pages/reports.js';
import { renderSettings }     from './pages/settings.js';
import { renderHelp }         from './pages/help.js';
import { renderAbout }        from './pages/about.js';

/* ---------------------------------------------------------------------------
 * Route registry — each entry maps a hash fragment to a render function plus
 * the metadata used to build the sidebar and the breadcrumb trail.
 * ------------------------------------------------------------------------- */
const ROUTES = [
  { hash: '#/dashboard',     label: 'Dashboard',          icon: 'layout-dashboard',   group: 'top',    render: renderDashboard,  crumb: 'Executive Dashboard' },
  { hash: '#/ordinances',    label: 'Ordinances',         icon: 'file-text',          group: 'top',    render: renderOrdinances,  crumb: 'Ordinances & Resolutions' },
  { hash: '#/sessions',      label: 'Sessions',           icon: 'calendar-check',     group: 'top',    render: renderSessions,    crumb: 'Session Management' },
  { hash: '#/agenda',        label: 'Agenda',             icon: 'calendar-days',      group: 'top',    render: renderAgenda,      crumb: 'Agenda & Calendar' },
  { hash: '#/committees',    label: 'Committees',         icon: 'users',              group: 'top',    render: renderCommittees,  crumb: 'Committee Management' },
  { hash: '#/voting',        label: 'Voting',             icon: 'vote',               group: 'top',    render: renderVoting,      crumb: 'Voting & Quorum' },
  { hash: '#/records',       label: 'Records',            icon: 'folder-open',        group: 'top',    render: renderRecords,     crumb: 'Records & Documents' },
  { hash: '#/hearings',      label: 'Public Hearings',    icon: 'mic',                group: 'top',    render: renderHearings,    crumb: 'Public Hearings' },
  { hash: '#/archives',      label: 'Archives',           icon: 'archive',            group: 'top',    render: renderArchives,    crumb: 'Legislative Archives' },
  { hash: '#/research',      label: 'Research',           icon: 'flask-conical',      group: 'top',    render: renderResearch,    crumb: 'Research & Policy Analysis' },
  { hash: '#/engagement',    label: 'Citizen Engagement', icon: 'message-square',     group: 'top',    render: renderEngagement,  crumb: 'Citizen Engagement' },
  { hash: '#/reports',       label: 'Reports',            icon: 'bar-chart-3',        group: 'system', render: renderReports,     crumb: 'Reports & Analytics' },
  { hash: '#/settings',      label: 'Settings',           icon: 'settings',           group: 'system', render: renderSettings,    crumb: 'Settings' },
  { hash: '#/help',          label: 'Help',               icon: 'help-circle',        group: 'system', render: renderHelp,        crumb: 'Help & User Guide' },
  { hash: '#/about',         label: 'About',              icon: 'info',               group: 'system', render: renderAbout,       crumb: 'About this Prototype' },
];

const DEFAULT_ROUTE = '#/dashboard';
let currentHash = DEFAULT_ROUTE;

/* ---------------------------------------------------------------------------
 * Sidebar navigation — renders the nav list into #ls-nav, with a divider
 * between the core legislative modules and the system/utility routes.
 * ------------------------------------------------------------------------- */
function renderSidebar() {
  const nav = document.getElementById('ls-nav');
  if (!nav) return;

  const topRoutes    = ROUTES.filter(r => r.group === 'top');
  const systemRoutes = ROUTES.filter(r => r.group === 'system');

  const link = (r) => {
    const active = currentHash === r.hash;
    return `
      <a href="${r.hash}"
         class="ls-nav-link group flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors ${active
            ? 'bg-primary-600 text-white shadow-sm'
            : 'text-slate-400 hover:text-white hover:bg-slate-800/70'}"
         data-route="${r.hash}">
        <span class="shrink-0 ${active ? 'text-white' : 'text-slate-500 group-hover:text-slate-300'}">${icon(r.icon, 'w-[18px] h-[18px]')}</span>
        <span class="truncate font-medium">${r.label}</span>
      </a>`;
  };

  nav.innerHTML =
    topRoutes.map(link).join('') +
    `<div class="my-3 border-t border-slate-700/70"></div>` +
    `<p class="px-3 pb-1 text-[10px] font-semibold uppercase tracking-wider text-slate-600">System</p>` +
    systemRoutes.map(link).join('');

  renderIcons();
}

/* ---------------------------------------------------------------------------
 * Breadcrumb — Home > current page label.
 * ------------------------------------------------------------------------- */
function renderBreadcrumb() {
  const el = document.getElementById('ls-breadcrumb');
  if (!el) return;
  const route = ROUTES.find(r => r.hash === currentHash) || ROUTES[0];
  el.innerHTML = breadcrumbs([
    { label: 'Home', href: '#/dashboard', icon: 'home' },
    { label: route.crumb }
  ]);
  renderIcons();
}

/* ---------------------------------------------------------------------------
 * Topbar title + mobile menu + notifications badge.
 * ------------------------------------------------------------------------- */
function renderTopbar() {
  // notification dot visibility
  const unread = getAll('notifications').filter(n => !n.read).length;
  const dot = document.getElementById('ls-notif-dot');
  if (dot) dot.style.display = unread ? 'block' : 'none';
}

/* ---------------------------------------------------------------------------
 * Router — resolves the current hash, tears down charts, mounts the page.
 * ------------------------------------------------------------------------- */
function router() {
  const hash = window.location.hash || DEFAULT_ROUTE;
  currentHash = hash;
  const route = ROUTES.find(r => r.hash === hash) || ROUTES[0];
  const main = document.getElementById('ls-main');

  // Tear down any charts from the previous page to avoid canvas reuse errors.
  destroyAll();

  if (!main) { console.warn('[router] #ls-main not found'); return; }

  // Smooth page transition: fade out, swap, fade in.
  main.classList.add('opacity-0');
  const mount = () => {
    try {
      route.render(main, route);
      // Keep main scroll at top on navigation.
      main.parentElement && (main.parentElement.scrollTop = 0);
      window.scrollTo({ top: 0, behavior: 'instant' in window ? 'instant' : 'auto' });
    } catch (err) {
      console.error('[router] page render failed:', err);
      main.innerHTML = `<div class="p-10 text-center text-red-500">Failed to render <b>${route.label}</b>. See console for details.</div>`;
    }
    renderIcons();
    main.classList.remove('opacity-0');
  };

  if (window.requestAnimationFrame) {
    window.requestAnimationFrame(mount);
  } else {
    mount();
  }

  renderSidebar();
  renderBreadcrumb();
  renderTopbar();

  // Close the mobile sidebar after navigation.
  closeMobileSidebar();
}

/* ---------------------------------------------------------------------------
 * Mobile sidebar toggle.
 * ------------------------------------------------------------------------- */
function openMobileSidebar() {
  const sb = document.getElementById('ls-sidebar');
  const bd = document.getElementById('ls-mobile-backdrop');
  if (sb) sb.classList.remove('-translate-x-full');
  if (bd) bd.classList.remove('hidden');
}
function closeMobileSidebar() {
  const sb = document.getElementById('ls-sidebar');
  const bd = document.getElementById('ls-mobile-backdrop');
  // Only collapse on small screens (avoid affecting desktop sticky layout).
  if (window.matchMedia('(max-width: 1023px)').matches) {
    if (sb) sb.classList.add('-translate-x-full');
  }
  if (bd) bd.classList.add('hidden');
}

/* ---------------------------------------------------------------------------
 * Notifications panel.
 * ------------------------------------------------------------------------- */
function renderNotificationsPanel() {
  const panel = document.getElementById('ls-notif-panel');
  if (!panel) return;
  const items = getAll('notifications').slice().sort((a, b) => new Date(b.date) - new Date(a.date));
  const unread = items.filter(n => !n.read).length;

  panel.innerHTML = `
    <div class="flex items-center justify-between px-4 py-3 border-b border-slate-200 dark:border-slate-700">
      <h3 class="font-semibold text-sm text-slate-800 dark:text-slate-100">Notifications</h3>
      ${unread
        ? `<button id="ls-notif-markread" class="text-xs font-medium text-primary-600 hover:text-primary-700">Mark all read</button>`
        : `<span class="text-xs text-slate-400">All caught up</span>`}
    </div>
    <div class="max-h-96 overflow-y-auto divide-y divide-slate-100 dark:divide-slate-800">
      ${items.length ? items.map(n => `
        <div class="px-4 py-3 ${n.read ? '' : 'bg-primary-50/50 dark:bg-primary-900/10'}">
          <div class="flex items-start gap-2">
            <span class="mt-0.5 shrink-0 ${n.read ? 'text-slate-400' : 'text-primary-600'}">${icon(n.icon || 'bell', 'w-4 h-4')}</span>
            <div class="min-w-0">
              <p class="text-sm font-medium text-slate-800 dark:text-slate-100 leading-snug">${n.title}</p>
              ${n.body ? `<p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5 leading-snug">${n.body}</p>` : ''}
              <p class="text-[10px] text-slate-400 mt-1">${n.date}</p>
            </div>
          </div>
        </div>
      `).join('') : `<div class="px-4 py-10 text-center text-sm text-slate-400">No notifications</div>`}
    </div>`;

  const markReadBtn = document.getElementById('ls-notif-markread');
  if (markReadBtn) {
    markReadBtn.onclick = () => {
      markAllRead();
      renderNotificationsPanel();
      renderTopbar();
      toast('All notifications marked as read', 'success');
    };
  }
  renderIcons();
}

function toggleNotifications(force) {
  const panel = document.getElementById('ls-notif-panel');
  if (!panel) return;
  const willOpen = force != null ? force : panel.classList.contains('hidden');
  if (willOpen) {
    renderNotificationsPanel();
    panel.classList.remove('hidden');
  } else {
    panel.classList.add('hidden');
  }
}

/* ---------------------------------------------------------------------------
 * Global search — searches ordinances, resolutions, sessions, committees,
 * members, records, archives, research, and feedback, then shows a dropdown.
 * ------------------------------------------------------------------------- */
const SEARCH_SOURCES = [
  { collection: 'ordinances',   label: 'Ordinance',  title: r => r.title,        sub: r => `${r.id} · ${r.status}`,      route: '#/ordinances' },
  { collection: 'resolutions',  label: 'Resolution', title: r => r.title,        sub: r => `${r.id} · ${r.status}`,      route: '#/ordinances' },
  { collection: 'sessions',     label: 'Session',    title: r => `${r.id} — ${r.title || r.type}`, sub: r => `${r.date} · ${r.status}`, route: '#/sessions' },
  { collection: 'committees',   label: 'Committee',  title: r => r.name,         sub: r => `${r.id} · ${r.members?.length || 0} members`, route: '#/committees' },
  { collection: 'councilMembers', label: 'Member',   title: r => r.name,         sub: r => `${r.position} · ${r.ward || r.committee || ''}`, route: '#/committees' },
  { collection: 'records',      label: 'Document',   title: r => r.title,        sub: r => `${r.id} · ${r.category}`,    route: '#/records' },
  { collection: 'archives',     label: 'Archive',    title: r => r.title,        sub: r => `${r.year} · ${r.category}`,  route: '#/archives' },
  { collection: 'research',     label: 'Research',   title: r => r.title,        sub: r => `${r.id} · ${r.status}`,      route: '#/research' },
  { collection: 'hearings',     label: 'Hearing',    title: r => r.title,        sub: r => `${r.date} · ${r.status}`,    route: '#/hearings' },
  { collection: 'feedback',     label: 'Feedback',   title: r => r.subject || r.title, sub: r => `${r.id} · ${r.type}`, route: '#/engagement' },
];

function performGlobalSearch(q) {
  const box = document.getElementById('ls-search-results');
  if (!box) return;
  q = (q || '').trim().toLowerCase();
  if (!q) { box.classList.add('hidden'); box.innerHTML = ''; return; }

  const hits = [];
  for (const src of SEARCH_SOURCES) {
    const items = getAll(src.collection) || [];
    for (const r of items) {
      const title = (src.title(r) || '').toString().toLowerCase();
      const sub   = (src.sub(r) || '').toString().toLowerCase();
      if (title.includes(q) || sub.includes(q) || (r.id || '').toLowerCase().includes(q)) {
        hits.push({ src, record: r });
        if (hits.length >= 12) break;
      }
    }
    if (hits.length >= 12) break;
  }

  if (!hits.length) {
    box.innerHTML = `<div class="p-4 text-sm text-slate-500 text-center">No matches for "<b>${q}</b>"</div>`;
  } else {
    box.innerHTML = hits.map(h => `
      <a href="${h.src.route}" class="flex items-center gap-3 px-3 py-2.5 hover:bg-slate-50 dark:hover:bg-slate-700/50 border-b border-slate-100 dark:border-slate-700 last:border-0">
        <span class="shrink-0 w-7 h-7 rounded-lg bg-primary-100 dark:bg-primary-900/40 text-primary-600 flex items-center justify-center">${icon('search', 'w-3.5 h-3.5')}</span>
        <div class="min-w-0 flex-1">
          <p class="text-sm font-medium text-slate-800 dark:text-slate-100 truncate">${h.src.title(h.record)}</p>
          <p class="text-xs text-slate-400 truncate">${h.src.sub(h.record)}</p>
        </div>
        <span class="shrink-0 text-[10px] font-semibold uppercase tracking-wide text-slate-400 bg-slate-100 dark:bg-slate-700 px-1.5 py-0.5 rounded">${h.src.label}</span>
      </a>`).join('') + `<div class="p-2 text-center"><span class="text-[11px] text-slate-400">Press <kbd class="px-1 py-0.5 bg-slate-100 dark:bg-slate-700 rounded text-[10px]">Enter</kbd> to go to ${hits[0].src.route.replace('#/','')}</span></div>`;
  }
  box.classList.remove('hidden');
  renderIcons();
}

/* ---------------------------------------------------------------------------
 * Dark mode — persisted in settings, toggles the 'dark' class on <html>.
 * ------------------------------------------------------------------------- */
function applyDarkMode() {
  const dark = getSettings().darkMode === true;
  document.documentElement.classList.toggle('dark', dark);
  const btn = document.getElementById('ls-dark-toggle');
  if (btn) {
    btn.innerHTML = icon(dark ? 'sun' : 'moon', 'w-5 h-5');
    renderIcons();
  }
}

function toggleDarkMode() {
  const dark = !document.documentElement.classList.contains('dark');
  document.documentElement.classList.toggle('dark', dark);
  saveSettings({ darkMode: dark });
  applyDarkMode();
  toast(dark ? 'Dark mode enabled' : 'Light mode enabled', 'info');
}

/* ---------------------------------------------------------------------------
 * Live dashboard auto-refresh — when any collection changes, re-render the
 * dashboard if it is the active route, refresh the notification dot, and
 * bump the notifications panel if open.
 * ------------------------------------------------------------------------- */
function wireLiveUpdates() {
  onAny((collection) => {
    // Notification badge should always reflect fresh state.
    renderTopbar();
    if (!document.getElementById('ls-notif-panel')?.classList.contains('hidden')) {
      renderNotificationsPanel();
    }
    // Re-render the dashboard live when it is the active view.
    if (currentHash === '#/dashboard') {
      const main = document.getElementById('ls-main');
      destroyAll();
      try { renderDashboard(main, ROUTES[0]); renderIcons(); }
      catch (e) { console.error('[live] dashboard refresh failed', e); }
    }
  });
}

/* ---------------------------------------------------------------------------
 * Keyboard shortcuts.
 * ------------------------------------------------------------------------- */
function wireKeyboard() {
  document.addEventListener('keydown', (e) => {
    // "/" focuses global search (unless already typing in a field).
    if (e.key === '/' && !/INPUT|TEXTAREA|SELECT/.test(e.target.tagName)) {
      e.preventDefault();
      const s = document.getElementById('ls-global-search');
      if (s) s.focus();
    }
    // Escape closes search dropdown / notification panel / modals.
    if (e.key === 'Escape') {
      const box = document.getElementById('ls-search-results'); if (box) box.classList.add('hidden');
      const panel = document.getElementById('ls-notif-panel'); if (panel) panel.classList.add('hidden');
      const root = document.getElementById('ls-modal-root'); if (root) root.innerHTML = '';
    }
  });

  // Click-away handlers for dropdown-style overlays.
  document.addEventListener('click', (e) => {
    const searchWrap = document.getElementById('ls-global-search')?.parentElement;
    const searchBox  = document.getElementById('ls-search-results');
    if (searchBox && !searchBox.classList.contains('hidden') && searchWrap && !searchWrap.contains(e.target)) {
      searchBox.classList.add('hidden');
    }
    const notifBtn   = document.getElementById('ls-notif-btn');
    const notifPanel = document.getElementById('ls-notif-panel');
    if (notifPanel && !notifPanel.classList.contains('hidden') && notifBtn && !notifBtn.contains(e.target) && !notifPanel.contains(e.target)) {
      notifPanel.classList.add('hidden');
    }
  });
}

/* ---------------------------------------------------------------------------
 * Topbar wiring — search input, dark toggle, notif button, mobile menu.
 * ------------------------------------------------------------------------- */
function wireTopbar() {
  const search = document.getElementById('ls-global-search');
  if (search) {
    let t;
    search.addEventListener('input', (e) => {
      clearTimeout(t);
      t = setTimeout(() => performGlobalSearch(e.target.value), 120);
    });
    search.addEventListener('focus', (e) => { if (e.target.value) performGlobalSearch(e.target.value); });
    search.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        const first = document.querySelector('#ls-search-results a');
        if (first) { window.location.hash = first.getAttribute('href'); search.value = ''; document.getElementById('ls-search-results').classList.add('hidden'); }
      }
    });
  }

  const darkBtn = document.getElementById('ls-dark-toggle');
  if (darkBtn) darkBtn.addEventListener('click', toggleDarkMode);

  const notifBtn = document.getElementById('ls-notif-btn');
  if (notifBtn) notifBtn.addEventListener('click', () => toggleNotifications());

  const menuBtn = document.getElementById('ls-menu-btn');
  if (menuBtn) menuBtn.addEventListener('click', openMobileSidebar);

  const backdrop = document.getElementById('ls-mobile-backdrop');
  if (backdrop) backdrop.addEventListener('click', closeMobileSidebar);
}

/* ---------------------------------------------------------------------------
 * Boot.
 * ------------------------------------------------------------------------- */
function boot() {
  // 1. Initialize the store (seeds mock data on first run).
  initStore(seedData);

  // 2. Apply persisted theme before first paint of content.
  applyDarkMode();

  // 3. Build the sidebar + topbar wiring once.
  renderSidebar();
  wireTopbar();

  // 4. Wire live updates + keyboard shortcuts.
  wireLiveUpdates();
  wireKeyboard();

  // 5. Routing.
  window.addEventListener('hashchange', router);
  if (!window.location.hash) window.location.hash = DEFAULT_ROUTE;
  router();

  // 6. Hide the loading screen once the first page is mounted.
  if (window.__lsLoaderInterval) clearInterval(window.__lsLoaderInterval);
  const loader = document.getElementById('ls-loading-screen');
  if (loader && loader.style.display !== 'none') {
    loader.style.transition = 'opacity 0.4s ease';
    loader.style.opacity = '0';
    setTimeout(() => { loader.style.display = 'none'; }, 420);
  }

  // 7. Friendly welcome toast (only on first ever load).
  if (!sessionStorage.getItem('lsms_welcomed')) {
    sessionStorage.setItem('lsms_welcomed', '1');
    setTimeout(() => toast('Welcome to the Legislative Services Management System prototype', 'success', 4000), 700);
  }

  // 8. Simulate a live notification nudge after a short delay (demo feel).
  setTimeout(() => {
    const n = getAll('notifications');
    if (n.length && !n.some(x => x.title?.includes('Live demo'))) {
      pushNotification({
        icon: 'sparkles',
        title: 'Live demo active',
        body: 'Interact with any module — changes persist in your browser via Local Storage.',
        date: new Date().toISOString().slice(0, 16).replace('T', ' '),
        read: false
      });
      renderTopbar();
    }
  }, 6000);

  renderIcons();
}

/* Run when the DOM is ready. */
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot);
} else {
  boot();
}
