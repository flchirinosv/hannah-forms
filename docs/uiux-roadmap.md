# Roadmap UI/UX y cierre de brechas — VanillaForms

**Fecha:** 2026-09-28
**Referencia:** la matriz de brechas vive en [docs/posicionamiento-comparativa.md](posicionamiento-comparativa.md).
**Decisión adoptada:** prioridad UI/UX; ítems realistas y cerrables en vanilla PHP/JS (sin
Composer, CSP `script-src 'self'`, tests siempre verdes).

## Matriz de cobertura (qué cierra cada punto)

| Brecha (de la comparativa) | Puntos que la atacan | Estado |
|---|---|---|
| UX/atractivo del flujo (Typeform/Jotform, polish móvil) | Q1, Q2, V1, V2 | Q1/Q2/Q3 HECHOS (estado de submit, est. de minutos). La brecha grande solo con **Tier 3 · modo foco** |
| Multilingüe (Typeform/Tally multi) | Q6 `?lang` + Accept-Language | Q6 HECHO (es/en por request o navegante); no añade idiomas |
| Anti-bot (reCAPTCHA/Turnstile) | B2 Turnstile opt-in | Cubierta si se ejecuta el Track B |
| Integraciones/no-code (Zapier/Sheets/Slack/CRM) | B1 Slack webhook + firma/retry en `Webhook` | Parcial: cubre el caso Slack e integrables por webhook; OAuth/CRMs fuera |
| Analítica / exploración de datos | Q5 presets de rango, V5 sparklines | Parcial (exploración); Qualtrics-class fuera |
| Plantillas de la comunidad | V6 galería con mini-preview | Parcial: UX del selector; el *marketplace* de la comunidad no |
| IA (generación/análisis) | — | Fuera de alcance |
| Investigación avanzada (conjoint/maxdiff/panel) | — | Fuera de alcance |
| Comercio/pagos (Jotform + Stripe) | — | Fuera de alcance |
| Colaboración real-time | — | Fuera de alcance |
| Idiomas extra (pt/fr/…) | — | Fuera de alcance (decisión de producto) |

## Tier 1 — Quick wins (≈0,5–1 d c/u, bajo riesgo)

| # | Ítem | Archivos | Estado / Notas |
|---|---|---|---|
| Q1 | Hero del formulario público: título/descripción con el tema (`themeCss`), "N preguntas · ~X min" | `views/fill/show.php`, `app.css` | HECHO · solo render |
| Q2 | Botón "Enviar" con estado: disable + spinner y foco/scroll al primer campo inválido | `fill.js`, `app.css` | HECHO · ya existía (submit disables + foco/scroll al primer error) |
| Q3 | Shortcuts de teclado en el builder: Ctrl+S guardar, Esc cerrar modal | `builder.js` | HECHO · ya existía (Ctrl+S/Ctrl+Z/Ctrl+D/Escape) |
| Q4 | Copiar enlace del formulario con toast (Clipboard API + fallback) | `dashboard.js` + vistas | HECHO · el botón del detalle estaba muerto (`js-copy-url` sin handler; `dashboard.js` hacía `return` sin `#dashGrid`); delegado a nivel documento + `btn-copy-link`/`data-url` |
| Q5 | Presets de rango en analytics (7/30/90 días, este mes) | `analytics.js`, `views/analytics/show.php` | HECHO · solo cliente, reenvía el GET |
| Q6 | Idiomas: override `?lang=` + detección `Accept-Language` en el fill público | `App\Core\I18n`, `FillController`, `views/fill/*.php` | HECHO · tests en `I18nTest`; campo oculto `lang` en el POST y `?lang=` en el PRG del thanks |

## Tier 2 — Alto valor (≈1–2 d c/u)

| # | Ítem | Archivos | Notas |
|---|---|---|---|
| V1 | Progreso real de completitud (% de obligatorios respondidos) en todos los modos + barra sticky en multi-paso | `fill.js`, `app.css` | HECHO | El wrap de progreso ya existe para `#vfProgressWrap` |
| V2 | Radios/checks como tarjetas táctiles (hit area ≥44px, selección en toda la tarjeta) | `views/fill/show.php`, `fill.js`, `app.css` | HECHO (CSS puro) | Gana en móvil y en campos largos |
| V3 | Vista previa en vivo en el builder (panel/modal renderizado desde el estado, read-only, con lógica) | `builder.js`, `app.css` | HECHO (ya existía) | Reutiliza el render de tarjetas del lienzo |
| V4 | Undo/redo en el builder (stack de snapshots del payload, debounce, Ctrl+Z/Y) | `builder.js` | HECHO (ya existía) | Estado en memoria; no carga extra al servidor |
| V5 | Sparkline de respuestas/día por formulario en el dashboard | query agregada (`Form`/`Submission`), `dashboard.js` | HECHO | SVG inline por card; `Submission::activityByForms()` con test |
| V6 | Galería de plantillas con mini-preview en el modal | `dashboard.js`, `views/dashboard/index.php` | HECHO | Render declarativo desde el JSON de plantilla (`types`) |

## Tier 3 — Apuesta documentada (NO por defecto en la próxima ronda)

| # | Ítem | Notas |
|---|---|---|
| B-F1 | Modo "foco" tipo Typeform opt-in por formulario (`settings.ux.mode=focus`): una pregunta por vista + navegación por teclado | La única que cierra de verdad la brecha UX; cambio grande (reusa la lógica multi-paso de `fill.js`) |
| B-F2 | Dark mode del panel + fill (`data-theme` en `:root`, `prefers-color-scheme` + toggle persistido) | Bajo riesgo, escribe al CSS |
| B-F3 | Drop-off por paso | Requiere tracking nuevo de "inicio" (dato): no cerrar sin una columna/evento |

## Track B — Cierre de brechas no-UI (realistas en vanilla)

| # | Ítem | Notas |
|---|---|---|
| B1 | Slack incoming-webhook por formulario + endurecer `Webhook` (firma HMAC opcional, 1 retry con backoff) | Cierra parte realista de "integraciones" sin OAuth; con tests del payload/firma |
| B2 | Turnstile opt-in por formulario (`settings.anti_bot`): verificación server-side del token | Requiere claves en `.env`; evolución natural del honeypot |

## Fuera de alcance (documentado, no estratégico para un self-hosted)

Pagos/Stripe, investigación avanzada (conjoint/MaxDiff/panel de SurveyMonkey), IA generativa,
colaboración real-time, idiomas extra (pt/fr) e integraciones OAuth (Sheets/CRM).

## Reglas transversales

- Cada ítem que toca controladores/modelos lleva su test; la suite debe seguir en verde (473+).
- Todo JS nuevo va en archivos externos; carga con `defer` salvo que sea crítico (CSP).
- No se introducen dependencias externas (ni Composer, ni npm, ni CDN).
- Commit por fase cerrada.