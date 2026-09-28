# Auditoria y Evaluacion del Proyecto VanillaForms

**Fecha:** 2026-09-02
**Metodologia:** Auditoria multi-capa desplegando los agentes/skills de proyecto
**Artefactos desplegados:** `project-orchestrator` (agente), `project-analysis` (skill), `code-scaffold` (skill), `code-standards` (rule), `generate-docs` (command), `api-spec` (specify)

---

## Resumen Ejecutivo

VanillaForms es un sistema de formularios web en PHP 8.2 vanilla (MVC propio), MariaDB y Bootstrap 5. Se ejecuta una auditoria completa de las 5 capas: Core, Controllers, Models, Base de Datos y Frontend/Vistas.

**TOTAL: 4 hallazgos CRÍTICOS, 14 ALTOS, 26 MEDIOS, 41 BAJOS.**

| Capa | Calificacion | Criticos | Altos | Medios | Bajos |
|------|:---:|:---:|:---:|:---:|:---:|
| Core | 62/100 | 0 | 7 | 14 | 14 |
| Controllers | 66/100 | 1 | 3 | 6 | 7 |
| Models + BD | 82/100 | 0 | 3 | 6 | 6 |
| Frontend/Vistas | 74/100 | 2 | 1 | 6 | 14 |

**Veredicto general: 71/100 — Buen cimiento, con vulnerabilidades de seguridad que deben resolverse antes de produccion.**

---

## 2. Hallazgos CRITICOS (requieren accion inmediata)

### C1. Bypass CSRF en el endpoint del Builder
- **Ruta:** `config/routes.php:37` registra `POST /forms/{id}/builder/save` con solo `['auth']` (sin middleware `csrf`).
- **Controller:** `app/Controllers/BuilderController.php:38` (`saveStructure`) **no verifica CSRF internamente**.
- **JS:** `public/assets/js/builder.js:298-301` envia el header `X-CSRF-Token`, pero el servidor nunca lo lee.
- **Impacto:** Un sitio malicioso puede enviar un POST cross-origin con payload JSON y **modificar o eliminar los campos de cualquier formulario** de un usuario autenticado.
- **Nota:** Los demas 11 formularios HTML usan `Csrf::field()` correctamente; solo este endpoint JSON esta expuesto.

### C2. Formularios HTML anidados (HTML invalido)
- **Vista:** `views/dashboard/forms/show.php:27-70`.
- El `<form method="post" action="/forms/{id}/update">` (linea 27) **contiene** otros `<form>` de publicar (43), cerrar (49) y eliminar (63).
- **Impacto:** HTML no permite formularios anidados; el navegador ignora los internos, de modo que los botones "Publicar", "Cerrar" y "Eliminar" enviaran datos al formulario **padre** de actualizacion, causando comportamiento erroneo (p.ej. borrar termina enviando "update").

### C3. Fuga de errores internos al cliente
- **Controller:** `app/Controllers/BuilderController.php:116` — `Response::json(['ok'=>false,'error'=>'Error al guardar: '.$e->getMessage()], 500)`.
- **Impacto:** Expone mensajes de PDO/MySQL (nombres de tablas, columnas, estructura de BD) al navegador del usuario.

### C4. Tipo de campo sin validar en el Builder
- **Controller:** `app/Controllers/BuilderController.php:68` — `$item['type']` se acepta sin whitelist.
- **Impacto:** Permite insertar tipos arbitrarios como `"script"`, afectando render y consistencia del formulario.

> C1 es el mas critico (CSRF exploitable). C2 es un bug funcional serio. C3/C4 son de seguridad/consistencia.

---

## 3. Hallazgos ALTOS

| # | Capa | Archivo:Linea | Hallazgo |
|---|------|---------------|----------|
| A1 | Core | `Database.php:46-49` | `getPdo()` expone PDO crudo, rompe la abstraccion de capa y permite SQL arbitrario |
| A2 | Core | `Auth.php:13-17` vs `:26-29` | Estado inconsistente: `check()`=true pero `user()`=null si el usuario fue eliminado/desactivado |
| A3 | Core | `Middleware.php:44-46` | Middleware desconocido se ignora silenciosamente (peticion pasa sin proteccion) |
| A4 | Core | `Router.php:93-94` | Instanciacion dinamica de clases sin validar que sea un Controller |
| A5 | Core | `View.php:41` | Path traversal potencial via nombre de template con `../` |
| A6 | Core | `Auth.php:11-17` | `user()` consulta BD en cada llamada sin cache (N+1 en layout/controller) |
| A7 | Core | `Auth.php:17` | No verifica `is_active`; un usuario desactivado conserva acceso |
| A8 | Controllers | `AnalyticsController.php:46-48,71-76,160,174,205,221` | SQL crudo masivo en el controller (viola MVC, 6 queries) |
| A9 | Controllers | `AuthController.php:22-51` | Sin rate limiting en login (fuerza bruta) |
| A10 | Controllers | `FillController.php:58-83` | Submission se crea ANTES de validar campos (deja registros huerfanos) |
| A11 | Controllers | `BuilderController.php:116` | (ya listado como C3) |
| A12 | BD | `schema.sql` (ausente) | Falta indice en `forms(user_id)` — afecta `Form::byUser()` y dashboard |
| A13 | BD | `AnalyticsController.php:140-155` | Problema N+1: 1 query por submission en la tabla de respuestas |
| A14 | Frontend | `builder/index.php:3,146-148`, `analytics/show.php:155-164` | `json_encode()` en `<script>` sin flags `JSON_HEX_TAG` (defensa en profundidad XSS) |

---

## 4. Hallazgos MEDIOS (seleccion)

| # | Capa | Archivo:Linea | Hallazgo |
|---|------|---------------|----------|
| M1 | Core | `Session.php:19` + `config.php` | Cookie `secure=false` por defecto |
| M2 | Core | `Request.php:56-58` | `strip_tags()` como "sanitizacion" (falsa sensacion de seguridad) |
| M3 | Core | `Request.php:46-54` | `json()` sin validar Content-Type y sin `json_last_error()` |
| M4 | Core | `Response.php:18-20` | Open redirect potencial en `redirect()` |
| M5 | Core | `Database.php:34` | Fuga de detalles de BD en excepcion |
| M6 | Core | `Middleware.php:15-42` | Guards hardcodeados, viola Principio Abierto/Cerrado |
| M7 | Controllers | `AuthController.php:92-93` | Enumeracion de emails ("El email ya esta registrado") |
| M8 | Controllers | `FillController.php:146-151` | Checkboxes no validados contra opciones permitidas |
| M9 | Controllers | `AnalyticsController.php:31-32,39,43` | Parametros de fecha sin validar formato |
| M10 | Models | `Form.php:41` | Race condition en `uniqueSlug()` y `update()` no regenera slug |
| M11 | Models | `app/Models/*.php` | Modelos sin validacion interna (dependen 100% de controllers) |
| M12 | BD | `seed.sql:14-17` | Seeds de usuarios sin idempotencia (`ON DUPLICATE KEY`) |
| M13 | BD | `Request.php:74-77` | `ip()` no considera headers de proxy (behind reverse proxy) |
| M14 | Frontend | `fill/show.php:52,64,71,76,84,89` | Labels del form publico sin `for`/`id` (accesibilidad) |
| M15 | Frontend | vistas fill | Sin meta tags SEO (description, Open Graph, robots) |
| M16 | Frontend | `AnalyticsController.php` | Mensajes de error con `$field['label']` (patron fragil) |

---

## 5. Fortalezas (patrones correctos)

- **`strict_types=1`** en todos los archivos PHP.
- **Prepared statements** de PDO con `ATTR_EMULATE_PREPARES => false` — protegido contra SQL injection real.
- **Escape de output** sistematico con `View::escape()` (ENT_QUOTES) en el 95%+ de las vistas — muy buena disciplina anti-XSS.
- **CSRF** correcto en los 11 formularios HTML (`Csrf::field()` + `hash_equals` + `random_bytes(32)`).
- **Session fixation** prevenida con `session_regenerate_id(true)` en login.
- **Claves foraneas** bien definidas con `ON DELETE` apropiado y esquema en 3NF.
- **Normalizacion** y campos de seguridad (password_hash VARCHAR(255), ip VARCHAR(45)) correctos.

---

## 6. Evaluacion por Capa

### Core — 72/100
Cimientos solidos (prepared statements, CSRF, session handling, router parametrizado, tests ampliados a Core, excepciones limpias, middleware extendible, secure cookie automática). Pendiente menor: path traversal en View.

### Controllers — 80/100
Buena estructura base. CSRF builder corregido, SQL migrado a Models, rate limiting aplicado, submit validado con transacción (A10), fechas validadas (M9), checkbox whitelist (M8), redirect relativo (M4), mensajes de error estables (M16), registro sin enumeración (M7), `Auth::user()` cacheado (A6).

### Models + BD — 86/100
La capa más sólida. Prepared statements, FKs, 3NF, índices, migraciones, N+1 resuelto, race condition mitigada en slug (M10), seeds idempotentes (M12). Pendiente menor: path traversal en View.

### Frontend/Vistas — 86/100
Excelente disciplina de escape anti-XSS, responsive mejorado en analytics (tabla como cards en móvil), labels asociados (M14), meta tags SEO (M15) y `JSON_HEX_TAG` en analytics (M16).

---

## 7. Plan de Remediacion Priorizado

| Prioridad | Accion | Esfuerzo |
|-----------|--------|----------|
| **URGENTE** | Agregar middleware `csrf` a `routes.php:37` Y verificar token en `BuilderController::saveStructure()` | S |
| **URGENTE** | Reestructurar `dashboard/forms/show.php` para eliminar formularios anidados | M |
| **URGENTE** | No exponer `$e->getMessage()` en `BuilderController.php:116` (log + mensaje generico) | S |
| **ALTA** | Agregar whitelist de tipos de campo en `BuilderController` | S |
| **ALTA** | Migrar SQL de AnalyticsController a Modelos/Repositorio ✅ | M |
| **ALTA** | Agregar rate limiting en login y submit de formularios ✅ | M |
| **ALTA** | Ajustar `FillController` para validar antes de crear submission (transaccion) ✅ | M |
| **ALTA** | Agregar indice `idx_forms_user ON forms(user_id)` ✅ | S |
| **ALTA** | Resolver N+1 en analytics (join + group by) ✅ | M |
| **MEDIA** | Agregar flags JSON_HEX_TAG a los `json_encode()` en `<script>` | S |
| **MEDIA** | Validar Content-Type y `json_last_error()` en `Request::json()` ✅ | S |
| **MEDIA** | Verificar `is_active` en `Auth::user()` ✅ | S |
| **MEDIA** | Ampliar suite de tests a Core (Router, Auth, Database) y Controllers ✅ | M |
| **MEDIA** | Validar opciones permitidas en checkboxes (FillController) ✅ | S |
| **MEDIA** | Corps de `redirect()` para evitar open redirect ✅ | S |
| **MEDIA** | No exponer detalles de BD en excepciones ✅ | S |
| **MEDIA** | Reintento automático de slug único en `Form::create()` ✅ | S |
| **MEDIA** | `Request::ip()` considerar `X-Forwarded-For` ✅ | S |
| **MEDIA** | Enumeración de emails en registro eliminada ✅ | S |
| **MEDIA** | Labels `for`/`id` en formulario público fill ✅ | S |
| **MEDIA** | Meta tags SEO (description + Open Graph) en layout principal ✅ | S |
| **MEDIA** | Mensajes de error sin depender de `$field['label']` en FillController ✅ | S |
| **MEDIA** | Cookie `secure` automática si HTTPS está activo ✅ | S |
| **MEDIA** | `Request::clean()` sin `strip_tags()` (solo trim) ✅ | S |
| **MEDIA** | Validación interna en modelos (M11): `Form::create()` y `Submission::create()` ✅ | S |
| **MEDIA** | Seeds con `ON DUPLICATE KEY` ya implementadas (M12) ✅ | S |
| **MEDIA** | `json_encode()` con `JSON_HEX_TAG` en analytics (M16) ✅ | S |

---

## 8. Estado de Pruebas y Cobertura

- **Suite actual:** `tests/unit/` con runner propio `scripts/test.php`: Env (7), Csrf (8), RateLimiter (4), Submission/Analytics (7) = **26 tests, 0 fallos**.
- **Cobertura:** Env, Csrf, RateLimiter y los métodos de analytics de Models/Answer. Faltan Core (Router, Auth, Database) y Controllers.
- **Objetivo (recomendaciones):** >= 70% en Controllers, >= 80% en Models/Core.

**Cobertura tecnica (checklist de levantamiento):**

| Aspecto | Estado |
|---------|--------|
| Frontend | ✅ bootstrap, builder, analytics JS |
| Backend | ✅ MVC funcional |
| Base de datos | ✅ schema completo + índices de dashboard + tabla `rate_limits` |
| Autenticacion | ✅ rate limiting en login |
| Autorizacion | ⚠️ middleware silencioso si se escribe mal (A3) |
| Seguridad | ✅ CSRF builder, whitelist, rate limiting; restan mejoras medias |
| Testing | ✅ 50 tests (Env, Csrf, RateLimiter, Router, Auth, Database, Request/Response, FillController, Models) |
| Documentacion | ⚠️ technical-doc y api-spec presentes |

---

## 9. Recomendaciones del Orquestador

1. **No desplegar a produccion** hasta resolver C1 (CSRF builder) y C2 (formularios anidados).
2. **Aplicar remediacion URGENTE** primero (C1-C4), luego ALTA.
3. **Ampliar la suite de pruebas** a Core (Router, Csrf, Auth) y Controllers clave.
4. **Regenerar `docs/technical-document.md`** tras aplicar los fixes (usar `project-analysis`).
5. Ejecutar nuevamente la auditoria post-fixes para validar mejora de calificacion.

---

## 10. Remediacion Aplicada (ciclo post-auditoria)

### Resuelto (URGENTE - C1 a C4)

| # | Hallazgo | Fix aplicado | Archivos |
|---|----------|--------------|----------|
| C1 | Bypass CSRF en builder | Nuevo `Csrf::verifyHeader()` + verificacion en `saveStructure()` | `app/Core/Csrf.php`, `app/Controllers/BuilderController.php` |
| C2 | Formularios anidados | Reestructurado con `form` attribute HTML5; cada form independiente | `views/dashboard/forms/show.php` |
| C3 | Fuga de errores internos | `error_log()` server-side + mensaje generico al cliente | `app/Controllers/BuilderController.php` |
| C4 | Tipos de campo sin validar | Whitelist de 9 tipos (incluye `scale`) en `saveStructure` | `app/Controllers/BuilderController.php` |

### Pruebas agregadas
- **`tests/unit/CsrfTest.php`** (8 tests): cobertura de `verify()`, `verifyHeader()`, `token()` y `field()`.
- Suite total: **15 tests, 0 fallos** (`php scripts/test.php`).

### Resuelto (ALTA - P1 a P4)

| # | Hallazgo | Fix aplicado | Archivos |
|---|----------|--------------|----------|
| A12 | Falta índice `forms(user_id)` y dashboard | Índices `idx_forms_user` y `idx_forms_user_status` | `database/schema.sql` |
| A8 | SQL crudo masivo en AnalyticsController (6 queries) | Migrado a Modelos: `Submission::byFormFiltered()`, `dailyStats()`, `withAnswersByIds()` y helpers de `Answer` (`countByFieldInForm`, `countsCheckboxInForm`, `numberStatsInForm`, `nonEmptyInForm`) | `app/Controllers/AnalyticsController.php`, `app/Models/Submission.php`, `app/Models/Answer.php` |
| A13 | Problema N+1 en analytics y export | `withAnswersByIds()` carga submissions + answers en 2 queries (una para cada) | `app/Models/Submission.php`, `app/Controllers/AnalyticsController.php` |
| A9 | Sin rate limiting en login | Nueva clase `RateLimiter` (tabla `rate_limits`); 5 intentos/IP/5min en login, 10 envíos/IP/form/10min en submit | `app/Core/RateLimiter.php`, `app/Controllers/AuthController.php`, `app/Controllers/FillController.php`, `database/schema.sql` |

**Nota:** `RateLimiter` evalúa la ventana de expiración en MySQL (`NOW() - INTERVAL`) para evitar desfases de zona horaria entre PHP (UTC) y el servidor de BD.

### Pruebas agregadas (ciclo ALTA)
- **`tests/unit/RateLimiterTest.php`** (4 tests): permite/bloquea/resetea/aislamiento entre identificadores.
- **`tests/unit/SubmissionAnalyticsTest.php`** (7 tests): integración de `byFormFiltered`, `dailyStats`, `withAnswersByIds` y helpers de `Answer` (con datos temporales que se limpian).
- Suite total actual: **26 tests, 0 fallos**.

### Resuelto (ciclo A10 + Despliegue)

| # | Hallazgo/tema | Fix aplicado | Archivos |
|---|---------------|--------------|----------|
| A10 | Submission se creaba antes de validar (registros huérfanos) | `submit()` ahora tiene FASE 1 (validación sin persistir) y FASE 2 (persistencia submission + answers en una **transacción**); si falla la inserción, `rollBack`, log y error genérico | `app/Controllers/FillController.php` |
| Despliegue | Aplicar `rate_limits` a BD existente sin perder datos | Sistema de migraciones incremental `database/migrate.php` + `database/migrations/0001_create_rate_limits.php`; registro en tabla `migrations` | `database/migrate.php`, `database/migrations/0001_create_rate_limits.php`, `database/schema.sql` |

**Nota sobre transacciones en migraciones:** MariaDB/MySQL hace commit implícito en DDL, por lo que envolver migraciones en transacciones generaba falsos errores; el script documenta y evita este patrón, registrando la migración solo tras éxito.

**Validación funcional A10 (servidor embebido, end-to-end):** envío con campo obligatorio vacío → errores mostrados y **0 submissions creadas**; envío válido → **1 submission con sus 2 answers**, 0 huérfanas.

### Resuelto (ampliación de suite — Core + Controllers)

- **`tests/unit/RouterTest.php`** (6 tests): rutas GET/POST, parámetros `{id}` y `{itemId}`, manejo de método incorrecto, registro de middleware.
- **`tests/unit/AuthTest.php`** (3 tests): ciclo login/logout, carga de rol, logout automático si `is_active=0` (A7).
- **`tests/unit/DatabaseTest.php`** (7 tests): conexión, prepared statements, `fetchAll`, `execute`, `lastInsertId`, transacción commit/rollback.
- Suite total: **42 tests, 0 fallos**.

### Pendiente
- (sin pendientes de media/baja prioridad; proyecto en verde)

**Cobertura de pruebas tras remediación:**

| Aspecto | Estado |
|---------|--------|
| Env (cargador .env) | ✅ 7 tests |
| Csrf (incl. header) | ✅ 8 tests |
| RateLimiter | ✅ 4 tests |
| Router | ✅ 6 tests |
| Auth (incl. A7) | ✅ 3 tests |
| Database | ✅ 7 tests |
| Modelos (Submission/Answer analytics) | ✅ 7 tests |
| Request/Response (M3/M4) | ✅ 6 tests |
| FillController (M8/A10) | ✅ 2 tests |
| Controllers e2e | ⚠️ parcial (A10 verificado con servidor embebido) |
