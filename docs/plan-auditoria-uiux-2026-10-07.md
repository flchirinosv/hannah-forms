# Plan de implementación para abordar la auditoría de VanillaForms

## 1. Quick Wins (0.25–0.5h c/u)

| ID | Ítem | Archivos | Cambios | Criterio de aceptación | Verificación | Esfuerzo | Riesgo | Prioridad |
|---|---|---|---|---|---|---|---|---|
| QW-1 | Estados vacíos en Roster (Lista/Invitaciones/Catálogo) | `views/dashboard/forms/_roster_list.php`, `views/dashboard/forms/_roster_invites.php`, `views/dashboard/forms/_roster_catalog.php` | Añadir estado vacío minimalista (icono sutil de FontAwesome + texto `text-secondary` + pequeño texto explicativo). No romper IDs. Solo cuando sin datos. | Al no haber datos, muestra mensaje claro. Con datos, comportamiento idéntico. No añade IDs nuevos críticos. | `php -l`, revisar HTML, `php scripts/test.php --filter RosterPanel`, `ViewStructureTest` relevante. | Bajo (0.3h) | Muy bajo | P0 |
| QW-2 | aria-live para slots AJAX | `views/dashboard/forms/show.php` (slots `vfSlotParticipants`, `vfSlotAccess`), `views/dashboard/forms/_roster_panel.php` | Añadir `aria-live="polite"` y `role="status"` a `#vfSlotParticipants` y `#vfSlotAccess`. Mantener estructura exacta exigida por tests (vfSlotParticipants sin card wrapper). | Anuncios accesibles en repintado. No rompe regex de `RosterPanelTest` (el test espera patrón exacto del div). | `php scripts/test.php --filter testElListadoNoViveEnLaTarjetaDeAcceso`, `ViewStructureTest`. | Muy bajo (0.1h) | Muy bajo | P0 |
| QW-3 | Loading state en botones AJAX (vfPost) | `public/assets/js/app.js` | En delegado de formularios (`[data-vf-ajax]` o submit con X-Requested-With), deshabilitar botón submit al enviar (añadir `disabled`), añadir spinner pequeño (`<span class="spinner-border spinner-border-sm me-1">` oculto inicialmente o crear dinámico), re-habilitar en `.then`/rescate. Solo si JSON request. No interfiere con fallback no-JS. | Evita dobles envíos. Se re-habilita tras respuesta. No rompe comportamiento existente. | Revisar JS, tests que usan rutas AJAX (`AsyncFormActionsTest`, `ControllerActionsTest`). Suite parcial AJAX. | Bajo (0.3h) | Bajo | P0 |
| QW-4 | Focus visible coherente (:focus-visible) | `public/assets/css/app.css` | Añadir bloque pequeño: `.btn:focus-visible, .form-control:focus-visible, .form-select:focus-visible, .app-dd-btn:focus-visible, summary:focus-visible` con ring sutil (outline + offset) usando colores existentes. No tocar Bootstrap defaults innecesarios. | Focus visible claro por teclado, sin romper estética. CSS válido. | `php -l` (no aplica), lint visual; `php scripts/test.php --filter ThemeFontCssInjectionTest` (no debe afectar). Suite general. | Muy bajo (0.15h) | Muy bajo | P0 |
| QW-5 | Mejorar filas tabla roster (alineación) | `public/assets/css/app.css` (o añadir utilidades en contexto) | Añadir regla para tabla en panel participantes: `.vf-tabs .table td, .vf-tabs .table th { vertical-align: middle; padding-block: .5rem; }`. Acotado a `.vf-tabs` para no afectar otras tablas. | Filas más legibles, acciones alineadas. No afecta estructura HTML. | CSS válido. `ViewStructureTest` sin cambios. | Muy bajo (0.1h) | Muy bajo | P1 |

## 2. Alto Valor — UX (0.5–1.5h c/u)

| ID | Ítem | Archivos | Cambios | Criterio de aceptación | Verificación | Esfuerzo | Riesgo | Prioridad |
|---|---|---|---|---|---|---|---|---|
| HV-1 | Estados vacíos más completos (microcopy claro) | `_roster_list.php`, `_roster_invites.php`, `_roster_catalog.php` | Refinar texto: explicar acción (ej. "No hay participantes aún. Añade uno a la lista o importa desde Excel."). Mantener minimalista, sin CTA invasivo (respetar jerarquía existente). | Legible, útil sin ruido. No modifica IDs/clases usados por JS. | `php -l`, estructura OK, `RosterPanelTest`. | Bajo (0.3h) | Muy bajo | P0 |
| HV-2 | Mejorar feedback flashes/alertas | Revisar vistas con alertas (auth, dashboard, fill). CSS opcional. | Añadir icono sutil (FontAwesome) en alertas existentes si procede, mantener minimalista. No crear nuevos componentes complejos. | Más claro sin romper diseño. No cambia contratos. | `php -l`, `ControllerActionsTest` rutas básicas. | Bajo (0.25h) | Muy bajo | P1 |
| HV-3 | Transiciones sutiles (hover/focus) | `public/assets/css/app.css` | Añadir `transition: background-color .15s ease, border-color .15s ease, color .15s ease;` acotado a `.btn`, `.app-dd-btn`, `.list-group-item-action` (si existe). Sin animaciones decorativas. | Feedback táctil sutil, no distrae. Performance neutro. | CSS válido. No rompe tests visuales/estructura. | Muy bajo (0.1h) | Muy bajo | P1 |

## 3. Accesibilidad (A11y) — Medio-Alto (0.2–0.5h)

| ID | Ítem | Archivos | Cambios | Criterio de aceptación | Verificación | Esfuerzo | Riesgo | Prioridad |
|---|---|---|---|---|---|---|---|---|
| A11Y-1 | aria-live en slots (refuerzo) | `views/dashboard/forms/show.php` | Añadir `aria-atomic="true"` junto a `aria-live="polite"`/`role="status"` en `#vfSlotParticipants` y `#vfSlotAccess` para asegurar lectura completa del bloque repintado. No alterar patrón de test. | Lectura completa en lectores. Mantiene compatibilidad con contratos existentes. | Verificar no rompe regex de `RosterPanelTest`. `ViewStructureTest`. | Muy bajo (0.05h) | Muy bajo | P0 |
| A11Y-2 | Revisar contraste (.text-secondary, .app-setting-note) | `public/assets/css/app.css` | Evaluar opacidad de `.text-secondary` y `.app-setting-note`. Si necesario, aumentar ligeramente contraste (sin cambiar nombres de clases). Ajuste mínimo conservador. | Mejora legibilidad AA sin alterar estética minimalista. | CSS válido. Sin cambios funcionales. | Muy bajo (0.15h) | Muy bajo | P1 |
| A11Y-3 | Mejorar hit area en elementos interactivos | `public/assets/css/app.css` | Verificar botones/summary. Ya corregido (`inline-flex`, min-height). Añadir mínimo `min-height`/`min-width` solo si detectado < 44px, acotado y conservador. | Mejor usabilidad táctil, mantiene densidad. | CSS válido. | Muy bajo (0.1h) | Muy bajo | P1 |

## 4. Pulido UI/Densidad (0.1–0.4h)

| ID | Ítem | Archivos | Cambios | Criterio de aceptación | Verificación | Esfuerzo | Riesgo | Prioridad |
|---|---|---|---|---|---|---|---|---|
| UI-1 | Espaciado entre bloques Roster | `_roster_panel.php` | Añadir `mt-3` sutil entre secciones (invitaciones/contadores/tabla) cuando corresponde. No reestructurar, solo utilidades Bootstrap existentes. | Mejor respiración sin romper estructura/tabs. | `php -l`, `ViewStructureTest`, `RosterPanelTest`. | Muy bajo (0.1h) | Muy bajo | P2 |
| UI-2 | Afinar padding vertical inputs-sm/selects-sm (ya hecho) | `public/assets/css/app.css` | Verificar estado actual. Solo ajustes mínimos si persiste recorte (<1px). No cambiar tokens globales. | Texto centrado sin romper alturas unificadas. | CSS válido. | Muy bajo (0.05h) | Muy bajo | P2 |
| UI-3 | Microajustes en `.app-setting` (espaciados notas) | `public/assets/css/app.css` | Revisar `.app-setting-note` (margin-top). Ajuste conservador si aporta legibilidad. | Mejor lectura, mantiene alineación switch. | CSS válido. `FormSettingsTest` sin impacto. | Muy bajo (0.05h) | Muy bajo | P2 |

## 5. Arquitectura/Mantenibilidad (Documentación) — 0.2–0.5h

| ID | Ítem | Archivos | Cambios | Criterio de aceptación | Verificación | Esfuerzo | Riesgo | Prioridad |
|---|---|---|---|---|---|---|---|---|
| DOC-1 | Añadir nota de alineación botones en AGENTS.md | `AGENTS.md` | Añadir breve recordatorio (1–2 líneas) sobre usar `inline-flex`, `align-items:center`, `gap`, `line-height:1` al tocar `.btn/.btn-sm` o `<summary>`. No duplicar reglas existentes. | Guía clara para evitar regresión. Formato coherente con AGENTS.md. | Lectura/estilo OK. | Muy bajo (0.05h) | Muy bajo | P2 |
| DOC-2 | Registrar hallazgos en changelog interno (docs) | Crear `docs/auditoria-uiux-2026-10-07.md` (resumen + referencias) | Generar documento conciso con resumen, puntuaciones, quick wins y estado de cierre. No modificar roadmap existente salvo referencia. | Documento útil, trazable. No rompe otros docs. | Sintaxis markdown. | Bajo (0.25h) | Muy bajo | P2 |


## 6. Orden de ejecución recomendado

Ejecutar por lotes, con validación tras cada lote:

### Lote A — Críticos UX/A11y (Quick Wins inmediatos)
1. **QW-1** (estados vacíos roster) → validar estructura + RosterPanel
2. **QW-2** (aria-live) → verificar no rompe regex de test
3. **QW-3** (loading state AJAX) → probar rutas AJAX existentes
4. **QW-4** (focus-visible) → CSS válido
5. **A11Y-1** (aria-atomic) → coherente con QW-2

**Validación lote A:** `php -l` archivos PHP; `php scripts/test.php --filter "testNingunaVistaDejaEtiquetasSinCerrar|testElListadoNoViveEnLaTarjetaDeAcceso"`; tests relacionados RosterPanel/AJAX; suite rápida.

### Lote B — Pulido visual
6. **QW-5** (filas tabla middle) + **HV-3** (transiciones) + **UI-1** (mt-3 roster)
7. **A11Y-2/A11Y-3** si procede (contraste/hit area)

**Validación lote B:** CSS válido, `ViewStructureTest` OK.

### Lote C — Refinamientos + Documentación
8. **HV-1/HV-2** microcopy/alertas (conservadores)
9. **DOC-1/DOC-2**

**Validación final:** Suite completa `php scripts/test.php` → **1040 tests, 0 fallidos, 0 errores**. Validación sintaxis PHP/CSS global.

## 7. Reglas de ejecución estrictas

- **Nunca romper estructura**: vfSlotParticipants **sin** card wrapper (respeta regex del test). No añadir IDs nuevos a elementos que tests buscan.
- **Bimodal intacto**: No modificar lógica JSON→HTML (`html[id]=markup`). Mantener fallback sin JS.
- **CSS acotado**: Usar scopes (`.vf-tabs .table`, bloques específicos). Evitar cambios globales agresivos.
- **ARIA no intrusivo**: Añadir atributos sin alterar layout visible.
- **Loading state cuidadoso**: Solo en solicitudes JSON (comprobar `X-Requested-With`). Re-habilitar SIEMPRE en finally-like (then+rescate).
- **Conservador con contraste**: Ajustes mínimos, preferir opacidad sutil.
