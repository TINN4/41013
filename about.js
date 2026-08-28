/* ==========================================================================
   pages/about.js — About / Project Overview
   ========================================================================== */
import { card, icon, pageHeader, button, renderIcons, sectionTitle, aiInsight } from '../ui.js';

export function renderAbout(main, route) {
  main.innerHTML = `
    ${pageHeader({title:'About This Project', subtitle:'Capstone Proposal Prototype — Legislative Services Management System', icon:'info'})}

    <div class="rounded-2xl bg-gradient-to-br from-primary-600 via-primary-700 to-primary-900 p-8 text-white mb-6 relative overflow-hidden">
      <div class="absolute -right-8 -top-8 opacity-10">${icon('landmark','w-48 h-48')}</div>
      <div class="relative">
        <div class="w-14 h-14 rounded-2xl bg-white/20 backdrop-blur flex items-center justify-center mb-4">${icon('landmark','w-7 h-7')}</div>
        <h1 class="text-2xl font-bold mb-2">Legislative Services Management System</h1>
        <p class="text-primary-100 max-w-2xl">A high-fidelity, front-end prototype designed for a Capstone Proposal presentation. It simulates a complete enterprise government information system for managing the full legislative lifecycle — entirely in the browser, with no backend.</p>
        <div class="flex flex-wrap gap-2 mt-4">
          ${['HTML5','Tailwind CSS','Vanilla JS (ES6)','Chart.js','Lucide Icons','Local Storage'].map(t=>`<span class="px-3 py-1 rounded-full bg-white/15 text-xs font-medium">${t}</span>`).join('')}
        </div>
      </div>
    </div>

    <div class="grid lg:grid-cols-3 gap-4 mb-6">
      ${card({title:'Project Objectives', icon:'target', body:`<ul class="space-y-2 text-sm text-slate-600 dark:text-slate-300">${['Deliver a realistic, production-ready appearance','Implement 10 fully interactive legislative modules','Use only front-end technologies (no backend)','Persist all data in Local Storage','Maintain one cohesive enterprise design system','Provide AI-style insights and simulated workflows'].map(o=>`<li class="flex items-start gap-2">${icon('check-circle-2','w-4 h-4 text-emerald-500 shrink-0 mt-0.5')}<span>${o}</span></li>`).join('')}</ul>`})}
      ${card({title:'10 Core Modules', icon:'grid-3x3', body:`<ul class="space-y-1.5 text-sm text-slate-600 dark:text-slate-300">${['Ordinance & Resolution Lifecycle','Session & Meeting Management','Agenda & Calendar Management','Committee Management & Assignment','Voting, Quorum & Decision Support','Records & Document Management','Public Hearing & Consultation','Archives & Historical Repository','Research, Policy Analysis & Impact','Citizen Engagement & Feedback'].map((m,i)=>`<li class="flex items-center gap-2"><span class="w-5 h-5 rounded bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300 text-xs font-bold flex items-center justify-center">${i+1}</span>${m}</li>`).join('')}</ul>`})}
      ${card({title:'Technology Stack', icon:'layers', body:`<div class="space-y-3 text-sm">${tech('HTML5','Structure & semantic markup').join('')}${tech('Tailwind CSS','Utility-first styling + custom design tokens').join('')}${tech('Vanilla JS (ES6 Modules)','No framework, no build step').join('')}${tech('Chart.js','Interactive data visualizations').join('')}${tech('Lucide Icons','Consistent iconography').join('')}${tech('Local Storage','Client-side data persistence').join('')}</div>`})}
    </div>

    <div class="grid lg:grid-cols-2 gap-4 mb-6">
      ${card({title:'Key Features', icon:'sparkles', body:`<div class="grid sm:grid-cols-2 gap-2 text-sm text-slate-600 dark:text-slate-300">${['Executive dashboard with live charts','Hash-based SPA routing','Reusable component library','Dark mode toggle','Global search','Toast notifications','Modal forms & confirmations','Loading skeletons','Empty states','Print & CSV export','AI-style insights','Animated voting simulation','Live session timer','Quorum calculator','Version history tracking','Audit trails'].map(f=>`<span class="flex items-center gap-1.5">${icon('check','w-4 h-4 text-emerald-500')}<span>${f}</span></span>`).join('')}</div>`})}
      ${card({title:'Design System', icon:'palette', body:`<div class="space-y-3"><div class="flex items-center gap-2"><span class="w-6 h-6 rounded bg-primary-600"></span><span class="text-sm text-slate-600 dark:text-slate-300">Primary — Blue (#1e40af)</span></div><div class="flex items-center gap-2"><span class="w-6 h-6 rounded bg-slate-500"></span><span class="text-sm text-slate-600 dark:text-slate-300">Slate Gray — Neutral base</span></div><div class="flex items-center gap-2"><span class="w-6 h-6 rounded bg-emerald-600"></span><span class="text-sm text-slate-600 dark:text-slate-300">Emerald — Success states</span></div><div class="flex items-center gap-2"><span class="w-6 h-6 rounded bg-amber-500"></span><span class="text-sm text-slate-600 dark:text-slate-300">Amber — Warnings</span></div><div class="flex items-center gap-2"><span class="w-6 h-6 rounded bg-red-600"></span><span class="text-sm text-slate-600 dark:text-slate-300">Red — Errors / critical</span></div></div><p class="text-xs text-slate-400 mt-3">Inter font · rounded-xl components · soft shadows · subtle animations · responsive layouts</p>`})}
    </div>

    ${aiInsight({title:'About This Prototype', body:'This system was designed to convincingly demonstrate a complete legislative management workflow during a Capstone Proposal presentation. Every module is fully interactive using Local Storage — you can create, edit, delete, search, filter, print, and export records. The dashboard updates in real time as you interact with any module, simulating a connected backend. No authentication, database, or server is required.'})}

    ${card({title:'Disclaimer', icon:'shield-alert', body:`<p class="text-sm text-slate-600 dark:text-slate-300">This is a demonstration prototype intended for academic and presentation purposes only. It is not affiliated with any real government body. All council members, ordinances, and data are fictional. No real data is collected, transmitted, or stored on any server.</p>`})}
  `;
  renderIcons();
}

function tech(name, desc) {
  return [`<div class="flex items-center justify-between p-2 rounded-lg bg-slate-50 dark:bg-slate-800"><div><p class="font-medium text-slate-800 dark:text-slate-100">${name}</p><p class="text-xs text-slate-400">${desc}</p></div>${icon('check-circle-2','w-4 h-4 text-emerald-500')}</div>`];
}
