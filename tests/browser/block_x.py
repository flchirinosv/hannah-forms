#!/usr/bin/env python3
"""Bloque X: filtro de la analítica por un atributo del listado."""

import csv, hashlib, io, json, re, subprocess, sys, uuid
sys.path.insert(0, "tests/browser")
import vf

R = vf.R()
ah = vf.Http("admin@demo.com")
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
    for i, (key, tipo, label, opts) in enumerate([
        ("RAD", "radio", "Turno", [{"value": "Mañana"}, {"value": "Tarde"}]),
        ("NUM", "number", "Número", []),
        ("OPEN", "text", "Comentario", []),
    ], start=1):
        vf.mysql("INSERT INTO fields (form_id,type,label,placeholder,help_text,is_required,options,"
                 "validations,position,parent_id,logic,is_pii,show_header) VALUES "
                 f"({fid},{sq(tipo)},{sq(label)},'', '',0,{sq(json.dumps(opts))},'{{}}',{i},NULL,NULL,0,1)")
        ids[key] = int(vf.db1(f"SELECT max(id) FROM fields WHERE form_id={fid} AND label={sq(label)}")[0])
    return fid, ids

def add_catalog(fid, key, label, pos):
    vf.mysql("INSERT INTO form_participant_fields (form_id,key_name,label,position) VALUES "
             f"({fid},{sq(key)},{sq(label)},{pos})")

def add_participant(fid, ced, nombre, correo, attrs):
    code = code_for(ced)
    vf.mysql("INSERT INTO form_participants (form_id,cedula,nombre,correo,telefono,"
             "identifier_value,code) VALUES "
             f"({fid},{sq(ced)},{sq(nombre)},{sq(correo)},'',{sq(ced)},{sq(code)})")
    pid = int(vf.db1(f"SELECT id FROM form_participants WHERE code={sq(code)}")[0])
    for k, v in attrs.items():
        vf.mysql("INSERT INTO form_participant_attrs (form_id,participant_id,key_name,value) VALUES "
                 f"({fid},{pid},{sq(k)},{sq(v)})")
    return pid

def add_submission(fid, ids, pid, stamp, idx):
    tok = uuid.uuid4().hex
    vf.mysql("INSERT INTO submissions (form_id,participant_id,ip_address,user_agent,finished_at,"
             "public_token) VALUES "
             f"({fid},{pid if pid else 'NULL'},'192.168.1.1','BT X','{stamp}',{sq(tok)})")
    sid = int(vf.db1(f"SELECT id FROM submissions WHERE public_token={sq(tok)}")[0])
    vals = {
        "RAD": "Mañana" if idx % 2 == 0 else "Tarde",
        "NUM": str(idx),
        "OPEN": f"Comentario {idx}",
    }
    for k, v in vals.items():
        vf.mysql(f"INSERT INTO answers (submission_id,field_id,value) VALUES ({sid},{ids[k]},{sq(v)})")
    return sid

def cards(doc):
    return {label: value for value, label in re.findall(
        r'<div class="app-stat-value">([^<]*)</div>\s*<div class="app-stat-label text-muted small">([^<]*)</div>',
        doc)}

def total_of(doc):
    return (cards(doc).get("Respuestas") or "").strip()

def table_rows(doc):
    m = re.search(r'<tbody id="analyticsRows"[^>]*>(.*?)</tbody>', doc, re.S)
    if not m:
        return []
    return re.findall(r"<tr[^>]*>(.*?)</tr>", m.group(1), re.S)

def strip_html(s):
    return re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", s)).strip()

try:
    vf.mysql("DELETE FROM forms WHERE title LIKE '[BT] X %'")
    vf.mysql("DELETE FROM rate_limits WHERE scope='dash:export'")
    vf.mysql("DELETE FROM rate_limits WHERE scope='dash:create'")

    fid, ids = create_form(f"[BT] X filtro {suf}", f"bt-x-filtro-{suf}")
    add_catalog(fid, "sede", "Sede", 0)
    add_catalog(fid, "region", "Región", 1)
    add_catalog(fid, "zona", "Zona", 2)

    pids = {}
    idx = 1
    for i in range(1, 21):
        attrs = {"sede": "Norte"}
        if i != 20:
            attrs["region"] = "Centro" if i % 2 else "Oriente"
        pids[f"n{i}"] = add_participant(fid, f"XN-{i:02d}", f"Norte {i:02d}", f"xn{i}@bt.local", attrs)
        day = "2026-10-20" if i <= 10 else "2026-10-21"
        add_submission(fid, ids, pids[f"n{i}"], f"{day} 10:{i:02d}:00", idx); idx += 1
    for i in range(1, 3):
        pid = add_participant(fid, f"XS-{i:02d}", f"Sur {i:02d}", f"xs{i}@bt.local", {"sede": "Sur"})
        add_submission(fid, ids, pid, f"2026-10-20 11:{i:02d}:00", idx); idx += 1
    add_submission(fid, ids, None, "2026-10-20 12:00:00", idx)

    # X13 — un atributo libre con más valores que el tope del desplegable.
    for i in range(205):
        attrs = {"zona": f"Zona {i:03d}"}
        if i < 30:
            attrs["sede"] = "Norte"
        add_participant(fid, f"XZ-{i:03d}", f"Zona {i:03d}", f"xz{i}@bt.local", attrs)

    base = f"/forms/{fid}/analytics"
    fnormal = 263816  # fixture normal sin listado

    with vf.VF(R) as v:
        # X1 — formulario de listado: atributo de «Todo el listado», sin valor aún.
        v.goto(base, wait=700)
        x1 = v.page.evaluate("""()=>({key:!!document.querySelector('select[name=atr_key]'),
            val:!!document.querySelector('select[name=atr_value]'),
            first:document.querySelector('select[name=atr_key] option')?.textContent.trim(),
            dates:document.querySelectorAll('input[type=date]').length})""")
        R.check("X1", x1["key"] and not x1["val"] and x1["first"] == "Todo el listado" and x1["dates"] == 2, str(x1))

        # X2 — formulario normal sin catálogo.
        v.goto(f"/forms/{fnormal}/analytics", wait=600)
        R.check("X2", v.page.locator("select[name=atr_key]").count() == 0, "")

        # X3 — elegir atributo y Filtrar sin valor conserva el atributo.
        v.goto(base, wait=700)
        v.page.select_option("select[name=atr_key]", "sede")
        v.page.locator(".analytics-filters").evaluate("f=>f.requestSubmit()")
        v.page.wait_for_timeout(800)
        x3 = v.page.evaluate("""()=>({key:document.querySelector('select[name=atr_key]').value,
            val:document.querySelector('select[name=atr_value]'),
            all:document.querySelector('select[name=atr_value] option')?.textContent.trim(),
            total:document.querySelector('.app-stat-value')?.textContent.trim()})""")
        R.check("X3", x3["key"] == "sede" and x3["val"] is not None
                and x3["all"] == "Todos los valores" and x3["total"] == "23", str(x3))

        # X4 — elegir Norte: indicador, barras, tabla y columna, todo del grupo.
        v.page.select_option("select[name=atr_value]", "Norte")
        v.page.locator(".analytics-filters").evaluate("f=>f.requestSubmit()")
        v.page.wait_for_timeout(800)
        doc4 = v.page.content()
        open("/tmp/xdoc4.html","w").write(doc4)
        rows4 = table_rows(doc4)
        chart = json.loads(v.page.locator("#analyticsApp").get_attribute("data-chart"))
        rad_raw = next((f["values"] for f in chart["fields"] if f["id"] == ids["RAD"]), [])
        rad = {x["value"]: x["c"] for x in rad_raw}
        thead = re.search(r"<thead>(.*?)</thead>", doc4, re.S)
        ths = strip_html(thead.group(1)) if thead else ""
        R.check("X4", total_of(doc4) == "20" and len(rows4) == 15
                and all("Norte" in strip_html(r) for r in rows4)
                and "Sede" in ths and rad.get("Mañana") == 10 and rad.get("Tarde") == 10,
                f"total={total_of(doc4)} filas={len(rows4)} rad={rad} th={ths[:120]}")

        # X5 — columnas del catálogo, nunca cédula/nombre/correo.
        R.check("X5", "Sede" in ths and "Región" in ths and "Zona" in ths
                and not any(x in ths for x in ["Cédula", "Nombre completo", "Correo"]), ths[:200])

        # X6 — quien no tiene el otro atributo pinta «—», no un hueco.
        p2 = [r for r in rows4 if re.search(r'data-label="Número"[^>]*>\s*20\s*<', r)]
        R.check("X6", any(re.search(r'data-label="Región"\s*>\s*<span[^>]*>—</span>', r) for r in p2),
                str([strip_html(r) for r in p2])[:300])

        # X7 — página 2 con el filtro puesto: siguen siendo del grupo y hay columna.
        pager = v.page.locator('.analytics-pager [data-paginate-page="2"]')
        if pager.count():
            pager.first.evaluate("e=>e.click()")
            v.page.wait_for_timeout(900)
        rows7 = table_rows(v.page.content())
        R.check("X7", bool(rows7) and all("Norte" in strip_html(r) for r in rows7)
                and "Sede" in ths, f"filas={len(rows7)}")

    # X8 — sin filtro sale también quien no está en la lista.
    _, d8all, _ = ah.get(base)
    open("/tmp/xd8all.html","w").write(d8all)
    _, d8fil, _ = ah.get(base + "?atr_key=sede&atr_value=Norte")
    R.check("X8", total_of(d8all) == "23" and total_of(d8fil) == "20", f"todo={total_of(d8all)} filtro={total_of(d8fil)}")

    # X9 — intentar filtrar por la identidad no filtra nada.
    _, d9, _ = ah.get(base + "?atr_key=cedula&atr_value=XN-01")
    sel9 = re.search(r'<select class="form-select" name="atr_key".*?</select>', d9, re.S)
    R.check("X9", total_of(d9) == "23" and "atr_value" not in d9
            and bool(sel9) and 'value="cedula"' not in sel9.group(0), f"total={total_of(d9)}")

    # X10 — valor inexistente: pantalla vacía y el valor sigue marcado.
    _, d10, _ = ah.get(base + "?atr_key=sede&atr_value=Oeste")
    R.check("X10", total_of(d10) == "0" and "Sin respuestas para este filtro." in d10
            and re.search(r'<option value="Oeste" selected>Oeste</option>', d10) is not None,
            f"total={total_of(d10)}")

    # X11 — clave inventada: pantalla completa y desplegable en «Todo el listado».
    _, d11, _ = ah.get(base + "?atr_key=inventada")
    R.check("X11", total_of(d11) == "23" and 'value=""' in d11
            and '<option value="">Todo el listado</option>' in d11 and "atr_value" not in d11,
            f"total={total_of(d11)}")

    # X12 — el CSV lleva solo el grupo y su número coincide con el indicador.
    vf.mysql("DELETE FROM rate_limits WHERE scope='dash:export'")
    s12, csv12, u12 = ah.get(f"/forms/{fid}/export.csv?atr_key=sede&atr_value=Norte")
    open("/tmp/xcsv12.txt","w").write(csv12[:500])
    rows12 = list(csv.reader(io.StringIO(csv12.lstrip("\ufeff"))))
    R.check("X12", s12 == 200 and len(rows12) - 1 == 20, f"status={s12} url={u12} filas_csv={len(rows12)-1}")

    # X13 — el desplegable de valores está topado (200 + «Todos»).
    _, d13, _ = ah.get(base + "?atr_key=zona")
    sel13 = re.search(r'<select class="form-select" name="atr_value".*?</select>', d13, re.S)
    opts13 = len(re.findall(r"<option ", sel13.group(0))) if sel13 else 0
    R.check("X13", opts13 == 201 and "Zona 000" in (sel13.group(0) if sel13 else ""), f"opciones={opts13}")

    # X14 — fecha + atributo se acumulan en la tabla y en el CSV.
    q14 = "?from=2026-10-21&to=2026-10-21&atr_key=sede&atr_value=Norte"
    _, d14, _ = ah.get(base + q14)
    vf.mysql("DELETE FROM rate_limits WHERE scope='dash:export'")
    s14, csv14, u14 = ah.get(f"/forms/{fid}/export.csv{q14}")
    rows14 = list(csv.reader(io.StringIO(csv14.lstrip("\ufeff"))))
    R.check("X14", total_of(d14) == "10" and len(rows14) - 1 == 10, f"total={total_of(d14)} csv={len(rows14)-1} status={s14}")

    print("BLOQUE X:", R.report())
finally:
    if fid:
        vf.mysql(f"DELETE FROM forms WHERE id={fid}")
