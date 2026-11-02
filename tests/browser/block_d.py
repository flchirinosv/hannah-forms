import json, re, sys, time
sys.path.insert(0, 'tests/browser')
import vf

R = vf.R()
ah = vf.Http('admin@demo.com')

def new_form(title):
    t = ah.csrf(ah.get('/dashboard')[1])
    r = ah.post('/forms', {'_token': t, 'title': title, 'description': ''})
    m = re.search(r'/forms/(\d+)/builder', r[2])
    return int(m.group(1))

def open_builder(v, fid):
    v.goto(f'/forms/{fid}/builder')
    v.page.wait_for_timeout(300)

def add_tile(v, name):
    v.page.get_by_role('button', name='Pregunta', exact=True).first.evaluate('e=>e.click()')
    v.page.wait_for_timeout(150)
    v.page.locator('#addFieldModal .app-type-tile').filter(has_text=name).first.evaluate('e=>e.click()')
    v.page.wait_for_timeout(250)

def save(v):
    v.page.get_by_role('button', name='Guardar', exact=True).first.evaluate('e=>e.click()')
    v.page.wait_for_timeout(1100)

def open_settings(v, n=0):
    v.page.locator('.app-field-tools [data-act=settings]').nth(n).evaluate('e=>e.click()')
    v.page.wait_for_timeout(250)

def close_settings(v):
    v.page.locator('#fieldSettingsModal .btn-close').first.evaluate('e=>e.click()')
    v.page.wait_for_timeout(250)

def fill_form(slug, data):
    h = vf.Http()
    s, html, u = h.get('/f/' + slug)
    tok = re.search(r'name="_token" value="([^"]+)"', html).group(1)
    return h.post('/f/' + slug, {'_token': tok, **data})

vf.mysql("DELETE FROM forms WHERE title LIKE '[BT] D%'")
vf.mysql("DELETE FROM rate_limits WHERE scope LIKE 'dash:create%'")

with vf.VF(R) as v:
    # D1
    fid = new_form('[BT] D1 vacío')
    open_builder(v, fid)
    empty = v.page.locator('#emptyCanvas')
    R.check('D1', v.page.locator('#canvasFields').count() == 1 and empty.count() == 1 and 'Agregar pregunta' in empty.inner_text() and v.page.locator('#builderTitle').count() == 1,
            'lienzo vacío con estado y botón')

    # D2
    fid = new_form('[BT] D2 campo')
    open_builder(v, fid)
    add_tile(v, 'Texto')
    card = v.page.locator('[data-uid]').first
    R.check('D2', card.count() == 1 and card.locator('.app-type-select').count() == 1 and card.locator('[data-act=settings]').count() == 1 and card.locator('[data-act=delete]').count() == 1 and 'Texto' in card.inner_text(),
            'tarjeta con tipo, obligatoria y herramientas')

    # D3
    open_settings(v, 0)
    drawer = v.page.locator('#fieldSettingsModal .modal-dialog')
    is_drawer = drawer.evaluate("e=>e.classList.contains('modal-drawer')")
    centered = drawer.evaluate("e=>e.classList.contains('modal-dialog-centered')")
    R.check('D3', is_drawer and not centered, 'la doc pide cajón derecho; el constructor usa modal centrado (una sola instancia)')

    # D4
    close_settings(v)
    fid = new_form('[BT] D4 persistencia')
    open_builder(v, fid)
    add_tile(v, 'Opción única')
    open_settings(v, 0)
    v.page.fill('#fieldLabel', 'Pregunta D4')
    opts = v.page.locator('#settingsForm input.option-input')
    opts.nth(0).fill('Primera')
    opts.nth(1).fill('Segunda')
    close_settings(v)
    save(v)
    row = vf.db1(f'select label,options from fields where form_id={fid}')
    R.check('D4', row and row[0] == 'Pregunta D4' and 'Primera' in row[1] and 'Segunda' in row[1], 'etiqueta y opciones persisten')

    # D5
    fid = new_form('[BT] D5 orden')
    open_builder(v, fid)
    add_tile(v, 'Texto'); add_tile(v, 'Email')
    save(v)
    before = [r[0] for r in vf.mysql(f'select type from fields where form_id={fid} order by position')]
    v.page.evaluate("""() => { const c=document.querySelector('#canvasFields'); const els=[...c.children].filter(e=>e.dataset.uid); c.appendChild(els[0]); Sortable.get(c).options.onEnd(); }""")
    v.page.wait_for_timeout(250)
    save(v)
    after = [r[0] for r in vf.mysql(f'select type from fields where form_id={fid} order by position')]
    R.check('D5', before == ['text', 'email'] and after == ['email', 'text'], f'orden antes={before} después={after} (drag no soportado; onEnd real)')

    # D6
    fid = new_form('[BT] D6 tipos')
    open_builder(v, fid)
    names = ['Sección de preguntas','Página (salto de paso)','Texto','Párrafo','Email','Número','Desplegable','Opción única','Casillas','Fecha','Escala','URL','Teléfono','Hora','Fecha y hora','Rango','Matriz','NPS','Estrellas','Ranking','Archivo']
    ok6 = True
    for n in names:
        try:
            add_tile(v, n)
        except Exception:
            ok6 = False; break
    R.check('D6', ok6 and v.page.locator('[data-uid]').count() >= 21, f'{len(names)} tipos, tarjetas={v.page.locator("[data-uid]").count()}')

    # D7
    fid = new_form('[BT] D7 tabs')
    open_builder(v, fid)
    add_tile(v, 'Texto')
    open_settings(v, 0)
    v.page.fill('#fieldLabel', 'Conservar D7')
    v.page.locator('#tabLogic').evaluate('e=>e.click()')
    v.page.wait_for_timeout(150)
    v.page.locator('#tabContent').evaluate('e=>e.click()')
    v.page.wait_for_timeout(150)
    kept = v.page.locator('#fieldLabel').input_value() == 'Conservar D7'
    close_settings(v); save(v)
    row = vf.db1(f'select label from fields where form_id={fid}')
    R.check('D7', kept and row[0] == 'Conservar D7', 'cambiar de pestaña conserva contenido')

    # D8/D9
    vf.mysql("DELETE FROM rate_limits WHERE scope LIKE 'submit:%'")
    s, h, u = fill_form('bt-p-blico-simple', {})
    d8 = s == 200 and 'Corrige los siguientes errores' in h and 'Este campo es obligatorio' in h
    s, h, u = fill_form('bt-p-blico-simple', {'field_277208': 'A', 'field_277210[]': 'Sí'})
    d9a = 'Debe tener al menos 2 caracteres' in h
    s, h, u = fill_form('bt-todos-los-tipos', {'field_277211': 'x', 'field_277214': '99'})
    d9b = 'menor o igual a 10' in h
    R.check('D8', d8, 'servidor rechaza obligatorio vacío')
    R.check('D9', d9a and d9b, 'mensajes por tipo: longitud y máximo')

    # D10 required matrix, incomplete
    vf.mysql('update fields set is_required=1 where id=277226')
    s, h, u = fill_form('bt-todos-los-tipos', {'field_277211': 'x', 'field_277226[0]': 'Malo'})
    vf.mysql('update fields set is_required=0 where id=277226')
    R.check('D10', 'Selección inválida en la fila' in h and 'Debe responder todas las filas' not in h, 'la doc espera la fila concreta; el servidor dice «todas las filas» sin nombrar la fila')

    # D11
    vf.mysql("DELETE FROM rate_limits WHERE scope LIKE 'submit:%'")
    s, h, u = fill_form('bt-todos-los-tipos', {'field_277211': 'x', 'field_277229[0]': '1', 'field_277229[1]': '1', 'field_277229[2]': '1'})
    R.check('D11', 'Los órdenes no pueden repetirse' in h, 'ranking duplicado rechazado')

    # D12
    fid = new_form('[BT] D12 pii')
    open_builder(v, fid)
    add_tile(v, 'Texto')
    open_settings(v, 0)
    v.page.locator('#fieldPii').evaluate("e=>{e.checked=true;e.dispatchEvent(new Event('change',{bubbles:true}))}")
    close_settings(v); save(v)
    R.check('D12', vf.db1(f'select is_pii from fields where form_id={fid}')[0] == '1', 'marca PII persiste')

    # D13 section PII control not available
    fid = new_form('[BT] D22 seccion')
    open_builder(v, fid)
    add_tile(v, 'Sección de preguntas')
    if v.page.locator('[data-uid]').count() == 0:
        add_tile(v, 'Sección de preguntas')
    open_settings(v, 0)
    pii_visible = v.page.locator('#fieldPii').evaluate('e=>!!e.offsetParent')
    R.check('D13', not pii_visible, 'sección no ofrece marcar PII')
    close_settings(v)

    # D14
    fid = new_form('[BT] D14 red')
    open_builder(v, fid)
    add_tile(v, 'Texto')
    v.page.route('**/builder/save', lambda r: r.abort())
    save(v)
    R.check('D14', 'Error de conexión al guardar' in v.page.locator('#toastWrap').inner_text(), 'fallo de red se avisa, no dice que guardó')
    v.page.unroute('**/builder/save')

    # D15
    fid = new_form('[BT] D15 recarga')
    open_builder(v, fid)
    add_tile(v, 'Texto')
    v.page.reload(); v.page.wait_for_timeout(400)
    R.check('D15', v.page.locator('[data-uid]').count() == 0, 'recargar sin Guardar pierde el cambio')

    # D16 direct self-reference
    fid = new_form('[BT] D16 ciclo')
    open_builder(v, fid)
    add_tile(v, 'Texto'); save(v)
    fid_field = int(vf.db1(f'select id from fields where form_id={fid}')[0])
    csrf = v.page.locator('#builderApp').get_attribute('data-csrf')
    payload = {'title': '[BT] D16 ciclo', 'description': '', 'status': 'draft', 'settings': {}, 'fields': [
        {'id': fid_field, '_uid': 'field-1', 'type': 'text', 'label': 'Ciclo', 'placeholder': '', 'help_text': '', 'is_required': False, 'is_pii': False, 'show_header': False, 'options': [], 'validations': {}, 'parent_id': None,
         'logic': {'operator': 'all', 'rules': [{'field_id': fid_field, 'op': 'is', 'value': 'x'}]}}]}
    resp = v.ctx.request.post(vf.BASE + f'/forms/{fid}/builder/save', data=json.dumps(payload), headers={'Content-Type': 'application/json', 'X-CSRF-Token': csrf})
    R.check('D16', resp.status >= 400, f'ciclo autorreferente rechazado con HTTP {resp.status}')

    # D17-D21
    fid = new_form('[BT] D17 pii ui')
    open_builder(v, fid)
    add_tile(v, 'Texto')
    open_settings(v, 0)
    v.page.fill('#fieldLabel', '¿Tu nombre y apellidos?'); v.page.wait_for_timeout(250)
    shown = 'd-none' not in (v.page.locator('#piiSuggestWrap').get_attribute('class') or '')
    not_checked = not v.page.locator('#fieldPii').is_checked()
    R.check('D17', shown and not_checked, 'la etiqueta «nombre» no dispara el aviso: PII_HINT_RE usa nomb[re], que no casa con «nombre»')
    # D18-D20 with a label the current regex does match
    v.page.fill('#fieldLabel', 'Tu correo'); v.page.wait_for_timeout(250)
    shown2 = 'd-none' not in (v.page.locator('#piiSuggestWrap').get_attribute('class') or '')
    if shown2:
        v.page.locator('#piiSuggestWrap').get_by_role('button', name='Sí, es dato personal').evaluate('e=>e.click()')
        v.page.wait_for_timeout(150)
    marked = v.page.locator('#fieldPii').is_checked()
    close_settings(v); save(v)
    R.check('D18', shown2 and marked and vf.db1(f'select is_pii from fields where form_id={fid}')[0] == '1', 'Sí marca y guarda PII (con «correo»)')
    open_settings(v, 0)
    v.page.fill('#fieldLabel', 'Tu correo'); v.page.wait_for_timeout(250)
    # uncheck to make the suggestion eligible again
    v.page.locator('#fieldPii').evaluate("e=>{e.checked=false;e.dispatchEvent(new Event('change',{bubbles:true}))}")
    v.page.fill('#fieldLabel', 'Tu correo'); v.page.wait_for_timeout(250)
    warned = 'd-none' not in (v.page.locator('#piiSuggestWrap').get_attribute('class') or '')
    if warned:
        v.page.locator('#piiSuggestWrap').get_by_role('button', name='No es', exact=True).evaluate('e=>e.click()')
        v.page.wait_for_timeout(150)
    hidden_after_no = 'd-none' in (v.page.locator('#piiSuggestWrap').get_attribute('class') or '')
    R.check('D19', warned and hidden_after_no, 'No es descarta el aviso')
    v.page.fill('#fieldLabel', 'Mi correo'); v.page.wait_for_timeout(250)
    again = 'd-none' not in (v.page.locator('#piiSuggestWrap').get_attribute('class') or '')
    R.check('D20', again, 'al reescribir la etiqueta vuelve el aviso')
    v.page.fill('#fieldLabel', 'Nombre de la empresa'); v.page.wait_for_timeout(250)
    shown_emp = 'd-none' not in (v.page.locator('#piiSuggestWrap').get_attribute('class') or '')
    R.check('D21', shown_emp and not v.page.locator('#fieldPii').is_checked(), '«Nombre de la empresa» tampoco dispara el aviso por el mismo patrón roto')
    close_settings(v)

    # D22 section has no PII controls/warning
    open_builder(v, vf.FIX['forms']['multi'])
    v.page.locator('.app-canvas-section .app-field-tools [data-act=settings]').first.evaluate('e=>e.click()')
    v.page.wait_for_timeout(250)
    R.check('D22', 'd-none' in (v.page.locator('#piiSuggestWrap').get_attribute('class') or '') and not v.page.locator('#fieldPii').evaluate('e=>!!e.offsetParent'), 'sección/página sin aviso PII')
    close_settings(v)

    # D23 actual has no embedded/live preview in settings
    v.page.get_by_role('button', name='Configuración', exact=True).first.evaluate('e=>e.click()'); v.page.wait_for_timeout(250)
    preview = v.page.locator('#formSettingsModal .vf-preview, #formSettingsModal [id*=preview]').count()
    R.check('D23', preview > 0, 'la doc espera preview que cambie al instante; el constructor ya no tiene preview embebido')
    # D24
    v.page.locator('#stFont').select_option('Inter'); v.page.wait_for_timeout(200)
    note = v.page.locator('#stFontNote')
    R.check('D24', 'text-success' in (note.get_attribute('class') or '') and 'se sirve desde esta instalación' in note.inner_text(), 'nota verde de fuente local')
    # D25
    v.page.locator('#stFont').select_option(''); v.page.wait_for_timeout(200)
    note_default = 'Predeterminada' in note.inner_text()
    _, fill_html, _ = vf.Http().get('/f/bt-p-blico-simple')
    R.check('D25', note_default and '@font-face' not in fill_html, 'por defecto sin @font-face en el publicado')
    v.page.locator('#formSettingsModal .modal-footer .btn-outline-secondary, #formSettingsModal .modal-footer .btn-secondary').first.evaluate('e=>e.click()')
    v.page.wait_for_timeout(200)

    # D26 ficha: email field labels in datalist
    fid = new_form('[BT] D26 email')
    open_builder(v, fid)
    add_tile(v, 'Email')
    open_settings(v, 0); v.page.fill('#fieldLabel', 'Correo D26'); close_settings(v); save(v)
    s, h, u = ah.get(f'/forms/{fid}')
    datalist = re.search(r'<datalist id="confirmFieldList">(.*?)</datalist>', h, re.S)
    R.check('D26', bool(datalist and 'Correo D26' in datalist.group(1)), 'lista de campos email para confirmación')

    # D27: settings numeric inputs carry help/placeholder
    nums = re.findall(r'<input[^>]*type="number"[^>]*>', h)
    has_help = all(('placeholder=' in n or 'title=' in n) for n in nums) and ('fa-circle-question' in h or 'form-text' in h)
    R.check('D27', has_help, f'{len(nums)} campos numéricos con contexto')

print({'OK': sum(1 for x in R.results.values() if x['status']=='OK'), 'FALLA': sum(1 for x in R.results.values() if x['status']=='FALLA'), 'N/A': sum(1 for x in R.results.values() if x['status']=='N/A'), 'PENDIENTE': sum(1 for x in R.results.values() if x['status']=='PENDIENTE')})
