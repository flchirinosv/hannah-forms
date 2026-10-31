

## AG5 — Claves de Cloudflare Turnstile por UI (2026-10-21)

Mueve las claves de Turnstile del `.env` al panel. El anti-bot es **opt-in por
formulario** (`settings.anti_bot.enabled`), pero las claves son de la
**INSTANCIA**: sin ellas, un formulario que las pide **no se sirve** (`fill/closed`,
POST con 403). Mismo patrón que marca/correo/identidad: fila en `app_settings`
gana; sin fila, `.env`.

- Nuevo `App\Core\TurnstileSettings`: `all()` (`stored_site_key`, `env_site_key`,
  `secret_stored`, `configured`), `siteKey()`/`secretKey()` (fila descifrada →
  `.env`), `secretStored()`, `save()`. **Vaciar** la site key borra su fila; la
  secret es **solo escritura** (`clear_secret` para borrar, valor nuevo para cifrar,
  vacío = no tocar). La **coherencia es obligatoria**: `save()` calcula el estado
  EFECTIVO (aplicando este guardado al estado resuelto) y, si queda **una sola**
  de las dos claves, rechaza **sin escribir nada** («medio configurado» dejaría
  todos los formularios anti-bot sin servir). Sin `APP_KEY`, guardar la secret
  **falla explícitamente** (nunca se escribe en claro). Un `APP_KEY` cambiado no
  deja la instancia sin anti-bot: si `Secret::decrypt()` no puede descifrar la fila,
  `secretKey()` cae al `.env` completo y `secretStored()` pasa a `false`.
- `App\Services\Turnstile` lee las claves por `TurnstileSettings` (`siteKey()`/
  `secretKey()`), conservando el seam `setKeysForTesting()` para los tests.
- `AdminController`: `updateTurnstile()` con PRG, `Auth::can('admin.access')`,
  CSRF, flash y evento `instance.turnstile_update`. Ruta
  `POST /admin/settings/turnstile`. `settings()` inyecta `turnstile` y
  `secretAvailable`.
- Vista: tarjeta «Anti-bot (Cloudflare Turnstile)» con input `password` vacío
  para la secret, casilla `clear_secret`, insignia «guardada», aviso si no está
  configurado o si falta `APP_KEY`, y nota de que se necesitan **las dos**.
- Tests: `TurnstileSettingsTest` (11: precedencia, cifrado de la secret, caída
  al `.env` con `APP_KEY` cambiado, coherencia todo-o-nada, borrado), +2 en
  `AdminSettingsPageTest` (endpoint + la secret no se devuelve al HTML). Las dos
  claves entran en `ControllerActionsTest::APP_SETTINGS_KEYS` y el smoke ejercita
  el POST. Documentación actualizada (AGENTS, manual, pruebas-funcionales
  bloque AI, technical-document, `.env.example`).

## AG11 — Instalador guiado web (2026-10-21)

Cierra el arranque sin SSH. Hasta ahora instalar exigía: crear la BD
(`mysql < schema.sql` + `seed.sql`), copiar el `.env`, generar `APP_KEY` y crear
el primer admin con `php scripts/create-admin.php`. Todo eso lo hace ahora un
asistente en `/install.php`.

- **Entrypoint propio, no una ruta.** `config/bootstrap.php` conecta la BD y
  `exit` si falla; en una instalación nueva la base no existe y ninguna ruta de
  `config/routes.php` llegaría a dibujarse. Por eso `public/install.php` carga
  solo el autoload + `.env` (opcional) y levanta sesión y CSRF propios.
- **`App\Services\InstallGuard`**: gate de «ya instalado» con el marcador en disco
  `storage/installed.lock` (JSON con fecha y email del admin, `0600`). No se puede
  preguntar a la BD porque a mitad de instalación puede no existir. Tras marcar,
  `install.php` responde 403 salvo `?force`. También revisa los requisitos (PHP,
  extensiones, permisos de escritura).
- **`App\Services\Installer`**: conecta con PDO **sin `dbname`**, hace
  `CREATE DATABASE IF NOT EXISTS`, y aplica `schema.sql` + `seed.sql` **reescribiendo
  su `USE vanilla_forms;`** por la base elegida (el `sqlSinUse` es puro y estático
  para poder probarlo). Luego corre las migraciones pendientes y crea el primer
  admin reutilizando `AuthController::validateNewUser` y resolviendo el rol `admin`
  **por nombre**. `schema.sql` usa `CREATE INDEX` plano: está pensado para una base
  **vacía** y no es idempotente (AGENTS.md ya lo avisa).
- **`App\Core\EnvWriter`**: escribe el `.env` de forma **atómica** (tmp + `rename`),
  preservando comentarios y orden, sin duplicar claves, con permisos `0600`. El
  cargador (`Env::load`) solo leía hasta ahora; el instalador es el primer escritor
  programático.
- **`APP_KEY` no se sobrescribe jamás**: solo se genera si no había una utilizable.
  Sobrescribirla perdería el descifrado de los secretos guardados.
- La contraseña de BD y la del admin nunca se muestran ni se guardan en el lock.
  El CSS del asistente (`.app-install-*`) va en `app.css` porque la CSP prohíbe
  `on*=` e inline salvo `style-src 'unsafe-inline'`.

## AG12 — Personalización ampliada de color y logo estirado (2026-10-22)

La marca (AG2) solo dejaba tocar el color **primario**; el degradado de
navbar/sidebar/login seguía clavado en el código, y lo mismo dentro del formulario
(`settings.theme`: `primary` + `background`). Una instancia con marca propia
quedaba a medio teñir. Y en pantalla estrecha el logo subido salía **aplanado**.

- **Cinco colores en las dos superficies** (instancia `Brand` y formulario
  `FormSettings::theme`): `primary`, `gradient_start`, `gradient_end`,
  `background` (fondo de las pantallas de acceso) y `text` (tinta **sobre** el
  degradado). Fila ausente = default; vaciar borra la fila, como en el resto del
  patrón de instancia. `gradient_start` sin fila cae al primario (tocar solo el
  primario sigue tiñendo la navbar entera, el comportamiento de antes),
  `gradient_end` al violeta `DEFAULT_GRAD_END`.
- **Auto-contraste**: si `text` está vacío, `--vf-on-grad` se calcula por
  luminancia del extremo inicial con `App\Core\Contrast::inkFor()` (WCAG, corte
  `0.5`): blanco sobre fondos oscuros y `#1e293b` sobre claros. Sin esto, un
  degradado amarillo o celeste dejaba el texto **blanco sobre blanco** y el
  formulario ilegible sin que nada fallara. Un valor que no se puede parsear cae a
  blanco (nunca deja el texto invisible).
- **Tokens en `:root`** (`--vf-grad-start`, `--vf-grad-end`, `--vf-on-grad`,
  `--vf-on-primary`, `--vf-instance-bg`) y se quitaron los `#8b5cf6`/`#d946ef`/
  `#7c3aed` hardcodeados de `.app-navbar`, `.app-sidebar-inner`, `.auth-body`,
  `.vf-header::before`, `.vf-progress-bar` y `.vf-submit`. Solo queda a mano el
  `#newFormModal .app-tpl-cover` (miniatura decorativa). Los tokens nuevos tienen
  su consumidor, que es lo que vigila `ThemeFontCssInjectionTest`.
- **Builder**: cuatro controles nuevos en la sección Tema (`stGradStart`,
  `stGradEnd`, `stText` + interruptor `stTextAuto`) y `paintThemePreview()` pinta el
  degradado y el fondo. `FormSettings::normalize()`/`validate()` cubren las cinco
  claves de color; el payload ya las llevaba en `settings`.
- **Instancia**: `updateBrand()` valida (hex o vacío) y guarda los cuatro colores;
  `views/admin/settings.php` los edita con el mismo picker `data-vf-color-for` del
  primario. Las claves nuevas entran en `ControllerActionsTest::APP_SETTINGS_KEYS`.
- **Logo estirado**: `.app-logo`, `.app-logo-lg` y `.app-logo-sm` ganan
  `flex: 0 0 auto` + `min-width`/`min-height` (34/64/26 px) y `.app-logo-img`
  `flex: 0 0 auto`. La causa era que la caja era un `inline-flex` sin base fija y
  en un padre flex apretado encogía **solo el ancho**, rompiendo el aspect ratio de
  la imagen (`width:100%;height:100%;object-fit:contain`).
- **Tests**: `ContrastTest` (negro/blanco/claros, forma corta, valor ilegible),
  casos nuevos en `FormSettingsTest` y `BrandTest`/`BrandControllerTest`
  (precedencia, vaciar borra la fila, hex inválido rechaza el POST), y se
  actualizaron `BrandTest`, `SidebarLayoutTest` y `BuilderPreviewCssTest` que
  fijaban los hex viejos.

### Cierre de huecos de AG12 (2026-10-22)

La evaluación posterior encontró cuatro cabos sueltos, todos de la misma familia:
la validación de color no estaba centralizada y la salida no era tan estricta como
la entrada.

- **Un único predicado de color**: `Contrast::isHex()`/`normalizeHex()`. Lo usan
  `Brand`, `FormSettings` y `AdminController::updateBrand()`. Antes `FormSettings`
  aceptaba `#RRGGBBAA` (regex `{3,8}`) y `Brand` solo `#RRGGBB`; peor: `Contrast`
  solo parsea 3/6, así que un degradado de 8 dígitos pasaba la validación y la
  tinta automática caía a **blanco en silencio**. Ahora el formato aceptado y
  almacenado es `#rrggbb`, con `#rgb` expandido al guardar. Un valor no-hex se
  conserva en `normalize()` (para que `validate()` diga cuál es el campo malo) y
  lo descarta la salida.
- **`FormSettings::themeCss()` revalida al salir**: ya lo hacía con la tipografía
  (catálogo cerrado) pero no con los colores, que se emitían con solo
  `htmlspecialchars`. Una fila escrita a mano producía CSS inválido en el fill
  público sin error. Ahora cada color pasa por `Contrast::normalizeHex()` antes de
  emitirse, igual que `Brand::themeCss()` vía `storedHex()`.
- **Paridad PHP↔JS atada por test**: la tabla de colores de
  `tests/js/contrast.js` es la misma que la de `tests/unit/ContrastParityTest.php`;
  el arnés extrae `textoAuto()` del builder REAL. Cambiar la fórmula en un solo
  lado pone uno de los dos en rojo. Los dos parsers se alinearon (un solo `#`
  inicial, `trim()`).
- **Tabla de fases del plan al día**: AG10/AG11/AG12 seguían como `PENDIENTE` en
  la tabla superior de `plan-ag5-ajustes-instancia-2026-10-21.md` mientras la de
  estado las daba por COMPLETADAS.
- **El formulario de `/admin/settings` ya no congela el default**: los campos de
  TEXTO de color se prellenaban con el valor EFECTIVO. Como `gradient_start` cae
  al primario cuando no hay fila, guardar cualquier otro ajuste sin tocar el
  degradado escribía el primario de ese momento en la fila y el degradado dejaba
  de seguirlo en los cambios posteriores. `Brand::storedColor()` devuelve lo
  guardado (o `null`, que el campo pinta vacío) y `AdminController` pasa las tres
  claves `*Stored`; los selectores `type="color"` siguen con el efectivo porque
  no admiten vacío.
- **Tests nuevos**: `ContrastParityTest` (2), `ContrastTest` amplía
  `isHex`/`normalizeHex` (2), `FormSettingsTest` añade la revalidación de salida
  (1), `BrandTest` cubre `storedColor()` (1) y `BrandMarkupTest` ata el
  prellenado guardado y el patrón `#rgb`/`#rrggbb` (2). `BrandTest::testUnColorDeTresDigitosNoVale`
  pasa a `testUnColorDeTresDigitosSeNormalizaASeis` (la forma corta ya vale y se
  expande); `testNormalizeAplanaYDescartaClavesExtras` espera `#ffffff` en vez de
  `#fff`.

### El SMTP real rechazaba el saludo 220 (2026-10-23)

El botón «Enviar prueba» de `/admin/settings` fallaba con `SMTP: saludo
inesperado ('220 in.mailjet.com ESMTP Mailjet')`: el servidor respondía bien, pero
`Mailer::expect()` comparaba `substr($reply, 0, 3)` (string `"220"`) contra las
listas de códigos (`[220]`, enteros) con `in_array(..., true)`. La comparación
estricta string/int da `false` SIEMPRE, así que la primera respuesta de cualquier
SMTP real se rechazaba. El transporte de prueba (`Mailer::setTransport()`) no pasa
por `smtpSession()`, por eso los tests no lo veían y el fallo solo aparecía con un
servidor de verdad.

Se corrige comparando el código como entero (`(int) substr(...)`) y se añade
`MailerTest::testElCodigoDeRespuestaSmtpSeComparaComoEntero`, que invoca `expect()`
por reflexión con el saludo real de Mailjet y con un código/EOF inválidos. Mutar la
comparación de vuelta a string pone el test en rojo. Verificado con un envío real:
el servidor acepta y finaliza el mensaje.

## Validación integral de la aplicación (2026-10-27)

Auditoría de rutas, controladores, servicios, vistas, esquema, permisos y tests
contra `docs/plan-validacion-completa.md`. Tres defectos reales y dos cabos
sueltos de coherencia; el resto de fases quedó validado por código, tests y
smoke de rutas.

- **El catálogo de `SecurityLog` filtraba eventos reales**: `recent()` solo
  devuelve las claves de `knownEvents()`, así que los eventos emitidos pero no
  registrados desaparecían del panel sin aviso. Se añadieron los diez que
  faltaban (`authz.denied`, `admin.user_create`, `form.publish`,
  `rate_limit.form_access`, `rate_limit.form_code`, `access.link_issued_by_owner`,
  `access.link_sent`, `roster.code_rejected`, `roster.imported`,
  `roster.truncated`) y `admin.group_update`. `rate_limit.dash_` sigue siendo un
  prefijo dinámico, no un evento literal. Lo vigila
  `SecurityLogTest::testTodosLosEventosLiteralesEstanEnElCatalogo`, que recorre
  `SecurityLog::record('…')` en `app/`.
- **Actualizar un grupo se anotaba como `admin.group_create`**: corregido a
  `admin.group_update` en `AdminController::groupUpdate()`, con regresión en
  `AdminRbacTest`.
- **Nombre de grupo duplicado daba un 500 por el UNIQUE**: `groupCreate()` y
  `groupUpdate()` lo comprueban antes con `RoleGroup::findByName()`, que usa la
  colación de la columna (la misma que el índice: no distingue mayúsculas ni
  acentos). En el update se excluye el propio id.
- **Un rol o usuario inventado en el POST de un grupo llegaba a las FK**: ahora
  `groupMembershipError()` valida los ids contra `Role::all()`/`User::all()` antes
  de crear o tocar la N:M; se conserva el comportamiento de ignorar ids `<= 0`.
  Un POST manipulado recibe un mensaje y no deja el grupo a medio escribir.
- **El filtro de fechas de respuestas no usaba el predicado común**:
  `ResponsesController::validarFecha()` aceptaba `2026-02-30` porque
  `createFromFormat` la corrige a marzo, mientras la analítica y el CSV usan
  `Submission::isValidDay()`. Ahora delega en él, así que el mismo `?from`/`?to`
  recorta el mismo periodo en las tres superficies.
- **Tests nuevos**: 3 en `AdminRbacTest` (nombre repetido en alta, renombrado
  repetido e ids inexistentes), 1 más para el evento de actualización, 1 en
  `SecurityLogTest` (catálogo completo) y 1 en `ResponsesTest` (día inexistente).
  `ControllerActionsTest::testTodasLasRutasRespondenSinErroresNiEndpointsMudos`
  sigue verde con las 97 rutas.
