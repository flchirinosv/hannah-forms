#!/usr/bin/env python3
"""Bloque U: el diálogo de confirmación y las altas sin recarga."""

import json, re, sys, uuid
sys.path.insert(0, "tests/browser")
import vf

R = vf.R()
ah = vf.Http("admin@demo.com")
suf = uuid.uuid4().hex[:8]
made = []
users = []
groups = []

def esc(s):
    return str(s).replace("\\", "\\\\").replace("'", "\\'")

def new_form(h, title):
    t = h.csrf(h.get("/dashboard")[1])
    r = h.post("/forms", {"_token": t, "title": title, "description": ""})
    m = re.search(r"/forms/(\d+)/builder", r[2])
    if not m:
        raise RuntimeError("no se creó " + title)
    fid = int(m.group(1)); made.append(fid)
    return fid

def allowlist(fid, emails=()):
    s = json.dumps({"access": {"mode": "allowlist"}}, ensure_ascii=False)
    vf.mysql(f"UPDATE forms SET settings='{esc(s)}' WHERE id={fid}")
    for e in emails:
        vf.mysql(f"INSERT INTO form_access_emails (form_id,email) VALUES ({fid},'{esc(e)}')")

def count_rows(fid):
    return int(vf.db1(f"SELECT COUNT(*) FROM form_access_emails WHERE form_id={fid}")[0])

def dialog_state(v):
    return v.page.evaluate("""() => ({show:!!document.querySelector('#vfConfirm.show'),
        title:document.querySelector('#vfConfirmTitle')?.innerText.trim()||'',
        text:document.querySelector('#vfConfirmText')?.innerText.trim()||'',
        ok:document.querySelector('#vfConfirmOk')?.innerText.trim()||'',
        tone:document.querySelector('#vfConfirmOk')?.className||'',
        labelled:document.querySelector('#vfConfirm')?.getAttribute('aria-labelledby')||'',
        described:document.querySelector('#vfConfirm')?.getAttribute('aria-describedby')||'',
        focus:document.activeElement?.id||document.activeElement?.tagName||''})""")

def click_delete(v):
    v.page.locator("form[action$='/delete'] button").first.evaluate("e => e.click()")
    v.page.wait_for_timeout(350)

def confirm(v, text=None):
    v.page.wait_for_selector("#vfConfirm.show", timeout=3000)
    if text:
        v.page.locator("#vfConfirmOk").filter(has_text=text).first.evaluate("e => e.click()")
    else:
        v.page.locator("#vfConfirmOk").evaluate("e => e.click()")
    v.page.wait_for_timeout(700)

try:
    vf.mysql("DELETE FROM forms WHERE title LIKE '[BT] U %'")
    vf.mysql("DELETE FROM users WHERE email LIKE 'u%@bt.local'")
    vf.mysql("DELETE FROM rate_limits WHERE scope='dash:create'")

    # U1–U5 — el diálogo de eliminar formulario.
    fid = new_form(ah, f"[BT] U borrar {suf}")
    with vf.VF(R) as v:
        v.goto(f"/forms/{fid}", wait=700)
        click_delete(v)
        d1 = dialog_state(v)
        R.check("U1", d1["show"] and "Eliminar" in d1["text"] and d1["ok"] == "Eliminar formulario"
                and "btn-danger" in d1["tone"], str(d1))
        v.page.locator("#vfConfirm").evaluate("e => e.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true,cancelable:true}))"); v.page.wait_for_timeout(500)
        R.check("U2", not dialog_state(v)["show"] and int(vf.db1(f"SELECT COUNT(*) FROM forms WHERE id={fid}")[0]) == 1,
                str(dialog_state(v)))
        click_delete(v); v.page.locator("#vfConfirmCancel").evaluate("e => e.click()"); v.page.wait_for_timeout(400)
        still = int(vf.db1(f"SELECT COUNT(*) FROM forms WHERE id={fid}")[0]) == 1
        click_delete(v); confirm(v, "Eliminar formulario")
        R.check("U3", still and int(vf.db1(f"SELECT COUNT(*) FROM forms WHERE id={fid}")[0]) == 0,
                f"still={still}")
        fid = new_form(ah, f"[BT] U foco {suf}")
        v.goto(f"/forms/{fid}", wait=700); click_delete(v)
        foco = dialog_state(v)["focus"]
        dentro = True
        for _ in range(8):
            v.page.keyboard.press("Tab"); v.page.wait_for_timeout(60)
            dentro = dentro and v.page.evaluate("() => !!document.activeElement?.closest('#vfConfirm')")
        R.check("U4", foco == "vfConfirmCancel" and dentro, f"foco={foco} dentro={dentro}")
        d5 = dialog_state(v)
        R.check("U5", d5["labelled"] == "vfConfirmTitle" and d5["described"] == "vfConfirmText"
                and "Eliminar" in d5["text"], str(d5))
        v.page.locator("#vfConfirmCancel").evaluate("e => e.click()")

        # U6/U7 — tono y texto del botón según la acción (markup de las vistas).
        src = vf.Path("views").rglob("*.php")
        html = "\n".join(p.read_text(errors="ignore") for p in src)
        primary = re.findall(r'data-confirm-ok="([^"]+)"[^>]*data-confirm-tone="primary"', html)
        danger = re.findall(r'data-confirm-ok="([^"]+)"(?![^>]*data-confirm-tone)', html)
        publicar = 'data-confirm-ok="Publicar"' in html
        R.check("U6", publicar and any("Aprobar" in x for x in primary) and any("Activar" in x for x in primary)
                and any("Devolver" in x for x in primary), f"publicar={publicar} primary={primary[:6]}")
        R.check("U7", any("Eliminar formulario" in x for x in danger) and any("Quitar" in x for x in danger),
                f"danger={danger[:8]}")

        # U8–U14 — allowlist en la pantalla de participantes.
        fid = new_form(ah, f"[BT] U lista {suf}")
        allowlist(fid, [f"ya-{suf}@bt.local"])
        v.goto(f"/forms/{fid}/access", wait=700)
        url0 = v.page.url
        navs = []
        v.page.on("framenavigated", lambda f: navs.append(f.url) if f == v.page.main_frame else None)
        v.page.locator("#accessAddEmail").fill(f"uno-{suf}@bt.local")
        v.page.locator("#accessAddBtn").evaluate("e => e.click()")
        v.page.wait_for_timeout(900)
        txt = v.page.locator("#accessList").inner_text()
        toast = v.page.evaluate("() => document.querySelector('#toastWrap')?.innerText||''")
        R.check("U8", not navs and v.page.url == url0 and f"uno-{suf}@bt.local" in txt
                and count_rows(fid) == 2 and "añadió" in toast.lower(), f"navs={navs} toast={toast!r}")
        v.page.locator("#accessAddEmail").fill(f"dos-{suf}@bt.local")
        v.page.locator("#accessAddBtn").evaluate("e => e.click()")
        v.page.wait_for_timeout(800)
        pos = v.page.evaluate("() => ({v:document.querySelector('#accessAddEmail')?.value, foco:document.activeElement?.id})")
        R.check("U9", pos["v"] == "" and pos["foco"] == "accessAddEmail" and count_rows(fid) == 3, str(pos))
        v.page.locator("#accessAddEmail").fill(f"ya-{suf}@bt.local")
        v.page.locator("#accessAddBtn").evaluate("e => e.click()")
        v.page.wait_for_timeout(800)
        err = v.page.evaluate("() => ({txt:document.querySelector('#accessAddError')?.innerText||'',vis:!document.querySelector('#accessAddError')?.classList.contains('d-none'),toast:document.querySelector('#toastWrap')?.innerText||''})")
        R.check("U10", err["vis"] and "ya estaba" in err["txt"] and count_rows(fid) == 3 and "ya estaba" not in err["toast"], str(err))
        v.page.locator("#accessAddEmail").fill(f"tres-{suf}@bt.local")
        v.page.locator("#accessAddBtn").evaluate("e => e.click()")
        v.page.wait_for_timeout(800)
        err2 = v.page.evaluate("() => ({vis:!document.querySelector('#accessAddError')?.classList.contains('d-none'),txt:document.querySelector('#accessAddError')?.innerText||''})")
        R.check("U11", not err2["vis"] and count_rows(fid) == 4, str(err2))
        R.na("U12", "Viewport móvil no fiable en Lightpanda; cubierto por CSS/test de estructura")
        # U13 — sin JS, el POST nativo guarda y deja el aviso.
        h = vf.Http("admin@demo.com")
        s, html, _ = h.get(f"/forms/{fid}/access"); tok = h.csrf(html)
        s, html, url = h.req("POST", f"/forms/{fid}/access/email", {"_token": tok, "email": f"sinjs-{suf}@bt.local"})
        R.check("U13", count_rows(fid) == 5 and f"sinjs-{suf}@bt.local" in html, f"status={s} url={url}")
        # U14 — quitar y añadir seguidos.
        v.page.locator("form[action$='/remove'] button").first.evaluate("e => e.click()")
        v.page.wait_for_timeout(250); confirm(v, "Quitar")
        before = count_rows(fid)
        v.page.locator("#accessAddEmail").fill(f"cuatro-{suf}@bt.local")
        v.page.locator("#accessAddBtn").evaluate("e => e.click()")
        v.page.wait_for_timeout(800)
        R.check("U14", before == 4 and count_rows(fid) == 5 and f"cuatro-{suf}@bt.local" in v.page.locator("#accessList").inner_text(),
                f"before={before} after={count_rows(fid)}")

        # U15 — sin JS, el borrado nativo no pregunta.
        s, html, url = ah.req("POST", f"/forms/{fid}/delete", {"_token": ah.csrf(ah.get(f"/forms/{fid}")[1])})
        R.check("U15", int(vf.db1(f"SELECT COUNT(*) FROM forms WHERE id={fid}")[0]) == 0, f"status={s}")

        # U16 — cambiar el modo de acceso guarda sin recargar.
        fid = new_form(ah, f"[BT] U modo {suf}")
        v.goto(f"/forms/{fid}", wait=700)
        navs.clear()
        v.page.locator("#access_mode").select_option("roster")
        v.page.locator("#formSettings").evaluate("f=>f.requestSubmit()")
        v.page.wait_for_timeout(1000)
        st = vf.db1(f"SELECT settings FROM forms WHERE id={fid}")[0]
        R.check("U16", not navs and '"mode":"roster"' in (st or "").replace(" ", ""), f"navs={navs} settings={st}")

        # U17–U19 — rol y grupos de /admin sin recarga.
        email = f"u{suf}@bt.local"
        vf.mysql("INSERT INTO users (role_id,name,email,password_hash,is_active,created_at,updated_at,password_changed_at) "
                 f"SELECT 2,'U Fila {suf}','{email}',password_hash,1,NOW(),NOW(),password_changed_at FROM users WHERE id=1")
        uid = int(vf.db1(f"SELECT id FROM users WHERE email='{email}'")[0]); users.append(uid)
        v.goto(f"/admin?q={email}", wait=700)
        navs.clear()
        v.page.locator(f'button[data-vf-user="{uid}"]').first.evaluate("e=>e.click()")
        v.page.wait_for_timeout(400)
        v.page.locator(f"#role-{uid}").select_option("3")
        v.page.wait_for_timeout(1000)
        rol = vf.db1(f"SELECT role_id FROM users WHERE id={uid}")[0]
        ins = v.page.locator(f"#vfUserRole{uid}").inner_text().strip() if v.page.locator(f"#vfUserRole{uid}").count() else ""
        R.check("U17", not navs and rol == "3" and "editor" in ins.lower(), f"navs={navs} rol={rol} badge={ins}")

        gname = f"Grupo U {suf}"
        vf.mysql(f"INSERT INTO role_groups (name,description) VALUES ('{esc(gname)}','')")
        gid = int(vf.db1(f"SELECT id FROM role_groups WHERE name='{esc(gname)}'")[0]); groups.append(gid)
        emailg = f"ug{suf}@bt.local"
        vf.mysql("INSERT INTO users (role_id,name,email,password_hash,is_active,created_at,updated_at,password_changed_at) "
                 f"SELECT 2,'U Grupo {suf}','{emailg}',password_hash,1,NOW(),NOW(),password_changed_at FROM users WHERE id=1")
        uidg = int(vf.db1(f"SELECT id FROM users WHERE email='{emailg}'")[0]); users.append(uidg)
        v.goto(f"/admin?q={emailg}", wait=700); navs.clear()
        v.page.locator(f'button[data-vf-user="{uidg}"]').first.evaluate("e=>e.click()")
        v.page.wait_for_timeout(400)
        v.page.locator(f'form[action$="/groups"] input[name="groups[]"][value="{gid}"]').evaluate("e=>{e.checked=true;e.dispatchEvent(new Event('change',{bubbles:true}))}")
        v.page.locator('form[action$="/groups"]').first.evaluate("f=>f.requestSubmit()")
        v.page.wait_for_timeout(900)
        en = int(vf.db1(f"SELECT COUNT(*) FROM role_group_users WHERE group_id={gid} AND user_id={uidg}")[0])
        badges = v.page.locator(f"#vfUserGroups{uidg}").inner_text() if v.page.locator(f"#vfUserGroups{uidg}").count() else ""
        R.check("U18", not navs and en == 1 and gname in badges, f"navs={navs} en={en} badge={badges}")

        # U19 — cambiar rol justo después de abrir los grupos no pierde lo marcado.
        email2 = f"u2{suf}@bt.local"
        vf.mysql("INSERT INTO users (role_id,name,email,password_hash,is_active,created_at,updated_at,password_changed_at) "
                 f"SELECT 2,'U Fila2 {suf}','{email2}',password_hash,1,NOW(),NOW(),password_changed_at FROM users WHERE id=1")
        uid2 = int(vf.db1(f"SELECT id FROM users WHERE email='{email2}'")[0]); users.append(uid2)
        v.goto(f"/admin?q={email2}", wait=700)
        navs.clear()
        v.page.locator(f'button[data-vf-user="{uid2}"]').first.evaluate("e=>e.click()")
        v.page.wait_for_timeout(300)
        v.page.locator(f"#role-{uid2}").select_option("3")
        v.page.locator(f'form[action$="/groups"] input[name="groups[]"][value="{gid}"]').evaluate("e=>{e.checked=true;e.dispatchEvent(new Event('change',{bubbles:true}))}")
        v.page.locator('form[action$="/groups"]').first.evaluate("f=>f.requestSubmit()")
        v.page.wait_for_timeout(1000)
        rol2 = vf.db1(f"SELECT role_id FROM users WHERE id={uid2}")[0]
        en2 = int(vf.db1(f"SELECT COUNT(*) FROM role_group_users WHERE group_id={gid} AND user_id={uid2}")[0])
        modal = v.page.evaluate("()=>document.querySelector('#userActionsModal')?.classList.contains('show')")
        R.check("U19", not navs and rol2 == "3" and en2 == 1 and modal, f"navs={navs} rol={rol2} en={en2} modal={modal}")

        # U20 — sin JS, rol y grupo por POST nativo.
        h = vf.Http("admin@demo.com")
        html = h.get("/admin")[1]; tok = h.csrf(html)
        h.req("POST", "/admin/users/role", {"_token": tok, "user_id": uid2, "role_id": 2})
        html = h.get("/admin")[1]; tok = h.csrf(html)
        h.req("POST", "/admin/users/groups", {"_token": tok, "user_id": uid2, "groups[]": gid})
        rol3 = vf.db1(f"SELECT role_id FROM users WHERE id={uid2}")[0]
        en3 = int(vf.db1(f"SELECT COUNT(*) FROM role_group_users WHERE group_id={gid} AND user_id={uid2}")[0])
        R.check("U20", rol3 == "2" and en3 == 1, f"rol={rol3} en={en3}")
finally:
    for fid in made:
        vf.mysql(f"DELETE FROM forms WHERE id={int(fid)}")
    vf.mysql("DELETE FROM forms WHERE title LIKE '[BT] U %'")
    for gid in groups:
        vf.mysql(f"DELETE FROM role_groups WHERE id={int(gid)}")
    for uid in users:
        vf.mysql(f"DELETE FROM users WHERE id={int(uid)}")
    vf.mysql("DELETE FROM users WHERE email LIKE 'u%@bt.local'")
    print("BLOQUE U:", R.report())
