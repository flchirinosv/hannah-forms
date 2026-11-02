import json, sys
sys.path.insert(0, 'tests/browser')
import vf

R = vf.R()

def set_settings(fid, settings):
    raw = json.dumps(settings, ensure_ascii=False).replace("\\", "\\\\").replace("'", "\\'")
    vf.mysql(f"update forms set settings='{raw}' where id={fid}")

def get_settings(fid):
    return json.loads(vf.db1(f"select settings from forms where id={fid}")[0])

def set_status(fid, status, reviewed_at=None, reviewed_by=None, req=None):
    parts = [f"status='{status}'", f"reviewed_at={vf.mysql_quote(reviewed_at)}" if hasattr(vf, 'mysql_quote') else ("reviewed_at=NULL" if reviewed_at is None else f"reviewed_at='{reviewed_at}'"), f"reviewed_by={reviewed_by if reviewed_by is not None else 'NULL'}", f"review_requested_at={'NULL' if req is None else repr(req)}"]
    # simpler and safe: explicit UPDATE built by caller instead
    vf.mysql(f"update forms set status='{status}', reviewed_at={'NULL' if reviewed_at is None else repr(reviewed_at).replace(chr(39), chr(34))}, reviewed_by={reviewed_by if reviewed_by is not None else 'NULL'} where id={fid}")

def token_from(html):
    import re
    m = re.search(r'name="_token"\s+value="([^"]+)"', html)
    return m.group(1) if m else ''

simple = 263816
review = 263833
approved = 263828
orig_simple = get_settings(simple)
orig_review = get_settings(review)
orig_approved = get_settings(approved)

try:
    # E1 draft not served
    anon = vf.Http()
    s, h, _ = anon.get('/f/bt-borrador')
    R.check('E1', s == 403, f'la doc espera 403; hoy devuelve {s} con la página «En preparación» (decisión F3)')

    # E2 published served
    s, h, _ = anon.get('/f/bt-p-blico-simple')
    R.check('E2', s == 200 and '/f/bt-p-blico-simple' in h and 'name="_token"' in h, f'GET publicado -> {s}')

    # E3 close keeps prior responses
    s, h, _ = anon.get('/f/bt-p-blico-simple')
    tok = token_from(h)
    anon.post('/f/bt-p-blico-simple', {'_token': tok, 'field_277208': 'hola', 'field_277209': 'e3@test.local', 'field_277210[]': 'Sí'}, follow=False)
    before = int(vf.db1(f'select count(*) from submissions where form_id={simple}')[0])
    vf.mysql(f"update forms set status='closed' where id={simple}")
    s, h, _ = anon.get('/f/bt-p-blico-simple')
    after = int(vf.db1(f'select count(*) from submissions where form_id={simple}')[0])
    R.check('E3', before >= 1 and after == before and 'name="_token"' not in h, f'cerrado: {before} respuestas siguen, GET {s}')
    vf.mysql(f"update forms set status='published' where id={simple}")

    # E4 publish with review required does not publish
    vf.mysql("insert into app_settings (key_name,value) values ('review_required','1') on duplicate key update value='1'")
    vf.mysql(f"update forms set status='draft', user_id=2 where id={review}")
    ad = vf.Http('admin@demo.com')
    s0, h0, _ = ad.get(f'/forms/{review}'); tk = token_from(h0)
    s, h, _ = ad.req('POST', f'/forms/{review}/publish', {'_token': tk}, follow=False)
    s2, h2, _ = ad.get(f'/forms/{review}')
    st = vf.db1(f'select status from forms where id={review}')[0]
    R.check('E4', st == 'draft' and ('revisi' in h2.lower()), f'publicar con revisión -> status {st}, {s}')

    # E5 editing approved returns to draft and clears approval (owner without exemption)
    vf.mysql(f"update forms set status='approved', user_id=2, reviewed_at=NOW(), reviewed_by=1 where id={review}")
    with vf.VF(R) as v:
        v.goto(f'/forms/{review}/builder')
        raw = v.page.locator('#builderApp').get_attribute('data-fields')
        fields = json.loads(raw)
        for i, f in enumerate(fields):
            f['_uid'] = 'field-' + str(f['id'])
            f['parent_uid'] = None
        payload = {'title': '[BT] Revisión editada', 'description': '', 'status': 'draft', 'settings': {}, 'fields': fields}
        csrf = v.page.locator('#builderApp').get_attribute('data-csrf')
        resp = v.ctx.request.post(vf.BASE + f'/forms/{review}/builder/save', data=json.dumps(payload), headers={'Content-Type': 'application/json', 'X-CSRF-Token': csrf})
        code = resp.status
    row = vf.db1(f'select status, reviewed_at, reviewed_by from forms where id={review}')
    s3, h3, _ = ad.get(f'/forms/{review}')
    R.check('E5', code == 200 and row[0] == 'draft' and row[1] == 'NULL' and row[2] == 'NULL' and 'Enviar a revisión' in h3, f'save {code}; status={row}')

    # E6 approve own form is allowed and logged as propio
    vf.mysql(f"update forms set status='in_review', user_id=1, review_requested_at=NOW(), reviewed_at=NULL, reviewed_by=NULL where id={review}")
    s0, h0, _ = ad.get(f'/forms/{review}'); tk = token_from(h0)
    s, h, _ = ad.req('POST', f'/forms/{review}/review/approve', {'_token': tk}, follow=False)
    st = vf.db1(f'select status from forms where id={review}')[0]
    log = vf.db1("select detail from security_log where event='form.review_approve' and detail like '%#263833%' order by id desc limit 1")
    R.check('E6', st == 'approved' and log is not None and 'propio' in log[0], f'aprobar propio -> {st}, log={log}')

    # E7 approve without forms.review is 403
    vf.mysql(f"update forms set status='in_review', review_requested_at=NOW(), reviewed_at=NULL, reviewed_by=NULL where id={review}")
    us = vf.Http('user@demo.com')
    s0, h0, _ = us.get('/dashboard'); tk = token_from(h0)
    s, h, _ = us.req('POST', f'/forms/{review}/review/approve', {'_token': tk}, follow=False)
    st = vf.db1(f'select status from forms where id={review}')[0]
    R.check('E7', s == 403 and st == 'in_review', f'user sin forms.review -> {s}, status {st}')

    # E8 in_review shows informative page and POST is rejected
    vf.mysql(f"update forms set status='in_review' where id={review}")
    s, h, _ = anon.get('/f/bt-revisi-n')
    stok = token_from(anon.get('/f/bt-p-blico-simple')[1])
    s2, h2, _ = anon.post('/f/bt-revisi-n', {'_token': stok}, follow=False)
    R.check('E8', s == 200 and ('revisi' in h.lower()) and s2 == 403, f'GET {s}, POST {s2}')

    # E9 return to draft
    vf.mysql(f"update forms set status='in_review', review_requested_at=NOW(), reviewed_at=NULL, reviewed_by=NULL where id={review}")
    s0, h0, _ = ad.get(f'/forms/{review}'); tk = token_from(h0)
    s, h, _ = ad.req('POST', f'/forms/{review}/review/return', {'_token': tk}, follow=False)
    st = vf.db1(f'select status from forms where id={review}')[0]
    R.check('E9', st == 'draft', f'devolver -> {st}')

    # E10 approving a published form is rejected
    vf.mysql(f"update forms set status='published' where id={simple}")
    s0, h0, _ = ad.get(f'/forms/{simple}'); tk = token_from(h0)
    s, h, _ = ad.req('POST', f'/forms/{simple}/review/approve', {'_token': tk}, follow=False)
    st = vf.db1(f'select status from forms where id={simple}')[0]
    R.check('E10', st == 'published', f'aprobar publicado -> status {st}, HTTP {s}')

    # E11 future open_at blocks; past open_at serves
    cfg = get_settings(simple); cfg['schedule']['open_at'] = '2030-01-01 00:00'; cfg['schedule']['close_at'] = None
    set_settings(simple, cfg)
    s, h, _ = anon.get('/f/bt-p-blico-simple')
    open_future_blocked = s == 200 and 'name="_token"' not in h and ('aún no' in h.lower() or 'todav' in h.lower() or 'no está abierto' in h.lower())
    cfg['schedule']['open_at'] = '2020-01-01 00:00'; set_settings(simple, cfg)
    s2, h2, _ = anon.get('/f/bt-p-blico-simple')
    R.check('E11', open_future_blocked and s2 == 200 and 'name="_token"' in h2, f'futuro bloquea ({s}), pasado sirve ({s2})')

    # E12 close before open is rejected and not saved
    before = get_settings(simple)
    tks = token_from(ad.get(f'/forms/{simple}')[1])
    s, h, _ = ad.req('POST', f'/forms/{simple}/settings', {'_token': tks, 'open_at': '2027-01-02T10:00', 'close_at': '2027-01-01T10:00'})
    after = get_settings(simple)
    R.check('E12', after == before and ('anterior' in h.lower() or 'posterior' in h.lower()), f'cierre anterior a apertura: {s}')

    # E13 quota reached blocks; token obtained before enabling
    cfg = get_settings(simple); cfg['schedule']['open_at'] = None; cfg['schedule']['close_at'] = None; cfg['quota']['enabled'] = False; set_settings(simple, cfg)
    s, h, _ = anon.get('/f/bt-p-blico-simple'); tok = token_from(h)
    n = int(vf.db1(f'select count(*) from submissions where form_id={simple}')[0])
    cfg['quota']['enabled'] = True; cfg['quota']['max'] = n; set_settings(simple, cfg)
    s, h, _ = anon.get('/f/bt-p-blico-simple')
    s2, h2, _ = anon.post('/f/bt-p-blico-simple', {'_token': tok, 'field_277208': 'cuota', 'field_277209': 'e13@test.local', 'field_277210[]': 'Sí'}, follow=False)
    R.check('E13', s == 200 and 'name="_token"' not in h and s2 == 403, f'cupo lleno: GET {s}, POST {s2}')

    # E14 max 0 without enabled does not limit
    cfg['quota']['enabled'] = False; cfg['quota']['max'] = 0; set_settings(simple, cfg)
    s, h, _ = anon.get('/f/bt-p-blico-simple')
    R.check('E14', s == 200 and 'name="_token"' in h, f'cupo 0 sin activar -> {s}')

finally:
    vf.mysql(f"update forms set status='published' where id={simple}")
    set_settings(simple, orig_simple)
    vf.mysql(f"update forms set status='draft', reviewed_at=NULL, reviewed_by=NULL, review_requested_at=NULL where id={review}")
    set_settings(review, orig_review)
    vf.mysql(f"update forms set status='approved' where id={approved}")
    set_settings(approved, orig_approved)
    vf.mysql("insert into app_settings (key_name,value) values ('review_required','1') on duplicate key update value='1'")

print({'OK': sum(1 for x in R.results.values() if x['status']=='OK'), 'FALLA': sum(1 for x in R.results.values() if x['status']=='FALLA'), 'N/A': sum(1 for x in R.results.values() if x['status']=='N/A'), 'PENDIENTE': sum(1 for x in R.results.values() if x['status']=='PENDIENTE')})
