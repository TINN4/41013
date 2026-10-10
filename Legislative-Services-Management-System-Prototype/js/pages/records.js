/* ==========================================================================
   pages/records.js — Legislative Records & Document Management (Module 6)
   Repository, upload sim, preview, version history, search, audit trail.
   ========================================================================== */
import { getAll, insert, update, remove, getById } from '../store.js';
import { card, statCard, icon, badge, pageHeader, button, renderIcons, fmtDate, memberName, filterBar, table, modal, field, readForm, toast, confirmDialog, exportCSV, emptyState, aiInsight, sectionTitle } from '../ui.js';

export function renderRecords(main, route) {
  main.innerHTML = `
    ${pageHeader({title:'Records & Document Management', subtitle:'Repository, version control, metadata, and audit trails', icon:'folder-open',
      actions: button({label:'Upload Document', icon:'upload', variant:'primary', onclick:"window.__openDocModal()"})})}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">${docStats()}</div>
    <div class="grid lg:grid-cols-3 gap-4 mb-6">
      ${card({title:'Document Categories', subtitle:'Distribution by type', icon:'folder-tree', className:'lg:col-span-2', body: categoriesBody()})}
      ${aiInsight({title:'Repository Intelligence', body:`${getAll('records').length} documents across ${new Set(getAll('records').map(d=>d.category)).size} categories. ${getAll('records').filter(d=>d.status==='Final').length} are finalized. The largest file is the City Development Plan (12.5 MB). Consider archiving drafts older than 6 months to the historical repository.`})}
    </div>
    <div id="doc-list"></div>
  `;
  renderIcons();
  drawDocList();
}

function docStats() {
  const d = getAll('records');
  const totalSize = d.reduce((s,x)=> s + parseFloat(x.size||0), 0);
  return statCard({label:'Total Documents', value:d.length, icon:'file-stack', color:'primary'}) +
         statCard({label:'Final Versions', value:d.filter(x=>x.status==='Final').length, icon:'badge-check', color:'emerald'}) +
         statCard({label:'Drafts', value:d.filter(x=>x.status==='Draft').length, icon:'file-pen-line', color:'amber'}) +
         statCard({label:'Repository Size', value: totalSize.toFixed(1)+' MB', icon:'database', color:'slate'});
}

function categoriesBody() {
  const cats = {};
  getAll('records').forEach(d => { cats[d.category] = (cats[d.category]||0)+1; });
  const max = Math.max(...Object.values(cats), 1);
  return `<div class="space-y-3">${Object.entries(cats).map(([cat,count])=>`<div><div class="flex justify-between text-sm mb-1"><span class="text-slate-700 dark:text-slate-200">${cat}</span><span class="text-slate-400">${count}</span></div><div class="h-2 rounded-full bg-slate-100 dark:bg-slate-800"><div class="h-full rounded-full bg-primary-500" style="width:${(count/max*100)}%"></div></div></div>`).join('')}</div>`;
}

function drawDocList() {
  const docs = getAll('records');
  document.getElementById('doc-list').innerHTML = card({title:'Document Repository', subtitle:'Search, filter, preview, and manage versions', icon:'library-big', body:`
    ${filterBar({searchPlaceholder:'Search documents…', selects:[{id:'doc-cat',label:'All Categories',options:[...new Set(docs.map(d=>d.category))]},{id:'doc-status',label:'All Statuses',options:[...new Set(docs.map(d=>d.status))]}], onSearch:'window.__docSearch'})}
    <div id="doc-table"></div>
  `});
  renderIcons();
  renderDocTable(docs);
}

function renderDocTable(docs) {
  const el = document.getElementById('doc-table');
  el.innerHTML = docs.length ? table({
    columns:[{label:'Document'},{label:'Category'},{label:'Type'},{label:'Size'},{label:'Uploaded'},{label:'Version'},{label:'Status'},{label:'Actions',align:'right',width:'w-1'}],
    rows: docs.map(d=>[
      `<div class="flex items-center gap-2">${icon(d.type==='PDF'?'file-text':'file','w-5 h-5 '+(d.type==='PDF'?'text-red-500':'text-primary-500'))}<div><p class="font-medium text-slate-800 dark:text-slate-100 line-clamp-1 max-w-xs">${d.title}</p><p class="text-xs text-slate-400">${d.tags?.join(', ')||''}</p></div></div>`,
      `<span class="text-xs text-slate-600 dark:text-slate-300">${d.category}</span>`,
      `<span class="badge bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300">${d.type}</span>`,
      `<span class="text-xs text-slate-500">${d.size}</span>`,
      `<span class="text-xs text-slate-500">${fmtDate(d.dateUploaded)}</span>`,
      `<span class="text-xs font-medium text-primary-600">${d.version}</span>`,
      badge(d.status),
      `<div class="flex items-center justify-end gap-1"><button onclick="window.__viewDoc('${d.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-500" title="Preview & Audit">${icon('eye','w-4 h-4')}</button><button onclick="window.__openDocModal('${d.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-primary-600" title="Edit">${icon('pencil','w-4 h-4')}</button><button onclick="window.__delDoc('${d.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-red-500" title="Delete">${icon('trash-2','w-4 h-4')}</button></div>`
    ])
  }) : emptyState({icon:'folder-open', title:'No documents', action: button({label:'Upload Document', icon:'upload', variant:'primary', onclick:"window.__openDocModal()"})});
  renderIcons();
}

window.__docSearch = function(){ const q=(document.getElementById('ls-search-input')?.value||'').toLowerCase(); const cat=document.getElementById('doc-cat')?.value; const st=document.getElementById('doc-status')?.value; let docs=getAll('records'); if(q)docs=docs.filter(d=>(d.title+d.category+(d.tags||[]).join('')).toLowerCase().includes(q)); if(cat)docs=docs.filter(d=>d.category===cat); if(st)docs=docs.filter(d=>d.status===st); renderDocTable(docs); };

window.__openDocModal = function(id){ const e=id?getById('records',id):null; const members=getAll('councilMembers'); modal({title:e?'Edit Document':'Upload Document (Simulated)', size:'md', body:`<form id="doc-form" class="space-y-4">${field({label:'Title', name:'title', value:e?.title||'', required:true})}<div class="grid sm:grid-cols-2 gap-4">${field({label:'Category', name:'category', type:'select', value:e?.category||'', options:['Budget','Committee Report','Transcript','Plan','Legislation Draft','Minutes','Contract','Other'].map(c=>({value:c,label:c}))})}${field({label:'File Type', name:'type', type:'select', value:e?.type||'PDF', options:['PDF','DOCX','XLSX','PPTX','IMG'].map(c=>({value:c,label:c}))})}</div><div class="grid sm:grid-cols-2 gap-4">${field({label:'Size (MB)', name:'size', type:'number', value:e?.size?parseFloat(e.size):2.0})}${field({label:'Status', name:'status', type:'select', value:e?.status||'Draft', options:['Draft','Active','Final','Archived'].map(c=>({value:c,label:c}))})}</div>${field({label:'Tags (comma-separated)', name:'tags', value:e?.tags?.join(', ')||'', placeholder:'budget, 2024, finance'})}<div class="p-4 rounded-lg border-2 border-dashed border-slate-200 dark:border-slate-700 text-center text-sm text-slate-400">${icon('upload-cloud','w-8 h-8 mx-auto mb-2')}Click to "upload" (simulated — no actual file transfer)</div></form>`, footer: button({label:'Cancel', variant:'secondary', onclick:"this.closest('[id^=modal]').querySelector('[data-close]').click()"})+button({label:e?'Update':'Upload', variant:'primary', icon:e?'save':'upload', onclick:"window.__saveDoc('"+(id||'')+"')"})}); };
window.__saveDoc = function(id){ const d=readForm(document.getElementById('doc-form')); if(!d.title){toast('Title required','error');return;} d.size=d.size+' MB'; d.tags=d.tags?d.tags.split(',').map(t=>t.trim()).filter(Boolean):[]; if(id){d.version='v'+(parseInt(getById('records',id).version.slice(1))+1); update('records',id,{...d, audit:[...(getById('records',id).audit||[]), {action:'updated', by:'M-001', time:new Date().toISOString()}]}); } else { insert('records',{...d, uploadedBy:'M-001', dateUploaded:new Date().toISOString(), version:'v1', audit:[{action:'uploaded',by:'M-001',time:new Date().toISOString()}]}); } toast('Document '+(id?'updated':'uploaded'),'success'); document.querySelector('[id^=modal] [data-close]').click(); renderRecords(document.getElementById('ls-main'),{}); };

window.__viewDoc = function(id){ const d=getById('records',id); modal({title:d.title, size:'lg', body:`<div class="flex items-center gap-2 mb-4">${badge(d.status)}<span class="badge bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300">${d.type}</span><span class="text-xs text-slate-400">${d.size} · ${d.version}</span></div>${card({title:'Metadata', icon:'info', body:`<dl class="text-sm space-y-2 grid sm:grid-cols-2"><div><dt class="text-slate-400 text-xs">Category</dt><dd>${d.category}</dd></div><div><dt class="text-slate-400 text-xs">Uploaded by</dt><dd>${memberName(d.uploadedBy)}</dd></div><div><dt class="text-slate-400 text-xs">Date</dt><dd>${fmtDate(d.dateUploaded)}</dd></div><div><dt class="text-slate-400 text-xs">Tags</dt><dd>${(d.tags||[]).join(', ')||'—'}</dd></div></dl>`})}<div class="mt-4 p-8 rounded-lg border-2 border-dashed border-slate-200 dark:border-slate-700 text-center text-slate-400">${icon('file-text','w-12 h-12 mx-auto mb-2')}<p class="text-sm">Document preview (simulated)</p><p class="text-xs mt-1">In production, this would render the actual file content.</p></div>${sectionTitle('Audit Trail')}<div class="space-y-2">${(d.audit||[]).map(a=>`<div class="flex items-center gap-3 py-2 px-3 rounded-lg bg-slate-50 dark:bg-slate-800"><span class="w-2 h-2 rounded-full bg-primary-500"></span><span class="text-sm text-slate-700 dark:text-slate-200 flex-1 capitalize">${a.action} by ${memberName(a.by)}</span><span class="text-xs text-slate-400">${fmtDate(a.time)}</span></div>`).join('')}</div>`, footer: button({label:'Export', icon:'download', variant:'outline', onclick:"window.__docExport('"+id+"')"})+button({label:'Close', variant:'secondary', onclick:"this.closest('[id^=modal]').querySelector('[data-close]').click()"})}); };
window.__docExport = function(id){ exportCSV('document-'+id+'.csv', [getById('records',id)], ['title','category','type','size','version','status','uploadedBy','dateUploaded']); };
window.__delDoc = function(id){ confirmDialog({title:'Delete document?', message:'This document and its audit trail will be permanently removed.', onConfirm:()=>{remove('records',id); toast('Deleted','success'); renderRecords(document.getElementById('ls-main'),{});}}); };
