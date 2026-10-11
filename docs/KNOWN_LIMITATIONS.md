# Known Limitations & Items Needing Confirmation

**Not verified in this environment**
* No real browser was available: visual layout, responsive behaviour, the browser console, camera QR scanning, and Geolocation prompts were **not** exercised. Server-rendered HTML, JSON APIs, and security behaviour were tested over HTTP; the JS (`assets/app.js`) was only syntax-checked, and the polling code was not run in a browser.
* The browser prototype (part A) was only syntax-checked; its pages were not opened. The one change (removed third-party script, pinned lucide) should be eyeballed.
* Apache `.htaccess` rules were not run on Apache (the equivalent `router.php` was tested). Nginx rules are documented, not tested.
* Email OTP, SMTP, MySQL migration: not implemented/tested.

**Design limitations**
* JSON file storage (see `STORAGE_AND_MIGRATION.md`): not for many simultaneous users; no multi-file transactions.
* Geofence is client-reported and spoofable; QR scan ≠ proof of presence. Badge + device secret live in the member's browser; XSS or device theft exposes them (mitigated by revocation, MFA).
* Account lockout is per username and per IP; a motivated attacker can lock out a user (deliberate trade-off). Admin can unlock.
* Rate-limit and audit files grow; no automatic rotation.
* A sysadmin can edit the quorum rule/"confirmed by office" flag (it is audited). If the office wants this restricted, move `settings.manage` to another role.
* Sessions already completed before this version have `Draft` agendas and `Draft` proceedings (legacy data, not reclassified so as not to invent review/approval).
* The approver of minutes is any user holding `minutes.approve` (Presiding Officer by default). The office must confirm who is formally authorized.
* Starting a session is allowed even if quorum has not been achieved (the system warns/records but does not block; the legal consequence is for the office to define).
* Passwords: no breach-list check; policy is length-based. No "remember device" for MFA (by design).

**Requires authorized confirmation (not invented)**: quorum threshold/basis, which members count (`eligible_for_quorum`), minutes approval authority, audit-log retention, session-start rules, MFA policy for ordinary accounts.

## E-mail OTP limitations
- Not tested against a real SMTP provider in this build (only a local SMTP sink); run `tools/test_mail.php` with real credentials.
- Security equals the strength of the user's mailbox; verification proves inbox control, not identity.
- Account creation/e-mail management stays with `users.manage` (System Administrator); the brief mentioned the Secretary creating accounts, but the existing permission model was preserved. Granting `users.manage` to the Secretary is a one-line RBAC change if the office wants it.
- Flat-file storage: single-server only; `otp_challenges.json` is pruned after 24 h.
- One mailbox per account (duplicates refused); shared mailboxes are not supported.
- If `SSMS_APP_KEY` is lost, codes cannot be issued until a new key is configured (stored challenges are short-lived).
- The static front-end prototype (`index.html`/`app.html`) has no authentication and was not changed.
