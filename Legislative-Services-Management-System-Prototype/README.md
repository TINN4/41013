# Legislative Services Management System — Capstone Prototype

A high-fidelity, front-end-only **Legislative Services Management System (LSMS)** prototype built for a Capstone Proposal presentation. It simulates the complete legislative workflow of a city/municipal council — from ordinance drafting and committee review, through session management and electronic voting, to public consultation, archives, research, and citizen engagement — all running entirely in the browser with **no backend, no server, no authentication, and no database**.

> **Status:** Prototype / Demonstration build · **Persistence:** Browser Local Storage · **Data:** Interconnected mock JSON dataset

---

## Table of Contents

1. [Overview](#overview)
2. [Key Features](#key-features)
3. [Technology Stack](#technology-stack)
4. [Quick Start](#quick-start)
5. [Project Structure](#project-structure)
6. [The 10 Core Modules](#the-10-core-modules)
7. [Design System](#design-system)
8. [Architecture & Data Flow](#architecture--data-flow)
9. [Reusable Component Library](#reusable-component-library)
10. [Interactions Guide](#interactions-guide)
11. [Limitations & Disclaimer](#limitations--disclaimer)

---

## Overview

This prototype demonstrates how a modern, digital legislative services platform could unify the day-to-day operations of a local legislative body. It was designed to be **visually polished, functionally interactive, and immediately runnable** — open `index.html` in a browser to view the public landing page, then click **Login** to open `app.html` — the application boots, seeds itself with realistic mock data, and is fully explorable.

Every action the user takes — creating an ordinance, scheduling a hearing, recording a vote, responding to citizen feedback — is persisted to the browser's Local Storage and reflected immediately across the dashboard and related modules through a lightweight publish/subscribe event system. The prototype is intentionally self-contained: there is no build step, no compilation, no package installation, and no external API dependency.

### Why a prototype?

The goal is to **communicate a vision** — to show stakeholders, advisors, and evaluators what a fully realized legislative services platform would feel like in daily use, covering ten interconnected systems in a single cohesive interface. The mock data, simulated workflows, and "AI insight" blocks are deliberately illustrative; they demonstrate *capability* and *information architecture*, not production-grade intelligence.

---

## Key Features

- **10 fully-interactive legislative modules** in a single SPA, plus Reports, Settings, Help, and About pages.
- **Hash-based SPA routing** — no page reloads, instant navigation, shareable URLs (`#/ordinances`, `#/voting`, etc.).
- **Local Storage persistence** with a pub/sub event system — changes in any module instantly update the dashboard and notification badge.
- **Create, edit, delete, search, filter, sort, print, and export (CSV/JSON)** on every data-bearing module.
- **Chart.js visualizations** — line, bar, doughnut, and radar charts with a managed registry that safely destroys and re-renders on navigation.
- **AI-style insight blocks** — each module surfaces contextual "AI" briefs, summaries, and recommendations (simulated for demonstration).
- **Executive dashboard** with live stat cards, legislative output trends, committee workload, session status, and an intelligence brief that adapts to current data.
- **Electronic voting simulation** with per-member Yes/No/Abstain controls, quorum verification, live tally, and outcome determination.
- **Monthly legislative calendar** with session, hearing, and deadline events.
- **Public hearing & citizen engagement** modules including a simulated public portal and engagement analytics.
- **Legislative archives** with digital restoration simulation and historical repository.
- **Policy research & impact evaluation** with radar charts, impact scoring, and benchmarking notes.
- **Dark mode toggle** (persisted), responsive layout, loading screen, toast notifications, modal dialogs, and confirm prompts.
- **Global search** across ordinances, resolutions, sessions, committees, members, documents, archives, research, hearings, and feedback.
- **Keyboard shortcuts** (`/` to focus search, `Esc` to close overlays).

---

## Technology Stack

| Layer | Technology | Notes |
|---|---|---|
| Markup | **HTML5** | Single `index.html` app shell |
| Styling | **Tailwind CSS** (CDN) | Custom config: primary blue palette, Inter font, custom shadows/animations, dark mode |
| Logic | **Vanilla JavaScript (ES6 Modules)** | No frameworks, no compilation |
| Charts | **Chart.js** (CDN) | Line, bar, doughnut, radar, horizontal bar |
| Icons | **Lucide Icons** (CDN) | Re-rendered after every DOM update |
| Font | **Inter** (Google Fonts) | via Tailwind config |
| Persistence | **Browser Local Storage** | Namespaced keys, pub/sub events |
| Data | **Mock JSON** (seeded on first load) | 12 council members, 6 committees, 6 ordinances, 5 resolutions, 5 sessions, and more |

**No React, Next.js, Vue, Angular, TypeScript, build tools, bundlers, or backends are used.** The application runs exactly as-is from the file system.

---

## Quick Start

### Option A — VS Code Live Server (recommended)

1. Download and unzip the project.
2. Open the `Legislative-Services-Management-System-Prototype` folder in **Visual Studio Code**.
3. Install the **Live Server** extension (by Ritwick Dey) if not already installed.
4. Right-click `index.html` → **"Open with Live Server"** to view the landing page, then click **Login** to open `app.html`.
5. The app seeds its mock data and lands on the Session Scheduling module.

### Option B — Any static file server

Because the app uses ES6 modules, browsers require an HTTP origin (not `file://`). Serve the folder with any static server, for example:

```bash
# Python 3
cd Legislative-Services-Management-System-Prototype
python3 -m http.server 8000
# then open http://localhost:8000

# Node (npx)
npx serve Legislative-Services-Management-System-Prototype

# PHP
php -S localhost:8000 -t Legislative-Services-Management-System-Prototype
```

### Resetting the data

The application seeds itself on first load. To reset to the original sample dataset at any time, go to **Settings → Data Management → Reset to Sample Data**. You can also export a full JSON backup from the same panel.

---

## Project Structure

```
Legislative-Services-Management-System-Prototype/
├── index.html                  # Public landing page (entry point)
├── app.html                    # App shell: sidebar, topbar, loading screen, content mount
├── css/
│   └── styles.css             # Global styles, animations, scrollbar, print, components
├── js/
│   ├── app.js                 # Boot, hash router, nav, breadcrumbs, search, notifications, dark mode
│   ├── store.js               # Local Storage manager: CRUD, pub/sub, seed, settings, notifications
│   ├── data.js                # Comprehensive interconnected mock data (seedData)
│   ├── ui.js                  # Reusable component library (cards, tables, forms, modals, badges…)
│   ├── charts.js              # Chart.js wrappers with a chart registry (line/bar/doughnut/radar)
│   └── pages/
│       ├── dashboard.js       # Executive dashboard
│       ├── ordinances.js      # Module 1 — Ordinance & Resolution Lifecycle
│       ├── sessions.js        # Module 2 — Session & Legislative Meeting Management
│       ├── agenda.js          # Module 3 — Legislative Agenda & Calendar
│       ├── committees.js      # Module 4 — Committee Management & Assignment
│       ├── voting.js          # Module 5 — Voting, Quorum & Decision Support
│       ├── records.js         # Module 6 — Legislative Records & Document Management
│       ├── hearings.js        # Module 7 — Public Hearing & Consultation
│       ├── archives.js        # Module 8 — Legislative Archives & Historical Repository
│       ├── research.js        # Module 9 — Research, Policy Analysis & Impact Evaluation
│       ├── engagement.js      # Module 10 — Citizen Engagement & Public Feedback
│       ├── reports.js         # Consolidated reports & analytics
│       ├── settings.js        # Profile, preferences, dark mode, data management
│       ├── help.js            # User guide, UI guide, workflows, FAQ
│       └── about.js           # Project overview, tech stack, objectives
└── README.md                  # This file
```

The structure is deliberately **minimalist**: one HTML shell, one CSS file, four shared JavaScript modules, and one file per page. This keeps the project easy to navigate and immediately runnable while delivering full module functionality.

---

## The 10 Core Modules

### 1. Ordinance & Resolution Lifecycle Management
Draft, review, endorse, approve, publish, and monitor legislative measures. Includes a tabbed interface for ordinances and resolutions, a six-stage lifecycle workflow visualization (Drafting → Committee Review → Pending Review → Approved → Published → Enacted), version history, AI-generated summaries, status badges, and full CRUD with CSV export.

### 2. Session & Legislative Meeting Management
Schedule regular, special, and joint sessions; track attendance and quorum; run a live session timer (start/pause/end); generate minutes automatically from session metadata; and view an AI session brief. The "Live Session" tab features a real-time elapsed-time display and attendance breakdown.

### 3. Legislative Agenda & Calendar Management
A monthly calendar grid showing sessions, public hearings, and deadlines with color-coded event chips. Navigate months, view upcoming deadlines with priority badges and countdowns, and manage agenda items (toggle complete, delete). An AI calendar insight highlights critical-priority items.

### 4. Committee Management & Assignment System
Create committees, assign council members, view per-committee dashboards with workload bars and member rosters, and visualize workload distribution and member allocation via charts. Each committee card shows status, jurisdiction, and active measures.

### 5. Voting, Quorum & Decision Support System
Quorum calculator with live member checkboxes, vote-outcome doughnut chart, participation trend bar chart, and a full voting history table. The electronic voting simulation modal lets you cast per-member Yes/No/Abstain votes, with quick-vote-all buttons, live tally, and automatic outcome determination (Passed / Unanimous / Failed). Vote detail modal includes an AI decision analysis.

### 6. Legislative Records & Document Management
A document repository with category distribution visualization, AI repository insight, searchable/filterable table, simulated drag-and-drop upload, document detail with metadata, version history, and a full audit-trail timeline. Supports CSV export.

### 7. Public Hearing & Consultation Management
Schedule hearings, track registrations vs. attendance, view issue-type distribution, and open hearing detail modals with statistics grids, sample public feedback, and AI hearing insights. Includes a printable hearing report.

### 8. Legislative Archives & Historical Repository
Digital archives with category doughnut chart, AI archive insight, searchable table by title/year/category/format, archive detail and preview modals, and a simulated digital restoration action. Stats include the oldest record year.

### 9. Legislative Research, Policy Analysis & Impact Evaluation
Research projects with impact-score comparison bar chart, status doughnut, a selectable project list, and a detail panel featuring an impact radar chart, AI recommendation, benchmarking notes, and an AI policy-impact summary. Create/edit/delete/export supported.

### 10. Citizen Engagement & Public Feedback Management
Feedback inbox with tabs (Inbox, Complaints, Suggestions, Analytics), feedback-type bar chart, status doughnut, view-and-respond modal, validation action, and a simulated public portal showing announcements and recent issues. The engagement analytics view includes ward distribution and response-performance metrics, plus AI engagement insights.

### Additional Pages
- **Reports** — consolidated legislative reports with trend charts, status overview, six downloadable CSV report cards, and an AI executive summary.
- **Settings** — profile card, dark mode toggle (persisted), compact density toggle, notifications toggle, organization settings, and data management (export JSON / reset to sample data).
- **Help** — getting started, core workflows, UI guide, FAQ, keyboard shortcuts, and troubleshooting — all with expandable sections.
- **About** — hero banner, project objectives, the 10 modules, technology stack, key features, design-system color palette, and a disclaimer.

---

## Design System

A single, government-inspired design system runs across every page for visual consistency.

| Token | Value | Usage |
|---|---|---|
| Primary | Blue (`#1e40af` / `#2563eb`) | Navigation, primary actions, active states |
| Slate | Gray (`#0f172a`–`#94a3b8`) | Sidebar, text, borders, neutral surfaces |
| Emerald | Green (`#059669`) | Success, enacted, completed, positive trends |
| Amber | Orange (`#d97706`) | Pending, warnings, in-progress |
| Red | (`#dc2626`) | Critical, failed, delete, destructive |
| Font | Inter (Google Fonts) | All UI text |
| Radius | `rounded-xl` (12px) | Cards, buttons, inputs |
| Shadows | Soft, layered | `shadow-card`, `shadow-sidebar` |
| Dark mode | Class-based toggle | Persisted in settings |

Additional design elements: a pulse dot for live indicators, card hover lift animations, skeleton shimmer loaders, smooth page transitions, toast notifications, and a consistent status-badge system mapping each legislative status to a color.

---

## Architecture & Data Flow

```
┌─────────────┐    hashchange     ┌──────────────┐    render()     ┌─────────────┐
│  app.html   │  ───────────────► │   app.js     │  ─────────────► │  #ls-main   │
│  (app shell)│                   │  (router)    │                 │  (content)  │
└─────────────┘                   └──────┬───────┘                 └──────┬──────┘
                                         │ imports                        │ reads/writes
                                         ▼                                ▼
                                  ┌──────────────┐               ┌──────────────┐
                                  │  page/*.js   │ ◄──────────── │  store.js    │
                                  │  (modules)   │    pub/sub    │ (LocalStorage)│
                                  └──────┬───────┘   onAny()      └──────┬───────┘
                                         │ imports                        │ seeds
                                         ▼                                ▼
                                  ┌──────────────┐               ┌──────────────┐
                                  │ ui.js charts │               │   data.js    │
                                  │ (components) │               │ (mock data)  │
                                  └──────────────┘               └──────────────┘
```

1. On boot, `app.js` calls `initStore(seedData)`. If Local Storage is empty, `data.js` seeds the full interconnected dataset; otherwise the existing data is hydrated into an in-memory cache.
2. The hash router (`#/dashboard`, `#/ordinances`, …) resolves the current route to a page render function, destroys any active charts, mounts the page into `#ls-main`, and re-renders Lucide icons.
3. Every CRUD operation in `store.js` emits a pub/sub event. `app.js` subscribes via `onAny()`: when data changes, the notification badge refreshes, and — if the dashboard is the active view — the dashboard re-renders live.
4. Settings (including dark mode) are stored in the `settings` collection and applied on every boot before the first content paint.

---

## Reusable Component Library

`ui.js` exports a comprehensive set of building blocks used by every page:

- `card`, `statCard` — content and statistic cards with headers, icons, actions
- `button` — 7 variants (primary, secondary, outline, ghost, danger, success, warning) and 3 sizes
- `badge` — status badge with a `STATUS_STYLES` map for consistent legislative status colors
- `table` — styled data table with hover rows
- `modal`, `confirmDialog` — modal and confirmation dialogs with open/close animations
- `field`, `readForm` — form field generator (text, select, textarea, date, time) and FormData reader
- `filterBar` — search + select filters with a callback hook
- `tabs` — tabbed navigation
- `toast` — toast notifications (success, error, info, warning)
- `breadcrumbs`, `pageHeader`, `sectionTitle` — layout primitives
- `emptyState`, `skeleton` — empty and loading states
- `aiInsight` — styled "AI" insight block with sparkles icon
- `memberName`, `memberAvatar`, `committeeName` — relational helpers that resolve IDs to names
- `fmtDate`, `fmtDateLong`, `fmtTime`, `relTime` — date/time formatting
- `exportCSV`, `printPage` — CSV export and print helpers
- `icon`, `renderIcons` — Lucide icon rendering and re-rendering

`charts.js` wraps Chart.js with `lineChart`, `barChart`, `doughnutChart`, `radarChart`, and `horizontalBarChart`, plus a `destroyAll()` registry call used by the router to prevent canvas-reuse errors.

---

## Interactions Guide

Every data-bearing module supports the full interaction set:

| Action | How |
|---|---|
| **Create** | Click the primary "New …" button → fill the modal form → Save |
| **Edit** | Click the pencil icon in a row → modify → Update |
| **Delete** | Click the trash icon → confirm in the dialog |
| **Search** | Type in the module's search box (filters live) |
| **Filter** | Use the dropdown selects in the filter bar |
| **Sort** | Click column headers where applicable |
| **View detail** | Click the eye icon → detail modal with metadata, versions, audit trails |
| **Print** | Use the "Print Report" button on supported modules |
| **Export** | Use "Export" / "Export CSV" buttons (downloads a CSV) |
| **Global search** | Type in the topbar search → results dropdown → Enter to navigate |
| **Dark mode** | Click the moon/sun icon in the topbar (persists) |
| **Notifications** | Click the bell icon → panel with mark-all-read |

---

## Limitations & Disclaimer

This is a **front-end-only prototype** built for demonstration and educational purposes as part of a Capstone Proposal. Specifically:

- **No real backend, server, database, or authentication** is included or required. All data is mock JSON persisted in the browser's Local Storage.
- **AI insight blocks are simulated** — they generate contextual text based on the current data but do not call any AI/ML service.
- **Electronic voting, session timers, public portal, and digital restoration are simulations** — they demonstrate workflow and UI, not real civic processes.
- **Data is per-browser** — clearing Local Storage or using a different browser resets to the seeded sample dataset.
- The Tailwind CSS and Chart.js CDNs are used for rapid prototyping; a production build would self-host these assets and use a compiled Tailwind build.

© 2024 Legislative Services Management System — Capstone Prototype. For demonstration purposes only.
