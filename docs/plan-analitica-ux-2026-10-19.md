# Plan — Correcciones de presentación de la analítica (2026-10-19)

Cinco problemas reportados por el autor tras revisar W1–W4 en el navegador.
Todos son de **presentación/comportamiento** de `views/analytics/*` y
`public/assets/*`; **ninguno toca el modelo de datos ni las consultas**.

Se mantienen las invariantes de `AGENTS.md`: conmutador efímero, datos en
`data-chart`, CSP sin `<script>` inline, listas blancas de
`FieldAggregator::VISTAS`, la matriz con `colCounts`/`<tfoot>`, y los dos enlaces
CSV + textos `Descargar CSV`/`Comparar por`.

## Tabla de estado

| # | Problema | Estado |
|---|---|---|
| G1 | Tarjetas en blanco al cambiar de vista (bug de repintado) | ✅ hecha |
| G2 | El conmutador «Vista» baja de línea con etiquetas largas | ✅ hecha |
| G3 | El texto del índice de preguntas se desborda | ✅ hecha |
| G4 | La tabla de detalle de respuestas se ve muy ancha | ✅ hecha |
| G5 | Campos compuestos (matriz, checkbox, ranking) sin estilo legible | ✅ hecha |
| G6 | El «Resumen» de una tarjeta NPS parece vacío (insignias blancas sobre blanco) | ✅ hecha |

**Cierre.** Las seis corregidas (G6 con su «bis»). Ficheros: `public/assets/js/analytics.js`
(`soltarCanvas`/`crear`/reintento de `pintar`), `views/analytics/show.php`
(`vf-card-head`/`vf-card-title`/`vf-card-actions` en las tres tarjetas
conmutables + la matriz), `views/analytics/_rows.php` (delega los chips en
`View::answerValue()`, la misma copia que pinta el detalle de una respuesta),
`public/assets/css/app.css` (cabecera, índice `min-width:0`, tabla de
detalle con sticky+cebra+compacta y reset móvil, `.vf-chip*`), y
`tests/js/analytics-charts.js` (arnés nuevo) + `tests/unit/AnalyticsChartsTest.php`
(5 métodos nuevos). **G5 se extendió** al detalle de una respuesta
(`views/responses/show.php`, commit `6a00f6f`): su fallback `Answer::displayValue()`
pintaba un checkbox como «a; b», una matriz como «Fila: valor; …» y un ranking como
«Opción: 3», así que el dueño veía el mismo valor de dos maneras según entrara por
la analítica o por el detalle; ahora los dos canales usan `View::answerValue()`.
Sus tests son `ResponsesTest::testElDetallePintaChipsComoLaAnalitica` y
`testAnswerValueSoloReformateaCompuestosValidos`, y la comprobación manual K34.

---

## G1 · Tarjetas en blanco al cambiar de vista

**Causa raíz.** `analytics.js:pintar()` hace `destruir(canvas)` y luego
`new Chart(...)` dentro de un `try/catch` que **se traga** el error. Chart.js
4.4.3 lanza `"Canvas is already in use..."` si el Chart anterior no se liberó, y
el `catch` solo escribe en consola: el lienzo queda **en blanco** sin señal
visible. El disparador es cambiar el **`type` del Chart sobre el mismo canvas**
(barras↔anillo en `choice`, o el primer cambio en `numeric`) combinado con la
caja **oculta por `d-none`** en el momento del repintado.

**Arreglo.**
- Destruir **antes** de tocar la visibilidad y crear **después de que el
  contenedor tenga tamaño** (forzar reflow / `requestAnimationFrame` cuando la
  caja acaba de salir de `d-none`).
- `pintar()` no traga el error de reutilización: en el `catch`, `destruir()`
  forzado y un reintento; si falla otra vez, log claro (sin dejar el canvas
  muerto).
- Red de seguridad: liberar el canvas con `Chart.getChart(canvas)?.destroy()`
  además del `Map`, para no depender solo de `chartsPorCanvas`.

**Test.** `tests/js/analytics-charts.js` (patrón de `tests/js/fill-ranking.js`):
extrae `destruir`/`pintar`/`aplicarVista` del JS real y los corre contra un
`Chart` falso que imita el guard «already in use». Ejecutado por
`tests/unit/AnalyticsChartsTest` (SKIP si no hay node). Hoy obliga a rojo;
después, verde.

## G2 · El conmutador «Vista» baja de línea

**Causa.** El encabezado de tarjeta es
`d-flex flex-wrap justify-content-between align-items-start gap-2`; con un `h4`
largo, el bloque del conmutador envuelve a una fila nueva.

**Decisión (autor).** Etiqueta arriba ocupando su espacio, botones **anclados
arriba-derecha** sin bajar.

**Arreglo.** Encabezado común `.vf-card-head`: `d-flex align-items-start
justify-content-between gap-2` (el bloque de botones `flex-shrink-0`), y el
contenedor del título con `min-width:0; flex:1 1 auto` para que **trunque sin
empujar**. Aplica a las 4 ramas (numérica, ranking, choice, matriz).

## G3 · El índice de preguntas se desborda

**Causa.** Los `<a class="nav-link text-truncate">` son flex items de un
`nav.flex-column`; `min-width:auto` impide encogerlos por debajo de su contenido
(`white-space:nowrap` de `text-truncate`), así que desbordan `col-lg-3`.

**Arreglo.** En `app.css`: `.app-analytics-index .nav { min-width:0 }` y
`.app-analytics-index .nav-link { min-width:0; max-width:100% }`. Se conserva el
`title` con el texto completo, el ancla y el `scroll-margin-top`.

## G4 · La tabla de detalle de respuestas se ve muy ancha

**Decisión (autor).** **Pulir la tabla actual** sin reestructurar columnas.

**Arreglo (CSS `.app-detail-table`, markup mínimo).**
- Primera columna `#` **sticky** a la izquierda y **cabecera sticky** con fondo
  sólido (`var(--bs-table-bg, #fff)`), como `.app-group-table`.
- **Cebra sutil** en `tbody tr:nth-child(even)` + `:hover`.
- Celdas más compactas y ancho mínimo de tabla para que el scroll horizontal sea
  el mecanismo (con `#` y cabecera fijos como referencia).
- Respetar la regla móvil `@media (max-width:767.98px)` (tabla→tarjetas con
  `data-label`).

## G5 · Campos compuestos legibles (chips)

**Decisión (autor).** **Chips/insignias** en la celda; matriz como filas
`Fila: valor`; ranking como lista ordenada; checkbox como chips.

**Arreglo en `views/analytics/_rows.php`** (+ `.vf-answer-chips` en `app.css`),
respetando el **enmascarado PII antes** de reinterpretar el JSON:
- **checkbox** (lista JSON): un `<span class="vf-chip">` por valor, en
  `d-flex flex-wrap gap-1`.
- **matrix/ranking** (mapa JSON): una fila compacta por `clave: valor`. En
  ranking, orden por el número y `#n · ítem`.
- Escalares (`select/radio/scale/star/number/nps`): sin cambios.
- Se conservan `data-label`, el `>—<` del vacío y el HTML escapado en servidor.

**Extensión (commit `6a00f6f`).** El render se extrajo a **`View::answerValue()`**
(una sola copia), que devuelve HTML ya escapado o `null` si no es un compuesto; lo
usan `_rows.php` **y** `views/responses/show.php`. Antes el detalle pintaba el
compuesto con `Answer::displayValue()` («a; b» / «fila: valor; …»), así que el
mismo valor se veía de dos maneras según la pantalla. Un adjunto no pasa por chips
(su JSON guarda la ruta interna) y el PII se resuelve antes, así que `[oculto]`
cae al lado `null`.

---

## G6 · El «Resumen» de un NPS parece vacío

**Síntoma que quedó tras G1.** Al alternar una tarjeta **NPS** o **Número** a
«Resumen», el histograma desaparece (correcto) pero el resumen **no se veía**: el
autor lo describió como «el gráfico se queda en blanco». La causa **no** era el
repintado (G1): era de **contraste**.

**Causa raíz.** Las insignias Detractores/Pasivos/Promotores usaban
`text-bg-danger-subtle` / `text-bg-secondary-subtle` / `text-bg-success-subtle`.
Esa pareja **no existe** en el Bootstrap 5.3.3 vendorizado (solo existe
`text-bg-<color>` sólido), así que la clase no pintaba nada: el `.badge` se
quedaba con su `color:#fff` sobre fondo transparente → **texto blanco sobre
blanco**. En un NPS el resumen son las mini-stats + esas insignias, así que sin
insignias legibles parecía vacío.

**Arreglo** (`views/analytics/show.php`): pareja correcta
`bg-<color>-subtle` + `text-<color>-emphasis` (el `border-<color>-subtle` sí
existía y se conserva). El mismo error de clase estaba en
`views/admin/_user_role.php` (`text-bg-primary-subtle`).

**Test.** `AnalyticsLayoutTest::testElResumenNumericoSeVeYLasInsigniasNpsTienenColor`
renderiza un formulario NPS + Número, comprueba que el resumen pinta mini-stats e
insignias, y `grep`a el CSS de Bootstrap para verificar que cada clase que se usa
**existe**. Comprobación manual K33.

**G6 (bis) — «Resumen» tiene que ser una vista, no el histograma sin gráfico.** Con
las insignias ya legibles seguía pareciendo vacío: medido en Chromium headless, las
mini-stats y las insignias se pintaban **siempre** (Histograma y Resumen), así que
Resumen solo quitaba el lienzo y no aportaba nada distinto. Ahora van dentro de
`data-vf-summary-box`, que `aplicarVista()` enseña **solo** en Resumen; Histograma y
Resumen pasan a ser representaciones mutuamente excluyentes.

---

## Proceso

1. G1 (harness rojo → arreglo → verde), es el de más riesgo.
2. G2 y G3 (CSS/markup), rápidos.
3. G4 (tabla de detalle).
4. G5 (chips).
5. Tests, `php -l` / `node --check`, filtros, **suite completa** una sola vez
   (~7 min), docs y commit local en español.

## Invariantes a vigilar

- `AnalyticsChartsTest` exige hoy `data-vf-chart-box`, `vf-choice-table`,
  `data-vf-family`, `ranking-chart`, `dist`. Conservarlos.
- `RosterAnalyticsGroupTest`: exactamente 2
  `export.csv?from=…&atr_key=…&atr_value=…` y textos `Descargar CSV`/`Comparar
  por`.
- `RosterAnalyticsFilterTest`/`PiiMaskingTest`: pintan `_rows` a mano; no romper
  `data-label`, `>—<` ni el enmascarado previo al JSON.
- `FormFichaLayoutTest`: opera sobre el **fuente crudo** de `show.php`
  (`analytics-filters`, `app-page-head`, sin `flex-*-nowrap`/`btn-success
  w-100`).
- La regla móvil de `.app-detail-table` debe seguir funcionando.
