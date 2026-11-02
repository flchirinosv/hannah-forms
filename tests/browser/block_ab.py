#!/usr/bin/env python3
"""Bloque AB: listado paginado del panel (M2)."""

import json, re, sys, uuid
from pathlib import Path
from urllib.parse import urlparse, parse_qs
sys.path.insert(0, "tests/browser")
import vf

R = vf.R()
suf = uuid.uuid4().hex[:8]
AB = f"ab{suf}@bt.local"
EMPTY = f"abempty{suf}@bt.local"
PREFIX = f"[BT] AB {suf}"


def sq(v):
    return "'" + str(v).replace("\\", "\\\\").replace("'", "''") + "'"


def make_user(email, name):
    vf.mysql(
        "INSERT INTO users (role_id,name,email,password_hash,is_active,password_changed_at) "
        "SELECT 2, " + sq(name) + ", " + sq(email) + ", password_hash, 1, password_changed_at "
        "FROM users WHERE id=1"
    )
    return int(vf.db1("SELECT id FROM users WHERE email=" + sq(email))[0])


def make_form(uid, title, slug, status, order):
    vf.mysql(
        "INSERT INTO forms (user_id,title,description,slug,status,settings,created_at,updated_at) VALUES "
        f"({uid},{sq(title)},'',{sq(slug)},{sq(status)},NULL,"
        f"'2026-10-01 12:00:{order:02d}','2026-10-01 12:00:{order:02d}')"
    )
    return int(vf.db1("SELECT id FROM forms WHERE slug=" + sq(slug))[0])


def add_submissions(fid, n):
    for i in range(n):
        tok = uuid.uuid4().hex
        vf.mysql(
            "INSERT INTO submissions (form_id,ip_address,user_agent,public_token,finished_at) VALUES "
            f"({fid},'127.0.0.1','BT AB',{sq(tok)},'2026-10-02 10:{i:02d}:00')"
        )


def cards(page):
    return page.locator("#dashGrid .app-dash-card-col")


def titles(page):
    return page.locator("#dashGrid .app-dash-card .card-title").all_inner_texts()


def html_titles(html):
    return [re.sub(r"<[^>]+>", "", x).strip()
            for x in re.findall(r'<h4 class="h6 fw-bold card-title mb-1">(.*?)</h4>', html, re.S)]

def qs(url):
    return parse_qs(urlparse(url).query)


def stat_values(page):
    return [int(x.replace(",", "")) for x in page.locator(".app-stat-value").all_inner_texts()]



names = [
    "norte", "sur", "Ábaco", "abeja", "árbol", "barco", "delfín", "Escuela",
    "faro", "Gato", "hielo", "Isla", "Casa", "jardín", "Koala", "luna",
    "mapa", "ñandú", "Océano", "pájaro", "queso", "Río", "tren", "uva",
    "Valle", "wifi", "xilófono", "Yate", "rio", "Zorro",
]
statuses = ["published"] * 12 + ["draft"] * 10 + ["in_review"] * 4 + ["approved"] * 4

try:
    vf.mysql("DELETE FROM users WHERE email=" + sq(AB))
    vf.mysql("DELETE FROM users WHERE email=" + sq(EMPTY))
    uid = make_user(AB, "AB User " + suf)
    euid = make_user(EMPTY, "AB Empty " + suf)

    expected = []
    ids = {}
    for i, (name, status) in enumerate(zip(names, statuses)):
        title = f"{PREFIX} {name}"
        fid = make_form(uid, title, f"bt-ab-{suf}-{i:02d}", status, i)
        ids[i] = fid
        expected.append(title)

    add_submissions(ids[5], 7)   # barco
    add_submissions(ids[6], 5)   # delfín
    add_submissions(ids[7], 3)   # Escuela

    empty_fid = make_form(euid, f"[BT] ABEMPTY {suf}", f"bt-abempty-{suf}", "draft", 0)

    eh = vf.Http(EMPTY)
    tok = eh.csrf(eh.get("/dashboard")[1])
    eh.post(f"/forms/{empty_fid}/delete", {"_token": tok})

    expected_alpha = [r[0] for r in vf.mysql(
        f"SELECT title FROM forms WHERE user_id={uid} ORDER BY title ASC, id ASC"
    )]

    with vf.Browser() as B:
        u = B.session(R, AB)
        with u:
            p = u.page

            u.goto("/dashboard")
            txt = u.text()
            R.check("AB1", cards(p).count() == 12 and "30 formularios · Página 1 de 3" in txt,
                    f"tarjetas={cards(p).count()} pager={'30 formularios · Página 1 de 3' in txt}")

            t1 = titles(p)
            p.locator(".app-pager a[rel=next]").evaluate("e => e.click()")
            p.wait_for_timeout(500)
            t2 = titles(p)
            R.check("AB2", "page=2" in p.url and "Página 2 de 3" in u.text()
                    and cards(p).count() == 12 and t1 != t2,
                    f"url={p.url} tarjetas={cards(p).count()} cambian={t1 != t2}")

            u.goto("/dashboard")
            p.fill("#dashSearch", "norte")
            p.wait_for_timeout(900)
            R.check("AB3", "q=norte" in p.url and cards(p).count() == 1
                    and "norte" in p.locator("#dashSearch").input_value(),
                    f"url={p.url} tarjetas={cards(p).count()} valor={p.locator('#dashSearch').input_value()}")

            p.fill("#dashSearch", "sur")
            p.wait_for_timeout(900)
            R.check("AB4", "q=sur" in p.url and "q=norte" not in p.url
                    and cards(p).count() == 1,
                    f"url={p.url} tarjetas={cards(p).count()}")

            u.goto("/dashboard?q=norte")
            before = p.url
            p.fill("#dashSearch", "norte ")
            p.wait_for_timeout(900)
            R.check("AB5", p.url == before and cards(p).count() == 1,
                    f"antes={before} despues={p.url}")

            u.goto("/dashboard")
            p.get_by_role("link", name="Borradores", exact=True).evaluate("e => e.click()")
            p.wait_for_timeout(500)
            all_draft = all("Borrador" in c for c in p.locator("#dashGrid .app-dash-card").all_inner_texts())
            R.check("AB6", "estado=draft" in p.url and cards(p).count() == 10
                    and p.locator(".app-dash-filters a.active").inner_text() == "Borradores"
                    and all_draft,
                    f"url={p.url} tarjetas={cards(p).count()} draft={all_draft}")

            p.fill("#dashSearch", "casa")
            p.wait_for_timeout(900)
            R.check("AB7", "estado=draft" in p.url and "q=casa" in p.url and cards(p).count() == 1,
                    f"url={p.url} tarjetas={cards(p).count()}")

            p.select_option("#dashSort", "alpha")
            p.wait_for_timeout(600)
            q = qs(p.url)
            R.check("AB8", q.get("q") == ["casa"] and q.get("estado") == ["draft"]
                    and q.get("orden", [])[-1] == "alpha" and p.locator("#dashSort").input_value() == "alpha",
                    f"url={p.url}")

            filtrada = p.url
            uh = vf.Http(AB)
            _, h11, _ = uh.get(filtrada.replace(vf.BASE, ""))
            h11_titles = [re.sub(r"<[^>]+>", "", x).strip()
                          for x in re.findall(r'<h4 class="h6 fw-bold card-title mb-1">(.*?)</h4>', h11, re.S)]
            R.check("AB11", titles(p) == h11_titles and len(h11_titles) == 1,
                    f"url={filtrada} iguales={titles(p) == h11_titles}")

            u.goto("/dashboard")
            p.get_by_role("link", name="Borradores", exact=True).evaluate("e => e.click()")
            p.wait_for_timeout(400)
            p.go_back()
            p.wait_for_timeout(500)
            R.check("AB12", "estado" not in qs(p.url) and cards(p).count() == 12
                    and p.locator(".app-dash-filters a.active").count() == 0,
                    f"url={p.url} tarjetas={cards(p).count()} activos={p.locator('.app-dash-filters a.active').count()}")

            u.goto("/dashboard?estado=inventado")
            R.check("AB13", cards(p).count() == 12 and "30 formularios · Página 1 de 3" in u.text()
                    and p.locator(".app-dash-filters a.active").count() == 0,
                    f"tarjetas={cards(p).count()}")

            u.goto("/dashboard?page=999")
            R.check("AB14", "Página 3 de 3" in u.text() and cards(p).count() == 6,
                    f"tarjetas={cards(p).count()} url={p.url}")

            u.goto("/dashboard?estado=closed")
            et = u.text()
            R.check("AB15", "Sin resultados" in et and "Quitar el filtro" in et
                    and "Aún no tienes formularios" not in et and cards(p).count() == 0,
                    f"sin={('Sin resultados' in et)} quitar={('Quitar el filtro' in et)}")

            u.goto("/dashboard?estado=draft")
            R.check("AB17", stat_values(p) == [30, 12, 10, 15] and cards(p).count() == 10,
                    f"stats={stat_values(p)} tarjetas={cards(p).count()}")

            u.logout()
            u.login(EMPTY)
            u.goto("/dashboard")
            et = u.text()
            R.check("AB16", "Aún no tienes formularios" in et
                    and "Crear formulario" in et
                    and u.page.locator(".app-dash-filters").count() == 0
                    and u.page.locator("#dashGrid").count() == 0,
                    f"texto={et[:120]!r} filtros={u.page.locator('.app-dash-filters').count()}")
            u.logout()
            u.login(AB)

            u.goto("/dashboard")
            p.evaluate("() => document.activeElement && document.activeElement.blur()")
            p.keyboard.press("/")
            p.wait_for_timeout(200)
            focused = p.evaluate("() => document.activeElement && document.activeElement.id")
            R.check("AB19", focused == "dashSearch", f"foco={focused}")

            got = []
            for page_no in (1, 2, 3):
                u.goto(f"/dashboard?orden=alpha&page={page_no}")
                got.extend(titles(p))
            R.check("AB9", got == expected_alpha, f"got={got[:5]} esperado={expected_alpha[:5]} iguales={got == expected_alpha}")

            u.goto("/dashboard?orden=subs")
            first = p.locator("#dashGrid .app-dash-card").first
            sub = first.locator(".app-sub-count").inner_text().strip()
            R.check("AB10", "barco" in first.inner_text() and sub == "7"
                    and stat_values(p)[3] == 15,
                    f"primera={first.locator('.card-title').inner_text()!r} respuestas={sub} stats={stat_values(p)}")

            u.goto("/dashboard")
            h_search = p.locator(".app-dash-search .form-control").bounding_box()["height"]
            h_sort = p.locator("#dashSort").bounding_box()["height"]
            h_btn = p.locator(".app-dash-filters .btn").first.bounding_box()["height"]
            css = Path("public/assets/css/app.css").read_text()
            no_rescue = ".form-select.app-dash-sort { max-width: 200px; }" in css
            R.na("AB24", "Lightpanda no calcula layout fiable: bounding boxes "
                         f"{h_search}/{h_sort}/{h_btn} y getComputedStyle vacío; "
                         "la regla CSS está verificada (form-select-sm compartido, "
                         f"sin rescate de altura: {no_rescue})")

        # AB18 — el mismo panel sin JavaScript. El CDP de este entorno no
        # permite desactivar la ejecución de scripts, así que se comprueba el
        # camino nativo: el formulario GET, el botón de <noscript>, los enlaces
        # de estado y el paginador responden sin que intervenga dashboard.js.
        uh = vf.Http(AB)
        _, h18, _ = uh.get("/dashboard")
        has_noscript = bool(re.search(r'<noscript><button type="submit"[^>]*>Aplicar</button></noscript>', h18))
        _, hq, _ = uh.get("/dashboard?q=norte")
        ok_search = len(re.findall(r'class="[^"]*app-dash-card-col[^"]*"', hq)) == 1
        _, ha, _ = uh.get("/dashboard?orden=alpha")
        ok_sort = html_titles(ha)[0] == expected_alpha[0]
        _, hd, _ = uh.get("/dashboard?estado=draft")
        ok_filter = (len(re.findall(r'class="[^"]*app-dash-card-col[^"]*"', hd)) == 10
                     and hd.count("app-status-draft") == 10)
        _, hp, _ = uh.get("/dashboard?page=2")
        ok_page = ("Página 2 de 3" in hp
                   and len(re.findall(r'class="[^"]*app-dash-card-col[^"]*"', hp)) == 12)
        R.check("AB18", has_noscript and ok_search and ok_sort and ok_filter and ok_page,
                f"noscript={has_noscript} buscar={ok_search} ordenar={ok_sort} "
                f"filtrar={ok_filter} paginar={ok_page}")

        # AB20–AB22 — la lista global de administración.
        ah = vf.Http("admin@demo.com")
        s, html, _ = ah.get("/admin/forms")
        cards1 = re.findall(r'class="[^"]*app-form-card[^"]*"', html)
        nxt = re.search(r'href="([^"]+)"[^>]*data-vf-page="2"', html) or re.search(r'data-vf-page="2"[^>]*href="([^"]+)"', html)
        s2, html2, _ = ah.get(nxt.group(1).replace("&amp;", "&")) if nxt else (0, "", "")
        cards2 = re.findall(r'class="[^"]*app-form-card[^"]*"', html2)
        R.check("AB20", s == 200 and len(cards1) == 20 and nxt and s2 == 200
                and len(cards2) == 20 and html != html2,
                f"status={s} tarjetas={len(cards1)} next={bool(nxt)} p2={len(cards2)}")

        s, html, _ = ah.get("/admin/forms?page=999")
        m = re.search(r"Página (\d+) de (\d+)", html)
        cards_last = re.findall(r'class="[^"]*app-form-card[^"]*"', html)
        R.check("AB21", s == 200 and m and m.group(1) == m.group(2) and len(cards_last) > 0,
                f"status={s} pagina={m.groups() if m else None} tarjetas={len(cards_last)}")

        s22, h22, _ = ah.get("/admin/forms")
        anchor = re.search(r'<a[^>]*data-vf-page="2"[^>]*>', h22)
        s23, h23, _ = ah.get("/admin/forms?page=2")
        R.check("AB22", s22 == 200 and anchor is not None and s23 == 200
                and "Página 2 de" in h23
                and len(re.findall(r'class="[^"]*app-form-card[^"]*"', h23)) == 20,
                f"enlace={anchor.group(0)[:80] if anchor else None} pagina2={s23}")

    # AB23 — la lista lleva LIMIT y el agregado de respuestas va sobre los ids
    # de la página (no sobre todo el alcance).
    src = Path("app/Models/Form.php").read_text()
    has_limit = "ORDER BY $orden LIMIT $porPagina OFFSET" in src
    has_page_agg = "WHERE s.form_id IN ($phCount)" in src
    page_ids = [ids[i] for i in range(12)]
    explain = vf.mysql(
        "EXPLAIN SELECT f.*, u.name AS owner_name, COALESCE(sc.c,0) AS submissions_count, "
        "COALESCE(sc.last_at,'') AS last_submission_at FROM forms f JOIN users u ON u.id=f.user_id "
        "LEFT JOIN (SELECT s.form_id, COUNT(*) AS c, MAX(s.finished_at) AS last_at FROM submissions s "
        f"WHERE s.form_id IN ({','.join(map(str, page_ids))}) GROUP BY s.form_id) sc ON sc.form_id=f.id "
        f"WHERE f.id IN ({','.join(map(str, page_ids))})"
    )
    R.check("AB23", has_limit and has_page_agg and len(explain) >= 2,
            f"LIMIT={has_limit} agregado_pagina={has_page_agg} explain_filas={len(explain)}")

finally:
    R.report()
    print(json.dumps(R.report(), ensure_ascii=False))
