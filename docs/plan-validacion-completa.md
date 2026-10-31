# Plan de validación VanillaForms - Contexto persistente

**Actualizado:** 2026-10-27

## Objetivo
Validar que cada ruta, vista, función y/o característica se comporta según lo
esperado: desde la instalación, login, analítica y administración, cubriendo flujo
normal, permisos y casos límite.

## Alcance
- Instalación: `public/install.php`, `App\Services\InstallGuard`, `App\Services\Installer`, `App\Core\EnvWriter`, `views/install/*`
- Autenticación/recuperación: `AuthController`, `AccountController`, `Auth`, middleware (`auth`, `guest`, `admin`), sesiones, rate limits
- Dashboard/Formularios: `DashboardController`, `FormController`, CRUD, revisión (F3), acceso (F4/roster), compartir/QR
- Constructor: `BuilderController`, borrador/autosave, validación estructura, revisión
- Llenado público: `FillController`, modos acceso, rate limits fill, PII, adjuntos
- Participantes/roster: `ParticipantController`, importación 2 pasos, invitaciones/recordatorios, puerta
- Analítica: `AnalyticsController`, agregados, filtros, vistas, export CSV, tasa respuesta (roster)
- Respuestas/adjuntos: `ResponsesController`, `AttachmentController`, PII/export
- Administración: `AdminController`, usuarios/grupos/roles/permisos, ajustes instancia (marca/correo/identidad/turnstile/uploads/session/network/webhook), revisión, seguridad
- Infra: router, middleware, CSRF, permisos, migraciones

## Metodología
1. Mapa: rutas (`config/routes.php`), middlewares, controladores, vistas clave
2. Instalación
3. Auth
4. Dashboard + formularios
5. Builder
6. Fill público
7. Roster
8. Analítica
9. Respuestas/adjuntos
10. Admin
11. Contratos/tests relacionados
12. Checklist de errores probables + correcciones

## Estado
- [x] Fase 0: Guardar plan
- [x] Fase 1: Mapa de rutas/middlewares/controladores
- [x] Fase 2: Instalación (código + tests; falta prueba manual del asistente)
- [x] Fase 3: Autenticación/recuperación
- [x] Fase 4: Dashboard + Formularios + Revisión
- [x] Fase 5: Constructor (Builder)
- [x] Fase 6: Llenado público (Fill)
- [x] Fase 7: Participantes/Roster
- [x] Fase 8: Analítica
- [x] Fase 9: Respuestas/Adjuntos
- [x] Fase 10: Administración (con correcciones; ver hallazgos)
- [x] Fase 11: Tests focalizados + fixes
- [x] Fase 12a: Suite completa y `php -l`
- [ ] Fase 12b: Checklist manual en navegador (instalador, builder, fill, roster, analítica, admin)

## Evidencia por fase

### Fase 1 — Mapa
- `config/routes.php`: **97 rutas** (**35 GET / 62 POST**).
- Comprobación por reflexión: **0 métodos de controlador inválidos**.
- Middlewares observados: `auth`, `guest`, `admin`, `csrf`, `csrf-header`,
  `post-max`; `editor` sigue registrado y sin rutas, como documenta AGENTS.
- Fuente de rutas: `config/routes.php`. `docs/technical-document.md` §4 y
  `docs/api-spec.md` están desactualizados y no se usaron como fuente.

### Fase 2 — Instalación
- Revisados `public/install.php`, `InstallGuard`, `Installer`, `EnvWriter` y
  `views/install/*`.
- Tests existentes: `InstallGuardTest` (marcador, permisos `0600`, requisitos) e
  `InstallerTest` (base descartable, `sqlSinUse`, alta del admin).
- Pendiente manual: ejecutar el asistente real en navegador sobre una base nueva
  y comprobar el bloqueo posterior por `storage/installed.lock`.

### Fase 3 — Autenticación/recuperación
- Revisados login, registro, logout, recuperación de contraseña, cambio de
  contraseña, sesiones y rate limits.
- Corregido aviso de sesión en `Auth::logoutIdle()`: solo se toca la sesión si
  `session_status() === PHP_SESSION_ACTIVE`.
- Tests focalizados verdes: `AuthTest`, `AuthHardeningTest`, `PiiMaskingTest`.
- Invariantes comprobadas: `auth_at`/`password_changed_at`, token de reset
  hasheado y de un solo uso, respuesta uniforme de `/forgot-password`, registro
  cerrado por defecto.

### Fase 4 — Dashboard + Formularios + Revisión
- Revisados `DashboardController`, `FormController`, `ReviewController`,
  `Form::page()`, `FormPanel`, estados `draft/in_review/published/closed/approved`
  y permisos de revisión.
- Sin defecto funcional nuevo. Se mantienen las invariantes de AGENTS: aprobar no
  publica, editar un publicado lo devuelve a borrador y la revisión se exime por
  el dueño.

### Fase 5 — Constructor
- Revisados `BuilderController`, `BuilderDraftMapper`, `LogicEvaluator`,
  `views/builder/*`, `builder.js` y contratos de validaciones/flags.
- Sin defecto funcional nuevo. Se comprobaron: autosave/borrador, preview con
  `?draft=1`, `parent_id` NULL para “sin sección”, tipos estructurales y
  validación de estructura.

### Fase 6 — Llenado público
- Revisados `FillController`, `FormAccess`, `Access`, `views/fill/*`, `fill.js`,
  honeypot, Turnstile, rate limits, PII, adjuntos y gracias por token.
- Sin defecto funcional nuevo. Se mantiene la revalidación de sesión de acceso y
  el orden honeypot → Turnstile → rate limit → CSRF.

### Fase 7 — Participantes/Roster
- Revisados `ParticipantController`, `FormParticipant`, `RosterPanel`,
  `ParticipantImporter`, `RosterTemplate`, `RosterDoor`, importación, invitaciones,
  recordatorios y la pantalla `/forms/{id}/access`.
- Correcciones ya aplicadas y cubiertas: modal de edición alternado con el panel
  de fila, formulario de edición con error propio, `data-vf-participant` en el
  botón `⋯`, “Vaciar la lista” fuera del colapsable, lector/escritor XLSX y
  atributos desconocidos filtrados.
- Tests focalizados verdes: `AccessPageTest`, `AccessCardDesignTest`,
  `RosterPanelTest`, `ViewStructureTest`, `AsyncActionsJsTest`.

### Fase 8 — Analítica
- Revisados `AnalyticsController`, `FieldAggregator`, `views/analytics/*`,
  `analytics.js`, filtros por fecha/atributo, comparación por grupo, respuestas
  abiertas, vistas conmutables y CSV.
- Verificado que el literal del estado vacío no es un defecto: encaja con
  `AnalyticsLayoutTest`. Sin defecto funcional nuevo.

### Fase 9 — Respuestas/Adjuntos
- Revisados `ResponsesController`, `AttachmentController`, `Pii`, `Uploader`,
  export JSON/CSV, detalle, borrado y adjuntos.
- Corregido `ResponsesController::validarFecha()`: ahora usa
  `Submission::isValidDay()`, el mismo predicado que analítica y CSV. Antes
  `2026-02-30` se aceptaba y PHP la “corregía” a marzo.
- Test nuevo: `ResponsesTest::testElFiltroDeFechasRechazaUnDiaQueNoExiste`.

### Fase 10 — Administración
- Revisados usuarios, grupos, roles, matriz de permisos, permisos directos,
  ajustes de instancia (marca, correo, identidad, Turnstile, uploads, sesión,
  red, webhook), revisión y log de seguridad.
- Corregido `AdminController::groupUpdate()`: registra `admin.group_update`, no
  `admin.group_create`.
- Añadidos a `SecurityLog::knownEvents()` los diez eventos literales que faltaban:
  `access.link_issued_by_owner`, `access.link_sent`, `admin.user_create`,
  `authz.denied`, `form.publish`, `rate_limit.form_access`,
  `rate_limit.form_code`, `roster.code_rejected`, `roster.imported`,
  `roster.truncated`; más `admin.group_update`.
- Añadido `RoleGroup::findByName()` y rechazo de nombres duplicados en alta y
  edición (la colación de la columna coincide con el UNIQUE).
- Añadido `groupMembershipError()`: valida roles/usuarios antes de escribir la
  N:M; un POST manipulado ya no llega a las FK ni deja un 500.
- Tests nuevos: 4 en `AdminRbacTest` y 1 en `SecurityLogTest`.
- Revisado el guard de auto-degradación de `updateRole()`: se mantiene tal cual.
  No hay guard explícito de “último admin”; el permiso `admin.users` controla
  quién puede degradar y el auto-degradado está bloqueado. Queda como decisión
  consciente, no como olvido.

### Fase 11 — Tests focalizados + fixes
- `php -l` correcto en todos los archivos tocados.
- `AdminRbacTest`: **44 métodos**, todos verdes.
- `SecurityLogTest`: **7 métodos**, todos verdes.
- `ControllerActionsTest::testTodasLasRutasRespondenSinErroresNiEndpointsMudos`:
  verde con las 97 rutas.
- Tests nuevos de fecha y evento de grupo: verdes.
- **Suite completa: 1385 tests, 0 fallos, 0 errores** (741,9 s).
- **`php -l`: 324 archivos, 0 errores**.

### Fase 12 — Verificación final
- **Automatizada: completa.** Suite completa **1385 tests, 0 fallos, 0 errores**
  (741,9 s) y `php -l` sobre **324 archivos** sin errores.
- Baseline previo: 1379 tests. Los 6 nuevos son los añadidos en esta validación
  (4 de grupos, 1 de catálogo de eventos, 1 de fechas de respuestas).
- **Pendiente manual:** instalador real, recorridos de builder/fill/roster,
  analítica y consola `/admin` en navegador (teclado, modales, responsive).

## Hallazgos cerrados
1. Eventos de `SecurityLog` invisibles por catálogo incompleto.
2. `admin.group_update` registrado como `admin.group_create`.
3. Alta/renombrado de grupo con nombre duplicado → 500 por UNIQUE.
4. Rol/usuario inexistente en el POST de grupo → 500 por FK.
5. Filtro de fechas de respuestas aceptaba días inexistentes y no coincidía con
   analítica/CSV.
6. Aviso de sesión en `Auth::logoutIdle()`.
7. Modal de edición del roster anidado/alternado y formulario sin `data-vf-error`.
8. Varias correcciones previas de roster, importación XLSX, analítica y builder
   documentadas en `docs/decisiones.md`.

## Riesgos/decisiones pendientes
- **Último admin**: no se añadió un guard adicional. Si se quiere endurecer, la
  regla sería contar admins activos antes de degradar al último y cubrir el caso
  de un usuario no-admin con `admin.users`.
- **Instalador real**: validado por tests, no por ejecución manual en navegador.
- **Documentación histórica**: `docs/technical-document.md` §4 y
  `docs/api-spec.md` siguen desactualizados respecto a rutas; no se usaron como
  fuente de verdad.

## Comandos de referencia
- Suite completa: `php scripts/test.php`
- Un método: `php scripts/test.php --filter <nombreDelMetodo>`
- Una carpeta: `php scripts/test.php --path tests/unit`
- Smoke de rutas: `php scripts/test.php --filter testTodasLasRutasRespondenSinErroresNiEndpointsMudos`
- Sintaxis: `php -l <archivo>` (por tandas, para no agotar el timeout)
