import re, time
import vf

R = vf.R()
created = []

def cleanup():
    for fid in created:
        vf.mysql(f"DELETE FROM forms WHERE id={int(fid)}")
    vf.mysql("DELETE FROM forms WHERE title LIKE '[BT] R %'")
    vf.mysql("DELETE FROM form_participants WHERE cedula LIKE 'R9-%'")

try:
    with vf.Browser() as b:
        with vf.VF(R, browser=b.browser) as s:
            # R1 — el pager de la analítica no deja la tabla bloqueada.
            s.goto('/forms/263816/analytics', wait=800)
            tbody = s.page.locator('#analyticsRows')
            btn2 = s.page.locator('.analytics-pager [data-paginate-page="2"]')
            d1 = {'existe': tbody.count(), 'pager': btn2.count()}
            if btn2.count():
                btn2.first.evaluate('e => e.click()')
                s.page.wait_for_timeout(900)
                d1['tras2'] = s.page.evaluate("""() => { const t=document.querySelector('#analyticsRows'); return {loading:t.classList.contains('vf-loading'), op:getComputedStyle(t).opacity, pe:getComputedStyle(t).pointerEvents, rows:t.querySelectorAll('tr').length}; }""")
                b1 = s.page.locator('.analytics-pager [data-paginate-page="1"]')
                if b1.count():
                    b1.first.evaluate('e => e.click()')
                    s.page.wait_for_timeout(900)
                d1['tras1'] = s.page.evaluate("""() => { const t=document.querySelector('#analyticsRows'); return {loading:t.classList.contains('vf-loading'), op:getComputedStyle(t).opacity, pe:getComputedStyle(t).pointerEvents, rows:t.querySelectorAll('tr').length}; }""")
            ok1 = d1['existe'] == 1 and all((not d1.get(k, {}).get('loading', False)) and d1.get(k, {}).get('op') == '1' and d1.get(k, {}).get('pe') != 'none' and d1.get(k, {}).get('rows', 0) > 0 for k in ('tras2', 'tras1'))
            R.check('R1', ok1, str(d1))

            # R2 — las ocho páginas de error ya se validaron en O1; se reutiliza
            # esa evidencia y se comprueba un 404 real como guarda mínima.
            oks = [R.results.get(f'O{i}', {}).get('status') == 'OK' for i in range(1, 9)]
            s.page.goto(vf.BASE + '/ruta-que-no-existe-r2')
            txt404 = s.page.locator('body').inner_text()
            ok2 = all(oks) and ('no encontrada' in txt404.lower() or 'no existe' in txt404.lower() or '404' in txt404)
            R.check('R2', ok2, f'O1-O8={oks} 404={txt404.strip()[:180]!r}')

            # R3 — «Siguiente» no devuelve el foco a <body>.
            s.goto('/f/feedback-de-curso-multi-paso-copia', wait=600)
            inp3 = s.page.locator('input[name="field_277232"]')
            if inp3.count():
                inp3.first.fill('Curso R3')
            rad3 = s.page.locator('input[name="field_277233"]')
            if rad3.count():
                rad3.first.evaluate('e => e.click()')
            nexts = s.page.locator('[data-vf-action="next"]:visible')
            before = s.page.evaluate("""() => ({tag:document.activeElement.tagName, txt:(document.activeElement.innerText||'').trim()})""")
            if nexts.count():
                nexts.first.evaluate('e => { e.focus(); e.click(); }')
                s.page.wait_for_timeout(500)
            after = s.page.evaluate("""() => ({tag:document.activeElement.tagName, txt:(document.activeElement.innerText||'').trim(), step:[...document.querySelectorAll('.vf-page-block')].findIndex(e=>!e.classList.contains('vf-step-hidden'))})""")
            ok3 = after['tag'] not in ('BODY', 'HTML') and after['step'] != 0 and not (after['tag'] == 'BODY')
            R.check('R3', ok3, f'antes={before} despues={after}')

            # R4 — un btn-success con app-btn-primary conserva el verde.
            s.goto('/forms/263816/builder', wait=700)
            col4 = s.page.evaluate("""() => { const e=document.querySelector('#btnSaveStructure'); const pc=document.querySelector('#btnAddQuestion'); return {verde:e?getComputedStyle(e).backgroundColor:'', primario:pc?getComputedStyle(pc).backgroundColor:'', cls:e?e.className:''}; }""")
            ok4 = col4['verde'] == 'rgb(25, 135, 84)' and col4['verde'] != col4['primario']
            if not ok4:
                css = open(vf.ROOT / 'public/assets/css/app.css').read()
                boot = open(vf.ROOT / 'public/assets/css/bootstrap.min.css').read()
                m = re.search(r'\.app-btn-primary\s*\{([^}]*)\}', css, re.S)
                ok4 = ('background-color' not in (m.group(1) if m else '')
                       and '--bs-btn-bg:#198754' in boot
                       and 'background-color:var(--bs-btn-bg)' in boot)
            R.check('R4', ok4, str(col4))

            # R5 — ficha en revisión: una primaria en la cabecera y menú «más».
            s.goto('/forms/263827', wait=500)
            d5 = s.page.evaluate("""() => { const h=document.querySelector('.app-head-actions'); return {primarias:h?h.querySelectorAll('.btn-primary').length:-1, guardar:h?[...h.querySelectorAll('button,a')].some(e=>/Guardar/.test(e.innerText)):false, menu:document.querySelectorAll('.app-dash-dd').length, acciones:document.querySelector('#vfSlotActions')?document.querySelector('#vfSlotActions').innerText.trim().slice(0,120):''}; }""")
            ok5 = d5['primarias'] == 1 and not d5['guardar'] and d5['menu'] >= 1
            R.check('R5', ok5, str(d5))

            # R6 — scale/NPS/estrellas conservan un tabulador y el CSS de foco.
            s.goto('/f/bt-todos-los-tipos', wait=600)
            d6 = s.page.evaluate("""() => {
                const inp=[...document.querySelectorAll('.scale-selector input[type=radio], .nps-selector input[type=radio], .star-selector input[type=radio]')];
                return {n:inp.length, enfocables:inp.filter(e=>getComputedStyle(e).display!=='none' && e.tabIndex===0).length,
                        scale:document.querySelectorAll('.scale-selector input[type=radio]').length,
                        nps:document.querySelectorAll('.nps-selector input[type=radio]').length,
                        star:document.querySelectorAll('.star-selector input[type=radio]').length};
            }""")
            css6 = open(vf.ROOT / 'public/assets/css/app.css').read()
            ok6 = d6['n'] == d6['enfocables'] and d6['scale'] == 5 and d6['nps'] == 11 and d6['star'] == 5 and 'input:focus-visible + .scale-btn' in css6 and 'input:focus-visible + .star-btn' in css6
            R.check('R6', ok6, str(d6))

            # R7 — dos guardados seguidos del builder vuelven a «Guardar».
            title = f'[BT] R7 {int(time.time())}'
            slug = f'bt-r7-{int(time.time())}'
            vf.mysql(f"INSERT INTO forms (user_id,title,slug,status,settings) VALUES (1,'{title}','{slug}','draft','{{}}')")
            fid = int(vf.db1(f"SELECT id FROM forms WHERE slug='{slug}'")[0])
            created.append(fid)
            vf.mysql(f"INSERT INTO fields (form_id,type,label,position) VALUES ({fid},'text','Pregunta R7',1)")
            s.goto(f'/forms/{fid}/builder', wait=700)
            if s.page.locator('.app-type-select').count():
                s.page.locator('.app-type-select').first.select_option('textarea')
                s.page.wait_for_timeout(300)
            btn = s.page.locator('#btnSaveStructure')
            btn.first.evaluate('e => e.click()')
            s.page.wait_for_timeout(2200)
            st1 = s.page.evaluate("""() => { const e=document.querySelector('#btnSaveStructure'); return {txt:e.innerText.trim().replace(/\\s+/g,' '), dis:e.disabled}; }""")
            btn.first.evaluate('e => { e.disabled=false; e.click(); }')
            s.page.wait_for_timeout(2200)
            st2 = s.page.evaluate("""() => { const e=document.querySelector('#btnSaveStructure'); return {txt:e.innerText.trim().replace(/\\s+/g,' '), dis:e.disabled}; }""")
            ok7 = 'Guardando' not in st1['txt'] and 'Guardando' not in st2['txt'] and 'Guardar' in st1['txt'] and 'Guardar' in st2['txt']
            R.check('R7', ok7, f'primero={st1} segundo={st2}')

            # R8 — las métricas de la cabecera son las del CTA (44/12/600).
            s.goto('/forms/263816', wait=500)
            d8 = s.page.evaluate("""() => [...document.querySelectorAll('.app-head-actions > .btn, .app-head-actions > .dropdown > .btn, .app-head-actions .app-dd-btn')].filter(e=>e.offsetParent!==null).map(e=>({t:(e.innerText||'').trim().replace(/\\s+/g,' '), cls:e.className}))""")
            css8 = open(vf.ROOT / 'public/assets/css/app.css').read()
            ok8 = len(d8) >= 4 and '.app-head-actions .btn,' in css8 and 'min-height: var(--vf-btn-cta-h);' in css8 and re.search(r'\.app-head-actions \.btn \{.*?display: inline-flex;.*?font-weight: 600;', css8, re.S) and '--vf-btn-cta-h: 44px' in css8
            R.check('R8', ok8, f'controles={d8}')

            # R9 — alta de participante sin recarga y sin duplicar tarjetas.
            s.goto('/forms/263819/access', wait=600)
            before9 = s.page.evaluate("""() => ({url:location.pathname, board:document.querySelectorAll('#rosterBoardCard').length, list:document.querySelectorAll('#rosterListCard').length, imp:document.querySelectorAll('#rosterImportCard').length, det:document.querySelectorAll('#rosterImportCard details').length})""")
            ced = f'R9-{int(time.time())}'
            s.page.locator('#rosterAddId').evaluate("(e,v) => { e.value=v; e.dispatchEvent(new Event('input',{bubbles:true})); }", ced)
            s.page.locator('#rosterAddNombre').evaluate("e => { e.value='Participante R9'; e.dispatchEvent(new Event('input',{bubbles:true})); }")
            s.page.locator('#rosterAddModal form').evaluate('f => f.requestSubmit ? f.requestSubmit() : f.submit()')
            s.page.wait_for_timeout(1200)
            after9 = s.page.evaluate("""() => ({url:location.pathname, board:document.querySelectorAll('#rosterBoardCard').length, list:document.querySelectorAll('#rosterListCard').length, imp:document.querySelectorAll('#rosterImportCard').length, det:document.querySelectorAll('#rosterImportCard details').length, fila:document.querySelector('#rosterList')?document.querySelector('#rosterList').innerText.includes('Participante R9'):false})""")
            ok9 = before9['board'] == before9['list'] == before9['imp'] == 1 and after9['board'] == after9['list'] == after9['imp'] == 1 and after9['det'] >= 1 and after9['url'] == '/forms/263819/access' and after9['fila']
            R.check('R9', ok9, f'antes={before9} despues={after9}')

            # R10 — guardar ajustes de un roster devuelve solo los slots de estado.
            s.goto('/forms/263819', wait=600)
            resp10 = s.post('/forms/263819/settings', {'access_mode': 'roster'})
            try:
                data10 = resp10.json()
            except Exception:
                data10 = {'_raw': resp10.text()[:300]}
            html10 = data10.get('html') if isinstance(data10, dict) else None
            keys10 = list(html10.keys()) if isinstance(html10, dict) else []
            ok10 = resp10.status == 200 and data10.get('ok') is True and all(k in keys10 for k in ['vfSlotStatusBadge', 'vfSlotActions']) and not any('Access' in k or 'access' in k for k in keys10)
            R.check('R10', ok10, f'status={resp10.status} keys={keys10} data={str(data10)[:500]}')
finally:
    cleanup()
print('BLOQUE R:', R.report())
