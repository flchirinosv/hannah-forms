import json, re, sys
sys.path.insert(0, 'tests/browser')
import vf

R = vf.R()

def sq(v):
    if v is None: return 'NULL'
    return "'" + str(v).replace("\\", "\\\\").replace("'", "''") + "'"

def base_settings():
    return json.loads(vf.db1("select settings from forms where id=263816")[0])

def create_form(title, slug, fields, settings=None):
    vf.mysql(f"delete from forms where title={sq(title)}")
    cfg = settings or base_settings()
    vf.mysql(f"insert into forms (user_id,title,description,slug,status,settings) values (1,{sq(title)},'',{sq(slug)},'published',{sq(json.dumps(cfg, ensure_ascii=False))})")
    fid = int(vf.db1(f"select max(id) from forms where title={sq(title)}")[0])
    ids = {}
    for f in fields:
        f = dict(f); key = f.pop('key'); parent_key = f.pop('parent_key', None); logic = f.pop('logic', None)
        parent = ids.get(parent_key) if parent_key else None
        cols = [('form_id',fid),('type',f.pop('type')),('label',f.pop('label')),('placeholder',f.pop('placeholder','')),
                ('help_text',f.pop('help_text','')),('is_required',int(f.pop('required',False))),
                ('options',json.dumps(f.pop('options',[]),ensure_ascii=False)),('validations','{}'),
                ('position',int(f.pop('position',1))),('parent_id',parent),('logic',None),
                ('is_pii',int(f.pop('pii',False))),('show_header',int(f.pop('show_header',1)))]
        def lit(v):
            if v is None: return 'NULL'
            return str(v) if isinstance(v, int) else sq(v)
        vf.mysql("insert into fields (%s) values (%s)" % (','.join(c for c,_ in cols), ','.join(lit(v) for _,v in cols)))
        ids[key] = int(vf.db1(f"select max(id) from fields where form_id={fid}")[0])
        if logic:
            lg = {'operator':logic.get('operator','all'),'rules':[dict(r, field_id=ids[r['field']]) for r in logic['rules']]}
            for rr in lg['rules']: rr.pop('field',None)
            vf.mysql(f"update fields set logic={sq(json.dumps(lg,ensure_ascii=False))} where id={ids[key]}")
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
        resp = op.open(r, timeout=15); return resp.status, resp.headers.get('Location',''), resp.read().decode('utf-8','replace')
    except urllib.error.HTTPError as e:
        return e.code, e.headers.get('Location',''), e.read().decode('utf-8','replace')

# Fixtures
ops, oid = create_form('[BT] G operadores', 'bt-g-operadores', [
    {'key':'T','type':'text','label':'Texto','position':1},
    {'key':'EQ','type':'text','label':'Igual','position':2,'logic':{'rules':[{'field':'T','op':'eq','value':'abc123'}]}},
    {'key':'NEQ','type':'text','label':'Distinto','position':3,'logic':{'rules':[{'field':'T','op':'neq','value':'abc123'}]}},
    {'key':'CON','type':'text','label':'Contiene','position':4,'logic':{'rules':[{'field':'T','op':'contains','value':'bc1'}]}},
    {'key':'NCON','type':'text','label':'No contiene','position':5,'logic':{'rules':[{'field':'T','op':'not_contains','value':'bc1'}]}},
    {'key':'FILL','type':'text','label':'Tiene valor','position':6,'logic':{'rules':[{'field':'T','op':'filled'}]}},
    {'key':'EMPTY','type':'text','label':'Vacío','position':7,'logic':{'rules':[{'field':'T','op':'empty'}]}},
    {'key':'RE','type':'text','label':'Regex','position':8,'logic':{'rules':[{'field':'T','op':'matches','value':'^abc[0-9]+$'}]}},
])
g2, g2id = create_form('[BT] G obligatorio condicional', 'bt-g-cond-required', [
    {'key':'A','type':'radio','label':'¿Mostrar?','options':[{'value':'Sí'},{'value':'No'}],'required':True,'position':1},
    {'key':'B','type':'text','label':'Obligatorio condicional','required':True,'position':2,'logic':{'rules':[{'field':'A','op':'eq','value':'Sí'}]}},
])
skip, sid = create_form('[BT] G skip', 'bt-g-skip', [
    {'key':'P1','type':'page','label':'Paso 1','position':1},
    {'key':'A','type':'radio','label':'¿Seguir?','options':[{'value':'Sí'},{'value':'No'}],'required':True,'position':2},
    {'key':'P2','type':'page','label':'Paso 2','position':3,'logic':{'rules':[{'field':'A','op':'eq','value':'No'}]}},
    {'key':'B','type':'text','label':'Solo si sí','position':4},
    {'key':'P3','type':'page','label':'Paso 3','position':5},
    {'key':'C','type':'text','label':'Final','position':6},
])

try:
    anon = vf.Http()
    # G1 every operator behaves as labelled
    with vf.VF(R) as v:
        v.goto('/f/bt-g-operadores')
        t = v.page.locator('#field_%d' % oid['T'])
        def vis():
            return {k: v.page.locator('[data-vf-field-id="%d"]' % oid[k]).is_visible() for k in ['EQ','NEQ','CON','NCON','FILL','EMPTY','RE']}
        t.fill('abc123'); t.dispatch_event('change'); v.page.wait_for_timeout(250); a = vis()
        t.evaluate("e=>{e.value='';e.dispatchEvent(new Event('input',{bubbles:true}));e.dispatchEvent(new Event('change',{bubbles:true}))}"); v.page.wait_for_timeout(250); b = vis()
        t.fill('xyz'); t.dispatch_event('change'); v.page.wait_for_timeout(250); c = vis()
    ok1 = a == {'EQ':True,'NEQ':False,'CON':True,'NCON':False,'FILL':True,'EMPTY':False,'RE':True}
    ok2 = b['NEQ'] and b['NCON'] and b['EMPTY'] and not any(b[k] for k in ['EQ','CON','FILL','RE'])
    ok3 = c['NEQ'] and c['NCON'] and c['FILL'] and not c['EQ'] and not c['CON'] and not c['RE'] and not c['EMPTY']
    R.check('G1', ok1 and ok2 and ok3, f'lleno={a} vacío={b} otro={c}')

    # G2 required field not shown does not block submit; shown does
    n0 = int(vf.db1(f'select count(*) from submissions where form_id={g2}')[0])
    s, h, _ = anon.get('/f/bt-g-cond-required'); tok = token_from(h)
    code1, _, body1 = post_capture(anon, '/f/bt-g-cond-required', {'_token':tok,'field_%d'%g2id['A']:'No'})
    n1 = int(vf.db1(f'select count(*) from submissions where form_id={g2}')[0])
    s, h, _ = anon.get('/f/bt-g-cond-required'); tok = token_from(h)
    code2, _, body2 = post_capture(anon, '/f/bt-g-cond-required', {'_token':tok,'field_%d'%g2id['A']:'Sí'})
    n2 = int(vf.db1(f'select count(*) from submissions where form_id={g2}')[0])
    R.check('G2', n1 == n0 + 1 and n2 == n1, f'oculto {code1} {n0}->{n1}; visible {code2} {n1}->{n2}')

    # G3 shuffle + validation error keeps the order the user saw
    orig = base_settings()
    cfg = base_settings(); cfg['randomize']['questions'] = True
    vf.mysql(f"update forms set settings={sq(json.dumps(cfg,ensure_ascii=False))} where id=263816")
    try:
        s, h, _ = anon.get('/f/bt-p-blico-simple')
        order = re.findall(r'data-vf-field-id="(\d+)"', h)
        tok = token_from(h)
        code, _, body = post_capture(anon, '/f/bt-p-blico-simple', {'_token':tok,'field_277208':'','field_277210[]':'Sí'})
        err_order = re.findall(r'data-vf-field-id="(\d+)"', body)
    finally:
        vf.mysql(f"update forms set settings={sq(json.dumps(orig,ensure_ascii=False))} where id=263816")
    R.check('G3', code in (200,422) and order == err_order, f'GET={order} error={err_order}')

    # G4-G6 skipped step, progress denominator and footer/block numbering
    with vf.VF(R) as v:
        v.goto('/f/bt-g-skip')
        first_block = v.page.locator('.vf-page-block:visible').get_attribute('aria-label')
        first_foot = v.page.locator('.vf-page-footer:visible').inner_text().replace('\n',' ')
        v.page.locator('input[name="field_%d"][value="No"]' % sid['A']).evaluate("e=>{e.checked=true;e.dispatchEvent(new Event('change',{bubbles:true}))}")
        v.page.wait_for_timeout(250)
        v.page.locator('button.vf-nav-next:visible').first.evaluate('e=>e.click()')
        v.page.wait_for_timeout(500)
        blocks = [(b.get_attribute('aria-label'), b.get_attribute('data-vf-skipped'), b.is_visible()) for b in v.page.locator('.vf-page-block').all()]
        vis_block = v.page.locator('.vf-page-block:visible').get_attribute('aria-label')
        foot = v.page.locator('.vf-page-footer:visible').inner_text().replace('\n',' ')
        pct = v.page.locator('body').inner_text()
        m = re.search(r'Avance\s+(\d+)%', pct)
        progress = int(m.group(1)) if m else -1
    skipped = [b for b in blocks if b[1] == '1']
    R.check('G4', len(skipped) == 1 and '2 de 2' in vis_block, f'bloques={blocks}')
    R.check('G5', progress == 50, f'Avance={progress}% (A contestada de A+C; B omitida)')
    R.check('G6', re.search(r'Paso\s+2\s+de\s+2', vis_block) is not None and re.search(r'Paso\s+2\s+de\s+2', foot) is not None, f'bloque={vis_block!r}, pie={foot!r}')

finally:
    pass

print({x:sum(1 for v in R.results.values() if v['status']==x) for x in ['OK','FALLA','N/A','PENDIENTE']})
