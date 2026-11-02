import sys, os, re, time
sys.path.insert(0, os.path.dirname(__file__)); import vf
F=vf.FIX; H=vf.Http

def tok(h): return h.csrf(h.get('/dashboard')[1])
def newform(h, title, desc=''):
    t=tok(h); return h.post('/forms', {'_token':t,'title':title,'description':desc})

def run(R):
    ah=H('admin@demo.com')
    # C1: listado con título, estado, fecha y enlace
    s,h,u=ah.get('/dashboard?q=Simple')
    R.check('C1', s==200 and '[BT] Público simple' in h and 'href="/forms/%s"'%F['forms']['simple'] in h and ('Borrador' in h or 'Publicado' in h), f'{s} simple={"simple" in h}')
    # C2: crear abre builder en borrador
    title='[BT] C2 nuevo'
    r=newform(ah,title,'Creado por prueba C2')
    fid=vf.db1(f"SELECT id FROM forms WHERE title='{title}' ORDER BY id DESC LIMIT 1")[0]
    st=vf.db1(f"SELECT status FROM forms WHERE id={fid}")[0]
    s2,h2,u2=ah.get(f'/forms/{fid}/builder')
    R.check('C2', r[0]==200 and '/builder' in r[2] and st=='draft' and s2==200, f'post={r[0]} url={r[2]} status={st} builder={s2}')
    # C3 duplicar
    before=int(vf.db1('SELECT COUNT(*) FROM forms')[0])
    t=tok(ah); rd=ah.post(f"/forms/{fid}/duplicate", {'_token':t})
    after=int(vf.db1('SELECT COUNT(*) FROM forms')[0])
    dup=vf.db1(f"SELECT id,title FROM forms WHERE title LIKE '%C2 nuevo%' ORDER BY id DESC LIMIT 1")
    R.check('C3', after==before+1 and dup and dup[0]!=fid, f'{before}->{after} dup={dup}')
    # C4 límite 30/hora por usuario, con user2
    u2=H('user2@bt.local'); vf.mysql("DELETE FROM rate_limits WHERE scope='dash:create'")
    made=0; blocked=False
    for i in range(31):
        r=newform(u2, f'[BT] C4 {i:02d}')
        if r[0]==200 and '/builder' in r[2]: made+=1
        else:
            blocked=True; break
    R.check('C4', made==30 and blocked, f'creados={made} bloqueado={blocked}')
    vf.mysql("DELETE FROM forms WHERE title LIKE '[BT] C4 %'")
    # C5 menú admin
    s,h,u=ah.get('/dashboard')
    hrefs=sorted(set(re.findall(r'href="(/[^"#?]+)"', h)))
    skip={'/logout','/install.php'}
    bad=[]
    for p in hrefs:
        if p in skip: continue
        rr=ah.get(p)
        if rr[0] not in (200,302): bad.append((p,rr[0]))
    R.check('C5', not bad, f'rutas={len(hrefs)} malas={bad[:5]}')
    # C6 user2 /admin 403
    u2=H('user2@bt.local'); s,h,u=u2.get('/admin')
    R.check('C6', s==403, f'{s}')
    # C7 creado aparece en listado como borrador y ficha abre
    s,h,u=ah.get('/dashboard?q=C2+nuevo')
    s2,h2,u2=ah.get(f'/forms/{fid}')
    R.check('C7', 'C2 nuevo' in h and 'Borrador' in h and s2==200, f'list={s} ficha={s2}')
    with vf.VF(R) as v:
        v.goto('/dashboard')
        # C8 sidebar de escritorio
        css0=open(os.path.join(vf.ROOT,'public/assets/css/app.css')).read()
        R.check('C8', v.page.locator('.app-sidebar').count()==1 and '.app-sidebar { position: sticky' in css0, 'sidebar sticky de escritorio')
        # C9 sin scroll horizontal
        ov=v.page.evaluate("document.documentElement.scrollWidth <= window.innerWidth + 1")
        R.check('C9', ov, f'sin_scroll_horizontal={ov}')
        # C13 cabeceras comparten clases/altura declarada
        css0=open(os.path.join(vf.ROOT,'public/assets/css/app.css')).read()
        has_common=('.app-head-actions' in css0 and ('.app-btn-primary' in css0 or '.app-form-cta' in css0))
        R.check('C13', has_common, 'CTA y cabecera comparten clases y alto declarado')
        # C16 cabeceras y subnavegación alineadas a la derecha por CSS
        css=open(os.path.join(vf.ROOT,'public/assets/css/app.css')).read()
        R.check('C16', '.app-head-actions' in css and 'justify-content: flex-end' in css and '.app-admin-nav' in css, 'reglas de alineación presentes')
        # móvil
        v.page.set_viewport_size({'width':390,'height':844}); v.goto('/dashboard')
        ham=v.page.locator('[data-bs-toggle=offcanvas]').first
        ham.evaluate('e=>e.click()'); v.page.wait_for_timeout(300)
        oc=v.page.locator('.app-sidebar')
        vis=oc.evaluate("e=>getComputedStyle(e).visibility") if oc.count() else 'none'
        cls=oc.get_attribute('class') if oc.count() else ''
        R.check('C10', 'show' in cls or vis=='visible', f'class={cls} vis={vis}')
        txt=v.text()
        R.check('C11', 'Nuevo formulario' in txt and 'Admin Demo' in txt and 'Dashboard' in txt, 'textos del menú/usuario presentes')
        # C12: botones de contenido solo icono vs ancho completo: comprobar clases de utilidad
        cssm=open(os.path.join(vf.ROOT,'public/assets/css/app.css')).read()
        R.check('C12', 'font-size: 0' in cssm and ':has(i[class*="fa-"])' in cssm, 'botones de contenido con icono se reducen a icono')
        s,h,u=ah.get('/admin')
        # C14/C15
        R.fail('C14', 'la subnavegación de /admin conserva los rótulos en móvil; la doc espera solo iconos')
        R.check('C15', 'app-btn-create' in h and 'badge' in h and '.badge { font-size: 0.7rem; }' in cssm, 'crear con icono y badges legibles')

R=vf.R(); run(R); print(R.report())
