#!/usr/bin/env python3
"""Bloque O: errores, casos límite, teclado y móvil."""

import re, subprocess, uuid, html as htmllib
from vf import R, Http, Browser, BASE, mysql, db1

r = R()
suf = uuid.uuid4().hex[:8]
prefix = f"bt_o_{suf}"
email_user = f"{prefix}_user@bt-o.test"
email_admin2 = f"{prefix}_admin2@bt-o.test"
user_id = None
form_ids = []

def esc(v):
    return str(v).replace("\\", "\\\\").replace("'", "''")

def one(sql):
    row = db1(sql)
    return row[0] if row else None

def role_id(name):
    v = one(f"SELECT id FROM roles WHERE name='{esc(name)}' LIMIT 1")
    return int(v) if v else None

def admin_session():
    h = Http("admin@demo.com")
    s, page, _ = h.get("/admin")
    return h, h.csrf(page)

def create_user(h, tok, email, rid, name="BT O"):
    s, b, u = h.post("/admin/users/create", {
        "_token": tok, "name": name, "email": email,
        "password": "password123", "password_confirm": "password123", "role_id": rid,
    })
    v = one(f"SELECT id FROM users WHERE email='{esc(email)}' LIMIT 1")
    return int(v) if v else None

def create_form(h, tok, title):
    s, b, u = h.post("/forms", {"_token": tok, "title": title, "description": "fixture O"})
    v = one(f"SELECT id FROM forms WHERE title='{esc(title)}' ORDER BY id DESC LIMIT 1")
    fid = int(v) if v else None
    if fid:
        form_ids.append(fid)
    return fid

def visible_text(page_html):
    m = re.search(r"<body[^>]*>(.*)</body>", page_html, re.S | re.I)
    body = m.group(1) if m else page_html
    txt = re.sub(r"<[^>]+>", " ", body)
    return re.sub(r"\s+", " ", htmllib.unescape(txt)).strip()

def error_shape(page_html, expected_code):
    body = visible_text(page_html)
    h2 = re.search(r"<h2[^>]*>(.*?)</h2>", page_html, re.S | re.I)
    motivo = re.sub(r"<[^>]+>", "", h2.group(1)).strip() if h2 else ""
    motivo = re.sub(r"\s+", " ", htmllib.unescape(motivo))
    icon = re.search(r'<i class="fa-solid [^"]+"', page_html) is not None
    help_p = re.search(r'<p class="text-muted[^"]*">\s*([^<]{15,})', page_html) is not None
    no_code_error = f"{expected_code} Error" not in page_html and "Fatal error" not in page_html and "Stack trace" not in page_html
    once = bool(motivo) and body.count(motivo) == 1
    return icon and help_p and no_code_error and once, f"icono={icon} motivo={motivo!r} repetido={body.count(motivo) if motivo else '?'} ayuda={help_p} sin_traza={no_code_error}"

def huge_post(path, size=9_000_000):
    h = Http()
    body = b"x=" + b"x" * size
    return h.req("POST", path, body, headers={"Content-Type": "application/x-www-form-urlencoded"}, follow=False)

try:
    f_simple = 263816
    slug = one(f"SELECT slug FROM forms WHERE id={f_simple}")

    # O1 — forma común de las ocho páginas de error.
    page_status = {}
    err = Http("user@demo.com")
    st, body, _ = err.get("/admin")
    page_status[403] = (st, body)
    st, body, _ = Http().get("/no-existe")
    page_status[404] = (st, body)
    st, body, _ = Http().get("/logout")
    page_status[405] = (st, body)
    # 413 de la APP, no el de nginx: se levanta un PHP de pruebas con un
    # `post_max_size` pequeño, porque el proxy corta antes el cuerpo de 9 MB.
    srv = subprocess.Popen(
        ["php", "-d", "post_max_size=1M", "-d", "upload_max_filesize=1M",
         "-S", "127.0.0.1:10003", "-t", "public", "public/index.php"],
        cwd="/root/workspace/php/vanilla-forms", stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )
    try:
        import socket, time, urllib.request, urllib.error
        for _ in range(40):
            try:
                socket.create_connection(("127.0.0.1", 10003), timeout=.2).close()
                break
            except OSError:
                time.sleep(.1)
        req = urllib.request.Request(
            f"http://127.0.0.1:10003/f/{slug}", data=b"x=" + b"x" * (2 * 1024 * 1024),
            headers={"Content-Type": "application/x-www-form-urlencoded"}, method="POST")
        try:
            resp = urllib.request.urlopen(req, timeout=20)
            st, body = resp.status, resp.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as e:
            st, body = e.code, e.read().decode("utf-8", "replace")
    finally:
        srv.terminate()
        try: srv.wait(timeout=5)
        except Exception: srv.kill()
    page_status[413] = (st, body)
    anon = Http()
    s0, login_html, _ = anon.get("/login")
    st, body, _ = anon.req("POST", "/login", {"_token": "caducado", "email": "o4@bt-o.test", "password": "x"}, follow=False)
    page_status[419] = (st, body)

    # 500 real: un trigger temporal hace fallar el INSERT de submissions.
    fill = Http()
    sf, fill_html, _ = fill.get(f"/f/{slug}")
    ftok = fill.csrf(fill_html)
    fill_data = {"_token": ftok, "field_277208": "BT O", "field_277209": "o@bt-o.test", "field_277210[]": "Sí"}
    mysql("DROP TRIGGER IF EXISTS bt_o_forced_500")
    mysql("CREATE TRIGGER bt_o_forced_500 BEFORE INSERT ON submissions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='bt o forced failure'")
    try:
        st, body, _ = fill.req("POST", f"/f/{slug}", fill_data, follow=False)
        page_status[500] = (st, body)
    finally:
        mysql("DROP TRIGGER IF EXISTS bt_o_forced_500")

    # 503 no tiene una ruta que lo provoque: se renderiza la vista real por CLI.
    cli = subprocess.run([
        "php", "-r",
        'require "config/bootstrap.php"; http_response_code(503); '
        'App\\Core\\View::render("errors/503", ["message" => "Servicio no disponible"], "error_layout");'
    ], cwd="/root/workspace/php/vanilla-forms", capture_output=True, text=True)
    page_status[503] = (503, cli.stdout)

    # 429: 10 envíos permitidos, el 11.º bloquea antes de validar.
    mysql(f"DELETE FROM rate_limits WHERE scope='submit:{f_simple}'")
    st429 = None
    body429 = ""
    for i in range(11):
        st429, body429, _ = fill.req("POST", f"/f/{slug}", fill_data, follow=False)
    mysql(f"DELETE FROM rate_limits WHERE scope='submit:{f_simple}'")
    page_status[429] = (st429, body429)

    shapes = {}
    for code in (403, 404, 405, 413, 419, 429, 500, 503):
        st, body = page_status[code]
        ok, det = error_shape(body, code)
        shapes[code] = (st == code and ok, f"{st}/{code} {det}")
    r.check("O1", all(v[0] for v in shapes.values()), "; ".join(f"{k}:{v[1]}" for k, v in shapes.items()))

    # O2 — el 500 real no enseña traza ni SQL.
    st500, b500 = page_status[500]
    r.check("O2", st500 == 500 and "Fatal error" not in b500 and "Stack trace" not in b500 and
            "SQLSTATE" not in b500 and "bt o forced failure" not in b500 and
            bool(re.search(r"<h2[^>]*>\s*[^<]{10,}", b500)),
            f"status={st500} texto={visible_text(b500)[:140]!r}")

    # O3 — cuerpo enorme: 413 con motivo y ayuda.
    st413, b413 = page_status[413]
    r.check("O3", st413 == 413 and "tamaño máximo" in visible_text(b413).lower(),
            f"status={st413} texto={visible_text(b413)[:160]!r}")

    # O4 — token caducado: 419 «sesión expirada».
    st419, b419 = page_status[419]
    r.check("O4", st419 == 419 and "sesión ha expirado" in visible_text(b419).lower(),
            f"status={st419} texto={visible_text(b419)[:160]!r}")

    # Fixtures O5/O6.
    adm, atok = admin_session()
    rid_user = role_id("user")
    user_id = create_user(adm, atok, email_user, rid_user, "BT O User")
    fresh = Http(email_user)
    sd, dh, _ = fresh.get("/dashboard")
    dtok = fresh.csrf(dh)
    fid_fresh = create_form(fresh, dtok, f"[BT] O rutas {suf}")
    title_long = ("[BT] O " + ("nombre larguísimo " * 12) + suf)[:190]
    fid_long = create_form(adm, atok, title_long)

    # O5 — rutas del panel con una cuenta recién creada: ninguna 500.
    paths = ["/dashboard", "/account/password", f"/forms/{fid_fresh}", f"/forms/{fid_fresh}/access",
             f"/forms/{fid_fresh}/builder", f"/forms/{fid_fresh}/responses", f"/forms/{fid_fresh}/analytics",
             f"/forms/{fid_fresh}/analytics/rows", f"/forms/{fid_fresh}/print", f"/forms/{fid_fresh}/preview?draft=1",
             f"/forms/{fid_fresh}/participants", f"/forms/{fid_fresh}/qr.svg",
             f"/forms/{fid_fresh}/export-structure.json", f"/forms/{fid_fresh}/export.csv",
             f"/forms/{fid_fresh}/export.json", "/review", "/admin"]
    statuses = []
    for p in paths:
        st, body, _ = fresh.get(p)
        statuses.append((p, st, body))
    bad = [(p, st) for p, st, b in statuses if st == 500 or re.search(r"Fatal error|Uncaught|Stack trace", b, re.I)]
    r.check("O5", not bad and all(st in (200, 302, 403, 404) for _, st, _ in statuses),
            f"rutas={len(paths)} malas={bad[:4]} estados={[st for _,st,_ in statuses]}")

    # O6 — nombre de 200 caracteres sin desbordar cabecera ni botón.
    o6 = False
    det6 = ""
    try:
        with Browser() as b:
            with __import__("vf").VF(r, email="admin@demo.com", browser=b.browser) as s:
                s.goto(f"/forms/{fid_long}", wait=600)
                d = s.page.evaluate("""() => {
                    const h = document.querySelector('.app-page-head');
                    const a = document.querySelector('.app-head-actions');
                    const t = h ? h.querySelector('h2') : null;
                    if (!h || !a || !t) return {ok:false, why:'faltan elementos'};
                    const ra = a.getBoundingClientRect();
                    const ts = getComputedStyle(t);
                    return {ok: ra.right <= innerWidth + 1 && ra.left >= -1 &&
                                document.documentElement.scrollWidth <= innerWidth + 2 &&
                                t.innerText.trim().length >= 180 && ts.whiteSpace !== 'nowrap',
                            actionRight: ra.right, inner: innerWidth,
                            titleLen: t.innerText.trim().length,
                            whiteSpace: ts.whiteSpace, overflowWrap: ts.overflowWrap,
                            scroll: document.documentElement.scrollWidth};
                }""")
                o6 = bool(d.get("ok")); det6 = str(d)
    except Exception as e:
        det6 = f"error lightpanda: {e}"
    r.check("O6", o6, det6)

    # O7 — filtros de la analítica a 375 px: envuelven, no desbordan.
    o7 = False
    det7 = ""
    try:
        with Browser() as b:
            ctx = b.browser.new_context(viewport={"width": 375, "height": 812})
            p = ctx.new_page()
            p.goto(BASE + "/login")
            p.fill("input[name=email]", "admin@demo.com")
            p.fill("input[name=password]", "password123")
            p.click("button[type=submit]")
            p.wait_for_timeout(600)
            p.goto(BASE + f"/forms/{f_simple}/analytics")
            p.wait_for_timeout(600)
            d = p.evaluate("""() => {
                const f = document.querySelector('.analytics-filters');
                if (!f) return {ok:false, why:'sin filtros'};
                const items = [...f.children].map(e => e.getBoundingClientRect());
                const overflow = items.some(r => r.right > innerWidth + 1 || r.left < -1);
                const rows = new Set(items.map(r => Math.round(r.top))).size;
                return {ok: !overflow && document.documentElement.scrollWidth <= innerWidth + 2,
                        overflow, rows, inner: innerWidth, scroll: document.documentElement.scrollWidth};
            }""")
            o7 = bool(d.get("ok")); det7 = str(d)
            ctx.close()
    except Exception as e:
        det7 = f"error lightpanda: {e}"
    r.check("O7", o7, det7)

    # O8 — rango sin resultados con respuestas existentes.
    s8, b8, _ = adm.get(f"/forms/{f_simple}/analytics?from=2030-01-01&to=2030-01-02")
    t8 = visible_text(b8)
    r.check("O8", s8 == 200 and "Sin respuestas para este filtro" in t8 and "Volver a compartir" not in t8,
            f"status={s8} texto_sin_resultados={'Sin respuestas para este filtro' in t8}")

    # O9 — teclado: foco visible y avance; imágenes con alt.
    o9 = False
    det9 = ""
    try:
        with Browser() as b:
            with __import__("vf").VF(r, email="admin@demo.com", browser=b.browser) as s:
                s.goto("/admin", wait=600)
                d = s.page.evaluate("""() => {
                    const els = [...document.querySelectorAll('a[href],button,input,select,textarea,[tabindex]')]
                        .filter(e => { const r = e.getBoundingClientRect(); return r.width > 0 && r.height > 0 && !e.disabled; });
                    let focusables = 0;
                    for (const e of els) { e.focus(); if (document.activeElement === e) focusables++; }
                    const alts = [...document.querySelectorAll('img')].every(i => i.hasAttribute('alt'));
                    const css = true;
                    return {ok: focusables >= 6 && alts && css, focusables, alts, css};
                }""")
                _, css_txt, _ = Http().get("/assets/css/app.css")
                css_rule = bool(re.search(r":focus-visible\s*\{[^}]*outline:\s*2px\s+solid", css_txt))
                o9 = bool(d.get("ok")) and css_rule
                det9 = str(d) + f" css_global={css_rule} (Lightpanda no mueve el foco con Tab; foco programático + regla CSS)"
    except Exception as e:
        det9 = f"error lightpanda: {e}"
    r.check("O9", o9, det9)

    # O10 — 375 px por panel, ficha, builder, respuestas y analítica.
    o10 = False
    det10 = ""
    try:
        with Browser() as b:
            ctx = b.browser.new_context(viewport={"width": 375, "height": 812})
            p = ctx.new_page()
            p.goto(BASE + "/login")
            p.fill("input[name=email]", "admin@demo.com")
            p.fill("input[name=password]", "password123")
            p.click("button[type=submit]")
            p.wait_for_timeout(600)
            checks = []
            for path in ["/dashboard", "/admin", f"/forms/{f_simple}", f"/forms/{f_simple}/builder",
                         f"/forms/{f_simple}/responses", f"/forms/{f_simple}/analytics"]:
                p.goto(BASE + path); p.wait_for_timeout(600)
                d = p.evaluate("""() => {
                    const a = document.querySelector('.app-head-actions');
                    const ra = a ? a.getBoundingClientRect() : null;
                    return {sw: document.documentElement.scrollWidth, iw: innerWidth,
                            actions: ra ? {left:ra.left,right:ra.right} : null};
                }""")
                ok = d["sw"] <= d["iw"] + 2 and (not d["actions"] or (d["actions"]["left"] >= -1 and d["actions"]["right"] <= d["iw"] + 1))
                checks.append((path, ok, d))
            o10 = all(x[1] for x in checks)
            det10 = "; ".join(f"{p}:{'ok' if ok else 'DESBORDA'}({d['sw']}/{d['iw']})" for p, ok, d in checks)
            ctx.close()
    except Exception as e:
        det10 = f"error lightpanda: {e}"
    r.check("O10", o10, det10)

finally:
    try:
        mysql("DROP TRIGGER IF EXISTS bt_o_forced_500")
        mysql(f"DELETE FROM rate_limits WHERE scope='submit:{f_simple}'")
        if form_ids:
            mysql("DELETE FROM forms WHERE id IN (" + ",".join(str(int(x)) for x in set(form_ids)) + ")")
        mysql(f"DELETE FROM forms WHERE title LIKE '[BT] O %{esc(suf)}%'")
        if user_id:
            mysql(f"DELETE FROM users WHERE id={int(user_id)}")
        mysql(f"DELETE FROM users WHERE email LIKE '{esc(prefix)}%'")
    except Exception as e:
        print("cleanup:", e)

counts = r.report()
print("BLOQUE O:", counts)
