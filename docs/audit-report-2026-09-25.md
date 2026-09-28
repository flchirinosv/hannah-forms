# Informe de Auditoría — VanillaForms

- **Fecha:** 2026-09-25
- **Alcance:** auditoría completa del código (Core, Controllers, Models/Services, config, vistas, JS, BD), seguridad, integridad, rendimiento y documentación.
- **Estado del audit previo:** `docs/audit-report.md` (2026-09-02, veredicto 71/100) — revisado y en gran parte resuelto.
- **Verificación realizada:**
  - Suite de tests propia (`php scripts/test.php`): **77 tests, 0 fallos, ~5.6 s**.
  - Sintaxis: `php -l` limpio en `app/`, `config/`, `public/`, `database/`, `scripts/`, `views/`.
  - Arranque real con `php -S`: `/login` 200, `/f/<slug inexistente>` 404, `/forms/999999999` redirige a login (302). Sin errores en log.
  - Migraciones: `0001` y `0002` aplicadas.
  - Hash seed verificada (bcrypt `password123` → `true`).
  - Entorno: PHP 8.4.24, extensiones `pdo_mysql`, `mbstring` presentes. Se confirma que **`rg` no está instalado** en el entorno (se usó el Grep dedicado; documentar si algún flujo del repo lo necesita).

## Veredicto general

Postura de seguridad y diseño **sólida y por encima del promedio** de un proyecto vanilla sin framework:

- **0 críticos** explotables confirmados en el código revisado.
- Autorización: checks por sesión/rol (`canAccess`/`canEdit`, middleware `auth/guest/admin/editor`) presentes y bien ubicados en los 7 controladores.
- CSRF: todos los POST llevan protección — `csrf` middleware o `Csrf::verifyRequest()`/`verifyHeader()` (builder vía header `X-CSRF-Token`). El hallazgo histórico C1 está corregido.
- Inyección SQL: consultas parametrizadas de extremo a extremo; sin concatenación peligrosa (verificado con grep). Único caso con placeholders generados (`Answer::withAnswersByIds`) opera sobre ids saneados con `intval`.
- Escape XSS: disciplina casi sistemática (`View::escape()`, `JSON_HEX_TAG` para ventanas JSON). **Un solo output sin escapar** (inicial de avatar en `layouts/main.php:49`).
- Sesión: cookie `HttpOnly`, `SameSite=Lax`, `secure` automático, `session.use_strict_mode`, regeneración de id en login, logout con CSRF.
- Rate limiting: login (5/5 min), register (5/10 min), submit por form+IP (10/10 min); ventana evaluada del lado BD (`NOW() - INTERVAL`) — patrón correcto.
- Contraseñas: bcrypt con coste por defecto; seed solo demo.

**Debilidades principales**: límites de tamaño de cuerpo para endpoints JSON, fuerza bruta distribuida (solo por IP), ausencia de CSP en producción, y varios gaps de validación/robustez de UX y rendimiento a escala. Ninguno compromete la integridad del sistema tal como está desplegado en dev.

## Cierre del audit previo (2026-09-02)

| N° | Foco | Estado |
|---|---|---|
| C1 | Builder expuesto (sin CSRF) | **Resuelto** — `Csrf::verifyHeader()` en `BuilderController::saveStructure`. |
| C2 | Redirects abiertos | **Resuelto** — `Response::redirect()` solo rutas relativas. |
| C3 | Registro de usuarios (C3 fue spamp) | **Mitigado** — rate limit 5/10 min + validaciones. |
| C4 | SQL por concatenación | **Resuelto** — analítica migrada a Models parametrizados. |
| A1 | `Database::getPdo()` expuesto | **Parcial** — solo se usa en tests; documentar como superficie restringida (B-04). |
| A5 | Path traversal en `View::resolve` | **No explotable hoy** (templates fijas de controllers); se recomienda asentar en doc (B-03). |
| M4 | Redirect abierto (v2) | **Resuelto** (cubierto por C2). |
| M6/M8/M10/M11 | Tiempos/validaciones/limpieza | **Resueltos** — validación de estructura de builder, whitelist de tipos, max 20 reglas de lógica. |

## Hallazgos nuevos

### A — Severidad alta

- **A1 — Sin límite de tamaño de cuerpo en endpoints JSON (DoS por sesión válida).**
  `app/Core/Request.php:48` lee `php://input` completo y `BuilderController.php:54` lo procesa sin tope. Un usuario autenticado puede saturar la memoria del proceso (acciones de escritura masivas) y degradar el servicio. **Recomendación:** límite (ej. 2 MB) previo a `json_decode`, responder 413; firma del endpoint también.
- **A2 — Fuerza bruta distribuida de credenciales.**
  `AuthController::login` limita 5 intentos/5 min **por IP**. Una botnet con muchas IPs no se detiene; no hay contador por email ni bloqueo de cuenta. **Recomendación:** contabilizar intentos fallidos por `email + IP` (y por email), desnormalizar en `rate_limits`, y exponer una política de backoff/bloqueo temporal.
- **A3 — Sin CSP y un output sin escapar.**
  El único caso que rompe la disciplina de escape es `layouts/main.php:49`: `<?= strtoupper(substr($currentUser['name'] ?? 'U', 0, 1)) ?>` (impacto directo bajo por ser 1 carácter, pero rompe el invariante). Además no hay `Content-Security-Policy` (solo `deny` XFO, nosniff y referrer) y varias páginas usan JS inline (error pages, `INIT_DRAFT` del builder, `CHART_DATA` de analytics, token CSRF en fill). **Recomendación:** escapar siempre y definir una CSP de producción que admita los inline conocidos (o migrarlos a módulos externos).

### M — Severidad media

- **M1 — Carrera last-write-wins en el autosave del builder.**
  Pideos de guardado concurrentes pueden persistir un estado previo. El siguiente autosave corrige, pero en edición rápida puede haber pérdida momentánea. **Recomendación:** versión/secuencia de guardado o deshabilitar el guardado mientras esté en vuelo (el JS ya hace debounce, pero no serializa).
- **M2 — Sin paginación en listados.**
  `Form::all()/byUser()`, `Submission::byForm` y vistas admin/dashboard sin `LIMIT/OFFSET`. Degradación garantizada con cientos de formularios o miles de respuestas. Pendiente documentado en recomendaciones; priorizar.
- **M3 — Validación de longitud inconsistente en updates.**
  `BuilderController` acepta `title`/`label` sin límite → `mb_strlen > 190` produce `PDOException` → 500 genérico sin feedback. Idem `register()` con email/name excesivos. **Recomendación:** validar en los puntos de entrada y capturar `PDOException` de negocio para devolver mensajes amigables.
- **M4 — `X-Frame-Options: DENY` global impide embeber formularios.**
  Caso de uso típico de self-hosted (insertar un `/f/{slug}` en un iframe externo) queda bloqueado. **Recomendación:** hacerlo configurable por `.env` o aplicar la cabecera solo a secciones admin.
- **M5 — Exportación CSV incoherente con el filtro de fechas.**
  `AnalyticsController::exportCsv` no respeta `from/to` del UI (criterio distinto al de la tabla visual). Unificar al mismo rango.
- **M6 — Privacidad en autosave del llenado (localStorage).**
  `fill.js` guarda respuestas (posible PII) sin TTL ni limpieza al enviar. **Recomendación:** `sessionStorage` o TTL + limpieza al completar.
- **M7 — Duplicados por doble submit.**
  No hay token de idempotencia en `FillController::submit`; el JS deshabilita el botón, pero si la respuesta no llega y se reintenta puede generarse un duplicado. Considerar token por intento.
- **M8 — Reescritura completa del formulario en cada autosave.**
  `saveStructure` reescribe todos los campos en una transacción (1 query por campo). Con +100 campos y autosave cada 1.5 s es pesado. Evaluar upsert por diffs o debounce mayor.
- **M9 — CDN externo de FontAwesome en páginas públicas.**
  `views/fill/closed.php` y `views/fill/draft.php` cargan FontAwesome desde `cdnjs.cloudflare.com` mientras el resto usa `all.min.css` local. Dependencia de terceros y rompe iconos en despliegues offline.

### B — Severidad baja

- **B1 — Router sin 405.** Método no permitido devuelve 404 en lugar de 405. Menor.
- **B2 — Variable muerta** `$db` en `AdminController::allForms`.
- **B3 — `View::resolve` admite `..`** (teórico; los templates son nombres fijos de controllers). Asentar en doc la restricción.
- **B4 — `Database::getPdo()`** expone PDO crudo (histórico A1); uso exclusivo tests. Considerar wrapper acotado.
- **B5 — `(int) $user['id']` sin guard de `null`** en `FormController::show/edit`; si el usuario se desactiva entre middleware y render habría warning/500. Guard explícito + redirect.
- **B6 — `docs/technical-document.md` desactualizado**: menciona rol `viewer` (real: `user`), no cubre secciones/agrupaciones/lógica del builder, y referencia `docs/api-spec.md`, **que no existe**.
- **B7 — Sin CSP** (ya absorbido en A3).
- **B8 — Seed con contraseña conocida** (`password123`) — riesgo solo si se despliega sin cambiar `.env` (documentado). Recordar en despliegue de producción.

## Recomendaciones priorizadas

1. **Inmediatas (seguridad):** A1 (límite de tamaño PHP + 413), A3 (escape avatar + CSP), A2 (fuerza bruta por email).
2. **Corto plazo (robustez):** M3, M1, M7, B5.
3. **Escalabilidad:** M2 (paginación), M8 (perf del builder).
4. **Producto:** M4 (embebido), M5 (filtro CSV), M6 (privacidad), M9 (CDN).
5. **Docs:** B6 (regenear technical-document y crear `api-spec.md`).

## Checklist de cierre (audit 2026-09-02)

- [x] Suit de tests propia ejecutable con `php scripts/test.php` — 77/77 verdes.
- [x] Lint PHP limpio en todo el código.
- [x] Migraciones aplicadas y status coherente con `database/schema.sql`.
- [x] Arranque en servidor embebido sin deprecaciones ni warnings en log.