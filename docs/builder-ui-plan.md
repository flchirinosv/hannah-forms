# Plan de mejora UI/UX del Builder (2026-09-24)

Objetivo: acercar el builder a los constructores de formularios líderes
(Tally, Typeform, Paperform, Fillout, Google Forms, Jotform).

Estado actual: 3 columnas (paleta | lienzo | panel de configuración), buen
paradigma base estilo Paperform/Fillout. Debilidades detectadas en
`views/builder/index.php` + `public/assets/js/builder.js`:

- Botón «Aplicar» obligatorio en el panel → riesgo de perder cambios y fricción.
- Solo se agregan campos al final del lienzo (o drag); no hay inserción entre tarjetas.
- Lógica condicional enterrada al final de un panel de scroll largo.
- Sin undo/redo real (solo un deshacer único de borrado vía toast).
- Lienzo con tarjetas uniformes (borde constante), secciones poco diferenciadas.
- Etiqueta no editable directamente en el lienzo.
- Paleta = lista estática larga.
- Sin indicador de guardado.

## Prioridad alta (ejecutar primero)

1. **Edición en vivo (write-through).** Los inputs del panel actualizan el
   campo y su preview en el lienzo al instante. Se elimina el botón «Aplicar».
   `Ctrl+S` se mantiene para guardar el formulario. Patrón: Tally, Typeform, Paperform.
2. **Insertar entre preguntas.** Botón «+» entre tarjetas (al hover) y «+»
   flotante al final del lienzo, que abren el menú de tipos existente
   (reutilizar el menú creado para secciones). Patrón: Typeform, Tally, Paperform, Google.
3. **Tabs en el panel de configuración: «Contenido» | «Lógica».**
   La lógica condicional pasa a su propia pestaña. Patrón: Fillout, Jotform.

## Prioridad media

4. **Undo/Redo real** (`Ctrl+Z` / `Ctrl+Shift+Z` / botones) sobre todas las
   operaciones (agregar, mover, eliminar, editar, duplicar). Patrón: Tally, Google Forms.
5. **Lienzo WYSIWYG:** borde solo al hover para las tarjetas normales,
   secciones con fondo/encabezado propio, y edición de la etiqueta inline en la
   tarjeta (clic → input). Patrón: Paperform, Tally.

## Prioridad baja (pulido)

6. **Launcher compacto de tipos** (paleta tipo «+» que abre grilla cerca del
   cursor) en lugar de la lista larga estática.
7. **Barra de estado** «Guardado automático ✓ / Sin guardar» para dar confianza
   al autor. Patrón: Tally, Typeform.

## Notas de implementación

- Todo el JS vive en `public/assets/js/builder.js` (IIFE, sin dependencias
  salvo Sortable y Bootstrap). `View::asset()` versiona automáticamente
  (`?v=filemtime`), y `config/bootstrap.php` manda `Cache-Control: no-store`
  en el HTML → los cambios se ven con recarga normal.
- Consola: `console.log('[builder] vYYYY-MM-DD…')` para verificar versión.
- Tests: `php scripts/test.php` (243 tests actualmente) — correr al final.
- UI en español. `declare(strict_types=1)` solo aplica a PHP, no al JS.

---

# Segunda tanda (2026-09-27)

Estado: los puntos 1-5 y 7 de la lista anterior están implementados y
verificados. El punto 6 («launcher de tipos que abre grilla») queda
cubierto por la fase F1 de abajo. Este apartado registra las decisiones
tomadas para la segunda tanda; **el sistema visual está en
`docs/design.md`** (tokens, tabla de valores medidos, patrones de
interacción).

## Diagnóstico de la tanda

Cinco pedidos del usuario. Lo relevante es que **tres de los cinco eran
bugs reales y no mejoras de gusto**, y uno ya estaba resuelto en el
servidor pero no se comunicaba en el builder:

1. **Uniformidad de tamaños.** No era impresión: había tres tamaños de
   botón de icono conviviendo (`.app-tool-btn` 28×28, `.app-add-between-btn`
   30×22, split de 2.7rem), dos idiomas visuales en la paleta (filas
   full-width de «Estructura» vs. grid de 2 columnas de «Tipos») y tres
   tamaños de tipografía auxiliar. Causa raíz: no había tokens de control.
2. **`+` se separaba de su elemento al arrastrar.** `createAddHandle()`
   insertaba los `.app-canvas-add` como **hermanos** de las tarjetas, y
   SortableJS reordena los hijos de `#canvasFields` con
   `filter: '.app-canvas-add'`. Los `+` no participaban del arrastre, así
   que se quedaban clavados en su índice de DOM: tras un drop quedaban dos
   `+` juntos y una tarjeta entre medio. El hover era posicional
   (`.app-canvas-field:hover ~ .app-canvas-add`), así que también se
   enganchaba al vecino equivocado.
3. **Caja del `+`.** Era un `<ul class="dropdown-menu">` con lista vertical
   de tipos, replicada además en el control de sección (split button).
4. **Configuración por elemento.** Se pidió un engranaje en cada tarjeta.
5. **Condición de la sección.** El usuario melaporkan que solo afectaba
   al título, no a las hijas.

## Punto 5: la estrategia correcta de condiciones

**El servidor ya hacía lo que hacen los constructores destacados.** No
hubo que cambiar el modelo de datos, ni migración.

| Constructor | Regla a nivel de grupo | Regla por pregunta | Cómo combinan |
|---|---|---|---|
| Google Forms | Sí (sección «show this section if…») | **No existe** | La del grupo es el único mecanismo |
| Microsoft Forms | No (la sección es solo organizativa) | No (solo *branching* de flujo) | n/a |
| Typeform | Sí (lógica de grupo) | Sí («Only show when…») | Ambas aplican (AND) |
| Jotform Smart Forms | Sí (reglas de sección) | Sí (reglas de campo) | Ambas aplican |
| Cognito Forms | Sí | Sí | Ambas aplican |
| Qualtrics | Sí (lógica de bloque) | Sí (lógica de pregunta) | Se combinan |

El modelo dominante es «regla del grupo AND regla propia, ambas
editables». Google Forms es la excepción, y solo porque carece de
condiciones por pregunta. Ours ya lo es:

- `FillController::isFieldVisible()` — `si la sección está oculta → oculto`
  (`app/Controllers/FillController.php:800-832`).
- `public/assets/js/fill.js:446-448` — `own[child] === false || secHidden`.

**Por qué la sección sigue ganando aunque las hijas tengan su condición.**
La premisa «si las hijas conservan su condición, la sección sobra» se
refuta con un caso: el **bloque mixto**, una sección con 5 preguntas donde
2 son condicionales y 3 fijas. Sin sección habría que duplicar el título
como campo de texto suelto y aun así no habría forma de expresar «este
título y estas preguntas son el mismo bloque visual». Ese caso —el más
común en encuestas reales— justifica el elemento. Motores secundarios: la
sección ya tiene zona anidada de drop, «agregar pregunta dentro», contador
de hijas, y la randomización la mueve con sus hijas.

**El «elemento título» propuesto ya existe:** una `section` sin hijas y sin
condición *es* un título + descripción, y el fill ya lo dibuja así. Añadir
un tipo `heading` tocaría whitelist, renderer, exports, plantillas e i18n
a cambio de nada. La confusión venía de la etiqueta de la paleta
(`views/builder/index.php:17`: «Título / Descripción» / «Crea una sección /
agrupa preguntas»), que mezcla las dos ideas en una línea.

## Fases

Estado: **F0, F1, F2, F3 y el gap sin JS implementados y verificados** (253
tests en verde). Todas las fases de esta tanda quedan cerradas.

- **F0 — Tokens y uniformidad. HECHO.** `--vf-ctrl-h`, `--vf-icon-btn`,
  `--vf-hint-fs`, `--vf-focus-ring`, `--vf-tile-min-h`, `--vf-tile-icon` en
  `app.css:6-28`. Unifica los tres tamaños de botón de icono (28 / 30×22 /
  2.7rem → 32×32), los dos idiomas visuales de la paleta y las tres
  tipografías auxiliares. El lienzo pasa de `gap-3` a `gap-4` para que la
  banda del «+» tenga aire.
- **F1 — `+` pegado + cuadrícula centralizada. HECHO.** El `+` es hijo de la
  tarjeta que precede, centrado en su borde inferior (`top: 100%` +
  `translateY(-50%)`); SortableJS lo mueve con ella, así que la separación
  desaparece **estructuralmente**. La banda lleva `pointer-events: none` y
  solo el botón captura el puntero, para no crear zonas muertas. El hover
  posicional (`~`) se sustituyó por selector de hijo (`>`) más
  `:focus-within`. El punto de inserción se calcula al hacer clic
  (`addTargetFor`): «la tarjeta que lo contiene + 1», immune al problema de
  índices; las hijas de sección también llevan su `+` (antes no lo tenían).
  El dropdown se sustituyó por el modal `#addFieldModal` con rejilla de 21
  tiles generada en la vista desde el mismo `$fieldTypes` que la paleta;
  dentro de una sección se ocultan los estructurales con
  `[data-vf-structural-part]` + `data-vf-structural-hidden`. El split button
  de la sección desaparece (mismo modal). `addFieldByType` ganó un `atIndex`
  explícito, y los estructurales lo ignoran para no caer dentro de una sección.
- **F2 — Engranaje por elemento. HECHO.** El panel derecho se mantiene
  tal cual; el engranaje abre la configuración en un modal. Se **reubica
  la instancia única** del panel (`insertBefore` entre panel y modal) en
  vez de duplicarla: 45 elementos están cacheados en el objeto `el`
  (`builder.js:113-158`) y duplicar obligaría a scopear cada referencia,
  mantener dos fuentes de verdad para el autosave y duplicar listeners,
  sin ganar nada. Además las pestañas de Bootstrap resuelven por selector
  global (`data-bs-target="#tabContent"`), así que dos copias harían que
  el pill del modal manejara el panel. Cuatro detalles que no son
  evidentes:
  1. **El opener muere.** `openFieldSettings()` llama a
     `selectFieldById()`, que redibuja el lienzo entero; el nodo del
     engranaje que se acaba de pulsar ya no existe. Para devolverle el
     foco se localiza por `[data-uid=…] [data-act="settings"]` al cerrar.
  2. **Deseleccionar con el modal abierto.** `clearSettings()` reubica el
     formulario **antes** de alternar las clases; si no, el modal se queda
     con una caja vacía y el panel muestra la invitación a seleccionar.
  3. **La página no debe saltar.** Al vaciarse el panel la página encoge y
     el navegador ajusta `scrollY`; `moveSettingsTo()` lo restaura (igual
     que el `scrollTop` del `.modal-body`, que es el contenedor con scroll
     del modal). Sin eso, cerrar el modal te deja en otro sitio.
  4. **El hueco de las herramientas.** Con tres botones (`--vf-tools-w`)
     la etiqueta quedaba debajo: el `pe-5` de 3rem ya era corto con dos.
     Ahora la cabecera reserva un token derivado de `--vf-icon-btn`.
  El panel gana el acceso simétrico (botón de expandir, deshabilitado sin
  selección) para que el modal también sea alcanzable desde ahí.
- **F3 — Representación de la lógica de sección. HECHO.** El backend ya
  aplicaba el AND, pero el builder no lo comunicaba: de ahí el reporte de
  que «la condición solo afecta al título». Cuatro piezas, ninguna duplica
  la semántica (esa vive en `isFieldVisible()`):
  1. **Chip heredado** en la tarjeta de cada hija de una sección con regla,
     con estilo propio (`.app-logic-inherited`, color info) para que se lea
     como estado y no como una condición editable ahí. Si la hija además
     tiene regla propia, el chip lo dice («Además, su sección X exige: …»).
  2. **Aviso de alcance** en la tarjeta de la sección: «Se oculta el bloque
     entero (N preguntas)» en vez de un «Si: …» sin más, que es
     exactamente lo que generaba la confusión.
  3. **Aviso en la pestaña Lógica** (`#logicInheritedNote`): en la sección,
     que se oculta el bloque completo; en la hija, que hereda la regla de su
     sección y que se cumplen **ambas**.
  4. **Errores de guardado por causa.** `resolveLogic()` devolvía `null`
     para auto-referencia, fuente estructural, referencia inexistente y
     operador inválido, y los cuatro caían en el mismo texto. Ahora
     devuelve un código (`self`/`section`/`missing`/`invalid`) y
     `logicErrorMessage()` redacta un mensaje accionable que **nombra el
     elemento**. Cubierto por `tests/unit/BuilderLogicErrorsTest.php` (10
     tests, sin BD: reflexión sobre los métodos privados).
  Además, la etiqueta de paleta de `section` pasa de «Título /
  Descripción» (que mezclaba las dos ideas) a «Sección de preguntas» con
  la descripción «puede ocultarse completo por una condición», sincronizada
  en `typeInfo` de `builder.js`.

## El bug de fondo que reportaba el usuario (F3-bis, HECHO)

El síntoma inicial («la condición de la sección solo afecta al título») no
era de presentación: **`parent_id` nunca llegaba al servidor**.
`buildPayload()` mandaba el array `fields` en crudo, con la clave `parentId`
del estado en memoria, y `BuilderController` lee `$item['parent_id']`. La
clave llegaba `undefined`, así que `parent_id` se guardaba `NULL` para todo
y **ninguna** pregunta quedaba anidada. Al reabrir el builder la sección
estaba vacía y sus preguntas sueltas — y una sección sin hijas no tiene
nada que ocultar, de ahí la impresión de que la regla solo afectaba al
título.

Evidencia en la BD antes del arreglo: `fields` con `parent_id` no nulo = 0
filas; el formulario de ejemplo tenía la sección en posición 1 y sus cuatro
preguntas en 2-5, todas a nivel raíz.

El arreglo es un mapeo explícito en `buildPayload()`, que además documenta
la frontera. Lo cubre `tests/unit/BuilderPayloadContractTest.php`, que
compara las claves que el controlador lee con las que el cliente serializa
(comprobado que falla si alguien vuelve a mandar `fields` en crudo). El
test compara archivos porque el fallo estaba en el emparejamiento de
nombres entre JS y PHP, no en la lógica de ninguno de los dos: no hay
ningún test de `saveStructure()` porque su cuerpo viene de `php://input`,
que no es inyectable en CLI.

## Gap sin JavaScript (HECHO)

`FillController::renderFill()` pasaba los campos a la vista **sin filtrar
visibilidad** (`app/Controllers/FillController.php:107-115`), así que sin
JS se veían las preguntas cuya condición era falsa. Ahora
`FillController::initialVisibility()` (público, con tests) resuelve la
visibilidad reutilizando `isFieldVisible()` + `pagePlan()` — las MISMAS reglas
del submit, sin una tercera semántica — y la vista la marca con `vf-hidden`.

Dos detalles que no son evidentes:

1. **Fuentes.** En un GET en fresco no hay respuestas, así que se evalúa
   contra un mapa vacío (una afirmación con fuente vacía es FALSE, una
   negación TRUE: la semántica ya documentada). En el re-render de errores
   se pasan las respuestas ya enviadas, de modo que el participante ve lo
   mismo que vería con JS.
2. **El cliente tiene que poder revertirlo.** `fill.js` solo reevaluaba los
   elementos con `data-vf-logic`, así que un `vf-hidden` puesto por el
   servidor sobre una pregunta **sin** lógica propia (hija de una sección
   cuya condición luego se cumple) habría quedado pegado para siempre. Se
   añadió `plainFieldCards` para barrerlas. Regla para futuras fases: si
   añades ocultado en el render, el cliente debe poder levantarlo.

Verificado por HTTP con un fixture temporal (ya borrado): sección con
condición falsa oculta + sus dos hijas ocultas (la de `neq` también, por el
AND del contenedor), hija `filled` sin respuesta oculta, hija sin lógica de
sección visible, y round-trip de guardado del builder con la estructura
nueva.

## Verificación

- `php -l` en los PHP tocados; `php scripts/test.php` (253 en verde: 6 de
  `initialVisibility` y 10 de `BuilderLogicErrorsTest`). Los cuatro mensajes de
  error de condición se comprobaron además sobre HTTP real (422 con el texto
  esperado en cada caso).
- **Sin Node en el entorno no hay linter de JS**: los cambios en
  `builder.js`/`fill.js` dependen de revisión manual en navegador. Es la
  limitación principal de esta tanda.
- Bump obligatorio del log de versión (`builder.js:1676`).