# Legislative Services Management System (LSMS)

A working **Session and Legislative Meeting Management System** built for the Sangguniang Panlungsod ng San Jose del Monte — a real PHP + MySQL application (not a static mockup) that manages session scheduling, agenda preparation, attendance and quorum tracking, live session tracking, proceedings documentation, and official minutes generation.

> **Status:** Working prototype, deployed and live · **Backend:** PHP 8.3 + MySQL/MariaDB · **Persistence:** Real database, not browser storage

---

## Table of Contents

1. [Overview](#overview)
2. [Key Features](#key-features)
3. [Technology Stack](#technology-stack)
4. [Deployment](#deployment)
5. [Project Structure](#project-structure)
6. [The Modules](#the-modules)
7. [Authentication & Roles](#authentication--roles)
8. [Security](#security)
9. [Known Limitations](#known-limitations)

---

## Overview

This system runs two parallel login paths into the same app:

- **Staff (Secretary/Admin)** log in with a username and password at `login.php`, and handle all the actual session-management work.
- **Council members** log in by scanning a personal QR badge at `qr_login.php` — no password to remember. This also doubles as their attendance check-in.

Everything is stored in a real MySQL database, not mock data or Local Storage. Every module — Scheduling, Agenda, Attendance, Proceedings, Minutes, Members, Tracking, Archive — reads and writes real rows, and a public, no-login dashboard (`public.php`) shows a live, read-only view of whichever session is currently happening.

---

## Key Features

- **Two login paths in one app**: password-based staff login, and QR-badge login for council members with location-based verification.
- **Session lifecycle**: Scheduled → Ongoing → Completed/Cancelled → Archived, enforced consistently — you can't mark attendance, log proceedings, or generate minutes for a session that hasn't actually started yet.
- **Attendance & quorum**: staff can mark Present/Excused/Absent by hand; council members scanning their QR badge during an Ongoing session get auto-marked Present. Quorum is calculated live from real attendance data.
- **Proceedings documentation** with a choice of typing manually or **live voice dictation** (English, Tagalog/Filipino, or an approximate Taglish mode) straight into the note field, including a microphone picker for devices with more than one input.
- **Minutes Generation** auto-compiles attendance, agenda, and proceedings for a session into an official, print-ready document — nothing is written by hand twice.
- **Session Archive** with a full, printable official record (including who created the session, who marked attendance, and who generated the minutes) and a JSON export/import for backups.
- **Real-Time Tracking** — a live dashboard of the current agenda item, elapsed session timer, and quorum status, auto-refreshing without a page reload.
- **Public dashboard** (no login required) showing the current session's status, agenda, and quorum count — safe, non-sensitive information only.
- **Two staff roles** — Admin and Secretary — with admin-only account management, including resetting a locked-out colleague's password, and a guarantee that the system can never end up with zero Admin accounts.
- **Every key action is attributed** to the staff member (or council member) who performed it — sessions, attendance marks, and minutes all record who did them.
- **Dark mode**, persisted per-browser, applied everywhere except official documents and the public dashboard, which stay in a consistent light "paper" look regardless of theme.
- **AFK auto-logout**: staff sessions show a warning after 15 minutes of inactivity and log out automatically after 20 if nobody responds.
- **All timestamps are Philippine time (Asia/Manila)**, set once at the application level so nothing is ever silently recorded in UTC.

---

## Technology Stack

| Layer | Technology | Notes |
|---|---|---|
| Backend | **PHP 8.3** | No framework — plain procedural PHP, deliberately kept simple for a prototype of this size |
| Web server | **Apache** (via `php:8.3-apache` Docker image) | |
| Database | **MySQL / MariaDB** | Accessed via PDO with prepared statements throughout |
| Frontend | **Vanilla HTML/CSS/JavaScript** | No frameworks, no build step, no bundler |
| Voice input | **Web Speech API** (browser built-in) | Chrome/Edge only — no external speech service, nothing sent to a third party |
| Deployment | **Docker** | A single `Dockerfile` at the project root; required because the hosting platform used for this project only auto-detects static sites or Dockerfiles, not plain PHP |

**No React, Vue, Angular, Laravel, Symfony, or any other framework is used.** The application is intentionally simple, procedural PHP — appropriate for a capstone prototype of this scope, not an enterprise system.

---

## Deployment

The project ships with a `Dockerfile` (PHP 8.3 + Apache + the `pdo_mysql` extension). To deploy:

1. Provision a MySQL/MariaDB database on your host.
2. Set these environment variables on the app: `SSMS_DB_HOST`, `SSMS_DB_NAME`, `SSMS_DB_USER`, `SSMS_DB_PASS`, `SSMS_DB_PORT`. `session-system/includes/db_config.php` reads these via `getenv()` and only falls back to placeholder local-dev values if they're unset.
3. Point your platform's build system at `dockerfile`, with the Dockerfile path set to the project's `Dockerfile` (see `session-system/includes/geofence.php` for the one setting you'll also need to update — see below).
4. Tables are created automatically the first time each one is used — no manual schema import needed, though `session-system/data/schema.sql` and `ssms_db_dump.sql` exist if you want to set things up manually or seed demo data.
5. **Before going live**, update the office coordinates in `session-system/includes/geofence.php` to your actual venue's real GPS coordinates — this repo may currently have them pointed at a test location used during development. The file clearly marks which values are which.

---

## Project Structure

```
Legislative-Services-Management-System-Prototype/
├── Dockerfile                   # PHP 8.3 + Apache + pdo_mysql, deployment entry point
├── index.html, app.html         # Earlier static landing/demo pages (kept for reference)
├── session-system/              # The real, working application
│   ├── login.php                # Staff login — brute-force lockout, AFK timeout, CSRF
│   ├── qr_login.php             # Council member QR badge login — geofence + device binding
│   ├── logout.php
│   ├── manage_devices.php       # Staff: issue/reset/regenerate QR badges
│   ├── public.php               # Anonymous, read-only live session dashboard
│   ├── cron_backup.php          # Scheduled/manual JSON backup trigger
│   ├── modules/                 # Every staff-facing module
│   │   ├── scheduling.php       # Create/manage sessions, status transitions
│   │   ├── agenda.php           # Agenda items per session
│   │   ├── attendance.php       # Mark attendance, view quorum (also council members' landing page)
│   │   ├── proceedings.php      # Log what happens — typed or voice-dictated
│   │   ├── minutes.php          # Auto-compile and generate official minutes
│   │   ├── tracking.php         # Live session dashboard for staff
│   │   ├── archive.php          # Archived sessions, official record view, export/import
│   │   ├── members.php          # Council member roster
│   │   └── account.php          # Staff accounts, roles, password management
│   ├── api/                     # JSON endpoints used by polling/live-updating pages
│   ├── includes/
│   │   ├── bootstrap.php        # Session start, security headers, PH timezone, error handling
│   │   ├── auth.php             # Login checks, staff/admin role checks, AFK enforcement
│   │   ├── store.php            # Database layer — every table read/write goes through here
│   │   ├── geofence.php         # Office location + allowed radius for QR check-in
│   │   └── header.php / footer.php / sidebar.php
│   ├── assets/                  # style.css, app.js — shared across every staff-facing page
│   └── data/                    # schema.sql, seed dump, migration scripts (for reference/manual setup)
└── docs/
    ├── PROJECT_OVERVIEW.md
    └── USER_GUIDE.md
```

---

## The Modules

| Module | What it does |
|---|---|
| **Session Scheduling** | Create sessions (title, type, date, time, venue), move them through Scheduled → Ongoing → Completed/Cancelled, and archive finished ones. |
| **Agenda Preparation** | Add, order, and track the status (Pending/Discussed/Approved/Deferred) of agenda items for a session. |
| **Attendance & Quorum** | Staff mark each council member Present/Excused/Absent; council members scanning their own QR badge get auto-marked Present. Only works once a session is Ongoing or Completed. |
| **Proceedings Documentation** | Log what happens during the session — type it, or dictate it live using your browser's speech recognition (English/Tagalog/Taglish), with a microphone picker. Only works once a session is Ongoing or Completed. |
| **Minutes Generation** | Pulls attendance, agenda, and proceedings for a session into one official, printable document. Only available once a session has actually started. |
| **Real-Time Tracking** | A live-updating dashboard (staff-only) showing the current agenda item, session timer, and quorum status. |
| **Council Members** | Roster management — add, edit, remove council members. |
| **Session Archive** | Browse archived (Completed/Cancelled) sessions, view the full official record, export/import as JSON. |
| **Account Settings** | Change your own password; Admins can add/remove staff accounts, promote/demote roles, and reset a locked-out colleague's password. |
| **Manage QR Badges & Devices** | Issue QR badges to council members, reset a lost/stolen device's binding, or regenerate a badge entirely. |

---

## Authentication & Roles

- **Staff accounts** (Admin or Secretary) log in with username + password. Both roles do the same day-to-day session work; only Admins can manage other staff accounts.
- **The system always keeps at least one Admin** — the last remaining Admin account can't be demoted or removed, so the system can never lock everyone out permanently.
- **Council members** log in by scanning a personal QR badge. First scan permanently binds that one device to their account; the location check (GPS-based) must pass every time, not just the first time.

---

## Security

- Prepared statements (PDO) everywhere — no raw SQL string concatenation.
- CSRF tokens on every form that changes data.
- Passwords hashed with PHP's `password_hash()` (bcrypt), never stored or logged in plain text.
- Brute-force protection: 5 failed staff login attempts locks that IP out for 10 minutes.
- AFK auto-logout for staff sessions (15-minute warning, 20-minute hard logout).
- Output is escaped (`htmlspecialchars`) everywhere user-supplied or database-sourced text is displayed.
- Database writes use targeted single-row operations with row-level locking on updates — not a read-everything/rewrite-everything pattern — so two people using the app at the same moment can't silently overwrite each other's data.
- Role-gated actions are enforced **server-side**, not just hidden in the UI — directly POSTing a restricted action without the right role/session is rejected regardless of what the browser shows.

---

## Known Limitations

Being direct about what this system does *not* do, rather than leaving it undocumented:

- **No PIN protection on first-time QR badge binding.** Whoever physically scans a badge first (within the allowed location radius) claims that device — there's no secondary verification that it's the actual named council member. This is a deliberate, known trade-off for simplicity; it can be added if needed (see `session-system/qr_login.php` for where it would go).
- **Backups written by `cron_backup.php` are not guaranteed to persist** if the hosting platform doesn't provide a persistent volume for the Docker container — a redeploy can wipe them. Use Session Archive's manual Export/Download for anything you actually need to keep.
- **No automated test suite.** Correctness has been verified through manual code review and testing, not automated tests — appropriate for the scope of this prototype, but worth knowing.
- **Voice dictation requires Chrome or Edge**, an internet connection, and HTTPS. It won't work in Firefox/Safari or over a plain HTTP connection.
- **Microphone selection is a best-effort workaround**, not an officially guaranteed browser feature — the Web Speech API has no formal way to bind to a specific input device.
