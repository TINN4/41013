/* ==========================================================================
   ui.js — Reusable UI Components & Helpers
   One cohesive component library used by every page so the app feels unified.
   ========================================================================== */

import { getAll, getById } from './store.js';

/* ----------------------------- icons ----------------------------- */
export function icon(name, cls = 'w-5 h-5') {
  return `<i data-lucide="${name}" class="${cls}"></i>`;
}
export function renderIcons() {
  if (window.lucide) window.lucide.createIcons();
}

/* ----------------------------- status helpers ----------------------------- */
const STATUS_STYLES = {
  // ordinances / resolutions
  'Drafting':        'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300',
  'Pending Review':  'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
  'Committee Review':'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300',
  'Approved':        'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
  'Enacted':         'bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300',
  'Adopted':         'bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300',
  'Published':       'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
  // sessions
  'Scheduled':       'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300',
  'In Progress':     'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
  'Concluded':       'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300',
  // agenda priority
  'High':            'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
  'Critical':        'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
  'Medium':          'bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300',
  'Low':             'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300',
  // generic
  'Pending Validation':'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
  'Validated':       'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
  'Under Review':    'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300',
  'Acknowledged':    'bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300',
  'Completed':       'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
  'In Progress':     'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
  'Active':          'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
  'Archived':        'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300',
  'Restored':        'bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300',
  'Final':           'bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300',
  'Passed':          'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
  'Unanimous':       'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
  'Failed':          'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
};

export function badge(status, extra = '') {
  const cls = STATUS_STYLES[status] || 'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300';
  return `<span class="badge ${cls} ${extra}">${status}</span>`;
}

/* ----------------------------- card ----------------------------- */
export function card({ title, subtitle, icon: iconName, action, body, className = '', headerClass = '', bodyClass = '' }) {
  return `
    <section class="bg-white dark:bg-slate-900 rounded-xl shadow-card border border-slate-200 dark:border-slate-800 ${className}">
      ${title ? `
        <header class="flex items-center justify-between gap-3 px-5 py-4 border-b border-slate-100 dark:border-slate-800 ${headerClass}">
          <div class="flex items-center gap-3 min-w-0">
            ${iconName ? `<span class="w-9 h-9 rounded-lg bg-primary-50 dark:bg-primary-900/30 text-primary-600 dark:text-primary-300 flex items-center justify-center shrink-0">${icon(iconName,'w-5 h-5')}</span>` : ''}
            <div class="min-w-0">
              <h3 class="font-semibold text-slate-800 dark:text-slate-100 truncate">${title}</h3>
              ${subtitle ? `<p class="text-xs text-slate-500 dark:text-slate-400 truncate">${subtitle}</p>` : ''}
            </div>
          </div>
          ${action || ''}
        </header>` : ''}
      <div class="${title ? 'p-5' : 'p-5'} ${bodyClass}">${body}</div>
    </section>`;
}

/* ----------------------------- stat card ----------------------------- */
export function statCard({ label, value, icon: iconName, trend, trendUp = true, color = 'primary', sub }) {
  const colors = {
    primary: 'from-primary-500 to-primary-700',
    emerald: 'from-emerald-500 to-emerald-700',
    amber:   'from-amber-500 to-amber-600',
    red:     'from-red-500 to-red-600',
    slate:   'from-slate-500 to-slate-700',
    indigo:  'from-indigo-500 to-indigo-700',
  };
  return `
    <div class="bg-white dark:bg-slate-900 rounded-xl shadow-card border border-slate-200 dark:border-slate-800 p-5 ls-card-hover">
      <div class="flex items-start justify-between">
        <div>
          <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider">${label}</p>
          <p class="text-2xl font-bold text-slate-800 dark:text-white mt-1">${value}</p>
          ${sub ? `<p class="text-xs text-slate-400 mt-1">${sub}</p>` : ''}
        </div>
        <div class="w-11 h-11 rounded-xl bg-gradient-to-br ${colors[color]} flex items-center justify-center text-white shadow-md">${icon(iconName,'w-5 h-5')}</div>
      </div>
      ${trend ? `<div class="mt-3 flex items-center gap-1.5 text-xs ${trendUp ? 'text-emerald-600' : 'text-red-600'}">
        ${icon(trendUp ? 'trending-up' : 'trending-down','w-4 h-4')}<span class="font-medium">${trend}</span>
      </div>` : ''}
    </div>`;
}

/* ----------------------------- button ----------------------------- */
export function button({ label, icon: iconName, variant = 'primary', size = 'md', onclick = '', type = 'button', extra = '', title = '' }) {
  const variants = {
    primary:  'bg-primary-600 hover:bg-primary-700 text-white shadow-sm',
    secondary:'bg-slate-100 hover:bg-slate-200 text-slate-700 dark:bg-slate-800 dark:hover:bg-slate-700 dark:text-slate-200',
    outline:  'border border-slate-300 dark:border-slate-600 hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-200',
    ghost:    'hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-600 dark:text-slate-300',
    danger:   'bg-red-600 hover:bg-red-700 text-white shadow-sm',
    success:  'bg-emerald-600 hover:bg-emerald-700 text-white shadow-sm',
    warning:  'bg-amber-500 hover:bg-amber-600 text-white shadow-sm',
  };
  const sizes = { sm:'px-2.5 py-1.5 text-xs gap-1.5', md:'px-3.5 py-2 text-sm gap-2', lg:'px-5 py-2.5 text-base gap-2' };
  return `<button type="${type}" ${onclick ? `onclick="${onclick}"` : ''} title="${title}" class="inline-flex items-center justify-center rounded-lg font-medium transition ls-focus ${variants[variant]} ${sizes[size]} ${extra}">${iconName ? icon(iconName, size==='sm'?'w-3.5 h-3.5':'w-4 h-4') : ''}<span>${label}</span></button>`;
}

/* ----------------------------- breadcrumbs ----------------------------- */
export function breadcrumbs(items) {
  return items.map((it, i) => {
    const last = i === items.length - 1;
    return `${last
      ? `<span class="font-medium text-slate-700 dark:text-slate-200 truncate">${it.label}</span>`
      : `<a href="#${it.path}" class="hover:text-primary-600 transition truncate">${it.label}</a>`}
    ${!last ? '<i data-lucide="chevron-right" class="w-3.5 h-3.5 text-slate-300"></i>' : ''}`;
  }).join('');
}

/* ----------------------------- page header ----------------------------- */
export function pageHeader({ title, subtitle, icon: iconName, actions }) {
  return `
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
      <div class="flex items-center gap-3">
        ${iconName ? `<span class="w-11 h-11 rounded-xl bg-gradient-to-br from-primary-500 to-primary-700 text-white flex items-center justify-center shadow-md">${icon(iconName,'w-6 h-6')}</span>` : ''}
        <div>
          <h1 class="text-xl sm:text-2xl font-bold text-slate-800 dark:text-white tracking-tight">${title}</h1>
          ${subtitle ? `<p class="text-sm text-slate-500 dark:text-slate-400 mt-0.5">${subtitle}</p>` : ''}
        </div>
      </div>
      ${actions ? `<div class="flex items-center gap-2 flex-wrap">${actions}</div>` : ''}
    </div>`;
}

/* ----------------------------- table ----------------------------- */
export function table({ columns, rows, empty = 'No records found.', className = '' }) {
  if (!rows || rows.length === 0) {
    return emptyState({ icon: 'inbox', title: empty, subtitle: 'Records will appear here once available.' });
  }
  return `
    <div class="overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-800 ${className}">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 dark:bg-slate-800/60 text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">
          <tr>${columns.map(c => `<th class="${c.align==='right'?'text-right':'text-left'} px-4 py-3 font-semibold ${c.width||''}">${c.label}</th>`).join('')}</tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
          ${rows.map(r => `<tr class="ls-row-hover transition">${r.map((cell, i) => `<td class="px-4 py-3 ${columns[i]?.align==='right'?'text-right':''} ${columns[i]?.cellClass||''}">${cell}</td>`).join('')}</tr>`).join('')}
        </tbody>
      </table>
    </div>`;
}

/* ----------------------------- empty state ----------------------------- */
export function emptyState({ icon: iconName = 'inbox', title = 'Nothing here yet', subtitle = '', action = '' }) {
  return `
    <div class="flex flex-col items-center justify-center text-center py-12 px-4">
      <div class="w-16 h-16 rounded-2xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-400 mb-4">${icon(iconName,'w-8 h-8')}</div>
      <h3 class="font-semibold text-slate-700 dark:text-slate-200">${title}</h3>
      ${subtitle ? `<p class="text-sm text-slate-500 dark:text-slate-400 mt-1 max-w-sm">${subtitle}</p>` : ''}
      ${action ? `<div class="mt-4">${action}</div>` : ''}
    </div>`;
}

/* ----------------------------- skeleton ----------------------------- */
export function skeleton(rows = 5) {
  return Array.from({length: rows}).map(() =>
    `<div class="flex items-center gap-4 p-4">${['w-1/6','w-2/5','w-1/5','w-1/6','w-1/6'].map(w => `<div class="ls-skeleton h-4 ${w}"></div>`).join('')}</div>`
  ).join('');
}

/* ----------------------------- toast ----------------------------- */
export function toast(message, type = 'success', duration = 3200) {
  const container = document.getElementById('ls-toast-container');
  if (!container) return;
  const styles = {
    success: { bg:'bg-emerald-600', icon:'check-circle-2' },
    error:   { bg:'bg-red-600', icon:'x-circle' },
    warning: { bg:'bg-amber-500', icon:'alert-triangle' },
    info:    { bg:'bg-primary-600', icon:'info' },
  };
  const s = styles[type] || styles.info;
  const el = document.createElement('div');
  el.className = `${s.bg} text-white rounded-xl shadow-xl px-4 py-3 flex items-center gap-3 text-sm font-medium animate-slide-up`;
  el.innerHTML = `${icon(s.icon,'w-5 h-5 shrink-0')}<span class="flex-1">${message}</span><button class="opacity-70 hover:opacity-100">${icon('x','w-4 h-4')}</button>`;
  container.appendChild(el);
  renderIcons();
  const close = () => { el.style.transition='opacity .3s, transform .3s'; el.style.opacity='0'; el.style.transform='translateX(20px)'; setTimeout(()=>el.remove(),300); };
  el.querySelector('button').onclick = close;
  setTimeout(close, duration);
}

/* ----------------------------- modal ----------------------------- */
export function modal({ title, body, footer = '', size = 'md', onMount }) {
  const root = document.getElementById('ls-modal-root');
  const sizes = { sm:'max-w-md', md:'max-w-lg', lg:'max-w-2xl', xl:'max-w-4xl' };
  const id = 'modal-' + Date.now();
  root.innerHTML = `
    <div id="${id}" class="fixed inset-0 z-[10000] flex items-center justify-center p-4 ls-modal-backdrop bg-slate-900/60 backdrop-blur-sm">
      <div class="ls-modal-panel w-full ${sizes[size]} bg-white dark:bg-slate-900 rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-700 max-h-[90vh] flex flex-col">
        <header class="flex items-center justify-between px-5 py-4 border-b border-slate-100 dark:border-slate-800 shrink-0">
          <h3 class="font-semibold text-slate-800 dark:text-white text-lg">${title}</h3>
          <button data-close class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-500">${icon('x','w-5 h-5')}</button>
        </header>
        <div class="p-5 overflow-y-auto flex-1">${body}</div>
        ${footer ? `<footer class="px-5 py-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-end gap-2 shrink-0">${footer}</footer>` : ''}
      </div>
    </div>`;
  renderIcons();
  const m = document.getElementById(id);
  const close = () => { root.innerHTML = ''; };
  m.querySelector('[data-close]').onclick = close;
  m.addEventListener('click', e => { if (e.target === m) close(); });
  document.addEventListener('keydown', function esc(e){ if(e.key==='Escape'){ close(); document.removeEventListener('keydown', esc); } });
  if (onMount) onMount(m);
  return { close, el: m };
}

export function confirmDialog({ title = 'Are you sure?', message, confirmLabel = 'Confirm', variant = 'danger', onConfirm }) {
  const m = modal({
    title, size: 'sm',
    body: `<p class="text-sm text-slate-600 dark:text-slate-300">${message}</p>`,
    footer: `${button({label:'Cancel',variant:'secondary',size:'md',onclick:"this.closest('#'+this.closest('[id^=modal]').id).querySelector('[data-close]').click()"})}${button({label:confirmLabel,variant,onclick:"window.__lsConfirmYes()"})}`
  });
  window.__lsConfirmYes = () => { m.close(); onConfirm && onConfirm(); };
}

/* ----------------------------- form fields ----------------------------- */
export function field({ label, name, type = 'text', value = '', placeholder = '', required = false, options = [], extra = '' }) {
  const req = required ? '<span class="text-red-500">*</span>' : '';
  let input = '';
  if (type === 'select') {
    input = `<select name="${name}" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 outline-none focus:border-primary-400 focus:ring-2 focus:ring-primary-100 dark:focus:ring-primary-900/40 transition ${extra}">
      <option value="">Select…</option>${options.map(o => `<option value="${o.value}" ${o.value===value?'selected':''}>${o.label}</option>`).join('')}
    </select>`;
  } else if (type === 'textarea') {
    input = `<textarea name="${name}" rows="${extra||4}" placeholder="${placeholder}" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 outline-none focus:border-primary-400 focus:ring-2 focus:ring-primary-100 dark:focus:ring-primary-900/40 transition">${value}</textarea>`;
  } else if (type === 'date') {
    input = `<input type="date" name="${name}" value="${value}" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 outline-none focus:border-primary-400 focus:ring-2 focus:ring-primary-100 dark:focus:ring-primary-900/40 transition">`;
  } else if (type === 'time') {
    input = `<input type="time" name="${name}" value="${value}" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 outline-none focus:border-primary-400 focus:ring-2 focus:ring-primary-100 dark:focus:ring-primary-900/40 transition">`;
  } else {
    input = `<input type="${type}" name="${name}" value="${value}" placeholder="${placeholder}" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 outline-none focus:border-primary-400 focus:ring-2 focus:ring-primary-100 dark:focus:ring-primary-900/40 transition">`;
  }
  return `<label class="block"><span class="block text-xs font-medium text-slate-600 dark:text-slate-300 mb-1.5">${label} ${req}</span>${input}</label>`;
}

export function readForm(formEl) {
  const data = {};
  new FormData(formEl).forEach((v, k) => { data[k] = v; });
  return data;
}

/* ----------------------------- filters bar ----------------------------- */
export function filterBar({ search = true, searchPlaceholder = 'Search…', selects = [], onSearch, right = '' }) {
  const selHtml = selects.map(s => `<select id="${s.id}" class="px-3 py-2 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 outline-none focus:border-primary-400 transition">
    <option value="">${s.label}</option>${s.options.map(o=>`<option value="${o}">${o}</option>`).join('')}</select>`).join('');
  return `
    <div class="flex flex-col md:flex-row md:items-center gap-3 mb-5">
      ${search ? `<div class="relative flex-1 min-w-[200px]">
        ${icon('search','w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400')}
        <input id="ls-search-input" type="text" placeholder="${searchPlaceholder}" oninput="${onSearch}()" class="w-full pl-9 pr-3 py-2 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 outline-none focus:border-primary-400 transition" />
      </div>` : ''}
      ${selHtml}
      <div class="md:ml-auto">${right}</div>
    </div>`;
}

/* ----------------------------- tabs ----------------------------- */
export function tabs(items, activeId, onchange) {
  return `<div class="flex items-center gap-1 border-b border-slate-200 dark:border-slate-800 mb-5 overflow-x-auto no-scrollbar">
    ${items.map(it => `<button onclick="${onchange}('${it.id}')" class="px-4 py-2.5 text-sm font-medium border-b-2 -mb-px whitespace-nowrap transition ${it.id===activeId ? 'border-primary-600 text-primary-600' : 'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-slate-200'}">${it.label}${it.count!=null?` <span class="ml-1 text-xs ${it.id===activeId?'text-primary-500':'text-slate-400'}">(${it.count})</span>`:''}</button>`).join('')}
  </div>`;
}

/* ----------------------------- member lookup ----------------------------- */
export function memberName(id) {
  const m = getById('councilMembers', id);
  return m ? m.name : 'Unknown';
}
export function memberAvatar(id, size = 'w-8 h-8 text-xs') {
  const m = getById('councilMembers', id);
  if (!m) return '';
  const colors = ['from-primary-500 to-primary-700','from-emerald-500 to-emerald-700','from-amber-500 to-amber-600','from-indigo-500 to-indigo-700','from-rose-500 to-rose-700','from-cyan-500 to-cyan-700'];
  const c = colors[(m.id.charCodeAt(2)||0) % colors.length];
  return `<div class="${size} rounded-full bg-gradient-to-br ${c} flex items-center justify-center text-white font-semibold shrink-0" title="${m.name}">${m.avatar}</div>`;
}

export function committeeName(id) {
  const c = getById('committees', id);
  return c ? c.name : '—';
}

/* ----------------------------- date helpers ----------------------------- */
export function fmtDate(d) {
  if (!d) return '—';
  const date = new Date(d);
  return date.toLocaleDateString('en-US', { year:'numeric', month:'short', day:'numeric' });
}
export function fmtDateLong(d) {
  if (!d) return '—';
  const date = new Date(d);
  return date.toLocaleDateString('en-US', { weekday:'long', year:'numeric', month:'long', day:'numeric' });
}
export function fmtTime(t) { return t || '—'; }
export function relTime(d) {
  if (!d) return '';
  const diff = (new Date() - new Date(d)) / 1000;
  if (diff < 60) return 'just now';
  if (diff < 3600) return Math.floor(diff/60)+'m ago';
  if (diff < 86400) return Math.floor(diff/3600)+'h ago';
  if (diff < 604800) return Math.floor(diff/86400)+'d ago';
  return fmtDate(d);
}

/* ----------------------------- export CSV ----------------------------- */
export function exportCSV(filename, rows, headers) {
  const head = headers.join(',');
  const body = rows.map(r => headers.map(h => {
    const v = r[h] ?? '';
    return `"${String(v).replace(/"/g,'""')}"`;
  }).join(',')).join('\n');
  const blob = new Blob([head+'\n'+body], { type:'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url; a.download = filename; a.click();
  URL.revokeObjectURL(url);
  toast('Exported '+filename, 'success');
}

/* ----------------------------- print ----------------------------- */
export function printPage() {
  window.print();
}

/* ----------------------------- AI insight block ----------------------------- */
export function aiInsight({ title = 'AI Insight', body, icon: iconName = 'sparkles' }) {
  return `
    <div class="rounded-xl border border-primary-200 dark:border-primary-800/60 bg-gradient-to-br from-primary-50 to-indigo-50 dark:from-primary-900/20 dark:to-indigo-900/20 p-4">
      <div class="flex items-start gap-3">
        <span class="w-8 h-8 rounded-lg bg-gradient-to-br from-primary-500 to-indigo-600 text-white flex items-center justify-center shrink-0">${icon(iconName,'w-4 h-4')}</span>
        <div class="min-w-0">
          <p class="text-xs font-semibold text-primary-700 dark:text-primary-300 uppercase tracking-wider flex items-center gap-1">${title} <span class="text-[10px] bg-primary-600 text-white px-1.5 py-0.5 rounded">BETA</span></p>
          <p class="text-sm text-slate-700 dark:text-slate-200 mt-1.5 leading-relaxed">${body}</p>
        </div>
      </div>
    </div>`;
}

/* ----------------------------- section divider ----------------------------- */
export function sectionTitle(title, subtitle, action) {
  return `<div class="flex items-center justify-between gap-3 mb-3 mt-1">
    <div><h2 class="font-semibold text-slate-700 dark:text-slate-200 text-sm uppercase tracking-wider">${title}</h2>${subtitle?`<p class="text-xs text-slate-400 mt-0.5">${subtitle}</p>`:''}</div>
    ${action||''}
  </div>`;
}
