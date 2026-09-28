# API de VanillaForms

Referencia de endpoints HTTP. Todo en español, formato interno (sin RFC oficial).
Autenticación por cookie de sesión (`SESSION_NAME`); para endpoints JSON (builder) el
token CSRF viaja en el header `X-CSRF-Token`.

Convenciones:
- `{int}` / `{slug}`: segmentos de ruta
- Respuestas de error: JSON `{"ok": false, "error": "..."}` en endpoints JSON; páginas de
  error HTML (`errors/{code}`) en el resto.
- Fechas de filtro: `YYYY-MM-DD`.

## Autenticación

| Método | Ruta | Middleware | Descripción |
|--------|------|------------|-------------|
| GET | `/login` | guest | Página de login |
| POST | `/login` | guest, csrf | Iniciar sesión. Redirige a `/dashboard`. Rate limit: 5 IP / 10 email cada 5 min |
| GET | `/register` | guest | Página de registro |
| POST | `/register` | guest, csrf | Registrar usuario (rol `user`). Redirige a `/dashboard`. Rate limit: 10 IP cada 10 min |
| POST | `/logout` | auth, csrf | Cerrar sesión |

Campos login: `email`, `password`, `_token`.
Campos register: `name`, `email`, `password`, `password_confirm`, `_token`.
Longitudes: name ≤ 100, email ≤ 190, password ≥ 6.

## Dashboard

| Método | Ruta | Middleware | Descripción |
|--------|------|------------|-------------|
| GET | `/` | auth | Redirige/despliega el dashboard |
| GET | `/dashboard` | auth | Lista de formularios del usuario (o todos si es admin) + stats |

## Formularios

| Método | Ruta | Middleware | Descripción |
|--------|------|------------|-------------|
| POST | `/forms` | auth, csrf | Crear formulario (body `title`, `description`). 302 → `/forms/{id}/builder`. Título ≤ 190 |
| GET | `/forms/{int}` | auth | Detalle del formulario (propietario/admin/editor) |
| POST | `/forms/{int}/update` | auth, csrf | Actualizar título/descripción |
| POST | `/forms/{int}/delete` | auth, csrf | Eliminar formulario y respuestas |
| POST | `/forms/{int}/publish` | auth, csrf | Cambiar estado a `published` |
| POST | `/forms/{int}/close` | auth, csrf | Cambiar estado a `closed` |

Reglas de acceso: propietario, `admin` o `editor` editan; el borrado solo propietario/admin.

## Builder

| Método | Ruta | Middleware | Descripción |
|--------|------|------------|-------------|
| GET | `/forms/{int}/builder` | auth | Editor visual (drag & drop, secciones, lógica) |
| POST | `/forms/{int}/builder/save` | auth, csrf-header | Guardar estructura completa (JSON). **413 si excede `MAX_JSON_BYTES`** (2 MiB default) |

Body JSON:
```json
{
  "title": "Encuesta",
  "description": "…",
  "status": "published",
  "fields": [
    { "_uid": "field-1", "type": "section", "label": "Sección A" },
    { "_uid": "field-2", "type": "text", "label": "Nombre",
      "parent_id": "field-1", "is_required": true, "placeholder": "…", "help_text": "…"
    },
    { "_uid": "field-3", "type": "select", "label": "Feedback",
      "options": [{"value": "ok", "label": "Bien"}],
      "logic": { "operator": "all", "rules": [{"field_id": "field-2", "op": "filled"}] }
    }
  ]
}
```

Respuesta: `200 {"ok": true}`; `419` token header inválido; `413` sobre límite;
`422` estructura inválida (tipos no permitidos, sección mal anidada, ciclo de lógica,
etiquetas > 190 / help > 255, título > 190); `500` error interno (sin detalles al cliente).
La lista de tipos permitida (`allowedTypes`) coincide con `typeInfo` de `builder.js`.

## Llenado público

| Método | Ruta | Middleware | Descripción |
|--------|------|------------|-------------|
| GET | `/f/{slug}` | — | Muestra el formulario (páginas `closed`/`draft` si aplica) |
| POST | `/f/{slug}` | csrf | Enviar respuestas. **Rate limit: 10 por IP+formulario cada 10 min (429)** |

Campos: `_token` + `field_{id}` por pregunta. Los campos ocultos por lógica condicional
no se validan ni persisten (decisión autoritativa del servidor). Errores de validación
re-renderizan la vista con `errors` y `old`.

## Analítica

| Método | Ruta | Middleware | Descripción |
|--------|------|------------|-------------|
| GET | `/forms/{int}/analytics?from=YYYY-MM-DD&to=YYYY-MM-DD` | auth | KPIs, gráfico diario, detalle de respuestas |
| GET | `/forms/{int}/export.csv?from=YYYY-MM-DD&to=YYYY-MM-DD` | auth | CSV descargable; respeta el filtro de fechas |

Acceso: propietario, admin o editor.

## Administración

| Método | Ruta | Middleware | Descripción |
|--------|------|------------|-------------|
| GET | `/admin` | auth, admin | Gestión de usuarios |
| POST | `/admin/users/role` | auth, admin, csrf | Cambiar rol (no permite auto-degradar admin) |
| POST | `/admin/users/toggle` | auth, admin, csrf | Activar/desactivar usuario (no sobre sí mismo) |
| GET | `/admin/forms` | auth, admin | Lista global de formularios |

## Códigos de error

| Código | Significado | Uso |
|--------|-------------|-----|
| 400 | Solicitud inválida | — |
| 403 | Acceso denegado | roles/estado no publicado |
| 404 | No encontrado | rutas inexistentes |
| 405 | Método no permitido | ruta con otro método registrado (header `Allow`) |
| 413 | Cuerpo excesivo | builder/save |
| 419 | CSRF inválido | token expirado |
| 429 | Rate limit excedido | login/register/submit |
| 500 | Error interno | sin detalles al cliente |

## Cabeceras de seguridad

Emiadas desde `public/index.php`:
- `X-Frame-Options` — `DENY` (default), `SAMEORIGIN` o vacío vía `FRAME_OPTIONS`
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Content-Security-Policy` — opcional vía `CSP_POLICY` (vacío = desactivada)