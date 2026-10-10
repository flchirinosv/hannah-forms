# docs/decisiones.md — historial de decisiones (archivo)

Este es el **registro histórico** de los hallazgos y los arreglos de
VanillaForms: por qué se cambió cada cosa, con su fecha y su etiqueta de fase.
Se conserva tal cual se escribió en `AGENTS.md` antes de compactarlo.

- Para la **regla vigente** que hay que respetar al tocar el código: `AGENTS.md`.
- Para el **detalle completo de cada fase** (diagnóstico, datos afectados,
  decisiones del autor): `docs/plan-remediacion-2026-09-27.md`.
- Etiquetas: `P*` = fases del producto (roadmap de encuesta), `F*` = builder/UX,
  `D*` = capa de datos, `B*` = track B (Slack/Turnstile), `V*`/`Q*` = quick wins
  de UI, `2.x`/`3.x` = fases 2 y 3 del plan de remediación.
- **Ojo con los nombres**: el `F0.4` de `docs/plan-remediacion-2026-09-27.md` (el
  arreglo de `builder.js`, «v2026-09-27e») **no** es esta serie. Aquí `F0.4-uno`,
  `F0.4-dos`, `F0.4-tres` y `F0.4-cuatro` son las cuatro tandas del panel sin
  recarga del 2026-10-02, que son posteriores. Cuando una etiqueta aparezca en dos
  documentos, el documento es el que la clarifies.

Todo lo que sigue está **aplicado**; no hay nada pendiente aquí. Se archivó el
2026-09-29 al compactar `AGENTS.md`.

- Todo POST necesita middleware `csrf` (hallazgo C1 histórico: builder quedó expuesto). Los forms HTML mandan `_token` (`Csrf::field()`); el builder JSON usa header `X-CSRF-Token` vía `Csrf::verifyHeader()` — agregar rutas JSON nuevas con ese patrón.
- Migraciones: cada archivo en `database/migrations/` devuelve un closure que recibe `Database`; deben ser idempotentes. NO se envuelven en transacción (DDL hace commit implícito en MariaDB); registro en tabla `migrations` solo tras éxito.
- `RateLimiter` evalúa la ventana de expiración del lado BD (`NOW() - INTERVAL`) para evitar desfases de zona horaria; mantener ese patrón al tocarlo.
- `Request::ip()` solo confía en `X-Forwarded-For`/`X-Real-IP` si `TRUST_PROXY=true` en `.env` (por defecto `false`). Dejarlo `false` salvo que haya un nginx/haproxy de confianza que sobrescriba esos headers; si no, el rate limiting es bypasseable.
- `Auth::user()` cachea el usuario por REQUEST (static), no en sesión; desactivar/degradar a un usuario surte efecto en el siguiente request. `Auth::forgetCache()` existe para limpiarla (tests).
- `Response::*` (redirect/abort/json/...) solo emiten headers+`exit` cuando `PHP_SAPI !== 'cli'`. En el runner (CLI puro) los controllers se invocan sin matar el proceso; bajo `php -S` (SAPI `cli-server`) y FPM el comportamiento es el real. No reintroducir `exit` duro en `Response`.
- El POST `/f/{slug}` ya NO responde el thanks: hace PRG (FASE 4) a `GET /f/{slug}/thanks`, o a `thanks.redirect_url` externa SOLO si el host pasa la allowlist de F5.1 (`RedirectGuard`) — una URL http/https por sí sola ya no basta (ver la entrada de F5.1). Las barreras de calendario/cupo (P6) viven en `FillController::scheduleState`/`quotaExceeded` y se chequean en `show` y en `submit`.
- P7: honeypot = input `_vf_hp` en `views/fill/show.php` (activado por `settings.honeypot.enabled`); en `submit` se chequea ANTES del rate limit y hace `redirectToThanks(...); return;` fingiendo éxito SIN persistir. Embed público = `GET /f/{slug}/embed` (solo si `settings.embed.enabled`) y relaja XFO/frame-ancestors a `'self'` únicamente en ese action (y en `thanks` si el form es embebible), emitiendo headers solo fuera de CLI; el fill normal sigue `DENY`. QR: `libs/qrcode/qrcode.php` (vendored, MIT) + `App\Services\QrCode::svg()` renderiza SVG sin GD; la URL del QR se calcula del lado servidor (`FormController::qrSvg` nunca acepta url del cliente). Clonar = `POST /forms/{id}/duplicate` → `Form::duplicate` (copia campos remapeando `parent_id`, settings y slug único).
- P8: salta-página = condición del campo `page` TRUE → se omite TODO el bloque. `FillController::submit` usa `pagePlan()` + `LogicEvaluator::dependencyOrder($fields, $extraDeps)` (cada pregunta depende de su page) para resolver la condición antes de sus preguntas; los campos de un bloque saltado no se validan/persisten ni son fuente (aunque el cliente envíe valores). OJO: una regla de página que referencie a una pregunta de SU bloque crea un ciclo → `validateStructure` lo rechaza al guardar (caveat documentado). La randomización (`settings.randomize.questions` + `validations.randomize`) SOLO aplica en GET (`show`/`embed`) vía `FillController::randomizeFields`; el re-render de errores del POST NO baraja. Quiz = `validations.correct` (string; checkbox con comas o JSON) + `App\Services\QuizScore::score()` stateless; el thanks se abre con un token de capacidad `?t=` (`submissions.public_token`, 128 bits, único e indexado) y `QuizScore` solo si la submission pertenece al form. OJO: el identificador del thanks NUNCA puede ser `submissions.id` — es un AUTO_INCREMENT recorrible y el thanks es público/sin sesión, así que `?sid=1..N` filtraba la respuesta literal de cada persona que contestó el formulario (`thanks.php` imprime «Tu respuesta» + la correcta). La migración 0003 hace backfill de las filas antiguas (NULL = solo agradecimiento, sin quiz, que es el fallo seguro) y `Submission::create()` acepta el token como 4º argumento opcional; `findWithAnswers(int)` queda solo para el dashboard autenticado, y de cara al thanks se usa `findWithToken(string)`.
- P9: plantillas = JSON DECLARATIVO en `database/templates/*.json` (refs enteros, NO ids de BD) con `App\Services\TemplateService` (`list/load/normalizeStructure/apply/exportPayload`). `load()` filtra la key con `^[a-z0-9][a-z0-9-]{0,60}$` (nada de path traversal). `POST /forms` acepta `template`; si la plantilla falla se borra el formulario recién creado. El modal del dashboard manda el toggle por `dashboard.js` (no inline: CSP) y el cliente fill lee i18n de `data-vf-i18n` en `<form>` (nada de `<script>` inline) con fallback es.
- P9 i18n: `App\Core\I18n` + `app/lang/{es,en}.php`; clave faltante devuelve la clave. Los catálogos DEBEN mantener la paridad de claves (lo verifica `I18nTest`) y el alcance es fill público + gracias/estados + correos (dashboard/builder quedan en español). Q6 (2026-09-28): `resolve()` ganó `?string $requested` y `bool $browser` con la firma clásica `resolve($settings)` SIN CAMBIOS (settings → `app.locale` → `es`), para que emails y middleware no se vean afectados; `I18n::forRequest($settings)` es la puerta del fill y añade: override `?lang=`/campo oculto `lang` en POST → settings.locale → Accept-Language del navegante (SOLO si el form no fija idioma: la elección del dueño manda) → `app.locale` → `es`. `views/fill/show.php` emite `<input type="hidden" name="lang">` para que el POST conserve el idioma renderizado (si no, un `?lang=` compartido se perdía en el re-render de errores), y `redirectToThanks` añade `?lang=` al PRG SOLO cuando el idioma resuelto difiere del que fija el formulario (la URL de gracias queda limpia en el caso normal). El override se valida contra `supportedLocales()`; un `?lang=xx` se ignora. Tests: `I18nTest` (incluye `testResolveUsaNavegante...` y `testForRequest...`).
- P10: `App\Services\Uploader` es el ÚNICO que escribe/borra adjuntos (storage en `paths.uploads` = `storage/uploads/`, JAMÁS dentro de `public/`). `stored` es relativo a la raíz (`YYYY/MM/<hex>.<ext>`) y `absolutePath()` valida patrón + `realpath` dentro de la base. MIME SIEMPRE con `finfo` (nunca `$_FILES['type']`); la extensión efectiva sale del MIME y `DENY_EXT` (php/html/svg/exe/...) se rechaza antes de mirar nada. La descarga es `GET /attachments/{answers.id}?i=N` (owner/editor/admin, `octet-stream` + `nosniff` + `private, no-store` + CSP sandbox) — nunca sirvas `storage/` por el web server. Todo POST del fill con archivo necesita el middleware `post-max` ANTES de `csrf` (si el cuerpo pasa `post_max_size`, PHP vacía `$_POST`/`$_FILES` y sin ese guard se ve un "CSRF inválido" engañoso; responde 413 con `views/errors/413.php`). Al borrar una respuesta, un formulario o un campo en el builder, la purga es **de dos fases** y el orden importa en las dos: `Uploader::pendingFor{Submission,Form,Field}()` DEVUELVE las rutas y tiene que correr **antes** del `DELETE` (los `ON DELETE CASCADE` se llevan los `answers` y `Answer::attachmentValuesBy*` ya no encuentra nada → el binario queda huérfano para siempre), y `Uploader::purgePaths($rutas)` borra del disco **después** del `commit()` (purgar dentro de la transacción deja, si hay rollback, una fila `answers` viva apuntando a un binario inexistente → 404 permanente en `GET /attachments/{id}`). `purgeSubmission/purgeForm/purgeField` son envoltorios de una sola fase: no usarlos tras un DELETE. Nada fuera del dashboard muestra la metadata cruda: todo pasa por `Uploader::displayValue`.
- `Config::get()` SÍ soporta notación de punto (`paths.uploads`, `app.uploads.max_size_mb`, `app.locale`); si añades una clave nueva, accede con esa forma y verifica que no devuelva el default en silencio (fue la causa de que los adjuntos se escribieran en `/uploads`).
- Deploy P10: `php.ini` (`upload_max_filesize`, `post_max_size`) debe admitir el tamaño anunciado, nginx `client_max_body_size` > `post_max_size`, y el usuario del web server necesita escritura en `storage/uploads`.
- SSRF del webhook (2026-09-28, fase 2 · 2.2): `Webhook::fire()` NO hace `file_get_contents` a la URL configurada. `resolveTarget($url, $allowPrivate)` resuelve el host y rechaza loopback, RFC1918, link-local (incluida `169.254.169.254` de la metadata de nube), CGNAT, multicast, reservadas y las IPv4 mapeadas en IPv6 (`::ffff:127.0.0.1`); se rechaza si **cualquiera** de las IP resueltas es privada (rebinding), y un host que no resuelve a ninguna IP tampoco pasa. La conexión se fija a la IP ya validada (TOCTOU) pero el `Host` y el SNI siguen siendo el NOMBRE (`peer_name`), que es lo que permite verificar el TLS de un dominio público al que se entra por su IP. No se siguen redirecciones, se rechazan CR/LF en la URL y credenciales embebidas. Sin `ext-curl` en este entorno, por eso el POST se hace a mano con `stream_socket_client` (si algún día hay curl, `CURLOPT_RESOLVE` es lo equivalente). OJO al tocarlo: (a) el seam `Webhook::setHandler()` está **detrás** de la validación, así que un test con URL de loopback ya no se dispara — usa `Webhook::setResolver()` para fijar la resolución y no depender del DNS real; (b) `webhook.allow_private_hosts` está declarado en el whitelist de `FormSettings::normalize()` porque si no se pierde en cada guardado y el opt-in de red local no sirve; (c) `isBlockedIp()` compara bytes empaquetados, no texto — pasar la IP en texto a `matchesCidr()` hace que nunca case y las mapeadas pasarían por públicas. Red local propia: hacen falta **DOS** opt-ins, no uno — `WEBHOOK_ALLOW_PRIVATE_HOSTS` (config `app.webhook.allow_private_hosts`, lo pone quien opera la instancia) Y `webhook.allow_private_hosts` en los settings del formulario. El AND es lo que cierra el agujero: con el opt-in solo en el formulario, cualquiera con permiso de edición (dueño, editor o admin, y con registro abierto cualquiera) mandaba `{"webhook":{"allow_private_hosts":true}}` al guardado de settings y usaba el webhook como proxy a la red interna; el opt-in del servidor no se puede tocar desde un formulario. OJO: el chequeo va en `fire()` ANTES de resolver y deja rastro en el log. En tests se fija con `Webhook::setAllowPrivate(true|false)` porque `Config` no tiene setter; `null` = se lee de la config (lo limpian `setUp`/`tearDown` de `WebhookSsrfTest`).
- Builder F0/F1 (2026-09-27): el «+» de inserción es **hijo** de la tarjeta que precede y cuelga de su borde inferior (no hermano: SortableJS reordena los hijos de `#canvasFields` y un hermano se separaba de su elemento al arrastrar). El hover es selector de hijo, nunca `~`. El «+» y el «agregar pregunta dentro» de la sección abren el modal `#addFieldModal`, cuya cuadrícula se genera en la vista desde el mismo `$fieldTypes` que la paleta; el contexto marca `[data-vf-structural-part]` para ocultar sección/página dentro de una sección. Tokens de UI en `app.css:6-28` (`--vf-icon-btn`, `--vf-ctrl-h`, `--vf-hint-fs`, `--vf-focus-ring`, `--vf-tile-*`): todo botón de icono mide `--vf-icon-btn`. Spec completa en `docs/design.md`; decisiones y diagnóstico en `docs/builder-ui-plan.md`.
- Builder F2 (2026-09-27): el engranaje de cada tarjeta (`data-act="settings"`) abre la configuración en `#fieldSettingsModal` reubicando la **MISMA** instancia de `#settingsForm` (`moveSettingsTo`), nunca duplicándola: ~45 refs cacheadas en `el` + pills de Bootstrap por selector global (`data-bs-target="#tabContent"`). El panel derecho se conserva y gana un botón de expandir. Ojo al tocarlo: (a) `openFieldSettings` llama a `selectFieldById`, que redibuja el lienzo, así que el opener se localiza por `[data-uid]` al cerrar; (b) `clearSettings()` reubica ANTES de alternar clases; (c) `moveSettingsTo` restaura `scrollY` y el `scrollTop` del contenedor, o la página salta al cerrar. `builder.js` bumpea a `v2026-09-27b`.
- BUG RAÍZ de secciones (2026-09-27, `builder.js` `buildPayload()`): el estado en memoria usa `parentId` (camelCase) y el servidor lee `$item['parent_id']` (snake_case). Mandando `fields` en crudo, la clave llegaba `undefined` y **todas** las preguntas se guardaban como sueltas: al reabrir el builder la sección aparecía vacía y sus preguntas fuera (y por eso la condición «solo ocultaba el título»). `buildPayload()` ahora **mapea explícitamente** al contrato del servidor; es la única frontera entre ambas convenciones, no la cambies a `fields` sin mapear. Lo fija `tests/unit/BuilderPayloadContractTest.php` (compara las claves que el controlador lee con las que el JS serializa; verificado que falla si se revierte). Ojo: `Request::json()` lee `php://input`, no inyectable en CLI, así que el controller `saveStructure()` no es testeable en el runner sin un seam de producción.
- F0.2 (2026-09-27): `applySettings()` NUNCA debe reconstruir `validations` desde cero (`const v = {}`): parte de `preservedValidations(f)` y encima escribe lo que el panel controla. El invariante, fijado por `tests/unit/BuilderValidationsContractTest.php`: una clave en `MANAGED_VALIDATION_KEYS` DEBE tener un control que la escriba en `applySettings()` (si no, listarla es justo lo que la borra); una clave que NO está en la lista se preserva sola, que es lo correcto para lo que no tiene UI (`max_files`). `text`/`textarea` declaran `validations: ['min_length','max_length']` porque `FillController` solo aplica longitudes a esos dos tipos. Antes, abrir el engranaje de una pregunta de plantilla y teclear borraba el cap y el autosave lo persistía. Ojo: `BuilderPayloadContractTest` compara NOMBRES de clave y no habría detectado esto; el fallo tenía los nombres bien y perdía el VALOR.
- F0.1 (2026-09-27): el ocultado de tiles estructurales dentro de una sección usa `setAttribute('data-vf-structural-hidden','1')`/`removeAttribute` y el selector de `app.css` es `[data-vf-structural-hidden]` (por presencia). NO volver a `toggleAttribute()` con un selector `="1"`: `toggleAttribute` deja el valor `""` y el selector nunca casaba, así que "Sección de preguntas" y "Página" seguían visibles dentro de una sección y al pulsarlos se insertaban en la raíz sin avisar.
- Builder F3 (2026-09-27): la lógica de sección se COMUNICA en el builder sin duplicar la semántica del servidor (chip `.app-logic-inherited` en las hijas, alcance «Se oculta el bloque entero (N)» en la sección, aviso `#logicInheritedNote` en la pestaña Lógica, etiqueta de paleta «Sección de preguntas» sincronizada en `$fieldTypes` + `typeInfo`). `BuilderController::resolveLogic()` ya no devuelve `null` para 4 causas distintas: devuelve `['error' => 'self'|'section'|'missing'|'invalid']` y `logicErrorMessage()` redacta un mensaje que nombra el elemento (tests en `tests/unit/BuilderLogicErrorsTest.php`).
- F0.3 (2026-09-29, mismo descuido que F0.2 dos capas más abajo): que `applySettings()` escriba `v.correct` no basta — el control `#quizAnswer` NO TENÍA listener, así que teclear la respuesta correcta no llamaba a `applySettings()` y el autosave guardaba la validación anterior. El síntoma era «no guarda la respuesta correcta»; el servidor nunca estuvo en medio (`correct` viaja en `validations` y lo lee `QuizScore`). Con el mismo hueco iban `fieldShuffleOps` (`randomize`), `valFileAccept`/`valFileMaxSize`/`valFileMultiple`. Regla: **toda clave en `MANAGED_VALIDATION_KEYS` necesita control Y listener**, y el par clave→control está declarado en `CONTROL_FOR_KEY` de `tests/unit/BuilderValidationsContractTest.php` (que además barre los listeners con `wiredControls()`, así que aguanta tanto el enlace directo como el bucle `forEach` sobre la lista de ids). Texto → `input`, casillas → `change`. `builder.js` bumpea a `v2026-09-29a`.
- Progreso del fill (2026-09-29): el denominador es `progressFieldCards()`, deliberadamente OTRO conjunto que `visibleFieldCards()`. Motivo: `.vf-step-hidden` es de MAQUETACIÓN (solo se ve un paso a la vez), así que filtrar por ella dejaba el denominador reducido al paso a la vista y contestar la primera página ponía la barra al 100%. OJO al escribir el caso de multipágina (`tests/js/fill-progress.js`): si las tarjetas no cuelgan de un `.vf-page-block`, el filtro viejo NO las excluía y el test pasaba verde con el bug puesto. El paso de verdad OMITIDO se marca con `data-vf-skipped` (lo pone `paintSteps`), NO con una clase: el atributo se pone y se quita, y las tarjetas del paso quedan con `vf-hidden`, que es lo que el filtro de progreso ya excluía. `applyCardVisibility()` vive separada de `evaluateConditions()` porque `paintSteps()` también la necesita (antes ocultaba las tarjetas del paso saltado sin poder volver a enseñarlas: un paso que dejaba de aplicar su condición se quedaba invisible para siempre). `isCardAnswered()` es «completa o nada» para matriz y ranking, igual que `validateField` (`val.matrix_all`): con `isEmptyValue(getFieldValue())` una fila de cinco llenaba la barra y el envío se rechazaba por el mismo dato. La visibilidad de la barra se decide DENTRO de `updateProgress()` (toggle, no `add`), para que un formulario que va revelando preguntas no se quede sin barra. `tests/js/fill-progress.js` extrae las funciones reales del JS y las corre contra un DOM mínimo; `tests/unit/FillProgressTest.php` lo invoca con `node` y avisa (sin ponerlo en rojo) si no lo hay — el runner trata un SKIP lanzado desde `setUp()` como ERROR.
- `PublicUrl` (2026-09-29): el enlace que se copia, el QR y el `<iframe>` de embed salen de `App\Core\PublicUrl`, no de `app.url` (cuyo default de desarrollo es `http://127.0.0.1:8080`, así que en cualquier otro host el QR no abría nada a quien lo recibía). Orden de resolución: pin `APP_PUBLIC_URL` (config `app.public_url`) → petición actual (`HTTP_HOST` validado + `HTTPS`/`X-Forwarded-Proto`) → `app.url`. Ojo: (a) el pin vacío NO es «sin URL», es «sin pin» — el seam es `setBaseForTesting(?string $pin, ?string $urlFallback = null)`, donde el segundo argumento anula el último peldaño (`''` = instancia sin URL utilizable) y es lo que permite probar «no hay ninguna base» sin depender del `.env` de la máquina; (b) `X-Forwarded-Proto` solo se lee con `Request::trustsProxy()` (el mismo criterio que `Request::ip()`); (c) `Host` se valida estricto (CR/LF, `@`, espacios, corchetes IPv6 mal cerrados, puertos imposibles) y si no tiene forma de host se cae al peldaño 3, en vez de acabar inyectando texto en un `value=`, un `data-url=` o el QR; (d) `views/dashboard/forms/show.php` avisa con `isLocalBase()` cuando la base resuelta es loopback/RFC1918 o un nombre sin dominio — es un aviso, no un bloqueo.
- Turnstile en multipágina (2026-09-29): `$emitPageFooter` de `views/fill/show.php` tiene que declarar `$turnstile` en su `use`. Una closure solo ve lo que declara: sin él, `!empty($turnstile['enabled'])` leía una variable INDEFINIDA, el widget no se pintaba en el pie del último paso y —como el `submit` sí exigía el token— el formulario se rechazaba a sí mismo con un anti-bot invisible. La rama `!$hasPages` (línea ~580) sí lo tenía; el bug era solo del pie. Ojo al añadir closures que lean variables de la vista: mismo patrón de fallo, sin aviso.
- Lógica de grupo: la regla de la sección y la de su hija se combinan con AND (modelo de la industria: Typeform/Jotform/Cognito/Qualtrics; Google Forms es la excepción porque no tiene reglas por pregunta). Ya lo implementaban `FillController::isFieldVisible()` y `fill.js`; el builder solo lo muestra como estado heredado.
- Gap sin JavaScript CERRADO: `FillController::initialVisibility()` resuelve la visibilidad en el render con las MISMAS reglas del submit (`isFieldVisible` + `pagePlan`) y la vista la marca con `vf-hidden`. `fill.js` recibió `plainFieldCards` para poder LEVANTAR ese `vf-hidden` cuando la condición cambie (si no, una pregunta sin lógica propia dentro de una sección quedaría oculta para siempre). Si añades ocultado en el render, asegúrate de que el cliente pueda revertirlo.
- P9 exports: `GET /forms/{id}/print` (hoja imprimible standalone) y `GET /forms/{id}/export-structure.json` (formato plantilla, round-trip). Los `header()`/`exit` de `exportStructure` van dentro de `if (PHP_SAPI !== 'cli')` para no matar el runner de tests.
- `Response::redirect/abort/json` NO cortan en CLI: si un controller debe detenerse ahí, poner `return;` explícito (si no, el flujo sigue y crea datos fantasma — bug corregido en `FormController::create`).
- F2 (2026-09-28): los parámetros de ruta llegan SIEMPRE como string y las firmas piden `int` (`{id}`/`{sid}`) o `string` (`{slug}`); como `Router.php` es `strict_types=1`, sin conversión `/forms/abc/builder` era un TypeError = HTTP 500 con el stack trace en el cuerpo (fuga de la ruta del servidor). `Router::orderArgs()` convierte según el tipo declarado de la firma y, si no encaja, responde 404 (ese recurso no existe) y **no** llama al controlador. Reglas al añadir una ruta: el tipo declarado debe ser `int`/`float`/`bool`/`string` (o sin tipo); nada de `array`/objetos, que no se convierten. `toInt()` solo acepta `-?\d+` y dentro del rango: rechaza `' 5'`, `'5.0'`, `'1e3'`, `'+5'`, dígitos Unicode y desbordes, porque `is_numeric()` se los traga y darían el id equivocado. `RouterParamsTest` lo fija sobre TODAS las rutas de `config/routes.php`; cambiar `{id}` a `string` "para que PHP deje de quejarse" rompe el test. Si `Response` no corta en CLI, el router ya devuelve `null` de `orderArgs()` y el llamador para.
- CSP + escapado del front (2026-09-28, fase 2 · 2.4/2.5): `app.csp_policy` tiene default SEGURO **en código** (`script-src 'self'`, sin `unsafe-inline`/`unsafe-eval`); `style-src 'unsafe-inline'` se queda a propósito (Bootstrap, `style=""` y el `<style>` de la hoja imprimible). OJO: el default estaba en `.env` de esta máquina, así que el hueco (instalación limpia sin CSP) NO se veía en desarrollo — por eso `testLaCspTieneDefaultSeguroEnCodigo` mira el valor en runtime **y** el default que pasa `config/config.php`; si solo miraras el runtime, vaciar el default no rompería nada aquí. Con `script-src 'self'` la app NO admite `<script>` inline ni `on*=`: el único que quedaba (botón de imprimir) está en `public/assets/js/print.js` vía `data-print`, y `testNingunaVistaTieneScriptInlineNiHandlerEnLinea` recorre `views/` (quitando comentarios) para que nadie reintroduzca uno y rompa la página en silencio. `frame-ancestors` se relaja de `'none'` a `'self'` con un REGEX sobre la política en `FillController::allowEmbedHeaders()` (solo en `/f/{slug}/embed` y en el thanks embebible): el default tiene que seguir casando con ese patrón o el embed se queda bloqueado sin avisar. Y `esc()` (builder.js + dashboard.js) escapa `& < > " '`: antes usaba el truco `textContent`→`innerHTML`, que NO escapa comillas, y su salida iba en atributos entrecomillados con `innerHTML` → `"` cerraba el atributo (XSS con la sesión de quien edita: el builder lo abre cualquiera con rol editor/admin). Invariante: la salida de `esc()` no tiene comillas crudas, así que vale para texto y atributo. Ojo al interpolar en atributo: usa `esc()`, nunca `textContent`, y el `type` de JS también cuenta (un `onerror=` o un `javascript:` en un `data-*` valdría igual).
- `freddy/`, `freddy.zip` y `pub.jar` son un toolkit externo de "levantamiento de información" (genera recommendations/coverage), NO código de la app; no modificar salvo pedido explícito.
- Seed y admin (2026-09-28, fase 2 · 2.3): `database/seed.sql` crea SOLO roles — no reintroducir usuarios ni hashes ahí, aunque sea "para que funcione de una vez": un bcrypt en el repo es una contraseña conocida aunque el archivo esté detrás de un opt-in, y los usuarios demo incluyen un admin. Los usuarios van por `database/seed.php`, que sin `SEED_DEMO=1` no hace nada y sale con **código 1** (a propósito, para que un despliegue que se lo encuentre en la tubería se note). El opt-in se comprueba **antes** del `INSERT`; si alguien lo mueve después, siembra igual y `SeedDemoOptInTest` se pone rojo. Para una instalación real, el primer admin se crea a mano con `scripts/create-admin.php`, que existe justo para que el opt-in no deje la app sin administrador (registrarse por la web da rol `user` y no hay ascenso). OJO: ese script NO acepta `--password=` (queda en el historial del shell y en `ps`) y lo rechaza con un mensaje que dice las tres vías válidas (`ADMIN_PASSWORD`, `--password-stdin`, prompt sin eco) — no lo cambies por un `??` en silencio.
- Auth (2026-09-28, fase 2 · 2.7): el login verifica SIEMPRE una contraseña, exista el usuario o no, contra su hash o contra `AuthController::DUMMY_HASH`. Ese señuelo tiene que ser **bcrypt al coste de `PASSWORD_DEFAULT`**: un hash más barato, o un string que `password_verify` no puede verificar, devolvería antes y volvería a delatar la diferencia de tiempo (medido: 0.016 s vs 0.33 s, un factor 20). No reintroducir `$user === null || !password_verify(...)`: el resultado final es idéntico, así que ningún test de comportamiento lo nota. `PASSWORD_MIN = 10` es una constante compartida con `views/auth/register.php` (si divergen, el navegador acepta 8 y el servidor lo rechaza) y tiene que caber con `password123` (11), que es la contraseña del seed. Rehash oportunista en cada login correcto vía `User::updatePasswordHash()`. `Session::start()` fija `use_only_cookies` **antes** de `session_start()`, no como default global. OJO al medir timings por HTTP: el limitador de intentos se comprueba ANTES de verificar la contraseña, así que a partir del sexto intento ambas rutas responden en ~0.016 s; es un limitador por IP, no por email, pero hay que vaciar `rate_limits` entre parejas al medir.
- `settings.theme.font` (2026-09-28, fase 2 · 2.8): el valor va directo a un `font-family` dentro de un `<style>` que carga el **fill público**, sin sesión ni CSRF, así que sin validación era inyección CSS. `FormSettings::isValidFontFamily()` es lista blanca (`^[A-Za-z0-9][A-Za-z0-9 _.,-]{0,119}$`; el 120 = 40 del recorte de `normalize()` × 3 familias) y se aplica en las DOS puertas: `normalize()` para lo que entra, y `themeCss()` para lo que sale, porque puede haber filas ya guardadas con un valor inválido y filtrar solo al guardar no las limpia. `validate()` mira el **valor crudo**: si mirara el normalizado (que ya viene en `null`) nunca vería el error y quien escribe la tipografía no sabría por qué no se guardó. La fuente se emite **sin comillas** a propósito: el patrón no las admite y el `htmlspecialchars` previo las volvía `&quot;`, que dentro de un `<style>` no se decodifica (nombre de fuente roto, sin error visible).
- Zona horaria (2026-09-28, fase 3 · D6): el contrato tiene TRES puntos y romper cualquiera de los tres falla en silencio. (1) La BD habla UTC: `Database::__construct()` hace `SET time_zone = '+00:00'` porque PDO no tiene parámetro de DSN para la zona y `init_command` es de mysqlnd; sin eso la sesión hereda la del host. (2) PHP habla UTC: `config/bootstrap.php` hace `date_default_timezone_set('UTC')`. (3) La conversión a la zona del lector (config `app.timezone` = `APP_TIMEZONE`, default `America/Caracas`) ocurre SOLO al pintar, en `App\Core\Clock` y los helpers `View::date()/datetime()/relative()/now()/timestamp()`. Antes: PHP en `America/Mexico_City` + sesión en SYSTEM, o sea que imprimir el string crudo se veía bien y solo fallaba la aritmética (5 de 8 submissions reales caían en el día equivocado, y `open_at` de un formulario abría 4 h antes de la hora escrita). **No se migraron los datos y no hay que migrarlos**: los TIMESTAMP existentes son instantes UTC correctos (`strtotime($v.' UTC') === UNIX_TIMESTAMP($v)`), lo que estaba mal era quién los leía; desplazarlos los habría corrompido. OJO al agrupar por día: `Submission::dateBounds()` traduce el filtro a límites UTC con el final EXCLUSIVO (día local = `[04:00, 04:00)` en Caracas) y `dailyStats()` pasa el desplazamiento NUMÉRICO a `CONVERT_TZ`, nunca el nombre de la zona (el nombre exige las tablas de zonas horarias en el servidor y sin ellas devuelve `NULL` sin avisar). `schedule.open_at/close_at` son muros de reloj de un `datetime-local`: se interpretan en `app.timezone` (`FillController::parseScheduleDate`), respetando la zona si el string trae una explícita. `Submission::isValidDay()` rechaza fechas imposibles porque `createFromFormat` "corrige" `2026-02-31` a marzo sin avisar. Un test recorre `views/` y falla si alguna vista pinta una fecha de la BD con `date()`/`strtotime()` a pelo.
- `rate_limits` (2026-09-28, fase 3 · D3): `RateLimiter::cleanExpired()` **no tenía call site** y la tabla crecía para siempre con las IPs de los respondientes (493 filas, +9 por ejecución de tests). Ahora `recordHit()` se lleva una limpieza oportunista (1 % de escrituras, una vez por petición vía `cleanedThisRequest`) con `MAX_WINDOW = 3600 s` — la ventana máxima que admite el limitador y el techo que usa la limpieza; se subió de 600 a 3600 cuando el límite del dashboard (F5.3, ventana por hora) pasó a ser el consumidor más grande. `maybeCleanExpired()` devuelve `?int`: `null` = no se ejecutó, entero = filas borradas. Eso no es purismo: devolver siempre 0 hace imposible distinguir "no limpió" de "no había nada" y un test con `> 0` pasa sin probar nada. El seam `setCleanupProbability()` existe para que un test pueda fijar 100 y comprobar que `recordHit()` REALMENTE limpia (con el 1 % real, el test pasaría igual con la llamada puesta que quitada — verificado). `scripts/clean-rate-limits.php` es el mantenimiento explícito/cron. Los fixtures de los tests de calendario (`ScheduleQuotaTest`) se escriben con `relojLocal()`: `date('Y-m-d H:i:s', time()-3600)` pinta hora UTC y llegaba 4 h al futuro en Caracas, así que la ventana salía invertida.
- `parent_id` (2026-09-28, fase 3 · D5): la columna es `INT UNSIGNED DEFAULT NULL` con FK `fk_fields_parent ON DELETE CASCADE` (migración 0004) y **`0` no es un padre válido**: un AUTO_INCREMENT no puede tener id 0. "Sin sección" es `NULL` y la normalización vive en UN solo sitio, `Field::normalizeParentId()`, usada al leer y al escribir. La app escribía `parent_id = 0` (el builder y varios tests), así que guardar la estructura de un formulario con preguntas sueltas reventaba con un 1452.
- Analítica (2026-09-28, fase 3 · D8): `AnalyticsController::show()` ya NO itera por pregunta: `FieldAggregator::forForm()` devuelve las estadísticas de TODOS los campos con **2 consultas fijas** (`Answer::nonEmptyCountsInForm` + `Answer::valueCountsInForm`), y promedios/min/max/NPS/matriz/ranking se derivan en PHP sobre esas dos tablas. El array tiene EXACTAMENTE la misma forma que devolvían los métodos por campo (la vista `views/analytics/show.php` no cambió y los métodos viejos siguen vivos como referencia del test de equivalencia `AnalyticsAggregationTest`). OJO: (a) `a.value` es TEXT y un `MIN`/`MAX` SQL compara como CADENA — `numberStatsInForm` usa `MIN(a.value + 0)` porque con 10, 9 y 7 devolvía min=10 y max=9; cualquier SQL futuro que derive de `a.value` numérico castea o compara mal. (b) El filtro `from`/`to` de `Answer::queryField()` recorta por DÍA LOCAL (mismos límites UTC que `Submission::dateBounds()`, final exclusivo): concatenar `$from . ' 00:00:00'` cae en el día equivocado para respuestas entre 00:00 y 04:00 UTC. (c) `Submission::withAnswersByIds()` trocea en tandas de `ID_BATCH = 5000` (techo real de MariaDB: 65 535 placeholders) y REORDENA el resultado globalmente (`finished_at DESC, id DESC`): concatenar tandas sin `usort` devuelve las respuestas en orden de tanda. `setIdBatchForTesting()` es el seam de tests. La agregación ES global sobre todas las submissions filtradas; paginar la tabla es una decisión de producto que NO está resuelta (métricas por página vs total). Tests: `AnalyticsAggregationTest` + `SubmissionBatchTest`.
- F5.1 (2026-09-28): `thanks.redirect_url` ya NO se salta con una URL http/https válida: tiene que pasar la allowlist de servidor `RedirectGuard`. Config `app.redirect_allowlist` = `APP_REDIRECT_ALLOWLIST` (hosts separados por coma, sin esquema; **vacío = bloqueado por defecto** → el thanks normal con aviso). `checkedExternal($url, $default)` parses la URL, exige http/https sin credenciales, compara el host EXACTO contra la lista y, si el host NO está, devuelve `$default` (agradecimiento local). `FillController::redirectToThanks` lo usa en submit y en el honeypot. OJO: config vacía no es "todo pasa", es "nada pasa"; quien quiera redirects externos debe listar dominios. Seam `RedirectGuard::setAllowlistForTesting(?array)` (null = leer config; `Config` no tiene setter); `setUp`/`tearDown` lo limpian. Tests: `RedirectGuardTest` (unitaria) + `RedirectAllowlistTest` (FillController con BD).
- F5.3 (2026-09-28): límite del dashboard POR USUARIO (no por IP). `App\Core\DashboardLimit` con `RateLimiter` y ventana `RateLimiter::MAX_WINDOW` (3600 s, ver D3): scope `dash:create` (crear/clonar form y exportar estructura) y `dash:export` (CSV/JSON de respuestas), identifier = `Auth::id()`. Config `app.limits.create_per_hour`/`export_per_hour` = `DASH_CREATE_PER_HOUR` (30) / `DASH_EXPORT_PER_HOUR` (120) en `.env`/`.env.example`. Guardas en `FormController::{create,duplicate,exportStructure}`, `AnalyticsController::exportCsv`, `ResponsesController::exportJson` ANTES del trabajo (no exportas y luego te dicen que no). Superado → `Response::abort(429, ...)` + vista nueva `views/errors/429.php`. En tests el límite se agota/se resetea vía `RateLimiter` con scope+identifier idénticos a producción (`DashboardLimitTest`).
- Smoke de TODAS las rutas (2026-09-28): `tests/unit/ControllerActionsTest.php` recorre `config/routes.php` con el Router REAL (vía `ReflectionProperty(Router::class,'routes')`), concretiza `{id}/{sid}/{slug}` con fixtures, inicia sesión según middleware (`guest`→anónimo, resto→admin), hace CSRF (`_token` en POST o `HTTP_X_CSRF_TOKEN` + `CONTENT_TYPE=application/json` + `setRequestBodyForTests('{}')`), y da FAIL si un controller no produce halt NI salida. `ensureForm()` reconstruye form/field/submission tras rutas destructivas; 2º usuario para `/admin/users/*`. Assert `count($routes) >= 30`. OJO al añadir una ruta: este test la recorre — si necesitas sesión/target distintos, completa `ensureForm`/identifiers. Destapó y arregló: `exportJson`/`exportCsv`/`qrSvg` hacían `header()/exit` incondicionales (mataban al runner en CLI; ahora `PHP_SAPI !== 'cli'`, patrón igual a `exportStructure`) y `Form::duplicate` copiaba `settings` crudo (PDOException 4025 JSON_VALID al duplicar un form sin settings); ahora `json_encode(self::settingsFrom($form))` normaliza con defaults. Seams de observabilidad: `Response::setRecordHalt()/lastHalt()` (en CLI no hay otra forma de ver un redirect/abort).
- `Request::ip()` (2026-09-28): contrato fijado por `RequestIpTest` + seam `Request::setTrustProxyForTesting(?bool)`. `TRUST_PROXY=false` (default) IGNORA `X-Forwarded-For`/`X-Real-IP` y usa `REMOTE_ADDR`. Con `true` confía en la PRIMERA IP válida de `X-Forwarded-For`, cae a `X-Real-IP` si no hay, y `0.0.0.0` si no hay ninguna válida. Quien toque `Request::ip()` debe mantener esa semántica (el rate limit del fill depende de que el spoof no pinte).
- Thumbs webp (2026-09-28): `GET /attachments/{answers.id}?i=N&thumb=1` sirve la miniatura INLINE `image/webp` (Content-Length + readfile) con los mismos headers de seguridad que la descarga, pero ya no como `attachment`; SIN `?thumb=1` la descarga sigue siendo octet-stream. `Answer::attachmentsBySubmission` añade `has_thumb` (criterio idéntico al del download: `!empty($meta['thumb'])`) para que la vista distinga. `views/responses/show.php` pinta `<img class="vf-attachment-thumb" src="/attachments/{aid}?i={n}&thumb=1">` solo para `image/*` con `has_thumb`. El `header()`/readfile va fuera de CLI. Cubierto por `P10UploadsTest` (PNG 1x1 → `image/webp`, `has_thumb=false` en PDF; requiere GD con webp en el entorno).
- Scale (2026-09-28): el tipo `scale` ahora se valida SERVER-SIDE en `FillController` (rama numérica: `is_numeric`, entero, `1..max`, rechaza `3.5`/`abc`/`0`); antes persistía cualquier valor y corrompía la analítica numérica. La vista emite `data-vf-min="1"` / `data-vf-max` en el input y `fill.js` valida en la rama numérica con mensaje `val.scale_range`. Clave nueva en `es.php`/`en.php` Y en el fallback de `fill.js`. Test: `SurveyTypesTest::testValidateScaleRango`.
- fallback i18n de fill.js (2026-09-28): `FALLBACK_I18N` son literales ES HARDCODEADOS (antes se auto-referenciaba: `t()` definía `I18N` a partir de `t()`... de sí mismo). Invariante fijado por `tests/unit/FillJSI18nTest.php`: el fallback no contiene auto-referencias, es subconjunto de `app/lang/es.php` y sin claves muertas, y las claves que usa `t()` existen en `es.php`. Si añades una clave a `t()` en fill.js, hay que sumarla a `es.php` + `en.php` + `FALLBACK_I18N` o el test se pone rojo.
- `scripts/clean-uploads.php` (2026-09-28): complemento de las purgas de P10. `Uploader::pruneEmptyMonths(?string $root = null, bool $dryRun = false)` borra los directorios `YYYY/MM` que quedan VACÍOS tras un purge, y también el año si quedó sin meses; solo toca rutas que sigan el patrón estricto `\d{4}/\d{2}` y que no tengan contenido — carpetas extrañas o con archivos se dejan intactas. `--dry-run` solo cuenta. Es el cron/manual; no duplica la lógica de `absolutePath`.
- `Form::find($id, bool $withFields = true)` (2026-09-28): con `false` se ahorra `Field::byForm`. `ResponsesController::{index,delete}` pasan `false` (el listado y el borrado no pintan campos); `show`/`exportJson` y el resto siguen con `true` (verificado que las vistas de responses usan `$form['fields']`). Si añades una ruta que solo lee el contenedor, pasa `false`.
- Limpieza fase 4 (2026-09-28): fuera del repo `Clock::addDays`, `Env::isLoaded` (+su prop `$loaded`), `Request::uri`, `Answer::valuesByField`, `Submission::countByForm` y `Field::deleteByForm` (sin call sites). NO eran seams: los seams documentados (Webhook::setResolver/setAllowPrivate, Mailer::setTransport, FieldAggregator::setIdBatchForTesting, RateLimiter::setCleanupProbability, Request::setRequestBodyForTests, Request::setTrustProxyForTesting, Response::setRecordHalt, RedirectGuard::setAllowlistForTesting) siguen vivos a propósito. FontAwesome: quedan `css/all.min.css` + `fonts/fa-solid-900.*` + `fa-regular-400.*`; se ELIMINARON brands/v4-shims/svg-with-js y los fonts de brands/v4compat — no reintroducir CDN ni archivos que `all.min.css` no pida (grep de `css/xxx` en views antes de añadir assets).
- F5.2 (2026-09-28) — **caducada el mismo día, resuelta por la línea de RBAC de abajo.** Ese día se dejó `editor` como estaba (acceso de dueño en forms, responses, analytics y adjuntos) con la condición de que se revisaría cuando existiera RBAC. Existía el mismo día: D10 lo invirtó, y `editor` hoy es `user` **sin `forms.delete_own`** y solo sobre sus propios formularios. Se deja la nota porque el caso sigue siendo real —`canAccess()`/`canEdit()` ya no existen, y `App\Core\Access` (dueño && `Auth::can(perm)`, o `forms.manage_all`) es quien decide — y porque dice la condición que se cumplió. **Ojo con lo que NO quedó resuelto:** el middleware `editor` sigue registrado y **no se aplica a ninguna ruta** de `config/routes.php`; su semántica real es la matriz de permisos del panel.
- RBAC (2026-09-28, fase 6): catálogo de permisos en `config/permissions.php` (fuente de verdad: `clave => [desc, roles_default]`) sembrado en DOS copias — la migración 0005 lo lee en runtime y `database/seed.sql` lleva el bloque estático para instalaciones limpias; si cambia una clave o un rol por defecto hay que tocar LOS DOS, lo pinza `PermissionSeedParityTest` (lee seed.sql y compara claves, y user/editor contra el catálogo; admin = todas). Por defecto: admin = todos, user = `dashboard.view` + propios (create/edit_own/delete_own/export_own, analytics/view_own, responses/view_own, attachments/download_own), editor = user SIN `forms.delete_own` (F5.2 queda invertido: editor ya no ve formularios ajenos). `Auth::can()` es la ÚNICA puerta de negocio — NUNCA gates con `role_name`. Permisos efectivos = rol propio + roles de los grupos (`Role::effectivePermissionKeys(roleId, groupIds)` vía `role_groups/role_group_roles/role_group_users`). `App\Core\Access`: `canView/canEdit/canDelete/canExport/canReadOwn` = `ownWith(owner, perm)`: dueño && `Auth::can(perm)` || `Auth::can('forms.manage_all')` — `forms.manage_all` abre el alcance global (seed: solo admin; un grupo no-admin puede otorgarlo). Middleware `admin` = `Auth::can('admin.access')`; `AdminController` vuelve a comprobar cada permiso.
- Matriz de roles (2026-09-28, fase 6): `AdminController::updateRoleMatrix()` RECHAZA un POST sin la estructura `perms[<role_id>][]` (un cuerpo vacío o JSON no significa "desmarcar todo": vaciaba la matriz entera y dejaba todos los roles sin permisos salvo admin.access — lo destapó el smoke `ControllerActionsTest` que postea `{}` a `/admin/roles/save`). El rol `admin` SIEMPRE conserva `admin.access` (se re-añade al guardar) para que el propio panel no sirva para auto-encarcelar al único recurso que puede arreglar la matriz.
- `SecurityLog` (2026-09-28, fase 6): `record(evento, detalle)` NUNCA rompe el request (try/catch + error_log), valida `^[a-z0-9_.]{2,60}$`, ip = `Request::ip()`. OJO: si `Auth::id()` apunta a un usuario inexistente (sesión con usuario borrado, o tests con id ficticio), `user_id` se guarda como NULL — hay un `EXISTS` sobre `users` antes del INSERT; sin él reventaba la FK `fk_sl_user` y el evento se perdía. `knownEvents()` lista los eventos para el filtro de UI; `recent(event, page)` filtra solo por conocidos; `purgeOlderThan(fecha)` es el mantenimiento/cron. Hooks emitidos: `auth.login_ok/fail/register/logout`, `rate_limit.{login,fill,dash_create,dash_export}`, `csrf.invalid` (verifyRequest/verifyHeader al fallar), `webhook.private_host`, `upload.denied_ext`, `form.delete/duplicate`, `admin.group_create/group_delete/role_matrix`.
- Caché dashboard (2026-09-28, fase 6): `Cache::remember(clave, ttl, cierre)` con expiración evaluada en UTC (patrón de RateLimiter); NUNCA rompe el request (si falla, devuelve el computo en vivo). `forgetLike('dash:')` invalida el prefijo entero y lo llaman Form::{create,update,delete,duplicate,setStatus,saveSettings} y Submission::{create,delete} (`bumpDashboardCache`). OJO CRÍTICO MariaDB: un `DELETE` en medio resetea `LAST_INSERT_ID()` a 0 — en `Form::create` y `Submission::create` el `lastInsertId()` se captura ANTES de invalidar la caché (mover la invalidación antes devolvía id 0 → cascada de "ID > 0" + FK 1452 en toda la suite).
- F1 PII (2026-09-29): dos capas, y la segunda es la que protege de verdad. `fields.is_pii` (columna, no clave en `settings`: se consulta por SQL al enmascarar y tiene que ser un hecho de la fila como `is_required`) marca lo que el dueño declara personal; `responses.pii` es la CAPACIDAD de leerlo en claro, y sin sufijo `_own` a propósito porque no decide el alcance (eso es `responses.view_own`) sino qué se lee dentro de lo ya autorizado. Por defecto SOLO `admin`. Tres decisiones que conviene no deshacer: (a) el enmascarado vive en `App\Services\Pii`, no en las vistas, porque hay cuatro canales de salida (detalle, listado, JSON, CSV) y enmascarar solo en la vista deja siempre un canal limpio; (b) UNA sola política para todos los canales — lo que se ve en pantalla es lo que sale en el JSON, porque un `***` en el CSV y el dato real en la pantalla es la forma más fácil de que alguien piense "el export está limpio" y exporte mal; (c) el gate de los ADJUNTOS va en `AttachmentController::download`, no en la vista: `/attachments/{id}` sigue siendo adivinable por el id correlativo de `answers`, así que ocultar solo el nombre del archivo no habría protegido nada. La política por tipo: email `j***@e***.com` y teléfono con solo el prefijo (los últimos 4 bastan para reidentificar), TODO lo demás `[oculto]` entero, incluido el user-agent, que no tiene parte no identificativa. Un campo `file` marcado se oculta entero porque el NOMBRE del archivo es el dato. Falla CERRADO a propósito: `Auth::can('responses.pii')` da false si la clave no está en la matriz, así que saltarse la migración 0006 muestra todo enmascarado en vez de filtrar. `PiiMaskingTest` (17 métodos) cubre las dos ramas del permiso con usuarios reales, no solo la del caso sin permiso.
- Migración 0006 (2026-09-29): siembra el catálogo de permisos LEYÉNDOLO de `config/permissions.php` en runtime, como ya hacía la 0005, y por qué: una clave nueva no llega a la tabla `permissions` si solo se edita el catálogo, porque la 0005 no vuelve a correr en una BD que ya la aplicó, y entonces `Auth::can()` da false para todos y la app funciona "bien" con todo enmascarado, sin un solo error visible. Con el bloque de re-siembra, añadir una clave después no requiere un segundo bloque ni borrar filas de `migrations`: se siembran todas y el `INSERT IGNORE` deja intactas las que ya estaban. Regla general que sale de esto: **nunca borres filas de la tabla `migrations`** para re-siembrar; escribe una migración nueva. Test de guardia: `PiiMaskingTest::testElPermisoResponsesPiiEstaEnLaMatrizDeAdmin`.
- Migration 0005: usar SIEMPRE `CREATE INDEX IF NOT EXISTS` (un `CREATE INDEX` plano revienta con 1061 al re-ejecutar: la migración quedaba "aplicada" pero con la matriz sembrada a medias). Si añades un permiso a la matriz de una BD existente: edita `config/permissions.php` + `seed.sql` y escribe una migración nueva que re-siembre el catálogo (ver 0006); NO borres la fila de `0005` de `migrations` (la 0005 es la historia de esa BD y borrarla la re-ejecuta entera). El seed de `role_permissions` es INSERT IGNORE idempotente.
- Paginación analítica (2026-09-28): la tabla de detalle se pagina EN EL SERVIDOR (15/página): `Submission::byFormFilteredPaginated(form, from, to, page, perPage)` acota la TABLA (los agregados siguen globales); `GET /forms/{id}/analytics/rows` devuelve `{ok, html, pagination, page, pages, total}` y la página 1 se renderiza server-side en `show()` (sin parpadeo); el pager (views/analytics/_pagination.php + `data-paginate-page` en analytics.js + `.vf-loading` en app.css) hace fetch. Envelope idéntico a `byFormPaginated`. Tests: `SubmissionPaginationTest`.
- Sparkline V5 (2026-09-28): `Submission::activityByForms(array $formIds, int $days = 14)` agrupa por DÍA LOCAL (mismo `CONVERT_TZ` + offset numérico que `dailyStats`) en UNA consulta con `IN` de placeholders NOMBRADOS — PDO lanza HY093 si mezclas `?` con `:desfase` en una misma sentencia. Respeta `Cache` del dashboard (el controlador usa `Cache::remember('dash:')`)? NO: la vista pinta el SVG inline desde el array; ids vacíos o `days <= 0` devuelven `['labels'=>[], 'forms'=>[], 'max'=>0]`. Índice 0 = día más viejo, `n-1` = hoy. Tests: `SubmissionActivityTest` (siembra con `finished_at` = MEDIODÍA local convertido a UTC, no con desplazamientos de reloj fijos, o el test depende de la hora UTC del host).
- V6 (2026-09-28): el modal de nuevo formulario usa tarjetas radio (`input[name="template"]` con clase `.d-none` dentro de `.app-tpl-card`) en vez de `<select>`. `TemplateService::list()` ganó la clave `types` (primeros 6 tipos ÚNICOS de campos no-sección, para los chips de la preview — aditiva, `P9TemplatesTest` no la verifica). En `dashboard.js` el bloque P9 DESHABILITA los radios (`.disabled = true` evita que un form en modo "en blanco" mande una plantilla vieja) y togglea `app-tpl-off` para atenuarlas; elegir una tarjeta marca `mode=template` automáticamente. Si vuelves a un `<select>`, el bloque JS actual (`templateInputs`, no `select`) se rompe en silencio.
- B1 Slack/firma/retry (2026-09-28, Track B): `settings.webhook` tiene TRES claves nuevas y todas tienen que estar en el whitelist de `FormSettings::normalize()` o se pierden al guardar: `kind` ('generic' | 'slack'), `secret` (firma) y las dos viejas. `kind: 'slack'` NO es cosmético: Slack ignora nuestro payload, así que `Webhook::slackBody()` lo convierte a mensaje de texto (acotado a 3900 chars CON el sufijo de truncado dentro del tope) y las respuestas anidadas se aplanan con `; `. `webhook.secret` → cabecera `X-VanillaForms-Signature: sha256=<hex>` sobre el body EXACTO que se envía; el receptor recomputa el HMAC. El envío tiene UN retry: `post()` devuelve el CÓDIGO HTTP (parseando la línea de estado, 0 = no conectó) y `fire()` reintenta con `usleep(backoff)` — un 2xx no reintenta y dos fallos no reintentan el tercero. `setHandler()` sigue siendo el seam de "no tocar red" (recibe el payload CRUDO, antes de formatear); para probar el cuerpo ya formateado, la firma y los intentos está `setTransport(?callable $target, $body, $signature, $attempt): int` y `setRetryBackoff(0)` (sin esto la suite espera 1 s por test). Tests: `WebhookSlackTest`.
- B2 Turnstile (2026-09-28, Track B): `settings.anti_bot.enabled` (toggle en el builder) + claves de instancia `TURNSTILE_SITE_KEY`/`TURNSTILE_SECRET_KEY` (config `app.turnstile.*`). Regla de seguridad: sin AMBAS claves, el formulario NO se sirve — `renderAvailabilityGate` pinta `fill/closed` con `status.misconfigured_*` y `submit` corta con 403. "Verificar solo si hay clave" sería dejar el control apagado en silencio. En el submit va TRAS el honeypot y ANTES del rate limit, y un token rechazado hace `renderFillErrors()` (re-render con `err.turnstile` y NADA persistido) porque el token es de un solo uso. OJO al leer la site key: va por `Turnstile::publicSiteKey()` (que honra el seam de tests), NUNCA por `Config::get('app.turnstile.site_key')` directo — así el render sale con la clave vacía en cuanto un test inyecta las suyas. El widget (`div.cf-turnstile` + `api.js` de Cloudflare) solo se pinta con el opt-in puesto, y la CSP de ESA respuesta se relaja en `emitFillCsp()` (añade el origen a `script-src` y `frame-src`); el resto de la app sigue con `script-src 'self'`. Tests: `TurnstileTest` (seams) + `TurnstileFormTest` (fill real con BD: barrera, sin token, token falso, token bueno).
- `defer` fase 6: los scripts no críticos llevan `defer` (dashboard.js; sortable.min.js + builder.js; chart.umd.min.js + analytics.js). OJO: los dos scripts de una pareja dependiente deben tener el MISMO atributo (defer o no), o el segundo ejecuta antes de que cargue la librería (chart/analytics y sortable/builder). `bootstrap.bundle.min.js` + `app.js` y `fill.js` quedan sin defer (críticos); `print.js` ya lo tenía.
- F2 recuperación de contraseña (2026-09-29): migración **0007** (`users.password_changed_at` + tabla `password_resets`), `App\Models\PasswordReset`, `App\Controllers\AccountController` y rutas `/forgot-password`, `/reset-password`, `/account/password`. Cinco decisiones que no conviene deshacer: (a) **el token se guarda hasheado (sha256) y de un solo uso**, y `markUsed()` + `updatePassword()` van en la MISMA transacción en `doReset` — quemarlo antes de guardar dejaría, con un fallo a medias, un enlace muerto y la contraseña sin cambiar; un intento fallido (contraseña corta, distinta, token ajeno) NO lo quema, que es lo que permite reintentar desde el mismo correo; (b) **la respuesta es uniforme** (mismo destino, mismo mensaje) exista o no el email, y un email desconocido NO crea token ni correo: el `password_verify` contra `DUMMY_HASH` solo aplana la parte barata del tiempo (el envío SMTP sigue delatando la diferencia), así que las defensas de verdad son el rate limit y el mensaje único; (c) **`/reset-password` NO lleva middleware `guest`**, porque el enlace del correo se abre en un navegador que puede tener sesión iniciada y `guest` lo mandaría a /dashboard sin mostrar el formulario (el enlace parecería roto); (d) la recuperación **no puede fijar la contraseña que ya tenía** — se compara con el hash, no con un campo del POST: en recuperación no se conoce la actual, así que un campo "la actual es X" solo probaría lo que el propio POST afirma; (e) al completar el cambio se cierra la sesión del navegador que lo hizo con `Auth::revokeCurrentSession()`, que borra `user_id`/`auth_at` y regenera el id pero **conserva el flash** (a diferencia de `logout()`, que destruye la sesión entera y se comería el mensaje de "contraseña cambiada"). Enlace y correo salen de `PublicUrl` y `Mailer`, nunca de `app.url`. Tests: `PasswordResetTest` (18) + `PasswordRecoveryFlowTest` (11, controlador con `Mailer::setTransport` y `Response::lastHalt`).
- F2 revocación de sesiones por instantes (2026-09-29): las sesiones son **ficheros PHP** y no hay tabla de sesión, así que no se pueden revocar por id; lo que las cierra es comparar `Session['auth_at']` contra `users.password_changed_at`. La comparación es **estricta (`auth_at > password_changed_at`) y la IGUALDAD se trata como obsoleta**, porque los dos instantes vienen de relojes distintos (PHP y MariaDB) con resolución de segundo: abrir sesión y cambiar la contraseña en el mismo segundo es indistinguible, y ante esa ambigüedad se cierra la sesión, que es el lado caro de equivocarse (el contrario sería dejar dentro al atacante con la credencial vieja). La contrapartida la cubre `Auth::markSessionFresh()`: login, seam de test y el refresco tras cambiar la contraseña propia adelantan `auth_at` un segundo si hace falta, así que la estrictez no expulsa a nadie legítimo. TRES detalles quecostaron un test rojo cada uno: (1) `User::find()` tiene que seleccionar `password_changed_at`, o `markSessionFresh()` no ve nada, `auth_at` se queda en `time()` a secas y **el login se cierra a sí mismo**; (2) `User::updatePassword()` **devuelve** el `password_changed_at` que escribió, para que quien cambia su propia contraseña refresque con el dato de la BD y no con `time()` (si los relojes no coinciden al segundo, se expulsa del panel en la página siguiente); (3) una sesión **sin `auth_at`** es de antes de F2 y se cierra, con el coste de que al desplegar todo el mundo tenga que entrar otra vez: es el precio de no aceptar sesiones cuyo origen no se puede comprobar. El rehash oportunista del login (`updatePasswordHash`) NO toca `password_changed_at` a propósito: rehashear con el mismo secreto no es cambiar la contraseña, y si lo hiciera cerraría la sesión del que acaba de entrar. Un test que falsase sesión con `Session::set('user_id')` a pelo seguiría en verde probando la rama equivocada (se encuentra sin sesión y todos sus `Auth::can()` dan false): para eso está `Auth::assumeLoggedIn()`.
- F3 revisión y aprobación (2026-09-29): migración **0008** (`forms.status` gana `in_review`; `review_requested_at`, `reviewed_at`, `reviewed_by` con FK `ON DELETE SET NULL` e índice `idx_forms_status_review`; re-siembra el catálogo como la 0006), permiso nuevo `forms.review` (**solo `admin`** por defecto), `App\Controllers\ReviewController` y la bandeja `views/review/inbox.php`. Cuatro decisiones que no conviene deshacer: (a) **opt-in por formulario** (`settings.review.enabled`, default `false`) — con revisión por defecto, todo formulario ya creado dejaría de poder publicarse sin un revisor y en la instalación de UN SOLO USUARIO (el caso normal de un self-hosted) no habría a quién pedirle aprobación: sería un teatro que bloquea al dueño de su propia herramienta sin salida. Por el mismo motivo la **autoaprobación se permite** y se marca con `propio` en el log, que es la pregunta que se hace al auditar un formulario publicado; (b) **la revisión se puede saltar por el builder**, y ese fue el hallazgo difícil: `BuilderController::saveStructure()` acepta `status` en el payload, así que guardar la estructura con `status: "published"` publicaba sin aprobar y convertía la revisión en un adorno. Con revisión activa el estado solo lo mueven las tres acciones de `ReviewController`, y **editar un formulario ya aprobado lo devuelve a la cola** (su contenido dejó de ser el que se aprobó) borrando la aprobación anterior; el `submitForReview()` va DENTRO de la transacción del builder, o queda una fila a medio camino — y la misma regla se repite en `FormController::update()` (cambiar el título por la ficha también es cambiar el contenido aprobado: cerrar solo el builder deja la otra puerta abierta); (c) aprobar exige el **permiso** (`Auth::can('forms.review')`), no `canEdit`: son dos cosas distintas y confundirlas es el error clásico de estos sistemas, y por eso aprobar vive en OTRO controlador y no en `FormController`; (d) en el GET público, `in_review` responde una **página informativa** (`views/fill/in_review.php`) y no un 404 —quien tiene el enlace se lo han mandado por correo y un 404 le haría pensar que está roto—, pero el POST sigue exigiendo `published` (403), porque la página informativa no puede ser el pretexto para aceptar respuestas. Aviso: en el panel es SIEMPRE (filtro, badge, contador y bandeja), por correo es **opt-in** (`settings.review.notify_email`) y apunta al **panel**, no al enlace público. `User::reviewers()` reproduce la regla de permisos efectivos (rol + grupos) en UNA consulta con dos `EXISTS` y filtra `is_active = 1` (avisar a una cuenta desactivada no sirve de nada). OJO al test: `User::reviewers()` devuelve usuarios REALES (el admin del seed puede revisar), así que las aserciones de correo se filtran por `@test.local`. Tests: `FormReviewTest` (21).
- F3 colisión de sesión en `P9TemplatesTest` (2026-09-29, arreglo colateral): sus dos helpers de login falsaban la sesión con `$_SESSION['user_id']` a pelo, lo cual desde F2 es una sesión SIN `auth_at` y por tanto se CIERRA (`Auth::user()` devuelve null y `logout()`). Pasaba solo porque otro test había dejado un `auth_at` suelto en `$_SESSION`; en cuanto un test nuevo hizo `Session::destroy()` en su `tearDown`, P9 se puso rojo con 4 fallos y culpando al culpable equivocado. Arreglo: usar `Auth::assumeLoggedIn()`, que es lo único que fija `user_id` **y** `auth_at` (y el propio AGENTS ya lo decía). Lección para tests nuevos: un `tearDown` con `Session::destroy()` es CORRECTO, y si rompe a otro test es porque ese test depended de un `auth_at` compartido, no porque el nuevo esté mal.
- F4 acceso por email (2026-09-29): migración **0009** (`form_access_emails`, `form_access_tokens`), `App\Models\FormAccess`, `App\Controllers\AccessController`, `App\Core\PublicUrl::accessUrl()` y el gate en `FillController::resolveAccess()`. Se eligieron **tablas desde el principio** y no el `settings` en JSON, aunque con pocos invitados lo parezca más sencillo: la lista se edita, se filtra, se cuenta y se revoca *por email*, y a los 300 invitados un `JSON` dentro de una fila de `forms` deja de ser una lista para ser un campo de texto con permisos de base de datos (índice único, cascada y borrado en un sitio). Cinco decisiones que no conviene deshacer: (a) **`public` es el default** — los tres modos son opt-in porque poner un gate por omisión dejaría sin poder publicar a los formularios ya creados; (b) **el gate va en GET, embed y POST**, y en el POST **antes** del `post-max` y del `csrf`, que si no responderían «algo va mal» a un visitante que no tenía ningún problema: un 403 de acceso tiene que parecer un 403 de acceso; (c) el **`form_id` va en el WHERE** de `findValidToken()` y no se filtra después, porque sin él un enlace sería la llave maestra de todos los formularios restringidos de la instalación — es la mitad de la autorización; (d) el **permiso de sesión guarda el `id` de la fila del token y se revalida contra la BD en cada request**: el primer diseño guardaba solo la caducidad y, cuando el test `testRevocarLaListaMataLosEnlacesYaRepartidos` falló, resultó que revocar un enlace NO expulsaba a quien ya había pasado por él y seguía dentro hasta 30 días después. Guardar el `id` de la fila (y no el token) mantiene la sesión libre de credenciales; (e) `requestAccess()` responde **igual** exista o no el email en la lista — un único `uniformAnswer()` con el mismo flash y el mismo destino — y **registra el intento** en los dos scopes de rate limit: `isAllowed()` lee y `recordHit()` cuenta, así que sin el `recordHit` el límite no existía y la página de identificación era un generador de correo gratis. F4 **no añade permiso**: la allowlist usa `Access::canEdit()`, porque un permiso nuevo hay que sembrarlo en `config/permissions.php` **y** en `database/seed.sql` **y** darle una migración de re-siembra, todo para editar una lista que solo puede ver quien ya puede editar el formulario. La allowlist se edita **en la ficha del formulario** y no en el builder, para que quitar un email (y revocar sus enlaces) se vea junto al formulario y no dentro de un modal de ajustes. `copyLink()` emite para alguien **de la lista** y solo en `allowlist`: emitir para quien fuera convertiría el botón en un generador de accesos y el modo no restringiría nada. OJO al test: los dos scopes de rate limit son **globales y por IP**, y todos los tests salen de `127.0.0.1`, así que `FormAccessTest` limpia `rate_limits` en su `setUp` — sin eso, el cupo de los cinco primeros tests agota el de los siguientes y los correos de los tests siguientes no se envían (y el test parece estar probando el rate limit cuando lo que se rompió es el aislamiento). Tests: `FormAccessTest` (26).
- F5 avance automático (2026-09-29): `settings.advance.auto` (opt-in, default `false`), toggle `stAdvanceAuto` en el builder, atributo `data-vf-auto-advance` en el `#vfForm`, y en `fill.js` la puerta `autoAdvanceAllowed(target)` más `maybeAutoAdvance`/`doAutoAdvance`. Sin migración: es un ajuste de `settings`, que ya es JSON normalizado. Cinco decisiones que no conviene deshacer: (a) **opt-in por formulario**, porque un salto de página automático es la clase de detalle que molesta si aparece sin pedirlo, y en el caso más común (formulario de una sola pregunta, que es el de una página) no hace nada; por eso el interruptor lleva un texto al lado que lo dice, para que no parezca roto; (b) **la completitud la decide `isCardAnswered()`, nunca `checkValidity()`** — un campo de texto con un espacio «cumple» `required` en el navegador, y la respuesta de la validación nativa ni siquiera es la misma entre navegadores, así que un avance automático medido con ella avanza de verdad antes de tiempo; `isCardAnswered()` es además la MISMA medida que el envío y la barra de progreso, y las tres cosas cambian a la vez si algún día se afina. Ojo a un caso en el que completitud y validez se separan de verdad: `isCardAnswered()` mide COMPLETITUD, y un ranking con posiciones repetidas tiene todas las posiciones puestas (la barra lo cuenta) pero el campo ya está en rojo con `val.ranking_unique`, que validan el cliente y `FillController`. Por eso `stepComplete()` pide **las dos** cosas, `isCardAnswered() && validateField(card).length === 0`: el avance automático es lo único que cambia de página sin que la persona pulse nada, así que es justo el momento en que dejar un error en la página anterior es una forma de que no se vuelva a ver. Una **opcional** inválida no bloquea, o el formulario solo se terminaría arreglando una pregunta que no hacía falta responder; (c) **el disparador es `change`, nunca `input`**: en un campo de texto `change` llega al perder el foco o al pulsar Enter, que es «he terminado este campo», y es el evento que el teclado y un lector de pantalla provocan igual que un ratón. Las opciones (radio, checkbox, select) saltan ya; los campos de texto pasan por un temporizador de 350 ms (`AUTO_ADVANCE_DELAY`) que se cancela en cada `change` siguiente, para que quien va contestando con el tabulador no vea la página saltar entre campo y campo; la decisión se vuelve a comprobar DENTRO del temporizador, porque entre el `change` y el salto la persona puede haber tocado otro campo del mismo paso; (d) **el foco va al bloque del paso, no a su primer campo** (`tabindex="-1"` + `aria-label` en `views/fill/show.php`, y `gotoStep()` con `opts.focus`): al cambiar de página la persona todavía no ha leído las preguntas de la nueva, y meterle el cursor en un input la manda a escribir a ciegas. El salto MANUAL no mueve el foco, porque ahí viene de un botón y el foco ya está donde ella lo puso (moverlo en cada «Siguiente» sería tirar al principio de la página). Y el nombre accesible se renumera en `paintSteps()` con los MISMOS dos números que el badge del pie (`rank`/`vis.length`, con `stepLabel()` y las claves `ui.paso`/`ui.de`): si solo se actualizara el badge, al omitirse un paso un lector de pantalla oiría «Paso 2 de 3» en el bloque y «Paso 2 de 2» en el pie de la misma página; (e) **`autoAdvanceReady` se enciende al FINAL del arranque**, después de `restoreDraft()` y `evaluateConditions()`. El borrador local restaura valores, así que un paso puede venir ya completo al cargar; con el mecanismo armado desde el principio, el formulario se recorría solo sin que nadie tocara nada. `goPrev()` lo cancela, porque volver atrás es una decisión consciente y, con un paso ya completo delante, sin eso la persona perdía el sitio en el que estaba. Reglas de la puerta: solo cuentan las obligatorias **visibles** del paso (una opcional no puede bloquear el salto, y si el paso no tiene ninguna obligatoria no hay señal de «terminado» que esperar, así que no se avanza nunca solo); el último paso no (su botón es Enviar); un paso **omitido** no, y `data-vf-skipped` se comprueba **por valor** (`=== '1'`), nunca por presencia, porque `toggleAttribute()` deja el valor en `""`. Tests: `FillAdvanceTest` (12) + el arnés `tests/js/fill-advance.js`, que extrae las funciones REALES de `fill.js` (sin copiar ni una línea, al contrario que el de la barra, que sí imita `getFieldValue`) y las corre contra un DOM mínimo; salta por `node` si no está, como `FillProgressTest`.
- Exportación por tandas (2026-09-30): `ResponsesController::exportJson` y `AnalyticsController::exportCsv` cargaban TODO el histórico en memoria (`Submission::exportPayload` acumulaba submissions + answers de golpe); con 50 000 respuestas, un export era un OOM. Ahora `Submission::exportBatches($formId, $from, $to, $batch = 0)` es un generator que hace keyset `(finished_at, id)` — `ORDER BY s.finished_at DESC, s.id DESC LIMIT $batch` con `AND (s.finished_at, s.id) < (:last_at, :last_id)` — y cede `[$rows, $bySubmission]` por tanda; `countByFiltered()` da el `total` del envelope sin barrer. OJO al placeholder: `Database` usa `PDO::ATTR_EMULATE_PREPARES = false` y reusar `:last_at` dos veces lanza `HY093`, así que la comparación es por TUPLAS (la admite MariaDB), no reutilizando el marcador. El tamaño de tanda es `idBatch()` (`ID_BATCH = 5000`, techo de placeholders) con el seam `setIdBatchForTesting(?int)` — el test usa 7 y verifica el orden GLOBAL a través de las tandas (7, 7, 7, 4), no por tanda. Los dos exports iteran por tandas (en JSON se escribe el envelope a mano y `json_encode` por fila con `JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_*`, sin PRETTY_PRINT por fila; en CSV un solo contador `$n` corre la fila `#` de 1 a N a través de las tandas). La PII se enmascara POR FILA (el `total` no cuenta con ella). `exportPayload` se refactorizó en `exportFormMeta()` + `exportSubmissionRow()` con la MISMA forma, para que `ResponsesTest`/`PiiMaskingTest`/`P9TemplatesTest` no tuvieran que cambiar. Tests: `ExportStreamingTest`.
- El runner carga el `.env` (2026-09-30): `scripts/test.php` hace `Env::load('.env')` justo después del autoload, antes de que ningún test toque `Config`. Motivo: `Config::all()` requiere `config/config.php` UNA vez y cachea el array en `self::$config`, así que la primera llamada congela `DB_PASSWORD` para todo el proceso. El runner antes solo cargaba el autoload, y 7 clases (`I18nTest`, `P7ShareTest`, `P8SmartTest`, `P9TemplatesTest`, `P10UploadsTest`, `QuizScoreTest`, `RandomizeTest` + `FormAccessTest`) lo intentaban arreglar con `if (!class_exists(Config::class)) require bootstrap.php`, que **nunca se ejecutaba**: `class_exists` dispara el autoload y devuelve siempre `true`. Consecuencia medida: con `--filter` estrecho esos tests salían con `Errores: 1` y `SKIP: base de datos no disponible`, y en la suite completa pasaban — es decir, el resultado dependía del orden de ejecución. La carga es del runner, no de un test, para que sea invariante. Los 8 workarounds rotos se borraron (método `loadEnv()`, propiedad `$envLoaded` y la llamada en `setUp`); el `require bootstrap.php` además era peligroso de dejar ahí porque el día que `class_exists` dejara de dar `true` habría conectado a la BD, arrancado sesión y enviado cabeceras en mitad de la suite. ~35 clases conservan un `loadEnv()` **correcto** (`Env::load()` directo, que es idempotente porque `Env::load` no sobreescribe variables ya definidas): hoy es redundante, se puede limpiar en otra pasada. El comentario de `DataIntegrityTest` se actualizó porque afirmaba lo contrario.
- Grupos no tienen matriz de permisos propia (2026-09-30): el "Abierto" del plan de remediación decía que la matriz de una instalación con grupos personalizados no se migra sola y lo daba como decisión de producto pendiente "para cuando exista RBAC de grupos que ve". Ese seguimiento se cerró como **moot**, no como arreglo: `role_permissions` es **por rol** y un grupo únicamente confiere roles (`Role::effectivePermissionKeys($roleId, $groupIds)` = rol propio + roles de los grupos, en una consulta con dos `EXISTS`). Así que "permisos del grupo" no existe como concepto y no hay nada que migrar ni que decidir; lo que cambia qué puede hacer un grupo son los ROLES que confiere, y eso lo escribe la matriz de roles del panel (`Role::replacePermissions()`, que es lo único además de la migración 0005 que toca `role_permissions`). Conviene dejarlo escrito porque la formulación del plan daba a entender que faltaba una pieza de RBAC que de hecho nunca hizo falta.
- Roles y grupos usables (2026-09-30): el panel tenía tres cosas rotas en la misma pantalla. (a) `groupUpdate()` exigía un `name` que **su propio formulario no mandaba**, así que guardar los roles o los miembros de un grupo fallaba SIEMPRE con «El nombre del grupo es obligatorio» sin haber tocado nada; el arreglo NO es «meter un campo oculto que mande el nombre» sino tratar el **ausente** y el **vacío** como cosas distintas (`Request::post('name') === null` → se conserva el actual; presente pero vacío → error), porque un POST bien formado tiene que poder cambiar la pertenencia sin reescribir el nombre, y un grupo no puede quedarse sin nombre a media edición. `User::groups()` (un id) se eliminó en favor de `User::groupsMap()` (todos, una consulta): la columna «Grupos» pintaba una consulta por cada fila de la tabla, y su sustituto lo hace `User::setGroups()`, el inverso de `RoleGroup::setUsers()`. (b) **La pertenencia es el POST completo en las dos direcciones**: `groups[]`/`users[]` vacíos sacan a alguien de todo, porque `role_group_users` es una N:M y un checkbox sin marcar es un «sácale», no un «no lo he tocado»; y editar los grupos de un usuario desde su fila NO usa `admin.users` sino `admin.groups`, que es el permiso que autoriza la membresía (quien puede cambiar el rol de alguien no puede por esta vía meterlo en grupos). (c) Los roles se dan de alta desde la matriz con `Role::create()` y **nacen sin permisos**: la matriz es lo que decide qué puede hacer un rol, y `role_permissions` sin filas es exactamente eso (fail closed, no fail open). El re-seed de las migraciones solo otorga a los roles **con nombre del catálogo**, así que un rol inventado no hereda nada de nadie por el camino. OJO con la minúscula: `roles.name` es `utf8mb4_unicode_ci`, o sea que el índice único ya rechaza «Admin» junto a «admin» **y** que `LIKE '%é%'` casa con cualquier `e`; normalizar a minúsculas es por legibilidad y por lo que compara la matriz, NO por unicidad, y un test tiene que mirar el valor guardado. **No hay baja de roles**: `users.role_id` es `ON DELETE RESTRICT` y borrarlos exige decidir qué pasa con quienes los llevan (¿se les sube a `user`? ¿se bloquea la baja con usuarios asignados?), que es una decisión de producto, no un endpoint. (Se cerró después: ver «Baja de roles y rol `approver`».) En la UI: los roles y los miembros son **checkboxes** y no `<select multiple>`, que obliga a mantener Ctrl pulsado sin decir nada en pantalla; el grupo pinta **qué permisos otorga** (la pregunta de quien administra es «qué le pasa a esta persona si la saco de aquí», no «qué roles tiene el grupo»); la matriz agrupa por prefijo con un «marcar toda la sección» en `app.js` porque la sección es la unidad en la que se piensa al dar permisos; y la fila del usuario muestra su rol como etiqueta en vez de un select cuando es una misma (el servidor ya lo rechazaba, así que ofrecerlo era una trampa). Tests: `AdminRbacTest` (10) + `ControllerActionsTest` con cuerpo real en las dos rutas nuevas.
- Ajustes operativos fuera del builder (2026-10-01): `FormSettings::merge($current, $patch, $allowed)` = `normalize(array_replace_recursive(...))` POR BLOQUES, y es la **puerta** de las dos escrituras de `forms.settings`: la ficha (`POST /forms/{id}/settings`) con `DASHBOARD_BLOCKS` y `BuilderController::saveStructure()` con `BUILDER_BLOCKS`. El motivo es que `normalize()` arranca de `defaults()`: guardar el array entero desde una de las dos pantallas pondría en fábrica todo lo de la otra (el tema volvía al de fábrica cada vez que se guardaba el cupo). Dos cosas que costaron un test rojo: (a) **el recorte va ANTES de fusionar**, o un `theme` colado por la ficha se guardaría dos veces normalizado; (b) en un formulario HTML **el presente y el vacío no son lo mismo** — una casilla sin marcar no llega, así que `fromDashboardPost()` mira `array_key_exists()` y las seis casillas de la ficha llevan un `<input type="hidden" name="…" value="0">` delante. La ficha valida el parche CRUDO y el resultado FUSIONADO (el primero conserva los valores crudos que `normalize()` ya saneó; el segundo es el único que ve combinaciones con lo que ya había, como cerrar antes de abrir). Con revisión activa, tocar lo operativo de un formulario publicado lo devuelve a la cola: es la misma razón por la que editarlo por el builder ya lo hacía, y va en `publishedChangesMatter()` para que cambiar el idioma o el color no lo mueva. Los hints de estado (`stAccessHint`, `stReviewHint`) se mudaron a `dashboard.js` porque es quien pinta ahora esos ajustes, y `View::help()` sustituyó a la closure por vista: con dos pantallas de ajustes, dos copias del botón divergen. Y `AccessController::addEmail()` es bimodal: con `X-Requested-With` responde JSON con el partial `dashboard/forms/_access_list.php` ya renderizado, y sin él el 302 de siempre. Devolver el **HTML** y no la lista de correos es lo que evita que el JS sepa qué es una fila; el `form` conserva `method`/`action` para que sin JavaScript el alta siga funcionando. Tests: `FormSettingsMergeTest`, `BuilderHelpTooltipTest` y los cinco métodos nuevos de `FormAccessTest`.
- Interruptor de registro público (2026-10-01): migración **0010** `app_settings` (clave-valor, `key_name`/`value`/`updated_at`), `App\Core\AppSettings` (`get/bool/setBool` con `try/catch`) y `AuthController::registrationEnabled()` como `public static`. Cuatro decisiones: (a) **tabla clave-valor y sin fila inicial** — el patrón es «la fila guardada gana; si no hay fila, manda el `.env`», y sembrarla con el valor del `.env` congelaría el estado de la instalación el día de la migración; (b) **la fila gana en los dos sentidos**: si solo ganara para «encendido», el interruptor del panel no podría cerrar un registro que el `.env` tiene abierto y sería un adorno. Lo que se guarda es `0`/`1`, nunca el valor resuelto del `.env`; (c) **no es un permiso nuevo**: es `admin.access`, que es el mismo que ya exige el middleware `admin` de la ruta, porque abrir el registro no es tocar la cuenta de nadie (y `createUser()` sigue sin mirar el interruptor, que es lo que evita que cerrar el registro deje la instalación sin forma de dar de alta a nadie); (d) **la escritura va al log de seguridad** (`instance.registration_on`/`_off`): es de las pocas escrituras de configuración que cambian quién puede entrar. OJO al test: `app_settings` es de la INSTALACIÓN, no un fixture — `ControllerActionsTest` guarda la fila previa y la devuelve en `cleanupResiduals()` (su smoke recorre la ruta y escribiría de verdad), y cualquier test que quiera el estado del `.env` necesita `AppSettings::setForTesting([])` además del override de `Config`, que está por debajo. El interruptor **no se reposiciona al terminar**: si el guardado falla, dejar la casilla como el usuario la dejó sería mostrar un estado que no es el real, así que el JS se repinta con el `enabled` que devuelve el servidor, también en error. Tests: `RegistrationSwitchTest` (13).
- Baja de roles y rol `approver` (2026-10-01): migración **0011** (`INSERT IGNORE` del rol + re-siembra del catálogo leyendo `config/permissions.php`, como la 0006), `Role::SYSTEM_ROLES`/`isSystem()`/`userCount()`/`delete()`, `AdminController::roleDelete()` (`admin.roles`, CSRF, `admin.role_delete` en el log, flash con el motivo), `POST /admin/roles/delete` y una tarjeta «Baja de roles» en `views/admin/roles.php`. La decisión de producto que estaba pendiente («no hay baja de roles… ¿se les sube a `user`?»): **un rol con personas no se borra y punto**. La alternativa de reasignarlos en cascada a `user` parece más generosa pero hace algo peor que impedir el borrado — convierte un «este rol está en uso, llévalo a otro sitio» en un «tu formulario se le quedó a otra persona», y el que la pidió es el único que sabe a quién quería dejarlo. El motivo se devuelve en el flash, que es lo que hace falta para arreglarlo (sacarle el rol a esos tres y volver). Y **los roles del sistema no se borran ni vacíos**, por el mismo motivo: `Role::SYSTEM_ROLES` es una constante y no una columna `is_system` porque el conjunto depende de lo que la app sepa hacer (`approver` existe por la revisión de F3) y una columna no se puede rellenar en el DDL — sembrarla obligaría a una migración nueva por cada rol nuevo **y** a un `UPDATE` para los que ya estuvieran, con la fila y el código pudiendo discrepar. `approver` nace con `forms.review` **y `dashboard.view`**: el plan decía solo la primera y estaba mal, porque `DashboardController::index()` exige la segunda y un rol que solo aprueba se encontraría con un 403 en la puerta de entrada sin poder alcanzar `/review`. `dashboard.view` es lo mínimo para **llegar**; lo que lo haría dueño de algo (`forms.create`, `forms.edit_own`) sigue sin dárselo. En la UI la baja va en una tarjeta aparte, no en la cabecera de la matriz, porque el botón necesita su propio `form` y un `form` dentro del `form` de «Guardar matriz» es HTML inválido; y **no hay botón deshabilitado con un `title`**, sino nada de botón y el motivo al lado como insignia, porque un deshabilitado con la explicación larga es algo que hay que descubrir con el ratón y la pregunta de quien administra no es «¿por qué no puedo?» sino «¿qué hago?». El borrado va al log porque un rol lo heredan también los grupos: quitarlo deja a sus miembros sin nada, y eso no se ve en la fila que se acaba de borrar. De paso, **la navegación enseña solo lo que se puede usar** (`views/layouts/main.php`): dos gates más, `dashboard.view` para el enlace del dashboard y `forms.create` para «Nuevo formulario», siguiendo el precedente de F3 con el enlace de Revisión. El caso que lo fijó: un `approver` veía «Nuevo formulario» y recibía un 403 con toda la pinta de un botón que funciona. Tests: `AdminRbacTest` (20) y `PermissionSeedParityTest::testLosRolesPorDefectoDeApproverCoinciden`.
- Limpieza visual del constructor y del fill (2026-10-01): cinco cosas, y todas por lo mismo —**lo que se ve en el builder no es lo que sale en el formulario**. (a) La miniatura de la tarjeta de los tipos de encuesta (`.nps-dot`, `.scale-dot`, `.star-icon`, `.ranking-pos`, `.vf-matrix-preview`) no tenía **ninguna** regla en `app.css`, así que salía como texto suelto: «0 1 2 3 … 10» y una fila de estrellas del tamaño por defecto. Ahora tienen forma, en `em` sobre el tamaño de fuente de la tarjeta para que la miniatura escale con ella. La auditoría que lo destapó es el tipo que conviene repetir: cruzar las clases que el JS emite con las que el CSS define, que son 13 en este archivo. (b) El **preview en vivo** hace lo contrario y reutiliza el markup del fill (`nps-option`/`scale-btn`, `star-option`/`star-btn`, `vf-matrix-cell`, `vf-ranking-*`): su HTML propio, con estilos inline y clases que no existían, hacía que la preview se viera distinta del formulario en los cuatro tipos que más cambian de aspecto. Dos markup distintos entre la miniatura de la tarjeta y el preview sí, y es lo correcto: la una es una foto del campo en una tarjeta estrecha y el otro es el formulario entero. (c) `.vf-section-icon` llevaba `margin: 0 auto`, que lo centra dentro de `.vf-section-inner` (flex) y hace que el título baile; `auto .5rem` lo empuja a la derecha con un margen simétrico, que es como se ve el encabezado del fill — y el preview de sección se ha reconstruido con esa misma estructura en vez de con `text-center`. (d) `.app-section-children` pierde `border-left` y `padding-left`: el borde de 3px con su hueco de 1rem se confundía con el de la tarjeta de la sección y hacía un doble escalón; se conservan fondo y radio, y de paso `:empty` gana el `border: 1px dashed` que antes no pintaba nada (un `border-style` sobre un `border-left` inexistente es un borde de 0), así que la pista de «arrastra aquí» salía sin recuadro. (e) Un salto de página **sin etiqueta** ya no inventa «Página N» (se fue `ui.page_title` de `es.php`, `en.php` y del `emitPageHeader()` del fill): el `aria-label` del bloque y el badge del pie ya dicen «Paso 2 de 3», así que el encabezado de relleno repite lo mismo dos veces y no dice nunca el contenido del paso; con descripción, la cabecera se queda con ella, y sin ninguna de las dos no se pinta. Y los huecos de la paleta, que sin separación hacían que *Sección* y *Página* parecieran una sola pieza de dos líneas. Tests: la suite (705), y el bloque Q de `docs/pruebas-funcionales.md` para lo que no se puede automatizar.
- Correcciones de la revisión del bloque 6 (2026-10-01): revisar en pantalla lo que el bloque 6 dijo dijo haber arreglado sacaron tres fallos, y los tres son la misma lección que la entrada anterior —**«se ve bien en el builder» no significa nada**. (a) Se corrigió `.app-section-children` (el lienzo) y se dejó `.vf-section-children` (la página pública) con su `border-left: 3px` y su `padding-left: 1rem`: son **dos clases distintas con el mismo problema**, y corregir una deja el formulario igual que estaba. La invariant se ata ahora a las dos en el mismo test, que es lo que evita que la próxima vez se elija una. (b) El botón del NPS es un `<span class="scale-btn">` y **a un elemento inline el `width`/`height` no se le aplican**: `.nps-selector .scale-btn` nunca declaró `display`, así que sus `40px` no se aplicaban y las opciones salían como el número suelto pegado al borde. La regla de `.scale-selector` sí lo declaraba, y como el NPS reutiliza la clase, el estilo venía de la del scale o de ningún sitio. El `display: inline-flex` y el centrado no son decoración: son la diferencia entre «un botón» y «un dígito con un borde alrededor». Y no lo detecta ni el builder ni el preview en vivo, porque antes de esta entrada el preview **no pintaba el markup del fill** para el NPS (el cambio de la entrada anterior llegó después de este fallo). (c) Quitar `ui.page_title` arreglaba el salto **sin** etiqueta, que es el caso raro: el builder escribía `Página N` como etiqueta al soltar el salto, y como `fields.label` es contenido, eso se publicaba como encabezado. El dato estaba en el editor, no en la vista. Ahora el salto nace sin etiqueta (el rótulo del editor lo pone el nombre del tipo) y la **migración 0012** limpia el relleno ya guardado, con un patrón estrecho —`Página 3`, `Page 3`— que respeta una etiqueta escrita a mano («Primera página») y nunca toca la descripción. Sin la migración, corregir el código no habría cambiado nada en las instalaciones que ya tenían formularios paginados, que es donde se vio. Tests: `BuilderPreviewCssTest` (10), y el bloque Q sigue siendo la lista de lo que solo se ve en pantalla.
- El elemento página se puede ocultar (2026-10-01): migración **0013** (`fields.show_header`), el interruptor `#fieldPageHeader` del panel del builder (solo visible con un `page` seleccionado) y el gate de `$emitPageHeader()` en el fill. Tres decisiones: (a) **columna, no clave**: en `validations` no cabe porque los tipos estructurales la ven vacía —`BuilderController` la fuerza a `[]` y `builder.js` hace lo mismo al serializar—, así que el ajuste se perdería en cada «Guardar estructura» sin avisar; y `options` tampoco, porque el cliente la normaliza con `Array.isArray(f.options) ? f.options : []` y un objeto se borraría al cargar. Es el mismo camino que `is_required` e `is_pii`; (b) **`DEFAULT 1` y sin backfill**: la migración no cambia nada de lo que ya está, porque lo único que cambia es un salto que el dueño marque, y un formulario publicado sigue mostrando lo que mostraba; (c) **apagar la cabecera no apaga el paso**: el bloque, su `aria-label` «Paso 2 de 3», la barra de progreso y la insignia del pie se siguen pintando, porque `stepLabel()` y `paintSteps()` tienen que seguir diciendo lo mismo (el bloque y su pie se nombran con los mismos números) y un paso sin nombre no es un paso: es una pantalla sin entrada. De paso, `.vf-page-head` pierde la caja crema con borde punteado —un aviso dentro de un formulario publicado— y se separa como una sección, conservando el ámbar del icono porque un paso y una sección no son lo mismo. Y el preview del builder deja de pintar la insignia centrada (`.vf-page-divider`) para emitir el markup del fill: era un tercer diseño, y un preview que enseña una cabecera que luego no aparece enseña lo contrario de lo que hace el formulario. Tests: `MultiStepTest::testElSaltoDePaginaPuedeOcultarSuCabecera` (render real: se cuenta la caja, no el texto, porque el `aria-label` también contiene «Paso 2»), `testElAjusteDeCabeceraLlegaPorElGuardadoDelConstructor` (el payload llega a la columna y un `text` la ignora) y `BuilderPreviewCssTest` (2 métodos nuevos).
- Dos ajustes de «Diseño» que se guardaban y no se aplicaban (2026-10-01): «Color de fondo» y «Fuente» salían de `themeCss()` como `--vf-background` y `--vf-font`, y **ninguna de las dos variables aparecía ni una vez en `app.css`** — de los tres ajustes del modal de diseño, solo el color principal funcionaba (89 usos). El valor no era el problema: `validate()` ya exigía `#RRGGBB`/`#RRGGBBAA` para los colores, y la tipografía tiene su lista blanca en las dos puertas (`ThemeFontCssInjectionTest`). El fondo de la página era un gradiente escrito a mano **dos veces** en el archivo, con la segunda ganando por orden y la primera siendo código muerto, y la fuente la fijaba `body` a pelo. Tres decisiones: (a) `--vf-background` se declara en `:root` **con el gradiente como valor por defecto** en vez de inventar un `fallback`: una custom property admite cualquier token, así que el color elegido lo sustituye y sin ajuste sigue viéndose lo de siempre; (b) el color se aplica a `.fill-body`, que es el fondo de la página, y la tarjeta se queda en **blanco a propósito** (`.app-fill-card .card-body`): con un color de página oscuro, teñir también la tarjeta dejaría el formulario ilegible, y el tooltip del control ya decía «El fondo de la página del formulario»; (c) `--vf-font` entra por `body` con `fallback` a Inter, y el `<style>` del tema solo se inyecta en `fill/show.php` y `fill/thanks.php`, así que el panel no se ve afectado. La regla general que sale de esto —y el test que la ata— es que **toda variable que emite el tema tiene que tener un consumidor en `app.css`**: un ajuste que se guarda bien y no se ve no produce ningún error, ni en el navegador ni en el log, y por eso llevaba años sin que nadie lo notara. Tests: `ThemeFontCssInjectionTest::testTodaVariableDelTemaTieneUnConsumidorEnElCss` y `testElColorDeFondoSeAplicaAlFondoDeLaPaginaYLaTarjetaSigueBlanca`.
- La ficha se pliega y el fill deja de explicar las reglas (2026-10-01): cuatro cosas de la misma revisión manual, y las cuatro por el mismo motivo — **un dato que se guarda, se sirve y no se ve, o se ve donde no toca**. (a) «Se muestra si: …» sale del formulario publicado: las reglas son información del editor, y al visitante le bastaba con encontrar su pregunta; peor era que, con el campo oculto por su propia condición, el texto se quedaba solo en pantalla sin nada a lo que referirse. Se borran la closure `$condSummary`, el mapa `$fieldById` que solo la alimentaba y las **nueve** claves de i18n que quedan sin lector (cuatro `ui.*` y cinco `op.*`, en `es.php` y `en.php`): una traducción que nadie lee es ruido que además invita a mantenerla al día dos veces. Lo que NO se toca es `data-vf-logic` (sin él no hay omisión de campo en el cliente) ni la insignia del builder, porque quien edita sí necesita ver la regla. (b) `.vf-section-children` pierde el fondo, y `.app-section-children` lo conserva: son la misma idea con dos contextos, y en el builder el tinte dice dónde acaba la sección —es la zona de trabajo—, mientras que en el formulario publicado la sección ya trae encabezado y línea, así que el tinte encima solo hacía que el formulario pareciera partido en dos. El test ata los DOS lados por eso: corregir uno y dejar el otro deja la mitad del problema. (c) La banda del «+» (`left: 0`) y «Agregar dentro» (`max-width: 100%`) medían el hueco de `2.35rem` que reservan las herramientas de la cabecera, que no tiene nada que ver con insertar entre tarjetas: se venían directamente de la reserva de iconos y nadie sabe ya por qué están. (d) Los siete grupos de «Ajustes del formulario» se pliegan con Bootstrap Collapse, y aquí lo no negociable es el **triple** por grupo: `<button type="button">` (sin `type`, pulsar el título envía el formulario de ajustes), `aria-expanded` y `aria-controls` apuntando al `id` real del panel (Bootstrap no lanza si no encuentra destino, así que el clic no haría nada sin error visible). El estado se recuerda en `localStorage` **por formulario** (`vf:grp:<id>` desde `data-vf-form-id`) porque el mismo usuario pasa por varias fichas, y **con un `alert-danger.app-alert` se abren todos**: el aviso del guardado fallido vive arriba del formulario, y un ajuste que falló escondido en un grupo plegado obliga a abrir los siete a ciegas. Test de cada punto: `BuilderPreviewCssTest` (los tres CSS) y `FormSettingsMergeTest` (los dos de markup). Comprobaciones manuales nuevas: bloque S de `docs/pruebas-funcionales.md`.
- F0.3-bis, el hueco que quedaba (2026-10-01, reportado como «el ajuste de mostrar u ocultar el elemento página no funciona»): el mismo descuido de F0.3, pero en los **flags de nivel superior** en vez de en las validaciones, y con el agravante de que el ajuste nuevo se anunciara funcionando. Las tres piezas existían y encajaban: el interruptor estaba en el panel, `applySettings()` leía `el.fieldPageHeader.checked` y lo escribía en `f.show_header`, y `buildPayload()` lo serializaba con el nombre que lee `BuilderController` (el round trip por PHP lo cubría `MultiStepTest::testElAjusteDeCabeceraLlegaPorElGuardadoDelConstructor`). Lo que **no** había era el `change`: sin listener, `applySettings()` no corría, el estado en memoria conservaba el valor cargado del servidor y el autosave guardaba exactamente lo mismo. El payload salía byte a byte idéntico, sin error ni aviso — indistinguible de un servidor que ignorase el campo, que es por eso que el contrato se mide en el JS. Y el hueco no era solo del ajuste nuevo: **`fieldPii` tampoco estaba enlazado**, así que mover el interruptor de «dato personal» no guardaba nada; solo se guardaba si se pulsaba el botón «Sí, es dato personal» de la sugerencia, que sí llama a `applySettings()`. Regla: **un flag necesita control Y listener, igual que una validación**, y el par flag→control va declarado en `TOP_LEVEL_FLAG_CONTROL` de `tests/unit/BuilderValidationsContractTest.php`, que barre los listeners con el mismo `wiredControls()` y comprueba también que ningún flag se escriba sin control declarado. Texto → `input`, casillas → `change`. `builder.js` bumpea a `v2026-10-01a`.

## F0.3-tres — «El elemento página no aparece en el formulario final»

Turno el otro, y no es un aviso de contenido: es que el interruptor se movía,
se guardaba bien y aun así no se veía nada en el formulario publicado. Con la
columna y el round trip por PHP ya en su sitio (y probados), la única variable
que quedaba era qué decide cada lado.

La respuesta son **tres** reglas, y solo funcionaba la primera:

1. `fields.show_header` decide **si** se pinta la cabecera. No dice **qué** dice:
   el título y la descripción son contenido y los pone el editor. Un salto de
   página nace SIN título, así que el estado por defecto de quien activa el
   interruptor y no escribe nada es «encendido y sin nada que pintar».
2. El fill solo pinta la caja **si hay título o descripción**. Una caja con el
   icono y el cuerpo vacío no dice de qué paso se trata, cuando el pie ya lleva
   «Paso 2 de 3» y el `aria-label` del bloque lo nombra igual: tres formas de
   decir el número y ninguna del contenido. Por eso un salto sin etiqueta **no**
   inventa un título (migración 0012) y por eso aquí tampoco se inventa una caja.
3. El preview del builder aplicaba **solo la primera**: pintaba la caja siempre
   que el interruptor estuviera encendido. De ahí el síntoma — el icono se veía
   en el constructor y en el publicado no había nada. Las dos pantallas usan
   markup y clases idénticos, así que «se ve bien en el preview» no significaba
   nada y nada delata la divergencia.

Lo corregido: el preview decide igual que el fill (los tres predicados, con el
mismo `trim()` que el fill, porque una etiqueta de solo espacios es sin título),
y el panel **explica** el caso sin contenido con un aviso bajo el interruptor
(`pageHeaderHint`), en vez de dejar un ajuste que parece roto. Es el mismo patrón
que `updateAdvanceHint()` y que la sugerencia de PII: el builder avisa, no
escribe contenido por el editor.

Lo que **no** se hizo, y es la tentación natural: que el interruptor «siempre se
note» pintando la caja aunque esté vacía. Se vería el ajuste funcionar, pero el
formulario publicado ganaría un icono solo al principio de cada paso, que es
decoración sin contenido — y el constructor volvería a mentir por el lado
opuesto.

Dos tests nuevos, y los dos por la parte que falla en silencio:
`MultiStepTest::testLaCabeceraDelPasoNoSePintaSinTituloNiDescripcion` fija el
comportamiento del fill (incluida la descripción sin título, y que espacios no
son un título) y `BuilderPreviewCssTest::testElPreviewYElFillDecidenIgualCuandoPintaLaCabeceraDelPaso`
ata los dos lados por predicado, para que no se puedan mover de uno en uno.
Comprobado que el segundo no es vacío: deshaciendo el criterio del preview, cae.

## F0.3-cuatro — El icono de la sección sin margen, y el salto de página vuelve a tener número

Dos cosas en una, porque son la misma pregunta: **qué es de la fila y qué es del
elemento**. La respuesta es que la alineación es de la fila.

`.vf-section-icon` se comía el
hueco sobrante con `margin: auto .5rem`, y con el `fill` y el preview trayendo
los hijos en **orden distinto** (icono primero el fill, título primero la
preview), ese margen era lo único que hacía que las dos superficies salieran
igual. O sea: la posición dependía de un margen invisible, y tocar el orden
movía el icono de lado en un sitio sin que se viera el motivo. Además el `.5rem`
metía dos huecos que nadie había pedido — uno entre el icono y su título, que la
fila ya cubre con `gap`, y otro hacia el borde. Ahora el icono no declara
margen y `.vf-section-inner` reparte el sobrante con `justify-content: space-
between`; el `fill` pasa a montar el título antes que el icono, que es el orden
de la preview, así que los dos dependen de la fila y ninguno del orden. El test
`testElIconoDeSeccionVaALaDerecha` fijaba el margen viejo: ahora fija la
propiedad de posición en las dos superficies y que ninguna declare `margin`.

Lo del salto de página es una decisión del editor, ya discutida: vuelve a nacer
con **«Página N»** —el número de páginas que ya hay más uno, no la posición en el
lienzo, que cuenta secciones y el bloque implícito—, igual que una sección nace
con «Nueva sección». Se había quitado porque `fields.label` es contenido y lo que
se guarda es lo que se publica, y un encabezado de relleno repetía lo que el
`aria-label` del bloque ya decía. Vuelve porque en el constructor el paso se
distingue de los demás campos por su número, y un nombre es más rápido de leer
que un «Página» a secas.

Lo que NO se deshace es el otro sentido, que es el que importaba: el **fill**
sigue sin fabricar el título cuando el editor borra la etiqueta
(`ui.page_title` sigue borrada, y su test también). Un relleno que pone el
servidor no se puede quitar; uno que pone el builder al crear el elemento es un
punto de partida y se sobrescribe. La migración **0012** se queda, con su patrón
estrecho, limpiando el relleno de las instalaciones que llevan tiempo usando la
app —su alcance son esas filas, no las nuevas—.

Y el aviso de `pageHeaderHint` sigue en pie: pasa a ser más raro (un paso nuevo
ya trae título) pero no es código muerto, porque sigue siendo el aviso para
cuando el editor **borra** el título con el interruptor encendido.

## F0.3-cinco — El icono del paso también a la derecha, y el texto del botón en claro

Dos retoques de maquetación que salen del mismo sitio: el que manda en una fila
es la fila, y los colores que nadie pone.

**El icono del salto de página a la derecha**, como el de la sección. `.vf-page-head`
es una fila igual que `.vf-section-inner`, y su icono iba en primer lugar, con el
título detrás: el mismo caso que acababa de corregirse en la sección, con el
salto de página todavía fuera. Ahora los dos marcan el título primero y el icono
al final, y las dos filas reparten el sobrante con `justify-content: space-between`.
Lo que importa del orden es que con `space-between` **el lado lo decide cuál va
el último**, así que un solo hijo desordenado devuelve el icono al otro extremo sin
que se note el motivo: constructor y publicado se ven distintos y las clases del
icono son las mismas en las dos pantallas. Comprobado en el HTML renderizado que
el orden sale como debe en los dos bloques, y los tests atan las dos superficies.

**El texto del botón de enviar, en claro.** `.vf-submit` no lleva la clase `btn`
de Bootstrap, y sin ella el color del texto es el del navegador: **negro sobre un
degradado que acaba en `#7c3aed`**. Es el texto de la acción principal sobre el
fondo más oscuro de la página, y no se nota en una captura hasta que se lee. Va
en `#fff`; el icono hereda por `currentColor`.

Va en claro y no «el que toque» porque el degradado siempre acaba en el mismo
morado oscuro: es el único color que se lee en toda la barra. La **otra** mitad
del degradado es `var(--vf-primary)`, que el editor puede poner claro, y ahí el
texto blanco se perdería: es el único caso de este cambio que no se puede
comprobar con un test y toca mirarlo a mano si alguien pone un color muy claro.

## F0.3-seis — El botón negro era el del preview, y el modal de tipos no cabía en un móvil

Dos correcciones que salieron de la misma revisión visual, y una de ellas corrige
la lectura del cambio anterior.

### El botón de «Enviar» que se veía negro no era `.vf-submit`

En F0.3-cinco se puso `color: #fff` en `.vf-submit` y se comprobó en el
formulario publicado, que es donde se veía. Lo que seguía viéndose **negro era el
botón de la vista previa del constructor**, que no es `.vf-submit`: `previewBody()`
se pintaba el suyo, `<button class="btn btn-lg app-btn-primary">`. `app-btn-primary`
aporta alto, radio y elevación y **NO color** —está escrito así a propósito, para
que el color lo ponga el destino—, así que salía el texto del navegador sobre un
fondo claro, y además era un botón que no se parecía en nada al que se publica.

La lección es la de siempre en este archivo: **«se arregló y se comprobó» en una
superficie no dice nada de la otra**. El preview en vivo ya reutilizaba el markup
del fill para las cabeceras y para las minaturas (`nps-option`, `scale-btn`,
`vf-matrix-cell`…), y el botón se había quedado fuera precisamente por ser el
único que escribe su propio texto. Ahora emite el markup de `views/fill/show.php`
(`.vf-submit-row` + `.vf-submit` + `.vf-submit-label`, con el mismo icono de
papel), sin el `id` del fill —en la página vive un `#vfSubmit` y duplicarlo sería
HTML inválido— y con `type="button"` porque en el preview no hay nada que enviar.

De paso, el por qué de F0.3-cinco queda con su límite escrito: **sí** se puede
comprobar con un test, y está atado en las dos superficies a la vez.

### El modal «Agregar elemento» no aparecía, y la causa no era el móvil

Síntoma: se oscurecía la pantalla, el diálogo **no** aparecía, no se cerraba salvo
recargar la página, y «a veces funcionaba».

**El primer diagnóstico estaba equivocado.** Se atribuyó a tres reglas de
Bootstrap que son ciertas y que encajan de sobra con el síntoma: la rejilla de
`minmax(9.5rem, 1fr)` son 152px y en un móvil de 360px el cuerpo del modal mide
~296px, de modo que los 21 tipos cabían en **una** columna (~1700px);
`.modal-dialog-centered` al pasarse de alto descuadra el desborde **hacia arriba**; y
arriba no scrollea nadie, porque el `overflow-y` de Bootstrap está en `.modal-body`,
**dentro** del diálogo. Tres causas encadenadas que explican los tres síntomas sin
que falte uno, y ninguna era la causa.

El arreglo de móvil se queda —la rejilla de una columna era real, y anclar arriba y
topar con `dvh` son lo que corresponde a un diálogo alto en un móvil—, pero
**no arregla este fallo**, y queda escrito para que nadie lo compruebe aquí dentro.

**La causa era un `</div>` de menos.** Al `modal-footer` de `#formSettingsModal` le
faltaba el cierre del propio modal, y lo dejó `ea9759f` («los ajustes operativos
salen del builder a la ficha»). A partir de ahí `#addFieldModal` y `#builderApp`
quedaban **anidados dentro de `#formSettingsModal`**, que Bootstrap lleva
`display: none`. Los tres síntomas son el mismo hecho:

- el backdrop lo añade Bootstrap a `<body>`, que está FUERA del corte, así que el
  tinte se ve igual que si todo fuera bien;
- el diálogo no se pinta: él se pone `display: block` inline, y eso solo surte
  efecto si el padre se ve, y el suyo no;
- no se cierra tocando el fondo porque el clic de dismissal lo escucha el propio
  `.modal`, y un elemento con `display: none` no puede ser el objetivo de un clic.
  En escritorio queda `Esc`; en móvil no hay `Esc`, y `body.modal-open` quita el
  scroll, así que solo quedaba recargar.

**Por qué nadie lo vio**, ni la revisión ni los 732 tests: `#builderApp` es un
`<div>` vacío que solo lleva `data-*` y el constructor entero está antes de los
modales, así que anidarlo dentro de un modal oculto **no cambia nada de lo que se
ve** salvo el propio modal. Los otros dos modales del constructor abrían bien
porque están antes del corte, que es lo que hizo parecer el fallo suyo y no de la
vista. Y el eje del diagnóstico estaba equivocado en el orden: se fue al móvil
porque el usuario tenía el móvil, y el fallo no es de móvil.

**Lo que habría parado antes: medir, no suponer.** Un
`getBoundingClientRect()` a 0×0 dice que el elemento no se ve **por un ancestro**, que
es un diagnóstico distinto del de «es demasiado alto y se sale por arriba». La
medición se queda en `show.bs.modal` (rectángulo, `top`, viewport y ancho) por
justo eso: ahora un 0×0 es la señal de que hay que mirar la vista. Antes de tocar
nada: **la primera pregunta es qué se mide, no qué parece**.

**El test que faltaba** — `ViewStructureTest` (`tests/unit/ViewStructureTest.php`).
Recorre las 53 vistas como lo haría el parser del navegador y ata dos cosas: que no
queda ninguna etiqueta sin cerrar, y que ningún `.modal` vive dentro de otro
elemento (todo lo que lo contiene está oculto, por definición casi). 732 tests no
miraban la estructura del HTML emitido, y un `</div>` perdido no da error en PHP,
ni en el navegador, ni en un test: el único que lo delata es un síntoma que
admite tres explicaciones. Los dos llevan guard anti-vacío —que el recorrido vea más
de 2000 aperturas y encuentre los 6 modales de la app— porque un test de estructura
que no recorre pasa verde por construcción. Pasó exactamente así al escribirlo: el
`foreach` iba sobre el `int` que devuelve `preg_match_all` en vez de sobre el array,
y el primer test daba verde sin haber mirado nada.

## F0.4-uno — `/admin`: el alta plegada, y el estado de los grupos pasa a ser por pantalla

El panel de «Usuarios» tenía dos tarjetas a ancho completo apiladas y el interruptor
de registro en medio, cuando lo que se abre en esa pantalla ocho de cada diez veces
es la tabla de usuarios. Y el `vf:grp:<id>` de los grupos plegables era, literalmente,
`vf:grp:<id de formulario>`: la ficha era la única pantalla con grupos y su clave no
tenía por qué parecerse a la de otra.

### El reparto 8/4

El alta se queda en `col-lg-8` y «Acceso de cualquiera» pasa a `col-lg-4`, el mismo
reparto que ya usaba la ficha del formulario, para que el panel tenga una sola
retícula. Los cinco campos van **3+2** y no cuatro en fila: dentro de `col-lg-8` un
`col-md-3` deja el campo de contraseña tan estrecho que el marcador de posición se
corta, que es justo lo que hace parecer mal repartido un formulario que solo lo está.

La columna del interruptor **no se emite vacía** si falta `admin.access`. Podría
haberse dejado el hueco y el alta ocuparía las mismas 8 columnas, pero una columna
que no pinta nada es una columna que aparece y desaparece: la retícula daría un
salto al recargar con otro rol.

### Plegado por defecto, abierto en el servidor

El alta se pliega, que es lo que pedía el reparto en vertical. Lo que **no** se hizo
es servirla cerrada, y esa es la parte que importa: con el registro público cerrado —
el default— este formulario es la **únICA** forma de crear un `user` o un `editor`,
porque `scripts/create-admin.php` solo sabe hacer administradores. Si el panel
llegara `collapse` sin `show`, sin JavaScript el alta desaparecería entera de la
pantalla sin un solo error: el botón seguiría ahí, el POST seguiría funcionando y
quien lo viera concluiría que no hay forma de crear cuentas. El patrón es el del
que ya usan los siete grupos de la ficha —`collapse show` en el servidor y que sea
el JS quien pliegue— y lo que se añadió es el atributo que dice «este se pliega»:
`data-vf-group-default="closed"`, cuya **ausencia** significa «abierto». Por eso los
siete grupos de la ficha no lo llevan, y un test lo fija.

### `data-vf-group-key`, y por qué la ficha conserva el suyo

`dashboard.js` recorría un contenedor por id (`getElementById('formSettings')`), que
es un contenedor y medio: con dos pantallas la cosa correcta era recorrerlas todas y
dejar que cada una diga cuál es. La ficha se queda con `data-vf-form-id` porque su
clave guardada es el id del formulario, y renombrar el atributo habría hecho que
todos los usuarios perdieran el estado de sus grupos sin que nada lo dijera; el
atributo nuevo es su alternativa, no su reemplazo. El estado es `vf:grp:<clave>` en
los dos casos.

### El `hidden.bs.collapse` de arranque

`bootstrap.Collapse.hide()` dispara `hidden.bs.collapse`, el mismo evento que un
clic. Sin más, la primera visita se guardaría a sí misma como si el usuario hubiera
decidido «cerrado», y un cambio de valor por defecto en una versión futura no
llegaría a nadie. En la ficha no pasaba: su valor por defecto es «abierto», coincide
con lo que llega del servidor, así que el bucle no tenía nada que corregir. En el
alta sí, porque el servidor manda abierto y el JS quiere cerrado. Los cambios de
arranque se marcan en `decididos` y esa primera escritura se descarta: solo se
guarda lo que alguien toca.

### El test, y dos cosas que se le colaron de paso

`tests/unit/GroupCollapseContractTest.php` fija cinco cosas: el marcado del grupo y
que el panel llega abierto, que el formulario de alta está **dentro** del panel y no
al lado, que el cierre por defecto solo lo pide `/admin`, que el JS recorre todos los
contenedores sin perder la clave de la ficha, y que la escritura de arranque se
descarta.

Escribiéndolo salieron dos trampas que ya están en el repo y que conviene no volver a
pisar:

- **Un `str_contains` sobre el archivo entero pasa verde con el atributo borrado**, si
  la misma cadena está escrita en el comentario que explica el porqué. Aquí pasaba:
  quitar `data-vf-group-default="closed"` del `<fieldset>` dejaba el test en verde
  porque el comentario de ocho líneas más arriba la nombraba. Las comprobaciones
  miran **etiquetas** (`<…>`) y no el archivo, y del JS se quitan los comentarios
  antes de buscar.
- **`--filter` casa por nombre de método, y un filtro demasiado corto casa con el
  test equivocado**: buscando por `Contrato` se ejecutaba
  `testExportBatchesFiltraPorFechasConElMismoContrato`, de otra clase, y daba verde
  mientras el test que se quería comprobar ni se había ejecutado. El contador de
  `Tests:` es el que dice si el filtro era el bueno.

Cada una de las cinco se comprobó **mutando el código**: sin el atributo de cierre,
con el panel cerrado en el servidor, con la ficha pidiendo cierre, con el JS
grabando el valor por defecto, con el JS atado a la ficha, con la ficha renombrando
el atributo, y con el formulario fuera del panel. Las siete se pusieron rojas.

### De paso, dos contadores que ya mentían

Al contar las filas de la tabla de pruebas funcionales para anotar las nuevas, el
número que se anunciaba en dos sitios (`docs/pruebas-funcionales.md` y `AGENTS.md`)
no cuadraba con las filas del archivo: decían 248 y había 257. Los siete casos de
este cambio lo suben a 264, y ese es el que se queda. El otro era el de la suite,
que en el mismo archivo decía 730 y iba por 739.

Un contador desfasado en un documento de pruebas es la misma clase de fallo que un
`</div>` de más: nada se rompe, nadie lo nota, y a los dos cambios de distancia ya
nadie sabe si es que se olvidó anotar un caso o es que se borró.

---

## F0.4-dos — El panel sin recarga: la misma ruta responde JSON o recarga

Publicar, cerrar, aprobar, guardar ajustes, quitar un email de la allowlist o
borrar una respuesta recargaban la página entera. La recarga no está mal en sí
misma, pero en una ficha con siete grupos plegados significa que **cada acción
tira abajo el estado de los paneles** que alguien había abierto y también la
posición del scroll, y en `/admin` ocurre lo mismo con cada usuario que se
desactiva. Aquí está el porqué de hacerlo bimodal y no «solo con fetch».

### Por qué bimodal y no solo JS

La respuesta corta es que **el POST nativo es el que ya funcionaba**, y JS es
una mejora: al revés (hacer la acción solo con fetch y que el botón sea un
`onclick`) la app deja de funcionar entera en cuanto algo falla, que es
justo lo que pasa cuando algo falla.

`Response::wantsJson()` mira una sola cosa, la cabecera `X-Requested-With` que
pone `window.vfPost()`, y a partir de ahí las acciones contestan JSON
(`{ok, message, html}`) o el 302 de siempre, con su flash. No hay un tercer
camino ni una ruta nueva: es la misma ruta con dos respuestas, que es lo que
permite que el botón siga siendo un `<form method="post">` y que el navegador
con el JS desactivado, o un `fetch` que devuelve algo raro, hagan exactamente
lo mismo que antes.

El sobre trae `html` con **ids** (`{vfSlotStatusBadge: "…"}`), no un fragmento
suelto, porque el JS solo sustituye el `innerHTML` de los ids que vienen: no
sabe pintar una insignia ni un botón, que son de la vista.

### La mitad invisible: hay cuatro huecos, no uno

La idea era «repintar los botones». El problema es que **`forms.status` decide
cuatro cosas de la ficha**, no una:

| hueco | qué cambia con el estado |
|---|---|
| `vfSlotStatusBadge` | el texto y el color de la insignia |
| `vfSlotReviewTrail` | si hay traza, y si nombra al revisor |
| `vfSlotActions` | qué botones existen (y cuáles no) |
| `vfSlotShare` | el enlace público, el QR y el iframe de embed |

Con tres de los cuatro, cerrar un formulario sin recarga deja el QR de un
formulario que ya no acepta respuestas y un botón de «Cerrar» que sigue ahí.
No hay forma de que se pongan de acuerdo porque uno se pintó y el otro no. Los
cuatro viven en `app/Services/FormPanel.php`, que los devuelve ya escapados; el
GET los pinta en línea y el POST los devuelve, y las dos rutas usan los mismos
partials para que no puedan separarse.

Y hay una acción cuyo efecto sobre el estado **no es el que dice su nombre**:
guardar ajustes en un formulario publicado con revisión activa lo devuelve a la
cola y borra la aprobación anterior (`Form::submitForReview()`, la misma regla
que el builder). Un guardado que devolviera solo «Ajustes guardados» dejaría la
insignia diciendo «Publicado» de un formulario que ya está en la cola. Por eso
`testGuardarAjustesTraeLaInsigniaDelEstadoQueQueda` mira **la insignia que
vuelve** y no el mensaje: es la única forma de que esa dependencia se note.

### El rescate es un segundo argumento, no un `.catch`

La forma natural de escribir esto es `fetch(...).then(...).catch(...)`, y es
un error: el mismo brazo recoge «se cayó la red» **y** «reventó el pintado», así
que una excepción al reponer los huecos —un `innerHTML` que no se puede poner,
un aviso que revienta— acababa en `form.submit()` y **repetía la operación**.
Lo encontrou el arnés de `tests/js/async-actions.js` (escenario «un fallo al
mostrar el resultado no repite la operación»), no una revisión a ojo.

Por eso el `.then()` va con dos manejadores: el primero procesa la respuesta y
el segundo (el rescate) solo se dispara si la **petición** falla. Y
`soltar()` —el botón que se apaga y el `data-vf-busy`— va **primero**, antes de
pintar nada: al revés, un fallo al pintar dejaba el formulario con el botón
apagado para siempre y sin que se viera el motivo.

### `Content-Type`, no «a ver si `r.json()` no revienta»

`vfPost()` **solo resuelve si la respuesta es JSON de verdad**, mirando el
`Content-Type`. Una respuesta tunesca (la página de error de un PHP, un aviso
del servidor web, el índice de un despliegue mal hecho) llega con **200** y
`text/html`, y `res.json()` sobre eso revienta con un error que se confunde con
«el servidor dijo que no». Mirar la cabecera separa las dos cosas, y quien llama
usa esa separación: un `ok:false` se **pinta**, un rechazo **cae al POST
nativo**.

Igual con `ok:false` dentro de un JSON bien formado: es un «no» del servidor, no
un fallo de transporte, así que se pinta su motivo y **no se reenvía nada**.

### El estado objetivo: por qué el botón no dice «al revés»

`User::toggleActive()` invertía (`1 - is_active`). Con un botón que va por
fetch, dos clics sobre «Desactivar» —o un reintento después de un tiempo de
espera— se activan y desactivan la misma cuenta, y el panel acaba diciendo lo
contrario de lo que pasó. Con `1 - is_active` no hay forma de decir «déjalo
como está».

Ahora el botón manda `active=0|1` (el estado **pedido**) y `User::setActive()`
hace `UPDATE … WHERE id = :id AND is_active <> :igual`, que devuelve 0 filas
cuando ya estaba en ese estado: eso es lo que hace el POST **idempotente**, y el
mensaje también lo dice («El usuario ya estaba en ese estado») porque
«Estado de usuario actualizado» sería cierto pero no avisaría de que no pasó
nada. Un POST **sin** el campo —una página cacheada de antes de este cambio—
sigue invirtiendo: el camino sin JS no se rompe.

Dos trampas de PHP en esa línea, las dos paga `Database` con
`ATTR_EMULATE_PREPARES = false`: un marcador repetido (`:v` dos veces) lanza
**HY093**, así que hay dos marcadores con el MISMO valor; y `execute()` ya
devuelve el `rowCount`, no un `PDOStatement` sobre el que llamar a `rowCount()`.

### El hueco del repintado no puede ser un `<form>`

El JS repone con `slot.innerHTML = html`. Si el `id` del hueco estuviera en el
`<form>` —como estaba el del toggle de `/admin`— lo que se metería dentro es
**otro formulario**. El parser del navegador descarta el `<form>` anidado, así
que «funcionaría» por casualidad y perdiendo contenido según el navegador. El
hueco es ahora un `<span id="vfUserToggleN">` que **envuelve** al formulario, y
`testElHuecoQueReponeElJsNoEsUnFormulario` lo vigila.

### `data-confirm` va en el `<form>`, no en el botón

El delegado lee `form.getAttribute('data-confirm')`, así que el atributo
colgado del botón **no lo miraba nadie** y el borrado salía sin preguntar. Está
en el `<form>` en todas partes, y `testLaConfirmacionVaEnElFormYNoEnElBoton`
busca `<button … data-confirm` en las 42+ vistas.

### Un delegado, y con nombre

Confirmación y envío comparten **un** `addEventListener('submit')` y en este
orden: primero se pregunta, luego se decide el modo. Con dos, el orden dependía
de cuál se anotara primero y una línea movida de sitio sacaba un POST sin
preguntar. La función se llama `vfHandleSubmit` a propósito (y no `function (e)`
anónimo) porque `tests/js/async-actions.js` la **extrae del fichero** y la corre
contra un DOM mínimo: una función anónima no se puede sacar, y lo que hay que
poder ejecutar es el camino de rescate.

El botón de «Guardar» de la ficha vive **fuera** del formulario (usa
`form="formUpdate"`), así que `vfSubmitButton()` lo busca también por
`button[form="…"]`: sin eso esa acción no se apagaba mientras está en vuelo y un
segundo clic sacaba otro POST. `data-vf-busy` hace lo mismo para el doble clic.

### De paso: «In_review» y un `esc()` que nadie llamaba

Escribir los tests destapó dos cosas que ya estaban:

- **La insignia decía «In_review»** con guion bajo, en la ficha y en los dos
  listados (`ucfirst($form['status'])`). El texto sale ahora de
  `Form::statusLabel()` —que devuelve `Borrador`/`En revisión`/`Publicado`/
  `Cerrado`— y las tres vistas lo usan, porque tres `ucfirst()` divergen. La
  **clase** CSS sigue siendo `app-status-<status>` sin traducir: es la que le
  pone el color, y `app.css` la tiene.
- **`dashboard.js` ya no necesita `esc()`**: su único texto interpolado era el
  del aviso flotante, y desde que delega en `window.vfToast()` el escapado
  ocurre en `app.js`. `BuilderEscapingTest` pasó a mirar `builder.js` y `app.js`
  (y `esc()` se sigue comprobando donde se usa, no donde existió), más un test
  de que `dashboard.js` delega y no vuelve a construir markup. Un `esc()` que
  nadie llama es un `esc()` que nadie revisa.

### Dos capas de test, y por qué la del JS ejecuta el código real

`tests/js/async-actions.js` (51 casos) extrae `vfPost()` y el delegado del
`app.js` de verdad y los corre contra un DOM y un `fetch` de mentira, con el
mismo criterio que `tests/js/fill-progress.js`: lo que importa es el
comportamiento, y no se puede comprobar leyendo el fichero.
`tests/unit/AsyncActionsJsTest.php` lo ejecuta (con SKIP si no hay `node`) y
añade el contrato que no necesita node; `tests/unit/AsyncFormActionsTest.php`
prueba el servidor: los cuatro huecos, que guardar ajustes devuelve la insignia
del estado que **queda**, que sin la cabecera sigue el 302 de siempre, el
idempotencia del toggle y el `count` del borrado de respuestas.

Las tres se comprobaron **mutando el código**: sin el `form.submit()` del
rescate, sin el `data-vf-busy`, sin la comprobación del `Content-Type`, y con
`data-vf-async` borrado de una vista. Las cuatro se pusieron rojas. Un detalle
del arnés que costó lo suyo y que está escrito en su cabecera: el extractor de
funciones tiene que distinguir **literales de regex** (`esc()` lleva
`.replace(/"/g, …)`, y un conteo de llaves a pelo lee ese `/` como el principio
de un comentario y se va de cabeza), y el DOM mínimo tiene que imitar al
navegador en `element.id` (que refleja el atributo) y en `remove()` (que pone
`parentNode` a null **en el nodo quitado, no en sus hijos**), o los casos pasan
sin haber comprobado nada.

---

## F0.4-tres — El diálogo de confirmación, y un alta que recargaba sin saberlo

Dos cosas que llegaron en la misma petición sin tener nada que ver: el `confirm()` del
navegador (el prompt gris del sistema, con los botones del sistema operativo y el
texto en la fuente que toque) y el alta de un correo a la lista de acceso, que
**recargaba la página** mientras quitarlo no lo hacía. La segunda terminó siendo
el ejemplo más claro del tipo de fallo que más gusta a este repo: **síntoma
distinto, misma causa de fondo**.

### El diálogo

`window.confirm()` no se puede estilar, no se puede traducir, no lo puedes
señalar para copiar el texto y en algunos sistemas aparece **fuera de la
ventana**. Lo sustituye `#vfConfirm`: un `.modal` de Bootstrap en
`views/layouts/main.php`, **uno solo para todo el panel** (copiarlo en cada vista
es copiar el prompt feo por décimo lugar), con trampa de foco, `Esc`, vuelta del
foco al botón y `aria-labelledby`/`aria-describedby` para que el lector de
pantalla diga el título y **la pregunta**.

Dos decisiones que costaron algo:

- **El foco va a «Cancelar», no a «Sí».** Escribir un `Enter` nada más abrir un
  diálogo con «Eliminar formulario» enfocado es una operación sin vuelta atrás a
  un `Enter` de distancia. El botón de «sí» se pinta con `data-confirm-ok`
  («Eliminar formulario», «Desactivar cuenta»), porque un «Sí, continuar» sobre
  una pregunta no dice qué se está aceptando; y el tono sale de
  `data-confirm-tone` (`primary` para lo que no destruye, `danger` por defecto).
  El contrato lo ata `testCadaAccionQueConfirmaDiceQueVaASerElSuBoton`, que
  recorre los `<form data-confirm>` de las vistas y exige `data-confirm-ok` en
  todos.
- **La segunda pasada no vuelve a preguntar.** `vfConfirmGo()` hace
  `requestSubmit()`, que vuelve a lanzar el evento `submit` y con él el delegado:
  sin el `vfConfirmed` (un `var` de módulo con el formulario ya confirmado) el
  diálogo se abriría otra vez, y desde ahí es un bucle de diálogos. Es lo primero
  que se rompe si se toca esa línea, así que el arnés lo mira
  («una sola vez: el reenvío con requestSubmit no vuelve a preguntar») y no como
  un detalle: sin él, el arnés daba verde porque `requestSubmit()` del DOM
  mínimo no re-despachaba nada.

Y dos cosas que **no** se hicieron, por si acaso alguien las echa de menos:
`vfAsk()` sigue cayendo a `window.confirm()` cuando no hay diálogo o no hay
Bootstrap (una página que no usa el layout del panel no puede quedarse sin
preguntar), y el texto se pone con `textContent`, nunca con `innerHTML` (el
mensaje lleva cosas que ha escapado la vista).

### `#vfConfirm` y la regla de los modales anidados

`ViewStructureTest` prohíbe que un `.modal` viva dentro de otro elemento, porque
Bootstrap pone `display: none` al `.modal` y cualquier ancestro con `display`
distinto lo deja invisible para siempre. Con el diálogo del panel al final de
`<body>` la regla saltó, y la lista de permitidos es ahora `<html>` y `<body>`:
no son un escondite, son el documento. La regla sigue fulminando `<main>`,
`<div>`, `<form>` o cualquier otra cosa —que es exactamente lo que tumbó
`#addFieldModal`—.

### El alta que recargaba

«Añadir correo» recargaba y «quitarlo» no. Los dos son la misma puerta
(`AccessController`, el mismo partial) y los dos devuelven JSON. La diferencia
estaba en **el atributo que se había quedado viejo**:

```js
const endpoint = card.dataset.addEndpoint;      // ← la vista ya no lo tiene
if (!endpoint || email === '') return;          // ← sale sin quitar el evento
```

`#access` declara `data-link-endpoint` (el del botón de copiar el enlace), así
que `endpoint` era `undefined`, la línea de guarda **salía sin hacer su trabajo**
—sin `preventDefault()`— y el navegador sacaba su POST nativo. La lista se
repintaba igual, porque volvía la página entera: por eso el síntoma parecía del
servidor cuando lo que se había perdido era un `data-` en una vista.

Y en el servidor, `addEmail()` contestaba `html` como **cadena suelta** en vez
del mapa `id → markup` que recorre `vfApplySlots()`. Aunque el formulario
hubiera llevado `data-vf-async="slots"`, no habría habido a qué reponer. Las dos
cosas juntas eran necesarias para el fallo, y las dos están ahora fijadas por su
test (`testAnadirUnEmailPorFetchReponeLaListaYNoRecarga`, que además comprueba
que la lista trae el correo nuevo y que un repetido vuelve como error).

El handler propio de `dashboard.js` (93 líneas con su `fetch`, su `.catch` y su
`form.submit()` de rescate) **desapareció**: lo que hace ahora es lo mismo que
las otras diez acciones, el delegado. Un IIFE que reimplementa lo que ya hace el
delegado es, tarde o temprano, un camino que se queda sin escapar.

### El error junto a su campo, y no también flotante

El «ese correo ya está en la lista» salía en el `<p id="accessAddError">` de
más abajo, con su `role="alert"`. Con el delegado, el `ok:false` pinta el aviso
flotante de siempre —que está en el otro extremo de la pantalla y se va solo a
los cuatro segundos— y el `role="alert"` se queda vacío para siempre. Por eso el
formulario declara `data-vf-error="accessAddError"`: el motivo va junto al campo
(que es donde se busca) y **no** sale además flotante, que sería lo mismo dos
veces. El hermano `data-vf-clear="accessAddEmail"` vacía el campo y devuelve el
foco a él, que es lo que hace falta para apuntar el siguiente correo sin tabular
hasta el principio.

Los dos son opcionales y genéricos: `vfByAttr(form, 'data-vf-…')` resuelve el
`id` que declara el formulario. El JS sigue sin saber cómo se pinta una fila, un
aviso ni un campo; solo sabe a qué `id` escribir.

### Lo que encontró el arnés, y lo que no

`tests/js/async-actions.js` pasó de 51 a **88** casos. Los nuevos comprueban la
pregunta en `textContent`, el `data-confirm-ok`, los dos tonos, el foco en
Cancelar, que confirmar envía **una** vez y con el submitter, que cancelar no
envía, los dos caminos de reserva (sin diálogo y sin Bootstrap), el navegador sin
`requestSubmit()` (que vuelve al POST nativo entero), y los dos atributos del
alta. Cada uno se comprobó **mutando** el código: sin el bypass, sin
`vfPending`, sin el `hide()`, con el foco en «Sí», con `innerHTML` en el texto, y
con el aviso flotante además del de campo. Las seis se pusieron rojas.

Una mutación se escapó, y esa es la que merece.recordarse: cambiar el
`p.form.submit()` (el `else` para navegadores sin `requestSubmit`) por una línea
que no hacía nada **no rompió ningún caso**, porque el `requestSubmit` del DOM
mínimo estaba siempre y ese `else` no lo recorre nadie. El caso se añadió —«sin
`requestSubmit`, confirmar manda el POST nativo»— y la mutación pasó a ponerse
roja. Un test que no llega a la rama es un test que no vigila la rama.

## F0.4-cuatro — Un ajuste que se guardaba y una fila que se recargaba

Los dos que quedan son de la misma familia y de la misma causa de fondo: un
cambio de estado que **ocurre en el servidor** y que la pantalla no se entera
de, porque el hueco que lo pintaría no existe.

### La tarjeta de Acceso no tenía hueco, porque no es del estado

«¿Quién puede responder?» se edita en un `<select>` que guarda por fetch
(`updateSettings`), y la tarjeta de debajo —insignia del modo, enlace de acceso,
alta de la lista— depende **de ese ajuste**. Guardarlo sin recargar la dejaba
mintiendo: el modo nuevo estaba en la base de datos y la pantalla seguía
diciendo el anterior, y el síntoma era desconcertante porque la operación
«funcionaba» (navegador, red, aviso verde) y el cambio no se veía.

El arreglo es `FormPanel::accessSlot()`, que relee el formulario y devuelve
`['vfSlotAccess' => …]`, y `FormPanel::json()` gana un quinto parámetro para
sumarlo. **El hueco va aparte y a propósito**, y esa es la parte que no es
obvia: `slots()` son los cuatro que dependen de `forms.status` y se devuelven en
**todas** las acciones de estado —publicar, cerrar, mandar a revisión, aprobar—.
Meter la tarjeta de Acceso ahí la tiraría en cada una de ellas, y con ella el
enlace de acceso recién emitido (que se rota al regenerarlo) y lo que alguien
tuviera escrito a medio teclear en el campo del alta. Un hueco que se repinta
de más no es un coste menor: es devolver un token que ya no sirve.

El test (`testGuardarAjustesReponeLaTarjetaDeAcceso`) comprueba el **modo
nuevo** en el HTML, y no que «venga un hueco»: un ajuste que se guarda bien y no
se ve no da ningún error, ni en el navegador ni en el log, ni con la página
entera recargada. Al mutar el `mode` que se pinta, la lista sale vacía y el
modo dice siempre `public`; las dos mutaciones se pusieron rojas.

### `form.submit()` no dispara el evento `submit`

Cambiar el rol de una persona en `/admin` recargaba la página entera, y con ella
se iba el desplegable de grupos que se estuviera editando en la misma fila. El
formulario del rol lleva `.js-auto-submit` (se envía solo al cambiar el
selector) y el JS tenía:

```js
sel.addEventListener('change', () => form.submit());   // ← no hay evento
```

`form.submit()` **no** dispara el evento `submit`: es el envío nativo sin
evento. Así que el delegado no se enteraba de nada —no preguntaba, no apagaba el
botón, no elegía el camino sin recarga— y el navegador sacaba su POST. Con
`requestSubmit()` el evento sí sale, entra el delegado y funciona sin recargar; y
el `else` de navegador viejo (sin `requestSubmit`) sigue siendo el
`form.submit()` de siempre, con la recarga, porque ahí es lo único que hay.

Es el tipo de fallo que no se ve leyendo el código: «se envía solo», dice el
selector, y es verdad. Solo aparece cuando el delegado **no aparece**.

Lo que faltaba además era el `data-vf-async="slots"` en los dos formularios de
la fila (rol y grupos) y un hueco para las insignias de grupo
(`vfUserGroups<uid>`, el mismo nombre que compone el servidor en su respuesta).
`updateRole()` devuelve JSON **sin `html`**: el `select` ya está en el valor
nuevo y nada más de la fila depende del rol, así que repintar la fila entera
tiraría el desplegable de grupos —justo lo que se estaba arreglando—. El error
de un rol que no existe se pinta (`ok:false`) en vez de redirigir: un fetch que
se sigue un 302 se descarga la página entera dentro del fetch y no sabría que el
guardado falló.

### Dos capas de test, y la que casi no muerde

El arnés pasó de 88 a **93** casos. Los dos nuevos ejecutan `vfAutoSubmit()` de
verdad contra el DOM mínimo: el cambio de rol **no** sale por `form.submit()`
(`submitted` a 0, un `fetch`, y con el `action` del formulario) y, sin
`requestSubmit()`, vuelve al POST nativo entero. Para eso hizo falta un
`conDelegado(escenario)`: el arnés no imita el `addEventListener` del documento,
así que un `submit` **disparado por el código** no llegaba al delegado y el
caso pasaba verde sin comprobar nada —que es exactamente el fallo que se quería
detectar—. Volver a `form.submit()` pone el caso rojo.

En PHP, el contrato de la vista (`data-vf-async` en los dos formularios de la
fila y el hueco con el `id` por fila) se comprobó quitando cada cosa por
separado: quitar el atributo de cualquiera de los dos formularios pone el test
rojo nombrando cuál, y cambiar el nombre del hueco también. El que mide «que el
`submit()` solo viva en el `else`» mira **posiciones**, no solo presencia: un
`submit()` suelto antes del `if` deja igual el cambio de rol recargando, y una
comprobación de presencia no lo notaría.

Y un apunte que costó un rato de lexers: un `?>` **dentro de un comentario `//`
cierra el modo PHP**, y todo lo que viene detrás se va como HTML —el error es un
«Unclosed `{' on line 362» que no señala el sitio—. En bloque (`/* */`) no pasa.
Vale para cualquier nota que hable de `<?php` o `<?=` en un test.

### Los títulos de los grupos plegables con el nivel de la tarjeta

La tarjeta de ajustes la cabecera `h3 class="h6 fw-bold"` «Ajustes del
formulario» y cada grupo lleva `<legend class="app-settings-group-title">`.
Antes el `<legend>` no tenía tamaño y heredaba `var(--bs-body-font-size)`
(`1rem`), así que el nombre de cada grupo se leía como un **encabezado de
sección** (y más grande) en vez de con el nivel de la tarjeta (`h6 fw-bold`,
`.85rem`, bold). `font-size: .85rem; font-weight: 600;` iguala el grupo al texto
de la tarjeta; el botón del grupo lo hereda porque la etiqueta ya estaba en el
mismo elemento.

### Campos y Acceso en la columna ancha, y por qué la lista no va en el desplegable

La ficha era `col-lg-8` (Detalles) y `col-lg-4` (Compartir, Ajustes, Acceso,
Campos), y la tarjeta de Acceso quedaba arriba de la de Campos: para llegar al
formulario había que pasar por la configuración, y la lista —la única parte que
puede medir —cientos de correos—. Ahora la columna ancha lleva **Detalles → Campos → Acceso** y
la estrecha **Enlace para compartir → Ajustes del formulario**.

Dos motivos, y el segundo es el que de verdad lo impedía. La lista de correos
puede tener cientos de líneas, y en una columna de un tercio estiraba la de los
ajustes; en la ancha se lee. Y el desplegable «Acceso» de «Ajustes del
formulario» está **dentro de `<form id="formSettings">`**: meter allí los
formularios de la allowlist anidaría dos `<form>`, que no es HTML válido, y el
parser descarta el interior —la lista desaparecería de verdad, no solo de
maquetación—. Por eso el modo **sigue** en el desplegable y la tarjeta solo
explica y lista: separarlos no fue capricho, fue lo que quedó al no anidar.

De paso se borraron las tres referencias cruzadas que decían «más abajo». En
dos columnas, si la izquierda es más alta que la derecha, «más abajo» es verdad;
si es al revés, es mentira, y depende de los formularios que hubiera en la
instancia. Ahora las dos tarjetas se nombran y no se sitúan.

### El botón de «Guardar» se quedaba en «Guardando…» para siempre

Lo reportó un usuario: el **segundo clic** sin cambios dejaba el botón apagado
con el spinner, sin red, sin error y sin forma de volver a pulsarlo. La causa no
era el clic sino una **salida**: `saveStructure()` apagaba el botón, y el atajo
de M8 —`payloadJson === lastSavedJson`, que evita el POST inútil— hacía
`return` **antes** del `try`. Ese `return` se saltaba el `finally` que era lo
único que reponía el botón, y un `finally` solo corre si hubo `try`. Es decir:
el atajo que existe para **no** hacer nada era el que dejaba la interfaz
colgada.

El arreglo no es añadir el `pintarBoton(false)` que faltaba en esa rama —eso
arregla el clic de hoy y deja la misma trampa al próximo atajo—, sino que la
reposición tenga **una sola puerta**: `pintarBoton()`, y que toda salida la
llame. `testNingunaSalidaSeSaltaLaReposicionDelBoton` ata justo eso: recorre
los `return` que hay *después* de que se encienda el botón y falla si alguno no
tiene una `pintarBoton(false)` antes. Verificado poniéndole el bug de vuelta:
el test cae y nombra la causa.

El test quita los comentarios antes de leer el cuerpo, porque la explicación
del propio bug menciona `return` y sin hacerlo el test fallaría por su propia
explicación. Es la misma trampa que ya describen los contratos de
`builder.js`: un test que lee estructura de código no debe leer prosa.

## Fase 3 — el listado de participantes (modelo, CRUD e importador)

### La denormalización de `identifier_value` y su invariante

El identificador de una persona en el formulario puede ser su cédula, su indicador
o su correo, y son **tres columnas distintas**. La puerta del fill necesita
encontrar a alguien por ese valor en cada petición. Con un `CASE` sobre tres
columnas hacen falta tres índices y el mismo código repetido tres veces; con una
cuarta columna `identifier_value` hay un índice y una comparación.

El coste es que la copia se puede quedar vieja, y la única forma de que eso no
pase es que **cambiar el identificador del formulario reescriba la copia**. Por eso
`FormParticipant::sinIdentificadorPara()` existe y por eso hay un test que cambia el
identificador del formulario y comprueba el valor nuevo: sin él, un formulario
pasado de `cedula` a `correo` seguiría buscando cédulas en una columna que ya nadie
escribe, y **no daría ningún error**: solo respuestas de más, o invitaciones a
equivocados.

### `utf8mb4_unicode_ci` convierte dos cabeceras en un atributo, y eso no es un detalle de collation

El `key_name` de un atributo sale del `slugify()` de la cabecera, así que `Diseño` y
`Diseno` dan el mismo `key_name`. Sin más, importar dos archivos donde uno dice
`Diseño` y otro `Diseno` —incluso uno detrás de otro en el **mismo** archivo— intenta
insertar dos filas con la misma clave y se come un `1062`.

La opción no es «normalizar más», sino **reutilizar la fila que ya existe**: es lo
que hace `ensureAttribute()`. Importar una hoja con `Diseño` y otra con `Diseno` no
crea dos atributos que se pisan; reconoce que son el mismo y escribe en el mismo.

El caso hermano es el slug que choca aunque el texto sea distinto: `Sede-Norte` y
`Sede Norte` dan `sede_norte` los dos, y ahí no hay manera de reconocer que
**no son la misma columna para el editor**: son dos columnas distintas que casualmente
comparten nombre. Se resuelven con **sufijo** (`sede_norte_2`), que es la
convención que ya usa el generador de `code`.

### En `reemplazar`, validar antes de borrar no es una optimización

La tentación es borrar la lista y escribir lo que venga. El problema es que un
archivo con 399 filas buenas y 1 mala dejaría la lista con las 399 **sin decir que
era una sustitución**: el usuario creería que su archivo se importó entero. En
`reemplazar` la validación es previa y total; si hay un solo problema, no se borra
nada.

En `fusionar` lo contrario, y por el motivo opuesto: un error de fila **no** tumba el
resto, porque el propósito de fusionar es extraer lo que haya podido traerse y
devolver un informe de lo que no. Esa asimetría se comprueba en los dos sentidos
con tests, y cada una de las dos ramas se verificó **quitando su `return` de
protección**.

### Una columna ausente en el archivo es «no la he rellenado», no «bórrala»

Al actualizar una persona por importación hay dos cosas que se parecen: una columna
que el archivo nuevo ya no trae (el editor ya no la quiere) y una columna presente en
el catálogo que en **esa fila** no viene (esta persona no la tiene rellenada).
Borrarla por lo segundo pierde datos sin avisar.

La regla que salió de esto: **`reemplazar` es la única operación que puede quitar
columnas**, y para vaciar del todo está `truncate`. Y hay un tercero, que ya estaba
dicho pero que conviene repetir porque se olvida: **el catálogo no se borra al
reemplazar ni al vaciar la lista**. Las columnas son el formato del próximo archivo; si se fueran con
la gente, vaciar la lista destruiría el formato que el editor ya tenía, y la segunda
importación del mismo archivo no tendría nada que reconocer.

### El controlador responde JSON antes de que exista la pestaña, y no por comodidad

`ParticipantController` devuelve `{ok, message, ...}` cuando llega
`X-Requested-With: XMLHttpRequest` y flash + 302 cuando no. Podría haberlo hecho al
revés, pero la pestaña del panel es la fase 5: **la respuesta que necesita la
pestaña es esta**, y hacer la vista ahora sería escribir markup que se borra en dos
fases. Cuando llegue la pestaña, esta misma respuesta es la que consumirá, sin
cambiar el contrato.

Lo que sí es puerta, y vive en `rosterForm()`: `Access::canEdit()` y el modo
`roster`, con **404 y no 403** si el formulario no es de lista, porque que un
formulario tenga o no listado no es información que deba darse a quien solo puede
verlo.

Y dos detalles que parecen de estilo y son autorización: `update` y `destroy`
reciben un `participant_id` del POST, y el dueño de dos formularios —cosa
normal— puede mandar el id de la lista que no es. Sin `(int) $fila['form_id'] !==
$id`, editar o borrar en la lista equivocada es un `WHERE id = ?` y nada más. **Los
primeros tests no lo cubrían** (creaban el participante «ajeno» en el mismo
formulario, así que el `form_id» era el mismo y todo pasaba), y se detectó
**quitando el `form_id` a propósito y viendo que la suite seguía verde**. El
agujero estaba en los tests.

### Tres devoluciones que se parecen, y una que no se parece a ninguna

`Form::create()` y `Submission::create()` devuelven un `int`;
`FormParticipant::create()` devuelve `['ok', 'id', 'motivo']`, no la fila. Leer
`$fila['code']` de ese array da `null` en silencio, y un test que compara el código
antes y después pasa comparando `null` contra `null`: **verde, probando nada**.
Leer `$form['id']` de un `int` también da `null`, y la llamada se va a `/forms/0`,
que responde 404 — otra forma de test verde por la vía equivocada.

Y una cuarta, que es la que más caro sale cuando se confunde:
`attributesOf($participantId)` devuelve los valores de **una** persona, y
`catalogFor($formId)` las **columnas**. Pasar el id del formulario al segundo
devuelve `[]` sin decir nada, así que un test que «comprueba que las columnas
siguen ahí» se pasa mirando las celdas de nadie.

### El cliente no elige el tamaño de página

`index()` lee `page` del query pero **no** `per_page`: el tamaño lo fija el servidor.
Aceptarlo sería un `per_page=100000` que se come la tabla entera en memoria y
descuadra el `total` de lo que se pinta. Está atado a un test que crea 51 filas a
propósito, porque con cinco filas cualquier valor de página da el mismo resultado y
el test pasaría sin comprobar nada.

### El orden del listado es textual, y «arreglarlo» rompería la búsqueda

`allForForm()` ordena por `identifier_value`, que es texto: `V-51` va **antes** que
`V-6`. Es lo correcto para un identificador que puede ser «CC-1234», «V-51» o un
correo, y también lo es la búsqueda. Ordenar «como número» solo funciona para un
subconjunto y cambia la página 2 sin avisar.

### Lo que la fase 3 NO hace, y era lo importante

**`roster` todavía no era una puerta.** No se había tocado `FillController`, ni
`FormSettings::RESTRICTED_MODES`, ni el `<select>` de los modos: un formulario en
modo `roster` se podía llenar sin invitación. Todo lo de esa fase era la
maquinaria de gestión. [Ya lo hizo la fase 4](#fase-4-la-puerta-del-fill-portero-y-404-por-qué).

## Fase 4: la puerta del fill — por qué y por qué ahora

### La lista y el gate van en el mismo commit

`roster` entró en `FormSettings::RESTRICTED_MODES` **al mismo tiempo** que su puerta
en `resolveAccess()`. Un commit antes, un formulario en modo `roster` habría estado
**abierto al mundo sin un error en ningún sitio**: `isRestricted()` es lo que decide
que el GET pida identificación y que el POST corte, y una lista a la que no se le
añade el valor no falla, simplemente no hace nada. Por eso `RosterDoorTest` tiene un
test que **mira la lista** y no el comportamiento: quitar `roster` de ahí pone 13 tests
en rojo.

Lo que sí está separado en el tiempo es el `<option>` del selector de la ficha (fase
6). Esa separación es segura porque sin `<option>` nadie alcanza el modo, y es
preferible a tener la puerta sin poder probarla en una instalación real.

### El identificador identifica; el código autentica

Es la primera asimetría real del diseño y conviene decirla: en un listado, «quién eres»
y «puedes responder» son cosas distintas. `V-51` puede estar repetido en dos surveys de
distintas-RV, y `correo` es un dato que la gente escribe mal. Si la puerta fuera por
identificador, dos personas con el mismo dato se pisarían y quien lo escribió mal se
quedaría fuera. El código es de 16 caracteres del alfabeto sin `0/O/1/L` (32 símbolos,
~80 bits), se genera en el alta y se reparte fuera de banda.

### El código no caduca, y esa es una decisión, no una comodidad

Vive en la URL, y una URL se copia a mano, se pega en un correo, se mete en un reenvío
y acaba en un historial de chat. Una caducidad se acabaría rompiendo en alguno de
esos sitios, y el síntoma sería «el enlace de la semana pasada ya no funciona», que es
la clase de queja que no se arregla con un correo. Lo que lo cierra es que la persona
**siga en la lista**, y eso se comprueba contra la fila en cada request
(`FormParticipant::existeEnFormulario()`).

En sesión, el permiso es `['id' => <participante>, 'exp' => 0, 'kind' =>
'participant']`. El `exp = 0` se comprueba **antes** que la caducidad: comparar
`0 <= time()` como si fuera una fecha deja la puerta cerrada en cada petición, y ese
es el fallo que cuesta más de encontrar porque la primera vez que funciona parece
milagro. Los permisos de F4 no llevan `kind` y se siguen tratando como tokens, que es
lo único compatible con lo ya repartido.

La consecuencia de revalidar en cada request es la que hay que querer: **borrar a
alguien de la lista le cierra la puerta en la petición siguiente**, y no solo a quien
no había entrado. Es exactamente lo que significa «la lista es la que dice quién
responde».

### `?t=` y por qué no un parámetro nuevo

F4 ya llena `?t=` con el token de email. Meter el código en otro parámetro habría
significado un segundo camino de entrada al formulario —y el corte del POST, que va
antes que `post-max` y que `csrf` para no contestarle «algo va mal» a alguien que solo
necesita un código, solo podía mirar uno de los dos—. Además, el `action` del POST no
habría tenido que cambiar: el enlace ya reenvía la credencial al enviar.

En `resolveAccess()` el **token se busca primero** y el código después. En un formulario
de listado un `?t=` que casa con `form_access_tokens` sigue siendo ese token, y los dos
tipos de fila no se buscan en la misma tabla: `findByCode()` sin `form_id` sería la
puerta maestra de todos los listados, que es la mutación que mata
`testUnCodigoDeOtroFormularioNoAbreEste`.

### La puerta también es un formulario

El enlace se equivoca, y esa es la razón de `POST /f/{slug}/code`: se parte al pegarlo,
se teclea en minúsculas, se le copia un carácter de más. Sin esta página, un código con
un carácter mal deja al visitante en una pantalla de la que no puede salir —la puerta no
se abre «a medias» porque no hay forma de decir cuál de los 16 caracteres falló— y su
única salida sería escribirle al dueño para que le mande otro enlace.

- El **cupo va antes de buscar el código**, con dos claves (formulario+IP y código), y
  **cuenta los intentos fallidos**. Si solo sumara los aciertos, enumerar códigos sería
  gratis y el rate limit sería un adorno.
- La **respuesta es uniforme**: inexistente, de otro formulario o mal tecleo dicen
  exactamente lo mismo. Distinguirlos convierte la página en un oráculo de qué códigos
  existen, que es justo lo que el cupo intenta que no se pueda averiguar. El test
  compara el **texto** del flash, no el tipo: con el tipo, dos mensajes distintos serían
  indistinguibles y la uniformidad no estaría comprobada.
- Es un **PRG**: el canje válido redirige a `/f/{slug}?t=<código>` y un refresco no
  vuelve a canjear nada. En embed no hay variante con `/embed` porque el canje redirige
  el frame entero, que es lo que se quiere en un iframe.
- Da **404** (no 403) si el formulario no es de lista: que tenga o no listado es
  información que no se le da a quien solo lo ve.

### La atribución va en la quinta transacción

`responded_at` se marca **en la misma transacción** que la respuesta, con un `UPDATE`
idempotente con estado objetivo (`WHERE responded_at IS NULL`). Si fueran dos cosas, una
falla y la otra no: la lista de «quién NO ha respondido» enseñaría a alguien que ya
contestó, sin forma de saber que se separaron. Y responder dos veces **no mueve la
primera fecha pero sí guarda las dos respuestas**, porque `roster` no es «una respuesta
por persona» (eso, si se quisiera, es una regla de negocio, no una consecuencia de la
base de datos).

`submissions.participant_id` es **NULL** en `public`, `email` y `allowlist`: existe para
atribuir respuestas de un listado, no para marcar «alguien entró con un correo», que ya
está en `form_access_tokens` y ahí sí se puede ver quién usó qué enlace.

## Fase 5: la pestaña de participantes — las dos zonas se ven SIN JavaScript

**El qué:** la tarjeta de Acceso tiene una rama propia para `roster`, con cinco huecos
(`rosterStats`, `rosterList`, `rosterCatalog`, `rosterAddForm`,
`rosterImportReport`) y dos zonas: «Lista» e «Importar».

**El porqué de las dos zonas visible sin JS:** lo natural era el `tab` de Bootstrap, y
`.tab-pane` sin `.active` está **oculto por CSS**. Eso convierte un panel entero en
algo que depende de JavaScript para *existir*, y se pierde en el navegador que menos lo
puede permitir (sin JS, sin `fetch`, con una extensión que lo bloquea). Aquí el servidor
pinta las dos zonas **sin `hidden`** y `dashboard.js` lo **añade**; sin JS no hay nadie
que lo ponga. Es lo mismo que el `collapse show` de los grupos plegables, y al revés que
el `tab`: degradado en vez de perdido. El `nav-tabs` de Bootstrap se usa solo para el
aspecto.

**El porqué de los cinco huecos y no uno:** son cinco estados distintos, y repintarlos
todos en cada acción es peor que escribirlo. `store` no cambia el catálogo ni el
informe, y un `catalogFor` por alta es un `information_schema` que nadie pidió. El
informe va además por **argumento**, no dentro de `data()`: las filas omitidas no están
en ninguna tabla, y `data()` se recalcula en cada visita —un informe guardado que se
leyera sin borrarse volvería a enseñar las mismas filas después de cualquier alta—.

**El porqué de que el alta NO reponga `rosterAddForm`:** el repintado borraría lo que
el dueño tiene a medio escribir, y el foco que devuelve `data-vf-clear` apuntaría a un
nodo que ya no está en el documento. Un formulario que se vacía solo al guardar el
anterior parece un formulario que pierde lo que escribes.

**El porqué de que el informe llegue por los dos caminos:** con JS va en
`html.rosterImportReport` —también con `ok:false`, que es cuando más falta hace, y
`app.js` aplica los huecos **antes** de pintar el aviso porque un aviso que revienta no
debe llevarse el informe por delante—; sin JS, el POST es nativo y redirige, así que
`import()` lo deja en la sesión y `FormController::show()` lo consume con
`takeValue()`, de un solo uso. Y **solo en el camino nativo**: guardado también con JS,
reaparecería en la siguiente carga de la ficha, que es cuando el dueño vuelve a la
pestaña «Lista» y se encuentra con un aviso de una importación que ya terminó de mirar.

**El porqué de que la búsqueda viaje en la URL:** un GET a `/forms/{id}?q=…&page=…` y no
un fetch. Así llega sin JavaScript, se puede compartir en un correo y el botón «atrás»
deshace una búsqueda. Las acciones por fetch la guardan en `rosterQ`/`rosterPage`/
`rosterSort` para reponer la misma vista: sin ellos, borrar a alguien con la lista
ordenada por nombre la repinta ordenada por cédula y en la primera página, y el cambio
parece un fallo de la operación —que sí lo fue.

**El porqué de que un archivo ilegible no se pinte como informe:** el partial con
`omitidos = 0` diría «las 0 filas del archivo se guardaron» al lado de un mensaje que sí
dice la verdad. `informe()` devuelve `null` cuando hay `$error` y cero omitidas.

## Fase 6: exponer `roster` y elegir con qué se identifica — el ajuste que puede dejar entrar a nadie

**El porqué de las dos mitades:** cambiar el identificador reescribe la identidad de
toda la lista, así que va `planDeReidentificacion()` (que no escribe nada: cuenta, y
nombra lo que está mal) y, solo con el plan limpio, la escritura —ajustes y
`reidentificar()` en UNA transacción. Un `UPDATE` que se va a medias deja un formulario
cuyo identificador dice «cédula» y cuya lista dice «indicador», y la puerta no deja
entrar a nadie con un mensaje que no explica nada.

**El porqué de que el plan cuente en SQL y no en PHP:** `UNIQUE (form_id,
identifier_value)` es `utf8mb4_unicode_ci`, así que `V-1` y `v-1` son la misma clave.
Agrupar en PHP con un array asociativo diría «no hay repetidos» y el `UPDATE` se comería
un 1062 con un error de servidor, en vez de un mensaje que dice qué corregir. Al revés
que en la importación, donde lo que decide qué es el mismo dato es PHP.

**El porqué de que `code` no se toque:** es la credencial que ya se repartió, y va en
los enlaces de correo y en el QR. `reidentificar()` mueve solo `identifier_value`, así
que quien entra por un enlace viejo sigue entrando.

**El porqué de que el interruptor sea solo de páginas y secciones en el builder:** lo
dicen las columnas de `fields`, no la UI. Un salto de página o una sección no guardan
respuestas, así que marcar su etiqueta como dato personal no tiene nada que enmascarar.

## Fase 7: el filtro de la analítica — el catálogo se pide siempre, y hay tres variables

**El porqué de `$catalogo` + `$atrClave` + `$atr`:** elegir el atributo y filtrar por un
valor son dos pasos, y con una sola variable no se llega al segundo. La clave pedida sin
valor no es un filtro (`filtroAtributo()` devuelve `null` a propósito: «sin filtro» y
«filtro por nada» se ven igual), pero el desplegable de valores tiene que salir ya, con
la etiqueta marcada. Leyendo la vista del filtro *resuelto*, elegir «Sede» y guardar
devolvía la pantalla a «Todo el listado» con la palabra Sede escribiendo lo que no
hace: un filtro que al seleccionarse se deselecciona.

**El porqué de pedir el catálogo siempre:** no hay ningún botón ni enlace que ponga
`atr_key` en la URL. Pedirlo solo con filtro puesto deja la pantalla **sin forma de
tener el primero**, y no hay ningún error que lo delate: es un filtro que nunca se
usa.

**El porqué de que la identidad no se pueda filtrar:** la lista blanca es el catálogo,
y el catálogo no puede contener `cedula`, `nombre` ni `correo` porque el importador
trata esas cabeceras como columnas fijas. No es una decisión de este filtro: es la
herencia de la fase 3, y por eso `atr_key=cedula` en la URL devuelve `null`.

**El porqué de que un valor inexistente NO se descarte:** puede ser un valor que se
borró al quitar una columna del catálogo. Descartarlo devolvería la pantalla entera
sin decir por qué; dejarlo sale vacío y es la respuesta honesta a lo que se está
mirando.

**El porqué de que el CSV lleve el filtro:** un botón que descarga un archivo
**distinto** de lo que se ve solo se descubre abriendo el archivo. Y la columna del CSV
no se añade, porque el filtro es de las respuestas que entran y no un dato más del
formulario.

**El porqué de las columnas solo con filtro puesto:** son los datos de quién es quién y
nadie los ha pedido ver sin haber filtrado. Un guion en vez de un hueco porque en un
`table-hover` un hueco vacío no se lee, y «no lo rellenó» y «se olvidó la columna» se
confunden.

## Fase 8: comparar por atributo — el techo es la decisión, y el resto es una resta

**El porqué de agrupar solo con la clave elegida y sin valor:** comparar los grupos de
un atributo mientras se está mirando uno de ellos no dice nada — una columna con los
datos de todos y el resto en cero — y quien quiere ese detalle lo tiene en la tabla de
abajo, que además trae los atributos de cada respuesta. Son dos lecturas de la misma
tabla y se eligen por el filtro que ya existía, no con un interruptor nuevo.

**El porqué de dos consultas y no una:** el plan inicial pensaba en una consulta con
`key_name, value, field_id, value, COUNT(*)`. Se acabó en dos porque el techo obliga.
Los promedios necesitan `SUM`/`COUNT` por grupo (la media de las medias no es la media,
y sale un número plausible, no un error), y el «valor más repetido» necesita un tope
**por celda**, que en esa consulta única no se puede poner sin convertir 1 023
combinaciones de una casilla múltiple en 1 023 filas por grupo. Son las mismas dos
consultas fijas que ya hacía `forForm()`, así que la decisión de `FieldAggregator` de
no multiplicar por preguntas ni por grupos sigue valiendo.

**El porqué de que el total venga de `SUM() OVER` y no de sumar lo que sobrevivió:** el
porcentaje de la celda necesita el denominador de **todas** las respuestas del grupo.
Con un campo de 6 opciones en un grupo de 12 respuestas, el recorte deja 5 valores y
sumarlos daría «2 de 5 = 40 %» donde la verdad es «2 de 12 = 17 %». El número inflado
no se distingue de uno bueno, y dos grupos con la misma proporción se verían distintos.
Por eso `total` se lee de la ventana del nivel de arriba y el `WHERE rn <= N` va en el
de fuera.

**El porqué de `valoresConConteo()` sin `LIMIT`:** el cubo de resto es
`total − lo que ocupa cada columna`. Con el `LIMIT` de 200 valores del desplegable, un
atributo de 800 daría un resto que en realidad es la cola del `LIMIT`, y el total
dejaría de cuadrar con la suma de las columnas. El tope del desplegable se aplica ahora
en PHP sobre el mismo array, que además es lo que ya hace `valuesOf()`.

**El porqué de que «Resto» no se pinte vacío:** con 8 valores exactos la resta da 0, y
una columna en cero con un «n = 0» diría que hay gente sin el atributo. No hay nadie, y
eso es un dato distinto.

**El porqué de `NULL` y no de un centinela de texto:** los valores vienen de un `.xlsx`
que sube el dueño. Un valor puede ser literalmente `__resto__`, y `NULL` no puede
colisionar con nada. Por el mismo motivo `pa.form_id = s.form_id` va **en el `ON`** y
no en el `WHERE`: es la condición de la unión, y comprobarla por respuesta es lo que
impide que un listado de otro formulario se cuele en los grupos.

**El porqué de dejar fuera `checkbox`, `matrix` y `ranking`:** los tres guardan un JSON
con la respuesta entera. «El valor más repetido» de una casilla múltiple es la
combinación, no lo que eligió la gente, y el de una matriz es literalmente la
respuesta. Se podría descifrar en PHP, pero el recorte por combinaciones perdería las
opciones que solo aparecen en combinaciones minoritarias y su porcentaje saldría mal —
que es peor que no tener la fila. No es un olvido ni una decisión de estilo: es que
la métrica no es la que se cree.

**El porqué de que las PII no se consulten:** la comparación es una de las cuatro
columnas de la analítica que ya se enmascaran sin el permiso. Si aquí se consultaran,
esta pantalla sería el canal limpio de las otras tres. Y la lista de tipos la decide el
controlador, no la vista: una lista vacía es la forma de decir «este usuario puede
verlos».

**El porqué de la primera columna `sticky`:** con 9 columnas la tabla se desplaza en
horizontal dentro del `.table-responsive`, y el nombre de la pregunta es la única
columna que hay que tener siempre a la vista. El fondo es el de la tabla porque sin él
las celdas que pasan por debajo se ven a través del texto.

**El porqué de arreglar aquí el segundo enlace de CSV:** se armaba a mano con
`from`/`to` y se olvidaba del filtro por atributo, que la fase 7 acababa de añadir. Es
una regresión de la fase 7 que salió al leer la vista para la fase 8, y el test vive en
el fichero de la fase 8 a propósito: la comprobación que se añadió cuando se
encontró, en la misma superficie donde se encontró.

## Fase 9: quién respondió — la puerta es `responses.pii`, y sin permiso no hay columna

La atribución estaba guardada (`submissions.participant_id`, en la misma transacción
que la respuesta) y no se veía en ninguna parte. Se cruza ahora en el listado de
respuestas y en el detalle de una respuesta, detrás de `Pii::canView()`, y hay tres
decisiones que conviene no deshacer:

1. **`responses.pii`, no `responses.view_own`.** El primero ya significa «puedes leer
   datos personales en claro» y por defecto es solo del admin; el segundo decide el
   alcance (a quién puedes ver) y no la lectura en claro. Conceder la identidad con el
   segundo sería dársela a cualquiera que pueda abrir el formulario, que es justo lo que
   F1 selló al revés.
2. **Sin permiso no hay columna, y no una columna de `[oculto]`.** En un formulario que
   no es de listado la columna sería de «—» siempre; y una celda enmascarada en cada
   fila no dice nada que el aviso no diga. Lo que sí se hace es **explicar la ausencia**
   («tu rol no incluye `responses.pii`, en la ficha sí se marca quién respondió»), porque
   un formulario con lista que no dice quién respondió parece un fallo y no lo es. La
   columna se decide por el formulario y el permiso, nunca por si esa página casó con
   alguien: si dependiera de la página, al paginar una lista de mil aparecería y
   desaparecería por casualidad.
3. **Los exports no cambian.** Ni nombre, ni cédula, ni identificador, ni el
   `participant_id` (que tampoco estaba). Un archivo lo descarga cualquiera que tenga el
   botón, y el CSV además enmascara siempre porque su puerta es `forms.export_own`, no
   `responses.pii`. Poner un nombre en un export fabricaría el «canal limpio» que F1
   cerró: la regla de una sola política para todos los canales va en los dos sentidos,
   y en un archivo va en la dirección de no añadir.

Y el detalle técnico que más fácil se rompe: **el `<td>` de la fila va dentro del mismo
`if` que el `<th>` del encabezado**, y `RosterResponseAttributionTest` cuenta las
celdas de cada `<tr data-vf-row>` (5 con columna, 4 sin ella). Un encabezado con la
columna y filas sin ella se ve «casi bien» y no da ningún error — el mismo fallo que el
`</div>` perdido del modal en el builder. Mutación comprobada: quitar el `<td>` de las
filas dejando el `<th>` pone el test en rojo.

`identidadesDe()` lleva el `form_id` en el WHERE aunque los ids salgan de `submissions`
de un solo formulario: es la mitad de la autorización, como en
`FormAccess::findValidToken()`, y la mutación (quitar el `AND form_id = ?`) rompe el
test en limpio. Los `null` se filtran antes de consultar porque `intval(null)` es 0.

## Fase 10: el correo de invitación — `invited_at` es una fecha, el botón es por tandas y falla donde se ve

El listado ya cargaba, se editaba y se repartía el «Código» a mano. Faltaba escribirle
a la gente, y la forma obvia de hacerlo —mandar el correo al importar— es la que no se
puede hacer. Hay seis decisiones detrás de lo que quedó:

1. **`invited_at DATETIME NULL`, no un `TINYINT` «invitado».** El botón solo necesita
   «¿ya le escribí?», así que el booleano basta para la función y por eso mismo es el
   que no vale: un mes después la pregunta de quien gestiona la lista es «¿a quién le
   escribí la semana pasada?», y un booleano no la contesta. `NULL` de verdad y no
   `DEFAULT '0000-00-00'`, porque `IS NOT NULL` sobre la fecha cero marcaría a toda la
   lista como invitada desde el primer día y el botón no mandaría nada nunca.
2. **Solo con identificador `correo`; con cédula, la columna «Código».** Con `cedula` o
   `indicador` el correo es un dato opcional de la lista, no la identidad con la que se
   entra: mandar un correo ahí es escribirle a quien *tiene* una dirección, no a quien
   *debe* responder, y el enlace sigue siendo el mismo de la columna que ya está en
   pantalla. Es la misma razón por la que `roster` no se añadió al `<select>` de la
   fase 6 solo por existir en el código.
3. **Ni importación ni alta manual envían correo; los manda un botón.** Un `.xlsx` de
   400 filas son 400 correos desde un `POST`, y un envío automático que nadie ha pedido
   no se puede deshacer. La importación deja la lista con `invited_at IS NULL` y quien
   gestiona la lista decide cuándo escribir. Es la razón por la que
   `RosterInvitationMailTest::testImportarNoMandaCorreoNiConIdentificadorCorreo` existe:
   la puerta se comprueba por el camino que **no** manda.
4. **50 por tanda y tres fallos seguidos, y el botón lo dice.** `Mailer::send()` lanza,
   así que una lista de 400 es 400 iteraciones sin límite en una petición: un SMTP caído
   da 400 excepciones idénticas y un «algo falló» que no dice a quién. Con el tope de
   50 el botón rotula `Enviar invitaciones (37)` —el tope es información, no un fallo— y
   con el de 3 se para y explica que los fallidos **siguen en la lista**. Un fallo suelto
   no corta la tanda porque de verdad pasa (una dirección que el cliente acepta y el
   servidor no) y cortar por eso castiga al resto.
5. **`markInvited()` va después del `send()`, nunca antes.** Marcar antes de saber si el
   correo salió es perder el envío sin avisar: la fila desaparece de la cola y nadie la
   vuelve a mirar. Con el orden correcto, un fallo no es «se perdió el correo» sino «el
   correo sigue pendiente», que es lo que hace posible reintentar. Mutación comprobada:
   marcar antes del `send()` pone en rojo los dos tests de fallos.

6. **Corregir el correo devuelve la fila a la cola; renombrar no.** La marca dice «a
   esta dirección se le mandó el enlace», así que una dirección nueva no puede heredar el
   acta de la anterior. Sin esto, arreglar una errata en el correo dejaba la fila
   marcada, el botón decía «no queda nadie pendiente» y el correo nuevo no se podría
   invitar nunca: la puerta más cerrada de las tres, porque no hay error, no hay aviso
   y el botón es el que dice que no hay nadie. El filtro es **el correo y solo el
   correo**: si la marca se borrara en cada escritura, bastaría con volver a subir el
   mismo `.xlsx` para que las 400 invitaciones volvieran a salir, y eso sí ocurre con
   la importación en modo fusionar, que llama a `update()` fila por fila.

Y el detalle de la vista que más fácil se rompe: **el botón no declara
`data-vf-error`**. En un `ok:false` por fallos el servidor devuelve `html` con el slot
`invites` repintado, así que un `data-vf-error` dentro de ese slot quedaría
**desacoplado** del nodo que lo contiene y el error no se vería nunca. El resultado de
la tanda va al aviso flotante. Es la regla general de los slots (`data-vf-error` vive en
un formulario estable) aplicada a un formulario que vive dentro de un hueco.

Dos cosas más que quedan escritas porque son fáciles de deshacer sin querer: el
correo **no menciona que existe una lista** (dice que hay un formulario que responder,
no «te toca entre 400»), y `invited_at` **no aparece en las exportaciones ni en la
analítica** — es una marca operativa de a quién se escribió, no un atributo del
listado. La pantalla donde se ve es la ficha del formulario, en la insignia de los
números.

## M2 — el listado del panel pagina en el servidor, y por eso la búsqueda también

**El hallazgo (auditoría 2026-09-25, M2).** `Form::byUser()` y `Form::all()`
devolvían el alcance entero y el `LIMIT` estaba en el navegador: el grid llevaba
`data-paginate data-rows="9"`, así que con doscientos formularios la página
pintaba doscientas tarjetas para mostrar nueve, y la subconsulta
`LEFT JOIN (SELECT … FROM submissions GROUP BY form_id)` agregaba la tabla
entera de respuestas **en cada consulta**, porque el `LIMIT` del exterior no la
reduce. La caché de un minuto solo tapaba el síntoma.

**La decisión que se aplazó y ahora se toma.** En el propio plan de remediación
esto quedó anotado como «cambia de interfaz, se decide aparte a propósito, para no
meter un cambio de producto disfrazado de Fix de capa de datos». Correcto: aquí
no se puede poner un `LIMIT` sin más, porque la búsqueda, los cinco filtros de
estado y los cuatro órdenes eran **de cliente**. Un `LIMIT` a secas habría dejado
un buscador que solo mira las doce tarjetas de la página: se busca «norte», se
pintan las doce y se ocultan todas. Un buscador que no encuentra lo que hay se ve
igual que uno que funciona.

Así que el arreglo del `LIMIT` es **la mitad** del trabajo, y la otra mitad es
mover los tres filtros a la consulta. Es exactamente lo que ya se había hecho con
el listado de participantes de la ficha (`?q=…&page=…&sort=…`), y el panel se
queda con la misma forma: la página vive en la URL, el botón de atrás funciona, el
enlace se puede copiar y sin JavaScript hay un formulario GET.

**Lo que cambió en la pantalla.**

1. Los botones de estado son **enlaces** con el filtro puesto. Un `active` que
   solo existe en memoria no se puede compartir ni sobrevive al «atrás».
2. El buscador y el desplegable de orden están dentro del mismo `GET`. Con JS,
   el buscador envía con un retardo de 400 ms (como se escribe, no en cada tecla)
   y el desplegable al cambiar; sin JS, el `Enter` busca y hay un botón
   «Aplicar» en un `<noscript>` para el orden.
3. El paginador son **enlaces** que conservan los filtros, y a una página por
   encima del final se recorta a la última.
4. Se fue el paginador de cliente de `app.js` (`[data-paginate]`, el evento
   `vf:refilter`) y su CSS `.app-pg-hidden`: con los filtros en el servidor no
   queda nada que ocultar.

**Las tres cosas que se pudieron hacer mal y se notaron al hacerlas.**

- **Los dos vacíos.** El «Sin resultados» se puso primero dentro de la rama
  `else` de `empty($forms)` —que es un error de lectura, no de PHP: un filtro que
  no casa con nada deja la página vacía, así que caía en el «Aún no tienes
  formularios», con su botón de crear el primero. Es mentira cuando hay catorce.
  Lo decide `$stats['total']` (el alcance entero) y no `$listado['total']`, que
  viene filtrado y vale cero en los dos casos.
- **Las cuentas de arriba.** Salían de recorrer el array de formularios. Con una
  página de doce, «Formularios: 12» con catorce en la cuenta. Ahora son
  `Form::counts()`, una consulta aparte, sin filtros y cacheada: son datos de la
  instalación, no de lo que hay en pantalla. Y la insignia de cada tarjeta y el
  aviso de «hay N en revisión» siguen saliendo de ese MISMO array, que es lo que
  llevaba desde F3.
- **El desempate del orden.** `ORDER BY updated_at DESC` solo, con dos
  formularios creados en el mismo segundo, deja la frontera entre páginas
  indeterminada: uno sale en dos páginas o en ninguna. Todos los órdenes acaban
  en `f.id`.

**Dos trampas de la capa de datos**, la misma de siempre: `ATTR_EMULATE_PREPARES =
false` no admite un marcador repetido, así que el `IN` del agregado y el del
`WHERE` usan juegos distintos (`:c0…` y `:w0…`), y la búsqueda es un
`CONCAT(title, ' ', description) LIKE :q` con **un** marcador en vez de dos `LIKE`
con el mismo `:q`. Y los `%`/`_` de lo que se escribe se escapan: sin eso, buscar
«100%» devuelve el alcance entero.

**Lo que no se hizo.** No hay selector de tamaño de página, ni se recuerda la
página al volver, ni hay filtro por propietario en el panel (el de `/admin/forms`
tampoco): son cosas que se pueden pedir cuando alguien las pida, y ahora el
`LIMIT` es de una pantalla. Y el `LIMIT` de 12 es del modelo
(`Form::POR_PAGINA`), con `/admin/forms` usando 20 porque su tarjeta es más
pequeña.

- La puerta del listado y su pantalla de acceso nombran el identificador (2026-10-08): `settings.access.identifier` decidía **a quién** buscaba la puerta y la pantalla pública seguía pidiendo «tu código personal o identificador» con un placeholder de 16 caracteres y un icono de ticket, así que un formulario en «cédula» le pedía a la gente algo que la ficha nunca mencionaba —y al revés: `RosterDoor::buscarAmbos()` probaba cédula, correo e indicador a ciegas, de modo que un formulario que pedía correo se abría con la cédula de cualquiera de la lista. Cuatro decisiones. (a) **La página entera sale de `access.*_{identifier}`**: `views/fill/access.php` resuelve el identificador contra `FormSettings::ACCESS_IDENTIFIERS` y compone la clave del rótulo, el placeholder, la intro, la nota, el icono y los atributos del input (`type=email`/`inputmode=email` para correo, `maxlength` solo para código); se borraron las cinco claves genéricas (`access.code_label`, `access.code_placeholder`, `access.intro_roster`, `access.note_roster`, `access.code_invalid`) **con sus usos**, porque un texto que no nombra el dato no le dice a nadie qué escribir. El `name="code"` y el `id="vfAccessCode"` no cambian: son el contrato con `verifyCode()` y con los tests, y no dependen del rótulo. (b) **La puerta admite solo el código y el identificador configurado** (`buscarAmbos()`), con `identifier = codigo` sin segunda vuelta porque el código ya se probó: es la misma regla que el `form_id` en el `WHERE` de `FormAccess::findValidToken()` —lo que la página no pide, no abre—. (c) **El mensaje de inválido se elige por FORMA y no por el motivo**: si lo tecleado parece código (`codeValido(normalizar(...))`) o el identificador es `codigo`, dice `access.invalid_codigo`; si no, `access.invalid_{identifier}`. La uniformidad del oráculo se mantiene (el alfabeto del código es público: 16 de esos caracteres dicen lo mismo existan o no) y quien escribió su cédula recibe un mensaje que nombra su cédula, que es lo único que le dice qué corregir — con eso se arregla `testUnCodigoMaloSeContestaIgualQueUnoInexistente`, que dejó de bastar con comparar mensajes entre sí. (d) **Con `identifier = codigo`, `identifier_value` pasa a ser el propio `code`** (`create()` lo genera antes de validar y lo guarda como identidad, `update()` usa `actual['code']`, `planDeReidentificacion()`/`reidentificar()` ya trabajaban con la columna `code`), mientras que **la columna del archivo sigue siendo la cédula** (`identificadorDeArchivo('codigo') === 'cedula'`): nadie puede saber de antemano el código que la lista va a generar, y sin esa traducción el importador pediría una columna imposible. Consecuencia que se acepta: el `UNIQUE (form_id, identifier_value)` pasa a ser sobre el código, así que en este modo dos filas pueden compartir cédula (el importador sigue deduplicando por la cédula); y la tarjeta de la lista muestra **la columna de la identidad y no el `identifier_value`**, o el código saldría dos veces y la cédula no saldría en ninguna parte (`_roster_list.php`, `$identidad()`). Textos del panel alineados en la misma tanda: la ayuda del desplegable, el `stAccessHint` de `dashboard.js` («con su código o con el identificador elegido») y el rótulo de orden de la lista. Tests: 10 métodos nuevos — 5 en `RosterDoorTest`, 2 en `FormParticipantTest`, 2 en `ParticipantImporterTest` y 1 en `RosterIdentifierTest` (1042 en total)—; comprobaciones manuales W14–W19 de `docs/pruebas-funcionales.md` (442).

- El ancho del fill es `col-12 col-lg-6`, y el preview va con él (2026-10-08): `.fill-container` tenía `max-width: 700px`, un techo fijo que en una pantalla de 1920 dejaba la tarjeta en un 36 % de la ventana (y en horizontal es donde primero se queda corta una matriz de cinco columnas), mientras que en un portátil de 1366 casi la llenaba. Ahora la clase declara `width/max-width: 100%` con `margin: 0 auto` —todo el ancho por debajo de `lg` (992px)— y `@media (min-width: 992px)` la baja a `50 %`. Cuatro decisiones. (a) **La regla es de UNA clase y la comparten las ocho superficies**: las siete vistas del fill (`show`, `thanks`, `access`, `draft`, `full`, `closed`, `in_review`) y el preview en vivo (`#livePreviewContent`), que se pinta con la misma `.fill-container` a propósito; así las siete cambian juntas y una octava tiene que decidir su ancho. `FillLayoutTest` cuenta esas ocho superficies y además rechaza un `style=` o una clase `w-*` en la etiqueta, que ganaría a `app.css` en silencio. (b) **El preview va con el MISMO comportamiento y sin regla propia**: la media query mira la VENTANA, no el elemento, así que dentro del `modal-xl` del constructor también se parte a lg —que es lo que se pidió—; y hay un test que prohíbe `#livePreviewContent { width: … }`, porque el id gana por especificidad a la clase y separaría preview y publicado sin que nada falle: el preview enseñaría un formulario más ancho que el que sale. (c) **`@media print` vuelve a todo el ancho**, porque una tarjeta al 50 % del papel es medio formulario. (d) Coste conocido y aceptado: entre 992 y ~1400 px de ventana la tarjeta mide 512–699 px, más estrecha que los 700 de antes; si resulta apretada, el arreglo es `xl` (1200 px), una palabra en la media query. De paso se borró `views/dashboard/forms/_participants_card.php`: parcial huérfano sin una sola referencia, nunca committeado (ausente de `git ls-files`) y sustituido por `_roster_panel.php` en la fase 5 de los participantes; con él, `find views -name '*.php' | wc -l` vuelve a 62, que es la cifra que dice `AGENTS.md`. Tests: `FillLayoutTest` (4 métodos; 1046 en total) y la comprobación manual S35 (443).

- Los botones del top, las pestañas tras guardar, el responsive de los ajustes y el enlace de registro (2026-10-08): cuatro regresiones de la misma clase —algo que se aplica o se guarda bien y no se ve—. (a) **Botones del top de la ficha**: la cabecera de `views/dashboard/forms/show.php` mezclaba el CTA a 44px/radio 12 con los contornos a ~38px/radio 6 y el botón del menú a 32×32/radio 8, tres alturas en la misma fila. Se acotan con una clase nueva `app-head-actions` en el contenedor y no en los botones, para que la regla general del CTA no alcance a `views/responses/index.php`, que sí quiere sus `btn-sm`; los cuatro controles comparten ahora 44px de alto y radio 12, y el menú «más» pasa a 44×44. (b) **Las pestañas sobreviven al repintado**: `dashboard.js` montaba las pestañas del panel de participantes en un IIFE que corría solo al cargar la página; «guardar ajustes» repinta `#vfSlotAccess` —con el panel de roster dentro— por `innerHTML`, y el IIFE ya no volvía, así que las dos zonas («Lista» e «Importar») quedaban apiladas. Se extrae a `window.vfInitTabs()`, idempotente con un marcador `data-vf-tabs-ready` por contenedor, y `app.js` la llama al final de `vfApplySlots()`. (c) **El responsive de los ajustes mira el contenedor, no la ventana**: los campos de Notificaciones y Webhook usaban `col-md-*`, que decide por el ancho de la VENTANA (≥768px), pero viven dentro de la columna estrecha `col-lg-4` (~400px): a 768px de ventana el contenedor seguía a ~400 y las columnas salían a ~120px. Se pasan a `col-12 col-sm-6` (o `col-12` el mensaje y la URL). (d) **El enlace de registro del login honra el interruptor**: `views/auth/login.php` lo decidía con `Config::get('app.registration_enabled')` (solo el `.env`), así que activar el registro desde el panel —que escribe la fila de `app_settings`— no lo mostraba y nadie llegaba a un registro abierto. Ahora consulta `AuthController::registrationEnabled()`, la misma puerta que sirve `showRegister()` y que resuelve `app_settings` sobre el `.env`. Tests: `FormFichaLayoutTest` (cabecera y responsive), el método de repintado en `RosterPanelTest`, `testElEnlaceDeRegistroDelLoginObedeceAlInterruptor` en `RegistrationSwitchTest` y el contrato del enlace en `AuthHardeningTest`. Tests: 1050 en total.

- El select de orden y la respuesta que no soltaba a nadie (2026-10-08): dos incoherencias de las que no dan ningún error. (a) **El desplegable de orden del panel medía ~11px más alto que el buscador y descuadraba la fila**: `app.css` tiene una regla global `.form-control, .form-select, .input-group-text { padding: .6rem .9rem }` con la MISMA especificidad que `.form-select-sm`, y como `app.css` va después de Bootstrap, dejaba sin efecto al `-sm`; el buscador se salvaba porque vive en un `input-group-sm` y `.input-group-sm > .form-control` (0,2,0) le gana. La corrección se acota al select y **no** a la regla global (tocar esta última cambiaría todos los `-sm` de la app, que nadie ha revisado), y con **dos clases** (`.form-select.app-dash-sort`, 0,2,0): con una sola la regla perdía por ORDEN, porque el bloque global `.form-control, .form-select` está **más abajo** en el mismo `app.css` y a igual especificidad gana el último —el primer intento de arreglo cayó justo en eso, y el select no cambiaba—. El `padding-top/bottom: .25rem`, `padding-left: .5rem` y `border-radius: .25rem` van con esa especificidad; el `padding-right` se deja para la flecha. (b) **Borrar una respuesta no soltaba a la persona**: `form_participants.responded_at` es la marca que cuenta en la tarjeta de Acceso y, sobre todo, la que cierra la puerta de `roster` —quien ya respondió no vuelve a entrar—, pero `Submission::delete` la dejaba puesta, así que la tarjeta seguía diciendo «respondió» y la persona no podía volver. Nuevo `FormParticipant::desmarcarSiSinRespuestas()`: `UPDATE form_participants SET responded_at = NULL WHERE id = :id AND responded_at IS NOT NULL AND NOT EXISTS (SELECT 1 FROM submissions s WHERE s.participant_id = :pid)`, con marcadores distintos porque `ATTR_EMULATE_PREPARES = false` no admite uno repetido. Se llama desde `ResponsesController::delete` DESPUÉS del `DELETE` —es cuando el `NOT EXISTS` ya ve el hueco— y con el `participant_id` leído de `findWithAnswers()` antes de borrar la fila. El `NOT EXISTS` respeta que `roster` no es «una respuesta por persona»: si le queda otra, la marca se mantiene. Tests: `testElSelectDeOrdenMideLoMismoQueElBuscador` en `DashboardListMarkupTest` y los dos de `RosterResponseAttributionTest` (mutado y comprobado: sin la llamada, el primero se pone rojo). Tests: 1053 en total; V24 y AB24 de `docs/pruebas-funcionales.md` (449).

- La tipografía del tema es un catálogo cerrado (2026-10-09): `settings.theme.font` era texto libre con lista blanca por patrón (`FormSettings::isValidFontFamily()`), es decir, el editor elegía por teclado una familia del sistema del visitante —si no la tenía, se caía a la que hubiera— y el propio texto de ayuda lo decía («esto no descarga la fuente»). Ahora es una **lista cerrada** de cuatro familias variables auto-hospedadas en `public/assets/fonts/` (Inter, Source Sans 3, Source Serif 4 y Lora, SIL OFL 1.1, subconjunto latin) y `FormSettings::fonts()` devuelve `familia => archivo`. Por qué es una decisión y no un detalle: (a) **lo que el editor elige se ve igual en todas partes** porque el `@font-face` que emite `themeCss()` apunta al `.woff2` de la misma instalación (mismo origen, sin terceros ni CDN); (b) **la inyección de CSS de la fase 2 se queda sin punto de entrada**, porque el nombre de familia y el archivo salen del mapa, no del texto de la fila —`isValidFontFamily()` desaparece y sus llamadores (vista, builder.js y ThemeFontCssInjectionTest) pasan al catálogo—; (c) la regla de las DOS puertas se mantiene igual: `normalize()` descarta lo que no esté en el catálogo (entrada) y `themeCss()` hace lo mismo al emitir (salida, para las filas viejas de cuando era texto libre), y `validate()` sigue mirando el valor crudo; (d) el select del builder se rellena con `FormSettings::fonts()` (sustituye al input libre + datalist) y el aviso `#stFontNote` sigue siendo estado (lo pide BuilderHelpTooltipTest) pero ahora dice «se sirve desde esta instalación». `ThemeFontCssInjectionTest` comprueba el catálogo en las dos direcciones que promete `FUENTES.md`: toda familia tiene su `.woff2`, y todo `.woff2` de la carpeta está en el catálogo (un archivo huérfano es código muerto). Añadir un idioma o una familia es subir el archivo y añadir su fila en `fonts()`; sin la fila, la familia no se puede elegir ni se emite su `@font-face`.
