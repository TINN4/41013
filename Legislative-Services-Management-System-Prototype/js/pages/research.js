/* ==========================================================================
   pages/research.js — Legislative Research, Policy Analysis & Impact (Module 9)
   Policy research, impact assessment, comparative analysis, benchmarking,
   visual analytics, downloadable reports, AI-style recommendations.
   ========================================================================== */
import { getAll, insert, update, remove, getById } from '../store.js';
import { card, statCard, icon, badge, pageHeader, button, renderIcons, fmtDate, filterBar, table, modal, field, readForm, toast, confirmDialog, exportCSV, emptyState, aiInsight, sectionTitle } from '../ui.js';
import { radarChart, barChart, PALETTE } from '../charts.js';

let selectedId = null;

export function renderResearch(main, route) {
  main.innerHTML = `
    ${pageHeader({title:'Research, Policy Analysis & Impact Evaluation', subtitle:'Policy research, impact assessment, benchmarking & AI-style recommendations', icon:'flask-conical',
      actions: button({label:'New Research', icon:'plus', variant:'primary', onclick:"window.__openResModal()"})})}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">${resStats()}</div>
    <div class="grid lg:grid-cols-3 gap-4 mb-6">
      <div class="lg:col-span-2">${card({title:'Impact Score Comparison', subtitle:'Across all research projects', icon:'bar-chart-3', body:`<div class="ls-chart-wrap h-64"><canvas id="resImpact"></canvas></div>`})}</div>
      ${card({title:'Assessment Status', subtitle:'Completed vs in-progress', icon:'pie-chart', body:`<div class="ls-chart-wrap h-64"><canvas id="resStatus"></canvas></div>`})}
    </div>
    <div class="grid lg:grid-cols-3 gap-4">
      <div class="space-y-2" id="res-list"></div>
      <div class="lg:col-span-2" id="res-detail"></div>
    </div>
  `;
  renderIcons();
  drawResList();
  drawResCharts();
  const first = getAll('research')[0];
  if (first) { selectedId = first.id; showDetail(first.id); }
}

function resStats() {
  const r = getAll('research');
  const avg = r.length ? (r.reduce((s,x)=>s+x.impactScore,0)/r.length).toFixed(1) : '0';
  return statCard({label:'Research Projects', value:r.length, icon:'flask-conical', color:'primary'}) +
         statCard({label:'Completed', value:r.filter(x=>x.status==='Completed').length, icon:'check-circle-2', color:'emerald'}) +
         statCard({label:'In Progress', value:r.filter(x=>x.status==='In Progress').length, icon:'loader', color:'amber'}) +
         statCard({label:'Avg. Impact Score', value:avg+'/10', icon:'gauge', color:'slate'});
}

function drawResCharts() {
  const r = getAll('research');
  barChart('resImpact', r.map(x=>x.title.slice(0,22)+'…'), [{label:'Impact Score', data:r.map(x=>x.impactScore), backgroundColor:r.map((_,i)=>PALETTE[i%PALETTE.length])}], {plugins:{legend:{display:false}}, scales:{y:{max:10}}});
  const comp = r.filter(x=>x.status==='Completed').length;
  const prog = r.filter(x=>x.status==='In Progress').length;
  doughnutChart('resStatus', ['Completed','In Progress'], [comp,prog], ['#059669','#d97706'], {cutout:'60%', plugins:{legend:{position:'bottom'}}});
}

function drawResList() {
  const items = getAll('research');
  document.getElementById('res-list').innerHTML = items.map(r=>`<button onclick="window.__selRes('${r.id}')" class="w-full text-left p-3 rounded-lg border border-slate-200 dark:border-slate-800 hover:border-primary-400 transition ${selectedId===r.id?'border-primary-500 bg-primary-50 dark:bg-primary-900/20':''}"><div class="flex items-center justify-between mb-1">${badge(r.status)}<span class="text-xs font-bold text-primary-600">${r.impactScore}/10</span></div><p class="text-sm font-medium text-slate-800 dark:text-slate-100 line-clamp-2">${r.title}</p><p class="text-xs text-slate-400 mt-1">${r.type} · ${fmtDate(r.date)}</p></button>`).join('');
  renderIcons();
}

window.__selRes = function(id){ selectedId=id; drawResList(); showDetail(id); };

function showDetail(id) {
  const r = getById('research', id);
  if (!r) return;
  document.getElementById('res-detail').innerHTML = `
    ${card({title:r.title, subtitle:r.type+' · '+r.scope, icon:'flask-conical',
      action:`<div class="flex gap-1">${button({label:'',icon:'pencil',variant:'ghost',size:'sm',onclick:"window.__openResModal('"+r.id+"')"})}${button({label:'',icon:'trash-2',variant:'ghost',size:'sm',onclick:"window.__delRes('"+r.id+"')"})}${button({label:'Export',icon:'download',variant:'outline',size:'sm',onclick:"window.__expRes('"+r.id+"')"})}</div>`,
      body:`
        <div class="grid sm:grid-cols-3 gap-3 mb-4">
          <div class="p-3 rounded-lg bg-primary-50 dark:bg-primary-900/20 text-center"><p class="text-3xl font-bold text-primary-600">${r.impactScore}</p><p class="text-xs text-slate-500">Impact Score /10</p></div>
          <div class="p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 text-center"><p class="text-sm font-semibold text-emerald-700 dark:text-emerald-300">${r.status}</p><p class="text-xs text-slate-500 mt-1">Status</p></div>
          <div class="p-3 rounded-lg bg-slate-100 dark:bg-slate-800 text-center"><p class="text-sm font-semibold text-slate-700 dark:text-slate-200">${r.policy}</p><p class="text-xs text-slate-500 mt-1">Policy Area</p></div>
        </div>
        ${sectionTitle('Impact Radar (Multi-Dimensional)')}
        <div class="ls-chart-wrap h-64 mb-4"><canvas id="resRadar"></canvas></div>
        ${card({title:'AI-Style Recommendation', icon:'lightbulb', body:`<p class="text-sm text-slate-700 dark:text-slate-200">${r.recommendation}</p>`})}
        <div class="mt-3">${card({title:'Benchmarking & Comparative Analysis', icon:'git-compare', body:`<p class="text-sm text-slate-700 dark:text-slate-200">${r.benchmark}</p>`})}
        <div class="mt-3">${aiInsight({title:'Policy Impact Summary', body:`This ${r.type.toLowerCase()} for the ${r.policy} policy area scores ${r.impactScore}/10 on composite impact. ${r.metrics.social>85?'Strong social benefits projected. ':''}${r.metrics.implementability<65?'Implementation complexity is moderate to high — phased rollout recommended. ':''}${r.metrics.economic>75?'Positive fiscal outlook. ':''}The recommended next step: ${r.recommendation.split('.')[0]}.`})}</div>
    `})}
  `;
  renderIcons();
  radarChart('resRadar', ['Environmental','Economic','Social','Implementability'], [{label:r.title.slice(0,20), data:[r.metrics.environmental, r.metrics.economic, r.metrics.social, r.metrics.implementability], backgroundColor:'rgba(37,99,235,.2)', borderColor:'#2563eb'}]);
}

window.__openResModal = function(id){ const e=id?getById('research',id):null; modal({title:e?'Edit Research':'New Research Project', size:'lg', body:`<form id="res-form" class="space-y-4">${field({label:'Title', name:'title', value:e?.title||'', required:true})}<div class="grid sm:grid-cols-2 gap-4">${field({label:'Type', name:'type', type:'select', value:e?.type||'Impact Assessment', options:['Impact Assessment','Comparative Analysis','Policy Research','Benchmarking'].map(c=>({value:c,label:c}))})}${field({label:'Policy Area', name:'policy', value:e?.policy||'', placeholder:'e.g. Transportation'})}</div><div class="grid sm:grid-cols-2 gap-4">${field({label:'Scope', name:'scope', value:e?.scope||'', placeholder:'e.g. Environment'})}${field({label:'Status', name:'status', type:'select', value:e?.status||'In Progress', options:['In Progress','Completed'].map(c=>({value:c,label:c}))})}</div>${field({label:'Impact Score (0-10)', name:'impactScore', type:'number', value:e?.impactScore||5})}${field({label:'Recommendation', name:'recommendation', type:'textarea', value:e?.recommendation||''})}${field({label:'Benchmark Notes', name:'benchmark', type:'textarea', value:e?.benchmark||''})}</form>`, footer: button({label:'Cancel', variant:'secondary', onclick:"this.closest('[id^=modal]').querySelector('[data-close]').click()"})+button({label:'Save', variant:'primary', icon:'save', onclick:"window.__saveRes('"+(id||'')+"')"})}); };
window.__saveRes = function(id){ const d=readForm(document.getElementById('res-form')); if(!d.title){toast('Title required','error');return;} d.impactScore=parseFloat(d.impactScore)||5; d.metrics=e&&id?getById('research',id).metrics:{environmental:60,economic:60,social:60,implementability:60}; if(id)update('research',id,d); else insert('research',{...d, date:new Date().toISOString()}); toast('Saved','success'); document.querySelector('[id^=modal] [data-close]').click(); renderResearch(document.getElementById('ls-main'),{}); };
window.__delRes = function(id){ confirmDialog({title:'Delete research?', message:'This research project will be removed.', onConfirm:()=>{remove('research',id); toast('Deleted','success'); selectedId=null; renderResearch(document.getElementById('ls-main'),{});}}); };
window.__expRes = function(id){ exportCSV('research-'+id+'.csv', [getById('research',id)], ['title','type','policy','scope','status','impactScore','recommendation','benchmark']); };
