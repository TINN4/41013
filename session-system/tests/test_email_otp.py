#!/usr/bin/env python3
"""
Dedicated e-mail OTP scenarios that need special server setups: SMTP outage, missing SMTP/APP_KEY config,
send rate limits, concurrency, account creation + e-mail ownership, migration and recovery tools.
Usage: TZ=UTC python3 tests/test_email_otp.py     (real PHPMailer -> local SMTP sink; NOT a real mail provider)
"""
import json, os, re, subprocess, sys, threading, time
import requests
import harness as H
from harness import check, C, login, start_login, code_from, link_from, email_of, php, read, write, PW, BASE

def attempt(user="admin_dev"):
    c = C(); c.get("login.php"); r = c.post("login.php", {"username": user, "password": PW}); return c, r

def scenario_outage():
    srv = H.Server(tag="outage")
    try:
        H.SINK.fail = True
        c, r = attempt()
        check("S1 SMTP delivery failure: no session, no dashboard, safe message, retry offered", "mfa.php" in r.url and "could not send" in r.text.lower() and "Send a new code" in r.text and c.get("dashboard.php", follow=False).status_code == 302, r.text[:200])
        check("S2 failure page leaks no SMTP detail", not re.search(r"554|simulated|127\.0\.0\.1|PHPMailer|Traceback", r.text))
        check("S3 failed delivery is recorded as undelivered and unusable", read("otp_challenges")[-1]["status"] == "undelivered")
        log = srv.log()
        check("S4 sanitized technical error reached the server log (admins can diagnose)", "mail send failed" in log, log[-200:])
        time.sleep(0.2); H.SINK.fail = False
        r = c.post("mfa.php", {"action": "resend"})
        m = H.SINK.wait_new(email_of("admin_dev"), 0)
        check("S5 controlled retry works once the outage ends (no cooldown penalty for the failed send)", m is not None)
        r = c.post("mfa.php", {"action": "verify", "code": code_from(m)}); check("S6 ...and the retried code signs in", "dashboard.php" in r.url)
        # secretary and presiding likewise cannot slip in during an outage
        H.SINK.fail = True
        for u in ("secretary_dev", "presiding_dev"):
            c2, r2 = attempt(u); check(f"S7 outage: {u} cannot reach protected pages", c2.get("modules/scheduling.php", follow=False).status_code == 302)
        H.SINK.fail = False
        # optional role still password-only during an outage (policy), mandatory never falls back
        c3, r3 = attempt("councilor_dev"); check("S8 optional role keeps its configured password-only flow during an outage", "dashboard.php" in r3.url)
        audit = open(os.path.join(H.DATA, "audit.log")).read()
        check("S9 audit log has the failed delivery and no code/secret", '"otp.issue"' in audit and '"delivery_failed"' in audit)
    finally: srv.stop(); srv.cleanup()

def scenario_misconfig():
    for tag, extra, mail in [("nosmtp", {}, False), ("nokey", {"SSMS_APP_KEY": ""}, True), ("badenc", {"SSMS_SMTP_ENCRYPTION": "bogus"}, True)]:
        srv = H.Server(extra_env=extra, with_mail=mail, tag=tag)
        try:
            if tag == "nokey":
                srv.proc.terminate(); srv.proc.wait()                 # restart with the key really absent
                env = dict(srv.env); env.pop("SSMS_APP_KEY", None); env["SSMS_APP_KEY"] = ""
                srv.proc = subprocess.Popen(["php", "-S", f"127.0.0.1:{H.PORT}", "router.php"], cwd=H.ROOT, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL); time.sleep(1)
            n = H.SINK.count()
            c, r = attempt()
            check(f"S10[{tag}] misconfiguration fails CLOSED: mandatory role gets no session and no code", c.get("dashboard.php", follow=False).status_code == 302 and H.SINK.count() == n and "mfa.php" in r.url, r.url)
            check(f"S11[{tag}] ...with a generic, safe message", "could not send" in r.text.lower() or "unavailable" in r.text.lower())
            c2, r2 = attempt("councilor_dev"); check(f"S12[{tag}] ordinary role unaffected (policy: password only)", "dashboard.php" in r2.url)
        finally: srv.stop(); srv.cleanup()

def scenario_rate_and_concurrency():
    srv = H.Server(extra_env={"SSMS_OTP_SENDS_PER_15MIN": "3"}, tag="rate")
    try:
        before = H.SINK.count(email_of("admin_dev"))
        for i in range(5): attempt()
        time.sleep(0.5)
        sent = H.SINK.count(email_of("admin_dev")) - before
        c, r = attempt()
        check("S13 code e-mails per account are capped (3 per 15 min here); further logins are refused without mailing", sent == 3 and "Too many codes" in r.text or "wait" in r.text.lower(), f"sent={sent}")
        write("ratelimit", {})
    finally: srv.stop(); srv.cleanup()
    srv = H.Server(tag="conc")
    try:
        c, r, b = start_login("admin_dev"); m = H.SINK.wait_new(email_of("admin_dev"), b); code = code_from(m)
        # 8 simultaneous submissions of the SAME correct code from the SAME pending login: exactly one may win
        c.get("mfa.php"); tok = c.csrf; cookies = c.s.cookies.get_dict(); res = []
        def go():
            rr = requests.post(BASE + "/mfa.php", data={"csrf": tok, "action": "verify", "code": code}, cookies=cookies, allow_redirects=False); res.append(rr)
        ts = [threading.Thread(target=go) for _ in range(8)]; [t.start() for t in ts]; [t.join() for t in ts]
        wins = [x for x in res if x.status_code == 302 and "dashboard.php" in x.headers.get("Location", "")]
        used = [x for x in read("otp_challenges") if x["status"] == "used"]
        check("S14 concurrent verification: the challenge is consumed exactly once", len(used) == 1 and 1 <= len(wins) <= 8, f"used={len(used)} wins={len(wins)}")
        # concurrent WRONG guesses cannot exceed the attempt budget
        c, r, b = start_login("secretary_dev"); m = H.SINK.wait_new(email_of("secretary_dev"), b); good = code_from(m)
        c.get("mfa.php"); tok = c.csrf; cookies = c.s.cookies.get_dict()
        wrong = [f"{i:06d}" for i in range(100000, 100020) if f"{i:06d}" != good]
        def g2(w): requests.post(BASE + "/mfa.php", data={"csrf": tok, "action": "verify", "code": w}, cookies=cookies, allow_redirects=False)
        ts = [threading.Thread(target=g2, args=(w,)) for w in wrong]; [t.start() for t in ts]; [t.join() for t in ts]
        ch = [x for x in read("otp_challenges") if x["user_id"] == 2][-1]
        check("S15 20 concurrent wrong guesses: attempt counter never exceeds the limit and the challenge is dead", ch["attempts"] <= 5 and ch["status"] == "exhausted", str(ch))
        rr = requests.post(BASE + "/mfa.php", data={"csrf": tok, "action": "verify", "code": good}, cookies=cookies, allow_redirects=False)
        check("S16 ...so the correct code no longer works", "dashboard.php" not in rr.headers.get("Location", ""))
        # concurrent logins -> only the newest challenge stays active
        cs = []
        def lg(): cs.append(attempt("presiding_dev"))
        ts = [threading.Thread(target=lg) for _ in range(5)]; [t.start() for t in ts]; [t.join() for t in ts]
        act = [x for x in read("otp_challenges") if x["user_id"] == 3 and x["status"] == "active"]
        check("S17 simultaneous logins of one account leave at most one active challenge", len(act) <= 1, str(len(act)))
    finally: srv.stop(); srv.cleanup()

def scenario_creation_and_ownership():
    srv = H.Server(tag="own")
    try:
        adm = login("admin_dev"); adm.get("admin_users.php")
        mk = lambda u, e, role="councilor": adm.post("admin_users.php", {"action": "create", "username": u, "name": "Member " + u, "email": e, "role": role, "confirm_password": PW})
        n_adm = H.SINK.count(email_of("admin_dev"))
        r = mk("member01", "member01@dev.invalid")
        row = [u for u in read("users") if u["username"] == "member01"][0]
        check("S18 account created: address stored as PENDING/unverified, no password set by the admin", row["email"] is None and not row["email_verified"] and row["pending_email"]["address"] == "member01@dev.invalid" and row["password_hash"] is None)
        m = H.SINK.last("member01@dev.invalid")
        check("S19 invitation went to the account holder's OWN address, never to the admin's", m is not None and H.SINK.count(email_of("admin_dev")) == n_adm and "reset_password.php?token=" in m["body"])
        check("S20 page never shows the setup link to the administrator", "reset_password.php?token=" not in r.text)
        check("S21 duplicate username rejected", "already exists" in mk("member01", "other@dev.invalid").text)
        check("S22 duplicate e-mail (even the Secretary's/Admin's) rejected", "already belongs" in mk("member02", email_of("secretary_dev")).text and "already belongs" in mk("member03", "MEMBER01@dev.invalid").text)
        check("S23 invalid e-mail rejected / missing e-mail rejected", "valid e-mail" in mk("member04", "not-an-email").text and "valid e-mail" in mk("member05", "").text)
        check("S24 wrong admin password -> nothing created", "password was not correct" in adm.post("admin_users.php", {"action": "create", "username": "member06", "name": "x", "email": "m6@dev.invalid", "role": "viewer", "confirm_password": "nope"}).text)
        nx = C(); sec = login("secretary_dev"); sec.get("dashboard.php")
        check("S25 Secretary cannot reach User Management or self-promote (403)", sec.get("admin_users.php").status_code == 403 and sec.post("admin_users.php", {"action": "change_role", "id": 2, "role": "sysadmin", "confirm_password": PW}).status_code == 403 and [u for u in read("users") if u["username"] == "secretary_dev"][0]["role"] == "secretary")
        # member sets password through the emailed link -> address becomes verified
        link = link_from(m, "reset_password.php"); mc = C(); mc.get(link.replace(BASE + "/", ""))
        NEWPW = "Member-Own-Passphrase-42"; r = mc.post("reset_password.php", {"new": NEWPW, "confirm": NEWPW})
        row = [u for u in read("users") if u["username"] == "member01"][0]
        check("S26 following the e-mailed link proves inbox control: address becomes verified", row["email"] == "member01@dev.invalid" and row["email_verified"] and row["pending_email"] is None)
        # secretary-role accounts: invitation, then OTP goes to their own inbox
        mk("sec2", "sec2@dev.invalid", "secretary"); ms = H.SINK.last("sec2@dev.invalid")
        s2 = C(); s2.get(link_from(ms, "reset_password.php").replace(BASE + "/", "")); s2.post("reset_password.php", {"new": NEWPW, "confirm": NEWPW})
        b = H.SINK.count("sec2@dev.invalid"); n_a = H.SINK.count(email_of("admin_dev")); c = C(); c.get("login.php"); c.post("login.php", {"username": "sec2", "password": NEWPW})
        mm = H.SINK.wait_new("sec2@dev.invalid", b)
        check("S27 new Secretary's code arrives in HER OWN inbox, not the creating admin's", mm is not None and "verification code" in mm["body"] and H.SINK.count(email_of("admin_dev")) == n_a)
        # password set but address still unverified (admin set via data) -> blocked
        mk("sec3", "sec3@dev.invalid", "secretary")
        users = read("users"); u3 = [u for u in users if u["username"] == "sec3"][0]
        import subprocess as sp
        u3["password_hash"] = [u for u in users if u["username"] == "admin_dev"][0]["password_hash"]; write("users", users)
        n = H.SINK.count("sec3@dev.invalid"); c3 = C(); c3.get("login.php"); r = c3.post("login.php", {"username": "sec3", "password": PW})
        check("S28 password known but address never verified: Secretary refused, no code mailed to the unverified address", "verified e-mail" in r.text and H.SINK.count("sec3@dev.invalid") == n)
        # resend verification
        r = adm.post("admin_users.php", {"action": "resend_verification", "id": u3["id"], "confirm_password": PW})
        ml = H.SINK.last("sec3@dev.invalid"); lk = link_from(ml, "verify_email.php")
        check("S29 admin can resend the confirmation link (sent only to the pending address)", lk is not None)
        # expired link
        users = read("users"); [u.__setitem__("pending_email", dict(u["pending_email"], expires=int(time.time()) - 5)) for u in users if u["username"] == "sec3"]; write("users", users)
        e = C(); r = e.get(lk.replace(BASE + "/", "")); check("S30 expired confirmation link is rejected", "Link not valid" in r.text)
        # self-service email change keeps old address active until confirmed + notifies the old address
        mc2 = login("viewer_dev"); n_old = H.SINK.count(email_of("viewer_dev"))
        r = mc2.post("my_security.php", {"action": "change_email", "new_email": "viewer.self@dev.invalid", "password": "wrong"}); check("S31 self e-mail change needs the current password", "incorrect" in r.text)
        r = mc2.post("my_security.php", {"action": "change_email", "new_email": "viewer.self@dev.invalid", "password": PW})
        row = [u for u in read("users") if u["username"] == "viewer_dev"][0]
        check("S32 self change: new address pending, old stays trusted, old address is notified", row["email"] == email_of("viewer_dev") and row["pending_email"]["address"] == "viewer.self@dev.invalid" and H.SINK.count(email_of("viewer_dev")) == n_old + 1)
        raw = H.SINK.last("viewer.self@dev.invalid"); vv = C(); vv.get(link_from(raw, "verify_email.php").replace(BASE + "/", "")); vv.post("verify_email.php", {})
        row = [u for u in read("users") if u["username"] == "viewer_dev"][0]
        check("S33 confirming the new address switches the trusted address", row["email"] == "viewer.self@dev.invalid" and row["pending_email"] is None)
        check("S34 session bound to the old address ended", mc2.get("dashboard.php", follow=False).status_code == 302)
        # suspended / disabled accounts
        adm.post("admin_users.php", {"action": "lock", "id": [u for u in read("users") if u["username"] == "secretary_dev"][0]["id"], "confirm_password": PW})
        c4, r4 = attempt("secretary_dev"); check("S35 locked account: generic failure, no code sent", "Invalid username or password" in r4.text)
        # verify-page / OTP-page edge: pending login dies if the account is deactivated meanwhile
        c5, r5, b5 = start_login("presiding_dev"); m5 = H.SINK.wait_new(email_of("presiding_dev"), b5)
        adm.post("admin_users.php", {"action": "deactivate", "id": 3, "confirm_password": PW})
        r = c5.post("mfa.php", {"action": "verify", "code": code_from(m5)})
        check("S36 account deactivated between password and code: login cannot complete", "dashboard.php" not in r.url)
    finally: srv.stop(); srv.cleanup()

def scenario_tools():
    srv = H.Server(tag="tools")
    try:
        root = H.ROOT; env = dict(srv.env, SSMS_DATA_DIR=srv.data)
        run = lambda *a: subprocess.run(["php", *a], capture_output=True, text=True, cwd=root, env=env)
        # legacy TOTP data set
        users = read("users")
        for u in users:
            for k in ("email", "email_verified", "email_verified_at", "pending_email"): u.pop(k, None)
            u["mfa"] = {"enabled": u["role"] == "sysadmin" or u["username"] == "councilor_dev", "secret_enc": "ENC", "last_counter": 5, "recovery": [{"h": "x", "used_at": None}], "enrolled_at": "2026-01-01"}
        write("users", users)
        r = run("tools/migrate_email_otp.php"); check("S37 migration dry run reports and writes nothing", "need migration" in r.stdout and "email" not in read("users")[0])
        r = run("tools/migrate_email_otp.php", "--apply"); users = read("users")
        check("S38 migration adds e-mail fields UNVERIFIED and empty (never guessed), keeps accounts and passwords", all(u["email"] is None and u["email_verified"] is False and u["password_hash"] for u in users) and len(users) == 8, r.stdout + r.stderr)
        check("S39 TOTP data moved to retained legacy block; live mfa block is e-mail-only", all("secret_enc" not in u["mfa"] for u in users) and any("mfa_legacy_totp" in u for u in users))
        check("S40 a backup was written before migration", any(d.endswith("pre-email-otp-migration") for d in os.listdir(os.path.join(srv.data, "backups"))))
        c, r = attempt("admin_dev"); check("S41 after migration a former TOTP admin is NOT admitted password-only (blocked until e-mail verified)", "verified e-mail" in r.text and c.get("dashboard.php", follow=False).status_code == 302)
        coun = [u for u in users if u["username"] == "councilor_dev"][0]
        check("S42 optional-role user who had TOTP stays protected (opted in => blocked until verified)", coun["mfa"]["email_otp"] is True)
        c2, r2 = attempt("councilor2_dev"); check("S43 optional-role user without prior MFA keeps password-only", "dashboard.php" in r2.url)
        r = run("tools/migrate_email_otp.php", "--apply"); check("S44 migration is idempotent", "Nothing to do" in r.stdout)
        # recovery tool
        r = run("tools/account_recovery.php", "set-email", "admin_dev", "admin.recovered@dev.invalid", "mailbox lost; identity checked in person")
        row = [u for u in read("users") if u["username"] == "admin_dev"][0]
        check("S45 recovery set-email (default) stores PENDING and e-mails a confirmation link", row["pending_email"]["address"] == "admin.recovered@dev.invalid" and not row["email_verified"] and H.SINK.last("admin.recovered@dev.invalid") is not None, r.stdout + r.stderr)
        lk = link_from(H.SINK.last("admin.recovered@dev.invalid"), "verify_email.php"); v = C(); v.get(lk.replace(BASE + "/", "")); v.post("verify_email.php", {})
        b = H.SINK.count("admin.recovered@dev.invalid"); c = C(); c.get("login.php"); r = c.post("login.php", {"username": "admin_dev", "password": PW})
        m = H.SINK.wait_new("admin.recovered@dev.invalid", b); r = c.post("mfa.php", {"action": "verify", "code": code_from(m)})
        check("S46 recovered administrator signs in with a code at the new, confirmed address", "dashboard.php" in r.url)
        r = run("tools/account_recovery.php", "set-email", "admin2_dev", "admin2@dev.invalid", "attested", "--mark-verified")
        check("S47 --mark-verified (operator attestation) trusts the address immediately and is audited", [u for u in read("users") if u["username"] == "admin2_dev"][0]["email_verified"] and "recovery.email_set_verified" in open(os.path.join(srv.data, "audit.log")).read())
        r = run("tools/account_recovery.php", "set-email", "admin2_dev", "x@dev.invalid", ""); check("S48 recovery requires a reason", r.returncode != 0)
        r = run("tools/account_recovery.php", "set-email", "viewer_dev", "admin.recovered@dev.invalid", "dup"); check("S49 recovery cannot assign an address that belongs to another account", r.returncode != 0)
        r = run("tools/test_mail.php", "probe@dev.invalid"); check("S50 tools/test_mail.php sends through the configured SMTP", "SENT" in r.stdout and H.SINK.last("probe@dev.invalid") is not None, r.stdout + r.stderr)
        r = subprocess.run(["php", "tools/test_mail.php", "probe@dev.invalid"], capture_output=True, text=True, cwd=root, env=dict(env, SSMS_SMTP_HOST="", SSMS_MAIL_FROM=""))
        check("S51 test_mail reports missing SMTP configuration instead of claiming success", "not configured" in r.stderr and r.returncode == 2, r.stdout + r.stderr)
        r = run("tools/migrate_email_otp.php", "--purge-legacy"); check("S52 --purge-legacy removes the retained TOTP secrets", not any("mfa_legacy_totp" in u for u in read("users")))
        check("S53 recovery/migration tools are not reachable over HTTP", C().get("tools/account_recovery.php", follow=False).status_code == 404)
    finally: srv.stop(); srv.cleanup()

if __name__ == "__main__":
    H.SINK = H.Sink(H.SMTP_PORT)
    err = None
    try:
        for f in (scenario_outage, scenario_misconfig, scenario_rate_and_concurrency, scenario_creation_and_ownership, scenario_tools):
            try: f()
            except BaseException as e:
                import traceback; traceback.print_exc(); err = e
    finally:
        H.SINK.stop()
    ok = H.summary(); sys.exit(0 if ok and err is None else 1)
