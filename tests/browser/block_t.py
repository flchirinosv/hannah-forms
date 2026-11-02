#!/usr/bin/env python3
"""Bloque T: acciones del panel sin recarga."""

import json, re, sys, uuid
sys.path.insert(0, "tests/browser")
import vf

R = vf.R()
ah = vf.Http("admin@demo.com")
uh = vf.Http("nuevo@demo.com")
rv = vf.Http("aprobador@demo.com")
suf = uuid.uuid4().hex[:8]
made = []
users = []

def esc(s):
    return str(s).replace("\\", "\\\\").replace("'", "\\'")

def new_form(h, title, owner=None):
    t = h.csrf(h.get("/dashboard")[1])
    r = h.post("/forms", {"_token": t, "title": title, "description": ""})
    m = re.search(r"/forms/(\d+)/builder", r[2])
    if not m:
        raise RuntimeError("no se creó " + title + " " + r[2])
    fid = int(m.group(1)); made.append(fid)
    if owner is not None:
        vf.mysql(f"UPDATE forms SET user_id={int(owner)} WHERE id={fid}")
    return fid

def item(uid, typ, label="", parent=None, options=None):
    return {"_uid": uid, "type": typ, "label": label, "placeholder": "", "help_text": "",
            "is_required": False, "is_pii": False, "show_header": True,
            "options": options or [], "validations": {}, "parent_id": parent, "logic": None}

def save_struct(v, fid, title, fields):
    v.goto(f"/forms/{fid}/builder", wait=700)
    csrf = v.page.locator("#builderApp").get_attribute("data-csrf")
    payload = {"title": title, "description": "", "status": "draft", "settings": {}, "fields": fields}
    r = v.ctx.request.post(vf.BASE + f"/forms/{fid}/builder/save", data=json.dumps(payload),
                           headers={"Content-Type": "application/json", "X-CSRF-Token": csrf})
    if r.status >= 400:
        raise RuntimeError(f"save {fid}: HTTP {r.status} {r.text()[:300]}")
    v.page.wait_for_timeout(250)

def publish_http(h, fid):
    html = h.get(f"/forms/{fid}")[1]
    tok = h.csrf(html)
    return h.post(f"/forms/{fid}/publish", {"_token": tok})

def slug_of(fid):
    return vf.db1(f"SELECT slug FROM forms WHERE id={fid}")[0]

def settings(fid, obj):
    s = json.dumps(obj, ensure_ascii=False)
    vf.mysql(f"UPDATE forms SET settings='{esc(s)}' WHERE id={fid}")

def submit_fill(fid, slug, val):
    fld = vf.db1(f"SELECT id FROM fields WHERE form_id={fid} AND type='text' ORDER BY id LIMIT 1")[0]
    h = vf.Http()
    s, html, _ = h.get(f"/f/{slug}")
    tok = h.csrf(html)
    s, html, url = h.req("POST", f"/f/{slug}", {"_token": tok, f"field_{fld}": val})
    return s, url

def title_of(fid):
    return vf.db1(f"SELECT title FROM forms WHERE id={fid}")[0]

def status_of(fid):
    return vf.db1(f"SELECT status FROM forms WHERE id={fid}")[0]

def confirm(v, ok_text=None):
    v.page.wait_for_selector("#vfConfirm.show", timeout=3000)
    if ok_text:
        v.page.locator("#vfConfirm .modal-footer .btn").filter(has_text=ok_text).first.evaluate("e => e.click()")
    else:
        v.page.locator("#vfConfirm .modal-footer .btn-primary, #vfConfirm .modal-footer .btn-danger").first.evaluate("e => e.click()")
    v.page.wait_for_timeout(800)

def open_slot_form(v, act):
    v.page.locator(f'form[action$="{act}"] button[type=submit]').first.evaluate("e => e.click()")

try:
    vf.mysql("DELETE FROM forms WHERE title LIKE '[BT] T %'")
    vf.mysql("DELETE FROM users WHERE email LIKE 't%@bt.local'")
    vf.mysql("DELETE FROM rate_limits WHERE scope='dash:create' AND identifier='1'")
    vf.mysql("DELETE FROM rate_limits WHERE scope='dash:create' AND identifier='4'")

    fid = new_form(ah, f"[BT] T ficha {suf}")
    with vf.VF(R) as v:
        save_struct(v, fid, f"[BT] T ficha {suf}", [item("t1", "text", "Campo T")])

        # T1 — publicar sin recarga, slots completos.
        v.goto(f"/forms/{fid}", wait=700)
        url0 = v.page.url
        navs = []
        v.page.on("framenavigated", lambda f: navs.append(f.url) if f == v.page.main_frame else None)
        v.page.locator("form[action$='/publish'] button").first.evaluate("e => e.click()")
        v.page.wait_for_timeout(900)
        t1 = v.page.evaluate("""() => ({badge:document.querySelector('#vfSlotStatusBadge')?.innerText.trim(),
            share:document.querySelector('#vfSlotShare')?.innerText||'', qr:!!document.querySelector('#vfSlotShare canvas, #vfSlotShare img, #vfSlotShare svg'),
            iframe:!!document.querySelector('#vfSlotShare iframe, #vfSlotShare textarea, #vfSlotShare code'),
            actions:document.querySelector('#vfSlotActions')?.innerText||''})""")
        share_url = v.page.locator("#vfSlotShare input").first.input_value() if v.page.locator("#vfSlotShare input").count() else ""
        R.check("T1", v.page.url == url0 and not navs and "Publicado" in t1["badge"] and "Cerrar" in t1["actions"]
                and share_url.startswith("http") and (t1["qr"] or t1["iframe"]),
                f"navs={navs} url={share_url!r} {t1}")

        # T2 — cerrar pregunta y quita el compartir.
        navs.clear()
        open_slot_form(v, "/close"); v.page.wait_for_timeout(900)
        dlg = 0
        t2 = v.page.evaluate("""() => ({badge:document.querySelector('#vfSlotStatusBadge')?.innerText.trim(),
            share:document.querySelector('#vfSlotShare')?.innerText||'', actions:document.querySelector('#vfSlotActions')?.innerText||''})""")
        R.check("T2", dlg == 1 and not navs and "Cerrado" in t2["badge"] and "Publicar" in t2["actions"]
                and ("Publica el formulario" in t2["share"] or t2["share"].strip() == ""),
                f"dialogo={dlg} navs={navs} {t2}")

        # T3 — publicar/cerrar otra vez deja un estado coherente.
        open_slot_form(v, "/publish"); v.page.wait_for_timeout(700)
        open_slot_form(v, "/close"); v.page.wait_for_timeout(900)
        t3 = v.page.evaluate("""() => ({badge:document.querySelector('#vfSlotStatusBadge')?.innerText.trim(),
            share:document.querySelector('#vfSlotShare')?.innerText||'', actions:document.querySelector('#vfSlotActions')?.innerText||''})""")
        R.check("T3", "Cerrado" in t3["badge"] and ("Reabrir" in t3["actions"] or "Publicar" in t3["actions"])
                and "Publica el formulario" in t3["share"], str(t3))

        # T4 — guardar ajustes sin recarga, sin plegar grupos ni saltar scroll.
        v.goto(f"/forms/{fid}", wait=700)
        v.page.evaluate("window.scrollTo(0, document.body.scrollHeight/2)")
        y0 = v.page.evaluate("scrollY")
        navs.clear()
        nuevo = f"[BT] T renombrado {suf}"
        v.page.locator("#title").fill(nuevo)
        v.page.locator('button[form="formUpdate"]').first.evaluate("e => e.click()")
        v.page.wait_for_timeout(900)
        t4 = v.page.evaluate("""() => ({h:document.querySelector('h1,h2,h3')?.innerText||'',
            open:document.querySelectorAll('#settings .collapse.show, .app-settings .collapse.show').length,
            closed:document.querySelectorAll('#settings .collapse:not(.show), .app-settings .collapse:not(.show)').length,
            y:scrollY})""")
        R.check("T4", not navs and nuevo in v.page.title() + " " + t4["h"] and t4["open"] >= 1
                and abs(t4["y"] - y0) < 250, f"navs={navs} y={y0}->{t4['y']} {t4}")

        # T18/T19 — Ctrl+S dentro de #formSettings (data-vf-shortcut) guarda ajustes.
        marca = f"ctrl-{suf}"
        v.page.locator("#thanks_message").fill(marca)
        v.page.locator("#thanks_message").evaluate("e => e.dispatchEvent(new KeyboardEvent('keydown',{key:'s',ctrlKey:true,bubbles:true,cancelable:true}))")
        v.page.wait_for_timeout(1000)
        st = json.loads(vf.db1(f"SELECT settings FROM forms WHERE id={fid}")[0] or "{}")
        R.check("T18", st.get("thanks", {}).get("message") == marca, f"settings={st.get('thanks')}")
        v.page.locator("#confirm_subject").fill(f"asunto-{suf}")
        v.page.locator("#confirm_subject").evaluate("e => e.dispatchEvent(new KeyboardEvent('keydown',{key:'s',ctrlKey:true,bubbles:true,cancelable:true}))")
        v.page.wait_for_timeout(900)
        st = json.loads(vf.db1(f"SELECT settings FROM forms WHERE id={fid}")[0] or "{}")
        R.check("T19", st.get("thanks", {}).get("message") == marca, f"settings={st.get('thanks')}")

        # T20 — doble clic en Guardar manda un solo POST.
        posts = []
        v.page.on("request", lambda r: posts.append(r.url) if r.method == "POST" and vf.urllib.parse.urlparse(r.url).path.startswith(f"/forms/{fid}/") else None)
        v.page.locator("#title").fill(f"{nuevo} doble")
        btn = v.page.locator('button[form="formUpdate"]').first
        btn.evaluate("e => { e.click(); e.click(); }")
        v.page.wait_for_timeout(900)
        R.check("T20", len(posts) == 1, f"posts={posts}")

        # T21 — error de fechas: grupos abiertos, mensaje rojo, sin éxito.
        v.goto(f"/forms/{fid}", wait=700)
        v.page.locator("#open_at").fill("2030-11-10T10:00")
        v.page.locator("#close_at").fill("2030-11-09T10:00")
        v.page.locator('#formSettings button[type=submit]').first.evaluate("e => e.click()")
        v.page.wait_for_timeout(900)
        t21 = v.page.evaluate("""() => ({toast:document.querySelector('#toastWrap')?.innerText||'',
            open:[...document.querySelectorAll('[id^=grp-].collapse')].filter(e=>e.classList.contains('show')).length,
            total:document.querySelectorAll('[id^=grp-].collapse').length,
            ok:!!document.querySelector('#toastWrap .text-bg-success')})""")
        R.check("T21", "fecha de apertura" in t21["toast"] and t21["open"] == t21["total"] and not t21["ok"],
                str(t21))

        # T23 — aviso role=status y foco fuera del body tras una acción.
        v.goto(f"/forms/{fid}", wait=700)
        open_slot_form(v, "/publish"); v.page.wait_for_timeout(800)
        t23 = v.page.evaluate("""() => {const a=document.querySelector('#toastWrap, [role=status]'); return {
            role:a?.getAttribute('role')||'', live:a?.getAttribute('aria-live')||'',
            focus:document.activeElement?.tagName||'', body:document.activeElement===document.body};}""")
        R.check("T23", t23["role"] == "status" and t23["live"] == "polite" and not t23["body"], str(t23))

    # T5 — publicado con revisión + dueño no exento: guardar ajuste deja borrador.
    fid5 = new_form(ah, f"[BT] T revision {suf}", owner=4)
    vf.mysql(f"UPDATE forms SET status='published' WHERE id={fid5}")
    with vf.VF(R) as v:
        v.goto(f"/forms/{fid5}", wait=700)
        v.page.locator("#title").fill(f"[BT] T revision editada {suf}")
        navs = []
        v.page.on("framenavigated", lambda f: navs.append(f.url) if f == v.page.main_frame else None)
        v.page.locator('button[form="formUpdate"]').first.evaluate("e => e.click()")
        v.page.wait_for_timeout(900)
        t5 = v.page.evaluate("""() => ({badge:document.querySelector('#vfSlotStatusBadge')?.innerText.trim(),
            trail:document.querySelector('#vfSlotReviewTrail')?.innerText||'', msg:document.querySelector('#toastWrap')?.innerText||''})""")
        R.check("T5", status_of(fid5) == "draft" and "Borrador" in t5["badge"] and not navs,
                f"status={status_of(fid5)} {t5}")

    # T6 — aprobado + guardar en builder deja borrador y ofrece enviar a revisión.
    fid6 = new_form(ah, f"[BT] T aprobado {suf}", owner=4)
    vf.mysql(f"UPDATE forms SET status='approved', review_requested_at=NOW(), reviewed_by=1, reviewed_at=NOW() WHERE id={fid6}")
    with vf.VF(R) as v:
        save_struct(v, fid6, f"[BT] T aprobado editado {suf}", [item("t1", "text", "Campo aprobado")])
        v.goto(f"/forms/{fid6}", wait=700)
        t6 = v.page.evaluate("""() => ({badge:document.querySelector('#vfSlotStatusBadge')?.innerText.trim(),
            actions:document.querySelector('#vfSlotActions')?.innerText||''})""")
        R.check("T6", status_of(fid6) == "draft" and "Borrador" in t6["badge"] and "revisión" in t6["actions"].lower(),
                f"status={status_of(fid6)} {t6}")

    # T7/T7b — dueño envía, aprobador aprueba, dueño publica.
    fid7 = new_form(uh, f"[BT] T flujo {suf}", owner=4)
    with vf.VF(R, email="nuevo@demo.com") as v:
        save_struct(v, fid7, f"[BT] T flujo {suf}", [item("t1", "text", "Campo flujo")])
        v.goto(f"/forms/{fid7}", wait=700)
        open_slot_form(v, "/review/send"); v.page.wait_for_timeout(800)
    t7_send = status_of(fid7)
    with vf.VF(R, email="aprobador@demo.com") as v:
        v.goto("/review", wait=800)
        card = v.page.locator(".card").filter(has_text=f"[BT] T flujo {suf}").first
        navs = []
        v.page.on("framenavigated", lambda f: navs.append(f.url) if f == v.page.main_frame else None)
        card.locator("form[action$='/review/approve'] button").first.evaluate("e => e.click()")
        v.page.wait_for_timeout(300)
        confirm(v, "Aprobar")
    with vf.VF(R, email="nuevo@demo.com") as v:
        v.goto(f"/forms/{fid7}", wait=700)
        t7 = v.page.evaluate("""() => ({badge:document.querySelector('#vfSlotStatusBadge')?.innerText.trim(),
            trail:document.querySelector('#vfSlotReviewTrail')?.innerText||'', actions:document.querySelector('#vfSlotActions')?.innerText||''})""")
        R.check("T7", t7_send == "in_review" and status_of(fid7) == "approved" and "Aprobado" in t7["badge"]
                and "aprobador" in t7["trail"].lower() and "publicar" in t7["actions"].lower() and not navs,
                f"send={t7_send} status={status_of(fid7)} navs={navs} {t7}")
        # T7b — publicar en la misma ficha.
        navs.clear()
        open_slot_form(v, "/publish"); v.page.wait_for_timeout(800)
        t7b = v.page.evaluate("""() => ({badge:document.querySelector('#vfSlotStatusBadge')?.innerText.trim(),
            share:document.querySelector('#vfSlotShare')?.innerText||'', qr:!!document.querySelector('#vfSlotShare canvas, #vfSlotShare img, #vfSlotShare svg')})""")
        cond = bool(status_of(fid7) == "published" and "Publicado" in t7b["badge"] and not navs
                and (t7b["qr"] or "http" in t7b["share"]))
        R.results["T7b"] = {"status": "OK" if cond else "FALLA", "detail": f"navs={navs} {t7b}", "at": __import__("time").strftime("%Y-%m-%d %H:%M:%S")}
        vf.save_results(R.results)

    # T8 — devuelto a borrador con traza.
    fid8 = new_form(uh, f"[BT] T devolver {suf}", owner=4)
    with vf.VF(R, email="nuevo@demo.com") as v:
        save_struct(v, fid8, f"[BT] T devolver {suf}", [item("t1", "text", "Campo devolver")])
        v.goto(f"/forms/{fid8}", wait=700)
        open_slot_form(v, "/review/send"); v.page.wait_for_timeout(800)
    with vf.VF(R, email="aprobador@demo.com") as v:
        v.goto("/review", wait=800)
        card = v.page.locator(".card").filter(has_text=f"[BT] T devolver {suf}").first
        card.locator("form[action$='/review/return'] button").first.evaluate("e => e.click()")
        v.page.wait_for_timeout(300); confirm(v, "Devolver")
    with vf.VF(R, email="nuevo@demo.com") as v:
        v.goto(f"/forms/{fid8}", wait=700)
        t8 = v.page.evaluate("""() => ({badge:document.querySelector('#vfSlotStatusBadge')?.innerText.trim(),
            trail:document.querySelector('#vfSlotReviewTrail')?.innerText||'', actions:document.querySelector('#vfSlotActions')?.innerText||''})""")
        R.check("T8", status_of(fid8) == "draft" and "Borrador" in t8["badge"] and "aprobador" in t8["trail"].lower()
                and "publicar" in t8["actions"].lower(), f"status={status_of(fid8)} {t8}")

    # T9 — sin JS, publicar es POST normal con 302 y aviso.
    fid9 = new_form(ah, f"[BT] T sinjs {suf}")
    s, html, url = publish_http(ah, fid9)
    R.check("T9", status_of(fid9) == "published" and "Publicado" in html and ("guard" in html.lower() or "formulario" in html.lower()),
            f"http={s} status={status_of(fid9)} url={url}")

    # T10 — sin JS, el segundo toggle es idempotente (active=0).
    pw = vf.db1("SELECT password_hash FROM users WHERE id=1")[0]
    vf.mysql(f"INSERT INTO users (name,email,password_hash,role_id,is_active) VALUES ('[BT] T Toggle','ttoggle@bt.local','{pw}',2,1)")
    uid = int(vf.db1("SELECT id FROM users WHERE email='ttoggle@bt.local'")[0]); users.append(uid)
    html = ah.get("/admin?q=ttoggle")[1]
    tok = ah.csrf(html)
    vf.urllib.request.urlopen  # noqa
    for _ in range(2):
        ah.post("/admin/users/toggle", {"_token": tok, "user_id": uid, "active": 0})
    R.check("T10", vf.db1(f"SELECT is_active FROM users WHERE id={uid}")[0] == "0", f"uid={uid}")

    # T11/T12 — desactivar sin recarga y guardar rol/grupos sobre el estado nuevo.
    uid = users[-1]
    with vf.VF(R) as v:
        v.goto("/admin?q=ttoggle", wait=800)
        v.page.locator(f"button[data-vf-user='{uid}']").evaluate("e => e.click()")
        v.page.wait_for_timeout(300)
        navs = []
        v.page.on("framenavigated", lambda f: navs.append(f.url) if f == v.page.main_frame else None)
        v.page.locator("form[action$='/toggle'] button").first.evaluate("e => e.click()")
        v.page.wait_for_timeout(250); confirm(v)
        v.page.wait_for_timeout(500)
        t11 = v.page.evaluate(f"""() => ({{status:document.querySelector('#vfUserStatus{uid}')?.innerText.trim(),
            panel:document.querySelector('#rowactions-{uid}')?.innerText||''}})""")
        R.check("T11", vf.db1(f"SELECT is_active FROM users WHERE id={uid}")[0] == "0" and not navs
                and "Inactivo" in t11["status"], f"navs={navs} {t11}")
        # T12 — el panel sigue abierto y el select de rol se puede guardar.
        rollback = v.page.locator("select[id^='role-']").first
        rollback.select_option("3")
        navs.clear()
        v.page.locator("form[action$='/role']").first.evaluate("f => f.requestSubmit()")
        v.page.wait_for_timeout(700)
        t12 = v.page.evaluate(f"""() => ({{open:true,
            role:document.querySelector('select[name=role_id]')?.value||'',
            badge:document.querySelector('#vfUserRole{uid}')?.innerText.trim()||''}})""")
        R.check("T12", vf.db1(f"SELECT role_id FROM users WHERE id={uid}")[0] == "3" and t12["open"]
                and t12["role"] == "3" and "editor" in t12["badge"].lower(), f"navs={navs} {t12}")

    # T13 — quitar email de allowlist sin recarga.
    fid13 = new_form(ah, f"[BT] T allowlist {suf}")
    settings(fid13, {"access": {"mode": "allowlist"}})
    vf.mysql(f"INSERT INTO form_access_emails (form_id,email) VALUES ({fid13},'quitar@bt.local')")
    with vf.VF(R) as v:
        v.goto(f"/forms/{fid13}/access", wait=800)
        navs = []
        v.page.on("framenavigated", lambda f: navs.append(f.url) if f == v.page.main_frame else None)
        v.page.locator("form[action$='/remove'] button").first.evaluate("e => e.click()")
        v.page.wait_for_timeout(250); confirm(v, "Quitar")
        v.page.wait_for_timeout(500)
        t13 = v.page.evaluate("""() => ({list:document.querySelector('#accessList')?.innerText||'', toast:document.querySelector('#toastWrap')?.innerText||''})""")
        R.check("T13", not navs and "quitar@bt.local" not in t13["list"] and t13["toast"].strip() != ""
                and vf.db1(f"SELECT COUNT(*) FROM form_access_emails WHERE form_id={fid13}")[0] == "0",
                f"navs={navs} {t13}")

    # Formulario con dos respuestas para T14 y con una para T15/T16.
    fid14 = new_form(ah, f"[BT] T respuestas {suf}")
    with vf.VF(R) as v:
        save_struct(v, fid14, f"[BT] T respuestas {suf}", [item("t1", "text", "Campo respuesta")])
    publish_http(ah, fid14)
    slug14 = slug_of(fid14)
    submit_fill(fid14, slug14, "uno"); submit_fill(fid14, slug14, "dos")
    with vf.VF(R) as v:
        v.goto(f"/forms/{fid14}/responses", wait=800)
        n0 = v.page.locator("tr[data-vf-row]").count()
        navs = []
        v.page.on("framenavigated", lambda f: navs.append(f.url) if f == v.page.main_frame else None)
        v.page.locator("form[action$='/delete'] button").first.evaluate("e => e.click()")
        v.page.wait_for_timeout(250); confirm(v, "Borrar")
        v.page.wait_for_timeout(500)
        t14 = v.page.evaluate("""() => ({rows:document.querySelectorAll('tr[data-vf-row]').length,
            count:document.querySelector('[data-vf-role=response-count]')?.innerText||'', toast:document.querySelector('#toastWrap')?.innerText||''})""")
        R.check("T14", not navs and t14["rows"] == n0 - 1 and "1" in t14["count"] and t14["toast"].strip() != "",
                f"n0={n0} {t14}")

    fid15 = new_form(ah, f"[BT] T ultima {suf}")
    with vf.VF(R) as v:
        save_struct(v, fid15, f"[BT] T ultima {suf}", [item("t1", "text", "Campo última")])
    publish_http(ah, fid15); slug15 = slug_of(fid15)
    submit_fill(fid15, slug15, "solo")
    with vf.VF(R) as v:
        v.goto(f"/forms/{fid15}/responses", wait=800)
        navs = []
        v.page.on("framenavigated", lambda f: navs.append(f.url) if f == v.page.main_frame else None)
        v.page.locator("form[action$='/delete'] button").first.evaluate("e => e.click()")
        v.page.wait_for_timeout(250); confirm(v, "Borrar")
        v.page.wait_for_timeout(1000)
        t15 = {"rows": v.page.locator("tr[data-vf-row]").count(),
               "empty": "aún no tiene respuestas" in v.text(),
               "count": v.page.locator("[data-vf-role=response-count]").inner_text() if v.page.locator("[data-vf-role=response-count]").count() else ""}
        R.check("T15", len(navs) >= 1 and t15["rows"] == 0 and t15["empty"], f"navs={navs} {t15}")

    # T16 — sin JS, borrar respuesta recarga con aviso verde.
    fid16 = new_form(ah, f"[BT] T sinjsresp {suf}")
    with vf.VF(R) as v:
        save_struct(v, fid16, f"[BT] T sinjsresp {suf}", [item("t1", "text", "Campo sin js")])
    publish_http(ah, fid16); slug16 = slug_of(fid16)
    submit_fill(fid16, slug16, "para borrar")
    html = ah.get(f"/forms/{fid16}/responses")[1]
    tok = ah.csrf(html)
    sid = vf.db1(f"SELECT id FROM submissions WHERE form_id={fid16} LIMIT 1")[0]
    s, html2, url2 = ah.post(f"/forms/{fid16}/responses/{sid}/delete", {"_token": tok})
    R.check("T16", "Respuesta eliminada" in html2 and "aún no tiene respuestas" in html2
            and vf.db1(f"SELECT COUNT(*) FROM submissions WHERE form_id={fid16}")[0] == "0",
            f"http={s} url={url2}")

    # T17 — falla el fetch: rescate a POST nativo y la operación no se repite.
    fid17 = new_form(ah, f"[BT] T offline {suf}")
    with vf.VF(R) as v:
        save_struct(v, fid17, f"[BT] T offline {suf}", [item("t1", "text", "Campo offline")])
        v.goto(f"/forms/{fid17}", wait=700)
        def rutear(route):
            if route.request.headers.get("x-requested-with"):
                route.abort()
            else:
                route.continue_()
        v.page.route("**/forms/%d/publish" % fid17, rutear)
        v.page.locator("form[action$='/publish'] button").first.evaluate("e => e.click()")
        v.page.wait_for_timeout(1200)
        t17 = {"status": status_of(fid17), "url": v.page.url, "ok": "Publicado" in v.text()}
        R.check("T17", t17["status"] == "published" and t17["ok"], str(t17))

    # T24 — «En revisión» con espacio en dashboard y admin.
    fid24 = new_form(ah, f"[BT] T enrevision {suf}")
    vf.mysql(f"UPDATE forms SET status='in_review', review_requested_at=NOW() WHERE id={fid24}")
    with vf.VF(R) as v:
        v.goto(f"/dashboard?q={vf.urllib.parse.quote('T enrevision')}", wait=800)
        dash = v.text()
        v.goto("/admin/forms?q=T%20enrevision", wait=800)
        adm = v.text()
        R.check("T24", "En revisión" in dash and "En revisión" in adm
                and "In_review" not in dash and "In_review" not in adm, f"dash={('En revisión' in dash)} admin={('En revisión' in adm)}")

    # T22: el fill no expone un camino AJAX de validación; se comprueba por HTTP
    # que un POST inválido repinta errores y conserva lo escrito (PRG normal).
    fid22 = new_form(ah, f"[BT] T validacion {suf}")
    with vf.VF(R) as v:
        save_struct(v, fid22, f"[BT] T validacion {suf}", [item("t1", "text", "Campo obligatorio")])
        ids = vf.mysql(f"SELECT id FROM fields WHERE form_id={fid22}")
        vf.mysql(f"UPDATE fields SET is_required=1 WHERE form_id={fid22}")
    publish_http(ah, fid22); slug22 = slug_of(fid22)
    hh = vf.Http(); s0, hp, _ = hh.get(f"/f/{slug22}"); tk = hh.csrf(hp)
    fld22 = vf.db1(f"SELECT id FROM fields WHERE form_id={fid22}")[0]
    s, hp2, _ = hh.req("POST", f"/f/{slug22}", {"_token": tk, f"field_{fld22}": ""})
    R.check("T22", s == 200 and ("obligatorio" in hp2.lower() or "required" in hp2.lower() or "error" in hp2.lower()),
            f"http={s} no hay AJAX de fill; se valida el repintado del POST")

finally:
    try:
        if made:
            vf.mysql("DELETE FROM forms WHERE id IN (" + ",".join(str(x) for x in set(made)) + ")")
        if users:
            vf.mysql("DELETE FROM users WHERE id IN (" + ",".join(str(x) for x in set(users)) + ")")
    except Exception as e:
        print("cleanup:", e)

print("BLOQUE T:", R.report())
