#!/usr/bin/env python3
"""Bloque Z: quién respondió (fase 9)."""

import hashlib, json, re, subprocess, sys, uuid
sys.path.insert(0, "tests/browser")
import vf

R = vf.R()
ah = vf.Http("admin@demo.com")
uh = vf.Http("user@demo.com")
suf = uuid.uuid4().hex[:8]
fid = 0
fid9 = 0
ALFA = "23456789ABCDEFGHJKMNPQRSTWXYZ"

def sq(v):
    return "'" + str(v).replace("\\", "\\\\").replace("'", "''") + "'"

def mysql_stdin(sql):
    p = subprocess.run(["mysql", "-h127.0.0.1", "-umariadb", f"-p{vf.dbpass()}", "vanilla_forms"],
                       input=sql, capture_output=True, text=True)
    if p.returncode != 0:
        raise RuntimeError(p.stderr)

def code_for(form_id, ident):
    d = hashlib.sha256(f"{form_id}:{ident}".encode()).digest()
    return "".join(ALFA[b % len(ALFA)] for b in d[:16])

def create_form(title, slug, owner=2, identifier="cedula"):
    vf.mysql(f"DELETE FROM forms WHERE title={sq(title)}")
    settings = json.dumps({"access": {"mode": "roster", "identifier": identifier}})
    vf.mysql("INSERT INTO forms (user_id,title,description,slug,status,settings) VALUES "
             f"({owner},{sq(title)},'',{sq(slug)},'published',{sq(settings)})")
    f = int(vf.db1(f"SELECT max(id) FROM forms WHERE title={sq(title)}")[0])
    defs = [("NOMBRE", "text", "Nombre", 0), ("PII", "text", "Correo personal", 1)]
    ids = {}
    for i, (key, tipo, label, pii) in enumerate(defs, start=1):
        vf.mysql("INSERT INTO fields (form_id,type,label,placeholder,help_text,is_required,options,"
                 "validations,position,parent_id,logic,is_pii,show_header) VALUES "
                 f"({f},{sq(tipo)},{sq(label)},'', '',0,'[]','{{}}',{i},NULL,NULL,{pii},1)")
        ids[key] = int(vf.db1(f"SELECT max(id) FROM fields WHERE form_id={f} AND label={sq(label)}")[0])
    return f, ids

def add_participant(form_id, ced, nombre, correo):
    code = code_for(form_id, ced)
    vf.mysql("INSERT INTO form_participants (form_id,cedula,nombre,correo,telefono,identifier_value,code) VALUES "
             f"({form_id},{sq(ced)},{sq(nombre)},{sq(correo)},'',{sq(correo) if form_id == fid9 else sq(ced)},{sq(code)})")
    return int(vf.db1(f"SELECT id FROM form_participants WHERE code={sq(code)}")[0])

def add_submission(form_id, ids, pid, stamp, valor, pii=""):
    tok = uuid.uuid4().hex
    vf.mysql("INSERT INTO submissions (form_id,participant_id,ip_address,user_agent,finished_at,public_token) VALUES "
             f"({form_id},{pid if pid else 'NULL'},'10.0.0.1','BT Z','{stamp}',{sq(tok)})")
    sid = int(vf.db1(f"SELECT id FROM submissions WHERE public_token={sq(tok)}")[0])
    vf.mysql(f"INSERT INTO answers (submission_id,field_id,value) VALUES ({sid},{ids['NOMBRE']},{sq(valor)})")
    if pii:
        vf.mysql(f"INSERT INTO answers (submission_id,field_id,value) VALUES ({sid},{ids['PII']},{sq(pii)})")
    return sid

def rows(doc):
    return re.findall(r"<tr data-vf-row>(.*?)</tr>", doc, re.S)

def strip_html(s):
    return re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", s)).strip()

try:
    vf.mysql("DELETE FROM forms WHERE title LIKE '[BT] Z %'")
    vf.mysql("DELETE FROM rate_limits WHERE scope='dash:export'")

    fid, ids = create_form(f"[BT] Z atribucion {suf}", f"bt-z-atribucion-{suf}", owner=2)
    pid = add_participant(fid, "Z-001", "Ana Pérez", "ana.perez@bt.local")
    sid_atribuida = add_submission(fid, ids, pid, "2026-10-22 12:00:00", "Respuesta con dueña", "ana@personal.test")
    for i in range(1, 30):
        add_submission(fid, ids, None, f"2026-10-21 10:{i:02d}:00", f"Anónima {i}")

    fid9, ids9 = create_form(f"[BT] Z correo {suf}", f"bt-z-correo-{suf}", owner=2, identifier="correo")
    p9 = add_participant(fid9, "Z9-001", "Correo Único", "z9@bt.local")
    sid9 = add_submission(fid9, ids9, p9, "2026-10-22 12:00:00", "Otra respuesta")

    normal = 263816
    base = f"/forms/{fid}/responses"

    s1, d1, _ = ah.get(base)
    r1 = rows(d1)
    R.check("Z1", s1 == 200 and "<th class=\"small text-muted\">Participante</th>" in d1 and len(r1) == 15
            and "Z-001" in r1[0] and "Ana Pérez" in r1[0] and "ana.perez@bt.local" in r1[0],
            f"filas={len(r1)} primera={strip_html(r1[0])[:160] if r1 else ''}")

    s2, d2, _ = uh.get(base)
    r2 = rows(d2)
    R.check("Z2", s2 == 200 and "Participante</th>" not in d2 and "Z-001" not in d2
            and "Ana Pérez" not in d2 and len(r2) == 15,
            f"status={s2} filas={len(r2)} col={'Participante</th>' in d2}")

    R.check("Z3", "no incluye el permiso" in d2 and "responses.pii" in d2
            and "quién" in d2 and "ficha del" in d2, "")

    _, d4, _ = ah.get(f"/forms/{normal}/responses")
    R.check("Z4", "Participante</th>" not in d4 and len(rows(d4)) > 0, "")

    R.check("Z5", any("—" in strip_html(r) for r in r1) and "Z-001" in r1[0],
            f"primera={strip_html(r1[0])[:100]}")

    _, d6, _ = ah.get(base + "?page=2")
    r6 = rows(d6)
    R.check("Z6", len(r6) == 15 and "Participante</th>" in d6 and all("—" in strip_html(r) for r in r6)
            and all("Z-001" not in r for r in r6), f"filas={len(r6)}")

    s7, d7, _ = ah.get(f"/forms/{fid}/responses/{sid_atribuida}")
    R.check("Z7", s7 == 200 and "Participante" in d7 and "Z-001" in d7
            and "Ana Pérez" in d7 and "ana.perez@bt.local" in d7, "")

    sid_anon = int(vf.db1(f"SELECT id FROM submissions WHERE form_id={fid} AND participant_id IS NULL ORDER BY id LIMIT 1")[0])
    s8, d8, _ = ah.get(f"/forms/{fid}/responses/{sid_anon}")
    box = re.search(r'<div class="text-uppercase mb-1">Participante</div>', d8)
    R.check("Z8", s8 == 200 and box is None, "")

    # Z9 — con identificador correo, la dirección no se repite debajo.
    _, d9, _ = ah.get(f"/forms/{fid9}/responses")
    r9 = rows(d9)
    celda9 = strip_html(r9[0]) if r9 else ""
    R.check("Z9", celda9.count("z9@bt.local") == 1 and "Correo Único" in celda9, celda9[:200])

    _, d10, _ = ah.get(base + "?page=3")
    R.check("Z10", "Participante</th>" in d10 and all("—" in strip_html(r) for r in rows(d10)), "")

    # Z11 — los archivos no llevan nombre, cédula ni identificador.
    vf.mysql("DELETE FROM rate_limits WHERE scope='dash:export'")
    sc, csv, _ = ah.get(f"/forms/{fid}/export.csv")
    vf.mysql("DELETE FROM rate_limits WHERE scope='dash:export'")
    sj, js, _ = ah.get(f"/forms/{fid}/export.json")
    R.check("Z11", sc == 200 and sj == 200 and all(x not in csv for x in ["Z-001", "Ana Pérez", "ana.perez@bt.local"])
            and all(x not in js for x in ["Z-001", "Ana Pérez", "ana.perez@bt.local"]),
            f"csv={sc} json={sj}")

    # Z12 — sin permiso: el aviso de PII sigue y no se dice quién respondió.
    _, du, _ = uh.get(f"/forms/{fid}/responses/{sid_atribuida}")
    R.check("Z12", "enmascarados" in du and "responses.pii" in du
            and "Z-001" not in du and "Ana Pérez" not in du, "")

    print("BLOQUE Z:", R.report())
finally:
    for f in (fid, fid9):
        if f:
            vf.mysql(f"DELETE FROM forms WHERE id={f}")
