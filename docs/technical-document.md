# Documento Tecnico - VanillaForms

**Proyecto:** VanillaForms
**Version:** 1.1.0
**Fecha:** 2026-09-25 (revisión de auditoría)
**Estado:** Estable

---

## 1. Resumen Ejecutivo

VanillaForms es un sistema de formularios web self-hosted construido con PHP 8.2 vanilla (sin framework), utilizando un patron MVC propio, MariaDB como base de datos y Bootstrap 5 para el frontend. El sistema permite crear, publicar y gestionar formularios con campos configurables, recolectar respuestas, visualizar analiticas y administrar usuarios con roles.

**Objetivo principal:** Sistema auto-hospedado de creacion y gestion de formularios con control total sobre datos y privacidad.

**Estado actual:** Funcional con suite de tests (442) en verde y auditoría de seguridad (2026-09-25) con remediaciones aplicadas. Documento reconciliado el 2026-09-28 (fase 3 horaria/analítica, fase 4 de limpieza y decisiones F5).

---

## 2. Alcance

### Incluido
- Autenticacion y gestion de usuarios con roles (admin, editor, user)
- CRUD de formularios con estados (draft, published, closed)
- Constructor visual de campos (builder) con drag & drop, secciones informativas y logica condicional de visibilidad
- Formulario publico de respuesta via slug
- Analiticas con graficos (Chart.js) y exportacion CSV
- Panel de administracion de usuarios y formularios
- Carga de archivos en el formulario publico (campo `file`) con validacion por MIME real, almacenamiento fuera de `public/` y descarga autenticada

### No incluido (futuro)
- Integraciones con sistemas externos
- Paginas de destino personalizadas
- Multi-tenant
- Integraciones de pago

---

## 3. Arquitectura del Sistema

## Patron: Monolito MVC (implementacion propia)

```
Cliente (Browser)
     ↓ HTTP
public/index.php (front controller)
     ↓
App\Core\Router (dispatcher + middleware)
     ↓
App\Controllers\* (logica HTTP)
     ↓                    ↓
App\Models\*        App\Core\* (base)
     ↓                    ↓
PDO ← MariaDB
     ↓
views/*.php (templates)
     ↓
HTTP Response
```

### Capas

| Capa | Responsabilidad | Ubicacion |
|------|----------------|-----------|
| **Front Controller** | Configuracion, arranque, dispatch | `public/index.php` |
| **Router** | Matching URL -> handler + middleware | `app/Core/Router.php` |
| **Controllers** | Manejar request/response, validar, orquestar | `app/Controllers/` |
| **Models** | Acceso a datos y logica de dominio | `app/Models/` |
| **Core** | Clases base (DB, Auth, Session, View, Request, Response, Csrf, Config) | `app/Core/` |
| **Middleware** | Autenticacion, autorizacion, admin | `app/Core/Middleware/` |
| **Views** | Rendering HTML | `views/` |

### Core classes

- `Router`: despacha GET/POST con middleware. Soporta parametros `{id}`, `{slug}`.
- `Database`: singleton PDO con prepared statements, transacciones.
- `Auth`: login/logout, usuario actual, roles.
- `Session`: flash messages, CSRF token.
- `Csrf`: generacion/verificacion de tokens.
- `Request`: acceso a POST/GET params con defaults.
- `Response`: redirect, notFound, forbidden.
- `View`: render de templates con escape.
- `Config`: carga de parametros desde `config/config.php`.

---

## 4. Modelo de Datos

### Diagrama de relaciones

```
roles (1)──(N) users (1)──(N) forms (1)──(N) fields
                                  │
                                  (N)
                              submissions (1)──(N) answers
```

### Tablas

#### roles
| Campo | Tipo | Notas |
|-------|------|-------|
| id | INT UNSIGNED AI PK | |
| name | VARCHAR(50) UNIQUE | admin, editor, user |
| description | VARCHAR(255) NULL | |
| created_at | TIMESTAMP | |

#### users
| Campo | Tipo | Notas |
|-------|------|-------|
| id | INT UNSIGNED AI PK | |
| role_id | INT UNSIGNED FK→roles | default 2 (user) |
| name | VARCHAR(100) | |
| email | VARCHAR(190) UNIQUE | |
| password_hash | VARCHAR(255) | bcrypt |
| is_active | TINYINT(1) | default 1 |
| created_at / updated_at | TIMESTAMP | |

#### forms
| Campo | Tipo | Notas |
|-------|------|-------|
| id | INT UNSIGNED AI PK | |
| user_id | INT UNSIGNED FK→users | ON DELETE CASCADE |
| title | VARCHAR(190) | |
| description | TEXT NULL | |
| slug | VARCHAR(190) UNIQUE | public URL |
| status | ENUM('draft','published','closed') | |
| theme / settings | JSON NULL | |
| created_at / updated_at | TIMESTAMP | |

#### fields
| Campo | Tipo | Notas |
|-------|------|-------|
| id | INT UNSIGNED AI PK | |
| form_id | INT UNSIGNED FK→forms | CASCADE |
| type | VARCHAR(30) | text, textarea, select, radio, etc |
| label | VARCHAR(190) | |
| placeholder / help_text | nullables | |
| is_required | TINYINT(1) | |
| options / validations | JSON NULL | |
| position | INT | orden (index sobre form_id,position) |
| parent_id | INT UNSIGNED FK→fields NULL | sección contenedora (NULL = nivel superior) |
| logic | JSON NULL | condición de visibilidad (operador + reglas) |

#### submissions
| Campo | Tipo | Notas |
|-------|------|-------|
| id | INT UNSIGNED AI PK | |
| form_id | INT UNSIGNED FK→forms | CASCADE |
| ip_address | VARCHAR(45) NULL | |
| user_agent | VARCHAR(255) NULL | |
| finished_at | TIMESTAMP | index (form_id, finished_at) |

#### answers
| Campo | Tipo | Notas |
|-------|------|-------|
| id | INT UNSIGNED AI PK | |
| submission_id | INT UNSIGNED FK→submissions | CASCADE |
| field_id | INT UNSIGNED FK→fields | CASCADE |
| value | TEXT NULL | |
| created_at | TIMESTAMP | |

---

## 5. Diseno de Frontend y UX

### Stack
- Bootstrap 5.3 (CSS/JS)
- Chart.js 4 (graficos analiticos)
- SortableJS (drag&drop en builder)
- JS vanilla (sin frameworks)

### Paginas principales

| Vista | Ruta | Descripcion |
|-------|------|-------------|
| Login/Register | `/login`, `/register` | Autenticacion |
| Dashboard | `/dashboard` | Lista de formularios + acciones |
| Form show | `/forms/{id}` | Detalle, estado, enlace publico |
| Builder | `/forms/{id}/builder` | Editor drag&drop de campos |
| Fill | `/f/{slug}` | Formulario publico |
| Thanks/Closed/Draft | `/f/...` | Estados de respuesta |
| Analytics | `/forms/{id}/analytics` | Graficos + export |
| Admin | `/admin` | Gestion general |

### Accesibilidad
- Semantic HTML con labels
- Estados de foco (Bootstrap default)
- Mensajes de error via flash session

---

## 6. Diseno de API y Contratos

**Referencia completa:** `docs/api-spec.md`

### Contratos principales

**Crear formulario**
```
POST /forms
Authorization: session cookie
Body: title=Mi Formulario&description=Desc
Response: 302 -> /forms/{id}/builder
```

**Guardar estructura builder**
```
POST /forms/{id}/builder/save
Headers: Content-Type: application/json, X-CSRF-Token: <token>
Body:
{
  "title": "Encuesta",
  "description": "…",
  "status": "published",
  "fields": [
    { "_uid": "field-1", "type": "section", "label": "Sección A" },
    { "_uid": "field-2", "type": "text", "label": "Nombre",
      "parent_id": "field-1", "is_required": true,
      "logic": { "operator": "all", "rules": [...] } }
  ]
}
Response: 200 {"ok": true} | 422 estructura inválida | 419 token inválido | 413 excede límite
```

**Enviar respuesta publica**
```
POST /f/{slug}
Body: _token=<csrf>&field_{id}=valor
Response: 200 -> fill/thanks (o 419 / 429 según validación/rate limit)
```

**Exportar CSV**
```
GET /forms/{id}/export.csv[?from=YYYY-MM-DD&to=YYYY-MM-DD]
Response: text/csv attachment (respeta el filtro de fechas)
```

---

## 7. Plan de Pruebas

### Estado actual
- Runner propio creado en `scripts/test.php` (sin dependencias, no usa Composer/PHPUnit). Filtros con espacio: `--filter <substring del MÉTODO>`, `--path <directorio>` (un filtro que no casa con nada ejecuta 0 tests y sale verde — comprobar el contador).
- Suite: `tests/unit/*.php` — **442 tests en verde** (Core, Models, Controllers parcial, integración sobre BD real con datos temporales `@test.local`, smoke de TODAS las rutas con el Router real en `ControllerActionsTest`). El detalle de fases vive en `docs/roadmap-encuesta.md`.

### Ejecucion
```bash
php scripts/test.php                # todos los tests
php scripts/test.php --filter Auth  # filtrar por nombre de método (espacio, NO `=`)
php scripts/test.php --path tests/unit
```

### Cobertura objetivo
- Models y Core: >= 80%
- Controllers: >= 70%

---

## 8. Seguridad y Rendimiento

### Seguridad actual
- ✅ CSRF tokens en todos los POST (campo `_token` o header `X-CSRF-Token` para JSON)
- ✅ password_hash/verify (bcrypt)
- ✅ Sesiones con httponly, samesite, strict mode
- ✅ Prepared statements (PDO)
- ✅ Middleware de auth y roles (auth, guest, admin, editor, csrf, csrf-header)
- ✅ Rate limiting en login (por IP y por email), register y envío de respuestas
- ✅ Escape sistemático de salida (View::escape)
- ✅ Redirects internos solo relativos (anti open-redirect); `thanks.redirect_url` externa pasa por la allowlist de servidor `APP_REDIRECT_ALLOWLIST` (vacía = bloqueada por defecto, F5.1)
- ✅ Rate limit del dashboard por usuario (crear/exportar, F5.3): `DASH_CREATE_PER_HOUR`/`DASH_EXPORT_PER_HOUR`, 429 con `views/errors/429.php`
- ✅ RBAC (2026-09-28, fase 6): catálogo de permisos en `config/permissions.php` + matriz en `role_permissions`; `Auth::can()` como única puerta de negocio y permisos efectivos = rol propio + roles de sus grupos (`role_groups*`); `App\Core\Access` para el alcance del dueño (`*_own` && dueño || `forms.manage_all`); middleware `admin` = `Auth::can('admin.access')`
- ✅ Log de eventos de seguridad (2026-09-28, fase 6): tabla `security_log` + `/admin/security-log` (`SecurityLog::record` nunca rompe el request, valida el formato del evento, `user_id` NULL si la sesión apunta a un usuario inexistente, purga por `purgeOlderThan()`)
- ✅ Límite de tamaño de cuerpo JSON y 405 para métodos no permitidos
- ✅ CSP y X-Frame-Options configurables por `.env` (CSP activa por defecto desde 2026-09-25; datos de servidor vía `data-*`, handlers JS externalizados)
- ✅ Uploads (P10): storage en `storage/uploads/` (fuera de `public/`) con nombre aleatorio y `.htaccess` defensivo; MIME detectado con `finfo` (nunca `$_FILES['type']`); lista negra de extensiones ejecutables (php, html, svg, exe…); el campo solo puede **restringir** formatos/tamaño/cantidad, nunca ampliarlos
- ✅ Descarga de adjuntos siempre por la app: `GET /attachments/{answers.id}?i=N` con autorización (dueño/editor/admin), `Content-Type: application/octet-stream`, `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store` y `Content-Security-Policy: default-src 'none'; sandbox`
- ✅ 413 dedicado (`post-max` antes de `csrf` + `views/errors/413.php`) cuando el cuerpo supera `post_max_size`, en lugar de un "CSRF inválido" engañoso
- ✅ Purga de adjuntos al borrar una respuesta, un formulario o un campo del builder, y rollback si el resto del envío es inválido
- ⚠️ `Database::getPdo()` (expone PDO crudo): uso restringido a tests

### Rendimiento

**Actual:**
- Indices en subclaves consultadas (forms.status, fields.form_id, answers*)
- PDO con `EMULATE_PREPARES=false`
- N+1 resuelto en analytics (submissions + answers en una consulta)
- Paginación client-side en dashboard, admin y tabla de analítica
- Caché del dashboard (2026-09-28, fase 6): `Cache::remember('dash:...', 60, ...)` con TTL e invalidación por prefijo `dash:%` en cada mutación de forms/submissions; la pérdida de caché nunca rompe el request
- Tabla de analítica paginada EN EL SERVIDOR (2026-09-28): `Submission::byFormFilteredPaginated` + `GET /forms/{id}/analytics/rows` (15/página, lazy-load por fetch); los agregados siguen globales
- `defer` en scripts no críticos (dashboard.js, sortable+builder, chart+analytics), preservando el orden de dependencias

**Despliegue (uploads):**
- `php.ini`: `upload_max_filesize` y `post_max_size` deben admitir el tamaño máximo anunciado (por defecto la app anuncia 10 MB, pero el servidor lo recorta al valor real de PHP)
- nginx: `client_max_body_size` mayor que `post_max_size` (si no, nginx rechaza el POST antes de PHP)
- El usuario del web server necesita permiso de escritura en `storage/uploads/`; ese directorio **no** debe servirse por el web server

**Pendiente:**
- Lazy loading del builder JS (cargar el bundle solo al entrar al builder)

---

## 9. Cronograma y Estado

| Fase | Tarea | Estado |
|------|-------|--------|
| 1 | Config a .env con cargador propio (Env.php + .env.example) | ✅ |
| 2 | Rate limiting en auth (IP + email) y envíos | ✅ |
| 3 | Suite testing (runner propio, 79 tests) | ✅ |
| 4 | Documentación API (`docs/api-spec.md`) | ✅ |
| 5 | Paginación dashboard / admin / analítica (client-side) | ✅ |
| 6 | Remediaciones de auditoría 2026-09-25 | ✅ |
| P1–P10 | Roadmap del producto (multi-paso, correos, honeypot/embed/QR, quiz+salta-pagina+aleatoriedad, plantillas/export/impresion/i18n, uploads de archivos) | ✅ (ver `docs/roadmap-encuesta.md`) |
| — | Zona horaria (fase 3 · D6): BD y PHP en UTC, conversión solo al pintar (Clock/View) | ✅ (2026-09-28) |
| — | Analítica agregada (fase 3 · D8): `FieldAggregator` en 2 queries + métricas derivadas en PHP | ✅ (2026-09-28) |
| — | Seguridad/quality (fase 4): smoke de todas las rutas, allowlist de redirect (F5.1), editor pendiente de RBAC (F5.2), límite dashboard por usuario (F5.3), thumbs, scale, limpieza de código muerto y assets | ✅ (2026-09-28) |
| — | RBAC completo (fase 6, 2026-09-28): catálogo de permisos, matriz de roles editable, grupos de roles, `Auth::can()`/`Access` como puertas, middleware `admin` por permiso | ✅ (2026-09-28) |
| — | Log de eventos de seguridad (fase 6, 2026-09-28): `security_log` + `/admin/security-log` + hooks en auth/CSRF/rate-limit/webhook/uploads/admin | ✅ (2026-09-28) |
| — | Rendimiento (fase 6, 2026-09-28): caché del dashboard (`Cache::remember`, invalidación por prefijo) + tabla de analítica paginada EN EL SERVIDOR (`/analytics/rows`) + `defer` en scripts no críticos | ✅ (2026-09-28) |

## Proximos Pasos

1. Lazy loading del builder JS (cargar `builder.js`/`sortable` solo al entrar al builder)
2. Verificación visual de la paginación server-side de analítica y de la matriz de roles en navegador
3. Re-evaluar con el orquestador
