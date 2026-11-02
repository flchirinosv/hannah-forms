#!/usr/bin/env python3
"""Bloque N: administración, roles, grupos y permisos."""

import re, time, uuid
from vf import R, Http, Browser, BASE, mysql, db1

r = R()
suf = uuid.uuid4().hex[:8]
prefix = f"bt_n_{suf}"
emails = {
    "one": f"{prefix}_one@bt-n.test",
    "two": f"{prefix}_two@bt-n.test",
    "editor": f"{prefix}_editor@bt-n.test",
    "approver": f"{prefix}_approver@bt-n.test",
    "plain": f"{prefix}_plain@bt-n.test",
    "manage": f"{prefix}_manage@bt-n.test",
    "closed": f"{prefix}_closed@bt-n.test",
    "noaccess": f"{prefix}_noaccess@bt-n.test",
    "nousers": f"{prefix}_nousers@bt-n.test",
    "panel": f"{prefix}_panel@bt-n.test",
    "panel_ns": f"{prefix}_panel_ns@bt-n.test",
    "extra": f"{prefix}_extra@bt-n.test",
}
user_ids = {}
role_ids = []
group_ids = []
form_ids = []

def esc(v):
    return str(v).replace("\\", "\\\\").replace("'", "''")

def one(sql):
    row = db1(sql)
    return row[0] if row else None

def rows(sql):
    return mysql(sql)

def user_id(email):
    v = one(f"SELECT id FROM users WHERE email='{esc(email)}' LIMIT 1")
    return int(v) if v else None

def role_id_by_name(name):
    v = one(f"SELECT id FROM roles WHERE name='{esc(name)}' LIMIT 1")
    return int(v) if v else None

def group_id_by_name(name):
    v = one(f"SELECT id FROM role_groups WHERE name='{esc(name)}' LIMIT 1")
    return int(v) if v else None

def perm_id(key):
    v = one(f"SELECT id FROM permissions WHERE `key`='{esc(key)}' LIMIT 1")
    return int(v) if v else None

def role_perms(rid):
    return [x[0] for x in rows(
        "SELECT p.`key` FROM permissions p JOIN role_permissions rp ON rp.permission_id=p.id "
        f"WHERE rp.role_id={int(rid)} ORDER BY p.`key`"
    )]

def user_direct(uid):
    return [x[0] for x in rows(
        "SELECT p.`key` FROM permissions p JOIN user_permissions up ON up.permission_id=p.id "
        f"WHERE up.user_id={int(uid)} ORDER BY p.`key`"
    )]

def all_roles():
    return [(int(x[0]), x[1]) for x in rows("SELECT id,name FROM roles ORDER BY id")]

def matrix_payload(target=None, keys=None, drop=()):
    data = {}
    for rid, _name in all_roles():
        ks = role_perms(rid)
        if target is not None and rid == int(target):
            if keys is not None:
                ks = list(keys)
        ks = [k for k in ks if k not in drop]
        if ks:
            data[f"perms[{rid}][]"] = ks
    return data

def admin_token(admin, path="/admin"):
    s, html, _ = admin.get(path)
    return admin.csrf(html), s, html

def create_user(admin, token, email, role_id, name="BT N"):
    s, body, url = admin.post("/admin/users/create", {
        "_token": token, "name": name, "email": email,
        "password": "password123", "password_confirm": "password123",
        "role_id": int(role_id),
    })
    uid = user_id(email)
    if uid:
        user_ids[email] = uid
    return s, body, url, uid

def create_role(admin, token, name):
    s, body, url = admin.post("/admin/roles/create", {
        "_token": token, "name": name, "description": "rol temporal de pruebas",
    })
    rid = role_id_by_name(name)
    if rid:
        role_ids.append(rid)
    return s, body, url, rid

def create_group(admin, token, name, roles=None, users=None, desc="grupo temporal"):
    data = {"_token": token, "name": name, "description": desc}
    if roles:
        data["roles[]"] = [int(x) for x in roles]
    if users:
        data["users[]"] = [int(x) for x in users]
    s, body, url = admin.post("/admin/groups/create", data)
    gid = group_id_by_name(name)
    if gid:
        group_ids.append(gid)
    return s, body, url, gid

def create_form(admin, token, title):
    s, body, url = admin.post("/forms", {"_token": token, "title": title, "description": "fixture N"})
    fid = one(f"SELECT id FROM forms WHERE title='{esc(title)}' ORDER BY id DESC LIMIT 1")
    fid = int(fid) if fid else None
    if fid:
        form_ids.append(fid)
    return s, body, url, fid

def cleanup():
    # Formularios y usuarios primero; luego grupos y roles.
    if form_ids:
        mysql("DELETE FROM forms WHERE id IN (" + ",".join(str(int(x)) for x in set(form_ids)) + ")")
    for email in emails.values():
        uid = user_id(email)
        if uid:
            mysql(f"DELETE FROM users WHERE id={int(uid)}")
    if group_ids:
        mysql("DELETE FROM role_groups WHERE id IN (" + ",".join(str(int(x)) for x in set(group_ids)) + ")")
    if role_ids:
        mysql("DELETE FROM roles WHERE id IN (" + ",".join(str(int(x)) for x in set(role_ids)) + ")")
    mysql(f"DELETE FROM users WHERE email LIKE '{esc(prefix)}%'")
    mysql(f"DELETE FROM role_groups WHERE name LIKE '{esc(prefix)}%'")
    mysql(f"DELETE FROM roles WHERE name LIKE '{esc(prefix)}%'")
    mysql(f"DELETE FROM forms WHERE title LIKE '[BT] N %{esc(suf)}%'")

try:
    admin = Http("admin@demo.com")
    tok, s0, html0 = admin_token(admin)
    user_role_id = role_id_by_name("user")
    editor_role_id = role_id_by_name("editor")
    approver_role_id = role_id_by_name("approver")
    admin_role_id = role_id_by_name("admin")

    # N1 — alta de usuario: rol por defecto y sin permisos directos.
    s, body, url, uid = create_user(admin, tok, emails["one"], user_role_id, "BT N Uno")
    u = db1(f"SELECT role_id FROM users WHERE id={int(uid)}") if uid else None
    r.check("N1", uid is not None and u and int(u[0]) == int(user_role_id) and user_direct(uid) == [],
            f"user={uid} role={u[0] if u else None} extra={user_direct(uid) if uid else []}")

    # N2 — rol nuevo nace sin permisos.
    role_a = prefix + "_role_a"
    s, body, url, rid_a = create_role(admin, tok, role_a)
    r.check("N2", rid_a is not None and role_perms(rid_a) == [],
            f"role={rid_a} perms={role_perms(rid_a) if rid_a else None}")

    # N3 — la matriz da el permiso y los usuarios del rol lo tienen.
    if rid_a:
        data = {"_token": tok}
        data.update(matrix_payload(rid_a, ["dashboard.view"]))
        s, body, url = admin.post("/admin/roles/save", data)
        create_user(admin, tok, emails["two"], rid_a, "BT N Dos")
        u2 = Http(emails["two"])
        st, dash, _ = u2.get("/dashboard")
        r.check("N3", "dashboard.view" in role_perms(rid_a) and st == 200,
                f"perms={role_perms(rid_a)} dashboard={st}")

    # N4 — duplicado y nombre con acento: rechazo legible.
    st_dup, body_dup, _ = admin.post("/admin/roles/create", {
        "_token": tok, "name": role_a, "description": "duplicado",
    })
    st_acc, body_acc, _ = admin.post("/admin/roles/create", {
        "_token": tok, "name": prefix + "_role_á", "description": "acento",
    })
    readable = ("Ya existe un rol" in body_dup or "duplicado" in body_dup.lower()) and \
               ("solo admite letras" in body_acc or "obligatorio" in body_acc)
    r.check("N4", st_dup in (200, 302) and st_acc in (200, 302) and readable,
            f"dup={st_dup}/{'Ya existe' in body_dup} acento={st_acc}/{'solo admite' in body_acc}")

    # N5 — matriz vacía: rechazo explícito, no «desmarcar todo».
    before_admin = set(role_perms(admin_role_id))
    s5, b5, _ = admin.post("/admin/roles/save", {"_token": tok},
                           headers={"X-Requested-With": "XMLHttpRequest"})
    r.check("N5", s5 == 422 and "No se recibió la matriz" in b5 and set(role_perms(admin_role_id)) == before_admin,
            f"status={s5} admin_perms={role_perms(admin_role_id)}")

    # N6 — admin.access no se puede quitar al rol admin.
    data = {"_token": tok}
    data.update(matrix_payload(admin_role_id, drop=("admin.access",)))
    admin.post("/admin/roles/save", data)
    r.check("N6", "admin.access" in role_perms(admin_role_id),
            f"admin_perms={role_perms(admin_role_id)}")

    # N7 — grupo con dos usuarios: ambos heredan sus roles.
    group_main = prefix + "_g_main"
    s, body, url, gid_main = create_group(admin, tok, group_main, [approver_role_id], [user_ids[emails["one"]], user_ids[emails["two"]]])
    u1 = Http(emails["one"])
    st1, _, _ = u1.get("/review")
    st2, _, _ = u2.get("/review")
    members = [int(x[0]) for x in rows(f"SELECT user_id FROM role_group_users WHERE group_id={int(gid_main)} ORDER BY user_id")] if gid_main else []
    r.check("N7", gid_main is not None and set(members) == {user_ids[emails["one"]], user_ids[emails["two"]]} and st1 == 200 and st2 == 200,
            f"group={gid_main} members={members} review={st1},{st2}")

    # N8 — sacar a un usuario del grupo le quita el permiso en la siguiente petición.
    if gid_main:
        admin.post("/admin/groups/update", {
            "_token": tok, "group_id": gid_main, "name": group_main,
            "roles[]": [approver_role_id], "users[]": [user_ids[emails["two"]]],
        })
    st1b, _, _ = u1.get("/review")
    st2b, _, _ = u2.get("/review")
    r.check("N8", st1b == 403 and st2b == 200,
            f"fuera={st1b} dentro={st2b}")

    # N9 — lista vacía de grupos quita a la persona de todos.
    admin.post("/admin/users/groups", {"_token": tok, "user_id": user_ids[emails["two"]]})
    st2c, _, _ = u2.get("/review")
    remaining = one(f"SELECT COUNT(*) FROM role_group_users WHERE user_id={int(user_ids[emails['two']])}")
    r.check("N9", st2c == 403 and int(remaining or 0) == 0, f"review={st2c} grupos={remaining}")

    # N10 — las dos direcciones (roles del grupo / grupos del usuario) coinciden.
    group_a = prefix + "_g_dir_a"
    group_b = prefix + "_g_dir_b"
    s, _, _, gid_a = create_group(admin, tok, group_a, [approver_role_id], [user_ids[emails["one"]]])
    s, _, _, gid_b = create_group(admin, tok, group_b, [], [user_ids[emails["two"]]])
    if gid_b:
        admin.post("/admin/groups/update", {
            "_token": tok, "group_id": gid_b, "name": group_b,
            "roles[]": [approver_role_id], "users[]": [user_ids[emails["two"]]],
        })
    st_a, _, _ = u1.get("/review")
    st_b, _, _ = u2.get("/review")
    r.check("N10", st_a == 200 and st_b == 200, f"grupo->rol={st_a} usuario->grupo={st_b}")

    # N11 — editor no puede borrar su propio formulario.
    create_user(admin, tok, emails["editor"], editor_role_id, "BT N Editor")
    editor = Http(emails["editor"])
    se, eh, _ = editor.get("/dashboard")
    etok = editor.csrf(eh)
    title_ed = f"[BT] N editor {suf}"
    se, _, _, fid_ed = create_form(editor, etok, title_ed)
    se2, eh2, _ = editor.get(f"/forms/{fid_ed}")
    etok2 = editor.csrf(eh2)
    st_del, bdel, _ = editor.post(f"/forms/{fid_ed}/delete", {"_token": etok2}, follow=False)
    exists_ed = one(f"SELECT COUNT(*) FROM forms WHERE id={int(fid_ed)}") if fid_ed else "0"
    r.check("N11", fid_ed is not None and st_del == 403 and int(exists_ed or 0) == 1,
            f"form={fid_ed} delete={st_del} sigue={exists_ed}")

    # N12 — un user con forms.manage_all ve y opera formularios ajenos.
    create_user(admin, tok, emails["manage"], user_role_id, "BT N Manage")
    mid = user_ids[emails["manage"]]
    if mid:
        pid = perm_id("forms.manage_all")
        if pid:
            mysql(f"INSERT INTO user_permissions (user_id, permission_id) VALUES ({int(mid)}, {int(pid)})")
    manage = Http(emails["manage"])
    st_form, _, _ = manage.get("/forms/263816")
    st_builder, _, _ = manage.get("/forms/263816/builder")
    r.check("N12", st_form == 200 and st_builder == 200,
            f"ficha={st_form} builder={st_builder}")

    # N13 — nombre ausente ≠ nombre vacío.
    group_keep = prefix + "_g_keep"
    s, _, _, gid_keep = create_group(admin, tok, group_keep, [], [], "descripción original")
    admin.post("/admin/groups/update", {"_token": tok, "group_id": gid_keep})
    keep = db1(f"SELECT name,description FROM role_groups WHERE id={int(gid_keep)}") if gid_keep else None
    st_empty, body_empty, _ = admin.post("/admin/groups/update", {
        "_token": tok, "group_id": gid_keep, "name": "",
    })
    keep2 = db1(f"SELECT name,description FROM role_groups WHERE id={int(gid_keep)}") if gid_keep else None
    r.check("N13", keep and keep[0] == group_keep and keep[1] == "descripción original" and
            st_empty in (200, 302, 422) and keep2 and keep2[0] == group_keep and "obligatorio" in body_empty,
            f"conservado={keep} tras_vacio={keep2} status={st_empty}")

    # N14 — la matriz refleja config/permissions.php.
    import subprocess
    cfg = subprocess.run(["php", "-r", "$x=require 'config/permissions.php'; echo implode(\"\\n\", array_keys($x));"],
                         cwd="/root/workspace/php/vanilla-forms", capture_output=True, text=True)
    keys = [k for k in cfg.stdout.splitlines() if k]
    s14, html14, _ = admin.get("/admin/roles")
    faltan = [k for k in keys if k not in html14]
    r.check("N14", s14 == 200 and keys and not faltan,
            f"keys={len(keys)} faltan={faltan[:5]}")

    # N15 — approver ve Dashboard/Revisión y no Nuevo formulario/Administración.
    create_user(admin, tok, emails["approver"], approver_role_id, "BT N Approver")
    appr = Http(emails["approver"])
    sd, hd, _ = appr.get("/dashboard")
    sr, hr, _ = appr.get("/review")
    sa, ha, _ = appr.get("/admin")
    r.check("N15", sd == 200 and sr == 200 and sa == 403 and
            "Nuevo formulario" not in hd and "Administración" not in hd,
            f"dashboard={sd} review={sr} admin={sa} nuevo={'Nuevo formulario' in hd} adminlink={'Administración' in hd}")

    # N16 — el approver revisa la estructura read-only y aprueba desde /review.
    title_rev = f"[BT] N revision {suf}"
    admin2 = Http("admin@demo.com")
    tok2, _, _ = admin_token(admin2, "/dashboard")
    s, _, _, fid_rev = create_form(admin2, tok2, title_rev)
    s, hf, _ = admin2.get(f"/forms/{fid_rev}")
    tokf = admin2.csrf(hf)
    admin2.post(f"/forms/{fid_rev}/review/send", {"_token": tokf})
    st_inbox, h_inbox, _ = appr.get("/review")
    st_print, h_print, _ = appr.get(f"/forms/{fid_rev}/print")
    st_ficha, _, _ = appr.get(f"/forms/{fid_rev}")
    tok_appr = appr.csrf(h_inbox)
    st_app, b_app, url_app = appr.post(f"/forms/{fid_rev}/review/approve", {"_token": tok_appr}, follow=False)
    status = one(f"SELECT status FROM forms WHERE id={int(fid_rev)}") if fid_rev else None
    loc = ""
    m = re.search(r"Location:\s*(\S+)", b_app)
    r.check("N16", fid_rev is not None and st_inbox == 200 and title_rev in h_inbox and
            st_print == 200 and "Volver" in h_print and st_ficha == 403 and
            st_app == 302 and status == "approved" and title_rev in h_inbox,
            f"inbox={st_inbox} print={st_print} ficha={st_ficha} approve={st_app} status={status}")

    # N17 — los cuatro roles del sistema salen como Sistema y sin botón de borrar.
    s17, h17, _ = admin.get("/admin/roles")
    sys_ids = [role_id_by_name(n) for n in ("admin", "user", "editor", "approver")]
    sin_boton = all(f'name="role_id" value="{rid}"' not in h17 for rid in sys_ids if rid)
    r.check("N17", s17 == 200 and h17.count("Sistema") >= 4 and "del sistema" in h17 and sin_boton,
            f"status={s17} sistema={h17.count('Sistema')} sin_boton={sin_boton}")

    # N18 — rol propio en uso: no se borra y el motivo dice cuántas personas.
    role_busy = prefix + "_role_busy"
    s, _, _, rid_busy = create_role(admin, tok, role_busy)
    create_user(admin, tok, emails["closed"], rid_busy, "BT N Busy")
    s18, b18, _ = admin.post("/admin/roles/delete", {"_token": tok, "role_id": rid_busy})
    still = role_id_by_name(role_busy)
    r.check("N18", still is not None and "1 persona" in b18,
            f"role={still} status={s18} motivo={'1 persona' in b18}")

    # N19 — rol vacío: se borra y desaparece de los grupos que lo confieren.
    role_free = prefix + "_role_free"
    s, _, _, rid_free = create_role(admin, tok, role_free)
    group_role = prefix + "_g_role"
    s, _, _, gid_role = create_group(admin, tok, group_role, [rid_free], [])
    s19, b19, _ = admin.post("/admin/roles/delete", {"_token": tok, "role_id": rid_free})
    gone = role_id_by_name(role_free) is None
    in_group = one(f"SELECT COUNT(*) FROM role_group_roles WHERE group_id={int(gid_role)} AND role_id={int(rid_free)}") if gid_role and rid_free else "0"
    r.check("N19", gone and int(in_group or 0) == 0 and "borrado" in b19,
            f"role={role_free} gone={gone} en_grupo={in_group} status={s19}")

    # N20 — confirmación rechazada: el rol sigue.
    role_cancel = prefix + "_role_cancel"
    s, _, _, rid_cancel = create_role(admin, tok, role_cancel)
    cancel_ok = False
    detail20 = ""
    try:
        with Browser() as b:
            with __import__("vf").VF(r, email="admin@demo.com", browser=b.browser) as s:
                s.goto("/admin/roles", wait=500)
                sel = f'form[action="/admin/roles/delete"]:has(input[value="{rid_cancel}"]) button[type="submit"]'
                if s.page.locator(sel).count():
                    s.jsclick(sel)
                    s.page.wait_for_timeout(300)
                    visible = s.page.locator("#vfConfirm").evaluate("e => e.classList.contains('show')")
                    s.page.locator("#vfConfirmCancel").evaluate("e => e.click()")
                    s.page.wait_for_timeout(300)
                    cancel_ok = bool(visible) and role_id_by_name(role_cancel) is not None
                    detail20 = f"dialogo={visible} sigue={role_id_by_name(role_cancel) is not None}"
                else:
                    detail20 = "no se encontró el botón de borrar"
    except Exception as e:
        detail20 = f"error lightpanda: {e}"
    r.check("N20", cancel_ok and role_id_by_name(role_cancel) is not None, detail20)

    # N21 — /admin: cabecera, filtros y tabla; alta en diálogo.
    s21, h21, _ = admin.get("/admin")
    r.check("N21", s21 == 200 and "Nuevo usuario" in h21 and 'id="newUserModal"' in h21 and
            'name="q"' in h21 and 'name="rol"' in h21 and 'name="estado"' in h21 and
            h21.count("data-vf-row") >= 2 and "table" in h21,
            f"status={s21} filas={h21.count('data-vf-row')}")

    # N22 — sin admin.access: 403.
    role_noaccess = prefix + "_role_noaccess"
    s, _, _, rid_noaccess = create_role(admin, tok, role_noaccess)
    create_user(admin, tok, emails["noaccess"], rid_noaccess, "BT N NoAccess")
    na = Http(emails["noaccess"])
    st22, h22, _ = na.get("/admin")
    r.check("N22", st22 == 403 and "Acceso denegado" in h22 or st22 == 403,
            f"status={st22}")

    # N23 — el plegado va por pantalla y persiste solo en la ficha.
    persist_ok = False
    detail23 = ""
    try:
        with Browser() as b:
            with __import__("vf").VF(r, email="admin@demo.com", browser=b.browser) as s:
                s.goto("/forms/263816", wait=600)
                s.page.evaluate("localStorage.removeItem('vf:grp:263816')")
                s.page.reload(); s.page.wait_for_timeout(500)
                s.page.locator('button[data-bs-target="#grp-acceso"]').evaluate("e => e.click()")
                s.page.wait_for_timeout(700)
                closed1 = not s.page.locator("#grp-acceso").evaluate("e => e.classList.contains('show')")
                s.page.reload(); s.page.wait_for_timeout(600)
                closed2 = not s.page.locator("#grp-acceso").evaluate("e => e.classList.contains('show')")
                s.goto("/admin/groups", wait=500)
                admin_groups_key = s.page.evaluate("localStorage.getItem('vf:grp:admin-grupos')")
                first_group_open = s.page.locator(".collapse.show").count() > 0
                persist_ok = closed1 and closed2 and admin_groups_key is None and first_group_open
                detail23 = f"plegado={closed1}/{closed2} key_admin={admin_groups_key} abierta={first_group_open}"
    except Exception as e:
        detail23 = f"error lightpanda: {e}"
    r.check("N23", persist_ok, detail23)

    # N24 — registro cerrado: /register 403 y el alta del panel sigue funcionando.
    mysql("UPDATE app_settings SET value='0' WHERE key_name='registration_enabled'")
    anon = Http()
    st_reg, b_reg, _ = anon.get("/register")
    s24, h24, _ = admin.get("/admin")
    s24b, b24b, _, uid24 = create_user(admin, tok, emails["panel"], user_role_id, "BT N Closed")
    created24 = uid24 is not None
    inline24 = 'id="newUserFormMount"' in h24 and 'action="/admin/users/create"' in h24
    _, _, _, uid_ns = create_user(admin, tok, emails["panel_ns"], user_role_id, "BT N NoJS")
    nojs_ok = uid_ns is not None
    nojs_detail = f"post_nativo_sin_js={nojs_ok} (Lightpanda no permite desactivar JS)"
    mysql("UPDATE app_settings SET value='1' WHERE key_name='registration_enabled'")
    r.check("N24", st_reg == 403 and created24 and inline24 and nojs_ok,
            f"register={st_reg} creado={created24} form_inline={inline24} {nojs_detail}")

    # N25 — email repetido dentro del diálogo: debe seguir abierto.
    modal_ok = False
    detail25 = ""
    try:
        with Browser() as b:
            with __import__("vf").VF(r, email="admin@demo.com", browser=b.browser) as s:
                s.goto("/admin", wait=700)
                s.jsclick('button[data-bs-target="#newUserModal"]')
                s.page.wait_for_timeout(500)
                s.page.locator("#newUserName").fill("Duplicado N")
                s.page.locator("#newUserEmail").fill("admin@demo.com")
                s.page.locator("#newUserPassword").fill("password123")
                s.page.locator("#newUserPasswordConfirm").fill("password123")
                s.page.locator('#newUserModal button[type="submit"]').evaluate("e => e.click()")
                s.page.wait_for_timeout(1500)
                visible = s.page.locator("#newUserModal").evaluate("e => e.classList.contains('show')")
                txt = s.page.locator("body").inner_text()
                modal_ok = bool(visible) and "Ya hay una cuenta con ese email" in txt
                detail25 = f"modal_abierto={visible} motivo={'Ya hay una cuenta con ese email' in txt} url={s.page.url}"
    except Exception as e:
        detail25 = f"error lightpanda: {e}"
    r.check("N25", modal_ok, detail25)

    # N26 — permiso extra desde la fila: ve formularios ajenos sin cambiar rol/grupos.
    create_user(admin, tok, emails["extra"], user_role_id, "BT N Extra")
    extra_email = emails["extra"]
    extra_id = user_ids.get(extra_email) or user_id(extra_email)
    st26pre, _, _ = Http(extra_email).get("/forms/263816")
    s26, b26, _ = admin.post("/admin/users/permissions", {
        "_token": tok, "user_id": extra_id, "permissions[]": ["forms.manage_all"],
    })
    extra = Http(extra_email)
    st26, _, _ = extra.get("/forms/263816")
    role_now = one(f"SELECT role_id FROM users WHERE id={int(extra_id)}") if extra_id else None
    groups_now = one(f"SELECT COUNT(*) FROM role_group_users WHERE user_id={int(extra_id)}") if extra_id else None
    r.check("N26", st26pre == 403 and st26 == 200 and int(role_now or 0) == int(user_role_id) and int(groups_now or 0) == 0,
            f"antes={st26pre} despues={st26} rol={role_now} grupos={groups_now}")

    # N27 — quitar la casilla: el POST es la lista completa.
    admin.post("/admin/users/permissions", {"_token": tok, "user_id": extra_id})
    st27, _, _ = extra.get("/forms/263816")
    r.check("N27", st27 == 403 and user_direct(extra_id) == [],
            f"ficha={st27} extra={user_direct(extra_id)}")

    # N28 — menú ⋯: desglose Rol / Grupos / Extra en la primera columna.
    detail28 = ""
    try:
        with Browser() as b:
            with __import__("vf").VF(r, email="admin@demo.com", browser=b.browser) as s:
                s.goto("/admin?q=" + extra_email, wait=700)
                sel = f'[data-vf-user="{extra_id}"]'
                if s.page.locator(sel).count():
                    s.jsclick(sel)
                    s.page.wait_for_timeout(500)
                    txt = s.page.locator("#userActionsModal").inner_text()
                    ok = all(x in txt for x in ("Permisos efectivos", "Rol", "Grupos", "Extra"))
                    detail28 = f"modal_abierto={s.page.locator('#userActionsModal.show').count()>0} desglose={ok}"
                    if not ok:
                        detail28 += " fragmento=" + txt[:180].replace("\n", " ")
                else:
                    detail28 = "no se encontró el botón ⋯ del usuario"
    except Exception as e:
        detail28 = f"error lightpanda: {e}"
    r.check("N28", "desglose=True" in detail28, detail28)

    # N29 — admin.access sin admin.users: 403.
    role_nousers = prefix + "_role_nousers"
    s, _, _, rid_nousers = create_role(admin, tok, role_nousers)
    if rid_nousers:
        data = {"_token": tok}
        data.update(matrix_payload(rid_nousers, ["admin.access"]))
        admin.post("/admin/roles/save", data)
    create_user(admin, tok, emails["nousers"], rid_nousers, "BT N NoUsers")
    nu = Http(emails["nousers"])
    st29, h29, _ = nu.get("/admin")
    r.check("N29", st29 == 403 and "admin.users" not in role_perms(rid_nousers) and "admin.access" in role_perms(rid_nousers),
            f"status={st29} perms={role_perms(rid_nousers)}")

finally:
    try:
        cleanup()
    except Exception as e:
        print("cleanup:", e)

counts = r.report()
print("BLOQUE N:", counts)
