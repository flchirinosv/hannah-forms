

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
