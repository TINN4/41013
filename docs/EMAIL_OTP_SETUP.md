# E-mail OTP: setup, migration, recovery, testing, defense notes

## 1. Requirements
PHP ≥ 8.1 with `sodium`, `openssl`, `mbstring`. PHPMailer 6.9.3 is **vendored** in `session-system/vendor/phpmailer` (no Composer needed; `composer.json` documents it, `composer update` also works). Blocked from the web by `.htaccess` / `router.php`.

## 2. Configure (`session-system/config/.env`; template: `config/.env.example`)
`php tools/setup.php` creates `.env` with a fresh `SSMS_APP_KEY`. Then set:
`SSMS_SMTP_HOST/PORT/ENCRYPTION/USER/PASS`, `SSMS_MAIL_FROM(_NAME)`, **`SSMS_APP_URL`** (public URL of the `session-system` folder; used for links in e-mails and never taken from the Host header), and optionally `SSMS_OTP_EXPIRY_MIN` (5), `SSMS_OTP_MAX_ATTEMPTS` (5), `SSMS_OTP_RESEND_COOLDOWN_SEC` (60), `SSMS_OTP_SENDS_PER_15MIN` (6), `SSMS_EMAIL_LINK_TTL_HOURS` (48), `SSMS_MFA_REQUIRED_ROLES`, `SSMS_MFA_ORDINARY_POLICY`.
The SMTP account is only the *sender*. Each user's own verified address is the destination. Gmail: host `smtp.gmail.com`, port 587, `tls`, an App Password (needs 2-Step Verification). Never commit `.env`; keep it outside the web root in production (`SSMS_ENV_FILE`).
**Test real delivery:** `php tools/test_mail.php you@yourdomain` (prints `SENT` only if the SMTP server accepted it; check the inbox to confirm).

## 3. Creating accounts
**Council Members page (Secretary or System Administrator):** *Council Members → Add council member* creates the member record and a `councilor` account in one step (role is fixed server-side; that page cannot touch any other role). The e-mail must have a real domain (MX/A lookup, `SSMS_EMAIL_DNS_CHECK`) and, more importantly, is trusted only after the member opens the invitation sent to it: a made-up or mistyped mailbox never receives codes and never becomes verified. DNS can show a domain exists, not that a specific mailbox exists; only the confirmation link proves that.

**User Accounts page (System Administrator):** for any role.

Administrators (`users.manage` = System Administrator; the existing permission model was kept) use *User Accounts → Create account*: name, username, role, **the holder's own e-mail**. An invitation is e-mailed to that address; the holder sets the password through it, which also verifies the address. Until then the address is *pending* and mandatory-role accounts cannot sign in. If mail is down the account still exists; use *E-mail password link* later.
Limit worth stating openly: verification proves control of an inbox, not that it belongs to the intended person. Entering the right address is the administrator's duty; creation and changes are audited.

## 4. Migrating an existing TOTP installation
1. Back up `data/` and `config/.env`.  2. Deploy the new code.  3. `php tools/migrate_email_otp.php` (dry run) then `--apply`.  4. Give the first administrator a verified address: `php tools/account_recovery.php set-email <admin> <address> "<reason>"` (e-mails a confirmation link) .  5. That administrator signs in and adds addresses for others.  6. After the cut-over is proven: `php tools/migrate_email_otp.php --purge-legacy`.
Effects: no address is guessed or auto-verified; mandatory roles cannot sign in until verified (nobody gains password-only access); old TOTP data is kept in `mfa_legacy_totp` (unused by any code) for rollback until purged; ex-TOTP ordinary users are opted in so they are not silently downgraded; all sessions end.
**Rollback:** stop the app, restore `data/backups/<timestamp>_pre-email-otp-migration/*` and the previous code release.

## 5. Recovery procedures (never a public bypass)
| Case | Procedure |
|---|---|
| User lost / wrong mailbox | Admin: *Change e-mail* (tick "current address lost" if the old one is untrusted). Privileged accounts: second administrator approves. New address is confirmed from its own inbox. |
| Secretary / Presiding can't get codes | Same as above, done by an administrator. |
| System Admin can't get codes | Another administrator does it. If none can sign in: server operator runs `tools/account_recovery.php set-email …` (shell access = trust boundary; backs up, audits with reason; `--mark-verified` only after out-of-band identity check). |
| SMTP outage | Mandatory roles wait (no fallback). Fix SMTP, then press *Send a new code*. Operators with shell access use `account_recovery.php` only for lockouts that cannot wait. |
| Disabled/suspended admin | Another administrator re-activates; else operator edits via the tool/ restore. |
| Expired/invalid/many codes | Per-login limits apply; sign in again, or wait out the 15-minute lockout; admin *Unlock* clears throttles. |
| Password forgotten | *E-mail password link* (to the verified address). `account_recovery.php password-link` prints a link for in-person hand-over. |

## 6. Tests
`TZ=UTC python3 tests/run_tests.py` (legislative regression + RBAC + OTP lifecycle) and `TZ=UTC python3 tests/test_email_otp.py` (SMTP outage, misconfiguration, rate limits, concurrency, account creation/ownership, migration, recovery tools). They drive the real app over HTTP with real PHPMailer against a **local SMTP sink**: this proves the SMTP code path, not delivery by a real provider (do the `test_mail.php` check above). `TZ=UTC` matters because the app uses PHP's UTC dates.

Manual checklist: create a user with a real inbox → receive invitation → set password → sign in → code arrives in that inbox only → wrong code ×5 → locked out → expired code → Send a new code (cooldown) → switch SMTP password to a wrong value and confirm sign-in is refused with the safe message → restore.

## 7. Architecture for the defense
Flat-file store kept; new table `otp_challenges`; new account fields `email`, `email_verified`, `pending_email`, `mfa.email_otp`. One policy gate `ssms_mfa_gate()` consulted at sign-in *and* on every request; one engine `includes/otp.php`; one mailer `includes/mailer.php`; one ownership module `includes/useremail.php`. Password and QR both feed the same gate.

## 8. Likely panel questions
- **Why e-mail OTP is weaker than TOTP/WebAuthn?** It's only as strong as the mailbox; chosen for no-device requirement. Mitigations: verified address, short expiry, attempt limits, mailbox owner ≠ administrator, recommend MFA on the mailbox.
- **How do you stop brute force of a 6-digit code?** 5 tries/code, then login destroyed; per-account lockout; send caps; 10⁶ space ⇒ ≤ 5·10⁻⁶ success per code.
- **Can the Secretary receive someone else's OTP?** Codes go only to the target's *verified* address; the Secretary never enters or verifies it for others (and Secretary can't manage users); duplicate addresses are refused.
- **What if SMTP is down?** Fail closed; retry; documented operator recovery.
- **Is the OTP stored?** Only an HMAC with a key from the environment; single use; superseded by newer codes.
- **Race conditions?** All challenge changes run under an exclusive file lock; tested with 8 simultaneous submissions (one wins) and 20 concurrent wrong guesses (≤5 counted).
- **Why a fixed mandatory-role floor?** So a config typo cannot disable MFA for privileged roles.
- **Session fixation?** Session id regenerated at the password step and after the code.
- **Why not JSON → MySQL?** Out of scope; migration path in `STORAGE_AND_MIGRATION.md`.
