#!/usr/bin/env python3
"""Bloque P: ayuda con tooltip (app-help)."""

import re, sys, uuid
sys.path.insert(0, "tests/browser")
import vf

R = vf.R()
ah = vf.Http("admin@demo.com")
suf = uuid.uuid4().hex[:8]
made = []

def new_form(title):
    t = ah.csrf(ah.get("/dashboard")[1])
    r = ah.post("/forms", {"_token": t, "title": title, "description": ""})
    m = re.search(r"/forms/(\d+)/builder", r[2])
    if m:
        made.append(int(m.group(1)))
        return int(m.group(1))
    return None

def open_builder(v, fid):
    v.goto(f"/forms/{fid}/builder", wait=700)

def open_settings(v):
    v.page.get_by_role("button", name="Configuración", exact=True).first.evaluate("e=>e.click()")
    v.page.wait_for_timeout(350)

def tooltip_text(v):
    t = v.page.locator(".tooltip.show .tooltip-inner")
    return t.first.inner_text() if t.count() else ""

try:
    fid = new_form(f"[BT] P ayuda {suf}")

    with vf.VF(R) as v:
        # P1 — los ajustes van en fila, sin párrafos grises bajo cada control.
        open_builder(v, fid)
        open_settings(v)
        d1 = v.page.evaluate("""() => {
            const m = document.getElementById('formSettingsModal');
            const notes = [...m.querySelectorAll('.form-text')].filter(e =>
                e.innerText.trim() !== '' && !['stFontNote','stAdvanceHint','stAccessHint'].includes(e.id));
            const cols = [...m.querySelectorAll('.row > [class*="col-"]')].filter(e => {
                const r = e.getBoundingClientRect(); return r.width > 0;
            });
            const rows = new Set(cols.map(e => Math.round(e.getBoundingClientRect().top))).size;
            return {notes: notes.map(e => e.innerText.trim().slice(0,60)), cols: cols.length, rows};
        }""")
        R.check("P1", len(d1["notes"]) == 0 and d1["cols"] >= 8, str(d1))

        # P2 — el tooltip conserva `<strong>` y `<code>`.
        v.goto(f"/forms/{fid}", wait=500)
        help2 = v.page.locator("label[for=webhook_allow_private] .app-help")
        help2.evaluate("e => e.dispatchEvent(new MouseEvent('mouseover', {bubbles:true}))")
        v.page.wait_for_timeout(400)
        if not tooltip_text(v):
            help2.evaluate("e => e.focus()")
            v.page.wait_for_timeout(300)
        tt = v.page.locator(".tooltip.show .tooltip-inner")
        strong = tt.locator("strong").first.inner_text() if tt.locator("strong").count() else ""
        code = tt.locator("code").first.inner_text() if tt.locator("code").count() else ""
        R.check("P2", "los dos" in strong and "WEBHOOK_ALLOW_PRIVATE_HOSTS" in code,
                f"strong={strong!r} code={code!r} tooltip={tooltip_text(v)[:100]!r}")

        # P3 — en móvil el clic abre la ayuda (no hay hover).
        v.page.set_viewport_size({"width": 390, "height": 844})
        v.goto(f"/forms/{fid}", wait=500)
        h3 = v.page.locator("#webhook_url + .app-help")
        h3.evaluate("e => e.click()")
        v.page.wait_for_timeout(400)
        t3 = v.page.locator(".tooltip.show .tooltip-inner")
        R.check("P3", t3.count() == 1 and "http" in t3.first.inner_text(),
                f"tooltip={t3.first.inner_text()[:100] if t3.count() else ''!r}")
        v.page.set_viewport_size({"width": 1366, "height": 900})

        # P4 — foco por teclado: el `?` recibe foco y abre la ayuda.
        open_builder(v, fid)
        open_settings(v)
        h4 = v.page.locator("label[for=stPrimary] .app-help")
        h4.evaluate("e => e.focus()")
        v.page.keyboard.press("Enter")
        v.page.wait_for_timeout(350)
        d4 = v.page.evaluate("""() => {
            const e = document.activeElement;
            return {cls: e ? e.className : '', tip: !!document.querySelector('.tooltip.show')};
        }""")
        R.check("P4", "app-help" in d4["cls"] and d4["tip"], str(d4))

        # P5 — el `?` dentro de un label no cambia el interruptor.
        v.goto(f"/forms/{fid}", wait=500)
        sw = v.page.locator("#webhook_allow_private")
        before = sw.is_checked()
        v.page.locator("label[for=webhook_allow_private] .app-help").evaluate("e => e.click()")
        v.page.wait_for_timeout(350)
        after = sw.is_checked()
        tip5 = v.page.locator(".tooltip.show .tooltip-inner").count() == 1
        R.check("P5", before == after and tip5, f"antes={before} despues={after} tooltip={tip5}")

        # P6 — la nota de fuente es estado visible al cambiar, no hover.
        open_builder(v, fid)
        open_settings(v)
        v.page.locator("#stFont").select_option("Inter")
        v.page.wait_for_timeout(300)
        note = v.page.locator("#stFontNote")
        d6 = note.evaluate("e => ({visible: !!e.offsetParent, text: e.innerText.trim(), cls: e.className})")
        R.check("P6", d6["visible"] and "se sirve desde esta instalación" in d6["text"],
                str(d6))

        # P7 — guardar y recargar conserva el ajuste y el texto del `?`.
        title_before = v.page.locator("label[for=stPrimary] .app-help").get_attribute("data-bs-title")
        v.page.locator("#stPrimary").evaluate("e => { e.value = '#123456'; e.dispatchEvent(new Event('input', {bubbles:true})); }")
        v.page.locator("#btnSaveSettings").evaluate("e => e.click()")
        v.page.wait_for_timeout(300)
        v.page.get_by_role("button", name="Guardar", exact=True).first.evaluate("e => e.click()")
        v.page.wait_for_timeout(1400)
        open_builder(v, fid)
        open_settings(v)
        val7 = v.page.locator("#stPrimary").input_value()
        title_after = v.page.locator("label[for=stPrimary] .app-help").get_attribute("data-bs-title")
        R.check("P7", val7.lower() == "#123456" and title_after == title_before,
                f"color={val7} texto_igual={title_after == title_before}")

        # P8 — el `?` mide 1.1rem y el área táctil se amplía con ::after
        # (Lightpanda no calcula tamaños de inline-flex; se ata a la regla CSS).
        css = vf.urllib.request.urlopen(vf.BASE + "/assets/css/app.css", timeout=10).read().decode()
        base = re.search(r"\.app-help\s*\{([^}]*)\}", css)
        after = re.search(r"\.app-help::after\s*\{([^}]*)\}", css)
        btxt = base.group(1) if base else ""
        atxt = after.group(1) if after else ""
        ok8 = ("width: 1.1rem" in btxt and "height: 1.1rem" in btxt
               and "inset: -.45rem" in atxt and "position: absolute" in atxt)
        R.check("P8", ok8,
                f"base={btxt.strip()[:90]!r} after={atxt.strip()[:90]!r} (1.1rem≈17.6px + 7.2px por lado)")

finally:
    try:
        if made:
            vf.mysql("DELETE FROM forms WHERE id IN (" + ",".join(str(x) for x in set(made)) + ")")
    except Exception as e:
        print("cleanup:", e)

print("BLOQUE P:", R.report())
