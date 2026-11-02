import json, os, re, subprocess, time, hashlib, urllib.parse
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[2]
BASE = os.environ.get("VF_BASE", "http://127.0.0.1:10001")
CDP = os.environ.get("VF_CDP", "http://127.0.0.1:9222")
STATE = Path(os.environ.get("VF_STATE", "/tmp/vf-browser-state.json"))
FIX = json.loads(Path(os.environ.get("VF_FIXTURES", "/tmp/bt-fixtures.json")).read_text())
DOCS = ROOT / "docs/pruebas-funcionales.md"
REPORT = ROOT / "tests/browser/report.md"
RESULTS = ROOT / "tests/browser/results.json"

def dbpass():
    for line in (ROOT / ".env").read_text().splitlines():
        if line.startswith("DB_PASSWORD="):
            return line.split("=", 1)[1]
    return ""

def mysql(sql, db="vanilla_forms"):
    p = subprocess.run(["mysql", "-h127.0.0.1", "-umariadb", f"-p{dbpass()}", db, "-N", "-B", "-e", sql], capture_output=True, text=True)
    if p.returncode != 0:
        raise RuntimeError(p.stderr.strip())
    rows = []
    for line in p.stdout.splitlines():
        rows.append(line.split("\t"))
    return rows

def db1(sql, db="vanilla_forms"):
    r = mysql(sql, db)
    return r[0] if r else None

def parse_checks():
    checks = {}
    block = ""
    for line in DOCS.read_text().splitlines():
        m = re.match(r"^## (?:Bloque )?([A-Z]{1,2})\b", line)
        if m:
            block = m.group(1)
        m = re.match(r"^\| ([A-Z]{1,2})[0-9]+ \|", line)
        if not m or not line.startswith("| "):
            continue
        cells = [c.strip() for c in line.strip().strip("|").split("|")]
        if len(cells) < 4:
            continue
        cid, what, expected = cells[0], cells[1], cells[2]
        checks[cid] = {"block": block, "what": what, "expected": expected}
    return checks

def load_results():
    if RESULTS.exists():
        try:
            return json.loads(RESULTS.read_text())
        except Exception:
            pass
    return {}

def save_results(results):
    RESULTS.write_text(json.dumps(results, ensure_ascii=False, indent=1))

class R:
    def __init__(self):
        self.checks = parse_checks()
        self.results = load_results()
        self.notes = []
    def mark(self, cid, ok, detail="", na=False):
        if cid not in self.checks and not re.match(r"^[A-Z]{1,2}[0-9]+$", cid):
            raise ValueError("id raro: " + cid)
        self.results[cid] = {"status": "N/A" if na else ("OK" if ok else "FALLA"), "detail": detail, "at": time.strftime("%Y-%m-%d %H:%M:%S")}
        save_results(self.results)
    def ok(self, cid, detail=""):
        self.mark(cid, True, detail)
    def fail(self, cid, detail=""):
        self.mark(cid, False, detail)
    def na(self, cid, detail=""):
        self.mark(cid, True, detail, na=True)
    def check(self, cid, cond, detail=""):
        self.mark(cid, bool(cond), detail)
        return bool(cond)
    def report(self):
        seen = set(self.results)
        for cid in self.checks:
            if cid not in seen:
                self.results[cid] = {"status": "PENDIENTE", "detail": "no ejecutado", "at": ""}
        save_results(self.results)
        counts = {}
        for v in self.results.values():
            counts[v["status"]] = counts.get(v["status"], 0) + 1
        lines = ["# Informe de pruebas funcionales con Lightpanda", "", f"Generado: {time.strftime('%Y-%m-%d %H:%M:%S')}", "", "## Resumen", ""]
        for k in ["OK", "FALLA", "N/A", "PENDIENTE"]:
            if k in counts:
                lines.append(f"- **{k}**: {counts[k]}")
        lines += ["", "## Detalle", ""]
        cur = None
        for cid in sorted(self.results, key=lambda x: (re.match(r"[A-Z]+", x).group(0), int(re.search(r"\d+", x).group(0)))):
            b = self.checks.get(cid, {}).get("block", "?")
            if b != cur:
                cur = b
                lines += [f"### Bloque {b}", "", "| # | Estado | Qué se comprobó | Detalle |", "|---|---|---|---|"]
            v = self.results[cid]
            what = self.checks.get(cid, {}).get("what", "").replace("|", "\\|")
            det = v["detail"].replace("|", "\\|").replace("\n", " ")[:500]
            lines.append(f"| {cid} | {v['status']} | {what} | {det} |")
        REPORT.write_text("\n".join(lines) + "\n")
        return counts

class Browser:
    def __init__(self):
        self.pw = None; self.browser = None
    def __enter__(self):
        self.pw = sync_playwright().start()
        self.browser = self.pw.chromium.connect_over_cdp(CDP)
        return self
    def __exit__(self, *a):
        try: self.pw.stop()
        except Exception: pass
    def session(self, R, email="admin@demo.com", password="password123"):
        return VF(R, email, password, browser=self.browser)

class VF:
    def __init__(self, R, email="admin@demo.com", password="password123", browser=None):
        self.R, self.email, self.password = R, email, password
        self.pw = None; self.browser = browser; self.ctx = None; self.page = None
    def __enter__(self):
        if self.browser is None:
            self.pw = sync_playwright().start()
            self.browser = self.pw.chromium.connect_over_cdp(CDP)
        self.ctx = self.browser.new_context(viewport={"width": 1366, "height": 900}, accept_downloads=True)
        self.page = self.ctx.new_page()
        if self.email:
            self.login()
        return self
    def __exit__(self, *a):
        try: self.ctx.close()
        except Exception: pass
        if self.pw is not None:
            try: self.pw.stop()
            except Exception: pass
    def login(self, email=None, password=None):
        email = email or self.email; password = password or self.password
        self.page.goto(BASE + "/login")
        self.page.fill("input[name=email]", email)
        self.page.fill("input[name=password]", password)
        self.page.click("button[type=submit]")
        self.page.wait_for_timeout(500)
        return "/login" not in self.page.url
    def logout(self):
        f = self.page.locator("form[action$='/logout']")
        if f.count():
            f.first.evaluate("f => f.requestSubmit()")
        else:
            self.page.goto(BASE + "/logout")
        self.page.wait_for_timeout(500)
    def goto(self, path, wait=350):
        r = self.page.goto(BASE + path)
        self.page.wait_for_timeout(wait)
        return r
    def text(self, sel="body"):
        return self.page.locator(sel).inner_text()
    def has(self, s):
        return s in self.text()
    def csrf(self):
        v = self.page.locator("body").get_attribute("data-csrf")
        if v:
            return v
        t = self.page.locator("input[name=_token]").first
        return t.get_attribute("value") if t.count() else ""
    def post(self, path, data=None, files=None, headers=None):
        data = dict(data or {})
        data.setdefault("_token", self.csrf())
        h = {"X-Requested-With": "XMLHttpRequest"}
        h.update(headers or {})
        return self.ctx.request.post(BASE + path, form=data if files is None else None, multipart=files, headers=h, max_redirects=0)
    def getreq(self, path, headers=None):
        return self.ctx.request.get(BASE + path, headers=headers or {}, max_redirects=0)
    def form_fill(self, sel, value):
        self.page.locator(sel).fill(value)
    def submit(self, sel="form"):
        self.page.locator(sel).first.evaluate("f => f.requestSubmit ? f.requestSubmit() : f.submit()")
        self.page.wait_for_timeout(500)
    def jsclick(self, sel):
        self.page.locator(sel).first.evaluate("e => e.click()")
        self.page.wait_for_timeout(400)
    def jssubmit(self, sel="form"):
        self.page.locator(sel).first.evaluate("f => f.requestSubmit ? f.requestSubmit() : f.submit()")
        self.page.wait_for_timeout(500)
    def body_class(self):
        return self.page.locator("body").get_attribute("class") or ""

def wait_server():
    import urllib.request
    for _ in range(30):
        try:
            urllib.request.urlopen(BASE + "/login", timeout=2)
            return True
        except Exception as e:
            if "403" in str(e) or "302" in str(e): return True
            time.sleep(.3)
    return False

class Http:
    """Sesión HTTP independiente (urllib) para escenarios con dos usuarios."""
    def __init__(self, email=None, password=None):
        import http.cookiejar, urllib.request, urllib.parse, urllib.error
        self.cj = http.cookiejar.CookieJar()
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cj))
        self.email = email
        if email:
            self.login(email, password or "password123")
    def req(self, method, path, data=None, headers=None, follow=True):
        import urllib.request, urllib.parse, urllib.error
        url = BASE + path
        if data is not None and not isinstance(data, bytes):
            data = urllib.parse.urlencode(data, doseq=True).encode()
        r = urllib.request.Request(url, data=data, method=method, headers=headers or {})
        class NoRedir(urllib.request.HTTPRedirectHandler):
            def redirect_request(self, *a, **k): return None
        op = self.op if follow else urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cj), NoRedir)
        try:
            resp = op.open(r, timeout=90)
            return resp.status, resp.read().decode("utf-8", "replace"), resp.geturl()
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode("utf-8", "replace"), url
    def get(self, path, **k): return self.req("GET", path, **k)
    def csrf(self, html):
        m = re.search(r'name="_token"\s+value="([^"]+)"', html)
        if m: return m.group(1)
        m = re.search(r'data-csrf="([^"]+)"', html)
        return m.group(1) if m else ""
    def login(self, email=None, password=None):
        email = email or self.email
        s, html, _ = self.get("/login")
        tok = self.csrf(html)
        s, html, url = self.req("POST", "/login", {"_token": tok, "email": email, "password": password or "password123"})
        return "dashboard" in url or s == 200 and "/login" not in url
    def post(self, path, data=None, headers=None, follow=True):
        data = dict(data or {})
        if "_token" not in data:
            s, html, _ = self.get(path)
            data["_token"] = self.csrf(html)
        return self.req("POST", path, data, headers=headers, follow=follow)
