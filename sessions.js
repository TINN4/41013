/* ==========================================================================
   pages/sessions.js — Session & Legislative Meeting Management (Module 2)
   Scheduling, agenda, attendance, quorum, minutes, live session tracking.
   ========================================================================== */
import { getAll, insert, update, remove, getById, pushNotification } from '../store.js';
import { card, statCard, icon, badge, pageHeader, button, renderIcons, fmtDate, fmtTime, fmtDateLong, memberName, memberAvatar, aiInsight, filterBar, table, modal, field, readForm, toast, confirmDialog, exportCSV, emptyState, sectionTitle } from '../ui.js';

let activeTab = 'list';
let liveTimer = null;

export function renderSessions(main, route) {
  main.innerHTML = `
    ${pageHeader({
      title:'Sessions & Legislative Meetings',
      subtitle:'Schedule sessions, manage attendance, track quorum, and generate minutes',
      icon:'calendar-clock',
      actions: button({label:'New Session', icon:'calendar-plus', variant:'primary', onclick:"window.__openSesModal()"})
    })}

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
      ${sesStats()}
    </div>

    <div id="ses-tabs"></div>
    <div id="ses-content"></div>
  `;
  renderIcons();
  drawTabs(); drawContent();
}

function sesStats() {
  const s = getAll('sessions');
  return statCard({label:'Total Sessions', value:s.length, icon:'calendar-days', color:'primary'}) +
         statCard({label:'In Progress', value:s.filter(x=>x.status==='In Progress').length, icon:'radio', color:'emerald'}) +
         statCard({label:'Scheduled', value:s.filter(x=>x.status==='Scheduled').length, icon:'calendar-plus', color:'amber'}) +
         statCard({label:'Avg. Attendance', value:'88%', icon:'user-check', color:'slate', trend:'+4% this quarter', trendUp:true});
}

function drawTabs() {
  const s = getAll('sessions');
  document.getElementById('ses-tabs').innerHTML = tableTabs([
    {id:'list', label:'All Sessions', count:s.length},
    {id:'live', label:'Live Session'},
    {id:'minutes', label:'Meeting Minutes'}
  ], activeTab, 'window.__sesTab');
}

window.__sesTab = function(id){ activeTab=id; if(id!=='live' && liveTimer){ clearInterval(liveTimer); liveTimer=null; } drawTabs(); drawContent(); };

function tableTabs(items, activeId, onchange) {
  return `<div class="flex items-center gap-1 border-b border-slate-200 dark:border-slate-800 mb-5 overflow-x-auto no-scrollbar">
    ${items.map(it=>`<button onclick="${onchange}('${it.id}')" class="px-4 py-2.5 text-sm font-medium border-b-2 -mb-px whitespace-nowrap transition ${it.id===activeId?'border-primary-600 text-primary-600':'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-slate-200'}">${it.label}${it.count!=null?` <span class="ml-1 text-xs ${it.id===activeId?'text-primary-500':'text-slate-400'}">(${it.count})</span>`:''}</button>`).join('')}
  </div>`;
}

function drawContent() {
  const el = document.getElementById('ses-content');
  if (activeTab === 'live') { el.innerHTML = liveView(); renderIcons(); startLiveTimer(); return; }
  if (activeTab === 'minutes') { el.innerHTML = minutesView(); renderIcons(); return; }

  const sessions = getAll('sessions');
  el.innerHTML = `
    ${filterBar({searchPlaceholder:'Search sessions…', selects:[{id:'ses-type',label:'All Types',options:['Regular','Special','Joint']},{id:'ses-status',label:'All Statuses',options:['Scheduled','In Progress','Concluded']}], onSearch:'window.__sesSearch'})}
    ${sessions.length ? table({
      columns:[{label:'Session'},{label:'Type'},{label:'Date & Time'},{label:'Venue'},{label:'Attendance'},{label:'Status'},{label:'Actions',align:'right',width:'w-1'}],
      rows: sessions.map(s=>{
        const present = s.attendance.filter(a=>a.status==='present'||a.status==='late').length;
        const total = s.attendance.length;
        return [
          `<div><p class="font-medium text-slate-800 dark:text-slate-100">${s.title}</p><p class="text-xs text-slate-500">${s.agendaCount} agenda items</p></div>`,
          `<span class="text-xs text-slate-600 dark:text-slate-300">${s.type}</span>`,
          `<div><p class="text-sm">${fmtDate(s.date)}</p><p class="text-xs text-slate-400">${fmtTime(s.time)}</p></div>`,
          `<span class="text-xs text-slate-600 dark:text-slate-300">${s.venue}</span>`,
          total ? `<div class="flex items-center gap-2"><div class="w-16 h-2 rounded-full bg-slate-200 dark:bg-slate-700 overflow-hidden"><div class="h-full bg-emerald-500" style="width:${(present/total*100)}%"></div></div><span class="text-xs">${present}/${total}</span></div>` : '<span class="text-xs text-slate-400">—</span>',
          badge(s.status),
          `<div class="flex items-center justify-end gap-1">
            <button onclick="window.__viewSes('${s.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-500" title="View">${icon('eye','w-4 h-4')}</button>
            <button onclick="window.__openSesModal('${s.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-primary-600" title="Edit">${icon('pencil','w-4 h-4')}</button>
            <button onclick="window.__delSes('${s.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-red-500" title="Delete">${icon('trash-2','w-4 h-4')}</button>
          </div>`
        ];
      })
    }) : emptyState({icon:'calendar-clock', title:'No sessions yet', action: button({label:'New Session', icon:'plus', variant:'primary', onclick:"window.__openSesModal()"})})}
  `;
  renderIcons();
}

window.__sesSearch = function(){ const q=(document.getElementById('ls-search-input')?.value||'').toLowerCase(); const ty=document.getElementById('ses-type')?.value; const st=document.getElementById('ses-status')?.value; const rows=Array.from(document.querySelectorAll('#ses-content tbody tr')); /* simple re-render */ drawContent(); };

/* ----------------------- Live session tracking ----------------------- */
function liveView() {
  const live = getAll('sessions').find(s=>s.status==='In Progress') || getAll('sessions')[0];
  if (!live) return emptyState({icon:'radio', title:'No live session', subtitle:'Start a session to track it live.'});
  const members = getAll('councilMembers');
  const present = live.attendance.filter(a=>a.status==='present').length;
  const quorum = Math.ceil(members.length/2)+1;
  const hasQuorum = present >= quorum;
  return `
    <div class="grid lg:grid-cols-3 gap-4">
      <div class="lg:col-span-2 space-y-4">
        ${card({title:'Live Session Tracker', subtitle:live.title, icon:'radio', headerClass:'border-emerald-200 dark:border-emerald-800',
          action:`<span class="flex items-center gap-2 text-emerald-600 text-sm font-medium"><span class="w-2.5 h-2.5 bg-emerald-500 rounded-full ls-pulse-dot"></span>LIVE</span>`,
          body:`
            <div class="grid sm:grid-cols-3 gap-4 mb-5">
              <div class="text-center p-4 rounded-xl bg-slate-50 dark:bg-slate-800">
                <p class="text-xs text-slate-400 uppercase tracking-wider">Elapsed</p>
                <p id="live-elapsed" class="text-3xl font-bold text-primary-600 tabular-nums">00:00:00</p>
              </div>
              <div class="text-center p-4 rounded-xl bg-slate-50 dark:bg-slate-800">
                <p class="text-xs text-slate-400 uppercase tracking-wider">Agenda Items</p>
                <p class="text-3xl font-bold text-slate-700 dark:text-slate-200">${live.agendaCount}</p>
              </div>
              <div class="text-center p-4 rounded-xl ${hasQuorum?'bg-emerald-50 dark:bg-emerald-900/20':'bg-red-50 dark:bg-red-900/20'}">
                <p class="text-xs text-slate-400 uppercase tracking-wider">Quorum</p>
                <p class="text-3xl font-bold ${hasQuorum?'text-emerald-600':'text-red-600'}">${present}/${quorum}</p>
              </div>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
              ${button({label:'Start Timer', icon:'play', variant:'success', onclick:"window.__liveStart()"})}
              ${button({label:'Pause', icon:'pause', variant:'secondary', onclick:"window.__livePause()"})}
              ${button({label:'End Session', icon:'square', variant:'danger', onclick:"window.__liveEnd('"+live.id+"')"})}
            </div>
        `})}

        ${card({title:'Attendance & Quorum', icon:'users', body:`
          <div class="grid sm:grid-cols-3 gap-3 mb-4">
            <div class="p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 text-center"><p class="text-2xl font-bold text-emerald-600">${live.attendance.filter(a=>a.status==='present').length}</p><p class="text-xs text-slate-500">Present</p></div>
            <div class="p-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 text-center"><p class="text-2xl font-bold text-amber-600">${live.attendance.filter(a=>a.status==='late').length}</p><p class="text-xs text-slate-500">Late</p></div>
            <div class="p-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-center"><p class="text-2xl font-bold text-red-600">${live.attendance.filter(a=>a.status==='absent').length}</p><p class="text-xs text-slate-500">Absent</p></div>
          </div>
          <div class="space-y-2">${live.attendance.map(a=>{const m=getById('councilMembers',a.memberId); if(!m)return''; const c=a.status==='present'?'text-emerald-600':a.status==='late'?'text-amber-600':'text-red-600'; const ic=a.status==='present'?'check-circle-2':a.status==='late'?'clock':'x-circle'; return `<div class="flex items-center gap-3 p-2 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800">${memberAvatar(a.memberId)}<span class="text-sm flex-1">${m.name}</span><span class="flex items-center gap-1 text-xs ${c}">${icon(ic,'w-4 h-4')}${a.status}</span></div>`;}).join('')}</div>
        `})}
      </div>

      <div class="space-y-4">
        ${card({title:'Session Info', icon:'info', body:`<dl class="text-sm space-y-2">
          <div class="flex justify-between"><dt class="text-slate-500">Date</dt><dd class="font-medium">${fmtDateLong(live.date)}</dd></div>
          <div class="flex justify-between"><dt class="text-slate-500">Time</dt><dd class="font-medium">${fmtTime(live.time)}</dd></div>
          <div class="flex justify-between"><dt class="text-slate-500">Venue</dt><dd class="font-medium">${live.venue}</dd></div>
          <div class="flex justify-between"><dt class="text-slate-500">Type</dt><dd class="font-medium">${live.type}</dd></div>
        </dl>`})}
        ${aiInsight({title:'AI Session Brief', body:`Quorum ${hasQuorum?'is met':'NOT met'} with ${present} of ${quorum} required members present. ${live.agendaCount} agenda items are queued. Estimated session duration: ${live.agendaCount*35} minutes. ${hasQuorum?'Proceed with deliberations.':'Consider notifying absent members.'}`})}
      </div>
    </div>
  `;
}

let elapsed = 0, running = false;
function startLiveTimer(){ elapsed=0; running=false; }
window.__liveStart = function(){ if(running)return; running=true; liveTimer=setInterval(()=>{ elapsed++; updateElapsed(); },1000); toast('Timer started','success'); };
window.__livePause = function(){ running=false; if(liveTimer)clearInterval(liveTimer); toast('Timer paused','warning'); };
window.__liveEnd = function(id){ if(liveTimer)clearInterval(liveTimer); running=false; update('sessions', id, {status:'Concluded', duration:Math.round(elapsed/60)}); toast('Session concluded','success'); activeTab='list'; drawTabs(); drawContent(); };
function updateElapsed(){ const el=document.getElementById('live-elapsed'); if(!el)return; const h=String(Math.floor(elapsed/3600)).padStart(2,'0'); const m=String(Math.floor((elapsed%3600)/60)).padStart(2,'0'); const s=String(elapsed%60).padStart(2,'0'); el.textContent=`${h}:${m}:${s}`; }

/* ----------------------- Minutes ----------------------- */
function minutesView() {
  const concluded = getAll('sessions').filter(s=>s.status==='Concluded');
  if (!concluded.length) return emptyState({icon:'file-text', title:'No concluded sessions', subtitle:'Minutes are generated after a session ends.'});
  const sel = concluded[0];
  return `
    <div class="grid lg:grid-cols-3 gap-4">
      <div class="space-y-2">
        ${concluded.map(s=>`<button onclick="window.__selMin('${s.id}')" class="w-full text-left p-3 rounded-lg border border-slate-200 dark:border-slate-800 hover:border-primary-400 transition ${s.id===sel.id?'border-primary-500 bg-primary-50 dark:bg-primary-900/20':''}"><p class="text-sm font-medium text-slate-800 dark:text-slate-100 truncate">${s.title}</p><p class="text-xs text-slate-400">${fmtDate(s.date)}</p></button>`).join('')}
      </div>
      <div class="lg:col-span-2" id="min-detail">${minutesDetail(sel)}</div>
    </div>
  `;
}
window.__selMin = function(id){ const s=getById('sessions',id); document.getElementById('min-detail').innerHTML=minutesDetail(s); renderIcons(); };
function minutesDetail(s) {
  const mins = Math.round(s.duration||0);
  const present = s.attendance.filter(a=>a.status!=='absent');
  return card({title:'Generated Meeting Minutes', subtitle:s.title, icon:'file-text', action: button({label:'Print', icon:'printer', variant:'outline', size:'sm', onclick:'window.print()'}),
    body:`
      <div class="prose prose-sm max-w-none dark:prose-invert">
        <p class="text-sm text-slate-600 dark:text-slate-300"><b>Date:</b> ${fmtDateLong(s.date)} at ${fmtTime(s.time)} · <b>Venue:</b> ${s.venue} · <b>Duration:</b> ${mins} minutes</p>
        <h3 class="font-semibold text-slate-800 dark:text-white mt-4 mb-2">Call to Order</h3>
        <p class="text-sm text-slate-600 dark:text-slate-300">The session was called to order at ${fmtTime(s.time)} by the presiding officer, Hon. Ricardo Almazan, City Secretary.</p>
        <h3 class="font-semibold text-slate-800 dark:text-white mt-4 mb-2">Roll Call & Quorum</h3>
        <p class="text-sm text-slate-600 dark:text-slate-300">${present.length} members were present, constituting a quorum. The following were in attendance: ${present.map(a=>memberName(a.memberId)).join(', ')}.</p>
        <h3 class="font-semibold text-slate-800 dark:text-white mt-4 mb-2">Agenda Items</h3>
        <ol class="list-decimal list-inside text-sm text-slate-600 dark:text-slate-300 space-y-1">
          <li>Approval of the minutes of the previous session</li>
          <li>Second reading of pending ordinances</li>
          <li>Committee reports</li>
          <li>Public hearing outcomes</li>
          <li>Other matters</li>
        </ol>
        <h3 class="font-semibold text-slate-800 dark:text-white mt-4 mb-2">Adjournment</h3>
        <p class="text-sm text-slate-600 dark:text-slate-300">Having no further business, the session was adjourned at approximately ${fmtTime(String(Number(s.time.slice(0,2))+Math.floor(mins/60)).padStart(2,'0')+':'+String((mins%60)+Number(s.time.slice(3,5))).padStart(2,'0'))}.</p>
      </div>
      <div class="mt-4">${aiInsight({title:'AI-Generated Minutes', body:'These minutes were auto-generated from session metadata for demonstration. In production, this would integrate speech-to-text and structured agenda tracking.'})}</div>
    `});
}

/* ----------------------- Modal ----------------------- */
window.__openSesModal = function(id) {
  const existing = id ? getById('sessions', id) : null;
  const members = getAll('councilMembers');
  modal({
    title: existing?'Edit Session':'New Session', size:'lg',
    body:`<form id="ses-form" class="space-y-4">
      ${field({label:'Title', name:'title', value:existing?.title||'', required:true, placeholder:'e.g. Regular Session — 43rd Regular Session'})}
      <div class="grid sm:grid-cols-2 gap-4">
        ${field({label:'Type', name:'type', type:'select', value:existing?.type||'Regular', options:['Regular','Special','Joint'].map(t=>({value:t,label:t}))})}
        ${field({label:'Status', name:'status', type:'select', value:existing?.status||'Scheduled', options:['Scheduled','In Progress','Concluded'].map(t=>({value:t,label:t}))})}
      </div>
      <div class="grid sm:grid-cols-2 gap-4">
        ${field({label:'Date', name:'date', type:'date', value:existing?.date?.slice(0,10)||''})}
        ${field({label:'Time', name:'time', type:'time', value:existing?.time||'09:00'})}
      </div>
      ${field({label:'Venue', name:'venue', value:existing?.venue||'', placeholder:'e.g. Session Hall, 3rd Floor'})}
      ${field({label:'Agenda Item Count', name:'agendaCount', type:'number', value:existing?.agendaCount||0})}
      <div>
        <span class="block text-xs font-medium text-slate-600 dark:text-slate-300 mb-1.5">Attendance (auto-add all members)</span>
        <div class="space-y-1.5 max-h-40 overflow-y-auto p-2 rounded-lg border border-slate-200 dark:border-slate-700">
          ${members.map(m=>`<label class="flex items-center gap-2 text-sm"><input type="checkbox" data-att="${m.id}" ${existing?.attendance.some(a=>a.memberId===m.id)?'checked':''} class="rounded"> ${memberName(m.id)}</label>`).join('')}
        </div>
      </div>
    </form>`,
    footer: button({label:'Cancel', variant:'secondary', onclick:"this.closest('[id^=modal]').querySelector('[data-close]').click()"}) +
            button({label:existing?'Update':'Schedule', variant:'primary', icon:'save', onclick:"window.__saveSes('"+(id||'')+"')"})
  });
};

window.__saveSes = function(id) {
  const data = readForm(document.getElementById('ses-form'));
  if (!data.title) { toast('Title required','error'); return; }
  const members = getAll('councilMembers');
  const checked = Array.from(document.querySelectorAll('[data-att]:checked')).map(c=>c.dataset.att);
  data.attendance = checked.map(mid=>({memberId:mid, status:'present'}));
  data.agendaCount = parseInt(data.agendaCount)||0;
  if (id) { update('sessions', id, data); toast('Session updated','success'); }
  else { insert('sessions', {...data, duration:0}); pushNotification({title:'New session scheduled', body:data.title, icon:'calendar-clock', color:'blue'}); toast('Session scheduled','success'); }
  document.querySelector('[id^=modal] [data-close]').click();
  drawTabs(); drawContent();
};

window.__viewSes = function(id){ const s=getById('sessions',id); modal({title:s.title, size:'lg', body:`<div class="space-y-3">${card({title:'Details',icon:'info',body:`<dl class="text-sm space-y-2"><div class="flex justify-between"><dt class="text-slate-500">Date</dt><dd>${fmtDateLong(s.date)}</dd></div><div class="flex justify-between"><dt class="text-slate-500">Time</dt><dd>${fmtTime(s.time)}</dd></div><div class="flex justify-between"><dt class="text-slate-500">Venue</dt><dd>${s.venue}</dd></div><div class="flex justify-between"><dt class="text-slate-500">Type</dt><dd>${s.type}</dd></div><div class="flex justify-between"><dt class="text-slate-500">Status</dt><dd>${badge(s.status)}</dd></div></dl>`})}</div>`, footer: button({label:'Close', variant:'secondary', onclick:"this.closest('[id^=modal]').querySelector('[data-close]').click()"})}); };

window.__delSes = function(id){ const s=getById('sessions',id); confirmDialog({title:'Delete session?', message:`"${s.title}" will be removed.`, onConfirm:()=>{remove('sessions',id); toast('Deleted','success'); drawTabs(); drawContent();}}); };
