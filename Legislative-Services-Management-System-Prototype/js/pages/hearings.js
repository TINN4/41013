/* ==========================================================================
   pages/hearings.js — Public Hearing & Consultation Management (Module 7)
   Scheduling, registration, attendance, feedback collection, reports.
   ========================================================================== */
import { getAll, insert, update, remove, getById } from '../store.js';
import { card, statCard, icon, badge, pageHeader, button, renderIcons, fmtDate, fmtDateLong, fmtTime, filterBar, table, modal, field, readForm, toast, confirmDialog, exportCSV, emptyState, aiInsight, sectionTitle } from '../ui.js';
import { barChart, doughnutChart, PALETTE } from '../charts.js';

export function renderHearings(main, route) {
  main.innerHTML = `
    ${pageHeader({title:'Public Hearings & Consultations', subtitle:'Scheduling, stakeholder registration, attendance, feedback & response tracking', icon:'mic',
      actions: button({label:'Schedule Hearing', icon:'calendar-plus', variant:'primary', onclick:"window.__openHearingModal()"})})}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">${hearStats()}</div>
    <div class="grid lg:grid-cols-2 gap-4 mb-6">
      ${card({title:'Registration vs Attendance', subtitle:'Per hearing', icon:'bar-chart-3', body:`<div class="ls-chart-wrap h-56"><canvas id="hearChart"></canvas></div>`})}
      ${card({title:'Issues Logged', subtitle:'By hearing', icon:'alert-circle', body:`<div class="ls-chart-wrap h-56"><canvas id="hearIssues"></canvas></div>`})}
    </div>
    <div id="hear-list"></div>
  `;
  renderIcons();
  drawHearList();
  drawHearCharts();
}

function hearStats() {
  const h = getAll('hearings');
  return statCard({label:'Total Hearings', value:h.length, icon:'mic', color:'primary'}) +
         statCard({label:'Scheduled', value:h.filter(x=>x.status==='Scheduled').length, icon:'calendar-clock', color:'amber'}) +
         statCard({label:'Total Attendees', value:h.reduce((s,x)=>s+x.attended,0), icon:'users', color:'emerald'}) +
         statCard({label:'Issues Logged', value:h.reduce((s,x)=>s+x.issues,0), icon:'alert-circle', color:'red'});
}

function drawHearCharts() {
  const h = getAll('hearings').filter(x=>x.status==='Concluded');
  barChart('hearChart', h.map(x=>x.title.slice(0,20)+'…'), [{label:'Registered',data:h.map(x=>x.registered),backgroundColor:'#2563eb'},{label:'Attended',data:h.map(x=>x.attended),backgroundColor:'#059669'}], {plugins:{legend:{position:'bottom'}}});
  doughnutChart('hearIssues', h.map(x=>x.title.slice(0,18)+'…'), h.map(x=>x.issues), PALETTE.slice(0,h.length), {plugins:{legend:{position:'bottom'}}});
}

function drawHearList() {
  const hearings = getAll('hearings');
  document.getElementById('hear-list').innerHTML = card({title:'Public Hearings', subtitle:'Schedule, track attendance, and collect feedback', icon:'megaphone', body:`
    ${filterBar({searchPlaceholder:'Search hearings…', selects:[{id:'hear-status',label:'All Statuses',options:['Scheduled','Concluded']}], onSearch:'window.__hearSearch'})}
    <div id="hear-table"></div>
  `});
  renderIcons();
  renderHearTable(hearings);
}

function renderHearTable(hearings) {
  const el = document.getElementById('hear-table');
  el.innerHTML = hearings.length ? table({
    columns:[{label:'Hearing'},{label:'Date & Time'},{label:'Venue'},{label:'Registered'},{label:'Attended'},{label:'Issues'},{label:'Status'},{label:'Actions',align:'right',width:'w-1'}],
    rows: hearings.map(h=>[
      `<p class="font-medium text-slate-800 dark:text-slate-100 line-clamp-1 max-w-xs">${h.title}</p>${h.ordinanceRef?`<p class="text-xs text-slate-400">Ref: ${h.ordinanceRef}</p>`:''}`,
      `<div><p class="text-sm">${fmtDate(h.date)}</p><p class="text-xs text-slate-400">${fmtTime(h.time)}</p></div>`,
      `<span class="text-xs text-slate-600 dark:text-slate-300">${h.venue}</span>`,
      `<span class="text-sm font-semibold text-primary-600">${h.registered}</span>`,
      `<span class="text-sm ${h.attended?'text-emerald-600':'text-slate-400'}">${h.attended||'—'}</span>`,
      `<span class="text-sm ${h.issues?'text-amber-600':'text-slate-400'}">${h.issues||'—'}</span>`,
      badge(h.status),
      `<div class="flex items-center justify-end gap-1"><button onclick="window.__viewHearing('${h.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-500" title="Details">${icon('eye','w-4 h-4')}</button><button onclick="window.__openHearingModal('${h.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-primary-600" title="Edit">${icon('pencil','w-4 h-4')}</button><button onclick="window.__delHearing('${h.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-red-500" title="Delete">${icon('trash-2','w-4 h-4')}</button></div>`
    ])
  }) : emptyState({icon:'mic', title:'No hearings scheduled', action: button({label:'Schedule Hearing', icon:'calendar-plus', variant:'primary', onclick:"window.__openHearingModal()"})});
  renderIcons();
}

window.__hearSearch = function(){ const q=(document.getElementById('ls-search-input')?.value||'').toLowerCase(); const st=document.getElementById('hear-status')?.value; let h=getAll('hearings'); if(q)h=h.filter(x=>x.title.toLowerCase().includes(q)); if(st)h=h.filter(x=>x.status===st); renderHearTable(h); };

window.__openHearingModal = function(id){ const e=id?getById('hearings',id):null; const ords=getAll('ordinances'); modal({title:e?'Edit Hearing':'Schedule Public Hearing', size:'md', body:`<form id="hear-form" class="space-y-4">${field({label:'Title', name:'title', value:e?.title||'', required:true})}<div class="grid sm:grid-cols-2 gap-4">${field({label:'Date', name:'date', type:'date', value:e?.date?.slice(0,10)||''})}${field({label:'Time', name:'time', type:'time', value:e?.time||'09:00'})}</div>${field({label:'Venue', name:'venue', value:e?.venue||'', placeholder:'e.g. City Gymnasium'})}${field({label:'Related Ordinance/Resolution', name:'ordinanceRef', type:'select', value:e?.ordinanceRef||'', options:ords.map(o=>({value:o.id,label:o.number+' — '+o.title.slice(0,40)}))})}<div class="grid sm:grid-cols-2 gap-4">${field({label:'Status', name:'status', type:'select', value:e?.status||'Scheduled', options:['Scheduled','Concluded'].map(c=>({value:c,label:c}))})}${field({label:'Expected Registrants', name:'registered', type:'number', value:e?.registered||0})}</div></form>`, footer: button({label:'Cancel', variant:'secondary', onclick:"this.closest('[id^=modal]').querySelector('[data-close]').click()"})+button({label:'Save', variant:'primary', icon:'save', onclick:"window.__saveHearing('"+(id||'')+"')"})}); };
window.__saveHearing = function(id){ const d=readForm(document.getElementById('hear-form')); if(!d.title){toast('Title required','error');return;} d.registered=parseInt(d.registered)||0; if(id)update('hearings',id,d); else insert('hearings',{...d, attended:0, issues:0, feedbacks:0}); toast('Saved','success'); document.querySelector('[id^=modal] [data-close]').click(); renderHearings(document.getElementById('ls-main'),{}); };

window.__viewHearing = function(id){ const h=getById('hearings',id); const attendanceRate = h.registered?Math.round(h.attended/h.registered*100):0; modal({title:h.title, size:'lg', body:`<div class="flex items-center gap-2 mb-4">${badge(h.status)}${h.ordinanceRef?`<span class="text-xs text-slate-400">Ref: ${h.ordinanceRef}</span>`:''}</div><div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-4"><div class="p-3 rounded-lg bg-primary-50 dark:bg-primary-900/20 text-center"><p class="text-2xl font-bold text-primary-600">${h.registered}</p><p class="text-xs text-slate-500">Registered</p></div><div class="p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 text-center"><p class="text-2xl font-bold text-emerald-600">${h.attended}</p><p class="text-xs text-slate-500">Attended</p></div><div class="p-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 text-center"><p class="text-2xl font-bold text-amber-600">${h.issues}</p><p class="text-xs text-slate-500">Issues</p></div><div class="p-3 rounded-lg bg-slate-100 dark:bg-slate-800 text-center"><p class="text-2xl font-bold text-slate-700 dark:text-slate-200">${attendanceRate}%</p><p class="text-xs text-slate-500">Attendance</p></div></div>${card({title:'Hearing Details', icon:'info', body:`<dl class="text-sm space-y-2"><div class="flex justify-between"><dt class="text-slate-500">Date</dt><dd>${fmtDateLong(h.date)}</dd></div><div class="flex justify-between"><dt class="text-slate-500">Time</dt><dd>${fmtTime(h.time)}</dd></div><div class="flex justify-between"><dt class="text-slate-500">Venue</dt><dd>${h.venue}</dd></div></dl>`})}${sectionTitle('Sample Public Feedback')}<div class="space-y-2">${['We support the ordinance but request a longer transition period for small vendors.','Please ensure barangay-level consultations are included before final approval.','The proposed fines may be too high for micro-enterprises — consider a warning-first approach.'].slice(0,h.feedbacks>0?3:0).map(f=>`<div class="p-3 rounded-lg bg-slate-50 dark:bg-slate-800 text-sm text-slate-600 dark:text-slate-300">${icon('quote','w-4 h-4 text-slate-400 inline mr-1')}${f}</div>`).join('')||'<p class="text-sm text-slate-400 text-center py-3">No feedback collected yet for this hearing.</p>'}</div>${h.status==='Concluded'?aiInsight({title:'Hearing Insights', body:`This hearing drew ${h.attended} participants (${attendanceRate}% of registrants) and logged ${h.issues} distinct issues. Public sentiment appears generally supportive with implementation concerns around vendor impact and transition timelines. Recommend the originating committee address transition periods in the revised draft.`}):''}`, footer: button({label:'Print Report', icon:'printer', variant:'outline', onclick:'window.print()'})+button({label:'Close', variant:'secondary', onclick:"this.closest('[id^=modal]').querySelector('[data-close]').click()"})}); };
window.__delHearing = function(id){ confirmDialog({title:'Delete hearing?', message:'This hearing record will be removed.', onConfirm:()=>{remove('hearings',id); toast('Deleted','success'); renderHearings(document.getElementById('ls-main'),{});}}); };
