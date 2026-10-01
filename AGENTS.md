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

- Suite completa: `php scripts/test.php` → 67 archivos, 642 métodos, ~2.6 min;
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
- **node es opcional**: solo lo usa `tests/unit/FillProgressTest.php` para correr
  el arnés `tests/js/fill-progress.js`; sin node ese test avisa y pasa.

Atajos de OpenCode (`.opencode/`, no es código de la app): el skill `vf-docs`
indexa `docs/` (con sus trampas de obsolescencia) y el skill `vf-migraciones` da la
receta de migración idempotente; los commands `/test`, `/migrate` y
`/nueva-migracion` ejecutan lo anterior.

## Arquitectura

- `public/index.php` → `config/bootstrap.php` (carga `.env` con `App\Core\Env`,
  fija **UTC** en PHP, conecta la BD, inicia sesión) → `config/routes.php` →
  controlador. `public/index.php` es el único PHP que ve el web server.
- Autoload PSR-4 propio `App\` → `app/` (`config/autoload.php`): `Controllers` (11),
  `Core` (el middleware vive en `Core/Middleware/Middleware.php`; `app/Middleware/`
  es un directorio **vacío**), `Models` (8), `Services` (12), `lang/{es,en}.php`.
  `views/` = 42 plantillas con `View`; `public/assets/js/` = `builder`, `fill`,
  `dashboard`, `analytics`, `app`, `print` + 3 librerías vendorizadas.
- Config: `.env` con fallbacks en `config/config.php`. `Config::get()` **sí**
  admite notación de punto (`paths.uploads`, `app.locale`): accede así a las
  claves nuevas y verifica que no devuelve el default en silencio.
- Middleware por string en la definición de la ruta: `auth`, `guest`, `admin`,
  `csrf`, `csrf-header`, `post-max` y `editor` (registrado, **aplicado a cero
  rutas**; no lo uses como si hiciera algo).
- Roles: `admin`(1) / `user`(2, default) / `editor`(3).

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
- **39 de los 67 archivos tocan la BD real** (los que llaman
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

- `settings.access.mode` tiene **tres** valores: `public` (default, para no
  romper lo existente), `email` (cualquiera que reciba el enlace) y `allowlist`
  (solo emails de `form_access_emails`). La allowlist se edita **en la ficha del
  formulario**, no en el builder.
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
- **Los cuatro canales de salida se enmascaran**: detalle, listado, `exportJson`
  y `exportCsv`. Enmascarar en la vista y no en la exportación deja un canal
  limpio. En el CSV se enmascara **dentro** del bucle de campos, después de
  decodificar: `[oculto]` empieza por `[` como un JSON y el bloque lo
  reinterpretaría.
- El bloqueo de **adjuntos** va en `AttachmentController::download` (403 si el
  campo es PII sin el permiso), no en la vista: `/attachments/{id}` es
  adivinable por el id correlativo de `answers`.

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
  que decide qué puede hacer. El re-seed de las migraciones solo otorga a los roles
  con nombre del catálogo, así que un rol invented no se lo lleva nadie por el camino.
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

**Builder (JS)**

- `buildPayload()` **mapea explícitamente** `parentId` (camelCase, en memoria) a
  `parent_id` (snake_case, lo que lee el servidor): es la única frontera entre
  ambas convenciones. Lo fija `BuilderPayloadContractTest`.
- `applySettings()` **nunca** reconstruye `validations` desde cero, parte de
  `preservedValidations(f)`. Regla dura: **toda clave en `MANAGED_VALIDATION_KEYS`
  necesita control Y listener** (el par clave→control está en `CONTROL_FOR_KEY`, de
  `BuilderValidationsContractTest`).
- Ocultar tiles estructurales dentro de una sección es
  `setAttribute('data-vf-structural-hidden','1')` + selector
  `[data-vf-structural-hidden]` **por presencia**: `toggleAttribute()` deja el valor
  `""` y el selector nunca casa.
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
- `docs/pruebas-funcionales.md`: 199 comprobaciones manuales en 17 bloques (A–P)
  más regresiones (R), para detectar problemas que la suite no ve (teclado,
  maquetación, flujo en pantalla). Al tocar el builder, el fill o el panel, R es
  la lista mínima.
- `docs/decisiones.md`: por qué se cambió cada cosa (archivo histórico).
- `docs/evaluacion-propuestas.md`: qué se decidió en F0–F5 y por qué; las seis
  cerradas.
- `docs/technical-document.md`: arquitectura y modelo de datos (16 tablas, con
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
