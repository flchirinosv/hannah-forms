import copy, json, re, sys, time
sys.path.insert(0, 'tests/browser')
import vf

R = vf.R()

def sq(v):
    if v is None: return 'NULL'
    return "'" + str(v).replace("\\", "\\\\").replace("'", "''") + "'"

def base_settings():
    return json.loads(vf.db1("select settings from forms where id=263816")[0])

def create_form(title, slug, fields, *, settings=None, status='published'):
    vf.mysql(f"delete from forms where title={sq(title)}")
    cfg = settings or base_settings()
    raw = json.dumps(cfg, ensure_ascii=False)
    vf.mysql(f"insert into forms (user_id,title,description,slug,status,settings) values (1,{sq(title)},'',{sq(slug)},{sq(status)},{sq(raw)})")
    fid = int(vf.db1(f"select max(id) from forms where title={sq(title)}")[0])
    ids = {}
    for f in fields:
        f = dict(f)
        key = f.pop('key')
        parent_key = f.pop('parent_key', None)
        logic = f.pop('logic', None)
        parent = ids.get(parent_key) if parent_key else None
        opts = json.dumps(f.pop('options', []), ensure_ascii=False)
        vals = json.dumps(f.pop('validations', {}), ensure_ascii=False)
        cols = {
            'form_id': fid, 'type': f.pop('type'), 'label': f.pop('label'),
            'placeholder': f.pop('placeholder', ''), 'help_text': f.pop('help_text', ''),
            'is_required': int(f.pop('required', False)), 'options': opts, 'validations': vals,
            'position': int(f.pop('position', 1)), 'parent_id': parent,
            'logic': None, 'is_pii': int(f.pop('pii', False)), 'show_header': int(f.pop('show_header', 1)),
        }
        names = ','.join(cols)
        def lit(v):
            if v is None: return 'NULL'
            return str(v) if isinstance(v, int) else sq(v)
        values = ','.join(lit(v) for v in cols.values())
        vf.mysql(f"insert into fields ({names}) values ({values})")
        ids[key] = int(vf.db1(f"select max(id) from fields where form_id={fid}")[0])
        if logic:
            lg = {'operator': logic.get('operator', 'all'), 'rules': []}
            for rule in logic['rules']:
                rr = dict(rule); rr['field_id'] = ids[rr.pop('field')]
                lg['rules'].append(rr)
            vf.mysql(f"update fields set logic={sq(json.dumps(lg, ensure_ascii=False))} where id={ids[key]}")
    return fid, ids

def token_from(html):
    m = re.search(r'name="_token"\s+value="([^"]+)"', html)
    return m.group(1) if m else ''

def post_capture(session, path, data):
    import urllib.request, urllib.parse, urllib.error
    body = urllib.parse.urlencode(data, doseq=True).encode()
    r = urllib.request.Request(vf.BASE + path, data=body, method='POST')
    class NoRedir(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, *a, **k): return None
    op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(session.cj), NoRedir)
    try:
        resp = op.open(r, timeout=15)
        return resp.status, resp.headers.get('Location', ''), resp.read().decode('utf-8', 'replace')
    except urllib.error.HTTPError as e:
        return e.code, e.headers.get('Location', ''), e.read().decode('utf-8', 'replace')

simple = 263816
multi = 263818
types = 263817
orig_simple = base_settings()

# --- Fixtures F11-F17 (created once per run) ---
cond, cid = create_form('[BT] F condicional', 'bt-f-condicional', [
    {'key':'A','type':'radio','label':'¿Mostrar?','options':[{'value':'Sí'},{'value':'No'}],'required':True,'position':1},
    {'key':'B','type':'text','label':'Condicional','position':2,'logic':{'rules':[{'field':'A','op':'eq','value':'Sí'}]}},
    {'key':'S','type':'section','label':'Sección condicional','position':3,'logic':{'rules':[{'field':'A','op':'eq','value':'Sí'}]}},
    {'key':'D','type':'radio','label':'¿Mostrar hijo?','options':[{'value':'Sí'},{'value':'No'}],'position':4},
    {'key':'C','type':'text','label':'Dentro','position':1,'parent_key':'S','logic':{'rules':[{'field':'D','op':'eq','value':'Sí'}]}},
])
acfg = base_settings(); acfg['advance']['auto'] = True
auto, aid = create_form('[BT] F auto', 'bt-f-auto', [
    {'key':'P1','type':'page','label':'Paso 1','position':1},
    {'key':'T1','type':'text','label':'Primero','required':True,'position':2},
    {'key':'P2','type':'page','label':'Paso 2','position':3},
    {'key':'T2','type':'text','label':'Segundo','position':4},
], settings=acfg)
arank, arid = create_form('[BT] F auto ranking', 'bt-f-auto-ranking', [
    {'key':'P1','type':'page','label':'Paso 1','position':1},
    {'key':'RR','type':'ranking','label':'Ordena','options':[{'value':'Uno'},{'value':'Dos'},{'value':'Tres'}],'required':True,'position':2},
    {'key':'P2','type':'page','label':'Paso 2','position':3},
    {'key':'T2','type':'text','label':'Segundo','position':4},
], settings=acfg)

try:
    anon = vf.Http()
    vf.mysql("delete from rate_limits where scope like 'submit:%'")
    urls = []

    # F1 valid submission -> thanks; token only appears when quiz is enabled
    s, h, _ = anon.get('/f/bt-p-blico-simple'); tok = token_from(h)
    code, loc, body = post_capture(anon, '/f/bt-p-blico-simple', {'_token': tok, 'field_277208':'F1 uno','field_277209':'f1@test.local','field_277210[]':'Sí'})
    s2, h2, _ = anon.get(loc.replace(vf.BASE, '')) if loc else (0, '', '')
    R.check('F1', code == 302 and '/thanks?t=' in loc, f'la doc exige token; hoy {code} -> {loc} (solo se añade ?t= con quiz activo)')

    # F2/F3 token on quiz thanks opens exactly that response
    s, h, _ = anon.get('/f/bt-quiz'); tok = token_from(h)
    code, qloc, body = post_capture(anon, '/f/bt-quiz', {'_token': tok, 'field_277251':'4'})
    other = vf.Http(); s, h, _ = other.get(qloc.replace(vf.BASE, ''))
    R.check('F2', code == 302 and '?t=' in qloc and s == 200 and '4' in h, f'quiz thanks anónimo -> {s}, {qloc}')
    bad = qloc[:-1] + ('0' if qloc[-1] != '0' else '1')
    s, h, _ = other.get(bad.replace(vf.BASE, ''))
    R.check('F3', not (s == 200 and 'correctas' in h), f'token alterado -> {s}')

    # F4 server rejects invalid field bypassing browser
    n0 = int(vf.db1(f'select count(*) from submissions where form_id={simple}')[0])
    s, h, _ = anon.get('/f/bt-p-blico-simple'); tok = token_from(h)
    code, loc, body = post_capture(anon, '/f/bt-p-blico-simple', {'_token': tok, 'field_277208':'', 'field_277209':'f4@test.local','field_277210[]':'Sí'})
    n1 = int(vf.db1(f'select count(*) from submissions where form_id={simple}')[0])
    R.check('F4', n1 == n0 and code in (200, 422) and ('obligatorio' in body.lower() or 'required' in body.lower()), f'inválido por servidor: {code}, {n0}->{n1}')

    # F5 whitespace is not an answer
    s, h, _ = anon.get('/f/bt-p-blico-simple'); tok = token_from(h)
    code, loc, body = post_capture(anon, '/f/bt-p-blico-simple', {'_token': tok, 'field_277208':'   ', 'field_277209':'f5@test.local','field_277210[]':'Sí'})
    n2 = int(vf.db1(f'select count(*) from submissions where form_id={simple}')[0])
    R.check('F5', n2 == n0 and code in (200, 422), f'espacios: {code}, {n0}->{n2}')

    # F6 keyboard-only traversal: Lightpanda does not implement Tab, so this
    # cannot be exercised for real here.
    R.na('F6', 'Lightpanda no implementa la tecla Tab; la travesía solo-teclado no es medible en este navegador')

    # F7 scale/NPS/star radios are focusable and the CSS paints a focus ring
    s, h, _ = anon.get('/f/bt-todos-los-tipos')
    css = open('public/assets/css/app.css').read()
    scale_ok = bool(re.search(r'name="field_2772(2[3-9]|30)"[^>]*type="radio"|type="radio"[^>]*name="field_2772(2[3-9]|30)"', h))
    nps_ok = 'class="nps-option"' in h
    star_ok = 'class="star-option"' in h
    ring_ok = all(x in css for x in ['.scale-option input:focus-visible + .scale-btn', '.nps-option input:focus-visible + .scale-btn', '.star-option input:focus-visible + .star-btn'])
    R.check('F7', scale_ok and nps_ok and star_ok and ring_ok, f'escala={scale_ok}, nps={nps_ok}, estrellas={star_ok}, anillo CSS={ring_ok}')

    # F8 Enter on Siguiente keeps focus in the step (not back at document start)
    with vf.VF(R) as v:
        v.goto('/f/feedback-de-curso-multi-paso-copia')
        v.page.locator('#field_277232').fill('Curso')
        v.page.locator('input[name="field_277233"][value="Sí, asistí a todas"]').evaluate("e=>{e.checked=true;e.dispatchEvent(new Event('change',{bubbles:true}))}")
        nxt = v.page.locator('button.vf-nav-next:visible').first
        nxt.focus(); v.page.keyboard.press('Enter'); v.page.wait_for_timeout(600)
        st = v.page.evaluate("()=>({tag:document.activeElement.tagName,cls:document.activeElement.className,txt:document.activeElement.innerText?.slice(0,20)})")
    R.check('F8', st['tag'] == 'SECTION' and 'vf-page-block' in st['cls'], f'foco tras Siguiente: {st}')

    # F9 Anterior/Siguiente do not jump focus to the first field
    with vf.VF(R) as v:
        v.goto('/f/feedback-de-curso-multi-paso-copia')
        v.page.locator('#field_277232').fill('Curso')
        v.page.locator('input[name="field_277233"][value="Sí, asistí a todas"]').evaluate("e=>{e.checked=true;e.dispatchEvent(new Event('change',{bubbles:true}))}")
        v.page.locator('button.vf-nav-next:visible').first.focus(); v.page.keyboard.press('Enter'); v.page.wait_for_timeout(500)
        v.page.locator('button[data-vf-action=prev]:visible').first.focus(); v.page.keyboard.press('Enter'); v.page.wait_for_timeout(500)
        tag = v.page.evaluate("()=>document.activeElement.tagName")
        first_focused = v.page.evaluate("()=>document.activeElement === document.querySelector('.vf-page-block:not([hidden]) input, .vf-page-block:not([hidden]) textarea')")
    R.check('F9', not first_focused, f'foco tras Anterior={tag}, primer campo={first_focused}')

    # F10 localStorage draft restores answers
    with vf.VF(R) as v:
        v.goto('/f/bt-p-blico-simple')
        v.page.locator('#field_277208').fill('borrador F10')
        v.page.locator('#field_277208').dispatch_event('change')
        v.page.wait_for_timeout(900)
        v.page.reload(); v.page.wait_for_timeout(800)
        val = v.page.locator('#field_277208').input_value()
    R.check('F10', val == 'borrador F10', f'recarga restaura: {val!r}')

    # F11/F12 conditional field visible/not and server saves only when shown
    with vf.VF(R) as v:
        v.goto('/f/bt-f-condicional')
        b = v.page.locator('[data-vf-field-id="%d"]' % cid['B'])
        initially_hidden = not b.is_visible()
        v.page.locator('input[name="field_%d"][value="Sí"]' % cid['A']).evaluate("e=>{e.checked=true;e.dispatchEvent(new Event('change',{bubbles:true}))}")
        v.page.wait_for_timeout(250)
        shown = b.is_visible()
        b.locator('input').fill('valor B')
        v.page.locator('form').first.evaluate('f=>f.requestSubmit()')
        v.page.wait_for_timeout(700)
        saved = vf.db1(f'select a.value from answers a join submissions s on s.id=a.submission_id where s.form_id={cond} and a.field_id={cid["B"]} order by a.id desc limit 1')
    R.check('F11', initially_hidden and shown and saved[0] == 'valor B', f'oculto->visible: {initially_hidden}->{shown}, guardado={saved}')

    n0 = int(vf.db1(f'select count(*) from submissions where form_id={cond}')[0])
    s, h, _ = anon.get('/f/bt-f-condicional'); tok = token_from(h)
    code, loc, body = post_capture(anon, '/f/bt-f-condicional', {'_token': tok, 'field_%d'%cid['A']:'No', 'field_%d'%cid['B']:'valor oculto'})
    n1 = int(vf.db1(f'select count(*) from submissions where form_id={cond}')[0])
    hidden_saved = vf.db1(f'select a.value from answers a join submissions s on s.id=a.submission_id where s.form_id={cond} and a.field_id={cid["B"]} and a.value="valor oculto"')
    R.check('F12', n1 == n0 + 1 and hidden_saved is None, f'envío {code}; oculto no guardado={hidden_saved}')

    # F13 section AND child condition
    with vf.VF(R) as v:
        v.goto('/f/bt-f-condicional')
        c = v.page.locator('[data-vf-field-id="%d"]' % cid['C'])
        sec = v.page.locator('section[data-vf-field-id="%d"]' % cid['S'])
        def setv(name, val):
            v.page.locator('input[name="field_%d"][value="%s"]' % (cid[name], val)).evaluate("e=>{e.checked=true;e.dispatchEvent(new Event('change',{bubbles:true}))}")
        a0 = c.is_visible()
        setv('A','Sí'); setv('D','Sí'); v.page.wait_for_timeout(250); a1 = c.is_visible() and sec.is_visible()
        setv('D','No'); v.page.wait_for_timeout(250); a2 = c.is_visible()
        setv('A','No'); setv('D','Sí'); v.page.wait_for_timeout(250); a3 = c.is_visible()
    R.check('F13', not a0 and a1 and not a2 and not a3, f'AND sección+hijo: {a0},{a1},{a2},{a3}')

    # F14 submit all required fields of multipage reaches thanks
    s, h, _ = anon.get('/f/feedback-de-curso-multi-paso-copia'); tok = token_from(h)
    data = {'_token': tok, 'field_277232':'Curso','field_277237':'3','field_277242':'4','field_277233':'Sí, asistí a todas','field_277239':'Materiales muy buenos','field_277244':'Sí'}
    code, loc, body = post_capture(anon, '/f/feedback-de-curso-multi-paso-copia', data)
    R.check('F14', code == 302 and '/thanks' in loc, f'último paso -> {code} {loc}')

    # F15 randomization changes order between GETs
    cfg = base_settings(); cfg['randomize']['questions'] = True
    vf.mysql(f"update forms set settings={sq(json.dumps(cfg, ensure_ascii=False))} where id={simple}")
    orders = []
    for _ in range(8):
        s, h, _ = anon.get('/f/bt-p-blico-simple')
        orders.append(tuple(re.findall(r'data-vf-field-id="(\d+)"', h)))
    R.check('F15', len(set(orders)) > 1, f'{len(set(orders))} órdenes distintos en 8 GET')

    # F16 auto advance and no advance on restore
    with vf.VF(R) as v:
        v.goto('/f/bt-f-auto')
        v.page.locator('#field_%d' % aid['T1']).fill('avanza')
        v.page.locator('#field_%d' % aid['T1']).dispatch_event('change')
        v.page.wait_for_timeout(600)
        moved = v.page.locator('#field_%d' % aid['T2']).is_visible()
        v.page.reload(); v.page.wait_for_timeout(800)
        restored_at_first = not v.page.locator('#field_%d' % aid['T2']).is_visible()
    R.check('F16', moved and restored_at_first, f'auto avanza={moved}; recarga con borrador no avanza={restored_at_first}')

    # F17 ranking duplicates block auto advance
    with vf.VF(R) as v:
        v.goto('/f/bt-f-auto-ranking')
        for i in range(3):
            v.page.locator('#f%d_r%d' % (arid['RR'], i)).evaluate("e=>{e.value='1';e.dispatchEvent(new Event('change',{bubbles:true}))}")
        v.page.wait_for_timeout(600)
        stayed = not v.page.locator('#f%d' % arid['T2']).is_visible()
    R.check('F17', stayed, 'ranking duplicado no avanza solo')

    # F18-F20 embed, disabled embed, language
    with vf.VF(R) as v:
        v.goto('/dashboard')
        v.page.evaluate("() => { const f=document.createElement('iframe'); f.id='vfFrame'; f.src='/f/bt-embed/embed'; f.style.width='800px'; f.style.height='600px'; document.body.appendChild(f); }")
        v.page.wait_for_timeout(900)
        frames = v.page.frames
        embedded = len(frames) > 1 and 'name="_token"' in frames[-1].content()
    R.check('F18', embedded, f'iframe mismo dominio: {len(frames)} frames')
    s, h, _ = anon.get('/f/bt-p-blico-simple/embed')
    R.check('F19', s == 403, f'embed desactivado -> {s}')
    s, h, _ = anon.get('/f/bt-p-blico-simple?lang=en')
    R.check('F20', s == 200 and '<html lang="en">' in h and 'Submit' in h, f'?lang=en -> {s}')

    # F21 stars hover and chosen value
    with vf.VF(R) as v:
        v.goto('/f/bt-todos-los-tipos')
        labels = v.page.locator('.star-selector label')
        labels.nth(2).evaluate('e=>e.click()')
        v.page.wait_for_timeout(100)
        labels.nth(4).evaluate("e=>e.dispatchEvent(new MouseEvent('mouseover',{bubbles:true,relatedTarget:document.body}))")
        v.page.wait_for_timeout(150)
        lit_hover = v.page.locator('.star-btn.is-lit').count()
        labels.nth(4).evaluate("e=>e.dispatchEvent(new MouseEvent('mouseout',{bubbles:true,relatedTarget:document.body}))")
        v.page.wait_for_timeout(150)
        lit_out = v.page.locator('.star-btn.is-lit').count()
        val = v.page.locator('input[name="field_277228"]:checked, input[name="field_277228"]').first.get_attribute('value')
        val = v.page.evaluate("() => { const x=document.querySelector('input[name=field_277228]:checked'); return x ? x.value : null }")
    R.check('F21', lit_hover == 5 and lit_out == 3 and val == '3', f'hover={lit_hover}, fuera={lit_out}, valor={val}')

    # F22 ranking arrow buttons renumber and submit visual order
    with vf.VF(R) as v:
        v.goto('/f/bt-todos-los-tipos')
        block = v.page.locator('[data-vf-field-id="277229"]')
        block.locator('[data-vf-rank-move="down"]').first.evaluate('e=>e.click()')
        v.page.wait_for_timeout(250)
        order = [x.inner_text().strip() for x in block.locator('[data-vf-rank] label').all()]
        vals = [x.input_value() for x in block.locator('.vf-ranking-input').all()]
        v.page.locator('#field_277211').fill('F22')
        v.page.locator('form').first.evaluate('f=>f.requestSubmit()')
        v.page.wait_for_timeout(700)
        ans = vf.db1(f'select a.value from answers a join submissions s on s.id=a.submission_id where s.form_id={types} and a.field_id=277229 order by a.id desc limit 1')
    R.check('F22', order[0] == 'Dos' and vals == ['1','2','3'] and ans is not None and 'Dos' in ans[0], f'orden={order}, inputs={vals}, guardado={ans}')

    # F23 reload restores ranking order from draft
    with vf.VF(R) as v:
        v.goto('/f/bt-todos-los-tipos')
        block = v.page.locator('[data-vf-field-id="277229"]')
        block.locator('[data-vf-rank-move="down"]').first.evaluate('e=>e.click()')
        v.page.wait_for_timeout(1200)
        first_before = block.locator('[data-vf-rank] label').first.inner_text().strip()
        v.page.reload(); v.page.wait_for_timeout(900)
        first_after = v.page.locator('[data-vf-field-id="277229"] [data-vf-rank] label').first.inner_text().strip()
    R.check('F23', first_before == 'Dos' and first_after == first_before, f'{first_before} -> {first_after}')

    # F24 without JS: numeric input remains, arrows are hidden by CSS, no class
    s, h, _ = anon.get('/f/bt-todos-los-tipos')
    nums = len(re.findall(r'class="[^"]*vf-ranking-input', h))
    cls = re.search(r'data-vf-ranking[^>]*class="([^"]*)"', h)
    cls = cls.group(1) if cls else ''
    css = open('public/assets/css/app.css').read()
    nojs = '.vf-rank-move { display: none; }' in css and '.vf-ranking--js .vf-ranking-input { display: none; }' in css
    R.check('F24', nums == 3 and 'vf-ranking--js' not in h and nojs, f'inputs={nums}, clase JS={bool(cls)}, CSS sin JS={nojs}')

finally:
    vf.mysql(f"update forms set settings={sq(json.dumps(orig_simple, ensure_ascii=False))} where id={simple}")

print({'OK': sum(1 for x in R.results.values() if x['status']=='OK'), 'FALLA': sum(1 for x in R.results.values() if x['status']=='FALLA'), 'N/A': sum(1 for x in R.results.values() if x['status']=='N/A'), 'PENDIENTE': sum(1 for x in R.results.values() if x['status']=='PENDIENTE')})
