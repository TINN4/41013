# Bug, Security & Logic Audit Report

Scope: the project contains **two separate applications** that share no authentication or data:

| Part | Entry point | Tech | Data | Auth |
|---|---|---|---|---|
| A. Browser prototype (LSMS) | `index.html` (landing) → `app.html` | static HTML/JS (`js/app.bundle.js`, built from `js/*.js` modules), Tailwind CSS | mock data in browser `localStorage` | **None, by design** (README states "no authentication"). `app.html` can be opened directly. |
| B. PHP Session & Legislative Meeting Management System (SSMS) | `session-system/login.php` | PHP 8, JSON files in `session-system/data/` | JSON files | Was hardcoded `admin/admin123` + QR badge login |

The landing page's *Login* buttons point to part B. Part A's `localStorage` state and part B's PHP session are unrelated and do not trust each other. Part A holds only mock data and is not protected; do not put real records in it.

## Findings (original code) and what was done

### Critical
| # | File | Problem | Impact | Fix |
|---|---|---|---|---|
| C1 | `session-system/data/*.json` | Data folder served by the web server and world-writable (0666). `data/qr_users.json` exposed every QR badge token in plaintext. | Anyone could download all records and log in as any council member. | Folder denied by `.htaccess` and `router.php`; files 0640; badge tokens now stored only as SHA-256 hashes; legacy tokens **not migrated** (invalid, re-issue). Tested (A4). |
| C2 | `login.php` | Hardcoded `admin`/`admin123`, shown on the login page; no hashing, no rate limit, no session regeneration. | Trivial full access; session fixation. | Per-user accounts, `password_hash`, throttling, generic errors, session regeneration, no credentials in the UI. |
| C3 | all modules | **No authorization at all**: any logged-in user (including QR "members") could create/start/cancel/delete sessions, edit attendance, delete proceedings. | Council members could alter official records. | Central RBAC (`includes/rbac.php`), enforced server-side per action. |
| C4 | all POST forms | No CSRF protection. | Cross-site forced actions. | CSRF token on every state-changing request (incl. logout). |
| C5 | `api/qr_login.php`, `qr_login.php` | First scanner of a badge bound their device permanently; token never expired; geofence trusted client coordinates; no rate limit; QR path would bypass any MFA; `data.name` injected into `innerHTML` (XSS). | Badge theft = account takeover. | Badge enrollment window + expiry, per-device secret, throttling, MFA gate, no identity before MFA, `textContent` UI. Geofence limitation documented. |
| C6 | `store.php` | `ssms_read` returned `[]` for a corrupt file, so the next write silently **erased all data**; no locking across read-modify-write → lost updates / duplicate ids. | Data loss under concurrency or corruption. | Locked atomic read-modify-write, atomic rename, corrupt file = hard error. Tested (I5–I7). |

### High
| # | File | Problem | Fix |
|---|---|---|---|
| H1 | `scheduling.php` | Any status could be set to any string (e.g. Completed→Scheduled, arbitrary text); no validation of date/time; sessions with agenda/attendance/minutes could be deleted leaving orphans; `started_at` stored inconsistently. | State machine with compare-and-set, validation, conflicts/duplicates, delete only for empty Scheduled sessions. |
| H2 | `agenda.php` | Next order = `count()+1` → duplicate numbers after a delete; item IDs not checked against the selected session (IDOR); no finalized state. | `max+1`, healing/renumbering, session-ownership checks, Draft/Final/Revision workflow with history. |
| H3 | `attendance.php` | Status unvalidated; unrecorded members silently treated as absent; quorum hardcoded to `floor(n/2)+1` and declared "Met/Not Met" regardless of roll-call completeness; no history. | Valid statuses, one record per member/session, configurable rule, 3-state quorum (achieved / not achieved / not yet determined), history, record-level privacy. |
| H4 | `proceedings.php` | Any user could **delete** official entries. | No deletion; Draft→Reviewed, amendment with version history, retraction with reason. |
| H5 | `minutes.php` | Generated minutes labelled unrecorded members "Absent", hardcoded "Prepared by the Secretary's Office", no approval state; unlimited regeneration (`minutes.json` duplicates). | Content from saved data only; unrecorded shown as unrecorded; Draft/PendingReview/Approved/Published; separation of duties; amendments. |
| H6 | `tracking.php` + `assets/app.js` | Live polling called `api/live_status.php` **relative to `modules/`** (404 – the "live" quorum never updated); updated IDs that did not exist; swallowed all errors silently; `api/live_status.php` had no permission check. | Correct path, real DOM ids, visible "connection problem" state, API requires login + permission. |
| H7 | `manage_devices.php` | Plain GET/POST by any non-QR user; tokens in plaintext JS; "admin" = whoever logged in with the demo password. | `devices.manage` permission, password re-entry, hashed tokens, one-time display. |

### Medium / Low
* `sidebar.php` decided the role label from a session prefix → replaced with real roles.
* Logout was a GET link (CSRF-able) → POST + CSRF.
* Error handling leaked nothing but also gave raw PHP failures → uniform safe 500 page.
* Third-party scripts: `html5-qrcode` loaded from unpkg without pinning/SRI → vendored locally; `qrcodejs` CDN removed (QR generation vendored).
* `app.html` loaded an unreviewed third-party script (`sites.super.myninja.ai/.../ninja-daytona-script.js`) and `lucide@latest` (unpinned). Script removed; lucide pinned to 0.469.0 (**verify this version renders your icons; it is the only change to part A**).
* Accessibility: labels, skip link, `aria-live`, `aria-current`, `aria-label` on icon buttons, focus ring.
* Duplicate submit: server-side duplicate checks + submit-button lock; unsaved-change warning.

### Not changed (documented)
* `js/app.bundle.js` was **not regenerated** (no build script is included). Syntax-checked only; the `js/*.js` ES-module sources are kept as-is.
* Part A has no authentication/RBAC/MFA (mock-data prototype). Adding client-side "login" there would be fake security; the recommendation is to treat it as a demo only.

## Remaining limitations → see `KNOWN_LIMITATIONS.md`
