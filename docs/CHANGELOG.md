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

## E-mail OTP release
- Replaced TOTP/QR-enrollment/recovery-codes with e-mail OTP (PHPMailer/SMTP). New: `includes/otp.php`, `mailer.php`, `useremail.php`, `my_security.php`, `verify_email.php`, `tools/migrate_email_otp.php`, `account_recovery.php`, `test_mail.php`. Removed: `includes/mfa.php`, `mfa_setup.php`, `tools/mfa_break_glass.php`, `tests/unit_totp.php`.
- Mandatory roles are a hard-coded floor (config can only add). Mandatory accounts without a verified address are refused instead of entering an "enrollment-only" session.
- User creation requires the holder's own address; invitation and password links are e-mailed, never shown to the administrator; e-mail changes for privileged accounts need two administrators.
- Fixed: stale doc reference (`SETUP_AND_SECURITY.md`); tests now independent of `setup.php --dev` and of the shell time zone (run with `TZ=UTC`).

## Database storage release
- `includes/store.php` now has two backends behind the same `ssms_*` functions: MySQL/MariaDB (automatic when `DB_HOST` + `DB_DATABASE` are set) or JSON files (local development). Every logical table is one JSON document row in `ssms_store`; read-modify-write runs in a transaction on a `SELECT ... FOR UPDATE` row lock.
- Audit log (`ssms_audit`, same hash chain) and PHP sessions (`ssms_sessions`, `includes/session_db.php`) also live in the database, so a redeploy no longer wipes accounts, data or sign-ins.
- New: `tools/migrate_to_db.php` (`--check`, `--import`). `setup.php` and `migrate_email_otp.php` work on either backend and print which one is active. `ssms_table_exists()` replaces direct file checks.
- Dockerfile installs `pdo_mysql`. Shipped `data/*.json` files are used once as first-run seed data for an empty database.
- Fixed: `.htaccess`/`router.php` blocked `assets/vendor/` (QR generator and scanner never loaded). Added speech dictation to Proceedings; QR badge Save image now saves a PNG.
- Tests: file mode 220/220 and 70/70 unchanged; database mode verified for CRUD, rollback, corruption refusal, 25 concurrent writers, audit chain, sessions across a server restart.
