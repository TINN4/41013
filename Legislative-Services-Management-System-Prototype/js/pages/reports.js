/* ==========================================================================
   pages/reports.js — Consolidated Reports & Exports
   ========================================================================== */
import { getAll } from '../store.js';
import { card, statCard, icon, badge, pageHeader, button, renderIcons, fmtDate, exportCSV, toast, aiInsight, sectionTitle } from '../ui.js';
import { lineChart, doughnutChart, barChart, PALETTE, STATUS_COLORS } from '../charts.js';

export function renderReports(main, route) {
  const ordinances = getAll('ordinances');
  const resolutions = getAll('resolutions');
  const sessions = getAll('sessions');
  const feedback = getAll('feedback');

  main.innerHTML = `
    ${pageHeader({title:'Reports & Analytics', subtitle:'Consolidated legislative reports and exports', icon:'file-bar-chart',
      actions: button({label:'Print All', icon:'printer', variant:'outline', onclick:'window.print()'})})}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
      ${statCard({label:'Ordinances Enacted', value:ordinances.filter(o=>o.status==='Enacted').length, icon:'scale', color:'primary'})}
      ${statCard({label:'Resolutions Adopted', value:resolutions.filter(r=>r.status==='Adopted').length, icon:'file-text', color:'emerald'})}
      ${statCard({label:'Sessions Held', value:sessions.filter(s=>s.status==='Concluded').length, icon:'calendar-check', color:'amber'})}
      ${statCard({label:'Citizen Feedback', value:feedback.length, icon:'message-square', color:'slate'})}
    </div>

    <div class="grid lg:grid-cols-2 gap-4 mb-6">
      ${card({title:'Legislative Output Trend', subtitle:'Monthly measures processed', icon:'trending-up', body:`<div class="ls-chart-wrap h-56"><canvas id="repTrend"></canvas></div>`})}
      ${card({title:'Status Overview', subtitle:'All measures', icon:'pie-chart', body:`<div class="ls-chart-wrap h-56"><canvas id="repStatus"></canvas></div>`})}
    </div>

    ${sectionTitle('Downloadable Reports')}
    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
      ${reportCard('Ordinance Registry', 'Complete list of all ordinances with status and dates', 'scroll-text', 'primary', "exportCSV('ordinances-registry.csv', getAll('ordinances'), ['number','title','status','category','dateIntroduced','dateApproved'])")}
      ${reportCard('Resolution Registry', 'All resolutions with sponsors and outcomes', 'file-text', 'emerald', "exportCSV('resolutions-registry.csv', getAll('resolutions'), ['number','title','status','category','dateIntroduced'])")}
      ${reportCard('Session Log', 'Session history with attendance and duration', 'calendar-days', 'amber', "exportCSV('session-log.csv', getAll('sessions'), ['title','type','date','time','venue','status','duration'])")}
      ${reportCard('Committee Roster', 'Committee members and assignments', 'users', 'primary', "exportCSV('committee-roster.csv', getAll('committeeMembers'), ['committeeId','memberId','role'])")}
      ${reportCard('Voting Record', 'All recorded votes with results', 'vote', 'indigo', "exportCSV('voting-record.csv', getAll('votes'), ['subject','date','type','yes','no','abstain','result'])")}
      ${reportCard('Citizen Feedback Report', 'All public feedback with responses', 'message-square', 'red', "exportCSV('feedback-report.csv', getAll('feedback'), ['type','subject','category','ward','priority','status','date','response'])")}
    </div>

    <div class="mb-6">${aiInsight({title:'Executive Summary (AI-Generated)', body:`This reporting period covers ${ordinances.length} ordinances and ${resolutions.length} resolutions, of which ${ordinances.filter(o=>o.status==='Enacted').length} ordinances were enacted and ${resolutions.filter(r=>r.status==='Adopted').length} resolutions adopted. ${sessions.filter(s=>s.status==='Concluded').length} sessions concluded with an average attendance of 88%. Citizen engagement generated ${feedback.length} feedback items with a ${feedback.filter(f=>f.response).length}/${feedback.length} response rate. Legislative productivity is trending upward, with the Committees on Laws & Ordinances and Finance driving the majority of enacted measures.`})}</div>
  `;
  renderIcons();

  lineChart('repTrend', ['Jul','Aug','Sep','Oct','Nov','Dec'], [{label:'Ordinances',data:[3,5,4,6,7,6],borderColor:'#2563eb',backgroundColor:'rgba(37,99,235,.12)'},{label:'Resolutions',data:[6,8,5,9,7,8],borderColor:'#059669',backgroundColor:'rgba(5,150,105,.12)'}], {plugins:{legend:{position:'bottom'}}});
  const all = [...ordinances.map(o=>o.status), ...resolutions.map(r=>r.status)];
  const counts = {}; all.forEach(s=>counts[s]=(counts[s]||0)+1);
  doughnutChart('repStatus', Object.keys(counts), Object.values(counts), Object.keys(counts).map(s=>STATUS_COLORS[s]||'#94a3b8'), {plugins:{legend:{position:'right'}}});
}

function reportCard(title, desc, iconName, color, onclick) {
  const colors = {primary:'text-primary-600 bg-primary-50 dark:bg-primary-900/30 dark:text-primary-300',emerald:'text-emerald-600 bg-emerald-50 dark:bg-emerald-900/30 dark:text-emerald-300',amber:'text-amber-600 bg-amber-50 dark:bg-amber-900/30 dark:text-amber-300',indigo:'text-indigo-600 bg-indigo-50 dark:bg-indigo-900/30 dark:text-indigo-300',red:'text-red-600 bg-red-50 dark:bg-red-900/30 dark:text-red-300'};
  return `<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-5 ls-card-hover">
    <div class="flex items-center gap-3 mb-3"><span class="w-10 h-10 rounded-lg ${colors[color]} flex items-center justify-center">${icon(iconName,'w-5 h-5')}</span><div><h3 class="font-semibold text-slate-800 dark:text-slate-100">${title}</h3><p class="text-xs text-slate-400">${desc}</p></div></div>
    ${button({label:'Export CSV', icon:'download', variant:'outline', size:'sm', onclick:"window.__repExport(()=>{"+onclick+"})"})}
  </div>`;
}

window.__repExport = function(fn){ fn(); };
