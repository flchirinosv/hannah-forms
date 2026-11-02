import csv, io, json, re, subprocess, sys, uuid
from pathlib import Path
sys.path.insert(0, 'tests/browser')
import vf
R = vf.R()

def sq(v):
    return "'" + str(v).replace("\\", "\\\\").replace("'", "''") + "'"

def create_form(title, slug, fields, owner=2):
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

def token(html):
    m = re.search(r'name="_token"\s+value="([^"]+)"', html)
    return m.group(1) if m else ''

def get(body, label):
    return body

def clear_export_limit():
    vf.mysql("delete from rate_limits where scope='dash:export'")

try:
    fid, ids = create_form('[BT] J pii', 'bt-j-pii', [
        {'key':'N','type':'text','label':'Nombre personal','pii':True,'position':1},
        {'key':'E','type':'email','label':'Correo personal','pii':True,'position':2},
        {'key':'T','type':'tel','label':'Telefono personal','pii':True,'position':3},
        {'key':'C','type':'text','label':'Comentario','position':4},
    ], owner=2)
    anon = vf.Http()
    s, h, _ = anon.get('/f/bt-j-pii'); tk = token(h)
    st, _, _ = anon.req('POST', '/f/bt-j-pii', {
        '_token': tk,
        f'field_{ids["N"]}': 'Persona Secreta',
        f'field_{ids["E"]}': 'juan@ejemplo.com',
        f'field_{ids["T"]}': '+58 412-1234567',
        f'field_{ids["C"]}': 'Comentario publico',
    })
    pii_sub = int(vf.db1(f'select max(id) from submissions where form_id={fid}')[0])
    vf.mysql(f"update submissions set ip_address='192.168.1.77', user_agent='Mozilla/5.0 (BT J)' where id={pii_sub}")

    admin = vf.Http('admin@demo.com')
    user = vf.Http('user@demo.com')
    det = f'/forms/{fid}/responses/{pii_sub}'

    # J1 admin sees the raw values
    s1, h1, _ = admin.get(det)
    j1 = s1 == 200 and all(x in h1 for x in ['Persona Secreta','juan@ejemplo.com','+58 412-1234567','Comentario publico']) and '[oculto]' not in h1
    R.check('J1', j1, f'POST {st}; GET admin {s1}; en_claro={j1}')

    # J2 user sees masked values and the unmarked text raw
    s2, h2, _ = user.get(det)
    j2 = s2 == 200 and '[oculto]' in h2 and 'j***@e***.com' in h2 and 'Comentario publico' in h2 and 'Persona Secreta' not in h2 and 'juan@ejemplo.com' not in h2
    R.check('J2', j2, f'GET user {s2}; oculto={"[oculto]" in h2}, correo={"j***@e***.com" in h2}, libre={"Comentario publico" in h2}')

    # J3 list as user masks the IP (the list shows no answer values)
    s3, h3, _ = user.get(f'/forms/{fid}/responses')
    j3 = s3 == 200 and '192.168.1.x' in h3 and '192.168.1.77' not in h3 and 'enmascarada' in h3
    R.check('J3', j3, f'GET user {s3}; ip_masked={"192.168.1.x" in h3}, a_la_vista={"192.168.1.77" in h3}')

    # J4 CSV as user is masked and still parses to the same columns
    clear_export_limit()
    s4, body4, _ = user.get(f'/forms/{fid}/export.csv')
    rows4 = list(csv.reader(io.StringIO(body4)))
    flat4 = body4
    j4 = s4 == 200 and len(rows4) == 2 and len(rows4[0]) == 6 and '[oculto]' in flat4 and 'j***@e***.com' in flat4 and 'Comentario publico' in flat4 and 'juan@ejemplo.com' not in flat4
    R.check('J4', j4, f'GET {s4}; filas={len(rows4)}, columnas={len(rows4[0]) if rows4 else 0}, oculto={"[oculto]" in flat4}')

    # J5 JSON as user keeps the envelope and masks inside it
    clear_export_limit()
    s5, body5, _ = user.get(f'/forms/{fid}/export.json')
    try:
        j5doc = json.loads(body5)
    except Exception:
        j5doc = {}
    sub5 = (j5doc.get('submissions') or [{}])[0]
    ans5 = sub5.get('answers', {})
    j5 = s5 == 200 and j5doc.get('total') == 1 and len(j5doc.get('submissions', [])) == 1 and ans5.get(str(ids['N'])) == '[oculto]' and ans5.get(str(ids['E'])) == 'j***@e***.com' and ans5.get(str(ids['C'])) == 'Comentario publico'
    R.check('J5', j5, f'GET {s5}; total={j5doc.get("total")}, respuestas={len(j5doc.get("submissions", []))}, nombre={ans5.get(str(ids["N"]))}, correo={ans5.get(str(ids["E"]))}')

    # J6 user sees masked IP and UA; admin sees both raw
    s6u, h6u, _ = user.get(det)
    s6a, h6a, _ = admin.get(det)
    j6 = '192.168.1.x' in h6u and '[oculto]' in h6u and '192.168.1.77' not in h6u and 'Mozilla/5.0 (BT J)' not in h6u and '192.168.1.77' in h6a and 'Mozilla/5.0 (BT J)' in h6a
    R.check('J6', j6, f'user ip={"192.168.1.x" in h6u}, ua_oculto={"[oculto]" in h6u}; admin crudo={"192.168.1.77" in h6a}')

    # J7 >5000 responses: the last keyset batch is masked too
    big, bids = create_form('[BT] J pii masiva', 'bt-j-pii-masiva', [
        {'key':'N','type':'text','label':'Nombre personal','pii':True,'position':1},
    ], owner=2)
    vals = []
    for n in range(5001):
        vals.append(f"({big},'192.168.1.{n % 250 + 1}','BT J7','{uuid.uuid4().hex}')")
    bigsql = 'insert into submissions (form_id,ip_address,user_agent,public_token) values ' + ','.join(vals) + ';'
    p = subprocess.run(['mysql','-h127.0.0.1','-umariadb',f'-p{vf.dbpass()}','vanilla_forms'], input=bigsql, capture_output=True, text=True)
    if p.returncode != 0: raise RuntimeError(p.stderr)
    vf.mysql(f"insert into answers (submission_id,field_id,value) select id,{bids['N']},'secreto masivo' from submissions where form_id={big}")
    clear_export_limit()
    s7, body7, _ = user.get(f'/forms/{big}/export.json')
    try:
        j7doc = json.loads(body7)
    except Exception:
        j7doc = {}
    subs7 = j7doc.get('submissions') or []
    last7 = subs7[-1] if subs7 else {}
    j7 = s7 == 200 and j7doc.get('total') == 5001 and len(subs7) == 5001 and last7.get('answers', {}).get(str(bids['N'])) == '[oculto]' and all(x.get('answers', {}).get(str(bids['N'])) == '[oculto]' for x in subs7)
    R.check('J7', j7, f'GET {s7}; total={j7doc.get("total")}, filas={len(subs7)}, ultima={last7.get("answers", {}).get(str(bids["N"]))}')

    # J8 an unmarked text stays clear in detail and exports
    j8 = 'Comentario publico' in h2 and 'Comentario publico' in flat4 and ans5.get(str(ids['C'])) == 'Comentario publico'
    R.check('J8', j8, f'detalle={"Comentario publico" in h2}; csv={"Comentario publico" in flat4}; json={ans5.get(str(ids["C"]))}')
finally:
    for t in ['[BT] J pii', '[BT] J pii masiva']:
        vf.mysql(f"delete from forms where title={sq(t)}")
    vf.mysql("delete from rate_limits where scope='dash:export'")
