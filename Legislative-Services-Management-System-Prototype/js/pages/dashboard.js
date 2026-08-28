/* ==========================================================================
   pages/dashboard.js — Executive Dashboard
   ========================================================================== */
import { getAll, on, getById } from '../store.js';
import { card, statCard, icon, badge, pageHeader, button, renderIcons, fmtDate, fmtTime, relTime, memberName, committeeName, aiInsight, sectionTitle } from '../ui.js';
import { lineChart, barChart, doughnutChart, PALETTE, STATUS_COLORS } from '../charts.js';

export function renderDashboard(main, route) {
  const ordinances = getAll('ordinances');
  const resolutions = getAll('resolutions');
  const sessions = getAll('sessions');
  const committees = getAll('committees');
  const feedback = getAll('feedback');
  const hearings = getAll('hearings');
  const activities = getAll('activities');
  const notifications = getAll('notifications');
  const agenda = getAll('agenda');
  const members = getAll('councilMembers');

  const pendingOrd = ordinances.filter(o => ['Pending Review','Committee Review','Drafting'].includes(o.status)).length;
  const pendingRes = resolutions.filter(r => ['Pending Review','Drafting'].includes(r.status)).length;
  const todaySessions = sessions.filter(s => s.date && new Date(s.date).toDateString() === new Date().toDateString());
  const upcomingMeetings = sessions.filter(s => s.status === 'Scheduled').length;
  const openFeedback = feedback.filter(f => !['Acknowledged','Validated'].includes(f.status)).length;

  const hour = new Date().getHours();
  const greeting = hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening';

  main.innerHTML = `
    ${pageHeader({
      title: `${greeting}, Hon. Almazan`,
      subtitle: new Date().toLocaleDateString('en-US',{weekday:'long', year:'numeric', month:'long', day:'numeric'}) + ' · City Legislative Council',
      icon: 'layout-dashboard',
      actions: button({label:'Today\'s Session', icon:'calendar-clock', variant:'primary', onclick:"location.hash='#/sessions'"}) +
              button({label:'Export Report', icon:'download', variant:'outline', onclick:"window.__expDashboard()"})
    })}

    <!-- AI Insight -->
    <div class="mb-6">${aiInsight({
      title:'Legislative Intelligence — Daily Brief',
      body:`You have <b>${pendingOrd} ordinances</b> and <b>${pendingRes} resolutions</b> pending action. The 42nd Regular Session is active today with <b>${todaySessions[0]?.agendaCount||0} agenda items</b>. Committee on Laws & Ordinances reports the highest workload (92%). ${openFeedback} citizen feedback items require attention, including 1 critical drainage complaint.`
    })}</div>

    <!-- Stats -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
      ${statCard({label:'Pending Ordinances', value:pendingOrd, icon:'scale', color:'primary', trend:'2 new this week', trendUp:true, sub:'Across 3 stages'})}
      ${statCard({label:'Pending Resolutions', value:pendingRes, icon:'file-text', color:'amber', trend:'1 awaiting review', trendUp:false, sub:'2 in drafting'})}
      ${statCard({label:'Sessions Today', value:todaySessions.length, icon:'calendar-clock', color:'emerald', trend:'On schedule', trendUp:true, sub:`${todaySessions[0]?.time||'—'} · ${todaySessions[0]?.venue||'—'}`})},
      ${statCard({label:'Open Citizen Feedback', value:openFeedback, icon:'message-square', color:'red', trend:'1 critical', trendUp:false, sub:'2 pending validation'})}
    </div>

    <!-- Charts row -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
      ${card({title:'Legislative Output', subtitle:'Ordinances & resolutions — last 6 months', icon:'bar-chart-3', className:'lg:col-span-2',
        body:`<div class="ls-chart-wrap h-72"><canvas id="chartOutput"></canvas></div>`})}
      ${card({title:'Ordinance Status', subtitle:'Current distribution', icon:'pie-chart',
        body:`<div class="ls-chart-wrap h-72"><canvas id="chartOrdStatus"></canvas></div>`})}
    </div>

    <!-- Two-column content -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
      <!-- Left 2 cols -->
      <div class="lg:col-span-2 space-y-4">
        ${card({title:'Pending Ordinances', subtitle:'Require your attention', icon:'scale',
          action:`<a href="#/ordinances" class="text-xs text-primary-600 font-medium hover:underline">View all</a>`,
          body:`<div class="space-y-3">${ordinances.filter(o=>['Pending Review','Committee Review','Drafting'].includes(o.status)).slice(0,4).map(o=>`
            <div class="flex items-center gap-3 p-3 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800/60 transition cursor-pointer" onclick="location.hash='#/ordinances'">
              <span class="w-10 h-10 rounded-lg bg-primary-50 dark:bg-primary-900/30 text-primary-600 dark:text-primary-300 flex items-center justify-center shrink-0">${icon('scroll-text','w-5 h-5')}</span>
              <div class="min-w-0 flex-1">
                <p class="text-sm font-medium text-slate-800 dark:text-slate-100 truncate">${o.title}</p>
                <p class="text-xs text-slate-500 mt-0.5">${o.number} · ${committeeName(o.committeeId)} · ${fmtDate(o.dateIntroduced)}</p>
              </div>
              ${badge(o.status)}
            </div>`).join('') || '<p class="text-sm text-slate-400 text-center py-6">All ordinances processed 🎉</p>'}</div>`})}

        ${card({title:'Committee Performance', subtitle:'Workload & activity index', icon:'users',
          body:`<div class="ls-chart-wrap h-64"><canvas id="chartCommittee"></canvas></div>`})}
      </div>

      <!-- Right col -->
      <div class="space-y-4">
        ${card({title:'Today\'s Session', icon:'radio', headerClass:todaySessions.length?'border-emerald-200 dark:border-emerald-800':'',
          body: todaySessions.length ? `
            <div class="flex items-center gap-2 mb-3">${badge('In Progress')}<span class="text-xs text-slate-400">Live</span></div>
            <h4 class="font-semibold text-slate-800 dark:text-white">${todaySessions[0].title}</h4>
            <div class="mt-3 space-y-2 text-sm">
              <p class="flex items-center gap-2 text-slate-600 dark:text-slate-300">${icon('clock','w-4 h-4 text-slate-400')} ${fmtTime(todaySessions[0].time)}</p>
              <p class="flex items-center gap-2 text-slate-600 dark:text-slate-300">${icon('map-pin','w-4 h-4 text-slate-400')} ${todaySessions[0].venue}</p>
              <p class="flex items-center gap-2 text-slate-600 dark:text-slate-300">${icon('list-checks','w-4 h-4 text-slate-400')} ${todaySessions[0].agendaCount} agenda items</p>
              <p class="flex items-center gap-2 text-slate-600 dark:text-slate-300">${icon('users','w-4 h-4 text-slate-400')} ${todaySessions[0].attendance.filter(a=>a.status==='present'||a.status==='late').length} of ${members.length} present</p>
            </div>
            <div class="mt-4">${button({label:'Open Session', icon:'arrow-right', variant:'primary', size:'sm', onclick:"location.hash='#/sessions'"})}</div>
          ` : `<p class="text-sm text-slate-400 text-center py-4">No session scheduled today.</p>`})}

        ${card({title:'Upcoming Meetings', icon:'calendar',
          body:`<div class="space-y-3">${sessions.filter(s=>s.status==='Scheduled').slice(0,3).map(s=>`
            <div class="flex items-center gap-3">
              <div class="w-12 h-12 rounded-lg bg-slate-100 dark:bg-slate-800 flex flex-col items-center justify-center shrink-0">
                <span class="text-[10px] uppercase text-slate-400 font-semibold">${new Date(s.date).toLocaleDateString('en-US',{month:'short'})}</span>
                <span class="text-lg font-bold text-slate-700 dark:text-slate-200 leading-none">${new Date(s.date).getDate()}</span>
              </div>
              <div class="min-w-0 flex-1"><p class="text-sm font-medium text-slate-800 dark:text-slate-100 truncate">${s.title}</p><p class="text-xs text-slate-500">${fmtTime(s.time)} · ${s.venue}</p></div>
            </div>`).join('')||'<p class="text-sm text-slate-400 text-center py-2">No upcoming meetings.</p>'}</div>`})}

        ${card({title:'Attendance Summary', icon:'user-check',
          body:`<div class="ls-chart-wrap h-48"><canvas id="chartAttendance"></canvas></div>
                <p class="text-xs text-slate-400 mt-3 text-center">Average attendance this quarter: <b class="text-slate-600 dark:text-slate-300">88%</b></p>`})}
      </div>
    </div>

    <!-- Bottom row -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
      ${card({title:'Recent Activity', subtitle:'Latest system events', icon:'activity', className:'lg:col-span-2',
        body:`<div class="space-y-2.5">${activities.slice(0,6).map(a=>{
          const aIcon = a.action==='create'?'plus-circle':a.action==='update'?'refresh-cw':'trash-2';
          const aColor = a.action==='create'?'text-emerald-600':a.action==='update'?'text-primary-600':'text-red-600';
          return `<div class="flex items-center gap-3 py-1.5"><span class="${aColor}">${icon(aIcon,'w-4 h-4')}</span><p class="text-sm text-slate-700 dark:text-slate-200 flex-1 truncate">${a.label}</p><span class="text-xs text-slate-400 shrink-0">${relTime(a.time)}</span></div>`;
        }).join('')}</div>`})}

      ${card({title:'Announcements', icon:'megaphone',
        body:`<div class="space-y-3">
          <div class="p-3 rounded-lg bg-primary-50 dark:bg-primary-900/20 border border-primary-100 dark:border-primary-800/40">
            <p class="text-sm font-medium text-primary-800 dark:text-primary-200">FY 2025 Budget Deliberations</p>
            <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Special session scheduled in 3 days. Committee on Finance to submit proposed allocations.</p>
          </div>
          <div class="p-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-100 dark:border-amber-800/40">
            <p class="text-sm font-medium text-amber-800 dark:text-amber-200">Public Hearing — Traffic Code</p>
            <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Scheduled in 5 days. Stakeholder registration ongoing.</p>
          </div>
          <div class="p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-100 dark:border-emerald-800/40">
            <p class="text-sm font-medium text-emerald-800 dark:text-emerald-200">Scholarship Ordinance Approved</p>
            <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Implementation guidelines to be drafted by Committee on Education.</p>
          </div>
        </div>`})}
    </div>

    <!-- Quick actions -->
    ${sectionTitle('Quick Actions', 'Jump to common workflows')}
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-3 mb-6">
      ${[
        {label:'New Ordinance', icon:'file-plus', hash:'#/ordinances', color:'primary'},
        {label:'Schedule Session', icon:'calendar-plus', hash:'#/sessions', color:'emerald'},
        {label:'Record Vote', icon:'vote', hash:'#/voting', color:'indigo'},
        {label:'Log Feedback', icon:'message-square-plus', hash:'#/engagement', color:'amber'},
        {label:'View Reports', icon:'file-bar-chart', hash:'#/reports', color:'slate'},
      ].map(qa=>{
        const colors = {primary:'text-primary-600 bg-primary-50 dark:bg-primary-900/30 dark:text-primary-300',emerald:'text-emerald-600 bg-emerald-50 dark:bg-emerald-900/30 dark:text-emerald-300',indigo:'text-indigo-600 bg-indigo-50 dark:bg-indigo-900/30 dark:text-indigo-300',amber:'text-amber-600 bg-amber-50 dark:bg-amber-900/30 dark:text-amber-300',slate:'text-slate-600 bg-slate-100 dark:bg-slate-800 dark:text-slate-300'};
        return `<a href="${qa.hash}" class="flex flex-col items-center gap-2 p-4 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 ls-card-hover text-center">
          <span class="w-10 h-10 rounded-lg ${colors[qa.color]} flex items-center justify-center">${icon(qa.icon,'w-5 h-5')}</span>
          <span class="text-xs font-medium text-slate-700 dark:text-slate-200">${qa.label}</span>
        </a>`;
      }).join('')}
    </div>
  `;

  renderIcons();
  drawCharts(ordinances, committees, sessions);
}

function drawCharts(ordinances, committees, sessions) {
  // Legislative output (mock 6-month trend)
  lineChart('chartOutput',
    ['Jul','Aug','Sep','Oct','Nov','Dec'],
    [
      { label:'Ordinances', data:[3,5,4,6,7,6], borderColor:'#2563eb', backgroundColor:'rgba(37,99,235,.12)' },
      { label:'Resolutions', data:[6,8,5,9,7,8], borderColor:'#059669', backgroundColor:'rgba(5,150,105,.12)' }
    ],
    { plugins:{ legend:{ position:'top' } } }
  );

  // Ordinance status doughnut
  const statusCounts = {};
  ordinances.forEach(o => { statusCounts[o.status] = (statusCounts[o.status]||0)+1; });
  const sLabels = Object.keys(statusCounts);
  doughnutChart('chartOrdStatus', sLabels, sLabels.map(s=>statusCounts[s]), sLabels.map(s=>STATUS_COLORS[s]||'#94a3b8'));

  // Committee workload
  barChart('chartCommittee',
    committees.map(c=>c.name.replace(' Committee','').replace('& Appropriations','& Approp.')),
    [{ label:'Workload %', data:committees.map(c=>c.workload), backgroundColor:PALETTE.slice(0,committees.length), borderRadius:6 }],
    { plugins:{ legend:{ display:false } }, scales:{ y:{ max:100 } } }
  );

  // Attendance doughnut
  const concluded = sessions.filter(s=>s.status==='Concluded');
  let present=0, late=0, absent=0;
  concluded.forEach(s=>s.attendance.forEach(a=>{ if(a.status==='present')present++; else if(a.status==='late')late++; else absent++; }));
  doughnutChart('chartAttendance', ['Present','Late','Absent'], [present,late,absent], ['#059669','#d97706','#94a3b8'], { cutout:'60%', plugins:{ legend:{ position:'bottom' } } });
}

window.__expDashboard = function() {
  const ords = getAll('ordinances');
  exportCSV('dashboard-ordinances.csv', ords, ['number','title','status','category','dateIntroduced','dateApproved']);
};
