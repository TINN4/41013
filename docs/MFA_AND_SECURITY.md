# MFA & Security Summary (e-mail OTP)

Authenticator-app TOTP, QR enrollment and recovery codes were **removed**. The second factor is a one-time code e-mailed to the account holder's *verified* address. Setup, SMTP, migration, recovery and the defense Q&A are in `EMAIL_OTP_SETUP.md`.

## Policy (server-side, from stored account data only)
| Situation | Result |
|---|---|
| `sysadmin`, `secretary`, `presiding` (built-in floor; config can only add roles) | e-mail code **always**; no verified address ⇒ sign-in refused |
| Other role, policy `optional`, user opted in | code required; opt-in only possible with a verified address |
| Other role, policy `optional`, not opted in | password (or QR badge) only |
| Other role, policy `required` | code; no verified address ⇒ password-only during `SSMS_MFA_GRACE_DAYS`, then refused |
| Unknown / malformed role | refused |
`ssms_current_user()` re-evaluates this on **every request**: a session without a completed code is destroyed for any account that requires one.

## Login flow
1. Username + password (or QR badge + device secret) → first factor only. Generic error, throttled, audited.
2. `ssms_after_first_factor()` decides `none | otp | blocked`. For `otp`: new session id, restricted *pending* state (grants nothing), 6-digit CSPRNG code e-mailed through PHPMailer/SMTP.
3. `mfa.php`: masked address, code input, Verify, Send a new code (cooldown), Cancel.
4. Server verifies under a table lock: right account, right pending login, active, unexpired, unused, attempts left. Success consumes the code atomically.
5. `ssms_establish_session()` regenerates the session id again, stores user id, `session_version`, `mfa_passed=true`, and clears pending state.

## Code protection
5-minute expiry · 5 wrong tries per code (then the pending login is destroyed) · 60 s resend cooldown within one login · new code supersedes older ones · single use · HMAC-SHA256 hash stored (key derived from `SSMS_APP_KEY`), plaintext never stored, logged or returned · per-account/IP send caps · per-account failure lockout · CSRF on every POST · strict field whitelist on the code form · code is bound to the e-mail address it was sent to (address change cancels it) · pending login dies if the account is deactivated, locked, or its password/role/e-mail changes.

## E-mail ownership
An address is trusted only after its owner proves control by opening an e-mailed link: either the invitation/password link (sets the password *and* verifies the address it was sent to) or the confirmation link (`verify_email.php`, confirm button = POST, so link scanners cannot verify). Addresses are unique across accounts. Changing the address of a privileged account needs request + approval by two different administrators; self-service changes need the current password and keep the old address active until the new one is confirmed (the old one is notified). Administrators never see password links; they are e-mailed.

## Failure behaviour
SMTP down, missing SMTP settings, missing `SSMS_APP_KEY`, bad encryption setting, no verified address: mandatory roles get **no session**, a safe message, a sanitized log line, and a retry button. There is no fallback to password-only or to any other factor.

## Unchanged protections
`password_hash`, ≥12-char policy, generic login errors, progressive lockout, HttpOnly/SameSite cookies, idle/absolute timeouts, escaped output, same-origin relative redirects, hash-chained audit log (no secrets), safe 500 pages, QR badge + device binding + geofence (QR is a first factor; it does not bypass the code).

## Not implemented
SMS, WebAuthn, external IdPs, CSP. See `KNOWN_LIMITATIONS.md`.
