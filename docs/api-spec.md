# API de VanillaForms

Referencia de endpoints HTTP. Todo en español, formato interno (sin RFC oficial).
Autenticación por cookie de sesión (`SESSION_NAME`); para endpoints JSON (builder y
`copyLink`) el token CSRF viaja en el header `X-CSRF-Token`. La fuente de verdad
de las rutas es `config/routes.php`: el middleware está en la definición de la ruta.

Convenciones:
- `{int}` / `{slug}`: segmentos de ruta; `{int}` llega al controlador como `string`
  y `Router::coerceParam()` lo valida (404 si no encaja).
- Respuestas de error: JSON `{"ok": false, "error": "..."}` en endpoints JSON;
  páginas de error HTML (`errors/{code}`) en el resto.
- Fechas de filtro: `YYYY-MM-DD`.

## Autenticación

| Método | Ruta | Middleware | Descripción |
|--------|------|------------|-------------|
| GET | `/login` | guest | Página de login |
| POST | `/login` | guest, csrf | Iniciar sesión. 302 → `/dashboard`. Rate limit: 5 IP / 10 email cada 5 min |
| GET | `/register` | guest | Página de registro |
| POST | `/register` | guest, csrf | Registrar usuario (rol `user`). 302 → `/dashboard`. Rate limit: 10 IP cada 10 min |
| POST | `/logout` | auth, csrf | Cerrar sesión |
| GET | `/forgot-password` | guest | F2: pedir recuperación (respuesta uniforme, exista o no el email) |
| POST | `/forgot-password` | guest, csrf | F2: genera token (hash sha256) y envía el correo con el enlace |
| GET | `/reset-password` | — | F2: formulario del enlace (SIN middleware `guest`: el enlace se abre con sesión iniciada); params `?token=` y `?email=` |
| POST | `/reset-password` | csrf | F2: cambia la contraseña y cierra la sesión del navegador que lo completó |
| GET | `/account/password` | auth | F2: cambiar la contraseña propia |
| POST | `/account/password` | auth, csrf | F2: modifica `password_changed_at` y refresca la sesión |

Campos login: `email`, `password`, `_token`.
Campos register: `name`, `email`, `password`, `password_confirm`, `_token`.
Longitud mínima de contraseña: **10** (`Auth::PASSWORD_MIN`), en los tres sitios (login, registro y recuperación). Máximos: name ≤ 100, email ≤ 190.

## Dashboard

| Método | Ruta | Middleware | Descripción |
|--------|------|------------|-------------|
| GET | `/` | auth | Redirige/despliega el dashboard |
| GET | `/dashboard` | auth | Lista de formularios del usuario (o todos si es admin) + stats |

## Formularios

| Método | Ruta | Middleware | Descripción |
|--------|------|------------|-------------|
| POST | `/forms` | auth, csrf | Crear (body `title`, `description`; `settings` opcional). 302 → builder. Título ≤ 190. Rate limit `dash:create` por usuario |
| GET | `/forms/{int}` | auth | Detalle del formulario + ficha (permite editar la allowlist F4) |
| POST | `/forms/{int}/update` | auth, csrf | Actualizar título/descripción |
| POST | `/forms/{int}/settings` | auth, csrf | Ajustes **operativos** (acceso, agenda, cupo, avisos, webhook, mensaje final, revisión, idioma). Fusiona con `FormSettings::merge()` y solo escribe `FormSettings::DASHBOARD_BLOCKS` |
| POST | `/forms/{int}/delete` | auth, csrf | Eliminar formulario y respuestas |
| POST | `/forms/{int}/publish` | auth, csrf | Cambiar estado a `published` |
| POST | `/forms/{int}/close` | auth, csrf | Cambiar estado a `closed` |
| POST | `/forms/{int}/duplicate` | auth, csrf | Duplica formulario con campos y settings normalizados |
| GET | `/forms/{int}/qr.svg` | auth | QR público del enlace (sale de `PublicUrl`) |
| GET | `/forms/{int}/print` | auth | Vista imprimible |
| GET | `/forms/{int}/export-structure.json` | auth | Estructura (título + campos) listos para reimportar |

### Reparto de `forms.settings` entre las dos puertas

`normalize()` arranca de `defaults()`, así que **ninguna** de las dos puertas
manda el array entero: cada una manda un parche y el servidor lo fusiona con lo
que había (`FormSettings::merge()`). Las dos listas son complemento y cubren
todos los bloques; `FormSettingsMergeTest` lo ata.

| Puerta | Bloques (`FormSettings::*_BLOCKS`) |
|--------|-------------------------------------|
| `POST /forms/{int}/builder/save` | `theme`, `quiz`, `randomize`, `advance`, `embed`, `honeypot`, `anti_bot` |
| `POST /forms/{int}/settings` | `access`, `schedule`, `quota`, `notify`, `webhook`, `thanks`, `review`, `locale` |

El constructor manda el JSON tal cual (`FormSettings::BUILDER_BLOCKS` es lo que
recorta); la ficha traduce campos planos (`access_mode`, `open_at`…) con
`fromDashboardPost()`, donde **el ausente y el vacío no son lo mismo**: una
casilla desmarcada solo envía `0` si delante hay un `<input type=hidden>` con el
mismo `name`.

Acceso efectivo por permisos (RBAC), no por `role_name`: editar = `forms.edit_own`
(dueño) o `forms.manage_all`.

## Revisión (F3)

| Método | Ruta | Middleware | Descripción |
|--------|------|------------|-------------|
| POST | `/forms/{int}/review/send` | auth, csrf | Mandar a revisión (`draft|published` → `in_review`) |
| POST | `/forms/{int}/review/approve` | auth, csrf | Aprobar (`in_review` → `published`). Exige permiso `forms.review`, no `canEdit` |
| POST | `/forms/{int}/review/return` | auth, csrf | Devolver a borrador (`in_review` → `draft`) |
| GET | `/review` | auth | Bandeja de revisión (formularios en `in_review`) |

## Builder

| Método | Ruta | Middleware | Descripción |
|--------|------|------------|-------------|
| GET | `/forms/{int}/builder` | auth | Editor visual (drag & drop, secciones, lógica, settings) |
| POST | `/forms/{int}/builder/save` | auth, csrf-header | Guardar estructura completa (JSON). **413 si excede `MAX_JSON_BYTES`** (2 MiB default) |

Body JSON:
```json
{
  "title": "Encuesta",
  "description": "…",
  "status": "published",
  "settings": { "advance": { "auto": true } },
  "fields": [
    { "_uid": "field-1", "type": "section", "label": "Sección A" },
    { "_uid": "field-2", "type": "text", "label": "Nombre",
      "parent_id": "field-1", "is_required": true, "is_pii": true,
      "placeholder": "…", "help_text": "…"
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
etiquetas > 190 / help > 255, título > 190); `500` error interno. Con revisión activa,
`status` se ignora (solo `ReviewController` mueve el estado) y guardar un formulario
aprobado lo devuelve a la cola.

## Acceso por email (F4)

| Método | Ruta | Middleware | Descripción |
|--------|------|------------|-------------|
| POST | `/f/{slug}/access` | csrf | Pedir acceso (403 si el formulario está en `email`/`allowlist` sin token). Respuesta uniforme |
| POST | `/f/{slug}/access/embed` | csrf | Ídem para el iframe de embed |
| POST | `/forms/{int}/access/email` | auth, csrf | Añadir un email a la allowlist (modo `allowlist`) |
| POST | `/forms/{int}/access/email/remove` | auth, csrf | Quitar email y revocar sus tokens (transacción) |
| POST | `/forms/{int}/access/link` | auth, csrf-header | Emitir/copiar enlace de acceso para un email de la lista (JSON) |

## Llenado público

| Método | Ruta | Middleware | Descripción |
|--------|------|------------|-------------|
| GET | `/f/{slug}` | — | Muestra el formulario (páginas `closed`/`draft`/`in_review` si aplica; 403 si `email`/`allowlist` sin acceso) |
| POST | `/f/{slug}` | post-max, csrf | Enviar respuestas. **Rate limit: 10 por IP+formulario cada 10 min (429)**. El 403 de acceso va ANTES del `post-max` y del `csrf` |
| GET | `/f/{slug}/thanks` | — | Gracias, identificado por `submissions.public_token` (`?t=`), nunca por el `id` |
| GET | `/f/{slug}/embed` | — | Embebible (solo con `settings.embed.enabled`) |

Campos: `_token` + `field_{id}` por pregunta. Los campos ocultos por lógica condicional
no se validan ni persisten (decisión autoritativa del servidor). Errores de validación
re-renderizan la vista con `errors` y `old`. Honeypot `_vf_hp` antes del rate limit y
de Turnstile; Turnstile solo con las DOS claves de instancia puestas.

## Respuestas (dashboard)

| Método | Ruta | Middleware | Descripción |
|--------|------|------------|-------------|
| GET | `/forms/{int}/responses` | auth | Listado paginado (15/página) |
| GET | `/forms/{int}/responses/{sid}` | auth | Detalle de una respuesta |
| POST | `/forms/{int}/responses/{sid}/delete` | auth, csrf | Borrar una respuesta (purga de adjuntos en dos fases) |
| GET | `/forms/{int}/export.json` | auth | Todas las respuestas del rango como JSON. Rate limit `dash:export` |

## Analítica

| Método | Ruta | Middleware | Descripción |
|--------|------|------------|-------------|
| GET | `/forms/{int}/analytics?from=YYYY-MM-DD&to=YYYY-MM-DD` | auth | KPIs, sparkline, detalle paginado |
| GET | `/forms/{int}/analytics/rows` | auth | Página del detalle (AJAX, paginación server-side) |
| GET | `/forms/{int}/export.csv` | auth | CSV descargable (respecta filtro de fechas; PII enmascarada sin `responses.pii`) |

## Adjuntos

| Método | Ruta | Middleware | Descripción |
|--------|------|------------|-------------|
| GET | `/attachments/{int}` | auth | Descarga de un adjunto (`answers.id`+`?i=N`); 403 si el campo es PII sin permiso. Miniatura `?thumb=1` (image/webp) |

`/attachments/{id}` es adivinable por el id correlativo: el gate de PII vive aquí, no en la vista.

## Administración

| Método | Ruta | Middleware | Descripción |
|--------|------|------------|-------------|
| GET | `/admin` | auth, admin | Gestión de usuarios |
| POST | `/admin/users/role` | auth, admin, csrf | Cambiar rol (no permite auto-degradar admin) |
| POST | `/admin/users/groups` | auth, admin, csrf | Grupos de un usuario (`groups[]` = lista completa; vacíos lo sacan de todos) |
| POST | `/admin/users/toggle` | auth, admin, csrf | Activar/desactivar usuario (no sobre sí mismo) |
| GET | `/admin/forms` | auth, admin | Lista global de formularios |
| GET | `/admin/groups` | auth, admin | Grupos de roles (RBAC) |
| POST | `/admin/groups/create` | auth, admin, csrf | Crear grupo |
| POST | `/admin/groups/update` | auth, admin, csrf | Roles y miembros del grupo; `name`/`description` ausentes se conservan |
| POST | `/admin/groups/delete` | auth, admin, csrf | Borrar grupo |
| GET | `/admin/roles` | auth, admin | Matriz de permisos por rol |
| POST | `/admin/roles/create` | auth, admin, csrf | Crear rol (nace sin permisos) |
| POST | `/admin/roles/save` | auth, admin, csrf | Guardar la matriz (`perms[<role>][]`; admin conserva `admin.access`) |
| GET | `/admin/security-log` | auth, admin | Auditoría de eventos |

## Códigos de error

| Código | Significado | Uso |
|--------|-------------|-----|
| 400 | Solicitud inválida | validaciones de formulario, payloads mal formados |
| 403 | Acceso denegado | roles, formulario no publicado (`draft`/`in_review`), modo `email`/`allowlist` sin token, adjunto PII |
| 404 | No encontrado | rutas inexistentes, ids/slugs que no casan |
| 405 | Método no permitido | ruta con otro método registrado (header `Allow`) |
| 413 | Cuerpo excesivo | builder/save y llenado con archivos (`post-max`) |
| 419 | CSRF inválido | token expirado o ausente |
| 422 | Estructura inválida | builder/save |
| 429 | Rate limit excedido | login/register/submit/access/export |

## Cabeceras de seguridad

Enviadas desde `public/index.php`:
- `X-Frame-Options` — `DENY` (default), `SAMEORIGIN` o vacío vía `FRAME_OPTIONS`
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Content-Security-Policy` — default **seguro en código** `script-src 'self'` (no hay
  `<script>` inline ni `on*=`; para desactivarla hay que ponerla vacía a mano). El fill
  con Turnstile la relaja (añade el origen de Cloudflare a `script-src`/`frame-src`).