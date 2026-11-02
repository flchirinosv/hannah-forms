#!/usr/bin/env python3
"""Bloque AD: consola de administración, ajustes, modales y sin recarga."""

import json, re, sys, uuid, urllib.parse
from pathlib import Path
sys.path.insert(0, "tests/browser")
import vf

R = vf.R()
suf = uuid.uuid4().hex[:6]
U1 = f"ad_uno_{suf}@bt.local"
U2 = f"ad_dos_{suf}@bt.local"
UL = f"ad_lim_{suf}@bt.local"
N1, N2 = f"AD Uno {suf}", f"AD Dos {suf}"
GNAME = f"AD Grupo {suf}"
GNAT = f"AD Nativo {suf}"
users, groups = [], []


def q(v):
    return "'" + str(v).replace("\\", "\\\\").replace("'", "''") + "'"


def mkuser(h, name, email):
    tok = h.csrf(h.get("/admin")[1])
    s, _, _ = h.post("/admin/users/create", {
        "_token": tok, "name": name, "email": email,
        "password": "password123", "password_confirm": "password123", "role_id": "2",
    })
    row = vf.db1("SELECT id FROM users WHERE email=" + q(email))
    uid = int(row[0]) if row else 0
    if uid: users.append(uid)
    return uid, s


def clean():
    if groups:
        vf.mysql("DELETE FROM role_groups WHERE id IN (" + ",".join(map(str, groups)) + ")")
    if users:
        vf.mysql("DELETE FROM users WHERE id IN (" + ",".join(map(str, users)) + ")")


try:
    ah = vf.Http("admin@demo.com")
    uid1, _ = mkuser(ah, N1, U1)
    uid2, _ = mkuser(ah, N2, U2)
    uidL, _ = mkuser(ah, f"AD Lim {suf}", UL)
    R.check("AD14", uid1 > 0 and uid2 > 0 and uidL > 0,
            f"altas nativas por POST: {uid1},{uid2},{uidL}")

    # AD1 — misma barra que /admin/forms.
    s1, log_html, _ = ah.get("/admin/security-log")
    s2, forms_html, _ = ah.get("/admin/forms")
    css = Path("public/assets/css/app.css").read_text()
    R.check("AD1", s1 == 200 and s2 == 200
            and 'input-group input-group-sm app-dash-search' in log_html
            and 'input-group input-group-sm app-dash-search' in forms_html
            and 'app-dash-sort' in log_html and 'app-dash-sort' in forms_html
            and 'app-dash-sort-wide' in log_html
            and ".form-select.app-dash-sort-wide { max-width: 320px; }" in css,
            "buscador + desplegable con ancho acotado como /admin/forms")

    # AD3 — las dos tarjetas de instancia y no están en usuarios.
    s3, set_html, _ = ah.get("/admin/settings")
    _, users_html, _ = ah.get("/admin")
    R.check("AD3", s3 == 200 and 'id="instance"' in set_html
            and 'action="/admin/settings/review"' in set_html
            and "Revisión de formularios" in set_html
            and "Revisión de formularios" not in users_html
            and "Acceso de cualquiera" not in users_html,
            "registro y revisión viven en /admin/settings, no en la ficha de usuarios")

    # AD4 — sin admin.access: 403 y sin pestaña.
    lh = vf.Http(UL, "password123")
    s4, _, _ = lh.get("/admin/settings")
    _, dash_html, _ = lh.get("/dashboard")
    R.check("AD4", s4 == 403 and "/admin/settings" not in dash_html
            and "Administración" not in dash_html,
            f"status={s4}")

    # AD5 — PRG a la misma pantalla.
    tok5 = ah.csrf(set_html)
    req_on = bool(re.search(r'id="reviewRequiredToggle"[^>]*\bchecked\b', set_html))
    mail_on = bool(re.search(r'id="reviewNotifyToggle"[^>]*\bchecked\b', set_html))
    s5, body5, url5 = ah.post("/admin/settings/review", {
        "_token": tok5,
        "review_required": "1" if req_on else "0",
        "review_notify_email": "1" if mail_on else "0",
    })
    R.ok("AD5", f"status={s5} destino={url5.split('://')[-1]} aviso={'guard' if 'Revisi' in body5 else '?'}")

    with vf.Browser() as B:
        u = B.session(R, "admin@demo.com")
        with u:
            p = u.page
            u.goto("/admin")

            # AD2
            tabs = p.locator(".app-admin-nav .nav-link")
            labels = [t.inner_text().strip() for t in tabs.all()]
            R.check("AD2", tabs.count() == 6 and labels[-1].endswith("Ajustes de la instancia")
                    and "/admin/settings" in (tabs.last.get_attribute("href") or ""),
                    f"tabs={labels}")

            # AD16 — bloque de usuario del pie de la sidebar.
            p.locator("[data-bs-target='#sidebarUserMenu']").evaluate("e => e.click()")
            p.wait_for_timeout(500)
            menu = p.locator("#sidebarUserMenu")
            expanded = p.locator("[data-bs-target='#sidebarUserMenu']").get_attribute("aria-expanded")
            menu_text = menu.inner_text()
            order = p.evaluate("() => {const m=document.getElementById('sidebarUserMenu');"
                               "const b=document.querySelector('[data-bs-target=\"#sidebarUserMenu\"]');"
                               "return !!(m && b && (m.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING));}")
            R.check("AD16", "show" in (menu.get_attribute("class") or "")
                    and expanded == "true" and "admin@demo.com" in menu_text
                    and "Seguridad de mi cuenta" in menu_text and "Cerrar sesión" in menu_text
                    and order and ".app-sidebar-user-btn {" in css
                    and "width: 100%;" in css and "transform: rotate(180deg);" in css,
                    f"aria-expanded={expanded} orden_menu_antes_boton={order}")

            # AD6 — grupos plegables y resumen.
            u.goto("/admin/groups")
            cards = p.locator("[data-vf-row]")
            summary = p.locator("[data-vf-row] [id^='vfGroupSummary']").first
            toggles = p.locator("[data-vf-row] .app-group-toggle")
            body_txt = p.locator("body").inner_text()
            sum_badges = summary.locator(".badge").count()
            collapsed = p.locator("[data-vf-row] .collapse").first.get_attribute("class") or ""
            R.check("AD6", cards.count() >= 2 and toggles.count() == cards.count()
                    and "show" in collapsed and sum_badges >= 2
                    and "Lo que otorga este grupo" in body_txt,
                    f"tarjetas={cards.count()} toggles={toggles.count()} badges={sum_badges} "
                    f"collapse={collapsed!r} efecto={'Lo que otorga este grupo' in body_txt}")

            # AD7 — alta en modal.
            p.locator("[data-bs-target='#newGroupModal']").first.evaluate("e => e.click()")
            p.wait_for_timeout(500)
            modal_form = p.locator("#newGroupModal [data-vf-modal-body] form").count()
            p.locator("#newGroupModal #groupName").fill(GNAME)
            p.locator("#newGroupModal #groupDesc").fill("Grupo temporal de pruebas")
            marker = p.evaluate("() => {window.__admark=1; return 1;}")
            p.locator("#newGroupModal form[action='/admin/groups/create'] button[type=submit]").evaluate("e => e.click()")
            p.wait_for_timeout(1000)
            grow = vf.db1("SELECT id FROM role_groups WHERE name=" + q(GNAME))
            gid = int(grow[0]) if grow else 0
            if gid: groups.append(gid)
            R.check("AD7", modal_form == 1 and gid > 0,
                    f"form_movido={modal_form} gid={gid} marker={marker}")

            # AD8 — guardar grupo sin recarga, repinta resumen y efecto.
            u.goto("/admin/groups")
            p.evaluate("() => {window.__admark=1}")
            desc8 = f"Descripción nueva {suf}"
            p.locator(f"#gdesc-{gid}").fill(desc8)
            p.locator(f"form[action='/admin/groups/update'] input[name='group_id'][value='{gid}']").locator("xpath=..").locator("button[type=submit]").evaluate("e => e.click()")
            p.wait_for_timeout(1200)
            marker8 = p.evaluate("() => window.__admark || null")
            sum_txt = p.locator(f"#vfGroupSummary{gid}").inner_text()
            R.check("AD8", marker8 == 1 and desc8 in sum_txt and p.url.endswith("/admin/groups"),
                    f"sin_recarga={marker8} resumen={sum_txt[:80]!r}")

            # AD9 — alta de usuario en modal, una columna y sin JS en línea.
            u.goto("/admin")
            p.locator("[data-bs-target='#newUserModal']").first.evaluate("e => e.click()")
            p.wait_for_timeout(500)
            cols = p.locator("#newUserModal [data-vf-modal-body] .col-12").count()
            bad = p.locator("#newUserModal [data-vf-modal-body] .col-lg-6, #newUserModal [data-vf-modal-body] .col-md-6").count()
            R.check("AD9", p.locator("#newUserModal #newUserName").count() == 1
                    and cols >= 5 and bad == 0
                    and 'id="newUserFormMount" data-vf-modal-move="#newUserModal"' in p.content(),
                    f"columnas={cols} otras={bad}")

            # AD10 — modal de acciones con el panel de SU fila.
            u.goto("/admin?q=" + urllib.parse.quote(U1))
            row1 = p.locator("tr[data-vf-row]").filter(has_text=U1)
            row1.locator(f"button[data-vf-user='{uid1}']").evaluate("e => e.click()")
            p.wait_for_timeout(500)
            modal = p.locator("#userActionsModal")
            title = p.locator("#userActionsModalLabel").inner_text()
            panel = p.locator(f"#userActionsModal .app-row-menu")
            cols10 = p.locator(f"#userActionsModal .app-row-menu > .row > div").count()
            R.check("AD10", "show" in (modal.get_attribute("class") or "") and N1 in title
                    and panel.count() == 1 and cols10 == 3
                    and p.locator(f"#userActionsModal .col-lg-4").count() >= 3,
                    f"titulo={title!r} columnas={cols10} paneles={panel.count()}")

            # AD11 — cambio de rol por fetch, sin recarga.
            p.evaluate("() => {window.__admark=1}")
            p.locator(f"#userActionsModal #role-{uid1}").select_option("3")
            p.wait_for_timeout(1200)
            role_db = vf.db1("SELECT role_id FROM users WHERE id=" + str(uid1))[0]
            marker11 = p.evaluate("() => window.__admark || null")
            badge = p.locator(f"#vfUserRole{uid1}").inner_text()
            R.check("AD11", role_db == "3" and marker11 == 1 and "editor" in badge.lower(),
                    f"role={role_db} sin_recarga={marker11} insignia={badge!r}")

            # AD12 — cerrar y abrir otra fila trae el panel de esa otra persona.
            p.locator("#userActionsModal .btn-close").evaluate("e => e.click()")
            p.wait_for_timeout(500)
            u.goto("/admin?q=" + urllib.parse.quote(U2))
            p.locator(f"button[data-vf-user='{uid2}']").evaluate("e => e.click()")
            p.wait_for_timeout(500)
            title12 = p.locator("#userActionsModalLabel").inner_text()
            panel12 = p.locator(f"#userActionsModal #role-{uid2}").count()
            old12 = p.locator(f"#userActionsModal #role-{uid1}").count()
            R.check("AD12", N2 in title12 and "show" in (p.locator("#userActionsModal").get_attribute("class") or "")
                    and panel12 == 1 and old12 == 0,
                    f"titulo={title12!r} panel_nuevo={panel12} panel_viejo={old12}")

            # AD13 — marcar un grupo no cierra el modal; el error se pinta dentro.
            box = p.locator(f"#userActionsModal input[name='groups[]'][value='{gid}']")
            box.evaluate("e => {e.checked=true; e.dispatchEvent(new Event('change',{bubbles:true}));}")
            p.wait_for_timeout(300)
            still = "show" in (p.locator("#userActionsModal").get_attribute("class") or "")
            # El hueco del aviso viaja DENTRO del panel de la fila, que es el que
            # se movió al modal; si el panel se hubiera clonado, el aviso del
            # POST se pintaría fuera de la vista.
            err = p.locator(f"#userActionsModal #groupsError{uid2}")
            err_in_modal = err.count() == 1
            group_form = p.locator("#userActionsModal form[action='/admin/users/groups']").count()
            R.check("AD13", still and err_in_modal and group_form == 1
                    and "show" in (p.locator("#userActionsModal").get_attribute("class") or ""),
                    f"marcar_no_cierra={still} error_en_modal={err_in_modal} form={group_form}")

            # AD14 (segunda mitad) — POST nativo de grupo y de rol, sin fetch.
            tok14 = ah.csrf(ah.get("/admin/groups")[1])
            s14a, _, _ = ah.post("/admin/groups/create", {"_token": tok14, "name": GNAT, "description": "nativo"})
            grow14 = vf.db1("SELECT id FROM role_groups WHERE name=" + q(GNAT))
            gid14 = int(grow14[0]) if grow14 else 0
            if gid14: groups.append(gid14)
            s14b, _, _ = ah.post("/admin/users/role", {"_token": tok14, "user_id": str(uid2), "role_id": "3"})
            role14 = vf.db1("SELECT role_id FROM users WHERE id=" + str(uid2))[0]
            R.check("AD14", s14a == 200 and gid14 > 0 and s14b == 200 and role14 == "3",
                    f"grupo_nativo={gid14} rol_nativo={role14}")

            # AD15 — móvil: tabla en tarjetas y modales acotados (CSS estático).
            p.locator("#userActionsModal .btn-close").evaluate("e => e.click()")
            u.goto("/admin")
            mobile_css = ("@media (max-width: 991.98px)" in css
                          and ".app-admin-table thead { display: none; }" in css
                          and "content: attr(data-label);" in css
                          and ".app-admin-table td::before" in css)
            labels = p.locator("tr[data-vf-row] td[data-label]").count()
            R.check("AD15", mobile_css and labels >= 24 and "modal-xl" in
                    (p.locator("#userActionsModal .modal-dialog").get_attribute("class") or ""),
                    f"css_tarjetas={mobile_css} celdas_con_label={labels} (layout no medible)")
finally:
    clean()
    counts = R.report()
    print(json.dumps(counts, ensure_ascii=False))
