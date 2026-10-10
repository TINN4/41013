# RBAC Permission Matrix

Source of truth: `session-system/includes/rbac.php` (this table is generated from it). Authorization is **deny-by-default**, evaluated on the server from the role stored in `data/users.json`; browser-supplied role values/cookies are ignored (test F7). Roles are never taken from storage in the browser.

| Permission | System Administrator | Legislative Secretary / Secretariat Staff | Presiding Officer | Council Member / Councilor | Documentation Staff / Transcriptionist | Authorized Viewer / Observer |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| `agenda.edit` |  | ✔ |  |  |  |  |
| `agenda.finalize` |  | ✔ |  |  |  |  |
| `agenda.revise` |  | ✔ |  |  |  |  |
| `agenda.view` |  | ✔ | ✔ | ✔ | ✔ |  |
| `agenda.view_final` |  |  |  |  |  | ✔ |
| `attendance.correct` |  | ✔ |  |  |  |  |
| `attendance.record` |  | ✔ |  |  |  |  |
| `attendance.view_all` |  | ✔ | ✔ |  |  |  |
| `attendance.view_own` |  |  |  | ✔ |  |  |
| `audit.view` | ✔ |  |  |  |  |  |
| `devices.manage` | ✔ |  |  |  |  |  |
| `mfa.reset.approve` | ✔ |  |  |  |  |  |
| `mfa.reset.request` | ✔ |  |  |  |  |  |
| `minutes.approve` |  |  | ✔ |  |  |  |
| `minutes.edit` |  | ✔ |  |  |  |  |
| `minutes.generate` |  | ✔ |  |  |  |  |
| `minutes.publish` |  | ✔ |  |  |  |  |
| `minutes.submit` |  | ✔ |  |  |  |  |
| `minutes.view_approved` |  | ✔ | ✔ | ✔ |  |  |
| `minutes.view_draft` |  | ✔ | ✔ |  | ✔ |  |
| `minutes.view_published` |  |  |  |  |  | ✔ |
| `proceedings.amend` |  | ✔ |  |  |  |  |
| `proceedings.draft` |  | ✔ |  |  | ✔ |  |
| `proceedings.review` |  | ✔ | ✔ |  |  |  |
| `proceedings.view` |  | ✔ | ✔ | ✔ | ✔ |  |
| `proceedings.view_draft` |  | ✔ | ✔ |  | ✔ |  |
| `quorum.view` |  | ✔ | ✔ | ✔ |  |  |
| `schedule.cancel` |  | ✔ |  |  |  |  |
| `schedule.manage` |  | ✔ |  |  |  |  |
| `schedule.view` | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| `session.transition` |  |  | ✔ |  |  |  |
| `settings.manage` | ✔ |  |  |  |  |  |
| `tracking.update_current` |  | ✔ |  |  |  |  |
| `tracking.view` |  | ✔ | ✔ | ✔ | ✔ |  |
| `users.manage` | ✔ |  |  |  |  |  |

## Notes on the design
* **System Administrator** has *only* technical permissions (+ read-only schedule view). It cannot edit agendas, attendance, proceedings, or approve/publish minutes (tests D1, E54).
* **Secretary** can prepare and submit but **cannot approve** minutes (E54) and cannot start/complete sessions (presiding-only `session.transition`; the secretary can schedule and cancel *scheduled* sessions).
* **Separation of duties** (enforced in code): the approver cannot be the person who generated/submitted the minutes; an entry's reviewer cannot be its author; an MFA reset needs a requester and a *different* approver; admins cannot change their own role or deactivate/lock themselves; the last usable administrator cannot be demoted, deactivated or locked.
* **Councilor** sees only their own attendance row (record-level restriction, linked via `users.member_id`) and only *Reviewed* proceedings and *Approved/Published* minutes.
* **Observer** sees finalized agendas and *Published* minutes only; no proceedings, attendance, or tracking.
* Role change / deactivation / lock / badge re-issue bumps `session_version`, ending the user's live sessions on their next request (F9, F10, G10).

## Needs confirmation by the authorized office
The role→permission mapping is a *recommended initial model* from the project brief. In particular: who may start/complete a session (here: Presiding Officer), whether the Secretary may finalize agendas (here: yes), and who publishes minutes (here: Secretary). Change them in one place (`rbac.php`).
