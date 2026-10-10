/* ==========================================================================
   pages/archives.js — Legislative Archives & Historical Repository (Module 8)
   Digital archives, search, classification, retention, restoration.
   ========================================================================== */
import { getAll, insert, update, remove, getById } from '../store.js';
import { card, statCard, icon, badge, pageHeader, button, renderIcons, fmtDate, filterBar, table, modal, field, readForm, toast, confirmDialog, exportCSV, emptyState, aiInsight, sectionTitle } from '../ui.js';
import { doughnutChart, PALETTE } from '../charts.js';

export function renderArchives(main, route) {
  main.innerHTML = `
    ${pageHeader({title:'Legislative Archives', subtitle:'Digital archives, historical records, classification & retention management', icon:'archive',
      actions: button({label:'Archive Record', icon:'archive', variant:'primary', onclick:"window.__openArchModal()"})})}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">${archStats()}</div>
    <div class="grid lg:grid-cols-3 gap-4 mb-6">
      ${card({title:'Archive Categories', subtitle:'By record type', icon:'folder-tree', className:'lg:col-span-2', body:`<div class="ls-chart-wrap h-56"><canvas id="archChart"></canvas></div>`})}
      ${aiInsight({title:'Archive Intelligence', body:`The repository holds ${getAll('archives').length} archived records spanning ${Math.min(...getAll('archives').map(a=>a.year))}–${Math.max(...getAll('archives').map(a=>a.year))}. ${getAll('archives').filter(a=>a.status==='Restored').length} historical records have been digitally restored. Retention policy: most legislative records are permanent; minutes retained 10 years.`})}
    </div>
    <div id="arch-list"></div>
  `;
  renderIcons();
  drawArchList();
  drawArchCharts();
}

function archStats() {
  const a = getAll('archives');
  const oldest = a.length?Math.min(...a.map(x=>x.year)):'—';
  return statCard({label:'Archived Records', value:a.length, icon:'archive', color:'primary'}) +
         statCard({label:'Digitized', value:a.filter(x=>x.format==='Digitized').length, icon:'scan-line', color:'emerald'}) +
         statCard({label:'Restored', value:a.filter(x=>x.status==='Restored').length, icon:'refresh-cw', color:'amber'}) +
         statCard({label:'Oldest Record', value:oldest, icon:'history', color:'slate', sub:'Year'});
}

function drawArchCharts() {
  const cats = {};
  getAll('archives').forEach(a => { cats[a.category] = (cats[a.category]||0)+1; });
  doughnutChart('archChart', Object.keys(cats), Object.values(cats), PALETTE.slice(0,Object.keys(cats).length), {plugins:{legend:{position:'right'}}});
}

function drawArchList() {
  const archives = getAll('archives');
  document.getElementById('arch-list').innerHTML = card({title:'Historical Repository', subtitle:'Search, classify, and manage retention', icon:'library', body:`
    ${filterBar({searchPlaceholder:'Search archives by title, year, or category…', selects:[{id:'arch-cat',label:'All Categories',options:[...new Set(archives.map(a=>a.category))]},{id:'arch-fmt',label:'All Formats',options:[...new Set(archives.map(a=>a.format))]}], onSearch:'window.__archSearch'})}
    <div id="arch-table"></div>
  `});
  renderIcons();
  renderArchTable(archives);
}

function renderArchTable(archives) {
  const el = document.getElementById('arch-table');
  el.innerHTML = archives.length ? table({
    columns:[{label:'Record'},{label:'Category'},{label:'Year'},{label:'Format'},{label:'Retention'},{label:'Archived'},{label:'Status'},{label:'Actions',align:'right',width:'w-1'}],
    rows: archives.map(a=>[
      `<div class="flex items-center gap-2">${icon(a.format==='Digitized'?'scan-line':'archive','w-5 h-5 text-slate-400')}<p class="font-medium text-slate-800 dark:text-slate-100 line-clamp-1 max-w-xs">${a.title}</p></div>`,
      `<span class="text-xs text-slate-600 dark:text-slate-300">${a.category}</span>`,
      `<span class="text-sm font-semibold text-slate-700 dark:text-slate-200">${a.year}</span>`,
      `<span class="badge bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300">${a.format}</span>`,
      `<span class="text-xs text-slate-500">${a.retention}</span>`,
      `<span class="text-xs text-slate-500">${fmtDate(a.dateArchived)}</span>`,
      badge(a.status),
      `<div class="flex items-center justify-end gap-1"><button onclick="window.__viewArch('${a.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-500" title="Preview">${icon('eye','w-4 h-4')}</button>${a.status==='Restored'?button({label:'',icon:'rotate-ccw',variant:'ghost',size:'sm',title:'Restore',onclick:"window.__restoreArch('"+a.id+"')"}):button({label:'',icon:'refresh-cw',variant:'ghost',size:'sm',title:'Restore',onclick:"window.__restoreArch('"+a.id+"')"})}<button onclick="window.__delArch('${a.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-red-500" title="Delete">${icon('trash-2','w-4 h-4')}</button></div>`
    ])
  }) : emptyState({icon:'archive', title:'Archive is empty', action: button({label:'Archive Record', icon:'archive', variant:'primary', onclick:"window.__openArchModal()"})});
  renderIcons();
}

window.__archSearch = function(){ const q=(document.getElementById('ls-search-input')?.value||'').toLowerCase(); const cat=document.getElementById('arch-cat')?.value; const fmt=document.getElementById('arch-fmt')?.value; let a=getAll('archives'); if(q)a=a.filter(x=>(x.title+x.category+x.year).toLowerCase().includes(q)); if(cat)a=a.filter(x=>x.category===cat); if(fmt)a=a.filter(x=>x.format===fmt); renderArchTable(a); };

window.__openArchModal = function(id){ const e=id?getById('archives',id):null; modal({title:e?'Edit Archived Record':'Archive New Record', size:'md', body:`<form id="arch-form" class="space-y-4">${field({label:'Title', name:'title', value:e?.title||'', required:true})}<div class="grid sm:grid-cols-2 gap-4">${field({label:'Category', name:'category', type:'select', value:e?.category||'', options:['Ordinance','Resolution','Minutes','Historical','Plan','Other'].map(c=>({value:c,label:c}))})}${field({label:'Year', name:'year', type:'number', value:e?.year||new Date().getFullYear()})}</div><div class="grid sm:grid-cols-2 gap-4">${field({label:'Format', name:'format', type:'select', value:e?.format||'Digital', options:['Digital','Digitized','Physical (scanned)'].map(c=>({value:c,label:c}))})}${field({label:'Retention', name:'retention', type:'select', value:e?.retention||'Permanent', options:['Permanent','10 years','5 years','3 years'].map(c=>({value:c,label:c}))})}</div></form>`, footer: button({label:'Cancel', variant:'secondary', onclick:"this.closest('[id^=modal]').querySelector('[data-close]').click()"})+button({label:'Archive', variant:'primary', icon:'archive', onclick:"window.__saveArch('"+(id||'')+"')"})}); };
window.__saveArch = function(id){ const d=readForm(document.getElementById('arch-form')); d.year=parseInt(d.year)||new Date().getFullYear(); if(!d.title){toast('Title required','error');return;} if(id)update('archives',id,d); else insert('archives',{...d, dateArchived:new Date().toISOString(), status:'Archived', searchable:true}); toast('Archived','success'); document.querySelector('[id^=modal] [data-close]').click(); renderArchives(document.getElementById('ls-main'),{}); };
window.__restoreArch = function(id){ update('archives',id,{status:'Restored'}); toast('Record digitally restored','success'); renderArchives(document.getElementById('ls-main'),{}); };
window.__viewArch = function(id){ const a=getById('archives',id); modal({title:a.title, size:'md', body:`<div class="flex items-center gap-2 mb-4">${badge(a.status)}<span class="badge bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300">${a.format}</span><span class="text-xs text-slate-400">${a.year}</span></div>${card({title:'Record Details', icon:'info', body:`<dl class="text-sm space-y-2"><div class="flex justify-between"><dt class="text-slate-500">Category</dt><dd>${a.category}</dd></div><div class="flex justify-between"><dt class="text-slate-500">Year</dt><dd>${a.year}</dd></div><div class="flex justify-between"><dt class="text-slate-500">Retention</dt><dd>${a.retention}</dd></div><div class="flex justify-between"><dt class="text-slate-500">Archived</dt><dd>${fmtDate(a.dateArchived)}</dd></div></dl>`})}<div class="mt-4 p-8 rounded-lg border-2 border-dashed border-slate-200 dark:border-slate-700 text-center text-slate-400">${icon('file-search','w-12 h-12 mx-auto mb-2')}<p class="text-sm">Archive preview (simulated)</p></div>`, footer: button({label:'Close', variant:'secondary', onclick:"this.closest('[id^=modal]').querySelector('[data-close]').click()"})}); };
window.__delArch = function(id){ confirmDialog({title:'Delete archived record?', message:'This historical record will be permanently removed from the repository.', onConfirm:()=>{remove('archives',id); toast('Deleted','success'); renderArchives(document.getElementById('ls-main'),{});}}); };
