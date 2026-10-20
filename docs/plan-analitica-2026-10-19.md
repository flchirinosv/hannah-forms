# Plan — Rediseño de la analítica de respuestas

**Fecha:** 2026-10-19
**Estado:** en curso (W1 → W4). Este documento es el **punto de restauración del
contexto**: si la sesión se pierde, aquí está el plan entero con sus decisiones,
sus fricciones y los tests que atan cada cosa.
**Objetivo:** que la pantalla `/forms/{id}/analytics` explique lo que hay que
analizar (UX), tenga las preguntas visibles que hoy son invisibles
(flexibilidad) y diga lo que dice con menos ruido (simplicidad).

Este es un plan **derivado de una auditoría** sobre `views/analytics/show.php`
(405 líneas), `_rows.php` (86), `_pagination.php` (28),
`AnalyticsController.php` (414), `FieldAggregator.php` (653) y
`analytics.js` (251). El *por qué* de cada cambio se archiva en
`docs/decisiones.md`; las comprobaciones manuales, en el bloque K de
`docs/pruebas-funcionales.md`.

---

## Decisiones ya tomadas (no volver a abrir)

| Decisión | Valor |
|---|---|
| Alcance | **Las cuatro fases W1–W4** |
| Preguntas abiertas | Listado de **últimas respuestas** por campo (no frecuencia de palabras) |
| Paginador AJAX | **Se mantiene**; no se migra a enlaces con URL |
| Tasa de respuesta | **Sí**, como KPI |
| Export JSON | **No** (queda fuera de esta tanda) |
| Selector de gráfico | **Sí**, efímero (client-side) con lista blanca por familia; **sin** migración ni builder |
| Marca / tokens / Inter / FontAwesome | **No se tocan**; CSS propio (`--vf-*`), sin CDN |
| Ancho | `analytics` sigue `container-fluid` **a propósito** (no aplicar el ancho de ficha) |

### Fuente de datos: `data-chart`

Los datos de los gráficos salen embebidos como atributo `data-chart` en
`#analyticsApp` (`show.php:7-21`), porque la CSP es `script-src 'self'`: **nunca**
`<script>` inline. Cambiar el tipo de gráfico es por eso puramente cliente.

---

## Contratos blindados (lo que este plan no puede romper)

- **`FormFichaLayoutTest`** exige en la analítica:
  `analytics-filters d-flex flex-wrap`; **sin** `flex-sm-nowrap`/`flex-lg-nowrap`;
  **sin** `w-sm-auto`/`btn-success w-100` (vista `show.php:42,108`).
  El test corta desde `app-head-actions` hasta el primer `</div>`, así que un
  comentario PHP con el literal `btn-sm` dentro de ese trozo rompe el test.
- **CSS:** mantener el selector combinado
  `.analytics-filters .form-control, .analytics-filters .form-select { width:auto }`
  en el mismo orden.
- **`RosterAnalyticsGroupTest`**: exactamente **dos** apariciones de
  `export.csv?from=…&atr_key=…&atr_value=…` y los textos `Descargar CSV` y
  `Comparar por`.
- **`RosterAnalyticsFilterTest`**: markup exacto de las opciones del `<select>`
  de atributos (`<option value="Norte" selected>`,
  `<option value="">Todos los valores</option>`, `<option value="sede" selected>Sede</option>`,
  `name="atr_key"`, `name="atr_value"`), y que `analytics.js` siga leyendo
  `getAttribute('data-atr-key')` y `getAttribute('data-atr-value')` (líneas
  377-384 del test).
- **`_rows.php`** conserva la clase `paginate-item`, `data-label="…"`, `>—<` y
  `data-atr-key`/`data-atr-value` en el `tbody`; **`_pagination.php`** conserva la
  subcadena `paginate` (ambos, `SubmissionPaginationTest`), y `PiiMaskingTest`
  cuenta celdas y `data-label`.
- **`AnalyticsAggregationTest::testCoincideConLosMetodosPorCampo`** compara
  `values` con `assertEquals` (`!=`, compara **claves**): **no añadir claves
  dentro de `values`**. Cualquier dato nuevo va en una clave nueva de `$stat`.
- **Nº de consultas**: `FieldAggregator` existe justo para que una visita cueste
  un número **fijo** de consultas; las preguntas abiertas añaden **una sola**.
- **PII**: solo `Pii::canView()` decide qué sale en claro; nunca una vía nueva
  que muestre valores crudos.
- **Sin JS**: los gráficos son progressive enhancement; la tabla de detalle, el
  CSV y los filtros GET funcionan solos.

---

## Fases

| Fase | Alcance | Estado |
|---|---|---|
| **W1** | Drill-down: enlace «Ver» por fila + arreglo del `#` (off-by-one) | ✅ `0b8be12` |
| **W2** | Preguntas abiertas: tarjeta con las últimas respuestas enlazadas | ✅ hecha |
| **W3** | Gráficos: histograma, barras horizontales, matriz con totales, ranking con barra + **selector «Vista»** | ✅ hecha |
| **W4** | Cabecera, filtros con etiqueta, índice de preguntas, estados vacíos, KPI participantes | ⬜ |

---

## W1 · Drill-down y arreglos

**Hallazgo:** el detalle de la analítica **no tiene enlace** a
`/forms/{id}/responses/{sid}`, mientras que el listado de respuestas sí
(`responses/index.php:113`). La fila `#` se numera desde **0**.

### W1.1 Enlace «Ver»

- `views/analytics/_rows.php`: nueva columna final **«Ver»** (icono ojo) →
  `/forms/{id}/responses/{sid}`, con `data-label="Ver"` para el layout móvil.
- El partial no recibe `$formId`: pasar `'formId' => (int) $form['id']` desde
  `AnalyticsController::show()` (≈línea 379) y `rows()` (≈línea 218).
- Pintar el enlace **solo si `$formId > 0`**: hay tests que llaman al partial a
  mano sin `formId` (`RosterAnalyticsFilterTest:229,257,275`, `PiiMaskingTest:406`).
- Se enlaza **siempre** (PII incluida): el destino aplica sus propios guards y
  `Pii::canView()`.

### W1.2 `#` off-by-one

- `show():383` y `rows():222` pasan `offset = per_page*(page-1)` (= **0** en la
  página 1) y `_rows.php:23,31` lo usa como primer índice → primera fila `#0`.
- Cambiar a `+1` en ambos: página 1 numera 1..15, página 2 empieza en 16
  (igual que `responses/index.php:74-76`).

### W1.3 Tests

- `SubmissionPaginationTest::testCadaFilaDelDetalleSePuedeAbrir` (enlace +
  rótulo móvil) y `testLaColumnaDeFilasEmpiezaEnUnoYNoEnCero` (página 1 = 1..15,
  nunca `#0`; página 2 empieza en 16), con helper `rowsDe(int $pagina)`.
- Comprobaciones manuales **K12** y **K13** en el bloque K de
  `docs/pruebas-funcionales.md`.

---

## W2 · Preguntas abiertas

**Hallazgo:** `show.php:150-151` descarta `text/textarea/email/date`, y **sin
tarjeta** quedan `url`, `tel`, `time`, `datetime-local` y `file` (no caen en
ninguna rama).

### W2.1 Backend (una consulta fija)

- `Answer::latestByFieldsInForm($formId, $ids, $from, $to, $atr, $limite)`:
  subconsulta con `ROW_NUMBER() OVER (PARTITION BY a.field_id ORDER BY a.id
  DESC) AS rn` + `WHERE t.rn <= :limite` **en el nivel exterior**, sobre
  `whereFormulario()`/`marcadoresDeCampos()` (patrón de `valueCountsInForm`).
  El `a.value <> ''` va dentro. Devuelve
  `[fieldId => [{value, at, sid}]]` (el `sid` es `submissions.id`, que es lo
  que luego enlaza la vista).
- `FieldAggregator`: constante **`TIPOS_ABIERTOS`** (los nueve: `text`,
  `textarea`, `email`, `url`, `tel`, `time`, `datetime-local`, `date`, `file`)
  y `ABIERTAS_MAX = 5`; clave nueva **`'abiertas'` en `$stat`** (nunca dentro
  de `values`).
- **La consulta se emite dentro de `forForm()` y solo si `$fieldIds` contiene
  campos abiertos**, así que `AnalyticsController::show()` no cambia: es la
  tercera consulta fija, condicional, y el número sigue siendo constante.
- Enmascarar con `$piiTypes` vía `enmascararAbiertas()` → `Pii::maskValue()`
  (para `file` el resultado es `[oculto]`, igual que en el resto).

### W2.2 Vista

- Rama nueva en `show.php` (sustituye el comentario de la 150-151) que llama a
  **`View::partial('analytics/_open', ['f','stat','form'])`**: el markup va en
  su propio fichero para que sea comprobable (dentro de `show.php` solo se lee
  como texto fuente).
- Tarjeta `col-md-6` anclada en `id="campo-{id}"`: `{non_empty} respuestas` +
  lista de las últimas N, valor con `app-truncate-2` (ya existe) + `title`
  completo, hora con `View::datetime`, y enlace **«Ver»** a
  `/forms/{id}/responses/{sid}` con icono de flecha. `file` se traduce con
  `Uploader::displayValue()`. Sin respuestas: «Sin respuestas todavía.».
- CSS propio `.vf-open-answers` en `app.css` (separadores, `li:last-child`
  sin borde, fecha a `.72rem`).

### W2.3 Tests

- `AnalyticsAggregationTest`: `testElTechoDeLasRespuestasRecientesEsPorCampoNoGlobal`
  (dos campos abiertos con `ABIERTAS_MAX + 3` cada uno, ambos llenos: fallaría
  con un `LIMIT` global), `testLasRespuestasRecientesRespetanElFiltroDeFechas`
  (día local + rango vacío), `testLasRespuestasRecientesSeEnmascaranSinPermisoPii`
  y `testSoloLosTiposAbiertosTraenRespuestasRecientes`.
- Markup: fichero nuevo **`tests/unit/AnalyticsOpenAnswersTest.php`** (7
  métodos, sin BD): estado vacío, enlace por `sid` y su orden, escapado en
  cuerpo y `title`, traducción de adjunto, la rama de `show.php`, los nueve
  tipos de `TIPOS_ABIERTOS`, y contador ≠ techo.
- Comprobaciones manuales **K14–K17**.

---

## W3 · Gráficos que informan

**Hallazgos:** la barra «Promedio/Mínimo/Máximo» (`analytics.js:144-165`) es
redundante con las mini-stats; el doughnut (`analytics.js:108-134`) es ilegible
con muchas opciones; la matriz no muestra totales por columna (`colCounts` se
calcula en `FieldAggregator:561` y nadie lo pinta); el ranking es solo tabla.

### W3.1 Histograma numérico

- `FieldAggregator::forForm()`: construir **`$stat['dist']`** desde `$rows` (ya
  disponible en `forForm():83`) con el mismo regex de `numerico():452`, ordenada
  asc. **Sin consulta extra.** Bins solo si `number` supera ~30 valores distintos.
- `analytics.js`: histograma `bar` con `dist`; las mini-stats de
  `show.php:171-173` se quedan.
- NPS: histograma 0-10 coloreado por zona (detractores/pasivos/promotores); el
  score de `show.php:161-168` se queda.

### W3.2 Barras horizontales para opciones

- `analytics.js:108-134`: `select`/`radio` → `bar` con `indexAxis:'y'`
  (Chart.js 4.4.3 lo soporta), ordenado por conteo desc, label `«MX · 4 (44%)»`.
  `checkbox` se queda vertical, también con `%`.

### W3.3 Matriz: totales + heatmap

- `show.php:196-220`: fila de pie **«Total»** con `$mv['colCounts']` + total
  general. Sombreado por intensidad `c/_total` por celda (`style=` inline está
  aceptado en el repo).

### W3.4 Ranking: barra de posición media

- `show.php:224-253` + `data-chart`: barras horizontales de `avg` (opcional;
  la tabla se queda).

### W3.5 Selector «Vista» efímero (aprobado por el usuario)

- **Client-side**, sin migración, sin builder, sin i18n (la vista está en
  español en crudo), con **lista blanca por familia**:

  | Familia | Opciones |
  |---|---|
  | `select`/`radio`/`checkbox` | **barras** (default) · **doughnut** · **tabla** |
  | numéricos (`number`/`scale`/`range`/`star`) | **histograma** (default) · **resumen** (avg/min/max) |
  | `nps` | **histograma 0-10** (default) · **resumen** (score + badges) |
  | `ranking` | **tabla** (default) · **barra** de posición media |

- Control: `btn-group` de iconos en la cabecera de la tarjeta (mismo patrón que
  los presets, `show.php:44-47`), **fuera** de `.app-head-actions`.
- JS: exponer `renderFieldChart(field, tipo)` y destruir/recrear (`chart.destroy()`).
- **No** es selector libre: fuera de la lista blanca hay combinaciones que
  mienten (doughnut sobre 10 000 números distintos, línea sobre categóricas).
- El estado **no sobrevive** a recargar ni al cambiar el filtro de fechas.

### W3.6 Tests (construido)

- `AnalyticsAggregationTest`: `testElHistogramaNumericoSumaElTotalYVaOrdenado`
  (suma de `c` == `answer_count`, orden asc), `testElHistogramaDelNpsCubreLaEscalaCeroADiez`
  (11 entradas con ceros) y `testUnNumeroMuyDispersoSeAgrupaEnBarras` (45 valores
  ⇒ `DIST_BINS`, suma 45). Sin tocar `values`.
- `tests/unit/AnalyticsChartsTest.php` (6 métodos, sin BD): listas blancas
  exactas de `VISTAS`, familias y tipos cubiertos en los dos sentidos, una sola
  vista activa en el conmutador, `<tfoot>` con `colCounts` y total general,
  familias/lienzos en el markup de `show.php`, y el JS que destruye antes de
  repintar y ya no pinta la barra redundante.

---

## W4 · Cabecera, filtros, navegación y KPI

### W4.1 Cabecera

- `show.php:36`: añadir `app-page-head` al wrapper del título (patrón de
  `responses/index.php:34`).
- Separar la **barra de filtros** de `.app-head-actions` (que queda solo con
  «Exportar CSV») a su propia fila. Conservar `analytics-filters d-flex flex-wrap`
  y evitar `btn-success w-100`.

### W4.2 Filtros

- `<label class="visually-hidden">` para los `input[type=date]` (hoy sin label);
  mantener los `aria-label` de los `<select>`.
- Opcional: presets 7d/30d/90d/Mes como `<a href="…">` reales para que funcionen
  sin JS (el JS ya los toma por `[data-preset]`, `analytics.js:15`).

### W4.3 Índice de preguntas

- `id="campo-{id}"` + `scroll-margin-top` en cada tarjeta; índice `sticky` en
  escritorio con anclas `<a href="#campo-N">` (**funcionan sin JS**) y un
  `<select>` con `scrollIntoView` en móvil.

### W4.4 Estados vacíos

- Total 0: tarjeta compuesta con CTA. Filtro sin resultados: mensaje + reusar el
  «Limpiar» de `show.php:99-101`. Unificar «Sin respuestas todavía» en todas las
  ramas.

### W4.5 KPI Participantes (tasa de respuesta)

- Solo modo `roster` (`FormSettings::ACCESS_ROSTER`):
  `FormParticipant::respondedCountForForm()` / `countForForm()`
  (`FormParticipant.php:606,628`).
- **Global, no respeta `from`/`to`** (el `responded_at` no se filtra por fecha):
  la etiqueta lo dice.
- La fila de KPI pasa de `col-sm-4` (3 tarjetas, `show.php:120-134`) a
  `col-sm-6 col-xxl-3` para que 4 no dejen una suelta.
- Test: la tarjeta aparece en un formulario `roster` y no en uno normal.

---

## Fricciones conocidas al implementar

1. **No añadir claves dentro de `values`** (rompe
   `testCoincideConLosMetodosPorCampo`).
2. **Exactamente dos** `export.csv?…` y los textos `Descargar CSV`/`Comparar por`.
3. `FormFichaLayoutTest` con la cabecera nueva.
4. Tests que llaman a `analytics/_rows` a mano sin `formId`.
5. CSP: sin `<script>` inline; todo dato va en `data-chart`.
6. Tokens e Inter fijos; FontAwesome solo `all.min.css` + sus dos fuentes.

## Proceso

- Suite completa `php scripts/test.php` al cierre de **cada fase** (5-7 min,
  correrla sola, nunca dos en paralelo). Hoy la línea base es
  **107 archivos / 1173 métodos**.
- Recuentos medidos al final: `AGENTS.md`, `docs/decisiones.md` y
  `docs/pruebas-funcionales.md` (hoy **549** comprobaciones).
- Commits locales por fase, mensajes en español vía `git commit -F`.
- `docs/manual-usuario.md` §9.2 se actualiza con lo que quede visible.
