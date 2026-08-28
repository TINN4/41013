# User Guide — Legislative Services Management System Prototype

This guide walks you through using the LSMS prototype. The application is fully interactive and persists all changes in your browser via Local Storage.

## Getting Started

1. Open the project folder in VS Code, right-click `index.html`, and choose **"Open with Live Server"** to view the landing page — Alternatively, serve the folder with any static HTTP server. Click **Login** to open `app.html`, the application itself.
2. On first load, the app displays a brief loading screen while it seeds the sample legislative dataset, then lands on the **Executive Dashboard**.
3. Use the left sidebar to navigate between modules. The dashboard is your home base and updates live as you interact with other modules.

## Navigation

- **Sidebar** — all 10 legislative modules plus Reports, Settings, Help, and About, grouped under "System."
- **Breadcrumb** — top center, shows your current location (Home › Page).
- **Global search** — top bar; searches ordinances, resolutions, sessions, committees, members, documents, archives, research, hearings, and feedback. Press `/` to focus it, `Enter` to jump to the first result.
- **Notifications** — the bell icon opens a panel of recent system notifications with a "Mark all read" action.
- **Dark mode** — the moon/sun icon toggles a dark theme; your preference is saved.

## Module Workflows

### Creating an Ordinance
1. Go to **Ordinances**.
2. Click **New Ordinance**.
3. The measure number is auto-suggested. Fill in the title (required), author, category, committee, status, and summary.
4. Click **Save as Draft**. The ordinance is created, an AI summary is auto-generated, the table updates, and the dashboard's pending count increases live.

### Advancing a Measure Through the Lifecycle
1. On the Ordinances page, switch to the **Lifecycle Workflow** tab to see the six-stage pipeline.
2. In the table, use the **advance** action (where available) to move a measure from one stage to the next (Drafting → Committee Review → Pending Review → Approved → Published → Enacted).

### Running a Live Session
1. Go to **Sessions** → **Live Session** tab.
2. Use the timer controls (Start / Pause / End) to simulate a live session.
3. View quorum status, attendance breakdown, and the AI session brief.
4. Switch to **Minutes** to see auto-generated minutes from the session metadata.

### Recording a Vote
1. Go to **Voting** → click **New Vote**.
2. Enter a subject, select a session, and choose a voting type.
3. For each council member, click **Yes**, **No**, or **Abstain** (or use the quick-vote-all buttons).
4. Click **Record Vote**. The tally, outcome (Passed / Unanimous / Failed), and charts update immediately.

### Managing the Calendar
1. Go to **Agenda** to see the monthly legislative calendar.
2. Use the month navigation arrows to move between months.
3. Events (sessions, hearings, deadlines) appear as colored chips on their dates.
4. The **Upcoming Deadlines** panel lists priorities with countdowns.

### Responding to Citizen Feedback
1. Go to **Citizen Engagement**.
2. Use the tabs to filter by Inbox, Complaints, or Suggestions.
3. Click the view icon on a feedback item to open the detail and **Respond**.
4. Use **Validate** to mark feedback as validated.
5. Click **Public Portal** to see a simulation of the citizen-facing portal.

### Archiving and Restoring Records
1. Go to **Archives** to browse the historical repository.
2. Use search and category/format filters to narrow results.
3. Click the restore icon on an archive item to simulate digital restoration.

### Resetting the Data
1. Go to **Settings** → **Data Management**.
2. Click **Export All Data (JSON)** to download a backup.
3. Click **Reset to Sample Data** to restore the original seeded dataset.

## Keyboard Shortcuts

| Key | Action |
|---|---|
| `/` | Focus the global search |
| `Esc` | Close search results, notifications, or any open modal |

## Troubleshooting

- **Blank page / stuck loading** — ensure you're accessing the app via an HTTP server (Live Server, `python3 -m http.server`, etc.), not by double-clicking the HTML file. ES6 modules require an HTTP origin.
- **Charts not showing** — hard-refresh the page; Chart.js is loaded from a CDN and needs internet access on first load.
- **Data looks wrong** — go to Settings → Reset to Sample Data to restore the original dataset.
- **Changes not persisting** — make sure your browser allows Local Storage for the origin (private/incognito modes may restrict it).
