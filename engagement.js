/* ==========================================================================
   pages/engagement.js — Citizen Engagement & Public Feedback (Module 10)
   Feedback submission, complaints, suggestions, validation workflow,
   response management, engagement analytics, public portal pages.
   ========================================================================== */
import { getAll, insert, update, remove, getById, pushNotification } from '../store.js';
import { card, statCard, icon, badge, pageHeader, button, renderIcons, fmtDate, relTime, filterBar, table, modal, field, readForm, toast, confirmDialog, exportCSV, emptyState, aiInsight, sectionTitle } from '../ui.js';
import { barChart, doughnutChart, PALETTE } from '../charts.js';

let activeTab = 'inbox';

export function renderEngagement(main, route) {
  main.innerHTML = `
    ${pageHeader({title:'Citizen Engagement & Public Feedback', subtitle:'Feedback, complaints, validation workflow, response management & analytics', icon:'message-square',
      actions: button({label:'Public Portal', icon:'external-link', variant:'outline', onclick:"window.__openPortal()"}) + button({label:'New Feedback', icon:'plus', variant:'primary', onclick:"window.__openFbModal()"})})}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">${fbStats()}</div>
    <div class="grid lg:grid-cols-3 gap-4 mb-6">
      ${card({title:'Feedback by Type', subtitle:'Complaints, suggestions, compliments', icon:'bar-chart-3', className:'lg:col-span-2', body:`<div class="ls-chart-wrap h-56"><canvas id="fbType"></canvas></div>`})}
      ${card({title:'Status Distribution', icon:'pie-chart', body:`<div class="ls-chart-wrap h-56"><canvas id="fbStatus"></canvas></div>`})}
    </div>
    <div id="fb-tabs"></div>
    <div id="fb-content"></div>
  `;
  renderIcons();
  drawFbTabs();
  drawFbCharts();
  drawFbContent();
}

function fbStats() {
  const f = getAll('feedback');
  return statCard({label:'Total Feedback', value:f.length, icon:'message-square', color:'primary'}) +
         statCard({label:'Complaints', value:f.filter(x=>x.type==='Complaint').length, icon:'alert-triangle', color:'red'}) +
         statCard({label:'Suggestions', value:f.filter(x=>x.type==='Suggestion').length, icon:'lightbulb', color:'amber'}) +
         statCard({label:'Pending Validation', value:f.filter(x=>x.status==='Pending Validation').length, icon:'clock', color:'slate'});
}

function drawFbTabs() {
  const f = getAll('feedback');
  document.getElementById('fb-tabs').innerHTML = `<div class="flex items-center gap-1 border-b border-slate-200 dark:border-slate-800 mb-5 overflow-x-auto no-scrollbar">
    ${[{id:'inbox',label:'Inbox',count:f.length},{id:'complaints',label:'Complaints',count:f.filter(x=>x.type==='Complaint').length},{id:'suggestions',label:'Suggestions',count:f.filter(x=>x.type==='Suggestion').length},{id:'analytics',label:'Engagement Analytics'}].map(it=>`<button onclick="window.__fbTab('${it.id}')" class="px-4 py-2.5 text-sm font-medium border-b-2 -mb-px whitespace-nowrap transition ${it.id===activeTab?'border-primary-600 text-primary-600':'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-slate-200'}">${it.label}${it.count!=null?` <span class="ml-1 text-xs ${it.id===activeTab?'text-primary-500':'text-slate-400'}">(${it.count})</span>`:''}</button>`).join('')}
  </div>`;
}
window.__fbTab = function(id){ activeTab=id; drawFbTabs(); drawFbContent(); };

function drawFbCharts() {
  const f = getAll('feedback');
  const types = {}; f.forEach(x=>types[x.type]=(types[x.type]||0)+1);
  barChart('fbType', Object.keys(types), [{label:'Count', data:Object.values(types), backgroundColor:['#dc2626','#d97706','#2563eb'].slice(0,Object.keys(types).length)}], {plugins:{legend:{display:false}}});
  const statuses = {}; f.forEach(x=>statuses[x.status]=(statuses[x.status]||0)+1);
  doughnutChart('fbStatus', Object.keys(statuses), Object.values(statuses), PALETTE.slice(0,Object.keys(statuses).length), {plugins:{legend:{position:'right'}}});
}

function drawFbContent() {
  const el = document.getElementById('fb-content');
  if (activeTab === 'analytics') { el.innerHTML = analyticsView(); renderIcons(); return; }
  let items = getAll('feedback');
  if (activeTab === 'complaints') items = items.filter(x=>x.type==='Complaint');
  if (activeTab === 'suggestions') items = items.filter(x=>x.type==='Suggestion');

  el.innerHTML = `
    ${filterBar({searchPlaceholder:'Search feedback…', selects:[{id:'fb-status',label:'All Statuses',options:[...new Set(items.map(x=>x.status))]},{id:'fb-priority',label:'All Priorities',options:[...new Set(items.map(x=>x.priority))]}], onSearch:'window.__fbSearch'})}
    ${items.length ? table({
      columns:[{label:'Feedback'},{label:'Type'},{label:'Category'},{label:'Ward'},{label:'Date'},{label:'Priority'},{label:'Status'},{label:'Actions',align:'right',width:'w-1'}],
      rows: items.map(f=>[
        `<div><p class="font-medium text-slate-800 dark:text-slate-100 line-clamp-1 max-w-xs">${f.subject}</p><p class="text-xs text-slate-400">by ${f.citizen}</p></div>`,
        `<span class="badge ${f.type==='Complaint'?'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300':f.type==='Suggestion'?'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300':'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'}">${f.type}</span>`,
        `<span class="text-xs text-slate-600 dark:text-slate-300">${f.category}</span>`,
        `<span class="text-xs text-slate-500">${f.ward}</span>`,
        `<span class="text-xs text-slate-500">${relTime(f.date)}</span>`,
        badge(f.priority),
        badge(f.status),
        `<div class="flex items-center justify-end gap-1"><button onclick="window.__viewFb('${f.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-500" title="View & Respond">${icon('eye','w-4 h-4')}</button><button onclick="window.__validateFb('${f.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-emerald-600" title="Validate">${icon('badge-check','w-4 h-4')}</button><button onclick="window.__delFb('${f.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-red-500" title="Delete">${icon('trash-2','w-4 h-4')}</button></div>`
      ])
    }) : emptyState({icon:'message-square', title:'No feedback in this view', action: button({label:'New Feedback', icon:'plus', variant:'primary', onclick:"window.__openFbModal()"})})}
  `;
  renderIcons();
}

function analyticsView() {
  const f = getAll('feedback');
  const byWard = {}; f.forEach(x=>byWard[x.ward]=(byWard[x.ward]||0)+1);
  return `
    <div class="grid lg:grid-cols-2 gap-4">
      ${card({title:'Feedback by Ward', subtitle:'Geographic distribution', icon:'map', body:`<div class="ls-chart-wrap h-56"><canvas id="fbWard"></canvas></div>`})}
      ${card({title:'Response Performance', icon:'timer', body:`<div class="grid grid-cols-2 gap-3"><div class="p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 text-center"><p class="text-2xl font-bold text-emerald-600">68%</p><p class="text-xs text-slate-500">Responded within 48h</p></div><div class="p-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 text-center"><p class="text-2xl font-bold text-amber-600">3.2 days</p><p class="text-xs text-slate-500">Avg. response time</p></div><div class="p-3 rounded-lg bg-primary-50 dark:bg-primary-900/20 text-center"><p class="text-2xl font-bold text-primary-600">4.2/5</p><p class="text-xs text-slate-500">Citizen satisfaction</p></div><div class="p-3 rounded-lg bg-slate-100 dark:bg-slate-800 text-center"><p class="text-2xl font-bold text-slate-700 dark:text-slate-200">82%</p><p class="text-xs text-slate-500">Resolution rate</p></div></div>`})}
    </div>
    <div class="mt-4">${aiInsight({title:'Engagement Insights', body:`Citizen engagement is strongest in ${Object.entries(byWard).sort((a,b)=>b[1]-a[1])[0]?.[0]||'District 1'}. Complaints about infrastructure and drainage are the leading categories — aligning feedback themes with the upcoming infrastructure agenda would improve satisfaction. Consider proactive announcements when addressing recurring issues.`})}</div>
  `;
}

window.__fbSearch = function(){ drawFbContent(); };

window.__viewFb = function(id){ const f=getById('feedback',id); modal({title:f.subject, size:'md', body:`<div class="flex items-center gap-2 mb-3">${badge(f.type)}${badge(f.priority)}${badge(f.status)}</div><div class="grid sm:grid-cols-2 gap-3 mb-4 text-sm"><div><p class="text-xs text-slate-400">From</p><p class="font-medium">${f.citizen}</p></div><div><p class="text-xs text-slate-400">Ward</p><p class="font-medium">${f.ward}</p></div><div><p class="text-xs text-slate-400">Category</p><p class="font-medium">${f.category}</p></div><div><p class="text-xs text-slate-400">Date</p><p class="font-medium">${fmtDate(f.date)}</p></div></div>${card({title:'Message', icon:'quote', body:`<p class="text-sm text-slate-700 dark:text-slate-200">${f.subject}</p>`})}<div class="mt-4">${sectionTitle('Official Response')}<textarea id="fb-response" rows="3" placeholder="Type your response to the citizen…" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 outline-none focus:border-primary-400 transition">${f.response||''}</textarea></div>`, footer: button({label:'Cancel', variant:'secondary', onclick:"this.closest('[id^=modal]').querySelector('[data-close]').click()"})+button({label:'Send Response', variant:'primary', icon:'send', onclick:"window.__respondFb('"+id+"')"})}); };
window.__respondFb = function(id){ const resp=document.getElementById('fb-response').value; if(!resp){toast('Type a response','error');return;} update('feedback',id,{response:resp, status:'Acknowledged'}); toast('Response sent to citizen','success'); document.querySelector('[id^=modal] [data-close]').click(); renderEngagement(document.getElementById('ls-main'),{}); };
window.__validateFb = function(id){ const f=getById('feedback',id); update('feedback',id,{status: f.status==='Pending Validation'?'Validated':f.status}); toast(f.status==='Pending Validation'?'Validated':'Updated','success'); renderEngagement(document.getElementById('ls-main'),{}); };
window.__delFb = function(id){ confirmDialog({title:'Delete feedback?', message:'This citizen feedback will be permanently removed.', onConfirm:()=>{remove('feedback',id); toast('Deleted','success'); renderEngagement(document.getElementById('ls-main'),{});}}); };

window.__openFbModal = function(){ modal({title:'New Citizen Feedback', size:'md', body:`<form id="fb-form" class="space-y-4">${field({label:'Type', name:'type', type:'select', value:'Complaint', options:['Complaint','Suggestion','Compliment'].map(c=>({value:c,label:c}))})}${field({label:'Subject', name:'subject', required:true, placeholder:'Brief description…'})}<div class="grid sm:grid-cols-2 gap-4">${field({label:'Category', name:'category', type:'select', options:['Infrastructure','Public Safety','Sanitation','Education','Drainage','Economy','Health','Other'].map(c=>({value:c,label:c}))})}${field({label:'Ward', name:'ward', type:'select', options:['District 1','District 2','District 3','District 4','District 5','District 6'].map(c=>({value:c,label:c}))})}</div><div class="grid sm:grid-cols-2 gap-4">${field({label:'Priority', name:'priority', type:'select', value:'Medium', options:['Critical','High','Medium','Low'].map(c=>({value:c,label:c}))})}${field({label:'Citizen Name', name:'citizen', value:'Anonymous'})}</div></form>`, footer: button({label:'Cancel', variant:'secondary', onclick:"this.closest('[id^=modal]').querySelector('[data-close]').click()"})+button({label:'Submit', variant:'primary', icon:'send', onclick:"window.__saveFb()"})}); };
window.__saveFb = function(){ const d=readForm(document.getElementById('fb-form')); if(!d.subject){toast('Subject required','error');return;} insert('feedback',{...d, date:new Date().toISOString(), status:'Pending Validation', response:''}); pushNotification({title:'New citizen feedback', body:d.subject, icon:'message-square', color:'amber'}); toast('Feedback submitted — pending validation','success'); document.querySelector('[id^=modal] [data-close]').click(); renderEngagement(document.getElementById('ls-main'),{}); };

/* ----------------------- Public Portal simulation ----------------------- */
window.__openPortal = function(){
  const announcements = [
    {title:'FY 2025 Budget Public Hearing', body:'Join us to review the proposed city budget. Your input shapes community priorities.', date: fmtDate(new Date(Date.now()+10*86400000).toISOString()), icon:'megaphone'},
    {title:'Traffic Management Code Public Consultation', body:'Share your views on the new traffic code — bike lanes, demerit system, and more.', date: fmtDate(new Date(Date.now()+5*86400000).toISOString()), icon:'car'},
  ];
  const recent = getAll('feedback').slice(0,4);
  modal({title:'Citizen Public Portal', size:'xl',
    body:`<div class="rounded-xl bg-gradient-to-br from-primary-600 to-primary-800 p-6 text-white mb-4">
        <h2 class="text-xl font-bold">City Legislative Council — Public Portal</h2>
        <p class="text-sm text-primary-100 mt-1">Submit feedback, view announcements, and track issues in your community.</p>
      </div>
      ${sectionTitle('Latest Announcements')}
      <div class="grid sm:grid-cols-2 gap-3 mb-4">${announcements.map(a=>`<div class="p-4 rounded-lg border border-slate-200 dark:border-slate-700"><div class="flex items-center gap-2 mb-1">${icon(a.icon,'w-4 h-4 text-primary-600')}<span class="text-xs text-slate-400">${a.date}</span></div><p class="font-medium text-slate-800 dark:text-slate-100">${a.title}</p><p class="text-xs text-slate-500 mt-1">${a.body}</p></div>`).join('')}</div>
      ${sectionTitle('Submit New Feedback')}
      <div class="p-4 rounded-lg border-2 border-dashed border-primary-200 dark:border-primary-800 text-center">
        <p class="text-sm text-slate-600 dark:text-slate-300 mb-3">Have something to say? Submit feedback directly to the council.</p>
        ${button({label:'Submit Feedback', icon:'message-square-plus', variant:'primary', onclick:"this.closest('[id^=modal]').querySelector('[data-close]').click(); window.__openFbModal()"})}
      </div>
      ${sectionTitle('Recently Tracked Issues')}
      <div class="space-y-2">${recent.map(f=>`<div class="flex items-center gap-3 p-3 rounded-lg bg-slate-50 dark:bg-slate-800"><span class="w-8 h-8 rounded-lg ${f.type==='Complaint'?'bg-red-100 text-red-600':f.type==='Suggestion'?'bg-amber-100 text-amber-600':'bg-emerald-100 text-emerald-600'} flex items-center justify-center">${icon(f.type==='Complaint'?'alert-triangle':f.type==='Suggestion'?'lightbulb':'heart','w-4 h-4')}</span><div class="min-w-0 flex-1"><p class="text-sm font-medium truncate">${f.subject}</p><p class="text-xs text-slate-400">${f.ward} · ${relTime(f.date)}</p></div>${badge(f.status)}</div>`).join('')}</div>`,
    footer: button({label:'Close Portal', variant:'secondary', onclick:"this.closest('[id^=modal]').querySelector('[data-close]').click()"})});
};
