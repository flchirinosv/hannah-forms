import sys, subprocess, json, os, urllib.request, urllib.error
sys.path.insert(0, os.path.dirname(__file__))
import vf

def run(R):
    with vf.VF(R) as v:
        # A1
        outs=[]
        for f in ["public/index.php","config/bootstrap.php","public/install.php"]:
            p=subprocess.run(["php","-l",str(vf.ROOT/f)],capture_output=True,text=True)
            outs.append(f"{f}:{p.returncode}")
        R.check("A1", all(x.endswith(":0") for x in outs), ",".join(outs))
        # A2
        r=v.goto("/", wait=200)
        R.check("A2", r.status in (200,302) and ("/login" in v.page.url or "/dashboard" in v.page.url or "/" == v.page.url[len(vf.BASE):]) and "Fatal error" not in v.text(), f"url={v.page.url} status={r.status}")
        # A3
        r=v.goto("/no-existe")
        t=v.text()
        R.check("A3", r.status==404 and ("404" in t or "no encontr" in t.lower()) and v.page.locator("a,button").count()>0, f"status={r.status} texto={t[:120]!r}")
        # A4
        r=v.getreq("/forms")
        R.check("A4", r.status==405 and ("405" in r.text() or "no permitido" in r.text().lower()), f"status={r.status}")
        # A5
        up=vf.ROOT/"storage/uploads"
        R.check("A5", up.is_dir() and os.access(up,os.W_OK), f"{up} wsp={os.access(up,os.W_OK)}")
        # A6 console
        errs=[]
        v.page.on("console", lambda m: errs.append(m.text) if m.type=="error" else None)
        v.page.on("pageerror", lambda e: errs.append(str(e)))
        v.goto("/dashboard", wait=700)
        R.check("A6", not errs, "; ".join(errs[:5]))
        # A7 reload + back
        v.goto("/dashboard"); before=v.page.locator("[data-vf-form-card], .app-form-card, .card").count()
        v.page.reload(); v.page.wait_for_timeout(400)
        after=v.page.locator("[data-vf-form-card], .app-form-card, .card").count()
        v.page.go_back(); v.page.wait_for_timeout(300)
        back=v.page.locator("[data-vf-form-card], .app-form-card, .card").count()
        R.check("A7", after<=before+1 and back<=before+1 and v.page.locator("body").count()==1, f"cards {before}/{after}/{back}")

if __name__=="__main__":
    R=vf.R(); run(R); print(R.report())
