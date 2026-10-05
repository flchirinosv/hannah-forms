# AGENTS.md — VanillaForms

Formularios self-hosted: **PHP 8.2+ vanilla, sin framework**, MVC propio, MariaDB,
Bootstrap 5. Sin Composer, PHPUnit, dotenv ni bundler: el autoload, el cargador de
`.env`, el router y el runner de tests son implementación propia. **No ejecutes
`composer install` ni busques `composer lint`: no hay `composer.json`,
`package.json`, `Makefile` ni CI.** Es el único control de calidad: `scripts/test.php`.

Todo el código, los comentarios, los strings de UI y esta documentación están en
español. `declare(strict_types=1);` va en `app/`, `config/`, `database/`,
`scripts/` y `tests/`; **las vistas de `views/` no lo llevan** (son fragmentos).
El *por qué* de cada cambio está archivado en `docs/decisiones.md`.

## Comandos

- Suite completa: `php scripts/test.php` → 89 archivos, 996 métodos, ~8 min;
  sale 1 solo si hay `Fallidos` o `Errores`.
- Un test: `php scripts/test.php --filter <substring del NOMBRE DEL MÉTODO>` (con
  espacio, no `--filter=`). Casa por **nombre de método**, no de clase: un filtro
  con el nombre de la clase ejecuta **0 tests y sale verde**. Mira siempre el
  contador `Tests:`.
- Una carpeta: `php scripts/test.php --path tests/unit` (solo directorios).
- BD desde cero: `mysql < database/schema.sql` y luego
  `mysql < database/seed.sql` (crea **roles + matriz de permisos**, nunca usuarios
  ni hashes). BD existente: `php database/migrate.php` (`--status`); **no**
  vuelvas a correr `schema.sql` sobre una BD con datos.
- Primer admin: `ADMIN_PASSWORD=una-clave-larga php scripts/create-admin.php`
  (también `--password-stdin` o prompt sin eco; **`--password=` está rechazado**,
  queda en el historial del shell y en `ps`).
- Usuarios demo: `SEED_DEMO=1 php database/seed.php`; sin el opt-in no hace nada
  y **sale con código 1** a propósito, para que un despliegue se entere.
- Servir: `php -S 127.0.0.1:8080 -t public` (default de `APP_URL` en `.env.example`).
- Mantenimiento: `scripts/clean-rate-limits.php` (`--all` vacía todo y pide
  confirmación, `--yes` la salta) y `scripts/clean-uploads.php` (`--dry-run` solo
  cuenta).
- **node es opcional**: solo lo usan `tests/unit/FillProgressTest.php` y
  `tests/unit/AsyncActionsJsTest.php` para correr los arneses `tests/js/fill-progress.js`
  y `tests/js/async-actions.js`; sin node esos tests avisan y pasan.

Atajos de OpenCode (`.opencode/`, no es código de la app): el skill `vf-docs`
indexa `docs/` (con sus trampas de obsolescencia) y el skill `vf-migraciones` da la
receta de migración idempotente; los commands `/test`, `/migrate` y
`/nueva-migracion` ejecutan lo anterior.

## Arquitectura

- `public/index.php` → `config/bootstrap.php` (carga `.env` con `App\Core\Env`,
  fija **UTC** en PHP, conecta la BD, inicia sesión) → `config/routes.php` →
  controlador. `public/index.php` es el único PHP que ve el web server.
- Autoload PSR-4 propio `App\` → `app/` (`config/autoload.php`): `Controllers` (13),
  `Core` (el middleware vive en `Core/Middleware/Middleware.php`; `app/Middleware/`
  es un directorio **vacío**), `Models` (10), `Services` (17), `lang/{es,en}.php`.
  `views/` = 59 plantillas y partials con `View` (dieciocho con prefijo `_`, sin ruta
  propia: se pintan dentro de otra); `public/assets/js/` = `builder`, `fill`,
  `dashboard`, `analytics`, `app`, `print` + 3 librerías vendorizadas.
- **Los recuentos de este fichero se miden, no se recuerdan**:
  `ls app/Controllers/*.php | wc -l` y `find views -name '*.php' | wc -l`. Al
  añadir una vista, un servicio o un controlador, el número se actualiza en el
  mismo commit: una cifra que ya no cuadra es la forma más barata de que este
  documento deje de servir.
- Config: `.env` con fallbacks en `config/config.php`. `Config::get()` **sí**
  admite notación de punto (`paths.uploads`, `app.locale`): accede así a las
  claves nuevas y verifica que no devuelve el default en silencio.
- Middleware por string en la definición de la ruta: `auth`, `guest`, `admin`,
  `csrf`, `csrf-header`, `post-max` y `editor` (registrado, **aplicado a cero
  rutas**; no lo uses como si hiciera algo).
- Roles: `admin`(1) / `user`(2, default) / `editor`(3) / `approver`(4, el de
  `forms.review` + `dashboard.view`). Los cuatro son del sistema:
  `Role::SYSTEM_ROLES`, una constante y no una columna `is_system` (el conjunto
  depende de lo que la app sepa hacer, y una columna que la migración no puede
  rellenar acaba discrepando del código). `Role::delete()` reductor si es del
  sistema o tiene personas, y devuelve el motivo.

## Convenciones

- Escapar toda salida de vista con `View::escape()`; en JS, para interpolar en
  atributos usa `esc()` (escapa `& < > " '`), nunca `textContent`.
- `app.csp_policy` tiene default **seguro en código** (`script-src 'self'`): **no
  hay `<script>` inline ni `on*=` en ninguna vista** (lo recorre un test);
  `style-src 'unsafe-inline'` se queda a propósito. Para desactivar la CSP hay que
  ponerla vacía a mano.
- `defer` en los scripts no críticos: `dashboard.js`; `sortable` + `builder.js`;
  `chart` + `analytics.js`. **Los dos scripts de una pareja necesitan el mismo
  atributo** o el segundo corre antes que la librería. `bootstrap` + `app.js` y
  `fill.js` van sin `defer` (críticos).
- FontAwesome: solo `css/all.min.css`, `fonts/fa-solid-900.*`,
  `fonts/fa-regular-400.*`. No reintroduzcas CDN ni assets que ese CSS no pida.

## Tests: cómo funciona el runner

- Clases `*Test` (pueden ir en `namespace Tests\Unit`), métodos públicos **no
  estáticos** `test*`, `setUp()`/`tearDown()` opcionales de instancia (el
  `tearDown` corre aunque el test falle). El runner **instancia la clase una vez
  por test**: el estado compartido va en `static`.
- Los asserts (`assertTrue`, `assertEquals`, `assertSame`, `assertNull`,
  `assertNotNull`, `assertArrayHasKey`, `assertFalse`, `fail`) los **define**
  `scripts/test.php`, así que un archivo de test **no corre directo**.
- **53 de los 89 archivos tocan la BD real** (los que llaman
  `Database::getInstance`): necesitan `pdo_mysql` + `mbstring` y MariaDB con el
  esquema de `.env`. Crean datos temporales (`@test.local`, tablas `_db_test*`) y
  se auto-limpian.
- **El runner carga el `.env`**: `scripts/test.php` hace `Env::load()` antes de
  que ningún test toque `Config`, y eso es lo que hace que un `--filter`
  estrecho dé el mismo resultado que la suite completa. No lo quites: sin esa
  línea, `Config::all()` congela `DB_PASSWORD` vacío en la PRIMERA llamada, y
  el resultado depende de qué test corra primero (7 clases lo intentaban
  arreglar con `if (!class_exists(Config::class)) require bootstrap.php`, un
  patrón roto porque `class_exists` dispara el autoload y devuelve **siempre
  `true`**: el `require` no corría nunca y el workaround se llevó por delante).
  Los ~30 `loadEnv()` que quedaban se borraron; 16 de ellos además registraban
  un `register_shutdown_function(cleanupResiduals)` y ese hook **sigue en pie**
  con otro nombre, `ensureCleanupHook()`, porque su guard evita que se registre
  una vez por test. `EnvTest` es la excepción: prueba el cargador en sí, con un
  `.env` temporal.

## Invariantes que fallan en silencio

**Fill y thanks**

- El POST `/f/{slug}` hace **PRG** a `GET /f/{slug}/thanks`; solo salta a una URL
  externa si el host pasa `RedirectGuard`.
- El thanks se identifica con un **token de capacidad `?t=`**
  (`submissions.public_token`), **nunca con `submissions.id`**: es público y sin
  sesión. De cara al thanks, `Submission::findWithToken(string)`;
  `findWithAnswers(int)` es solo del dashboard.
- Salto de página: si la condición del campo `page` es verdadera se omite el
  bloque entero, y sus campos ni se validan ni se persisten ni son fuente aunque el
  cliente mande valores; una regla de página que referencie a una pregunta de su
  propio bloque es un ciclo que `validateStructure` rechaza al guardar.
- La **lógica de grupo es AND** entre la regla de la sección y la de su hija.
- `initialVisibility()` resuelve la visibilidad en el render con las mismas reglas
  del submit: si añades ocultado en el servidor, asegúrate de que `fill.js` pueda
  levantarlo (`plainFieldCards`).
- El **builder y el fill comparten CSS, no clases**: `.app-section-children` (lienzo)
  y `.vf-section-children` (página pública) existen por la misma razón, así que la
  invariant se ata a **las dos** en `BuilderPreviewCssTest`; corregir una y dejar
  la otra deja el formulario igual que estaba. Lo que **no** se comparte es el
  fondo: solo el lienzo lo lleva (es la zona de trabajo y el tinte dice dónde acaba
  la sección), porque en el formulario publicado la sección ya trae encabezado y
  línea y el tinte encima lo hacía parecer partido en dos. Y un `<span>` con `width`/`height`
  no tiene caja: `.scale-btn` necesita `display` (el del NPS nunca lo tuvo, así
  que sus 40px no se aplicaban y salía el número suelto), que es lo que separa
  un botón de un dígito con un borde alrededor.
- **`fields.show_header` dice SI se pinta la cabecera, nunca QUÉ dice**: el título
  y la descripción son contenido y los pone el editor, así que un salto de página
  sin título ni descripción **no pinta caja** aunque el interruptor esté
  encendido (una caja con el icono y el cuerpo vacío no dice de qué paso se
  trata, cuando el pie ya lleva «Paso N de M» y el `aria-label` del bloque lo
  nombra igual). Y el preview del builder decide **igual que el fill** —mismos tres
  predicados y el mismo `trim()`—, o se ve el icono en el constructor y nada en
  el publicado: dos pantallas con markup idéntico donde nada delata la
  divergencia. Cuando no hay contenido, el panel lo **explica** con
  `pageHeaderHint` (patrón `updateAdvanceHint()`): un ajuste que parece roto es
  peor que un ajuste que avisa de cuándo es inaplicable.
- **`fields.show_header` es del SALTO DE PÁGINA y nada más**: el servidor lo
  fuerza a `true` en cualquier otro tipo, y apagado apaga `.vf-page-head` (icono,
  título y descripción) **sin** quitar el paso: bloque, `aria-label` del paso,
  progreso y la insignia del pie («Paso 2 de 3») siguen, porque quitar la
  cabecera no es quitar el paso. En el builder sale el interruptor solo en páginas
  y secciones, pero en secciones el servidor también lo fuerza: no guardan
  respuestas.
- **El texto de las reglas es del editor, nunca del visitante**: «Se muestra si: …»
  solo se pinta en `builder.js` (`data-vf-logic` se queda en el fill, que es lo que
  la ejecuta). Si vuelve al fill, hay que devolver también las nueve claves que se
  borraron (cuatro `ui.*` y cinco `op.*`, en `es.php` **y** `en.php`).
- Los **grupes plegables** son de Bootstrap, no propios: `type="button"` (sin él el
  título envía el formulario), `aria-expanded`/`aria-controls` al `id` real del
  panel, y todos `collapse show` en el servidor para que sin JS se vea entero. El
  estado se guarda en `localStorage['vf:grp:<clave>']`, **por pantalla**, y con un
  `.alert-danger.app-alert` se abren todos: el aviso del guardado fallido está
  arriba del formulario.
- **Un panel llega abierto del servidor aunque su valor por defecto sea cerrado, y no
  es un descuido.** `dashboard.js` recorre **todos** los contenedores
  (`[data-vf-group-key]`, con `data-vf-form-id` como alternativa) y
  `data-vf-group-default="closed"` es lo que dice «plégalo al cargar»; su
  **ausencia** significa «abierto», y por eso los siete grupos de la ficha **no**
  llevan el atributo. Hoy hay dos: la ficha (`data-vf-form-id`, clave = id del
  formulario, que no se renombra porque sería perder el estado guardado de todos) y
  el alta de `/admin` (`data-vf-group-key="admin-usuarios"`, cerrada). Que el panel
  llegue `show` es lo que permite usarlo sin JS, y en el alta no es opcional: con
  el registro público cerrado es la **única** forma de crear un `user` o un
  `editor`.
- **El `hidden.bs.collapse` de arranque no se guarda.** El `hide()`/`show()` inicial
  dispara el mismo evento que un clic, así que `dashboard.js` apunta lo que él
  decide en `decididos` y descarta esa primera escritura: si se guardara, la primera
  visita cerraría el panel para siempre y un cambio futuro del valor por defecto no
  llegaría a nadie.
- **`/admin` reparte el alta y el interruptor en `col-lg-8` / `col-lg-4`**, y la
  columna del interruptor **no se emite vacía** si falta `admin.access`: el alta se
  queda en 8 columnas de todos modos y la retícula no da un salto al desaparecer.
  Los cinco campos van en 3+2, y no cuatro en fila, porque dentro de `col-lg-8` un
  `col-md-3` deja el campo de contraseña tan estrecho que se corta el marcador de
  posición.
- **`fields.label` es contenido, no un rótulo de editor**: un salto de página nace
  con `Página N` —el número de páginas que ya hay más uno, no la posición en el
  lienzo, que cuenta secciones y el bloque implícito—, igual que una sección nace
  con `Nueva sección`. Es un punto de partida para sobrescribir, y por eso lo que
  NO se hace es lo contrario: el **fill** no vuelve a fabricarlo cuando el editor
  borra el título (`testElSaltoDePaginaSinEtiquetaNoInventaTitulo` y la clave de
  i18n `ui.page_title`, borrada), porque un relleno que el servidor pone no se
  puede quitar. La migración **0012** sigue limpiando el `Página N` que las
  instalaciones anteriores ya tenían guardado, con un patrón estrecho que respeta
  lo escrito a mano y nunca toca la descripción; su alcance son esas filas, no las
  nuevas. El nombre del tipo lo pone el editor al pintar la tarjeta cuando no hay
  etiqueta, y el divisor del preview.
- Honeypot `_vf_hp`: se chequea **antes** del rate limit y **finge éxito sin
  persistir**. Turnstile va tras el honeypot y antes del rate limit; sin **las
  dos** claves de instancia el formulario **no se sirve** (403).
- La randomización aplica **solo en GET** (`show`/`embed`): el re-render de errores
  del POST no baraja.
- Toda closure de `views/fill/show.php` que lea una variable de la vista la
  declara en su `use`; si no, lee una indefinida y falla sin aviso.

**Revisión de publicación (F3)**

- `forms.status` tiene **cuatro** valores: `draft`, `in_review`, `published`,
  `closed`. `in_review` **no** se sirve: el GET pinta `views/fill/in_review.php`
  (página informativa, no 404, a propósito) y el POST corta con 403 como en un
  `draft`.
- La revisión es **opt-in por formulario** (`settings.review.enabled`, default
  `false`) y aprobar exige el **permiso `forms.review`**, nunca `canEdit`: por eso
  `ReviewController` es un controlador aparte de `FormController` (un `canEdit()`
  leído como autorización para aprobar es exactamente el error a evitar).
- El **estado solo lo mueven las tres acciones** de `ReviewController`
  (`sendReview`, `approve`, `returnDraft`) y cada una comprueba su estado de
  origen. El campo `status` del payload del builder **no publica**: con revisión
  activa se ignora, y **editar un formulario ya aprobado lo devuelve a la cola**
  borrando la aprobación anterior (`Form::submitForReview()` va dentro de la
  transacción del builder). Ese gate vive en `BuilderController::saveStructure()`;
  añadir otra vía que escriba `forms.status` es saltarse la revisión.
- Aprobar un formulario propio está permitido y el log lo marca con `propio`: en
  una instalación de un solo usuario no hay a quién pedirle aprobación, y
  prohibirlo dejaría esa instalación sin poder publicar nunca.
- `User::reviewers()` (rol propio **+** roles de los grupos, en una sola consulta
  con dos `EXISTS`, filtrando `is_active = 1`) alimenta el aviso; el aviso en el
  panel es siempre y el correo es opt-in (`settings.review.notify_email`) y apunta
  al panel, nunca al enlace público. En tests, sus destinatarios se filtran por
  `@test.local` porque también salen los usuarios reales de la instancia.
- **`Form::pendingReviews()` es global**: la bandeja no es «la de este test», es
  la de la instalación, y en una instancia en uso hay formularios de verdad
  esperando. Un test que afirme que la cola **está vacía**, o que sus dos
  fixtures ocupan las posiciones 0 y 1, pasa solo en una base recién sembrada
  y se rompe en cuanto alguien usa la app (`FormReviewTest` falló así con un
  formulario real de `josangeles@gmail.com` en cola). Hay que **filtrar a los
  ids propios** antes de comparar, igual que con los destinatarios y los
  `rate_limits`: la propiedad a comprobar es la ordenación o la ausencia de
  *tu* fila, no el contenido de la tabla.

**Acceso por email (F4)**

- `settings.access.mode` tiene **cuatro** valores: `public` (default, para no
  romper lo existente), `email` (cualquiera que reciba el enlace), `allowlist`
  (solo emails de `form_access_emails`) y `roster` (solo quien tiene código
  personal; su puerta está en la sección del listado). La allowlist se edita **en
  la ficha del formulario**, no en el builder.
- El gate vive en `FillController::resolveAccess()` y se aplica a **GET, embed y
  POST**: el POST sin acceso corta con 403 **antes** del `post-max` y del `csrf`,
  que si no responderían «algo va mal» a un visitante sin ningún problema.
- El **`form_id` va en el WHERE** de `FormAccess::findValidToken()`, no se filtra
  después: es la mitad de la autorización. Un token de otro formulario no existe.
- El permiso de sesión (`$_SESSION['vf_form_access']`) guarda el **id de la fila
  del token**, no el token ni solo la fecha, y `sessionAccessValid()` revalida
  contra la BD en cada request. Sin esa revalidación, revocar un enlace o quitar
  un email de la lista no cerraba nada a quien ya había pasado por él.
- `removeEmail()` borra la fila y revoca sus tokens en una **transacción**;
  `issue()` invalida el token anterior del mismo par (form, email).
- `requestAccess()` responde **igual** exista o no el email en la lista (mismo
  flash, mismo destino, un único `uniformAnswer()`) y **registra el intento** en
  los dos scopes de rate limit: `isAllowed()` lee, `recordHit()` cuenta. Un test
  que separe «correo enviado» de «correo no enviado» por el número de mensajes
  rompe esta uniformidad.
- F4 **no añade permiso**: la gestión de la lista usa `Access::canEdit()`.
- En un test, `requestAccess()` exige limpiar `rate_limits` para los scopes
  `form_access` y `form_access_email`: son globales y por IP, y todos los tests
  salen de `127.0.0.1`, así que el cupo de los primeros agota el de los demás.

**PII (F1)**

- `fields.is_pii` (columna, migración **0006**) marca los campos personales; lo
  pone el dueño en el builder y `BuilderController` lo fuerza a `false` en
  secciones y páginas (no guardan respuestas). Sin marca no hay enmascarado
  posible: un `text` puede ser "¿Qué opinas?" o "Nombre de tu jefe".
- El builder **avisa, no marca**: cuando la etiqueta parece un dato personal
  muestra una sugerencia (con patrón propio, sin auto-marcado) y el marcado solo
  se produce por el botón «Sí, es dato personal» (que pasa por `applySettings()`).
  El descarte es **por etiqueta** (no booleano eterno), para que al reescribirla
  vuelva a preguntar. La sugerencia no aparece en secciones/páginas, porque el
  control está oculto.
- **`responses.pii` es la única puerta**, y `App\Services\Pii` el único sitio que
  decide el valor que sale. Sin el permiso: IP a `192.168.1.x`, user-agent
  `[oculto]`, campos marcados `[oculto]` (email `j***@e***.com`, teléfono solo
  prefijo). `piiFieldTypes()` NO comprueba el permiso (extrae la lista);
  `maybeMaskAnswers()`/`maybeMaskSubmission()` sí.
- **Los canales de salida se enmascaran**: detalle, listado, `exportJson`,
  `exportCsv` y **la analítica** (sus gráficos y su tabla de detalle). Enmascarar
  en la vista y no en la exportación deja un canal limpio. En el CSV y en
  `analytics/_rows` se enmascara **dentro** del bucle de campos, después de
  decodificar: `[oculto]` empieza por `[` como un JSON y el bloque lo
  reinterpretaría.
- **En la analítica se enmascara ANTES de agregar, no después**, porque la
  pantalla existe para agrupar: `Answer::valueCountsInForm()` agrupa por
  `(field_id, value)`, así que cuando llega a PHP los valores crudos ya son
  distintos entre sí. Enmascarar el resultado daría 30 barras `[oculto]` de un 1
  cada una, y las etiquetas de los gráficos viajan en `data-chart`
  (`views/analytics/show.php:6`). Lo hace `FieldAggregator::enmascararValores()`,
  que además **funde** las que quedan con el mismo marcador sumando su `c`. Cada
  agregado sale honesto después: `conteoSimple` da una barra `[oculto]` con el
  total; `numerico`/`nps` filtran lo no numérico con una regex y quedan a `null`,
  que es mejor que un promedio inventado; `matriz`/`ranking` buscan la etiqueta en
  las opciones del campo y la fila desaparece.
- **El permiso se decide en el CONTROLADOR, no en la vista**: los controladores
  pasan `Pii::canView() ? [] : Pii::piiFieldTypes($fields)`, y una lista vacía es
  la forma de decir «este usuario puede verlos». Mascarar dentro del partial
  dejaba la analítica siempre tapada, **también para el admin**, que es lo
  contrario de lo que hace el listado de respuestas.
- **Asimetría conocida, no la «arregles» sin pensar:** `exportCsv` enmascara
  **siempre**, porque su puerta es `forms.export_own`, no `responses.pii`. O sea
  que un admin ve la PII en la analítica y `[oculto]` en el CSV del mismo
  formulario. Está en el lado estricto (nadie ve de más) y es lo que testea
  `PiiMaskingTest`, así que **no lo cambies por simetría sin decidirlo**: abrirlo
  es aflojar una exportación.

- El bloqueo de **adjuntos** va en `AttachmentController::download` (403 si el
  campo es PII sin el permiso), no en la vista: `/attachments/{id}` es
  adivinable por el id correlativo de `answers`.

**Listado de participantes (roster)**

- **`roster` ES una puerta, y su `<option>` en el selector todavía NO**: la
  fase 4 metió el gate y la fase 6 el selector. Un formulario en `roster` se
  alcanza hoy solo si alguien edita sus ajustes a mano.
- **`roster` fuera de `FormSettings::RESTRICTED_MODES` deja el formulario
  ABIERTO**, sin error en el log ni en la respuesta: `isRestricted()` es lo que
  decide que el GET pida identificación y que el POST corte. La lista y el gate
  van **siempre en el mismo commit**, y `RosterDoorTest` mira la lista.
- **La credencial entra por `?t=`, el mismo hueco que el token de F4**, y por eso
  el `action` del POST no se tocó. En `resolveAccess()` se busca **primero** el
  token: en un formulario de listado un `?t=` que casa con `form_access_tokens`
  sigue siendo ese token, y los dos tipos de fila no se buscan en la misma tabla.
- **El código no caduca** (vive en la URL, en cadenas de reenvío y correos) y lo
  que lo cierra es que la persona **siga en la lista**. En sesión eso es
  `['id' => <participante>, 'exp' => 0, 'kind' => 'participant']`: el `exp = 0`
  significa «sin caducidad» y se comprueba **antes** que la caducidad, porque
  comparar `0 <= time()` como fecha tira la puerta en cada petición. La
  revalidación es `FormParticipant::existeEnFormulario()`, y su consecuencia es
  **borrar a alguien de la lista le cierra la puerta en la petición siguiente**.
  Los permisos de F4 no llevan `kind` y siguen siendo tokens.
- **`POST /f/{slug}/code` es un PRG** y un rate limit con dos claves (formulario+IP
  y código) **antes de buscar**, contando también los fallidos: si solo sumaran
  los válidos, enumerar códigos sería gratis. La respuesta es **uniforme**
  (inexistente, ajeno y mal tecleo dicen lo mismo) y da **404** si el formulario
  no es de lista. En embed **no hay variante con `/embed`**: el canje redirige el
  frame entero.
- **Responder marca `responded_at` en la MISMA transacción** que la submission, y
  el `UPDATE` es idempotente con estado objetivo (`WHERE responded_at IS NULL`):
  responder dos veces no mueve la primera fecha **pero sí guarda las dos
  respuestas** (`roster` no es «una respuesta por persona»). La atribución viaja en
  el quinto argumento de `Submission::create()` y es **NULL** en los otros tres
  modos.
- **Tres devoluciones que se parecen y no son lo mismo**, y confundirlas hace que
  un test pase probando otra cosa: `Form::create()` devuelve **un `int`** (leer
  `$form['id']` de un entero da `null` y la llamada se va a `/forms/0`, que
  responde 404), `Submission::create()` también devuelve `int`, y
  `FormParticipant::create()` devuelve el resultado **`['ok','id','motivo']`**, no
  la fila. Para el `code` hay que releer con `find()`.
- **El catálogo y los valores son dos methods distintos**: `catalogFor($formId)`
  devuelve las COLUMNAS (`key_name`, `label`, `position`) y
  `attributesOf($participantId)` los valores de UNA persona. Pasar el id del
  formulario al segundo devuelve `[]` en silencio, y el test que "comprueba que
  las columnas siguen ahí" se pasa mirando las celdas de nadie.
- **Vaciar la lista no borra el catálogo**: `truncate()` quita filas, y las
  columnas son el formato del próximo archivo. Si se fueran con la gente,
  vaciar destruiría el formato que el editor ya tenía.
- **El `form_id` hay que compararlo en el controlador**, en `update()` y
  `destroy()`: los dos reciben un `participant_id` del POST y sin
  `(int) $fila['form_id'] !== $id` el dueño de dos formularios borra o edita en
  la lista que no es. Es la mitad de la autorización, como el `form_id` en el
  WHERE de `FormAccess::findValidToken()`.
- **El cliente NO elige el tamaño de página**: `index()` usa `self::POR_PAGINA` y
  no lee `per_page` del query. Aceptarlo sería un `per_page=100000` que se come la
  tabla entera en memoria y descuadra el `total` de lo que se pinta. Lo fija
  `testElListadoBuscaYPagina`, que crea 51 filas a propósito.
- El orden del listado es por identificador **como texto** (es lo que se guarda y
  lo que se busca), así que `V-51` va antes que `V-6`. Un test que cria `V-1`…
  `V-51` y espera `V-51` el último está probando un orden natural que no existe.
- El **mensaje de duplicado lleva el identificador** (`{id}`): «ya hay alguien con
  ese identificador» obliga a abrir la lista a buscar a quién se refiere, y quien
  escribe ya lo tiene en el campo. Sale del POST, no de la fila.
- Importar y vaciar son **acciones distintas**: «reemplazar con un archivo vacío»
  se rechaza, y vaciar tiene que poder existir igual aunque el importador no lo
  acepte. `truncate` por eso no mira el `modo` del POST.
- **Hay dos tamaños de página y no se deducen uno del otro**:
  `ParticipantController::index()` (la API JSON) usa 50 y
  `RosterPanel::POR_PAGINA` (la pantalla) usa 25. El 50 es un contrato con su test
  atado al número; el 25 es una decisión de pantalla, porque cada fila lleva su
  `<details>` de edición con un input por atributo y a 50 el botón de «Quitar» deja
  de notarse. Si se cambian, se cambian los dos sitios y sus tests.

**El filtro de la analítica (fase 7)**

- **El catálogo se pide SIEMPRE, filtre quien filtre.** `$catalogo` alimenta el
  desplegable del filtro y no hay ningún botón ni enlace que ponga `atr_key` en la
  URL: pedirlo solo con filtro puesto deja la pantalla **sin forma de tener el
  primero** y el filtro nace muerto sin un solo error.
- **Elegir el atributo y filtrar por un valor son dos pasos, y son tres
  variables**: `$catalogo` (siempre), `$atrClave` (la fila del catálogo de la clave
  **pedida**, aunque no haya valor) y `$atr` (el filtro **resuelto**, `null` sin
  valor). Sin `$atrClave`, elegir «Sede» y guardar devuelve la pantalla a «Todo el
  listado» y el filtro se deselcciona solo. Lo que **filtra** es solo `$atr`: las
  columnas del detalle van con `$atr`, no con `$atrClave`.
- **La lista blanca es el catálogo, y por eso la identidad no se puede filtrar**:
  `cedula`, `nombre` y `correo` no pueden estar en `form_participant_fields` porque
  el importador trata esas cabeceras como columnas fijas
  (`FormParticipant::COLUMNA_IDENTIFICADOR`), así que `atr_key=cedula` en la URL
  devuelve `null` y sale la pantalla entera. En sentido contrario, un **valor** que
  no existe **no** se descarta: sale la pantalla vacía con el valor marcado en su
  desplegable, que es lo que se está mirando.
- **Un filtro, cuatro caminos**: detalle, agregados (`Answer`), gráfico por día y
  CSV. El CSV es una decisión y no una consecuencia, y el motivo es que un botón que
  descarga un archivo **distinto** de lo que se ve solo se descubre abriendo el
  archivo. La columna del CSV **no** se añade: el filtro es de las respuestas que
  entran, no una columna más.
- **`Answer` ya no mezcla `?` con `:nombrado`**: con
  `ATTR_EMULATE_PREPARES = false` PDO no los mezcla en la misma sentencia (HY093),
  así que `marcadoresDeCampos()` genera los `:val_f0, :val_f1…` y `queryField()`
  usa `:field_id`.
- El `total` del envelope del CSV es `countByFiltered()` con el mismo filtro: si
  solo se filtrara el `foreach`, el archivo tendría una fila y su envoltorio
  anunciaría cinco.

**La comparación por atributo (fase 8)**

- Solo se agrupa con **clave elegida y sin valor filtrado**: comparar los grupos de un
  atributo mientras se mira uno de ellos da una columna con todos los datos y el resto
  en cero. Quien quiere ese detalle lo tiene en la tabla de abajo, que ya trae los
  atributos de cada respuesta.
- **El techo son tres números** (`FieldAggregator::GRUPOS_MAX`/`FILAS_MAX`/
  `TOP_VALORES`) y las filas se eligen **antes** de consultar: si el tope se aplicara
  después, las dos consultas fijas serían de 200 ids. Un `.xlsx` con 800 sedes son 9
  columnas, no 800.
- **El «resto» es una resta, no un centinela**: `total − lo que ocupa cada columna`, y
  por eso `valoresConConteo()` no trae `LIMIT` (el de `valuesOf()` son 200 valores del
  **desplegable**, y se topa en PHP sobre el mismo array). En SQL el resto es
  `CASE WHEN pa.value IN (…) THEN pa.value ELSE NULL END` y no `__resto__`: un valor
  puede ser literalmente eso. «Resto» **no se pinta si no queda nadie** (columna en
  cero = decir que hay gente sin el atributo).
- **El `total` de una celda viene de `SUM() OVER`, no de sumar lo que sobrevivió al
  tope**: si no, «2 de 5» saldría 40 % donde la verdad es «2 de 12» = 17 %, y dos
  grupos con la misma proporción se verían distintos. El `WHERE rn <= N` va **fuera**
  del nivel de la ventana.
- **El promedio sale de `SUM/COUNT` en SQL**, nunca de la media de las medias: con un
  grupo de 2 y otro de 200 sale otro número, y plausible.
- **`checkbox`, `matrix` y `ranking` no se comparan**: su valor guardado es un JSON con
  la respuesta entera, así que «el valor más repetido» sería la combinación o la
  respuesta literal. No es un olvido; la métrica no es la que se cree.
- Las PII **no se consultan** si quien mira no tiene `responses.pii` (misma lista que
  decide el resto de la pantalla): si se consultaran, esta tabla sería el canal limpio
  de las otras tres.
- **Los dos enlaces de CSV usan el mismo `$qsAnalytics`**. El segundo se armaba a mano
  con `from`/`to` y se olvidaba del filtro de atributo (fase 7), que solo se nota al
  abrir el archivo; lo vigila
  `RosterAnalyticsGroupTest::testLosDosEnlacesDeCsvLlevanElFiltroDeAtributo`.

**Quién respondió (fase 9)**

- La atribución se ve en el **listado de respuestas** y en el **detalle**, detrás de
  `Pii::canView()` (que es `responses.pii`). No de `responses.view_own`: ese decide el
  alcance y no la lectura en claro, y con él la identidad le tocaría a cualquiera que
  pueda abrir el formulario.
- **Sin permiso no hay columna, y no una columna de `[oculto]`**: en un formulario que
  no es de listado sería de «—» siempre, y una celda enmascarada en cada fila no dice
  nada que el aviso no diga. Lo que sí hay es el **aviso** que explica la ausencia, y
  dice dónde está la información (la ficha marca quién respondió). Una columna que
  desaparece en un formulario con lista parece un fallo y no lo es.
- La columna se decide por el **formulario y el permiso**, nunca por si esa página casó
  con alguien: si dependiera de la página, al paginar una lista de mil aparecería y
  desaparecería por casualidad.
- `identidadesDe($formId, $ids)` lleva el `form_id` en el WHERE aunque los ids salgan
  de `submissions` de un solo formulario: es la mitad de la autorización, como en
  `FormAccess::findValidToken()`. Los `null` se filtran antes de consultar, porque
  `intval(null)` es 0 y un `IN (0)` hace trabajo de más. El mapa va **indexado por
  `participant_id`**, así que se busca `[$pid]`, no `[0]`.
- `identidadDe()` devuelve **datos, no markup**, y con identificador `correo` el
  `correo` sale vacío: el `identifier_value` ya es esa dirección y repetirla debajo
  sería la misma dos veces en la misma celda.
- El `<td>` de la fila va **dentro del mismo `if` que el `<th>`** del encabezado, y el
  test cuenta celdas por `<tr data-vf-row>` (5 con columna, 4 sin ella). Un encabezado
  con la columna y filas sin ella se ve «casi bien» y no da ningún error: es el mismo
  fallo que el `</div>` perdido del modal en el builder.
- **Los exports NO cambian**: ni nombre, ni cédula, ni `participant_id` (que tampoco
  estaba). Un archivo lo descarga cualquiera con el botón y el CSV enmascara siempre
  porque su puerta es `forms.export_own`. No lo cambies por simetría con la pantalla:
  en un archivo, «lo que se ve en pantalla» va en la dirección de no añadir.

**Elegir con qué se identifica (fase 6)**

- `roster` es la **cuarta y última** `<option>` de `#access_mode`: el orden de la lista
  es el mensaje (cada vez más cerrado) y el test lo compara entero, no «que exista».
- El estado inicial del segundo desplegable lo pone el **servidor**: `#accessIdentifierBox`
  y `#access_identifier` vienen sin `hidden` en `roster` y con él en los otros tres, y
  `dashboard.js` solo lo mantiene al día (`updateIdentifierBox()`) al cambiar de modo
  **sin guardar**. `hidden` y `disabled` van **juntos**: un `<select>` escondido sigue
  mandando su valor, y sin `disabled` un formulario en «Público» guardaría «cédula»
  (la primera opción) en cada guardado de cualquier ajuste.
- Sin JS el camino también existe, con **un guardado de más**: se elige `roster`, se
  guarda y al reabrir la ficha el selector ya está a la vista. No lo escribas como «el
  selector se ve siempre» en un comentario: es falso.
- **Cambiar el identificador es la operación que puede dejar entrar a nadie.** Por eso
  van las dos mitades: `planDeReidentificacion()` (sin escribir nada) y, solo con el
  plan limpio, `FormController::updateSettings()` mete ajustes y `reidentificar()` en
  **una transacción**.
- **El plan cuenta en SQL, y no es una decisión de gusto**: `UNIQUE (form_id,
  identifier_value)` es `utf8mb4_unicode_ci`, así que `V-1` y `v-1` son **la misma
  clave**. Agrupar en PHP con un array asociativo diría «no hay repetidos» y el
  `UPDATE` se comería un 1062 con un error de servidor en vez de un mensaje que dice
  qué corregir. Al revés que `normalizar()`: allí lo que decide qué es el mismo dato es
  PHP; aquí es el índice. La prueba que lo ata monta las dos filas con identificadores
  **distintos** (una cédula y un indicador) y luego cambia a cédula: es la única forma
  de que dos valores que solo colisionan al cambiar lleguen a existir.
- `a_cambiar` excluye las filas sin el dato nuevo (`TRIM(...) <> ''`): no se van a
  reescribir, y `sin_identidad` las corta antes.
- `reidentificar()` mueve **solo** `identifier_value`: `code` es la credencial que ya
  se repartió, y quien entra por un enlace viejo tiene que seguir entrando.
- `settings.access.identifier` no viaja en el POST mientras el `<select>` venga
  `disabled`, y `fromDashboardPost()` lo respeta («si no viene, no lo toques»): un POST
  sin el campo tiene que dejar guardado el que hubiera.
- **Un test que quita comentarios de un fichero de JS con un regex: que el regex sea
  `/*`.** `[/][/*]` es una clase de caracteres —«una barra o un asterisco»—, y el
  recorte empezaba en la barra de `'use strict'` y llegaba al primer cierre de verdad:
  tapaba por casualidad el comentario que el test vigilaba. Cuando se añadió código
  nuevo dejó de taparlo y el test falló sin que el código vigilado cambiara
  (`FormAccessTest`).

**El panel de la ficha (fase 5)**

- `roster` tiene su propia rama en `_access_card.php` y su propio partial:
  meterlo en el `else` habría producido un alta de correo por persona. Y el `else`
  sigue siendo exactamente la allowlist de F4 (`testLaRamaDeLaAllowlistNoSeHaTocado`).
- **El panel son CINCO huecos y ninguno está en un `<form>`**: `rosterStats`,
  `rosterList`, `rosterCatalog`, `rosterAddForm` y `rosterImportReport`. Los tres
  markup salen de `RosterPanel::html()`, que pinta **los mismos partials** que la
  ficha: con dos copias, la lista repuesta tras un borrado y la que se vería
  recargando divergirían sin que nada fallara.
- **Cada acción declara qué huecos cambian** (`$cambia` en `ok()`): alta/edición/
  borrado/vaciar → lista+números; baja de atributo → lista+catalogo+alta;
  importación → los cinco. Reponerlos todos hace un `catalogFor` por alta.
- **El alta NO repone `rosterAddForm`**: el repintado borraría lo que el dueño
  tiene a medio escribir y el foco que devuelve `data-vf-clear` apuntaría a un nodo
  que ya no está.
- **Las pestañas NO son el `tab` de Bootstrap**: `.tab-pane` sin `.active` está
  oculto por CSS, así que sin JS desaparecería un panel entero. El servidor pinta
  las dos zonas SIN `hidden` y `dashboard.js` lo pone. Si el servidor empezara a
  pintar `hidden`, el segundo panel se pierde y ningún test de PHP lo nota
  (`testLasDosZonasSePintanLasDosYNingunaVieneEscondida`).
- **La búsqueda y el orden viajan en la URL** (`/forms/{id}?q=…&page=…`), y las
  acciones por fetch los reponen en `rosterQ`/`rosterPage`/`rosterSort`. Sin ellos,
  borrar a alguien salta a la primera página y a ordenar por cédula, y el cambio
  parece un fallo de la operación.
- **La búsqueda es un `LIKE '%…%'`**, así que `V-1` también casa con `V-10`. Un test
  que filtre esperando una fila tiene que usar cédulas de ancho fijo (`V-01`).
- **El informe de la importación llega por los DOS caminos** y son decisiones
  distintas: con JS va en `html.rosterImportReport` —**también con `ok:false`**, que
  es cuando más falta hace, y `app.js` lo aplica antes de pintar el aviso—; sin JS
  va en `Session::flashValue('roster_report')`, que consume `FormController::show()`
  con `takeValue()` (un solo uso). Solo se guarda en sesión en el camino nativo: si
  se guardara también con JS, el informe reaparecería en la siguiente carga de la
  ficha.
- **Un `$error` con cero omitidas NO se pinta como informe** (`informe()` devuelve
  `null`): un `.xlsx` ilegible no ha guardado «las 0 filas», y el partial con
  `omitidos = 0` diría exactamente eso al lado de un mensaje que sí dice la verdad.

- **`Database::transaccionar()` confirma cuando la closure DEVUELVE**, así que un
  `return` dentro de la closure es un `return` normal y deja confirmado todo lo que ya
  se había escrito. `ParticipantImporter::asegurarAtributos()` lanza por eso en vez de
  devolver `null`: devolviendo, una etiqueta de 250 caracteres (que no cabe en `label`)
  confirmaba las columnas **anteriores** del catálogo y el dueño veía el mensaje
  correcto con un catálogo inflado que no había pedido. Y el `catch` compara el mensaje
  y **re-lanza** cualquier otro `RuntimeException`, para que un fallo real de escritura
  no acabe contestando «renombra la columna». Lo vigila
  `testUnaEtiquetaImposibleNoDejaLasColumnasAnterioresEnElCatalogo`.
- **El informe de la importación está topado (`MAX_PROBLEMAS` = 200) y el contador
  `omitidos` NO**: se cuentan por separado, porque `count($problemas)` daría «200
  omitidos» de un archivo con 260 y quien lee el informe no puede saber si se le quedó
  gente fuera a propósito. La lista topeada es lo cómodo; el número es lo honesto.
- **`atributos_nuevos` se deduplica por etiqueta NORMALIZADA**, no por cabecera cruda:
  `Sede Norte` y `Sede  Norte` son la misma columna del catálogo (el `UNIQUE (form_id,
  label)` las pega) y el lector solo hace `trim()`, así que el espacio interior llega
  intacto. Anunciar dos atributos nuevos donde hay uno hace que el informe mienta.

**Adjuntos**

- `Uploader` es el **único** que escribe o borra adjuntos, y viven en
  `paths.uploads` (`storage/uploads/`), **nunca** dentro de `public/`.
- **Purga de dos fases y el orden importa**: `pendingFor{Submission,Form,Field}()`
  **antes** del `DELETE` (el `ON DELETE CASCADE` se lleva los `answers` y el binario
  queda huérfano) y `purgePaths($rutas)` **después** del `commit()` (purgar dentro
  de la transacción deja una fila `answers` viva apuntando a un archivo
  inexistente). `purgeSubmission/Form/Field` son envoltorios de una fase: no los
  uses después de un DELETE.
- MIME siempre con `finfo` (nunca `$_FILES['type']`); la extensión sale del MIME y
  `DENY_EXT` se rechaza antes de mirar nada. Fuera del dashboard, toda metadata
  pasa por `Uploader::displayValue()`.
- Todo POST del fill con archivo lleva **`post-max` antes de `csrf`**: si el cuerpo
  pasa `post_max_size`, PHP vacía `$_POST`/`$_FILES` y sin el guard sale un "CSRF
  inválido" engañoso (responde 413).

**Webhook**

- El POST se hace a mano con `stream_socket_client` **aunque ext-curl esté
  disponible**: es lo que permite fijar la conexión a la IP ya validada (TOCTOU)
  manteniendo `Host` y SNI como el **nombre** (`peer_name`) para poder verificar el
  TLS. Con curl, `CURLOPT_RESOLVE` es el equivalente.
- `setHandler()` está **detrás** de la validación, así que un test con URL de
  loopback ya no se dispara: usa `setResolver()` para fijar la resolución sin
  depender del DNS real. `isBlockedIp()` compara bytes empaquetados, no texto.
- Red local propia: hacen falta **dos** opt-ins, `WEBHOOK_ALLOW_PRIVATE_HOSTS`
  (servidor) **Y** `webhook.allow_private_hosts` (settings del formulario).

**Estructura de las vistas**

- El HTML que emite una vista es **estructura**, y nada más lo comprueba: un
  `</div>` de más o de menos no da error en PHP, ni en el navegador, ni en un test.
  Lo tenía `views/builder/index.php` desde `ea9759f`: al `modal-footer` de
  `#formSettingsModal` le faltaba el cierre del modal, así que `#addFieldModal` y
  `#builderApp` quedaban **dentro** de un `.modal` (`display: none`). El backdrop lo
  añade Bootstrap a `<body>`, que estaba fuera, de modo que se veía el tinte y
  ningún diálogo; y no se cerraba tocando porque el clic de dismissal lo escucha el
  propio `.modal`, que al no verse no puede ser el objetivo de un clic. Sin `Esc`
  (móvil) y con `body.modal-open`, solo quedaba recargar.
- `#builderApp` es un `<div>` **vacío** que solo lleva `data-*`, y el constructor
  está antes de los modales: anidarlo dentro de algo oculto no cambia nada de lo que
  se ve, y por eso el fallo parecía del modal y no de la vista. **Cuando algo se ve
  pero no funciona, comprueba que lo que se movió estaba vacío.**
- `ViewStructureTest` recorre las 59 vistas como el parser del navegador: ninguna
  etiqueta sin cerrar, y **ningún `.modal` dentro de otro elemento** (todo lo que lo
  contiene está oculto, por definición casi). Los dos tests llevan guard
  anti-vacío —59 vistas, >1800 aperturas vistas y los 6 modales encontrados—
  porque un test de estructura que no recorre pasa verde por construcción, y así
  pasó al escribirlo (`foreach` sobre el `int` de `preg_match_all`).
- **Ante un síntoma de «no aparece», mide antes de arreglar**: un
  `getBoundingClientRect()` a 0×0 significa que el elemento no se ve **por un
  ancestro**, que es un diagnóstico distinto del de «es demasiado alto y se sale por
  arriba». La medición de `show.bs.modal` en `builder.js` (rectángulo, `top`,
  viewport, ancho) está para eso, y así un 0×0 manda a mirar la vista.

**Acciones sin recarga (bimodal)**

- **`Response::wantsJson()` mira UNA cosa**, la cabecera `X-Requested-With` que
  pone `window.vfPost()`: la misma ruta responde `{ok, message, html}` o el 302
  de siempre. **No hay ruta nueva ni botón con `onclick`**: el `<form
  method="post">` sigue siendo el camino principal y sin JS todo funciona
  igual, que es lo que se pierde en cuanto `onclick` es la única entrada.
- **`html` es un mapa de `id` → markup**, no un fragmento suelto: el JS solo
  sustituye el `innerHTML` de los ids que vienen, porque no sabe pintar una
  insignia ni un botón, que son de la vista.
- **La ficha tiene CUATRO huecos, no uno**, porque `forms.status` decide cuatro
  cosas: `vfSlotStatusBadge`, `vfSlotReviewTrail`, `vfSlotActions` y
  `vfSlotShare` (este último desaparece al cerrar, o queda un QR repartible de un
  formulario que ya no acepta respuestas). Los produce
  `app/Services/FormPanel.php::slots()`, y GET los pinta en línea: los dos
  caminos usan los **mismos partials**, y por eso no pueden separarse.
- **Guardar ajustes puede cambiar el estado**: un publicado con revisión activa
  vuelve a la cola y pierde la aprobación (`Form::submitForReview()`, la misma
  regla del builder), así que el JSON de `updateSettings()` devuelve **la
  insignia del estado que queda**. Si devolviera solo «Ajustes guardados», el
  panel dejaría de mentir por poco.
- **Un hueco va con lo que depende de SU estado, no con lo que se repinta de
  más**: `slots()` son los cuatro de `forms.status` y se devuelven en todas las
  acciones de estado; la tarjeta de Acceso (`vfSlotAccess`, `accessSlot()`) es de
  los **ajustes**, y por eso va aparte y solo la manda `updateSettings()`.
  Sumarla a `slots()` repondría la tarjeta en cada publicar/cerrar/aprobar, y con
  ella el enlace recién emitido (que `issue()` rota al regenerarlo) y lo que
  alguien tuviera a medio teclear en el alta. Al añadir un hueco, pregunta de
  qué estado depende: si es de otro que no cambia, `js-auto-submit` lo despacha
  **sin** `html` (el `select` ya está en el valor nuevo), y si hay algo que
  reponer, el `id` lo compone el POST y el servidor devuelve ese mismo nombre.
- **El rescate es el SEGUNDO argumento de `.then()`, no un `.catch()`**: `.catch()`
  recoge también «reventó el pintado», y ahí `form.submit()` **repetía la
  operación**. `soltar()` va **primero** (un fallo al pintar dejaba el botón
  apagado para siempre), y un `ok:false` bien formado se **pinta**, no se
  reenvía. Comprueban eso `testUnFalloAlPintarNoRepiteLaOperacion` y
  `testUnFormularioQueNoEsJsonVuelveAlPostNativo`.
- **`vfPost()` solo resuelve si la respuesta es JSON de verdad**, mirando el
  `Content-Type`: un 200 tunesco llega con `text/html` y `res.json()` sobre eso
  revienta con un error que se confunde con «el servidor dijo que no».
- **`data-confirm` va en el `<form>`**, no en el botón: el delegado lee
  `form.getAttribute(...)` y colgado del botón no lo miraba nadie. Y va **antes**
  de decidir el modo, porque preguntar y luego recargar es lo contrario de
  preguntar.
- **`data-confirm` significa «diálogo», y hay UNO en todo el panel**
  (`#vfConfirm`, al final de `<body>` en `views/layouts/main.php`): `vfAsk()`
  lo abre con Bootstrap y, si no hay diálogo o no hay Bootstrap, cae a
  `window.confirm()`. Un `vfConfirmGo()` por formulario, o un modal por vista, es
  volver a copiar el prompt feo. El texto va con **`textContent`** (el mensaje
  lleva cosas que han escapado la vista), el foco entra en **«Cancelar»** —no en
  «Sí», que un `Enter` sin querer ya no tiene vuelta atrás— y sale de ahí al
  botón. El diálogo vive fuera de todo elemento, porque `ViewStructureTest`
  prohíbe un `.modal` dentro de otro salvo `<html>` y `<body>`.
- **La segunda pasada por el delegado NO vuelve a preguntar**: `vfConfirmGo()`
  usa `requestSubmit()`, que relanza el evento `submit`, así que sin el
  `vfConfirmed` se abriría el diálogo otra vez (y desde ahí es un bucle). El
  arnés lo mira con «una sola vez: el reenvío con requestSubmit no vuelve a
  preguntar». Donde **no** hay `requestSubmit` (navegador viejo) se devuelve el
  envío al navegador con `p.form.submit()`: ese `else` también necesita un caso
  que lo recorra, o deja de vigilarse solo.
- **Cada `data-confirm` trae su `data-confirm-ok` y su tono**
  (`data-confirm-tone`, `danger` por defecto y `primary` para lo que no
  destruye): un «Sí, continuar» sobre «¿Eliminar formulario?» no dice qué se está
  aceptando. Lo recorre
  `testCadaAccionQueConfirmaDiceQueVaASerElSuBoton`, que cuenta los
  `<form data-confirm>` de las vistas (con los `<?= ?>` cambiados por una
  palabra antes, o el `action` con un `>` dentro cortaba la etiqueta y solo
   aparecían 3 de 10).
- **`data-vf-async` sin ningún hueco que reponer**: el `select` de rol de
  `/admin` se despacha **sin** `html` (el valor nuevo ya está en el selector), y
  lo que hace falta para que no recargue no es el `html` sino el atributo: sin él
  el POST es nativo y se va la página entera, con el desplegable de grupos
  abierto al lado. Los grupos de la misma fila sí tienen hueco
  (`vfUserGroups<uid>`, el mismo nombre que compone el POST).
- **`form.submit()` NO dispara el evento `submit`**: es el envío nativo sin
  evento, así que `.js-auto-submit` (que se envía solo al cambiar el selector) con
  `form.submit()` no entraba nunca en el delegado —no preguntaba, no apagaba el
  botón, no elegía el camino sin recarga— y la página se recargaba. Ahora es
  `vfAutoSubmit()`, que usa `requestSubmit()` y, en su `else`, `submit()`. El
  fallo no se ve leyendo el código («se envía solo», dice el selector) ni en el
  log: solo aparece porque el delegado no aparece.
- **El arnés no imita el `addEventListener` del documento**: un `submit`
  **disparado por el código** (el `requestSubmit()` del auto-envío, la segunda
  pasada de un `data-confirm`) no llega al delegado, así que el caso pasa verde
  sin comprobar nada. Para eso está `conDelegado(escenario)`, que lo ata al
  formulario del escenario.
- **Un `?>` dentro de un comentario `//` cierra el modo PHP** y todo lo que
  viene detrás se va como HTML (el `php -l` dice «Unclosed `{` on line N» y no
  señala el sitio). En bloque (`/* */`) no pasa. Vale para cualquier nota de un
  test que hable de `<?php` o `<?=`.
- **`data-vf-error` y `data-vf-clear` son del FORMULARIO y opcionales**: el
  `ok:false` se pinta en el `id` que declara el formulario (junto al campo, que
  es donde se busca, y **no** además como aviso flotante, que sería lo mismo dos
  veces) y el `ok:true` vacía el campo de `data-vf-clear` y le devuelve el foco
  para encadenar la misma acción. Sin ellos, el camino de siempre (toast) sigue
  siendo lo que funciona; los resuelve `vfByAttr()` y el JS no sabe pintar nada.
- **Alta y baja de la allowlist son la misma operación**: mismo controlador,
  mismo partial y **el mismo `html` por ids** (`['accessList' => …]`). Lo que
  las separaba era que `addEmail()` contestaba `html` como cadena suelta (no había
  hueco al que reponer) **y** que su handler propio de `dashboard.js` leía
  `card.dataset.addEndpoint`, un `data-` que la vista ya no tenía: la línea de
  guarda salía sin quitar el evento y el alta se iba por su POST nativo —que
  recarga— mientras el borrado no. Ese handler (93 líneas con su `fetch`, su
  `.catch` y su `form.submit()`) **ya no existe**: lo hace el delegado. Un IIFE
  que reimplementa el delegado es un camino que se queda viejo en silencio.
- **El `id` del hueco NO puede estar en un `<form>`**: el repintado mete markup
  dentro del elemento y quedaría un formulario anidado, que el parser descarta
  (funciona «por casualidad» y pierde contenido según el navegador). El del
  toggle de `/admin` es un `<span id="vfUserToggleN">` que **envuelve** al
  formulario. Lo vigila `testElHuecoQueReponeElJsNoEsUnFormulario`.
- **`User::setActive()` es idempotente con estado OBJETIVO**: el botón manda
  `active=0|1` y el `UPDATE … WHERE id = :id AND is_active <> :igual` no toca
  una fila que ya está en ese estado (y el mensaje lo dice). Un POST **sin** el
  campo sigue invirtiendo, para que una página cacheada de antes no se rompa.
  Dos trampas: `Database::execute()` ya devuelve el `rowCount` (no llames a
  `rowCount()` encima), y con `ATTR_EMULATE_PREPARES = false` un marcador
  repetido lanza HY093, así que son dos con el mismo valor.
- **`app.js` exporta en `window` lo que los tests y `dashboard.js` necesitan**
  (`vfToast`, `vfPost`), y el delegado se llama `vfHandleSubmit` a propósito: el
  arnés lo **extrae del fichero** y lo ejecuta, y una función anónima no se
  puede sacar. El extractor tiene que distinguir **literales de regex** (`esc()`
  lleva `.replace(/"/g, …)`) e imitar al navegador en `element.id` y en
  `remove()` (que pone `parentNode` a null en el nodo quitado, no en sus hijos).
- **El botón de «Guardar» de la ficha vive FUERA del formulario** (usa
  `form="formUpdate"`), así que `vfSubmitButton()` lo busca también por
  `button[form="…"]`: si no, esa acción no se apaga en vuelo y un segundo clic
  saca otro POST. `data-vf-busy` evita el doble clic.
- Los avisos usan **`role="status"` + `aria-live="polite"`**: un aviso que sale
  solo y no se anuncia no le sirve a quien navega con lector de pantalla.
- **`Form::statusLabel()`** es la fuente de los cuatro estados en las tres vistas
  (`ucfirst($status)` pintaba «In_review»); la **clase** CSS sigue siendo
  `app-status-<status>` sin traducir, que es la que lleva el color.

**Router, params y respuestas**

- `Router.php` es `strict_types=1` y los parámetros llegan **siempre como string**:
  el tipo declarado en la firma (`int` para `{id}`/`{sid}`, `string` para `{slug}`)
  es el contrato, y `coerceParam()` convierte y responde 404 si no encaja. No
  cambies `{id}` a `string`.
- `Response::*` **solo emiten headers y `exit` cuando `PHP_SAPI !== 'cli'`**: en el
  runner los controladores se invocan sin cortar el proceso, así que un action que
  debe detenerse ahí necesita `return;` explícito o crea datos fantasma.
- `tests/unit/ControllerActionsTest.php` recorre **todas** las rutas de
  `config/routes.php` con el router real: al añadir una ruta, ese test la ejecuta
  (completa `ensureForm`/identificadores si necesitas otros fixtures).

**Auth y RBAC**

- El **registro público está cerrado por defecto**: `REGISTRATION_ENABLED` con
  fallback `false` en `config/config.php`. `AuthController::showRegister()` y
  `register()` consultan `registrationEnabled()` y cortan con 403, y
  `views/auth/login.php` esconde el enlace. El gate del POST va **antes** del
  `csrf` del middleware: si no, un visitante sin ningún problema vería «token
  CSRF inválido» en vez de «el registro está cerrado». Cerrar `/register` **no**
  afecta al alta por panel (`/admin`), que es la vía de las cuentas de una
  instalación normal.
- **El registro tiene DOS fuentes y la tabla gana**: `app_settings` (fila
  `registration_enabled`, migración **0010**, leída por `App\Core\AppSettings` con
  `try/catch`) sobre `REGISTRATION_ENABLED` del `.env`, y `registrationEnabled()`
  —`public` y `static` porque la resuelve el panel para pintar el interruptor—
  decide. Gana en **los dos** sentidos (si no, el interruptor no podría cerrar
  un registro que el `.env` tiene abierto) y lo que se guarda es `0`/`1`, nunca
  el valor resuelto del `.env`. Sin fila, manda el `.env`.
- **`Config::setForTesting()` no alcanza para el registro**: `AppSettings` está
  por encima y una fila viva se lo traga. Un test que quiera el estado del
  `.env` tiene que poner además `AppSettings::setForTesting([])` y
  `forgetForTesting()` en el `tearDown`. Y `app_settings` es de la
  INSTALACIÓN, no un fixture: `ControllerActionsTest` guarda la fila previa y
  la devuelve en `cleanupResiduals()`, porque el smoke recorre
  `POST /admin/settings/registration` y escribiría de verdad.
- Cerrar el registro **crea un agujero de arranque** que hay que cubrir siempre
  con algo: sin `AdminController::createUser()`, la única vía para un `user` o un
  `editor` sería abrir el registro, dejarse apuntar y cerrarlo. `createUser()` es
  el camino ese, y **no** lleva el gate de `registrationEnabled()` (sí lleva
  `admin.users`): con la puerta cerrada tiene que seguir creando cuentas.
- Las reglas de alta viven en **`AuthController::validateNewUser()`**, público y
  compartido por `register()` y `createUser()`, por lo mismo que `PASSWORD_MIN`
  es público: dos copias divergirían y aparecería una cuenta creada por panel
  que el registro público rechazaría. Lo que **no** es compartible es lo que
  depende de la puerta de entrada: el rate limit y el mensaje uniforme de «email
  ya existe» son de `register()` (protege del sondeo del registro público), así
  que `createUser()` dice «Ya hay una cuenta con ese email» y no usa
  `RateLimiter`. Y valida `role_id` contra `Role::find()`, no contra una lista de
  ids: un rol inventado deja una cuenta sin rol interpretable, en la que
  `Auth::can()` da false para todo lo suyo sin decir nada.
- `Config` tiene **un solo** seam, y es de test: `setForTesting()` /
  `forgetForTesting()`. Pisan `$overrides` y dejan `$config` a `null` para
  que `all()` vuelva a leer `.env` con ellos aplicados. Por eso el `tearDown`
  tiene que llamar a `forgetForTesting()`: un override vivo se lo traga el
  siguiente test que lea `Config`, y el fallo sale en otro sitio.
- `Auth::can()` es la **única** puerta de negocio: nunca gates con `role_name`. Los
  permisos efectivos = rol propio + roles de los grupos.
- El catálogo de permisos está **duplicado**: `config/permissions.php` (fuente; la
  lee la migración 0005 en runtime) y el bloque estático de `database/seed.sql`
  (instalaciones limpias). Si cambias una clave o un rol por defecto, toca **los
  dos**; lo fija `PermissionSeedParityTest`.
- `updateRoleMatrix()` **rechaza** un POST sin la estructura `perms[<role>][]`
  (un cuerpo vacío no significa "desmarcar todo") y el rol `admin` siempre conserva
  `admin.access`.
- Un rol nuevo se crea con `Role::create()` y **nace sin permisos**: la matriz es lo
  que decide qué puede hacer. El re-seed de las migraciones (0005, 0006, 0011) solo
  otorga a los roles con nombre del catálogo, así que un rol inventado no se lo lleva
  nadie por el camino.
- **Cada enlace de la navegación se enseña solo si su destino deja pasar**
  (`views/layouts/main.php`: `dashboard.view`, `forms.review`, `admin.access`,
  `forms.create`). El caso que lo fijó: un `approver` veía «Nuevo formulario» y
  recibía un 403 con toda la pinta de un botón que funciona.
- `roles.name` es `utf8mb4_unicode_ci`: el WHERE y el índice único **no distinguen
  mayúsculas ni acentos**. Por eso `Role::create()` valida el slug y devuelve `null`
  ante un duplicado, y por eso un test de "no existe tal nombre" tiene que mirar el
  **valor guardado**, no que el WHERE encuentre la fila (`LIKE '%é%'` casa con
  cualquier `e`).
- **La pertenencia es el POST completo**: `groups[]` / `users[]` vacíos significan
  "sácale de todos", no "no lo he tocado" (`role_group_users` es N:M). La única
  excepción, `name`/`description` de un grupo, trata el **ausente** y el vacío como
  cosas distintas: el formulario de edición los manda, pero si un POST no los
  incluye se conservan, y llegar a esa rama es lo que hace falta para cambiar roles y
  miembros sin reescribir el nombre a mano.
- La pertenencia de un usuario se edita desde su fila en `/admin` y la de un grupo
  desde su tarjeta en `/admin/groups`: son las dos direcciones de la misma N:M, con
  `User::setGroups()` y `RoleGroup::setRoles()/setUsers()` como inversos. La primera
  exige `admin.groups` (mueve membresía), no `admin.users` (que mueve el rol).
- El login verifica **siempre** una contraseña, exista el usuario o no, contra su
  hash o contra `AuthController::DUMMY_HASH`, que tiene que ser bcrypt al coste de
  `PASSWORD_DEFAULT`. `PASSWORD_MIN` (10) es una constante **pública** compartida
  con `views/auth/register.php` y `AccountController` (tres sitios, una sola
  constante: fijarla por un camino y no por otro dejaría contraseñas que un
  formulario acepta y el otro rechaza).
- `Auth::user()` cachea por request (static, no sesión): desactivar a un usuario
  surte efecto en el request siguiente; `Auth::forgetCache()` la limpia.
- `database/seed.sql` crea **solo roles**: un bcrypt en el repo es una contraseña
  conocida, aunque esté detrás de un opt-in.

**Recuperación de contraseña (F2)**

- Las sesiones son **ficheros PHP**, no hay tabla de sesión: no se pueden revocar
  por id. Lo que las cierra es comparar instantes, `Session['auth_at']` contra
  `users.password_changed_at` (migración **0007**).
- La comparación es **estricta** (`auth_at > password_changed_at`) porque los dos
  valores vienen de relojes distintos (PHP y MariaDB) con resolución de segundo:
  ante la ambigüedad del mismo segundo se **cierra** la sesión, que es el lado
  caro de equivocarse. Quien abre sesión de verdad (`Auth::login()`,
  `assumeLoggedIn()` y el refresco de `AccountController::changePassword`) pasa por
  `Auth::markSessionFresh()`, que adelanta `auth_at` un segundo si hace falta, así
  que la estrictez no expulsa a nadie legítimo.
- **`User::updatePassword()` devuelve el `password_changed_at` que escribió**: el
  que cambia su propia contraseña lo usa para refrescar su sesión con el dato de
  la BD, no con `time()`. Y `User::find()` tiene que seleccionar
  `password_changed_at` por eso (si falta, el login se cierra a sí mismo).
- Una sesión **sin `auth_at`** es de antes de F2 y se cierra: al desplegar, todo
  el mundo tiene que entrar otra vez. Es el precio de no aceptar sesiones cuyo
  origen no se puede comprobar.
- El token de recuperación se guarda **hasheado** (sha256) y de un solo uso: en
  `doReset`, `markUsed()` y el cambio de contraseña van en la **misma
  transacción**; si el token se quemara antes de guardar, un fallo dejaría el
  enlace muerto y la contraseña sin cambiar. Un intento fallido **no** lo quema.
- `/forgot-password` responde **igual** exista o no el email (mismo destino, mismo
  mensaje) y no crea token ni correo para los desconocidos: el rate limit y el
  mensaje uniforme son las defensas, el `password_verify` contra `DUMMY_HASH`
  solo aplana la parte barata del tiempo de respuesta.
- La recuperación **no puede fijar la contraseña que ya tenía** (se compara con el
  hash, no con un campo del POST) y **cierra la sesión del navegador que la
  completó** con `Auth::revokeCurrentSession()`, que a diferencia de `logout()`
  conserva el flash del "contraseña cambiada".
- `/reset-password` **no lleva middleware `guest`**: el enlace del correo se abre
  en un navegador que puede tener sesión iniciada y `guest` lo mandaría a
  /dashboard sin mostrar el formulario.
- El enlace del correo sale de `PublicUrl` (como el QR y el embed), nunca de
  `app.url`.
- `PASSWORD_MIN` en las vistas de auth y en el mail: la clave de i18n nueva hay que
  meterla en `es.php`, `en.php` **y** (si la lee `fill.js`) en `FALLBACK_I18N`.

**Capa de datos**

- **Contrato horario, tres puntos**: la sesión de MariaDB hace
  `SET time_zone = '+00:00'`, PHP hace `date_default_timezone_set('UTC')`, y la
  conversión a la zona del lector (`app.timezone`, default `America/Caracas`)
  ocurre **solo al pintar** (`App\Core\Clock` y los helpers de `View`). Romper
  cualquiera de los tres solo se nota en la aritmética. **No migres los datos**:
  los TIMESTAMP existentes son instantes UTC correctos.
- Al agrupar por día: límites **UTC** con el final **exclusivo**
  (`Submission::dateBounds()`) y desplazamiento **numérico** en `CONVERT_TZ` (el
  nombre de zona exige las tablas de zonas horarias del servidor y sin ellas
  devuelve `NULL` sin avisar). `schedule.open_at/close_at` son muros de reloj de un
  `datetime-local`: se interpretan en `app.timezone`.
- **`Clock::dayStartUtc()`/`dayEndUtc()` toman un día de la ZONA DE PRESENTACIÓN,
  no un día UTC**, aunque el nombre diga `Utc`: lo que devuelven es la medianoche
  local *expresada* en UTC (Caracas = 04:00 UTC). Por eso un test que le pase
  `todayUtc()` mezcla dos zonas y solo se rompe entre las 00:00 y las 04:00 UTC,
  cuando el día local todavía es el anterior: el filtro arranca en el futuro y no
  alcanza lo recién creado. Medio día pasa y medio falla, y como la suite dura
  menos que la franja, el fallo sale de madrugada sin nada que lo dispare. Es la
  clase de test que solo existe en el reloj de quien lo escribió:
  `ExportStreamingTest::testElDiaDelFiltroEsElDeLaZonaDePresentacion` ata la
  propiedad y `todayLocal()` da el día correcto.
- `a.value` es TEXT, así que `MIN`/`MAX` SQL comparan como cadena: para números,
  castea (`MIN(a.value + 0)`).
- `withAnswersByIds()` trocea en tandas de 5000 (techo de placeholders) y
  **reordena el resultado globalmente**; concatenar tandas sin `usort` devuelve
  respuestas en orden de tanda.
- **Los exports escriben en streaming, no acumulan**: `exportJson` y `exportCsv`
  iteran `Submission::exportBatches()` (keyset `(finished_at, id)`, ceden
  `[$rows, $bySubmission]` por tanda) y `countByFiltered()` da el `total` del
  envelope sin barrer. Volver a juntar todo en un array antes de escribir es un
  OOM que ningún test detecta. Dos trampas: el keyset se compara **por tuplas**
  (`(s.finished_at, s.id) < (:last_at, :last_id)`) porque `Database` usa
  `ATTR_EMULATE_PREPARES = false` y **reusar `:last_at` dos veces lanza HY093**;
  y la PII se enmascara **por fila** (no una vez al principio, o las filas de
  las últimas tandas salen en claro). Tanda = `idBatch()`, seam
  `setIdBatchForTesting()`. `exportPayload()` conserva su forma exacta.
- `fields.parent_id` es `INT UNSIGNED DEFAULT NULL`: **"sin sección" es `NULL`,
  nunca `0`**. La normalización vive solo en `Field::normalizeParentId()`.
- MariaDB resetea `LAST_INSERT_ID()` a 0 con un `DELETE` en medio: en `Form::create`
  y `Submission::create` el `lastInsertId()` se captura **antes** de
  `bumpDashboardCache()`.
- Migraciones: closure idempotente, **sin transacción** (el DDL hace commit
  implícito), registro en `migrations` solo tras éxito y **`CREATE INDEX IF NOT
  EXISTS`** siempre (un `CREATE INDEX` plano revienta con 1061 al re-ejecutar y
  deja la matriz a medias).
- Una clave de permiso nueva necesita una **migración nueva** que re-siemre el
  catálogo leyéndolo de `config/permissions.php` (así hace la 0006): la que lo
  sembró ya no vuelve a correr. **Nunca borres filas de `migrations`** para
  re-sembrar.
- **`schema.sql` y las migraciones tienen que decir LO MISMO, y `schema.sql` lleva
  su propio `USE vanilla_forms;`**: `mysql otra_base < database/schema.sql` NO elige
  esa base, aplica sobre `vanilla_forms` pase lo que pase. Para instalar de verdad
  en otra hay que copiar el fichero sustituyendo el nombre. Con `USE` dentro, el
  primer `CREATE TABLE` que ya existe no hace nada y el primer `CREATE INDEX` plano
  revienta con 1061: el error dice «duplicate key» y parece que la base está mal
  cuando lo que pasó es que has apuntado a la que no creías.
- La comparación de verdad entre «instalación limpia» y «instalación migrada» no es
  un `mysqldump | diff` (llena de ruido de `AUTO_INCREMENT` y de comentarios): es
  una consulta a `information_schema` que compara columnas con su **posición**,
  índices y **`DELETE_RULE` de cada FK**. Tres diferencias reales salieron así, y
  ninguna se ve leyendo el código: una FK que solo estaba en las migradas, una
  columna que la migración dejaba al final y `schema.sql` en medio, y `COMMENT`
  divergentes. **`diff` de dos ficheros VACÍOS sale con código 0**: comprueba el
  número de líneas antes de creerte que dos esquemas son iguales.
- **`schema.sql` tiene un test que vigila que no haya prosa fuera de comentario**
  (`ParticipantSchemaTest::testSchemaSqlNoTieneComentariosRotos`): una continuación
  de comentario a la que se le cayó el `--` hace que MariaDB ejecute la frase como
  SQL, y **`schema.sql` dejó de instalar** sin que ningún test se enterara. El error
  aparece en la línea del `--` perdido, no en la del comentario que empezó.

**Builder (JS)**

- `buildPayload()` **mapea explícitamente** `parentId` (camelCase, en memoria) a
  `parent_id` (snake_case, lo que lee el servidor): es la única frontera entre
  ambas convenciones. Lo fija `BuilderPayloadContractTest`.
- `applySettings()` **nunca** reconstruye `validations` desde cero, parte de
  `preservedValidations(f)`. Regla dura: **toda clave en `MANAGED_VALIDATION_KEYS`
  necesita control Y listener** (el par clave→control está en `CONTROL_FOR_KEY`, de
  `BuilderValidationsContractTest`).
- **Un flag necesita control Y listener, igual que una validación**: que
  `applySettings()` escriba `f.<flag>` y que `buildPayload()` lo mande no basta si el
  interruptor no dispara `applySettings` (`change`). Sin eso el interruptor se mueve
  en pantalla, el estado en memoria conserva el valor del servidor y el autosave
  guarda lo mismo de antes: el payload sale idéntico y sin error. Pasó con
  `fieldPii` y con el interruptor del salto de página (`show_header`); el par
  flag→control está en `TOP_LEVEL_FLAG_CONTROL` de `BuilderValidationsContractTest`,
  que barre los listeners con el mismo `wiredControls()`.
- Ocultar tiles estructurales dentro de una sección es
  `setAttribute('data-vf-structural-hidden','1')` + selector
  `[data-vf-structural-hidden]` **por presencia**: `toggleAttribute()` deja el valor
  `""` y el selector nunca casa.
- **TODA clase de la app que `builder.js` emite tiene que existir como selector
  en `app.css`**, y lo vigila `BuilderPreviewCssTest` cruzando las dos. Salieron
  trece sin definir (`.nps-dot`, `.scale-dot`, `.star-icon`, `.ranking-pos`,
  `.vf-matrix-preview`…), y el síntoma era la miniatura de la tarjeta hecha texto
  suelto. Los ganchos de JS que no llevan estilo se declaran **explícitamente** en
  ese test: o es estilo, o es gancho, y la lista corta es lo que lo hace
auditable. En sentido contrario, el **preview en vivo (`previewBody()`)
   reutiliza el markup del fill** (`nps-option`/`scale-btn`, `star-option`/
   `star-btn`, `vf-matrix-cell`, `vf-ranking-*`, y también `.vf-submit-row`/
   `.vf-submit`/`vf-submit-label` del botón de enviar): su HTML propio lo hacía
   ver distinto del formulario que iba a salir, y cada cambio obligaba a tocarlo
   dos veces. El botón se había quedado fuera de esa regla y salió **negro**:
   pintaba `<button class="btn btn-lg app-btn-primary">`, y **`app-btn-primary`
   pone fondo y NO color** (a propósito, para que el color lo ponga el destino),
   así que el texto salía con el del navegador. Que se comprobara el `.vf-submit`
   del formulario publicado no decía nada del botón del preview: **«se arregló y
   se comprobó» en una superficie no es nada sobre la otra**.
- **El modal de «Agregar elemento» tiene que caber en un móvil, y son tres reglas
  a la vez** (`@media (max-width: 575.98px)` de `app.css`, atadas en
  `testElDialogoDeTiposNoSaleDeLaPantallaEnMovil`): la rejilla del constructor
  baja su mínimo a `7.5rem` (con el `9.5rem` de escritorio, en un móvil de 360px
  cabía **una** columna y los 21 tipos medían ~1700px), el diálogo se ancla
  **arriba** (`align-items: flex-start`) y el alto se topa con **`dvh`**, que es el
  viewport que se ve. Lo de `dvh` no es cosmetía: `.modal-dialog-scrollable`
  mide en `100%` de un `position: fixed`, que es el viewport **grande** (con la
  barra de direcciones desplegada). Y con el diálogo centrado el desborde cae por
  **arriba**, donde no scrollea nadie, porque el `overflow-y` está en
  `.modal-body`, dentro del diálogo. **Estas tres reglas NO arreglaron el «no
  aparece»** que las hacía necesarias: ese era el `</div>` perdido de
  `#formSettingsModal` (ver «Estructura de las vistas»). Se dejan escritas
  porque son verdaderas, y porque el diagnóstico se fue primero por aquí: encajaban
  con el síntoma una a una y estaban en la misma pantalla que el fallo.
- **Dentro de un bloque de declaraciones los comentarios van con `/* … */`.** Un
  `//` ahí no es CSS: el parser descarta la declaración y se come la de al lado.
  Y ojo con los tests que buscan una propiedad con un helper que **borra** las
  líneas que empiezan por `//`: pasan por accidente
  (`testElBotonDeEnviarTieneElTextoEnClaro` lo hizo, y el helper era el que
  decidía el resultado). Para leer el cuerpo de una regla usa `bloqueCss()`, que
  quita los comentarios de bloque y devuelve las declaraciones de verdad.
- El engranaje de cada tarjeta **reubica la misma** instancia de `#settingsForm`
  (`moveSettingsTo`), no la duplica; como `openFieldSettings` redibuja el lienzo, el
  opener se localiza por `[data-uid]` al cerrar. Spec de UI: `docs/design.md`.

**fill.js / i18n**

- El denominador de la barra de progreso es `progressFieldCards()`, que es
  deliberadamente **otro conjunto** que `visibleFieldCards()`: `.vf-step-hidden`
  es de maquetación (solo se ve un paso), no de omisión. El paso realmente
  **omitido** se marca con `data-vf-skipped` (atributo que se pone y se quita), no
  con una clase.
- `applyCardVisibility()` vive separada de `evaluateConditions()` porque
  `paintSteps()` también la necesita, e `isCardAnswered()` es "completa o nada"
  para matriz y ranking (igual que `val.matrix_all`).
- **F5 (avance automático)**: opt-in (`settings.advance.auto`, off), y su puerta
  es `autoAdvanceAllowed()`. Salta con `change` y **nunca** con `input`, y decide
  con `isCardAnswered()` y **nunca** con `checkValidity()`/`required` del
  navegador (un texto con espacios «cumple» `required`, y su respuesta no es
  igual en todos los navegadores). Y «terminado» son DOS cosas: `stepComplete()`
  exige `isCardAnswered() && validateField(card).length === 0`, porque la
  completitud no es la validez (un ranking con posiciones repetidas tiene todas
  las posiciones y aun así el campo está en rojo con `val.ranking_unique`) y el
  avance automático es lo único que cambia de página sin que nadie pulse nada.
  Una **opcional** inválida no bloquea. Solo cuentan las obligatorias
  **visibles**, y un paso sin obligatorias no avanza nunca solo; el último paso y
  los omitidos tampoco. `autoAdvanceReady` se enciende al final del arranque, tras
  `restoreDraft()` y `evaluateConditions()`: si no, el borrador restaurado hace
  que el formulario se recorra solo al cargar. `goPrev()` lo cancela.
- El salto automático mueve el foco al **bloque** del paso
  (`tabindex="-1"` + `aria-label`, en `views/fill/show.php`), no a su primer
  campo, y el manual no lo mueve: `gotoStep()` solo lo hace con `opts.focus`.
  `stepLabel()` compone el nombre accesible con las **mismas** claves i18n que el
  badge del pie, y `paintSteps()` lo renumera con los mismos dos números que él
  (`rank`/`vis.length`): al omitirse un paso, el bloque y su pie tienen que
  decir lo mismo. `data-vf-skipped` se comprueba **por valor** (`=== '1'`), no
  por presencia.
- Al añadir una clave de i18n hay que tocar **tres** sitios: `app/lang/es.php`,
  `app/lang/en.php` (paridad, la verifica `I18nTest`) y `FALLBACK_I18N` de
  `fill.js` (subconjunto de `es.php`, sin auto-referencias).
- `resolve($settings)` mantiene su firma clásica para emails y middleware;
  `I18n::forRequest($settings)` es la puerta del fill y añade el override
  `?lang=`/campo oculto, validado contra `supportedLocales()`.

**Seguridad y peticiones**

- `Request::ip()` solo confía en `X-Forwarded-For`/`X-Real-IP` con
  `TRUST_PROXY=true` (default `false`): si no, el rate limiting del fill es
  bypasseable. `X-Forwarded-Proto` (en `PublicUrl`) usa el mismo criterio.
- El enlace copiado, el QR y el `<iframe>` de embed salen de `App\Core\PublicUrl`
  (`APP_PUBLIC_URL` → petición actual → `app.url`), nunca de `app.url` directo.
- `thanks.redirect_url` pasa `RedirectGuard` (allowlist `APP_REDIRECT_ALLOWLIST`);
  **vacía = bloqueado**, no "todo pasa".
- `settings.theme.font` es lista blanca (`FormSettings::isValidFontFamily()`) y se
  valida en **las dos puertas**, `normalize()` (lo que entra) y `themeCss()` (lo
  que sale), porque puede haber filas ya guardadas con valores inválidos;
  `validate()` mira el valor **crudo**, no el normalizado.
- **Toda variable que emite `themeCss()` tiene que tener un consumidor en
  `app.css`** (`ThemeFontCssInjectionTest` lo recorre y falla si alguna no
  aparece). `--vf-background` va a `.fill-body` **y solo a él** (la tarjeta se
  queda blanca: con un color de página oscuro, teñirla también la haría
  ilegible) y `--vf-font` entra por `body`; los dos declaran su valor por
  defecto en `:root`, así que sin ajuste se ve el gradiente y la Inter de
  siempre. Un ajuste que se guarda bien y no se ve no da ningún error, ni en
  el navegador ni en el log.
- Los límites del dashboard son **por usuario** (`App\Core\DashboardLimit`, scopes
  `dash:create` / `dash:export`) y se comprueban **antes** de hacer el trabajo.
- `SecurityLog::record()` y `Cache::remember()` nunca rompen el request (el
  primero guarda `user_id` NULL si `Auth::id()` no existe); `forgetLike('dash:')`
  lo llaman las escrituras de `Form`/`Submission`.
- Un test que falsifique sesión con `$_SESSION['user_id']` a pelo está **roto**
  desde F2 (una sesión sin `auth_at` se cierra y `Auth::user()` da null): usa
  `Auth::assumeLoggedIn()`, que fija las dos cosas. Por eso el `tearDown` de un
  test con `Session::destroy()` es correcto aunque ponga rojo a otro test: lo
  que estaba rompiendo es ese otro test, que dependía de un `auth_at` compartido.

## Seams de test (no son código muerto)

`Webhook::setResolver/setAllowPrivate/setTransport/setRetryBackoff/setHandler`,
`Mailer::setTransport`, `Turnstile::publicSiteKey`,
`Submission::setIdBatchForTesting`, `RateLimiter::setCleanupProbability`,
`Request::setRequestBodyForTests/setTrustProxyForTesting`,
`Response::setRecordHalt/lastHalt`, `RedirectGuard::setAllowlistForTesting`,
`PublicUrl::setBaseForTesting`, `Auth::forgetCache`. `Config` no tiene setter:
para lo suyo, o `null` = se lee de la config, o un seam propio.
`Auth::assumeLoggedIn()` es además el **único** modo de falsar una sesión en un
test: fija `auth_at` a la vez que `user_id`, y omitirlo hace que `Auth::user()`
cierre la sesión y todos los `Auth::can()` de ese test den false (verde, pero
probando la rama equivocada).

## Dónde mirar

- `docs/manual-usuario.md`: manual de instalación y uso (para quien opera la
  instancia). Escrito contra el código: si necesitas saber cómo funciona algo,
  **mira el código y actualiza el manual**, no al revés.
- `docs/pruebas-funcionales.md`: 391 comprobaciones manuales en 26 bloques (A–Z,
  las regresiones R, la tanda visual S, las acciones sin recarga T, el
  diálogo de confirmación con el alta de la allowlist U, la pestaña de
  participantes V, el identificador de la lista W, el filtro de la analítica
  por atributo X, la comparación por atributo con su techo Y y quién respondió
  Z), para detectar problemas que la suite no ve (teclado, maquetación, flujo
  en pantalla). Al tocar el builder, el fill o el panel, R, S, T y U son la
  lista mínima.
- `docs/decisiones.md`: por qué se cambió cada cosa (archivo histórico).
- `docs/evaluacion-propuestas.md`: qué se decidió en F0–F5 y por qué; las seis
  cerradas.
- `docs/technical-document.md`: arquitectura y modelo de datos (22 tablas, con
  F2/F3/F4 y RBAC).
- `docs/plan-remediacion-2026-09-27.md` y `docs/roadmap-encuesta.md`: fases 0–6 y
  P1–P10 con su diagnóstico; los mapas de estado quedaron al día el 2026-09-29.
- `docs/design.md`: spec del builder (tokens, componentes, reglas para código
  nuevo).
- `docs/api-spec.md`: referencia de endpoints. Para el middleware EXACTO de cada
  ruta, `config/routes.php` sigue siendo la fuente de verdad.
- `docs/audit-report*.md`, `fix-plan.md` y `levantamiento_informacion.md` son
  auditorías ya cerradas: histórico.
- `freddy/`, `freddy.zip` y `pub.jar` son un toolkit externo de "levantamiento de
  información", **no código de la app** (y están en `.gitignore`). No los toques.
