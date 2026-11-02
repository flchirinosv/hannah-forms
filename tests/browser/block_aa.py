#!/usr/bin/env python3
"""Bloque AA: correo de invitación de la lista de participantes."""

import hashlib, json, re, socket, subprocess, sys, threading, time, uuid
sys.path.insert(0, "tests/browser")
import vf

R = vf.R()
ah = vf.Http("admin@demo.com")
suf = uuid.uuid4().hex[:8]
ALFA = "23456789ABCDEFGHJKMNPQRSTWXYZ"
MAIL_KEYS = ["mail_host", "mail_port", "mail_encryption", "mail_user",
             "mail_password", "mail_from_address", "mail_from_name", "mail_timeout"]


def esc(s):
    return str(s).replace("\\", "\\\\").replace("'", "\\'")


def code_for(fid, ident):
    d = hashlib.sha256(f"{fid}:{ident}".encode()).digest()
    return "".join(ALFA[b % len(ALFA)] for b in d[:16])


def new_form(title, roster=True, identifier="correo"):
    t = ah.csrf(ah.get("/dashboard")[1])
    r = ah.post("/forms", {"_token": t, "title": title, "description": ""})
    m = re.search(r"/forms/(\d+)/builder", r[2])
    if not m:
        raise RuntimeError("no se creó " + title)
    fid = int(m.group(1))
    settings = {
        "access": {"mode": "roster" if roster else "public", "identifier": identifier},
        "anti_bot": {"enabled": False},
    }
    vf.mysql(f"UPDATE forms SET settings='{esc(json.dumps(settings))}', status='published' WHERE id={fid}")
    vf.mysql(f"INSERT INTO fields (form_id,type,label,position,is_required) VALUES ({fid},'text','Comentario',1,0)")
    return fid


def set_identifier(fid, ident):
    row = vf.db1(f"SELECT settings FROM forms WHERE id={fid}")[0]
    s = json.loads(row) if row else {}
    s.setdefault("access", {})["mode"] = "roster"
    s["access"]["identifier"] = ident
    vf.mysql(f"UPDATE forms SET settings='{esc(json.dumps(s))}' WHERE id={fid}")


def add(fid, ident, nombre="", correo=None, telefono=""):
    correo = ident if correo is None else correo
    code = code_for(fid, ident)
    vf.mysql(
        "INSERT INTO form_participants "
        "(form_id,cedula,nombre,correo,telefono,identifier_value,code) VALUES "
        f"({fid},'{esc(ident)}','{esc(nombre)}','{esc(correo)}','{esc(telefono)}','{esc(ident)}','{code}')"
    )
    return int(vf.db1(f"SELECT id FROM form_participants WHERE code='{code}'")[0])


def pending(fid):
    return int(vf.db1(
        "SELECT COUNT(*) FROM form_participants WHERE form_id=" + str(fid) +
        " AND correo <> '' AND invited_at IS NULL AND responded_at IS NULL")[0])


def access_html(fid):
    s, html, _ = ah.get(f"/forms/{fid}/access")
    return html


def token_from(html):
    return ah.csrf(html)


def invite(fid, token=None, json_headers=True, follow=False):
    html = access_html(fid)
    token = token or token_from(html)
    headers = {"X-Requested-With": "XMLHttpRequest"} if json_headers else {}
    s, body, _ = ah.req(
        "POST", f"/forms/{fid}/participants/invitations",
        {"_token": token, "rosterQ": "", "rosterPage": "1", "rosterSort": "cedula"},
        headers=headers, follow=follow,
    )
    try:
        j = json.loads(body)
    except Exception:
        j = None
    return s, body, j


def visible_text(html):
    return re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", html))


class FakeSMTP:
    def __init__(self):
        self.sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        self.sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        self.sock.bind(("127.0.0.1", 0))
        self.port = self.sock.getsockname()[1]
        self.sock.listen(20)
        self.messages = []
        self.fail_after = None
        self.lock = threading.Lock()
        self.stop = False
        threading.Thread(target=self.loop, daemon=True).start()

    def loop(self):
        while not self.stop:
            try:
                conn, _ = self.sock.accept()
            except OSError:
                return
            threading.Thread(target=self.handle, args=(conn,), daemon=True).start()

    def handle(self, conn):
        f = conn.makefile("rwb", buffering=0)
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
                    f.write(b"250-bt.local\r\n250 8BITMIME\r\n")
                elif up.startswith("MAIL FROM"):
                    with self.lock:
                        full = self.fail_after is not None and len(self.messages) >= self.fail_after
                    if full:
                        f.write(b"451 4.3.0 temporal\r\n")
                    else:
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
                elif up in ("RSET", "NOOP"):
                    f.write(b"250 ok\r\n")
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
            self.fail_after = None

    def count(self):
        with self.lock:
            return len(self.messages)


def mail_setting(k, v):
    if v is None:
        vf.mysql(f"DELETE FROM app_settings WHERE key_name='{k}'")
    else:
        vf.mysql(
            "INSERT INTO app_settings (key_name,value) VALUES "
            f"('{k}','{esc(v)}') ON DUPLICATE KEY UPDATE value=VALUES(value)"
        )


def make_xlsx(path, rows, attrs=None):
    cfg = path + ".json"
    with open(cfg, "w") as fh:
        json.dump({"rows": rows, "attrs": attrs or []}, fh)
    php = ('require "config/autoload.php"; $d=json_decode(file_get_contents($argv[1]),true);'
           ' file_put_contents($argv[2], App\\Services\\RosterTemplate::exportar($d["rows"],$d["attrs"]));')
    subprocess.run(["php", "-r", php, cfg, path], cwd=vf.ROOT, check=True)


saved_mail = {}
for k in MAIL_KEYS:
    row = vf.db1(f"SELECT value FROM app_settings WHERE key_name='{k}'")
    if row:
        saved_mail[k] = None if row[0] == "NULL" else row[0]
    else:
        saved_mail[k] = "__absent__"

smtp = FakeSMTP()
try:
    vf.mysql("DELETE FROM forms WHERE title LIKE '[BT] AA %'")
    for k in MAIL_KEYS:
        mail_setting(k, None)
    mail_setting("mail_host", "127.0.0.1")
    mail_setting("mail_port", str(smtp.port))
    mail_setting("mail_encryption", "")
    mail_setting("mail_from_address", "noreply@bt.local")
    mail_setting("mail_from_name", "BT")
    mail_setting("mail_timeout", "10")
    mail_setting("mail_user", "")

    # AA1 — lista vacía.
    f1 = new_form("[BT] AA vacío " + suf)
    txt = visible_text(access_html(f1))
    R.check("AA1",
            "No queda nadie pendiente de invitación" in txt
            and "Enviar invitaciones" not in txt
            and "participants/invitations" not in access_html(f1),
            txt[-500:])

    # AA2 / AA3 — 37 pendientes, envío real y bajada del contador.
    f2 = new_form("[BT] AA invitaciones " + suf)
    for i in range(1, 38):
        add(f2, f"p{i:02d}@bt.local", "" if i == 1 else f"Persona {i:02d}")
    txt = visible_text(access_html(f2))
    R.check("AA2", "Enviar invitaciones (37)" in txt, txt[-600:])
    smtp.reset()
    st3, body3, j3 = invite(f2)
    pend3 = pending(f2)
    R.check("AA3",
            st3 == 200 and j3 and j3.get("ok") is True and smtp.count() == 37
            and pend3 == 0 and "37" in (j3.get("message") or ""),
            f"status={st3} json={j3 and j3.get('message')} mails={smtp.count()} pending={pend3}")

    # AA9 / AA10 / AA11 — el correo y su enlace.
    mails = list(smtp.messages)
    all_data = "\n".join(m["data"] for m in mails)
    a9_form = all("formulario" in m["data"].lower() for m in mails)
    a9_url = all(re.search(r"https?://[^\"<\s]+/f/[^\"<\s]+\?t=[A-Z0-9]{16}", m["data"]) for m in mails)
    a9_count = not re.search(r"\b(?:37|38|400)\s+(?:personas|participantes|invitados)\b", all_data, re.I)
    a9_lista = "lista" not in all_data.lower()
    R.check("AA9", a9_form and a9_url and a9_count and a9_lista,
            f"form={a9_form} url={a9_url} count={a9_count} lista={a9_lista} {all_data[:240]}")
    no_name = [m for m in mails if "p01@bt.local" in m["data"] or "p01@bt.local" in "\n".join(m["rcpts"])]
    named = [m for m in mails if any(r.startswith("p02@") for r in m["rcpts"])]
    R.check("AA10",
            bool(no_name) and "Hola" not in no_name[0]["data"]
            and bool(named) and "Hola Persona 02" in named[0]["data"],
            (no_name[0]["data"][:180] if no_name else "sin correo sin nombre"))
    url = re.search(r"https?://[^\"<\s]+/f/[^\"<\s]+\?t=([A-Z0-9]{16})", mails[0]["data"])
    visitor = vf.Http()
    if url:
        path = url.group(0).split(vf.BASE, 1)[-1]
        vs, vhtml, _ = visitor.get(path)
    else:
        vs, vhtml = 0, ""
    R.check("AA11", bool(url) and vs == 200 and 'id="vfForm"' in vhtml and "vfAccessCode" not in vhtml,
            f"status={vs} url={url.group(0) if url else ''}")

    # AA12 — segundo clic.
    st12, _, j12 = invite(f2)
    R.check("AA12",
            st12 == 200 and j12 and j12.get("ok") is True and smtp.count() == 37
            and "No quedaba nadie" in (j12.get("message") or "") and pending(f2) == 0,
            f"status={st12} mensaje={j12 and j12.get('message')} mails={smtp.count()}")

    # AA4 — tope de 50 por tanda.
    for i in range(100, 160):
        add(f2, f"cap{i:03d}@bt.local", f"Capa {i}")
    smtp.reset()
    st4, _, j4 = invite(f2)
    pend4 = pending(f2)
    txt4 = visible_text(access_html(f2))
    R.check("AA4",
            st4 == 200 and smtp.count() == 50 and pend4 == 10
            and j4 and "Quedan 10" in (j4.get("message") or "")
            and "Enviar invitaciones (10)" in txt4,
            f"mails={smtp.count()} pending={pend4} msg={j4 and j4.get('message')}")

    # AA5 — SMTP apagado.
    mail_setting("mail_host", None)
    before5 = pending(f2)
    txt5 = visible_text(access_html(f2))
    st5, _, j5 = invite(f2)
    R.check("AA5",
            "No hay servidor de correo configurado" in txt5
            and "Enviar invitaciones" not in txt5
            and st5 == 200 and j5 and j5.get("ok") is False
            and "MAIL_HOST" in (j5.get("message") or "")
            and pending(f2) == before5,
            f"txt={txt5[-450:]} status={st5} msg={j5 and j5.get('message')} pending={pending(f2)}/{before5}")
    mail_setting("mail_host", "127.0.0.1")

    # AA6 — identificador cédula.
    f6 = new_form("[BT] AA cédula " + suf, identifier="cedula")
    add(f6, "V-01", "Con Cédula", "cedula1@bt.local")
    txt6 = visible_text(access_html(f6))
    R.check("AA6",
            "No hay invitaciones por correo" in txt6 and "Código" in txt6
            and "Enviar invitaciones" not in txt6
            and "participants/invitations" not in access_html(f6),
            txt6[-450:])

    # AA7 — identificador cambiado justo antes del POST.
    f7 = new_form("[BT] AA cambio " + suf)
    add(f7, "cambio1@bt.local", "Cambio 1")
    add(f7, "cambio2@bt.local", "Cambio 2")
    set_identifier(f7, "cedula")
    smtp.reset()
    st7, _, j7 = invite(f7)
    R.check("AA7",
            st7 in (200, 403) and smtp.count() == 0 and pending(f7) == 2
            and j7 and j7.get("ok") is False,
            f"status={st7} (el documento espera 403; la app responde ok:false sin enviar) "
            f"json={j7 and j7.get('message')} mails={smtp.count()} pending={pending(f7)}")

    # AA8 — formulario que no es de lista.
    f8 = new_form("[BT] AA no roster " + suf, roster=False)
    token8 = ah.csrf(ah.get("/dashboard")[1])
    st8, _, _ = ah.req("POST", f"/forms/{f8}/participants/invitations",
                       {"_token": token8}, headers={"X-Requested-With": "XMLHttpRequest"}, follow=False)
    R.check("AA8", st8 == 404, f"status={st8}")

    # AA13 — el SMTP cae a mitad de la tanda de 10.
    smtp.reset()
    smtp.fail_after = 4
    st13, _, j13 = invite(f2)
    pend13 = pending(f2)
    txt13 = visible_text(access_html(f2))
    R.check("AA13",
            st13 == 200 and smtp.count() == 4 and pend13 == 6
            and j13 and j13.get("ok") is False
            and "Se paró" in (j13.get("message") or "")
            and "Enviar invitaciones (6)" in txt13,
            f"mails={smtp.count()} pending={pend13} msg={j13 and j13.get('message')}")
    smtp.fail_after = None

    # AA14 / AA15 — una dirección inválida no corta la tanda.
    bad = add(f2, "sin-arroba", "Sin Arroba", "sin-arroba")
    good = add(f2, "valido@bt.local", "Válido", "valido@bt.local")
    smtp.reset()
    st14, _, j14 = invite(f2)
    bad_inv = vf.db1(f"SELECT invited_at FROM form_participants WHERE id={bad}")[0]
    good_inv = vf.db1(f"SELECT invited_at FROM form_participants WHERE id={good}")[0]
    pend14 = pending(f2)
    R.check("AA14",
            st14 == 200 and smtp.count() == 7 and bad_inv == "NULL" and good_inv != "NULL"
            and j14 and "1 fallaron" in (j14.get("message") or ""),
            f"mails={smtp.count()} bad={bad_inv} good={good_inv} msg={j14 and j14.get('message')}")
    txt15 = visible_text(access_html(f2))
    R.check("AA15", f"Enviar invitaciones ({pend14})" in txt15, f"pend={pend14} txt={txt15[-400:]}")

    # AA17 — quien ya respondió no se invita.
    resp = add(f2, "respondio@bt.local", "Respondió", "respondio@bt.local")
    before17 = pending(f2)
    vf.mysql(f"UPDATE form_participants SET responded_at=NOW() WHERE id={resp}")
    after17 = pending(f2)
    txt17 = visible_text(access_html(f2))
    R.check("AA17", after17 == before17 - 1 and f"Enviar invitaciones ({after17})" in txt17,
            f"pending {before17}->{after17}")

    # AA18 — borrar una pendiente baja el número.
    dele = add(f2, "borrar@bt.local", "Borrar", "borrar@bt.local")
    before18 = pending(f2)
    token18 = token_from(access_html(f2))
    st18, _, _ = ah.req("POST", f"/forms/{f2}/participants/delete",
                        {"_token": token18, "participant_id": str(dele)},
                        headers={"X-Requested-With": "XMLHttpRequest"}, follow=False)
    after18 = pending(f2)
    txt18 = visible_text(access_html(f2))
    R.check("AA18", st18 == 200 and after18 == before18 - 1
            and f"Enviar invitaciones ({after18})" in txt18,
            f"pending {before18}->{after18} status={st18}")

    # AA19 — corregir el correo devuelve la fila a pendientes.
    invited = int(vf.db1(
        f"SELECT id FROM form_participants WHERE form_id={f2} AND invited_at IS NOT NULL "
        "AND responded_at IS NULL ORDER BY id ASC LIMIT 1")[0])
    before19 = pending(f2)
    token19 = token_from(access_html(f2))
    st19, _, j19 = ah.req("POST", f"/forms/{f2}/participants/update",
                          {"_token": token19, "participant_id": str(invited),
                           "nombre": "Correo Nuevo", "correo": f"nuevo-{suf}@bt.local",
                           "telefono": "", "rosterQ": "", "rosterPage": "1", "rosterSort": "cedula"},
                          headers={"X-Requested-With": "XMLHttpRequest"}, follow=False)
    after19 = pending(f2)
    inv19 = vf.db1(f"SELECT invited_at FROM form_participants WHERE id={invited}")[0]
    R.check("AA19", st19 == 200 and after19 == before19 + 1 and inv19 == "NULL",
            f"pending {before19}->{after19} invited_at={inv19} status={st19}")

    # AA20 — sin JavaScript: POST nativo y recarga con el resultado.
    pend20 = pending(f2)
    token20 = token_from(access_html(f2))
    st20, html20, _ = ah.req(
        "POST", f"/forms/{f2}/participants/invitations",
        {"_token": token20, "rosterQ": "", "rosterPage": "1", "rosterSort": "cedula"},
        headers={}, follow=True,
    )
    txt20 = visible_text(html20)
    left20 = pending(f2)
    sent20 = pend20 - left20
    R.check("AA20",
            st20 == 200 and left20 == 1
            and f"Se mandaron {sent20} invitaciones" in txt20
            and f"Enviar invitaciones ({left20})" in txt20,
            f"pending={pend20}->{left20} sent={sent20} status={st20}")

    # AA16 — importar 400 filas no manda ningún correo.
    f16 = new_form("[BT] AA import " + suf)
    xls = f"/tmp/bt_aa16_{suf}.xlsx"
    rows = [{"cedula": "", "nombre": f"Importada {i:03d}",
             "correo": f"i{i:03d}-{suf}@bt.local", "telefono": "", "data": {}}
            for i in range(1, 401)]
    make_xlsx(xls, rows)
    smtp.reset()
    with vf.VF(R) as v:
        v.goto(f"/forms/{f16}/access", wait=500)
        tok = v.csrf()
        buf = open(xls, "rb").read()
        rp = v.ctx.request.post(
            vf.BASE + f"/forms/{f16}/participants/import",
            multipart={"_token": tok, "modo": "fusionar", "rosterQ": "", "rosterPage": "1",
                       "rosterSort": "cedula",
                       "archivo": {"name": "400.xlsx", "mimeType":
                                   "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
                                   "buffer": buf}},
            headers={"X-Requested-With": "XMLHttpRequest"}, max_redirects=0)
        jp = rp.json()
        rep = (jp.get("html") or {}).get("rosterImportReport", "")
        mt = re.search(r'name="roster_import_token" value="([^"]+)"', rep)
        rc = v.ctx.request.post(
            vf.BASE + f"/forms/{f16}/participants/import/confirm",
            form={"_token": tok, "roster_import_token": mt.group(1) if mt else "",
                  "map_identificador": "2", "map_nombre": "1", "map_correo": "",
                  "map_telefono": "3", "modo": "fusionar", "rosterQ": "", "rosterPage": "1",
                  "rosterSort": "correo"},
            headers={"X-Requested-With": "XMLHttpRequest"}, max_redirects=0)
        jc = rc.json()
    n16 = int(vf.db1(f"SELECT COUNT(*) FROM form_participants WHERE form_id={f16}")[0])
    R.check("AA16", jp.get("ok") is True and jc.get("ok") is True
            and n16 == 400 and smtp.count() == 0 and pending(f16) == 400,
            f"preview={jp.get('ok')} confirm={jc.get('ok')} n={n16} mails={smtp.count()} pending={pending(f16)}")

    R.na("AA21", "No se puede detener MariaDB a mitad de la tanda sin tumbar la instancia; el caso equivalente de fallo de transporte queda cubierto por AA13")
    R.na("AA22", "Lightpanda no implementa foco/Tab ni :focus-visible: la comprobación de teclado no es medible en este entorno")
finally:
    smtp.stop = True
    try:
        smtp.sock.close()
    except Exception:
        pass
    for k in MAIL_KEYS:
        mail_setting(k, None)
    for k, v in saved_mail.items():
        if v != "__absent__":
            mail_setting(k, v)

print(R.report())
