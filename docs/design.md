# Sistema visual del builder — `design.md`

Especificación de tokens, componentes y patrones de interacción del
constructor de formularios. Es la fuente de verdad del «lenguaje visual»
del builder: si un valor no está aquí, se hereda de Bootstrap o se
introduce como token primero.

Decisiones y diagnóstico de origen: `docs/builder-ui-plan.md` (apartado
«Segunda tanda»).

Todo el CSS vive en `public/assets/css/app.css`. No hay preprocesador ni
framework: son custom properties nativas sobre Bootstrap 5.

---

## 1. Tokens

### 1.1 Marca, superficie y texto (existentes, `app.css:6-19`)

| Token | Valor | Uso |
|---|---|---|
| `--vf-primary` | `#6366f1` | Color de marca: focus, selección, enlaces activos |
| `--vf-primary-dark` | `#4f46e5` | Estado hover/focus de la marca |
| `--vf-primary-light` | `#eef2ff` | Fondos de realce suave, iconos, chips |
| `--vf-bg` | `#f4f5fb` | Fondo de página |
| `--vf-background` | gradiente de `.fill-body` | **Lo emite el tema**: «Color de fondo» del builder. Sin ajuste es el gradiente; con él, el color elegido |
| `--vf-font` | (sin valor) | **Lo emite el tema**: «Fuente». `body` la usa con `fallback` a Inter |
| `--vf-surface` | `#ffffff` | Tarjetas, paneles, campos |
| `--vf-text` | `#1e293b` | Texto principal |
| `--vf-text-muted` | `#64748b` | Texto secundario, labels |
| `--vf-border` | `#e2e8f0` | Bordes de tarjetas y separadores |
| `--vf-radius` | `14px` | Radio de superficies propias (tarjetas). Los controles nativos usan el de Bootstrap |
| `--vf-radius-lg` | `20px` | Radio de panel y wrapper del lienzo |
| `--vf-shadow` | `0 1px 3px …, 0 8px 24px …` | Elevación en hover |
| `--vf-shadow-lg` | `0 12px 40px …` | Modales y estados elevados |

### 1.2 Controles (nuevos en F0, 2026-09-27)

Existían porque cada componente fijaba sus propios píxeles y el resultado
era que tres tamaños de botón de icono convivían en pantalla.

| Token | Valor | Sustituye a |
|---|---|---|
| `--vf-ctrl-h` | `32px` | Alturas escritas a mano en botones y controles |
| `--vf-icon-btn` | `32px` (cuadrado) | `.app-tool-btn` 28px, `.app-add-between-btn` 30×22px, split 2.7rem |
| `--vf-hint-fs` | `.75rem` | `.68` / `.72` / `.74` / `.78rem` sueltos en tips y captions |
| `--vf-focus-ring` | `0 0 0 3px rgba(99,102,241,.18)` | Anillo de foco de tarjeta y controles propios (los nativos usan su `:focus` con `--vf-primary`) |
| `--vf-tile-min-h` | `72px` | Altura de tile del modal de cuadrícula |
| `--vf-tile-icon` | `30px` (cuadrado) | Iconos de 36px (filas) y 30px (rejilla) |
| `--vf-mono` | `ui-monospace, SFMono-Regular, Menlo, Consolas, monospace` | Claves de permiso y todo lo que se lee como código (matriz de roles) |

**Regla:** todo botón de icono es `width`/`height` = `--vf-icon-btn`,
con `padding: 0` y `display: inline-flex` centrado. Si necesitas otro
tamaño, es un componente distinto, no una excepción.

**Regla (controles nativos):** `.form-control`, `.form-select` y
`.input-group-text` van con las métricas de **Bootstrap** (radio, borde y
padding). No se sobreescriben: un bloque global con la misma especificidad que
los `-sm` —y cargado después de Bootstrap— deja a todos los `-sm` sin efecto
(ver §4, regla 4). Lo único propio es el **color** del anillo de foco, que sale
de `--vf-primary`.

---

## 2. Inventario de inconsistencias medidas (corregidas en F0/F1)

Valores reales leídos del CSS antes de la intervención. Esta tabla es el
registro de por qué existen los tokens de §1.2.

| Elemento | Antes | Después | Dónde |
|---|---|---|---|
| Botón de herramientas de tarjeta | `28×28` | `--vf-icon-btn` 32×32 | `.app-tool-btn` |
| Botón «+» entre tarjetas | `30×22` | `--vf-icon-btn` 32×32 | `.app-add-between-btn` |
| Botón split del control de sección | `2.7rem` ancho | `--vf-icon-btn` | `.app-add-control .dropdown-toggle-split` |
| Botón de regla de lógica | `24×24` | `calc(var(--vf-icon-btn) - 8px)` | `.app-logic-rule .app-tool-btn` |
| Icono de palette en «Estructura» | `36px` | `--vf-tile-icon` 30px | `.app-palette-icon` |
| Icono de palette en rejilla | `30px` | `--vf-tile-icon` 30px | `.app-palette-grid .app-palette-icon` |
| Altura de ítem de paleta |filas ~58px vs. rejilla ~62px | `--vf-tile-min-h` 72px | `.app-palette-item` |
| Tips y captions | `.68` / `.72` / `.74` / `.78rem` | `--vf-hint-fs` `.75rem` | varios |
| Botón de sección (`btn-primary`) | `44px` | `44px` (se mantiene: es primario) | `.app-btn-primary` |
| Hueco para herramientas | `pe-5` 3rem → `--vf-tools-w` 104px | **Se elimina con el pie de la tarjeta (F2)**: las herramientas y el rótulo ya no comparten fila | `.app-field-head` (borrada) |

Nota: `.app-btn-primary` conserva `min-height: 44px` a propósito. Los
tokens de §1.2 gobiernan los controles *secundarios*; una acción
primaria debe seguir siendo la más grande de la pantalla para no competir
con el guardado.

---

## 3. Componentes

### 3.1 Tarjeta de campo (`.app-canvas-field`)

- Rejilla flex: asa de arrastre (`.app-field-drag`) + cuerpo
  (`.app-field-main`). Borde de 2px; `.selected` pasa a
  `--vf-primary` con `--vf-focus-ring`.
- Etiqueta editable en línea (`.app-field-label`): clic la convierte en
  input; `Enter` confirma, `Escape` cancela.
- Miniatura del campo (`.app-field-body`): ver §3.5b.
- **Pie de la tarjeta (F2):** selector de tipo (`select.app-type-select`), el
  interruptor de obligatoria y las herramientas
  (`.app-field-tools`: **duplicar → configurar → eliminar**). Orden fijo; el de
  eliminar lleva `text-danger`.
- Las herramientas están **siempre visibles** en el pie: el `opacity: 0` de las
  cabeceras viejas las hacía indescubribles (no había hover en el móvil y no
  era un camino de teclado). Por eso dejan de compartir fila con la etiqueta y
  la cabecera `.app-field-head` y su hueco `--vf-tools-w` **ya no existen**: el
  cambio de tipo y la obligatoria se hacen desde el pie, sin abrir ajustes.
- El asa de arrastre solo puede iniciar arrastre
  (`handle: '.app-field-drag'` en SortableJS).

### 3.2 «+» de inserción (`.app-canvas-add`)

Contrato de implementación, no negociable:

- **Es hijo de la tarjeta que precede**, cuelga de su borde inferior. No
  es hermano. Motivo: SortableJS reordena los hijos de `#canvasFields`;
  un hermano se queda clavado en su índice de DOM y se separa de su
  tarjeta al arrastrar. Como hijo, se mueve con ella por construcción.
- El hover se revela con **selector de hijo**
  (`.app-canvas-field:hover > .app-canvas-add`), nunca con
  `~` (hermano general), que depende de la posición y se engancha al
  vecino equivocado.
- El punto de inserción es «la tarjeta que lo contiene, + 1». No se
  cuentan hermanos previos.
- Los `section` y `page` solo son insertables en la raíz; dentro de una
  sección quedan excluidos (validado también en servidor:
  `BuilderController.php:123-133`).
- **La banda ocupa todo el ancho de la tarjeta (`left: 0`)**, no el ancho
  menos el hueco de las herramientas (`2.35rem`, que es lo que mide
  `.app-canvas-tools`). La constante se heredaba de la cabecera y, con
  la línea y el botón colgando de la derecha, no había nada que la
  justificara: se veía el «+» desplazado. Por el mismo motivo
  `.app-add-control` («Agregar dentro») es `max-width: 100%` y
  `margin: .25rem 0 0`, sin el `2.35rem` de margen ni del ancho. Las
  dos reglas miden la misma constante: se mueven a la vez o se
  desincronizan.

### 3.3 Cuadrícula de tipos (`.app-type-grid`)

Rejilla responsive de tiles (icono + etiqueta) generada desde el mismo
catálogo `$fieldTypes` de la vista que se usa en el modal de alta:

- Tile: `min-height: var(--vf-tile-min-h)`, icono
  `var(--vf-tile-icon)`, etiqueta `--vf-hint-fs`.
- `section` y `page` se marcan visualmente (borde punteado, icono
  relleno) para que se distingan de las preguntas.
- `:active` aplana el tile (`transform: translateY(0)`), porque el hover
  lo eleva: sin el estado de prensa, el clic parece un salto.

### 3.4 De dónde salen los tipos

La paleta izquierda permanente **ya no existe** (F1): el lienzo es la única
columna y los tipos viven en el diálogo `#addFieldModal` (`col-sm-6` en
escritorio, dos columnas estables en móvil). Es la decisión de Jotform del
plan: una paleta fija compite con el lienzo, y el botón `+` (de la barra y de
la banda entre tarjetas) abre el mismo diálogo con el destino ya resuelto.

### 3.5 Estados lógicos (insignias y notices)

- Insignia de tipo de campo: `.badge` con `text-bg-light`, en mayúsculas
  `--vf-hint-fs`.
- Contador de hijas de una sección: `.app-section-count`; el estado vacío
  usa `.app-section-count-empty` (borde punteado, `--vf-text-muted`).
- Un campo con condición lleva chip de lógica en la tarjeta; una sección
  con condición debe marcar también a sus hijas como heredadas (F3,
  pendiente).

### 3.5b Miniatura del campo en la tarjeta (`.app-field-body`)

Los cuatro tipos de encuesta (NPS, escala, estrellas, ranking) y la matriz
se dibujan en la tarjeta del lienzo con clases propias, y esas clases
**tienen** reglas: sin ellas la miniatura salía como texto suelto
(«0 1 2 3 … 10», una fila de estrellas del tamaño por defecto) y la
tarjeta parecía un error de maquetación.

| Clase | Qué es |
|---|---|
| `.nps-dot` | Botón de la escala 0–10 de la miniatura, con los extremos resaltados |
| `.scale-dot` | Botón circular de una escala 1..N |
| `.star-icon` | Estrella de la miniatura (`#fbbf24`) |
| `.ranking-pos` | Insignia de posición de una opción del ranking |
| `.vf-matrix-preview` | Contenedor de la matriz en miniatura, con `overflow: hidden` |

El preview en vivo del modal (`previewBody()` en `builder.js`) **no** usa
estas: reutiliza el markup del fill (`nps-option`/`scale-btn`,
`star-option`/`star-btn`, `vf-matrix-cell`, `vf-ranking-*`), para que lo
que se ve antes de publicar se parezca a lo que se verá después. Las
miniaturas van en `em` sobre el tamaño de fuente de la tarjeta, que es lo
que hace que escalen con ella.


### 3.6 Cabecera de salto de página (`.vf-page-head`)

- Es la caja con icono, título y descripción que abre un paso. **Sin
  fondo ni caja**: se separa con la misma línea fina que usa
  `.vf-section-block` (`border-top: 1px solid var(--vf-border)`), porque
  una caja de color dentro de un formulario publicado se lee como un
  aviso.
- Icono de 40px con su ámbar (`#fef3c7` / `#d97706`) y título a
  `1.1rem`/700: mismo tamaño que `.vf-section-icon` y `.vf-section-title`
  para que ambos encabezados midan igual, con el color como única
  diferencia —un paso no es una sección y tiene que poder distinguirse.
- El builder reutiliza este markup en el preview (`BuilderPreviewCssTest`
  lo ata), y con `fields.show_header` apagado no lo pinta ni el
  formulario ni el preview.
- El separador «— siguiente paso —» del preview es `.vf-page-break`, con
  línea a ambos lados. Antes era `.vf-page-divider`, la clase de la que
  pintaba la insignia centrada del preview: una sola clase para dos
  formas que no se parecían en nada.

### 3.7 Roles y grupos (`app-rbac-*`)

Fuera del builder, pero con el mismo lenguaje visual.

| Clase | Qué es |
|---|---|
| `.app-rbac-list` | Lista con scroll de casillas (roles o miembros de un grupo) |
| `.app-rbac-item` | Una fila: checkbox + nombre + metadato; hover y `:focus-within` con `--vf-primary-light` |
| `.app-rbac-effect` | «Lo que otorga este grupo»: la unión de permisos de sus roles |
| `.app-rbac-effect-keys` | Las claves como chips `code` |
| `.app-rbac-section` | Cabecera de sección de la matriz, con «marcar toda la sección» |
| `.app-rbac-count` | Contador `n/total` de la columna de un rol |
| `.app-rbac-select` | Select de rol de la tabla de usuarios (auto-envía al cambiar) |
| `.app-rbac-groups-btn` | Botón que abre los grupos de un usuario |

Dos reglas que nacen de no haber sido designed antes:

- **Nada de `<select multiple>`** para elegir varias cosas: obliga a mantener
  Ctrl pulsado y no hay nada en pantalla que lo diga. Casillas, una por rol
  o por persona.
- **Se enseña el efecto, no el cableado**: una columna «Grupos» con badges
  solo responde «en qué grupos está», y la pregunta del día a día es qué
  permisos le da a esa persona estar en ese grupo. Por eso el grupo pinta su
  unión de permisos.

### 3.8 Grupos plegables de la ficha (`app-settings-group`)

Los siete bloques de «Ajustes del formulario» (acceso, programación y cupo,
notificaciones, webhook, mensaje final, revisión e idioma) se pliegan: con los
siete abiertos la tarjeta ocupaba más de tres pantallas y el ajuste que se
busca casi nunca está en el primero.

| Clase | Qué es |
|---|---|
| `.app-settings-group` | El `<fieldset>` del grupo. Bootstrap ya pone `min-width: 0` |
| `.app-settings-group-title` | El `<legend>`: mismo tamaño y peso que el título de la tarjeta
  (`h6 fw-bold`), así que los títulos de los siete grupos no se leen como
  subtítulos de la sección, sino como el mismo nivel que «Ajustes del
  formulario». Sin esto el botón del grupo heredaba el tamaño del cuerpo
  (`var(--bs-body-font-size)`) y el grupo se leía como un encabezado de
  sección, no como un título de bloque plegable. `margin-bottom: .75rem` es el
  espacio con el bloque de controles de abajo. |
| `.app-group-toggle` | El `<button type="button">` del título: `flex`, ancho completo, `cursor: pointer` y anillo de `:focus-visible` (`--vf-focus-ring`) |
| `.app-group-caret` | El chevron a la derecha (`ms-auto`); gira con `transform` cuando `aria-expanded="true"` |

Contrato de implementación, no negociable:

- El botón va dentro del `<legend>`: el `fieldset` conserva su nombre
  accesible y el botón es el único control con `aria-expanded` y
  `aria-controls` apuntando al `id` real del panel. `type="button"` es
  obligatorio — sin él, pulsar el título **envía el formulario de ajustes**.
- El plegado es de Bootstrap (`data-bs-toggle="collapse"`), no propio: no
  hay `hidden` en el markup, así que sin JavaScript el formulario se ve
  entero. Todos los paneles salen `collapse show`.
- El estado se recuerda en `localStorage` **por formulario**
  (`vf:grp:<id>`, leído de `data-vf-form-id`), no por el navegador: el
  mismo usuario pasa por varias fichas. Con un `alert-danger.app-alert` en
  la página —un guardado fallido— se **abren todos**, porque el aviso está
  arriba y el ajuste que falló no puede quedar escondido.
- Los hijos de una sección (`vf-section-children` en el fill,
  `app-section-children` en el builder) comparten la ausencia de `border-left`,
  pero **no** el fondo: en el builder es la zona de trabajo y el tinte dice
  dónde acaba la sección; en el formulario publicado la sección ya trae
  encabezado y línea, y el tinte encima solo lo hacía parecer partido en
  dos. Los dos lados están atados por el mismo test.

### 3.9 Modales de ajustes (centrados)

Los ajustes del builder (campo y formulario) viven en un **modal centrado**,
igual que el de agregar elemento: `modal-dialog-centered` + `modal-lg` +
`modal-dialog-scrollable` sobre un `.modal` de Bootstrap (mismo `Esc`,
backdrop, focus y `aria-modal`). Antes se habían diseñado como cajón lateral
(F2/F3, `.modal-drawer`), pero un error de marcado —la clase iba en el mismo
elemento que `.modal-dialog`, cuando el selector la esperaba como ancestro— los
dejaba como un modal Bootstrap por defecto: 500px, centrado y con una sola
esquina redondeada. Se unificaron con el patrón de §4.3.

Contrato de implementación, no negociable:

- Las tres superficies (`#addFieldModal`, `#fieldSettingsModal`,
  `#formSettingsModal`) usan **las mismas clases de diálogo**. No hay
  `position: absolute` ni `100dvh` por id.
- El pie del panel de formulario (`modal-footer border-0`) es **hermano** de
  `modal-body`, no hijo: así `modal-body` se desplaza (`.modal-body` con
  `overflow-y: auto; flex: 1 1 auto`) y el pie queda anclado abajo.
  `BuilderHelpTooltipTest::testLosTextosDeAyudaYaNoEstanSueltosEnLosPanelesDeAjustes`
  exige el string en la vista.
- Nada de paneles `position: sticky` en el builder: el modal es la única
  superficie de configuración, y `#settingsForm` (campo) vive en él desde el
  arranque en una sola instancia (no hay `insertBefore` entre superficies).
- El título del panel de campo es **fijo** («Configuración del elemento»): la
  etiqueta de la pregunta es contenido y se edita en su propio campo, no en la
  cabecera del panel.

### 3.10 Vista previa

Una sola superficie de preview en el constructor: el modal en pantalla completa
(`#livePreviewModal` → `#livePreviewContent`), que abre el botón **Vista
previa** de la barra (`#btnLivePreview`). No hay preview embebido en el lienzo:
un segundo botón que pintaba el formulario debajo del canvas se retiró porque
quedaba fuera de la vista y parecía no hacer nada.

Regla: `previewBody()` reutiliza el markup del FILL
(`nps-option`/`scale-btn`, `star-option`/`star-btn`, `vf-matrix-cell`,
`vf-ranking-*`, `.vf-submit`), y `FillLayoutTest` prohíbe una regla de ancho
por id para `livePreviewContent`: publicar y prever comparten
`width/max-width: 100%` + `col-lg-6`, y una regla por id los
separaría en silencio.

---

### 3.11 Shell y navegación (sidebar)

El panel se envuelve en un shell de dos columnas: `.app-shell` contiene el
`<aside class="app-sidebar offcanvas-lg offcanvas-start" id="appSidebar">` y el
`.app-content` (barra superior móvil + `main` + pie).

- **Una sola superficie de navegación.** En `lg+` el `<aside>` es una columna
  fija (`position: sticky; top: 0; height: 100vh`, 260px) y por debajo se
  comporta como cajón que entra desde la izquierda. El salto lo trae Bootstrap
  (`offcanvas-lg`): sin JS propio, sin `localStorage` y sin scroll-lock casero.
- **El `position` va dentro de su media query.** Bootstrap posiciona el cajón
  con `position: fixed` **dentro de un `@media (max-width: 991.98px)`**; una
  regla `.app-sidebar { position: sticky }` sin media query ganaría por orden de
  fuente (app.css va después de Bootstrap) y **el cajón no abriría**, sin ningún
  error. Por eso el `sticky` vive en `@media (min-width: 992px)` y la regla base
  solo lleva `--bs-offcanvas-width`. Lo ata
  `SidebarLayoutTest::testElPlegadoDeEscritorioViveEnSuMediaQuery`.
- **Superficie oscura con texto claro.** La sidebar es el mismo degradado de la
  marca (`linear-gradient(90deg, var(--vf-primary) 0%, #8b5cf6 100%)`) con texto
  blanco. No es un gusto: sobre el blanco de la superficie los grises del menú se
  leían mal (el reporte del dueño fue justo ese), así que el menú va en blanco al
  85% y el estado activo es una **píldora blanca con texto índigo**, el mayor
  contraste de la pantalla. La franja de marca (`.app-sidebar-brand`, que
  reutiliza `.app-navbar`) comparte degradado, dirección y ancho, así que la
  cabecera queda **sin costura**. El botón «Nuevo formulario` es blanco con texto
  índigo, y la insignia de rol usa el badge claro por defecto. Lo ata
  `SidebarLayoutTest::testLaSidebarEsOscuraConTextoClaro`.
- **La sidebar conserva su texto en móvil.** Las etiquetas del menú, el botón
  «Nuevo formulario» y el bloque de usuario **no** se ocultan al abrir el cajón:
  un cajón con solo iconos parece roto y no dice a qué lleva cada entrada. (Lo
  que se pidió ocultar era el texto de los botones del **contenido**, abajo.) El
  botón de cerrar del cajón es `btn-close-white`: sobre el degradado, el
  `btn-close` oscuro de Bootstrap desaparecía. Lo ata
  `SidebarLayoutTest::testEnMovilLaSidebarConservaSusEtiquetas`.
- **En móvil, los botones del CONTENIDO con icono muestran solo el icono**
  (`@media (max-width: 991.98px)`): para los `.btn` de `.app-content` que llevan
  un `<i class="fa-…">`, el texto se colapsa con `font-size: 0` (sigue en el
  nombre accesible, así que un lector de pantalla lo lee) y el icono recupera su
  tamaño. Quedan fuera los botones **sin icono** (quedarían vacíos) y los
  `w-100`: un botón a ancho completo con un solo icono centrado no se distingue
  de uno roto, y ahí el texto es lo que dice qué hace («Eliminar», «Guardar
  ajustes»). La regla es de `.app-content`, así que la sidebar nunca entra. Lo
  ata `ContentButtonsMobileTest`.
- `.app-navbar` **no** se toca: la reutilizan las cabeceras con degradado de los
  cinco modales, así que la franja de marca y la barra móvil la reutilizan tal
  cual y la navegación usa clases propias (`.app-shell`, `.app-sidebar`,
  `.app-sidebar-inner`, …).
- `#vfConfirm` sigue siendo hijo directo de `<body>` (lo exige
  `AsyncActionsJsTest`).

---

## 4. Patrones de interacción

### 4.1 Alta de campos

Un solo diálogo para los tres puntos de entrada: el botón **Agregar
elemento** de la barra, los `+` del lienzo, y el control «agregar pregunta
dentro» de una sección. Todos abren el **mismo modal** con la cuadrícula
completa. La única diferencia entre ellos es el destino, de modo que el
conjunto de tipos válidos se calcula en un único lugar por contexto.

### 4.2 Configuración de un campo

- Clic en la tarjeta → se selecciona, y su configuración aparece en el
  modal de ajustes de campo (ver §3.9).
- El engranaje de la tarjeta abre ese mismo modal con el campo en cuestión;
  el «expandir» del cajón viejo ya no existe: no hay panel que expandir.
- **Invariante: una sola instancia del formulario de configuración.** Vive en
  el modal desde el arranque; los ids y los listeners son los de siempre, y
  `#settingsForm` no se mueve ni se duplica.
- Cerrar el modal (botón, `Esc` o backdrop) deja la tarjeta que lo abrió
  seleccionada: `selectFieldById()` redibuja el lienzo, así que el engranaje
  se localiza **por `data-uid`** al devolver la selección.
- Foco: al abrir se enfoca la etiqueta; al cerrar vuelve al botón que abrió.

### 4.3 Modales

- `modal-dialog-centered` + `modal-dialog-scrollable`: el desplazamiento
  ocurre **dentro** del modal, nunca en la página.
- Las tres superficies del builder (`#addFieldModal`, `#fieldSettingsModal` y
  `#formSettingsModal`) comparten esas clases; no hay excepciones de cajón
  (ver §3.9).
- Rejilla de tipos agrupada por secciones con títulos, no una lista
  larga: el menú vertical obligaba a hacer scroll para encontrar un tipo.
- Foco, `Escape` y backdrop los trae Bootstrap; no se reimplementan.

### 4.4 Ayuda (tooltip `?`)

Regla del proyecto, y la razón de que exista el botón `.app-help`:

> **La explicación se esconde, el estado no.**

- Cada ajuste lleva su explicación en un `button.app-help[data-bs-toggle=tooltip]`
  colgado de la etiqueta, generado por la closure `$helpButton()` de la vista
  (`views/builder/index.php`). El texto va en `data-bs-title` escapado con
  `View::escape()` y `data-bs-html="true"`, para que `<code>` siga siendo código.
- El tooltip lo inicializa `app.js` (`trigger: 'hover focus click'`). El `click`
  no es opcional: en un móvil no hay hover y la ayuda sería inalcanzable.
- Los hints que escribe la aplicación al vuelo (`#stFontNote`, `#stAdvanceHint`,
  `#stAccessHint`, `#stReviewHint`) **no** van en tooltip: son estado, y quien
  acaba de guardar necesita verlos sin tener que cazarlos con el puntero.
- Un `?` dentro de un `<label for>` neutraliza el clic en JS (`app.js`), porque
  el clic en un label reenvía al control y abrir la ayuda acabaría moviendo el
  interruptor de al lado.

### 4.5 Accesibilidad

- Todo botón de icono lleva `aria-label` o `title` descriptivo.
- Los toggles de tipo de filtro llevan `aria-pressed`.
- Los `<label for>` dependen de ids únicos: **no se pueden eliminar** al
  duplicar markup (el criterio queda registrado en F2 del plan).
- Foco visible en tarjetas y campos: `--vf-focus-ring`.

---

## 5. Reglas para código nuevo

1. Si un valor no es un token de §1, añade el token primero; no escribas
   píxeles sueltos en un componente.
2. Botón de icono = `--vf-icon-btn`. Sin excepciones.
3. El color de marca se referencia por `--vf-primary*`, nunca literal
   (`#6366f1` etc. solo en `:root`).
4. Radios: `--vf-radius` en superficies propias (tarjetas), `--vf-radius-lg`
   en paneles y wrappers. Los controles nativos (`.form-control`,
   `.form-select`, `.input-group-text`) conservan el radio, el borde y el
   padding de Bootstrap; no se sobreescriben, solo el **color** del foco
   (`--vf-primary`).
5. Todo estado interactivo necesita su hover y su `:focus-visible`.
6. El estado en memoria de `builder.js` es camelCase (`parentId`) y el
   contrato JSON del servidor es snake_case (`parent_id`). Toda la
   traducción ocurre en `buildPayload()`: si añades un campo, serialízalo
   ahí explícitamente. Mandar el array en crudo pierde datos en silencio.
7. Un error de validación nombra el elemento y dice qué hacer; nunca una
   lista de causas posibles («puede ser A, B o C»), porque el autor no
   puede adivinar. Si el backend detecta varias causas, devuelve un código
   por causa y el mensaje se redacta en un solo sitio.
8. Al tocar `builder.js`, bumpear el log de versión del final del archivo
   (convención `vYYYY-MM-DD<letra>` con el resumen del cambio). Permite
   confirmar en consola qué versión está sirviendo el navegador.
9. Los strings de UI van en español, y los del fill público también en
   `app/lang/{es,en}.php` (verificar paridad con `I18nTest`).
10. Un ajuste del builder es un modal centrado (§3.9) o nada: nada de paneles
    `position: sticky` ni de mover la misma instancia de un formulario
    entre superficies.
11. Un preview del constructor reutiliza `previewBody()` y el markup del
    fill, y **no** lleva ancho por su id; si añades una superficie nueva,
    `FillLayoutTest` cuenta la octava y el test de los ids.