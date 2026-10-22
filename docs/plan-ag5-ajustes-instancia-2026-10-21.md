# Plan AG5+ — Ajustes de instancia por UI y ejecutable final

> **Estado:** abierto (2026-10-21). Este documento es la memoria del plan para no
> perder contexto entre sesiones. Se actualiza la tabla de estado **al cerrar cada
> fase**.
>
> **Objetivo de producto:** que la instancia se pueda operar **sin SSH ni editar
> `.env`**. AG1–AG4 cerraron el cimiento (cifrado), la marca, el correo y la
> identidad operativa. Este plan cierra lo que queda y termina con un **instalador
> guiado web**.

## Principio rector (heredado de AG2/AG3/AG4)

Cada grupo de ajustes sigue el **patrón de instancia**:

1. **Precedencia**: fila de `app_settings` gana; sin fila, el `.env`; el valor se
   **relee en cada petición** (caliente, sin reiniciar).
2. **Lectura tolerante**: una fila inválida se **ignora al leer** y cae al `.env`
   (validación en las dos puertas: leer y guardar).
3. **Escritura atómica**: `save()` valida **antes** de escribir; si algo falla **no
   escribe nada** (ni borra la fila previa).
4. **`all()` separa** `stored_*` (fila cruda), `env_*` (default del `.env`, se
   muestra como «Por defecto (…)») y el valor **efectivo resuelto**.
5. **Secreto** (si lo hay): cifrado con `App\Core\Secret` (AES-256-GCM), nunca
   devuelto a la vista, `decrypt()` que no lanza. Sin `APP_KEY`, `save()` falla
   explícitamente en vez de escribir en claro.
6. **Controlador**: `Auth::can('admin.access')` + `Csrf::verifyRequest()` +
   `SecurityLog::record('instance.<algo>_update', …)` + flash + **PRG**.
7. **Ruta**: `POST /admin/settings/<grupo>` con `['auth','admin','csrf']`
   (`post-max` **antes** de `csrf` si hay archivo).
8. **Vista**: la fila guardada en los campos; el `.env` como «Por defecto»; los
   secretos **jamás** (solo «(guardada)»).
9. **`.env` y `schema.sql` no cambian de forma**: son el fallback documentado.

Referencia canónica: `App\Core\InstanceSettings` (AG4) y `App\Core\MailSettings`
(AG3). Tests: `InstanceSettingsTest`, `AdminSettingsPageTest`, y la clave en el
snapshot `ControllerActionsTest::APP_SETTINGS_KEYS`.

---

## Fases

| # | Fase | Claves `.env` | Esfuerzo | Estado |
|---|------|---------------|----------|--------|
| AG5 | **Turnstile por UI** (site key + secret cifrada) | `TURNSTILE_SITE_KEY`, `TURNSTILE_SECRET_KEY` | 1 d | **COMPLETADA** |
| AG6 | **Límites de upload por UI** | `UPLOAD_MAX_SIZE_MB`, `UPLOAD_MAX_FILES` | 0.5 d | **COMPLETADA** |
| AG7 | **Cupos del dashboard por UI** | `DASH_CREATE_PER_HOUR`, `DASH_EXPORT_PER_HOUR` | 0.5 d | **COMPLETADA** |
| AG8 | **Hardening de sesión / HTTPS por UI** | `SESSION_NAME`, `SESSION_LIFETIME`, `SESSION_SECURE`, `SESSION_HTTPONLY`, `SESSION_SAMESITE` | 1 d | **COMPLETADA** |
| AG9 | **Red y redirecciones por UI** | `TRUST_PROXY`, `APP_REDIRECT_ALLOWLIST`, `FRAME_OPTIONS` + `CSP` | 1.5 d | **COMPLETADA** |
| AG10 | **Webhook red local por UI** | `WEBHOOK_ALLOW_PRIVATE_HOSTS` | 0.5 d | PENDIENTE |
| AG11 | **Instalador guiado web** (el ejecutable) | — | 2 d | PENDIENTE |
| AG12 | **Personalización ampliada de color** (instancia + formulario) y logo estirado | — | 0.5 d | PENDIENTE |

Orden elegido por **riesgo/valor decreciente**: AG5 es el único que hoy **rompe el
fill con 403**; AG11 cierra la promesa de «vendible y autogestionable».

---

## Fase AG5 — Turnstile por UI

**Problema actual.** El opt-in del formulario (`settings.anti_bot.enabled`) existe y
está en el builder, pero las **claves de instancia** solo viven en el `.env`. Si el
formulario pide anti-bot y la instancia no tiene claves, `FillController` **no sirve
el formulario** (`fill/closed`) y el POST corta con **403**
(`renderAvailabilityGate` :495, `submit` :1068). Hoy eso se resuelve por SSH.

**Diseño.**

- Clave pública `instance_turnstile_site_key` (texto) + clave secreta
  `instance_turnstile_secret_key` (**cifrada**, patrón `MailSettings::PASSWORD`).
- Site key: fila → `app.turnstile.site_key` (`.env`). Secret: fila descifrada →
  `app.turnstile.secret_key` (`.env`); sin `APP_KEY`, la fila cifrada no se puede
  leer y cae al `.env` (no rompe).
- **`isConfigured()` exige LAS DOS claves** (invariant `TurnstileTest`). Guardar
  una sola **no** puede dejar «medio configurado»: el `save()` valida coherencia
  (o las dos, o ninguna) y lo avisa; el caso «hay site key pero la secret no
  descifra» degrada a `.env` completo, no a media configuración.
- Turnstile lee las claves por un accessor que consulta la fila → `.env` → seam
  (`setKeysForTesting` se conserva).

**Cambios previstos.**
- Nuevo `app/Core/TurnstileSettings.php` (o extender `Turnstile` con
  `resolvedSiteKey()`/`resolvedSecret()`): `all()`, `save()`, `setForTesting()`,
  `forgetForTesting()`, `secretStored()`.
- `app/Services/Turnstile.php`: `siteKey()`/`secretKey()` pasan por la resolución
  de instancia (conservando el seam de test).
- `AdminController::settings()` inyecta los valores; nuevo `updateTurnstile()`
  (PRG, `instance.turnstile_update`); oculto `clear_secret` como en correo.
- Ruta `POST /admin/settings/turnstile`; tarjeta «Anti-bot (Turnstile)» en
  `views/admin/settings.php`.
- `SecurityLog::knownEvents()` += `instance.turnstile_update`.
- `tests/unit/TurnstileSettingsTest.php`; claves en `APP_SETTINGS_KEYS`; markup en
  `AdminSettingsPageTest`.

**Riesgos anotados.** Rotar la clave afecta a **todos** los formularios a la vez;
la CSP del fill ya reescribe `challenges.cloudflare.com` (`emitFillCsp` :1355) —
no se toca, pero verificar que sigue valiendo con claves de fila.

---

## Fase AG6 — Límites de upload por UI

**Claves:** `instance_upload_max_size_mb`, `instance_upload_max_files`.

- Valida enteros **≥ 1** (un `0` rechazaría todo); el límite **efectivo** es el
  mínimo con `upload_max_filesize`/`post_max_size` de PHP (`Uploader::maxSizeMb`
  :82). La tarjeta debe **mostrar el efectivo**, no solo el configurado, o miente.
- Consumidores que deben leer **la misma fuente**: `Uploader::maxSizeMb/maxFiles`,
  middleware `post-max` (`Middleware:54`), `FillController:1030`,
  `Brand::saveUpload` :195 (hoy lee `Config` directo), y las vistas
  `views/fill/show.php` + `views/builder/index.php` (data-*).
- Nuevo `app/Core/UploadSettings.php`; `updateUploads()`; ruta
  `POST /admin/settings/uploads`; evento `instance.uploads_update`; tarjeta.
- Test propio + claves en snapshot + comprobar que `post-max` y el fill coinciden.

---

## Fase AG7 — Cupos del dashboard por UI

**Claves:** `instance_dash_create_per_hour`, `instance_dash_export_per_hour`.

- La **ventana es constante** (`DashboardLimit::WINDOW = 3600`) y **no** se expone:
  `RateLimiter::MAX_WINDOW` y `clean-rate-limits.php` dependen de ella.
- Valida enteros ≥ 1; `0` cerraría la creación/exportación en seco.
- `DashboardLimit::isAllowed()` :41 lee `Config::get('app.limits')` → pasa a
  resolver por instancia. Ojo: los límites del **fill** (`fill_submit_per_10min`,
  `roster_code_per_15min`) **NO** entran aquí (son por IP, no por usuario).
- Nuevo `app/Core/DashboardSettings.php`; `updateDashboardLimits()`; ruta
  `POST /admin/settings/dashboard`; evento `instance.dashboard_limits_update`.
- Nota para la UI: bajar el límite **no reinicia** la ventana en curso.

---

## Fase AG8 — Sesión / HTTPS por UI

**Claves:** `instance_session_secure`, `instance_session_samesite`,
`instance_session_httponly`, `instance_session_lifetime`, `instance_session_name`.

- **Aplicación en caliente limitada**: `Session::start()` corre **una vez** en
  `config/bootstrap.php:42`, **antes** del router. Leer la fila ahí exige una
  consulta en el arranque. Decisión a tomar en implementación: (a) leer la fila en
  `bootstrap.php` con `try/catch` (la BD ya está conectada antes de la sesión —ver
  bootstrap), o (b) aplicar los cambios en la **siguiente** petición (documentarlo).
  **Preferida (a)** si el orden de bootstrap lo permite; si no, (b) explícito.
- `secure` se **fuerza** a `true` si se detecta HTTPS (`Session:15`): la fila solo
  manda en HTTP plano. `samesite=None` sin `secure` hace que el navegador rechace la
  cookie (rompe el embed, que es `Lax` a propósito). `httponly=false` reabre la
  cookie a JS. `SESSION_NAME` cambiado «desloguea» a todos (cookie nueva).
- **No** exponer `use_strict_mode`/`use_only_cookies` (fijos en código a propósito,
  `Session:30-35`).
- `updateSession()`; ruta `POST /admin/settings/session`; evento
  `instance.session_update`; tarjeta con avisos de impacto.

---

## Fase AG9 — Red y redirecciones por UI

**Claves:** `instance_trust_proxy`, `instance_redirect_allowlist`,
`instance_frame_options`, y `CSP` (posiblemente solo lectura explicativa).

- **`TRUST_PROXY` es interruptor de seguridad**: `true` **sin** proxy de confianza
  hace falsificable `X-Forwarded-For` (bypass de rate limiting); `false` **con**
  proxy deja a todos con la IP del proxy. La tarjeta debe explicar cuál es cuál.
- **`APP_REDIRECT_ALLOWLIST`**: lista de hosts. «Borrar la fila» = **no se sigue
  ninguna** URL externa (default vacío = bloqueado). Validar cada host.
- **CSP y `FRAME_OPTIONS` se contradicen**: el default de CSP trae
  `frame-ancestors 'none'`; para embeber hay que cambiar **ambas**. Decisión:
  editables juntas con aviso, o CSP de solo lectura (el default seguro está en
  código; AGENTS.md lo protege). **Preferida:** CSP de solo lectura con nota; solo
  `FRAME_OPTIONS` editable emparejado con un flag de «permitir embed» que ajuste
  ambas.
- Headers se emiten en `public/index.php:15-26` **antes** del router → misma
  decisión de arranque que AG8 (leer la fila en bootstrap con `try/catch`).
- `Request::trustsProxy()` :211, `RedirectGuard::allowlist()` :58,
  `Request::json()` (max_json_bytes, quizá fuera de alcance).
- `updateNetwork()`; rutas correspondientes; eventos; tarjeta.

---

## Fase AG10 — Webhook red local por UI

**Clave:** `instance_webhook_allow_private_hosts` (booleano).

- Es la **frontera de autorización del SSRF**: con `true`, **todos** los formularios
  pueden alcanzar loopback/RFC1918/link-local, no solo el que lo pidió.
- Gate ya existente: `Webhook::serverAllowsPrivate()` :166 combina con el opt-in
  **del formulario** (`settings.webhook.allow_private_hosts`). Las dos capas siguen:
  esta fila es la de la instancia.
- Fallo **silencioso por diseño**: si está apagado, el webhook a host privado
  simplemente no sale. La UI debe decir «el opt-in de instancia está apagado» en
  lugar de «no funciona».
- Posible refuerzo: gate de permiso más fino que `admin.access` (a evaluar).
- `updateWebhook()`; ruta `POST /admin/settings/webhook`; evento
  `instance.webhook_update`; tarjeta.

---

## Fase AG11 — Instalador guiado web (el ejecutable)

**Problema.** Hoy el arranque exige: crear la BD (`mysql < schema.sql` + `seed.sql`),
copiar `.env`, generar `APP_KEY`, crear el primer admin por CLI
(`scripts/create-admin.php`). Todo por SSH. El instalador lo hace **sin terminal**.

**Diseño implementado:**

- Entrada: **`public/install.php`** (entrypoint propio, NO una ruta de
  `config/routes.php`). Razón: `config/bootstrap.php` conecta la BD y `exit` si
  falla, así que en una instalación nueva ninguna ruta llegaría a dibujarse. El
  entrypoint carga solo el autoload y el `.env` (opcional) y levanta sesión + CSRF
  propios.
- Gate: `App\Services\InstallGuard` decide con un marcador en disco
  (`storage/installed.lock`, JSON con fecha + email del admin, `0600`). No se puede
  preguntar a la BD (a mitad de instalación puede no existir). Una vez marcado,
  `install.php` responde 403 salvo `?force`.
- Pasos: (1) requisitos (PHP ≥ 8.2, `pdo_mysql`/`mbstring`/`fileinfo`/`openssl`,
  escritura en `storage/`, `storage/uploads`, `storage/branding` y `.env`);
  (2) conexión BD y creación de la base (`App\Services\Installer` conecta sin
  `dbname`, `CREATE DATABASE IF NOT EXISTS`, aplica `schema.sql` + `seed.sql` con su
  `USE` reescrito y las migraciones pendientes); (3) admin (email + contraseña,
  reutiliza `AuthController::validateNewUser` / `PASSWORD_MIN` y resuelve el rol
  `admin` por nombre); (4) escritura del `.env` con `App\Core\EnvWriter` (atómico,
  preserva comentarios, `0600`) y `APP_KEY` **solo si no había una**.
- Seguridad: nada queda accesible tras instalar (lock); la contraseña de BD y la del
  admin nunca se muestran ni se guardan en el lock; `APP_KEY` existente **nunca** se
  sobrescribe (perdería el descifrado de los secretos). El CSS del asistente va en
  `app.css` (clases `.app-install-*`) porque la CSP prohíbe estilos/scripts en línea
  más allá de `style-src 'unsafe-inline'`.
- Tests: `EnvWriterTest` (atómico, merge, permisos, relectura), `InstallerTest`
  (nombre válido, reescritura `USE`/`CREATE DATABASE`, validación de admin, ciclo
  real si el usuario puede crear bases), `InstallGuardTest` (lock, 0600, requisitos).
  El flujo completo POST end-to-end es manual (`docs/pruebas-funcionales.md`, bloque AI).

---

## Fase AG12 — Personalización ampliada de color y arreglo del logo

**Problema.**

1. La marca de la instancia (AG2) solo dejaba elegir el **color primario**. El
   degradado de la navbar/sidebar/login seguía clavado en el código (`#5b5bd6` →
   `#8b5cf6`), así que una instancia con marca propia quedaba a medio teñir. Lo mismo
   dentro de cada formulario (`settings.theme`): `primary` y `background`, nada más.
2. En la tarjeta «Marca de la instancia» de `/admin/settings`, el logo subido salía
   **estirado** (aplanado en horizontal) en pantallas estrechas: la caja `.app-logo-lg`
   era un `inline-flex` sin `flex: 0 0 auto`, así que en un padre flex apretado
   encogía solo el ancho y rompía la proporción de `.app-logo-img`
   (`width:100%;height:100%;object-fit:contain`).

**Diseño implementado.**

- **Cuatro colores nuevos** en las dos superficies (instancia `Brand` y formulario
  `FormSettings::theme`): `gradient_start`, `gradient_end`, `background` (fondo de las
  pantallas de acceso) y `text` (tinta **sobre** el degradado).
- **Auto por defecto**: si `text` está vacío, `--vf-on-grad` se **calcula por
  luminancia** del extremo inicial con `App\Core\Contrast::inkFor()` (WCAG, corte
  `0.5`): blanco sobre fondos oscuros, `#1e293b` sobre claros. Sin esto, un degradado
  amarillo o celeste dejaba el texto blanco ilegible.
- **Tokens** en `:root` de `app.css` (`--vf-grad-start`, `--vf-grad-end`,
  `--vf-on-grad`, `--vf-on-primary`, `--vf-instance-bg`); `Brand::themeCss()` y
  `FormSettings::themeCss()` los sobrescriben con la fila guardada. Se sustituyeron
  los `#8b5cf6`/`#d946ef`/`#7c3aed` hardcodeados de `.app-navbar`,
  `.app-sidebar-inner`, `.auth-body`, `.vf-header::before`, `.vf-progress-bar` y
  `.vf-submit`. Solo queda a mano el `#newFormModal .app-tpl-cover` (miniatura
  decorativa).
- **`FormSettings::normalize()`/`validate()`** cubren las cinco claves de color
  (hex o vacío); `BuilderController` ya las guardaba por el `settings` del payload.
  En el builder se añaden los cuatro controles en la sección Tema (con un interruptor
  «Automático» que habilita/deshabilita el texto) y `builder.js` pinta el degradado y
  el fondo en la vista previa (`paintThemePreview`).
- **Instancia**: `updateBrand()` valida y guarda los cuatro colores (hex o vacío →
  `null`), y `views/admin/settings.php` los edita con los selectores de color que ya
  usaba el primario (`data-vf-color-for`).
- **Logo**: `.app-logo`, `.app-logo-lg` y `.app-logo-sm` ganan `flex: 0 0 auto` +
  `min-width`/`min-height` (34/64/26 px) y `.app-logo-img` `flex: 0 0 auto`. La caja
  ya no se encoge en un padre flex apretado y la imagen conserva su proporción.
- **Tests**: `ContrastTest` (luminancia, forma corta, valor ilegible → blanco),
  nuevos casos en `FormSettingsTest` (degradado, tinta automática, texto explícito) y
  en `BrandTest`/`BrandControllerTest` (precedencia, vaciar borra la fila, hex
  inválido). Las cuatro claves nuevas entran en `ControllerActionsTest::APP_SETTINGS_KEYS`.
---

## Convenciones y trampas (no olvidar)

- **Migraciones**: si una fase añade una clave nueva que necesita re-seed del
  catálogo, usa `database/migrations/` (última `0020`; la 0021 estaba cancelada).
  Para `app_settings` **no** hace falta migración nueva: es clave-valor y las claves
  nacen vacías (`0010`).
- **Snapshot del smoke**: cada clave nueva de `app_settings` que escriba
  `ControllerActionsTest` **debe** entrar en `APP_SETTINGS_KEYS` y devolverse en
  `cleanupResiduals()`, o **envenena** otros tests.
- **CSP**: prohibidos `<script>` inline y `on*=` (`testNingunaVistaTieneScriptInlineNiHandlerEnLinea`).
  Todo CSS nuevo en `public/assets/css/app.css`.
- **`--filter` casa por nombre de método**; suspicious `Tests: 0` = filtro mal.
- **Suite completa** ~7 min en background; nunca dos a la vez.
- **`.env` y `schema.sql` no cambian de forma**: solo se les añaden notas de
  «editable en /admin/settings».
- **Documentación**: cada fase actualiza `AGENTS.md`, `docs/decisiones.md`,
  `docs/manual-usuario.md`, `docs/pruebas-funcionales.md`, `docs/technical-document.md`
  y `.env.example`.

## Estado de la tabla (actualizar al cerrar cada fase)

| Fase | Estado | Commit |
|------|--------|--------|
| AG5 Turnstile | COMPLETADA | 32fd89e |
| AG6 Uploads | COMPLETADA | af4d8a8 |
| AG7 Cupos dashboard | COMPLETADA | 58ccd51 |
| AG8 Sesión/HTTPS | COMPLETADA | c0a2fd4 |
| AG9 Red/redirecciones | COMPLETADA | 94885ce |
| AG10 Webhook red local | COMPLETADA | a632dcc |
| AG11 Instalador web | COMPLETADA | 63db0ae |
| AG12 Color ampliado + logo | COMPLETADA | 2ed2b87 |

> AG10 incluye el arreglo estructural de `views/admin/settings.php` (`ca97c87`):
> la vista arrastraba `</form>`/`</div>` sin cerrar desde AG7–AG9 y dejaba las
> últimas tarjetas anidadas dentro de los formularios anteriores.
