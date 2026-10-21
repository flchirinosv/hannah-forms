

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
