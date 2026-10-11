"""
Shared test harness for the SSMS HTTP tests.
 * Starts `php -S` on a THROWAWAY copy of the data directory and drives the real application over HTTP.
 * Runs a local SMTP *sink* (plain SMTP on 127.0.0.1) so the real PHPMailer code path is exercised and the
   test can read the e-mailed codes/links. This is NOT a real mail provider: see docs/EMAIL_OTP_SETUP.md for the
   manual real-SMTP test.
 * Builds its own users (with verified dev.invalid addresses) so the tests do not depend on `setup.php --dev`.
Needs: php (>= 8.1), python3, requests.
"""
import base64, json, os, re, shutil, socketserver, subprocess, sys, tempfile, threading, time
import requests

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PORT = int(os.environ.get("SSMS_TEST_PORT", "8099"))
SMTP_PORT = int(os.environ.get("SSMS_TEST_SMTP_PORT", "2525"))
BASE = f"http://127.0.0.1:{PORT}"
PW = "DevOnly#2026-ssms"
OFFICE = (14.81053705, 121.046147894731)
results = []
DATA = None
TMP = None

def check(name, cond, detail=""):
    results.append((name, bool(cond), detail))
    print(("PASS " if cond else "FAIL ") + name + ("" if cond else f"  -> {str(detail)[:300]}"))

# ------------------------------------------------------------------ SMTP sink
class Sink:
    """Minimal SMTP server that stores every message. Can be told to refuse delivery (outage simulation)."""
    def __init__(self, port):
        self.mail = []            # dicts: to, subject, body
        self.lock = threading.Lock()
        self.fail = False
        outer = self
        class H(socketserver.StreamRequestHandler):
            def handle(self):
                w = lambda s: (self.wfile.write((s + "\r\n").encode()), self.wfile.flush())
                w("220 sink ESMTP")
                to, data = [], None
                while True:
                    line = self.rfile.readline()
                    if not line: return
                    cmd = line.decode("utf-8", "replace").strip(); up = cmd.upper()
                    if up.startswith(("EHLO", "HELO")): w("250 sink")
                    elif up.startswith("MAIL FROM"): to = []; w("250 ok")
                    elif up.startswith("RCPT TO"):
                        to.append(re.search(r"<([^>]+)>", cmd).group(1).lower()); w("250 ok")
                    elif up == "DATA":
                        if outer.fail: w("554 transaction failed (simulated outage)"); continue
                        w("354 go")
                        buf = []
                        while True:
                            l = self.rfile.readline().decode("utf-8", "replace")
                            if l in (".\r\n", ".\n", ""): break
                            buf.append(l[1:] if l.startswith("..") else l)
                        raw = "".join(buf)
                        head, _, body = raw.partition("\r\n\r\n")
                        subj = re.search(r"^Subject:\s*(.*)$", head, re.M | re.I)
                        enc = re.search(r"Content-Transfer-Encoding:\s*(\S+)", head, re.I)
                        if enc and enc.group(1).lower() == "quoted-printable":
                            import quopri; body = quopri.decodestring(body.encode()).decode("utf-8", "replace")
                        elif enc and enc.group(1).lower() == "base64":
                            body = base64.b64decode(body).decode("utf-8", "replace")
                        with outer.lock:
                            for t in to: outer.mail.append({"to": t, "subject": subj.group(1).strip() if subj else "", "body": body, "raw": raw})
                        w("250 queued")
                    elif up == "QUIT": w("221 bye"); return
                    elif up == "RSET": w("250 ok")
                    else: w("250 ok")
        class S(socketserver.ThreadingTCPServer):
            allow_reuse_address = True; daemon_threads = True
        self.srv = S(("127.0.0.1", port), H)
        threading.Thread(target=self.srv.serve_forever, daemon=True).start()
    def stop(self): self.srv.shutdown(); self.srv.server_close()
    def count(self, to=None):
        with self.lock: return len([m for m in self.mail if to is None or m["to"] == to.lower()])
    def wait_new(self, to, after, timeout=8):
        """Wait for a message to `to` beyond the first `after` already seen; returns it or None."""
        end = time.time() + timeout
        while time.time() < end:
            with self.lock:
                got = [m for m in self.mail if m["to"] == to.lower()]
            if len(got) > after: return got[-1]
            time.sleep(0.1)
        return None
    def last(self, to):
        with self.lock: got = [m for m in self.mail if m["to"] == to.lower()]
        return got[-1] if got else None
    def clear(self):
        with self.lock: self.mail.clear()

SINK = None
def code_from(msg): return re.search(r"verification code:\s+(\d{6})", msg["body"]).group(1)
def link_from(msg, page):
    m = re.search(r"(http://127\.0\.0\.1:\d+/" + re.escape(page) + r"\?token=[a-f0-9]{64})", msg["body"]); return m.group(1) if m else None

# ------------------------------------------------------------------ HTTP client
class C:
    """Browser-like client with its own cookie jar."""
    def __init__(self): self.s = requests.Session(); self.csrf = None
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

def email_of(user): return f"{user}@dev.invalid"

def start_login(user, pw=PW):
    """Password step. Returns (client, response, newest-mail-count-before)."""
    before = SINK.count(email_of(user)) if SINK else 0
    c = C(); c.get("login.php")
    r = c.post("login.php", {"username": user, "password": pw})
    return c, r, before

def login(user, pw=PW):
    """Full sign-in: password, then the code actually delivered through SMTP to the user's own address."""
    c, r, before = start_login(user, pw)
    if "mfa.php" in r.url:
        m = SINK.wait_new(email_of(user), before)
        assert m, f"no OTP e-mail arrived for {user}"
        r = c.post("mfa.php", {"action": "verify", "code": code_from(m)})
        assert "dashboard.php" in r.url, f"OTP login failed for {user}: {r.url}"
    return c

def php(code, env=None):
    e = dict(os.environ); e.update(env or {}); e["SSMS_ENV_FILE"] = ENVFILE
    return subprocess.run(["php", "-r", code], capture_output=True, text=True, cwd=ROOT, env=e).stdout.strip()

def read(table):
    p = os.path.join(DATA, table + ".json")
    return json.load(open(p)) if os.path.exists(p) else []
def write(table, rows): json.dump(rows, open(os.path.join(DATA, table + ".json"), "w"))
def data_dir(): return DATA

ENVFILE = None
DEV = [("admin_dev", "sysadmin", None), ("secretary_dev", "secretary", None), ("presiding_dev", "presiding", 1), ("councilor_dev", "councilor", 2),
       ("councilor2_dev", "councilor", 3), ("docstaff_dev", "documentation", None), ("viewer_dev", "viewer", None), ("admin2_dev", "sysadmin", None)]

class Server:
    """PHP built-in server with N workers on a fresh copy of the data directory."""
    def __init__(self, extra_env=None, smtp_port=None, with_mail=True, tag="srv"):
        global DATA, TMP, ENVFILE
        self.tmp = tempfile.mkdtemp(prefix=f"ssms_{tag}_"); TMP = self.tmp
        self.data = os.path.join(self.tmp, "data"); os.makedirs(self.data); DATA = self.data
        for f in os.listdir(os.path.join(ROOT, "data")):
            p = os.path.join(ROOT, "data", f)
            if os.path.isfile(p) and f.endswith(".json") and f not in ("users.json",): shutil.copy(p, self.data)
        pwh = subprocess.run(["php", "-r", f"echo password_hash('{PW}', PASSWORD_DEFAULT);"], capture_output=True, text=True).stdout
        now = time.strftime("%Y-%m-%d %H:%M:%S")
        users = []
        for i, (u, role, member) in enumerate(DEV, 1):
            users.append({"id": i, "username": u, "name": u, "role": role, "member_id": member, "password_hash": pwh, "active": True,
                          "email": email_of(u), "email_verified": True, "email_verified_at": now, "pending_email": None, "mfa": {"email_otp": False},
                          "failed_count": 0, "session_version": 1, "created_at": now, "last_login_at": None, "is_dev_account": True})
        write("users", users)
        for t in ("ratelimit",): open(os.path.join(self.data, t + ".json"), "w").write("{}")
        write("otp_challenges", [])
        key = base64.b64encode(os.urandom(32)).decode()
        env = {"SSMS_APP_KEY": key, "SSMS_ENV": "development", "SSMS_APP_URL": BASE, "SSMS_OTP_RESEND_COOLDOWN_SEC": "5",
               "SSMS_OTP_SENDS_PER_15MIN": "200", "SSMS_GEOFENCE_ENABLED": "true"}
        if with_mail:
            env.update({"SSMS_SMTP_HOST": "127.0.0.1", "SSMS_SMTP_PORT": str(smtp_port or SMTP_PORT), "SSMS_SMTP_ENCRYPTION": "none", "SSMS_MAIL_FROM": "ssms@test.invalid"})
        env.update(extra_env or {})
        ENVFILE = os.path.join(self.tmp, "test.env")
        open(ENVFILE, "w").write("# test environment\n")           # real env vars below win; keeps the developer's config/.env out of tests
        penv = dict(os.environ, SSMS_DATA_DIR=self.data, SSMS_ENV_FILE=ENVFILE, PHP_CLI_SERVER_WORKERS="8", **env)
        for k in ("SSMS_SMTP_HOST", "SSMS_MAIL_FROM"):
            if not with_mail: penv.pop(k, None)
        self.env = penv
        self.proc = subprocess.Popen(["php", "-S", f"127.0.0.1:{PORT}", "router.php"], cwd=ROOT, env=penv, stdout=subprocess.DEVNULL, stderr=open(os.path.join(self.tmp, "php.log"), "w"))
        for _ in range(50):
            try: requests.get(BASE + "/login.php", timeout=1); break
            except Exception: time.sleep(0.1)
    def log(self): return open(os.path.join(self.tmp, "php.log")).read()
    def stop(self):
        self.proc.terminate()
        try: self.proc.wait(5)
        except Exception: self.proc.kill()
    def cleanup(self): shutil.rmtree(self.tmp, ignore_errors=True)

def summary(extra_log_checks=None):
    passed = sum(1 for r in results if r[1])
    print(f"\n==== {passed}/{len(results)} passed ====")
    return passed == len(results)
