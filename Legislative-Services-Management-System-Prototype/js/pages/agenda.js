/* ==========================================================================
   pages/agenda.js — Legislative Agenda & Calendar Management (Module 3)
   Priority scheduling, calendar, meeting coordination, deadline tracking.
   ========================================================================== */
import { getAll, insert, update, remove, getById } from '../store.js';
import { card, statCard, icon, badge, pageHeader, button, renderIcons, fmtDate, fmtDateLong, fmtTime, sectionTitle, filterBar, table, modal, field, readForm, toast, confirmDialog, exportCSV, emptyState, aiInsight } from '../ui.js';

let viewMonth = new Date();

export function renderAgenda(main, route) {
  main.innerHTML = `
    ${pageHeader({title:'Agenda & Calendar', subtitle:'Priority scheduling, deadline tracking, and legislative timeline', icon:'calendar-days',
      actions: button({label:'New Agenda Item', icon:'plus', variant:'primary', onclick:"window.__openAgendaModal()"})})}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">${agendaStats()}</div>
    <div class="grid lg:grid-cols-3 gap-4 mb-6">
      <div class="lg:col-span-2">${calendarCard()}</div>
      <div class="space-y-4">${deadlinesCard()}${aiInsightCard()}</div>
    </div>
    <div id="agenda-list"></div>
  `;
  renderIcons();
  drawAgendaList();
}

function agendaStats() {
  const a = getAll('agenda');
  const overdue = a.filter(x => new Date(x.deadline) < new Date() && x.status!=='Completed').length;
  return statCard({label:'Agenda Items', value:a.length, icon:'list-checks', color:'primary'}) +
         statCard({label:'High Priority', value:a.filter(x=>x.priority==='High'||x.priority==='Critical').length, icon:'alert-circle', color:'red'}) +
         statCard({label:'Completed', value:a.filter(x=>x.status==='Completed').length, icon:'check-circle-2', color:'emerald'}) +
         statCard({label:'Overdue', value:overdue, icon:'alarm-clock', color:'amber', trend: overdue?'Needs attention':'On track', trendUp: !overdue});
}

function calendarCard() {
  const year = viewMonth.getFullYear(), month = viewMonth.getMonth();
  const firstDay = new Date(year, month, 1).getDay();
  const daysInMonth = new Date(year, month+1, 0).getDate();
  const monthName = viewMonth.toLocaleDateString('en-US',{month:'long', year:'numeric'});
  const sessions = getAll('sessions');
  const hearings = getAll('hearings');
  const agenda = getAll('agenda');

  let cells = '';
  ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].forEach(d=>{ cells += `<div class="text-center text-xs font-semibold text-slate-400 py-2">${d}</div>`; });
  for (let i=0;i<firstDay;i++) cells += '<div></div>';
  for (let d=1; d<=daysInMonth; d++) {
    const dateStr = `${year}-${String(month+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
    const daySessions = sessions.filter(s=>s.date?.slice(0,10)===dateStr);
    const dayHearings = hearings.filter(h=>h.date?.slice(0,10)===dateStr);
    const dayAgenda = agenda.filter(a=>a.deadline?.slice(0,10)===dateStr);
    const isToday = new Date().toDateString() === new Date(year,month,d).toDateString();
    const events = [...daySessions.map(s=>({t:'s',l:s.title})), ...dayHearings.map(h=>({t:'h',l:h.title})), ...dayAgenda.map(a=>({t:'a',l:a.title}))];
    cells += `<div class="min-h-[80px] p-1.5 rounded-lg border ${isToday?'border-primary-400 bg-primary-50 dark:bg-primary-900/20':'border-slate-100 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800/40'} transition">
      <span class="text-xs ${isToday?'text-primary-600 font-bold':'text-slate-500'}">${d}</span>
      ${events.slice(0,2).map(e=>`<div class="mt-1 text-[10px] truncate px-1 py-0.5 rounded ${e.t==='s'?'bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300':e.t==='h'?'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300':'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300'}">${e.l}</div>`).join('')}
      ${events.length>2?`<div class="text-[10px] text-slate-400 mt-0.5">+${events.length-2} more</div>`:''}
    </div>`;
  }

  return card({title:'Legislative Calendar', icon:'calendar',
    action:`<div class="flex items-center gap-1">${button({label:'', icon:'chevron-left', variant:'ghost', size:'sm', onclick:'window.__calPrev()'})}<span class="text-sm font-medium px-2">${monthName}</span>${button({label:'', icon:'chevron-right', variant:'ghost', size:'sm', onclick:'window.__calNext()'})}</div>`,
    body:`<div class="grid grid-cols-7 gap-1">${cells}</div>
      <div class="flex items-center gap-3 mt-4 text-xs text-slate-500">
        <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded bg-primary-200"></span>Session</span>
        <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded bg-emerald-200"></span>Hearing</span>
        <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded bg-amber-200"></span>Deadline</span>
      </div>`});
}

window.__calPrev = function(){ viewMonth.setMonth(viewMonth.getMonth()-1); renderAgenda(document.getElementById('ls-main'),{}); };
window.__calNext = function(){ viewMonth.setMonth(viewMonth.getMonth()+1); renderAgenda(document.getElementById('ls-main'),{}); };

function deadlinesCard() {
  const upcoming = getAll('agenda').filter(a=>a.status!=='Completed').sort((a,b)=>new Date(a.deadline)-new Date(b.deadline)).slice(0,5);
  return card({title:'Upcoming Deadlines', icon:'alarm-clock', body:`<div class="space-y-3">${upcoming.map(a=>{
    const days = Math.ceil((new Date(a.deadline)-new Date())/(86400000));
    const overdue = days < 0;
    return `<div class="flex items-center gap-3"><div class="w-10 h-10 rounded-lg ${overdue?'bg-red-100 dark:bg-red-900/30 text-red-600':'bg-slate-100 dark:bg-slate-800 text-slate-600'} flex flex-col items-center justify-center shrink-0"><span class="text-[9px] uppercase">${new Date(a.deadline).toLocaleDateString('en-US',{month:'short'})}</span><span class="text-sm font-bold leading-none">${new Date(a.deadline).getDate()}</span></div><div class="min-w-0 flex-1"><p class="text-sm font-medium truncate">${a.title}</p><p class="text-xs ${overdue?'text-red-500':'text-slate-400'}">${overdue?Math.abs(days)+' days overdue':days===0?'Due today':days+' days left'}</p></div>${badge(a.priority)}</div>`;
  }).join('')||'<p class="text-sm text-slate-400 text-center py-2">No upcoming deadlines.</p>'}</div>`});
}

function aiInsightCard() {
  const critical = getAll('agenda').filter(a=>a.priority==='Critical').length;
  return aiInsight({title:'Calendar Intelligence', body:`${critical} critical-priority item(s) are on the calendar. The FY 2025 Budget Hearing is the highest-impact upcoming event — ensure committee reports are submitted 24 hours prior. Consider scheduling the Traffic Code public hearing after the budget deliberations to avoid member scheduling conflicts.`});
}

function drawAgendaList() {
  const items = getAll('agenda');
  document.getElementById('agenda-list').innerHTML = card({title:'Agenda Items', subtitle:'Priority queue and meeting coordination', icon:'list-checks', body: table({
    columns:[{label:'Item'},{label:'Category'},{label:'Responsible'},{label:'Deadline'},{label:'Priority'},{label:'Status'},{label:'Actions',align:'right',width:'w-1'}],
    rows: items.map(a=>[
      `<p class="font-medium text-slate-800 dark:text-slate-100">${a.title}</p>`,
      `<span class="text-xs text-slate-600 dark:text-slate-300">${a.category}</span>`,
      `<span class="text-xs text-slate-600 dark:text-slate-300">${a.responsible}</span>`,
      `<span class="text-xs text-slate-500">${fmtDate(a.deadline)}</span>`,
      badge(a.priority),
      badge(a.status),
      `<div class="flex items-center justify-end gap-1">
        <button onclick="window.__toggleAgenda('${a.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-emerald-600" title="Toggle complete">${icon('check-circle-2','w-4 h-4')}</button>
        <button onclick="window.__delAgenda('${a.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-red-500" title="Delete">${icon('trash-2','w-4 h-4')}</button>
      </div>`
    ])
  })});
  renderIcons();
}

window.__openAgendaModal = function(id) {
  const existing = id?getById('agenda',id):null;
  const sessions = getAll('sessions');
  modal({title: existing?'Edit Agenda Item':'New Agenda Item', size:'md',
    body:`<form id="agenda-form" class="space-y-4">
      ${field({label:'Title', name:'title', value:existing?.title||'', required:true})}
      <div class="grid sm:grid-cols-2 gap-4">
        ${field({label:'Category', name:'category', type:'select', value:existing?.category||'', options:['Legislation','Finance','Public Hearing','Administrative','Community'].map(c=>({value:c,label:c}))})}
        ${field({label:'Priority', name:'priority', type:'select', value:existing?.priority||'Medium', options:['Critical','High','Medium','Low'].map(c=>({value:c,label:c}))})}
      </div>
      ${field({label:'Responsible', name:'responsible', value:existing?.responsible||'', placeholder:'e.g. Committee on Finance'})}
      <div class="grid sm:grid-cols-2 gap-4">
        ${field({label:'Deadline', name:'deadline', type:'date', value:existing?.deadline?.slice(0,10)||''})}
        ${field({label:'Status', name:'status', type:'select', value:existing?.status||'Pending', options:['Pending','Scheduled','In Progress','Completed'].map(c=>({value:c,label:c}))})}
      </div>
    </form>`,
    footer: button({label:'Cancel', variant:'secondary', onclick:"this.closest('[id^=modal]').querySelector('[data-close]').click()"}) + button({label:'Save', variant:'primary', icon:'save', onclick:"window.__saveAgenda('"+(id||'')+"')"})
  });
};

window.__saveAgenda = function(id){ const d=readForm(document.getElementById('agenda-form')); if(!d.title){toast('Title required','error');return;} if(id)update('agenda',id,d); else insert('agenda',d); toast('Saved','success'); document.querySelector('[id^=modal] [data-close]').click(); renderAgenda(document.getElementById('ls-main'),{}); };
window.__toggleAgenda = function(id){ const a=getById('agenda',id); update('agenda',id,{status: a.status==='Completed'?'Pending':'Completed'}); toast('Updated','success'); renderAgenda(document.getElementById('ls-main'),{}); };
window.__delAgenda = function(id){ confirmDialog({title:'Delete item?', message:'This agenda item will be removed.', onConfirm:()=>{remove('agenda',id); toast('Deleted','success'); renderAgenda(document.getElementById('ls-main'),{});}}); };
