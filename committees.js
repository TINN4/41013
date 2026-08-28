/* ==========================================================================
   pages/committees.js — Committee Management & Assignment (Module 4)
   Creation, members, jurisdiction, workload, performance, reports.
   ========================================================================== */
import { getAll, insert, update, remove, getById } from '../store.js';
import { card, statCard, icon, badge, pageHeader, button, renderIcons, memberName, memberAvatar, committeeName, filterBar, table, modal, field, readForm, toast, confirmDialog, exportCSV, emptyState, aiInsight, sectionTitle } from '../ui.js';
import { barChart, doughnutChart, PALETTE } from '../charts.js';

export function renderCommittees(main, route) {
  main.innerHTML = `
    ${pageHeader({title:'Committee Management', subtitle:'Formation, member assignments, jurisdiction, workload & performance', icon:'users',
      actions: button({label:'New Committee', icon:'users-plus', variant:'primary', onclick:"window.__openComModal()"})})}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">${comStats()}</div>
    <div class="grid lg:grid-cols-3 gap-4 mb-6">
      ${card({title:'Committee Workload', subtitle:'Distribution across committees', icon:'bar-chart-3', className:'lg:col-span-2', body:`<div class="ls-chart-wrap h-64"><canvas id="comWorkload"></canvas></div>`})}
      ${card({title:'Member Distribution', subtitle:'By committee', icon:'pie-chart', body:`<div class="ls-chart-wrap h-64"><canvas id="comMembers"></canvas></div>`})}
    </div>
    <div id="com-list"></div>
  `;
  renderIcons();
  drawComList();
  drawComCharts();
}

function comStats() {
  const c = getAll('committees');
  const cm = getAll('committeeMembers');
  return statCard({label:'Active Committees', value:c.filter(x=>x.status==='active').length, icon:'users', color:'primary'}) +
         statCard({label:'Total Assignments', value:cm.length, icon:'user-plus', color:'emerald'}) +
         statCard({label:'Avg. Workload', value: Math.round(c.reduce((s,x)=>s+x.workload,0)/c.length)+'%', icon:'gauge', color:'amber'}) +
         statCard({label:'High Workload (>85%)', value: c.filter(x=>x.workload>85).length, icon:'trending-up', color:'red'});
}

function drawComCharts() {
  const c = getAll('committees');
  barChart('comWorkload', c.map(x=>x.name.replace('& Appropriations','& Approp.')), [{label:'Workload %', data:c.map(x=>x.workload), backgroundColor:PALETTE.slice(0,c.length)}], {plugins:{legend:{display:false}}, scales:{y:{max:100}}});
  doughnutChart('comMembers', c.map(x=>x.name.replace(' Committee','')), c.map(x=>getAll('committeeMembers').filter(m=>m.committeeId===x.id).length), PALETTE.slice(0,c.length));
}

function drawComList() {
  const committees = getAll('committees');
  document.getElementById('com-list').innerHTML = `<div class="grid md:grid-cols-2 gap-4">${committees.map(c=>{
    const members = getAll('committeeMembers').filter(m=>m.committeeId===c.id);
    return card({title:c.name, subtitle:c.jurisdiction, icon:'users',
      action:`<div class="flex items-center gap-2">${badge(c.status==='active'?'Active':'Inactive')}${button({label:'', icon:'pencil', variant:'ghost', size:'sm', onclick:"window.__openComModal('"+c.id+"')"})}${button({label:'', icon:'trash-2', variant:'ghost', size:'sm', onclick:"window.__delCom('"+c.id+"')"})}</div>`,
      body:`
        <div class="grid grid-cols-2 gap-3 mb-4">
          <div class="p-2 rounded-lg bg-slate-50 dark:bg-slate-800"><p class="text-xs text-slate-400">Workload</p><div class="flex items-center gap-2 mt-1"><div class="flex-1 h-2 rounded-full bg-slate-200 dark:bg-slate-700"><div class="h-full rounded-full ${c.workload>85?'bg-red-500':c.workload>70?'bg-amber-500':'bg-emerald-500'}" style="width:${c.workload}%"></div></div><span class="text-xs font-semibold">${c.workload}%</span></div></div>
          <div class="p-2 rounded-lg bg-slate-50 dark:bg-slate-800"><p class="text-xs text-slate-400">Members</p><p class="text-lg font-bold text-slate-700 dark:text-slate-200 mt-0.5">${members.length}</p></div>
        </div>
        <p class="text-xs text-slate-400 mb-2">Committee Roster</p>
        <div class="space-y-1.5">${members.map(m=>{const mem=getById('councilMembers',m.memberId); return `<div class="flex items-center gap-2">${memberAvatar(m.memberId,'w-7 h-7 text-[10px]')}<span class="text-sm flex-1 truncate">${mem?.name||'—'}</span><span class="text-[10px] ${m.role==='Chair'?'text-primary-600':m.role==='Vice Chair'?'text-emerald-600':'text-slate-400'} font-medium">${m.role}</span></div>`;}).join('')}</div>
        <div class="mt-3 flex gap-2">${button({label:'View Dashboard', icon:'layout-dashboard', variant:'outline', size:'sm', onclick:"window.__comDash('"+c.id+"')"})}${button({label:'Assign Member', icon:'user-plus', variant:'secondary', size:'sm', onclick:"window.__assignCom('"+c.id+"')"})}</div>
    `});
  }).join('')}</div>`;
  renderIcons();
}

window.__comDash = function(id){
  const c=getById('committees',id);
  const members=getAll('committeeMembers').filter(m=>m.committeeId===c.id);
  const ords=getAll('ordinances').filter(o=>o.committeeId===id);
  modal({title:c.name+' — Dashboard', size:'lg',
    body:`<div class="grid sm:grid-cols-3 gap-3 mb-4">
        <div class="p-3 rounded-lg bg-primary-50 dark:bg-primary-900/20 text-center"><p class="text-2xl font-bold text-primary-600">${ords.length}</p><p class="text-xs text-slate-500">Measures Referred</p></div>
        <div class="p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 text-center"><p class="text-2xl font-bold text-emerald-600">${ords.filter(o=>o.status==='Enacted'||o.status==='Approved').length}</p><p class="text-xs text-slate-500">Approved/Enacted</p></div>
        <div class="p-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 text-center"><p class="text-2xl font-bold text-amber-600">${ords.filter(o=>['Pending Review','Committee Review','Drafting'].includes(o.status)).length}</p><p class="text-xs text-slate-500">In Progress</p></div>
      </div>
      ${aiInsight({title:'Committee Performance', body:`The ${c.name} has a workload index of ${c.workload}%, with ${members.length} members and ${ords.length} referred measures. Performance is ${c.workload>85?'highly active — consider workload redistribution':c.workload>65?'healthy':'light'}.`})}`,
    footer: button({label:'Close', variant:'secondary', onclick:"this.closest('[id^=modal]').querySelector('[data-close]').click()"})});
};

window.__assignCom = function(id){
  const c=getById('committees',id);
  const existing=getAll('committeeMembers').filter(m=>m.committeeId===id).map(m=>m.memberId);
  const available=getAll('councilMembers').filter(m=>!existing.includes(m.id));
  modal({title:'Assign Member to '+c.name, size:'md',
    body:`<form id="assign-form" class="space-y-4">
      ${field({label:'Member', name:'memberId', type:'select', required:true, options:available.map(m=>({value:m.id,label:m.name}))})}
      ${field({label:'Role', name:'role', type:'select', value:'Member', options:['Chair','Vice Chair','Member'].map(r=>({value:r,label:r}))})}
    </form>`,
    footer: button({label:'Cancel', variant:'secondary', onclick:"this.closest('[id^=modal]').querySelector('[data-close]').click()"}) + button({label:'Assign', variant:'primary', icon:'user-plus', onclick:"window.__saveAssign('"+id+"')"})});
};
window.__saveAssign = function(cid){ const d=readForm(document.getElementById('assign-form')); if(!d.memberId){toast('Select a member','error');return;} insert('committeeMembers',{committeeId:cid,...d}); toast('Member assigned','success'); document.querySelector('[id^=modal] [data-close]').click(); renderCommittees(document.getElementById('ls-main'),{}); };

window.__openComModal = function(id){ const e=id?getById('committees',id):null; modal({title:e?'Edit Committee':'New Committee', size:'md', body:`<form id="com-form" class="space-y-4">${field({label:'Name', name:'name', value:e?.name||'', required:true})}${field({label:'Jurisdiction', name:'jurisdiction', value:e?.jurisdiction||'', placeholder:'e.g. City budget and appropriations'})}${field({label:'Scope', name:'scope', value:e?.scope||'', placeholder:'e.g. Financial legislation'})}<div class="grid sm:grid-cols-2 gap-4">${field({label:'Workload %', name:'workload', type:'number', value:e?.workload||50})}${field({label:'Status', name:'status', type:'select', value:e?.status||'active', options:[{value:'active',label:'Active'},{value:'inactive',label:'Inactive'}]})}</div></form>`, footer: button({label:'Cancel', variant:'secondary', onclick:"this.closest('[id^=modal]').querySelector('[data-close]').click()"})+button({label:'Save', variant:'primary', icon:'save', onclick:"window.__saveCom('"+(id||'')+"')"})}); };
window.__saveCom = function(id){ const d=readForm(document.getElementById('com-form')); d.workload=parseInt(d.workload)||50; if(!d.name){toast('Name required','error');return;} if(id)update('committees',id,d); else insert('committees',{...d, established:new Date().toISOString().slice(0,10)}); toast('Saved','success'); document.querySelector('[id^=modal] [data-close]').click(); renderCommittees(document.getElementById('ls-main'),{}); };
window.__delCom = function(id){ confirmDialog({title:'Delete committee?', message:'The committee and its member assignments will be removed.', onConfirm:()=>{remove('committees',id); getAll('committeeMembers').filter(m=>m.committeeId===id).forEach(m=>remove('committeeMembers',m.id)); toast('Deleted','success'); renderCommittees(document.getElementById('ls-main'),{});}}); };
