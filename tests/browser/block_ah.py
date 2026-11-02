#!/usr/bin/env python3
"""Bloque AH: Identidad operativa de la instancia."""

import json, os, re, sys
sys.path.insert(0, "tests/browser")
import vf

R = vf.R()
ah = vf.Http("admin@demo.com")
uh = vf.Http("user@demo.com", "password123")
KEYS = ["instance_public_url", "instance_timezone", "instance_locale"]


def esc(s):
    return str(s).replace("\\", "\\\\").replace("'", "\\'")


def getv(k):
    r = vf.db1(f"SELECT value FROM app_settings WHERE key_name='{esc(k)}'")
    return None if not r else r[0]


def clear():
    vf.mysql("DELETE FROM app_settings WHERE key_name IN ('" + "','".join(KEYS) + "')")


def token(path="/admin/settings"):
    return ah.csrf(ah.get(path)[1])


def save(data, follow=True):
    body = {"_token": token()}
    body.update(data)
    return ah.req("POST", "/admin/settings/instance", body, follow=follow)


saved = {k: getv(k) for k in KEYS}
try:
    clear()
    s1, h1, _ = ah.get("/admin/settings")
    R.check("AH1", s1 == 200 and "Identidad operativa" in h1
            and 'name="instance_public_url"' in h1 and 'name="instance_timezone"' in h1
            and 'name="instance_locale"' in h1 and 'action="/admin/settings/instance"' in h1,
            "tarjeta identidad")

    s2, h2, url2 = save({"instance_public_url": "https://formularios.midominio.com", "instance_timezone": "", "instance_locale": ""})
    html2 = ah.get("/admin/settings")[1]
    R.check("AH2", s2 == 200 and url2.endswith("/admin/settings") and "Identidad de la instancia actualizada." in h2
            and 'value="https://formularios.midominio.com"' in html2,
            f"status={s2} url={url2}")

    s3, fh, _ = ah.get("/forms/263816")
    R.check("AH3", s3 == 200 and "https://formularios.midominio.com/f/bt-p-blico-simple" in fh,
            "enlace usa URL pública")

    s4, h4, _ = save({"instance_public_url": "https://formularios.midominio.com", "instance_timezone": "America/Bogota", "instance_locale": ""})
    R.check("AH4", s4 == 200 and getv("instance_timezone") == "America/Bogota" and "Identidad de la instancia actualizada." in h4,
            "zona horaria guardada")

    s5, h5, _ = save({"instance_public_url": "https://formularios.midominio.com", "instance_timezone": "", "instance_locale": "en"})
    R.check("AH5", s5 == 200 and getv("instance_locale") == "en" and "Identidad de la instancia actualizada." in h5,
            "idioma por defecto guardado")

    s6, h6, _ = save({"instance_public_url": "", "instance_timezone": "", "instance_locale": ""})
    R.check("AH6", s6 == 200 and getv("instance_public_url") is None and getv("instance_timezone") is None and getv("instance_locale") is None
            and "Identidad de la instancia actualizada." in h6,
            "vuelve al .env")

    save({"instance_public_url": "https://x.com", "instance_timezone": "America/Bogota", "instance_locale": "es"})
    antes7 = {k: getv(k) for k in KEYS}
    s7, h7, _ = save({"instance_public_url": "no es una url", "instance_timezone": "", "instance_locale": ""})
    despues7 = {k: getv(k) for k in KEYS}
    R.check("AH7", s7 == 200 and "URL pública no es válida" in h7 and antes7 == despues7,
            "URL inválida rechazada sin cambios")

    clear()
    s8, h8, _ = save({"instance_public_url": "", "instance_timezone": "Marte/Olympus", "instance_locale": ""})
    R.check("AH8", s8 == 200 and "zona horaria no es" in h8 and getv("instance_timezone") is None,
            "zona inválida rechazada")

    save({"instance_public_url": "https://formularios.midominio.com", "instance_timezone": "America/Lima", "instance_locale": "en"})
    R.check("AH9", getv("instance_public_url") == "https://formularios.midominio.com"
            and getv("instance_timezone") == "America/Lima"
            and getv("instance_locale") == "en",
            "filas en app_settings")

    t10 = uh.csrf(uh.get("/dashboard")[1])
    s10, _, _ = uh.req("POST", "/admin/settings/instance", {"_token": t10, "instance_timezone": "America/Caracas"}, follow=False)
    R.check("AH10", s10 == 403 and getv("instance_timezone") == "America/Lima",
            f"403 y no cambia ({s10})")

    clear()
    s11, _, _ = save({"instance_public_url": "https://nativo.test", "instance_timezone": "", "instance_locale": ""}, follow=False)
    html11 = ah.get("/admin/settings")[1]
    R.check("AH11", s11 == 302 and getv("instance_public_url") == "https://nativo.test" and "nativo.test" in html11,
            "nativo PRG")
finally:
    clear()
    for k, v in saved.items():
        if v is None:
            vf.mysql(f"DELETE FROM app_settings WHERE key_name='{esc(k)}'")
        else:
            vf.mysql(f"INSERT INTO app_settings(key_name,value) VALUES('{esc(k)}','{esc(v)}') ON DUPLICATE KEY UPDATE value=VALUES(value)")

print(R.report())
