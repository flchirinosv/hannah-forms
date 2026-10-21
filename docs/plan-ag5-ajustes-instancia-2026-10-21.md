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
| AG9 | **Red y redirecciones por UI** | `TRUST_PROXY`, `APP_REDIRECT_ALLOWLIST`, `FRAME_OPTIONS` + `CSP` | 1.5 d | PENDIENTE |
| AG10 | **Webhook red local por UI** | `WEBHOOK_ALLOW_PRIVATE_HOSTS` | 0.5 d | PENDIENTE |
| AG11 | **Instalador guiado web** (el ejecutable) | — | 2 d | PENDIENTE |

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

**Diseño (a decidir en implementación, propuesta):**

- Entrada: `public/install.php` o ruta `GET/POST /install`, **bloqueada si ya hay
  una instalación** (detectar `app_settings` con `instance_*`/usuarios, o un
  marcador en disco `storage/installed.lock`).
- Pasos: (1) requisitos (PHP ≥ 8.2, extensiones `pdo_mysql`, `mbstring`, `fileinfo`,
  `openssl`; permisos de `storage/uploads` y `storage/branding`); (2) conexión BD
  (host/puerto/nombre/usuario/clave; probar con `PDO`); (3) esquema (aplicar
  `schema.sql` + `seed.sql` desde PHP, o `migrate.php`); (4) `.env` (escribir
  archivo con `DB_*`, `APP_KEY` generada, `APP_URL`); (5) primer admin (email +
  contraseña, reutilizar `AuthController::validateNewUser` y `PASSWORD_MIN`);
  (6) ajustes de instancia iniciales (marca, SMTP opcional, identidad) — reusar las
  tarjetas/servicios de AG2/AG3/AG4.
- **Seguridad**: el instalador no debe quedar accesible tras instalar; si no hay
  `APP_KEY`/`.env`, las claves del paso 2 y 4 van por POST y se validan; nunca
  mostrar la contraseña. Considerar un token de instalación temporal en disco.
- **Reutilización**: `create-admin.php` y `database/migrate.php` ya tienen la lógica;
  el instalador la llama desde PHP (sin `exec` si es posible).
- Tests: un test que verifique el gate de «ya instalado» (no se puede reabrir) y la
  validación de requisitos; el flujo completo end-to-end es manual
  (`docs/pruebas-funcionales.md`, bloque nuevo).

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
| AG5 Turnstile | PENDIENTE | — |
| AG6 Uploads | PENDIENTE | — |
| AG7 Cupos dashboard | PENDIENTE | — |
| AG8 Sesión/HTTPS | PENDIENTE | — |
| AG9 Red/redirecciones | PENDIENTE | — |
| AG10 Webhook red local | PENDIENTE | — |
| AG11 Instalador web | PENDIENTE | — |
