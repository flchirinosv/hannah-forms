#!/usr/bin/env python3
"""Bloque AF: marca de la instancia (nombre, colores, logo y favicon)."""

import base64, json, os, re, shutil, sys, tempfile, uuid
sys.path.insert(0, "tests/browser")
import vf

R = vf.R()
ah = vf.Http("admin@demo.com")
suf = uuid.uuid4().hex[:8]
BRAND_KEYS = ["instance_name", "instance_color", "instance_color_grad_start",
              "instance_color_grad_end", "instance_color_bg", "instance_color_text"]
BRAND_ROWS = ["instance_logo", "instance_favicon"]
DIR = vf.ROOT / "storage" / "branding"
ENV = vf.ROOT / ".env"
PNG = base64.b64decode(
    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2nGQAAAAASUVORK5CYII="
)
CSS = (vf.ROOT / "public" / "assets" / "css" / "app.css").read_text()


def esc(s):
    return str(s).replace("\\", "\\\\").replace("'", "\\'")


def rows():
    out = {}
    for k, v in vf.mysql(
            "SELECT key_name,value FROM app_settings WHERE key_name IN ('" +
            "','".join(BRAND_KEYS + BRAND_ROWS) + "') ORDER BY key_name"):
        out[k] = v
    return out


def set_setting(key, value):
    if value is None:
        vf.mysql(f"DELETE FROM app_settings WHERE key_name='{esc(key)}'")
    else:
        vf.mysql(
            "INSERT INTO app_settings (key_name,value) VALUES "
            f"('{esc(key)}','{esc(value)}') ON DUPLICATE KEY UPDATE value=VALUES(value)")


def clear_brand():
    vf.mysql("DELETE FROM app_settings WHERE key_name IN ('" + "','".join(BRAND_KEYS + BRAND_ROWS) + "')")
    if DIR.exists():
        for p in DIR.iterdir():
            if p.name != ".htaccess" and p.is_file():
                p.unlink()


def snapshot():
    saved_rows = rows()
    tmp = tempfile.mkdtemp(prefix="af-brand-")
    if DIR.exists():
        for p in DIR.iterdir():
            if p.name != ".htaccess" and p.is_file():
                shutil.copy2(p, os.path.join(tmp, p.name))
    return saved_rows, tmp


def restore(saved_rows, tmp):
    clear_brand()
    for k, v in saved_rows.items():
        set_setting(k, v)
    for name in os.listdir(tmp):
        DIR.mkdir(parents=True, exist_ok=True)
        shutil.copy2(os.path.join(tmp, name), DIR / name)
    shutil.rmtree(tmp, ignore_errors=True)


def token(path="/admin/settings"):
    return ah.csrf(ah.get(path)[1])


def save_brand(data):
    t = token()
    body = {"_token": t}
    body.update(data)
    return ah.req("POST", "/admin/settings/brand", body, follow=True)


def settings_html():
    return ah.get("/admin/settings")[1]


def favicon_urls(html):
    return re.findall(r'href="(/branding/favicon(?:\?v=\d+)?)"', html)


def logo_urls(html):
    return re.findall(r'src="(/branding/logo(?:\?v=\d+)?)"', html)


def theme_css(html):
    m = re.findall(r"<style>(:root\{[^<]*\})</style>", html)
    return m[-1] if m else ""


def file_meta(kind):
    row = vf.db1(f"SELECT value FROM app_settings WHERE key_name='instance_{kind}'")
    return json.loads(row[0]) if row else None


def multipart_native(path, fields, field, filename, content, ctype="image/png"):
    boundary = "----vfaf" + uuid.uuid4().hex
    out = bytearray()
    for k, v in fields.items():
        out += f"--{boundary}\r\nContent-Disposition: form-data; name=\"{k}\"\r\n\r\n{v}\r\n".encode()
    out += (f"--{boundary}\r\nContent-Disposition: form-data; name=\"{field}\"; "
            f"filename=\"{filename}\"\r\nContent-Type: {ctype}\r\n\r\n").encode()
    out += content + b"\r\n"
    out += f"--{boundary}--\r\n".encode()
    return ah.req("POST", path, bytes(out),
                  headers={"Content-Type": f"multipart/form-data; boundary={boundary}"},
                  follow=True)


def upload(v, path, filename="af.png", content=PNG, ctype="image/png"):
    t = v.csrf()
    return v.ctx.request.post(
        vf.BASE + path,
        multipart={"_token": t, "brand_image": {"name": filename, "mimeType": ctype, "buffer": content}},
        headers={"X-Requested-With": "XMLHttpRequest"}, max_redirects=0)


def style_vars(css):
    return dict(re.findall(r"(--vf-[\w-]+):([^;}]*)", css))


saved, saved_dir = snapshot()
app_name = next((l.split("=", 1)[1] for l in ENV.read_text().splitlines()
                 if l.startswith("APP_NAME=")), "VanillaForms")
try:
    clear_brand()

    # AF1 — la tarjeta existe y es de la instancia.
    s1, h1, _ = ah.get("/admin/settings")
    R.check("AF1", s1 == 200
            and "Marca de la instancia" in h1
            and 'action="/admin/settings/brand"' in h1
            and 'name="instance_name"' in h1 and 'name="instance_color"' in h1
            and 'action="/admin/settings/brand/logo"' in h1
            and 'action="/admin/settings/brand/favicon"' in h1
            and "El nombre, el logo, el favicon y el color" in h1,
            "tarjeta con nombre, color, logo y favicon")

    # AF2 — nombre + PRG; sidebar, topbar y title.
    nombre = "BT Marca " + suf
    s2, h2, url2 = save_brand({"instance_name": nombre, "instance_color": ""})
    s2b, dash2, _ = ah.get("/dashboard")
    R.check("AF2", s2 == 200 and url2.endswith("/admin/settings")
            and "Marca actualizada." in h2
            and vf.db1("SELECT value FROM app_settings WHERE key_name='instance_name'")[0] == nombre
            and f"<title>{nombre}</title>" in dash2
            and f'class="app-sidebar-label fw-bold">{nombre}</span>' in dash2
            and f'class="fw-bold">{nombre}</span>' in dash2,
            f"status={s2} url={url2} nombre={nombre}")

    # AF3 — el picker sincroniza el texto (JS) y el primario llega al CSS.
    with vf.Browser() as br, br.session(R) as v:
        v.goto("/admin/settings")
        v.page.locator("#instanceColor").evaluate(
            "(e, v) => { e.value = v; e.dispatchEvent(new Event('input', {bubbles: true})); }",
            "#2a7f62")
        sincronizado = v.page.locator("#instanceColorText").input_value()
        v.page.locator("#instanceColorText").fill("#2a7f62")
    s3, h3, _ = save_brand({"instance_name": nombre, "instance_color": "#2a7f62"})
    _, dash3, _ = ah.get("/dashboard")
    vars3 = style_vars(theme_css(dash3))
    R.check("AF3", sincronizado == "#2a7f62" and vars3.get("--vf-primary") == "#2a7f62"
            and "background-color: var(--vf-primary);" in CSS
            and ".app-sidebar-cta .app-btn-create" in CSS,
            f"texto={sincronizado} primary={vars3.get('--vf-primary')}")

    # AF4 — color inválido: error, sin fila y default en la ficha.
    save_brand({k: "" for k in BRAND_KEYS})
    s4, h4, _ = save_brand({"instance_name": "", "instance_color": "azul"})
    _, set4, _ = ah.get("/admin/settings")
    R.check("AF4", s4 == 200 and "El color primario debe ser un valor hex" in h4
            and vf.db1("SELECT COUNT(*) FROM app_settings WHERE key_name='instance_color'")[0] == "0"
            and re.search(r'id="instanceColorText" name="instance_color"\s+value=""', set4)
            and re.search(r'id="instanceColor"\s+value="#5b5bd6"', set4),
            "azul rechazado y ficha sin color guardado")

    # AF5 — nombre vacío vuelve al APP_NAME del .env.
    save_brand({"instance_name": nombre, "instance_color": ""})
    s5, h5, _ = save_brand({"instance_name": "", "instance_color": ""})
    _, dash5, _ = ah.get("/dashboard")
    R.check("AF5", s5 == 200 and "Marca actualizada." in h5
            and vf.db1("SELECT COUNT(*) FROM app_settings WHERE key_name='instance_name'")[0] == "0"
            and f"<title>{app_name}</title>" in dash5
            and f'class="app-sidebar-label fw-bold">{app_name}</span>' in dash5,
            f"app_name={app_name}")

    # AF6 — logo PNG en sidebar, login y con su Content-Type.
    with vf.Browser() as br, br.session(R) as v:
        r6 = upload(v, "/admin/settings/brand/logo")
        st6 = r6.status
        _, logo6, _ = ah.get("/branding/logo")
        _, dash6, _ = ah.get("/dashboard")
        _, login6, _ = ah.get("/login")
        meta6 = file_meta("logo")
        logo_file = DIR / meta6["stored"] if meta6 else None
        r6h = v.ctx.request.get(vf.BASE + "/branding/logo", max_redirects=0)
    R.check("AF6", st6 == 302 and meta6 is not None and logo_file and logo_file.is_file()
            and logo_urls(dash6) and logo_urls(login6)
            and r6h.status == 200
            and r6h.headers.get("content-type") == "image/png"
            and r6h.headers.get("x-content-type-options") == "nosniff",
            f"status={st6} url={logo_urls(dash6)[:1]} ctype={r6h.headers.get('content-type')}")

    # AF7 — un texto renombrado a .png se rechaza y no reemplaza el logo.
    before7 = file_meta("logo")
    with vf.Browser() as br, br.session(R) as v:
        r7 = upload(v, "/admin/settings/brand/logo", "falso.png", b"no soy una imagen", "image/png")
        v.goto("/admin/settings")
        aviso7 = v.has("Formato no soportado: usa PNG, JPG, GIF o WEBP.")
    after7 = file_meta("logo")
    R.check("AF7", r7.status == 302 and aviso7 and before7 == after7,
            f"status={r7.status} aviso={aviso7} mismo_logo={before7 == after7}")

    # AF8 — favicon en las nueve superficies.
    with vf.Browser() as br, br.session(R) as v:
        r8 = upload(v, "/admin/settings/brand/favicon")
        r8h = v.ctx.request.get(vf.BASE + "/branding/favicon", max_redirects=0)
    meta8 = file_meta("favicon")
    login8 = vf.Http()
    st8l, html8l, _ = login8.get("/login", follow=False)
    vistas8pre = {"login": st8l == 200 and bool(favicon_urls(html8l))}
    rutas8 = [
        ("/dashboard", "panel"),
        ("/forms/263816/print", "print"), ("/f/bt-p-blico-simple", "fill"),
        ("/f/bt-cerrado", "cerrado"), ("/f/bt-borrador", "borrador"),
        ("/f/bt-en-revisi-n", "en revisión"), ("/f/bt-roster", "acceso"),
        ("/no-existe-af", "error"),
    ]
    vistas8 = {}
    for path, nombre_vista in rutas8:
        st, html, _ = ah.get(path, follow=False)
        vistas8[nombre_vista] = bool(favicon_urls(html))
    vistas8.update(vistas8pre)
    R.check("AF8", r8.status == 302 and meta8 is not None
            and (DIR / meta8["stored"]).is_file()
            and r8h.status == 200 and r8h.headers.get("content-type") == "image/png"
            and all(vistas8.values()) and len([x for x in vistas8.values() if x]) >= 9,
            "vistas=" + ",".join(k for k, x in vistas8.items() if x))

    # AF9 — quitar logo: confirmación, fila/archivo fuera e icono por defecto.
    t9 = token()
    logo9 = file_meta("logo")
    s9, h9, _ = ah.req("POST", "/admin/settings/brand/logo/remove", {"_token": t9}, follow=True)
    _, set9, _ = ah.get("/admin/settings")
    st9, _, _ = ah.req("GET", "/branding/logo", follow=False)
    R.check("AF9", s9 == 200 and "Logo eliminado: se vuelve al icono por defecto." in h9
            and file_meta("logo") is None and not (DIR / logo9["stored"]).exists()
            and st9 == 404 and "fa-list-check" in set9 and not logo_urls(set9)
            and 'data-confirm="¿Quitar el logo de la instancia?"' not in set9,
            f"status={s9} branding_logo={st9}")

    # AF10 — una fila sin archivo cae al icono por defecto, sin error.
    with vf.Browser() as br, br.session(R) as v:
        upload(v, "/admin/settings/brand/logo")
    meta10 = file_meta("logo")
    (DIR / meta10["stored"]).unlink()
    s10, h10, _ = ah.get("/admin/settings")
    _, dash10, _ = ah.get("/dashboard")
    R.check("AF10", s10 == 200 and "fa-list-check" in h10 and not logo_urls(h10)
            and not logo_urls(dash10) and file_meta("logo") is not None,
            "fila viva, archivo ausente, fallback al icono")
    set_setting("instance_logo", None)

    # AF11 — kind inventado.
    s11, _, _ = ah.req("GET", "/branding/loquesea", follow=False)
    R.check("AF11", s11 == 404, f"status={s11}")

    # AF12 — sin admin.access: 403 y sin escritura.
    uh = vf.Http("user@demo.com", "password123")
    _, dash12, _ = uh.get("/dashboard")
    t12 = uh.csrf(dash12)
    antes12 = vf.db1("SELECT COUNT(*) FROM app_settings WHERE key_name='instance_name'")[0]
    s12, _, _ = uh.req("POST", "/admin/settings/brand",
                       {"_token": t12, "instance_name": "BT AF intruso", "instance_color": ""},
                       follow=False)
    despues12 = vf.db1("SELECT value FROM app_settings WHERE key_name='instance_name'")
    R.check("AF12", s12 == 403 and antes12 == "0" and despues12 is None,
            f"status={s12} nombre={despues12}")

    # AF13 — camino nativo: texto, color, subida y borrado por POST sin JS.
    s13a, h13a, _ = save_brand({"instance_name": "BT AF nativo " + suf, "instance_color": "#0f766e"})
    t13 = token()
    s13b, h13b, _ = multipart_native("/admin/settings/brand/logo",
                                     {"_token": t13}, "brand_image", "nativo.png", PNG)
    meta13 = file_meta("logo")
    tiene13 = bool(meta13 and (DIR / meta13["stored"]).is_file())
    t13c = token()
    s13c, h13c, _ = ah.req("POST", "/admin/settings/brand/logo/remove", {"_token": t13c}, follow=True)
    nombre13 = vf.db1("SELECT value FROM app_settings WHERE key_name='instance_name'")
    R.check("AF13", s13a == 200 and "Marca actualizada." in h13a
            and nombre13 and nombre13[0] == "BT AF nativo " + suf
            and s13b == 200 and "Logo actualizado." in h13b and meta13 is not None
            and tiene13
            and s13c == 200 and "Logo eliminado" in h13c and file_meta("logo") is None,
            f"nombre_status={s13a} nombre={nombre13} subida={s13b} flash_subida={'Logo actualizado.' in h13b} "
            f"meta={bool(meta13)} archivo={tiene13} borrado={s13c} flash_borrado={'Logo eliminado' in h13c}")

    # AF14 — con APP_KEY vacía la marca guardada se sigue viendo.
    save_brand({"instance_name": "BT AF sin clave " + suf, "instance_color": "#334155"})
    raw_env = ENV.read_text()
    mode = os.stat(ENV).st_mode
    try:
        new_env = re.sub(r"^APP_KEY=.*$", "APP_KEY=", raw_env, flags=re.M)
        ENV.write_text(new_env)
        os.chmod(ENV, mode)
        s14, set14, _ = ah.get("/admin/settings")
        _, dash14, _ = ah.get("/dashboard")
        vars14 = style_vars(theme_css(dash14))
    finally:
        ENV.write_text(raw_env)
        os.chmod(ENV, mode)
    R.check("AF14", s14 == 200 and "BT AF sin clave " + suf in set14
            and "BT AF sin clave " + suf in dash14
            and vars14.get("--vf-primary") == "#334155",
            f"status={s14} primary={vars14.get('--vf-primary')}")

    # AF15 — degradado claro: variables y tinta automática oscura.
    s15, h15, _ = save_brand({
        "instance_name": "", "instance_color": "#334155",
        "instance_color_grad_start": "#ffffee", "instance_color_grad_end": "#ffffcc",
        "instance_color_bg": "", "instance_color_text": "",
    })
    _, dash15, _ = ah.get("/dashboard")
    _, login15, _ = ah.get("/login")
    vars15 = style_vars(theme_css(dash15))
    R.check("AF15", s15 == 200 and vars15.get("--vf-grad-start") == "#ffffee"
            and vars15.get("--vf-grad-end") == "#ffffcc"
            and vars15.get("--vf-on-grad") == "#1e293b"
            and "linear-gradient(90deg, var(--vf-grad-start) 0%, var(--vf-grad-end) 100%)" in CSS
            and "background: var(--vf-instance-bg);" in CSS
            and vars15.get("--vf-grad-start") in login15,
            f"vars={vars15}")

    # AF16 — degradado oscuro y texto vacío: tinta blanca automática.
    s16, h16, _ = save_brand({
        "instance_name": "", "instance_color": "#334155",
        "instance_color_grad_start": "#111111", "instance_color_grad_end": "#222222",
        "instance_color_bg": "", "instance_color_text": "",
    })
    _, dash16, _ = ah.get("/dashboard")
    vars16 = style_vars(theme_css(dash16))
    R.check("AF16", s16 == 200 and vars16.get("--vf-grad-start") == "#111111"
            and vars16.get("--vf-grad-end") == "#222222"
            and vars16.get("--vf-on-grad") == "#ffffff",
            f"vars={vars16}")

    # AF17 — color de texto manual manda sobre el automático.
    s17, h17, _ = save_brand({
        "instance_name": "", "instance_color": "#334155",
        "instance_color_grad_start": "#111111", "instance_color_grad_end": "#222222",
        "instance_color_bg": "", "instance_color_text": "#abcdef",
    })
    _, dash17, _ = ah.get("/dashboard")
    vars17 = style_vars(theme_css(dash17))
    R.check("AF17", s17 == 200 and vars17.get("--vf-on-grad") == "#abcdef",
            f"on_grad={vars17.get('--vf-on-grad')}")

    # AF18 — un degradado inválido no guarda NADA del POST (ni el primario).
    antes18 = rows()
    s18, h18, _ = save_brand({
        "instance_name": "BT AF no debe quedar", "instance_color": "#00ff00",
        "instance_color_grad_start": "azul", "instance_color_grad_end": "#222222",
        "instance_color_bg": "", "instance_color_text": "#abcdef",
    })
    despues18 = rows()
    R.check("AF18", "El color de inicio del degradado debe ser un valor hex" in h18
            and antes18 == despues18,
            f"status={s18} antes={antes18} despues={despues18}")

    # AF19 — las cajas del logo no se aplanan (CSS estático; sin layout real).
    bloque_logo = re.search(r"\.app-logo\s*\{([^}]*)\}", CSS)
    bloque_img = re.search(r"\.app-logo-img\s*\{([^}]*)\}", CSS)
    bloque_lg = re.search(r"\.app-logo-lg\s*\{([^}]*)\}", CSS)
    R.check("AF19", bool(bloque_logo and bloque_img and bloque_lg)
            and "flex: 0 0 auto" in bloque_logo.group(1)
            and "min-width: 34px" in bloque_logo.group(1)
            and "min-height: 34px" in bloque_logo.group(1)
            and "flex: 0 0 auto" in bloque_img.group(1)
            and "object-fit: contain" in bloque_img.group(1)
            and "flex: 0 0 auto" in bloque_lg.group(1),
            "app-logo/app-logo-img/app-logo-lg conservan caja y proporción")
finally:
    restore(saved, saved_dir)

print(R.report())
