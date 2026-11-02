import sys, os, json, re, time, subprocess, random
sys.path.insert(0, os.path.dirname(__file__))
import vf

def clear_rate(scope=None, ident=None):
    where=[]
    if scope: where.append(f"scope='{scope}'")
    if ident: where.append(f"identifier='{ident}'")
    sql="DELETE FROM rate_limits" + (" WHERE "+" AND ".join(where) if where else "")
    vf.mysql(sql)

def run(R):
    # B1-B4 with registration open
    vf.mysql("INSERT INTO app_settings (key_name,value) VALUES ('registration_enabled','1') ON DUPLICATE KEY UPDATE value='1'")
    email=f"btreg{random.randint(10000,99999)}@bt.local"
    minphp=subprocess.run(["php","-r","require 'config/bootstrap.php'; echo App\\Controllers\\AuthController::PASSWORD_MIN;"],cwd=vf.ROOT,capture_output=True,text=True).stdout.strip()
    with vf.VF(R, email=None) as v:
        v.goto("/register")
        ph=v.page.locator("#password").get_attribute("placeholder") or ""
        ml=v.page.locator("#password").get_attribute("minlength") or ""
        R.check("B4", str(minphp) in ph and ml==str(minphp), f"placeholder={ph!r} minlength={ml} server={minphp}")
        v.page.fill("#name","BT Corta"); v.page.fill("#email",f"btcorta{random.randint(1000,9999)}@bt.local"); v.page.fill("#password","123456789"); v.page.fill("#password_confirm","123456789")
        v.page.click("button[type=submit]"); v.page.wait_for_timeout(500)
        R.check("B2", "/register" in v.page.url and ("mínimo" in v.text().lower() or "minimo" in v.text().lower() or "10" in v.text()), v.text()[:160])
        v.page.fill("#name","BT Registro"); v.page.fill("#email",email); v.page.fill("#password","password123"); v.page.fill("#password_confirm","password123")
        v.page.click("button[type=submit]"); v.page.wait_for_timeout(700)
        R.check("B1", "/dashboard" in v.page.url and vf.db1(f"SELECT id FROM users WHERE email='{email}'") is not None, f"url={v.page.url}")
    with vf.VF(R, email=None) as v:
        v.goto("/register"); v.page.fill("#name","BT Dup"); v.page.fill("#email",email); v.page.fill("#password","password123"); v.page.fill("#password_confirm","password123")
        v.page.click("button[type=submit]"); v.page.wait_for_timeout(500)
        t=v.text()
        R.check("B3", "/register" in v.page.url and ("ya existe" in t.lower() or "no se pudo" in t.lower() or "cuenta" in t.lower()), t[:180])
    # B5/B6 uniform
    with vf.VF(R, email=None) as v:
        clear_rate('login'); clear_rate('login_email')
        v.goto("/login"); v.page.fill("#email","admin@demo.com"); v.page.fill("#password","malamala"); v.page.click("button[type=submit]"); v.page.wait_for_timeout(400)
        t1=v.text()
        clear_rate('login'); clear_rate('login_email')
        v.goto("/login"); v.page.fill("#email","noexiste@bt.local"); v.page.fill("#password","malamala"); v.page.click("button[type=submit]"); v.page.wait_for_timeout(400)
        t2=v.text()
        import re as _re
        m1=_re.search(r'(Credenciales[^\n]*|Email o contraseña[^\n]*|incorrect[^\n]*)', t1, _re.I)
        m2=_re.search(r'(Credenciales[^\n]*|Email o contraseña[^\n]*|incorrect[^\n]*)', t2, _re.I)
        R.check("B5", bool(m1) and "no existe" not in t1.lower() and "no encontrado" not in t1.lower(), (m1.group(0) if m1 else t1[:120]))
        R.check("B6", (m1.group(0) if m1 else "") == (m2.group(0) if m2 else "x") and (m2 is not None), f"iguales={ (m1.group(0) if m1 else '') == (m2.group(0) if m2 else '') }")
    # B7 rate limit
    with vf.VF(R, email=None) as v:
        clear_rate('login'); clear_rate('login_email')
        last=""
        for i in range(6):
            v.goto("/login"); v.page.fill("#email","admin@demo.com"); v.page.fill("#password","mala"); v.page.click("button[type=submit]"); v.page.wait_for_timeout(250); last=v.text()
        R.check("B7", ("demasiados" in last.lower() or "intentos" in last.lower()) and "/login" in v.page.url, last[:160])
        clear_rate('login'); clear_rate('login_email')
    # B8 forgot uniform
    with vf.VF(R, email=None) as v:
        def fp(mail):
            v.goto("/forgot-password"); v.page.fill("#email",mail); v.page.click("button[type=submit]"); v.page.wait_for_timeout(500); return v.page.url, v.text()
        u1,t1=fp("admin@demo.com"); u2,t2=fp("nadie@bt.local")
        R.check("B8", u1==u2 and t1.strip()==t2.strip(), f"urls={u1}=={u2} textos={t1[:60]!r}/{t2[:60]!r}")
    # B9/B10 reset token
    token=subprocess.run(["php","-r","require 'config/bootstrap.php'; echo App\\Models\\PasswordReset::issue(2);"],cwd=vf.ROOT,capture_output=True,text=True).stdout.strip()
    with vf.VF(R, email=None) as v:
        v.goto("/reset-password?t="+token)
        R.check("B9", "/reset-password" in v.page.url and v.page.locator("#password").count()==1, f"url={v.page.url}")
        v.page.fill("#password","password789"); v.page.fill("#password_confirm","password789"); v.page.click("button[type=submit]"); v.page.wait_for_timeout(600)
        okreset=("contraseña" in v.text().lower() and "/login" in v.page.url)
        v.goto("/reset-password?t="+token)
        R.check("B10", okreset and (v.page.locator("#password").count()==0 or "inválid" in v.text().lower() or "usado" in v.text().lower()), f"reset={okreset} url={v.page.url} texto={v.text()[:120]!r}")
    with vf.VF(R, email="user@demo.com", password="password789") as v:
        login_ok = "/dashboard" in v.page.url
        v.goto("/account/password"); v.page.fill("#current_password","password789"); v.page.fill("#password","password123"); v.page.fill("#password_confirm","password123"); v.page.locator("form").filter(has=v.page.locator("#current_password")).first.evaluate("f => f.requestSubmit()"); v.page.wait_for_timeout(600)
        R.check("B11", login_ok and ("/dashboard" in v.page.url or "/account" in v.page.url), f"login_nueva={login_ok} url={v.page.url}")
    # B12 password change invalidates session (model; no UI for other user)
    with vf.VF(R, email="user2@bt.local") as u:
        r=u.goto("/dashboard"); before=r.status
        subprocess.run(["php","-r","require 'config/bootstrap.php'; App\\Models\\User::updatePassword(%d,'password999');"%vf.FIX['users']['user2']],cwd=vf.ROOT,capture_output=True)
        r=u.goto("/dashboard")
        R.check("B12", before==200 and "/login" in u.page.url, f"antes={before} despues={u.page.url} (no hay UI en /admin; se probó el mecanismo del modelo)")
        subprocess.run(["php","-r","require 'config/bootstrap.php'; App\\Models\\User::updatePassword(%d,'password123');"%vf.FIX['users']['user2']],cwd=vf.ROOT,capture_output=True)
    # B13 anonymous dashboard
    with vf.VF(R, email=None) as v:
        r=v.goto("/dashboard")
        R.check("B13", "/login" in v.page.url, f"url={v.page.url}")
    # B14 deactivate via admin endpoint while session open
    uhttp = vf.Http("user2@bt.local")
    ahttp = vf.Http("admin@demo.com")
    uhttp.get("/dashboard")
    _, ah, _ = ahttp.get("/admin")
    atok = ahttp.csrf(ah)
    r = ahttp.post("/admin/users/toggle", {"_token":atok,"user_id":str(vf.FIX['users']['user2']),"active":"0"}, headers={"X-Requested-With":"XMLHttpRequest"})
    s2, html2, url2 = uhttp.get("/dashboard")
    deact = "/login" in url2 or "iniciar sesión" in html2.lower()
    ahttp.post("/admin/users/toggle", {"_token":atok,"user_id":str(vf.FIX['users']['user2']),"active":"1"}, headers={"X-Requested-With":"XMLHttpRequest"})
    R.check("B14", deact and r[0] in (200,302), f"status={r[0]} url={url2}")
    # B15 logout + back
    with vf.VF(R, email="user2@bt.local") as v:
        v.goto("/dashboard"); v.logout(); v.page.go_back(); v.page.wait_for_timeout(300); v.page.reload(); v.page.wait_for_timeout(400)
        R.check("B15", "/dashboard" not in v.page.url and ("/login" in v.page.url or "iniciar" in v.text().lower()), f"url={v.page.url}")
    # B16-18 registration closed
    vf.mysql("UPDATE app_settings SET value='0' WHERE key_name='registration_enabled'")
    with vf.VF(R, email=None) as v:
        v.goto("/login"); haslink=v.page.locator("a[href='/register']").count()>0
        r=v.goto("/register")
        R.check("B16", not haslink, f"enlace={haslink}")
        R.check("B17", r.status==403 and v.page.locator("#email").count()==0, f"status={r.status}")
        rr=v.getreq("/register")
        R.check("B18", rr.status==403 and "csrf" not in rr.text().lower(), f"status={rr.status} texto={rr.text()[:100]!r}")
    # B19-23 create users from admin
    stamp=str(random.randint(100000,999999))
    with vf.VF(R) as a:
        a.goto("/admin")
        uemail=f"btpanel{stamp}@bt.local"
        r=a.post("/admin/users/create", {"name":"BT Panel","email":uemail,"password":"password123","password_confirm":"password123","role_id":"2"})
        created=vf.db1(f"SELECT id,role_id,is_active FROM users WHERE email='{uemail}'")
        R.check("B19", created is not None, f"http={r.status} fila={created}")
        ah=vf.Http("admin@demo.com"); s_ah, h_ah, u_ah = ah.get("/admin")
        r2=ah.post("/admin/users/create", {"_token":ah.csrf(h_ah),"name":"BT Panel","email":uemail,"password":"password123","password_confirm":"password123","role_id":"2"})
        txt=r2[1]
        R.check("B21", "ya hay una cuenta" in txt.lower() or "ya existe" in txt.lower(), f"admin={s_ah}/{u_ah} post={r2[0]} {txt[:120]}")
        r3=a.post("/admin/users/create", {"name":"BT Corta2","email":f"btcorta{stamp}@bt.local","password":"123456789","password_confirm":"123456789","role_id":"2"})
        R.check("B22", vf.db1(f"SELECT id FROM users WHERE email='btcorta{stamp}@bt.local'") is None, f"http={r3.status}")
        r4=a.post("/admin/users/create", {"name":"BT NoCoincide","email":f"btnc{stamp}@bt.local","password":"password123","password_confirm":"password124","role_id":"2"})
        R.check("B23", vf.db1(f"SELECT id FROM users WHERE email='btnc{stamp}@bt.local'") is None, f"http={r4.status}")
        R.check("B20", created is not None and int(created[1])==2 and int(created[2])==1, f"rol/activo={created}")
        R.check("B25", created is not None and int(created[1])==2 and int(created[2])==1, f"{created}")
        adminemail=f"btadmin{stamp}@bt.local"
        a.post("/admin/users/create", {"name":"BT Admin Nuevo","email":adminemail,"password":"password123","password_confirm":"password123","role_id":"1"})
        R.check("B26", vf.db1(f"SELECT id FROM users WHERE email='{adminemail}' AND role_id=1") is not None, "admin creado")
    with vf.VF(R, email=uemail) as u:
        R.check("B20", created is not None and "/dashboard" in u.page.url, f"login={u.page.url}")
    with vf.VF(R, email=adminemail) as au:
        r=au.goto("/admin")
        R.check("B26", vf.db1(f"SELECT id FROM users WHERE email='{adminemail}' AND role_id=1") is not None and r.status==200 and "Usuarios" in au.text(), f"status={r.status}")
    # B24 approver without admin.users
    with vf.VF(R, email="approver2@bt.local") as ap:
        r=ap.goto("/admin")
        rr=ap.post("/admin/users/create", {"name":"X","email":"x@bt.local","password":"password123","password_confirm":"password123","role_id":"2"})
        R.check("B24", r.status==403 and rr.status==403 and ap.page.locator("input[name=email]").count()==0, f"get={r.status} post={rr.status}")
    # B27 toggle via panel
    original=vf.db1("SELECT value FROM app_settings WHERE key_name='registration_enabled'")[0]
    ahttp = vf.Http("admin@demo.com")
    _, ah2, _ = ahttp.get("/admin")
    r = ahttp.post("/admin/settings/registration", {"_token":ahttp.csrf(ah2),"enabled":"1"}, headers={"X-Requested-With":"XMLHttpRequest"})
    an = vf.Http()
    s, html, url = an.get("/login")
    link = 'href="/register"' in html
    s2, html2, url2 = an.get("/register")
    R.check("B27", r[0]==200 and link and s2==200 and 'name="email"' in html2, f"post={r[0]} link={link} register={s2}")
    vf.mysql(f"UPDATE app_settings SET value='{original}' WHERE key_name='registration_enabled'")

if __name__=="__main__":
    R=vf.R(); run(R); print(R.report())
