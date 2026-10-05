# Plan de remediación — VanillaForms (2026-09-27)

**Estado de partida:** 257 tests en verde · 14 658 LOC de app/vistas/JS · 5 393 LOC
de tests · sin repo git · MariaDB 11.8 · PHP 8.2+ sin Composer.

Este documento es la bitácora de la auditoría del 2026-09-27 y el plan que de ella
sale. Las fases 0 y 1 se ejecutaron en esa misma sesión; las fases 2 a 5 quedan
pendientes para otra. **Cada fase empieza por su número; no las reordenes sin
actualizar la tabla de estado al final.**

## Diagnóstico

El proyecto es sólido en lo que está escrito en PHP (PDO sin emular prepares,
CSRF en todas las rutas mutantes, escapado sistemático, adjuntos fuera de
`public/`, autorización por propiedad en 6 controladores) y frágil en las
**fronteras**: JS↔PHP, filesystem↔BD y docs↔código. Los cinco bugs más graves
encontrados —los dos de esta tarde incluidos— son el mismo síntoma: contratos
implícitos que nadie verifica y que fallan en silencio.

Clases de bug repetidas, para no volver a tropezar:

1. **Pérdida silenciosa en frontera de contrato.** El nombre de la clave era el
   giveaway: `parentId` en memoria contra `parent_id` en el servidor. Toda la
   jerarquía de secciones se perdía al guardar. Lo fijó un test de contrato.
2. **Pérdida silenciosa en frontera de estado.** `applySettings()` reconstruía
   `validations` desde cero y borraba las claves sin control en la UI. El
   contrato de *nombres* estaba bien; el de *contenido*, no. Ningún test lo
   cubría.
3. **El filesystem no participa en las transacciones.** El código purga
   adjuntos *antes* del `commit()`, así que un rollback posterior deja filas que
   apuntan a binarios inexistentes. Irrecuperable.
4. **El contrato de BD y el de la aplicación divergen en silencio.**
   `schema.sql` declara 7 FKs; una BD migrada tiene 6. Nada avisa del drift.

## Decisiones tomadas por el autor

- **Alcance de esta ronda:** fases 0 y 1. El resto queda aquí para otra sesión.
- **Zona horaria canónica: `America/Caracas`** (UTC−4, sin DST desde 2016).
  Guardar en UTC, convertir solo en la capa de presentación. La app hoy usa
  `America/Mexico_City` (UTC−6), así que el error acumulado es de 8 horas, no
  de 6: las 6 del desfase BD/PHP más las 2 de zona equivocada.
- **El registro público se queda abierto.** Esto *eleva* la SSRF y la inyección
  de atributos del builder a mitigaciones de primera línea: cualquiera puede
  convertirse en dueño de un formulario y disparar la URL que él mismo definió.
- **Los datos existentes se reparan caso por caso**, con visto bueno. Solo D1
  requiere decisión; sigue abierto.

## Inventario de datos afectados

Ninguno de los cinco hallazgos perdió datos de respuestas. Se verificó
submission por submission y archivo por archivo.

| # | Qué | Dónde | Impacto | Reparación |
|---|---|---|---|---|
| **D1** | Sección `12705` sin hijas; sus 4 preguntas (`12706`–`12709`) quedaron a nivel raíz | form `5372` `quis-example` | **Solo estructura.** Las 3 submissions reales conservan sus 4 respuestas cada una. Ningún campo tiene lógica, así que no hay regla que preservar | **HECHO (2026-09-27).** Ver "Fase 3 · D1" abajo. `answers` intactas: 12 |
| D2 | 11 campos con posiciones colisionadas (dos en posición 1), una hija antes que su padre, 4 submissions con 0 respuestas | form `4623` `debug-p10` | Ninguno: es el formulario que se creó para depurar P10 | Borrar o dejar. No es del autor |
| D3 | 493 filas basura en `rate_limits` con IPs de respondientes en claro, +9 por ejecución | BD | Privacidad (PII retenida) y crecimiento sin techo | `DELETE` + llamar `cleanExpired()` (Fase 3) |
| D4 | **Cero adjuntos registrados en toda la BD** | `storage/uploads` | El bug de purga-antes-de-commit *nunca* llegó a destruir un archivo | Nada que reparar; F1.1 lo previene |
| D5 | `fk_fields_parent` sin crear y `parent_id` sin índice | esquema | Hoy 0 filas colgando, nada corrupto. Al borrar una sección dejaría huérfanos y desordenaría el formulario | Migración 0003 (Fase 3) |

## Fase 0 — Regresiones introducidas el 2026-09-27

**0.1 · `data-vf-structural-hidden` no ocultaba nada.** `builder.js:1225` usaba
`toggleAttribute('data-vf-structural-hidden', insideSection)`, que deja el
atributo con valor `""`, pero `app.css:1226` exigía `[data-vf-structural-hidden="1"]`.
Dentro de una sección seguían visibles los tiles "Sección de preguntas" y
"Página", y `addFieldByType` forzaba `parentUid = null` sin avisar: el autor
creía anidar y obtenía un bloque suelto.
Arreglo: selector CSS por presencia del atributo y `setAttribute`/`removeAttribute`
explícito en el JS.

**0.2 · `applySettings()` destruía validaciones sin control en la UI.**
`builder.js:1133` asigna `f.validations = isStructural ? {} : v` de forma
incondicional, y `v` solo contiene claves con control: `text`/`textarea` no
declaran `min_length`/`max_length` (`builder.js:90-91`) y `max_files` no tiene
control aunque `Uploader.php:100` y `database/templates/evento-inscripcion.json:156`
lo usen. Editar el engranaje de una pregunta de texto borraba el cap y el
autosave lo persistía. 5 de 6 plantillas traen esas validaciones.
Arreglo: leer-mergear — conservar las claves existentes sin control, sobrescribir
solo las que sí.

**0.3 · Test de contenido, no de nombres.** `BuilderPayloadContractTest` compara
*nombres de clave*, por eso no detectó 0.2. Se añadió un test que carga cada
plantilla de `database/templates/*.json` y comprueba que toda clave de
`validations` que la plantilla declara tiene control de lectura y de
re-serialización en `builder.js`. Falla si se reintroduce el borrado.

**0.4** Log de `builder.js` a `v2026-09-27e`; `AGENTS.md` sincronizado.

## Fase 1 — Pérdida de datos

**1.1 · Purgar adjuntos después del commit, nunca antes.**
`BuilderController.php:259`, `FormController.php:189` y `ResponsesController.php:93`
purgaban dentro de la transacción, con rollbacks posteriores en 272/288/293: la
fila `answers` volvía con su ruta `stored` intacta pero el binario ya no existía,
así que `GET /attachments/...` daba 404 para siempre. Ahora se acumulan los ids y
se purgan tras el `commit()`.

**1.2 · `Form::duplicate` transaccional.** `Form.php:161-192` hacía 1+N escrituras
sin transacción ni `try/catch`: un fallo en el campo N dejaba un `forms` en
`draft` con N−1 campos, el slug consumido y un 500.

**1.3 · Cota de `answers.value` en el servidor.** `schema.sql:89` es `TEXT`
(65 535 B) y `max_length` solo se validaba si el campo lo traía. Un textarea de
70 KB daba `ERROR 1406` → `FillController.php:512` hacía `rollBack()` **y
`Uploader::rollback()`, borrando los adjuntos que el participante ya había
subido**, y devolvía un 500 indistinguible de un error suyo. Con 0.2 sin arreglar
el cap de plantilla desaparecía al abrir el engranaje, así que el 500 era fácil de
alcanzar por el camino normal de uso.

**1.4 · `mb_substr` en el user agent.** `Request.php:204` cortaba por bytes contra
un `VARCHAR(255)` utf8mb4 en strict mode: `ERROR 1366` y 500 en cada envío de
aquel cliente. Es una cabecera, así que `post-max` no lo detiene.

**1.5 · Smoke de verificación:** crear con adjunto, borrar el campo con `settings`
inválidas en el mismo POST, y comprobar que tras el rollback la fila `answers` *y*
el archivo siguen ahí.

## Fase 2 — Seguridad (otra sesión; prioridad elevada por el registro abierto)

| # | Hallazgo | Arreglo mínimo | Estado |
|---|---|---|---|
| 2.1 | `?sid=` sin token: `FillController.php:611-616` solo comprueba que la submission pertenezca al formulario. IDs correlativos y PII libre impresa en `thanks.php:86` | Firmar el `sid` (HMAC + expiry) o token opaco de un uso | **HECHO (2026-09-28)** — token de capacidad `?t=` (`submissions.public_token`, 128 bits, único+indexado, migración 0003 con backfill). `sid` eliminado del thanks |
| 2.2 | SSRF: `Webhook.php:41-47` solo valida el esquema y luego hace `file_get_contents` (línea 75). Loopback, `169.254.169.254` y `10/8` son válidos | Resolver el host, rechazar rangos privados, fijar la IP al conectar, exigir https | **HECHO (2026-09-28)** — `resolveTarget()` resuelve y filtra IP; conexión con la IP fijada y SNI del nombre; sin redirecciones |
| 2.3 | Seed con contraseña de admin publicada (`seed.sql:12`) | Detrás de `SEED_DEMO=1` | **HECHO (2026-09-28)** — `seed.sql` solo crea roles (cero credenciales); los usuarios demo se siembran con `database/seed.php`, que sin `SEED_DEMO=1` no hace nada y sale con código 1; hash generado en el momento, nunca escrito en el repo; `scripts/create-admin.php` da la vía del primer admin real |
| 2.4 | `CSP_POLICY` vacío por defecto (`config/config.php:24`) → instalación limpia sin CSP | Default seguro en código | **HECHO (2026-09-28)** — default seguro en código (`script-src 'self'`, sin `unsafe-inline` ni `unsafe-eval`); único `<script>`/handler inline restante externalizado a `public/assets/js/print.js`; `BuilderEscapingTest` impide reintroducirlos |
| 2.5 | `esc()` del builder no escapa `"` ni `'` y el resultado va en atributos con comillas dobles (`builder.js:358,415,872`) → inyección de atributos; la CSP es hoy la única barrera | Escapar comillas, o construir con `setAttribute` | **HECHO (2026-09-28)** — `esc()` escapa `& < > " '` (builder.js y dashboard.js); invariante probado: la salida no tiene comillas crudas, así que vale para texto y atributo |
| 2.6 | `Router.php:45` compila `{id}` como `[^/]+` y lo pasa por reflexión a firmas `int`: `TypeError` sin capturar, traza con rutas absolutas | Validar numérico → 404; `set_exception_handler` genérico | **HECHO (2026-09-27)** — `Router::coerceParam()`/`toInt()`, 404 sin ejecutar el controlador; `RouterParamsTest` sobre todas las rutas |
| 2.7 | Login: oráculo de temporización (`AuthController.php:56`), contraseña mínima de 6, sin rehash, `use_only_cookies` sin fijar | `password_verify` señuelo, mínimo 10-12, rehash oportunista, `ini_set` | **HECHO (2026-09-28)** — hash señuelo bcrypt del mismo coste en el camino "no existe" (antes el `||` cortocircuitaba el bcrypt y enumeraba emails por tiempo: 0.016 s vs 0.33 s, medido); `PASSWORD_MIN = 10` compartido con la vista; rehash oportunista en login correcto; `session.use_only_cookies=1` |
| 2.8 | `settings.theme.font` no se valida → inyección CSS | Lista blanca o patrón estricto | **HECHO (2026-09-28)** — `isValidFontFamily()` con lista blanca, aplicada en `normalize()` (entrada) **y** en `themeCss()` (salida, para las filas ya guardadas); `validate()` avisa del valor crudo en vez de descartarlo en silencio |

## Fase 3 — Capa de datos (otra sesión)

- **Migración 0003 idempotente:** `idx_fields_parent` + `fk_fields_parent … ON
  DELETE CASCADE`. Cierra el drift con `schema.sql:63` (la BD real tiene 6 de 7
  FKs y `parent_id` sin índice, así que borrar una sección no localiza a sus hijas).
- **Índices:** `(user_id, updated_at)` en `forms` (quita el filesort del
  dashboard), `(submission_id, field_id)` en `answers`, `idx_rate_window(window_start)`.
- **`cleanExpired()`:** hoy tiene cero call sites. Llamar desde cron o en el 1 % de
  los logins, y ejecutar el `DELETE` de las 493 filas de D3.
- **Zona horaria (decidida, `America/Caracas`):** `SET time_zone` en el DSN para que
  la BD escriba UTC, `date_default_timezone_set('UTC')` y convertir en la capa de
  presentación. Arregla las 6 h de desfase más las 2 h de zona equivocada, y los
  filtros `from`/`to` que hoy caen en el día incorrecto entre 18:00 y 24:00.
  **Requiere migrar las filas ya escritas**, interpretándolas en UTC.
- **Analítica y exports:** `AnalyticsController::show()` hacía 2 consultas por
  pregunta (recuento + distribución) más una de NPS, o sea 3·N consultas de
  agregación por visita; `Submission::withAnswersByIds` generaba un
  `IN (?,?,…)` **sin lotear** contra el techo de 65 535 placeholders de MariaDB
  (un formulario de 70 000 respuestas reventaba con un `HY093`), y
  `byFormFiltered` no pagina. Loteo de 5 000 ids y una sola pasada de
  agregación. **HECHO** — ver "Fase 3 · D8" abajo. La paginación del listado de
  formularios se cerró el 2026-10-05 (D14) y la del detalle de respuestas en la
  fase 5; ninguna sigue abierta
  porque es un cambio de interfaz, no de capa de datos.
- **Queries redundantes:** `Form::settings()` se ejecuta 2 veces por render con
  datos ya en memoria; `Form::find()` carga `fields` que el listado de respuestas
  no usa.

## Fase 4 — Calidad y verdad documental (CERRADA 2026-09-28)

> Estado al cerrar: `git` con historial por fase; `ControllerActionsTest` recorre
> TODAS las rutas con el Router real (destapó y arregló `header()/exit`
> incondicionales en `exportJson`/`exportCsv`/`qrSvg` y el `Form::duplicate` con
> `settings` crudo → 4025 JSON_VALID); tests que no probaban reescritos con asserts
> reales (Response/Request vía seams `setRecordHalt`/`setRequestBodyForTests`,
> `@after`→`tearDown`); `Request::ip()` con contrato fijado por `RequestIpTest`;
> eliminados los 6 `data-vf-*` sin consumidor, el FontAwesome muerto (~510 KB) y 7
> métodos sin call site (`Clock::addDays`, `Env::isLoaded`+`$loaded`,
> `Request::uri`, `Answer::valuesByField`, `Submission::countByForm`,
> `Field::deleteByForm`); `scripts/clean-uploads.php` +
> `Uploader::pruneEmptyMonths()`; `Form::find(id, withFields)`; thumbs webp ya
> mostrados; `scale` validado server-side; fallback i18n de fill.js sin
> auto-referencias; docs reconciliados (AGENTS.md, technical-document.md,
> docblock de `scripts/test.php`). Suite **442/0/0**.

- **`git init` + commit inicial.** Hoy no hay control de versiones: sin historial,
  sin diff, sin reversión. Es el riesgo operativo mayor y el más barato de cerrar.
  Decide si entran `freddy/`, `freddy.zip` y `pub.jar` (toolkit externo, ya fuera
  de uso).
- **Tests que no prueban:** `ResponseTest.php:17,23,29` son tres `assertTrue(true)`
  que nunca llaman a `Response` (el open redirect queda sin test); dos tests de
  `RequestTest` inyectan el body donde el código lee `php://input`; tres archivos
  usan `@after`, que el runner no soporta; `MailerTest` deja el transporte estático
  inyectado, así que las notificaciones del submit nunca llegan a una aserción.
- **18 acciones de controller sin una sola invocación:** `AuthController` (5/5),
  `AdminController` (4/4), `DashboardController::index`, `AttachmentController::download`,
  `ResponsesController::index/exportJson`, `AnalyticsController::exportCsv`,
  `FillController::embed` y **todas las rutas de error del submit** (404, 403, 413,
  419, 429, ciclo, 500 de transacción). `Request::ip()` tiene 0 tests y es la base
  del rate limiting.
- **Docs:** conteos obsoletos en `technical-document.md`, `builder-ui-plan.md`,
  `fix-plan.md` y ambos `audit-report*.md`; dos auditorías que se contradicen sin
  registrar las remediaciones posteriores; el filtro real del runner es
  `--filter Auth` (con espacio) y busca en **nombres de método**;
  `AGENTS.md` desalineado en versión, en el número de clases que tocan BD (16, no 6)
  y lista un middleware `editor` que ninguna ruta usa.
- **Deuda:** 11 métodos públicos sin call site, ~510 KB de FontAwesome nunca
  servido, thumbnails `.webp` generados y nunca mostrados, directorios `YYYY/MM` que
  no se purgan.
- **Menores:** columnas vacías en analítica para `page` y tipos sin agregación;
  `mode` del alta sin efecto sin JS; `scale`/`star` con límites solo en cliente;
  5 `data-vf-*` emitidos sin consumidor; fallback i18n divergente en `fill.js:40`.

## Fase 5 — Decisiones de producto (CERRADA 2026-09-28)

- `thanks.redirect_url` → **allowlist de servidor** (F5.1, `APP_REDIRECT_ALLOWLIST`,
  vacía = bloqueada): `App\Services\RedirectGuard` + seam de tests; gracias con aviso
  si el host no está listado.
- El rol `editor` → **decisión: se deja como está** (F5.2), pendiente de un sistema
  de roles/grupos (RBAC). No acotarlo ahora; cuando exista RBAC hay que revisar cada
  `canAccess()`/`canEdit()` a la vez que se decide qué puede ver `editor`. El
  middleware `editor` sigue declarado en el Router pero **no** se aplica a ninguna
  ruta de `config/routes.php`.
- Límite de ritmo en la API autenticada → **F5.3 implementado**: `DashboardLimit`
  por usuario, scopes `dash:create`/`dash:export` con `RateLimiter` (ventana
  `MAX_WINDOW`=3600 s), config `app.limits`, 429 con `views/errors/429.php`.
- **~~D1~~** reanidado de `12706`–`12709`: aprobado (ver abajo).

## Fase 3 · D1 (hecho) — reanudar `12706`–`12709` bajo la sección `12705`

Formulario `5372` (`quis-example`, quiz). La sección "Comprueba lo que aprendiste"
se había quedado vacía y sus 4 preguntas quedaron al mismo nivel. Reparado con
visto bueno del usuario, solo en `fields`:

```sql
UPDATE fields SET parent_id = 12705, position = 1 WHERE id = 12706;
UPDATE fields SET parent_id = 12705, position = 2 WHERE id = 12707;
UPDATE fields SET parent_id = 12705, position = 3 WHERE id = 12708;
UPDATE fields SET parent_id = 12705, position = 4 WHERE id = 12709;
-- rollback: UPDATE fields SET parent_id=NULL, position=id-12704 WHERE id BETWEEN 12706 AND 12709;
```

Copias de seguridad antes de tocar nada: `mysqldump ... fields --where="form_id=5372"`
y las `answers` de esos campos, en `/tmp/d1-backup-*.sql` (volátiles: si el
`/tmp` se limpia, el `rollback` de arriba es lo que reproduce el estado previo).

`position` **no** es global: el builder numera dentro de cada padre empezando en 1
(`$positionCounters[$counterKey]`), así que la sección se queda en 1 dentro de la
raíz y sus hijas van 1..4. El orden de lectura es `ORDER BY position, id`, y el
empate de posición 1 lo resuelve el `id` a favor de la sección (`12705 < 12706`),
que es justo lo que la vista del fill necesita: consume hijas **consecutivas**
detrás de su sección, no las busca en otro sitio.

Verificado:

- `GET /f/quis-example`: las 4 preguntas aparecen **dentro** del
  `<section class="vf-section-block">` y no queda ninguna suelta fuera.
- Opciones de los `radio`/`checkbox` y `validations.correct` (incluido
  `{"min":8,"max":8,"correct":"8"}`) intactos.
- Las 12 respuestas (3 submissions × 4) siguen enlazadas a sus `field_id`.
- **Round-trip por el builder**: reenviar la estructura tal cual la pinta el
  builder (`POST /forms/5372/builder/save` con `parent_id` = `_uid` del padre)
  devuelve `ok:true` y la jerarquía sobrevive intacta. Es la prueba de que un
  guardado posterior no deshace la reparación.
- Un `parent_id` con un id de BD en vez del `_uid` lo rechaza con 422
  "La pregunta hace referencia a una sección inválida", sin tocar la BD.

## Tabla de estado

| Fase | Alcance | Estado |
|---|---|---|
| 0 | Regresiones del 2026-09-27 | HECHO |
| 1 | Pérdida de datos | HECHO |
| 2 | Seguridad | HECHO (los 8 ítems cerrados, 362 tests) |
| 3 | Capa de datos | HECHO (índice/FK, limpieza de `rate_limits`, contrato horario, loteo y `settingsFrom()`/`Form::find($id,false)` contra las queries redundantes; la paginación del detalle de respuestas se cerró en `Submission::byFormPaginated` y la del listado de formularios en `Form::page()`, D14) |
| 4 | Calidad y docs | HECHO (historial por fase, `ControllerActionsTest` recorre todas las rutas, asserts reales, `Request::ip()` fijado) |
| 5 | Decisiones de producto | HECHO (F0–F5, ver `docs/evaluacion-propuestas.md`) |

## Fases 0 y 1 · cómo quedaron verificadas

Suite: `279 tests, 0 fallos, 0 errores` (`php scripts/test.php`).

### Fase 0

- **F0.1** `data-vf-structural-hidden` por presencia. Antes, `toggleAttribute()`
  dejaba el valor `""` y el selector `="1"` no casaba: «Sección de preguntas» y
  «Página» seguían visibles dentro de una sección.
- **F0.2** `applySettings()` no reconstruye `validations`. `MANAGED_VALIDATION_KEYS`
  + `preservedValidations()`. `text`/`textarea` declaran `min_length`/`max_length`
  porque `FillController` solo aplica longitudes a esos dos tipos; `max_files` no
  tiene control y se preserva solo.
- **F0.3** `tests/unit/BuilderValidationsContractTest.php` fija el invariante
  (cada clave de `MANAGED_VALIDATION_KEYS` tiene un control que la escribe).
  Comprobado que **falla** al revertir el arreglo.
- **F0.4** `builder.js` → `v2026-09-27e`.

### Fase 1

- **F1.1** Purga de adjuntos **en dos fases**, y las dos partes se necesitan:

  | Fase | Qué | Por qué en ese orden |
  |---|---|---|
  | 1. recoger | `Uploader::pendingFor{Submission,Form,Field}()` | lee las filas `answers`; si va después del `DELETE`, la cascada ya se las llevó y **no queda nada que leer** → el binario queda huérfano para siempre |
  | 2. desenlazar | `Uploader::purgePaths()`, tras el `commit()` | si va dentro de la transacción y hay `rollback`, la fila `answers` vuelve viva apuntando a un binario inexistente → `404` permanente en `GET /attachments/{id}` |

  El arreglo original (F1.1 v1) solo movía el `purgeField()` después del commit
  y **jugaba sucio**: `purgeField()` consulta `Answer::attachmentValuesByField()`,
  que ya no encontraba nada tras la cascada, así que todos los borrados con
  adjuntos empezaron a dejar huérfanos. Se detectó al borrar los formularios del
  smoke: 4 PDFs intactos en `storage/uploads/2026/09/`. Corregido con la API de
  dos fases (`Uploader::purgePaths()` / `pendingFor*()`; `purgeSubmission`,
  `purgeForm` y `purgeField` quedan como envoltorios de una fase).

  Verificación end-to-end por HTTP (`php -S` + `curl`, no hay test que lo cubra
  porque `Request::json()` lee `php://input`):
  - Se sube un PDF por el fill público → `answers.value` con `stored` + `sha`.
  - `POST /forms/{id}/builder/save` que **omite el campo file** y manda
    `settings.notify.emails = []` → `422` en la validación de settings, es decir
    *después* de haber borrado el campo.
  - Resultado con el arreglo: la fila del campo vuelve (rollback), la respuesta
    sigue, y **el archivo sigue en disco** (922 bytes).
  - Con el bug original reintroducido: la fila y la respuesta vuelven, pero el
    archivo **desaparece** → adjunto fantasma. El escenario detecta el bug.

  Tests que lo vigilan (`DataIntegrityTest`, 6): orden `pendingFor*()` antes del
  `DELETE` y `purgePaths()` después del `commit()` en los tres controladores.
  El helper `assertPurgeAfter()` **ignora los comentarios**: un comentario que
  nombra el método no debe poder hacer pasar (o fallar) el test.

- **F1.2** `Form::duplicate()` en transacción. El test inyecta el fallo con un
  `CHECK` temporal `form_id <= N` (N = el máximo actual) sobre `fields`, que las
  filas existentes cumplen y los campos de la copia no; `try/finally` lo baja
  siempre. Comprobado que **falla** sin la transacción, nombrando el formulario
  a medias. Vías descartadas y documentadas en el test: `user_id` inexistente
  (falla el primer INSERT, inútil), desbordar `label` con el modo estricto
  apagado (MariaDB trunca en silencio) y corromper un JSON (el modelo lo
  normaliza a `null`).
- **F1.3** Cota absoluta de respuesta: 16 000 caracteres y 60 000 bytes de JSON,
  con `val.too_long` en `es`/`en`. Evita `ERROR 1406` → HTTP 500.
- **F1.4** `Request::userAgent()` con `mb_substr()` y descarte de UTF-8 inválido.

## Fase 2 (primer item) · parámetros de ruta convertidos al tipo de la firma

**Síntoma**: `GET /forms/abc/builder` → HTTP 500 con el stack trace en el cuerpo,
filtrando la ruta absoluta del servidor. Alcance real: las **17** rutas con
`{id}`/`{sid}` (17 métodos declaran `int $id`).

**Causa**: un parámetro de ruta siempre llega como texto, y `Router.php` es
`strict_types=1`, así que pasar `"abc"` a un `int $id` es un `TypeError` no
capturable. El patrón de la ruta (`[^/]+`) aceptaba cualquier cosa y el
dispatcher no convertía nada.

**Arreglo** (`app/Core/Router.php`), en la única frontera donde el bug vivía:

- `coerceParam()` convierte el texto al tipo que declara la firma: `int`,
  `float`, `bool`, `string`; sin tipo declarado no se toca, y los tipos que no
  vienen de la URL (`array`, objetos) pasan tal cual.
- Un valor que no encaja **no es un 500**: no existe ese recurso, así que
  `Response::notFound()` y el controlador no se invoca. En CLI `Response` no
  corta el proceso, así que `orderArgs()` devuelve `null` y el llamador para
  (el patrón `return;` explícito que el proyecto ya documenta).
- `toInt()` es deliberadamente más estricto que `is_numeric()`, que se traga
  `' 5'`, `'5.0'`, `'1e3'`, `'+5'` y dígitos Unicode (`'٣'`) y daría un id
  equivocado; también rechaza los desbordes de rango, donde un `(int)` a pelo
  daría `PHP_INT_MAX` en silencio en vez de un id inventado.
- Se unificó la construcción de argumentos en `orderArgs()` (la tenían duplicada
  `run()` e `invokeWithNamedParams()`), dejando la coerción en un solo sitio.
- El middleware sigue ejecutándose **antes** de convertir: a un usuario sin
  sesión le sigue viendo su redirección a `/login`, no un 404 que delate la
  forma de las rutas.

**Verificado**:

- `tests/unit/RouterParamsTest.php` (13 tests): tabla de conversión, los trucos de
  parseo, desbordes, slug intacto y **el camino completo por `dispatch()`** (un
  id inválido devuelve la página 404 sin ejecutar el handler; un id válido llega
  como `int`).
- **Contrato sobre `config/routes.php`**: todo parámetro de ruta debe declarar un
  tipo convertible, y todo `{id}`/`{sid}` debe declarar `int` (15+ comprobados).
  Es lo que agarra el bug en las 17 rutas de golpe, y lo agarra en las que se
  añadan después. El test resuelve los `use` del archivo porque `routes.php` usa
  imports cortos: sin eso `class_exists()` falla y el contrato pasa sin mirar
  ninguna ruta.
- Contraprueba: revirtiendo la conversión en `orderArgs()`, el test del camino
  completo falla con el `TypeError` original
  (`Argument #1 ($id) must be of type int, string given`).
- Smoke por HTTP: 11 URLs con id inválido (`/forms/abc`, `/forms/abc/builder`,
  `/forms/abc/responses`, `/forms/5372/responses/xyz`, `/attachments/abc`,
  `/forms/abc/export.csv`, `/forms/5%20`, `/forms/1e3`,
  `/forms/99999999999999999999`…) → todas 404 con la página de error y **sin**
  stack trace. Las rutas válidas siguen igual (dashboard, builder, respuestas,
  analítica, CSV, print, QR SVG, export JSON, fill, embed, submission propia).
  `/forms/4623/responses/3651` da 404 por el chequeo de propiedad (ese submission
  es del 5372), lo que además confirma que los dos `int` se convierten y el
  chequeo se ejecuta.

**Pendiente de la fase 2** (siguientes items del inventario): CSP vacía,
`esc()` insuficiente en atributos, login, `theme.font`, y el resto.

`GET /forms/abc/builder` → **TypeError sin capturar** en
`BuilderController::show(int $id)`: HTTP 500 con stack trace que filtra la ruta
absolita del servidor. El enrutador pasa el `{id}` de la URL como `string`. Es el
mismo problema de tipado de rutas del inventario, ahora con prueba de que es
alcanzable por un enlace cualquiera. **Corregido** junto con el 2.6 (arriba).

## Fase 2 — item 2.1: IDOR de `thanks?sid=` (CERRADO 2026-09-28)

**Prueba previa (no teórica).** Con el servidor local y el formulario `5372`
(`quis-example`, 4 respuestas reales de terceros), una petición **sin sesión ni
cookie**:

```
GET /f/quis-example/thanks?sid=3651   → 200
```

mostrando la respuesta literal de otra persona y la correcta:

```
¿Capital de Bolivia?            Correcta
HTTP 404 significa:              Correcta
Métodos seguros (mult choice)    Tu respuesta: PATCH   Correcta: GET
```

Recorrer `?sid=` consecutive (`3651, 3652, 3943`…) volcaba una respuesta por
petición: el `sid` era el `AUTO_INCREMENT`, un entero corto y correlativo. La
comprobación de que la submission perteneciera al formulario impedía el cruce
**entre** formularios, pero no el acceso a las respuestas ajenas **dentro** del
mismo formulario, que es donde están los datos personales.

**Arreglo: token de capacidad en vez de id.** El identificador que viaja en la
URL pública deja de ser un entero recorrible:

- `submissions.public_token CHAR(32)` (`random_bytes(16)`, 128 bits) con índice
  ÚNICO,nullable a propósito (migración 0003, con backfill de las filas
  antiguas); `schema.sql` actualizado para que una instalación limpia nazca con
  la columna.
- `submit` genera el token y redirige a `?t=<token>`; `thanks` resuelve con
  `Submission::findWithToken()` tras validar el formato (`^[a-f0-9]{32}$`, sin
  consultar la BD si no encaja) y **mantiene** el filtro de `form_id`.
- `?sid=` se deja de aceptar por completo. No es un cierre «a medias»: si
  colara un `?sid=`, volvería a ser enumerable.
- `Referrer-Policy: no-referrer` en el thanks, para que el token no viaje en el
  `Referer` si la página enlaza o carga algo de otro origen.

`QuizScore` no cambia: sigue siendo stateless. Lo que se protege es el ACCESO.

**Comprobado:**
- POST real → `Location: /f/smoke-token-.../thanks?t=6c919d77e0e3613f46b148326ff7b895`.
- El token propio muestra el resultado; `?sid=<id>` y los ids vecinos (rango
  `±2`): 0 apariciones de bloque de quiz.
- Token de `3651` sobre el slug equivocado (`debug-p10`): 0 apariciones.
- Backfill: las filas antiguas reciben token, así que el `3651` conserva su
  «3 de 4 correctas» si se consulta con `?t=<su token>`. Ojo al leerlo: eso NO
  significa que los enlaces compartidos sigan sirviendo. Los que se habían
  copiado eran `?sid=3651`, y `?sid=` ya no se acepta, así que **esos enlaces
  dan el agradecimiento sin quiz** (que es el fallo seguro: se thank-you, no la
  respuesta ajena). Compatibilidad real con enlaces antiguos solo habría exigido
  firmarlos, que es justo lo que se descartó.

**Tests.** `tests/unit/ThanksTokenTest.php` (11) + los 3 de integración de
`QuizScoreTest.php` migrados a `?t=`. Un detalle de diseño que salió del
contra-test: la primera versión de esos tests reimplementaba los guards del
controlador en un helper, y con `?sid=` de vuelta a colarse seguían **en verde**
mientras el HTML filtraba respuestas. Ahora se invoca
`FillController::thanks()` real y se mira el HTML que sale; reintroducir la
vulnerabilidad pone en rojo `testElThanksNoAceptaElParametroSid` y
`testThanksIgnoraElIdDeSubmission`.

Suite completa: **304 tests, 0 fallos, 0 errores**.

## Fase 2 — item 2.2: SSRF del webhook (CERRADO 2026-09-28)

**Prueba previa (no teórica).** Listener propio en `127.0.0.1:9999` + formulario
temporal con `webhook.url = http://127.0.0.1:9999/ssrf-proof`. Una respuesta
**anónima** al formulario hizo que el servidor emitiera el POST al servicio
interno:

```
POST /ssrf-proof HTTP/1.1
Host: 127.0.0.1:9999
Content-Type: application/json; charset=utf-8
Content-Length: 242
```

`settings.webhook.url` lo configura el dueño del formulario y, con el registro
abierto, cualquiera llega a tener un formulario: la petición la emite el servidor
hacia donde el atacante quiera *dentro de su red*, con los datos de la respuesta
en el cuerpo. No es ciego del todo: la respuesta se descarta, pero el POST ocurre
(da acceso a internos que cambian estado con POST, y a la metadata de la nube
`169.254.169.254`, de la que se roban credenciales en AWS/GCP/Azure).

**Arreglo.** `Webhook::resolveTarget()` decide, y decide antes de conectar:

- solo `http`/`https`; se rechazan CR/LF en la URL y credenciales embebidas;
- el host se resuelve (A + AAAA) y **se rechaza si cualquiera de sus IP es
  privada/reservada** — no solo la primera, que es la forma de un rebinding;
- rangos fuera: loopback, RFC1918, CGNAT, link-local (**incluida
  `169.254.169.254`**), multicast, reservadas, documentación y benchmarking; en
  IPv6, ULA, link-local, multicast, `::/128`, NAT64, 6to4 y las **IPv4 mapeadas**
  (`::ffff:127.0.0.1`, que se comprueba como IPv4);
- un host que no resuelve a ninguna IP tampoco pasa (nombres tipo `intranet`);
- la conexión se fija a la IP ya validada — cierra el TOCTOU entre validar y
  conectar — pero el `Host` y el **SNI siguen siendo el nombre** (`peer_name`),
  que es lo que permite verificar el TLS de un dominio público al que se entra
  por su IP;
- no se siguen redirecciones.

No hay `ext-curl` en este entorno, así que el POST se escribe a mano con
`stream_socket_client` (con curl, `CURLOPT_RESOLVE` es lo equivalente). El
cliente es fire-and-forget, que es lo que ya era: no se parsea la respuesta.

**Opt-in de red local: hacen falta DOS, no uno.** El primer corte de este item
dejó el opt-in solo en los settings del formulario, y eso **no cerraba el
agujero**: los settings los guarda cualquiera con permiso de edición (dueño,
editor o admin) y con registro abierto cualquiera puede tener un formulario, así
que `{"webhook":{"allow_private_hosts":true}}` bastaba para usar el webhook
como proxy a `127.0.0.1` o a `169.254.169.254`. La frontera correcta es un AND:

| Interruptor | Dónde | Quién lo pone |
|---|---|---|
| `WEBHOOK_ALLOW_PRIVATE_HOSTS` | config `app.webhook.allow_private_hosts` (default `false`) | quien opera la instancia |
| `webhook.allow_private_hosts` | settings del formulario (default `false`) | quien edita ese formulario |

Con el servidor en `false` ningún formulario llega a red privada por muchos
settings que mande, y con el servidor en `true` cada formulario sigue teniendo que
pedirlo (nadie gana una llave maestra). Ambos se comprueban en `fire()`
*antes* de resolver, con rastro en el log cuando un formulario pide red privada
que el servidor no permite. Lo fijan `testElOptinDelFormularioNoBastaSinElDelServidor`,
`testElOptinDelServidorSoloNoBasta` y `testElOptinDelServidorCortaLoopbackYMetadata`
(los tres verificados revirtiendo el AND). La clave del formulario sigue en el
whitelist de `FormSettings::normalize()` porque si no se perdería en cada guardado
(`testElOptinSobreviveANormalize`).

El opt-in del servidor se fija en los tests con `Webhook::setAllowPrivate(true|false)`,
porque `Config` no tiene setter; `null` = se lee de la config.

**Comprobado:**
- el ataque anterior contra `127.0.0.1:9999` ya no llega al listener; el log dice
  `destino bloqueado (IP privada/reservada 127.0.0.1)`;
- con los dos opt-ins sí llega, y el POST a mano sale bien formado
  (`POST /optin-proof HTTP/1.1`, `Content-Length: 179`, cuerpo JSON íntegro);
- con el opt-in del formulario SOLO ya no llega (ni loopback ni metadata), que es
  el hueco que quedaba abierto en el primer corte;
- TLS contra un servidor propio: con certificado autofirmado **falla cerrado** y
  con el cert confiado **funciona** (`bool(true)`), lo que prueba que `peer_name`
  + SNI están bien (si no, ningún https real funcionaría).

**Tests.** `tests/unit/WebhookSsrfTest.php` (23): la tabla completa de rangos,
literales privados, host sin DNS, rebinding, los dos opt-ins y su frontera de
autorización, `normalize()` y las formas de URL. Sin red: `Webhook::setResolver()`
fija la resolución, así que la suite no depende de DNS (`NotificationsTest` también
lo inyecta, porque su `https://ejemplo.com/hook` pasó a depender de la resolución
real). Contra-tests: quitar la comprobación de IP pone en rojo los 4 tests que lo
cubren, y revertir el AND de los opt-ins pone en rojo los 2 de la frontera.

Suite completa: **336 tests, 0 fallos, 0 errores**.

## Fase 2 — items 2.4 y 2.5: CSP por defecto y escapado del cliente (CERRADO 2026-09-28)

Estos dos iban juntos a propósito: el 2.5 era un XSS **con barreduras de
atributos**, y la única barrera que le quedaba era la CSP; pero la CSP estaba
vacía por defecto. Arreglar solo uno deja el otro a medias, y cerrar la CSP
deja la app rota si queda un `<script>` inline.

**2.5 — `esc()` no escapaba comillas.** Escapaba con el truco
`textContent` → `innerHTML`, que escapa `& < >` y **no** las comillas. Ese
resultado se interpolaba en atributos entrecomillados (`placeholder=`, `value=`,
`accept=`, `<option value=`) con `innerHTML`, así que un texto con `"` cerraba
el atributo y lo de detrás se leía como atributos nuevos (`onfocus=…`).

Lo que hace que sea serio: el builder lo abre cualquiera con rol editor o
admin (`canEdit()`). Es decir, un editor que revisa el formulario de otro
ejecutaba el JS de quien lo creó — XSS **con la sesión de un privileged**, no
auto-XSS.

El arreglo son 5 `replace` en `builder.js` y `dashboard.js` (escapar comillas
tampoco cambia el resultado visual en un nodo de texto: el navegador decodifica
la entidad, así que una sola función sigue valiendo para texto y atributo). El
invariante que lo sostiene, y que se mide, es: **la salida de `esc()` no
contiene comillas crudas**, con lo que vale para cualquier atributo.

**2.4 — CSP segura por defecto.** El default de `app.csp_policy` era `''`
"porque las vistas usaban `<script>` inline". No había ni uno: el único handler
en línea que quedaba era el botón de la vista imprimible, que se movió a
`public/assets/js/print.js` (`data-print` + script externo). El default en
código es ahora `script-src 'self'` sin `unsafe-inline` ni `unsafe-eval`;
`style-src 'unsafe-inline'` sí se queda, a propósito (Bootstrap, atributos
`style=""` y el `<style>` de la hoja imprimible).

Que el default esté **en el código** y no solo en `.env.example` importa: esta
máquina ya tenía `CSP_POLICY` en su `.env` y por eso el hueco no se veía en
desarrollo — se veía en una instalación limpia. `testLaCspTieneDefaultSeguroEnCodigo`
comprueba el valor en runtime **y** que `config/config.php` pase un default no
vacío, porque si el default se vacía el `.env` de esta máquina lo enmascararía.

**Comprobado:**
- smoke de todas las páginas con la CSP activa: dashboard, builder, responses,
  analytics, print, export-structure, qr, fill, thanks y login responden 200 y
  llevan la cabecera; `object-src 'none'`, `base-uri` y `form-action` presentes;
- `frame-ancestors` se relaja a `'self'` **solo** en `/f/{slug}/embed` de un
  formulario embebible, y el fill normal de ese mismo formulario sigue en
  `'none'` (el regex de `allowEmbedHeaders()` casa con el default: lo fija un
  test, porque si no casara el embed se quedaría bloqueado sin avisar);
- la vista imprimible ya no lleva `onclick` y carga `print.js?v=<mtime>`;
- `print.js` responde 200; sin `eval`, `new Function` ni URLs `javascript:` en
  los JS del front.

**Tests.** `tests/unit/BuilderEscapingTest.php` (9). El más útil a futuro es
`testNingunaVistaTieneScriptInlineNiHandlerEnLinea`: recorre `views/`, quita
comentarios (para no contar el `<script>` que menciona una nota) y falla si
aparece un `<script>` sin `src` o un `on*=`. Con `script-src 'self'` eso es
justo lo que rompe la página **en silencio**, así que el test avisa antes de que
se note en producción. `testElPlaceholderConComillaNoRompeElAtributo` fija el
invariante por conteo de comillas crudas (el payload no abre ni cierra
delimitadores), no buscando la palabra `onfocus`, que sí aparece pero como texto
inerte.

Contra-tests (los tres revertidos por separado, y en rojo cada uno):
`esc()` al truco `textContent`; el default de la CSP a `''`; y el `onclick` de
vuelta en la vista imprimible. Ojo con el de la CSP: aquí el `.env` lo
enmascara y solo salta la comprobación de código, que es exactamente para lo que
está.

Suite completa: **336 tests, 0 fallos, 0 errores**.

---

## Fase 2 · 2.3 — El seed con la contraseña del admin publicada

**El hallazgo.** `database/seed.sql` insertaba tres usuarios con la MISMA
contraseña, escrita en el propio archivo del repositorio, y uno era `admin`.
El AGENTS.md y el README de la época decían "para empezar rápido: `mysql <
database/schema.sql` y luego `database/seed.sql`", o sea que el camino normal de
un despliegue terminaba con un administrador cuya contraseña estaba en GitHub. Con
el registro abierto de la app, aquello no era un descuido: era una puerta de
entrada, y el repositorio la entregaba.

**El arreglo es de tres piezas, no una.** Quitar los usuarios del SQL (1), poner
quien los siembra detrás de un opt-in (2) y dar una vía para el primer admin de una
instalación real (3). La segunda sin la tercera deja la aplicación sin
administrador: registrarse por la web da rol `user` y no hay forma de ascenderse,
así que un despliegue "limpio" se quedaría sin poder administrar nada.

- `database/seed.sql` crea **solo los roles**. Sin usuarios, sin hashes, sin
  contraseñas. Sigue siendo idempotente (`ON DUPLICATE KEY`) porque los roles los
  necesita hasta el `create-admin`.
- `database/seed.php` siembra los usuarios demo **solo con `SEED_DEMO=1`** en el
  entorno. Sin el opt-in imprime por qué no hace nada y sale con **código 1**, para
  que un despliegue que se lo encuentre en la tubería se note en vez de seguir en
  silencio. Es idempotente y acepta `--fresh` para resetear la contraseña de los ya
  existentes. El hash se genera ahí con `password_hash()`: un bcrypt escrito en el
  repo es una contraseña conocida aunque el script esté protegido. Mínimo 10
  caracteres (el mismo que el registro, item 2.7) y aviso explícito si se deja la
  contraseña pública por defecto.
- `scripts/create-admin.php` crea (o actualiza con `--force`) una cuenta admin.
  La contraseña **nunca** va como argumento: se lee de `ADMIN_PASSWORD`, de
  `--password-stdin` o de un prompt sin eco, porque un argumento queda en el
  historial del shell y en la lista de procesos de todo el sistema. `--password=`
  se rechaza explícitamente con un mensaje que dice por dónde sí va, en vez de
  ignorarse en silencio. Se niega a crear un segundo admin sin `--force`.

**Smoke.** Sin opt-in: no siembra, exit 1. Con opt-in: siembra, exit 0,
idempotente. Contraseña de 5 caracteres: rechazada, exit 1. `create-admin` con
`--force` crea el admin (rol 1, `is_active=1`) y la contraseña verifica; con
`--password=` sale con el mensaje; sin ninguna fuente de contraseña, avisa de las
tres formas disponibles.

**Tests.** `tests/unit/SeedDemoOptInTest.php` (8). Leen el código de los tres
ficheros —un seed es un script que se ejecuta a mano, y no hay forma de invocarlo
desde la suite sin sembrar de verdad en la BD del entorno—, y lo que vigilan es lo
que no se ve mirando el resultado: que `seed.sql` no lleve ningún `$2y$` ni
ningún `INSERT INTO users`; que el opt-in se compruebe **antes** del `INSERT`
(anclado en el código, no en el docblock: si el opt-in fuera decorativo, sembraría
igual y el resultado sería idéntico); y que `create-admin` no acepte la contraseña
como argumento. Cuatro contra-tests lo confirman revirtiendo cada pieza por
separado: bcrypt de vuelta en el SQL, `seed.sql` sin roles, el opt-in movido
detrás del INSERT, y `--password=` readmitido.

## Fase 2 · 2.7 — Login: enumeración de emails por tiempo de respuesta

**El hallazgo, medido.** El login hacía `$user === null || !password_verify(...)`.
El `||` cortocircuitado se saltaba el bcrypt cuando el email no existía, así que
la respuesta lo decía todo:

| intento | email existente | email inexistente |
|---|---|---|
| antes | **0.328 s** | **0.016 s** |
| después | **0.320 s** | **0.322 s** |

Un factor 20, con la respuesta y los headers idénticos. En un despliegue con
registro abierto, permitir las cuentas puede ser un requisito (inscripciones,
soporte, encuestas públicas), y entonces esto es un oráculo para saber qué correos
están dados de alta, y por tanto un paso previo para el resto de ataques dirigidos.

**El arreglo.** Un `DUMMY_HASH` de bcrypt real al coste de `PASSWORD_DEFAULT` que
se verifica cuando el usuario no existe. El coste es el de un `password_verify`
normal, así que los dos caminos hacen el mismo trabajo. Que el señuelo sea
**bcrypt del mismo coste** es la parte que no se puede negociar: un hash más
barato, o un string que `password_verify` no puede verificar, devolvería antes y
volvería a delatar la diferencia con la misma mezcla. `AuthHardeningTest` mide la
razón entre ambos tiempos (no un umbral absoluto, que depende de la máquina) y
comprueba que el hash declarado es del coste actual.

De paso, en el mismo item: mínimo de contraseña **10** (compartido como
constante entre servidor y vista, porque si la vista dice una cosa y el servidor
otra el usuario escribe 8 y el navegador se lo acepta), **rehash oportunista** en
cada login correcto (sin flujo de "cambiar contraseña", es la única vía de
migración de hash: una cuenta con coste 4 sube a 12 en su primer login y la
contraseña no cambia) y `session.use_only_cookies=1` antes del `session_start()`,
que sin eso acepta un identificador de sesión que venga en la URL (`?SID=`) y lo
fija: robar una sesión mandando un enlace.

El rehash se prueba sobre la BD real degradando el hash a coste 4, ejecutando el
`login()` de verdad y comprobando que el hash queda al coste actual. En CLI no hay
petición, así que el test inyecta `$_POST` (que es lo que lee `Request::post()`) y
un token de CSRF válido; sin eso el controlador saldría por el flash de error y
el rehash no se ejecutaría nunca — el test pasaría sin probar nada.

**Tests.** `tests/unit/AuthHardeningTest.php` (8). Cinco de ellos son
contra-test-lelos de lo que se suele dar por hecho: el patrón viejo del `||` no
puede reaparecer aunque el resultado final sea idéntico, la contraseña del seed
sigue cabiendo en el mínimo, `use_only_cookies` se fija **antes** de
`session_start()` y no basta con un default global, y `updatePasswordHash` no
puede guardar la clave en claro. Cinco reversiones comprobadas una a una.

Ojo al medir el timing por HTTP: el limitador de intentos se comprueba **antes**
de verificar la contraseña, así que a partir del sexto intento las dos peticiones
responden en ~0.016 s. Es un limitador **por IP**, no por email, así que no
enumeración; pero si mides sin vaciar `rate_limits` entre parejas, el diferencia
que ves es del limitador y la conclusión sale mal.

## Fase 2 · 2.8 — Inyección de CSS por la tipografía del formulario

**El hallazgo.** `settings.theme.font` se validaba con la misma lógica que... nada:
no se validaba. Los colores sí (`^#[0-9a-f]{3,8}$`), el font no. Y su valor va
directo a un `font-family` dentro de un `<style>` que carga el **fill público**, o
sea sin sesión ni CSRF y con el formulario de cualquiera. Guardar como tipografía
`Arial; } body{background:url(//atacante/leer?x)` añadía declaraciones CSS nuevas
en la página de todos los visitantes, con el estilo de la app como pantalla para
atrapar clics.

**El arreglo es una lista blanca, en las DOS puertas.** `isValidFontFamily()`
admite letras, números, espacios y `- _ . ,` (una o varias familias separadas por
comas, `Inter, sans-serif`), que es todo lo que hace falta para un `font-family` y
nada más: sin comillas, paréntesis, `;`, llaves, barra, dos puntos ni `<`, o sea
sin `url(`, sin `@import` y sin cierre de declaración.

El filtro está en `normalize()` (todo lo que llega del builder o de un POST de
settings) **y** en `themeCss()` al emitir. La segunda puerta es la que de verdad
importa: puede haber filas en `forms.settings` guardadas antes de que existiera el
patrón, y filtrar solo al guardar no las limpia. Comprobado con una fila escrita a
mano en la BD como si fuera previa al arreglo: la página pública sale con
`--vf-primary` y sin una sola aparición del payload.

`validate()` mira el **valor crudo**, no el normalizado. Si mirara el normalizado
nunca vería un valor inválido (ya viene en `null`) y quien escribe la tipografía se
quedaría sin ningún aviso de por qué no se guardó.

Un detalle de la salida: la fuente se emite **sin comillas**, porque el patrón ya
no las admite. Antes el `htmlspecialchars` previo las convertía en `&quot;`, que
dentro de un `<style>` no se decodifica, o sea un nombre de fuente roto que no
fallaba de forma visible. Una familia con espacios (`Source Sans 3`) es CSS
válido sin comillas.

**Tests.** `tests/unit/ThemeFontCssInjectionTest.php` (9), con 15 payloads de
inyección (cierre de regla, `url()`, `@import`, comillas, cierre de `</style>`,
punto y coma, paréntesis...) contra las tres puertas, más un test de que un font
inválido **no** se lleva por delante los colores válidos: se descarta la variable,
no el tema entero, que sería un fallo distinto y más silencioso. Tres
contra-tests: lista blanca que acepta todo, `themeCss` sin filtrar, y `validate()`
mirando el valor normalizado.

Suite completa: **362 tests, 0 fallos, 0 errores**.

## Fase 3 · Capa de datos

### D5 · `fk_fields_parent` e índices que el esquema promete y la BD no tiene

**Diagnóstico.** `database/schema.sql` declara la FK `fk_fields_parent`
(`ON DELETE CASCADE`) y varios índices, pero una base creada con las migraciones
0001-0003 se queda sin ellos, y nada avisa: el INSERT sigue funcionando, el
dashboard sigue funcionando, y **al borrar una sección sus preguntas quedan
colgando como raíces**, que es el síntoma del bug D1 pero por el camino de los
datos.

**Arreglo.** Migración `0004_schema_index_drift.php`, idempotente, que crea
`idx_forms_user_status`, `idx_fields_parent`, `fk_fields_parent`,
`idx_answers_submission_field` e `idx_rate_window`. Antes de añadir la FK
comprueba que no haya filas colgando.

**Lo que destapó.** La app escribía `parent_id = 0` para "campo suelto" cuando
la columna está declarada `INT UNSIGNED DEFAULT NULL` y la FK no admite el 0
(¡un AUTO_INCREMENT no puede tener id 0!). Guardar la estructura de un
formulario con preguntas sueltas reventaba con un 1452. Ahora la normalización
vive en un solo sitio, `Field::normalizeParentId()`, y 0 → NULL.

**Verificación.** FK y CASCADE probados con fixtures temporales; contra-test que
revierte la normalización y reproduce el 1452.

### D3 · `rate_limits`: 493 filas con IPs de respondientes, creciendo sin freno

**Diagnóstico.** `RateLimiter::cleanExpired()` **no tenía ningún call site**.
Cada combinación scope/identificador dejaba una fila para siempre; el suite de
tests lo hacía evidente (+9 filas por ejecución, todas `submit:*`).

**Arreglo.**
- `RateLimiter::recordHit()` se lleva una limpieza oportunista (1 % de las
  escrituras, máximo una por petición) → la tabla se mantiene acotada sin cron.
- `RateLimiter::MAX_WINDOW = 600 s` acota qué se considera caducado. Si el umbral
  fuera menor que la ventana más larga que admite el limitador (1 h), esas filas
  nunca se borrarían.
- `scripts/clean-rate-limits.php` para mantenimiento explícito y cron.
- `maybeCleanExpired()` devuelve `?int`: `null` = no se ejecutó, entero = filas
  borradas. Devolver siempre 0 hacía imposible distinguir "no limpió" de "no
  había nada que limpiar", que es justo lo que hace que un test de este cleanup
  pase sin probar nada.

### D6 · El contrato horario: la BD en UTC, PHP en America/Mexico_City

**Diagnóstico.** Tres cosas estaban rotas a la vez, y por eso nadie lo veía:

1. Las columnas de fecha son `TIMESTAMP` y la sesión de MariaDB usaba
   `SYSTEM`, o sea heredaba la zona del host (aquí UTC, por casualidad).
2. `config/bootstrap.php` ponía PHP en `America/Mexico_City`, así que
   `strtotime()` de un string de la BD lo colocaba 6 h más tarde de su instante
   real. **Imprimir el string en crudo lo disimulaba**: la fecha se veía bien y
   solo fallaba la aritmética (los "hace X", los filtros por día, los `DATE()`).
3. `America/Mexico_City` está 2 h por delante de la zona canónica (Caracas).

Medido sobre los 8 datos reales: **5 de 8 submissions caían en el día equivocado**
en el agrupamiento diario, y el filtro `from`/`to` comparaba días UTC ingenuos
contra la columna. El calendario del formulario (P6) leía `open_at`/`close_at`
—que salen de un `datetime-local`, o sea muros de reloj en la zona de quien
administra— en la zona del proceso: un formulario con `open_at = 09:00` se
abría a las 05:00.

**Arreglo.** El contrato en tres puntos, y que no se puede romper en uno solo:
- `Database::__construct()` hace `SET time_zone = '+00:00'` → la BD habla UTC
  aunque el host esté en otro sitio. PDO no tiene parámetro de DSN para la zona
  y `init_command` es de mysqlnd, no de PDO, así que la vía portable es un `SET`
  tras conectar.
- `config/bootstrap.php` pone PHP en **UTC**.
- `App\Core\Clock` convierte a la zona de presentación (`APP_TIMEZONE`,
  `America/Caracas` por defecto) **solo en el punto de pintar**:
  `View::date()`, `View::datetime()`, `View::relative()`, `View::now()`,
  `View::timestamp()`.

Y donde el día importa de verdad, la conversión va al SQL:
- `Submission::dateBounds()` traduce el filtro de la interfaz a límites UTC
  (día local = `[04:00, 04:00)` en Caracas), con el final **exclusivo** para que
  el filtro y el agrupamiento no puedan discrepar en un segundo.
- `Submission::dailyStats()` agrupa por día local. Pasa el desplazamiento
  **numérico** a `CONVERT_TZ` y no el nombre de la zona, porque el nombre exige
  que el servidor tenga las tablas de zonas horarias cargadas y, si no las
  tiene, devuelve `NULL` en silencio (agrupamiento vacío y ninguna pista).
- `FillController::parseScheduleDate()` interpreta los muros de reloj en
  `app.timezone`, respetando la zona si el string trae una explícita.
- `isValidDay()` rechaza fechas imposibles: `createFromFormat` "corrige"
  `2026-02-31` a marzo sin avisar.

**Lo que NO se hizo: migrar los datos.** El plan original pedía desplazar las
filas existentes. Se verificó que no hace falta y que habría sido
**corromperlas**: `strtotime($valor . ' UTC') === UNIX_TIMESTAMP($columna)` en las
8 submissions. Los datos ya eran instantes UTC correctos; lo único que estaba mal
era quién los leía. No hay ni una columna escrita desde `date()` de PHP, así que
tampoco hay nada que future-proof que actualizar.

**Verificación.** `tests/unit/TimezoneTest.php` (18 tests) más cinco
contra-tests: quitar el `SET time_zone`, volver a concatenar la fecha cruda en
el filtro, dejar `parent_id = 0`, poner un `date()` crudo en una vista y bajar
`MAX_WINDOW` — los cinco se ponen rojos.

### D7 · Dos consultas de más en la vista imprimible y el export de estructura

`Form::find()` hace `SELECT f.*`, o sea que **ya trae** la columna `settings`, y
en `printForm()` y `exportStructure()` se pedía otra vez con
`Form::settings($id)`: una consulta repetida por petición y un segundo
`FormSettings::normalize()` (que recorta y pasa por lista blanca un puñado de
bloques, no es trivial).

`Form::settingsFrom($form)` normaliza los settings de una fila ya cargada, y
`Form::settings($id)` delega en ella: la normalización queda en un solo sitio, así
que una fila guardada antes de que existiera un ajuste de la whitelist sale
igual por las dos puertas. En `FillController` **no** había redundancia: sus tres
llamadas a `Form::settings()` están en ramas excluyentes (cerrado / borrador /
resto), nunca se ejecutan dos en la misma petición.

### D8 · La analítica: 3 consultas por pregunta y un `IN` que revienta

**Diagnóstico.** `AnalyticsController::show()` pedía, **por cada pregunta**, un
recuento de no vacías (`Answer::nonEmptyInForm`) y una distribución de valores
(`countByField` / `countsCheckbox` / `matrixStats` / `rankingStats` / …), más
una de NPS si había un campo de ese tipo. Sobre el formulario `4623`
(11 preguntas) eso son 16 consultas de agregación por visita; el rango del plan
(46–108) era la cota teórica, no la medida. Y `Submission::withAnswersByIds()`
metía **todos** los ids en un solo `IN (?,?,…)`: con 70 000 respuestas eso son
70 000 placeholders y MariaDB responde `HY093` — un formulario con respuestas de
masía no abría su propia analítica.

**Arreglo.**
- `App\Services\FieldAggregator::forForm()` hace **2 consultas fijas** para todas
  las preguntas a la vez: una de recuentos (`Answer::nonEmptyCountsInForm()`,
  que ya no trae `a.value`, así que los `TEXT` largos no se arrastran a memoria)
  y una de distribuciones (`Answer::valueCountsInForm()`). Los derivados
  (promedios, min/max, NPS, matriz, ranking) se calculan en PHP sobre esas dos
  tablas ya en memoria. La forma del array es idéntica a la que devolvían los
  métodos por campo, así que `views/analytics/show.php` no cambió ni una línea.
- `Submission::withAnswersByIds()` trocea en tandas de 5 000 ids
  (`ID_BATCH`), y **reordena el resultado globalmente** al final
  (`finished_at DESC, id DESC`): concatenar las tandas tal cual devolvería las
  respuestas en orden de tanda, no de fecha.
- `AnalyticsController::validarFecha()` delega en `FieldAggregator::validDay()`
  para que el CSV y la pantalla rechacen los mismos días imposibles.

**Lo que destapó: `MIN`/`MAX` comparaban como texto.** `answers.value` es TEXT,
así que `MIN(a.value)`/`MAX(a.value)` de `numberStatsInForm()` comparaban
**lexicográficamente**: con 10, 9 y 7 devolvían `min_value = 10` y
`max_value = 9`, o sea un rango al revés, en pantalla. La agregación en PHP
compara float y salía bien, lo que dejaba a los dos caminos en desacuerdo y
delataba que el viejo mentía. Arreglado en el SQL con `MIN(a.value + 0)`.

**Lo que también faltaba: el filtro de la analítica no recortaba por día local.**
`Answer::queryField()` concatenaba `$from . ' 00:00:00'` sobre la columna UTC.
Con el filtro `from=2026-03-09` (Caracas) eso es `[2026-03-09 00:00, ...] UTC`
y una respuesta de las 02:00 UTC del día 10 —que en Caracas son las 22:00 del
día 9— se caía del día 9. Ahora usa los mismos límites UTC de
`Submission::dateBounds()` vía `Clock::dayStartUtc()` / `Clock::dayEndUtc()`,
con el final **exclusivo**, igual que el resto de la app. La agregación es
global sobre todas las submissions filtradas; paginar la tabla es otra cosa
(ver "Lo que queda abierto").

**Verificación.** `tests/unit/AnalyticsAggregationTest.php` compara la
agregación nueva contra los métodos por campo campo a campo, con respuestas
duplicadas, fechas imposibles, filtro de un día y la frontera de día local;
`tests/unit/SubmissionBatchTest.php` cubre el orden global entre tandas, el
`IN ()` vacío, la normalización de ids y una lista de **70 001** ids (por
encima del techo real de 65 535). Contra-tests: quitar un `* c` de los
promedios, quitar el `usort()` o el troceo de tandas pone los tests rojos.

Suite completa: **401 tests, 0 fallos, 0 errores**.

### Dos puntos del plan que, al mirarlos, ya estaban hechos

- **`Submission::withAnswersByIds()` ya estaba en lote**: son 2 consultas
  (submissions + answers con `IN (...)`), no un N+1. Lo que no tenía era
  **trocear** ese `IN` (ver D8).
- **`rate_limits` sin call site**: tampoco era "nunca se limpia", era "nadie la
  ha llamado nunca" — cubierto en D3.

### Lo que queda abierto (y no es de la capa de datos)

> **CERRADO 2026-09-28 por D13** (paginación server-side de la tabla de
> analítica, `Submission::byFormFilteredPaginated()`, 15/página, página 1
> renderizada en servidor). El texto se conserva como registro de la fase 5.
>
> Y el listado de formularios del panel y de `/admin/forms` —el otro `LIMIT` que
> faltaba de M2— quedó cerrado el **2026-10-05 por D14**. Aquí se decía «cambia
> de interfaz, se decide aparte»: el argumento era que paginar un listado no es
> una limpieza de datos sino un cambio de producto, y es cierto. Se decidió
> aparte, tres semanas después, en D14.

`AnalyticsController` carga **todas** las submissions del formulario
(`byFormFiltered()` no tiene `LIMIT`) y la vista las pinta una a una. Con un
formulario de miles de respuestas esa página se come la memoria y el render. La
paginación es el arreglo, pero es un cambio de **interfaz** (contador, enlaces y
qué agregados se calculan sobre la página o sobre el total), no una limpieza de
datos: se decide aparte a propósito, para no meter un cambio de producto
disfrazado de Fix de capa de datos.

---

## Fase 6 — RBAC, log de seguridad y rendimiento (CERRADA 2026-09-28)

Cierre del ciclo pendiente de la fase 5: **controles de acceso por permisos**,
**registro de eventos de seguridad** y **rendimiento del dashboard** (caché) +
**paginación server-side** de la tabla de analítica. Los tres eran los
pendientes que apuntaba el documento de fase 5 y los "Próximos Pasos" del
technical-document.

## D10 — RBAC: de "nombres mágicos" a permisos

**Antes.** El acceso se decidía con `role_name`: `Auth::isAdmin()`, el
middleware `admin` igualando el nombre del rol, y los `canAccess/canEdit` de los
controladores comiendo migas "dueño || rol mágico". `editor` tenía acceso de
dueño a TODO (F5.2 quedó pendiente a propósito), cualquiera con rol editado o
una nueva consulta SQL de `roles` cambiaba la semántica sin test que se diera
cuenta, y no existía forma de dar una capacidad a un equipo sin subirle el rol.

**Después.**

- Catálogo de permisos en `config/permissions.php` (`clave => [desc, roles]`),
  sembrado en **dos copias** por la misma convención de siempre: la migración
  0005 (bases existentes, que lee el catálogo en runtime) y `database/seed.sql`
  (instalaciones limpias, bloque estático). `PermissionSeedParityTest` lee
  seed.sql y compara claves y roles por defecto contra el catálogo: sin él las
  dos copias divergirían sin ruido.
- Tablas: `permissions`, `role_permissions`, `role_groups`,
  `role_group_roles`, `role_group_users` (migración 0005 + `schema.sql`).
- `Auth::can()/permissions()` como ÚNICA puerta de negocio: permisos efectivos =
  rol propio + roles de los grupos a los que pertenece cada usuario
  (`Role::effectivePermissionKeys`). La caché de permisos por request se
  refresca con `Auth::forgetCache()` (mismo patrón que el usuario cacheado).
- `App\Core\Access` unifica el alcance del dueño: `ownWith(owner, '*_own')`
  && dueño, o `forms.manage_all` a secas. Ese permiso abre el alcance global
  (seed: solo admin; un grupo no-admin puede otorgarlo, que es la vía diseñada
  para "equipo que opera formularios de todos" sin escalar rol).
- Los controladores del panel vuelven a comprobar el permiso con `Auth::can()`
  (el middleware `admin` solo controla la puerta de `/admin`; cada vista suya
  rellena el menú según `admin.*`).

**Bug real destapado en el smoke.** `ControllerActionsTest` (que recorre TODAS
las rutas con CSRF válido) postea `{}` a `/admin/roles/save`: `$_POST['perms']`
inexistente se interpretaba como "desmarcar todo" y la matriz quedaba así —
admin con solo `admin.access`, user/editor sin nada — y a partir de ahí toda la
suite caía (las puertas `forms.create` cerradas por el nuevo RBAC). La guarda:
un POST sin la estructura `perms[<role_id>][]` se rechaza sin persistir. Añadido
además el invariante de que el rol `admin` conserva siempre `admin.access` para
que el panel no pueda auto-encarcelar al único recurso que lo arregla.

**Efecto colateral de la matriz rota.** La suite completa se llevó una cascada
de 100+ errores que no tenían que ver con RBAC: `Role::permissionKeys(2)` vacío
→ `Auth::can('forms.create')=false` → `FormController::create` responde 403 →
los tests que creaban formularios (DashboardLimit, P9Templates, FillController,
P10Uploads, …) se descoyuntaban. Re-sembrada la matriz, la cascada desapareció;
el test de paridad ahora evita que vuelva a romperse en silencio.

## D11 — `security_log`: auditoría que nunca rompe el request

**Antes.** No existía registro de eventos de seguridad: los intentos fallidos de
login, CSRF inválido, rate limits alcanzados, hosts privados de webhook
rechazados y subidas fallidas se perdían o vivían solo en `error_log`.

**Después.** Tabla `security_log` (evento, detalle, ip, user_id, created_at) +
página `/admin/security-log` con filtro por evento y paginación server-side
(`SecurityLog::recent`). Contrato fijado:

- `record()` **nunca rompe el flujo**: todo está en try/catch y ante cualquier
  fallo el evento se pierde pero la petición sigue (auditoría ≠ componente de
  negocio).
- El evento se valida (`^[a-z0-9_.]{2,60}$`) y el detalle se recorta a 500.
- **FK a salvo**: heredar `user_id` de `Auth::id()` a pelo reventaba con 1452
  cuando la sesión apuntaba a un usuario inexistente (tests con id ficticio o
  usuario borrado a mitad de request): ahora hay un `EXISTS` sobre `users`
  antes del INSERT y, si el usuario no existe, la fila se guarda con NULL. La
  auditoría no puede depender de la coherencia de la sesión.
- Hooks emitidos (todos con su evento en `knownEvents()`): login ok/fail,
  register/logout, CSRF inválido (al fallar `verifyRequest`/`verifyHeader`),
  rate limits (login/fill/dash_create/dash_export), hosts privados de webhook
  rechazados, extensiones denegadas en uploads, borrado/duplicado de
  formularios y acciones admin (crear/borrar grupo, matriz de roles).

## D12 — Caché del dashboard e `LAST_INSERT_ID` (el bug gordo)

`DashboardController` recalcula el layout en cada request (formularios + conteos
por usuario, o global con `forms.manage_all`). Con `Cache::remember('dash:...',
60, ...)` se sirve durante 60 s, y las mutaciones de forms/submissions invalidan
el prefijo `dash:%` (`bumpDashboardCache`). La caché nunca rompe el request: si
la tabla falla o la fila está corrupta, se recalcula en vivo.

**El bug que costó una cascada de 102 errores en la suite.** `bumpDashboardCache`
(que hace `DELETE FROM cache WHERE cache_key LIKE 'dash:%'`) estaba
**entre** el `INSERT` y el `lastInsertId()` de `Form::create` y
`Submission::create`. En MariaDB un `DELETE` en medio resetea
`LAST_INSERT_ID()` a **0**: todos los formularios se creaban (el row existía)
pero los tests recibían `id = 0` → "el ID debe ser mayor a 0" o FK 1452 en
`fields.form_id` en cadena. Lección, registrada en AGENTS.md: si la pérdida de
caché convive con un `lastInsertId()`, capturar el id ANTES de invalidar.

Nota: el orden importa SOLO en el insert; en `update/delete/setStatus/
saveSettings` la invalidación se hace al final sin problema (no depende de
`LAST_INSERT_ID`).

## D13 — Paginación server-side de la tabla de analítica

El "queda abierto" del final de la fase 3 se cierra ahora como cambio de
interfaz: la tabla de detalle se pagina en el servidor (15/página), los
agregados siguen globales, y la página 1 se renderiza server-side (sin
parpadeo). `GET /forms/{id}/analytics/rows` devuelve `{ok, html, pagination,
page, pages, total}` y `analytics.js` aprovecha `data-paginate-page` para
refrescar el tbody + el pager vía fetch. `SubmissionPaginationTest` pinza el
envelope, el clamp de páginas fuera de rango y el 403 sin permiso de dueño.
(El contrato es idéntico al de `byFormPaginated`, así el cliente usa uno.)

## Verificación

- Tests nuevos: `PermissionSeedParityTest` (paridad catalog/seed.sql),
  `RbacAccessTest` (can/editor sin delete/permisos efectivos vía grupos),
  `CacheDashboardTest` (remember una sola vez, forgetLike, invalidación por
  create de form y submission), `SubmissionPaginationTest` (envelope + rows),
  `SecurityLogTest` (formato, user_id NULL vs real, filtro knownEvents, purga).
- Suite completa: **465 tests, 0 fallos, 0 errores**.

## Abierto

Revisado el 2026-09-30. **No queda nada abierto de la lista anterior**: un ítem
era moot y el otro sigue vivo pero con valor bajo.

- ~~La matriz de permisos de una instalación con grupos personalizados no se
  migra automáticamente~~ — **moot, no pendiente** (2026-09-30). El ítem daba
  por hecho que un grupo iba a tener su propia matriz de permisos, y no la
  tiene: `role_permissions` es **por rol**, y un grupo solo confiere roles
  (`Role::effectivePermissionKeys($roleId, $groupIds)` = rol propio + roles de
  los grupos, en una consulta con dos `EXISTS`). Por tanto un grupo con
  permisos propios no existe como concepto y no hay nada que migrar; la
  semipermanencia de una instalación con grupos se resuelve desde la matriz de
  roles del panel, que es lo que sí escribe `role_permissions`. Un grupo nuevo
  que quiere cambiar qué puede hacer sus usuarios se ajusta en los ROLES que
  confiere.
- Lazy loading del bundle del builder (`builder.js` + `sortable`, hoy con
  `defer` en `views/builder/index.php`). **Sigue sin hacerse, y se decide que
  no**: los dos scripts ya se descargan **solo** en la página del builder, así
  que el lazy loading no le ahorra un solo byte a nadie — quien no entra al
  builder no los descarga igual, y quien entra los necesita. Se anota por si
  algún día el builder crece lo bastante como para que duela, que hoy no es el
  caso. Ojo si se retoma: los dos scripts de la pareja necesitan el
  **mismo** atributo o el segundo corre antes que la librería.

---

**Suite final de la fase: `465 tests | 0 fallos | 0 errores`.**
