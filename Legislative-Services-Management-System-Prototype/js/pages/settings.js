/* ==========================================================================
   pages/settings.js — Settings & Preferences
   Profile, dark mode, density, notifications, data management (reset/export).
   ========================================================================== */
import { getAll, getSettings, saveSettings, exportData, resetAll, pushNotification } from '../store.js';
import { card, icon, pageHeader, button, renderIcons, toast, confirmDialog, aiInsight, sectionTitle } from '../ui.js';

export function renderSettings(main, route) {
  const s = getSettings();
  const members = getAll('councilMembers').slice(0,1)[0];

  main.innerHTML = `
    ${pageHeader({title:'Settings', subtitle:'Manage your profile, preferences, and application data', icon:'settings'})}

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="space-y-4">
        ${card({title:'Your Profile', icon:'user', body:`
          <div class="flex flex-col items-center text-center py-2">
            <div class="w-20 h-20 rounded-full bg-gradient-to-br from-emerald-500 to-emerald-700 flex items-center justify-center text-white text-2xl font-bold mb-3">RA</div>
            <h3 class="font-semibold text-slate-800 dark:text-white">Hon. Ricardo Almazan</h3>
            <p class="text-sm text-slate-500">City Secretary · Presiding</p>
            <p class="text-xs text-slate-400 mt-1">${members?.email||'almazan@council.gov'}</p>
          </div>
        `})}
        ${aiInsight({title:'Data Status', body:`Your workspace is stored locally in the browser. No data is sent to any server. ${getAll('ordinances').length} ordinances, ${getAll('feedback').length} feedback items, and ${getAll('sessions').length} sessions are currently saved.`})}
      </div>

      <div class="lg:col-span-2 space-y-4">
        ${card({title:'Appearance', icon:'palette', body:`
          <div class="space-y-4">
            <div class="flex items-center justify-between">
              <div><p class="text-sm font-medium text-slate-700 dark:text-slate-200">Dark Mode</p><p class="text-xs text-slate-400">Toggle the UI theme (UI only)</p></div>
              <label class="relative inline-flex items-center cursor-pointer">
                <input type="checkbox" id="set-dark" ${s.darkMode?'checked':''} class="sr-only peer">
                <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer dark:bg-slate-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-primary-600"></div>
              </label>
            </div>
            <div class="flex items-center justify-between">
              <div><p class="text-sm font-medium text-slate-700 dark:text-slate-200">Compact Density</p><p class="text-xs text-slate-400">Reduce spacing for more content</p></div>
              <label class="relative inline-flex items-center cursor-pointer">
                <input type="checkbox" id="set-compact" ${s.density==='compact'?'checked':''} class="sr-only peer">
                <div class="w-11 h-6 bg-slate-200 rounded-full peer dark:bg-slate-700 peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-primary-600"></div>
              </label>
            </div>
          </div>
        `})}

        ${card({title:'Notifications', icon:'bell', body:`
          <div class="flex items-center justify-between">
            <div><p class="text-sm font-medium text-slate-700 dark:text-slate-200">Enable Notifications</p><p class="text-xs text-slate-400">Receive in-app alerts for new feedback, sessions, etc.</p></div>
            <label class="relative inline-flex items-center cursor-pointer">
              <input type="checkbox" id="set-notif" ${s.notifications?'checked':''} class="sr-only peer">
              <div class="w-11 h-6 bg-slate-200 rounded-full peer dark:bg-slate-700 peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-primary-600"></div>
            </label>
          </div>
        `})}

        ${card({title:'Organization', icon:'building-2', body:`
          <div class="grid sm:grid-cols-2 gap-4">
            <div><label class="block text-xs font-medium text-slate-600 dark:text-slate-300 mb-1.5">Organization Name</label><input id="set-org" type="text" value="${s.orgName||'City Legislative Council'}" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 outline-none focus:border-primary-400"></div>
            <div><label class="block text-xs font-medium text-slate-600 dark:text-slate-300 mb-1.5">Fiscal Year</label><input id="set-fy" type="number" value="${s.fiscalYear||2024}" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 outline-none focus:border-primary-400"></div>
          </div>
        `})}

        ${sectionTitle('Save Changes')}
        ${button({label:'Save Settings', icon:'save', variant:'primary', onclick:"window.__saveSettings()"})}

        ${sectionTitle('Data Management')}
        ${card({title:'Export & Reset', icon:'database', body:`
          <div class="flex flex-wrap gap-3">
            ${button({label:'Export All Data (JSON)', icon:'download', variant:'outline', onclick:"window.__exportData()"})}
            ${button({label:'Reset to Sample Data', icon:'refresh-ccw', variant:'danger', onclick:"window.__resetData()"})}
          </div>
          <p class="text-xs text-slate-400 mt-3">Export downloads a JSON backup of all Local Storage data. Reset clears and re-seeds the application with the original sample dataset.</p>
        `})}
      </div>
    </div>
  `;
  renderIcons();

  document.getElementById('set-dark')?.addEventListener('change', e => {
    saveSettings({ darkMode: e.target.checked });
    document.documentElement.classList.toggle('dark', e.target.checked);
    toast('Theme updated','success');
  });
}

window.__saveSettings = function(){
  const patch = {
    density: document.getElementById('set-compact')?.checked ? 'compact' : 'comfortable',
    notifications: document.getElementById('set-notif')?.checked,
    orgName: document.getElementById('set-org')?.value,
    fiscalYear: parseInt(document.getElementById('set-fy')?.value)||2024
  };
  saveSettings(patch);
  toast('Settings saved','success');
};

window.__exportData = function(){
  const data = exportData();
  const blob = new Blob([JSON.stringify(data, null, 2)], {type:'application/json'});
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a'); a.href=url; a.download='lsms-backup.json'; a.click();
  URL.revokeObjectURL(url);
  toast('Data exported','success');
};

window.__resetData = function(){
  confirmDialog({title:'Reset all data?', message:'This will erase all your changes and restore the original sample dataset. This cannot be undone.', confirmLabel:'Reset', onConfirm:()=>{ resetAll(); toast('Data reset — reloading…','success'); setTimeout(()=>location.reload(),1000); }});
};
