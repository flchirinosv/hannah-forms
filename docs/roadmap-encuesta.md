# Roadmap Encuesta — VanillaForms (Tier 1 + Tier 2)

**Fecha:** 2026-09-25
**Contexto:** comparativa vs constructores populares (Google Forms, Typeform, SurveyMonkey,
Jotform, Tally, Formspree). Decisión del usuario: implementar **Tier 1 + Tier 2**; **Tier 3
queda en backlog para el futuro**.

## Decisions aprobadas (defaults)

1. Email: **SMTP cliente propio** (`App\Core\Mailer`, STARTTLS, AUTH LOGIN/PLAIN, sin composer).
2. QR: **librería single-file vendored** `libs/qrcode/qrcode.php` (qrcode-generator de Kazuhiko Arase, MIT) con render **SVG propio** desde la matriz (sin GD). La API de la matriz: `QRCode::getMinimumQRCode($data, QR_ERROR_CORRECT_LEVEL_M)`, `getModuleCount()`, `isDark($row,$col)`.
3. PDF: **vista imprimible** (navegador→PDF) + export **JSON**; sin binarios nativos.
4. i18n: **alcance reducido** (fill público, gracias, correos; `APP_LOCALE` + `settings.locale`).
5. Uploads: **incluidos**, `storage/uploads`, descarga **solo autenticada**, límites por campo.
6. Quiz: puntuación **computada al vuelo** (sin columna nueva).

## Hallazgos del terreno

- `forms.theme` y `forms.settings` (LONGTEXT JSON) **ya existen** → sin migración para settings.
- `fields.validations`/`options` JSON libres → correct answer / randomize / rows×cols sin DDL.
- `answers.value` TEXT → matriz (JSON) y archivos (JSON ruta+nombre) sin DDL.
- `LogicEvaluator` + réplica `fill.js` → lógica avanzada se toca en ambos + tests.
- Whitelist de tipos en `BuilderController::saveStructure` (~línea 69) + `builder.js:typeInfo`.
- XFO `DENY` + CSP `frame-ancestors 'none'` → embed necesita **relajación opt-in solo en
  `/f/{slug}/embed`** (fill normal sigue `DENY/none`).
- `style-src 'unsafe-inline'` en la CSP → theming con CSS vars inline es compatible.

## Schema de `forms.settings` (fuente única, whitlist en `App\Services\FormSettings`)

```
theme    { primary, background, font }
schedule { open_at, close_at }              // datetime ISO o null
quota    { enabled, max }                   // max envíos
notify   { enabled, emails[], confirmation: { enabled, subject, message, to_field } }
webhook  { url }
thanks   { message, redirect_url }
quiz     { enabled }
randomize{ questions }                      // shuffle de preguntas (y options por campo)
embed    { enabled }                        // habilita /f/{slug}/embed
honeypot { enabled }
locale   { code }                           // 'es' | 'en' (i18n reducido)
```

## Fases (implementación en curso)

| # | Fase | Esfuerzo | Estado |
|---|------|----------|--------|
| P1 | Settings como fuente única (modelo+validador, builder/save, panel Configuración, tests) | 1.5 d | **COMPLETADA** |
| P2 | Tipos de encuesta: matriz/Likert, NPS 0-10, estrella 1-5, ranking + analytics/CSV | 3 d | **COMPLETADA** |
| P3 | Gestión de respuestas: listado (paginación server-side), detalle, borrar, export JSON | 2 d | **COMPLETADA** |
| P4 | Multi-paso: tipo `page`, barra de progreso, validación por paso, draft con `step` | 2 d | **COMPLETADA** |
| P5 | Email SMTP propio + notificaciones dueño/confirmación + webhook | 2 d | **COMPLETADA** |
| P6 | Cupos, apertura/cierre, gracias personalizado + redirect (PRG) | 1 d | **COMPLETADA** |
| P7 | Honeypot, prefill por URL, theming, embed/QR/share, clonar | 2.5 d | **COMPLETADA** |
| P8 | Quiz + lógica avanzada (regex, salta-página) + randomización | 2.5 d | **COMPLETADA** |
| P9 | Plantillas (6-8 JSON), export imprimible/JSON, i18n reducido | 3 d | **COMPLETADA** |
| P10 | Uploads de archivos (storage, validación, descarga autenticada) | 3 d | **COMPLETADA** |

**Total estimado: ~22-23 días seriados.**

**P2 (implementado):** tipos `matrix`, `nps`, `star` y `ranking` en builder/fill/analytics.
- `matrix`: options con `group: rows|cols`, editor en builder (textareas filas/columnas), render tabla de radios, valor JSON `{Fila: Col}`; `Answer::matrixStatsInForm` → tabla de conteos por fila/columna.
- `nps`: 0-10 fijo, tarjeta numérica + score NPS (`Answer::npsStatsInForm`).
- `star`: escala 1-N (default 5), render estrellas, `numberStats`.
- `ranking`: options + inputs number por opción, valor JSON `{Opt: orden}`, validación 1..N únicos; `Answer::rankingStatsInForm` → promedio por opción.
- Server: `FillController::normalizeFieldValue` preserva claves (`array_is_list`), `validateField` reglas nuevas, `stringify` con claves.
- CSV y tabla de detalle decodifican objetos `{k: v}`.
- Whitelist en `BuilderController::saveStructure` y `typeInfo` en `builder.js`. Tests: `tests/unit/SurveyTypesTest.php` (17 tests).

**P3 (implementado):** gestión de respuestas (`ResponsesController` + `views/responses/{index,show}.php`).
- Listado `/forms/{id}/responses` con **paginación server-side** (remedio de M2 del audit): `Submission::byFormPaginated` con `LIMIT/OFFSET` (page clamp a la última, `per_page` fijo 15), enlaces numéricos con elipsis.
- Detalle `/forms/{id}/responses/{sid}` con metadata (fecha, IP, user-agent) y respuestas decodificadas (`Answer::displayValue`: arrays `a; b`, objetos `clave: valor`).
- Borrado por POST con `csrf` y `data-confirm`; las answers caen por FK ON DELETE CASCADE.
- Export JSON `/forms/{id}/export.json` (attachment, respeta `from/to` como el CSV — lección M5): `Submission::exportPayload` decodifica compositos a arrays/objetos; metadata de la submission incluida.
- Entrada: botón **Respuestas** en `views/dashboard/forms/show.php`. Tests: `tests/unit/ResponsesTest.php` (11 tests).

**P4 (implementado):** multi-paso con tipo de campo `page` (salto de paso).
- `page` es estructural de nivel superior (sin `parent_id`), no se responde, `is_required=false`, sin validations/options/placeholder. Las condiciones de página (salta-página) llegaron con **P8**.
- Backend: `BuilderController::saveStructure` (`$isStructural` para section+page, rechaza salto dentro de sección), `FillController::submit` ignora valores de campos `page`.
- Builder: paleta dividida en **Estructura** (sección + página) y **Tipos de pregunta**; menús `+` raíz incluyen página (los de sección no); preview con divisor.
- Fill: bloques `.vf-page-block` (uno implícito si hay contenido antes del primer salto), nav `Anterior/Paso X de Y/Siguiente`, validación **por paso** (`validateStep`), borrador guarda `step` y reanuda en él, Enter avanza, `hasServerErrors` salta al último paso. Sin páginas → fila de envío clásica intacta.
- Límite conocido (documentable): condiciones pueden referir campos de otros pasos (evaluadas con valores actuales); "salta-página" es P8.
- Tests: `tests/unit/MultiStepTest.php` (6 tests). Validación UI/UX manual por el usuario.

**P5 (implementado):** correo propio + notificaciones + webhook.
- `App\Core\Mailer` (nuevo): cliente SMTP sin librerías — conexión directa/`ssl`, **STARTTLS**, `AUTH LOGIN` con fallback `AUTH PLAIN`, mensaje **multipart/alternative** (texto+HTML, charset UTF-8), asuntos con encoded-words RFC 2047, dot-stuffing, saneo de CR/LF contra inyección de cabeceras, timeouts acotados. Fallos lanzan `\RuntimeException` que **nunca rompen el envío de respuestas**.
- Config: bloque `mail` en `config/config.php` (`MAIL_HOST/PORT/ENCRYPTION/USER/PASSWORD/FROM_ADDRESS/FROM_NAME/TIMEOUT`, leídos por `Config::mail()`). Con `MAIL_HOST` vacío el envío se omite y se registra (dev seguro). Keys añadidas a `.env` y `.env.example`.
- `App\Services\Notifier` (nuevo): `afterSubmit()` enganchado en `FillController::submit` **FASE 3, tras commit y fuera de transacción**, envuelto en try/catch. `notifyOwner` (a `notify.emails[]`, asunto "Nueva respuesta: {título}", resumen HTML con filas label→valor + versión texto) y `sendConfirmation` (resuelve `notify.confirmation.to_field` por etiqueta de campo, envía al valor; asunto/mensaje personalizables o defaults). Vistas: `views/mail/notification.php` y `views/mail/confirmation.php`.
- `App\Services\Webhook` (nuevo): POST JSON fire-and-forget a `webhook.url` (solo http/https, timeout 5s, `ignore_errors`), payload con metadatos de form/submission + `fields` (id+label) + `answers` decodificadas (listas/mapas como arreglos). Construido por `FillController::webhookPayload`.
- Testeable sin red: `Mailer::setTransport/setConfigOverride` y `Webhook::setHandler` inyectan grabadores.
- Tests: `tests/unit/MailerTest.php` (8) + `tests/unit/NotificationsTest.php` (10). Suite: **140/140**.
- UI ya existía desde P1 (grupos "Notificaciones" y "Integraciones y final" en `views/builder/index.php`); `FormSettings::validate` valida los emails/URLs.

**P6 (implementado):** cupos, apertura/cierre y gracias personalizado + PRG.
- **Calendario** (`settings.schedule.open_at/close_at`, formato `'YYYY-MM-DD HH:MM'` hora local): `FillController::scheduleState` devuelve `open|not_open|closed` (fechas inválidas se ignoran → open). `show` renderiza `fill/closed` con heading/nota ("Formulario cerrado" / "Aún no está abierto"); `submit` re-chequea y aborta 403.
- **Cupo** (`settings.quota`): `FillController::quotaExceeded` compara `Form::submissionCount` vs `max` (deshabilitado o max<=0 = ilimitado). `show` renderiza la vista nueva `fill/full.php` ("Cupo agotado"); `submit` aborta 403. La cuenta es por submissions; carreras concurrentes pueden pasar el límite en ±1 (documentado).
- **Gracias + PRG** (Post/Redirect/Get): el POST ya NO responde el thanks. Ruta nueva GET `/f/{slug}/thanks` → `FillController::thanks` (antes de `/f/{slug}`; el patrón `[^/]+` no cruza `/`, sin colisión). En `submit` (FASE 4): si `thanks.redirect_url` es una URL http/https válida → `Response::redirectExternal` (nueva, whitelist http/https; `Response::redirect` sigue limitada a relativas por M4) y si no → 303 a `/f/{slug}/thanks`. `views/fill/thanks.php` muestra `thanks.message` personalizado si existe.
- **Runner compatible con PRG:** `Response` ahora solo emite headers/`exit` fuera de PHP CLI puro (`isCli()`). Bajo el runner los controllers se invocan sin matar el proceso; bajo `cli-server`/FPM el comportamiento es el real. `abort/json/notFound/methodNotAllowed/redirect*` usan ese guard.
- Tests: `tests/unit/ScheduleQuotaTest.php` (16 tests: scheduleState/parse/isHttpUrl por reflexión + cupo y barreras de `show` con BD real + thanks por GET). Suite: **156/156**.
- El panel Configuración ya tenía los inputs desde P1 (`stOpenAt/stCloseAt/stQuotaEnabled/stQuotaMax/stThanksMessage/stThanksRedirect`).

**P7 (implementado):** honeypot, prefill por URL, theming, embed/QR/share y clonar.
- **Honeypot** (`settings.honeypot.enabled`, default true): campo trampa `_vf_hp` en `views/fill/show.php` fuera de la vista/teclado (`.vf-honeypot`); en `submit` se chequea tras las barreras P6 y antes del rate limit: si viene rellenado se PRG-finge a gracias (`redirectToThanks`) **sin persistir, validar ni notificar**, y en CLI se retorna explícitamente para que el flujo no continúe.
- **Prefill por URL** (`?field_<id>=valor`, checkbox con `valor1,valor2`): `fill.js::applyPrefill` se aplica ANTES de restaurar el borrador de P4 (el borrador y los errores del servidor ganan sobre el prefill; la validación server-side sigue siendo autoritativa).
- **Theming**: `FormSettings::themeCss` se inyecta inline en `<head>` de `fill/show` y `fill/thanks`; las vars `--vf-*` ganan a `app.css` por orden de carga (aplicación real de los settings de P1).
- **Embed** (`settings.embed.enabled`): ruta pública `/f/{slug}/embed` (`FillController::embed`) que re-usa el mismo render y las barreras de disponibilidad; relaja `X-Frame-Options: SAMEORIGIN` y `frame-ancestors 'self'` SOLO en ese action (+ en `thanks` si el form es embebible, para el PRG dentro del iframe); el fill normal sigue con `DENY`. headers emiten solo fuera de CLI (`PHP_SAPI`).
- **Compartir/QR**: botón **Duplicar** (`POST /forms/{id}/duplicate`, CSRF) → `Form::duplicate` que copia título "(copia)", slug único, status `draft`, settings y campos (options/validations/logic, remapeando `parent_id`); en `views/dashboard/forms/show.php` la tarjeta de compartir ahora usa la URL absoluta (`shareUrl`), muestra el **QR en SVG** (`GET /forms/{id}/qr.svg` → `QrCode::svg`, la URL se calcula del lado servidor desde el slug) y el **código iframe** cuando el embed está habilitado. `app/Controllers/FormController::qrSvg/duplicate`.
- QR vendored: `libs/qrcode/qrcode.php` (qrcode-generator, MIT, single-file) + `App\Services\QrCode::svg($data)` que renderiza SVG desde la matriz (`isDark`/`getModuleCount`) — sin GD.
- Tests: `tests/unit/P7ShareTest.php` (7: honeypot atrapa sin persistir / no atrapa con trampa vacía / off ignora la trampa, render con tema+honeypot, duplicado con campos/padres/settings/opciones/lógica e integridad del original, QR determinista + dato vacío). Suite: **163/163**.

**P8 (implementado):** quiz, lógica avanzada (regex + salta-página) y randomización.
- **Op `matches` (regex)** en `LogicEvaluator::OPERATORS` + `matchesRegex` (delimitador `@`, flags `iu`, en checkbox basta UNA opción que coincida, patrón inválido o fuente vacía → `false` fail-safe); réplica en `fill.js` y en el builder (`opsFor`/`OP_TEXTS`). Tests en `LogicEvaluatorTest`.
- **Salta-página**: la condición del campo `page` decide si su BLOQUE se omite. Server: `BuilderController::saveStructure` ya acepta lógica en páginas; `FillController::submit` calcula `pagePlan` (bloques/`pageOf`) y pasa `$extraDeps` (cada pregunta depende del `page` de su bloque) a `LogicEvaluator::dependencyOrder($fields, $extraDeps)` — el orden topológico garantiza que la condición de la página se resuelva antes que sus preguntas. Si la condición se cumple, todo el bloque cuenta como oculto: **no se valida, no se persiste ni es fuente** (aunque el cliente envíe valores). Client: `views/fill/show.php` emite `data-vf-page-logic` por bloque; `fill.js` (`pageSkipped`/`visibleSteps`/`paintSteps`/`buildFooterControls`) repinta la navegación reenumerando pasos, reconstruyendo prev/siguiente/enviar y limpiando los campos de pasos omitidos; `step`/badge se recalculan. Caveat documentado: una regla de página que referencie a una pregunta de SU propio bloque crea un ciclo → se rechaza al guardar (`validateStructure`).
- **Quiz** (`settings.quiz.enabled` + `validations.correct`): `App\Services\QuizScore::score($fields, $answersById)` → `points/max/per`, stateless (sin columna). Comparación por tipo: select/radio exacta, checkbox como CONJUNTO (coma o JSON, orden irrelevante), text/email/url/tel case-insensitive, number/nps/star/scale/range numérica; matrix/ranking/section/page se excluyen del recuento. En `submit` (FASE 4) el thanks incluye un token de capacidad `?t=` (`submissions.public_token`, 128 bits; nunca el `AUTO_INCREMENT`); `thanks` valida el token y que la submission pertenezca al form, y muestra puntuación + por-pregunta (✓/✗ con la correcta). Builder: input "Respuesta correcta" por pregunta (visible con modo quiz activo) y checkbox "Barajar opciones" (→ `validations.randomize`).
- **Randomización** (`settings.randomize.questions` + `validations.randomize`): `FillController::randomizeFields` baraja UNIDADES de preguntas (secciones sin hijos movidas al bloque se mueven con ellos) dentro de cada segmento de página (los `page` anclan y quedan fijos), y las opciones de select/radio/checkbox por campo. SOLO en GET (`show`/`embed`); el re-render del POST con errores no baraja (los campos no se reordenan bajo los dedos). Las respuestas se guardan por `field_id`, así el orden de render no afecta.
- Tests: `P8SmartTest` (4 con BD real: salta-página omite bloque / visible valida obligatorias / valores de bloque saltado ignorados / condición con regex fuera del bloque), `QuizScoreTest` (10, incluyendo thanks con `?sid` propio vs ajeno y sin quiz), `RandomizeTest` (5, invariantes del barajado), + 5 en `LogicEvaluatorTest` (matches, extraDeps, ciclo por página). Suite: **189/189**.

**P9 (implementado):** plantillas JSON, export imprimible/JSON e i18n reducido.
- **Formato declarativo de plantilla** (`database/templates/*.json`): `{key, name, description, settings, fields[]}` donde cada campo usa `ref` (entero único y estable, orden de lectura) en vez de ids de BD. Las referencias de anidamiento y de condición apuntan a `ref` (`parent`, `logic.rules[].field_id`); las opciones aceptan strings sueltos o `{value, group}` (filas/columnos de matriz). Se puede abrir, leer y editar a mano.
- **`App\Services\TemplateService`** (nuevo): `list()` (metadatos para el selector del dashboard), `load($key)` (key filtrada por patrón `^[a-z0-9][a-z0-9-]{0,60}$` → sin path traversal), `normalizeStructure()` (declarativo → contrato "wire" del builder: `_uid = tpl-{ref}`, `parent_id` y `logic.field_id` como `_uid`; valida ref único/entero, tipo en whitelist, padre = `section` declarada ANTES, fuente de condición contestable y ≠ el propio campo), `apply($formId, $items)` (transacción: contadores de posición por padre, `Field::create` encadenado, segundo pase `_uid → id`, `LogicEvaluator::validateStructure` antes de persistir la lógica, `rollBack` + mensaje de error) y `exportPayload($form, $settings)` (inversa: ids → refs 1..N).
- **Alta desde plantilla**: `POST /forms` acepta `template`; si viene, título/descripción caen a los de la plantilla, se guardan sus `settings` (normalizados por `FormSettings`) y se aplica la estructura. Si algo falla se **borra el formulario** (no quedan restos a medias). El modal del dashboard (`views/dashboard/index.php`) suma radio *En blanco* / *Desde una plantilla* + `<select name="template">`; el toggle vive en `dashboard.js` (fuera del bloque de `dashGrid` para que también funcione en el estado vacío) y deshabilita el select + el `required` del título en modo plantilla.
- **Export / impresión**: `GET /forms/{id}/print` → `views/dashboard/forms/print.php` standalone (layout `null`, `@media print`, `window.print()`, tabla de preguntas con tipo/opciones/condición; secciones y `page` son estructurales y no cuentan como pregunta). `GET /forms/{id}/export-structure.json` → el mismo formato declarativo, attachment `{slug}.template.json` (round-trip: lo que descargas se puede volver a subir como plantilla). Headers y `exit` solo fuera de CLI (igual que `Response::*`), para que el runner no muera. Botones **Imprimir**/**Exportar** en `views/dashboard/forms/show.php`.
- **i18n reducido** (`App\Core\I18n` + `app/lang/{es,en}.php`): catálogos planos `key => mensaje` con placeholders `{name}`; precedencia `settings.locale.code` (whitelist `es|en`) → `APP_LOCALE` (`config app.locale`) → `es`; clave faltante devuelve la propia clave. Alcance: fill público (nav, progreso, condicionales, labels de tipo, ranking/NPS, errores), gracias + quiz, estados cerrado/lleno/borrador y **correos** (`Notifier` traduce asunto y texto plano alterno, `views/mail/*` el HTML). El dashboard/builder/admin quedan en español (herramienta interna). Cliente sin `<script>` inline (CSP): `views/fill/show.php` emite `data-vf-i18n` (subcatálogo `ui./val./js.`) y `fill.js` lo lee en `t(key, params)` con **fallback en español** si falta algo.
- Decisiones: (a) las referencias siguen siendo **refs enteros**, no ids, para que una plantilla sobreviva a exportaciones/importaciones; (b) el subcatálogo del cliente se filtra por prefijo para no filtrar correos/errores internos al DOM; (c) `FormController::create` ahora hace `return` explícito tras cada `redirect` (en CLI `Response::redirect` no corta y el flujo seguía criando formularios fantasma).
- Bug menor corregido de paso: `++$positionCounters[$parent]` en `BuilderController::saveStructure` y `TemplateService::apply` emitía *Undefined array key* en el primer hijo de cada sección (ahora se inicializa el contador).
- Tests: `P9TemplatesTest` (15 con BD real: 6 plantillas instaladas y todas normalizan, keys inválidas/traversal, refs→uids, opciones string/objeto, 6 casos de estructura inválida, apply con secciones+lógica+settings, ciclo → rollback sin campos, round-trip del export, export por controller, render de la hoja imprimible, alta con/sin plantilla y plantilla inexistente) e `I18nTest` (10: precedencia, idioma no soportado, interpolación, fail-safe, **paridad de claves es/en**, render del fill en es y en). Suite: **214/214**.

**P10 (implementado):** campo `file` con storage fuera de `public/`, validación por MIME real y descarga autenticada.
- **`App\Services\Uploader`** (nuevo, sin dependencias): `paths.uploads` (por defecto `storage/uploads/`, **nunca** dentro de `public/`) + `YYYY/MM/` + nombre aleatorio (`bin2hex(random_bytes(16))`); whitelist `MIME_EXT` (pdf, txt/md/log/csv, json, jpg/jpeg, png, gif, webp, doc/docx, xls/xlsx, ppt/pptx, odt/ods/odp, zip) y `DENY_EXT` (php, phtml, phar, exe, sh, bat, cmd, com, cgi, pl, js, html, htm, xhtml, svg, jar, …) que se rechazan **aunque** el MIME cuadre.
- **Validación en dos capas**: (a) extensión del cliente contra `DENY_EXT` antes de nada; (b) MIME **detectado con `finfo`** (nunca `$_FILES['type']`) → la extensión efectiva sale del MIME detectado (si la familia admite varias —p. ej. `text/plain`: txt/md/log/csv— gana la del cliente, pero solo si pertenece a esa familia). Un PDF renombrado a `.csv` se guarda como `.pdf`; un texto renombrado a `.pdf` se rechaza. `validations.accept` (CSV del builder) solo **restringe**: se interseca con la whitelist global.
- **Límites**: `app.uploads.max_size_mb` / `max_files` (env `UPLOAD_MAX_SIZE_MB`/`UPLOAD_MAX_FILES`) acotados por `upload_max_filesize` de php.ini (`maxSizeMb()` usa el menor) y `validations.max_size_mb` / `max_files` por campo. El input lleva `accept`, `multiple`, `data-vf-file-max-size`/`-max-files` y el texto de límite se calcula **en el servidor** (nunca un valor hardcodeado en la vista).
- **Metadata en `answers.value`** (JSON): `{name, stored, size, mime, thumb, sha}` (o lista si `multiple`). `stored` es relativo a la raíz de uploads; `absolutePath()` valida el patrón (`^\d{4}/\d{2}/<hex>(\.<ext>)?(-thumb\.webp)?$`) y que el `realpath` siga dentro de la base → anti path-traversal. Thumbnail WebP de imágenes **solo si hay GD** (si no, `thumb: null`); nunca se sirve directo.
- **Descarga autenticada**: `GET /attachments/{id}[?i=N]` (`AttachmentController::download`), donde `{id}` es el `answers.id` (nunca el nombre del archivo) y `i` el índice dentro de un campo multi. Resuelve el contexto (respuesta → submission → form → dueño) y permite **dueño, editor o admin** (otro usuario 403, anónimo 302 a `/login`). Headers: `Content-Type: application/octet-stream`, `Content-Length`, `Content-Disposition: attachment` (con `filename` ASCII + `filename*=UTF-8''`), `X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store`, `Content-Security-Policy: default-src 'none'; sandbox`. En cada `YYYY/MM` se escribe un `.htaccess` (`Require all denied`/`Deny from all`/`Options -Indexes`) por si el web server acabara sirviendo `storage/`.
- **413 y errores legibles**: el middleware **`post-max`** (nuevo, corre **antes** de `csrf`) detecta `CONTENT_LENGTH > post_max_size` y responde **413** con `views/errors/413.php` (nuevo) — si no, PHP vacía `$_POST`/`$_FILES` y el usuario veía un "token CSRF inválido" engañoso. Los errores de campo (`val.file_too_big`, `val.file_count`, `val.file_type`, `val.blocked_extension`, `val.upload_too_big`, `val.server_error`) re-renderizan el fill con los valores previos y traducibles.
- **Rollback**: si el resto del envío es inválido o la transacción falla, `Uploader::rollback()` borra lo ya escrito (no quedan huérfanos). Purga en cascada al **borrar una respuesta**, **borrar un formulario** y **quitar el campo `file` en el builder** (`Uploader::purgeSubmission/purgeForm/purgeField`).
- **Nada de rutas internas fuera del dashboard**: respuestas, analytics, CSV, `export.json`, correos (`Notifier`) y el payload de webhooks muestran `nombre.pdf (1,2 MB)` vía `Uploader::displayValue`/`Answer::displayValue`, nunca el JSON crudo.
- Builder: tipo `file` en la paleta + ajustes (`accept`, `max_size_mb`, `multiple`) y los límites globales como techo (`data-upload-limits`); el campo **no** es puntuable en quiz (`QuizScore` lo excluye). `fill.js` valida cantidad/tamaño en cliente, lista los archivos elegidos y **no** los mete en el borrador ni en el prefill (un archivo no se puede rehidratar por seguridad).
- **Bug de fondo encontrado en el smoke HTTP**: `Config::get()` **no soportaba notación de punto**, así que `Config::get('paths.uploads')` devolvía `''` y el `fallback` de `Uploader::baseDir()` armaba `/uploads` → los adjuntos se escribían **en la raíz del filesystem** (y `APP_LOCALE` / `UPLOAD_MAX_SIZE_MB` se ignoraban en silencio). Arreglado: `Config::get()` recorre el array anidado y `baseDir()` nunca puede producir una ruta absoluta en la raíz.
- Despliegue: `upload_max_filesize` y `post_max_size` de php.ini deben admitir el tamaño máximo anunciado, `client_max_body_size` de nginx mayor que `post_max_size`, y el usuario del web server necesita **escritura** en `storage/uploads` (nada de serving directo). Los binarios están en `.gitignore` (solo se versiona `.gitkeep`).
- Tests: `tests/unit/P10UploadsTest.php` (23: metadata y aislamiento por extensión/MIME, `multiple`, límites por campo y globales, errores de `$_FILES` (INI/form size), extensiones ejecutables, `.php` y texto renombrado, rechazo de MIME no permitido sin escribir nada, anti-traversal de `absolutePath`, attachments aplanados por campo, render con `enctype`/`accept`/límites, dos envíos con CSRF y la ruta interna nunca expuesta, rollback sin huérfanos, purge por submission/form) + `tearDown()` nuevo en el runner (limpia lo que el test escribió sin tocar adjuntos reales). Suite: **237/237**.

## Archivos clave por fase (referencia)

- P1: `app/Models/Form.php`, `app/Services/FormSettings.php` (nuevo), `app/Controllers/BuilderController.php`,
  `views/builder/index.php`, `public/assets/js/builder.js`, `tests/unit/*`.
- P2: `builder.js:typeInfo`, `fill.js`, `FillController`, `BuilderController:69`, `AnalyticsController`, `Answer`.
- P3: `ResponsesController` (nuevo), `views/responses/{index,show}.php`, `routes.php`, `Submission`.
- P4: `builder.js`, `fill.js`, `FillController`, `Field::byForm`.
- P5: `App/Core/Mailer.php` (nuevo), `App/Services/Notifier|Webhook` (nuevos), `FillController@submit`, `views/mail/{notification,confirmation}.php`, `config/config.php`, `.env`.
- P6: `FillController@show/@submit/@thanks`, `views/fill/{closed,full,thanks}.php`, `views/fill/thanks.php`, `routes.php`, `Response::redirectExternal`, `tests/unit/ScheduleQuotaTest.php`.
- P7: `FillController`, `views/fill/show.php`, `FormController@duplicate`, `routes.php`, `libs/phpqrcode` (vendored).
- P8: `LogicEvaluator`, `FillController` (submit página/extraDeps/pagePlan, randomizeFields, thanks quiz), `App/Services/QuizScore.php` (nuevo), `fill.js` (pageSkipped/paintSteps, matches), `views/fill/{show,thanks}.php`, `views/builder/index.php`, `builder.js` (matches, lógica de página, quiz, barajar), `tests/unit/{P8SmartTest,QuizScoreTest,RandomizeTest}.php`.
- P9: `database/templates/*.json` (nuevo), `App/Services/TemplateService.php` (nuevo), `App/Core/I18n.php` (nuevo), `app/lang/{es,en}.php` (nuevos), `FormController@create/@printForm/@exportStructure`, `DashboardController`, `views/dashboard/forms/print.php` (nuevo), `views/dashboard/{index,forms/show}.php`, `fill.js`, `Notifier`, `views/mail/*`, `tests/unit/{P9TemplatesTest,I18nTest}.php`.
- P10: `App/Services/Uploader.php` (nuevo), `app/Controllers/AttachmentController.php` (nuevo), `views/errors/413.php` (nuevo), `FillController@submit`, `BuilderController` (tipo `file` + purge), `ResponsesController@delete`, `FormController@delete`, `Answer`/`Submission` (adjuntos + display), `AnalyticsController@exportCsv`, `Notifier`, `routes.php` (`/attachments/{id}`, middleware `post-max`), `Core/Config` (notación de punto), `views/fill/show.php`, `views/responses/show.php`, `views/analytics/show.php`, `views/builder/index.php`, `fill.js`, `builder.js`, `app.css`, `app/lang/{es,en}.php`, `tests/unit/P10UploadsTest.php` (nuevo), docs (php.ini / nginx `client_max_body_size`).

## Estrategia transversal

- Cada fase suma casos al runner (`php scripts/test.php`); suite siempre en verde (≥79+n).
- POST nuevos con middleware `csrf` / `csrf-header` (convención C1).
- Uploads: nombre aleatorio, headers `nosniff`, sin ejecución, limpieza en cascade.
- `settings` y `validations` con whitelists estrictas (nunca confiar en input).

## Tier 3 (backlog futuro)

Pagos (Stripe), integraciones nativas (Sheets/Zapier/Slack OAuth), multi-tenant/SaaS, IA
generativa, reCAPTCHA/Turnstile, rework UX tipo Typeform, PWA/offline.