import html as htmlmod
import json
import re
import sys
import urllib.request
sys.path.insert(0, 'tests/browser')
import vf

R = vf.R()

def sq(v):
    return "'" + str(v).replace("\\", "\\\\").replace("'", "''") + "'"

def settings_for(fid):
    return vf.db1(f"select settings from forms where id={fid}")[0]

def save_settings(fid, settings):
    vf.mysql(f"update forms set settings={sq(json.dumps(settings, ensure_ascii=False))} where id={fid}")

def create_fixture():
    old = vf.db1("select settings from forms where id=263817")[0]
    d = json.loads(old)
    d['honeypot']['enabled'] = True
    d['anti_bot']['enabled'] = False
    d['quiz']['enabled'] = False
    vf.mysql("delete from forms where title='[BT] M seguridad'")
    vf.mysql("insert into forms (user_id,title,description,slug,status,settings) values (2,'[BT] M seguridad','','bt-m-seguridad','published',%s)" % sq(json.dumps(d, ensure_ascii=False)))
    fid = int(vf.db1("select max(id) from forms where title='[BT] M seguridad'")[0])
    vf.mysql("insert into fields (form_id,type,label,placeholder,help_text,is_required,options,validations,position,parent_id,logic,is_pii,show_header) values (%d,'text','Respuesta M','','',1,'[]','{\"correct\":\"Esperado M6\"}',1,NULL,NULL,0,1)" % fid)
    field_id = int(vf.db1(f"select max(id) from fields where form_id={fid}")[0])
    return fid, field_id

def post_token(h, slug):
    s, page, _ = h.get('/f/' + slug)
    return s, page, h.csrf(page)

fid = None
reg_row = vf.db1("select value from app_settings where key_name='registration_enabled'")
try:
    fid, field = create_fixture()
    slug = 'bt-m-seguridad'
    url = '/f/' + slug

    s, page, tok = post_token(vf.Http(), slug)
    css = (vf.ROOT / 'public/assets/css/app.css').read_text()
    m1 = (s == 200 and 'class="vf-honeypot"' in page and 'aria-hidden="true"' in page
          and 'name="_vf_hp"' in page and 'tabindex="-1"' in page
          and '.vf-honeypot' in css and 'left: -99999px' in css)
    R.check('M1', m1, f'GET={s}; wrapper={"vf-honeypot" in page}; input={"_vf_hp" in page}; tabindex={"-1" in page}; css={".vf-honeypot" in css}')

    before = int(vf.db1(f"select count(*) from submissions where form_id={fid}")[0])
    h = vf.Http()
    _, page, tok = post_token(h, slug)
    body = {'_token': tok, 'lang': 'es', 'field_%d' % field: 'Humano', '_vf_hp': 'bot'}
    s2, b2, u2 = h.req('POST', url, body)
    after = int(vf.db1(f"select count(*) from submissions where form_id={fid}")[0])
    R.check('M2', s2 == 200 and after == before and '/thanks' in u2,
            f'POST={s2}; destino={u2}; submissions {before}->{after}')

    R.na('M3', 'Turnstile no configurado: TURNSTILE_SITE_KEY y TURNSTILE_SECRET_KEY vacíos; no hay widget real que probar')

    st = settings_for(fid)
    d = json.loads(st); d['anti_bot']['enabled'] = True
    save_settings(fid, d)
    g = vf.Http()
    sget, gp, _ = g.get(url)
    _, login_page, _ = g.get('/login')
    gtok = g.csrf(login_page)
    spost, bp, up = g.req('POST', url, {'_token': gtok, 'field_%d' % field: 'bot'}, follow=False)
    d['anti_bot']['enabled'] = False
    save_settings(fid, d)
    R.check('M4', sget == 403 or ('Temporalmente no disponible' in gp and spost == 403),
            f'GET={sget} ({"página cerrada" if "Temporalmente no disponible" in gp else "servido"}); POST={spost}')

    st = settings_for(fid)
    d = json.loads(st); d['quiz']['enabled'] = True
    save_settings(fid, d)
    h = vf.Http()
    _, page, tok = post_token(h, slug)
    s1, b1, u1 = h.req('POST', url, {'_token': tok, 'lang': 'es', 'field_%d' % field: 'Secreto M6'}, follow=True)
    token1 = re.search(r'[?&]t=([0-9a-f]+)', u1)
    token1 = token1.group(1) if token1 else ''
    s1thanks, thanks1, _ = h.get('/f/%s/thanks?t=%s' % (slug, token1)) if token1 else (0, '', '')
    s2b, b2b, u2b = h.req('POST', url, {'_token': tok, 'lang': 'es', 'field_%d' % field: 'Otro M6'}, follow=True)
    token2 = re.search(r'[?&]t=([0-9a-f]+)', u2b)
    token2 = token2.group(1) if token2 else ''
    s2thanks, thanks2, _ = h.get('/f/%s/thanks?t=%s' % (slug, token2)) if token2 else (0, '', '')
    sbad, bad, _ = h.get('/f/%s/thanks?t=%s' % (slug, '0' * 32))
    ssid, sidpage, _ = h.get('/f/%s/thanks?sid=1' % slug)
    c1 = bool(token1) and token1 != token2
    c2 = s1thanks == 200 and 'Secreto M6' in thanks1 and 'Otro M6' not in thanks1
    c3 = s2thanks == 200 and 'Otro M6' in thanks2 and 'Secreto M6' not in thanks2
    c4 = 'Secreto M6' not in bad and 'Secreto M6' not in sidpage
    R.check('M6', c1 and c2 and c3 and c4,
            f'token1={len(token1)}; token2={len(token2)}; distintos={c1}; thanks1={s1thanks}:{c2}; thanks2={s2thanks}:{c3}; malo/sid={sbad}/{ssid}:{c4}; t1_en_malo={"Secreto M6" in bad}; t1_en_sid={"Secreto M6" in sidpage}')
    d['quiz']['enabled'] = False
    save_settings(fid, d)

    log_base = int(vf.db1("select coalesce(max(id),0) from security_log")[0])
    before_m5 = int(vf.db1(f"select count(*) from submissions where form_id={fid}")[0])
    vf.mysql(f"delete from rate_limits where scope='submit:{fid}'")
    h = vf.Http()
    _, page, tok = post_token(h, slug)
    statuses = []
    for i in range(11):
        s, b, u = h.req('POST', url, {'_token': tok, 'lang': 'es', 'field_%d' % field: 'Envio %d' % i}, follow=False)
        statuses.append(s)
    allowed = sum(1 for x in statuses if x == 302)
    last = statuses[-1]
    subs = int(vf.db1(f"select count(*) from submissions where form_id={fid}")[0])
    R.check('M5', allowed == 10 and last == 429 and subs == before_m5 + 10,
            f'estados={statuses}; permitidos={allowed}; ultimo={last}; submissions={subs} (antes {before_m5})')
    vf.mysql("delete from rate_limits where scope='login'")
    h = vf.Http()
    _, lp, _ = h.get('/login')
    ltok = h.csrf(lp)
    sl, bl, ul = h.req('POST', '/login', {'_token': ltok, 'email': 'nadie@bt-m.test', 'password': 'malísima'}, headers={'X-Forwarded-For': '203.0.113.77', 'X-Real-IP': '203.0.113.77'}, follow=False)
    row = vf.db1("select identifier,attempts from rate_limits where scope='login' order by id desc limit 1")
    R.check('M11', sl == 302 and row is not None and row[0] == '127.0.0.1',
            f'login={sl}; rate_identifier={row[0] if row else None}; X-Forwarded-For=203.0.113.77')

    with vf.Browser() as br:
        with vf.VF(R, email=None, browser=br.browser) as s:
            s.goto(url, 700)
            resources = s.page.evaluate("() => performance.getEntriesByType('resource').map(e => e.name)")
            current = s.page.url
            html = s.page.content()
            inline = re.findall(r'<script(?![^>]*\bsrc=)[^>]*>', html, re.I)
            srcs = re.findall(r'<script[^>]*\bsrc=["\']([^"\']+)', html, re.I)
            handlers = re.findall(r'\son[a-z]+\s*=', html, re.I)
            same_resource = all(x.startswith(vf.BASE) or x.startswith('/') for x in resources)
            same_scripts = all(x.startswith(vf.BASE) or x.startswith('/') for x in srcs)
            R.check('M8', same_resource and same_scripts and not inline and not handlers,
                    f'recursos={len(resources)} mismo_origen={same_resource}; scripts={len(srcs)} mismo_origen={same_scripts}; inline={len(inline)}; on*={len(handlers)}')

    req = urllib.request.Request(vf.BASE + '/login')
    with urllib.request.urlopen(req, timeout=10) as resp:
        xfo = resp.headers.get('X-Frame-Options', '')
    R.check('M9', xfo == 'DENY', f'X-Frame-Options={xfo!r}')

    reg_before = vf.db1("select value from app_settings where key_name='registration_enabled'")
    vf.mysql("insert into app_settings (key_name,value) values ('registration_enabled','1') on duplicate key update value='1'")
    vf.mysql("delete from rate_limits where scope='register'")
    reg_status = []
    for i in range(11):
        h = vf.Http()
        s, page, _ = h.get('/register')
        tokk = h.csrf(page)
        email = 'bt-m-reg-%02d-%s@bt-m.test' % (i + 1, 'x' * 4)
        s2, b2, u2 = h.req('POST', '/register', {'_token': tokk, 'name': 'BT M %d' % i, 'email': email, 'password': 'password123', 'password_confirm': 'password123'}, follow=False)
        reg_status.append(s2)
    created = int(vf.db1("select count(*) from users where email like '%@bt-m.test'")[0])
    if reg_row is None:
        vf.mysql("delete from app_settings where key_name='registration_enabled'")
    else:
        vf.mysql("insert into app_settings (key_name,value) values ('registration_enabled',%s) on duplicate key update value=%s" % (sq(reg_before[0]), sq(reg_before[0])))
    vf.mysql("delete from users where email like '%@bt-m.test'")
    vf.mysql("delete from rate_limits where scope='register'")
    R.check('M10', reg_status.count(302) == 10 and reg_status[-1] != 302,
            f'estados={reg_status}; usuarios_creados={created}; fila_contador={vf.db1("select attempts from rate_limits where scope=%s" % sq("register"))}')

    events = vf.mysql(f"select event,count(*) from security_log where id>{log_base} group by event order by event")
    ev = {e: int(c) for e, c in events}
    R.check('M7', ev.get('rate_limit.fill', 0) >= 1 and ev.get('auth.login_fail', 0) >= 1 and ev.get('auth.register', 0) >= 1,
            'eventos=' + json.dumps(ev, ensure_ascii=False))

finally:
    if fid:
        vf.mysql(f"delete from rate_limits where scope='submit:{fid}'")
        vf.mysql("delete from forms where id=%d" % fid)
    vf.mysql("delete from rate_limits where scope='login'")
    vf.mysql("delete from rate_limits where scope='register'")
    vf.mysql("delete from users where email like '%@bt-m.test'")
    if reg_row is None:
        vf.mysql("delete from app_settings where key_name='registration_enabled'")
    else:
        vf.mysql("insert into app_settings (key_name,value) values ('registration_enabled',%s) on duplicate key update value=%s" % (sq(reg_row[0]), sq(reg_row[0])))
