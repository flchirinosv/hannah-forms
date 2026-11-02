#!/usr/bin/env python3
"""Bloque AC: el modal de nuevo formulario como galería."""

import json, re, sys, uuid
from pathlib import Path
sys.path.insert(0, "tests/browser")
import vf

R = vf.R()
suf = uuid.uuid4().hex[:8]
created = []


def sq(v):
    return "'" + str(v).replace("\\", "\\\\").replace("'", "''") + "'"


def open_modal(p):
    p.locator("button[data-bs-target='#newFormModal']").first.click()
    p.wait_for_timeout(400)


def form_row(fid):
    return vf.mysql(
        "SELECT title,description FROM forms WHERE id=" + str(fid)
    )[0]


def clean_created():
    if created:
        vf.mysql("DELETE FROM forms WHERE id IN (" + ",".join(map(str, created)) + ")")


try:
    with vf.Browser() as B:
        u = B.session(R, "admin@demo.com")
        with u:
            p = u.page
            u.goto("/dashboard")
            open_modal(p)

            dialog = p.locator("#newFormModal .modal-dialog")
            classes = dialog.get_attribute("class") or ""
            head = p.locator("#newFormModal .modal-header").get_attribute("class") or ""
            R.check("AC1", "modal-dialog-centered" in classes and "modal-lg" in classes
                    and "app-navbar" in head and "text-white" in head,
                    f"dialog={classes} header={head}")

            cards = p.locator("#newFormModal .app-tpl-card")
            names = p.locator("#newFormModal .app-tpl-name").all_inner_texts()
            descs = p.locator("#newFormModal .app-tpl-desc").all_inner_texts()
            counts = p.locator("#newFormModal .app-tpl-q").all_inner_texts()
            covers = p.locator("#newFormModal .app-tpl-cover")
            expected_names = ["Formulario en blanco", "Control de asistencia",
                              "Encuesta de satisfacción", "Feedback de curso (multi-paso)",
                              "Inscripción a evento", "Quiz de conocimiento", "Sondeo rápido"]
            R.check("AC2", names == expected_names and cards.count() == 7
                    and len(descs) == 7 and all("pregunta" in c for c in counts)
                    and covers.count() == 7
                    and p.locator("#newFormModal .app-tpl-type, #newFormModal .app-tpl-types").count() == 0,
                    f"names={names} counts={counts}")

            checked = p.locator("#newFormModal input[name=template]:checked").get_attribute("value")
            blank_card = p.locator("#newFormModal .app-tpl-card").first
            R.check("AC3", checked == "" and "app-tpl-checked" in (blank_card.get_attribute("class") or ""),
                    f"checked={checked!r} clase={blank_card.get_attribute('class')}")

            R.check("AC4", p.locator("#newFormModal input[name=title], #newFormModal textarea[name=description], "
                                    "#newFormModal input[name=description]").count() == 0,
                    "sin campos de título/descripción")

            template_card = p.locator("#newFormModal .app-tpl-card").nth(1)
            template_card.evaluate("e => e.click()")
            p.wait_for_timeout(200)
            v5 = p.locator("#newFormModal input[name=template]:checked").get_attribute("value")
            R.check("AC5", v5 == "control-asistencia"
                    and "app-tpl-checked" in (template_card.get_attribute("class") or "")
                    and "app-tpl-checked" not in (blank_card.get_attribute("class") or ""),
                    f"checked={v5}")

            blank_card.evaluate("e => e.click()")
            p.wait_for_timeout(200)
            v6 = p.locator("#newFormModal input[name=template]:checked").get_attribute("value")
            R.check("AC6", v6 == "" and "app-tpl-checked" in (blank_card.get_attribute("class") or "")
                    and "app-tpl-checked" not in (template_card.get_attribute("class") or ""),
                    f"checked={v6!r}")

            p.locator("#newFormModal button[type=submit]").evaluate("e => e.click()")
            p.wait_for_url(re.compile(r"/forms/\d+/builder"), timeout=10000)
            m = re.search(r"/forms/(\d+)/builder", p.url)
            fid7 = int(m.group(1))
            created.append(fid7)
            title7, desc7 = form_row(fid7)
            R.check("AC7", title7 == "Formulario en blanco"
                    and desc7 == "Empieza desde cero y añade tus propias preguntas.",
                    f"id={fid7} title={title7!r} desc={desc7!r}")

            u.goto("/dashboard")
            open_modal(p)
            p.locator("#newFormModal .app-tpl-card").filter(has_text="Sondeo rápido").evaluate("e => e.click()")
            p.locator("#newFormModal button[type=submit]").evaluate("e => e.click()")
            p.wait_for_url(re.compile(r"/forms/\d+/builder"), timeout=10000)
            m = re.search(r"/forms/(\d+)/builder", p.url)
            fid8 = int(m.group(1))
            created.append(fid8)
            title8, desc8 = form_row(fid8)
            fields8 = int(vf.db1(f"SELECT COUNT(*) FROM fields WHERE form_id={fid8}")[0])
            R.check("AC8", title8 == "Sondeo rápido"
                    and desc8.startswith("Tres preguntas cortas")
                    and fields8 == 3,
                    f"id={fid8} title={title8!r} desc={desc8[:45]!r} fields={fields8}")

            u.goto("/dashboard")
            html = p.content()
            R.check("AC9", '<form method="post" action="/forms" class="modal-content border-0">' in html
                    and "modal-dialog-scrollable" in (p.locator("#newFormModal .modal-dialog").get_attribute("class") or "")
                    and p.locator("#newFormModal .modal-body").count() == 1,
                    "form es .modal-content y el diálogo es scrollable")

            open_modal(p)
            # Lightpanda no despacha Tab: se comprueba que el radio no está
            # display:none (recibe foco) y que Espacio selecciona la tarjeta.
            p.evaluate("() => document.querySelectorAll('#newFormModal input[name=template]')[1].focus()")
            focus = p.evaluate("() => document.activeElement && document.activeElement.name")
            p.keyboard.press(" ")
            p.wait_for_timeout(200)
            selected = p.locator("#newFormModal input[name=template]:checked").get_attribute("value")
            card_cls = p.locator("#newFormModal .app-tpl-card").nth(1).get_attribute("class") or ""
            R.check("AC10", focus == "template" and selected == "control-asistencia"
                    and "app-tpl-checked" in card_cls
                    and "visually-hidden" in (p.locator("#newFormModal input[name=template]").first.get_attribute("class") or ""),
                    "Tab no emulable en Lightpanda; foco programático + Espacio sí; "
                    f"focus={focus} seleccionado={selected!r}")

            # AC11 — el camino nativo (sin JS) es el POST con el radio marcado.
            ah = vf.Http("admin@demo.com")
            tok = ah.csrf(ah.get("/dashboard")[1])
            before = int(vf.db1("SELECT COALESCE(MAX(id),0) FROM forms")[0])
            s11, _, _ = ah.post("/forms", {"_token": tok, "template": "sondeo-rapido"})
            fid11 = int(vf.db1("SELECT id FROM forms WHERE id>" + str(before) + " ORDER BY id LIMIT 1")[0])
            created.append(fid11)
            title11, desc11 = form_row(fid11)
            fields11 = int(vf.db1(f"SELECT COUNT(*) FROM fields WHERE form_id={fid11}")[0])
            R.check("AC11", s11 == 200 and title11 == "Sondeo rápido"
                    and desc11.startswith("Tres preguntas cortas") and fields11 == 3,
                    f"status={s11} title={title11!r} fields={fields11}")

            # AC12 — la rejilla y el alto del diálogo en móvil son CSS; el motor
            # de layout de este entorno no permite medir columnas.
            css = Path("public/assets/css/app.css").read_text()
            one_col = "grid-template-columns: repeat(auto-fill, minmax(13rem, 1fr));" in css
            mobile = "@media (max-width: 575.98px)" in css and "#newFormModal .modal-content" in css
            R.check("AC12", one_col and mobile,
                    f"rejilla_auto={one_col} media_movil={mobile} (layout no medible)")
finally:
    clean_created()
    R.report()
    print(json.dumps(R.report(), ensure_ascii=False))
