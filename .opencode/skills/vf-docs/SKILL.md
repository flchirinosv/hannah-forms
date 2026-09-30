---
name: vf-docs
description: Mapa de la documentación de VanillaForms (arquitectura, modelo de datos, decisiones, roadmap, auditoría) y qué documento leer según la tarea. Úsalo antes de proponer cambios que toquen el esquema, la lógica de llenado, el builder o los permisos.
---

# Documentación de VanillaForms

Los documentos de `docs/` son largos y a veces están desactualizados a propósito
(las auditorías son histórico cerrado). Este skill es el índice: **no te los cargues
todos**, elige el que corresponda y léelo entero solo si la tarea lo pide.

## Choosing the right document

| Si la tarea toca… | Lee |
|---|---|
| Arquitectura, request lifecycle, modelo de datos, tablas | `docs/technical-document.md` |
| Por qué una decisión se tomó así, historia del proyecto | `docs/decisiones.md` |
| Builder visual: tokens, componentes, reglas de UI | `docs/design.md` |
| Qué fases hay hechas y cuáles faltan (P1-P10, Tiers) | `docs/roadmap-encuesta.md` |
| Hallazgos de auditoría ya cerrados (no rehacer) | `docs/audit-report.md`, `docs/audit-report-2026-09-25.md`, `docs/fix-plan.md` |
| Qué se corrigió y por qué (P1-P10) | `docs/plan-remediacion-2026-09-27.md` |
| Posicionamiento frente a SaaS y open-source | `docs/posicionamiento-comparativa.md` |

## Trampas de los documentos (verificadas)

- **`docs/technical-document.md` §4 está DESACTUALIZADA a propósito**: describe
  6 de las 15 tablas reales y omite `submissions.public_token`. Para el modelo de
  datos fíjate en `database/schema.sql`, que es la fuente de verdad, no en §4.
- **`docs/api-spec.md` es OBSOLETA**: le faltan rutas nuevas y dice contraseña ≥6 y
  CSP opcional (hoy `PASSWORD_MIN` es 10 y la CSP es estricta). Para endpoints usa
  `config/routes.php`.
- **Los `audit-report*`, `fix-plan.md` y `levantamiento_informacion.md` son
  históricos cerrados**: no son una lista de pendientes, ya se aplicó lo que
  propusieron.
- **`.opencode/` no es código de la app.** `freddy/`, `freddy.zip` y `pub.jar` son un
  toolkit externo y están en `.gitignore`; no son código nuestro ni se tocan.

## Si la tarea es una evaluación de una propuesta

Antes de tabular "ya existe / falta", **verifica en el código**, no en los docs:

- Rutas y middleware: `config/routes.php`.
- Permisos: `config/permissions.php` (fuente) **y** `database/seed.sql` (copia);
  los dos tienen que tocarse juntos o falla `PermissionSeedParityTest`.
- Columnas reales: `database/schema.sql` y `database/migrations/`.
- Comportamiento: `app/Services/` y el controlador implicado.

Un documento puede afirmar paridad con un competidor que la realidad ya cambió
(por ejemplo, Turnstile se implementó en P7 pero alguna comparativa lo sigue
listando como brecha). El código manda.
