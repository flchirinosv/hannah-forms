#!/usr/bin/env python3
"""Bloque V: pantalla de participantes (roster)."""

import json, re, subprocess, sys, uuid
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
    import random
    return "".join(random.choice(ALFA) for _ in range(16))

def new_form(title, roster=False):
    t = ah.csrf(ah.get("/dashboard")[1])
    r = ah.post("/forms", {"_token": t, "title": title, "description": ""})
    m = re.search(r"/forms/(\d+)/builder", r[2])
    if not m:
        raise RuntimeError("no se creó " + title)
    fid = int(m.group(1)); made.append(fid)
    if roster:
        s = json.dumps({"access": {"mode": "roster", "identifier": "cedula"}})
        vf.mysql(f"UPDATE forms SET settings='{esc(s)}' WHERE id={fid}")
        vf.mysql(f"INSERT INTO fields (form_id,type,label,position,is_required) VALUES ({fid},'text','Comentario',1,0)")
    return fid

def catalog(fid, label="Sede", key="sede"):
    vf.mysql(f"INSERT INTO form_participant_fields (form_id,key_name,label,position) VALUES ({fid},'{key}','{label}',0)")

def make_xlsx(path, rows, attrs=None):
    cfg = path + ".json"
    with open(cfg, "w") as fh:
        json.dump({"rows": rows, "attrs": attrs or []}, fh)
    php = ('require "config/autoload.php"; $d=json_decode(file_get_contents($argv[1]),true);'
           ' file_put_contents($argv[2], App\\Services\\RosterTemplate::exportar($d["rows"],$d["attrs"]));')
    subprocess.run(["php", "-r", php, cfg, path], cwd=vf.ROOT, check=True)

def participants(fid, n, attr="Norte", start=1):
    for i in range(start, start + n):
        ced = f"V-{i:02d}"
        code = code_for(i + fid * 100)
        vf.mysql("INSERT INTO form_participants (form_id,cedula,nombre,correo,telefono,identifier_value,code) "
                 f"VALUES ({fid},'{ced}','Nombre {i:02d}','p{i}@bt.local','','{ced}','{code}')")
        pid = int(vf.db1(f"SELECT id FROM form_participants WHERE code='{code}'")[0])
        vf.mysql(f"INSERT INTO form_participant_attrs (form_id,participant_id,key_name,value) VALUES ({fid},{pid},'sede','{attr if i % 2 else 'Sur'}')")

def counts(fid):
    return (int(vf.db1(f"SELECT COUNT(*) FROM form_participants WHERE form_id={fid}")[0]),
            int(vf.db1(f"SELECT COUNT(*) FROM form_participant_fields WHERE form_id={fid}")[0]))

def confirm(v, text=None):
    v.page.wait_for_selector("#vfConfirm.show", timeout=3000)
    if text:
        v.page.locator("#vfConfirmOk").filter(has_text=text).first.evaluate("e=>e.click()")
    else:
        v.page.locator("#vfConfirmOk").evaluate("e=>e.click()")
    v.page.wait_for_timeout(700)

def submit(form):
    form.evaluate("f=>f.requestSubmit()")

try:
    vf.mysql("DELETE FROM forms WHERE title LIKE '[BT] V %'")
    vf.mysql("DELETE FROM rate_limits WHERE scope='dash:create'")

    fid = new_form(f"[BT] V roster {suf}", roster=True)
    catalog(fid); participants(fid, 26)
    slug = vf.db1(f"SELECT slug FROM forms WHERE id={fid}")[0]

    with vf.VF(R) as v:
        # V1, V28 — cuatro tarjetas y tres bloques.
        v.goto(f"/forms/{fid}/access", wait=800)
        ids = v.page.evaluate("()=>['access','rosterBoardCard','rosterListCard','rosterImportCard'].map(x=>!!document.getElementById(x))")
        R.check("V1", all(ids) and v.page.locator("#rosterImportCard details").count() == 1
                and not v.page.locator("#rosterImportCard details").first.evaluate("d=>d.open"), str(ids))
        R.check("V28", v.page.locator("#rosterBoardCard").count() == 1 and v.page.locator("#rosterListCard").count() == 1
                and v.page.locator("#rosterImportCard").count() == 1, "tres tarjetas separadas")

        # V2, V33 — el colapso nativo y las dos columnas.
        d = v.page.locator("#rosterImportCard details").first
        d.locator("summary").evaluate("e=>e.click()"); v.page.wait_for_timeout(250)
        layout = v.page.evaluate("""()=>({open:document.querySelector('#rosterImportCard details').open,
            izq:!!document.querySelector('#rosterImportCard .col-lg-7'),
            der:!!document.querySelector('#rosterImportCard .col-lg-5'),
            fuera:!document.querySelector('#rosterImportCard details').contains(document.querySelector('#rosterImportCard form[action$="/truncate"]'))})""")
        R.check("V2", layout["open"] and layout["izq"] and layout["der"], str(layout))
        R.check("V33", layout["izq"] and layout["der"] and layout["fuera"], str(layout))

        # V3, V36 — sin JS la pantalla y la búsqueda siguen.
        h = vf.Http("admin@demo.com")
        s, html, _ = h.get(f"/forms/{fid}/access")
        s2, html2, _ = h.get(f"/forms/{fid}/access?q=V-01")
        R.check("V3", "rosterListCard" in html and "rosterImportCard" in html and "V-01" in html, f"status={s}")
        R.check("V36", "V-01" in html2 and "V-02" not in html2, "GET filtra")

        # V26, V27 — URL con q/sort/page y paginador que conserva.
        v.goto(f"/forms/{fid}/access?q=V-&sort=nombre&page=2", wait=800)
        v26 = v.page.evaluate("""()=>({rows:document.querySelectorAll('#rosterListCard tbody tr[data-vf-row]').length,
            q:document.querySelector('[data-vf-roster-search] input[name=q]')?.value||'', page:new URL(location.href).searchParams.get('page'),
            sort:new URL(location.href).searchParams.get('sort')||''})""")
        links = v.page.evaluate("()=>[...document.querySelectorAll('#rosterListCard .pagination a')].map(a=>a.getAttribute('href'))")
        R.check("V26", v26["rows"] == 1 and v26["q"] == "V-" and v26["page"] == "2" and v26["sort"] == "nombre"
                and any("q=V-" in x and "sort=nombre" in x for x in links), f"{v26} links={links[:3]}")
        v.page.locator("#rosterListCard .pagination a").filter(has_text="1").first.evaluate("e=>e.click()")
        v.page.wait_for_timeout(700)
        v27 = v.page.evaluate("()=>({q:document.querySelector('[data-vf-roster-search] input[name=q]')?.value||'',sort:new URL(location.href).searchParams.get('sort')||''})")
        R.check("V27", v27["q"] == "V-" and v27["sort"] == "nombre", str(v27))

        # V19 — 26 participantes: 25 por página y números del total.
        v.goto(f"/forms/{fid}/access", wait=800)
        v19 = v.page.evaluate("""()=>({rows:document.querySelectorAll('#rosterListCard tbody tr[data-vf-row]').length,
            stats:document.querySelector('#rosterStats')?.innerText||'', pag:document.querySelectorAll('#rosterListCard .pagination a').length})""")
        R.check("V19", v19["rows"] == 25 and "26" in v19["stats"] and v19["pag"] >= 2, str(v19))

        # V30, V31, V29 — buscador, modales y columnas.
        v30 = v.page.evaluate("""()=>({search:!!document.querySelector('[data-vf-roster-search]'),
            grupo:!!document.querySelector('[data-vf-roster-search] .input-group'),
            mismo:document.querySelector('[data-vf-roster-search]')?.closest('.d-flex') === document.querySelector('[data-bs-target="#rosterAddModal"]')?.closest('.d-flex')})""")
        R.check("V30", v30["search"] and v30["grupo"] and v30["mismo"], str(v30))
        v.page.locator('[data-bs-target="#rosterAddModal"]').first.evaluate("e=>e.click()")
        v.page.wait_for_timeout(400)
        v31 = v.page.evaluate("""()=>{const f=document.querySelector('#rosterAddModal form'); if(!f) return {n:0};
            const cols=[...f.querySelectorAll('.row > div')].map(d=>d.className); return {n:cols.length, todas:cols.every(c=>c.includes('col-12')&&!c.includes('col-lg-'))};}""")
        v29 = v.page.evaluate("()=>document.querySelector('#rosterAddModal .modal-header')?.className||''")
        R.check("V31", v31["n"] >= 4 and v31["todas"], str(v31))
        R.check("V29", "app-navbar" in v29 and "text-white" in v29 and "modal-header" in v29, v29)
        v.page.locator("#rosterAddModal .btn-close").first.evaluate("e=>e.click()"); v.page.wait_for_timeout(300)

        # V4–V6 — alta sin recarga, duplicado y catálogo estable.
        navs = []
        v.page.on("framenavigated", lambda f: navs.append(f.url) if f == v.page.main_frame else None)
        cat0 = v.page.locator("#rosterCatalog").inner_html() if v.page.locator("#rosterCatalog").count() else ""
        v.page.locator('[data-bs-target="#rosterAddModal"]').first.evaluate("e=>e.click()")
        v.page.wait_for_timeout(350)
        v.page.locator('#rosterAddModal input[name="cedula"]').fill(f"V-90{suf[:1]}")
        submit(v.page.locator('#rosterAddModal form'))
        v.page.wait_for_timeout(1000)
        n1 = counts(fid)[0]
        R.check("V4", not navs and n1 == 27 and v.page.locator('#rosterAddId').input_value() == "" and v.page.evaluate("()=>document.activeElement?.id") == "rosterAddId",
                f"navs={navs} n={n1}")
        cat1 = v.page.locator("#rosterCatalog").inner_html() if v.page.locator("#rosterCatalog").count() else ""
        # El alta ya no recarga: tras guardar el diálogo sigue abierto y volver a
        # pulsar su disparador lo cerraría (Bootstrap lo alterna).
        v.page.evaluate("""()=>{const m=document.querySelector('#rosterAddModal');
            if(!m.classList.contains('show')) document.querySelector('[data-bs-target="#rosterAddModal"]').click();}""")
        v.page.wait_for_timeout(350)
        v.page.locator('#rosterAddModal input[name="cedula"]').fill(f"V-90{suf[:1]}")
        submit(v.page.locator('#rosterAddModal form')); v.page.wait_for_timeout(900)
        n2 = counts(fid)[0]
        err = v.page.evaluate("()=>{const e=document.querySelector('#rosterAddError');return {txt:e?.innerText||'',vis:!!e&&!e.classList.contains('d-none')}}")
        R.check("V5", n2 == 27 and err["vis"] and "ya" in err["txt"].lower(), f"n={n2} err={err}")
        cat2 = v.page.locator("#rosterCatalog").inner_html() if v.page.locator("#rosterCatalog").count() else ""
        R.check("V6", cat0 == cat1 == cat2, "catálogo sin repintar")

        # V7, V8, V32, V25 — edición en modal.
        pid = int(vf.db1(f"SELECT id FROM form_participants WHERE form_id={fid} AND cedula='V-01'")[0])
        v.page.locator(f'button[data-vf-participant="{pid}"]').first.evaluate("e=>e.click()")
        v.page.wait_for_timeout(400)
        v7 = v.page.evaluate("""()=>{const m=document.querySelector('#rosterEditModal');
            return {show:m?.classList.contains('show'), title:m?.innerText||'', has:!!document.getElementById('rosterEditForm"""+str(pid)+"""')};}""".replace('"+str(pid)+"', str(pid)))
        v32 = v.page.evaluate("""()=>{const m=document.querySelector('#rosterEditModal');
            const btns=[...m.querySelectorAll('button')].map(b=>b.innerText.trim());return {btns:btns, guardar:!!m.querySelector('button[form^="rosterEditForm"]')};}""")
        R.check("V7", v7["show"] and "Nombre" in v7["title"] and v7["has"], str(v7))
        R.check("V32", v32["guardar"] and any("Quitar" in x for x in v32["btns"]) and any("Guardar" in x for x in v32["btns"])
                and v32["btns"].index("Quitar") < v32["btns"].index("Guardar"), str(v32))
        v.page.locator(f"#rosterEditNombre{pid}").fill("Zeta editado")
        submit(v.page.locator(f"#rosterEditForm{pid}")); v.page.wait_for_timeout(900)
        nombre = vf.db1(f"SELECT nombre FROM form_participants WHERE id={pid}")[0]
        R.check("V8", nombre == "Zeta editado" and "Zeta editado" in v.page.locator("#rosterListCard").inner_text(), f"nombre={nombre}")
        v.page.locator(f'button[data-vf-participant="{pid}"]').first.evaluate("e=>e.click()"); v.page.wait_for_timeout(300)
        v.page.locator("#rosterEditModal").evaluate("e=>e.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true,cancelable:true}))")
        v.page.wait_for_timeout(500)
        v25 = v.page.evaluate("""()=>{const x=document.getElementById('rowactions-"""+str(pid)+"""');
            return {show:document.querySelector('#rosterEditModal')?.classList.contains('show'),
            caja:!!document.querySelector('#rosterList #rowactions-"""+str(pid)+"""'), oculta:!!x?.closest('tr.d-none')};}""")
        R.check("V25", not v25["show"] and v25["caja"] and v25["oculta"], str(v25))

        # V34, V35, V23 — buscar sin recarga y atrás.
        v.goto(f"/forms/{fid}/access", wait=700)
        navs.clear()
        v.page.evaluate("()=>{window.__vfMark='vivo';}")
        v.page.locator('[data-vf-roster-search] input[name="q"]').fill("V-01")
        v.page.locator('[data-vf-roster-search] button[type="submit"]').first.evaluate("e=>e.click()")
        v.page.wait_for_timeout(900)
        v34 = v.page.evaluate("""()=>({url:location.search, q:document.querySelector('[data-vf-roster-search] input[name="q"]')?.value,
            txt:document.querySelector('#rosterListCard')?.innerText||'', vivo:window.__vfMark==='vivo'})""")
        R.check("V34", v34["vivo"] and "q=V-01" in v34["url"] and v34["q"] == "V-01"
                and "V-01" in v34["txt"] and "V-02" not in v34["txt"], f"navs={navs} {v34}")
        v.page.go_back(); v.page.wait_for_timeout(800)
        v35 = v.page.evaluate("()=>document.querySelector('[data-vf-roster-search] input[name=\"q\"]')?.value||''")
        R.check("V35", v35 == "", f"q={v35!r}")
        R.na("V23", "El scroll del panel no es fiable en Lightpanda; cubierto por JS/CSS")

        # V10 — borrar con búsqueda puesta.
        v.goto(f"/forms/{fid}/access?q=V-02", wait=700)
        pid2 = int(vf.db1(f"SELECT id FROM form_participants WHERE form_id={fid} AND cedula='V-02'")[0])
        v.page.locator(f'button[data-vf-participant="{pid2}"]').first.evaluate("e=>e.click()")
        v.page.wait_for_timeout(300)
        v.page.locator('form[action$="/participants/delete"] button[type="submit"]').first.evaluate("e=>e.click()")
        confirm(v, "Sí, quitar"); v.page.wait_for_timeout(700)
        v10 = v.page.evaluate("()=>({url:location.search, txt:document.querySelector('#rosterListCard')?.innerText||''})")
        R.check("V10", "q=V-02" in v10["url"] and "V-03" not in v10["txt"] and counts(fid)[0] == 26, str(v10))

        # V17, V18, V16 — catálogo, baja y vaciado.
        fid2 = new_form(f"[BT] V catalogo {suf}", roster=True); catalog(fid2, "Sede", "sede"); participants(fid2, 3)
        v.goto(f"/forms/{fid2}/access", wait=700)
        cat = v.page.locator("#rosterCatalog")
        cat.locator('form[action$="/participants/attribute"] button[type="submit"]').first.evaluate("e=>e.click()")
        confirm(v, "Sí, quitar el atributo"); v.page.wait_for_timeout(800)
        v17 = v.page.evaluate("()=>({cat:document.querySelector('#rosterCatalog')?.innerText||'', fila:document.querySelector('#rosterListCard')?.innerText||'', alta:!!document.querySelector('#rosterAddModal input[name^=\"data[\"]')})")
        R.check("V17", "Sede" not in v17["cat"] and "Norte" not in v17["fila"] and not v17["alta"]
                and counts(fid2)[1] == 0, str(v17))
        catalog(fid2, "Sede", "sede")
        v.goto(f"/forms/{fid2}/access", wait=700)
        pid3 = int(vf.db1(f"SELECT id FROM form_participants WHERE form_id={fid2} ORDER BY id LIMIT 1")[0])
        v.page.locator(f'button[data-vf-participant="{pid3}"]').first.evaluate("e=>e.click()"); v.page.wait_for_timeout(300)
        v.page.locator('form[action$="/participants/delete"] button[type="submit"]').first.evaluate("e=>e.click()")
        dlg = v.page.evaluate("()=>({txt:document.querySelector('#vfConfirmText')?.innerText||'',ok:document.querySelector('#vfConfirmOk')?.innerText||''})")
        R.check("V18", "código" in dlg["txt"] and "respuestas" in dlg["txt"] and "quitar" in dlg["ok"].lower(), str(dlg))
        confirm(v, "Sí, quitar"); v.page.wait_for_timeout(700)
        v.goto(f"/forms/{fid2}/access", wait=700)
        v.page.locator('form[action$="/participants/truncate"] button').first.evaluate("e=>e.click()")
        dlg2 = v.page.evaluate("()=>({txt:document.querySelector('#vfConfirmText')?.innerText||'',ok:document.querySelector('#vfConfirmOk')?.innerText||''})")
        confirm(v, "Sí, vaciar la lista"); v.page.wait_for_timeout(900)
        v.goto(f"/forms/{fid2}/access", wait=700)
        R.check("V16", "respuestas" in dlg2["txt"] and "catálogo" in dlg2["txt"] or ("respuestas" in dlg2["txt"])
                and counts(fid2)[0] == 0 and counts(fid2)[1] == 1, f"dlg={dlg2} counts={counts(fid2)}")

        # V24 — borrar la respuesta suelta la marca y deja volver a entrar.
        vf.mysql(f"UPDATE forms SET status='published' WHERE id={fid}")
        code = vf.db1(f"SELECT code FROM form_participants WHERE form_id={fid} AND cedula='V-04'")[0]
        fld = vf.db1(f"SELECT id FROM fields WHERE form_id={fid} AND type='text' LIMIT 1")[0]
        hh = vf.Http()
        s, html, _ = hh.get(f"/f/{slug}?t={code}"); tok = hh.csrf(html)
        s, html, url = hh.req("POST", f"/f/{slug}?t={code}", {"_token": tok, f"field_{fld}": "hola"})
        sid = int(vf.db1(f"SELECT id FROM submissions WHERE form_id={fid} ORDER BY id DESC LIMIT 1")[0])
        vf.mysql(f"UPDATE form_participants SET responded_at=NOW() WHERE code='{code}'")
        ah.req("POST", f"/forms/{fid}/responses/{sid}/delete", {"_token": ah.csrf(ah.get(f"/forms/{fid}/responses")[1])})
        marcada = vf.db1(f"SELECT responded_at FROM form_participants WHERE code='{code}'")[0]
        rre, rhtml, _ = hh.get(f"/f/{slug}?t={code}")
        R.check("V24", str(marcada).upper() in ("", "NONE", "NULL") and rre == 200 and 'id="vfForm"' in rhtml, f"responded_at={marcada} re={rre}")

        # V22 — fuera de roster solo la tarjeta de acceso normal.
        fid3 = new_form(f"[BT] V lista {suf}")
        vf.mysql(f"UPDATE forms SET settings='{esc(json.dumps({'access': {'mode': 'allowlist'}}))}' WHERE id={fid3}")
        v.goto(f"/forms/{fid3}/access", wait=700)
        v22 = v.page.evaluate("()=>({list:!!document.getElementById('accessList'), roster:!!document.getElementById('rosterListCard'), alta:!!document.getElementById('accessAddForm')})")
        R.check("V22", v22["list"] and not v22["roster"] and v22["alta"], str(v22))

        # V21 — sin JS: buscar, añadir y borrar.
        h = vf.Http("admin@demo.com")
        s, html, _ = h.get(f"/forms/{fid2}/access?q=V-"); tok = h.csrf(html)
        h.req("POST", f"/forms/{fid2}/participants", {"_token": tok, "cedula": "V-99", "nombre": "Sin JS"})
        s, html, _ = h.get(f"/forms/{fid2}/access")
        add_ok = "V-99" in html
        pid4 = int(vf.db1(f"SELECT id FROM form_participants WHERE form_id={fid2} AND cedula='V-99'")[0])
        tok = h.csrf(html)
        h.req("POST", f"/forms/{fid2}/participants/delete", {"_token": tok, "participant_id": pid4})
        R.check("V21", add_ok and int(vf.db1(f"SELECT COUNT(*) FROM form_participants WHERE id={pid4}")[0]) == 0, "POST nativo")
        # V9 — buscar sin recarga, en el orden actual y conservando la URL.
        v.goto(f"/forms/{fid}/access?sort=nombre", wait=700)
        v.page.evaluate("()=>{window.__vfMark='vivo';}")
        v.page.locator('[data-vf-roster-search] input[name="q"]').fill("V-01")
        v.page.locator('[data-vf-roster-search] button[type="submit"]').first.evaluate("e=>e.click()")
        v.page.wait_for_timeout(900)
        v9 = v.page.evaluate("""()=>({vivo:window.__vfMark==='vivo', url:location.search,
            q:document.querySelector('[data-vf-roster-search] input[name="q"]')?.value,
            txt:document.querySelector('#rosterListCard')?.innerText||''})""")
        R.check("V9", v9["vivo"] and "q=V-01" in v9["url"] and "sort=nombre" in v9["url"]
                and "V-01" in v9["txt"] and "V-02" not in v9["txt"], str(v9))

        # V11 — importar .xlsx con atributo nuevo desde la UI (sin recarga).
        fid4 = new_form(f"[BT] V import {suf}", roster=True); participants(fid4, 1)
        xls11 = f"/tmp/bt_v11_{suf}.xlsx"
        make_xlsx(xls11, [{"cedula": "V-70", "nombre": "Importado", "correo": "i70@bt.local",
                           "telefono": "", "data": {"sede": "Norte"}}],
                  [{"label": "Sede", "key_name": "sede"}])
        v.goto(f"/forms/{fid4}/access", wait=700)
        v.page.evaluate("()=>{window.__vfMark='vivo';}")
        v.page.locator("#rosterArchivo").set_input_files(xls11)
        v.page.locator('form[action$="/participants/import"]').first.evaluate("f=>f.requestSubmit()")
        v.page.wait_for_timeout(900)
        v.page.locator('form[action$="/participants/import/confirm"]').first.evaluate("f=>f.requestSubmit()")
        v.page.wait_for_timeout(1300)
        v11 = v.page.evaluate("""()=>({vivo:window.__vfMark==='vivo',
            alta:!!document.querySelector('#rosterAddModal input[name^="data["]'),
            rep:document.querySelector('#rosterImportReport')?.innerText||'',
            lista:document.querySelector('#rosterListCard')?.innerText||''})""")
        R.check("V11", v11["vivo"] and int(vf.db1(f"SELECT COUNT(*) FROM form_participants WHERE form_id={fid4} AND cedula='V-70'")[0]) == 1
                and bool(vf.db1(f"SELECT id FROM form_participant_fields WHERE form_id={fid4} AND key_name='sede'"))
                and v11["alta"] and "V-70" in v11["lista"], str(v11))

        # V12 — fila sin identificador en reemplazar: no entra nadie y hay informe.
        xls12 = f"/tmp/bt_v12_{suf}.xlsx"
        make_xlsx(xls12, [{"cedula": "", "nombre": "Sin ID", "correo": "", "telefono": "", "data": {}},
                          {"cedula": "V-72", "nombre": "Buena", "correo": "", "telefono": "", "data": {}}])
        n12antes = int(vf.db1(f"SELECT COUNT(*) FROM form_participants WHERE form_id={fid4}")[0])
        v.getreq(f"/forms/{fid4}/access")
        tok = v.csrf()
        buf = open(xls12, "rb").read()
        r12 = v.ctx.request.post(vf.BASE + f"/forms/{fid4}/participants/import",
                                 multipart={"_token": tok, "modo": "reemplazar", "rosterQ": "", "rosterPage": "1", "rosterSort": "cedula",
                                            "archivo": {"name": "malo.xlsx", "mimeType": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet", "buffer": buf}},
                                 headers={"X-Requested-With": "XMLHttpRequest"}, max_redirects=0)
        j12 = r12.json()
        rep12 = (j12.get("html") or {}).get("rosterImportReport", "")
        mtok = re.search(r'name="roster_import_token" value="([^"]+)"', rep12)
        r12c = v.ctx.request.post(vf.BASE + f"/forms/{fid4}/participants/import/confirm",
                                  form={"_token": tok, "roster_import_token": mtok.group(1) if mtok else "",
                                        "map_identificador": "0", "map_nombre": "1", "map_correo": "2", "map_telefono": "3",
                                        "modo": "reemplazar", "rosterQ": "", "rosterPage": "1", "rosterSort": "cedula"},
                                  headers={"X-Requested-With": "XMLHttpRequest"}, max_redirects=0)
        j12c = r12c.json(); rep12c = (j12c.get("html") or {}).get("rosterImportReport", "")
        n12 = int(vf.db1(f"SELECT COUNT(*) FROM form_participants WHERE form_id={fid4}")[0])
        R.check("V12", j12.get("ok") is True and bool(mtok) and j12c.get("ok") is False
                and ("fila" in rep12c.lower() or "identificador" in rep12c.lower())
                and n12 == n12antes,
                f"preview={j12.get('ok')} confirm={j12c.get('ok')} n={n12}/{n12antes} rep={rep12c[:200]}")

        # V13, V15 — sin JS: informe dentro de la tarjeta y de un solo uso.
        fid5 = new_form(f"[BT] V import nativo {suf}", roster=True)
        xls13 = f"/tmp/bt_v13_{suf}.xlsx"
        make_xlsx(xls13, [{"cedula": "V-80", "nombre": "Nativa", "correo": "", "telefono": "", "data": {}},
                          {"cedula": "", "nombre": "Mala", "correo": "", "telefono": "", "data": {}}])
        hn = vf.Http("admin@demo.com")
        html = hn.get(f"/forms/{fid5}/access")[1]; tok = hn.csrf(html)
        body = open(xls13, "rb").read()
        boundary = "----vf" + uuid.uuid4().hex
        def part(name, value):
            return (f"--{boundary}\r\nContent-Disposition: form-data; name=\"{name}\"\r\n\r\n{value}\r\n").encode()
        payload = (part("_token", tok) + part("modo", "fusionar") + part("rosterQ", "") + part("rosterPage", "1") + part("rosterSort", "cedula")
                   + (f"--{boundary}\r\nContent-Disposition: form-data; name=\"archivo\"; filename=\"datos.xlsx\"\r\n"
                      "Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet\r\n\r\n").encode()
                   + body + f"\r\n--{boundary}--\r\n".encode())
        sprev, hprev, _ = hn.req("POST", f"/forms/{fid5}/participants/import", payload,
               {"Content-Type": f"multipart/form-data; boundary={boundary}"}, follow=True)
        mtok13 = re.search(r'name="roster_import_token" value="([^"]+)"', hprev)
        confirm13 = {"_token": hn.csrf(hprev), "roster_import_token": mtok13.group(1) if mtok13 else "",
                     "map_identificador": "0", "map_nombre": "1", "map_correo": "2", "map_telefono": "3",
                     "modo": "fusionar", "rosterQ": "", "rosterPage": "1", "rosterSort": "cedula"}
        hn.req("POST", f"/forms/{fid5}/participants/import/confirm", confirm13, follow=False)
        s13, h13, _ = hn.get(f"/forms/{fid5}/access")
        m13 = re.split(r'id="rosterImportReport"', h13, 1)
        txt13 = re.sub(r"<[^>]+>", " ", re.split(r'id="rosterCatalog"', m13[1], 1)[0]) if len(m13) > 1 else h13
        R.check("V13", s13 == 200 and ("fila" in txt13.lower() or "omit" in txt13.lower()) and "V-80" in h13, txt13[:200])
        s15, h15, _ = hn.get(f"/forms/{fid5}/access")
        R.check("V15", not re.search(r'id="rosterImportReport"[^>]*>[^<]*(fila|omit)', h15, re.I|re.S), "informe de un solo uso")

        # V14 — un archivo que no es .xlsx: aviso y sin informe de 0 filas.
        v.getreq(f"/forms/{fid4}/access"); tok = v.csrf()
        r14 = v.ctx.request.post(vf.BASE + f"/forms/{fid4}/participants/import",
                                 multipart={"_token": tok, "modo": "fusionar", "archivo": {"name": "malo.xlsx", "mimeType": "text/plain", "buffer": b"no soy un xlsx"}},
                                 headers={"X-Requested-With": "XMLHttpRequest"}, max_redirects=0)
        j14 = r14.json(); rep14 = (j14.get("html") or {}).get("rosterImportReport", "")
        R.check("V14", j14.get("ok") is False and "0 filas" not in rep14 and bool(j14.get("message")), f"ok={j14.get('ok')} msg={str(j14.get('message'))[:120]} rep={rep14[:120]}")

        R.na("V20", "Layout móvil no medible en Lightpanda; cubierto por CSS/@media")

finally:
    for fid in made:
        vf.mysql(f"DELETE FROM forms WHERE id={int(fid)}")
    vf.mysql("DELETE FROM forms WHERE title LIKE '[BT] V %'")
    print("BLOQUE V:", R.report())
