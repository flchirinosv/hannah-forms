import json, os, re, subprocess, sys, uuid
from pathlib import Path
sys.path.insert(0, 'tests/browser')
import vf

R = vf.R()
ROOT = Path('.').resolve()

def sq(v):
    if v is None: return 'NULL'
    return "'" + str(v).replace("\\", "\\\\").replace("'", "''") + "'"

def create_form(title, slug, fields, owner=1):
    vf.mysql(f"delete from forms where title={sq(title)}")
    settings = vf.db1("select settings from forms where id=263816")[0]
    vf.mysql(f"insert into forms (user_id,title,description,slug,status,settings) values ({owner},{sq(title)},'',{sq(slug)},'published',{sq(settings)})")
    fid = int(vf.db1(f"select max(id) from forms where title={sq(title)}")[0])
    ids = {}
    for f in fields:
        f = dict(f); key = f.pop('key')
        cols = [('form_id',fid),('type',f.pop('type')),('label',f.pop('label')),('placeholder',''),('help_text',''),
                ('is_required',int(f.pop('required',False))),('options','[]'),
                ('validations',json.dumps(f.pop('validations',{}),ensure_ascii=False)),
                ('position',int(f.pop('position',1))),('parent_id',None),('logic',None),
                ('is_pii',int(f.pop('pii',False))),('show_header',1)]
        def lit(v):
            if v is None: return 'NULL'
            return str(v) if isinstance(v,int) else sq(v)
        vf.mysql("insert into fields (%s) values (%s)" % (','.join(c for c,_ in cols), ','.join(lit(v) for _,v in cols)))
        ids[key] = int(vf.db1(f"select max(id) from fields where form_id={fid}")[0])
    return fid, ids

def token_from(html):
    m = re.search(r'name="_token"\s+value="([^"]+)"', html)
    return m.group(1) if m else ''

def post_multipart(session, path, fields, files):
    import urllib.request, urllib.parse, urllib.error
    b = '----bt' + uuid.uuid4().hex
    out = bytearray()
    for k, v in fields.items():
        out += f'--{b}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode()
    for name, filename, data, ctype in files:
        out += (f'--{b}\r\nContent-Disposition: form-data; name="{name}"; filename="{filename}"\r\n'
                f'Content-Type: {ctype}\r\n\r\n').encode() + data + b'\r\n'
    out += f'--{b}--\r\n'.encode()
    r = urllib.request.Request(vf.BASE + path, data=bytes(out), method='POST', headers={'Content-Type': f'multipart/form-data; boundary={b}'})
    class NoRedir(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, *a, **k): return None
    op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(session.cj), NoRedir)
    try:
        resp = op.open(r, timeout=30); return resp.status, resp.headers.get('Location',''), resp.read().decode('utf-8','replace')
    except urllib.error.HTTPError as e:
        return e.code, e.headers.get('Location',''), e.read().decode('utf-8','replace')

def get_bytes(session, path):
    import urllib.request, urllib.error
    op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(session.cj))
    try:
        resp = op.open(vf.BASE + path, timeout=20); return resp.status, dict(resp.headers), resp.read()
    except urllib.error.HTTPError as e:
        return e.code, dict(e.headers), e.read()

# Files
pdf = Path('/tmp/bt_test.pdf'); pdf.write_bytes(b'%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n' + b'0'*100)
import zipfile
docx = Path('/tmp/bt_test.docx')
with zipfile.ZipFile(docx,'w') as z:
    z.writestr('[Content_Types].xml','<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>')
    z.writestr('_rels/.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>')
    z.writestr('word/document.xml','<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>hola</w:t></w:r></w:p></w:body></w:document>')
jpg = next((ROOT/'recursos').glob('*.jpg'))
png = Path('/tmp/bt_test.png')
png.write_bytes(bytes.fromhex('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000a49444154789c6360000002000100ffff03000006000557bfabd40000000049454e44ae426082'))
exe = Path('/tmp/bt_test.exe'); exe.write_bytes(b'MZ' + b'\0'*200)
php = Path('/tmp/bt_test.php'); php.write_bytes(b'<?php echo "x";')
js = Path('/tmp/bt_test.js'); js.write_bytes(b'alert(1)')
fake_pdf = Path('/tmp/bt_fake.pdf'); fake_pdf.write_bytes(b'MZ' + b'\0'*200)
big_pdf = Path('/tmp/bt_big.pdf'); big_pdf.write_bytes(b'%PDF-1.4\n' + b'0'*(1200*1024))
huge_pdf = Path('/tmp/bt_huge.pdf'); huge_pdf.write_bytes(b'%PDF-1.4\n' + b'0'*(9*1024*1024))
pdf2 = Path('/tmp/bt_test2.pdf'); pdf2.write_bytes(b'%PDF-1.4\nsecond\n%%EOF\n')

gen, gid = create_form('[BT] H uploads', 'bt-h-uploads', [
    {'key':'F','type':'file','label':'Adjunto','validations':{'max_size_mb':2,'max_files':5,'accept':[],'multiple':True}},
])
lim, lid = create_form('[BT] H limits', 'bt-h-limits', [
    {'key':'F','type':'file','label':'Adjunto','validations':{'max_size_mb':1,'max_files':1,'accept':[]}},
])
pii, pid = create_form('[BT] H pii', 'bt-h-pii', [
    {'key':'F','type':'file','label':'Documento personal','pii':True,'validations':{'max_size_mb':2,'max_files':1,'accept':[]}},
], owner=2)

anon = vf.Http()
def submit_files(form_slug, form_id, field_id, files):
    s, h, _ = anon.get('/f/' + form_slug); tok = token_from(h)
    return post_multipart(anon, '/f/' + form_slug, {'_token':tok}, [('field_%d[]'%field_id, n, d, c) for n,d,c in files])

def last_meta(form_id, field_id):
    row = vf.db1(f'select id,value from answers where field_id={field_id} and submission_id in (select id from submissions where form_id={form_id}) order by id desc limit 1')
    if not row: return None, None
    try: return int(row[0]), json.loads(row[1])
    except Exception: return int(row[0]), None

try:
    n0 = int(vf.db1(f'select count(*) from submissions where form_id={gen}')[0])
    code, loc, body = submit_files('bt-h-uploads', gen, gid['F'], [
        ('informe.pdf', pdf.read_bytes(), 'application/pdf'),
        ('foto.png', png.read_bytes(), 'image/png'),
        ('carta.docx', docx.read_bytes(), 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
    ])
    n1 = int(vf.db1(f'select count(*) from submissions where form_id={gen}')[0])
    aid, metas = last_meta(gen, gid['F'])
    metas = metas or []
    R.check('H1', code == 302 and n1 == n0 + 1 and len(metas) == 3, f'POST {code}; guardados={len(metas)}')

    n0 = int(vf.db1(f'select count(*) from submissions where form_id={gen}')[0])
    codes = []
    for name, data in [('malo.exe', exe.read_bytes()), ('malo.php', php.read_bytes()), ('malo.js', js.read_bytes())]:
        c, _, _ = submit_files('bt-h-uploads', gen, gid['F'], [(name, data, 'application/octet-stream')])
        codes.append(c)
    n1 = int(vf.db1(f'select count(*) from submissions where form_id={gen}')[0])
    R.check('H2', n1 == n0 and all(c in (200,422) for c in codes), f'códigos={codes}; respuestas {n0}->{n1}')

    n0 = int(vf.db1(f'select count(*) from submissions where form_id={gen}')[0])
    c, _, b3 = submit_files('bt-h-uploads', gen, gid['F'], [('falso.pdf', fake_pdf.read_bytes(), 'application/pdf')])
    n1 = int(vf.db1(f'select count(*) from submissions where form_id={gen}')[0])
    R.check('H3', c in (200,422) and n1 == n0, f'.exe renombrado: {c}, respuestas {n0}->{n1}')

    n0 = int(vf.db1(f'select count(*) from submissions where form_id={lim}')[0])
    c, _, b4 = submit_files('bt-h-limits', lim, lid['F'], [('grande.pdf', big_pdf.read_bytes(), 'application/pdf')])
    n1 = int(vf.db1(f'select count(*) from submissions where form_id={lim}')[0])
    R.check('H4', c in (200,422) and n1 == n0 and re.search(r'tama|MB|grande', b4, re.I) is not None, f'1,2MB con máximo 1MB: {c}, {n0}->{n1}')

    n0 = int(vf.db1(f'select count(*) from submissions where form_id={lim}')[0])
    c, _, b5 = submit_files('bt-h-limits', lim, lid['F'], [('a.pdf', pdf.read_bytes(), 'application/pdf'), ('b.pdf', pdf2.read_bytes(), 'application/pdf')])
    n1 = int(vf.db1(f'select count(*) from submissions where form_id={lim}')[0])
    R.check('H5', c in (200,422) and n1 == n0 and re.search(r'archivo|cantidad|m[aá]ximo', b5, re.I) is not None, f'dos con máximo 1: {c}, {n0}->{n1}')

    s, h, _ = anon.get('/f/bt-h-uploads'); tok = token_from(h)
    c, loc, b6 = post_multipart(anon, '/f/bt-h-uploads', {'_token':tok}, [('field_%d[]'%gid['F'], 'enorme.pdf', huge_pdf.read_bytes(), 'application/pdf')])
    R.check('H6', c == 413 and re.search(r'grande|tama|413', b6, re.I) is not None, f'9MB > post_max_size: {c}')

    admin = vf.Http('admin@demo.com')
    code, headers, data = get_bytes(admin, f'/attachments/{aid}')
    R.check('H7', code == 200 and data.startswith(b'%PDF') and 'attachment' in headers.get('Content-Disposition','').lower(), f'descarga {code}, {len(data)} bytes')

    stored = (metas[0] or {}).get('stored') if metas else ''
    disk = ROOT / 'storage' / 'uploads' / stored
    direct_code, _, _ = get_bytes(anon, '/storage/uploads/' + stored)
    R.check('H8', bool(stored) and disk.is_file() and 'public' not in str(disk) and direct_code in (403,404), f'stored={stored}, disco={disk.is_file()}, URL directa={direct_code}')

    # H9 delete response and run the cleaner --dry-run
    # use the last H1 submission's answer id
    s, h, _ = anon.get('/f/bt-h-uploads'); tok = token_from(h)
    sid = int(vf.db1(f'select submission_id from answers where id={aid}')[0])
    s, page, _ = admin.get(f'/forms/{gen}/responses')
    tk = token_from(page)
    s2, h2, _ = admin.req('POST', f'/forms/{gen}/responses/{sid}/delete', {'_token':tk}, follow=False)
    gone = not disk.exists()
    p = subprocess.run(['php','scripts/clean-uploads.php','--dry-run'], capture_output=True, text=True, timeout=120)
    out9 = (p.stdout + p.stderr).strip()
    R.check('H9', gone and stored in out9, f'borrada: archivo_en_disco={not gone}; dry-run={out9[-300:]}')

    # H10 PII attachment: owner without responses.pii gets 403
    n0 = int(vf.db1(f'select count(*) from submissions where form_id={pii}')[0])
    c, _, _ = submit_files('bt-h-pii', pii, pid['F'], [('cedula.pdf', pdf.read_bytes(), 'application/pdf')])
    paid, pmetas = last_meta(pii, pid['F'])
    user = vf.Http('user@demo.com')
    cu, _, du = get_bytes(user, f'/attachments/{paid}')
    ca, _, da = get_bytes(admin, f'/attachments/{paid}')
    R.check('H10', cu == 403 and ca == 200, f'user sin responses.pii={cu}, admin={ca}')

finally:
    pass

print({x:sum(1 for v in R.results.values() if v['status']==x) for x in ['OK','FALLA','N/A','PENDIENTE']})
