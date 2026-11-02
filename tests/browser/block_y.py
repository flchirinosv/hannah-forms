#!/usr/bin/env python3
"""Bloque Y: comparación de la analítica por un atributo del listado."""

import csv, hashlib, io, json, re, subprocess, sys, uuid
sys.path.insert(0, "tests/browser")
import vf

R = vf.R()
ah = vf.Http("admin@demo.com")
uh = vf.Http("user@demo.com")
suf = uuid.uuid4().hex[:8]
fid = 0
ALFA = "23456789ABCDEFGHJKMNPQRSTWXYZ"

def sq(v):
    return "'" + str(v).replace("\\", "\\\\").replace("'", "''") + "'"

def mysql_stdin(sql):
    p = subprocess.run(["mysql", "-h127.0.0.1", "-umariadb", f"-p{vf.dbpass()}", "vanilla_forms"],
                       input=sql, capture_output=True, text=True)
    if p.returncode != 0:
        raise RuntimeError(p.stderr)

def code_for(ident):
    d = hashlib.sha256(f"{fid}:{ident}".encode()).digest()
    return "".join(ALFA[b % len(ALFA)] for b in d[:16])

def create_form(title, slug):
    vf.mysql(f"DELETE FROM forms WHERE title={sq(title)}")
    settings = json.dumps({"access": {"mode": "roster", "identifier": "cedula"}})
    vf.mysql("INSERT INTO forms (user_id,title,description,slug,status,settings) VALUES "
             f"(1,{sq(title)},'',{sq(slug)},'published',{sq(settings)})")
    fid = int(vf.db1(f"SELECT max(id) FROM forms WHERE title={sq(title)}")[0])
    ids = {}
    defs = [
        ("NUM", "number", "Satisfacción", [], 0),
        ("NPS", "nps", "Recomendación", [], 0),
        ("RAD", "radio", "¿Recomendaría?", [{"value": "Sí"}, {"value": "No"}], 0),
        ("CHK", "checkbox", "Casillas", [{"value": "A"}, {"value": "B"}], 0),
        ("MAT", "matrix", "Matriz", [{"value": "Fila", "group": "rows"}, {"value": "Malo", "group": "cols"}], 0),
        ("RANK", "ranking", "Ranking", [{"value": "Uno"}, {"value": "Dos"}], 0),
        ("PII", "number", "Dato personal", [], 1),
    ]
    for i, (key, tipo, label, opts, pii) in enumerate(defs, start=1):
        vf.mysql("INSERT INTO fields (form_id,type,label,placeholder,help_text,is_required,options,"
                 "validations,position,parent_id,logic,is_pii,show_header) VALUES "
                 f"({fid},{sq(tipo)},{sq(label)},'', '',0,{sq(json.dumps(opts))},'{{}}',{i},NULL,NULL,{pii},1)")
        ids[key] = int(vf.db1(f"SELECT max(id) FROM fields WHERE form_id={fid} AND label={sq(label)}")[0])
    return fid, ids

def add_catalog(key, label, pos):
    vf.mysql("INSERT INTO form_participant_fields (form_id,key_name,label,position) VALUES "
             f"({fid},{sq(key)},{sq(label)},{pos})")

def add_participant(ced, nombre, attrs):
    code = code_for(ced)
    vf.mysql("INSERT INTO form_participants (form_id,cedula,nombre,correo,telefono,"
             "identifier_value,code) VALUES "
             f"({fid},{sq(ced)},{sq(nombre)},{sq(ced.lower()+'@bt.local')},'',{sq(ced)},{sq(code)})")
    pid = int(vf.db1(f"SELECT id FROM form_participants WHERE code={sq(code)}")[0])
    for k, v in attrs.items():
        vf.mysql("INSERT INTO form_participant_attrs (form_id,participant_id,key_name,value) VALUES "
                 f"({fid},{pid},{sq(k)},{sq(v)})")
    return pid

def add_submission(ids, pid, stamp, num, nps, rad, pii, extra=True):
    tok = uuid.uuid4().hex
    vf.mysql("INSERT INTO submissions (form_id,participant_id,ip_address,user_agent,finished_at,public_token) VALUES "
             f"({fid},{pid if pid else 'NULL'},'192.168.1.1','BT Y','{stamp}',{sq(tok)})")
    sid = int(vf.db1(f"SELECT id FROM submissions WHERE public_token={sq(tok)}")[0])
    vals = {"NUM": str(num), "NPS": str(nps), "RAD": rad, "PII": str(pii),
            "CHK": json.dumps(["A"]), "MAT": json.dumps({"Fila": "Malo"}),
            "RANK": json.dumps({"Uno": "1", "Dos": "2"})}
    for k, v in vals.items():
        vf.mysql(f"INSERT INTO answers (submission_id,field_id,value) VALUES ({sid},{ids[k]},{sq(v)})")
    return sid

def group_table(doc):
    m = re.search(r'<table[^>]*app-group-table[^>]*>(.*?)</table>', doc, re.S)
    if not m:
        return [], {}
    body = m.group(1)
    head = re.search(r"<thead>(.*?)</thead>", body, re.S).group(1)
    heads = [re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", h)).strip() for h in re.findall(r"<th[^>]*>(.*?)</th>", head, re.S)]
    rows = {}
    for tr in re.findall(r"<tr>(.*?)</tr>", re.search(r"<tbody>(.*?)</tbody>", body, re.S).group(1), re.S):
        cells = re.findall(r"<t[hd][^>]*>(.*?)</t[hd]>", tr, re.S)
        if not cells:
            continue
        label = re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", cells[0])).strip()
        rows[label] = [re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", c)).strip() for c in cells[1:]]
    return heads, rows

def cell_for(doc, field_label, col_label):
    heads, rows = group_table(doc)
    if field_label not in rows:
        return None
    idx = [i for i, h in enumerate(heads) if h.split(" n = ")[0] == col_label]
    return rows[field_label][idx[0] - 1] if idx else None

try:
    vf.mysql("DELETE FROM forms WHERE title LIKE '[BT] Y %'")
    vf.mysql("DELETE FROM rate_limits WHERE scope='dash:create'")
    vf.mysql("DELETE FROM rate_limits WHERE scope='dash:export'")

    fid, ids = create_form(f"[BT] Y comparar {suf}", f"bt-y-comparar-{suf}")
    add_catalog("grupo", "Grupo", 0)
    add_catalog("ciudad", "Ciudad", 1)
    add_catalog("ocho", "Ocho valores", 2)

    # 200 personas del grupo Grande, todas con 10 de satisfacción y NPS 10.
    grande = []
    for i in range(1, 201):
        pid = add_participant(f"YG-{i:03d}", f"Grande {i:03d}", {"grupo": "Grande"})
        rad = "Sí" if i <= 150 else "No"
        day = "2026-10-20" if i <= 100 else "2026-10-21"
        add_submission(ids, pid, f"{day} 09:{i % 60:02d}:00", 10, 10, rad, 7)
        grande.append(pid)

    # Dos del grupo Pequeño: promedio 150 y NPS 0 (uno detractor, uno promotor).
    p1 = add_participant("YP-01", "Pequeño 01", {"grupo": "Pequeño", "ciudad": "C1"})
    add_submission(ids, p1, "2026-10-20 10:00:00", 100, 0, "Sí", 7)
    p2 = add_participant("YP-02", "Pequeño 02", {"grupo": "Pequeño", "ciudad": "C1"})
    add_submission(ids, p2, "2026-10-21 10:00:00", 200, 10, "No", 7)

    # Grupo sin respuestas: la columna debe salir con «—», no con 0,00.
    add_participant("YS-01", "Sin responder", {"grupo": "SinRespuesta"})

    # Ciudad con 10 valores: solo C1 responde; el resto forma «Resto».
    for i in range(2, 11):
        add_participant(f"YC-{i:02d}", f"Ciudad {i:02d}", {"ciudad": f"C{i}"})

    # Ocho valores justos: sin columna Resto.
    for i in range(1, 9):
        add_participant(f"YO-{i:02d}", f"Ocho {i:02d}", {"ocho": f"O{i}"})

    # Completar los atributos de comparación en TODAS las personas: así «Resto»
    # significa de verdad «el resto de valores» y el caso de ocho valores justos
    # no inventa una columna vacía.
    for key, expr in (("grupo", "'Extra'"), ("ciudad", "CONCAT('C', (p.id % 10) + 1)"),
                      ("ocho", "CONCAT('O', (p.id % 8) + 1)")):
        vf.mysql("INSERT INTO form_participant_attrs (form_id,participant_id,key_name,value) "
                 f"SELECT {fid}, p.id, {sq(key)}, {expr} FROM form_participants p "
                 f"WHERE p.form_id={fid} AND NOT EXISTS (SELECT 1 FROM form_participant_attrs a "
                 "WHERE a.participant_id=p.id AND a.key_name=" + sq(key) + ")")

    base = f"/forms/{fid}/analytics"
    normal = 263816

    s, doc, _ = ah.get(base + "?atr_key=grupo")
    Path = __import__("pathlib").Path
    Path("/tmp/ydoc.html").write_text(doc)
    Path("/tmp/yuser.html").write_text(uh.get(base + "?atr_key=grupo")[1])

    heads, rows = group_table(doc)
    R.check("Y1", s == 200 and "Comparar por Grupo" in doc and len(heads) >= 4
            and doc.find("app-group-table") < doc.find('id="analyticsRows"')
            and any("n = 200" in h for h in heads), f"heads={heads}")

    R.check("Y2", cell_for(doc, "Satisfacción", "Grande") == "10.00 n = 200"
            and cell_for(doc, "Satisfacción", "Pequeño") == "150.00 n = 2",
            str({k: rows.get("Satisfacción") for k in rows})[:300])

    R.check("Y3", cell_for(doc, "Recomendación", "Grande") == "100 n = 200"
            and cell_for(doc, "Recomendación", "Pequeño") == "0 n = 2",
            f"grande={cell_for(doc,'Recomendación','Grande')} pequeno={cell_for(doc,'Recomendación','Pequeño')}")

    rad_g = cell_for(doc, "¿Recomendaría?", "Grande")
    rad_p = cell_for(doc, "¿Recomendaría?", "Pequeño")
    R.check("Y4", "Sí 150 (75 %)" in rad_g and "50 %" in rad_p
            and "No 1 (50 %)" in rad_p and "Sí 1 (50 %)" in rad_p,
            f"grande={rad_g} pequeno={rad_p}")

    # Y5 — con valor filtrado la comparación desaparece.
    _, dfil, _ = ah.get(base + "?atr_key=grupo&atr_value=Grande")
    R.check("Y5", "app-group-table" not in dfil and "Comparar por" not in dfil, "")

    R.check("Y6", cell_for(doc, "Satisfacción", "Grande") == "10.00 n = 200"
            and cell_for(doc, "Satisfacción", "Pequeño") == "150.00 n = 2", "promedios por grupo")

    R.check("Y7", cell_for(doc, "Satisfacción", "SinRespuesta") == "—"
            and any("n = 1" in h for h in heads), f"sin={cell_for(doc,'Satisfacción','SinRespuesta')}")

    # Y8 — más de ocho valores: octava + Resto, y ningún crecimiento extra.
    _, d8, _ = ah.get(base + "?atr_key=ciudad")
    h8, _ = group_table(d8)
    resto_n = int(re.search(r"n = (\d+)", h8[-1]).group(1)) if h8 and h8[-1].startswith("Resto") else 0
    R.check("Y8", len([h for h in h8 if h.startswith("C")]) == 8 and h8[-1].startswith("Resto")
            and resto_n > 0, f"heads={h8}")

    # Y9 — ocho valores justos: no queda nadie para «Resto».
    _, d9, _ = ah.get(base + "?atr_key=ocho")
    h9, _ = group_table(d9)
    R.check("Y9", len(h9) == 9 and not any(h.startswith("Resto") for h in h9), f"heads={h9}")

    # Y10 — comprobación manual (posición/sticky en ventana estrecha).
    R.na("Y10", "Comprobación manual: sticky de la primera columna en ventana estrecha")

    # Y11 — checkbox, matriz y ranking no se comparan.
    Y11 = all(x not in rows for x in ["Casillas", "Matriz", "Ranking"])
    R.check("Y11", Y11 and "Casillas" in doc and "Matriz" in doc and "Ranking" in doc,
            f"filas={list(rows)}")

    # Y12/Y13 — el campo PII se tapa sin permiso y se ve con él.
    _, udoc, _ = uh.get(base + "?atr_key=grupo")
    Path("/tmp/yuser.html").write_text(udoc)
    uh_rows = group_table(udoc)[1]
    R.check("Y12", "Dato personal" not in uh_rows, f"filas_user={list(uh_rows)}")
    R.check("Y13", "Dato personal" in rows and cell_for(doc, "Dato personal", "Grande") == "7.00 n = 200",
            f"admin={cell_for(doc,'Dato personal','Grande')}")

    # Y14 — con rango de fechas los promedios son del rango.
    q14 = "?atr_key=grupo&from=2026-10-21&to=2026-10-21"
    _, d14, _ = ah.get(base + q14)
    R.check("Y14", cell_for(d14, "Satisfacción", "Pequeño") == "200.00 n = 1"
            and cell_for(d14, "Satisfacción", "Grande") == "10.00 n = 100",
            f"peq={cell_for(d14,'Satisfacción','Pequeño')} grande={cell_for(d14,'Satisfacción','Grande')}")

    # Y15 — los dos botones de CSV llevan exactamente el mismo filtro.
    q15 = "?atr_key=grupo&atr_value=Grande&from=2026-10-20&to=2026-10-21"
    _, d15, _ = ah.get(base + q15)
    hrefs = re.findall(r'href="([^"]*export\.csv[^"]*)"', d15)
    R.check("Y15", len(hrefs) == 2 and hrefs[0] == hrefs[1]
            and "atr_key=grupo" in hrefs[0] and "atr_value=Grande" in hrefs[0]
            and "from=2026-10-20" in hrefs[0] and "to=2026-10-21" in hrefs[0], str(hrefs))

    # Y16 — un formulario sin lista no tiene comparación ni desplegable de atributo.
    _, d16, _ = ah.get(f"/forms/{normal}/analytics")
    R.check("Y16", "app-group-table" not in d16 and 'name="atr_key"' not in d16 and "Comparar por" not in d16, "")

    # Y17 — comprobación manual (800 filas y mayoría sin el atributo).
    R.na("Y17", "Comprobación manual: lista de 800 filas y «Resto» mayoritario")

    print("BLOQUE Y:", R.report())
finally:
    if fid:
        vf.mysql(f"DELETE FROM forms WHERE id={fid}")
