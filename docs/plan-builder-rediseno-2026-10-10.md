# Plan — Rediseño del builder a estilo Google Forms

**Fecha:** 2026-10-10
**Estado:** en curso — este documento es el punto de restauración y la hoja de ruta.
Cada fase se cierra en su propio commit con la suite completa en verde
(`php scripts/test.php` → 1059 tests) antes de pasar a la siguiente.

**Objetivo:** migrar la interfaz del builder a un modelo de **columna central de
tarjetas** al estilo Google Forms: intuitivo, minimalista y moderno, tomando lo mejor
de los constructores evaluados. **No cambia nada** del contrato de datos ni del
backend: el payload del POST `saveStructure` (`_uid`, `parent_id`, snake_case) y las
rutas son intocables.

## Punto de restauración (F0, hecho)

| Recurso | Valor | Uso |
|---|---|---|
| Tag git | `pre-rediseno-builder` (HEAD `188ae46`) | Volver a este código: `git reset --hard pre-rediseno-builder` |
| Dump BD | `storage/backups/vanilla-forms-pre-rediseno-builder-2026-10-10.sql` (3 MB, 22 tablas) | Restaurar si una migración rompe datos: `mysql < <dump>` |
| `.gitignore` | `storage/backups/` añadido | El dump (datos reales) **nunca** entra al repo |
| Uploads | `storage/uploads/` no se toca | El rediseño no afecta adjuntos |

Receta de rollback (cualquier fase):
1. `git reset --hard pre-rediseno-builder` (o el commit de la fase anterior).
2. Si hubo migración de esquema: `mysql -u mariadb -p vanilla_forms < storage/backups/vanilla-forms-pre-rediseno-builder-2026-10-10.sql`.
3. `php scripts/test.php` y verificar que el contador vuelve a 1059 verde.

## Evaluación de constructores (resumen)

| Constructor | Modelo de UI | Qué se toma | Qué se descarta |
|---|---|---|---|
| **Google Forms** | Columna central de tarjetas; edición inline; agregar con `+` (barra y entre tarjetas); secciones como bloques; palette de tema | **El modelo central**: tarjeta por pregunta con tipo, obligatoria, duplicar/eliminar en la propia tarjeta | Su falta de ajustes profundos |
| **Typeform** | Árbol izquierdo + canvas central + panel derecho + preview en vivo | **Drawer lateral** de propiedades y **preview embebido** mientras se edita | La lógica difícil de encontrar (usability test) y los iconos sin rótulo |
| **Jotform** | Paleta izquierda fija + canvas + panel de propiedades con tabs | Pestañas contextuales en el cajón | La paleta permanente: **se oculta tras el botón `+`** (decisión) |
| **Microsoft Forms** | Lista de tarjetas + acciones per-pregunta | Acciones junto a la pregunta | Nada |
| **SurveyMonkey** | Navegación de estructura a la izquierda | — (los bloques de sección/página ya dan estructura) | Panel izquierdo extra |
| **Tally/Paperform** | Estilo documento/slash commands | Tensión de simplicidad (estados vacíos, menos ruido) | No encaja con tarjetas/NPS/matriz/ranking |

## Decisiones ya tomadas (no volver a abrir)

| Decisión | Valor |
|---|---|
| Espacio de trabajo | Trabajar en `master` (como siempre), con el tag + dump como red |
| Paleta de tipos | **Se oculta** la columna izquierda permanente → menú desde el botón `+` de la barra (`#addFieldModal` ya existe) |
| Ajustes avanzados del campo | **Drawer desde la derecha** (sustituye columna fija y `#fieldSettingsModal`), reubicando la **misma** instancia `#settingsForm` |
| Preview | **Toggle embebido** en el lienzo (reusando `previewBody()` y el markup del fill) + `#livePreviewModal` intacto |
| Ritmo | **Fases con checkpoint**: suite completa verde al cierre de cada una |
| Layout del lienzo | Columna central de máx ~860px; `#canvasFields` y `#emptyCanvas` |
| Tema | `#formSettingsModal` se rediseña como panel deslizante, **sin cambiar ids ni funciones** |

## Contratos blindados (lo que el rediseño no puede romper)

Fuente: `AGENTS.md` (§ Builder JS, Estructura de las vistas, Acciones sin recarga) y los
tests que los atan.

- **Contrato de datos**: `#builderApp` transporta todo por `data-*`; `buildPayload()` es
  la única frontera camelCase→snake_case (`parent_id`); `_uid` resuelto en el servidor.
  No se toca `BuilderController::saveStructure()` ni `config/routes.php`.
- **`ViewStructureTest`**: el HTML se lee como el parser del navegador; **ningún
  `.modal` dentro de otro elemento**. `#addFieldModal`, `#livePreviewModal` y
  `#formSettingsModal` siguen a nivel `<body>`.
- **`BuilderPreviewCssTest`**: toda clase `app-`/`vf-`/`nps-`/`star-`/`ranking-` que
  emita `builder.js` debe existir como selector en `app.css`; los ganchos de JS sin
  estilo se declaran explícitos en el test. Clases nuevas → selector en el mismo commit.
- **`BuilderValidationsContractTest`**: toda clave en `MANAGED_VALIDATION_KEYS` y todo
  flag de `TOP_LEVEL_FLAG_CONTROL` necesita control **y** listener; `wiredControls()`
  barre los listeners. El drawer conserva todos los `#field*` y `#val*`.
- **`BuilderHelpTooltipTest`**: ayuda = tooltip (`data-bs-title`/`View::help()`);
  **estado = visible** (`#stFontNote`, `#stAdvanceHint`… siguen en el DOM con
  `role="status"`); `data-bs-toggle` con `data-bs-*`; `id="stFont"` sigue en el builder.
- **`BuilderEscapingTest`**: `esc()`/`textContent` para interpolar en atributos; CSP sin
  `<script>` inline, sin `on*=`; el botón de enviar del preview en texto claro.
- **`BuilderPayloadContractTest` / `BuilderSaveButtonTest` (M8-bis)**: toda salida de
  `saveStructure()` repone `pintarBoton(true)`; autosave 800 ms + `lastSavedJson` (si el
  payload no cambió, no hay POST).
- **`BuilderLogicErrorsTest` (F3)**: mensajes por causa (`self/section/missing/invalid`)
  sin tocar; `LogicEvaluator::validateStructure` intacto.
- **Comportamientos puntuales**: literal `'Página ' + n` del salto de paso (migración
  0012 + `BuilderPreviewCssTest`); `pageHeaderHint` y `piiSuggestWrap` (avisar-no-marcar);
  edición en vivo sin botón «Aplicar»; `moveSettingsTo` reubica **la misma** instancia;
  `collectSettingsFromModal` manda **solo** los 7 bloques `FormSettings::BUILDER_BLOCKS`.
- **CSP/Assets**: `font-src 'self'`, FontAwesome limitado a `css/all.min.css` +
  `fonts/fa-solid-900.*` + `fonts/fa-regular-400.*`; `style-src 'unsafe-inline'` a
  propósito (lo necesita `themeCss()`).

## Fases

### F0 · Punto de restauración — **hecho** (tag + dump + este documento)
Commit: «Punto de restauracion pre-rediseno-builder (tag + dump)». Añadió
`storage/backups/` a `.gitignore`.

### F1 · Chrome estilo Google Forms (layout)
`views/builder/index.php` + `public/assets/css/app.css` + `public/assets/js/builder.js`.
- Columna central única (máx ~860px) para `#canvasFields` y `#emptyCanvas`.
- Barra de herramientas superior mínima: volver, título, undo/redo, `+` (abre
  `#addFieldModal`), tema, preview, estado de guardado (`#saveStatus`), URL pública,
  guardar.
- La paleta izquierda deja de renderizarse como columna: el alta de campos va por el
  `#addFieldModal` ya existente + `.app-add-between-btn` + duplicar.
- Se conservan todos los ids/data-*; `ViewStructureTest` y `BuilderPreviewCssTest` en
  verde (los selectores nuevos se declaran en su commit).
**Checkpoint:** suite verde; revisar en navegador bloques R/S de `pruebas-funcionales.md`.

### F2 · Tarjetas ricas + drawer de ajustes
`public/assets/js/builder.js` + `views/builder/index.php` + `app.css`.
- Markup de tarjeta (`cardHeadHtml`, `cardBodyHtml`, `cardChipHtml`, `cardHelpHtml`):
  pie de tarjeta con selector de tipo, interruptor **Obligatoria**, opciones,
  **duplicar/eliminar**, engranaje → abre el cajón.
- Drawer derecha reemplaza columna `col-lg-3` y `#fieldSettingsModal` (se conservan el
  `#settingsForm` y **todos** sus ids/listeners; pestañas Contenido/Lógica). Móvil: a
  pantalla completa.
- Comportamientos intactos: `Página N`, `pageHeaderHint`, PII avisar-no-marcar,
  autosave, repositions.
**Checkpoint:** suite verde + `BuilderValidationsContractTest`, `BuilderHelpTooltipTest`,
`BuilderEscapingTest` y `ControllerActionsTest`.

### F3 · Tema como palette y preview embebido
`views/builder/index.php` + `builder.js` + `app.css`.
- `#formSettingsModal` rediseñado como panel deslizante derecha; **ids y funciones
  intactos**: `populateSettingsModal`, `collectSettingsFromModal` (solo `BUILDER_BLOCKS`),
  `updateAdvanceHint` (F5), `checkFontLive`, `#stFont`/`#stFontNote`.
- Toggle de preview embebido en el lienzo reusando `previewBody()` y el markup del fill
  (`.vf-preview-scroll .fill-body .fill-container`); `#livePreviewModal` intacto.
- `ThemeFontCssInjectionTest` y `I18nTest` en verde.
**Checkpoint:** suite verde.

### F4 · Pulido, docs y cierre
- Tokens más planos (sombras/radio suaves), hover/active/focus, estados vacíos,
  responsive móvil (`dvh`), contraste `app-btn-primary`.
- `docs/decisiones.md` (entrada), `docs/manual-usuario.md` (sección builder),
  `docs/pruebas-funcionales.md` (R/S/T/U/AA), y **recuentos de AGENTS.md** si la
  estructura de `views/` cambia (nuevos partials `views/builder/_*.php` → el 62 sube en
  el mismo commit).
- Tabla de estado de este documento → **cerrado**; `docs/design.md` actualizado con las
  nuevas reglas de UI.
**Checkpoint:** suite completa verde + revisión manual R/S/T/U/AA.

## Tabla de estado

| Fase | Descripción | Estado |
|---|---|---|
| F0 | Punto de restauración (tag + dump + plan) | **hecho** |
| F1 | Chrome estilo Google Forms (layout columna central) | pendiente |
| F2 | Tarjetas ricas + drawer de ajustes | pendiente |
| F3 | Tema como palette y preview embebido | pendiente |
| F4 | Pulido, docs y cierre | pendiente |