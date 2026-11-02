#!/usr/bin/env python3
"""Bloque AE: recordatorio por correo de la lista de participantes."""

import hashlib, json, re, socket, sys, threading, uuid
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
        raise RuntimeError("no se creó " + title + " :: " + str(r[0]))
    fid = int(m.group(1))
    settings = {
        "access": {"mode": "roster" if roster else "public", "identifier": identifier},
        "anti_bot": {"enabled": False},
    }
    vf.mysql(f"UPDATE forms SET settings='{esc(json.dumps(settings))}', status='published' WHERE id={fid}")
    vf.mysql(f"INSERT INTO fields (form_id,type,label,position,is_required) VALUES ({fid},'text','Comentario',1,0)")
    return fid


def settings_of(fid):
    row = vf.db1(f"SELECT settings FROM forms WHERE id={fid}")
    return json.loads(row[0]) if row and row[0] else {}


def set_identifier(fid, ident):
    s = settings_of(fid)
    s.setdefault("access", {})["mode"] = "roster"
    s["access"]["identifier"] = ident
    vf.mysql(f"UPDATE forms SET settings='{esc(json.dumps(s))}' WHERE id={fid}")


def set_close(fid, close_at):
    s = settings_of(fid)
    s.setdefault("schedule", {})["close_at"] = close_at
    vf.mysql(f"UPDATE forms SET settings='{esc(json.dumps(s))}' WHERE id={fid}")


def add(fid, ident, nombre="", correo=None, invited=False):
    correo = ident if correo is None else correo
    code = code_for(fid, ident)
    vf.mysql(
        "INSERT INTO form_participants "
        "(form_id,cedula,nombre,correo,telefono,identifier_value,code) VALUES "
        f"({fid},'{esc(ident)}','{esc(nombre)}','{esc(correo)}','','{esc(ident)}','{code}')"
    )
    pid = int(vf.db1(f"SELECT id FROM form_participants WHERE code='{code}'")[0])
    if invited:
        vf.mysql(f"UPDATE form_participants SET invited_at=NOW() WHERE id={pid}")
    return pid


def access_html(fid):
    return ah.get(f"/forms/{fid}/access")[1]


def remind(fid, json_headers=True, follow=True):
    html = access_html(fid)
    tok = ah.csrf(html)
    headers = {"X-Requested-With": "XMLHttpRequest"} if json_headers else {}
    s, body, url = ah.req(
        "POST", f"/forms/{fid}/participants/reminders",
        {"_token": tok, "rosterQ": "", "rosterPage": "1", "rosterSort": "cedula"},
        headers=headers, follow=follow,
    )
    try:
        j = json.loads(body)
    except Exception:
        j = None
    return s, body, j


def visible_text(html):
    return re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", html))


def pending_reminders(fid):
    return int(vf.db1(
        "SELECT COUNT(*) FROM form_participants WHERE form_id=" + str(fid) +
        " AND correo <> '' AND invited_at IS NOT NULL AND responded_at IS NULL")[0])


def marked(fid):
    return int(vf.db1(
        "SELECT COUNT(*) FROM form_participants WHERE form_id=" + str(fid) +
        " AND reminded_at IS NOT NULL")[0])


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


saved_mail = {}
for k in MAIL_KEYS:
    row = vf.db1(f"SELECT value FROM app_settings WHERE key_name='{k}'")
    saved_mail[k] = "__absent__" if not row else ("NULL" if row[0] == "NULL" else row[0])

smtp = FakeSMTP()
try:
    vf.mysql("DELETE FROM forms WHERE title LIKE '[BT] AE %'")
    for k in MAIL_KEYS:
        mail_setting(k, None)
    mail_setting("mail_host", "127.0.0.1")
    mail_setting("mail_port", str(smtp.port))
    mail_setting("mail_encryption", "")
    mail_setting("mail_from_address", "noreply@bt.local")
    mail_setting("mail_from_name", "BT")
    mail_setting("mail_timeout", "10")
    mail_setting("mail_user", "")

    # AE1 — lista sin invitar: no hay recordatorio.
    f1 = new_form("[BT] AE vacío " + suf)
    txt1 = visible_text(access_html(f1))
    R.check("AE1", "No queda nadie pendiente de invitación" in txt1
            and "Enviar recordatorios" not in txt1
            and "participants/reminders" not in access_html(f1), txt1[-450:])

    # AE2 / AE4 — 30 invitados sin responder + 5 sin invitar: dos números distintos.
    f2 = new_form("[BT] AE treinta " + suf)
    for i in range(1, 31):
        add(f2, f"r{i:02d}@bt.local", f"Recordado {i:02d}", invited=True)
    for i in range(1, 6):
        add(f2, f"n{i:02d}@bt.local", f"Nuevo {i:02d}", invited=False)
    html2 = access_html(f2)
    txt2 = visible_text(html2)
    p_inv = txt2.find("Enviar invitaciones (5)")
    p_rem = txt2.find("Enviar recordatorios (30)")
    R.check("AE2", p_rem > p_inv >= 0 and "Enviar recordatorios" in txt2
            and "(30)" in txt2 and "(35)" not in txt2.split("Enviar recordatorios")[1][:60],
            f"invitaciones@={p_inv} recordatorios@={p_rem} pendientes={pending_reminders(f2)}")
    R.check("AE4", "Enviar invitaciones (5)" in txt2 and "Enviar recordatorios (30)" in txt2
            and pending_reminders(f2) == 30,
            f"porInvitar=5 porRecordar=30 total={vf.db1('SELECT COUNT(*) FROM form_participants WHERE form_id=' + str(f2))[0]}")

    # AE3 / AE17 — envío real, el contador no baja y solo se repone el hueco de invitaciones.
    smtp.reset()
    st3, body3, j3 = remind(f2)
    html3 = ((j3 or {}).get("html") or {}).get("rosterInvites", "")
    R.check("AE3", st3 == 200 and j3 and j3.get("ok") is True and smtp.count() == 30
            and marked(f2) == 30 and pending_reminders(f2) == 30
            and "Se mandaron 30 recordatorios." in (j3.get("message") or "")
            and "Enviar recordatorios" in html3 and "(30)" in html3,
            f"status={st3} mails={smtp.count()} marcados={marked(f2)} pendientes={pending_reminders(f2)} msg={j3 and j3.get('message')}")
    R.check("AE17", (j3 or {}).get("html") is not None
            and set(((j3 or {}).get("html") or {}).keys()) == {"rosterInvites"},
            f"html={sorted(((j3 or {}).get('html') or {}).keys())}")

    # AE10 — repetible: otra tanda vuelve a escribir a los mismos.
    smtp.reset()
    st10, body10, j10 = remind(f2)
    twice = int(vf.db1(f"SELECT COUNT(*) FROM form_participants WHERE form_id={f2} AND reminder_count=2")[0])
    R.check("AE10", st10 == 200 and j10 and j10.get("ok") is True and smtp.count() == 30
            and twice == 30 and marked(f2) == 30,
            f"status={st10} mails={smtp.count()} con_dos={twice} msg={j10 and j10.get('message')}")

    # AE5 — el correo cita la fecha de cierre; sin fecha no queda una línea a medias.
    f5 = new_form("[BT] AE fecha " + suf)
    pid5 = add(f5, "fecha@bt.local", "Con Fecha", invited=True)
    set_close(f5, "2030-12-31T18:30")
    smtp.reset()
    st5, body5, j5 = remind(f5)
    data5 = smtp.messages[-1]["data"] if smtp.messages else ""
    url5 = re.search(r"https?://[^\"<\s]+/f/[^\"<\s]+\?t=([A-Z0-9]{16})", data5)
    with_date = "31/12/2030 18:30" in data5 and "cierra el" in data5 and url5 is not None
    set_close(f5, "")
    smtp.reset()
    st5b, body5b, j5b = remind(f5)
    data5b = smtp.messages[-1]["data"] if smtp.messages else ""
    R.check("AE5", st5 == 200 and j5 and j5.get("ok") is True and with_date
            and st5b == 200 and j5b and j5b.get("ok") is True
            and "31/12/2030" not in data5b and "cierra el" not in data5b,
            f"con_fecha={with_date} url={bool(url5)} sin_fecha_limpia={'31/12/2030' not in data5b and 'cierra el' not in data5b}")

    # AE6 — con cédula no hay botón: el enlace va en la columna Código.
    f6 = new_form("[BT] AE cedula " + suf, identifier="cedula")
    add(f6, "V-1", "Cédula Uno", correo="cedula1@bt.local", invited=True)
    html6 = access_html(f6)
    txt6 = visible_text(html6)
    R.check("AE6", "No hay invitaciones por correo" in txt6
            and "columna «Código»" in txt6 and "participants/reminders" not in html6,
            txt6[-450:])

    # AE7 — sin MAIL_HOST se explica que el correo está apagado y no se toca nada.
    mail_setting("mail_host", None)
    before7 = pending_reminders(f2)
    html7 = access_html(f2)
    txt7 = visible_text(html7)
    smtp.reset()
    st7, body7, j7 = remind(f2)
    R.check("AE7", "No hay servidor de correo configurado" in txt7
            and "MAIL_HOST" in txt7 and "participants/reminders" not in html7
            and st7 == 200 and j7 and j7.get("ok") is False and smtp.count() == 0
            and pending_reminders(f2) == before7,
            f"status={st7} msg={j7 and j7.get('message')} mails={smtp.count()} pendientes={pending_reminders(f2)}")
    mail_setting("mail_host", "127.0.0.1")

    # AE8 — el formulario cambia a cédula justo antes: no se manda nada.
    f8 = new_form("[BT] AE cambia " + suf)
    add(f8, "cambio@bt.local", "Cambia", invited=True)
    smtp.reset()
    before8 = marked(f8)
    set_identifier(f8, "cedula")
    st8, body8, j8 = remind(f8)
    R.check("AE8", st8 in (200, 403) and j8 and j8.get("ok") is False
            and smtp.count() == 0 and marked(f8) == before8,
            f"status={st8} (doc dice 403; el handler responde ok:false) msg={j8 and j8.get('message')} mails={smtp.count()}")

    # AE9 — un formulario que no es de lista responde 404.
    f9 = new_form("[BT] AE publico " + suf, roster=False)
    tok9 = ah.csrf(ah.get("/dashboard")[1])
    smtp.reset()
    st9, body9, _ = ah.req("POST", f"/forms/{f9}/participants/reminders",
                           {"_token": tok9}, headers={"X-Requested-With": "XMLHttpRequest"})
    R.check("AE9", st9 == 404 and smtp.count() == 0,
            f"status={st9} mails={smtp.count()}")

    # AE11 / AE12 — SMTP cae a mitad: 4 salen, 3 fallan y se para.
    f11 = new_form("[BT] AE falla " + suf)
    for i in range(1, 21):
        add(f11, f"e{i:02d}@bt.local", f"Falla {i:02d}", invited=True)
    smtp.reset()
    smtp.fail_after = 4
    st11, body11, j11 = remind(f11)
    smtp.fail_after = None
    msg11 = (j11 or {}).get("message") or ""
    R.check("AE11", st11 == 200 and j11 and j11.get("ok") is False
            and smtp.count() == 4 and marked(f11) == 4 and pending_reminders(f11) == 20
            and "4" in msg11 and "3" in msg11 and ("fallaron" in msg11 or "fallos" in msg11),
            f"status={st11} mails={smtp.count()} marcados={marked(f11)} pendientes={pending_reminders(f11)} msg={msg11}")
    R.check("AE12", marked(f11) == 4 and int(vf.db1(
                f"SELECT COUNT(*) FROM form_participants WHERE form_id={f11} AND reminded_at IS NULL")[0]) == 16,
            f"marcados={marked(f11)} sin_marcar={vf.db1('SELECT COUNT(*) FROM form_participants WHERE form_id=' + str(f11) + ' AND reminded_at IS NULL')[0]}")

    # AE13 — marcar respondido saca a esa persona de la cola.
    p13 = int(vf.db1(f"SELECT id FROM form_participants WHERE form_id={f11} ORDER BY id LIMIT 1")[0])
    vf.mysql(f"UPDATE form_participants SET responded_at=NOW() WHERE id={p13}")
    html13 = access_html(f11)
    R.check("AE13", pending_reminders(f11) == 19 and "(19)" in visible_text(html13)
            and f"Enviar recordatorios (19)" in visible_text(html13),
            f"pendientes={pending_reminders(f11)} texto={visible_text(html13)[-260:]}")

    # AE14 — 60 pendientes: salen 50 y el aviso dice cuántos quedan, no "50 enviados".
    f14 = new_form("[BT] AE tope " + suf)
    for i in range(1, 61):
        add(f14, f"c{i:02d}@bt.local", f"Tope {i:02d}", invited=True)
    smtp.reset()
    st14, body14, j14 = remind(f14)
    msg14 = (j14 or {}).get("message") or ""
    R.check("AE14", st14 == 200 and j14 and j14.get("ok") is True
            and smtp.count() == 50 and marked(f14) == 50 and pending_reminders(f14) == 60
            and "Quedan 10" in msg14 and "50 enviados" not in msg14,
            f"status={st14} mails={smtp.count()} marcados={marked(f14)} pendientes={pending_reminders(f14)} msg={msg14}")

    # AE15 — segunda tanda: primero los que no recibieron nada, sin repetir.
    f15 = new_form("[BT] AE tandas " + suf)
    for i in range(1, 61):
        add(f15, f"t{i:02d}@bt.local", f"Tanda {i:02d}", invited=True)
    smtp.reset()
    st15a, body15a, j15a = remind(f15)
    first = {r for m in smtp.messages for r in m["rcpts"]}
    smtp.reset()
    st15b, body15b, j15b = remind(f15)
    second_msgs = [r for m in smtp.messages for r in m["rcpts"]]
    second = set(second_msgs)
    sin_nada = {r for m in smtp.messages for r in m["rcpts"]} | first
    sin_nada = {f"t{i:02d}@bt.local" for i in range(1, 61)} - first
    empieza_por_los_nuevos = set(second_msgs[:10]) == sin_nada
    R.check("AE15", st15a == 200 and st15b == 200 and len(first) == 50
            and len(second) == 50 and empieza_por_los_nuevos
            and len(first & second) == 40 and len(first | second) == 60
            and int(vf.db1(f"SELECT MAX(reminder_count) FROM form_participants WHERE form_id={f15}")[0]) == 2
            and int(vf.db1(f"SELECT COUNT(*) FROM form_participants WHERE form_id={f15} AND reminded_at IS NOT NULL")[0]) == 60,
            f"primera={len(first)} segunda={len(second)} nuevos_primero={empieza_por_los_nuevos} "
            f"solapan={len(first & second)} max={vf.db1('SELECT MAX(reminder_count) FROM form_participants WHERE form_id=' + str(f15))[0]}")

    # AE16 — el confirmador dice cuántos, el tope, a quién y qué botón se acepta.
    html16 = access_html(f2)
    R.check("AE16", f'action="/forms/{f2}/participants/reminders"' in html16
            and "data-confirm=" in html16 and "30" in html16 and "50" in html16
            and "Solo se avisa a quienes ya recibieron la invitación" in html16
            and 'data-confirm-ok="Sí, recordar"' in html16
            and 'data-confirm-tone="primary"' in html16,
            "confirmador de recordatorio")

    # AE18 — sin JavaScript: POST nativo, 302 y mismo resultado.
    f18 = new_form("[BT] AE nativo " + suf)
    for i in range(1, 4):
        add(f18, f"nat{i:02d}@bt.local", f"Nativo {i:02d}", invited=True)
    smtp.reset()
    st18, body18, _ = remind(f18, json_headers=False, follow=False)
    html18 = access_html(f18)
    R.check("AE18", st18 == 302 and smtp.count() == 3 and marked(f18) == 3
            and "Enviar recordatorios (3)" in visible_text(html18),
            f"status={st18} mails={smtp.count()} marcados={marked(f18)}")

    # AE20 — borrar a alguien de la lista lo saca de la cola.
    f20 = new_form("[BT] AE borrado " + suf)
    p201 = add(f20, "borra1@bt.local", "Borra Uno", invited=True)
    add(f20, "borra2@bt.local", "Borra Dos", invited=True)
    before20 = pending_reminders(f20)
    tok20 = ah.csrf(access_html(f20))
    st20, body20, _ = ah.req("POST", f"/forms/{f20}/participants/delete",
                             {"_token": tok20, "participant_id": p201},
                             headers={"X-Requested-With": "XMLHttpRequest"})
    R.check("AE20", st20 == 200 and before20 == 2 and pending_reminders(f20) == 1
            and "Enviar recordatorios (1)" in visible_text(access_html(f20)),
            f"status={st20} antes={before20} despues={pending_reminders(f20)}")

    R.na("AE19", "Lightpanda no emula Tab ni :focus-visible; el diálogo y el foco en Cancelar no son medibles en este entorno")
finally:
    smtp.stop = True
    try:
        smtp.sock.close()
    except Exception:
        pass
    vf.mysql("DELETE FROM forms WHERE title LIKE '[BT] AE %'")
    for k in MAIL_KEYS:
        mail_setting(k, None)
    for k, v in saved_mail.items():
        if v != "__absent__":
            mail_setting(k, v)

print(R.report())
