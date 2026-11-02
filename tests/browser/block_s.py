#!/usr/bin/env python3
"""Bloque S: tanda visual del constructor, el fill y la ficha."""

import json, re, sys, uuid
sys.path.insert(0, "tests/browser")
import vf

R = vf.R()
ah = vf.Http("admin@demo.com")
suf = uuid.uuid4().hex[:8]

# Fixtures publicados del bloque de builder/fill.
types = 263817
made = []

def esc(s):
    return str(s).replace("\\", "\\\\").replace("'", "\\'")

def css():
    return vf.urllib.request.urlopen(vf.BASE + "/assets/css/app.css", timeout=10).read().decode()

def block(sel, text):
    m = re.search(re.escape(sel) + r"\s*\{([^}]*)\}", text)
    return m.group(1) if m else ""

def new_form(title):
    t = ah.csrf(ah.get("/dashboard")[1])
    r = ah.post("/forms", {"_token": t, "title": title, "description": ""})
    m = re.search(r"/forms/(\d+)/builder", r[2])
    if not m:
        raise RuntimeError("no se creó " + title)
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
    r = v.ctx.request.post(vf.BASE + f"/forms/{fid}/builder/save", data=json.dumps(payload),
                           headers={"Content-Type": "application/json", "X-CSRF-Token": csrf})
    if r.status >= 400:
        raise RuntimeError(f"save {fid}: HTTP {r.status} {r.text()[:300]}")
    v.page.wait_for_timeout(250)

def publish(fid):
    h = ah.get(f"/forms/{fid}")[1]
    m = re.search(r'name="_token" value="([^"]+)"', h)
    tok = m.group(1) if m else ah.csrf(h)
    return ah.post(f"/forms/{fid}/publish", {"_token": tok})[0]

def slug_of(fid):
    return vf.db1(f"SELECT slug FROM forms WHERE id={fid}")[0]

def settings_of(fid):
    return json.loads(vf.db1(f"SELECT settings FROM forms WHERE id={fid}")[0] or "{}")

def set_settings(fid, fn):
    s = settings_of(fid); fn(s)
    vf.mysql(f"UPDATE forms SET settings='{esc(json.dumps(s, ensure_ascii=False))}' WHERE id={fid}")

def open_field_settings(v, uid):
    idx = {"p1": 0, "t1": 1, "p2": 2, "t2": 3}.get(uid, 0)
    v.page.locator(".app-canvas-field").nth(idx).locator('[data-act="settings"]').first.evaluate("e => e.click()")
    v.page.wait_for_timeout(350)

try:
    vf.mysql("DELETE FROM forms WHERE title LIKE '[BT] S %'")
    vf.mysql("DELETE FROM form_participants WHERE cedula LIKE 'S33-%'")
    vf.mysql("DELETE FROM rate_limits WHERE scope='dash:create' AND identifier='1'")

    fid_off = new_form(f"[BT] S off {suf}")
    fid_on = new_form(f"[BT] S on {suf}")
    fid_sec = new_form(f"[BT] S sec {suf}")
    fid_logic = new_form(f"[BT] S logic {suf}")
    fid_hint = new_form(f"[BT] S hint {suf}")
    fid_labels = new_form(f"[BT] S labels {suf}")
    fid_theme = new_form(f"[BT] S theme {suf}")

    two_pages = [
        item("p1", "page", "Página 1"), item("t1", "text", "Campo uno"),
        item("p2", "page", "Página 2"), item("t2", "text", "Campo dos"),
    ]
    off_fields = [
        item("p1", "page", "Página 1", show_header=False), item("t1", "text", "Campo uno"),
        item("p2", "page", "Página 2"), item("t2", "text", "Campo dos"),
    ]
    sec_fields = [
        item("sec", "section", "Sección S"),
        item("child", "text", "Dentro de la sección", parent="sec"),
    ]
    logic_fields = [
        item("a", "radio", "¿Aplica?", options=[{"value": "Sí"}, {"value": "No"}]),
        item("b", "text", "Campo condicional"),
        item("sec", "section", "Sección condicional"),
        item("c", "text", "Dentro condicional", parent="sec"),
    ]
    hint_fields = [item("p1", "page", ""), item("t1", "text", "Campo del paso")]
    theme_fields = [item("t1", "text", "Campo con tema")]

    with vf.VF(R) as v:
        save_struct(v, fid_off, f"[BT] S off {suf}", off_fields)
        save_struct(v, fid_on, f"[BT] S on {suf}", two_pages)
        save_struct(v, fid_sec, f"[BT] S sec {suf}", sec_fields)
        save_struct(v, fid_logic, f"[BT] S logic {suf}", logic_fields)
        save_struct(v, fid_hint, f"[BT] S hint {suf}", hint_fields)
        save_struct(v, fid_theme, f"[BT] S theme {suf}", theme_fields)
        # Labels se construye desde la UI para S21; su versión guardada se usa en S22.
        save_struct(v, fid_labels, f"[BT] S labels {suf}", [])

        # Lógica condicional directa: A == "Sí" oculta B, la sección S y su hija.
        ids = {r[1]: int(r[0]) for r in vf.mysql(f"SELECT id,label FROM fields WHERE form_id={fid_logic}")}
        lg = lambda fid: json.dumps({"operator": "all", "rules": [{"field_id": fid, "op": "eq", "value": "Sí"}]}, ensure_ascii=False)
        vf.mysql(f"UPDATE fields SET logic='{esc(lg(ids['¿Aplica?']))}' WHERE form_id={fid_logic} AND label IN ('Campo condicional','Sección condicional')")

        # Tema claro + Lora; S15 lo oscurece y S26 lo vuelve amarillo claro.
        set_settings(fid_theme, lambda s: s.update({"theme": {"primary": "#6366f1", "background": "#e0f2fe",
            "gradient_start": "#2563eb", "gradient_end": "#7c3aed", "text": None, "font": "Lora"}}))

        for f in (fid_off, fid_on, fid_sec, fid_logic, fid_hint, fid_theme):
            publish(f)
        slug_off, slug_on = slug_of(fid_off), slug_of(fid_on)
        slug_sec, slug_logic = slug_of(fid_sec), slug_of(fid_logic)
        slug_theme = slug_of(fid_theme)
        css_txt = css()

        # S1 — el interruptor de cabecera solo se ve en páginas/secciones.
        v.goto(f"/forms/{fid_on}/builder", wait=700)
        open_field_settings(v, "p1")
        d1 = v.page.evaluate("""() => ({wrap: document.querySelector('#fieldPageHeaderWrap').className,
            hidden: document.querySelector('#fieldPageHeaderWrap').classList.contains('d-none'),
            label: document.querySelector('#fieldPageHeaderWrap label').innerText})""")
        js = v.page.content()
        v.page.locator("#fieldSettingsModal .btn-close").first.evaluate("e => e.click()")
        v.page.wait_for_timeout(250)
        v.page.locator('.app-canvas-field').nth(1).locator('[data-act="settings"]').first.evaluate("e => e.click()")
        v.page.wait_for_timeout(300)
        d1b = v.page.evaluate("""() => ({hidden: document.querySelector('#fieldPageHeaderWrap').classList.contains('d-none'),
            disabled: document.querySelector('#fieldPageHeader').disabled})""")
        v.page.locator("#fieldSettingsModal .btn-close").first.evaluate("e => e.click()")
        v.page.wait_for_timeout(200)
        R.check("S1", d1["hidden"] is False and "cabecera" in d1["label"].lower() and d1b["hidden"] is True,
                f"página={d1} campo={d1b}")

        # S2 — cabecera apagada: no hay icono/título/desc, pero el paso sigue.
        v.goto(f"/f/{slug_off}", wait=700)
        b1 = v.page.locator(".vf-page-block").nth(0)
        s2 = {"blocks": v.page.locator(".vf-page-block").count(), "head": b1.locator(".vf-page-head").count(),
              "foot": b1.locator(".vf-page-footer").inner_text(), "aria": b1.get_attribute("aria-label") or "",
              "progress": v.page.locator(".vf-progress, .progress").count()}
        R.check("S2", s2["blocks"] == 2 and s2["head"] == 0 and "Paso 1 de 2" in s2["foot"]
                and s2["progress"] > 0 and ("Paso 1" in s2["aria"] or s2["aria"] == ""),
                str(s2))

        # S3 — recargar mantiene la cabecera apagada.
        v.page.reload(); v.page.wait_for_timeout(600)
        R.check("S3", v.page.locator(".vf-page-block").nth(0).locator(".vf-page-head").count() == 0
                and "Página 1" not in v.page.locator(".vf-page-block").nth(0).inner_text(), "recarga sin cabecera")

        # S4 — al encenderla vuelve icono ámbar y separador fino, sin caja/fondo.
        v.goto(f"/f/{slug_on}", wait=700)
        head = v.page.locator(".vf-page-head").first
        s4 = {"head": v.page.locator(".vf-page-head").count(), "title": head.locator(".vf-page-title").inner_text(),
              "icon": head.locator(".vf-page-icon i").count(), "bg": head.evaluate("e => getComputedStyle(e).backgroundColor")}
        head_css = block(".vf-page-head", css_txt)
        icon_css = block(".vf-page-icon", css_txt)
        R.check("S4", s4["head"] >= 1 and s4["title"] == "Página 1" and s4["icon"] == 1
                and "border-top" in head_css and "background" not in head_css
                and "#fef3c7" in icon_css and s4["bg"] in ("rgba(0, 0, 0, 0)", "transparent"),
                f"{s4} css_head={head_css[:80]!r}")

        # S5 — sección publicada sin tinte en los hijos.
        v.goto(f"/f/{slug_sec}", wait=700)
        sec_css = block(".vf-section-children", css_txt)
        s5 = {"section": v.page.locator(".vf-section-block").count(),
              "children": v.page.locator(".vf-section-children [data-vf-field-id]").count(),
              "child_bg": v.page.locator(".vf-section-children [data-vf-field-id]").first.evaluate("e => getComputedStyle(e).backgroundColor")}
        R.check("S5", s5["section"] == 1 and s5["children"] == 1 and "background" not in sec_css,
                f"{s5} css={sec_css[:100]!r}")

        # S6 — línea y «+» arrancan en el borde de la tarjeta.
        v.goto(f"/forms/{fid_on}/builder", wait=700)
        add_css = block(".app-canvas-add", css_txt)
        s6 = v.page.locator(".app-canvas-add").count()
        R.check("S6", s6 > 0 and re.search(r"left:\s*0(px)?\s*;", add_css) is not None and "right: 0" in add_css,
                f"asas={s6} css={add_css[:100]!r}")

        # S7 — «Agregar dentro» ocupa el ancho de la tarjeta.
        v.goto(f"/forms/{fid_sec}/builder", wait=700)
        ctrl_css, child_css = block(".app-add-control", css_txt), block(".app-btn-add-child", css_txt)
        s7 = {"ctrl": v.page.locator(".app-add-control .app-btn-add-child.w-100").count(),
              "label": v.page.locator(".app-add-control .app-btn-add-child").first.inner_text() if v.page.locator(".app-add-control .app-btn-add-child").count() else ""}
        R.check("S7", s7["ctrl"] == 1 and "flex: 1 0 100%" in ctrl_css and "max-width: 100%" in ctrl_css,
                f"{s7} css={ctrl_css[:100]!r}")

        # S8 — publicado: ni rastro del texto de las reglas, y siguen omitiendo.
        v.goto(f"/f/{slug_logic}", wait=700)
        body_txt = v.page.locator("body").inner_text()
        s8 = v.page.evaluate("""() => {
            const vis = sel => { const e = document.querySelector(sel); if (!e) return false;
                return getComputedStyle(e).display !== 'none' && !e.classList.contains('vf-hidden') && !e.hidden; };
            const cards = [...document.querySelectorAll('.vf-field-card')];
            const cardBy = txt => cards.find(c => c.innerText.includes(txt));
            const b = cardBy('Campo condicional'), sec = [...document.querySelectorAll('.vf-section-block')].find(e => e.innerText.includes('Sección condicional'));
            return {b: b ? (getComputedStyle(b).display !== 'none' && !b.classList.contains('vf-hidden')) : false,
                    sec: sec ? (getComputedStyle(sec).display !== 'none' && !sec.classList.contains('vf-hidden')) : false,
                    logic: document.querySelectorAll('[data-vf-logic]').length};
        }""")
        R.check("S8", "Se muestra si" not in body_txt and s8["logic"] >= 1 and not s8["b"] and not s8["sec"],
                f"{s8} texto_regla={'Se muestra si' in body_txt}")

        # S9 — el editor sí ve la insignia de la regla.
        v.goto(f"/forms/{fid_logic}/builder", wait=700)
        chips = v.page.locator(".app-logic-chip").all_inner_texts()
        R.check("S9", len(chips) >= 1 and any("Si:" in x for x in chips), f"chips={chips[:3]}")

        # S10 — seis grupos plegables y caret del abierto.
        v.page.evaluate("localStorage.clear()")
        v.goto("/forms/263816", wait=700)
        groups = v.page.locator("#formSettings .app-settings-group .app-group-toggle").all_inner_texts()
        aria = v.page.locator("#formSettings .app-group-toggle").evaluate_all("els => els.map(e => e.getAttribute('aria-expanded'))")
        caret_css = block('.app-group-toggle[aria-expanded="true"] .app-group-caret', css_txt)
        expected = ["Acceso", "Programación y cupo", "Notificaciones", "Webhook", "Mensaje final", "Idioma público"]
        R.check("S10", len(groups) == 6 and all(e in " | ".join(groups) for e in expected) and all(x == "true" for x in aria)
                and "rotate(180deg)" in caret_css, f"grupos={groups} aria={aria}")

        # S11 — cada formulario recuerda sus grupos; otro formulario no se mueve.
        v.page.locator('#formSettings [data-bs-target="#grp-acceso"]').evaluate("e => e.click()"); v.page.wait_for_timeout(500)
        v.page.locator('#formSettings [data-bs-target="#grp-agenda"]').evaluate("e => e.click()"); v.page.wait_for_timeout(500)
        v.page.reload(); v.page.wait_for_timeout(800)
        s11a = v.page.evaluate("""() => ({acc: document.querySelector('#grp-acceso').classList.contains('show'),
            age: document.querySelector('#grp-agenda').classList.contains('show'),
            key: localStorage.getItem('vf:grp:263816')})""")
        v.goto("/forms/263817", wait=800)
        s11b = v.page.evaluate("""() => ({acc: document.querySelector('#grp-acceso').classList.contains('show'),
            age: document.querySelector('#grp-agenda').classList.contains('show')})""")
        R.check("S11", not s11a["acc"] and not s11a["age"] and s11b["acc"] and s11b["age"], f"{s11a} otro={s11b}")

        # S12 — guardar con error abre todos los grupos y deja el aviso arriba.
        v.page.evaluate("localStorage.clear()")
        v.goto("/forms/263816", wait=700)
        v.page.locator('#formSettings [data-bs-target="#grp-acceso"]').evaluate("e => e.click()"); v.page.wait_for_timeout(450)
        tok = v.page.locator("#formSettings input[name=_token]").first.get_attribute("value")
        v.ctx.request.post(vf.BASE + "/forms/263816/settings", form={
            "_token": tok, "access_mode": "public", "open_at": "2026-12-10T10:00", "close_at": "2026-12-01T10:00",
        }, headers={}, max_redirects=0)
        v.goto("/forms/263816", wait=900)
        s12 = v.page.evaluate("""() => ({alert: !!document.querySelector('.alert-danger.app-alert'),
            closed: [...document.querySelectorAll('#formSettings .collapse')].filter(e => !e.classList.contains('show')).length,
            total: document.querySelectorAll('#formSettings .collapse').length})""")
        R.check("S12", s12["alert"] and s12["closed"] == 0 and s12["total"] >= 6, str(s12))

        # S13 — títulos enfocables, anillo visible y Enter pliega sin enviar.
        s13 = v.page.evaluate("""() => { const bs=[...document.querySelectorAll('#formSettings .app-group-toggle')];
            return {n: bs.length, tags: bs.every(b => b.tagName === 'BUTTON' && b.type === 'button'),
                    tabs: bs.every(b => b.tabIndex === 0), before: bs[0].getAttribute('aria-expanded')}; }""")
        focus_css = block(".app-settings-group .app-group-toggle:focus-visible", css_txt)
        first = v.page.locator("#formSettings .app-group-toggle").first
        first.focus(); v.page.keyboard.press("Enter"); v.page.wait_for_timeout(450)
        s13b = {"after": first.get_attribute("aria-expanded"), "url": v.page.url}
        R.check("S13", s13["n"] == 6 and s13["tags"] and s13["tabs"] and "box-shadow" in focus_css
                and s13b["after"] != s13["before"] and s13b["url"].endswith("/forms/263816"), f"{s13} {s13b}")
        v.page.evaluate("localStorage.clear()")

        # S14 — fondo claro en la página y tarjeta blanca.
        v.goto(f"/f/{slug_theme}", wait=700)
        s14 = v.page.evaluate("""() => { const st=document.querySelector('style[data-vf-theme], style'); return {
            bodyBg: getComputedStyle(document.querySelector('.fill-body')).backgroundColor,
            cardBg: getComputedStyle(document.querySelector('.vf-field-card')).backgroundColor,
            style: [...document.querySelectorAll('style')].map(s=>s.textContent).join(' ')}; }""")
        card_css = block(".vf-field-card", css_txt)
        R.check("S14", "--vf-background:#e0f2fe" in s14["style"] and "background:#fff" in card_css.replace(" ", ""),
                f"body={s14['bodyBg']} card={s14['cardBg']} css={card_css[:60]!r}")

        # S15 — fondo oscuro legible: tarjeta blanca y tinta clara del degradado.
        set_settings(fid_theme, lambda s: s["theme"].update({"background": "#111827"}))
        v.goto(f"/f/{slug_theme}", wait=700)
        s15 = v.page.evaluate("""() => { const st=[...document.querySelectorAll('style')].map(s=>s.textContent).join(' ');
            const card=getComputedStyle(document.querySelector('.vf-field-card')).backgroundColor;
            return {style: st, card}; }""")
        R.check("S15", "--vf-background:#111827" in s15["style"] and "--vf-on-grad:#ffffff" in s15["style"]
                and "background:#fff" in block(".vf-field-card", css_txt).replace(" ", ""), str(s15))

        # S16 — fuente del formulario y no del panel/ficha.
        v.goto(f"/f/{slug_theme}", wait=600)
        style_fill = v.page.content()
        v.goto("/forms/263816", wait=600)
        style_panel = v.page.content()
        R.check("S16", "--vf-font:Lora" in style_fill and "@font-face{font-family:Lora" in style_fill
                and "--vf-font:Lora" not in style_panel and "font-family:Lora" not in style_panel,
                f"fill={'--vf-font:Lora' in style_fill} panel={'--vf-font:Lora' in style_panel}")

        # S17 — sin tocar: gradiente y tipografía de fábrica.
        v.goto("/f/bt-p-blico-simple", wait=600)
        s17 = v.page.content()
        root_css = block(":root", css_txt)
        R.check("S17", "--vf-background:" not in s17 and "--vf-font:" not in s17
                and "radial-gradient" in root_css and "Inter" in css_txt, "sin overrides de tema")

        # S18 — paso vacío con cabecera encendida: aviso y ni caja ni icono.
        v.goto(f"/forms/{fid_hint}/builder", wait=700)
        open_field_settings(v, "p1")
        s18 = v.page.evaluate("""() => { const h=document.querySelector('#pageHeaderHint'); return {
            visible: !!h && !h.classList.contains('d-none'), text: h ? h.innerText : '',
            check: document.querySelector('#fieldPageHeader').checked}; }""")
        v.page.locator("#fieldSettingsModal .btn-close").first.evaluate("e => e.click()"); v.page.wait_for_timeout(200)
        v.goto(f"/f/{slug_of(fid_hint)}", wait=700)
        s18b = {"head": v.page.locator(".vf-page-head").count(), "icon": v.page.locator(".vf-page-icon").count()}
        R.check("S18", s18["visible"] and s18["check"] and "no tiene título" in s18["text"]
                and s18b["head"] == 0 and s18b["icon"] == 0, f"{s18} publicado={s18b}")

        # S19 — escribir título: se va el aviso y aparece la cabecera.
        v.goto(f"/forms/{fid_hint}/builder", wait=700)
        open_field_settings(v, "p1")
        v.page.fill("#fieldLabel", "Paso con título"); v.page.wait_for_timeout(400)
        s19 = v.page.evaluate("""() => ({hint: document.querySelector('#pageHeaderHint').classList.contains('d-none'),
            label: document.querySelector('#fieldLabel').value,
            card: document.querySelectorAll('.app-canvas-field')[0].innerText})""")
        R.check("S19", s19["hint"] and s19["label"] == "Paso con título" and "Paso con título" in s19["card"], str(s19))

        # S20 — solo descripción: icono + descripción, sin título.
        v.page.fill("#fieldLabel", "")
        v.page.fill("#fieldHelp", "Descripción del paso"); v.page.wait_for_timeout(400)
        s20 = v.page.evaluate("""() => ({hint: document.querySelector('#pageHeaderHint').classList.contains('d-none'),
            help: document.querySelector('#fieldHelp').value,
            card: document.querySelectorAll('.app-canvas-field')[0].innerText})""")
        # Persistir la descripción y comprobar el publicado.
        hint_fields2 = [item("p1", "page", "", help_text="Descripción del paso"), item("t1", "text", "Campo del paso")]
        save_struct(v, fid_hint, f"[BT] S hint {suf}", hint_fields2); publish(fid_hint)
        v.goto(f"/f/{slug_of(fid_hint)}", wait=700)
        head20 = v.page.locator(".vf-page-head").first
        s20b = {"head": head20.count(), "title": head20.locator(".vf-page-title").count(),
                "desc": head20.locator(".vf-page-desc").inner_text(), "icon": head20.locator(".vf-page-icon").count()}
        R.check("S20", s20["hint"] and s20["help"] == "Descripción del paso" and "Descripción del paso" in s20["card"]
                and s20b["head"] == 1 and s20b["title"] == 0 and "Descripción del paso" in s20b["desc"] and s20b["icon"] == 1,
                f"{s20} publicado={s20b}")

        # S21 — al agregar saltos, la tarjeta dice Página 1, Página 2…
        v.goto(f"/forms/{fid_labels}/builder", wait=700)
        for _ in range(2):
            v.page.locator("#btnAddQuestion").evaluate("e => e.click()"); v.page.wait_for_timeout(300)
            v.page.locator('#addFieldModal .app-type-tile[data-type="page"]').first.evaluate("e => e.click()")
            v.page.wait_for_timeout(500)
            if v.page.locator("#addFieldModal.show").count():
                v.page.locator("#addFieldModal .btn-close").first.evaluate("e => e.click()"); v.page.wait_for_timeout(200)
        labels = v.page.locator(".app-canvas-field .app-field-label").all_inner_texts()
        R.check("S21", labels == ["Página 1", "Página 2"] or labels[:2] == ["Página 1", "Página 2"], f"tarjetas={labels}")

        # S22 — dos pasos nuevos con cabecera; borrar el título quita solo esa.
        labels_fields = [
            item("p1", "page", "Página 1"), item("t1", "text", "Campo uno"),
            item("p2", "page", "Página 2"), item("t2", "text", "Campo dos"),
        ]
        save_struct(v, fid_labels, f"[BT] S labels {suf}", labels_fields); publish(fid_labels)
        v.goto(f"/f/{slug_of(fid_labels)}", wait=700)
        s22 = [v.page.locator(".vf-page-block").nth(i).locator(".vf-page-title").inner_text()
               for i in range(v.page.locator(".vf-page-block").count())]
        labels_fields[0]["label"] = ""
        save_struct(v, fid_labels, f"[BT] S labels {suf}", labels_fields); publish(fid_labels)
        v.goto(f"/f/{slug_of(fid_labels)}", wait=700)
        s22b = {"heads": v.page.locator(".vf-page-head").count(), "first": v.page.locator(".vf-page-block").nth(0).locator(".vf-page-head").count(),
                "second": v.page.locator(".vf-page-block").nth(1).locator(".vf-page-title").inner_text()}
        v.goto(f"/forms/{fid_labels}/builder", wait=700)
        open_field_settings(v, "p1")
        s22c = v.page.evaluate("() => !document.querySelector('#pageHeaderHint').classList.contains('d-none')")
        R.check("S22", s22 == ["Página 1", "Página 2"] and s22b["heads"] == 1 and s22b["first"] == 0
                and s22b["second"] == "Página 2" and s22c, f"antes={s22} después={s22b} aviso={s22c}")

        # S23 — sección publicada: icono a la derecha y sin hueco extra.
        v.goto(f"/f/{slug_sec}", wait=700)
        inner_css = block(".vf-section-inner", css_txt)
        s23 = v.page.evaluate("""() => { const h=document.querySelector('.vf-section-inner');
            return {title: h.querySelector('.vf-section-title').innerText,
                    icon: !!h.querySelector('.vf-section-icon'),
                    order: [...h.children].map(e => e.className)}; }""")
        v.goto(f"/forms/{fid_sec}/builder", wait=700)
        s23b = v.page.locator(".app-canvas-section .vf-section-inner, .app-canvas-section .app-section-inner").count()
        R.check("S23", s23["title"] == "Sección S" and s23["icon"] and s23["order"][-1].find("icon") >= 0
                and "space-between" in inner_css and s23b >= 1, f"{s23} css={inner_css[:80]!r} builder={s23b}")

        # S24 — el icono del paso también a la derecha.
        v.goto(f"/f/{slug_on}", wait=700)
        s24 = v.page.evaluate("""() => { const h=document.querySelector('.vf-page-head');
            return {title: h.querySelector('.vf-page-title').innerText,
                    order: [...h.children].map(e => e.className),
                    icon: !!h.querySelector('.vf-page-icon')}; }""")
        R.check("S24", s24["title"] == "Página 1" and s24["icon"] and s24["order"][-1].find("icon") >= 0
                and "space-between" in block(".vf-page-head", css_txt), str(s24))

        # S25 — Enviar en blanco sobre el degradado.
        v.goto("/f/bt-p-blico-simple", wait=700)
        sub_css = block(".vf-submit", css_txt)
        s25 = {"text": v.page.locator("#vfSubmit").inner_text(), "label": v.page.locator("#vfSubmit .vf-submit-label").inner_text(),
               "css_color": "color: var(--vf-on-grad)" in sub_css.replace("colour", "color"),
               "class_btn": "btn" in (v.page.locator("#vfSubmit").get_attribute("class") or "").split()}
        R.check("S25", "Enviar" in s25["label"] and s25["css_color"] and not s25["class_btn"], str(s25))

        # S26 — primario muy claro: la tinta del texto cambia a oscuro.
        set_settings(fid_theme, lambda s: s["theme"].update({"primary": "#fef3c7", "gradient_start": "#fef3c7",
            "gradient_end": "#fde68a", "background": "#ffffff"}))
        v.goto(f"/f/{slug_theme}", wait=700)
        s26 = v.page.evaluate("() => [...document.querySelectorAll('style')].map(s => s.textContent).join(' ')")
        R.check("S26", "--vf-on-grad:#1e293b" in s26 and "--vf-grad-start:#fef3c7" in s26,
                "tinta automática clara->oscura")

        # S27 — el diálogo de tipos aparece y no está anidado en otro .modal.
        v.goto(f"/forms/{fid_on}/builder", wait=700)
        v.page.locator("#btnAddQuestion").evaluate("e => e.click()"); v.page.wait_for_timeout(400)
        s27 = v.page.evaluate("""() => { const m=document.querySelector('#addFieldModal');
            return {show: m.classList.contains('show'), tiles: m.querySelectorAll('.app-type-tile').length,
                    nested: !!m.parentElement.closest('.modal'), title: m.querySelector('#addFieldTitle').innerText}; }""")
        R.check("S27", s27["show"] and s27["tiles"] == 21 and not s27["nested"] and s27["title"] == "Agregar elemento", str(s27))

        # S28 — en móvil: diálogo dentro, dos columnas, encabezado arriba y cerrable.
        v.page.set_viewport_size({"width": 360, "height": 740})
        v.page.wait_for_timeout(500)
        s28 = v.page.evaluate("""() => { const m=document.querySelector('#addFieldModal'), g=m.querySelector('.app-type-grid'),
            d=m.querySelector('.modal-dialog'), c=m.querySelector('.modal-content'), h=m.querySelector('#addFieldTitle');
            return {show: m.classList.contains('show'), cols: getComputedStyle(g).gridTemplateColumns.split(' ').filter(Boolean).length,
                    top: d.getBoundingClientRect().top, width: d.getBoundingClientRect().width, vw: innerWidth,
                    heading: h.innerText, css: getComputedStyle(c).maxHeight}; }""")
        mobile_css = css_txt[css_txt.find("@media (max-width: 575.98px)", css_txt.find("#addFieldModal")-300):]
        mobile_css = mobile_css[:mobile_css.find("}\n\n", mobile_css.find("dvh"))+2]
        v.page.locator("#addFieldModal .btn-close").first.evaluate("e => e.click()")
        v.page.wait_for_timeout(300)
        s28b = v.page.evaluate("() => !document.querySelector('#addFieldModal').classList.contains('show')")
        R.check("S28", s28["show"] and s28["heading"] == "Agregar elemento"
                and "minmax(7.5rem" in mobile_css and "dvh" in mobile_css
                and "align-items: flex-start" in mobile_css and s28b,
                str(s28) + f" cerrado={s28b} css={mobile_css[:80]!r}")
        v.page.set_viewport_size({"width": 1366, "height": 900}); v.page.wait_for_timeout(300)

        # S29 — vista previa: el botón es el publicado (blanco sobre degradado).
        v.goto(f"/forms/{fid_on}/preview?draft=1", wait=700)
        s29 = v.page.evaluate("""() => { const st=[...document.querySelectorAll('style')].map(s=>s.textContent).join(' ');
            const b=document.querySelector('#vfSubmit'); return {submit: !!b, label: b ? b.innerText : '',
                white: true, cls: b ? b.className : ''}; }""")
        sub_css = block(".vf-submit", css_txt)
        R.check("S29", s29["submit"] and "Enviar" in s29["label"] and s29["cls"] == "vf-submit"
                and "--vf-on-grad: #ffffff" in css_txt and "color: var(--vf-on-grad)" in css_txt,
                str(s29) + f" css={sub_css[:60]!r}")

        # S30 — /admin ancho: cabecera, filtros, tabla y alta en diálogo.
        v.page.set_viewport_size({"width": 1366, "height": 900})
        v.goto("/admin", wait=800)
        s30 = v.page.evaluate("""() => ({wide: !!document.querySelector('.container-fluid'),
            head: !!document.querySelector('.app-page-head'),
            action: !!document.querySelector('.app-head-actions [data-bs-target="#newUserModal"]'),
            table: !!document.querySelector('.app-admin-table'),
            filters: !!document.querySelector('form input[name="q"]')});""")
        v.page.locator('.app-head-actions [data-bs-target="#newUserModal"]').evaluate('e => e.click()'); v.page.wait_for_timeout(500)
        s30b = v.page.evaluate("""() => { const m=document.querySelector('#newUserModal');
            return {show: m.classList.contains('show'),
                    moved: !!m.querySelector('.modal-body form[action$="/admin/users/create"]'),
                    fields: m.querySelectorAll('.modal-body input').length}; }""")
        v.page.locator('#newUserModal .btn-close').first.evaluate("e => e.click()"); v.page.wait_for_timeout(250)
        R.check("S30", all(s30.values()) and s30b["show"] and s30b["moved"] and s30b["fields"] >= 5, f"{s30} {s30b}")

        # S31 — /admin a 375: tabla apilada con rótulos, sin desbordes; alta a una columna.
        v.page.set_viewport_size({"width": 375, "height": 740})
        v.goto("/admin", wait=800)
        s31 = v.page.evaluate("""() => { const t=document.querySelector('.app-admin-table');
            const tds=[...t.querySelectorAll('tbody td')];
            return {labels: tds.length > 0 && tds.every(td => (td.dataset.label || '').trim() !== ''),
                    display: getComputedStyle(t).display, thead: getComputedStyle(t.querySelector('thead')).display,
                    overflow: document.documentElement.scrollWidth <= innerWidth + 2}; }""")
        v.page.locator('.app-head-actions [data-bs-target="#newUserModal"]').evaluate('e => e.click()'); v.page.wait_for_timeout(500)
        s31b = v.page.evaluate("""() => { const m=document.querySelector('#newUserModal'), d=m.querySelector('.modal-dialog'), f=m.querySelector('.modal-body form');
            return {one: f.querySelectorAll('.row > .col-12').length >= 5,
                    fields: [...f.querySelectorAll('input, select')].filter(i => i.type !== 'hidden').length,
                    width: d.getBoundingClientRect().width, vw: innerWidth,
                    visible: m.classList.contains('show')}; }""")
        v.page.locator('#newUserModal .btn-close').first.evaluate("e => e.click()"); v.page.wait_for_timeout(200)
        admin_css = block(".app-admin-table td::before", css_txt)
        R.check("S31", s31["labels"] and s31["display"] == "block" and "@media (max-width: 991.98px)" in css_txt
                and ".app-admin-table thead { display: none; }" in css_txt and s31["overflow"]
                and s31b["one"] and s31b["fields"] >= 5 and s31b["visible"] and s31b["width"] <= s31b["vw"]
                and "attr(data-label)" in admin_css, f"{s31} modal={s31b}")

        # S32 — ficha ancha: dos columnas con el reparto esperado.
        v.page.set_viewport_size({"width": 1366, "height": 900})
        v.goto("/forms/263816", wait=700)
        s32 = v.page.evaluate("""() => { const left=document.querySelector('.row > .col-lg-8'), right=document.querySelector('.row > .col-lg-4');
            return {left: !!left && !!left.querySelector('#formUpdate') && !!left.querySelector('#settings') === false,
                    right: !!right && !!right.querySelector('#settings') && !!right.querySelector('#vfSlotShare'),
                    access: !!document.querySelector('#access, .app-access-card'),
                    order: left.compareDocumentPosition(right) & Node.DOCUMENT_POSITION_FOLLOWING ? 'ok' : 'bad'}; }""")
        R.check("S32", s32["left"] and s32["right"] and not s32["access"] and s32["order"] == "ok", str(s32))

        # S33 — lista larga de correos con holgura y sin desbordar la pantalla.
        vals = []
        for i in range(30):
            ced = f"S33-{i:03d}"; vals.append(f"({263819},'{ced}','Persona {i}','persona.con.correo.largo.{i}@example-domain.test','0414-000{i:04d}','{ced}','S33{i:013d}')")
        vf.mysql("INSERT INTO form_participants (form_id,cedula,nombre,correo,telefono,identifier_value,code) VALUES " + ",".join(vals))
        v.goto("/forms/263819/access", wait=900)
        s33 = v.page.evaluate("""() => { const w=document.querySelector('#rosterList .table-responsive');
            return {rows: document.querySelectorAll('#rosterList tr[data-vf-row]').length,
                    wrap: !!w, overflow: !!w && getComputedStyle(w).overflowX === 'auto',
                    pageOverflow: document.documentElement.scrollWidth <= innerWidth + 2,
                    pager: !!document.querySelector('#rosterList + nav, #rosterList .pagination, .pagination')}; }""")
        bs_css = vf.urllib.request.urlopen(vf.BASE + "/assets/css/bootstrap.min.css", timeout=10).read().decode()
        R.check("S33", s33["rows"] == 25 and s33["wrap"] and s33["pageOverflow"]
                and ".table-responsive{overflow-x:auto" in bs_css.replace(" ", ""), str(s33))
        vf.mysql("DELETE FROM form_participants WHERE cedula LIKE 'S33-%'")

        # S34 — ficha a 375: tarjetas apiladas y sin desbordes.
        v.page.set_viewport_size({"width": 375, "height": 740})
        v.goto("/forms/263816", wait=800)
        s34 = v.page.evaluate("""() => { const l=document.querySelector('.row > .col-lg-8'), r=document.querySelector('.row > .col-lg-4');
            return {left: !!l, right: !!r, settings: !!document.querySelector('#settings'),
                    share: !!document.querySelector('#vfSlotShare'),
                    access: !!document.querySelector('#access, .app-access-card'),
                    overflow: document.documentElement.scrollWidth <= innerWidth + 2}; }""")
        boot = vf.urllib.request.urlopen(vf.BASE + "/assets/css/bootstrap.min.css", timeout=10).read().decode()
        R.check("S34", s34["left"] and s34["right"] and s34["settings"] and s34["share"]
                and not s34["access"] and s34["overflow"] and "@media (min-width:992px)" in boot, str(s34))

        # S35 — 1920 mitad, 800 todo, preview igual; sin scroll horizontal por la matriz.
        v.page.set_viewport_size({"width": 1920, "height": 1000})
        v.goto("/f/bt-todos-los-tipos", wait=900)
        s35a = v.page.evaluate("""() => { const c=document.querySelector('.fill-container').getBoundingClientRect();
            return {ratio: c.width / innerWidth, overflow: document.documentElement.scrollWidth <= innerWidth + 2}; }""")
        v.page.set_viewport_size({"width": 800, "height": 1000}); v.page.wait_for_timeout(600)
        s35b = v.page.evaluate("""() => { const c=document.querySelector('.fill-container').getBoundingClientRect();
            return {ratio: c.width / innerWidth, overflow: document.documentElement.scrollWidth <= innerWidth + 2,
                    matrix: !!document.querySelector('.vf-matrix-table')}; }""")
        v.page.set_viewport_size({"width": 1366, "height": 900})
        v.goto(f"/forms/{fid_on}/preview?draft=1", wait=700)
        s35c = v.page.evaluate("""() => { const c=document.querySelector('.fill-container').getBoundingClientRect();
            return {ratio: c.width / innerWidth, same: !!document.querySelector('.fill-container')}; }""")
        fill_css = block(".fill-container", css_txt)
        R.check("S35", "@media (min-width: 992px) { .fill-container { width: 50%; max-width: 50%; } }" in css_txt
                and "width: 100%" in fill_css and s35b["matrix"] and s35c["same"]
                and "#livePreviewContent" not in fill_css and "#livePreviewModal" not in fill_css,
                f"1920={s35a} 800={s35b} preview={s35c} css={fill_css[:80]!r}")

        # S36 — avisos y webhook: campos a media fila o fila entera, sin recortes.
        v.page.set_viewport_size({"width": 1366, "height": 900})
        v.goto("/forms/263816", wait=700)
        s36 = v.page.evaluate("""() => {
            const par = sel => { const e=document.querySelector(sel); return e ? e.parentElement.className : ''; };
            const fs = ['#confirm_to_field','#confirm_subject','#webhook_kind','#webhook_secret','#confirm_message','#webhook_url'];
            return {half: [par('#confirm_to_field'), par('#confirm_subject'), par('#webhook_kind'), par('#webhook_secret')].every(c => c.includes('col-sm-6')),
                    full: ['#confirm_message','#webhook_url'].every(s => par(s).includes('col-12')) && !!document.querySelector('#notify_emails'),
                    widths: fs.map(s => { const e=document.querySelector(s); return e ? e.getBoundingClientRect().width : 0; }),
                    clipped: fs.filter(s => { const e=document.querySelector(s); return e && e.scrollWidth > e.clientWidth + 2; })}; }""")
        R.check("S36", s36["half"] and s36["full"] and not s36["clipped"], str(s36))

        # S37 — constructor: una columna, sin paleta, una sola vista previa.
        v.goto(f"/forms/263816/builder", wait=900)
        s37 = v.page.evaluate("""() => ({palette: document.querySelectorAll('.app-palette').length,
            canvas: document.querySelectorAll('.builder-layout > .col-lg-9.mx-auto').length,
            save: !!document.querySelector('#btnSaveStructure'), preview: document.querySelectorAll('#btnLivePreview').length,
            add: !!document.querySelector('#btnAddQuestion'),
            previewTexts: [...document.querySelectorAll('button, a')].filter(e => e.innerText.trim() === 'Vista previa').length,
            inline: !!document.querySelector('#btnPreviewInline, #btnEmbedPreview')});""")
        R.check("S37", s37["palette"] == 0 and s37["canvas"] == 1 and s37["save"] and s37["preview"] == 1
                and s37["add"] and s37["previewTexts"] == 1 and not s37["inline"], str(s37))


        # S38 — «Agregar elemento» y el `+` entre tarjetas insertan en su posición.
        v.page.set_viewport_size({"width": 1366, "height": 900})
        v.goto(f"/forms/{fid_on}/builder", wait=900)
        before38 = v.page.locator(".app-canvas-field").count()
        v.page.locator(".app-canvas-add .app-add-between-btn").first.evaluate("e => e.click()")
        v.page.wait_for_timeout(450)
        s38a = v.page.evaluate("""() => ({show: document.querySelector('#addFieldModal').classList.contains('show'),
            types: document.querySelectorAll('#addFieldModal .app-type-tile').length,
            heading: document.querySelector('#addFieldModal')?.innerText.trim().slice(0, 40)})""")
        v.page.locator('#addFieldModal .app-type-tile[data-type="text"]').evaluate("e => e.click()")
        v.page.wait_for_timeout(450)
        s38 = v.page.evaluate("""() => ({count: document.querySelectorAll('.app-canvas-field').length,
            labels: [...document.querySelectorAll('.app-canvas-field .app-field-label')].map(e => e.innerText.trim()),
            closed: !document.querySelector('#addFieldModal')?.classList.contains('show')})""")
        R.check("S38", s38a["show"] and s38a["types"] == 21 and "Agregar" in (s38a["heading"] or "")
                and s38["count"] == before38 + 1 and s38["closed"]
                and len(s38["labels"]) > 1 and "Nueva pregunta" in s38["labels"][1],
                f"modal={s38a} canvas={s38} before={before38}")

        # S39 — pie de tarjeta: tipo, obligatoria y herramientas; persiste y publica.
        save_struct(v, fid_labels, f"[BT] S labels {suf}", [item("t1", "text", "Campo S39")])
        v.goto(f"/forms/{fid_labels}/builder", wait=900)
        v.page.locator(".app-canvas-field").first.locator("select.app-type-select").select_option("email")
        v.page.wait_for_timeout(250)
        v.page.locator(".app-canvas-field").first.locator(".app-required-toggle input").evaluate(
            "e => { e.checked = true; e.dispatchEvent(new Event('change', {bubbles: true})); }")
        v.page.wait_for_timeout(300)
        s39 = v.page.evaluate("""() => { const c=document.querySelector('.app-canvas-field');
            const f=c.querySelector('.app-field-footer'), tools=[...f.querySelectorAll('.app-tool-btn')];
            return {type: c.querySelector('select.app-type-select').value,
                    req: c.querySelector('.app-required-toggle input').checked,
                    preview: !!c.querySelector('.app-field-body input[type="email"]'),
                    tools: tools.length >= 3, toolsVisible: tools.every(e => getComputedStyle(e).display !== 'none'),
                    requiredHidden: !!c.querySelector('.app-required-toggle'),
                    checkCss: !!document.querySelector('.app-required-toggle .form-check-input')}; }""")
        req_css = block(".app-required-toggle .form-check-input", css_txt)
        v.page.locator("#btnSaveStructure").evaluate("e => e.click()"); v.page.wait_for_timeout(900)
        publish(fid_labels)
        v.goto(f"/f/{slug_of(fid_labels)}", wait=900)
        s39b = v.page.evaluate("""() => ({required: !!document.querySelector('input[type="email"][required]'),
            noMatrix: !document.querySelector('.vf-matrix-table')})""")
        R.check("S39", s39["type"] == "email" and s39["req"] and s39["preview"] and s39["tools"]
                and s39["toolsVisible"] and s39["requiredHidden"] and "width: 1rem" in req_css
                and "height: 1rem" in req_css and s39b["required"], f"{s39} {s39b} css={req_css[:60]!r}")

        # S40 — engranaje: modal centrado, título fijo y tarjeta seleccionada al cerrar.
        v.goto(f"/forms/{fid_labels}/builder", wait=900)
        opener = v.page.locator(".app-canvas-field").first
        opener.locator('[data-act="settings"]').first.evaluate("e => e.click()")
        v.page.wait_for_timeout(450)
        s40 = v.page.evaluate("""() => { const m=document.querySelector('#fieldSettingsModal'), d=m?.querySelector('.modal-dialog');
            return {show: !!m && m.classList.contains('show'), title: m?.querySelector('#fieldSettingsTitle')?.innerText.trim(),
                    centered: !!d && d.classList.contains('modal-dialog-centered'),
                    lg: !!d && d.classList.contains('modal-lg'),
                    scroll: !!d && d.classList.contains('modal-dialog-scrollable')}; }""")
        v.page.locator('#fieldSettingsModal .btn-close').first.evaluate("e => e.click()")
        v.page.wait_for_timeout(500)
        s40b = v.page.evaluate("""() => { const c=document.querySelector('.app-canvas-field.selected');
            return {closed: !document.querySelector('#fieldSettingsModal')?.classList.contains('show'),
                    selected: !!c, uid: c?.dataset.uid || ''}; }""")
        R.check("S40", s40["show"] and s40["title"] == "Configuración del elemento" and s40["centered"]
                and s40["lg"] and s40["scroll"] and s40b["closed"] and s40b["selected"], f"{s40} {s40b}")

        # S41 — configuración del formulario: modal centrado y pie fijo.
        v.goto(f"/forms/{fid_on}/builder", wait=900)
        v.page.locator("#btnFormSettings").evaluate("e => e.click()"); v.page.wait_for_timeout(450)
        s41 = v.page.evaluate("""() => { const m=document.querySelector('#formSettingsModal'), d=m?.querySelector('.modal-dialog'),
            body=m?.querySelector('.modal-body'), foot=m?.querySelector('.modal-footer');
            return {show: !!m && m.classList.contains('show'), centered: !!d && d.classList.contains('modal-dialog-centered'),
                    lg: !!d && d.classList.contains('modal-lg'), scroll: !!d && d.classList.contains('modal-dialog-scrollable'),
                    footer: !!foot && foot.innerText.includes('Guardar configuración'),
                    sibling: !!body && !!foot && body.nextElementSibling === foot,
                    close: !!m?.querySelector('.btn-close')}; }""")
        v.page.locator('#formSettingsModal .btn-close').first.evaluate("e => e.click()"); v.page.wait_for_timeout(300)
        R.check("S41", all(s41.values()), str(s41))

        # S42 — vista previa contra el borrador, sin pulsar Guardar.
        v.goto(f"/forms/{fid_on}/builder", wait=900)
        v.page.locator(".app-canvas-field").nth(1).locator('[data-act="settings"]').first.evaluate("e => e.click()")
        v.page.wait_for_timeout(350)
        v.page.locator("#fieldLabel").fill("Campo borrador S42")
        v.page.wait_for_timeout(2700)
        v.page.evaluate("""() => { window.__vfPreview = null;
            const orig = window.open; window.open = (u, n, f) => { window.__vfPreview = u; return null; }; }""")
        v.page.locator("#btnLivePreview").evaluate("e => e.click()")
        v.page.wait_for_timeout(1800)
        prev_url = v.page.evaluate("() => window.__vfPreview")
        s42 = {"url": prev_url or ""}
        if prev_url:
            v.goto(prev_url, wait=900)
            s42["first"] = v.page.evaluate("() => document.body.innerText.includes('Campo borrador S42')")
            v.page.reload(wait_until="load"); v.page.wait_for_timeout(500)
            s42["reload"] = v.page.evaluate("() => document.body.innerText.includes('Campo borrador S42')")
        R.check("S42", bool(prev_url) and "draft=1" in (prev_url or "") and s42.get("first")
                and s42.get("reload"), str(s42))

        # S43 — matriz: primera columna ancha y opciones ajustadas.
        v.goto("/f/bt-todos-los-tipos", wait=900)
        s43 = v.page.evaluate("""() => { const t=document.querySelector('.vf-matrix-table');
            return {table: !!t, wrap: !!t?.closest('.vf-matrix-table-wrap'),
                    ths: t ? [...t.querySelectorAll('thead th')].map(th => th.innerText.trim()) : [],
                    first: t ? getComputedStyle(t.querySelector('th:first-child')).width : '',
                    css: !!document.querySelector('.vf-matrix-table th:first-child')}; }""")
        matrix_css = block(".vf-matrix-table th:first-child,\n.vf-matrix-table td:first-child,\n.vf-matrix-empty", css_txt)
        if not matrix_css:
            matrix_css = block(".vf-matrix-table th:first-child", css_txt)
        v.goto(f"/forms/{types}/builder", wait=900)
        s43b = v.page.evaluate("""() => !!document.querySelector('.vf-matrix-preview .vf-matrix-table')""")
        R.check("S43", s43["table"] and s43["wrap"] and len(s43["ths"]) >= 3 and s43b
                and "width: 100%" in matrix_css and "min-width: 9rem" in matrix_css, f"{s43} preview={s43b} css={matrix_css[:80]!r}")

        # S44 — vista previa larga: mismo pie de envío que el publicado.
        v.goto("/f/bt-p-blico-simple", wait=900)
        s44a = v.page.evaluate("""() => { const r=document.querySelector('.vf-submit-row');
            return {row: !!r, cls: r?.className, parent: r?.parentElement?.className,
                    css: !!r && getComputedStyle(r).position}; }""")
        v.goto("/forms/263816/preview?draft=1", wait=900)
        s44b = v.page.evaluate("""() => { const r=document.querySelector('.vf-submit-row');
            return {row: !!r, cls: r?.className, parent: r?.parentElement?.className,
                    oldModal: !!document.querySelector('#livePreviewContent')}; }""")
        R.check("S44", s44a["row"] and s44b["row"] and s44a["cls"] == s44b["cls"]
                and s44a["parent"] == s44b["parent"] and not s44b["oldModal"]
                and "position: sticky" in block(".vf-submit-row", css_txt), f"pub={s44a} preview={s44b}")

        # S45 — ventana baja: la vista previa scrollea y deja llegar al envío.
        v.page.set_viewport_size({"width": 375, "height": 500})
        v.goto("/forms/263816/preview?draft=1", wait=900)
        s45 = v.page.evaluate("""() => { const d=document.scrollingElement;
            return {submit: !!document.querySelector('#vfSubmit'), noOverflow: d.scrollWidth <= innerWidth + 2,
                    scrolls: d.scrollHeight > innerHeight + 20}; }""")
        v.page.evaluate("() => window.scrollTo(0, document.documentElement.scrollHeight)")
        v.page.wait_for_timeout(400)
        s45b = v.page.evaluate("""() => ({y: window.scrollY, submit: !!document.querySelector('#vfSubmit')})""")
        R.check("S45", s45["submit"] and s45["noOverflow"] and s45["scrolls"] and s45b["submit"]
                and ".vf-submit { width: 100%; }" in css_txt, f"{s45} {s45b}")

        # S46 — panel y acceso con controles Bootstrap, foco índigo y buscador -sm.
        v.page.set_viewport_size({"width": 1366, "height": 900})
        v.goto("/dashboard", wait=800)
        s46 = v.page.evaluate("""() => ({search: document.querySelector('#dashSearch')?.className || '',
            sort: document.querySelector('#dashSort')?.className || ''})""")
        v.goto("/admin", wait=800)
        s46b = v.page.evaluate("""() => { const n=document.querySelector('#newUserName'), r=document.querySelector('#newUserRole');
            return {name: n?.className || '', role: r?.className || '',
                    labels: document.querySelectorAll('.app-content .form-label').length > 0}; }""")
        login_html = vf.urllib.request.urlopen(vf.BASE + "/login", timeout=10).read().decode()
        R.check("S46", "form-control-sm" in s46["search"] and "form-select-sm" in s46["sort"]
                and s46b["labels"] and "form-control" in s46b["name"] and "form-select" in s46b["role"]
                and 'class="form-control"' in login_html and ".form-control:focus" in css_txt
                and "border-color: var(--vf-primary)" in css_txt, f"{s46} {s46b}")

        # S47 — input-group con icono: un solo borde y foco en el contenedor.
        s47 = {"groups": login_html.count('class="input-group"'), "icons": login_html.count("input-group-text"),
               "iconCls": "app-input-icon" in login_html}
        R.check("S47", s47["groups"] >= 2 and s47["icons"] >= 2 and s47["iconCls"]
                and ".app-input-icon" in css_txt and ".app-dash-search:focus-within" in css_txt
                and "box-shadow: none" in block(".app-dash-search .form-control:focus", css_txt), str(s47))

        # S48 — detalle de respuesta con el ancho y la cabecera del listado.
        sid = vf.db1("SELECT id FROM submissions WHERE form_id=263816 ORDER BY id DESC LIMIT 1")[0]
        v.goto(f"/forms/263816/responses/{sid}", wait=900)
        s48 = v.page.evaluate("""() => { const c=document.querySelector('.container'), h=document.querySelector('.app-page-head'),
            a=document.querySelector('.app-head-actions'), f=document.querySelector('form[action$="/delete"]');
            return {cls: c?.className || '', style: c?.getAttribute('style') || '', head: !!h,
                    crumb: h?.innerText.includes('Volver a respuestas'), delete: a?.innerText.includes('Borrar'),
                    confirm: !!f && f.hasAttribute('data-confirm'), action: f?.getAttribute('action') || '',
                    css: getComputedStyle(document.documentElement).getPropertyValue('--vf-btn-cta-h').trim()}; }""")
        R.check("S48", "container px-lg-4" in s48["cls"] and not s48["style"] and s48["head"]
                and s48["crumb"] and s48["delete"] and s48["confirm"]
                and f"/forms/263816/responses/{sid}/delete" in s48["action"]
                and s48["css"] == "44px", str(s48))

finally:
    try:
        if made:
            vf.mysql("DELETE FROM forms WHERE id IN (" + ",".join(str(x) for x in set(made)) + ")")
        vf.mysql("DELETE FROM form_participants WHERE cedula LIKE 'S33-%'")
    except Exception as e:
        print("cleanup:", e)

print("BLOQUE S:", R.report())
