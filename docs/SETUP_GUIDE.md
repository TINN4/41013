# Setup Guide

## Requirements
PHP ≥ 8.1 with `openssl`/`sodium`, `mbstring`, `json`, `session` (all standard). No Composer, no database. (Tests also need Python 3 + `requests`.)

## Run locally (development)
```bash
cd Legislative-Services-Management-System-Prototype/session-system
php tools/setup.php --dev          # creates config/.env (fresh key), migrates data, seeds LOCAL-ONLY dev accounts
php -S 127.0.0.1:8080 router.php   # MUST use router.php (blocks data/config folders)
# open http://127.0.0.1:8080/login.php
```
Browser prototype (separate application, mock data only): open `index.html` / `app.html` directly in a browser. Its *Login* links point to `session-system/login.php`, which works when the whole project folder is served by Apache/Nginx; for development just use the URL above.
QR login needs camera + location, which browsers only allow on `https://` or `localhost`.

## Development test accounts (LOCAL ONLY)
Created by `--dev`; refused when `SSMS_ENV=production`. Shared dev password: `DevOnly#2026-ssms`.
| Username | Role | MFA |
|---|---|---|
| `admin_dev`, `admin2_dev` | System Administrator (two, for the two-person MFA reset) | required → forced enrollment on first login |
| `secretary_dev` | Legislative Secretary | required |
| `presiding_dev` (member 1) | Presiding Officer | required |
| `councilor_dev` (member 2), `councilor2_dev` (member 3) | Council Member | optional |
| `docstaff_dev` | Documentation Staff | optional |
| `viewer_dev` | Authorized Viewer | optional |
**Before real use:** sign in as an admin, create real accounts (user chooses own password via one-time link), then *Deactivate* every account marked `DEV`; or delete `data/users.json` content and re-create.
Typical demo: `secretary_dev` schedules + builds/finalizes agenda + records roll call → `presiding_dev` starts → `docstaff_dev` logs proceedings → `secretary_dev` reviews entries and generates minutes → `presiding_dev` completes the session → `secretary_dev` submits → `presiding_dev` approves → `secretary_dev` publishes → `viewer_dev` reads.

## Configuration (`config/.env`, template `config/.env.example`)
Key settings: `SSMS_ENV`, `SSMS_APP_KEY` (**back up securely; it keys the stored OTP hashes. Without it no code can be issued and mandatory-MFA accounts cannot sign in**), session timeouts, MFA policy, geofence coordinates/radius, badge validity. Real environment variables override the file. Set `SSMS_ENV_FILE=/path/outside/webroot/.env` in production.
Office settings that need **authorized confirmation** (Admin → System Settings): quorum rule and basis; assumed session length; whether a finalized agenda is required to start. Not defined by this system: audit-log retention period, minutes approval authority beyond the Presiding Officer, legal quorum.

## Production checklist
1. Apache/Nginx + PHP-FPM over **HTTPS only**; document root = `session-system/`; enable `.htaccess` (`AllowOverride All`) or replicate its deny rules in Nginx (`location ~ ^/(data|config|includes|tools|tests)/ { deny all; }`). Do not use `php -S`.
2. `SSMS_ENV=production`; `.env` outside webroot, mode 0600, owned by the PHP user; `data/` writable by PHP only (0750).
3. Remove dev accounts; set `SSMS_MFA_ORDINARY_POLICY=required`.
4. Back up `data/` and `.env` regularly (separately). Rotate/archive `audit.log` per office policy without editing it.
5. Run `TZ=UTC python3 tests/run_tests.py` and `TZ=UTC python3 tests/test_email_otp.py` after any change (they use a throwaway data copy and a local SMTP sink). E-mail OTP setup: see EMAIL_OTP_SETUP.md.
