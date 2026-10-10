/* ==========================================================================
   pages/ordinances.js — Ordinance & Resolution Lifecycle Management (Module 1)
   Drafting → review → committee → approval → publication → monitoring
   Includes version history, AI summarization, search/filter/export/print.
   ========================================================================== */
import { getAll, insert, update, remove, getById } from '../store.js';
import { card, statCard, icon, badge, pageHeader, button, renderIcons, fmtDate, memberName, memberAvatar, committeeName, aiInsight, filterBar, table, tabs, modal, field, readForm, toast, confirmDialog, exportCSV, printPage, emptyState, sectionTitle } from '../ui.js';

let activeTab = 'ordinances';
let lastSearch = '', lastStatus = '', lastCategory = '';

export function renderOrdinances(main, route) {
  main.innerHTML = `
    ${pageHeader({
      title:'Ordinance & Resolution Lifecycle',
      subtitle:'Draft, review, endorse, approve, publish, and monitor legislative measures',
      icon:'scale',
      actions: button({label:'New Ordinance', icon:'file-plus', variant:'primary', onclick:"window.__openOrdModal()"}) +
               button({label:'Export', icon:'download', variant:'outline', onclick:"window.__expOrd()"})
    })}

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
      ${ordStats()}
    </div>

    <div id="ord-tabs"></div>

    <div id="ord-content"></div>
  `;
  renderIcons();
  drawTabs();
  drawContent();
}

function ordStats() {
  const o = getAll('ordinances');
  const r = getAll('resolutions');
  return statCard({label:'Total Ordinances', value:o.length, icon:'scroll-text', color:'primary', sub:`${o.filter(x=>x.status==='Enacted').length} enacted`}) +
         statCard({label:'Total Resolutions', value:r.length, icon:'file-text', color:'emerald', sub:`${r.filter(x=>x.status==='Adopted').length} adopted`}) +
         statCard({label:'In Committee Review', value:[...o,...r].filter(x=>x.status==='Pending Review').length, icon:'git-commit', color:'amber', sub:'Awaiting endorsement'}) +
         statCard({label:'Avg. Days to Enact', value:'48', icon:'timer', color:'slate', sub:'From introduction to publication', trend:'-12% vs last year', trendUp:true});
}

function drawTabs() {
  const o = getAll('ordinances');
  const r = getAll('resolutions');
  const el = document.getElementById('ord-tabs');
  el.innerHTML = tabs([
    { id:'ordinances', label:'Ordinances', count:o.length },
    { id:'resolutions', label:'Resolutions', count:r.length },
    { id:'lifecycle', label:'Lifecycle Workflow' }
  ], activeTab, 'window.__ordTab');
}

window.__ordTab = function(id){ activeTab = id; drawTabs(); drawContent(); };

function drawContent() {
  const el = document.getElementById('ord-content');
  if (activeTab === 'lifecycle') { el.innerHTML = lifecycleView(); renderIcons(); return; }

  const collection = activeTab === 'ordinances' ? 'ordinances' : 'resolutions';
  const records = filterRecords(collection);

  el.innerHTML = `
    ${filterBar({
      searchPlaceholder: 'Search by title, number, or author…',
      selects: [
        { id:'ord-status', label:'All Statuses', options:[...new Set(getAll(collection).map(r=>r.status))] },
        { id:'ord-category', label:'All Categories', options:[...new Set(getAll(collection).map(r=>r.category||'General'))] }
      ],
      onSearch: 'window.__ordSearch',
      right: button({label:activeTab==='ordinances'?'New Ordinance':'New Resolution', icon:'plus', size:'sm', variant:'primary', onclick:"window.__openOrdModal()"})
    })}
    ${records.length ? table({
      columns: [
        { label:'Number / Title' },
        { label:'Author' },
        { label:'Category' },
        { label:'Committee' },
        { label:'Date' },
        { label:'Status' },
        { label:'Actions', align:'right', width:'w-1' }
      ],
      rows: records.map(r => [
        `<div><p class="font-medium text-slate-800 dark:text-slate-100">${r.number}</p><p class="text-xs text-slate-500 line-clamp-1 max-w-xs">${r.title}</p></div>`,
        `<div class="flex items-center gap-2">${memberAvatar(r.author)}<span class="text-sm">${memberName(r.author)}</span></div>`,
        `<span class="text-xs text-slate-600 dark:text-slate-300">${r.category||'General'}</span>`,
        `<span class="text-xs text-slate-600 dark:text-slate-300">${committeeName(r.committeeId)}</span>`,
        `<span class="text-xs text-slate-500">${fmtDate(r.dateIntroduced)}</span>`,
        badge(r.status),
        `<div class="flex items-center justify-end gap-1">
          <button onclick="window.__viewOrd('${r.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-500" title="View">${icon('eye','w-4 h-4')}</button>
          <button onclick="window.__advanceOrd('${r.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-500" title="Advance Stage">${icon('git-commit-horizontal','w-4 h-4')}</button>
          <button onclick="window.__editOrd('${r.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-primary-600" title="Edit">${icon('pencil','w-4 h-4')}</button>
          <button onclick="window.__delOrd('${r.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-red-500" title="Delete">${icon('trash-2','w-4 h-4')}</button>
        </div>`
      ])
    }) : emptyState({ icon:'scale', title:'No measures found', subtitle:'Try adjusting filters or create a new one.', action: button({label:activeTab==='ordinances'?'New Ordinance':'New Resolution', icon:'plus', variant:'primary', onclick:"window.__openOrdModal()"}) })}
  `;
  renderIcons();
}

function filterRecords(collection) {
  let records = getAll(collection);
  if (lastSearch) {
    const q = lastSearch.toLowerCase();
    records = records.filter(r => (r.title+r.number+(r.category||'')).toLowerCase().includes(q));
  }
  if (lastStatus) records = records.filter(r => r.status === lastStatus);
  if (lastCategory) records = records.filter(r => (r.category||'General') === lastCategory);
  return records;
}

window.__ordSearch = function() {
  lastSearch = document.getElementById('ls-search-input')?.value || '';
  const ss = document.getElementById('ord-status'); if (ss) lastStatus = ss.value;
  const sc = document.getElementById('ord-category'); if (sc) lastCategory = sc.value;
  drawContent();
};

/* ----------------------- Lifecycle visual ----------------------- */
function lifecycleView() {
  const stages = ['Drafting','Committee Review','Pending Review','Approved','Published','Enacted'];
  const counts = stages.map(s => getAll('ordinances').filter(o=>o.stage===s).length + getAll('resolutions').filter(r=>r.status===s).length);
  return `
    ${card({title:'Legislative Lifecycle Workflow', subtitle:'Track measures through every stage from drafting to enactment', icon:'git-branch',
      body:`<div class="overflow-x-auto pb-2"><div class="flex items-center gap-1 min-w-[800px]">
        ${stages.map((s,i)=>`
          <div class="flex items-center">
            <div class="flex flex-col items-center w-32 text-center">
              <div class="w-12 h-12 rounded-full ${i===0?'bg-slate-300 text-white':i<3?'bg-amber-500 text-white':i<5?'bg-primary-600 text-white':'bg-emerald-600 text-white'} flex items-center justify-center font-bold">${i+1}</div>
              <p class="text-xs font-medium mt-2 text-slate-700 dark:text-slate-200">${s}</p>
              <span class="text-xs text-slate-400 mt-0.5">${counts[i]} items</span>
            </div>
            ${i<stages.length-1?`<div class="h-0.5 w-8 bg-gradient-to-r from-slate-300 to-slate-300 dark:from-slate-600 dark:to-slate-600"></div>`:''}
          </div>`).join('')}
      </div></div>`})}

    ${card({title:'How It Works', subtitle:'Simulated end-to-end workflow', icon:'info',
      body:`<div class="grid sm:grid-cols-2 gap-4 text-sm">
        ${[
          {icon:'file-edit',title:'1 · Drafting',body:'Author creates the measure; status = Drafting. Saved with a summary.'},
          {icon:'git-commit',title:'2 · Committee Review',body:'Assigned committee reviews and endorses; status advances automatically.'},
          {icon:'eye',title:'3 · Pending Review',body:'Placed on the session agenda for first/second reading.'},
          {icon:'check-circle',title:'4 · Approved',body:'Council approves on third reading; status = Approved.'},
          {icon:'newspaper',title:'5 · Published',body:'Published in the official gazette; previous version archived.'},
          {icon:'landmark',title:'6 · Enacted',body:'Takes effect; implementation monitored by the originating committee.'},
        ].map(s=>`<div class="flex gap-3"><span class="w-9 h-9 rounded-lg bg-primary-50 dark:bg-primary-900/30 text-primary-600 dark:text-primary-300 flex items-center justify-center shrink-0">${icon(s.icon,'w-4 h-4')}</span><div><p class="font-semibold text-slate-800 dark:text-slate-100">${s.title}</p><p class="text-slate-600 dark:text-slate-300 mt-0.5">${s.body}</p></div></div>`).join('')}
      </div>`})}
  `;
}

/* ----------------------- Create / Edit modal ----------------------- */
window.__openOrdModal = function(id) {
  const isRes = activeTab === 'resolutions';
  const members = getAll('councilMembers');
  const committees = getAll('committees');
  const existing = id ? getById(isRes?'resolutions':'ordinances', id) : null;

  const m = modal({
    title: existing ? `Edit ${isRes?'Resolution':'Ordinance'}` : `New ${isRes?'Resolution':'Ordinance'}`,
    size: 'lg',
    body: `<form id="ord-form" class="space-y-4">
      ${field({label:'Measure Number', name:'number', value:existing?.number||(isRes?'RES-2024-':'ORD-2024-')+String(getAll(isRes?'resolutions':'ordinances').length+1).padStart(3,'0'), required:true, placeholder:'e.g. ORD-2024-006'})}
      ${field({label:'Title', name:'title', value:existing?.title||'', required:true, placeholder:'An Ordinance…'})}
      <div class="grid sm:grid-cols-2 gap-4">
        ${field({label:'Author', name:'author', type:'select', value:existing?.author||'', required:true, options:members.map(mm=>({value:mm.id,label:mm.name}))})}
        ${field({label:'Category', name:'category', type:'select', value:existing?.category||'', options:['Environment','Education','Finance','Health','Transportation','Housing','Governance','Public Safety','Sanitation','Ceremonial','Economy'].map(c=>({value:c,label:c}))})}
      </div>
      <div class="grid sm:grid-cols-2 gap-4">
        ${field({label:'Committee', name:'committeeId', type:'select', value:existing?.committeeId||'', options:committees.map(c=>({value:c.id,label:c.name}))})}
        ${field({label:'Status', name:'status', type:'select', value:existing?.status||'Drafting', options:['Drafting','Pending Review','Committee Review','Approved','Enacted','Published','Adopted'].map(s=>({value:s,label:s}))})}
      </div>
      ${field({label:'Summary / Body', name:'summary', type:'textarea', value:existing?.summary||'', placeholder:'Brief description of the measure…'})}
      <div id="ord-ai-box"></div>
    </form>`,
    footer: button({label:'Cancel', variant:'secondary', onclick:"this.closest('[id^=modal]').querySelector('[data-close]').click()"}) +
            button({label:existing?'Update':'Save as Draft', variant:'primary', icon:'save', onclick:"window.__saveOrd('"+(id||'')+"')"})
  });
};

window.__saveOrd = function(id) {
  const form = document.getElementById('ord-form');
  const data = readForm(form);
  if (!data.title || !data.number) { toast('Title and number are required','error'); return; }
  const isRes = activeTab === 'resolutions';
  const coll = isRes ? 'resolutions' : 'ordinances';
  data.stage = isRes ? (data.status==='Adopted'?'Adopted':data.status) : data.status;
  if (!data.aiSummary) data.aiSummary = generateAISummary(data);
  if (id) { update(coll, id, data); toast('Updated successfully','success'); }
  else { insert(coll, { ...data, dateIntroduced: new Date().toISOString(), versions:1 }); toast('Created — status set to '+data.status,'success'); }
  document.querySelector('[id^=modal] [data-close]').click();
  drawTabs(); drawContent();
};

function generateAISummary(d) {
  const cat = d.category || 'general governance';
  return `This ${d.number.startsWith('RES')?'resolution':'ordinance'} addresses ${cat.toLowerCase()} matters${d.summary?': "'+d.summary.slice(0,120)+'…"':''}. Proposed by ${memberName(d.author||'M-001')}. ${d.committeeId?`Routed to the ${committeeName(d.committeeId)} for review.`:'No committee assigned yet.'} AI-generated summary for demonstration purposes.`;
}

/* ----------------------- View detail ----------------------- */
window.__viewOrd = function(id) {
  const isRes = activeTab==='resolutions';
  const r = getById(isRes?'resolutions':'ordinances', id);
  if (!r) return;
  const versions = Array.from({length: r.versions||1}, (_,i)=>({ v:`v${i+1}`, date: fmtDate(new Date(Date.now()-(r.versions-1-i)*86400000*7).toISOString()), author: memberName(r.author), notes: i===0?'Initial draft':i===(r.versions||1)-1?'Current version':'Revised after committee feedback' }));

  modal({
    title: r.number, size: 'xl',
    body: `
      <div class="flex items-center gap-2 mb-4">${badge(r.status)}${badge(r.category||'General','bg-slate-100 text-slate-600')}<span class="text-xs text-slate-400 ml-auto">Introduced ${fmtDate(r.dateIntroduced)}</span></div>
      <h2 class="text-lg font-bold text-slate-800 dark:text-white mb-2">${r.title}</h2>
      <div class="flex items-center gap-2 mb-4">${memberAvatar(r.author)}<div><p class="text-sm font-medium">${memberName(r.author)}</p><p class="text-xs text-slate-400">Sponsor</p></div></div>

      <div class="grid sm:grid-cols-2 gap-4 mb-4">
        ${card({title:'Details', icon:'info', body:`<dl class="text-sm space-y-2">
          <div class="flex justify-between"><dt class="text-slate-500">Committee</dt><dd class="font-medium">${committeeName(r.committeeId)}</dd></div>
          <div class="flex justify-between"><dt class="text-slate-500">Approved</dt><dd class="font-medium">${fmtDate(r.dateApproved)}</dd></div>
          <div class="flex justify-between"><dt class="text-slate-500">Published</dt><dd class="font-medium">${fmtDate(r.datePublished)}</dd></div>
          <div class="flex justify-between"><dt class="text-slate-500">Versions</dt><dd class="font-medium">${r.versions||1}</dd></div>
        </dl>`})}
        ${card({title:'Version History', icon:'git-commit-vertical', body:`<div class="space-y-2">${versions.map(v=>`<div class="flex items-center gap-2 text-sm"><span class="badge bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300">${v.v}</span><span class="text-slate-600 dark:text-slate-300 flex-1 truncate">${v.notes}</span><span class="text-xs text-slate-400">${v.date}</span></div>`).join('')}</div>`})}
      </div>

      ${card({title:'Measure Summary', icon:'align-left', body:`<p class="text-sm text-slate-700 dark:text-slate-200 leading-relaxed">${r.summary||'No summary provided.'}</p>`})}
      ${r.aiSummary ? `<div class="mt-4">${aiInsight({title:'AI Summarization', body:r.aiSummary})}</div>` : ''}
    `,
    footer: button({label:'Print', icon:'printer', variant:'outline', onclick:'window.print()'}) +
            button({label:'Close', variant:'secondary', onclick:"this.closest('[id^=modal]').querySelector('[data-close]').click()"})
  });
};

/* ----------------------- Advance stage ----------------------- */
window.__advanceOrd = function(id) {
  const isRes = activeTab==='resolutions';
  const coll = isRes?'resolutions':'ordinances';
  const r = getById(coll, id);
  if (!r) return;
  const flow = isRes ? ['Drafting','Pending Review','Committee Review','Approved','Adopted'] : ['Drafting','Committee Review','Pending Review','Approved','Published','Enacted'];
  const idx = flow.indexOf(r.status);
  if (idx === -1 || idx === flow.length-1) { toast(r.status==='Enacted'||r.status==='Adopted'?'Already '+r.status:'Cannot advance','warning'); return; }
  const next = flow[idx+1];
  const patch = { status: next, stage: next };
  if (next === 'Approved') patch.dateApproved = new Date().toISOString();
  if (next === 'Published' || next === 'Enacted') patch.datePublished = new Date().toISOString();
  if ((next === 'Published' || next === 'Enacted') && r.versions) patch.versions = r.versions + 1;
  update(coll, id, patch);
  toast(`Advanced to "${next}"`,'success');
  drawTabs(); drawContent();
};

window.__editOrd = function(id){ activeTab = activeTab; window.__openOrdModal(id); };

window.__delOrd = function(id) {
  const isRes = activeTab==='resolutions';
  const coll = isRes?'resolutions':'ordinances';
  const r = getById(coll, id);
  confirmDialog({ title:'Delete measure?', message:`"${r.number}" will be permanently removed. This action cannot be undone.`, confirmLabel:'Delete',
    onConfirm: () => { remove(coll, id); toast('Deleted','success'); drawTabs(); drawContent(); } });
};

window.__expOrd = function() {
  const isRes = activeTab==='resolutions';
  const coll = isRes?'resolutions':'ordinances';
  exportCSV(isRes?'resolutions.csv':'ordinances.csv', getAll(coll), ['number','title','author','category','committeeId','status','dateIntroduced','dateApproved']);
};
