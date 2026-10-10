# Change Log (this audit/repair)

## New files (`session-system/`)
`includes/bootstrap.php` (config, hardened sessions, CSRF, rate limiting, guards) · `includes/config.php` · `includes/rbac.php` (central permissions) · `includes/domain.php` (quorum, state machine, conflicts, transitions) · `includes/audit.php` (hash-chained log) · `includes/mfa.php` (TOTP/base32/encryption/recovery codes) · `includes/authflow.php` (login/MFA primitives) · `mfa.php`, `mfa_setup.php`, `reset_password.php`, `dashboard.php`, `admin_users.php`, `audit_view.php`, `settings.php` · `router.php`, `index.php`, `.htaccess` · `tools/setup.php`, `tools/mfa_break_glass.php` · `tests/unit_totp.php`, `tests/run_tests.py` · `config/.env.example` · `assets/vendor/{qrcode.js,html5-qrcode.min.js}` + licenses · docs.

## Rewritten (behaviour preserved where correct)
| File | Why |
|---|---|
| `login.php`, `logout.php` | real accounts, throttling, MFA step, POST+CSRF logout |
| `qr_login.php`, `api/qr_login.php` | secure badge/device/MFA model; XSS and spoofing fixes |
| `manage_devices.php` | RBAC, hashed tokens, revoke/reset/re-issue |
| `includes/store.php` | locking, atomic writes, corruption safety, helpers |
| `includes/auth.php`, `header.php`, `sidebar.php`, `footer.php`, `geofence.php` | shim, permission-aware nav, a11y, config-driven geofence |
| `modules/scheduling.php` | validation, conflicts, state machine, edit, safe delete |
| `modules/agenda.php` | Draft/Final/Revision, history, ordering fix, IDOR checks |
| `modules/attendance.php` | statuses, history, departures, 3-state quorum, privacy |
| `modules/proceedings.php` | no deletion; draft/review/amend/retract + versions |
| `modules/minutes.php` | real-data snapshot, approval workflow, amendments |
| `modules/tracking.php`, `api/live_status.php`, `assets/app.js` | permission checks, working polling, honest sync state, confirm/duplicate guards |
| `assets/style.css` | appended classes only |
## Data
Additive migration + new tables (see STORAGE_AND_MIGRATION.md). `data/qr_users.json` removed from the shipped tree (plaintext tokens).
## Browser prototype
`app.html`: removed third-party `ninja-daytona-script.js`; pinned `lucide`. Nothing else touched.
