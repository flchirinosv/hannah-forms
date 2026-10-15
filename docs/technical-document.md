# Documento Tecnico - VanillaForms

**Proyecto:** VanillaForms
**Version:** 1.2.0
**Fecha:** 2026-10-05 (listado del panel paginado)
**Estado:** Estable

---

## 1. Resumen Ejecutivo

VanillaForms es un sistema de formularios web self-hosted construido con PHP 8.2 vanilla (sin framework), utilizando un patron MVC propio, MariaDB como base de datos y Bootstrap 5 para el frontend. El sistema permite crear, publicar y gestionar formularios con campos configurables, recolectar respuestas, visualizar analiticas y administrar usuarios con roles.

**Objetivo principal:** Sistema auto-hospedado de creacion y gestion de formularios con control total sobre datos y privacidad.

**Estado actual:** Funcional, con la suite en verde (**1032 tests**) y auditoría de seguridad (2026-09-25) con remediaciones aplicadas. Reconciliado el 2026-09-28 (fase 3 horaria/analítica, fase 4 de limpieza y decisiones F5) y el 2026-10-02 (F0.4-uno…cuatro: el panel y `/admin` contestan JSON por fetch y el camino sin JavaScript sigue siendo un POST normal, `docs/decisiones.md`). Y el 2026-10-05, M2: el listado de formularios pagina en el servidor y por eso su búsqueda, filtro y orden viajan en la URL (`docs/decisiones.md`).

---

## 2. Alcance

### Incluido
- Autenticacion y gestion de usuarios con roles (admin, editor, user, approver)
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
| **Services** | Reglas que no son de una tabla: PII, ajustes, webhook, agregados, **HTML de los huecos del panel** | `app/Services/` |
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
- `Response`: redirect, notFound, forbidden, y la parte **bimodal**: `wantsJson()` (mira `X-Requested-With`), `json()` y `html()` (mapa `id → markup`).
- `View`: render de templates con escape.
- `Config`: carga de parametros desde `config/config.php`.
- `FormPanel` (servicio): los huecos del panel del formulario (`vfSlotStatusBadge`, `vfSlotReviewTrail`, `vfSlotActions`, `vfSlotShare` y `vfSlotAccess`), para que GET y el POST por fetch pinten **los mismos partials**.

---

## 4. Modelo de Datos

### Diagrama de relaciones

```
roles (1)──(N) users (1)──(N) forms (1)──(N) fields
  │                           │
  │ (N)                       │ (N)
  role_permissions ↔ permissions │ submissions (1)──(N) answers
  user_permissions ↔ permissions │
  │                           │
  role_group_roles (N)──(M) role_groups ──(N) role_group_users (M)──(1) users
```

El listado de participantes son tres tablas: `form_participants` (una fila por
persona, con su `code` de acceso, su `responded_at`, su `invited_at`, que es
«cuándo se le mandó el correo de invitación», justo detrás de `responded_at`, y
su `reminded_at` + `reminder_count`, que es «cuándo recibió el último recordatorio»
y «cuántos ha recibido» —el recordatorio es repetible, la invitación no—),
`form_participant_fields` (el catálogo de atributos, que sobrevive a vaciar la lista)
y `form_participant_attrs` (el valor de un atributo para una persona).

Todo lo demás son tablas soporte: `rate_limits` (anti-abuso), `migrations`
(qué migraciones se aplicaron), `password_resets` (F2),
`form_access_emails`/`form_access_tokens` (F4), `security_log` (auditoría que
nunca rompe el request), `cache` (caché TTL del dashboard) y `app_settings`
(interruptores de la instalación).

### Tablas

#### roles
| Campo | Tipo | Notas |
|-------|------|-------|
| id | INT UNSIGNED AI PK | |
| name | VARCHAR(50) UNIQUE | admin, editor, user, approver |
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
| password_changed_at | DATETIME NULL | F2: cierra sesiones anteriores al cambio |
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
| status | ENUM('draft','in_review','published','closed') | F3: `in_review` no se sirve (página informativa) |
| theme / settings | JSON NULL | incluye `review.{enabled,notify_email}` (F3) y `access.mode` (F4) |
| review_requested_at | DATETIME NULL | F3: cuándo se mandó a revisión |
| reviewed_at | DATETIME NULL | F3: cuándo se aprobó |
| reviewed_by | INT UNSIGNED NULL FK→users | ON DELETE SET NULL (F3: la traza no se rompe al borrar el usuario) |
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
| is_pii | TINYINT(1) | F1: el dueño marca el campo como personal |
| show_header | TINYINT(1) | solo `page`: pintar su cabecera en el formulario (0013, DEFAULT 1) |

#### submissions
| Campo | Tipo | Notas |
|-------|------|-------|
| id | INT UNSIGNED AI PK | |
| form_id | INT UNSIGNED FK→forms | CASCADE |
| ip_address | VARCHAR(45) NULL | |
| user_agent | VARCHAR(255) NULL | |
| finished_at | TIMESTAMP | index (form_id, finished_at) |
| public_token | CHAR(32) UNIQUE NULL | thanks por token de capacidad, nunca por `id` |
| participant_id | INT UNSIGNED FK→form_participants NULL | SET NULL; solo en formularios de listado (`roster`) |

#### answers
| Campo | Tipo | Notas |
|-------|------|-------|
| id | INT UNSIGNED AI PK | |
| submission_id | INT UNSIGNED FK→submissions | CASCADE |
| field_id | INT UNSIGNED FK→fields | CASCADE |
| value | TEXT NULL | |
| created_at | TIMESTAMP | |

#### form_access_emails
| Campo | Tipo | Notas |
|-------|------|-------|
| id | INT UNSIGNED AI PK | |
| form_id | INT UNSIGNED FK→forms | CASCADE |
| email | VARCHAR(190) | en minúsculas: la clave de la allowlist |
| created_at | TIMESTAMP | |

UNIQUE (form_id, email). F4, solo se usa en modo `allowlist`.

#### form_access_tokens
| Campo | Tipo | Notas |
|-------|------|-------|
| id | INT UNSIGNED AI PK | |
| form_id | INT UNSIGNED FK→forms | CASCADE |
| email | VARCHAR(190) | a quién se emitió |
| token_hash | CHAR(64) UNIQUE | sha256; el token en claro solo existe en el correo |
| expires_at | DATETIME | 30 días |
| revoked_at | DATETIME NULL | lo pone `removeEmail()` |
| ip_address | VARCHAR(45) NULL | |
| created_at | TIMESTAMP | |

UNIQUE (token_hash), índice (form_id, email). F4. **No lleva FK a
`form_access_emails`**: en modo `email` hay tokens sin fila en la allowlist, y la
revocación es explícita (transacción en `removeEmail()`). El `form_id` va en el
WHERE de `findValidToken()`: es la mitad de la autorización.

#### app_settings
| Campo | Tipo | Notas |
|-------|------|-------|
| key_name | VARCHAR(64) PK | p. ej. `registration_enabled` |
| value | TEXT NULL | lo que se guardó; la fila ausente deja mandar al `.env` |
| updated_at | TIMESTAMP | se actualiza sola |

Migración 0010. Clave-valor y **sin fila inicial**: el patrón es «la fila
guardada gana; si no hay fila, manda el `.env`», y sembrarla con el valor del
`.env` congelaría el estado de la instalación el día de la migración. La lee
`App\Core\AppSettings` (con `try/catch`: una instancia sin la migración
aplicada no puede caer en 500) a través de
`AuthController::registrationEnabled()`.

#### password_resets
| Campo | Tipo | Notas |
|-------|------|-------|
| id | INT UNSIGNED AI PK | |
| user_id | INT UNSIGNED FK→users | CASCADE |
| email | VARCHAR(190) | |
| token_hash | CHAR(64) | sha256, de un solo uso |
| expires_at | DATETIME | |
| used_at | DATETIME NULL | se quema con el cambio de contraseña, en la misma transacción |
| created_at | TIMESTAMP | |

#### role_permissions
| Campo | Tipo | Notas |
|-------|------|-------|
| role_id | INT UNSIGNED FK→roles | CASCADE |
| permission_id | INT UNSIGNED FK→permissions | CASCADE |

PK (role_id, permission_id). RBAC (migración 0005): qué roles llevan qué
permisos. La semántica de cada clave vive en `config/permissions.php`.

#### permissions
| Campo | Tipo | Notas |
|-------|------|-------|
| id | INT UNSIGNED AI PK | |
| key | VARCHAR(80) UNIQUE | `dashboard.view`, `forms.create`, … |
| description | VARCHAR(255) NULL | |
| created_at | TIMESTAMP | |

Catálogo. Las filas las siembra `database/seed.sql` (instalación limpia) y la
migración 0005/0006 (BD existente), las dos leyendo `config/permissions.php`.

#### role_groups
| Campo | Tipo | Notas |
|-------|------|-------|
| id | INT UNSIGNED AI PK | |
| name | VARCHAR(80) UNIQUE | |
| description | VARCHAR(255) NULL | |
| created_at | TIMESTAMP | |

#### role_group_roles
| Campo | Tipo | Notas |
|-------|------|-------|
| group_id | INT UNSIGNED FK→role_groups | CASCADE |
| role_id | INT UNSIGNED FK→roles | CASCADE |

PK (group_id, role_id). Un usuario hereda los permisos de su rol propio **+** los
de los roles de sus grupos **+** sus permisos directos.

#### role_group_users
| Campo | Tipo | Notas |
|-------|------|-------|
| group_id | INT UNSIGNED FK→role_groups | CASCADE |
| user_id | INT UNSIGNED FK→users | CASCADE |

PK (group_id, user_id).

#### user_permissions
| Campo | Tipo | Notas |
|-------|------|-------|
| user_id | INT UNSIGNED FK→users | CASCADE |
| permission_id | INT UNSIGNED FK→permissions | CASCADE |

PK (user_id, permission_id). RBAC (migración 0017): la cuarta fuente. Da **un**
permiso a **una** persona sin cambiarle el rol (que mueve todo su conjunto) ni
meterla en un grupo (que se lo da a todo el equipo). `User::setPermissions()`
reemplaza la lista completa; las claves fuera de `config/permissions.php` se
descartan en silencio. Se editan desde *Permisos extra*, en el menú `⋯` de su fila
en `/admin`.

#### rate_limits
| Campo | Tipo | Notas |
|-------|------|-------|
| id | BIGINT UNSIGNED AI PK | |
| scope | VARCHAR(30) | `login`, `fill`, `dash:create`, `form_access`, … |
| identifier | VARCHAR(64) | IP o email, según el scope |
| attempts | INT UNSIGNED | cuenta del intento |
| window_start | TIMESTAMP | ventana deslizante |
| created_at | TIMESTAMP | |

UNIQUE (scope, identifier) + índice (identifier, window_start). El número de la
ventana es «n intentos por `window_start`» (no por intervalo deslizante, para
que cada petición no tenga que calcular su ventana).

#### security_log
| Campo | Tipo | Notas |
|-------|------|-------|
| id | BIGINT UNSIGNED AI PK | |
| event | VARCHAR(60) | `auth.login_ok`, `csrf.invalid`, `webhook.private_host`, … |
| detail | VARCHAR(500) NULL | |
| ip | VARCHAR(45) NULL | |
| user_id | INT UNSIGNED FK→users NULL | ON DELETE SET NULL |
| created_at | TIMESTAMP | |

Auditoría que **nunca** rompe el request (`SecurityLog::record()` con
try/catch). `user_id` NULL si `Auth::id()` no resuelve.

#### cache
| Campo | Tipo | Notas |
|-------|------|-------|
| cache_key | VARCHAR(190) PK | `dash:…` (dashboard) |
| value | MEDIUMTEXT | |
| expires_at | TIMESTAMP | evaluado en UTC |
| created_at | TIMESTAMP | |

#### migrations
| Campo | Tipo | Notas |
|-------|------|-------|
| id | INT UNSIGNED AI PK | |
| name | VARCHAR(190) UNIQUE | nombre del fichero |
| applied_at | TIMESTAMP | |

Registro de `database/migrate.php`. **Nunca borres filas de aquí** para
re-sembrar: una migración nueva es la única forma de re-ejecutar un seed.

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
| Admin | `/admin` | Gestion general (usuarios, grupos, roles, log, formularios) |
| Ajustes de la instancia | `/admin/settings` | Registro publico y politica de revision |

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
- Suite: `tests/unit/*.php` — **765 tests en verde** en 76 archivos (Core, Models, Controllers parcial, integración sobre BD real con datos temporales `@test.local`, smoke de TODAS las rutas con el Router real en `ControllerActionsTest`, y `ViewStructureTest` sobre las 53 vistas). Además hay un arnés de JS sin dependencias (`tests/js/async-actions.js`, 93 casos) que **ejecuta el `app.js` real** contra un DOM mínimo: node es opcional y sin él el caso avisa y pasa. El detalle de fases vive en `docs/roadmap-encuesta.md`.

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
- ✅ RBAC (2026-09-28, fase 6; permisos directos 2026-10-13): catálogo de permisos en `config/permissions.php` + matriz en `role_permissions`; `Auth::can()` como única puerta de negocio y permisos efectivos = rol propio + roles de sus grupos (`role_groups*`) + permisos directos por usuario (`user_permissions`, migración 0017, editables desde *Permisos extra*, en el menú `⋯` de su fila en `/admin`); `App\Core\Access` para el alcance del dueño (`*_own` && dueño || `forms.manage_all`); middleware `admin` = `Auth::can('admin.access')`
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
- Listado de formularios paginado EN EL SERVIDOR (2026-10-05, M2): `Form::page()` (12 por página; 20 en `/admin/forms`) con búsqueda, filtro de estado y orden en la URL (`/dashboard?q=…&estado=…&orden=…&page=…`); el conteo de respuestas va sobre los ids de la página y las tarjetas de arriba son el alcance entero sin filtros (`Form::counts()`, cacheado). El paginador de cliente de `app.js` se fue con la caché: ya no hay nada que ocultar
- Caché del dashboard (2026-09-28, fase 6; ajustada el 2026-10-05): solo las CUENTAS, `Cache::remember('dash:stats:…', 60, ...)` con TTL e invalidación por prefijo `dash:%` en cada mutación de forms/submissions; la pérdida de caché nunca rompe el request
- Tabla de analítica paginada EN EL SERVIDOR (2026-09-28): `Submission::byFormFilteredPaginated` + `GET /forms/{id}/analytics/rows` (15/página, lazy-load por fetch); los agregados siguen globales
- Revisión/aprobación (2026-09-29, F3; política de instancia 2026-10-13): `forms.status` gana `in_review` + `review_requested_at`/`reviewed_at`/`reviewed_by` (migración 0008); permiso `forms.review` y controlador aparte `ReviewController` con las tres transiciones. La exigencia ya no es opt-in por formulario (`settings.review.enabled`): la decide un administrador con `app_settings.review_required`/`review_notify_email` (interruptores en `/admin/settings`, migración 0016, con `App\Services\ReviewPolicy` como única puerta) y exime a los dueños con `forms.publish_without_review`. El estado solo lo mueven esas acciones — el `status` del payload del builder no publica, y editar un formulario aprobado lo devuelve a la cola
- `defer` en scripts no críticos (dashboard.js, sortable+builder, chart+analytics), preservando el orden de dependencias

**Despliegue (uploads):**
- `php.ini`: `upload_max_filesize` y `post_max_size` deben admitir el tamaño máximo anunciado (por defecto la app anuncia 10 MB, pero el servidor lo recorta al valor real de PHP)
- nginx: `client_max_body_size` mayor que `post_max_size` (si no, nginx rechaza el POST antes de PHP)
- El usuario del web server necesita permiso de escritura en `storage/uploads/`; ese directorio **no** debe servirse por el web server

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
| — | Loteo de `withAnswersByIds()` en tandas de 5000 | ✅ (2026-09-28) |
| — | Contrato horario: `SET time_zone='+00:00'` en la sesión + UTC en PHP | ✅ (2026-09-28) |
| — | Fases de producto F0–F5 (avance automático, acceso por email, revisión, PII, contraseña) | ✅ (2026-09-29, ver `docs/evaluacion-propuestas.md`) |
| — | Seguridad/quality (fase 4): smoke de todas las rutas, allowlist de redirect (F5.1), editor pendiente de RBAC (F5.2), límite dashboard por usuario (F5.3), thumbs, scale, limpieza de código muerto y assets | ✅ (2026-09-28) |
| — | RBAC completo (fase 6, 2026-09-28): catálogo de permisos, matriz de roles editable, grupos de roles, `Auth::can()`/`Access` como puertas, middleware `admin` por permiso | ✅ (2026-09-28) |
| — | Log de eventos de seguridad (fase 6, 2026-09-28): `security_log` + `/admin/security-log` + hooks en auth/CSRF/rate-limit/webhook/uploads/admin | ✅ (2026-09-28) |
| — | Rendimiento (fase 6, 2026-09-28): caché del dashboard (`Cache::remember`, invalidación por prefijo) + tabla de analítica paginada EN EL SERVIDOR (`/analytics/rows`) + `defer` en scripts no críticos | ✅ (2026-09-28) |
| — | F0.4-uno…cuatro (2026-10-02): panel y `/admin` sin recarga (`FormPanel`, bimodal en `Response`), diálogo de confirmación único, allowlist por fetch y auto-envío de rol/grupos | ✅ (2026-10-02, ver `docs/decisiones.md`) |

## Proximos Pasos

1. **Verificación manual en navegador** de los bloques R, S, T y U de
   `docs/pruebas-funcionales.md` (312 comprobaciones). Es lo único que la suite no
   puede ver: maquetación, foco con teclado y flujo en pantalla. Con node
   opcional, el arnés cubre el comportamiento del envío, no el pixel.
2. Lazy loading del builder JS (cargar `builder.js`/`sortable` solo al entrar al builder)
3. Re-evaluar con el orquestador
