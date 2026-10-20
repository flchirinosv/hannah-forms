# AGENTS.md — VanillaForms

Formularios self-hosted: **PHP 8.2+ vanilla, sin framework**, MVC propio, MariaDB,
Bootstrap 5. Sin Composer, PHPUnit, dotenv ni bundler: el autoload, el cargador de
`.env`, el router y el runner de tests son implementación propia. **No ejecutes
`composer install` ni busques `composer lint`: en la app no hay `composer.json`,
`Makefile` ni CI** (el único `package.json` es `.opencode/`, tooling de OpenCode,
no de la aplicación). Es el único control de calidad: `scripts/test.php`.

Todo el código, los comentarios, los strings de UI y esta documentación están en
español. `declare(strict_types=1);` va en `app/`, `config/`, `database/`,
`scripts/` y `tests/`; **las vistas de `views/` no lo llevan** (son fragmentos).
El *por qué* de cada cambio está archivado en `docs/decisiones.md`.

## Comandos

- Suite completa: `php scripts/test.php` → 110 archivos, 1212 métodos, ~8 min;
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
- **node es opcional**: solo lo usan `tests/unit/FillProgressTest.php`,
  `tests/unit/AsyncActionsJsTest.php` y `tests/unit/FillAdvanceTest.php` para
  correr los arneses `tests/js/fill-progress.js`, `tests/js/async-actions.js` y
  `tests/js/fill-advance.js`; sin node esos tests avisan y pasan.

Atajos de OpenCode (`.opencode/`, no es código de la app): el skill `vf-docs`
indexa `docs/` (con sus trampas de obsolescencia) y el skill `vf-migraciones` da la
receta de migración idempotente; los commands `/test`, `/migrate` y
`/nueva-migracion` ejecutan lo anterior.

## Arquitectura

- `public/index.php` → `config/bootstrap.php` (carga `.env` con `App\Core\Env`,
  fija **UTC** en PHP, conecta la BD, inicia sesión) → `config/routes.php` →
  controlador. `public/index.php` es el único PHP que ve el web server.
- Autoload PSR-4 propio `App\` → `app/` (`config/autoload.php`): `Controllers` (13),
  `Core` (el middleware vive en `Core/Middleware/Middleware.php`), `Models` (10),
  `Services` (19), `lang/{es,en}.php`.
  `views/` = 72 plantillas y partials con `View` (veintisiete con prefijo `_`, sin ruta
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
- **63 de los 110 archivos tocan la BD real** (los que llaman
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
- Los superglobales llegan al runner **sin inicializar**: en CLI no hay `$_GET`
  ni `$_POST` hasta que un test los asigna. Por eso `Request::all()` los
  comprueba con `is_array()`, como hacía `postAll()`, y no con `array_merge()` a
  pelo: si no, un `--filter` estrecho puede acabar con un `array_merge(): Argument
  #1 must be of type array, null given` **según qué test haya corrido primero**
  (con la suite entera otro test los define antes y nadie lo ve).

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
  **ausencia** significa «abierto», y por eso los seis grupos de la ficha **no**
  llevan el atributo. Hoy hay dos: la ficha (`data-vf-form-id`, clave = id del
  formulario, que no se renombra porque sería perder el estado guardado de todos) y
  la lista de grupos de `/admin/groups` (`data-vf-group-key="admin-grupos"`, abierta).
  Que el panel llegue `show` es lo que permite usarlo sin JS.
- **El `hidden.bs.collapse` de arranque no se guarda.** El `hide()`/`show()` inicial
  dispara el mismo evento que un clic, así que `dashboard.js` apunta lo que él
  decide en `decididos` y descarta esa primera escritura: si se guardara, la primera
  visita cerraría el panel para siempre y un cambio futuro del valor por defecto no
  llegaría a nadie.
- **Las altas de `/admin` son formularios servidos EN LÍNEA que `dashboard.js` mueve
  al diálogo** (`data-vf-modal-move` + `data-vf-modal-form`), nunca markup duplicado:
  sin JS se ven en su tarjeta de cabecera y el botón del modal no hace falta. El alta
  de usuario va en **una sola columna** (`col-12`, diálogo a ancho por defecto) porque
  en dos el campo de contraseña se corta en pantallas estrechas; el de grupo también en
  **una** (`col-12`) con la descripción como `<textarea>`. Las acciones de la fila de
  usuario (`⋯`) son **otro** modal compartido (`#userActionsModal`, `modal-xl`) al que
  el JS **traslada el panel de esa fila** y lo devuelve a su sitio al cerrar: los `id`
  de los huecos, los `action` y los avisos siguen siendo los de la fila. El panel se
  organiza en **tres columnas** (permisos efectivos / rol y grupos / permisos extra).
  Los ajustes de la INSTANCIA (registro y revisión) no viven aquí sino en
  `/admin/settings`.
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
- **Dos rate limits son configurables por `.env`** (centro de censo):  `app.limits.fill_submit_per_10min` (`FILL_SUBMIT_PER_10MIN`, default **10**, ventana de
  **600 s**) para el envío del fill y `app.limits.roster_code_per_15min`
  (`ROSTER_CODE_PER_15MIN`, default **10**, ventana de `RosterDoor::VENTANA` = **900 s**)
  para la puerta de código de una lista. Los defaults son los valores que antes estaban
  escritos a mano, así que una instalación sin las claves se comporta **igual**; basta
  ponerlas para apretar o aflojar. `Config::get()` lee con notación de punto, y lo
  importante es que **el default es el que se prueba**, no el del `.env`. Lo que NO se
  configura es el segundo límite de `RosterDoor` (el **por código**, `MAX_INTENTOS`):
  contar por clave es lo que hace que enumerar el padrón cueste, y aflojarlo por
  variables es dejar que un `.env` olvidado abra esa puerta. Los ata  `RateLimitConfigTest`.
- La randomización aplica **solo en GET** (`show`/`embed`): el re-render de errores
  del POST no baraja.
- Toda closure de `views/fill/show.php` que lea una variable de la vista la
  declara en su `use`; si no, lee una indefinida y falla sin aviso.

**Revisión de publicación (F3)**

- `forms.status` tiene **cinco** valores: `draft`, `in_review`, `published`,
  `closed` y `approved`. `approved` va **al final del ENUM** (migración **0019**):
  insertarlo en medio habría re-mapeado el valor almacenado de las filas existentes.
  Ni `in_review` ni `approved` se sirven: el GET pinta `views/fill/in_review.php`
  para el primero y `views/fill/draft.php` para el aprobado (su nota, «todavía no
  ha sido publicado por su propietario», es literal), y el POST corta con 403 como
  en un `draft`.
- **La exigencia de revisión es de la INSTANCIA, no opt-in por formulario**
  (migración **0016**; antes `settings.review.enabled`). La decide un administrador
  con `app_settings.review_required` (y `review_notify_email`) desde `/admin`, y
  exime a los dueños que tengan el permiso `forms.publish_without_review`.
  `App\Services\ReviewPolicy` es la única puerta (`policyOn()`, `notifyEmail()`,
  `requiredFor($form)`), y la exención se evalúa sobre el **dueño** (`forms.user_id`),
  no sobre quien actúa: un `admin` con `forms.manage_all` que publica el formulario
  de otra persona no puede saltarse la revisión por su propio permiso. Aprobar exige
  el **permiso `forms.review`**, nunca `canEdit`: por eso `ReviewController` es un
  controlador aparte de `FormController` (un `canEdit()` leído como autorización para
  aprobar es exactamente el error a evitar).
- **El revisor trabaja desde la bandeja, no desde la ficha.** La ficha `/forms/{id}`
  es del dueño (exige `canEdit`), y `reviewable()` **no** comprueba `canEdit`: un rol
  que solo tenga `forms.review` + `dashboard.view` (el `approver`) tiene que poder
  aprobar el formulario de cualquiera sin `forms.manage_all` ni `forms.edit_own`.
  Meter `canEdit` ahí dejaba al revisor con el botón en la bandeja y un 403 en el POST.
  Por eso tras aprobar/devolver `accept()`/`reject()` no vuelven siempre a la ficha:
  `destino()` devuelve `/review` si `Access::canEdit()` es falso y `/forms/{id}` si no.
  Para decidir, el revisor abre la **estructura read-only** (`GET /forms/{id}/print`),
  que `FormController::printForm()` le permite solo cuando `status === 'in_review'`
  (un borrador ajeno no es asunto suyo) y con vuelta a la bandeja; esa vista la
  pinta también el dueño desde la ficha, y su enlace «Volver» es condicional. El
  enlace del aviso por correo apunta a la bandeja (`/review`), nunca a la ficha.
- La **0016 conserva el estado de las instalaciones que ya usaban la revisión por
  formulario**: lee el `settings` JSON de cada formulario y, si alguno la tenía
  encendida, enciende `review_required`. Sin eso, actualizar APAGARÍA en silencio la
  revisión de quien la tenía puesta. No toca `forms.status`. `forms.publish_without_review`
  se siembra a `admin` con la re-siembra del catálogo de esa misma migración.
- **Aprobar NO publica**: `approve` deja `forms.status = 'approved'`. Publicar es el
  paso siguiente y lo da el **dueño** (`FormController::publish()`), que puede
  publicar al momento o dejar el aprobado esperando su agenda. Antes de la 0019
  aprobar publicaba en el mismo acto, así que una aprobación con semanas de
  antelación sacaba el formulario al público sola.
- El **estado solo lo mueven las acciones de estado** —`ReviewController`
  (`sendReview`, `approve`, `returnDraft`) y `FormController` (`publish`, `close`)—
  y cada una comprueba su estado de origen. `publish()` se salta el gate de
  `ReviewPolicy::requiredFor()` **solo** cuando el formulario ya está `approved`:
  ese pasó por revisión y publicarlo es el paso que se le dejó al dueño; desde
  cualquier otro estado con revisión activa se rechaza con un mensaje que dice qué
  hacer. `sendReview` desde un `approved` también se rechaza («ya está aprobado:
   publícalo o edítalo»). El campo `status` del payload del builder **no publica**:
   con revisión activa se ignora, y **editar un formulario `published` o `approved`
   lo deja en `draft`** (borrando la aprobación y la fecha de petición con
   `Form::returnToDraft()`, dentro de la transacción del builder): la cola no se
   llena sola, **el dueño decide cuándo enviarlo a revisión** con «Enviar a
   revisión». Ese gate vive en
   `BuilderController::saveStructure()`; añadir otra vía que escriba `forms.status`
   es saltarse la revisión. Publicar además registra
   `SecurityLog::record('form.publish', …)`: con el flujo nuevo la aprobación ya no
   implica publicación, así que sin esa traza publicar no dejaría rastro.
- Aprobar un formulario propio está permitido y el log lo marca con `propio`: en
  una instalación de un solo usuario no hay a quién pedirle aprobación, y
  prohibirlo dejaría esa instalación sin poder publicar nunca.
- `User::reviewers()` (rol propio **+** roles de los grupos **+** permisos directos,
  en una sola consulta con tres `EXISTS`, filtrando `is_active = 1`) alimenta el
  aviso; el aviso en el panel es siempre y el correo es opt-in
  (`app_settings.review_notify_email`) y apunta al panel, nunca al enlace público.
  En tests, sus destinatarios se filtran por `@test.local` porque también salen los
  usuarios reales de la instancia.
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
  los válidos, enumerar códigos sería gratis. La respuesta es **uniforme por
  FORMA** (un tecleo con forma de código dice lo mismo exista o no; lo que no la
  tiene se contesta con el texto del identificador que el formulario pide) y da
  **404** si el formulario no es de lista. En embed **no hay variante con
  `/embed`**: el canje redirige el frame entero. De esas dos claves, la que es por
  **IP** es la configurable (`ROSTER_CODE_PER_15MIN`) y la que es por **código**
  no lo es: ver «Dos rate limits son configurables» en *Fill y thanks*.
- **La puerta de un formulario de lista admite DOS credenciales y nada más: su
  `code` y el dato `settings.access.identifier`.** `RosterDoor::buscarAmbos()`
  prueba el código y a continuación **solo** el identificador configurado (antes
  probaba cédula, correo e indicador a ciegas, de modo que un formulario en
  «correo» se abría con la cédula de cualquiera de la lista); con
  `identifier = codigo` no hay segunda vuelta, porque el código ya se probó. Es la
  misma regla que el `form_id` en el `WHERE` de `FormAccess::findValidToken()`:
  lo que la página no pide, no abre.
- **Todo lo que dice la pantalla pública de acceso sale de `access.*_{identifier}`**
  (rótulo, placeholder, intro, nota, icono y `type`/`inputmode`/`maxlength` del
  campo): `views/fill/access.php` resuelve `$identificador` contra
  `FormSettings::ACCESS_IDENTIFIERS` y compone la clave. Las claves genéricas
  `access.code_label`, `access.code_placeholder`, `access.intro_roster`,
  `access.note_roster` y `access.code_invalid` **se borraron** (con sus usos):
  un texto que no nombra el dato no le dice a nadie qué escribir. El campo sigue
  siendo `name="code"` con `id="vfAccessCode"` — es el contrato con
  `verifyCode()` y con los tests, y no cambia aunque el rótulo diga cédula. Lo
  atan `RosterDoorTest::testLaPaginaDeAccesoPideElIdentificadorQueToca` y las
  filas W14–W19 de `docs/pruebas-funcionales.md`.
- **El mensaje de acceso inválido se elige por FORMA y no por el motivo**: si lo
  tecleado parece código (`FormParticipant::codeValido(RosterDoor::normalizar(...))`)
  o el identificador es `codigo`, dice `access.invalid_codigo`; si no,
  `access.invalid_{identifier}`. La uniformidad del oráculo se mantiene (el
  alfabeto del código es público: 16 de esos caracteres dicen lo mismo existan o
  no) y quien escribió su cédula recibe un mensaje que nombra su cédula, que es lo
  único que le dice qué corregir.
- **Con `identifier = codigo`, `identifier_value` ES el `code`** (`create()` lo
  genera antes de validar y lo guarda como identidad; `update()` usa
  `actual['code']`), mientras que **la columna del archivo sigue siendo la cédula**:
  `identificadorDeArchivo('codigo') === 'cedula'`, porque nadie puede saber de
  antemano el código que la lista va a generar y sin esa traducción el importador
  pediría una columna imposible y rechazaría todos los archivos.
  `identifierValue('codigo', …)` devuelve `''` a propósito: no hay columna de la
  que leer, y un valor inventado ahí encubriría el error dentro del importador.
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
  menú `⋯` con la edición (`#rowactions-<pid>`, un input por atributo) y a 50 el
  botón de «Quitar» deja de notarse. Si se cambian, se cambian los dos sitios y sus
  tests.

**El correo de invitación (fase 10)**

- **Solo hay botón si el identificador es `correo`.** `FormParticipant::identifierFor($id)
  === FormParticipant::IDENT_CORREO`, comprobado en `ParticipantController::invitations()`,
  que es la ÚNICA acción de participantes que manda correo. Con `cedula`,
  `indicador` o `codigo` el
  correo es un dato opcional de la lista, no la identidad con la que se entra, así que el
  enlace se reparte desde la columna «Código». Si añades otro sitio que escriba en
  `form_participants.correo`, ese tampoco manda.
- **`Mailer::isConfigured()` va ANTES de `Mailer::send()`**, porque `send()` lanza con
  `MAIL_HOST` vacío y eso se leería como «el servidor de correo falló» cuando lo que
  falta es configuración.
- **La ruta NO lleva `post-max`**: el `post-max` del fill existe porque un cuerpo que
  pasa de `post_max_size` vacía `$_FILES` y sin el guard el error es de CSRF. Aquí no
  hay archivo, así que el middleware solo estorbaría (y obligaría a subir el límite).
- **Se marca lo que se mandó, DESPUÉS del `send()`, nunca antes.** Marcado antes, un
  fallo pierde el correo sin avisar: la fila desaparece de la cola. Con el orden correcto
  un fallo es «sigue pendiente», que es lo que permite reintentar. Mutación comprobada
  (`markInvited` antes del envío) → `testUnFalloSuelto…` y
  `testSeParaTrasTresFallos…` en rojo.
- **Tres cortes, y los tres son deliberados**: 50 por tanda
  (`INVITE_POR_TANDA`, y el botón lo dice en el rótulo: el tope es información, no un
  fallo), parada a los **3 fallos seguidos** (`INVITE_FALLOS_SEGUIDOS`, porque un SMTP
  caído son 400 excepciones idénticas) y **el filtro `correo <> ''` en SQL**, que es la
  segunda línea detrás de `planDeReidentificacion()`: si entrara una fila sin correo,
  `Mailer::send()` lanzaría y el contador de fallos dejaría de significar «el servidor
  está caído».
- **A quien ya respondió no se le escribe** (`responded_at IS NULL`): se le puede
  escribir otra vez desde el correo, pero no desde el botón, que es para los que no han
  llegado.
- **Corregir el correo devuelve la fila a la cola, y renombrar no**: `update()` hace
  `invited_at = IF(:cambioCorreo = 1, NULL, invited_at)`, con la comparación de las dos
  direcciones **en minúsculas** (el `UPDATE` guarda `mb_strtolower`, y si solo se
  normalizara una de las dos, escribir la misma dirección en mayúsculas borraría la
  marca y se volvería a invitar a quien ya la recibió). El filtro es **el correo y solo
  el correo** por lo que **reimportar el mismo `.xlsx` no deshace las invitaciones**: si
  en vez de comparar se borrara la marca en cada escritura, bastaría con volver a subir
  el archivo para que las 400 invitaciones volvieran a salir. Lo atan
  `testCorregirElCorreoDevuelveLaFilaAPendientesYRenombrarNo` y
  `testReimportarElMismoArchivoNoDeshaceLasInvitaciones`. Sin la mitad del correo, la
  puerta se cierra sin error ni aviso: el botón dice «no queda nadie pendiente».
- **`invited_at` es una FECHA y no un «sí/no»**, y `NULL` de verdad (no
  `DEFAULT '0000-00-00'`): `IS NOT NULL` sobre la fecha cero marcaría a toda la lista
  como invitada desde el primer día. Lo fija
  `ParticipantSchemaTest::testLaInvitacionEsUnaFechaYNoUnSiNo`, que además mira que vaya
  justo detrás de `responded_at` (posición que una base limpia y una migrada tienen que
  compartir).
- **La importación NO manda correo** y `testImportarNoMandaCorreoNiConIdentificadorCorreo`
  mira justo ese camino: 400 correos desde un `POST` son un accidente esperando.
- **El `?t=` de la invitación es el mismo hueco que el de F4 y el del canje de código**,
  y por eso `PublicUrl::rosterUrl($slug, $code)` y no `app.url` directo.
- **El correo no menciona la lista** ni cuántas personas son: dice que hay un formulario
  que responder. Escribir «te toca entre 400» es filtrarle el listado a quien todavía
  no ha respondido nada, y `views/mail/roster_invite.php` no lleva el `form_id`.

**El recordatorio del correo (fase 12)**

- **El recordatorio NO es «invitar otra vez»** y solo va **a quien fue invitado y no
  respondió**: `pendingReminders()` filtra `invited_at IS NOT NULL AND responded_at IS
  NULL AND correo <> ''`, el mismo criterio que usa el contador del botón (`porRecordar`
  = `pendingReminderCount()`, y solo con identificador `correo`). Recordar a quien nunca
  recibió la invitación sería una invitación disfrazada; a quien ya respondió le
  estarías pidiendo algo que ya hizo. Los tres casos conviven en el mismo formulario en
  `RosterReminderMailTest::testSoloSeRecuerdaAQuienFueInvitadoYNoRespondio`, para que un
  filtro que falte se note.
- **Es repetible, con contador** (`reminded_at` + `reminder_count`, migración **0018**):
  a diferencia de la invitación, que se manda una sola vez, a la misma persona se le
  puede recordar muchas veces —el `remindersent`/`remindercount` de LimeSurvey—. Por eso
  `markReminded()` **no** tiene guarda de una sola vez y **se llama DESPUÉS del
  `send()`, nunca antes**: marcado antes, un fallo le quita a la fila la cola sin que
  el correo llegara. Lo atan `testElRecordatorioEsRepetibleYSumaElContador` y
  `testUnFalloSueltoNoFallaLaTandaDeRecordatorios` (contador 0 en los fallidos, 1 en
  los que salieron).
- **`pendingReminderCount()` NO baja al enviar** — la diferencia con
  `pendingInviteCount()`, donde los invitados salen de la cola: marcar un recordatorio
  no saca a nadie porque siguen sin responder. Por eso el «quedan N» del mensaje NO sale
  de ese contador sino de `max(0, pendingReminderCount() - enviados)`, que es a cuántos
  no se llegó en **esta** tanda (los fallidos y los que quedaron fuera del tope). Con
  60 elegibles y 50 enviados dicen «Quedan 10»; con 5 y 3 salidos, «2 fallaron».
  Confundirlos deja un mensaje falso en los dos sentidos.
- **La tanda rota y se corta con las mismas constantes que la invitación**:  `ORDER BY reminded_at IS NULL DESC, reminded_at ASC, id ASC` (el que no ha recibido
  ninguno primero, y después el más antiguo, para no martillar a los mismos), tope
  `RosterPanel::INVITE_POR_TANDA` (50) y parada a `INVITE_FALLOS_SEGUIDOS` (3) fallos
  seguidos. Compartir constantes es a propósito: el botón y el texto dicen el mismo
  número porque es el mismo.
- **La fecha de cierre es contenido del correo, no del builder**: sale de
  `schedule.close_at` dentro de `deadlineTexto()` y se formatea `d/m/Y H:i` con
  `Clock::displayZone()`. Sin fecha el bloque simplemente no aparece; lo que **no** hay
  es un «cierra el » a la mitad.
- **La puerta es la misma que la de la invitación**: `rosterForm($id,
  'send_invitations')`, `csrfOk()`, identificador `correo` y `Mailer::isConfigured()`
  **antes** de `Mailer::send()`, en ese orden. Un `POST` de recordatorio con cédula o
  sin SMTP responde `ok:false` con el motivo y **no marca a nadie**. La ruta lleva
  `['auth','csrf']` y **no** `post-max` (igual que la de invitaciones: sin archivo, el
  guard solo estorbaría).
- **Dos `<form>` en el mismo partial**, uno por acción, ambos con `data-confirm` /
  `data-confirm-ok` / `data-confirm-tone="primary"` y con los ocultos `rosterQ` /
  `rosterPage` / `rosterSort`. El aviso va a **`vfToast`** (`rosterInvites` es el hueco
  que reponer y **NO** lleva `data-vf-error`, la misma excepción que en la invitación) y
  `$cambia` es `['invites','stats']`: el recordatorio no cambia la lista ni el catálogo,
  así que repintarlos sería un `catalogFor` de más por clic. El botón no aparece con
  `$porInvitar > 0 && $porRecordar === 0` y `$porInvitar === 0 && $porRecordar === 0`
  son dos ramas distintas del partial.
- **Un recordatorio no es «una respuesta por persona»**: nada de esto toca
  `responded_at`. Marcar respuesta sigue siendo solo de `Submission::create()` en el
  fill (quinta argumento de `Submission::create()`).

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

**Preguntas abiertas de la analítica (W2)**

- **`FieldAggregator::TIPOS_ABIERTOS` es la ÚNICA fuente** de qué tipos llevan
  tarjeta de texto libre (`show.php` hace `in_array(...)`): `text`, `textarea`,
  `email`, `url`, `tel`, `time`, `datetime-local`, `date` y `file`. Antes los
  cuatro primeros se descartaban con un comentario y los otros cinco **no caían
  en ninguna rama** del `if/elseif`: la pregunta no se pintaba y no había error
  en PHP, ni en el navegador, ni en el log. `section`/`page` no guardan
  respuestas y no entran; los demás tipos tienen su rama con agregado propio.
  Lo ata `AnalyticsOpenAnswersTest::testLaListaDeTiposAbiertosCubreALosNueve`.
- El listado es de las **últimas** respuestas, no de las más frecuentes: en un
  campo libre cada valor es único, así que la agregación daría un párrafo con
  `c = 1` y sin fecha. `Answer::latestByFieldsInForm()` usa
  `ROW_NUMBER() OVER (PARTITION BY a.field_id …)` y
  `WHERE t.rn <= :limite` **en el nivel exterior**: el techo es **por campo**.
  Un `LIMIT` global a secas deja la segunda pregunta abierta vacía sin que nada
  falle.
- Es la **tercera consulta fija** de `forForm()` y se emite **solo si hay
  campos abiertos**; `from`/`to`/`atr` viajan igual que en las otras dos. Si no,
  la tarjeta enseña respuestas de fuera del rango junto a un contador que sí
  está filtrado: dos cifras distintas en la misma tarjeta.
- **El enmascarado ocurre en `FieldAggregator`, antes de que la vista vea
  nada** (`enmascararAbiertas()` → `Pii::maskValue()`, con la misma `$piiTypes`
  que decide el resto de la pantalla). Aquí **no** se funden iguales como en
  `enmascararValores()`: cada fila es una persona distinta con su fecha.
- La tarjeta vive en `views/analytics/_open.php` (partial, para que su markup
  sea comprobable), el valor se pinta **dos veces** (cuerpo y `title`) con
  `View::escape()` (`ENT_QUOTES`), y el enlace lleva el `sid` de la consulta,
  nunca el índice de la fila. El contador sale de `non_empty`, no de
  `ABIERTAS_MAX`: 400 respuestas dicen 400 aunque solo se listen 5.

**Las vistas de la analítica (W3)**

- **`FieldAggregator::VISTAS` es la lista blanca de vistas por familia y
  `familiaDe()` la única traducción tipo→familia** (`choice`: barras/anillo/tabla;
  `numeric` y `nps`: histograma/resumen; `ranking`: tabla/barras). `matrix` no
  tiene familia (una sola representación), los abiertos tampoco (lista) y
  `section`/`page` no guardan respuestas. Añadir una vista = añadir la clave a esa
  lista y su rama en `_views.php`/`analytics.js`; ofrecer una fuera de ella
  (anillo de 10 000 números, histograma de categorías) es una combinación que
  miente sobre lo que enseña.
- **`dist` se construye desde las filas agrupadas de `numerico()`, sin consulta
  nueva**, así que el histograma y el promedio no pueden discrepar de
  `answer_count` por construcción. Va ordenado por **valor**, no por frecuencia
  (que es como devuelve `valueCountsInForm()`), excluye lo no numérico con la
  misma regex y el NPS rellena 0..10 con ceros para no comprimir el eje. Un
  `number` con más de `DIST_MAX = 30` valores distintos se agrupa en
  `DIST_BINS = 20` barras y **no pierde respuestas** (la suma se conserva).
- **La fila «Total» de la matriz es `colCounts` y el total general es
  `array_sum(colCounts)`, no el total de respuestas de la cabecera**: una fila sin
  contestar deja menos celdas que `respuestas × columnas`.
- **El conmutador es efímero** (`data-vf-view` + `aria-pressed`): no va a la URL
  ni a la BD, no hay migración, ajuste ni clave de i18n, y se pierde al recargar.
  El servidor pinta solo las vistas de la familia y marca una activa; el JS
  **destruye el `Chart` anterior** (`Map` por canvas) antes de repintar, o cambiar
  de vista apila instancias sobre el mismo lienzo. Los datos siguen en
  `data-chart`, nunca en un `<script>`.
- **La barra «Promedio/Mínimo/Máximo» se retiró**: repetía las mini-stats y
  ocupaba el sitio del histograma; el promedio sigue en el resumen y en la tabla
  del ranking.

**La cabecera y los estados de la analítica (W4)**

- **La barra de filtros va en su PROPIA fila**, fuera de `.app-head-actions`: en
  la misma fila, con poco ancho, empujaba el botón de exportar. La cabecera usa
  la común (`.app-page-head`) y el test mira que `analytics-filters` venga
  **después** de `app-head-actions` en el fuente.
- **Los dos campos de fecha llevan su `<label class="visually-hidden">`** con
  `for` al `id` real (`analyticsFrom`/`analyticsTo`): sin él, un lector de
  pantalla anuncia «campo de fecha» dos veces sin distinguir desde de hasta.
- **El índice de preguntas aparece solo con más de tres campos** y son dos
  superficies: la columna `col-lg-3` (anclas `href="#campo-{id}"`, funciona sin
  JS) y el `<select id="analyticsIndexSelect">` de móvil (`d-lg-none`), que
  `initQuestionIndex()` resuelve con `scrollIntoView`. Cada tarjeta es un destino
  con `id="campo-{id}"`; sin ese id las anclas no llevan a ninguna parte.
- **Hay DOS vacíos y no son el mismo**: `$total === 0 && $from === '' && $to === ''
  && $atr === null` es «este formulario no tiene respuestas» (tarjeta con CTA «Ir
  a compartir», nunca un panel de ceros), y un filtro que no casa deja el
  formulario **con** respuestas y solo el detalle dice «Sin respuestas para este
  filtro». El literal del primer estado es «Este formulario aún no ha recibido
  ninguna respuesta»: «Sin respuestas todavía» **colisiona** con el texto del
  partial `_open.php` para una tarjeta abierta vacía, así que un test que busque
  esa cadena casa en los dos casos.
- **La tarjeta «Tasa de respuesta» solo existe en modo `roster`** (es el único con
  padrón del que sacar el denominador): `AnalyticsController::show()` compara
  `FormAccess::mode(Form::settingsFrom($form)) === FormSettings::ACCESS_ROSTER` y
  pasa `$participacion = ['respondidos','total']` con
  `FormParticipant::respondedCountForForm()`/`countForForm()`. Sale «N de M (P%)»
  y **no sigue el filtro de fechas**: es del padrón, no de lo filtrado. Un test
  que cree participantes y submissions a mano **tiene que llamar a
  `FormParticipant::marcarRespondido()`**, porque en la app eso lo hace el POST
  del fill en la misma transacción y sin la marca el contador diría cero
  (`AnalyticsLayoutTest::testLaTasaDeRespuestaApareceSoloEnUnFormularioDeListado`).

**Presentación de la analítica (G1–G6)**

- **El repintado de un gráfico NO puede tragarse el error** (`analytics.js`):
  `pintar()` creaba un `Chart` sobre un canvas con una instancia viva, Chart.js
  lanzaba «Canvas is already in use» y el `catch` solo escribía en consola, así
  que el lienzo quedaba **en blanco sin señal visible** al cambiar de vista. La
  red de seguridad es `soltarCanvas()`, que usa `Chart.getChart(canvas)` (el
  registro interno de Chart.js) y no solo el `Map` `chartsPorCanvas`: una
  instancia huérfana —creada por otra vía o cuyo `destroy()` falló— también se
  suelta. `pintar()` reintenta UNA vez tras un segundo `soltarCanvas`, y el
  `console.error` es el fallo **definitivo**, no el primero. Lo ata el arnés
  `tests/js/analytics-charts.js` (extrae las funciones REALES y las corre contra
  un `Chart` falso que imita el guard): un test que solo leyera el código no
  distingue «suelta el canvas» de «suelta el canvas que está en el Map».
- **La cabecera de una tarjeta conmutable no lleva `flex-wrap`** (`vf-card-head`):
  con `flex-wrap` y una etiqueta larga, el conmutador de vista bajaba a una fila
  nueva. El título va en `.vf-card-title` (con `min-width:0`, que es lo que le
  permite encoger en vez de empujar) y las acciones en `.vf-card-actions`
  (`flex:0 0 auto`). Un `min-width:0` que falte deja el mismo síntoma.
- **El índice de preguntas necesita `min-width:0` en sus items**: son `<a
  class="nav-link text-truncate">` dentro de un `nav.flex-column`, y un flex item
  de columna tiene `min-width:auto`, así que no encoje por debajo de su texto
  (`nowrap`) y **se desbordaba** de la columna `col-lg-3`. El `title` con el texto
  completo se conserva.
- **La tabla de detalle es ANCHA por diseño (una columna por pregunta)**: se pulió
  sin cambiar su estructura. `#` y la cabecera van `sticky` (con fondo sólido, o
  el texto de debajo se transparenta), hay cebra y las celdas son compactas. En
  la media query móvil se **anulan** el `sticky` y el `min-width` (allí la tabla
  es una lista de tarjetas con `data-label`); olvidarlo deja la columna `#` pegada
  sobre tarjetas apiladas.
- **`_rows.php` reformatea un JSON VÁLIDO y no toca el marcador PII**: el
  enmascarado va **antes** de mirar el `[`/`{` inicial, y `[oculto]` empieza por
  `[`. Solo se reformatea cuando `json_decode` da un array (`array_is_list` para
  el checkbox): un valor que solo **parece** JSON se deja intacto y un texto suelto
  se escapa. El checkbox es un chip por valor; la matriz, filas
  `fila → valor`; el ranking se **ordena por el puesto** (1..N), no por el orden
  del JSON. El `<span>` con los chips es HTML ya escapado, así que la celda lo
  imprime sin volver a escapar (el resto sí se escapa). Lo ata
  `AnalyticsChartsTest::testLasRespuestasCompuestasSePintanConChips` y
  `testElMarcadorPiiNoSeReformateaComoJson`.
- **El render de esos chips vive UNA vez, en `View::answerValue()`**: no en cada
  vista. Lo usan la tabla de la analítica (`views/analytics/_rows.php`) **y** el
  detalle de una respuesta (`views/responses/show.php`), que antes pintaba el
  mismo valor con `Answer::displayValue()` («a; b» / «fila: valor; …») y por eso
  el dueño veía el compuesto de dos maneras distintas según la pantalla.
  `answerValue()` devuelve **HTML ya escapado** o `null` cuando el valor no es un
  compuesto (entonces la vista pinta el texto de siempre). El PII se resuelve
  **antes** de llamarlo, así que `[oculto]` cae al lado `null` y no se
  reinterpreta. Lo ata
  `ResponsesTest::testElDetallePintaChipsComoLaAnalitica` y
  `testAnswerValueSoloReformateaCompuestosValidos`.
- **El resumen de una tarjeta numérica ES una vista, no el hueco que deja el
  lienzo**: «Resumen» y «Histograma» son **representaciones mutuamente
  excluyentes** del mismo dato. Las mini-stats (Promedio/Mínimo/Máximo) y, en NPS,
  las insignias Detractores/Pasivos/Promotores van dentro de
  `data-vf-summary-box`, y `aplicarVista()` lo enseña **solo** en Resumen (oculta
  el bloque y el lienzo en el otro sentido). Si el bloque estuviera siempre
  visible, Resumen no aportaría nada distinto de quitar el gráfico —el usuario ve
  las mismas mini-stats en las dos vistas— y **parece roto**: es el síntoma «el
  resumen no se ve», distinto del de las insignias invisibles.
- **Y si esas insignias no se ven, el resumen de un NPS también parece vacío.**
  La trampa es de clase, no de datos: `text-bg-danger-subtle` (y
  `-secondary-`/`-success-`) **NO existe** en el Bootstrap 5.3 vendorizado, así que
  la clase no pinta nada, el `.badge` se queda con su `color:#fff` sobre fondo
  transparente y el texto sale blanco sobre blanco. La pareja correcta es
  `bg-<color>-subtle` + `text-<color>-emphasis` (el borde `border-<color>-subtle`
  sí existe). Lo ata
  `AnalyticsLayoutTest::testElResumenNumericoSeVeYLasInsigniasNpsTienenColor`, que
  además `grep`a el CSS de Bootstrap para comprobar que la clase que se usa existe
  de verdad y que las mini-stats e insignias viven dentro de `data-vf-summary-box`.
  El mismo error de clase estaba en `views/admin/_user_role.php`.

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

**El panel de participantes (fase 5; en su propia pantalla desde el rediseño)**

- **La tarjeta de Acceso y su listado viven en `GET /forms/{id}/access`**, no en la
  ficha. Se separaron porque el listado del roster (pestañas, alta, importación y,
  desde el rediseño, modales) no cabía con dignidad en la columna de los ajustes de
  la ficha. La ficha lleva un botón **«Participantes»** entre «Editar campos» y
  «Respuestas» (`show.php`). El **modo** y el **identificador**
  (`settings.access.mode`) se siguen editando en «Ajustes del formulario» de la
  ficha: `access.php` los LEE. Mismos guards que `show()` (`canEdit`).
- **La pantalla son CUATRO tarjetas a todo el ancho, no una** (rediseño
  2026-10-15): la **cabecera** de acceso (`#access`, que pinta `_access_card.php`),
  el **tablero** (`#rosterBoardCard`: `#rosterStats` + `#rosterInvites`), la
  **lista** (`#rosterListCard`: buscador + filas + paginador) y la **importación**
  (`#rosterImportCard`). Antes iban apiladas dentro de `#access` y una lista real
  se leía como un bloque recargado. `RosterPanel::panel()` lo llama `access.php` y
  **no** `_access_card.php`: la cabecera no cambia con el estado de la lista ni la
  lista con el modo de acceso. Los huecos (`rosterStats`/`rosterInvites`/…) no
  cambian de id, así que `RosterPanel::html()` los repone igual. Los tests que
  pintan el partial `_access_card` solo (`AccessCardDesignTest`, `RosterPanelTest`)
  concatenan el panel en su helper, como la pantalla real.
- **La cabecera de los modales del roster replica la de `/admin`**
  (`modal-header app-navbar text-white border-0 py-2` + `app-logo-sm` + `btn-close
  btn-close-white`), y el **buscador de participantes es un `input-group`** (icono +
  campo + botón «Buscar», en la misma fila que el botón de alta). El botón de alta
  se llama **«Agregar»** (no «Añadir uno a la lista»): rótulo corto y del mismo
  `btn-sm` que «Buscar», para que los dos pesen lo mismo en la fila. El título del
  modal sí dice «Agregar participante». El disparador se localiza por
  `fa-user-plus me-1"></i>Agregar`, no por un texto largo.
- **Los dos modales van a UNA columna** (`col-12`, como el alta de usuario de
  `/admin`): en dos columnas, con tres o cuatro atributos, los campos quedaban
  estrechos y desalineados. El de alta se queda a **ancho por defecto** (el mismo
  patrón que `/admin`) y el de edición bajó de `modal-xl` a **`modal-lg`**: con una
  sola columna, en `xl` los inputs salían de más de 1000 px. Y los **botones del
  modal de edición se alinean en un pie propio**: «Quitar» (`data-confirm`, a la
  izquierda) y «Guardar» a la derecha con `ms-auto`, en un `d-flex` **fuera** del
  `<form>` de edición; el «Guardar» se ata con `form="rosterEditForm<pid>"`, el
  mismo patrón del «Guardar» de la ficha (`vfSubmitButton()` ya busca
  `button[form="…"]`). El error de la fila (`#rosterEditError<pid>`) sigue **dentro**
  del form, que es donde `data-vf-error` lo pinta.
- **La tarjeta «Importar» va en DOS columnas** (`_roster_panel.php`: `col-lg-7`
  «Subir un archivo» con plantilla, formulario e informe, y `col-lg-5` el catálogo
  `#rosterCatalog`), ambas **dentro de un `<details>`** con `summary.vf-import-summary`
  (el tris de colapso es CSS propio, no Bootstrap), y «Vaciar la lista» cierra
  abajo **a todo el ancho pero FUERA del `<details>`**, con su `hr` y su
  explicación: en una sola columna, un catálogo de veinte atributos empujaba el
  formulario de importar fuera de la vista; y como lo único que no se puede deshacer
  viviera plegado, parecería que la función ya no existía. Lo ata
  `AccessCardDesignTest::testVaciarLaListaQuedaFueraDelColapsable`.
- **`FormPanel::accessSlot()` ya no existe** y `json()` no acepta `$extraSlots`:
  guardar ajustes devuelve **solo** los cuatro huecos del estado (`slots()`), no la
  tarjeta. La tarjeta se pinta directa en `access.php`, y su repintado
  (`data-vf-async="slots"` del alta/borrado) la repone por los huecos internos que
  viven en `_access_card.php`, no por un hueco de la ficha. Lo ata
  `AsyncFormActionsTest::testGuardarAjustesNoReponeLaTarjetaDeAccesoQueYaTieneSuPantalla`.
- `roster` tiene su propia rama en `_access_card.php` (solo la cabecera) y su
  propio partial (`_roster_panel.php`: el tablero y la lista, que `access.php`
  compone): meterlo en el `else` habría producido un alta de correo por persona. Y
  el `else` sigue siendo exactamente la allowlist de F4
  (`testLaRamaDeLaAllowlistNoSeHaTocado`).
- **Alta y edición del listado van en MODAL, con la UX de `/admin`** (y se acepta
  perder la edición sin JS): el alta conserva camino sin JS (el formulario se sirve
  EN LÍNEA y `moverFormulariosInline()` de `dashboard.js` lo traslada a
  `#rosterAddModal`, con `data-vf-modal-move="#rosterAddModal"` + `data-vf-modal-form`
  sobre `#rosterAddForm`); la edición por fila es el mismo patrón que la tabla de
  usuarios (`⋯` = `data-vf-participant`, panel `#rowactions-<pid>` que se MUEVE a
  `#rosterEditModal` y se devuelve al cerrar). `dashboard.js` generalizó
  `initTablaAcciones()` a **`initAccionesDeFila(modalId, atributo, prefijoCaja)`**,
  que sirven tanto `/admin/users` como el roster. Los dos modales van a nivel
  SUPERIOR del fragmento (fuera de `#participantes`): un `.modal` anidado lo
  descarta el parser (`ViewStructureTest`).
- **El listado del roster pagina con `views/dashboard/_pagination.php`** (el mismo
  paginador que el dashboard y `/admin`): enlaces reales, `POR_PAGINA = 25`, y
  conserva `q`/`sort` en el `$qs`. Ya no hay «Anterior/Siguiente» propio ni «Página
  X de Y» en el toolbar.
- **El panel son SEIS huecos y ninguno está en un `<form>`**: `rosterStats`,
  `rosterList`, `rosterCatalog`, `rosterAddForm`, `rosterImportReport` y
  `rosterInvites`. Los tres markup salen de `RosterPanel::html()`, que pinta **los
  mismos partials** que la pantalla: con dos copias, la lista repuesta tras un
  borrado y la que se vería recargando divergirían sin que nada fallara.
- **Cada acción declara qué huecos cambian** (`$cambia` en `ok()`): alta/edición/
  borrado/vaciar → lista+números+invitaciones; baja de atributo →
  lista+catalogo+alta; importación → los seis. Reponerlos todos hace un
  `catalogFor` por alta.
- **`rosterInvites` NO lleva `data-vf-error`**, y es la excepción a la regla de los
  formularios con ese atributo: en un `ok:false` por fallos el servidor devuelve `html`
  con el slot repintado, así que el nodo del error quedaría **desacoplado** y no se
  vería nunca. El resultado va a `vfToast`. El hueco se repone en alta, edición,
  borrado, vaciado, importación y el propio envío (lo que cambia el número de
  pendientes), y **no** en `destroyAttribute`, que no toca correos.
- **El alta NO repone `rosterAddForm`**: el repintado borraría lo que el dueño
  tiene a medio escribir y el foco que devuelve `data-vf-clear` apuntaría a un nodo
  que ya no está.
- **Las pestañas del roster se retiraron con la separación Lista/Importar**: no
  quedan `vf-tabs*` en el CSS, ni `initTabs()`/`vfInitTabs` en `dashboard.js`/`app.js`,
  ni `data-vf-tab` en ninguna vista. Lo que antes se alternaba son **dos tarjetas
  apiladas** (lista, importación), así que el `<details>` es el único colapso que
  queda y es **nativo** (se ve y funciona sin JS). `testLaTarjetaDeImportarSePliegaConCssPropio`
  además mira que `.vf-tabs` no haya vuelto al CSS: verificar que una función retirada
  sigue funcionando no dice nada de la pantalla nueva.
- **`#vfPaneRosterLista` y `#vfPaneRosterImportar` conservan `data-vf-pane` sin
  `hidden`**: son los dos identificadores con que los tests (y el CSS) distinguen
  las zonas, y el servidor sigue pintando las dos a la vista. Si empezara a pintar
  `hidden`, una tarjeta entera se perdería y ningún test de PHP lo notaría
  (`testLasDosZonasSePintanLasDosYNingunaVieneEscondida`).
- **La búsqueda del listado es AJAX y cambia la URL por `pushState`**: el form
  `data-vf-roster-search` de `_roster_panel.php` se envía con
  `X-Requested-With`, el servidor (`FormController::access()`) responde solo
  `['rosterList' => …]` cuando esa cabecera va con modo `roster`, y
  `initBuscarRoster()` repinta `#rosterList` y escribe `?q=…&page=…&sort=…`.
  **Sin la cabecera la misma ruta sirve la página entera**, que es el camino nativo
  y el fallback de `form.submit()`: un `fetch` que no devuelve JSON no mata el
  formulario. `popstate` re-sincroniza el campo. Lo atan
  `AccessPageTest::testLaBusquedaPorAjaxRespondeJSONConElHuecoDeLaLista` y
  `testSinCabeceraJsonSigueSiendoLaPaginaEntera`, y
  `RosterPanelTest::testElBuscadorDelRosterSeEnviaSinRecargar`.
- **`initAccionesDeFila()` es DELEGACIÓN en `document`** (con guarda
  `data-vf-actions-ready`), no un listener por botón: repintar `#rosterList`
  tras buscar o borrar sustituiría nodos con listeners colgados y los `⋯` de las
  filas nuevas dejarían de abrir el modal. Por eso no hace falta re-correr
  `vfInitAdminModals()` después de cada repintado.
- **La búsqueda y el orden viajan en la URL**
  (`/forms/{id}/access?q=…&page=…&sort=…`), y las acciones por fetch los reponen en
  `rosterQ`/`rosterPage`/`rosterSort`. Sin ellos, borrar a alguien salta a la primera
  página y a ordenar por cédula, y el cambio parece un fallo de la operación.
- **La búsqueda es un `LIKE '%…%'`**, así que `V-1` también casa con `V-10`. Un test
  que filtre esperando una fila tiene que usar cédulas de ancho fijo (`V-01`).
- **El informe de la importación llega por los DOS caminos** y son decisiones
  distintas: con JS va en `html.rosterImportReport` —**también con `ok:false`**, que
  es cuando más falta hace, y `app.js` lo aplica antes de pintar el aviso—; sin JS
  va en `Session::flashValue('roster_report')`, que consume `FormController::access()`
  con `takeValue()` (un solo uso). Solo se guarda en sesión en el camino nativo: si
  se guardara también con JS, el informe reaparecería en la siguiente carga de la
  pantalla.
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
- `ViewStructureTest` recorre las 70 vistas como el parser del navegador: ninguna
  etiqueta sin cerrar, y **ningún `.modal` dentro de otro elemento** (todo lo que lo
  contiene está oculto, por definición casi). Los dos tests llevan guard
  anti-vacío —`count($vistas) >= 50` (hay 70), `> 1800` aperturas vistas y
  `>= 6` modales encontrados—
  porque un test de estructura que no recorre pasa verde por construcción, y así
  pasó al escribirlo (`foreach` sobre el `int` de `preg_match_all`).
- **Ante un síntoma de «no aparece», mide antes de arreglar**: un
  `getBoundingClientRect()` a 0×0 significa que el elemento no se ve **por un
  ancestro**, que es un diagnóstico distinto del de «es demasiado alto y se sale por
  arriba». La medición de `show.bs.modal` en `builder.js` (rectángulo, `top`,
  viewport, ancho) está para eso, y así un 0×0 manda a mirar la vista.
- **Las vistas de un formulario comparten el ancho de contenido**
  (`.container px-lg-4`): ficha, builder, participantes, respuestas **y el detalle
  de una respuesta**. El builder iba en `container-fluid` (ancho completo), así
  que su lienzo era más ancho que el resto y la misma pantalla «se movía» al
  entrar y salir del constructor; el detalle de una respuesta tenía un
  `max-width: 860px` en línea que lo dejaba más estrecho que el listado del que
  salía. El dashboard y la analítica siguen a ancho completo a propósito. Y el
  detalle usa la **cabecera común** (`.app-page-head` + `.app-head-actions`), con
  su «Borrar» en la fila de 44px reusando la ruta delete del listado. Lo ata
  `FormFichaLayoutTest::testLasVistasDeFormularioCompartenElAnchoDeContenido`.
- **Los campos del panel se distinguen de la tarjeta blanca y sus labels van en
  negrita**: acotado a `.app-content`, `.form-control`/`.form-select`/
  `.input-group-text` toman el off-white `#fbfcfe` (el mismo valor que el fill usa
  en `.vf-control-wrap`), el foco vuelve a blanco y `.form-label`/`.col-form-label`/
  `legend.form-label` suben a `font-weight: 600`. No se comparte clase con el fill,
  se comparte el valor; el formulario público y las pantallas de auth quedan
  fuera porque no cuelgan de `.app-content`. Y `.app-head-actions` queda pegado a
  la derecha (`justify-content: flex-end` + `margin-left: auto`): con solo
  `space-between` en el contenedor, al envolver la fila de acciones caía al borde
  izquierdo. Lo ata
  `FormFichaLayoutTest::testLosCamposDelPanelSeDistinguenYLosLabelsVanEnNegrita`.
- **Las tres filas de botones del panel miden lo mismo y van a la derecha.** La
  de cabecera (`.app-head-actions`, ya existía), la sub-navegación de `/admin`
  (`.app-admin-nav`: son `nav-link` de un `nav-pills`, no `.btn`, y estaban
  sueltas a la izquierda) y el pie de los modales
  (`.modal-footer .btn`, donde un «Cancelar» de 38px convivía con el CTA de
  44). Las tres toman `min-height: var(--vf-btn-cta-h)` + radio 12; la
  nav de `/admin` añade `justify-content: flex-end`, **resetado a `flex-start`
  en móvil** porque con `overflow-x: auto` un `flex-end` deja los primeros
  destinos fuera del alcance del scroll. Lo atan
  `ContentButtonsMobileTest::testLaSubNavegacionDeAdminVisteComoUnBotonDeCabecera`
  y `FormFichaLayoutTest::testElPieDeLosModalesUnificaLosBotones`.
- **El pie del modal NO se acota a `.app-content`** (selector `.modal-footer .btn`
  a secas, y el test lo comprueba con un `assertFalse` sobre el CSS viejo): el
  diálogo de confirmación `#vfConfirm` es hijo directo de `<body>`, así que con el
  selector acotado sus dos botones se quedaban en 38px al lado del resto de la app.
- **Un botón de guardar dentro de una tarjeta mide 44, igual que el modal al que su
  formulario se traslada.** `.app-form-cta` da `min-height: var(--vf-btn-cta-h)`
  + radio 12 + peso 600 **sin tocar color ni añadir sombra** (los pone cada
  variante), y es lo que usan los submits de `/admin`, del roster y de la ficha
  que no son primarios (`btn-outline-danger`, `btn-outline-success`…); los que ya
  eran primarios llevan `app-btn-primary`, que mide 44 de por sí. **No se suman
  las dos** (la sombra quedaría por encima del radio) ni `btn-sm`, que es de
  32px y se reserva para controles de verdad compactos (dentro de un
  `input-group-sm`, iconos de fila). Lo ata
  `FormFichaLayoutTest::testElAltoDelCtaDeFormularioEsElMismoDentroDeUnaTarjeta`.
- **El botón de un `input-group-sm` va con `btn-sm`**: el `.btn` de 38 junto a un
  `form-control-sm` de 32 se nota, y no da ningún error en ningún sitio.

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
  queda en borrador y pierde la aprobación (`Form::returnToDraft()`, la misma
  regla del builder y de `update()`), así que el JSON de `updateSettings()`
  devuelve **la insignia del estado que queda**. Si devolviera solo «Ajustes
  guardados», el panel dejaría de mentir por poco.
- **Un hueco va con lo que depende de SU estado, no con lo que se repinta de
  más**: `slots()` son los cuatro de `forms.status` y se devuelven en todas las
  acciones de estado. La tarjeta de Acceso **ya no es un hueco de la ficha**: tiene
  pantalla propia (`GET /forms/{id}/access`) y la pinta `access.php`; su repintado
  interno (alta/borrado de la allowlist) va por `data-vf-async="slots"` y repone
  sus huecos propios, no los de la ficha. Al añadir un hueco, pregunta de
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

**El listado del panel (M2)**

- La búsqueda, los filtros por estado y el orden **viajan en la URL**
  (`/dashboard?q=…&estado=…&orden=…`) y los decide `Form::page()`. Antes eran de
  cliente sobre el listado entero, y con el `LIMIT` en SQL eso solo habría roto
  la pantalla: buscando «norte» se pintaban las doce tarjetas de la página y se
  ocultaban todas. **Un filtro que no va en la consulta no es un filtro**: es un
  filtro que funciona con doce filas.
- Los dos vacíos se distinguen por **`$stats['total']`** (el alcance entero), no
  por `empty($forms)` ni por `$listado['total']`: los dos últimos valen cero
  cuando un filtro no casa con nada, y con el criterio viejo salía «Aún no tienes
  formularios» con su botón de crear el primero, que es mentira con catorce.
- Las **tarjetas de arriba son del alcance entero y sin filtros**: son datos de la
  instalación, no de lo que hay en pantalla. De ahí que `counts()` sea una
  consulta aparte (y la cacheada) en vez de un `foreach` sobre la página. Lo
  atan `testLasCuentasSonDelAlcanceEnteroYNoDeLaPagina` y
  `testLaUrlMandaYLasTarjetasNoCambianConElFiltro`.
- El **conteo de respuestas va sobre los ids de la página**, no sobre el alcance:
  son dos consultas (`rowsDeListado()`) porque el agregado de la segunda solo
  cuenta las respuestas de las doce tarjetas que se pintan. Los marcadores van
  numerados **y por juego** (`:c0…` para el `IN` del agregado, `:w0…` para el
  `WHERE`): `Database` va con `ATTR_EMULATE_PREPARES = false` y un marcador
  repetido lanza HY093. El `CONCAT` del buscador es el mismo motivo: dos `LIKE`
  con `:q` repetido también lo lanzarían.
- **Todo `ORDER BY` acaba en `f.id`**: `updated_at` se empata entre formularios
  creados en el mismo segundo y el título entre dos formularios con el mismo
  nombre, y sin desempate la frontera entre páginas es indeterminada — un
  formulario sale en dos páginas o en ninguna. Lo vigila
  `testElOrdenNoReparteUnFormularioEnDosPaginasAunqueSeEmpaten`.
- Una página de la URL **por encima del final se recorta a la última**, no pinta
  un grid vacío: con un filtro puesto es el camino normal —filtrar deja menos
  filas de las que había—, y una URL escrita a mano o un enlace viejo también
  llegan así. Lo que **no** lo justifica ya es la caché (solo cachea las
  cuentas, no el listado).
- El paginador son **enlaces**, no botones: la página vive en la URL, el «atrás»
  funciona y el enlace se puede copiar. Y **conserva los filtros**: un
  `/dashboard?page=2` sin el `q` es la página 2 de todo lo demás. Lo atan
  `DashboardListMarkupTest`.
- `Form::page()` es **la única forma de listar formularios**: `byUser()` y `all()`
  ya no existen (devolvían el alcance entero). `/admin/forms` la usa con 20 por
  página y sin filtros.
- Lo `%` y el `_` de lo que se escribe en el buscador **se escapan**
  (`addcslashes`): sin eso, buscar «100%» devuelve el alcance entero y parece un
  buscador roto.
- **El listado de usuarios de `/admin` usa la misma regla** (`User::page()`,
  espejo de `Form::page()`): buscador (nombre o correo), filtro por rol y por
  estado viajan en la URL y se resuelven en SQL, con `LIMIT` de 25 y el
  paginador reponiendo los tres. Un filtro de cliente sobre la página pintada
  diría «sin resultados» de la persona 26. El paginador se comparte con el
  listado de formularios (`views/dashboard/_pagination.php`), que acepta
  `$sujeto`/`$aria` para no decir «formularios» en la pantalla de usuarios.
  El buscador del dashboard NO se reutiliza aquí (`data-vf-dash-search` está
  atado a `#dashSearch`/`#dashSort`): los desplegables van con `js-auto-submit`
  y el texto con `Enter`.
- **La fila de usuarios tiene UNA sola puerta de acciones** (fase 3): un botón
  `⋯` (`.app-row-menu-btn`, `data-bs-auto-close="outside"`) abre el menú
  `.app-row-menu` con los cuatro formularios (rol con `js-auto-submit`, grupos,
  permisos extra y el toggle de estado). Las columnas muestran el VALOR
  (insignias), no controles: por eso cambiar el rol **sí** devuelve un hueco
  (`vfUserRole<uid>`, `views/admin/_user_role.php`) y no solo el aviso. La fila
  propia (uno mismo) no ofrece el menú: cambiar el rol o el estado de uno mismo
  no es una operación del listado. Los ids de los huecos van en `<span>` que
  **envuelven** a cada `<form>`, nunca dentro: el repintado mete markup dentro
  del nodo y un formulario anidado lo descarta el parser.
- **En móvil la tabla se apila en tarjetas** (`.app-admin-table`, ≤991.98px):
  la cabecera se oculta y cada `<td>` pinta su `data-label` con `::before`. Una
  celda nueva sin `data-label` sale sin nombre y no da ningún error; a qué
  rótulo corresponde lo vigila `AdminRbacTest`. El menú `⋯` vive dentro de
  `.table-responsive` (`overflow-x: auto`, que también recorta en vertical), así
  que su `max-height` está topado a `min(60vh, 24rem)`: un menú más alto se
  sale por abajo y queda cortado (y no lo caza ningún test de PHP, se vio en el
  navegador).

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
  permisos efectivos = rol propio + roles de los grupos + **permisos directos**
  (`user_permissions`).
- El catálogo de permisos está **duplicado**: `config/permissions.php` (fuente; la
  lee la migración 0005 en runtime) y el bloque estático de `database/seed.sql`
  (instalaciones limpias). Si cambias una clave o un rol por defecto, toca **los
  dos**; lo fija `PermissionSeedParityTest`.
- `updateRoleMatrix()` **rechaza** un POST sin la estructura `perms[<role>][]`
  (un cuerpo vacío no significa "desmarcar todo") y el rol `admin` siempre conserva
  `admin.access`.
- Un rol nuevo se crea con `Role::create()` y **nace sin permisos**: la matriz es lo
  que decide qué puede hacer. El re-seed de las migraciones (0005, 0006, 0011, 0016)
  solo otorga a los roles con nombre del catálogo, así que un rol inventado no se lo
  lleva nadie por el camino.
- **La cuarta fuente son los permisos DIRECTOS** (`user_permissions`, migración
  **0017**): dar UN permiso a UNA persona sin cambiarle el rol (que mueve todo su
  conjunto) ni meterla en un grupo (que se lo da al equipo). Se editan desde la
  tercera columna (*Permisos extra*) del menú `⋯` de su fila en `/admin`
  (`#userActionsModal`; gate `admin.users`, el mismo del rol: mueve la autorización
  de esa persona) y caen en cascada al borrar el usuario. `User::setPermissions()`
  **reemplaza la lista completa**: un POST sin casillas quita todos los directos, y
  las claves fuera del catálogo se descartan en silencio (el catálogo no se borra).
  `Auth::permissions()` es la unión de las tres fuentes; `User::hasPermission()` y
  `User::reviewers()` la reproducen en SQL con tres `EXISTS`, y **tienen que ir con
  ella** o la revisión de la instancia ignoraría una exención concedida a mano.
  `User::effectivePermissionsMap()` da el desglose por origen (rol/grupos/extra) que
  el desplegable pinta como auditoría.
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
- **El tipo y la obligatoria se cambian desde el PIE de la tarjeta, sin abrir
  ajustes** (Google Forms, F2): `change` en `select.app-type-select` llama a
  `changeFieldType`, y `setFieldRequired()` escribe `f.required` a pelo —no vía
  `applySettings()`, que parte de `preservedValidations(f)` y aquí no hay nada
  que preservar—. El estado del toggle se pone al renderizar cada tarjeta, no
  al arrancar: un `#requiredToggleN` nuevo (duplicado, cambiar de tipo) tiene
  que reflejar el valor en memoria y no el del servidor.
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
- El engranaje de cada tarjeta abre el **modal de ajustes de campo**, centrado y
  con las mismas clases de diálogo que el de agregar elemento
  (`modal-dialog-centered modal-lg modal-dialog-scrollable` sobre el `.modal` de
  Bootstrap: Esc, backdrop y foco de siempre). `#settingsForm` vive ahí desde el
  arranque, **una sola instancia, sin moverse** entre superficies; cerrar el
  modal devuelve la selección a la tarjeta y, como `openFieldSettings` redibuja
  el lienzo, el opener se localiza por `[data-uid]`. El pie del panel de
  formulario (`modal-footer border-0`) es **hermano** de `modal-body` para
  quedar anclado abajo. **No hay cajón lateral**: la clase `.modal-drawer` que
  hubo en F2/F3 se retiró (iba en el mismo elemento que `.modal-dialog`, cuando
  el selector la esperaba como ancestro, así que nunca se aplicó: se veía como
  un modal por defecto de 500px con una sola esquina redondeada). El título del
  panel de campo es fijo (`Configuración del elemento`), no la etiqueta de la
  pregunta. El botón **Vista previa** (`#btnLivePreview`) abre el formulario en
  **otra pestaña** (`/forms/{id}/preview?draft=1`); el modal `#livePreviewModal`
  sigue en la vista pero ya no lo abre nadie (su retiro va en una fase aparte),
  y el preview embebido que pintaba debajo del lienzo se retiró (quedaba fuera
  de la vista y parecía roto). Esa superficie no lleva `width` por su id
  (`FillLayoutTest::testNadieReabreElAnchoDelPreviewDelConstructor` la ata al
  ancho del publicado). Spec de UI: `docs/design.md` (§3.9, §3.10, §4.2).
- **La vista previa lee el BORRADOR siempre, y se vacía al abrir**:
  `openLivePreview()` pone `?draft=1` **sin condición** —el servidor cae a la
  estructura viva si no hay borrador— y después llama a `vaciarBorrador(win)`.
  La query no depende de `data-vf-has-draft`: ese dato lo sabe el HTML **al
  cargar**, así que un borrador que el autosave creaba después no se reflejaba
  en la vista previa hasta recargar el *builder*, y la URL de la pestaña no la
  ganaba sola al recargarla. `vaciarBorrador()` cancela el debounce de 2 s,
  espera una escritura en vuelo (`draftPromise`), decide comparando
  `buildPayload()` contra `lastDraftJson` (lo que el servidor tiene; solo
  avanza con un `{ok:true}` real) y **recarga la pestaña abierta** si escribió:
  abrir la ventana va primero a propósito, porque dentro de un `await` el
  bloqueador de ventanas puede tumbar la apertura. El mismo vaciado corre en
  `visibilitychange` (builder oculto, **sin** pestaña): es el instante en que
  el usuario se va a la vista previa a pulsar F5, y lo último tiene que estar
  ya en la BD. Un `saving` en vuelo lo descarta (el commit limpia el borrador
  y actualiza la marca; escribir ahora lo recrearía). Lo ata
  `BuilderPreviewFreshnessTest`.

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
- **Estrellas y ranking se responden con el ratón, y sin JS el contrato no
  cambia.** Las estrellas siguen siendo un `radio` 1..max y su valor es el mismo:
  `paintStars()` solo colorea las anteriores (`is-lit`, con preview al hover), un
  relleno cosmético. El ranking gana botones ▲▼ que **mueven la fila y renumeran
  1..N por orden visual**, de modo que el orden **pasa a ser la respuesta**; pero
  los `name` siguen atados a la opción original (`field[i]`), así que el mapa
  opción=>orden que espera el servidor (`normalizeFieldValue`) no se toca —por eso
  `moveRanking()` no puede escribir `name`—. **Con JS el input numérico se oculta**
  (clase `vf-ranking--js` que pone `syncRankingOrder()`) y mandan las flechas; **sin
  JS la clase no se pone y queda el campo número**, que es la única forma de
  responder y lo que lee el servidor: el input sigue en el DOM y no se elimina.
  `setFieldValue()` vuelve a ordenar las
  filas al restaurar el borrador (`syncRankingOrder`), o lo restaurado se leería en
  el orden original. Las claves `ui.rank_up`/`ui.rank_down` viven en `es.php` y
  `en.php`, no en el
  `FALLBACK_I18N` (las pinta la vista, no `fill.js`). Lo atan
  `FillInteractionTest` y `tests/js/fill-ranking.js`.
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
- `settings.theme.font` es una **lista cerrada** (`FormSettings::fonts()`, cuatro
  familias auto-hospedadas en `public/assets/fonts/`) y se valida en **las dos
  puertas**, `normalize()` (lo que entra) y `themeCss()` (lo que sale), porque
  puede haber filas ya guardadas con valores de cuando era texto libre y
  filtrar solo al guardar no las limpia; `validate()` mira el valor **crudo**,
  no el normalizado. Como familia y archivo salen del mapa, la inyección de CSS
  de la fase 2 ya no tiene punto de entrada, y `ThemeFontCssInjectionTest`
  comprueba el catálogo en las dos direcciones (familia sin archivo y `.woff2`
  fuera del catálogo).
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
- `docs/pruebas-funcionales.md`: 572 comprobaciones manuales en 31 bloques (A–Z, AA,
  las regresiones R, la tanda visual S, las acciones sin recarga T, el
  diálogo de confirmación con el alta de la allowlist U, la pantalla de
  participantes V, el identificador de la lista W, el filtro de la analítica
  por atributo X, la comparación por atributo con su techo Y, quién respondió Z,
  el correo de invitación AA, el listado paginado del panel AB, la galería del
  modal de nuevo formulario AC, la consola de administración con modales AD y el
  recordatorio por correo AE), para detectar
  problemas que la suite no ve
  (teclado, maquetación, flujo en pantalla). Al tocar el builder, el fill o el
  panel, R, S, T, U, AA y AE son la
  lista mínima.
- `docs/decisiones.md`: por qué se cambió cada cosa (archivo histórico).
- `docs/plan-analitica-2026-10-19.md`: plan de rediseño de la pantalla de
  analítica (W1–W4 con su selector de gráfico efímero); su tabla de estados se
  actualiza al cerrar cada fase.
- `docs/plan-analitica-ux-2026-10-19.md`: correcciones de presentación de la
  analítica (G1–G5: repintado de gráficos, cabecera, índice, tabla de detalle y
  respuestas compuestas); su tabla de estados se actualiza al cerrar cada una.
- `docs/evaluacion-propuestas.md`: qué se decidió en F0–F5 y por qué; las seis
  cerradas.
- `docs/technical-document.md`: arquitectura y modelo de datos (23 tablas, con
  F2/F3/F4, RBAC y el listado de participantes con su `invited_at`).
- `docs/plan-remediacion-2026-09-27.md` y `docs/roadmap-encuesta.md`: fases 0–6 y
  P1–P10 con su diagnóstico, **todas cerradas**.
- `docs/plan-participantes-2026-10-04.md`: fases 0–12 del listado de participantes,
  con `invited_at` y el correo de invitación; su tabla de estado es la que se
  actualiza al cerrar cada fase.
- `docs/plan-admin-rediseno-2026-10-14.md`: rediseño de usabilidad de la consola
  `/admin` (fases F0–F6); su tabla de estado se actualiza al cerrar cada fase.
- `docs/design.md`: spec del builder (tokens, componentes, reglas para código
  nuevo).
- `docs/api-spec.md`: referencia de endpoints. Para el middleware EXACTO de cada
  ruta, `config/routes.php` sigue siendo la fuente de verdad.
- `docs/audit-report*.md`, `fix-plan.md` y `levantamiento_informacion.md` son
  auditorías ya cerradas: histórico.
- `freddy/`, `freddy.zip` y `pub.jar` son un toolkit externo de "levantamiento de
  información", **no código de la app** (y están en `.gitignore`). No los toques.
