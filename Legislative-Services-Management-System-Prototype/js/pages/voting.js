/* ==========================================================================
   pages/voting.js — Voting, Quorum & Decision Support System (Module 5)
   Quorum verification, manual/electronic voting sim, tallying, charts, reports.
   ========================================================================== */
import { getAll, insert, update, remove, getById } from '../store.js';
import { card, statCard, icon, badge, pageHeader, button, renderIcons, memberName, memberAvatar, fmtDate, table, modal, field, readForm, toast, confirmDialog, exportCSV, emptyState, aiInsight, sectionTitle } from '../ui.js';
import { doughnutChart, barChart, PALETTE } from '../charts.js';

export function renderVoting(main, route) {
  main.innerHTML = `
    ${pageHeader({title:'Voting, Quorum & Decisions', subtitle:'Quorum verification, electronic voting simulation, tallying & reports', icon:'vote',
      actions: button({label:'New Vote', icon:'plus', variant:'primary', onclick:"window.__openVoteModal()"})})}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">${voteStats()}</div>
    <div class="grid lg:grid-cols-3 gap-4 mb-6">
      ${card({title:'Quorum Calculator', subtitle:'Verify session quorum in real time', icon:'users', className:'lg:col-span-1', body: quorumBody()})}
      ${card({title:'Vote Outcomes', subtitle:'Results distribution', icon:'pie-chart', className:'lg:col-span-1', body:`<div class="ls-chart-wrap h-56"><canvas id="voteResults"></canvas></div>`})}
      ${card({title:'Participation Trend', subtitle:'Voter turnout per session', icon:'trending-up', className:'lg:col-span-1', body:`<div class="ls-chart-wrap h-56"><canvas id="voteTrend"></canvas></div>`})}
    </div>
    <div id="vote-list"></div>
  `;
  renderIcons();
  drawVoteList();
  drawVoteCharts();
  setupQuorum();
}

function voteStats() {
  const v = getAll('votes');
  return statCard({label:'Total Votes', value:v.length, icon:'vote', color:'primary'}) +
         statCard({label:'Passed', value:v.filter(x=>x.result==='Passed'||x.result==='Unanimous').length, icon:'check-circle-2', color:'emerald'}) +
         statCard({label:'Failed', value:v.filter(x=>x.result==='Failed').length, icon:'x-circle', color:'red'}) +
         statCard({label:'Avg. Turnout', value:'89%', icon:'percent', color:'amber'});
}

function quorumBody() {
  const members = getAll('councilMembers');
  const quorum = Math.ceil(members.length/2)+1;
  return `
    <p class="text-sm text-slate-600 dark:text-slate-300 mb-3">Required quorum: <b>${quorum}</b> of ${members.length} members (majority + 1).</p>
    <div class="space-y-2 max-h-48 overflow-y-auto mb-3 p-2 rounded-lg border border-slate-200 dark:border-slate-700">
      ${members.map(m=>`<label class="flex items-center gap-2 text-sm cursor-pointer"><input type="checkbox" data-quorum="${m.id}" checked class="rounded quorum-cb"> ${memberAvatar(m.id,'w-6 h-6 text-[10px]')} <span>${m.name}</span></label>`).join('')}
    </div>
    <div id="quorum-result" class="p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 text-center">
      <p class="text-xs text-slate-400 uppercase">Status</p>
      <p id="quorum-status" class="text-lg font-bold text-emerald-600">Quorum Met</p>
      <p id="quorum-count" class="text-sm text-slate-500 mt-1">${members.length} present of ${quorum} required</p>
    </div>`;
}

function setupQuorum() {
  document.querySelectorAll('.quorum-cb').forEach(cb => cb.addEventListener('change', updateQuorum));
  updateQuorum();
}
function updateQuorum() {
  const members = getAll('councilMembers');
  const quorum = Math.ceil(members.length/2)+1;
  const present = document.querySelectorAll('.quorum-cb:checked').length;
  const box = document.getElementById('quorum-result');
  const status = document.getElementById('quorum-status');
  const count = document.getElementById('quorum-count');
  if (!box) return;
  const met = present >= quorum;
  box.className = `p-3 rounded-lg text-center ${met?'bg-emerald-50 dark:bg-emerald-900/20':'bg-red-50 dark:bg-red-900/20'}`;
  status.className = `text-lg font-bold ${met?'text-emerald-600':'text-red-600'}`;
  status.textContent = met ? 'Quorum Met' : 'Quorum Not Met';
  count.textContent = `${present} present of ${quorum} required`;
}

function drawVoteCharts() {
  const votes = getAll('votes');
  const results = { Passed:0, Unanimous:0, Failed:0 };
  votes.forEach(v => { results[v.result] = (results[v.result]||0)+1; });
  doughnutChart('voteResults', Object.keys(results), Object.values(results), ['#059669','#2563eb','#dc2626'], {cutout:'60%', plugins:{legend:{position:'bottom'}}});
  barChart('voteTrend', votes.map(v=>v.subject.slice(0,20)+'…'), [{label:'Yes',data:votes.map(v=>v.yes),backgroundColor:'#059669'},{label:'No',data:votes.map(v=>v.no),backgroundColor:'#dc2626'},{label:'Abstain',data:votes.map(v=>v.abstain),backgroundColor:'#94a3b8'}], {plugins:{legend:{position:'bottom'}}});
}

function drawVoteList() {
  const votes = getAll('votes');
  document.getElementById('vote-list').innerHTML = card({title:'Voting History', subtitle:'Recorded decisions and printable reports', icon:'history', body: votes.length ? table({
    columns:[{label:'Subject'},{label:'Date'},{label:'Yes'},{label:'No'},{label:'Abstain'},{label:'Result'},{label:'Actions',align:'right',width:'w-1'}],
    rows: votes.map(v=>[
      `<p class="font-medium text-slate-800 dark:text-slate-100 line-clamp-1 max-w-xs">${v.subject}</p><p class="text-xs text-slate-400">${v.type}</p>`,
      `<span class="text-xs text-slate-500">${fmtDate(v.date)}</span>`,
      `<span class="text-sm font-semibold text-emerald-600">${v.yes}</span>`,
      `<span class="text-sm font-semibold text-red-600">${v.no}</span>`,
      `<span class="text-sm font-semibold text-slate-400">${v.abstain}</span>`,
      badge(v.result),
      `<div class="flex items-center justify-end gap-1"><button onclick="window.__viewVote('${v.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-500" title="View">${icon('eye','w-4 h-4')}</button><button onclick="window.__delVote('${v.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-red-500" title="Delete">${icon('trash-2','w-4 h-4')}</button></div>`
    ])
  }) : emptyState({icon:'vote', title:'No votes recorded', action: button({label:'New Vote', icon:'plus', variant:'primary', onclick:"window.__openVoteModal()"})})});
  renderIcons();
}

/* ----------------------- Voting simulation ----------------------- */
window.__openVoteModal = function() {
  const members = getAll('councilMembers');
  const sessions = getAll('sessions');
  modal({title:'New Vote — Electronic Voting Simulation', size:'lg',
    body:`<form id="vote-form" class="space-y-4">
      ${field({label:'Subject', name:'subject', required:true, placeholder:'e.g. Approval of Ordinance No. 2024-005'})}
      <div class="grid sm:grid-cols-2 gap-4">
        ${field({label:'Session', name:'sessionId', type:'select', options:sessions.map(s=>({value:s.id,label:s.title}))})}
        ${field({label:'Voting Type', name:'type', type:'select', value:'Roll Call', options:['Roll Call','Viva Voce','Division'].map(t=>({value:t,label:t}))})}
      </div>
      <div class="p-3 rounded-lg bg-slate-50 dark:bg-slate-800">
        <div class="flex items-center justify-between mb-2">
          <span class="text-sm font-medium text-slate-600 dark:text-slate-300">Member Votes (simulate)</span>
          <div class="flex gap-1">${['yes','no','abstain'].map(v=>`<button type="button" onclick="window.__quickVote('${v}')" class="text-xs px-2 py-1 rounded ${v==='yes'?'bg-emerald-600 text-white':v==='no'?'bg-red-600 text-white':'bg-slate-400 text-white'}">${v}</button>`).join('')}</div>
        </div>
        <div id="vote-roster" class="space-y-1.5 max-h-56 overflow-y-auto">${members.map(m=>`<div class="flex items-center gap-2 text-sm py-1">${memberAvatar(m.id,'w-7 h-7 text-[10px]')}<span class="flex-1">${m.name}</span><div class="flex gap-1">${['yes','no','abstain'].map(v=>`<button type="button" data-vote="${v}" data-mid="${m.id}" onclick="window.__setVote(this)" class="vote-btn text-xs px-2.5 py-1 rounded border border-slate-200 dark:border-slate-600 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-700">${v}</button>`).join('')}</div></div>`).join('')}</div>
      </div>
    </form>`,
    footer: button({label:'Cancel', variant:'secondary', onclick:"this.closest('[id^=modal]').querySelector('[data-close]').click()"}) + button({label:'Record Vote', variant:'primary', icon:'check', onclick:"window.__saveVote()"})
  });
};

window.__setVote = function(btn){ const mid=btn.dataset.mid; const vote=btn.dataset.vote; document.querySelectorAll(`.vote-btn[data-mid="${mid}"]`).forEach(b=>{b.className='vote-btn text-xs px-2.5 py-1 rounded border border-slate-200 dark:border-slate-600 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-700'; b.dataset.selected='';}); btn.className='vote-btn text-xs px-2.5 py-1 rounded text-white '+(vote==='yes'?'bg-emerald-600':vote==='no'?'bg-red-600':'bg-slate-500'); btn.dataset.selected='1'; };
window.__quickVote = function(vote){ document.querySelectorAll('.vote-btn').forEach(b=>{ if(b.dataset.vote===vote){ b.className='vote-btn text-xs px-2.5 py-1 rounded text-white '+(vote==='yes'?'bg-emerald-600':vote==='no'?'bg-red-600':'bg-slate-500'); b.dataset.selected='1'; } else { b.className='vote-btn text-xs px-2.5 py-1 rounded border border-slate-200 dark:border-slate-600 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-700'; b.dataset.selected=''; } }); };
window.__saveVote = function(){ const d=readForm(document.getElementById('vote-form')); if(!d.subject){toast('Subject required','error');return;} const tallies=[]; document.querySelectorAll('.vote-btn[data-selected="1"]').forEach(b=>tallies.push({memberId:b.dataset.mid, vote:b.dataset.vote})); const yes=tallies.filter(t=>t.vote==='yes').length; const no=tallies.filter(t=>t.vote==='no').length; const abstain=tallies.filter(t=>t.vote==='abstain').length; const result = yes>no ? (no===0&&abstain===0?'Unanimous':'Passed') : 'Failed'; insert('votes',{...d, total:tallies.length, yes, no, abstain, result, tallies, date:new Date().toISOString()}); toast('Vote recorded — '+result,'success'); document.querySelector('[id^=modal] [data-close]').click(); renderVoting(document.getElementById('ls-main'),{}); };

window.__viewVote = function(id){ const v=getById('votes',id); modal({title:'Vote Result', size:'md', body:`<div class="text-center mb-4"><p class="text-sm text-slate-500">${v.subject}</p><p class="text-3xl font-bold ${v.result==='Passed'||v.result==='Unanimous'?'text-emerald-600':v.result==='Failed'?'text-red-600':'text-slate-600'} mt-2">${v.result}</p></div><div class="grid grid-cols-3 gap-3 mb-4"><div class="p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 text-center"><p class="text-2xl font-bold text-emerald-600">${v.yes}</p><p class="text-xs text-slate-500">Yes</p></div><div class="p-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-center"><p class="text-2xl font-bold text-red-600">${v.no}</p><p class="text-xs text-slate-500">No</p></div><div class="p-3 rounded-lg bg-slate-100 dark:bg-slate-800 text-center"><p class="text-2xl font-bold text-slate-600">${v.abstain}</p><p class="text-xs text-slate-500">Abstain</p></div></div>${v.tallies.length?`<div class="space-y-1.5">${v.tallies.map(t=>`<div class="flex items-center gap-2 text-sm">${memberAvatar(t.memberId,'w-6 h-6 text-[10px]')}<span class="flex-1">${memberName(t.memberId)}</span><span class="text-xs ${t.vote==='yes'?'text-emerald-600':t.vote==='no'?'text-red-600':'text-slate-400'} font-medium">${t.vote}</span></div>`).join('')}</div>`:'<p class="text-sm text-slate-400 text-center">Viva voce / division vote — no per-member records.</p>'}<div class="mt-4">${aiInsight({title:'Decision Analysis', body:`Motion ${v.result.toLowerCase()} with ${v.yes} in favor, ${v.no} against, and ${v.abstain} abstaining. ${v.result==='Passed'?'This decision will be recorded in the session minutes and forwarded for publication.':v.result==='Unanimous'?'A unanimous decision indicates strong consensus — suitable for ceremonial or non-controversial matters.':'The motion did not pass. The sponsor may revise and reintroduce at a future session.'}`})}</div>`, footer: button({label:'Print Report', icon:'printer', variant:'outline', onclick:'window.print()'})+button({label:'Close', variant:'secondary', onclick:"this.closest('[id^=modal]').querySelector('[data-close]').click()"})}); };
window.__delVote = function(id){ confirmDialog({title:'Delete vote?', message:'This vote record will be permanently removed.', onConfirm:()=>{remove('votes',id); toast('Deleted','success'); renderVoting(document.getElementById('ls-main'),{});}}); };
