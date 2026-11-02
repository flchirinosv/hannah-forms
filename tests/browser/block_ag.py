#!/usr/bin/env python3
"""Bloque AG: correo SMTP de la instancia."""

import json, os, re, socket, sys, threading, uuid
sys.path.insert(0, "tests/browser")
import vf

R = vf.R()
ah = vf.Http("admin@demo.com")
suf = uuid.uuid4().hex[:8]
KEYS = ["mail_host", "mail_port", "mail_encryption", "mail_user", "mail_password",
        "mail_from_address", "mail_from_name", "mail_timeout"]
ENV = vf.ROOT / ".env"


def esc(s):
    return str(s).replace("\\", "\\\\").replace("'", "\\'")


def rows():
    out = {}
    for k, v in vf.mysql(
            "SELECT key_name,value FROM app_settings WHERE key_name IN ('" +
            "','".join(KEYS) + "') ORDER BY key_name"):
        out[k] = v
    return out


def set_setting(k, v):
    if v is None:
        vf.mysql(f"DELETE FROM app_settings WHERE key_name='{esc(k)}'")
    else:
        vf.mysql(
            "INSERT INTO app_settings (key_name,value) VALUES "
            f"('{esc(k)}','{esc(v)}') ON DUPLICATE KEY UPDATE value=VALUES(value)")


def reset_mail():
    vf.mysql("DELETE FROM app_settings WHERE key_name IN ('" + "','".join(KEYS) + "')")


def settings_html():
    return ah.get("/admin/settings")[1]


def token(path="/admin/settings"):
    return ah.csrf(ah.get(path)[1])


def save_mail(data, follow=True):
    body = {"_token": token()}
    body.update(data)
    return ah.req("POST", "/admin/settings/mail", body, follow=follow)


def test_mail(data, follow=True):
    body = {"_token": token()}
    body.update(data)
    return ah.req("POST", "/admin/settings/mail/test", body, follow=follow)


def alertas(html):
    out = []
    for m in re.findall(r'<div class="alert[^"]*"[^>]*>(.*?)</div>', html, re.S):
        t = re.sub(r"<[^>]+>", " ", m)
        out.append(re.sub(r"\s+", " ", t).strip())
    return " | ".join(out)[:700]


def field_value(html, name):
    m = re.search(r'name="' + re.escape(name) + r'"[^>]*value="([^"]*)"', html)
    return m.group(1) if m else None


def free_port():
    s = socket.socket()
    s.bind(("127.0.0.1", 0))
    p = s.getsockname()[1]
    s.close()
    return p


class FakeSMTP:
    def __init__(self):
        self.sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        self.sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        self.sock.bind(("127.0.0.1", 0))
        self.port = self.sock.getsockname()[1]
        self.sock.listen(20)
        self.messages = []
        self.lock = threading.Lock()
        self.stop = False
        threading.Thread(target=self.loop, daemon=True).start()

    def loop(self):
        while not self.stop:
            try:
                c, _ = self.sock.accept()
            except OSError:
                return
            threading.Thread(target=self.handle, args=(c,), daemon=True).start()

    def handle(self, conn):
        f = conn.makefile("rwb", buffering=0)
        auth_state = 0
        try:
            f.write(b"220 bt.local ESMTP fake\r\n")
            rcpts, data = [], None
            while True:
                line = f.readline()
                if not line:
                    break
                raw = line.rstrip(b"\r\n")
                cmd = raw.decode("utf-8", "replace")
                up = cmd.upper()
                if data is not None:
                    if raw == b".":
                        with self.lock:
                            self.messages.append({"rcpts": list(rcpts), "data": data.decode("utf-8", "replace")})
                        data = None
                        rcpts = []
                        f.write(b"250 2.0.0 queued\r\n")
                    else:
                        data += line
                    continue
                if up.startswith("EHLO") or up.startswith("HELO"):
                    f.write(b"250-bt.local\r\n250-8BITMIME\r\n250 AUTH LOGIN PLAIN\r\n")
                elif up.startswith("AUTH LOGIN"):
                    auth_state = 1
                    f.write(b"334 VXNlcm5hbWU6\r\n")
                elif up.startswith("AUTH PLAIN"):
                    f.write(b"235 2.7.0 Authentication successful\r\n")
                elif auth_state == 1:
                    auth_state = 2
                    f.write(b"334 UGFzc3dvcmQ6\r\n")
                elif auth_state == 2:
                    auth_state = 0
                    f.write(b"235 2.7.0 Authentication successful\r\n")
                elif up.startswith("MAIL FROM"):
                    f.write(b"250 2.1.0 ok\r\n")
                elif up.startswith("RCPT TO"):
                    m = re.search(r"<([^>]+)>", cmd)
                    if m:
                        rcpts.append(m.group(1))
                    f.write(b"250 2.1.5 ok\r\n")
                elif up == "DATA":
                    data = b""
                    f.write(b"354 end with .\r\n")
                elif up == "QUIT":
                    f.write(b"221 bye\r\n")
                    break
                else:
                    f.write(b"250 ok\r\n")
        except Exception:
            pass
        finally:
            try:
                f.close()
            except Exception:
                pass
            try:
                conn.close()
            except Exception:
                pass

    def reset(self):
        with self.lock:
            self.messages = []

    def count(self):
        with self.lock:
            return len(self.messages)


saved = rows()
raw_env = ENV.read_text()
env_mode = os.stat(ENV).st_mode
smtp = FakeSMTP()
try:
    reset_mail()
    # Valores base: host local, sin cifrado, sin usuario (el resto de pruebas
    # que necesitan AUTH lo activan explícitamente).
    base = {
        "mail_host": "127.0.0.1", "mail_port": str(smtp.port),
        "mail_encryption": "", "mail_user": "", "mail_password": "",
        "mail_from_address": "noreply@bt.local", "mail_from_name": "BT",
        "mail_timeout": "10",
    }

    # AG1 — tarjeta, campos y botón, sin configurar.
    s1, h1, _ = ah.get("/admin/settings")
    R.check("AG1", s1 == 200 and "Correo (SMTP)" in h1
            and 'name="mail_host"' in h1 and 'name="mail_port"' in h1
            and 'name="mail_encryption"' in h1 and 'name="mail_user"' in h1
            and 'name="mail_password"' in h1 and 'action="/admin/settings/mail/test"' in h1
            and "Sin configurar" in h1,
            "tarjeta inicial sin configurar")

    # AG2 — servidor, puerto, cifrado, usuario y contraseña; PRG y relectura.
    data2 = dict(base, mail_user="bt-user", mail_password="s3cr3t-" + suf)
    s2, h2, url2 = save_mail(data2)
    html2 = settings_html()
    R.check("AG2", s2 == 200 and url2.endswith("/admin/settings") and "Correo actualizado." in h2
            and field_value(html2, "mail_host") == "127.0.0.1"
            and field_value(html2, "mail_user") == "bt-user"
            and field_value(html2, "mail_from_address") == "noreply@bt.local"
            and field_value(html2, "mail_password") == ""
            and "Configurado" in html2,
            f"status={s2} url={url2}")

    # AG3 — la contraseña no vuelve al HTML y se anuncia «guardada».
    R.check("AG3", 'type="password"' in html2 and 'name="mail_password"' in html2
            and "guardada" in html2 and "s3cr3t-" + suf not in html2
            and "Déjalo vacío para no cambiarla" in html2,
            "password vacío + insignia guardada")

    # AG4 — guardar otro campo no toca la contraseña.
    pwd3 = vf.db1("SELECT value FROM app_settings WHERE key_name='mail_password'")
    s4, h4, _ = save_mail(dict(base, mail_user="bt-user", mail_password="", mail_from_name="BT Otro " + suf))
    html4 = settings_html()
    R.check("AG4", s4 == 200 and "guardada" in html4
            and vf.db1("SELECT value FROM app_settings WHERE key_name='mail_password'") == pwd3
            and field_value(html4, "mail_from_name") == "BT Otro " + suf,
            "contraseña intacta al guardar el nombre")

    # AG5 — borrar la contraseña guardada.
    s5, h5, _ = save_mail(dict(base, mail_user="bt-user", mail_password="", clear_password="1"))
    html5 = settings_html()
    R.check("AG5", s5 == 200 and "guardada" not in html5
            and vf.db1("SELECT COUNT(*) FROM app_settings WHERE key_name='mail_password'")[0] == "0"
            and "Borrar la contraseña guardada" not in html5,
            "fila de contraseña borrada")

    # AG6 — puerto fuera de rango: error y ninguna escritura.
    antes6 = rows()
    s6, h6, _ = save_mail(dict(base, mail_port="70000"))
    despues6 = rows()
    R.check("AG6", s6 == 200 and "El puerto tiene que estar entre 1 y 65535." in h6
            and antes6 == despues6,
            f"status={s6} antes={antes6} despues={despues6}")

    # AG7 — remitente inválido: error y ninguna escritura.
    antes7 = rows()
    s7, h7, _ = save_mail(dict(base, mail_from_address="no-es-correo"))
    despues7 = rows()
    R.check("AG7", s7 == 200 and "El remitente no es una dirección de correo válida." in h7
            and antes7 == despues7,
            f"status={s7} antes={antes7} despues={despues7}")

    # AG8 — servidor vacío: se borra la fila y avisa de que no hay SMTP.
    save_mail(base)
    s8, h8, _ = save_mail(dict(base, mail_host=""))
    html8 = settings_html()
    R.check("AG8", s8 == 200 and vf.db1("SELECT COUNT(*) FROM app_settings WHERE key_name='mail_host'")[0] == "0"
            and "No hay ningún servidor configurado" in html8
            and "Sin configurar" in html8
            and re.search(r'<button type="submit" class="btn btn-outline-primary app-form-cta"\s+disabled', html8),
            "host borrado y aviso sin configurar")

    # AG9 — envío real al SMTP falso y destino explícito.
    save_mail(dict(base, mail_user="bt-user", mail_password="s3cr3t-" + suf))
    smtp.reset()
    s9, h9, _ = test_mail({"test_to": "destino@bt.local"})
    R.check("AG9", s9 == 200 and "Correo de prueba enviado a destino@bt.local." in h9
            and smtp.count() == 1 and smtp.messages[0]["rcpts"] == ["destino@bt.local"],
            f"status={s9} mails={smtp.count()} rcpts={[m['rcpts'] for m in smtp.messages]} flash={alertas(h9)}")

    # AG10 — servidor inexistente: aviso, no 500 y configuración intacta.
    puerto10 = free_port()
    save_mail(dict(base, mail_host="127.0.0.1", mail_port=str(puerto10), mail_user=""))
    antes10 = rows()
    s10, h10, _ = test_mail({"test_to": "destino@bt.local"})
    despues10 = rows()
    R.check("AG10", s10 == 200 and "No se pudo enviar la prueba:" in h10
            and antes10 == despues10,
            f"status={s10} aviso={'No se pudo enviar la prueba:' in h10}")

    # AG11 — sin destino se manda al correo de quien pide la prueba.
    save_mail(dict(base, mail_user=""))
    smtp.reset()
    s11, h11, _ = test_mail({"test_to": ""})
    R.check("AG11", s11 == 200 and "Correo de prueba enviado a admin@demo.com." in h11
            and smtp.count() == 1 and smtp.messages[0]["rcpts"] == ["admin@demo.com"],
            f"status={s11} mails={smtp.count()} rcpts={[m['rcpts'] for m in smtp.messages]} flash={alertas(h11)}")

    # AG12 — la contraseña guardada está cifrada, no en claro.
    secreto = "plano-" + suf
    save_mail(dict(base, mail_user="bt-user", mail_password=secreto))
    guardado = vf.db1("SELECT value FROM app_settings WHERE key_name='mail_password'")[0]
    R.check("AG12", guardado != secreto and secreto not in guardado
            and len(guardado) > 40 and "guardada" in settings_html(),
            f"largo={len(guardado)} contiene_plano={secreto in guardado}")

    # AG13 — sin APP_KEY no se guarda la contraseña (ni el resto de la tanda).
    antes13 = rows()
    try:
        ENV.write_text(re.sub(r"^APP_KEY=.*$", "APP_KEY=", raw_env, flags=re.M))
        os.chmod(ENV, env_mode)
        s13, h13, _ = save_mail(dict(base, mail_user="bt-user", mail_password="otra-" + suf))
    finally:
        ENV.write_text(raw_env)
        os.chmod(ENV, env_mode)
    despues13 = rows()
    R.check("AG13", s13 == 200 and "APP_KEY" in h13 and "no hay APP_KEY" not in h13.lower()
            and "No hay APP_KEY" in h13 and antes13 == despues13,
            f"status={s13} aviso={'APP_KEY' in h13} sin_cambios={antes13 == despues13}")

    # AG14 — sin admin.access: 403 en guardar y en probar.
    uh = vf.Http("user@demo.com", "password123")
    _, dash14, _ = uh.get("/dashboard")
    t14 = uh.csrf(dash14)
    antes14 = rows()
    s14a, _, _ = uh.req("POST", "/admin/settings/mail", {"_token": t14, "mail_host": "x"}, follow=False)
    s14b, _, _ = uh.req("POST", "/admin/settings/mail/test", {"_token": t14, "test_to": "destino@bt.local"}, follow=False)
    R.check("AG14", s14a == 403 and s14b == 403 and antes14 == rows(),
            f"guardar={s14a} probar={s14b}")

    # AG15 — camino nativo: guardar y probar por POST sin JavaScript (PRG).
    smtp.reset()
    s15a, _, _ = save_mail(dict(base, mail_user=""), follow=False)
    s15b, _, _ = test_mail({"test_to": "nativo@bt.local"}, follow=False)
    _, h15, _ = ah.get("/admin/settings")
    R.check("AG15", s15a == 302 and s15b == 302 and smtp.count() == 1
            and smtp.messages[0]["rcpts"] == ["nativo@bt.local"]
            and "Correo de prueba enviado a nativo@bt.local." in h15,
            f"guardar={s15a} probar={s15b} mails={smtp.count()} flash={alertas(h15)}")
finally:
    smtp.stop = True
    try:
        smtp.sock.close()
    except Exception:
        pass
    ENV.write_text(raw_env)
    os.chmod(ENV, env_mode)
    reset_mail()
    for k, v in saved.items():
        set_setting(k, v)

print(R.report())
