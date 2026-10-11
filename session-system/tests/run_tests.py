#!/usr/bin/env python3
"""
SSMS end-to-end test suite (HTTP, real application, real PHPMailer -> local SMTP sink).
Regression of the legislative modules + RBAC + the e-mail OTP login flow.
Usage:  TZ=UTC python3 tests/run_tests.py        (needs: php, python3, requests; TZ=UTC because the app uses PHP's UTC date)
See also tests/test_email_otp.py for the dedicated OTP / e-mail ownership / failure-mode scenarios.
"""
import hashlib, json, os, re, shutil, sys, threading, time
import requests
import harness as H
from harness import check, C, login, start_login, code_from, link_from, email_of, php, read, write, data_dir, PW, OFFICE, BASE

def main():
    H.SINK = H.Sink(H.SMTP_PORT)
    srv = H.Server(tag="main")
    err = None
    try:
        run()
    except BaseException as e:           # never let a crash look like a clean pass
        import traceback; traceback.print_exc(); err = e
    finally:
        srv.stop(); H.SINK.stop()
        warn = [l for l in srv.log().splitlines() if re.search(r"Warning|Notice|Deprecated|Fatal|Parse error", l)]
        check("No PHP warnings/notices/fatals in server log during entire run", not warn, "\n".join(warn[:5]))
        ok = H.summary(); srv.cleanup()
        sys.exit(0 if ok and err is None else 1)

SINK = lambda: H.SINK
CODES = []          # every OTP delivered during the run (used by the audit-log secrecy check)
def sign_in_otp(user, pw=PW):
    c, r, before = start_login(user, pw)
    m = H.SINK.wait_new(email_of(user), before); code = code_from(m); CODES.append(code)
    r = c.post("mfa.php", {"action": "verify", "code": code})
    return c, r

def run():
    # ---------------------------------------------------------------- A. basic / exposure
    c = C()
    for p in ["login.php", "qr_login.php", "reset_password.php", "verify_email.php"]:
        check(f"A1 public page loads: {p}", c.get(p).status_code == 200)
    for p in ["dashboard.php", "modules/scheduling.php", "modules/agenda.php", "modules/attendance.php", "modules/proceedings.php", "modules/minutes.php", "modules/tracking.php", "admin_users.php", "manage_devices.php", "audit_view.php", "settings.php", "my_security.php"]:
        r = c.get(p, follow=False)
        check(f"A2 unauthenticated redirected to login: {p}", r.status_code == 302 and "login.php" in r.headers.get("Location", ""), f"{r.status_code}")
    r = c.get("api/live_status.php?session_id=1", follow=False)
    check("A3 live_status API without login -> 401 JSON", r.status_code == 401 and r.json().get("success") is False)
    for p in ["data/users.json", "data/audit.log", "config/.env", "config/.env.example", "includes/store.php", "tools/setup.php", "data/badges.json", "data/sessions.json", "data/otp_challenges.json", "vendor/phpmailer/phpmailer/src/PHPMailer.php", "composer.json"]:
        r = c.get(p, follow=False)
        check(f"A4 private file not served: {p}", r.status_code == 404 and "SSMS_APP_KEY" not in r.text and "password_hash" not in r.text, f"{r.status_code}")
    check("A5 login page has no demo credentials", "admin123" not in c.get("login.php").text)

    # ---------------------------------------------------------------- B. password stage / rate limit
    c = C(); c.get("login.php")
    r1 = c.post("login.php", {"username": "admin_dev", "password": "wrong-password-1"})
    r2 = c.post("login.php", {"username": "no_such_user", "password": "wrong-password-1"})
    e = lambda t: re.search(r'login-error[^>]*>.*?</i>\s*([^<]+)', t, re.S).group(1).strip()
    check("B1 wrong password -> generic error", "Invalid username or password" in r1.text)
    check("B2 unknown user -> identical error (no enumeration)", e(r1.text) == e(r2.text), (e(r1.text), e(r2.text)))
    r = c.post("login.php", {"username": "viewer_dev", "password": PW}, csrf=False)
    check("B3 login POST without CSRF token rejected", r.status_code == 403)
    for i in range(6): r = c.post("login.php", {"username": "councilor2_dev", "password": "bad-guess-%d" % i})
    r = c.post("login.php", {"username": "councilor2_dev", "password": PW})
    check("B4 account throttled after repeated failures (even correct password blocked)", "Too many failed attempts" in r.text)


    # ---------------------------------------------------------------- C. e-mail OTP login lifecycle
    ADM = "admin_dev"; M = lambda u: H.SINK.count(email_of(u))
    a = C(); sid0 = (a.get("login.php"), a.sid())[1]
    n_sec = M("secretary_dev"); n_adm = M(ADM)
    r = a.post("login.php", {"username": ADM, "password": PW})
    check("C1 mandatory role: correct password leads to the code page, not the dashboard", "mfa.php" in r.url and "Check your e-mail" in r.text, r.url)
    check("C2 session id regenerated at the password step (fixation defence)", a.sid() != sid0)
    m = H.SINK.wait_new(email_of(ADM), n_adm); code1 = code_from(m); CODES.append(code1)
    check("C3 the code was e-mailed to THAT account's own address, and only there", m is not None and M("secretary_dev") == n_sec and all(x["to"] == email_of(ADM) for x in H.SINK.mail[-1:]))
    check("C4 e-mail identifies the system, states expiry and warns not to share", "Session and Legislative Meeting Management System" in m["body"] and "expires in 5 minute" in m["body"] and "Do not share" in m["body"], m["body"][:200])
    check("C5 code page shows the MASKED address only; code never in the HTTP response", email_of(ADM) not in r.text and "@d" in r.text and "*" in r.text and code1 not in r.text)
    for p, want in [("dashboard.php", "login.php"), ("modules/scheduling.php", "login.php"), ("admin_users.php", "login.php"), ("my_security.php", "login.php")]:
        rr = a.get(p, follow=False)
        check(f"C6 pending (password-only) state cannot open {p}", rr.status_code == 302 and want in rr.headers["Location"], rr.status_code)
    check("C7 pending state cannot call APIs", a.get("api/live_status.php?session_id=1", follow=False).status_code == 401)
    r = a.post("mfa.php", {"action": "verify", "code": "000000" if code1 != "000000" else "111111"})
    check("C8 wrong code rejected, attempts counted", "not correct" in r.text and "4 attempt(s) left" in r.text and "mfa.php" in r.url, r.text[:100])
    r = a.post("mfa.php", {"action": "verify", "code": "12ab56"})
    check("C9 malformed code rejected without spending an attempt", "6-digit" in r.text)
    r = a.post("mfa.php", {"action": "verify", "code": code1, "role": "sysadmin"})
    check("C10 unexpected request fields are rejected (400)", r.status_code == 400)
    r = a.post("mfa.php", {"action": "verify", "code": code1}, csrf=False)
    check("C11 code submission without CSRF token -> 403", r.status_code == 403)
    sid_before = a.sid()
    r = a.post("mfa.php", {"action": "verify", "code": code1})
    check("C12 correct code grants the session and opens the dashboard", "dashboard.php" in r.url and r.status_code == 200, r.url)
    check("C13 session id regenerated again after the code", a.sid() != sid_before)
    check("C14 role comes from server data: sysadmin reaches user admin", a.get("admin_users.php").status_code == 200)
    a.post("logout.php")
    check("C15 logout destroys the session server-side", a.get("dashboard.php", follow=False).status_code == 302)
    # reuse
    b, r, before = start_login(ADM); m2 = H.SINK.wait_new(email_of(ADM), before); code2 = code_from(m2); CODES.append(code2)
    r = b.post("mfa.php", {"action": "verify", "code": code1})
    check("C16 an already-used code is rejected on a new login", "dashboard.php" not in r.url and "not correct" in r.text or "No valid code" in r.text, r.text[:100])
    r = b.post("mfa.php", {"action": "verify", "code": code2})
    check("C17 the new login's own code works", "dashboard.php" in r.url)
    b.post("logout.php")
    # raw storage
    raw = open(os.path.join(data_dir(), "otp_challenges.json")).read()
    check("C18 OTP codes are not stored in plaintext (keyed hash only)", not any(c in raw for c in CODES if re.search(r'"' + c + '"', raw)) and '"code_h"' in raw and not re.search(r'"code"\s*:', raw))
    # resend + cooldown + supersede
    b, r, before = start_login(ADM); mA = H.SINK.wait_new(email_of(ADM), before); codeA = code_from(mA); CODES.append(codeA)
    r = b.post("mfa.php", {"action": "resend"})
    check("C19 resend inside the cooldown is refused (no new e-mail)", "Please wait" in r.text and M(ADM) == before + 1, r.text[:100])
    time.sleep(5.3)
    r = b.post("mfa.php", {"action": "resend"}); mB = H.SINK.wait_new(email_of(ADM), before + 1); codeB = code_from(mB); CODES.append(codeB)
    check("C20 resend after the cooldown e-mails a new code", mB is not None and codeB != codeA)
    r = b.post("mfa.php", {"action": "verify", "code": codeA})
    check("C21 a resend invalidates the previous code (even though it is still unexpired)", "dashboard.php" not in r.url, r.url)
    r = b.post("mfa.php", {"action": "verify", "code": codeB})
    check("C22 the newest code works", "dashboard.php" in r.url)
    b.post("logout.php")
    # attempt limit
    b, r, before = start_login(ADM); mC = H.SINK.wait_new(email_of(ADM), before); codeC = code_from(mC); CODES.append(codeC)
    wrong = [x for x in ("000001", "000002", "000003", "000004", "000005", "000006") if x != codeC][:5]
    for w in wrong: r = b.post("mfa.php", {"action": "verify", "code": w})
    check("C23 five wrong codes: the challenge dies and the pending login is destroyed", "login.php" in r.url and "Too many incorrect codes" in r.text, r.url)
    r = b.post("mfa.php", {"action": "verify", "code": codeC})
    check("C24 after the attempt limit even the CORRECT code is useless", "dashboard.php" not in r.url and b.get("dashboard.php", follow=False).status_code == 302)
    ch = read("otp_challenges")
    check("C25 exhausted challenge is marked as such server-side", ch and ch[-1]["status"] == "exhausted", ch[-1:] )
    write("ratelimit", {})
    # expiry
    b, r, before = start_login(ADM); mD = H.SINK.wait_new(email_of(ADM), before); codeD = code_from(mD); CODES.append(codeD)
    ch = read("otp_challenges"); ch[-1]["expires_at"] = int(time.time()) - 1; ch[-1]["created_at"] -= 400; write("otp_challenges", ch)
    r = b.post("mfa.php", {"action": "verify", "code": codeD})
    check("C26 an expired code is rejected", "expired" in r.text and "dashboard.php" not in r.url, r.text[:120])
    time.sleep(5.3)
    r = b.post("mfa.php", {"action": "resend"}); mE = H.SINK.wait_new(email_of(ADM), before + 1); codeE = code_from(mE); CODES.append(codeE)
    r = b.post("mfa.php", {"action": "verify", "code": codeE})
    check("C27 a fresh code after expiry works", "dashboard.php" in r.url)
    b.post("logout.php")
    # cross-account / cross-login isolation
    x, rx, bx = start_login("admin_dev"); y, ry, by = start_login("secretary_dev")
    cx = code_from(H.SINK.wait_new(email_of("admin_dev"), bx)); cy = code_from(H.SINK.wait_new(email_of("secretary_dev"), by)); CODES.extend([cx, cy])
    r = y.post("mfa.php", {"action": "verify", "code": cx})
    check("C28 a code issued to one account cannot complete another account's login", "dashboard.php" not in r.url and y.get("dashboard.php", follow=False).status_code == 302)
    x2, rx2, bx2 = start_login("admin_dev"); cx2 = code_from(H.SINK.wait_new(email_of("admin_dev"), bx2)); CODES.append(cx2)
    r = x.post("mfa.php", {"action": "verify", "code": cx})
    check("C29 a newer login of the same account cancels the older pending login's code", "dashboard.php" not in r.url)
    r = x2.post("mfa.php", {"action": "verify", "code": cx})
    check("C30 a code cannot be replayed into a different pending login of the same account", "dashboard.php" not in r.url)
    r = x2.post("mfa.php", {"action": "verify", "code": cx2}); check("C31 ...while that login's own code still works", "dashboard.php" in r.url)
    x2.post("logout.php")
    # cancel
    z, rz, bz = start_login("admin_dev"); cz = code_from(H.SINK.wait_new(email_of("admin_dev"), bz)); CODES.append(cz)
    z.post("logout.php"); r = z.post("mfa.php", {"action": "verify", "code": cz})
    check("C32 'Cancel' on the code page ends the pending login and cancels its code", "dashboard.php" not in r.url and z.get("dashboard.php", follow=False).status_code == 302)
    write("ratelimit", {})
    # policy
    n = M("councilor_dev"); cc = C(); cc.get("login.php"); r = cc.post("login.php", {"username": "councilor_dev", "password": PW})
    check("C33 optional role (policy 'optional', not opted in): password-only sign-in, no code sent", "dashboard.php" in r.url and M("councilor_dev") == n)
    r = cc.post("my_security.php", {"action": "enable_otp", "password": PW})
    check("C34 optional role can opt in (needs password) and is signed out to prove the code path", "login.php" in r.url and "turned on" in r.text.lower() or "sign in again" in r.text.lower(), r.text[:100])
    cc2, r2 = sign_in_otp("councilor_dev")
    check("C35 after opting in, sign-in requires the e-mailed code", "dashboard.php" in r2.url)
    r = cc2.post("my_security.php", {"action": "disable_otp", "password": "wrong"})
    check("C36 turning it off needs the current password", "incorrect" in r.text)
    r = cc2.post("my_security.php", {"action": "disable_otp", "password": PW}); cc2.post("logout.php")
    cc3 = C(); cc3.get("login.php"); r = cc3.post("login.php", {"username": "councilor_dev", "password": PW})
    check("C37 opted-out again: password-only", "dashboard.php" in r.url); cc3.post("logout.php")
    ad2 = login(ADM); r = ad2.post("my_security.php", {"action": "disable_otp", "password": PW})
    check("C38 mandatory role cannot turn the code requirement off", "mandatory" in r.text)
    r = ad2.post("my_security.php", {"action": "enable_otp", "password": PW})
    check("C39 ...nor is a self-service switch needed/possible to change policy", ad2.get("dashboard.php", follow=False).status_code == 200)
    ad2.post("logout.php")
    # unverified / malformed
    users = read("users"); sec_row = [u for u in users if u["username"] == "secretary_dev"][0]
    sec_row["email_verified"] = False; write("users", users); n = M("secretary_dev")
    s1 = C(); s1.get("login.php"); r = s1.post("login.php", {"username": "secretary_dev", "password": PW})
    check("C40 mandatory role with an UNVERIFIED address: refused, explained, nothing e-mailed", "login.php" in r.url and "verified e-mail" in r.text and M("secretary_dev") == n, r.url)
    check("C41 ...and no route to protected content exists", s1.get("dashboard.php", follow=False).status_code == 302 and s1.get("modules/agenda.php", follow=False).status_code == 302)
    sec_row["email_verified"] = True; sec_row["email"] = None; write("users", users)
    s1 = C(); s1.get("login.php"); r = s1.post("login.php", {"username": "secretary_dev", "password": PW})
    check("C42 mandatory role with NO address at all: refused", "login.php" in r.url and "verified e-mail" in r.text)
    sec_row["email"] = email_of("secretary_dev"); write("users", users)
    v_row = [u for u in users if u["username"] == "viewer_dev"][0]; v_row["role"] = "hacker"; write("users", users)
    s1 = C(); s1.get("login.php"); r = s1.post("login.php", {"username": "viewer_dev", "password": PW})
    check("C43 malformed/unknown role: sign-in refused", "dashboard.php" not in r.url and s1.get("dashboard.php", follow=False).status_code == 302)
    v_row["role"] = "viewer"; write("users", users)
    # config floor
    dd = data_dir()
    for val in ["viewer", "", "garbage,,nonsense", "sysadmin"]:
        out = php("define('SSMS_DATA_DIR','%s');require 'includes/bootstrap.php';echo json_encode(ssms_mfa_required_roles());" % dd, {"SSMS_MFA_REQUIRED_ROLES": val})
        got = json.loads(out)
        check(f"C44 config cannot remove mandatory roles (SSMS_MFA_REQUIRED_ROLES='{val}')", all(r in got for r in ("sysadmin", "secretary", "presiding")), out)
    out = php("define('SSMS_DATA_DIR','%s');require 'includes/bootstrap.php';echo ssms_otp_ttl(),',',ssms_otp_cooldown(),',',ssms_otp_max_tries();" % dd, {"SSMS_OTP_RESEND_COOLDOWN_SEC": "", "SSMS_OTP_EXPIRY_MIN": "", "SSMS_OTP_MAX_ATTEMPTS": ""})
    check("C45 defaults: 5-minute expiry, 60-second resend cooldown, 5 attempts", out == "300,60,5", out)
    out = php("define('SSMS_DATA_DIR','%s');require 'includes/bootstrap.php';echo json_encode([ssms_mfa_gate(['role'=>'sysadmin','email'=>null,'email_verified'=>false]), ssms_mfa_gate(['role'=>'secretary','email'=>'a@b.co','email_verified'=>true]), ssms_mfa_gate(['role'=>'viewer','email'=>null]), ssms_mfa_gate(['role'=>'']), ssms_mfa_gate([])]);" % dd, {"SSMS_MFA_ORDINARY_POLICY": "optional"})
    check("C46 policy gate fails closed (blocked / otp / none / blocked / blocked)", out == '["blocked","otp","none","blocked","blocked"]', out)
    check("C47 TOTP/authenticator routes no longer exist", C().get("mfa_setup.php", follow=False).status_code == 404 and not os.path.exists(os.path.join(H.ROOT, "includes", "mfa.php")))
    write("ratelimit", {})

    # ---------------------------------------------------------------- D. RBAC matrix
    sec = login("secretary_dev"); pres = login("presiding_dev"); adm = login("admin_dev")
    coun = login("councilor_dev"); doc = login("docstaff_dev"); view = login("viewer_dev")
    check("D0 all six role accounts can sign in", all("Dashboard" in x.get("dashboard.php").text or "Dashboard" in x.get("dashboard.php").text for x in [sec, pres, adm, coun, doc, view]))
    pages = ["modules/scheduling.php", "modules/agenda.php", "modules/attendance.php", "modules/proceedings.php", "modules/minutes.php", "modules/tracking.php", "admin_users.php", "manage_devices.php", "audit_view.php", "settings.php"]
    expect = {  # role -> pages that must be OK (200). everything else must be 403
        "sec": {"modules/scheduling.php", "modules/agenda.php", "modules/attendance.php", "modules/proceedings.php", "modules/minutes.php", "modules/tracking.php"},
        "pres": {"modules/scheduling.php", "modules/agenda.php", "modules/attendance.php", "modules/proceedings.php", "modules/minutes.php", "modules/tracking.php"},
        "adm": {"modules/scheduling.php", "admin_users.php", "manage_devices.php", "audit_view.php", "settings.php"},
        "coun": {"modules/scheduling.php", "modules/agenda.php", "modules/attendance.php", "modules/proceedings.php", "modules/minutes.php", "modules/tracking.php"},
        "doc": {"modules/scheduling.php", "modules/agenda.php", "modules/proceedings.php", "modules/minutes.php", "modules/tracking.php"},
        "view": {"modules/scheduling.php", "modules/agenda.php", "modules/minutes.php"},
    }
    cl = {"sec": sec, "pres": pres, "adm": adm, "coun": coun, "doc": doc, "view": view}
    bad = []
    for role, client in cl.items():
        for p in pages:
            code = client.get(p, follow=False).status_code
            want = 200 if p in expect[role] else 403
            if code != want: bad.append(f"{role}:{p} got {code} want {want}")
    check("D1 role x module GET matrix (6 roles x 10 pages) matches RBAC design", not bad, "; ".join(bad[:6]))
    r = adm.get("modules/minutes.php"); check("D2 sysadmin cannot open legislative minutes", r.status_code == 403)

    # ---------------------------------------------------------------- E. legislative workflow
    today = time.strftime("%Y-%m-%d")
    sec.get("modules/scheduling.php")
    def S(path, d, who=None):
        who = who or sec; return who.post(path, d)
    r = S("modules/scheduling.php", {"action": "create", "title": "Test <script>alert(1)</script> Session", "type": "Special", "date": today, "time": "09:00", "end_time": "11:00", "venue": "Room A"})
    check("E1 secretary creates session", "Session scheduled successfully" in r.text)
    check("E2 XSS: title is escaped in output", "<script>alert(1)</script>" not in r.text and "&lt;script&gt;" in r.text)
    sessions = json.load(open(os.path.join(data_dir(), "sessions.json")))
    new = [s for s in sessions if s["venue"] == "Room A"][0]; sid = new["id"]
    r = S("modules/scheduling.php", {"action": "create", "title": "Test <script>alert(1)</script> Session", "type": "Special", "date": today, "time": "09:00", "end_time": "11:00", "venue": "Room A"})
    check("E3 duplicate submission rejected", "already exists" in r.text)
    r = S("modules/scheduling.php", {"action": "create", "title": "Other", "type": "Regular", "date": today, "time": "10:00", "end_time": "12:00", "venue": "room a"})
    check("E4 overlapping venue/time conflict rejected", "conflict" in r.text.lower())
    r = S("modules/scheduling.php", {"action": "create", "title": "Past", "type": "Regular", "date": "2020-01-01", "time": "10:00", "venue": "Hall"})
    check("E5 past date rejected", "in the past" in r.text)
    r = S("modules/scheduling.php", {"action": "create", "title": "Bad type", "type": "Hacked", "date": today, "time": "14:00", "venue": "Hall"})
    check("E6 invalid type rejected server-side", "valid session type" in r.text)
    r = S("modules/scheduling.php", {"action": "create", "title": "Bad time", "type": "Regular", "date": today, "time": "25:99", "venue": "Hall"})
    check("E7 invalid time rejected", "valid start time" in r.text)
    r = sec.post("modules/scheduling.php", {"action": "create", "title": "NoCSRF", "type": "Regular", "date": today, "time": "15:00", "venue": "Hall"}, csrf=False)
    check("E8 state change without CSRF token -> 403", r.status_code == 403)
    r = S("modules/scheduling.php", {"action": "set_status", "id": sid, "status": "Ongoing"})
    check("E9 secretary cannot start a session (presiding-only transition)", r.status_code == 403)
    r = S("modules/scheduling.php", {"action": "set_status", "id": sid, "status": "Ongoing"}, pres)
    check("E10 cannot start without a finalized agenda", "agenda must be finalized" in r.text, r.text[:100])
    # agenda
    sec.get(f"modules/agenda.php?session_id={sid}")
    for t in ["Call to order", "Second reading of Ordinance", "Other matters"]:
        S("modules/agenda.php", {"action": "add_item", "session_id": sid, "item": t, "presenter": "Hon. X"})
    r = S("modules/agenda.php", {"action": "add_item", "session_id": sid, "item": "Call to order"})
    check("E11 duplicate agenda item rejected", "already exists" in r.text)
    items = [i for i in json.load(open(os.path.join(data_dir(), "agenda.json"))) if i["session_id"] == sid]
    check("E12 agenda numbered 1..3", sorted(i["order"] for i in items) == [1, 2, 3])
    first = [i for i in items if i["order"] == 1][0]
    S("modules/agenda.php", {"action": "delete", "session_id": sid, "id": first["id"]})
    S("modules/agenda.php", {"action": "add_item", "session_id": sid, "item": "Added after delete"})
    items = [i for i in json.load(open(os.path.join(data_dir(), "agenda.json"))) if i["session_id"] == sid]
    check("E13 order stays unique/contiguous after delete+add (original count()+1 bug)", sorted(i["order"] for i in items) == [1, 2, 3], str(sorted(i["order"] for i in items)))
    other = [i for i in json.load(open(os.path.join(data_dir(), "agenda.json"))) if i["session_id"] == 1][0]
    r = S("modules/agenda.php", {"action": "delete", "session_id": sid, "id": other["id"]})
    check("E14 IDOR: cannot delete another session's agenda item", any(i["id"] == other["id"] for i in json.load(open(os.path.join(data_dir(), "agenda.json")))))
    r = S("modules/agenda.php", {"action": "add_item", "session_id": sid, "item": "x"}, doc)
    check("E15 documentation staff cannot edit agenda (403)", r.status_code == 403)
    r = S("modules/agenda.php", {"action": "finalize", "session_id": sid})
    check("E16 secretary finalizes agenda", "Agenda finalized" in r.text)
    r = S("modules/agenda.php", {"action": "add_item", "session_id": sid, "item": "sneaky"})
    check("E17 finalized agenda cannot be changed", "finalized" in r.text and not any(i["item"] == "sneaky" for i in json.load(open(os.path.join(data_dir(), "agenda.json")))))
    r = S("modules/agenda.php", {"action": "revise", "session_id": sid, "reason": ""})
    check("E18 revision requires a reason", "reason" in r.text.lower())
    r = S("modules/agenda.php", {"action": "revise", "session_id": sid, "reason": "Add urgent item"})
    check("E19 authorized revision reopens agenda and keeps history", "revision 1" in r.text and len(json.load(open(os.path.join(data_dir(), "agenda_revisions.json")))) == 1)
    S("modules/agenda.php", {"action": "finalize", "session_id": sid})
    # attendance + quorum
    S("modules/attendance.php", {"action": "mark", "session_id": sid, "member_id": 1, "status": "Present"})
    r = sec.get(f"modules/attendance.php?session_id={sid}")
    check("E20 quorum NOT declared from partial roll call ('not yet determined')", "Quorum not yet determined" in r.text)
    for m in [2, 3, 4, 5]: S("modules/attendance.php", {"action": "mark", "session_id": sid, "member_id": m, "status": "Present"})
    r = sec.get(f"modules/attendance.php?session_id={sid}")
    check("E21 quorum achieved at 5 of 9 (default majority rule)", "Quorum achieved" in r.text and "5 / 9" in r.text)
    check("E22 unconfirmed quorum rule is flagged", "not been confirmed" in r.text)
    S("modules/attendance.php", {"action": "mark", "session_id": sid, "member_id": 2, "status": "Present"})
    att = [x for x in json.load(open(os.path.join(data_dir(), "attendance.json"))) if x["session_id"] == sid and x["member_id"] == 2]
    check("E23 no duplicate attendance record per member/session", len(att) == 1)
    r = S("modules/attendance.php", {"action": "mark", "session_id": sid, "member_id": 2, "status": "Bogus"})
    check("E24 invalid attendance status rejected", "Invalid attendance status" in r.text)
    r = S("modules/attendance.php", {"action": "mark", "session_id": sid, "member_id": 99, "status": "Present"})
    check("E25 unknown member rejected", "Unknown member" in r.text)
    r = S("modules/attendance.php", {"action": "mark", "session_id": sid, "member_id": 7, "status": "Present"}, coun)
    check("E26 councilor cannot record anyone's attendance (403)", r.status_code == 403)
    r = coun.get(f"modules/attendance.php?session_id={sid}")
    check("E27 councilor sees only own attendance row (record-level restriction)", "Hon. D. Santos" in r.text and "Hon. M. Reyes" not in r.text and "Hon. L. Fernandez" not in r.text)
    S("modules/attendance.php", {"action": "mark", "session_id": sid, "member_id": 5, "status": "Absent"})
    r = sec.get(f"modules/attendance.php?session_id={sid}")
    check("E28 quorum recalculated after a correction (4 present -> not achieved / undetermined)", "Quorum achieved" not in r.text and "4 / 9" in r.text)
    check("E29 attendance change is logged", len(json.load(open(os.path.join(data_dir(), "attendance_history.json")))) >= 6)
    S("modules/attendance.php", {"action": "mark", "session_id": sid, "member_id": 5, "status": "Present"})
    # start (presiding)
    r = S("modules/scheduling.php", {"action": "set_status", "id": sid, "status": "Ongoing"}, pres)
    check("E30 presiding officer starts the session", "marked Ongoing" in r.text, r.text[:80])
    r = S("modules/scheduling.php", {"action": "set_status", "id": sid, "status": "Scheduled"}, pres)
    check("E31 invalid transition Ongoing->Scheduled refused", "cannot be changed" in r.text)
    r = S("modules/scheduling.php", {"action": "update", "id": sid, "title": "x", "type": "Regular", "date": today, "time": "10:00", "venue": "v"})
    check("E32 ongoing session schedule cannot be edited", "still Scheduled" in r.text)
    r = S("modules/scheduling.php", {"action": "delete", "id": sid})
    check("E33 session with records cannot be deleted", "cannot" in r.text.lower() or "Only sessions" in r.text)
    # tracking
    ag = [i for i in json.load(open(os.path.join(data_dir(), "agenda.json"))) if i["session_id"] == sid]
    r = S("modules/tracking.php", {"action": "set_current", "session_id": sid, "agenda_id": ag[0]["id"]})
    check("E34 secretary sets current agenda item", "Current agenda item updated" in r.text)
    r = S("modules/tracking.php", {"action": "set_current", "session_id": sid, "agenda_id": other["id"]})
    check("E35 current agenda must belong to the session (IDOR)", "does not belong" in r.text)
    r = S("modules/tracking.php", {"action": "set_current", "session_id": sid, "agenda_id": ag[1]["id"]}, coun)
    check("E36 councilor cannot change live tracking (403)", r.status_code == 403)
    api = sec.s.get(f"{BASE}/api/live_status.php?session_id={sid}"); j = api.json()
    check("E37 live status API returns saved data", j["success"] and j["status"] == "Ongoing" and j["present"] == 5 and j["quorum_status"] == "achieved", str(j))
    check("E38 live API unknown session -> 404", sec.s.get(f"{BASE}/api/live_status.php?session_id=9999").status_code == 404)
    # proceedings
    doc.get(f"modules/proceedings.php?session_id={sid}")
    r = S("modules/proceedings.php", {"action": "add_log", "session_id": sid, "note": "Motion carried <b>unanimously</b>", "kind": "Motion", "agenda_id": ag[1]["id"], "speaker": "Hon. D. Santos"}, doc)
    check("E39 documentation staff records a draft entry (escaped)", "Draft entry saved" in r.text and "<b>unanimously</b>" not in r.text)
    r = S("modules/proceedings.php", {"action": "add_log", "session_id": sid, "note": "Motion carried <b>unanimously</b>", "kind": "Motion"}, doc)
    check("E40 duplicate proceedings submission ignored", "duplicate" in r.text.lower())
    procs = [p for p in json.load(open(os.path.join(data_dir(), "proceedings.json"))) if p["session_id"] == sid]; pid = procs[0]["id"]
    r = S("modules/proceedings.php", {"action": "review", "session_id": sid, "id": pid}, doc)
    check("E41 documentation staff cannot mark entries reviewed (403)", r.status_code == 403)
    r = S("modules/proceedings.php", {"action": "add_log", "session_id": sid, "note": "x"}, coun)
    check("E42 councilor cannot add proceedings (403)", r.status_code == 403)
    r = coun.get(f"modules/proceedings.php?session_id={sid}")
    check("E43 councilor does not see unreviewed draft proceedings", "unanimously" not in r.text)
    r = S("modules/proceedings.php", {"action": "review", "session_id": sid, "id": pid})
    check("E44 secretary reviews the entry", "marked Reviewed" in r.text)
    r = coun.get(f"modules/proceedings.php?session_id={sid}")
    check("E45 councilor now sees reviewed entry", "unanimously" in r.text)
    r = S("modules/proceedings.php", {"action": "edit_log", "session_id": sid, "id": pid, "note": "tampered"}, doc)
    check("E46 reviewed entry cannot be edited directly", "cannot be edited" in r.text)
    r = S("modules/proceedings.php", {"action": "amend", "session_id": sid, "id": pid, "note": "Motion carried 8-0", "reason": "vote count added"})
    check("E47 amendment keeps previous version", "Amendment recorded" in r.text and len(json.load(open(os.path.join(data_dir(), "proceedings_history.json")))) == 1)
    # minutes
    r = S("modules/minutes.php", {"action": "generate", "session_id": sid})
    check("E48 secretary generates DRAFT minutes (not approved)", "NOT approved" in r.text)
    r = sec.get(f"modules/minutes.php?session_id={sid}")
    doc_html = r.text[r.text.index('class="minutes-doc"'):]
    check("E49 minutes use saved data (this session only), flag draft, show unrecorded members honestly", "DRAFT" in doc_html and "Not recorded (4)" in doc_html and "Motion carried 8-0" in doc_html and "58th" not in doc_html and "Room A" in doc_html)
    r = S("modules/minutes.php", {"action": "submit", "session_id": sid, "id": json.load(open(os.path.join(data_dir(), "minutes.json")))[0]["id"]})
    check("E50 cannot submit minutes before the session is completed", "Complete (adjourn)" in r.text)
    r = S("modules/scheduling.php", {"action": "set_status", "id": sid, "status": "Completed"}, pres)
    check("E51 presiding officer completes the session", "marked Completed" in r.text)
    mid = json.load(open(os.path.join(data_dir(), "minutes.json")))[0]["id"]
    r = S("modules/minutes.php", {"action": "approve", "session_id": sid, "id": mid}, pres)
    check("E52 cannot approve minutes that were never submitted", "pending review" in r.text)
    r = S("modules/minutes.php", {"action": "submit", "session_id": sid, "id": mid})
    check("E53 secretary submits minutes for review", "Submitted for review" in r.text)
    for who, nm in [(sec, "secretary"), (doc, "documentation"), (adm, "sysadmin"), (coun, "councilor"), (view, "viewer")]:
        r = S("modules/minutes.php", {"action": "approve", "session_id": sid, "id": mid}, who)
        check(f"E54 {nm} cannot approve minutes (403)", r.status_code == 403)
    r = S("modules/minutes.php", {"action": "save_edit", "session_id": sid, "id": mid, "notes": "x"})
    check("E55 minutes under review cannot be edited", "Only draft" in r.text)
    r = S("modules/minutes.php", {"action": "approve", "session_id": sid, "id": mid}, pres)
    check("E56 presiding officer approves minutes", "Minutes approved" in r.text)
    r = S("modules/minutes.php", {"action": "save_edit", "session_id": sid, "id": mid, "notes": "tamper"})
    check("E57 approved minutes cannot be edited", "Only draft" in r.text)
    r = S("modules/proceedings.php", {"action": "add_log", "session_id": sid, "note": "late addition"}, doc)
    check("E58 proceedings locked once minutes approved", "locked" in r.text)
    r = S("modules/attendance.php", {"action": "mark", "session_id": sid, "member_id": 9, "status": "Present", "reason": "late fix"})
    check("E59 attendance locked once minutes approved", "locked" in r.text)
    r = view.get(f"modules/minutes.php?session_id={sid}")
    check("E60 observer cannot see approved-but-unpublished minutes", "Minutes of the Session" not in r.text)
    r = S("modules/minutes.php", {"action": "publish", "session_id": sid, "id": mid})
    check("E61 secretary publishes approved minutes", "Minutes published" in r.text)
    r = view.get(f"modules/minutes.php?session_id={sid}")
    check("E62 observer sees published minutes (read-only, no workflow controls)", "Minutes of the Session" in r.text and "Approve minutes" not in r.text and 'name="action"' not in r.text)
    r = S("modules/minutes.php", {"action": "amend", "session_id": sid, "id": mid, "reason": "Typo in vote count"})
    check("E63 formal amendment creates a new draft; approved version stays in force", "Amendment draft created" in r.text)
    # cancel flow
    r = S("modules/scheduling.php", {"action": "create", "title": "To cancel", "type": "Regular", "date": today, "time": "16:00", "venue": "Room B"})
    cid = [s for s in json.load(open(os.path.join(data_dir(), "sessions.json"))) if s["title"] == "To cancel"][0]["id"]
    r = S("modules/scheduling.php", {"action": "set_status", "id": cid, "status": "Cancelled", "reason": ""})
    check("E64 cancel requires a reason", "reason is required" in r.text)
    r = S("modules/scheduling.php", {"action": "set_status", "id": cid, "status": "Cancelled", "reason": "Typhoon"})
    check("E65 secretary cancels a scheduled session", "marked Cancelled" in r.text)
    r = S("modules/scheduling.php", {"action": "set_status", "id": cid, "status": "Ongoing"}, pres)
    check("E66 cancelled session cannot be started/reopened", "cannot be changed" in r.text)
    r = S("modules/attendance.php", {"action": "mark", "session_id": cid, "member_id": 1, "status": "Present"})
    check("E67 no attendance for cancelled session", "cannot be recorded" in r.text)
    # settings: quorum configurable
    adm.get("settings.php")
    r = adm.post("settings.php", {"qtype": "fixed", "fixed": "3", "numerator": 1, "denominator": 2, "dur": 180, "req_final": "1", "confirmed": "1", "confirmed_by": "Office of the SP Secretary", "note": "test"})
    q = sec.get(f"modules/attendance.php?session_id={sid}")
    check("E68 quorum rule is configurable (fixed 3) and 'confirmed' warning disappears", "3 needed" in q.text and "not been confirmed" not in q.text)
    adm.post("settings.php", {"qtype": "majority", "numerator": 1, "denominator": 2, "dur": 180, "req_final": "1"})

    # ---------------------------------------------------------------- F. user admin / RBAC changes
    adm.get("admin_users.php")
    users = json.load(open(os.path.join(data_dir(), "users.json"))); U = {u["username"]: u for u in users}
    r = adm.post("admin_users.php", {"action": "change_role", "id": U["admin_dev"]["id"], "role": "viewer", "confirm_password": PW})
    check("F1 admin cannot change own role", "cannot change your own role" in r.text)
    r = adm.post("admin_users.php", {"action": "change_role", "id": U["viewer_dev"]["id"], "role": "superuser", "confirm_password": PW})
    check("F2 invalid role value rejected server-side", "Invalid role" in r.text)
    r = adm.post("admin_users.php", {"action": "change_role", "id": U["viewer_dev"]["id"], "role": "documentation", "confirm_password": "wrong"})
    check("F3 sensitive admin action requires admin's password", "password was not correct" in r.text)
    check("F4 viewer cannot reach user admin directly", view.get("admin_users.php").status_code == 403)
    r = view.post("admin_users.php", {"action": "change_role", "id": U["viewer_dev"]["id"], "role": "sysadmin", "confirm_password": PW})
    check("F5 privilege escalation attempt by observer blocked (403)", r.status_code == 403)
    users = json.load(open(os.path.join(data_dir(), "users.json")))
    check("F6 ...and role unchanged on disk", [u for u in users if u["username"] == "viewer_dev"][0]["role"] == "viewer")
    # cookie tampering can't elevate
    t = C(); t.s.cookies.set("role", "sysadmin"); t.s.cookies.set("mfa_verified", "true"); t.s.cookies.set("ssms_user", "admin_dev")
    check("F7 forged role/MFA cookies grant nothing", t.get("admin_users.php", follow=False).status_code == 302)
    # role change invalidates active session
    r = adm.post("admin_users.php", {"action": "change_role", "id": U["docstaff_dev"]["id"], "role": "viewer", "confirm_password": PW})
    check("F8 role change applied", "Role updated" in r.text)
    check("F9 affected user's live session is invalidated after role change", doc.get("dashboard.php", follow=False).status_code == 302)
    adm.post("admin_users.php", {"action": "change_role", "id": U["docstaff_dev"]["id"], "role": "documentation", "confirm_password": PW})
    # deactivate
    r = adm.post("admin_users.php", {"action": "deactivate", "id": U["councilor_dev"]["id"], "confirm_password": PW})
    check("F10 deactivated account loses its existing session", coun.get("dashboard.php", follow=False).status_code == 302)
    c2 = C(); c2.get("login.php"); r = c2.post("login.php", {"username": "councilor_dev", "password": PW})
    check("F11 deactivated account cannot sign in (generic error)", "Invalid username or password" in r.text)
    adm.post("admin_users.php", {"action": "activate", "id": U["councilor_dev"]["id"], "confirm_password": PW})
    # last admin
    adm.post("admin_users.php", {"action": "deactivate", "id": U["admin2_dev"]["id"], "confirm_password": PW})
    users = json.load(open(os.path.join(data_dir(), "users.json")))
    check("F12 an administrator can deactivate another while one remains usable", not [u for u in users if u["username"] == "admin2_dev"][0]["active"])
    # create admin2 back & test last-admin protection by trying via second session is impossible; check demotion of 'only other admin'
    adm.post("admin_users.php", {"action": "activate", "id": U["admin2_dev"]["id"], "confirm_password": PW})

    # e-mail change of a privileged account needs two different administrators
    pres_id = U["presiding_dev"]["id"]
    r = adm.post("admin_users.php", {"action": "set_email", "id": pres_id, "confirm_password": PW, "new_email": "new.presiding@dev.invalid", "reason": "old mailbox closed", "revoke_current": "1"})
    check("F14 changing a privileged account's e-mail is only REQUESTED by one admin", "DIFFERENT administrator" in r.text and [u for u in read("users") if u["id"] == pres_id][0]["email"] == email_of("presiding_dev"))
    r = adm.post("admin_users.php", {"action": "approve_email_change", "id": pres_id, "confirm_password": PW})
    check("F15 requester cannot approve own e-mail change", "cannot approve your own" in r.text)
    check("F16 trusted address unchanged meanwhile", [u for u in read("users") if u["id"] == pres_id][0]["email"] == email_of("presiding_dev"))
    a2c = login("admin2_dev"); a2c.get("admin_users.php")
    n_new = H.SINK.count("new.presiding@dev.invalid")
    r = a2c.post("admin_users.php", {"action": "approve_email_change", "id": pres_id, "confirm_password": PW})
    row = [u for u in read("users") if u["id"] == pres_id][0]
    check("F17 a DIFFERENT administrator approves: old address revoked, new one only PENDING, confirmation e-mailed to it", row["email"] is None and not row["email_verified"] and row["pending_email"]["address"] == "new.presiding@dev.invalid" and H.SINK.count("new.presiding@dev.invalid") == n_new + 1, str(row)[:200])
    check("F18 revocation ends the user's sessions", pres.get("dashboard.php", follow=False).status_code == 302)
    p1 = C(); p1.get("login.php"); r = p1.post("login.php", {"username": "presiding_dev", "password": PW})
    check("F19 until the new address is confirmed the Presiding Officer cannot sign in at all", "login.php" in r.url and p1.get("modules/minutes.php", follow=False).status_code == 302)
    ev = link_from(H.SINK.last("new.presiding@dev.invalid"), "verify_email.php")
    pv = C(); r = pv.get(ev.replace(BASE + "/", ""))
    check("F20 verification link only DISPLAYS a confirm page (GET does not verify); token moved out of the URL", "token=" not in r.url and "Yes, this is my address" in r.text and [u for u in read("users") if u["id"] == pres_id][0]["email"] is None)
    r = pv.post("verify_email.php", {})
    check("F21 pressing confirm trusts the new address", "E-mail confirmed" in r.text and [u for u in read("users") if u["id"] == pres_id][0]["email"] == "new.presiding@dev.invalid")
    pv2 = C(); r = pv2.get(ev.replace(BASE + "/", ""))
    check("F22 verification link is single-use", "Link not valid" in r.text and "Yes, this is my address" not in r.text)
    U_pres_mail = "new.presiding@dev.invalid"
    b4 = H.SINK.count(U_pres_mail); p2 = C(); p2.get("login.php"); r = p2.post("login.php", {"username": "presiding_dev", "password": PW})
    mm = H.SINK.wait_new(U_pres_mail, b4); r = p2.post("mfa.php", {"action": "verify", "code": code_from(mm)}); CODES.append(code_from(mm))
    check("F23 afterwards the code goes to the NEW address and the user regains access", "dashboard.php" in r.url and p2.get("modules/minutes.php").status_code == 200)
    pres = p2
    # restore the dev address so later sections keep working
    users = read("users"); [u.update(email=email_of("presiding_dev")) for u in users if u["id"] == pres_id]; write("users", users)
    # non-privileged target: applied directly but still needs proof of ownership
    r = adm.post("admin_users.php", {"action": "set_email", "id": U["viewer_dev"]["id"], "confirm_password": PW, "new_email": "viewer.new@dev.invalid", "reason": "typo fix"})
    row = [u for u in read("users") if u["username"] == "viewer_dev"][0]
    check("F24 ordinary account: new address is PENDING until its owner confirms (old stays trusted)", row["pending_email"]["address"] == "viewer.new@dev.invalid" and row["email"] == email_of("viewer_dev"))
    r = adm.post("admin_users.php", {"action": "set_email", "id": U["viewer_dev"]["id"], "confirm_password": PW, "new_email": email_of("secretary_dev"), "reason": "dup"})
    check("F25 an address already used by another account is refused", "already belongs" in r.text)
    users = read("users"); [u.update(pending_email=None) for u in users if u["username"] == "viewer_dev"]; write("users", users)
    r = adm.post("admin_users.php", {"action": "set_email", "id": U["viewer_dev"]["id"], "confirm_password": PW, "new_email": "bad@@x", "reason": "x"})
    check("F26 malformed address refused", "valid e-mail" in r.text)
    r = view.post("admin_users.php", {"action": "set_email", "id": U["viewer_dev"]["id"], "confirm_password": PW, "new_email": "evil@dev.invalid", "reason": "x"})
    check("F27 non-administrators cannot change anyone's e-mail (403)", r.status_code == 403)

    # ---------------------------------------------------------------- G. QR login / devices
    a2c.get("manage_devices.php")
    def issue(uname):
        r = a2c.post("manage_devices.php", {"action": "issue", "user_id": U[uname]["id"], "confirm_password": PW})
        return re.search(r'data-token="([A-Za-z0-9_-]+)"', r.text).group(1)
    tok = issue("councilor_dev")
    check("G1 badge token is 192-bit and never stored in plaintext", len(tok) >= 32 and tok not in open(os.path.join(data_dir(), "badges.json")).read())
    def qr(client, token, dev, secret="", lat=OFFICE[0], lng=OFFICE[1]):
        return client.s.post(BASE + "/api/qr_login.php", json={"token": token, "lat": lat, "lng": lng, "deviceId": dev, "deviceSecret": secret, "deviceLabel": "test"})
    q = C(); r = qr(q, tok, "dev-" + "a" * 36, lat=0, lng=0)
    check("G2 QR login outside the geofence refused", r.status_code == 403)
    r = qr(q, "A" * 32, "dev-" + "a" * 36)
    check("G3 unknown token refused with generic message", r.status_code == 401 and r.json()["success"] is False)
    r = qr(q, tok, "dev-" + "a" * 36); j = r.json()
    check("G4 valid badge binds first device and returns one-time device secret", j.get("success") and j.get("deviceSecret") and j["next"] == "dashboard.php", str(j))
    check("G5 response reveals no name/role before MFA", "name" not in j and "role" not in j)
    check("G6 QR session works for council member (limited role)", q.get("modules/attendance.php").status_code == 200 and q.get("admin_users.php", follow=False).status_code == 403)
    dsecret = j["deviceSecret"]
    q2 = C(); r = qr(q2, tok, "dev-" + "b" * 36, dsecret)
    check("G7 same badge from a different device refused", r.status_code == 401)
    q3 = C(); r = qr(q3, tok, "dev-" + "a" * 36, "")
    check("G8 badge + device id without device secret refused (photographed badge useless)", r.status_code == 401)
    q4 = C(); r = qr(q4, tok, "dev-" + "a" * 36, dsecret)
    check("G9 correct device + secret logs in again", r.json().get("success") is True)
    # revoke
    badges = json.load(open(os.path.join(data_dir(), "badges.json"))); bid = [b for b in badges if b["user_id"] == U["councilor_dev"]["id"] and not b.get("revoked")][0]["id"]
    a2c.post("manage_devices.php", {"action": "revoke", "id": bid, "confirm_password": PW})
    check("G10 revoked badge kills the live QR session immediately", q.get("dashboard.php", follow=False).status_code == 302)
    check("G11 revoked badge can no longer log in", qr(C(), tok, "dev-" + "a" * 36, dsecret).status_code == 401)
    # expired enrollment window
    tok2 = issue("councilor2_dev")
    badges = json.load(open(os.path.join(data_dir(), "badges.json")))
    for b in badges:
        if b["token_hash"] == hashlib.sha256(tok2.encode()).hexdigest(): b["enroll_expires_at"] = "2020-01-01 00:00:00"
    json.dump(badges, open(os.path.join(data_dir(), "badges.json"), "w"))
    check("G12 first-bind after the enrollment window is refused", qr(C(), tok2, "dev-" + "c" * 36).status_code == 401)
    # QR + MFA (presiding is MFA-required & enrolled)
    tok3 = issue("presiding_dev")
    nq = H.SINK.count(email_of("presiding_dev"))
    qp = C(); r = qr(qp, tok3, "dev-" + "d" * 36); j = r.json()
    check("G13 QR login for an MFA account stops at the code challenge", j["success"] and j["next"] == "mfa.php")
    check("G14 ...and grants NO access before MFA (QR does not bypass MFA)", qp.get("dashboard.php", follow=False).status_code == 302 and qp.get("modules/minutes.php", follow=False).status_code == 302)
    # presiding was re-enrolled by login() above -> secret in pres.secret
    qp.get("mfa.php")
    mq = H.SINK.wait_new(email_of("presiding_dev"), nq); CODES.append(code_from(mq))
    r = qp.post("mfa.php", {"action": "verify", "code": code_from(mq)})
    check("G15 QR + valid e-mailed code completes login", "dashboard.php" in r.url and qp.get("modules/minutes.php").status_code == 200)
    # outsider JSON CSRF
    r = requests.post(BASE + "/api/qr_login.php", data="token=x", headers={"Content-Type": "application/x-www-form-urlencoded"})
    check("G16 QR API rejects non-JSON (login-CSRF) requests", r.status_code == 415)
    r = requests.post(BASE + "/api/qr_login.php", json={"token": tok, "deviceId": "dev-" + "a" * 36}, headers={"Origin": "http://evil.example"})
    check("G17 QR API rejects cross-origin requests", r.status_code == 403)
    for i in range(7): qr(C(), "B" * 32, "dev-" + "e" * 36)
    check("G18 repeated bad badge scans are throttled", qr(C(), "B" * 32, "dev-" + "e" * 36).status_code == 429)

    # ---------------------------------------------------------------- H. password reset
    a2c.get("admin_users.php")
    nv = H.SINK.count(email_of("viewer_dev"))
    r = a2c.post("admin_users.php", {"action": "send_link", "id": U["viewer_dev"]["id"], "confirm_password": PW})
    check("H0 password link is E-MAILED to the account holder and never shown to the administrator", not re.search(r"reset_password\.php\?token=", r.text) and H.SINK.wait_new(email_of("viewer_dev"), nv) is not None)
    link = re.search(r"reset_password\.php\?token=([a-f0-9]{64})", H.SINK.last(email_of("viewer_dev"))["body"]).group(1)
    v = C(); r = v.get(f"reset_password.php?token={link}")
    check("H1 reset token is moved out of the URL (session-held)", "token=" not in r.url and "Choose a password" in r.text)
    r = v.post("reset_password.php", {"new": "short", "confirm": "short"})
    check("H2 weak password rejected", "at least 12" in r.text)
    NEW = "A-Brand-New-Passphrase-77"
    r = v.post("reset_password.php", {"new": NEW, "confirm": NEW})
    check("H3 valid reset completes", "Password set" in r.text)
    v2 = C(); r = v2.get(f"reset_password.php?token={link}")
    check("H4 reset token is single-use", "Link not valid" in r.text and 'name="new"' not in r.text)
    v3 = C(); v3.get("login.php"); r = v3.post("login.php", {"username": "viewer_dev", "password": NEW})
    check("H5 new password works; old one no longer does", "dashboard.php" in r.url)
    v3.post("logout.php")
    check("H6 logout destroys session server-side", v3.get("dashboard.php", follow=False).status_code == 302)

    # ---------------------------------------------------------------- I. audit + concurrency
    out = php("define('SSMS_DATA_DIR','%s');require 'includes/config.php';require 'includes/store.php';require 'includes/audit.php';[$ok,$b]=ssms_audit_verify();echo $ok?'INTACT':'BROKEN';" % data_dir())
    check("I1 audit log hash chain verifies", out == "INTACT", out)
    log = open(os.path.join(data_dir(), "audit.log")).read()
    for needle in CODES + [PW, NEW, tok, dsecret, link]:
        if needle in log: check("I2 audit log contains no secrets/passwords/tokens/OTPs", False, needle[:6]); break
    else: check("I2 audit log contains no secrets/passwords/tokens/OTPs", True)
    acts = set(json.loads(l)["action"] for l in log.splitlines())
    need = {"auth.login", "otp.issue", "otp.verify", "email.verified", "email.change_requested", "email.change_approved", "user.role_changed", "session.created", "session.status_changed", "attendance.recorded", "attendance.corrected", "agenda.finalized", "proceedings.amended", "minutes.generated", "minutes.approved", "minutes.published", "device.bound", "badge.revoked", "authz.denied", "settings.changed"}
    check("I3 audit covers required event types", need <= acts, str(need - acts))
    # tamper detection
    lines = log.splitlines(); k = [i for i, l in enumerate(lines) if '"result":"ok"' in l][2]; lines[k] = lines[k].replace('"result":"ok"', '"result":"hax"'); 
    open(os.path.join(data_dir(), "audit.log"), "w").write("\n".join(lines) + "\n")
    out = php("define('SSMS_DATA_DIR','%s');require 'includes/config.php';require 'includes/store.php';require 'includes/audit.php';[$ok,$b]=ssms_audit_verify();echo $ok?'INTACT':'BROKEN';" % data_dir())
    check("I4 tampering with an audit entry is detected", out == "BROKEN", out)
    # concurrency: 12 simultaneous attendance writes for different members
    s2 = [s for s in json.load(open(os.path.join(data_dir(), "sessions.json"))) if s["id"] == 2][0]
    sec.get("modules/attendance.php?session_id=2"); tokn = sec.csrf; errs = []
    def worker(m):
        try:
            requests.post(BASE + "/modules/attendance.php", data={"csrf": tokn, "action": "mark", "session_id": 2, "member_id": m, "status": "Present"}, cookies=sec.s.cookies.get_dict())
        except Exception as e: errs.append(str(e))
    ths = [threading.Thread(target=worker, args=(m,)) for m in range(1, 10)]
    [t.start() for t in ths]; [t.join() for t in ths]
    rows = json.load(open(os.path.join(data_dir(), "attendance.json")))
    mine = [r for r in rows if r["session_id"] == 2]
    ids = [r["id"] for r in rows]
    check("I5 concurrent writes: valid JSON, 9 distinct records, unique ids, none lost", len(mine) == 9 and len(set(ids)) == len(ids) and not errs, f"{len(mine)} rows")
    # corrupted file handling
    p = os.path.join(data_dir(), "members.json"); good = open(p).read(); open(p, "w").write("{broken")
    r = sec.get("modules/attendance.php", follow=False)
    check("I6 corrupted data file -> safe error, no stack trace, nothing overwritten", r.status_code == 500 and "Traceback" not in r.text and "members.json" not in r.text and open(p).read() == "{broken")
    open(p, "w").write(good)
    check("I7 service recovers once the file is restored", sec.get("modules/attendance.php").status_code == 200)


if __name__ == "__main__":
    main()
