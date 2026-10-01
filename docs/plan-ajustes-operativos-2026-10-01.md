# Plan — Ajustes operativos fuera del builder, registro global, roles y limpieza visual

**Fecha:** 2026-10-01
**Estado:** en ejecución, un commit por bloque.
**Alcance:** seis bloques independientes, en el orden en que se listan porque cada uno
depende del anterior (el bloque 2 crea la puerta por la que entra el ajuste de acceso
del bloque 3).

Continuación de [docs/uiux-roadmap.md](uiux-roadmap.md) (el *_roadmap_ es histórico;
esto es el lote concreto que se pidió después).

## Decisiones ya tomadas (no volver a abrir)

| Decisión | Valor |
|---|---|
| Qué sale del modal del builder | Solo lo **operativo**: acceso, agenda, cupo, avisos+confirmación, webhook, gracias, revisión e idioma |
| Qué se queda en el builder | Tema, quiz, barajado, avance automático, embed, honeypot y anti-bot |
| Estado del registro público | Tabla `app_settings` con **fallback** a `REGISTRATION_ENABLED` de `.env` |
| Interruptor del registro | Se guarda por **fetch** (sin recarga), no por POST+flash |
| Rol de aprobación | Se crea **`approver`** como rol del sistema, con `forms.review` |
| Borrado de roles | Solo roles **propios** (fuera del catálogo) **y** sin usuarios |
| Secciones del builder | Se quita el `padding-left` y el `border-left`, se **conserva el fondo** |
| Preview de sección | Fila con título a la izquierda e icono a la derecha (`margin: auto .5rem`) |

## Hallazgos que fijaron el diseño

- `Form::saveSettings()` normaliza el array **entero** (`Form.php:406`): guardar un
  subconjunto pondría el resto en defaults. Sin un `FormSettings::merge()` compartido,
  mover ajustes a otra pantalla son dos puertas que se pisan.
- `#access` ya hace `fetch` con `X-Requested-With` (`public/assets/js/dashboard.js:264`):
  el patrón de «sin recarga» ya existe y se copia tal cual (`AccessController::copyLink()`).
- `users.role_id` es `ON DELETE RESTRICT` (`database/schema.sql:34`): la base ya impide
  borrar un rol con usuarios. Falta el gate, el recuento y la UI.
- `roles` **no** tiene marca de rol del sistema: el conjunto vive en
  `Role::SYSTEM_ROLES`, y es lo que hace `User::reviewers()` irrelevante para el rol
  nuevo (filtra por el permiso `forms.review`, no por el nombre del rol).
- El catálogo de permisos está duplicado (`config/permissions.php` + `database/seed.sql`)
  y lo vigila `PermissionSeedParityTest`: añadir `approver` toca **los dos** y una
  migración nueva que re-siembra (precedente: `0006_fields_is_pii.php`).
- `.nps-option` **sí existe** (`app.css:1454-1456`): lo que no tiene CSS es el preview
  de la tarjeta en el lienzo (`nps-preview`, `nps-dot`, `star-icon`, `vf-matrix-preview`,
  `ranking-pos`…), que hoy se ve crudo. Auditoría: 13 clases `app-`/`nps-`/`vf-` que
  `builder.js` emite y `app.css` no define.
- `views/fill/show.php:536-550` inventa `ui.page_title` cuando una page no tiene label.
  El `aria-label` del bloque (`:526`) y el badge del pie (`:571`) ya dan «Paso X de Y».

## Bloques

### 1 · Tooltips en el modal de configuración
`views/builder/index.php` + `public/assets/js/builder.js`.
Cada ayuda estática pasa a un botón `?` con `data-bs-toggle="tooltip"` (texto conservado
en el DOM + `aria-describedby`). `initTooltips(root)` con `trigger: 'hover focus click'`.
**Fuera del tooltip:** `stFontNote`, `stReviewHint`, `stAccessHint`, `stAdvanceHint`: son
estado, no explicación. Regla a `docs/design.md`: *ayuda = tooltip, estado = visible*.

### 2 · `FormSettings::merge()` + `POST /forms/{id}/settings`
`FormSettings::merge($current, $patch)` = `normalize(array_replace_recursive(...))` por
bloques de primer nivel. Lo usan **las dos** puertas: la nueva ruta y
`BuilderController::saveStructure()`, que deja de pisar. En la ficha (`#settings`) caen
los 8 bloques operativos; el modal se queda sin ellos y sin sus listeners.

### 3 · Alta de emails de acceso sin recarga
Rama JSON en `AccessController::addEmail()` con el patrón de `copyLink()` (mismos i18n y
códigos de error). Parcial `views/dashboard/forms/_access_list.php` como fuente única;
el POST devuelve ese HTML y `dashboard.js` lo intercambia en `#accessList`. Sin JS el
POST sigue recargando (fallback). Los textos «cambia el modo en Configuración» desaparecen.

### 4 · Interruptor de registro público
Migración **0010** `app_settings` (sin fila inicial: manda `.env`),
`App\Core\AppSettings` con `get/set/bool` y **try/catch** (una instancia sin migraciones
no puede caer en 500), `AuthController::registrationEnabled()` pública y con override,
`POST /admin/settings/registration` (`admin.access` + CSRF) y el interruptor en `/admin`
guardado por fetch.

### 5 · Rol `approver` y borrado de roles
Migración **0011**: `INSERT IGNORE` del rol + re-siembra leyendo `config/permissions.php`.
`Role::SYSTEM_ROLES`, `isSystem()`, `userCount()`, `delete()` (false si es del sistema o
tiene usuarios), `AdminController::roleDelete()` (`admin.roles`, CSRF, `SecurityLog`,
flash con el motivo) y botón con `data-confirm` solo donde se puede.

### Notas del bloque 5

- **`approver` nace con `dashboard.view` además de `forms.review`.** El plan
  decía solo `forms.review` y estaba mal: `DashboardController::index()` exige
  `dashboard.view`, así que un rol que solo puede aprobar se encuentra con un
  403 en la puerta de entrada y la bandeja `/review` no se alcanza desde la
  navegación. `dashboard.view` es lo mínimo para **llegar**; lo que lo hace
  dueño de algo (`forms.edit_own`, `forms.create`) sigue sin dárselo, que es la
  línea que separa «revisor» de «dueño a medio camino».
- **La lista de roles del sistema va en el código (`Role::SYSTEM_ROLES`), no en
  una columna `is_system`.** Una columna parecería más limpia, pero el conjunto
  depende de lo que la app sepa hacer (`approver` existe por la revisión de F3)
  y no se puede rellenar en el DDL: sembrarla desde la migración obligaría a
  una migración nueva cada vez que se añadiera un rol **y** a un `UPDATE` para
  los que ya estuvieran, con el riesgo de que la fila y el código acaben
  discrepando. Con la lista en el código la comparación es la fuente.
- **Las dos condiciones del borrado se comprueban por separado y en el
  modelo.** `users.role_id` es `ON DELETE RESTRICT`, así que la base ya impide
  borrar un rol con gente; se comprueba en `Role::delete()` para poder decir
  «tiene 3 personas» en vez de un error de integridad. Y los roles del sistema
  no se borran aunque estén vacíos: `approver` sin `forms.review` es un rol que
  no puede hacer nada, y borrarlo deja la revisión de F3 sin destino.
- **El botón de borrar no es un botón deshabilitado con un `title`.** No hay
  botón, y el motivo va al lado como insignia («Sistema», «En uso»). Un
  deshabilitado con la explicación larga es algo que hay que descubrir con el
  ratón, y la pregunta de quien administra no es «¿por qué no puedo?» sino
  «¿qué hago?».
- **La baja va en una tarjeta aparte, no en la cabecera de la matriz.** El botón
  necesita su propio `form` y un `form` dentro del `form` de «Guardar matriz» es
  HTML inválido que cada navegador resuelve como le apetece.
- **`data-confirm` va en el `form`, no en el botón.** El listener de `app.js`
  busca `form[data-confirm]`, así que puesto en el botón no aparecería ninguna
  confirmación.
- **La navegación enseña solo lo que se puede usar.** Se han metido dos gates
  más en `views/layouts/main.php` (`dashboard.view` para el enlace del
  dashboard, `forms.create` para «Nuevo formulario») siguiendo el precedente
  de F3 con el enlace de Revisión. El caso que lo motivó: un `approver` que
  veía «Nuevo formulario» y recibía un 403 con toda la pinta de un botón que
  funciona.
- **`Role::userCount()` va aparte de `allWithPermissions()`** y en un mapa
  indexado por id: la lista de la baja se recorre con `foreach` y un `count`
  por fila son N consultas para un número que se enseña junto al nombre.
- Lo que NO se ha tocado: `updateRoleMatrix()` sigue dejando vaciarle los
  permisos a cualquier rol, incluido `approver`. Si un operador le quita
  `forms.review` al rol, es su decisión y la matriz es el sitio para tomarla; no
  hace falta una regla más.

### 6 · Limpieza visual
`.app-section-children` sin `padding-left` ni `border-left` (fondo conservado),
`.app-palette-static` con `gap` (separar Sección de Página), preview de sección en
`.vf-section-inner` con `.vf-section-icon { margin: auto .5rem }`, CSS de los previews de
NPS/estrella/matriz/ranking, y `views/fill/show.php` sin «Página N».

## Invariantes que este lote no puede romper

- CSP `script-src 'self'`: nada de `<script>` inline ni `on*=`; todo JS en `public/assets/js/`.
- Todo POST de settings pasa por `FormSettings::validate()` **sobre el array fusionado**.
- F3: `forms.status` solo lo mueven las tres acciones de `ReviewController` y
  `BuilderController::saveStructure()`; tocar `review.enabled` no publica nada.
- `ControllerActionsTest` recorre `config/routes.php`: las tres rutas nuevas se ejecutarán
  solas, con fixtures.
- Suites de PHP nunca en paralelo (comparten fixtures y BD).
- `.env` manda por defecto; el override es un valor, no una escritura al `.env`.

## Verificación

- Por bloque: `php scripts/test.php --filter <nombre del método>` (**por nombre de
  método**, nunca de clase) y suite completa al final.
- Tests nuevos: merge de settings, ruta de settings, override de registro, ciclo de vida
  de roles, y contrato de las clases del preview.

## Estado

- [x] Plan registrado
- [x] 1 · Tooltips
- [x] 2 · Merge + mudanza de ajustes
- [x] 3 · Acceso sin recarga
- [x] 4 · Registro global
- [x] 5 · Rol `approver` + borrable
- [ ] 6 · Limpieza visual
- [x] Suite completa verde

### Notas de ejecución

- **Un `?>` escrito dentro de un comentario PHP cierra el bloque PHP.** La closure
  `$helpButton` se imprimía como texto en el HTML y el modal reventaba con
  «Value of type null is not callable»; lo detectó `ControllerActionsTest`
  (`GET /forms/{id}/builder`). La nota que explica el tropezo no puede contener
  la secuencia literal.
- `data-bs-title` no admite selector en Bootstrap 5.3 (`_getTitle()` solo mira
  `config.title` o el atributo), así que el texto viaja escapado con
  `data-bs-html="true"` en lugar de apuntar a un elemento oculto.
- Los tooltips se inicializan en `app.js`, no en `builder.js`: `app.js` se carga
  en todas las páginas de `main.php` justo después de `bootstrap`, así que hay un
  solo sitio que inicializar y no hay que acordarse de llamarlo por página.
- En el bloque 1 solo se convierten los grupos que el modal **conserva** (tema y
  comportamiento); los operativos salen en el bloque 2 y el test de completitud
  pasa entonces a abarcar el modal entero.

### Notas del bloque 2

- **`merge()` recorta ANTES de fusionar.** `FormSettings::merge($current, $patch,
  $allowed)` es la puerta: un bloque que no esté en `$allowed` no se guarda
  aunque venga en el POST. Si el recorte fuera posterior al `normalize()`, un
  `theme` colado por la ficha se habría guardado dos veces de-normalizado y el
  test de la allowlist no vería nada.
- **El presente y el vacío no son lo mismo en un formulario HTML.** Una casilla
  sin marcar no llega en el POST, así que `fromDashboardPost()` mira
  `array_key_exists()` y solo traduce lo que viene. Las seis casillas de la ficha
  llevan un `<input type="hidden" name="…" value="0">` delante: sin eso, desmarcar
  «notificar al recibir» no llegaría al servidor y el ajuste seguiría encendido.
- **La `T` del `datetime-local` se traduce en `fromDashboardPost()`, no en
  `normalize()`.** Es un formato de transporte del input, no del esquema; y
  `schedule.open_at/close_at` son muros de reloj en `app.timezone`, no instantes
  (por eso `View::datetimeLocal()` tampoco convierte zonas: solo cambia el
  separador y recorta los segundos).
- **Dos validaciones y no una.** `validate()` mira a propósito el valor CRUDO
  para el color de la tipografía y el modo de acceso, porque `normalize()` ya los
  saneó y comprobarlos después no detectaría nada. En la ficha se valida el
  parche (lo que conserva los valores crudos) **y** el resultado fusionado (lo
  único que ve combinaciones con lo que ya había: cerrar antes de abrir, avisos
  sin destinatario). Los mensajes se deduplican con `array_unique`.
- **Revisión al cambiar lo operativo.** Con revisión activa y formulario
  publicado, tocar acceso/agenda/cupo/avisos/webhook/gracias lo devuelve a la
  cola (`Form::submitForReview()`), igual que ya hacía el builder. Solo lo que
  cambia la OFERTA, no el color ni el idioma: `publishedChangesMatter()`.
- `View::help()` sustituye a la closure por vista. Con dos pantallas de ajustes,
  dos copias del botón divergen; el nombre de `BuilderHelpTooltipTest` se quedó
  atrás y ahora cubre las dos.
- Los hints de acceso y revisión (`stAccessHint`, `stReviewHint`) se mudaron a
  `dashboard.js`, que es quien pinta ahora esos ajustes. Se comprueba también
  que el JS los escriba: un hint presente en el HTML y sin `textContent` es un
  hueco que pasa desapercibido.

### Notas del bloque 3

- **El partial lo pinta el servidor y el JS no sabe qué es una fila.** El POST
  devuelve `html` con el partial `views/dashboard/forms/_access_list.php`
  renderizado, y `dashboard.js` solo hace `box.innerHTML = res.data.html`. La
  alternativa (devolver la lista de correos en JSON y construir la fila en el
  navegador) deja dos definiciones de «qué se ve en la allowlist»: añadir por
  fetch y recargar mostraría cosas distintas, y el fallo solo aparecería en uno
  de los dos caminos.
- **`X-Requested-With` como discriminador, no un `?json=1`.** Es el mismo rótulo
  que ya manda `dashboard.js` en `/access/link`, así que no hay un discriminador
  nuevo que aprender. Y como el `form` conserva `method`/`action`, un POST normal
  de navegador (que no lo lleva) sigue en el camino de siempre: el alta funciona
  sin JavaScript y el fallback del `catch` es un `form.submit()` de verdad.
- **La misma puerta, no una segunda.** El JSON usa los mismos i18n, el mismo
  `FormAccess::addEmail()` y los mismos cortes (modo `allowlist`, email válido,
  no duplicado). Un `fetch` que aceptara lo que el formulario rechaza dejaría el
  camino raro como el único sin probar; `testElCaminoJsonAplicaLasMismasReglas
  QueElFormulario` ata las dos mitades.
- **Los status del JSON no son un invento:** `419` para el CSRF (el mismo código
  que ya usa el resto de la app) y `422` para «no aplica». Lo que cambia no es la
  validación sino que el error se devuelve en el cuerpo: con 200 y texto vacío
  el `fetch` no tenía nada que enseñar y el usuario creería que se guardó.
- El aviso de lista vacía vive en el partial, así que el rerenderizado lo quita
  solo al añadir el primero. El mensaje de éxito se escribe en el `role="alert"`
  de la caja: sin recargar, no hay flash que lo anuncie.

### Notas del bloque 4

- **El fichero se llama `0010_app_settings.php`, no `…_registration`.** La tabla
  es clave-valor genérica a propósito: sembrarla con el valor del `.env`
  congelaría el estado de esta instalación el día de la migración, y con el
  nombre de un ajuste ya no se lee como lo que es. La clave concreta es
  `AppSettings::REGISTRATION`.
- **La fila gana al `.env` en los dos sentidos.** Si solo ganara para
  «encendido», el interruptor no podría cerrar un registro que el `.env` tiene
  abierto, y sería un adorno. Y lo que se guarda es `0`/`1`, **no** el valor
  resuelto: guardar el default convertiría la tabla en una segunda fuente de
  verdad que el despliegue ya no controla.
- **`Config::setForTesting()` no alcanza para cerrar el registro.** Ese override
  vive debajo de `AppSettings`, que gana: un test que pone
  `app.registration_enabled = false` y no neutraliza la fila
  (`AppSettings::setForTesting([])`) está probando el estado de la máquina.
  `ControllerActionsTest` lo hace, y además **guarda y devuelve** la fila de
  `registration_enabled` en `cleanupResiduals()`: `app_settings` es de la
  instalación, y una corrida de tests no puede dejar el registro abierto o
  cerrado por sorpresa.
- **Un valor que no se entiende vale `false`.** La columna es `TEXT` y cualquiera
  puede escribir «quiza» con un `INSERT`; eso no puede ser un 500 en
  `/register`.
- **El interruptor NO se reposiciona al terminar.** Es lo contrario de lo
  habitual y está atado a propósito: si el guardado falla, dejar la casilla como
  el usuario la dejó sería mostrar un estado que no es el real, y el estado que
  importa es «¿puede cualquiera crear su cuenta?». El JS se repinta con el
  `enabled` que devuelve el servidor, también en error.
- La respuesta es JSON y no un 302 porque un `fetch` que recibe un 302 sigue la
  redirección y se baja el HTML entero de la ficha de usuarios: el JS vería un
  `200` con la página dentro y no sabría que el guardado falló.
- `dashboard.js` se carga también en `/admin` (`defer`, como en el resto del
  panel). Todos sus bloques están guardados por `if (!elemento) return`, y el
 delegado de «copiar enlace» vive antes del `return` a propósito: así también
  funciona en las páginas que no tienen `#dashGrid`.
- El permiso es `admin.access`, no `admin.users`: abrir el registro no es tocar
  la cuenta de nadie, y es el mismo permiso que ya exige el middleware `admin`
  de la ruta. `createUser()` sigue SIN mirar el interruptor, que es la
  independencia que evita que cerrar el registro deje la instalación sin forma
  de dar de alta a nadie.
