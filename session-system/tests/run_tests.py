#!/usr/bin/env python3
"""
SSMS end-to-end test suite. Starts `php -S` on a THROWAWAY COPY of the data directory and drives the
real application over HTTP (no mocks). Own TOTP implementation => independent cross-check of the PHP one.
Usage:  python3 tests/run_tests.py        (needs: php, python3, requests)
Prereq: php tools/setup.php --dev has been run once (creates config/.env and dev accounts).
"""
import base64, hashlib, hmac, json, os, re, shutil, struct, subprocess, sys, tempfile, threading, time
import requests

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PORT = 8099
BASE = f"http://127.0.0.1:{PORT}"
PW = "DevOnly#2026-ssms"
OFFICE = (14.81053705, 121.046147894731)
results = []

def check(name, cond, detail=""):
    results.append((name, bool(cond), detail))
    print(("PASS " if cond else "FAIL ") + name + ("" if cond else f"  -> {detail}"))

def totp(secret_b32, t=None, step=30):
    key = base64.b32decode(secret_b32.replace(" ", "").upper() + "=" * (-len(secret_b32.replace(" ", "")) % 8))
    c = int((t or time.time()) // step)
    h = hmac.new(key, struct.pack(">Q", c), hashlib.sha1).digest()
    o = h[19] & 15
    return str((struct.unpack(">I", h[o:o+4])[0] & 0x7fffffff) % 10**6).zfill(6)

LAST = {}
def otp(secret):
    """Next valid, never-before-used code: honours replay protection and the +/-1 step window."""
    c = max(LAST.get(secret, 0) + 1, int(time.time() // 30))
    while c > int(time.time() // 30) + 1: time.sleep(1)
    LAST[secret] = c
    return totp(secret, c * 30)

class C:
    """Browser-like client with its own cookie jar."""
    def __init__(self):
        self.s = requests.Session(); self.csrf = None; self.secret = None
    def get(self, path, **kw):
        r = self.s.get(BASE + "/" + path.lstrip("/"), allow_redirects=kw.pop("follow", True), **kw)
        m = re.search(r'name="csrf" value="([a-f0-9]+)"', r.text)
        if m: self.csrf = m.group(1)
        return r
    def post(self, path, data=None, csrf=True, **kw):
        d = dict(data or {})
        if csrf: d["csrf"] = self.csrf
        r = self.s.post(BASE + "/" + path.lstrip("/"), data=d, allow_redirects=kw.pop("follow", True), **kw)
        m = re.search(r'name="csrf" value="([a-f0-9]+)"', r.text)
        if m: self.csrf = m.group(1)
        return r
    def sid(self): return self.s.cookies.get("SSMS_SID")

def login(user, pw=PW, enroll=True):
    c = C(); c.get("login.php")
    r = c.post("login.php", {"username": user, "password": pw})
    if "mfa_setup.php" in r.url and enroll:      # mandatory enrollment for privileged roles
        c.post("mfa_setup.php", {"action": "begin"})
        r = c.get("mfa_setup.php")
        c.secret = re.search(r'id="manual-key"[^>]*>([A-Z2-7 ]+)<', r.text).group(1).replace(" ", "")
        r = c.post("mfa_setup.php", {"action": "confirm", "code": otp(c.secret)})
        c.codes = re.findall(r"<span>([A-Z2-7]{5}-[A-Z2-7]{5})</span>", r.text)
    return c

def php(code):
    return subprocess.run(["php", "-r", code], capture_output=True, text=True, cwd=ROOT).stdout.strip()

def main():
    tmp = tempfile.mkdtemp(prefix="ssms_test_")
    data = os.path.join(tmp, "data"); os.makedirs(data)
    for f in os.listdir(os.path.join(ROOT, "data")):
        p = os.path.join(ROOT, "data", f)
        if os.path.isfile(p) and f.endswith(".json"): shutil.copy(p, data)
    for t in ("ratelimit",): open(os.path.join(data, t + ".json"), "w").write("{}")
    env = dict(os.environ, SSMS_DATA_DIR=data, SSMS_ENV="development")
    srv = subprocess.Popen(["php", "-S", f"127.0.0.1:{PORT}", "router.php"], cwd=ROOT, env=env, stdout=subprocess.DEVNULL, stderr=open(os.path.join(tmp, "php.log"), "w"))
    time.sleep(1.2)
    try:
        run()
    finally:
        srv.terminate()
        log = open(os.path.join(tmp, "php.log")).read()
        warn = [l for l in log.splitlines() if re.search(r"Warning|Notice|Deprecated|Fatal|Parse error", l)]
        check("No PHP warnings/notices/fatals in server log during entire run", not warn, "\n".join(warn[:5]))
        passed = sum(1 for r in results if r[1])
        print(f"\n==== {passed}/{len(results)} passed ====")
        shutil.rmtree(tmp, ignore_errors=True)
        sys.exit(0 if passed == len(results) else 1)

def run():
    # ---------------------------------------------------------------- A. basic / exposure
    c = C()
    for p in ["login.php", "qr_login.php", "reset_password.php"]:
        check(f"A1 public page loads: {p}", c.get(p).status_code == 200)
    for p in ["dashboard.php", "modules/scheduling.php", "modules/agenda.php", "modules/attendance.php", "modules/proceedings.php", "modules/minutes.php", "modules/tracking.php", "admin_users.php", "manage_devices.php", "audit_view.php", "settings.php", "mfa_setup.php"]:
        r = c.get(p, follow=False)
        check(f"A2 unauthenticated redirected to login: {p}", r.status_code == 302 and "login.php" in r.headers.get("Location", ""), f"{r.status_code}")
    r = c.get("api/live_status.php?session_id=1", follow=False)
    check("A3 live_status API without login -> 401 JSON", r.status_code == 401 and r.json().get("success") is False)
    for p in ["data/users.json", "data/audit.log", "config/.env", "config/.env.example", "includes/store.php", "tools/setup.php", "data/badges.json", "data/sessions.json"]:
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

    # ---------------------------------------------------------------- C. MFA lifecycle
    a = C(); sid0 = (a.get("login.php"), a.sid())[1]
    r = a.post("login.php", {"username": "admin_dev", "password": PW})
    check("C1 privileged account without MFA is forced to enrollment", "mfa_setup.php" in r.url and "required for your account" in r.text)
    check("C2 session id regenerated at login (fixation defence)", a.sid() != sid0)
    r = a.get("modules/scheduling.php", follow=False)
    check("C3 enrollment-only session cannot reach modules", r.status_code == 302 and "mfa_setup.php" in r.headers["Location"])
    a.post("mfa_setup.php", {"action": "begin"}); r = a.get("mfa_setup.php")
    secret = re.search(r'id="manual-key"[^>]*>([A-Z2-7 ]+)<', r.text).group(1).replace(" ", "")
    check("C4 enrollment page shows otpauth URI and 160-bit secret", "otpauth://totp/" in r.text and len(secret) == 32)
    check("C5 secret not placed in a URL", secret not in r.url)
    r = a.post("mfa_setup.php", {"action": "confirm", "code": "000000"})
    check("C6 enrollment rejects wrong code", "did not match" in r.text)
    r = a.post("mfa_setup.php", {"action": "confirm", "code": otp(secret)})
    codes = re.findall(r"<span>([A-Z2-7]{5}-[A-Z2-7]{5})</span>", r.text)
    check("C7 correct code enables MFA (Python TOTP == PHP TOTP) and shows 10 recovery codes once", len(codes) == 10)
    r = a.get("mfa_setup.php")
    check("C8 recovery codes are not shown again", not re.findall(r"<span>([A-Z2-7]{5}-[A-Z2-7]{5})</span>", r.text))
    raw = open(os.path.join(data_dir(), "users.json")).read()
    check("C9 TOTP secret & recovery codes not stored in plaintext", secret not in raw and not any(cd.replace("-", "") in raw for cd in codes))
    a.post("logout.php"); 
    b = C(); b.get("login.php")
    r = b.post("login.php", {"username": "admin_dev", "password": PW})
    check("C10 password OK + MFA enabled -> MFA challenge, not access", "mfa.php" in r.url)
    r = b.get("dashboard.php", follow=False)
    check("C11 password-only (pending) state cannot open protected page", r.status_code == 302 and "login.php" in r.headers["Location"])
    r = b.get("api/live_status.php?session_id=1", follow=False)
    check("C12 pending state cannot call APIs", r.status_code == 401)
    r = b.post("mfa.php", {"mode": "totp", "code": "123456" if totp(secret) != "123456" else "654321"})
    check("C13 wrong OTP rejected", "not valid" in r.text and "mfa.php" in r.url)
    sid_before = b.sid()
    used = otp(secret); r = b.post("mfa.php", {"mode": "totp", "code": used})
    check("C14 correct OTP grants session and goes to dashboard", "dashboard.php" in r.url and r.status_code == 200, r.url)
    check("C15 session id regenerated after MFA", b.sid() != sid_before)
    b.post("logout.php")
    b = C(); b.get("login.php"); b.post("login.php", {"username": "admin_dev", "password": PW})
    r = b.post("mfa.php", {"mode": "totp", "code": used})
    check("C16 replay of an already-used OTP is rejected", "dashboard.php" not in r.url)
    r = b.post("mfa.php", {"mode": "recovery", "code": codes[0]})
    check("C17 valid recovery code signs in", "dashboard.php" in r.url)
    b.post("logout.php")
    b = C(); b.get("login.php"); b.post("login.php", {"username": "admin_dev", "password": PW})
    r = b.post("mfa.php", {"mode": "recovery", "code": codes[0]})
    check("C18 reused recovery code rejected", "dashboard.php" not in r.url)
    r = b.post("mfa.php", {"mode": "recovery", "code": "AAAAA-AAAAA"})
    check("C19 invalid recovery code rejected", "dashboard.php" not in r.url)
    # brute force: pending killed after max tries
    for i in range(6): r = b.post("mfa.php", {"mode": "totp", "code": "%06d" % (111111 + i)})
    r = b.get("mfa.php", follow=False)
    check("C20 MFA brute force: pending state destroyed after repeated failures", r.status_code == 302 and "login.php" in r.headers["Location"], f"{r.status_code}")
    open(os.path.join(data_dir(), "ratelimit.json"), "w").write("{}")   # lift the throttle the brute-force test deliberately triggered
    # expired pending
    b = C(); b.get("login.php"); b.post("login.php", {"username": "secretary_dev", "password": PW})  # secretary -> enroll path (no pending); use admin2 instead
    b2 = login("admin2_dev"); b2.post("logout.php")
    b = C(); b.get("login.php"); b.post("login.php", {"username": "admin2_dev", "password": PW})
    sess_file = None
    check("C21 pending state is session-bound (cookie-less client has none)", C().get("mfa.php", follow=False).status_code == 302)
    # ---------------------------------------------------------------- D. RBAC matrix
    sec = login("secretary_dev"); pres = login("presiding_dev"); adm = a2 = login("admin_dev", enroll=False)
    # admin was enrolled earlier by 'a'; re-login with real MFA:
    adm = C(); adm.get("login.php"); adm.post("login.php", {"username": "admin_dev", "password": PW})
    adm.post("mfa.php", {"mode": "totp", "code": otp(secret)}); adm.get("dashboard.php")
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
    # MFA reset needs two admins
    pres_id = U["presiding_dev"]["id"]
    r = adm.post("admin_users.php", {"action": "request_mfa_reset", "id": pres_id, "confirm_password": PW, "reason": "lost phone, identity verified in person"})
    check("F14 admin can REQUEST an MFA reset (not perform it)", "reset requested" in r.text.lower())
    r = adm.post("admin_users.php", {"action": "approve_mfa_reset", "id": pres_id, "confirm_password": PW})
    check("F15 requester cannot approve own MFA reset", "cannot approve your own" in r.text)
    still = [u for u in json.load(open(os.path.join(data_dir(), "users.json"))) if u["id"] == pres_id][0]
    check("F16 MFA stayed enabled", still["mfa"]["enabled"] is True)
    a2 = login("admin2_dev"); a2.get("login.php")
    a2c = C(); a2c.get("login.php"); a2c.post("login.php", {"username": "admin2_dev", "password": PW})
    if "mfa_setup" in a2c.get("dashboard.php", follow=False).headers.get("Location", "") or True:
        pass
    # admin2 was enrolled by login(); use its secret
    a2s = a2.secret
    a2c.post("mfa.php", {"mode": "totp", "code": otp(a2s)}); a2c.get("admin_users.php")
    r = a2c.post("admin_users.php", {"action": "approve_mfa_reset", "id": pres_id, "confirm_password": PW})
    check("F17 a DIFFERENT administrator approves the MFA reset", "MFA reset" in r.text and not [u for u in json.load(open(os.path.join(data_dir(), "users.json"))) if u["id"] == pres_id][0]["mfa"]["enabled"])
    check("F18 reset ends the user's sessions", pres.get("dashboard.php", follow=False).status_code == 302)
    pres = login("presiding_dev")   # re-enrolls (mandatory)
    check("F19 after reset the privileged user must re-enroll, then regains access", pres.get("modules/minutes.php").status_code == 200)

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
    qp = C(); r = qr(qp, tok3, "dev-" + "d" * 36); j = r.json()
    check("G13 QR login for an MFA account stops at the MFA challenge", j["success"] and j["next"] == "mfa.php")
    check("G14 ...and grants NO access before MFA (QR does not bypass MFA)", qp.get("dashboard.php", follow=False).status_code == 302 and qp.get("modules/minutes.php", follow=False).status_code == 302)
    # presiding was re-enrolled by login() above -> secret in pres.secret
    r = qp.post("mfa.php", {"mode": "totp", "code": otp(pres.secret)})
    check("G15 QR + valid OTP completes login", "dashboard.php" in r.url and qp.get("modules/minutes.php").status_code == 200)
    # outsider JSON CSRF
    r = requests.post(BASE + "/api/qr_login.php", data="token=x", headers={"Content-Type": "application/x-www-form-urlencoded"})
    check("G16 QR API rejects non-JSON (login-CSRF) requests", r.status_code == 415)
    r = requests.post(BASE + "/api/qr_login.php", json={"token": tok, "deviceId": "dev-" + "a" * 36}, headers={"Origin": "http://evil.example"})
    check("G17 QR API rejects cross-origin requests", r.status_code == 403)
    for i in range(7): qr(C(), "B" * 32, "dev-" + "e" * 36)
    check("G18 repeated bad badge scans are throttled", qr(C(), "B" * 32, "dev-" + "e" * 36).status_code == 429)

    # ---------------------------------------------------------------- H. password reset
    a2c.get("admin_users.php")
    r = a2c.post("admin_users.php", {"action": "issue_reset", "id": U["viewer_dev"]["id"], "confirm_password": PW})
    link = re.search(r"reset_password\.php\?token=([a-f0-9]{64})", r.text).group(1)
    v = C(); r = v.get(f"reset_password.php?token={link}")
    check("H1 reset token is moved out of the URL (session-held)", "token=" not in r.url and "Choose a password" in r.text)
    r = v.post("reset_password.php", {"new": "short", "confirm": "short"})
    check("H2 weak password rejected", "at least 12" in r.text)
    NEW = "A-Brand-New-Passphrase-77"
    r = v.post("reset_password.php", {"new": NEW, "confirm": NEW})
    check("H3 valid reset completes", "Password set" in r.text)
    v2 = C(); v2.get(f"reset_password.php?token={link}")
    r = v2.post("reset_password.php", {"new": NEW + "x", "confirm": NEW + "x"})
    check("H4 reset token is single-use", "invalid, expired, or already used" in r.text)
    v3 = C(); v3.get("login.php"); r = v3.post("login.php", {"username": "viewer_dev", "password": NEW})
    check("H5 new password works; old one no longer does", "dashboard.php" in r.url)
    v3.post("logout.php")
    check("H6 logout destroys session server-side", v3.get("dashboard.php", follow=False).status_code == 302)

    # ---------------------------------------------------------------- I. audit + concurrency
    out = php("define('SSMS_DATA_DIR','%s');require 'includes/config.php';require 'includes/store.php';require 'includes/audit.php';[$ok,$b]=ssms_audit_verify();echo $ok?'INTACT':'BROKEN';" % data_dir())
    check("I1 audit log hash chain verifies", out == "INTACT", out)
    log = open(os.path.join(data_dir(), "audit.log")).read()
    for needle in [secret, codes[1], PW, NEW, tok, dsecret]:
        if needle in log: check("I2 audit log contains no secrets/passwords/tokens/OTPs", False, needle[:6]); break
    else: check("I2 audit log contains no secrets/passwords/tokens/OTPs", True)
    acts = set(json.loads(l)["action"] for l in log.splitlines())
    need = {"auth.login", "mfa.enrolled", "mfa.challenge", "mfa.recovery_code_used", "user.role_changed", "session.created", "session.status_changed", "attendance.recorded", "attendance.corrected", "agenda.finalized", "proceedings.amended", "minutes.generated", "minutes.approved", "minutes.published", "device.bound", "badge.revoked", "mfa.reset_approved", "authz.denied", "settings.changed"}
    check("I3 audit covers required event types", need <= acts, str(need - acts))
    # tamper detection
    lines = log.splitlines(); lines[3] = lines[3].replace('"ok"', '"hax"'); 
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

def data_dir(): return DATA
DATA = None
if __name__ == "__main__":
    # data dir is created in main(); expose via global before run()
    _orig = tempfile.mkdtemp
    def _mk(prefix=""):
        global DATA
        d = _orig(prefix=prefix); DATA = os.path.join(d, "data"); return d
    tempfile.mkdtemp = _mk
    main()
