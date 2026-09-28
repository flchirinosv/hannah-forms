# Levantamiento de Información - VanillaForms

**Fecha:** 2026-09-02
**Proyecto:** VanillaForms
**Tipo:** Mantenimiento / Mejora
**Estado:** Análisis completo

---

## 1. Datos del Proyecto

| Campo | Valor |
|-------|-------|
| **Nombre** | VanillaForms |
| **Descripción** | Sistema de formularios web auto-hospedado con constructor visual, analíticas y gestión de usuarios |
| **Tipo** | Mantenimiento (proyecto existente) |
| **Prioridad** | Media |
| **URL base** | http://127.0.0.1:8080 |

---

## 2. Análisis del Código Actual

### 2.1 Arquitectura

```
vanilla-forms/
├── app/
│   ├── Controllers/    (7 controladores)
│   ├── Core/           (8 clases base)
│   ├── Models/         (6 modelos)
│   └── Middleware/
├── config/             (4 archivos de configuración)
├── database/           (schema + seed)
├── public/             (assets estáticos)
└── views/              (templates PHP)
```

**Patrón:** MVC personalizado sin framework (vanilla PHP)

### 2.2 Stack Tecnológico Actual

| Componente | Tecnología |
|------------|------------|
| **Backend** | PHP 8.x (vanilla, sin framework) |
| **Base de datos** | MariaDB/MySQL |
| **Frontend** | HTML, CSS (Bootstrap 5), JavaScript vanilla |
| **Servidor** | PHP built-in server (development) |

### 2.3 Base de Datos

**6 tablas principales:**
- `roles` - Roles de usuario (admin, editor, viewer)
- `users` - Usuarios del sistema
- `forms` - Formularios creados (draft/published/closed)
- `fields` - Campos de cada formulario
- `submissions` - Respuestas enviadas
- `answers` - Valores de cada respuesta
- `rate_limits` - Control de intentos (rate limiting / anti fuerza bruta)
- `migrations` - Registro de migraciones aplicadas (`database/migrate.php`)

**Relaciones:**
```
roles ← users → forms → fields
                  ↓
              submissions → answers
```

### 2.4 Funcionalidades Implementadas

| Módulo | Estado | Descripción |
|--------|--------|-------------|
| **Auth** | ✅ | Login, registro, sesiones, roles |
| **Dashboard** | ✅ | Lista de formularios del usuario |
| **Builder** | ✅ | Constructor visual de campos |
| **Fill** | ✅ | Formulario público para respuestas |
| **Analytics** | ✅ | Gráficos y exportación CSV |
| **Admin** | ✅ | Gestión de usuarios y formularios |

### 2.5 Rutas API

| Método | Ruta | Auth | Descripción |
|--------|------|------|-------------|
| GET/POST | `/login`, `/register` | guest | Autenticación |
| GET | `/dashboard` | auth | Panel principal |
| POST | `/forms` | auth | Crear formulario |
| GET | `/forms/{id}` | auth | Ver formulario |
| POST | `/forms/{id}/update` | auth | Actualizar |
| POST | `/forms/{id}/delete` | auth | Eliminar |
| POST | `/forms/{id}/publish` | auth | Publicar |
| POST | `/forms/{id}/close` | auth | Cerrar |
| GET | `/forms/{id}/builder` | auth | Editor visual |
| POST | `/forms/{id}/builder/save` | auth | Guardar estructura |
| GET | `/f/{slug}` | público | Formulario público |
| POST | `/f/{slug}` | csrf | Enviar respuesta |
| GET | `/forms/{id}/analytics` | auth | Analíticas |
| GET | `/forms/{id}/export.csv` | auth | Exportar CSV |
| GET | `/admin` | admin | Panel admin |

### 2.6 Seguridad Implementada

- ✅ CSRF tokens en formularios
- ✅ Password hashing (bcrypt via `password_hash`)
- ✅ Sesiones con configuración segura
- ✅ Middleware de autenticación y autorización
- ✅ Prepared statements (previene SQL injection)
- ✅ Credenciales migradas a `.env` (cargador propio `App\Core\Env`, sin Composer)
- ✅ CSRF del builder corregido (C1): verificación del header `X-CSRF-Token` en `Csrf::verifyHeader()`
- ✅ Formularios anidados eliminados (C2): reestructurado `dashboard/forms/show.php` con `form` attribute
- ✅ Errores internos ya no se exponen (C3): `error_log()` + mensaje genérico en BuilderController
- ✅ Tipos de campo validados con whitelist (C4) en BuilderController
- ✅ Rate limiting (A9): clase `RateLimiter` con tabla `rate_limits`; 5 intentos/IP/5min en login y 10 envíos/IP/form/10min en submit
- ✅ SQL de Analytics migrado a Modelos (A8): `Submission` (byFormFiltered, dailyStats, withAnswersByIds) y `Answer` (countByFieldInForm, countsCheckboxInForm, numberStatsInForm, nonEmptyInForm)
- ✅ Problema N+1 resuelto (A13): `withAnswersByIds()` carga submissions y answers en 2 queries
- ✅ Índices de dashboard (A12): `idx_forms_user` y `idx_forms_user_status` en `forms(user_id)`
- ✅ Secure cookie automática (M1): `Session::start()` fuerza `secure=true` si `HTTPS` está activo
- ✅ Sanitización sin `strip_tags` (M2): `Request::clean()` usa solo `trim()`
- ✅ Validación interna en modelos (M11): `Form::create()` y `Submission::create()` con defensas básicas
- ✅ Seeds idempotentes (M12): `seed.sql` usa `ON DUPLICATE KEY UPDATE`
- ✅ JSON seguro en analytics (M16): `json_encode(..., JSON_HEX_TAG)` previene inyección en `<script>`

---

## 3. Recomendaciones Tecnológicas

### 3.1 Stack Propuesto (Mantenimiento)

| Componente | Recomendación | Justificación |
|------------|---------------|---------------|
| **Backend** | PHP 8.2+ | Ya en uso, mantener compatibilidad |
| **Framework** | Mantener vanilla | Proyecto pequeño, no necesita overhead |
| **DB** | MariaDB 10.x | Ya configurado, funciona bien |
| **Frontend** | Bootstrap 5 + JS vanilla | Consistente con código actual |
| **Testing** | Runner propio (sin Composer/PHPUnit) | Mantener sin dependencias externas |
| **CSRF** | Implementación actual | Funcional |

### 3.2 Mejoras Sugeridas

| Área | Mejora | Prioridad |
|------|--------|-----------|
| **Seguridad** | Migrar config a variables de entorno (.env) | Alta |
| **Testing** | Agregar tests unitarios y de integración | Alta |
| **Documentación** | Generar API docs con OpenAPI | Media |
| **Frontend** | Agregar validación JS en cliente | Media |
| **Rendimiento** | Agregar caché de consultas frecuentes | Baja |
| **CI/CD** | Configurar pipeline básico | Media |

---

## 4. Cobertura Técnica (estado tras auditoría)

| Aspecto | Estado | Notas |
|---------|--------|-------|
| Frontend | ✅ | Bootstrap 5, responsive, builder/analytics JS |
| Backend | ✅ | MVC funcional, PDO prepared statements |
| Base de datos | ✅ | Schema relacional, 3NF, FK, índices dashboard + tabla `rate_limits` |
| Autenticación | ✅ | Rate limiting en login; verificación `is_active` en `Auth::user()` al cargar sesión |
| Autorización | ⚠️ | Middleware silencioso si se escribe mal (A3) |
| Seguridad | ✅ | CSRF builder, whitelist, rate limiting; restan mejoras medias |
| Testing | ⚠️ | 26 tests (Env, Csrf, RateLimiter, Models); faltan Router/Auth/Controllers |
| Documentación | ⚠️ | technical-doc, api-spec y audit-report generados |
| CI/CD | ❌ | No configurado |
| Monitoreo | ❌ | Sin logging |

**Ver detalles completos en `docs/audit-report.md` (veredicto general: 71/100).**

---

## 5. Artefactos Generados

- [x] `docs/levantamiento_informacion.md` - Este documento
- [x] `docs/recommendations.md` - Recomendaciones técnicas (APROBADAS)
- [x] `docs/technical-document.md` - Documento técnico
- [x] `docs/audit-report.md` - Reporte de auditoría (este ciclo)
- [x] `.opencode/` - Agent, skills, command, rule, specify
- [x] `tests/unit/EnvTest.php` - Suite inicial (7 tests)
- [x] `tests/unit/CsrfTest.php` - Tests de CSRF (8 tests)
- [x] `tests/unit/RateLimiterTest.php` - Tests de rate limiting (4 tests)
- [x] `tests/unit/SubmissionAnalyticsTest.php` - Tests de analytics en Modelos (7 tests)
- [x] `database/migrations/0001_create_rate_limits.php` + `database/migrate.php` - Sistema de migraciones (nota de despliegue)

---

## 6. Proximos Pasos (remediación priorizada)

- **[HECHO - URGENTE]** Corregir CSRF del builder (C1): `Csrf::verifyHeader()` + verificación en BuilderController
- **[HECHO - URGENTE]** Eliminar formularios anidados en `dashboard/forms/show.php` (C2)
- **[HECHO - URGENTE]** No exponer errores internos en `BuilderController.php` (C3)
- **[HECHO - URGENTE]** Validar tipos de campo con whitelist en builder (C4)
- **[HECHO - ALTA]** Migrar SQL de Analytics a Modelos (A8) — `Submission` + `Answer`
- **[HECHO - ALTA]** Rate limiting en login y submit (A9) — clase `RateLimiter` + tabla `rate_limits`
- **[HECHO - ALTA]** Índice `forms(user_id)` (A12) y resolver N+1 en analytics (A13)
- **[HECHO - ALTA]** Validar antes de crear submission en `FillController` (A10): doble fase (validación → persistencia en transacción)
- **[HECHO - DESPLIEGUE]** Sistema de migraciones `database/migrate.php` + `database/migrations/` (aplica `rate_limits` a BD existente sin perder datos)
- **[HECHO - MEDIA]** Verificar `is_active` en `Auth::user()` (A7): logout automático si el usuario fue desactivado/eliminado
- **[HECHO - MEDIA]** Ampliar suite de tests a Core (Router, Auth, Database) y Controllers (50 tests)
- **[HECHO - MEDIA]** Mejoras de interfaz y validaciones: tabla de respuestas en analytics, fechas (M9), checkbox whitelist (M8), JSON seguro (M3), redirect relativo (M4)

---

**Generado por:** Sistema de Levantamiento v1.1.0
**Versión:** 1.0.0
