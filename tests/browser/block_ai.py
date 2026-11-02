#!/usr/bin/env python3
"""Bloque AI: claves anti-bot (Cloudflare Turnstile) de la instancia."""

import os, re, sys
sys.path.insert(0, "tests/browser")
import vf

R = vf.R()
ah = vf.Http("admin@demo.com")
uh = vf.Http("user@demo.com", "password123")
KEYS = ["instance_turnstile_site_key", "instance_turnstile_secret_key"]
ENV = vf.ROOT / ".env"


def esc(s):
    return str(s).replace("\\", "\\\\").replace("'", "\\'")


def getv(k):
    r = vf.db1(f"SELECT value FROM app_settings WHERE key_name='{esc(k)}'")
    return None if not r else r[0]


def clear():
    vf.mysql("DELETE FROM app_settings WHERE key_name IN ('" + "','".join(KEYS) + "')")


def token():
    return ah.csrf(ah.get("/admin/settings")[1])


def save(data, follow=True):
    body = {"_token": token()}
    body.update(data)
    return ah.req("POST", "/admin/settings/turnstile", body, follow=follow)


def alertas(html):
    out = []
    for m in re.findall(r'<div class="alert[^"]*"[^>]*>(.*?)</div>', html, re.S):
        t = re.sub(r"<[^>]+>", " ", m)
        out.append(re.sub(r"\s+", " ", t).strip())
    return " | ".join(out)


raw_env = ENV.read_text()
env_mode = os.stat(ENV).st_mode
saved = {k: getv(k) for k in KEYS}
try:
    clear()
    s1, h1, _ = ah.get("/admin/settings")
    R.check("AI1", s1 == 200 and "Anti-bot (Cloudflare Turnstile)" in h1
            and 'name="turnstile_site_key"' in h1 and 'name="turnstile_secret"' in h1
            and 'action="/admin/settings/turnstile"' in h1 and "Sin configurar" in h1,
            "tarjeta anti-bot sin configurar")

    s2, h2, _ = save({"turnstile_site_key": "site-" + "a" * 20, "turnstile_secret": "secret-" + "b" * 20})
    h2b = ah.get("/admin/settings")[1]
    R.check("AI2", s2 == 200 and "Claves de Turnstile actualizadas." in h2
            and "Configurado" in h2b and getv("instance_turnstile_site_key") == "site-" + "a" * 20,
            f"status={s2}")

    R.check("AI3", 'type="password"' in h2b and 'name="turnstile_secret"' in h2b
            and "guardada" in h2b and "secret-" + "b" * 20 not in h2b
            and "Déjala vacía para no cambiarla" in h2b,
            "secret oculta y guardada")

    before4 = {k: getv(k) for k in KEYS}
    s4, h4, _ = save({"turnstile_site_key": "site-" + "a" * 20, "turnstile_secret": ""})
    h4b = ah.get("/admin/settings")[1]
    R.check("AI4", s4 == 200 and "Claves de Turnstile actualizadas." in h4
            and "guardada" in h4b and {k: getv(k) for k in KEYS} == before4,
            "secret intacta al guardar solo site")

    # AI5 — borrar la secret; sin secret en .env no puede quedar medio configurado.
    s5, h5, _ = save({"turnstile_site_key": "site-" + "a" * 20, "turnstile_secret": "", "clear_secret": "1"})
    h5b = ah.get("/admin/settings")[1]
    R.check("AI5", s5 == 200 and "necesita la site key Y la secret key" in h5 and "guardada" in h5b
            and getv("instance_turnstile_secret_key") is not None,
            f"status={s5} alertas={alertas(h5)}")

    # AI6 — solo site key, sin escrituras.
    clear()
    s6, h6, _ = save({"turnstile_site_key": "solo-site", "turnstile_secret": ""})
    R.check("AI6", s6 == 200 and "necesita la site key Y la secret key" in h6
            and getv("instance_turnstile_site_key") is None and getv("instance_turnstile_secret_key") is None,
            f"rechaza solo site: {alertas(h6)}")

    # AI7 — solo secret, sin escrituras.
    s7, h7, _ = save({"turnstile_site_key": "", "turnstile_secret": "solo-secret"})
    R.check("AI7", s7 == 200 and "necesita la site key Y la secret key" in h7
            and getv("instance_turnstile_site_key") is None and getv("instance_turnstile_secret_key") is None,
            f"rechaza solo secret: {alertas(h7)}")

    # AI8 — ambas en blanco + borrar: sin filas y vuelve al .env.
    save({"turnstile_site_key": "site-x", "turnstile_secret": "secret-x"})
    s8, h8, _ = save({"turnstile_site_key": "", "turnstile_secret": "", "clear_secret": "1"})
    h8b = ah.get("/admin/settings")[1]
    R.check("AI8", s8 == 200 and getv("instance_turnstile_site_key") is None and getv("instance_turnstile_secret_key") is None
            and "Sin configurar" in h8b,
            "ambas borradas")

    # AI9 — secret cifrada.
    secreto = "plana-" + "z" * 12
    save({"turnstile_site_key": "site-final", "turnstile_secret": secreto})
    guardada = getv("instance_turnstile_secret_key")
    R.check("AI9", guardada is not None and guardada != secreto and secreto not in guardada
            and len(guardada) > 40 and "guardada" in ah.get("/admin/settings")[1],
            f"largo={len(guardada or '')} contiene_plano={secreto in (guardada or '')}")

    # AI10 — sin APP_KEY no se guarda la secret (ni la site de la tanda).
    antes10 = {k: getv(k) for k in KEYS}
    try:
        ENV.write_text(re.sub(r"^APP_KEY=.*$", "APP_KEY=", raw_env, flags=re.M))
        os.chmod(ENV, env_mode)
        s10, h10, _ = save({"turnstile_site_key": "site-nueva", "turnstile_secret": "secreta-nueva"})
    finally:
        ENV.write_text(raw_env)
        os.chmod(ENV, env_mode)
    R.check("AI10", s10 == 200 and "APP_KEY" in h10 and antes10 == {k: getv(k) for k in KEYS},
            f"status={s10} sin_cambios={antes10 == {k: getv(k) for k in KEYS}}")

    # AI11 — sin admin.access.
    t11 = uh.csrf(uh.get("/dashboard")[1])
    antes11 = {k: getv(k) for k in KEYS}
    s11, _, _ = uh.req("POST", "/admin/settings/turnstile", {"_token": t11, "turnstile_site_key": "x"}, follow=False)
    R.check("AI11", s11 == 403 and antes11 == {k: getv(k) for k in KEYS}, f"403 ({s11})")

    # AI12 — camino nativo (PRG).
    clear()
    s12, _, _ = save({"turnstile_site_key": "native-site", "turnstile_secret": "native-secret"}, follow=False)
    h12 = ah.get("/admin/settings")[1]
    R.check("AI12", s12 == 302 and getv("instance_turnstile_site_key") == "native-site"
            and "Configurado" in h12,
            f"nativo {s12}")
finally:
    ENV.write_text(raw_env)
    os.chmod(ENV, env_mode)
    clear()
    for k, v in saved.items():
        if v is None:
            vf.mysql(f"DELETE FROM app_settings WHERE key_name='{esc(k)}'")
        else:
            vf.mysql(f"INSERT INTO app_settings(key_name,value) VALUES('{esc(k)}','{esc(v)}') ON DUPLICATE KEY UPDATE value=VALUES(value)")

print(R.report())
