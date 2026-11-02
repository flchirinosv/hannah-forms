import re, sys, json, time
from pathlib import Path
sys.path.insert(0, 'tests/browser')
import vf
R = vf.R()
F_PUB, F_EMAIL, F_ALLOW = 263816, 263821, 263820
S_PUB, S_EMAIL, S_ALLOW = 'bt-p-blico-simple', 'bt-email', 'bt-allowlist'

def token(html):
    m = re.search(r'name="_token"\s+value="([^"]+)"', html)
    return m.group(1) if m else ''

def access_token(email='permitido@bt.local', form=F_ALLOW):
    a = vf.Http('admin@demo.com')
    s, h, _ = a.get(f'/forms/{form}/access')
    tk = token(h)
    s, body, _ = a.req('POST', f'/forms/{form}/access/link', {'_token':tk,'email':email}, headers={'X-Requested-With':'XMLHttpRequest','X-CSRF-Token':tk})
    try: return json.loads(body).get('url','')
    except Exception: return ''

def restricted(html):
    return 'id="vfAccessForm"' in html and 'id="vfAccessEmail"' in html

try:
    # I1 public form is served and accepts a submission
    anon = vf.Http()
    s, h, _ = anon.get('/f/' + S_PUB)
    tk = token(h)
    s2, h2, url = anon.req('POST', '/f/' + S_PUB, {'_token':tk,'field_277208':'Prueba I1','field_277210':'1'})
    R.check('I1', s == 200 and 'field_277208' in h and s2 in (200,302), f'GET {s} con campo; POST {s2}')

    # I2 email mode without access
    s, h, _ = anon.get('/f/' + S_EMAIL)
    R.check('I2', s == 200 and restricted(h) and 'field_277247' not in h, f'GET {s}; pide acceso={restricted(h)}')

    # I3 requires real email delivery: N/A in this instance
    R.na('I3', 'Sin SMTP configurado: no se puede comprobar la entrega del correo')

    # I4 identical response inside and outside the list
    vf.mysql("delete from rate_limits where scope in ('form_access','form_access_email')")
    res = {}
    for label, email in [('lista','permitido@bt.local'),('fuera','desconocido@bt.local')]:
        se = vf.Http()
        s, h, _ = se.get('/f/' + S_EMAIL); tk = token(h)
        st, body, loc = se.req('POST', '/f/' + S_EMAIL + '/access', {'_token':tk,'email':email}, follow=False)
        res[label] = (st, loc)
    # fetch the flash after the redirect with a fresh request in each session
    R.check('I4', res['lista'] == res['fuera'] and res['lista'][0] == 302, f'{res}')

    # I5 POST without valid access
    se = vf.Http()
    s, h, _ = se.get('/f/' + S_EMAIL); tk = token(h)
    st, body, _ = se.req('POST', '/f/' + S_EMAIL, {'_token':tk,'field_277247':'x'}, follow=False)
    R.check('I5', st == 403, f'POST sin acceso: {st}')

    # I6 token of another form
    url = access_token('permitido@bt.local', F_ALLOW)
    t = re.search(r'[?&]t=([^&]+)', url)
    se = vf.Http()
    st, h, _ = se.get('/f/' + S_EMAIL + '?t=' + (t.group(1) if t else 'x'))
    R.check('I6', st == 200 and restricted(h) and 'field_277247' not in h, f'token de allowlist en email: {st}, pide acceso={restricted(h)}')

    # I7 remove email -> token no longer works
    tmp = 'i7@bt.local'
    a = vf.Http('admin@demo.com')
    s, h, _ = a.get(f'/forms/{F_ALLOW}/access'); tk = token(h)
    a.req('POST', f'/forms/{F_ALLOW}/access/email', {'_token':tk,'email':tmp}, follow=False)
    url7 = access_token(tmp, F_ALLOW)
    t7 = re.search(r'[?&]t=([^&]+)', url7).group(1)
    priv = vf.Http()
    s1, h1, _ = priv.get('/f/' + S_ALLOW + '?t=' + t7)
    a.req('POST', f'/forms/{F_ALLOW}/access/email/remove', {'_token':tk,'email':tmp}, follow=False)
    fresh = vf.Http()
    s2, h2, _ = fresh.get('/f/' + S_ALLOW + '?t=' + t7)
    R.check('I7', 'field_277246' in h1 and restricted(h2), f'antes={("field_277246" in h1)}, después_pide_acceso={restricted(h2)}')

    # I8 session scoped access
    tmp8 = 'i8@bt.local'
    s, h, _ = a.get(f'/forms/{F_ALLOW}/access'); tk = token(h)
    a.req('POST', f'/forms/{F_ALLOW}/access/email', {'_token':tk,'email':tmp8}, follow=False)
    url8 = access_token(tmp8, F_ALLOW)
    t8 = re.search(r'[?&]t=([^&]+)', url8).group(1)
    priv8 = vf.Http()
    s1, h1, _ = priv8.get('/f/' + S_ALLOW + '?t=' + t8)
    s2, h2, _ = priv8.get('/f/' + S_ALLOW)
    other = vf.Http(); s3, h3, _ = other.get('/f/' + S_ALLOW)
    R.check('I8', 'field_277246' in h1 and 'field_277246' in h2 and restricted(h3), f'con token={("field_277246" in h1)}, tras sesión={("field_277246" in h2)}, otra sesión pide={restricted(h3)}')

    # I9 share link and QR
    a = vf.Http('admin@demo.com')
    s, h, _ = a.get(f'/forms/{F_ALLOW}')
    m = re.search(r'id="shareUrl"[^>]*value="([^"]+)"', h)
    share = m.group(1) if m else ''
    sq, qh, _ = a.get(f'/forms/{F_ALLOW}/qr.svg')
    target = vf.Http(); ts, th, _ = target.get(share.replace(vf.BASE, ''))
    R.check('I9', share.endswith('/f/' + S_ALLOW) and sq == 200 and 'svg' in qh.lower() and restricted(th), f'share={share}, QR={sq}, destino pide acceso={restricted(th)}')

    # I10 async add (start from an empty list)
    vf.mysql(f'delete from form_access_emails where form_id={F_ALLOW}')
    with vf.Browser() as b:
        s = b.session(R, 'admin@demo.com')
        with s:
            s.goto(f'/forms/{F_ALLOW}/access')
            warn0 = s.page.locator('#accessList .vf-access-empty').count()
            s.page.evaluate("window.__vfNoReload = 1")
            s.page.fill('#accessAddEmail', 'i10@bt.local')
            s.page.locator('#accessAddBtn').evaluate('e => e.click()')
            s.page.wait_for_timeout(900)
            same = s.page.evaluate("window.__vfNoReload === 1")
            row = s.page.locator('#accessList .vf-access-row').count()
            val = s.page.locator('#accessAddEmail').input_value()
            focus = s.page.evaluate("document.activeElement && document.activeElement.id")
            warn1 = s.page.locator('#accessList .vf-access-empty').count()
            R.check('I10', warn0 == 1 and same and row == 1 and val == '' and focus == 'accessAddEmail' and warn1 == 0,
                    f'aviso_inicial={warn0}, sin_recarga={same}, filas={row}, valor="{val}", foco={focus}, aviso_final={warn1}')

            # I11 duplicate
            s.page.fill('#accessAddEmail', 'i10@bt.local')
            s.page.locator('#accessAddBtn').evaluate('e => e.click()')
            s.page.wait_for_timeout(700)
            err = s.page.locator('#accessAddError').inner_text().strip()
            row = s.page.locator('#accessList .vf-access-row').count()
            R.check('I11', row == 1 and err != '' and 'ya' in err.lower(), f'filas={row}, error="{err}"')

            # I12 invalid: the type=email field blocks it before the POST
            info = s.page.evaluate("""() => {
                const e = document.querySelector('#accessAddEmail');
                const err = document.querySelector('#accessAddError');
                err.textContent = ''; err.classList.add('d-none');
                e.value = 'no-es-correo';
                e.dispatchEvent(new Event('input',{bubbles:true}));
                document.getElementById('accessAddForm').requestSubmit();
                return {valid:e.checkValidity(), mismatch:e.validity.typeMismatch, msg:e.validationMessage};
            }""")
            s.page.wait_for_timeout(500)
            row = s.page.locator('#accessList .vf-access-row').count()
            R.check('I12', row == 1 and not info['valid'] and info['mismatch'] and info['msg'] != '',
                    f'filas={row}, validez={info["valid"]}, typeMismatch={info["mismatch"]}, motivo="{info["msg"]}"')

            # I13 Enter key
            s.page.evaluate("window.__vfNoReload = 1")
            s.page.fill('#accessAddEmail', 'i13@bt.local')
            s.page.press('#accessAddEmail', 'Enter')
            s.page.wait_for_timeout(900)
            same = s.page.evaluate("window.__vfNoReload === 1")
            row = s.page.locator('#accessList .vf-access-row').count()
            R.check('I13', same and row == 2, f'sin_recarga={same}, filas={row}')

    # I14 no JavaScript: native POST
    nh = vf.Http('admin@demo.com')
    s, h, _ = nh.get(f'/forms/{F_ALLOW}/access'); tk = token(h)
    s2, h2, url2 = nh.req('POST', f'/forms/{F_ALLOW}/access/email', {'_token':tk,'email':'i14@bt.local'})
    R.check('I14', s2 == 200 and 'i14@bt.local' in h2 and ('añad' in h2.lower() or 'lista de acceso' in h2.lower()), f'POST nativo {s2}; fila={"i14@bt.local" in h2}')

    # I15 normal user cannot enter /admin/settings
    u = vf.Http('user@demo.com')
    s, h, _ = u.get('/admin/settings')
    R.check('I15', s == 403, f'user -> {s}')

    # I16 admin toggle works without reload and updates its text
    with vf.Browser() as b:
        s = b.session(R, 'admin@demo.com')
        with s:
            s.goto('/admin/settings', 600)
            s.page.evaluate("window.__vfNoReload = 1")
            was = s.page.locator('#registrationToggle').is_checked()
            s.page.locator('#registrationToggle').evaluate('e => e.click()')
            s.page.wait_for_timeout(1000)
            now = s.page.locator('#registrationToggle').is_checked()
            txt = s.page.locator('[data-role=registration-state]').inner_text().strip()
            same = s.page.evaluate("window.__vfNoReload === 1")
            dbv = vf.db1("select value from app_settings where key_name='registration_enabled'")
            R.check('I16', was != now and same and ('abierto' in txt.lower() if now else 'cerrado' in txt.lower()) and dbv and int(dbv[0]) == int(now),
                    f'{was}->{now}, texto="{txt}", sin_recarga={same}, BD={dbv and dbv[0]}')
            # restore ON through the same UI
            if not s.page.locator('#registrationToggle').is_checked():
                s.page.locator('#registrationToggle').evaluate('e => e.click()'); s.page.wait_for_timeout(800)

            # I17 network failure leaves the switch in the server state
            before = s.page.locator('#registrationToggle').is_checked()
            s.page.route('**/admin/settings/registration', lambda route: route.abort())
            s.page.locator('#registrationToggle').evaluate('e => e.click()')
            s.page.wait_for_timeout(1000)
            after = s.page.locator('#registrationToggle').is_checked()
            hint = s.page.locator('#registrationHint').inner_text().strip()
            cls = s.page.locator('#registrationHint').get_attribute('class') or ''
            R.check('I17', after == before and 'text-danger' in cls, f'casilla {before}->{after}, aviso="{hint}", clase="{cls}"')
            s.page.unroute('**/admin/settings/registration')
            if not s.page.locator('#registrationToggle').is_checked():
                s.page.evaluate("document.getElementById('registrationToggle').checked = true")

    # I18 app_settings wins over .env
    env = Path('.env'); old = env.read_text()
    try:
        new = old
        if re.search(r'^REGISTRATION_ENABLED=', new, re.M):
            new = re.sub(r'^REGISTRATION_ENABLED=.*$', 'REGISTRATION_ENABLED=true', new, flags=re.M)
        else:
            new += '\nREGISTRATION_ENABLED=true\n'
        env.write_text(new)
        vf.mysql("delete from app_settings where key_name='registration_enabled'")
        vf.mysql("insert into app_settings (key_name,value) values ('registration_enabled','0')")
        anon = vf.Http()
        s, h, _ = anon.get('/register')
        R.check('I18', s == 403, f'.env=true + fila=0 -> /register {s}')
    finally:
        env.write_text(old)
        vf.mysql("insert into app_settings (key_name,value) values ('registration_enabled','1') on duplicate key update value='1'")

finally:
    # restore allowlist to its original fixture
    vf.mysql(f"delete from form_access_emails where form_id={F_ALLOW}")
    vf.mysql(f"insert into form_access_emails (form_id,email) values ({F_ALLOW},'permitido@bt.local')")
    vf.mysql("delete from rate_limits where scope in ('form_access','form_access_email')")
    vf.mysql("insert into app_settings (key_name,value) values ('registration_enabled','1') on duplicate key update value='1'")

print({x:sum(1 for v in R.results.values() if v['status']==x) for x in ['OK','FALLA','N/A','PENDIENTE']})
