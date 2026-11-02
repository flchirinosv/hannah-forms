#!/usr/bin/env python3
"""Bloque Q: limpieza visual del constructor y del fill."""

import json, re, subprocess, sys, uuid
sys.path.insert(0, "tests/browser")
import vf

R = vf.R()
ah = vf.Http("admin@demo.com")
suf = uuid.uuid4().hex[:8]
made = []

def mysql_escape(s):
    return s.replace("\\", "\\\\").replace("'", "\\'")

def new_form(title):
    t = ah.csrf(ah.get("/dashboard")[1])
    r = ah.post("/forms", {"_token": t, "title": title, "description": ""})
    m = re.search(r"/forms/(\d+)/builder", r[2])
    if not m:
        raise RuntimeError("no se creó el formulario " + title)
    fid = int(m.group(1)); made.append(fid)
    return fid

def item(uid, typ, label="", parent=None, options=None, help_text="", validations=None, show_header=True):
    return {"_uid": uid, "type": typ, "label": label, "placeholder": "", "help_text": help_text,
            "is_required": False, "is_pii": False, "show_header": show_header,
            "options": options or [], "validations": validations or {}, "parent_id": parent, "logic": None}

def save_struct(v, fid, title, fields):
    v.goto(f"/forms/{fid}/builder", wait=700)
    csrf = v.page.locator("#builderApp").get_attribute("data-csrf")
    payload = {"title": title, "description": "", "status": "draft", "settings": {}, "fields": fields}
    r = v.ctx.request.post(vf.BASE + f"/forms/{fid}/builder/save",
                           data=json.dumps(payload),
                           headers={"Content-Type": "application/json", "X-CSRF-Token": csrf})
    if r.status >= 400:
        raise RuntimeError(f"save {fid}: HTTP {r.status} {r.text()[:300]}")
    v.page.wait_for_timeout(250)

def publish(fid):
    h = ah.get(f"/forms/{fid}")[1]
    m = re.search(r'name="_token" value="([^"]+)"', h)
    tok = m.group(1) if m else ah.csrf(h)
    r = ah.post(f"/forms/{fid}/publish", {"_token": tok})
    return r[0]

def slug_of(fid):
    return vf.db1(f"SELECT slug FROM forms WHERE id={fid}")[0]

try:
    vf.mysql("DELETE FROM forms WHERE title LIKE '[BT] Q %'")
    main_title = f"[BT] Q main {suf}"
    pages_title = f"[BT] Q pages {suf}"
    desc_title = f"[BT] Q desc {suf}"
    fid_main = new_form(main_title)
    fid_pages = new_form(pages_title)
    fid_desc = new_form(desc_title)

    main_fields = [
        item("sec", "section", "Sección Q"),
        item("sec_child", "text", "Pregunta dentro", parent="sec"),
        item("empty_sec", "section", "Sección vacía"),
        item("nps", "nps", "¿Nos recomiendas?"),
        item("star", "star", "Estrellas Q", validations={"max": 5}),
        item("scale", "scale", "Escala Q", validations={"max": 5}),
        item("rank", "ranking", "Ranking Q", options=[{"value": "Uno"}, {"value": "Dos"}, {"value": "Tres"}]),
        item("matrix", "matrix", "Matriz Q", options=[
            {"value": "Fila A", "group": "rows"}, {"value": "Fila B", "group": "rows"},
            {"value": "Col 1", "group": "cols"}, {"value": "Col 2", "group": "cols"}]),
    ]
    pages_fields = [
        item("p1", "page", "Inicio"), item("t1", "text", "Campo uno"),
        item("p2", "page", ""), item("t2", "text", "Campo dos"),
        item("p3", "page", "Final"), item("t3", "text", "Campo tres"),
    ]
    desc_fields = [
        item("p1", "page", "Inicio"), item("t1", "text", "Campo uno"),
        item("p2", "page", "", help_text="Descripción del paso 2"), item("t2", "text", "Campo dos"),
        item("p3", "page", "Final"), item("t3", "text", "Campo tres"),
    ]

    with vf.VF(R) as v:
        save_struct(v, fid_main, main_title, main_fields)
        save_struct(v, fid_pages, pages_title, pages_fields)
        save_struct(v, fid_desc, desc_title, desc_fields)

        # Q1 — paleta: Sección y Página son dos tarjetas separadas.
        v.goto(f"/forms/{fid_main}/builder", wait=700)
        v.page.locator("#btnAddQuestion").evaluate("e => e.click()")
        v.page.wait_for_timeout(300)
        grid = v.page.locator("#addFieldModal .app-type-grid[data-vf-structural-part]")
        d1 = grid.evaluate("""g => ({n: g.querySelectorAll('.app-type-tile').length,
            types: [...g.querySelectorAll('.app-type-tile')].map(e => e.dataset.type),
            gap: getComputedStyle(g).gap})""")
        css = vf.urllib.request.urlopen(vf.BASE + "/assets/css/app.css", timeout=10).read().decode()
        gap_css = re.search(r"\.app-type-grid\s*\{([^}]*)\}", css)
        has_gap = bool(gap_css and "gap:" in gap_css.group(1) and re.search(r"gap:\s*(?!0)[^;]+", gap_css.group(1)))
        R.check("Q1", d1["n"] == 2 and set(d1["types"]) == {"section", "page"} and (d1["gap"] not in ("", "normal", "0px") or has_gap),
                str(d1) + f" css_gap={bool(has_gap)}")
        v.page.locator("#addFieldModal .btn-close").first.evaluate("e => e.click()")
        v.page.wait_for_timeout(200)

        # Q2 — sección con hija, sin línea vertical.
        v.goto(f"/forms/{fid_main}/builder", wait=700)
        secs = v.page.locator(".app-canvas-section")
        kids = secs.nth(0).locator(".app-section-children [data-uid]").count()
        sec_css = re.search(r"\.app-section-children\s*\{([^}]*)\}", css).group(1)
        R.check("Q2", secs.count() == 2 and kids == 1 and "border-left" not in sec_css and "background" in sec_css,
                f"secciones={secs.count()} hijas={kids} css={sec_css.strip()[:120]!r}")

        # Q3 — sección vacía: recuadro punteado con pista.
        d3 = secs.nth(1).locator(".app-section-children").evaluate("""e => ({
            children: e.children.length, hint: e.dataset.hint || '',
            border: getComputedStyle(e).borderStyle})""")
        empty_css = re.search(r"\.app-section-children:empty\s*\{([^}]*)\}", css)
        before_css = re.search(r"\.app-section-children:empty::before\s*\{([^}]*)\}", css)
        R.check("Q3", d3["children"] == 0 and d3["hint"] != "" and
                bool(empty_css and "dashed" in empty_css.group(1)) and
                bool(before_css and "attr(data-hint)" in before_css.group(1)),
                str(d3) + f" css_empty={bool(empty_css)} css_before={bool(before_css)}")

        # Q4 — NPS y estrellas con forma de botones/estrellas.
        body = v.page.locator("#canvasFields .app-field-body")
        nps_dots = body.locator(".nps-dot").count()
        stars = body.locator(".star-icon").count()
        R.check("Q4", nps_dots == 11 and stars >= 5, f"nps={nps_dots} estrellas={stars}")

        # Q5 — escala, ranking y matriz distinguibles y matriz contenida.
        scales = body.locator(".scale-dot").count()
        ranks = body.locator(".ranking-pos").count()
        mats = body.locator(".vf-matrix-preview .vf-matrix-table").count()
        mat_wrap_css = re.search(r"\.app-field-body \.vf-matrix-preview\s*\{([^}]*)\}", css)
        R.check("Q5", scales == 5 and ranks == 3 and mats == 1 and
                bool(mat_wrap_css and "overflow: hidden" in mat_wrap_css.group(1)),
                f"escala={scales} ranking={ranks} matriz={mats} css={mat_wrap_css.group(1).strip() if mat_wrap_css else ''!r}")

        # Q6 — preview en vivo: sección con título a la izquierda e icono a la derecha.
        v.goto(f"/forms/{fid_main}/preview?draft=1", wait=700)
        src = v.page.content()
        i_title = src.find("vf-section-title")
        i_icon = src.find("vf-section-icon")
        inner_css = re.search(r"\.vf-section-inner\s*\{([^}]*)\}", css)
        R.check("Q6", i_title >= 0 and i_icon > i_title and v.page.locator(".vf-section-title").first.inner_text() == "Sección Q"
                and bool(inner_css and "space-between" in inner_css.group(1)),
                f"título={i_title} icono={i_icon} css={inner_css.group(1).strip()[:90] if inner_css else ''!r}")

        # Q7 — preview de NPS, estrellas, matriz y ranking como el fill.
        q7 = {
            "nps": v.page.locator(".nps-option .scale-btn").count(),
            "star": v.page.locator(".star-option .star-btn").count(),
            "matrix": v.page.locator(".vf-matrix-cell").count(),
            "ranking": v.page.locator(".vf-ranking-input").count(),
        }
        R.check("Q7", q7["nps"] == 11 and q7["star"] == 5 and q7["matrix"] == 4 and q7["ranking"] == 3, str(q7))

        publish(fid_main)
        publish(fid_pages)
        publish(fid_desc)
        slug_main, slug_pages, slug_desc = slug_of(fid_main), slug_of(fid_pages), slug_of(fid_desc)

        # Q8 — salto sin etiqueta: no se inventa «Página N» y el pie sigue.
        v.goto(f"/f/{slug_pages}", wait=700)
        blocks = v.page.locator(".vf-page-block")
        b2 = blocks.nth(1)
        foot2 = b2.locator(".vf-page-footer").inner_text()
        R.check("Q8", blocks.count() == 3 and b2.locator(".vf-page-head").count() == 0
                and "Página 2" not in v.page.locator("body").inner_text()
                and "Paso 2 de 3" in foot2,
                f"bloques={blocks.count()} head2={b2.locator('.vf-page-head').count()} pie={foot2!r}")

        # Q9 — salto con descripción y sin etiqueta: cabecera con descripción, sin título inventado.
        v.goto(f"/f/{slug_desc}", wait=700)
        b2 = v.page.locator(".vf-page-block").nth(1)
        d9 = {
            "head": b2.locator(".vf-page-head").count(),
            "title": b2.locator(".vf-page-title").count(),
            "desc": b2.locator(".vf-page-desc").inner_text() if b2.locator(".vf-page-desc").count() else "",
        }
        R.check("Q9", d9["head"] == 1 and d9["title"] == 0 and "Descripción del paso 2" in d9["desc"], str(d9))

        # Q10 — sección publicada: hijos sin línea vertical y sin tinte doble.
        v.goto(f"/f/{slug_main}", wait=700)
        vf_children_css = re.search(r"\.vf-section-children\s*\{([^}]*)\}", css).group(1)
        q10 = {"sections": v.page.locator(".vf-section-block").count(),
               "children": v.page.locator(".vf-section-children [data-vf-field-id]").count()}
        R.check("Q10", q10["sections"] == 2 and q10["children"] == 1
                and "border-left" not in vf_children_css and "background" not in vf_children_css,
                str(q10) + f" css={vf_children_css.strip()[:120]!r}")

        # Q11 — NPS publicado: once botones cuadrados.
        nps_btns = v.page.locator(".nps-option .scale-btn")
        nps_css = re.search(r"\.nps-selector \.scale-btn\s*\{([^}]*)\}", css).group(1)
        R.check("Q11", nps_btns.count() == 11 and "display: inline-flex" in nps_css
                and "width: 40px" in nps_css and "height: 40px" in nps_css,
                f"botones={nps_btns.count()} css={nps_css.strip()[:120]!r}")

        # Q12 — 0012 limpia «Página N» pero respeta una etiqueta escrita a mano.
        old_title = f"[BT] Q old {suf}"
        fid_old = new_form(old_title)
        vf.mysql("INSERT INTO fields (form_id,type,label,placeholder,help_text,is_required,options,validations,position,parent_id,logic,is_pii,show_header) VALUES "
                 f"({fid_old},'page','Página 2','','',0,'[]','[]',1,NULL,NULL,0,1),"
                 f"({fid_old},'page','Primera página','','',0,'[]','[]',2,NULL,NULL,0,1),"
                 f"({fid_old},'page','','','Descripción conservada',0,'[]','[]',3,NULL,NULL,0,1)")
        subprocess.run(["php", "-r",
                        "require 'config/bootstrap.php'; "
                        "$m=require 'database/migrations/0012_page_label_placeholder.php'; "
                        "$m(App\\Core\\Database::getInstance(App\\Core\\Config::db()));"],
                       cwd=vf.ROOT, check=True, capture_output=True)
        rows = vf.mysql(f"SELECT label,help_text FROM fields WHERE form_id={fid_old} ORDER BY position")
        v.goto(f"/forms/{fid_old}/preview?draft=1", wait=700)
        txt = v.page.content()
        R.check("Q12", rows == [["", ""], ["Primera página", ""], ["", "Descripción conservada"]]
                and "Página 2" not in txt and "Primera página" in txt,
                f"filas={rows} texto_ok={'Primera página' in txt}")

finally:
    try:
        if made:
            vf.mysql("DELETE FROM forms WHERE id IN (" + ",".join(str(x) for x in set(made)) + ")")
    except Exception as e:
        print("cleanup:", e)

print("BLOQUE Q:", R.report())
