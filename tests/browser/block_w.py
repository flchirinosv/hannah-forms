#!/usr/bin/env python3
"""Bloque W: con qué se identifica una lista de participantes."""

import json, random, re, sys, uuid
sys.path.insert(0, "tests/browser")
import vf

R = vf.R()
ah = vf.Http("admin@demo.com")
suf = uuid.uuid4().hex[:8]
made = []
ALFA = "23456789ABCDEFGHJKMNPQRSTWXYZ"

def esc(s):
    return str(s).replace("\\", "\\\\").replace("'", "\\'")

def code_for(i):
    return "".join(random.choice(ALFA) for _ in range(16))

def new_form(title, settings=None, published=False):
    t = ah.csrf(ah.get("/dashboard")[1])
    r = ah.post("/forms", {"_token": t, "title": title, "description": ""})
    m = re.search(r"/forms/(\d+)/builder", r[2])
    if not m:
        raise RuntimeError("no se creó " + title)
    fid = int(m.group(1)); made.append(fid)
    if settings is not None:
        vf.mysql(f"UPDATE forms SET settings='{esc(json.dumps(settings))}' WHERE id={fid}")
    if published:
        vf.mysql(f"UPDATE forms SET status='published' WHERE id={fid}")
    return fid

def participants(fid, filas, identifier="cedula"):
    ids = []
    for ced, nombre, correo in filas:
        code = code_for(len(ids) + fid)
        valor = {"cedula": ced, "correo": correo, "codigo": code}[identifier]
        vf.mysql("INSERT INTO form_participants "
                 "(form_id,cedula,nombre,correo,telefono,identifier_value,code) VALUES "
                 f"({fid},'{esc(ced)}','{esc(nombre)}','{esc(correo)}','','{esc(valor)}','{code}')")
        ids.append(int(vf.db1("SELECT id FROM form_participants WHERE code='%s'" % code)[0]))
    return ids

def catalog(fid):
    vf.mysql(f"INSERT INTO form_participant_fields (form_id,key_name,label,position) VALUES ({fid},'sede','Sede',0)")

def settings(fid, data):
    _, html, _ = ah.get(f"/forms/{fid}")
    data = dict(data)
    data["_token"] = ah.csrf(html)
    s, body, _ = ah.req("POST", f"/forms/{fid}/settings", data,
                        headers={"X-Requested-With": "XMLHttpRequest"})
    try:
        return s, json.loads(body)
    except Exception:
        return s, {"raw": body}

def ident(fid):
    return vf.db1(f"SELECT settings FROM forms WHERE id={fid}")[0]

def ident_field(fid):
    s = json.loads(ident(fid))
    return (s.get("access") or {}).get("identifier")

def modo(fid):
    s = json.loads(ident(fid))
    return (s.get("access") or {}).get("mode")

def pub_get(slug):
    cli = vf.Http()
    s, html, url = cli.get(f"/f/{slug}")
    return cli, s, html, url

def reset_code_limits():
    vf.mysql("DELETE FROM rate_limits WHERE scope IN ('form_code','form_code_codigo')")

try:
    vf.mysql("DELETE FROM forms WHERE title LIKE '[BT] W %'")
    vf.mysql("DELETE FROM rate_limits WHERE scope='dash:create'")
    reset_code_limits()

    # W1–W3, W6, W19 — selector de modo/identificador en la ficha.
    fui = new_form(f"[BT] W ui {suf}", {"access": {"mode": "public", "identifier": "correo"}})
    # W4/W5 — el mismo formulario, ya con roster y correo guardado.
    fro = new_form(f"[BT] W roster {suf}", {"access": {"mode": "roster", "identifier": "correo"}})

    # W7 — lista sin correo.
    f7 = new_form(f"[BT] W falta {suf}", {"access": {"mode": "roster", "identifier": "cedula"}})
    participants(f7, [(f"W7-{i}", f"W7 {i}", "") for i in range(2)])

    # W8 — dos correos iguales.
    f8 = new_form(f"[BT] W dup {suf}", {"access": {"mode": "roster", "identifier": "cedula"}})
    participants(f8, [("W8-1", "Uno", "igual@bt.local"), ("W8-2", "Dos", "igual@bt.local"),
                      ("W8-3", "Tres", "otro@bt.local")])

    # W9 — nueve valores repetidos (pares), más de los cinco que caben.
    f9 = new_form(f"[BT] W nueve {suf}", {"access": {"mode": "roster", "identifier": "cedula"}})
    filas9 = []
    for i in range(9):
        filas9 += [(f"W9-{i}a", f"Noventa {i}a", f"rep{i}@bt.local"),
                   (f"W9-{i}b", f"Noventa {i}b", f"rep{i}@bt.local")]
    participants(f9, filas9)

    # W10–W13 — todos con correo, para reescribir la identidad sin rechazo.
    f10 = new_form(f"[BT] W reescribe {suf}",
                   {"access": {"mode": "roster", "identifier": "cedula"}}, published=True)
    catalog(f10)
    filas10 = [("W10-1", "Uno", "w10-1@bt.local"), ("W10-2", "Dos", "w10-2@bt.local"),
               ("W10-3", "Tres", "w10-3@bt.local")]
    pids10 = participants(f10, filas10)
    codes10 = [vf.db1(f"SELECT code FROM form_participants WHERE id={p}")[0] for p in pids10]
    fpub = new_form(f"[BT] W publico {suf}", {"access": {"mode": "public"}}, published=True)

    # W14–W17 — puerta pública con cédula y luego con correo.
    fgate = new_form(f"[BT] W puerta {suf}",
                     {"access": {"mode": "roster", "identifier": "cedula"}}, published=True)
    vf.mysql(f"INSERT INTO fields (form_id,type,label,position,is_required) VALUES ({fgate},'text','Comentario',1,0)")
    pg = participants(fgate, [("W17-1", "Puerta Uno", "gate1@bt.local"),
                              ("W17-2", "Puerta Dos", "gate2@bt.local")])
    slug_gate = vf.db1(f"SELECT slug FROM forms WHERE id={fgate}")[0]
    code_gate = vf.db1(f"SELECT code FROM form_participants WHERE id={pg[0]}")[0]

    with vf.VF(R) as v:
        # W1 — cuatro opciones, roster la última.
        v.goto(f"/forms/{fui}", wait=700)
        ops = v.page.evaluate("()=>[...document.querySelectorAll('#access_mode option')].map(o=>o.value)")
        R.check("W1", ops == ["public", "email", "allowlist", "roster"], str(ops))

        # W2 — roster sin guardar enseña el identificador guardado.
        w2 = v.page.evaluate("""()=>{const s=document.getElementById('access_mode');s.value='roster';
            s.dispatchEvent(new Event('change',{bubbles:true}));
            return {hidden:document.getElementById('accessIdentifierBox').hasAttribute('hidden'),
                    dis:document.getElementById('access_identifier').disabled,
                    val:document.getElementById('access_identifier').value};}""")
        R.check("W2", not w2["hidden"] and not w2["dis"] and w2["val"] == "correo", str(w2))

        # W3 — volver a public sin guardar; el valor guardado no se pierde.
        w3 = v.page.evaluate("""()=>{const s=document.getElementById('access_mode');s.value='public';
            s.dispatchEvent(new Event('change',{bubbles:true}));
            return {hidden:document.getElementById('accessIdentifierBox').hasAttribute('hidden'),
                    dis:document.getElementById('access_identifier').disabled,
                    val:document.getElementById('access_identifier').value};}""")
        R.check("W3", w3["hidden"] and w3["dis"] and w3["val"] == "correo" and ident_field(fui) == "correo", str(w3))

        # W4 — servidor pinta el selector visible y con correo.
        v.goto(f"/forms/{fro}", wait=700)
        w4 = v.page.evaluate("""()=>({hidden:document.getElementById('accessIdentifierBox').hasAttribute('hidden'),
            dis:document.getElementById('access_identifier').disabled,
            val:document.getElementById('access_identifier').value})""")
        R.check("W4", not w4["hidden"] and not w4["dis"] and w4["val"] == "correo", str(w4))

        # W5 — sin JS: guardar roster sin mandar identificador conserva correo.
        s5, j5 = settings(fro, {"access_mode": "roster"})
        v.goto(f"/forms/{fro}", wait=700)
        w5 = v.page.evaluate("""()=>({hidden:document.getElementById('accessIdentifierBox').hasAttribute('hidden'),
            dis:document.getElementById('access_identifier').disabled,
            val:document.getElementById('access_identifier').value})""")
        R.check("W5", modo(fro) == "roster" and ident_field(fro) == "correo"
                and not w5["hidden"] and w5["val"] == "correo", f"{j5} {w5}")

        # W6 — guardar otro ajuste en public no manda el identificador apagado.
        s6, j6 = settings(fro, {"access_mode": "public", "title": f"[BT] W roster {suf} editado"})
        R.check("W6", modo(fro) == "public" and ident_field(fro) == "correo", f"{j6} {ident(fro)}")

        # W19 — el aviso del modo lista habla del identificador elegido.
        v.goto(f"/forms/{fui}", wait=700)
        w19 = v.page.evaluate("""()=>{const s=document.getElementById('access_mode');s.value='roster';
            s.dispatchEvent(new Event('change',{bubbles:true}));
            return document.getElementById('stAccessHint').textContent;}""")
        R.check("W19", "identificador elegido" in w19 and "código" in w19, w19)

        # W18 — la ayuda del identificador nombra la pantalla y la invitación.
        w18html = v.page.content()
        R.check("W18", "código de la invitación sirve siempre" in w18html
                and "solo sirve para mandarles el enlace" not in w18html, "")

    # W7 — cambiar a correo con gente sin correo: no guarda nada.
    n7 = int(vf.db1(f"SELECT COUNT(*) FROM form_participants WHERE form_id={f7}")[0])
    s7, j7 = settings(f7, {"access_mode": "roster", "access_identifier": "correo"})
    err7 = json.dumps(j7, ensure_ascii=False)
    n7b = int(vf.db1(f"SELECT COUNT(*) FROM form_participants WHERE form_id={f7}")[0])
    R.check("W7", s7 == 422 and ident_field(f7) == "cedula" and n7 == n7b
            and "no lo tiene" in err7, f"{s7} {err7[:300]}")

    # W8 — dos correos iguales: rechazo nombrando el valor repetido.
    s8, j8 = settings(f8, {"access_mode": "roster", "access_identifier": "correo"})
    err8 = json.dumps(j8, ensure_ascii=False)
    R.check("W8", s8 == 422 and ident_field(f8) == "cedula"
            and "igual@bt.local" in err8, f"{s8} {err8[:400]}")

    # W9 — mensaje topado y total aparte.
    s9, j9 = settings(f9, {"access_mode": "roster", "access_identifier": "correo"})
    err9 = json.dumps(j9, ensure_ascii=False)
    citados = re.findall(r"«rep\d@bt\.local»", err9)
    R.check("W9", s9 == 422 and len(citados) == 5 and "18 persona" in err9,
            f"citados={len(citados)} {err9[:400]}")

    # W10 — cambio válido reescribe identifier_value y conserva el código.
    s10, j10 = settings(f10, {"access_mode": "roster", "access_identifier": "correo"})
    vals10 = [r[0] for r in vf.mysql(f"SELECT identifier_value FROM form_participants WHERE form_id={f10} ORDER BY id")]
    codes10b = [vf.db1(f"SELECT code FROM form_participants WHERE id={p}")[0] for p in pids10]
    R.check("W10", s10 == 200 and ident_field(f10) == "correo"
            and vals10 == [r[2] for r in filas10] and codes10 == codes10b, f"{j10} {vals10}")

    # W11 — el enlace con el código viejo sigue entrando.
    reset_code_limits()
    slug10 = vf.db1(f"SELECT slug FROM forms WHERE id={f10}")[0]
    cli10 = vf.Http()
    _, htmlg, _ = cli10.get(f"/f/{slug10}")
    s11, body11, url11 = cli10.req("POST", f"/f/{slug10}/code",
                                   {"_token": cli10.csrf(htmlg), "code": codes10[0]})
    R.check("W11", s11 == 200 and f"?t={codes10[0]}" in url11, f"{s11} {url11}")

    # W12 — volver a public deja la lista y el catálogo intactos.
    s12, j12 = settings(f10, {"access_mode": "public"})
    n12 = int(vf.db1(f"SELECT COUNT(*) FROM form_participants WHERE form_id={f10}")[0])
    c12 = int(vf.db1(f"SELECT COUNT(*) FROM form_participant_fields WHERE form_id={f10}")[0])
    _, html12, _ = vf.Http().get(f"/f/{slug10}")
    R.check("W12", modo(f10) == "public" and n12 == 3 and c12 == 1
            and "vfAccessForm" not in html12, f"{j12} n={n12} c={c12}")

    with vf.VF(R) as v:
        # W13 — con roster, los botones de la ficha son los mismos.
        settings(f10, {"access_mode": "roster", "access_identifier": "correo"})
        v.goto(f"/forms/{f10}", wait=700)
        a10 = v.page.evaluate("()=>document.getElementById('vfSlotActions')?.innerText||''")
        s10 = v.page.evaluate("()=>document.getElementById('vfSlotShare')?.innerText||''")
        v.goto(f"/forms/{fpub}", wait=700)
        apub = v.page.evaluate("()=>document.getElementById('vfSlotActions')?.innerText||''")
        spub = v.page.evaluate("()=>document.getElementById('vfSlotShare')?.innerText||''")
        R.check("W13", a10.strip() == apub.strip() and "Cerrar" in a10
                and "Abrir en nueva pestaña" in s10 and "Abrir en nueva pestaña" in spub,
                f"roster={a10!r} public={apub!r}")

        # W14 — la puerta con cédula pide la cédula.
        v.goto(f"/f/{slug_gate}", wait=700)
        w14 = v.page.evaluate("""()=>({label:document.querySelector('label[for=vfAccessCode]')?.textContent.trim(),
            ph:document.getElementById('vfAccessCode')?.placeholder,
            note:document.querySelector('.fill-container p.small.text-muted.mt-3.mb-0')?.textContent||''})""")
        R.check("W14", w14["label"] == "Tu cédula" and "V-12345678" in (w14["ph"] or "")
                and "cédula" in w14["note"], str(w14))

        # W15 — con identificador código, el campo pide los 16 caracteres.
        settings(fgate, {"access_mode": "roster", "access_identifier": "codigo"})
        v.goto(f"/f/{slug_gate}", wait=700)
        w15 = v.page.evaluate("""()=>({label:document.querySelector('label[for=vfAccessCode]')?.textContent.trim(),
            ph:document.getElementById('vfAccessCode')?.placeholder})""")
        R.check("W15", w15["label"] == "Tu código personal" and "16 caracteres" in (w15["ph"] or ""), str(w15))

    # W16 — con correo, teclear una cédula no entra y el aviso habla del correo.
    settings(fgate, {"access_mode": "roster", "access_identifier": "correo"})
    reset_code_limits()
    cli16 = vf.Http()
    _, h16, _ = cli16.get(f"/f/{slug_gate}")
    s16, b16, u16 = cli16.req("POST", f"/f/{slug_gate}/code",
                              {"_token": cli16.csrf(h16), "code": "W17-1"})
    R.check("W16", s16 == 200 and "Ese correo no está en la lista." in b16
            and "Esa cédula no está en la lista." not in b16, f"{s16} {u16}")

    # W17 — con cédula, teclearla entra y redirige con el código de esa persona.
    settings(fgate, {"access_mode": "roster", "access_identifier": "cedula"})
    reset_code_limits()
    cli17 = vf.Http()
    _, h17, _ = cli17.get(f"/f/{slug_gate}")
    s17, b17, u17 = cli17.req("POST", f"/f/{slug_gate}/code",
                              {"_token": cli17.csrf(h17), "code": "W17-1"})
    R.check("W17", s17 == 200 and f"?t={code_gate}" in u17 and "Acceso restringido" not in b17,
            f"{s17} {u17}")

    print("BLOQUE W:", R.report())
finally:
    if made:
        ids = ",".join(str(i) for i in made)
        vf.mysql(f"DELETE FROM forms WHERE id IN ({ids})")
