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
| `--vf-surface` | `#ffffff` | Tarjetas, paneles, campos |
| `--vf-text` | `#1e293b` | Texto principal |
| `--vf-text-muted` | `#64748b` | Texto secundario, labels |
| `--vf-border` | `#e2e8f0` | Bordes de tarjetas y separadores |
| `--vf-radius` | `14px` | Radio de tarjeta y campo |
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
| `--vf-focus-ring` | `0 0 0 3px rgba(99,102,241,.18)` | Anillo de foco de tarjeta y campo |
| `--vf-tile-min-h` | `72px` | Altura de tile en paleta y modal de cuadrícula |
| `--vf-tile-icon` | `30px` (cuadrado) | Iconos de 36px (filas) y 30px (rejilla) |
| `--vf-tools-w` | `calc(--vf-icon-btn * 3 + .5rem)` | `pe-5` (3rem) en la cabecera de la tarjeta |
| `--vf-mono` | `ui-monospace, SFMono-Regular, Menlo, Consolas, monospace` | Claves de permiso y todo lo que se lee como código (matriz de roles) |

**Regla:** todo botón de icono es `width`/`height` = `--vf-icon-btn`,
con `padding: 0` y `display: inline-flex` centrado. Si necesitas otro
tamaño, es un componente distinto, no una excepción.

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
| Hueco para herramientas | `pe-5` 3rem | `--vf-tools-w` 104px | `.app-field-head` (F2) |

Nota: `.app-btn-primary` conserva `min-height: 44px` a propósito. Los
tokens de §1.2 gobiernan los controles *secundarios*; una acción
primaria debe seguir siendo la más grande de la pantalla para no competir
con el guardado.

---

## 3. Componentes

### 3.1 Tarjeta de campo (`.app-canvas-field`)

- Rejilla flex: asa de arrastre (`.app-field-drag`) + cuerpo
  (`.app-field-body`). Borde de 2px; `.selected` pasa a
  `--vf-primary` con `--vf-focus-ring`.
- Herramientas (`.app-field-tools`) arriba a la derecha, `opacity: 0`,
  reveladas por `:hover` o `.selected`. Orden fijo:
  **duplicar → configurar → eliminar**. El de eliminar lleva
  `text-danger`.
- Las herramientas se montan sobre la etiqueta, así que la fila de
  cabecera (`.app-field-head`) reserva `--vf-tools-w`, derivado de
  `--vf-icon-btn`. Antes era `pe-5` (3rem), que ya se quedaba corto con
  dos botones y se montaba encima de la etiqueta al añadir el engranaje.
- El asa de arrastre solo puede iniciar arrastre
  (`handle: '.app-field-drag'` en SortableJS).
- Etiqueta editable en línea (`.app-field-label`): clic la convierte en
  input; `Enter` confirma, `Escape` cancela.

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

### 3.3 Cuadrícula de tipos (`.app-type-grid`)

Rejilla responsive de tiles (icono + etiqueta) usada por la paleta y por
el modal de alta:

- Mismo componente en ambos sitios. El modal se genera desde el mismo
  catálogo `$fieldTypes` de la vista, así que no puede desincronizarse de
  la paleta.
- Tile: `min-height: var(--vf-tile-min-h)`, icono
  `var(--vf-tile-icon)`, etiqueta `--vf-hint-fs`.
- `section` y `page` se marcan visualmente (borde punteado, icono
  relleno) para que se distingan de las preguntas.

### 3.4 Paleta (`.app-palette-*`)

- Dos grupos: **Estructura** (`section`, `page`) y **Tipos de pregunta**.
- Ambos grupos usan el mismo tile; cambia la retícula (fila vs. 2
  columnas), nunca la altura del ítem.
- Los dos tiles de «Estructura» van apilados (`.app-palette-static` es
  una columna con `gap`): sin separación parecían una sola pieza de dos
  líneas.

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

### 3.6 Roles y grupos (`app-rbac-*`)

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

---

## 4. Patrones de interacción

### 4.1 Alta de campos

Un solo diálogo para los tres puntos de entrada: la paleta (clic), los
`+` del lienzo, y el control «agregar pregunta dentro» de una sección.
Todos abren el **mismo modal** con la cuadrícula completa. La única
diferencia entre ellos es el destino, de modo que el conjunto de tipos
válidos se calcula en un único lugar por contexto.

### 4.2 Configuración de un campo

- Clic en la tarjeta → se selecciona y su configuración aparece en el
  panel derecho.
- El engranaje de la tarjeta abre esa misma configuración en un modal
  centrado, para no obligar a desplazarse hasta el panel (que además es
  `position: sticky`, así que su contenido largo se sale de pantalla).
- El panel tiene el acceso simétrico: un botón de «expandir» en su
  cabecera, deshabilitado mientras no haya selección.
- **Invariante: una sola instancia del formulario de configuración.** Se
  reubica (`insertBefore`) entre el panel y el modal; nunca se duplica.
  Motivos técnicos en `docs/builder-ui-plan.md` (F2).
- Foco: al abrir se enfoca la etiqueta; al cerrar vuelve al botón que
  abrió el modal. Como `selectFieldById()` redibuja el lienzo, el
  engranaje se localiza **por `data-uid`** al volver, no por la referencia
  al nodo original (que ya no existe).
- Al cerrar el modal (botón, `Escape` o backdrop) el formulario vuelve al
  panel, conservando el `scrollTop` del contenedor con scroll y de la
  página: la página no debe saltar al encoger el panel.

### 4.3 Modales

- `modal-dialog-centered` + `modal-dialog-scrollable`: el desplazamiento
  ocurre **dentro** del modal, nunca en la página.
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
4. Radios: `--vf-radius` en campos y tarjetas, `--vf-radius-lg` en
   paneles y wrappers.
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
