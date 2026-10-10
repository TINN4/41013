# MFA & Security Implementation Summary

## Login flow (password and QR share the same gate)
1. Password (or QR badge+device) verified → **first factor only**.
2. If MFA is enabled: a server-side *pending* state is created (5-minute TTL, 5 tries) and the session id is regenerated. The pending state grants **no** access: protected pages redirect to login, APIs return 401 (tests C11, C12, G14).
3. User enters a TOTP code **or** a recovery code at `mfa.php`.
4. Only then `ssms_establish_session()` regenerates the session id again and loads the role from `users.json`.
5. If the account's MFA policy is *required* but not enrolled, the session is **enrollment-only**: every page except `mfa_setup.php`/logout redirects there (C1, C3).
Nothing client-side (cookies, localStorage) is trusted as proof of MFA (F7).

## TOTP
* RFC 6238/4226, HMAC-SHA1, 6 digits, 30 s, ±1 step window. Implemented with PHP built-ins only; **verified against the RFC 6238 test vectors** (`tests/unit_totp.php`) and cross-checked against an independent Python implementation in the HTTP tests.
* 160-bit secret from `random_bytes`; QR rendered **in the browser** with a vendored library (no third-party service sees the secret); manual key also shown; secret is never in a URL or log.
* Secret stored encrypted (libsodium `secretbox`) with `SSMS_APP_KEY` from the environment/`.env` (not in code). Missing/undecryptable secret ⇒ login fails with a configuration error; MFA is **never silently disabled**.
* Replay prevention: last accepted time-step stored; checked and updated atomically under a file lock (C16).
* Throttling: 5 wrong codes → 15-minute lockout (progressive), pending state destroyed (C20).
* 10 recovery codes (50 bits each, CSPRNG), shown once, stored only as keyed HMAC-SHA256, single-use (C7, C8, C17, C18).
* Re-verification (password + current TOTP) for regenerating codes or disabling MFA. Disabling is refused for roles where MFA is required.

## MFA policy (config/.env)
`SSMS_MFA_REQUIRED_ROLES=sysadmin,secretary,presiding`; `SSMS_MFA_ORDINARY_POLICY=optional|required`; `SSMS_MFA_GRACE_DAYS`. **Recommended for a real deployment: `required` for all roles.**

## Recovery / reset
* Lost device: use a recovery code → regenerate codes.
* No codes: one administrator **requests** a reset (reason mandatory); a **different** administrator **approves** (admin password re-entered by both). Effects: MFA cleared, sessions ended, user must re-enroll (F14–F19).
* No usable administrator: server-side break-glass `php tools/mfa_break_glass.php <user> "<reason>"` (needs shell access; backs up data and audits).

## QR badge & device model
* Badge QR = opaque 192-bit random identifier (no password/secret/role inside); stored only as SHA-256; expires (`SSMS_BADGE_VALID_DAYS`).
* First scan must occur within `SSMS_BADGE_ENROLL_HOURS` after the admin issues it; the server then generates a 256-bit **device secret** returned once to the device (stored in that browser's localStorage; only its hash is stored server-side). Later logins need badge **and** device secret ⇒ a photographed badge alone is useless (G7, G8).
* Re-issue/revoke/reset require `devices.manage` + the admin's password; revoke ends live sessions immediately (G10, G11).
* QR login with MFA required stops at the MFA challenge (G13–G15). Responses reveal no name/role before MFA (G5).
* **Geofence**: coordinates come from the user's browser and can be spoofed; indoor GPS is imprecise. It is a policy deterrent, not proof of presence. Neither the geofence nor a QR scan is treated as proof of physical presence for attendance, and the existing QR flow is *login* only — it does not record attendance.
* Throttling by IP and by badge (G18); same-origin + JSON-only to stop login-CSRF (G16, G17).

## Other protections
`password_hash` (bcrypt/argon default), ≥12-char policy, generic login errors, progressive lockout, session cookies `HttpOnly; SameSite=Lax; Secure` (when HTTPS), strict session mode, 30-min idle / 8-h absolute timeouts (configurable), CSRF on all POSTs, escaped output everywhere (tested E2), no open redirects (`ssms_redirect` only relative), admin-issued one-time password links (24 h, hashed, single-use, moved out of the URL; H1–H4), hash-chained append-only audit log (tamper-evident, I1, I4) containing no secrets (I2), safe 500 pages with no stack traces (I6), security headers.

## Not implemented
* **Email OTP** — not built (no SMTP configured/tested; the brief says do not claim it works). `SSMS_SMTP_*` keys are placeholders only.
* SMS, external identity providers, WebAuthn/phishing-resistant MFA.
* Content-Security-Policy (the existing pages use inline scripts and CDNs for fonts/icons).
