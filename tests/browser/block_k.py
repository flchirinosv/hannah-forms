import csv, html, io, json, re, subprocess, sys, uuid
from pathlib import Path
sys.path.insert(0, 'tests/browser')
import vf
R = vf.R()

def sq(v):
    return "'" + str(v).replace("\\", "\\\\").replace("'", "''") + "'"

def mysql_stdin(sql):
    p = subprocess.run(['mysql','-h127.0.0.1','-umariadb',f'-p{vf.dbpass()}','vanilla_forms'], input=sql, capture_output=True, text=True)
    if p.returncode != 0: raise RuntimeError(p.stderr)

def create_form(title, slug, fields, owner=2, settings_from=263817):
    vf.mysql(f"delete from forms where title={sq(title)}")
    settings = vf.db1(f"select settings from forms where id={settings_from}")[0]
    vf.mysql(f"insert into forms (user_id,title,description,slug,status,settings) values ({owner},{sq(title)},'',{sq(slug)},'published',{sq(settings)})")
    fid = int(vf.db1(f"select max(id) from forms where title={sq(title)}")[0])
    ids = {}
    for f in fields:
        f = dict(f); key = f.pop('key')
        cols = [('form_id',fid),('type',f.pop('type')),('label',f.pop('label')),('placeholder',''),('help_text',''),
                ('is_required',int(f.pop('required',False))),('options',json.dumps(f.pop('options',[]),ensure_ascii=False)),
                ('validations',json.dumps(f.pop('validations',{}),ensure_ascii=False)),
                ('position',int(f.pop('position',1))),('parent_id',None),('logic',None),
                ('is_pii',int(f.pop('pii',False))),('show_header',1)]
        def lit(v):
            if v is None: return 'NULL'
            return str(v) if isinstance(v,int) else sq(v)
        vf.mysql("insert into fields (%s) values (%s)" % (','.join(c for c,_ in cols), ','.join(lit(v) for _,v in cols)))
        ids[key] = int(vf.db1(f"select max(id) from fields where form_id={fid}")[0])
    return fid, ids

def token(html):
    m = re.search(r'name="_token"\s+value="([^"]+)"', html)
    return m.group(1) if m else ''

def clear_export(user_id=None):
    if user_id is None: vf.mysql("delete from rate_limits where scope='dash:export'")
    else: vf.mysql(f"delete from rate_limits where scope='dash:export' and identifier='{user_id}'")

try:
    long_label = 'Una etiqueta de pregunta extremadamente larga que no debe empujar el conmutador de vista a otra fila ni salirse de su tarjeta en la analítica'
    fid, ids = create_form('[BT] K analitica', 'bt-k-analitica', [
        {'key':'SEC','type':'section','label':'Sección con acentos y eñes','position':1},
        {'key':'OPEN','type':'text','label':'Comentario libre','position':2},
        {'key':'PII','type':'text','label':'Nombre secreto','pii':True,'position':3},
        {'key':'NUM','type':'number','label':'Número de satisfacción','position':4},
        {'key':'NPS','type':'nps','label':'Recomendación NPS','position':5},
        {'key':'RAD','type':'radio','label':'Opción única','options':[{'value':'Uno'},{'value':'Dos'}],'position':6},
        {'key':'CHK','type':'checkbox','label':'Casillas','options':[{'value':'Uno'},{'value':'Dos'},{'value':'Tres'}],'position':7},
        {'key':'MAT','type':'matrix','label':'Matriz','options':[{'value':'Fila 1','group':'rows'},{'value':'Fila 2','group':'rows'},{'value':'Malo','group':'cols'},{'value':'Bueno','group':'cols'}],'position':8},
        {'key':'RANK','type':'ranking','label':'Ranking','options':[{'value':'Uno'},{'value':'Dos'},{'value':'Tres'}],'position':9},
        {'key':'URL','type':'url','label':'Sitio web','position':10},
        {'key':'TEL','type':'tel','label':'Teléfono','position':11},
        {'key':'TIME','type':'time','label':'Hora','position':12},
        {'key':'DATE','type':'date','label':'Fecha','position':13},
        {'key':'DT','type':'datetime-local','label':'Fecha y hora','position':14},
        {'key':'FILE','type':'file','label':'Archivo','position':15},
        {'key':'LONG','type':'radio','label':long_label,'options':[{'value':'Sí'},{'value':'No'}],'position':16},
        {'key':'NOISE','type':'text','label':'Texto sin marcar','position':17},
    ], owner=2)

    subs = []
    nums = [1,2,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,20]
    npss = [0,6,7,8,9,10,0,6,7,8,9,10,0,6,7,8,9,10,7,9]
    for n in range(20):
        day = '2026-10-20' if n < 10 else '2026-10-21'
        stamp = f'{day} 10:{n:02d}:00'
        vals = {
            'OPEN': f'Comentario número {n} con ñ y café',
            'PII': f'Persona Secreta {n}',
            'NUM': str(nums[n]),
            'NPS': str(npss[n]),
            'RAD': 'Uno' if n % 2 == 0 else 'Dos',
            'CHK': json.dumps(['Uno','Dos'] if n % 3 == 0 else (['Tres'] if n % 3 == 1 else ['Dos','Tres'])),
            'MAT': json.dumps({'Fila 1': 'Malo' if n % 2 == 0 else 'Bueno', 'Fila 2': 'Bueno' if n % 3 == 0 else ''}),
            'RANK': json.dumps({'Uno': str((n % 3) + 1), 'Dos': str(((n + 1) % 3) + 1), 'Tres': str(((n + 2) % 3) + 1)}),
            'URL': 'https://ejemplo.com/año',
            'TEL': '+58 412-1234567',
            'TIME': '10:30',
            'DATE': day,
            'DT': f'{day}T10:30',
            'FILE': json.dumps([{'stored':'2026/10/abcdef12.pdf','name':'informe-ñ.pdf','size':2048,'mime':'application/pdf'}]),
            'LONG': 'Sí' if n % 2 == 0 else 'No',
            'NOISE': f'Ruido {n}',
        }
        cols = ['form_id','ip_address','user_agent','finished_at','public_token']
        sub = f"({fid},'192.168.1.{n+1}','BT K','{stamp}','{uuid.uuid4().hex}')"
        subs.append(sub)
        vals['_stamp'] = stamp
        # store for insertion below
        subs[-1] = (sub, vals)
    sub_sql = 'insert into submissions (form_id,ip_address,user_agent,finished_at,public_token) values ' + ','.join(x[0] for x in subs) + ';'
    mysql_stdin(sub_sql)
    rows = vf.mysql(f'select id,finished_at from submissions where form_id={fid} order by finished_at,id')
    ans = []
    for (sid, _), (_, vals) in zip(rows, subs):
        for key, val in vals.items():
            if key == '_stamp': continue
            ans.append(f"({sid},{ids[key]},{sq(val)})")
    mysql_stdin('insert into answers (submission_id,field_id,value) values ' + ','.join(ans) + ';')

    admin = vf.Http('admin@demo.com')
    user = vf.Http('user@demo.com')
    base = f'/forms/{fid}/analytics'
    s, page, _ = admin.get(base)
    Path('/tmp/ana-k.html').write_text(page)
    doc = page

    # K1 analytics opens with daily series, distributions and detail
    k1 = s == 200 and 'data-chart=' in doc and 'id="campo-%d"' % ids['NUM'] in doc and 'Detalle de respuestas' in doc and 'id="analyticsRows"' in doc
    R.check('K1', k1, f'GET {s}; chart={"data-chart=" in doc}, numero={"id=\"campo-%d\"" % ids["NUM"] in doc}, detalle={"analyticsRows" in doc}')

    # ------------------------------------------------------------------
    # Helpers de lectura del HTML/JSON ya renderizado
    # ------------------------------------------------------------------
    def card_html(key):
        marker = f'id="campo-{ids[key]}"'
        i = doc.find(marker)
        if i < 0:
            return ''
        m = re.search(r'<div class="col-md-6[^"]*" id="campo-\d+">', doc[i + len(marker):])
        j = i + len(marker) + m.start() if m else len(doc)
        return doc[i:j]

    def open_values(fragment):
        return re.findall(r'<div class="small app-truncate-2" title="[^"]*">([^<]*)</div>', fragment)

    def open_card(document, field_id):
        marker = f'id="campo-{field_id}"'
        i = document.find(marker)
        if i < 0:
            return ''
        m = re.search(r'<div class="col-md-6[^"]*" id="campo-\d+">', document[i + len(marker):])
        j = i + len(marker) + m.start() if m else len(document)
        return document[i:j]

    def analytics_json(document):
        m = re.search(r'id="analyticsApp"[^>]*data-chart="([^"]*)"', document)
        return json.loads(html.unescape(m.group(1))) if m else {'fields': []}

    def chart_field(document, key):
        data = analytics_json(document)
        for f in data.get('fields', []):
            if int(f.get('id', 0)) == ids[key]:
                return f, data
        return {}, data

    # K2/K3/K4 — la tabla de detalle cambia de página y sigue siendo usable.
    with vf.Browser() as browser:
        with browser.session(R, 'admin@demo.com') as s:
            s.goto(base, 1400)
            first1 = s.page.locator('#analyticsRows td').first.inner_text().strip()
            s.jsclick('.analytics-pager [data-paginate-page="2"]')
            s.page.wait_for_timeout(900)
            first2 = s.page.locator('#analyticsRows td').first.inner_text().strip()
            cls2 = s.page.locator('#analyticsRows').get_attribute('class') or ''
            links2 = s.page.locator('#analyticsRows a[href*="/responses/"]').count()
            href2 = s.page.locator('#analyticsRows a[href*="/responses/"]').first.get_attribute('href') if links2 else ''
            opened2 = s.ctx.request.get(vf.BASE + href2) if href2 else None
            R.check('K2', first1 == '1' and first2 == '16' and 'vf-loading' not in cls2 and links2 > 0 and opened2 is not None and opened2.status == 200,
                    f'antes #{first1}; pagina2 #{first2}; clase={cls2!r}; enlaces={links2}; abrir={getattr(opened2, "status", None)}')

            s.jsclick('.analytics-pager [data-paginate-page="1"]')
            s.page.wait_for_timeout(800)
            back1 = s.page.locator('#analyticsRows td').first.inner_text().strip()
            s.jsclick('.analytics-pager [data-paginate-page="2"]')
            s.page.wait_for_timeout(800)
            back2 = s.page.locator('#analyticsRows td').first.inner_text().strip()
            cls3 = s.page.locator('#analyticsRows').get_attribute('class') or ''
            R.check('K3', back1 == '1' and back2 == '16' and 'vf-loading' not in cls3,
                    f'volver #{back1}; segunda vez #{back2}; clase={cls3!r}')

            s.goto(base, 1400)
            s.page.route('**/analytics/rows*', lambda route: route.abort())
            s.jsclick('.analytics-pager [data-paginate-page="2"]')
            s.page.wait_for_timeout(900)
            avisos = s.page.evaluate("""() => [...document.querySelectorAll('.alert, [role="alert"], #vfToast .toast, .invalid-feedback')].map(x => x.textContent.trim()).filter(Boolean)""")
            cls4 = s.page.locator('#analyticsRows').get_attribute('class') or ''
            links4 = s.page.locator('#analyticsRows a[href*="/responses/"]').count()
            s.page.unroute('**/analytics/rows*')
            R.check('K4', bool(avisos) and 'vf-loading' not in cls4 and links4 > 0,
                    f'avisos={avisos}; clase={cls4!r}; enlaces={links4}')

            # K12/K13 — Ver abre la respuesta y la numeración de página es la del listado.
            link = re.search(fr'href="(/forms/{fid}/responses/\d+)"', doc)
            href = link.group(1) if link else ''
            detail = admin.get(href) if href else (0, '', '')
            first_cell = re.search(r'<td class="text-muted" data-label="#">([^<]*)</td>', doc)
            R.check('K12', bool(href) and detail[0] == 200 and first_cell is not None and first_cell.group(1).strip() == '1',
                    f'href={href}; GET={detail[0]}; #={first_cell.group(1).strip() if first_cell else None}')

            rows1 = json.loads(admin.get(f'/forms/{fid}/analytics/rows?page=1')[1]).get('html', '')
            rows2 = json.loads(admin.get(f'/forms/{fid}/analytics/rows?page=2')[1]).get('html', '')
            n1 = re.search(r'<td class="text-muted" data-label="#">([^<]*)</td>', rows1)
            n2 = re.search(r'<td class="text-muted" data-label="#">([^<]*)</td>', rows2)
            R.check('K13', n1 is not None and n2 is not None and n1.group(1).strip() == '1' and n2.group(1).strip() == '16',
                    f'pagina1 #{n1.group(1).strip() if n1 else None}; pagina2 #{n2.group(1).strip() if n2 else None}')

            # K20 — opciones: barras, anillo, tabla y vuelta a barras sin canvas vacío.
            s.goto(base, 1500)
            fchoice = f'#campo-{ids["RAD"]}'
            s.jsclick(fchoice + ' [data-vf-view="doughnut"]')
            s.page.wait_for_timeout(250)
            t_dough = s.page.evaluate("""sel => { const c = document.querySelector(sel + ' canvas'); const ch = Chart.getChart(c); return ch && ch.config.type; }""", fchoice)
            s.jsclick(fchoice + ' [data-vf-view="table"]')
            s.page.wait_for_timeout(250)
            table_visible = s.page.locator(fchoice + ' .vf-choice-table').is_visible()
            s.jsclick(fchoice + ' [data-vf-view="bars"]')
            s.page.wait_for_timeout(250)
            bars = s.page.evaluate("""sel => { const c = document.querySelector(sel + ' canvas'); const ch = Chart.getChart(c); return ch && {type: ch.config.type, axis: ch.options.indexAxis, labels: ch.data.labels}; }""", fchoice)
            R.check('K20', t_dough == 'doughnut' and table_visible and bars and bars['type'] == 'bar' and bars['axis'] == 'y'
                    and all(re.search(r'· \d+ \(\d+%\)$', x) for x in bars['labels']),
                    f'anillo={t_dough}; tabla={table_visible}; barras={bars}')

            # K22 — ranking: tabla por defecto y barras ordenadas por promedio.
            s.goto(base, 1500)
            frank = f'#campo-{ids["RANK"]}'
            rtable = s.page.locator(frank + ' .app-stat-ranking').inner_text()
            s.jsclick(frank + ' [data-vf-view="bars"]')
            s.page.wait_for_timeout(250)
            rb = s.page.evaluate("""sel => { const c = document.querySelector(sel + ' canvas'); const ch = Chart.getChart(c); return ch && {labels: ch.data.labels, data: ch.data.datasets[0].data, tip: ch.options.plugins.tooltip.callbacks.label.toString()}; }""", frank)
            R.check('K22', 'Promedio' in rtable and 'Veces' in rtable and '1.95' in rtable and '2.05' in rtable
                    and rb and rb['labels'] == ['Uno', 'Tres', 'Dos'] and rb['data'] == [1.95, 2, 2.05]
                    and ('Orden medio' in rb['tip'] and 'respuestas' in rb['tip']),
                    f'tabla={rtable.splitlines()[:3]}; barras={rb}')

            # K25 — índice de escritorio por anclas e índice móvil con JS.
            anchor_count = len(re.findall(r'<a class="nav-link px-0 py-1 small text-truncate" href="#campo-\d+"', doc))
            select_label = 'Ir a una pregunta' in doc and 'id="analyticsIndexSelect"' in doc
            s.page.evaluate("""() => { const sel = document.getElementById('analyticsIndexSelect'); window.__vfScrolled = null; const target = document.getElementById('campo-%d'); target.scrollIntoView = function () { window.__vfScrolled = this.id; }; sel.value = 'campo-%d'; sel.dispatchEvent(new Event('change', {bubbles: true})); }""" % (ids['NPS'], ids['NPS']))
            mobile_jump = s.page.evaluate("""() => ({target: window.__vfScrolled, value: document.getElementById('analyticsIndexSelect').value})""")
            R.check('K25', anchor_count > 3 and select_label and mobile_jump.get('target') == f'campo-{ids["NPS"]}' and mobile_jump.get('value') == '',
                    f'anclas={anchor_count}; selector={select_label}; salto={mobile_jump}')

            # K28 — las dos tarjetas numéricas aguantan cambios de vista sin canvas vacío.
            s.goto(base, 1500)
            errores = []
            s.page.on('console', lambda m: errores.append(m.text) if m.type == 'error' else None)
            ok28 = True
            detalle28 = []
            for key in ('NUM', 'NPS'):
                sel = f'#campo-{ids[key]}'
                for view in ('summary', 'histogram', 'summary', 'histogram'):
                    s.jsclick(f'{sel} [data-vf-view="{view}"]')
                    s.page.wait_for_timeout(180)
                    if view == 'histogram':
                        alive = s.page.evaluate("""sel => { const c = document.querySelector(sel + ' canvas'); return !!(c && Chart.getChart(c)); }""", sel)
                        ok28 = ok28 and alive
                        detalle28.append(f'{key}:{view}={alive}')
            R.check('K28', ok28 and not errores, '; '.join(detalle28) + f'; errores={errores[:3]}')

            # K33 — Resumen oculta el histograma y muestra las mini-stats/insignias.
            s.goto(base, 1500)
            fnps = f'#campo-{ids["NPS"]}'
            s.jsclick(fnps + ' [data-vf-view="summary"]')
            s.page.wait_for_timeout(220)
            state33 = s.page.evaluate("""sel => { const c = document.querySelector(sel); const box = c.querySelector('[data-vf-summary-box]'); const chart = c.querySelector('[data-vf-chart-box]'); return {summary: !box.classList.contains('d-none'), chart: !chart.classList.contains('d-none'), badges: [...c.querySelectorAll('.badge')].map(x => x.textContent.trim())}; }""", fnps)
            s.jsclick(fnps + ' [data-vf-view="histogram"]')
            s.page.wait_for_timeout(220)
            state33b = s.page.evaluate("""sel => { const c = document.querySelector(sel); const box = c.querySelector('[data-vf-summary-box]'); const chart = c.querySelector('[data-vf-chart-box]'); return {summary: !box.classList.contains('d-none'), chart: !box.classList.contains('d-none') || !chart.classList.contains('d-none'), canvas: !!Chart.getChart(c.querySelector('canvas'))}; }""", fnps)
            R.check('K33', state33['summary'] and not state33['chart'] and len(state33['badges']) == 3
                    and any('Detractores' in b for b in state33['badges']) and any('Pasivos' in b for b in state33['badges']) and any('Promotores' in b for b in state33['badges'])
                    and not state33b['summary'] and state33b['canvas'],
                    f'resumen={state33}; histograma={state33b}')
            s.goto(base, 1500)
            nps_colors = s.page.evaluate("""id => { const c = document.querySelector(`canvas[data-field=\"${id}\"]`); const ch = Chart.getChart(c); return ch ? ch.data.datasets[0].backgroundColor : []; }""", ids['NPS'])

    # K5-K9/K11 — exports por HTTP con headers reales.
    with vf.Browser() as browser:
        with browser.session(R, 'admin@demo.com') as s:
            csv_resp = s.ctx.request.get(vf.BASE + f'/forms/{fid}/export.csv')
            csv_body = csv_resp.text()
            disposition = csv_resp.headers.get('content-disposition', '')
            content_type = csv_resp.headers.get('content-type', '')
            csv_rows = list(csv.reader(io.StringIO(csv_body)))
            header = csv_rows[0] if csv_rows else []
            R.check('K5', csv_resp.status == 200 and f'filename="bt-k-analitica.csv"' in disposition and header[:2] == ['#', 'Fecha']
                    and len(header) == 18 and 'Sección con acentos y eñes' not in header,
                    f'status={csv_resp.status}; disposition={disposition}; content-type={content_type}; columnas={len(header)}')

            data_rows = csv_rows[1:]
            col = {name: i for i, name in enumerate(header)}
            first = data_rows[0] if data_rows else []
            matrix_cell = first[col['Matriz']] if first and 'Matriz' in col else ''
            rank_cell = first[col['Ranking']] if first and 'Ranking' in col else ''
            chk_cell = first[col['Casillas']] if first and 'Casillas' in col else ''
            R.check('K6', len(data_rows) == 20 and 'Fila 1: Bueno' in matrix_cell and 'Fila 2: ' in matrix_cell
                    and rank_cell == 'Uno: 2; Dos: 3; Tres: 1' and '[' not in chk_cell and chk_cell == 'Tres',
                    f'filas={len(data_rows)}; matriz={matrix_cell!r}; ranking={rank_cell!r}; casillas={chk_cell!r}')

            f7 = s.ctx.request.get(vf.BASE + f'/forms/{fid}/export.csv?from=2026-10-21&to=2026-10-21').text()
            rows7 = list(csv.reader(io.StringIO(f7)))
            dates7 = [r[1] for r in rows7[1:] if len(r) > 1]
            R.check('K7', len(rows7) - 1 == 10 and dates7 and all(d.startswith('2026-10-21') for d in dates7),
                    f'filas={len(rows7)-1}; fechas={sorted(set(dates7))}')

            j8 = admin.get(f'/forms/{fid}/export.json')
            payload = json.loads(j8[1])
            subs8 = payload.get('submissions', [])
            R.check('K8', j8[0] == 200 and set(['form', 'total', 'submissions']).issubset(payload) and payload.get('total') == 20
                    and len(subs8) == 20 and {'id', 'finished_at', 'ip_address', 'user_agent', 'answers'}.issubset(subs8[0]),
                    f'status={j8[0]}; claves={sorted(payload)}; total={payload.get("total")}; submission={sorted(subs8[0]) if subs8 else []}')

            j9 = admin.get(f'/forms/{fid}/export-structure.json')
            est = json.loads(j9[1])
            fields9 = est.get('fields', [])
            R.check('K9', j9[0] == 200 and {'app', 'version', 'key', 'name', 'description', 'settings', 'fields'}.issubset(est)
                    and len(fields9) == 17 and {'ref', 'type', 'label'}.issubset(fields9[0]) and isinstance(est.get('settings'), dict),
                    f'status={j9[0]}; claves={sorted(est)}; campos={len(fields9)}')

            R.check('K11', not csv_body.startswith('\ufeff') and 'ñ' in csv_body and 'café' in csv_body and 'año' in csv_body and '\ufffd' not in csv_body,
                    f'BOM={csv_body.startswith(chr(0xfeff))}; ñ={"ñ" in csv_body}; café={"café" in csv_body}; año={"año" in csv_body}')

    # K10 — el límite de exportación es por usuario (120/hora).
    clear_export(2)
    with vf.Browser() as browser10:
        with browser10.session(R, 'user@demo.com') as u:
            statuses10 = [u.ctx.request.get(vf.BASE + f'/forms/{fid}/export.csv').status for _ in range(121)]
    clear_export(2)
    R.check('K10', statuses10[:120] == [200] * 120 and statuses10[120] == 429,
            f'200={statuses10.count(200)}; ultimo={statuses10[-1]}')

    # K14-K16 — preguntas abiertas y PII.
    admin_doc = admin.get(base)[1]
    user_doc = user.get(base)[1]
    card14 = open_card(admin_doc, ids['OPEN'])
    vals14 = open_values(card14)
    fechas14 = re.findall(r'(\d{2}/\d{2}/\d{4} \d{2}:\d{2})', card14)
    links14 = re.findall(fr'href="(/forms/{fid}/responses/\d+)"', card14)
    opened14 = admin.get(links14[0])[0] if links14 else 0
    R.check('K14', '20 respuestas' in re.sub(r'\s+', ' ', card14) and vals14 == [f'Comentario número {n} con ñ y café' for n in range(19, 14, -1)]
            and len(fechas14) >= 5 and len(links14) == 5 and opened14 == 200,
            f'total={"20 respuestas" in card14}; valores={vals14}; fechas={len(fechas14)}; ver={len(links14)}; abrir={opened14}')

    card15a = open_card(admin.get(base + '?from=2026-10-21&to=2026-10-21')[1], ids['OPEN'])
    card15b = open_card(admin.get(base + '?from=2026-10-20&to=2026-10-20')[1], ids['OPEN'])
    R.check('K15', open_values(card15a) == [f'Comentario número {n} con ñ y café' for n in range(19, 14, -1)]
            and open_values(card15b) == [f'Comentario número {n} con ñ y café' for n in range(9, 4, -1)]
            and '10 respuestas' in re.sub(r'\s+', ' ', card15a) and '10 respuestas' in re.sub(r'\s+', ' ', card15b),
            f'21={open_values(card15a)}; 20={open_values(card15b)}')

    card16a = open_card(admin_doc, ids['PII'])
    card16b = open_card(user_doc, ids['PII'])
    R.check('K16', '[oculto]' not in card16a and 'Persona Secreta' in card16a and '[oculto]' in card16b and 'Persona Secreta' not in card16b,
            f'admin_oculto={"[oculto]" in card16a}; user_oculto={card16b.count("[oculto]")}')

    # K17 — los tipos abiertos que antes no se pintaban.
    tipos17 = {
        'URL': 'https://ejemplo.com/año', 'TEL': '+58 412-1234567', 'TIME': '10:30',
        'DATE': '2026-10-21', 'DT': '2026-10-21T10:30', 'FILE': 'informe-ñ.pdf (2,0 KB)',
    }
    presentes = {k: (v in open_card(admin_doc, ids[k])) for k, v in tipos17.items()}
    file_json_present = '{"stored"' in open_card(admin_doc, ids['FILE'])
    R.check('K17', all(presentes.values()) and not file_json_present,
            f'presentes={presentes}; json_archivo={file_json_present}')

    # K18/K19 — histogramas y escala NPS.
    nfield, _ = chart_field(admin_doc, 'NUM')
    nvals = nfield.get('values', {})
    nlabels = [x.get('value') for x in nfield.get('dist', [])]
    ndata = [int(x.get('c', 0)) for x in nfield.get('dist', [])]
    card18 = card_html('NUM')
    active18 = re.search(r'<button[^>]*class="[^"]*\bactive\b[^"]*"[^>]*data-vf-view="histogram"', card18)
    R.check('K18', nvals.get('answer_count') == 20 and float(nvals.get('avg_value') or 0) == 9.65
            and nlabels == sorted(nlabels, key=int) and sum(ndata) == 20 and active18 is not None,
            f'answer_count={nvals.get("answer_count")}; avg={nvals.get("avg_value")}; dist={list(zip(nlabels, ndata))}; suma={sum(ndata)}; histograma_activo={active18 is not None}')

    npfield, _ = chart_field(admin_doc, 'NPS')
    npdist = npfield.get('dist', [])
    nplabels = [str(x.get('value')) for x in npdist]
    npdata = [int(x.get('c', 0)) for x in npdist]
    R.check('K19', nplabels == [str(i) for i in range(11)] and npdata[0] == 3 and npdata[1] == 0
            and len(nps_colors) == 11 and nps_colors[:7] == ['#ef4444'] * 7
            and nps_colors[7:9] == ['#f59e0b'] * 2 and nps_colors[9:] == ['#22c55e'] * 2,
            f'labels={nplabels}; data={npdata}; colores={nps_colors}')

    # K21 — totales de la matriz.
    card21 = re.sub(r'\s+', ' ', re.sub('<[^>]+>', ' ', card_html('MAT')))
    R.check('K21', 'Total' in card21 and 'Fila 1 10 10 20' in card21 and 'Fila 2 0 7 7' in card21
            and 'Total 10 17 27' in card21 and 'rgba(99,102,241,' in card_html('MAT'),
            f'texto={card21[:320]}')

    # K23/K24 — cabecera/filtros y rótulos accesibles.
    phead = admin_doc.find('app-page-head')
    pactions = admin_doc.find('app-head-actions')
    pfilters = admin_doc.find('analytics-filters')
    R.check('K23', 0 <= phead < pactions < pfilters and 'Exportar CSV' in admin_doc[phead:pfilters]
            and 'analytics-filters d-flex flex-wrap' in admin_doc[pfilters:pfilters + 120],
            f'posiciones={phead},{pactions},{pfilters}')

    label_from = re.search(r'<label class="visually-hidden" for="analyticsFrom">([^<]+)</label>', admin_doc)
    label_to = re.search(r'<label class="visually-hidden" for="analyticsTo">([^<]+)</label>', admin_doc)
    R.check('K24', bool(label_from and label_to) and label_from.group(1) == 'Desde' and label_to.group(1) == 'Hasta',
            f'desde={label_from.group(1) if label_from else None}; hasta={label_to.group(1) if label_to else None}')

    # K26 — dos vacíos distintos.
    empty_doc = admin.get('/forms/263823/analytics')[1]
    filtered_doc = admin.get(base + '?from=2020-01-01&to=2020-01-02')[1]
    R.check('K26', '<h3 class="h5 fw-bold mb-1">Sin respuestas todavía</h3>' in empty_doc
            and 'Ir a compartir' in empty_doc and 'vf-analytics-field' not in empty_doc
            and 'Sin respuestas para este filtro.' in filtered_doc
            and '<h3 class="h5 fw-bold mb-1">Sin respuestas todavía</h3>' not in filtered_doc,
            f'vacio={"Sin respuestas todavía" in empty_doc}; cta={"Ir a compartir" in empty_doc}; filtrado={"Sin respuestas para este filtro." in filtered_doc}')

    # K27 — tasa de respuesta del roster y su independencia del filtro.
    vf.mysql("UPDATE form_participants SET responded_at=NOW() WHERE form_id=263819 AND identifier_value IN ('BT-001','BT-002')")
    roster_doc = admin.get('/forms/263819/analytics')[1]
    roster_filtered = admin.get('/forms/263819/analytics?from=2020-01-01&to=2020-01-02')[1]
    vf.mysql("UPDATE form_participants SET responded_at=NULL WHERE form_id=263819 AND identifier_value IN ('BT-001','BT-002')")
    R.check('K27', 'Tasa de respuesta' in roster_doc and '2 de 3 (67%)' in roster_doc
            and 'Tasa de respuesta' in roster_filtered and '2 de 3 (67%)' in roster_filtered
            and 'Tasa de respuesta' not in admin_doc,
            f'roster={"2 de 3 (67%)" in roster_doc}; filtrado={"2 de 3 (67%)" in roster_filtered}; normal={"Tasa de respuesta" in admin_doc}')

    app_css = Path('public/assets/css/app.css').read_text()
    boot_css = Path('public/assets/css/bootstrap.min.css').read_text()

    # K29 — la etiqueta larga no empuja el conmutador.
    card29 = card_html('LONG')
    head29 = re.search(r'<div class="vf-card-head[^"]*">(.*?)</div>\s*<div class="vf-card-actions">', card29, re.S)
    R.check('K29', 'vf-card-title' in card29 and 'vf-card-actions' in card29 and 'data-vf-view="bars"' in card29
            and long_label in card29 and head29 is not None
            and re.search(r'\.vf-card-head\s*>\s*\.vf-card-title\s*\{[^}]*flex:\s*1 1 auto;[^}]*min-width:\s*0;', app_css, re.S) is not None
            and re.search(r'\.vf-card-head\s*>\s*\.vf-card-actions\s*\{[^}]*flex:\s*0 0 auto;', app_css, re.S) is not None,
            f'titulo={"vf-card-title" in card29}; acciones={"vf-card-actions" in card29}; css_titulo={bool(re.search(r"vf-card-title", app_css))}')

    # K30 — índice con truncado y title completo.
    anchors30 = re.findall(r'<a class="nav-link[^"]*" href="#campo-\d+" title="([^"]*)">', doc)
    R.check('K30', long_label in anchors30 and all(anchors30)
            and '.app-analytics-index .nav { min-width: 0; }' in app_css
            and re.search(r'\.app-analytics-index \.nav-link\s*\{[^}]*min-width:\s*0;[^}]*overflow:\s*hidden;[^}]*text-overflow:\s*ellipsis;', app_css, re.S) is not None,
            f'anclas={len(anchors30)}; etiqueta_larga={long_label in anchors30}')

    # K31 — cabecera/primera columna fijas y modo tarjeta en móvil.
    first_row31 = re.search(r'<tr class="paginate-item">(.*?)</tr>', doc, re.S)
    labels31 = re.findall(r'<td[^>]*data-label="([^"]*)"', first_row31.group(1)) if first_row31 else []
    R.check('K31', 'id="analyticsRows"' in doc and '.app-detail-table th {\n    position: sticky;\n    top: 0;' in app_css
            and re.search(r'\.app-detail-table th:first-child,\s*\.app-detail-table td:first-child\s*\{[^}]*position:\s*sticky;\s*left:\s*0;', app_css, re.S) is not None
            and re.search(r'\.app-detail-table tbody tr:nth-child\(even\)\s*\{[^}]*background:\s*#fbfcfe;', app_css) is not None
            and re.search(r'\.app-detail-table td::before\s*\{[^}]*content:\s*attr\(data-label\);', app_css, re.S) is not None
            and len(labels31) >= 17 and labels31[0] == '#',
            f'rotulos={len(labels31)}; primero={labels31[0] if labels31 else None}')

    # K32 — chips en la tabla de detalle y PII enmascarada sin permiso.
    row32 = re.search(r'<tr class="paginate-item">(.*?)</tr>', user_doc, re.S)
    row32 = row32.group(1) if row32 else ''
    pos32 = re.findall(r'<span class="vf-chip-pos">(\d+)</span>', row32)
    R.check('K32', row32.count('vf-answer-chips') >= 1 and 'vf-answer-matrix' in row32 and 'vf-answer-ranking' in row32
            and 'vf-chip-matrix' in row32 and pos32 == ['1', '2', '3'] and '[oculto]' in row32
            and '{&quot;' not in row32 and 'Persona Secreta' not in row32,
            f'chips={row32.count("vf-answer-chips")}; matriz={"vf-answer-matrix" in row32}; ranking={pos32}; pii={"[oculto]" in row32}')

    # K33 — además de alternar, las clases de color deben existir de verdad.
    R.check('K33', state33['summary'] and not state33['chart'] and len(state33['badges']) == 3
            and any('Detractores' in b for b in state33['badges']) and any('Pasivos' in b for b in state33['badges']) and any('Promotores' in b for b in state33['badges'])
            and not state33b['summary'] and state33b['canvas']
            and all(c in boot_css for c in ['.bg-danger-subtle', '.text-danger-emphasis', '.bg-secondary-subtle', '.text-secondary-emphasis', '.bg-success-subtle', '.text-success-emphasis'])
            and all(f'badge bg-{c}-subtle text-{c}-emphasis border border-{c}-subtle' in admin_doc for c in ['danger', 'secondary', 'success'])
            and 'data-vf-summary-box' in admin_doc and 'Promedio' in admin_doc and 'Mínimo' in admin_doc and 'Máximo' in admin_doc,
            f'resumen={state33["summary"]}; canvas={state33b["canvas"]}; insignias={state33["badges"]}')

    # K34 — detalle con los mismos chips que la analítica.
    user_link = re.search(fr'href="(/forms/{fid}/responses/\d+)"', user_doc)
    detail34 = user.get(user_link.group(1))[1] if user_link else ''
    R.check('K34', bool(detail34) and 'vf-answer-chips' in detail34 and 'vf-answer-matrix' in detail34
            and 'vf-answer-ranking' in detail34 and '[oculto]' in detail34
            and 'Comentario número 19 con ñ y café' in detail34 and '{&quot;' not in detail34
            and '{&quot;Fila' not in detail34 and '{"Fila' not in detail34,
            f'chips={detail34.count("vf-answer-chips")}; matriz={"vf-answer-matrix" in detail34}; ranking={"vf-answer-ranking" in detail34}; pii={"[oculto]" in detail34}')

finally:
    if 'fid' in locals():
        vf.mysql("UPDATE form_participants SET responded_at=NULL WHERE form_id=263819 AND identifier_value IN ('BT-001','BT-002')")
        clear_export()
        vf.mysql("delete from forms where id=%d" % fid)
