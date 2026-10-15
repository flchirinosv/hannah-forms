# Plan — Rediseño de la consola de administración (`/admin`)

**Fecha:** 2026-10-14
**Estado:** **F0–F6 hechas y verdes**. Plan cerrado y, además, las rondas de
correcciones visuales posteriores (C1–C4 y C5–C7, abajo) aplicadas y verdes.
**Objetivo:** llevar el módulo `/admin` (Usuarios, Grupos de roles, Roles y
permisos, Log de seguridad, Formularios) a un objetivo de **usabilidad**: cada
acción, con el menor número de pasos posible. **No se toca la marca** (degradado
violeta y tipografía Inter del shell) ni el contrato de datos/backend: las rutas,
los controladores y las claves de i18n no cambian de nombre.

Este documento es el punto de restauración del contexto del plan. Cada fase se
cierra con la suite completa en verde (`php scripts/test.php`) antes de pasar a
la siguiente. El *por qué* de cada cambio va a `docs/decisiones.md`; el diseño
visual/interacción, a `docs/design.md` (§3.12 y §4.6).

## Referencias (elegidas)

- **Primaria:** Linear › Settings › Administration › Members — fila + menú `⋯`,
  búsqueda/filtros que viven en la URL, acciones en bloque, autoguardado.
- **Secundaria:** Vercel › Settings › Members — invitación compacta email + rol.
- **RBAC:** matriz de Supabase.
- **Descartado:** drawers, MFA/2FA, sesiones activas, API keys, tenants, diffs de
  log, chips decorativos, dark mode, skeletons.

## Decisiones ya tomadas (no volver a abrir)

| Decisión | Valor |
|---|---|
| Marca / tipografía | **No se tocan**. El problema era densidad y jerarquía, no el color |
| Filtrar | Se resuelve en **SQL** con el `LIMIT` ya aplicado, y viaja en la **URL** |
| Guardado sin recarga | Patrón **bimodal** `data-vf-async="slots"`: mismo markup, mismo controlador y **camino nativo sin JS** |
| Una acción primaria por pantalla | El alta vive en su tarjeta; lo secundario, en la fila de acciones de la cabecera |
| Una sola puerta por fila | Un menú `⋯` agrupa las acciones de la fila, no una sopa de controles |
| Ritmo | Fases con checkpoint: suite completa verde al cierre de cada una |

## Contratos blindados (lo que el rediseño no puede romper)

- **Camino sin JS** en toda acción (el `<form method="post">` sigue siendo la
  entrada principal; `onclick` nunca es la única).
- El `id` del hueco que repinta el fetch vive en un `<span>` que **envuelve** al
  `<form>`, nunca dentro: el repintado hace `innerHTML` y un formulario anidado lo
  descarta el parser.
- **`Auth::can()` es la única puerta de negocio**; nunca se gatea con `role_name`.
- El RBAC efectivo = rol propio ∪ roles de grupo ∪ permisos **directos** (solo
  suma). `User::hasPermission()`/`reviewers()` deben seguir coincidiendo con
  `Auth::permissions()`.
- **CSP `script-src 'self'`**: sin `<script>` inline ni `on*=` (lo vigila un test).
- **`Response::wantsJson()` mira solo `X-Requested-With`**: la misma ruta
  responde `{ok, message, html}` o el 302 de siempre.
- No se renombran claves de i18n ni ids que los tests atan.

## Fases

| Fase | Alcance | Estado |
|---|---|---|
| **F0** | Documentación y contrato (`design.md` §3.12/§4.6) | ✅ hecha |
| **F1** | Cabecera compartida + subnavegación | ✅ hecha |
| **F2** | Listado de usuarios: buscar/filtrar/paginar en el servidor | ✅ hecha |
| **F3** | Fila de usuarios: columnas = valor + menú `⋯` + tarjetas móviles | ✅ hecha |
| **F4** | Grupos de roles (`/admin/groups`) | ✅ hecha |
| **F5** | Roles y permisos (`/admin/roles`, la matriz) | ✅ hecha |
| **F6** | Log de seguridad y Formularios del panel | ✅ hecha |

---

### F0 — Documentación y contrato (hecha)

- `docs/design.md` **§3.12 «Consola de administración»** y **§4.6 «Consola de
  administración»** (filtros en URL/SQL, una acción primaria, una puerta por
  fila `⋯`, slots que no mienten, estado vacío propio, tarjetas apiladas en
  móvil `.app-admin-table`). §3.11 actualizada.

### F1 — Cabecera y navegación (hecha)

- `views/admin/_page_head.php`: cabecera única (sub-navegación `admin/_nav` +
  título/subtítulo + `.app-head-actions`). Variables: `$active/$icon/$title/
  $subtitle/$actions`.
- Las cinco cabeceras admin refactorizadas para usar el partial.
- `app.css`: la sub-navegación admin **conserva sus rótulos** en móvil (una línea
  con `overflow-x: auto` en vez de partirse o quedarse en iconos).
- Tests: `ContentButtonsMobileTest::testLaSubNavegacionDeAdminConservaSusRotulos`,
  `FormFichaLayoutTest` (asserta el partial).

### F2 — Listado de usuarios en el servidor (hecha)

- `app/Models/User.php`: `POR_PAGINA = 25`, `page(array $filtros, int $page,
  ?int $porPagina)` → `['items','total','page','pages','per_page']` (recorta
  `$page` a `$pages`) y `whereListado()` (buscador `q` escapado + `rol` +
  `estado`).
- `AdminController::index()` lee `q/rol/estado/page` y usa `User::page()`.
- `views/admin/users.php`: barra de filtros GET en la URL (`js-auto-submit` en
  los desplegables, `noscript` para aplicar), estados vacíos diferenciados
  («Sin resultados» vs «Todavía no hay usuarios») y paginador.
- `views/dashboard/_pagination.php`: parámetros opcionales `$sujeto`/`$aria`.
- Tests en `AdminRbacTest` (buscador/estado/rol en SQL, `User::page`).

### F3 — Fila de usuarios y móvil (hecha)

- Columnas = **valor** (insignias de rol, grupos, permisos extra, estado); la
  edición se agrupa en un **único menú `⋯`** por fila (`.app-row-menu`,
  `data-bs-auto-close="outside"`) con los cuatro formularios: rol
  (`js-auto-submit`), grupos, permisos extra y activar/desactivar.
- `views/admin/_user_role.php` (nuevo); `AdminController::updateRole()` devuelve
  el hueco `vfUserRole<uid>` (la insignia sí depende del rol).
- `_user_toggle.php` → botón de ancho completo y con rótulo.
- `app.css`: `.app-row-menu-btn`, `.app-row-menu` (alto topado a
  `min(60vh, 24rem)` porque vive dentro de `.table-responsive`, que recorta en
  vertical) y `.app-admin-table` (tarjetas apiladas ≤991.98px con `data-label`).
- Tests: `AdminRbacTest` (menú único, rótulos+CSS, repintado de la insignia),
  `AsyncFormActionsTest` (el rol ahora sí devuelve hueco), `ContentButtonsMobileTest`.

---

### F4 — Grupos de roles (`/admin/groups`) (hecha)

- `views/admin/_group_summary.php` (nuevo): **cabecera-resumen** de la tarjeta
  (nombre, descripción, insignias «N roles»/«N miembros», «lo que otorga» y el
  botón de borrar). El GET lo pinta en línea y `groupUpdate()` devuelve el MISMO
  partial como hueco `vfGroupSummary<gid>`: no pueden divergir. El botón de
  borrar va dentro para que un renombrado sin recarga actualice el nombre de la
  confirmación.
- `AdminController::groupUpdate()`/`groupDelete()` **bimodales**
  (`Response::wantsJson()`): éxito → `{ok, message, html:{vfGroupSummary<gid>}}`
  y `{ok, message, count}` respectivamente; errores 403/419/404/422. El borrado
  reusa el contrato del borrado de respuestas: el `count` es del servidor y el JS
  recarga cuando queda en cero (la lista es global).
- `views/admin/groups.php`: reescrita la tarjeta (resumen arriba + form editable
  con `data-vf-async="slots"`), eje nombre/descripción a un lado y roles/miembros
  al otro, y **buscador de miembros**.
- `public/assets/js/app.js`: `vfFilterList(input)` — busca la lista por
  `input.closest('[data-vf-list]')`, esconde `.app-rbac-item` que no casan y
  muestra/oculta `.app-filter-empty`, **sin tocar los checkboxes** (la
  pertenencia es el POST completo: esconder a alguien no puede sacarlo). Se
  engancha en `document.querySelectorAll('[data-vf-filter]')`.
- Tests: `AdminRbacTest` (el resumen repintado lleva los recuentos y el efecto;
  el `count` del borrado es el de la instalación; la lista de miembros tiene
  buscador+nota) y `tests/js/async-actions.js` (9 casos de `vfFilterList`, 106
  arranques). Verificado en navegador (sin recarga al guardar, filtro, borrado
  con confirmación, sin overflow en 1400/1024/390).

**Contratos que toca:** `AdminController::groupUpdate()` (devuelve huecos),
`groupDelete()`; vista `views/admin/groups.php`; `User::all()` para las casillas
(intencionado, no paginado).

### F5 — Roles y permisos (`/admin/roles`, la matriz) (hecha)

**Estado anterior:** matriz roles × permisos agrupada por prefijo con «Marcar
toda la sección» (`data-vf-bulk-all`), cabecera y secciones no pegajosas dentro de
un `.table-responsive`, guardado con recarga y contador `n/total` congelado en el
de la última carga.

**Hecho:**

- **Cabecera y rótulos de sección pegajosos**: contenedor propio
  `.app-rbac-matrix-scroll` (`max-height` + `overflow:auto`) en vez de
  `.table-responsive` —su `overflow` computa el eje vertical a `auto` y rompe el
  anclaje—. `thead th` a `top:0` y `.app-rbac-section th` a
  `top: var(--vf-rbac-head)`, con la altura real del encabezado medida por JS (en
  móvil depende del ancho).
- **Contador `n/total` en vivo**: `data-vf-count` por rol y `data-vf-role-id` por
  casilla; `syncRoleCounts()` recalcula al marcar/desmarcar y al usar
  «Marcar toda la sección».
- **Guardado sin recarga**: `data-vf-async="slots"` en el formulario y rama JSON
  en `updateRoleMatrix()` (403/419/422 + `{ok:true,message}`), con giro en el
  botón. Conserva la posición de scroll al guardar. Sin JS, el POST nativo sigue.
- **Alta/baja siguen nativas** (recarga): crear o borrar un rol añade/quita una
  columna entera y el repintado por slots dejaría la matriz obsoleta.

**Contratos que toca:** `AdminController::updateRoleMatrix()`; vista
`views/admin/roles.php`; helper JS `syncRoleCounts()`/`initRoleMatrix()`;
`tests/unit/AdminRbacTest.php` (guardado JSON + markup) y el arnés
`tests/js/async-actions.js` (contador).

### F6 — Log de seguridad y Formularios del panel (hecha)

**Estado anterior:** el log solo filtraba por `event` con un botón de submit y
traía su **propio** paginador; `/admin/forms` pintaba tarjetas y paginaba con
`qs=""`, sin filtros ni orden.

**Hecho — Log (`/admin/security-log`):**

- Filtro por evento con `js-auto-submit` (se envía al cambiar, arrastrando lo
  escrito) + **buscador** por detalle/IP/usuario, los dos en la URL.
  `SecurityLog::recent()` recibe `$q` (al final de la firma, para no romper las
  llamadas posicionales), con tres marcadores `:q1/:q2/:q3` —no `:q` repetido,
  HY093— y el `%`/`_` escapados con `addcslashes`. El `JOIN` con `users` va
  también en el `COUNT` porque el buscador mira `u.name`.
- Paginador compartido (`dashboard/_pagination.php`) con `$sujeto`/`$aria` y los
  filtros preservados en los enlaces.
- Estado vacío propio que distingue «sin resultados» (con enlace a limpiar) de
  «todavía no hay eventos».

**Hecho — Formularios (`/admin/forms`):**

- `AdminController::allForms()` lee `q`/`estado`/`orden` de la URL (validados
  contra las listas blancas de `Form`) y llama a `Form::page(0, true, $filtros,
  …, 20)`: mismo listado que el dashboard, con su paginación server-side.
- La vista gana la barra de búsqueda + botones de estado (enlaces) + selector de
  orden (`js-auto-submit`) y un estado vacío que distingue «no hay formularios»
  de «sin resultados».
- Cada tarjeta añade el enlace **Configurar** (`/forms/{id}`), además de
  Analítica y Ver público.

**Contratos que toca:** `SecurityLog::recent()` (nuevo `$q`);
`AdminController::securityLog()`/`allForms()`; vistas
`views/admin/security_log.php` y `views/admin/forms.php`. Lo atan
`SecurityLogTest`, `FormListPaginationTest` y `AdminRbacTest`.

---

### Correcciones visuales posteriores (C1–C4) (hechas)

Ronda corta tras revisar el resultado en el navegador. No reabre el alcance de
F0–F6: son cuatro ajustes de usabilidad y coherencia, con el mismo contrato.

| # | Alcance | Estado |
|---|---|---|
| **C1** | `/admin/security-log`: barra de filtros con el mismo estilo que `/admin/forms` | ✅ hecha |
| **C2** | Ajustes de la instancia a **pantalla propia** `/admin/settings` (pestaña nueva) | ✅ hecha |
| **C3** | `/admin/groups`: alta en modal, tarjeta plegable y «lo que otorga» al cuerpo | ✅ hecha |
| **C4** | `/admin/users`: alta en modal y acciones de fila en un modal compartido | ✅ hecha |

**C1 — Log con la barra de filtros de Formularios.** `views/admin/security_log.php`
pasa a llevar la búsqueda + filtro de evento con el mismo marcado que
`forms.php`; `app.css` gana `.form-select.app-dash-sort-wide { max-width: 320px; }`
para que el desplegable no ocupe toda la fila.

**C2 — Ajustes de la instancia a `/admin/settings`.** Registro público y política
de revisión dejan de apilarse encima de la tabla de usuarios (competían en altura
sin ser de esa pantalla). Vista nueva `views/admin/settings.php` (dos tarjetas) y
la sexta pestaña de `admin/_nav.php` (`admin.access`, no `admin.users`: ninguno
de los dos toca la cuenta de nadie), ruta
`GET /admin/settings → AdminController::settings`, y los tres
`redirect('/admin')` de `updateReviewPolicy()` pasan a `/admin/settings`.
`AdminController::index()` deja de pasar `registrationEnabled`/`reviewRequired`/
`reviewNotifyEmail`/`canManageInstance`.

**C3 — Grupos: alta en modal y tarjeta plegable.** `views/admin/groups.php`
reescrita: el alta vive en `#newGroupModal` (servida en línea en
`#newGroupFormMount` con `data-vf-modal-move` y movida al diálogo por JS, para
no duplicar marcado ni depender de `<noscript>`), cada tarjeta es un grupo
plegable (`data-vf-group-key="admin-grupos"`, paneles `grp-grupo-<gid>` en
`collapse show`) y «lo que otorga este grupo» se muda al cuerpo de la tarjeta
en un partial nuevo `views/admin/_group_effect.php`. `groupUpdate()` devuelve
**dos** huecos: `vfGroupSummary<gid>` y `vfGroupEffect<gid>`.

**C4 — Usuarios: alta en modal y acciones en un modal compartido.** El alta deja
el `<fieldset>` plegable y se sirve en línea en `#newUserFormMount`, movida a
`#newUserModal` por JS. El `⋯` de cada fila deja de ser un `dropdown` de
Bootstrap: es un disparador `data-vf-user="<uid>"` y el panel de la fila (con
sus cuatro formularios y sus huecos) lo **mueve** `initTablaAcciones()` al
`#userActionsModal` al abrir y lo devuelve a su fila al cerrar. Un solo modal,
no uno por usuario; el mismo nodo sirve para las dos superficies (con JS y sin
JS). CSS: `.modal-body .app-row-menu` suelta el tope de alto y el ancho mínimo
(dentro del modal scrollea el diálogo, no el panel).

**Contratos que toca C1–C4:** `views/admin/settings.php` y
`views/admin/_group_effect.php` (nuevas); `views/admin/users.php`,
`groups.php`, `security_log.php`, `_nav.php`; `AdminController::settings()`
(nueva), `index()`, `updateReviewPolicy()`, `groupUpdate()`;
`public/assets/js/dashboard.js` (IIFE `window.vfInitAdminModals`:
`moverFormulariosInline()` + `initTablaAcciones()`); `public/assets/css/app.css`.
Lo atan `AdminSettingsPageTest` (nuevo), `GroupCollapseContractTest` (reescrito),
`AdminRbacTest`, `RegistrationSwitchTest`, `ReviewPolicySwitchTest`,
`ContentButtonsMobileTest`, `FormFichaLayoutTest`, `ViewStructureTest`.

---

### Segunda revisión en navegador (C5–C7) (hechas)

Otra ronda corta al mirar la consola ya rediseñada en el navegador. Tampoco
reabre el alcance de F0–F6: son ajustes de retícula, densidad y contraste, con el
mismo contrato.

| # | Alcance | Estado |
|---|---|---|
| **C5** | Retícula de las altas y del modal de acciones, y tarjeta de grupo | ✅ hecha |
| **C6** | Alta de usuario en **una** columna, acciones de fila en **tres**, y usuario de la sidebar colapsable a todo el ancho | ✅ hecha |
| **C7** | Contraste del menú de usuario de la sidebar | ✅ hecha |

**C5 — Retícula.** `views/admin/users.php`: el alta se reparte en dos columnas y el
`#userActionsModal` en dos; `views/admin/groups.php` y `_group_effect.php`: la
tarjeta de grupo pone los roles arriba a la derecha y «lo que otorga» abajo a la
izquierda; `app.css` gana `.app-rbac-item-line` (cada ítem del RBAC en una línea).
Commit `f210153`. (C6 rehace las columnas de `users.php`: C5 fue el primer
intento.)

**C6 — Una columna y tres columnas.** El alta de usuario queda en **una sola
columna** (`col-12` ×5) y su modal baja a ancho por defecto; `#userActionsModal`
sube a `modal-xl` y se reparte en **tres** columnas (permisos efectivos / rol y
grupos / permisos extra), de modo que la del medio explica a las otras dos. El
bloque de usuario de la sidebar se convierte en un colapsable a todo el ancho
(`#sidebarUserMenu`, botón `data-bs-toggle="collapse"`, se abre **hacia arriba**,
con `aria-expanded` que rota la flecha). Commit `77ceccf`.

**C7 — Contraste de la sidebar.** El «Cerrar sesión» en `text-danger` sobre el
degradado medía ~1.0 de contraste (invisible); el panel del menú pasa a llevar un
velo `rgba(0,0,0,.25)` y el enlace destructivo a `#fecaca`, con el correo a blanco
al 85 %. Commit `4ea7867`; lo ata
`SidebarLayoutTest::testElCierreDeSesionTieneContrasteSobreElDegradado`.

**Contratos que toca C5–C7:** `views/admin/users.php`, `groups.php`,
`_group_effect.php`, `views/layouts/main.php`; `public/assets/css/app.css`;
`public/assets/js/dashboard.js`; test `SidebarLayoutTest` (método nuevo).

---

## Verificación

- Suite completa: `php scripts/test.php` → **1121 tests** (a fecha de C7),
  `Fallidos: 0`, `Errores: 0` (~6 min). Comprobación en navegador para lo que la
  suite no ve (maquetación, teclado, menús): Playwright/Chromium sobre
  `http://127.0.0.1:10001`.
- Al añadir una vista, un controlador o un test: actualizar los contadores de
  `AGENTS.md` en el mismo commit (`find views -name '*.php' | wc -l`,
  `ls app/Controllers/*.php | wc -l`, y el número de métodos de test).

## Archivos clave

- `views/admin/`: `users.php`, `_page_head.php`, `_nav.php`, `_user_role.php`,
  `_user_groups.php`, `_user_perms.php`, `_user_status.php`, `_user_toggle.php`,
  `groups.php`, `_group_summary.php`, `_group_effect.php`, `roles.php`,
  `security_log.php`, `forms.php`, `settings.php`.
- `app/Controllers/AdminController.php`; `app/Models/User.php`, `Form.php`,
  `Role.php`, `RoleGroup.php`, `SecurityLog.php`.
- `public/assets/css/app.css` (`.app-admin-table`, `.app-row-menu*`,
  `.app-rbac-*`); `public/assets/js/dashboard.js`, `app.js` (`vfHandleSubmit`).
- Tests: `tests/unit/AdminRbacTest.php`, `AsyncFormActionsTest.php`,
  `AsyncActionsJsTest.php`, `ContentButtonsMobileTest.php`, `FormFichaLayoutTest.php`,
  `SidebarLayoutTest.php`, `ViewStructureTest.php`, `ControllerActionsTest.php`.
