/* ==========================================================================
   pages/help.js — Help, User Guide, UI Guide, Workflows
   ========================================================================== */
import { card, icon, pageHeader, button, renderIcons, sectionTitle } from '../ui.js';

export function renderHelp(main, route) {
  main.innerHTML = `
    ${pageHeader({title:'Help & Documentation', subtitle:'User guide, UI guide, and workflow documentation', icon:'help-circle'})}

    <div class="grid lg:grid-cols-3 gap-4 mb-6">
      ${helpCard('Getting Started', 'Quick start guide for first-time users', 'rocket', 'primary', '#/help#getting-started')}
      ${helpCard('Core Workflows', 'Step-by-step legislative processes', 'git-branch', 'emerald', '#/help#workflows')}
      ${helpCard('UI Guide', 'Understand the interface and components', 'layout-panel-top', 'amber', '#/help#ui-guide')}
      ${helpCard('FAQ', 'Frequently asked questions', 'message-circle-question', 'indigo', '#/help#faq')}
      ${helpCard('Keyboard Shortcuts', 'Speed up your navigation', 'keyboard', 'slate', '#/help#shortcuts')}
      ${helpCard('Troubleshooting', 'Common issues and solutions', 'wrench', 'red', '#/help#troubleshooting')}
    </div>

    <div class="space-y-4" id="help-content">
      ${section('getting-started','Getting Started', 'rocket', `
        <p class="text-sm text-slate-600 dark:text-slate-300 mb-3">Welcome to the Legislative Services Management System. This prototype runs entirely in your browser using Local Storage — no backend or login required. On first launch, the system auto-seeds with comprehensive sample data including council members, ordinances, sessions, committees, and more.</p>
        <div class="grid sm:grid-cols-2 gap-3">
          ${step('1','Open the Dashboard','View executive statistics, charts, and AI insights at a glance.')}
          ${step('2','Explore Modules','Use the sidebar to navigate all 10 legislative management modules.')}
          ${step('3','Create Records','Use the "New" buttons to add ordinances, sessions, votes, and more.')}
          ${step('4','Track Changes','Every action updates the dashboard and recent activity in real time.')}
        </div>
      `)}

      ${section('workflows','Core Workflows', 'git-branch', `
        <div class="space-y-4">
          ${workflow('Ordinance Lifecycle','scale','Drafting → Committee Review → Pending Review → Approved → Published → Enacted','Create an ordinance, then use the "Advance Stage" button to move it through each stage. Each advancement updates the status, dates, and dashboard statistics automatically.')}
          ${workflow('Session Management','calendar-clock','Schedule → Attendance → Live Tracking → Conclude → Minutes','Schedule a session, mark attendance, start the live timer during the session, then conclude to auto-generate meeting minutes.')}
          ${workflow('Voting Simulation','vote','Quorum Check → Cast Votes → Tally → Record Result','Verify quorum, open a new vote, simulate each member\'s yes/no/abstain vote, then record the result with animated charts.')}
          ${workflow('Citizen Feedback','message-square','Submit → Validate → Respond → Acknowledge','Citizens submit feedback via the public portal; staff validate, respond, and acknowledge — each step tracked with timestamps.')}
        </div>
      `)}

      ${section('ui-guide','UI Guide', 'layout-panel-top', `
        <div class="grid sm:grid-cols-2 gap-3">
          ${uiItem('Sidebar','Menu','The fixed left sidebar contains all 16 navigation items grouped logically. Active items are highlighted with a blue accent.')}
          ${uiItem('Top Bar','Bar','Contains breadcrumbs, global search, dark mode toggle, notifications, and quick actions.')}
          ${uiItem('Cards','Box','Reusable cards display grouped content with optional icons, titles, subtitles, and action buttons.')}
          ${uiItem('Tables','Table','Sortable, filterable tables with hover states, status badges, and per-row action menus.')}
          ${uiItem('Modals','Dialog','Click "New" or "Edit" buttons to open modal forms. Press ESC or click outside to close.')}
          ${uiItem('Toasts','Bell','Success, error, warning, and info notifications appear in the top-right corner.')}
        </div>
      `)}

      ${section('faq','Frequently Asked Questions', 'message-circle-question', `
        <div class="space-y-3">
          ${faq('Where is my data stored?','All data is stored in your browser\'s Local Storage. Nothing is sent to any server. Clearing your browser data will reset the application.')}
          ${faq('Do I need to log in?','No. This is a front-end prototype for demonstration. Simply open index.html with Live Server.')}
          ${faq('Can I export my data?','Yes. Go to Settings → Data Management → Export All Data to download a JSON backup. You can also export individual reports from the Reports page.')}
          ${faq('How do I reset the sample data?','Go to Settings → Data Management → Reset to Sample Data. This restores the original dataset.')}
          ${faq('Does dark mode persist?','Yes. Dark mode preference is saved in Local Storage and applied on every visit.')}
          ${faq('Is this a real production system?','No. This is a high-fidelity prototype for a Capstone Proposal. It simulates backend behavior using JavaScript and Local Storage.')}
        </div>
      `)}

      ${section('shortcuts','Keyboard Shortcuts', 'keyboard', `
        <div class="grid sm:grid-cols-2 gap-3">
          ${kbd('ESC','Close any open modal')}
          ${kbd('/','Focus the global search bar')}
          ${kbd('g then d','Go to Dashboard')}
          ${kbd('g then o','Go to Ordinances')}
          ${kbd('g then s','Go to Sessions')}
          ${kbd('Ctrl/Cmd + P','Print current page')}
        </div>
      `)}

      ${section('troubleshooting','Troubleshooting', 'wrench', `
        <div class="space-y-3">
          ${faq('Charts not displaying?','Ensure you have an internet connection — Chart.js loads from a CDN. Refresh the page.')}
          ${faq('Icons showing as text?','Lucide icons load from a CDN. Check your connection and refresh.')}
          ${faq('Data disappeared?','You may have cleared browser storage or used a private window. Reset to sample data from Settings.')}
          ${faq('Page not loading with Live Server?','Make sure you open index.html (not a page file) and that Live Server is running on the correct port.')}
        </div>
      `)}
    </div>
  `;
  renderIcons();
}

function helpCard(title, desc, iconName, color, hash) {
  const colors = {primary:'text-primary-600 bg-primary-50 dark:bg-primary-900/30 dark:text-primary-300',emerald:'text-emerald-600 bg-emerald-50 dark:bg-emerald-900/30 dark:text-emerald-300',amber:'text-amber-600 bg-amber-50 dark:bg-amber-900/30 dark:text-amber-300',indigo:'text-indigo-600 bg-indigo-50 dark:bg-indigo-900/30 dark:text-indigo-300',slate:'text-slate-600 bg-slate-100 dark:bg-slate-800 dark:text-slate-300',red:'text-red-600 bg-red-50 dark:bg-red-900/30 dark:text-red-300'};
  return `<a href="${hash}" class="flex items-center gap-3 p-4 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 ls-card-hover">
    <span class="w-10 h-10 rounded-lg ${colors[color]} flex items-center justify-center shrink-0">${icon(iconName,'w-5 h-5')}</span>
    <div class="min-w-0"><p class="font-medium text-slate-800 dark:text-slate-100">${title}</p><p class="text-xs text-slate-400">${desc}</p></div>
    ${icon('arrow-right','w-4 h-4 text-slate-400 ml-auto')}
  </a>`;
}

function section(id, title, iconName, body) {
  return card({title, icon: iconName, className: 'scroll-mt-20', body});
}

function step(n, title, desc) {
  return `<div class="flex gap-3 p-3 rounded-lg bg-slate-50 dark:bg-slate-800"><span class="w-7 h-7 rounded-full bg-primary-600 text-white flex items-center justify-center text-sm font-bold shrink-0">${n}</span><div><p class="text-sm font-medium text-slate-800 dark:text-slate-100">${title}</p><p class="text-xs text-slate-500">${desc}</p></div></div>`;
}

function workflow(title, iconName, flow, desc) {
  return `<div class="p-4 rounded-lg border border-slate-200 dark:border-slate-700"><div class="flex items-center gap-2 mb-2"><span class="text-primary-600">${icon(iconName,'w-5 h-5')}</span><h4 class="font-semibold text-slate-800 dark:text-slate-100">${title}</h4></div><p class="text-xs font-mono text-primary-600 bg-primary-50 dark:bg-primary-900/20 rounded px-2 py-1 mb-2">${flow}</p><p class="text-sm text-slate-600 dark:text-slate-300">${desc}</p></div>`;
}

function uiItem(title, label, desc) {
  return `<div class="p-3 rounded-lg bg-slate-50 dark:bg-slate-800"><div class="flex items-center gap-2 mb-1"><span class="badge bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300">${label}</span><span class="text-sm font-medium text-slate-800 dark:text-slate-100">${title}</span></div><p class="text-xs text-slate-500">${desc}</p></div>`;
}

function faq(q, a) {
  return `<details class="p-3 rounded-lg bg-slate-50 dark:bg-slate-800 group"><summary class="text-sm font-medium text-slate-800 dark:text-slate-100 cursor-pointer flex items-center justify-between">${q}<span class="text-slate-400 group-open:rotate-180 transition">${icon('chevron-down','w-4 h-4')}</span></summary><p class="text-sm text-slate-600 dark:text-slate-300 mt-2">${a}</p></details>`;
}

function kbd(key, desc) {
  return `<div class="flex items-center gap-3 p-3 rounded-lg bg-slate-50 dark:bg-slate-800"><kbd class="px-2 py-1 text-xs font-mono bg-white dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded shadow-sm">${key}</kbd><span class="text-sm text-slate-600 dark:text-slate-300">${desc}</span></div>`;
}
