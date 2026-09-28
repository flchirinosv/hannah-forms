# Plan de corrección — Auditoría 2026-09-25

Objetivo: corregir todos los hallazgos de `docs/audit-report-2026-09-25.md` **sin romper los 77 tests ni los contratos públicos**. Convenciones: español, `declare(strict_types=1)`, `View::escape()`, sin dependencias externas.

Decisiones del usuario:
- **M2 Paginación:** del lado del CLIENTE (JS) en dashboard, admin/forms y tabla de analítica.
- **M7 Doble submit:** solo JS + limpiar borrador (sin nonce de servidor).
- **B6 Docs:** regenerar `technical-document.md` + crear `docs/api-spec.md`.

## Fase 1 — Seguridad (riesgo bajo, sin UI)

1. **F1-1 · Avatar escapado** — `views/layouts/main.php:49`: `View::escape(strtoupper(substr(...)))`.
2. **F1-2 · A1 Límite de cuerpo JSON** — `app/Core/Request.php`: validar `CONTENT_LENGTH` contra `app.max_json_bytes` (fallback 2 MiB, env `MAX_JSON_BYTES`), leer con `maxlen`, flag `jsonTooLarge()`; `BuilderController::saveStructure` → `Response::json(..., 413)`. Config en `config/config.php` + `.env.example`. Tests en `tests/unit/RequestTest.php`.
3. **F1-3 · A2 Fuerza bruta por email** — `AuthController::login`: además del límite por IP (5/5min por `login:ip`), scope `login_email` con identificador `sha256(email normalizado)` (~10/5min); reset de ambos en éxito; recordHit en ambos en fallo. `RateLimiter` no cambia.
4. **F1-4 · A3b CSP configurable** — env `CSP_POLICY` (default `''` = off). `public/index.php` emite header si no vacío. Se documenta política recomendada en recommendations.
5. **F1-5 · Higiene CSRF-header** — nuevo middleware `csrf-header` (`Middleweware::guards()` → `Csrf::verifyHeader()`); aplicarlo en `config/routes.php` a `POST /forms/{id}/builder/save` (hoy solo `['auth']`).

## Fase 2 — Robustez (riesgo bajo, sin UI)

6. **F2-6 · B5 Guard `$user === null`** — en `FormController::{show,update,delete,publish,close}`, `BuilderController::{show,saveStructure}`, `AnalyticsController::{show,exportCsv}` tras `Auth::user()` → redirect `/login`.
7. **F2-7 · M3 Longitudes** — `register()`: name≤100, email≤190; `FormController::{create,update}`: title≤190 (flash); `BuilderController::saveStructure`: label≤190, placeholder≤190, help_text≤255, title≤190 → 422 JSON.
8. **F2-8 · B1 Router 405** — `Router::dispatch`: si hay ruta que matchea path pero no método → `Response::methodNotAllowed($allow)` con header `Allow` + vista `errors/405` (copia de 404). Verificación con curl (los tests no capturan headers).
9. **F2-9 · B3 `View::resolve`** — rechazar `..` en el nombre del template (500 controlado).
10. **F2-10 · M1+M8 Autosave builder** — `builder.js`: serializar (`if (saving) return` + re-save si quedó dirty), omitir payload idéntico (snapshot JSON del último OK), debounce ~2 s.
11. **F2-11 · M6+M7 fill.js** — `sessionStorage` en vez de `localStorage` para el borrador; `removeItem(draftKey)` + bloqueo de doble `submit` en el envío.
12. **F2-12 · M9 CDN→local** — `views/fill/closed.php` y `views/fill/draft.php`: `View::asset('css/all.min.css')`.
13. **F2-13 · B2 variable muerta** — quitar `$db` y imports sin usar en `AdminController::allForms`.

## Fase 3 — UI/Producto

14. **F3-14 · M2 Paginación client-side** — componente en `app.js` (IIFE, `data-paginate`, `data-rows`, footer Bootstrap), aplicado a: formularios de `views/dashboard/index.php`, tabla de `views/admin/forms.php`, tabla de respuestas de `views/analytics/show.php`. Nota: alivia DOM/UX; el peso de la query no cambia a esta escala.
15. **F3-15 · M4 XFO configurable** — env `FRAME_OPTIONS` (default `DENY`) en `public/index.php` vía `config/app`.
16. **F3-16 · M5 CSV con filtro** — `AnalyticsController::exportCsv` lee/valida `from/to` y usa `Submission::byFormFiltered`; enlaces de export (2) incluyen `?from=&to=`.
17. **F3-17 · B6 Docs** — regenerar `docs/technical-document.md` (rol `user`, secciones/agrupaciones/lógica del builder) y crear `docs/api-spec.md` con los endpoints.
18. **F3-18 · B4/B7/B8** — notas documentales en `docs/recommendations.md` (getPdo acotado, CSP recomendada, seed conocido).

## Fase 4 — CSP estricta (registrado 2026-09-25 tras pedido del usuario)

Objetivo: poder activar `CSP_POLICY` con `script-src 'self'` (sin `'unsafe-inline'`) sin romper el frontend. Era el pendiente B7 documentado en recommendations/technical-document.

19. **F4-1 · Externalizar data del builder** — `views/builder/index.php`: eliminar `<script>` inline (FORM_ID/INITIAL_FIELDS/CSRF_TOKEN/FORM_STATUS) → atributos `data-*` en `<div id="builderApp">`; `public/assets/js/builder.js` lee de `appEl.dataset` (con fallback a `[]`). Botón «Abrir» (inline `onclick`) → `id="btnPreviewUrl"` + listener en builder.js.
20. **F4-2 · Externalizar CHART_DATA** — `views/analytics/show.php`: eliminar `<script>` inline → `data-chart` JSON en `<div id="analyticsApp">`; `analytics.js` lo parsea.
21. **F4-3 · Borrar bloque inline de thanks** — `views/fill/thanks.php`: el `<script>` de limpieza quedó **muerto e incorrecto** (usa `localStorage`; el draft vive en `sessionStorage`, y `fill.js:447` ya lo limpia en el submit). Se elimina.
22. **F4-4 · Handlers inline restantes** — los atributos `on*` también los bloquea CSP `script-src 'self'`:
    - Confirm de borrar en `views/dashboard/index.php` y `views/dashboard/forms/show.php` → `data-confirm="…"` (delegación `submit` en app.js con `confirm()`).
    - Copiar enlace en `views/dashboard/forms/show.php` → clase `.js-copy-url`.
    - Select de rol (`onchange`) en `views/admin/users.php` → `.js-auto-submit`.
    - Confirm de toggle de usuario (`onsubmit`) → `data-confirm`.
23. **F4-5 · Activar CSP** — setear `CSP_POLICY` (política recomendada de recommendations.md) en `.env` y `.env.example`. Headers se emiten en `public/index.php` (ya implementado en F1-4).

**Estado Fase 4: COMPLETADA** (verificada 2026-09-25): `php -l` limpio, 79 tests verdes, 0 `<script>` inline y 0 handlers `on*` en vistas, header CSP presente en dashboard/builder/analytics/fill via curl, builder/analytics renderizan los `data-*` correctamente.

## Fase B — Verificación visual JS (COMPLETADA 2026-09-25, via Playwright headless)

> El entorno no tenía navegador; se instaló tooling FUERA del repo (en `/tmp/opencode/e2e`):
> `python3` + `pip` + `playwright` (pip) + `chromium` 153 (debian, por apt). Sin Node, sin
> tocar el proyecto. El e2e (`/tmp/opencode/e2e/e2e_playwright.py`) corre en headless.

- **Regresión de paginación encontrada y corregida** (F3-14 estaba mal): `app.js` ocultaba
  ítems con `.d-none` y `visible()` contaba solo los que NO tenían `d-none` → al pulsar
  «Siguiente» el conjunto filtrado se reducía a la página actual → `pages=1` → `page` clampeada
  a 1 → paginador desaparecía. Fix: clase propia `.app-pg-hidden { display:none !important; }`
  en `app.css`; la paginación ya no toca `d-none` (propiedad exclusiva del filtro de dashboard).
- **E2E verde (17/17 PASS)** con Chromium headless: login admin; 12 forms creados; paginador
  presente en pág 1 de 3; «Siguiente» conserva el paginador y muestra ítems distintos (pág 2);
  «Anterior» vuelve a 1; búsqueda repagina (1 de 2, 9 ítems, siguiente → 2 de 2); dashboard,
  builder y analítica cargan sin violaciones CSP ni errores de consola/pageerror.
- Checklist QA: puntos 1 y 4 cubiertos por el e2e. Autosave (2) y doble-submit/draft de fill (3)
  quedaron como verificación manual del usuario; el suite unitario cubre la lógica de negocio.
- Datos de prueba auto-limpios (`DELETE ... title LIKE 'E2E Paginaci%'`) y `rate_limits` purgados;
  `php -l` limpio y **79 tests verdes (0 fallidos, 0 errores)** tras el fix y el e2e.

## Verificación

- `php -l` en todo el árbol PHP.
- `php scripts/test.php` (79 actuales).
- Smoke con curl: 405 (`GET /forms/{id}/builder/save`), login normal, builder/save ok, `export.csv?from&to`, **header `Content-Security-Policy` presente**, y `grep` de `<script>`/`on*` inline = 0.
- Paginación JS: verificación en navegador headless (Fase B, 17/17 PASS).