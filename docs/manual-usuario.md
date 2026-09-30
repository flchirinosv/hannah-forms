# Manual de usuario — VanillaForms

Manual de instalación y uso de la aplicación, escrito **contra el código**
(verificado en el commit `751602c`). Si algo de este manual y lo que ves en
pantalla no coinciden, el código manda: `config/routes.php` para las rutas,
`app/Services/FormSettings.php` para los ajustes y `config/permissions.php`
para los permisos.

Documento terminado: 2026-09-30.

---

## 1. Qué es esto

VanillaForms es un constructor de formularios públicos que se instala en tu
propio servidor. Crea un formulario, comparte su enlace y consulta las
respuestas. No es un SaaS: los datos no salen de tu máquina.

Tres ideas que aparecen todo el manual:

| Idea | Qué significa |
|---|---|
| **Borrador vs publicado** | Un formulario recién creado es un **borrador**: existe para ti y su enlace da 403. Nadie más lo ve hasta que lo publicas. |
| **Formulario propio** | Cada usuario ve sus propios formularios. Ver los de otros requiere un permiso aparte. |
| **Acceso por email** | Opcional. Por defecto el formulario es público para cualquiera que tenga el enlace. |

---

## 2. Requisitos

| Elemento | Versión mínima | Notas |
|---|---|---|
| PHP | **8.2** | Sin Composer, sin framework. Nada que instalar con `composer install`. |
| MariaDB | 10.x | Motor InnoDB, `utf8mb4_unicode_ci`. Las migraciones usan `CREATE INDEX IF NOT EXISTS`, sintaxis de **MariaDB**: en MySQL no existe. |
| Extensiones PHP | `pdo_mysql`, `mbstring`, `fileinfo` | `fileinfo` es la que valida el tipo real de los adjuntos. |
| Servidor web | cualquiera con PHP-FPM o similar | Apache, Nginx… El único PHP expuesto es `public/index.php`. |
| Espacio escribible | `storage/uploads/` | El servidor web debe poder escribir ahí para los adjuntos. |

Opcional: `gd` (para recomprimir imágenes WebP) y un servidor SMTP para los
correos.

---

## 3. Instalación

### 3.1 Desde cero

```bash
# 1. Configuración
cp .env.example .env
$EDITOR .env

# 2. Base de datos (crea la BD y las 16 tablas)
mysql < database/schema.sql

# 3. Roles y permisos (NO crea usuarios)
mysql < database/seed.sql

# 4. Primer administrador
ADMIN_PASSWORD=una-clave-larga php scripts/create-admin.php

# 5. Levantar para probar
php -S 127.0.0.1:8080 -t public
```

Sobre el paso 4: el script **rechaza** `--password=valor` a propósito, porque
esa contraseña quedaría en el historial del shell y en la lista de procesos.
Usa la variable de entorno, `--password-stdin` o el prompt sin eco que aparece
si no pasas nada.

`database/seed.sql` es idempotente: puedes volver a correrlo. **Nunca** crea
usuarios ni contraseñas — un hash de ejemplo en el repositorio es una
contraseña conocida aunque esté detrás de una confirmación.

### 3.2 Actualizar una instalación que ya tiene datos

```bash
php database/migrate.php --status   # qué falta
php database/migrate.php            # aplicar
```

No vuelvas a correr `schema.sql` sobre una base con datos.

### 3.3 Variables de configuración

Todo tiene un valor por defecto en el código, así que `.env` puede ser
mínimo. Las que de verdad importan:

| Variable | Por defecto | Para qué |
|---|---|---|
| `APP_URL` | `http://127.0.0.1:8080` | Dirección de la app. |
| `APP_PUBLIC_URL` | *(vacía)* | Si el formulario se sirve desde otro dominio (CDN, proxy), ponlo aquí. Vacía = se deduce del host de cada petición. |
| `TRUST_PROXY` | `false` | **Déjalo en `false`** si no hay proxy. En `true` la app confía en `X-Forwarded-For`, y es justo eso lo que permite saltarse el límite de envíos. |
| `APP_LOCALE` | `es` | Idioma de la interfaz: `es` o `en`. |
| `APP_TIMEZONE` | `America/Caracas` | **Solo presentación**. Las fechas se guardan en UTC; esta variable decide cómo se muestran. |
| `CSP_POLICY` | restringida | CSP con `script-src 'self'`. Para desactivarla hay que vaciarla a mano. |
| `APP_REDIRECT_ALLOWLIST` | *(vacía)* | Dominios a los que se permite redirigir tras enviar. **Vacía = ninguna redirección externa se sigue.** |
| `UPLOAD_MAX_SIZE_MB` | `10` | Tope de tamaño de adjuntos. El valor efectivo es el menor entre este y el de `php.ini`. |
| `UPLOAD_MAX_FILES` | `5` | Ídem con el número de archivos. |
| `DASH_CREATE_PER_HOUR` | `30` | Formularios que un usuario puede crear o duplicar por hora. |
| `DASH_EXPORT_PER_HOUR` | `120` | Exportaciones por hora y usuario. |
| `TURNSTILE_SITE_KEY` / `TURNSTILE_SECRET_KEY` | *(vacías)* | Cloudflare Turnstile. **Sin las dos**, un formulario con anti-bot activado no se sirve (403). |
| Bloque `MAIL_*` | vacío | SMTP. Con `MAIL_HOST` vacío los correos no se envían, pero la app **no se rompe**: es el modo por defecto en desarrollo. |

### 3.4 Scripts de mantenimiento

```bash
php scripts/test.php                     # suite completa (el único control de calidad)
php scripts/test.php --filter <método>   # un test; casa por NOMBRE DE MÉTODO
php scripts/clean-rate-limits.php --all  # vaciar contadores (pide confirmación)
php scripts/clean-uploads.php --dry-run  # ver adjuntos huérfanos, sin borrar nada
```

El `--all` de los rate limits pide confirmación; `--yes` la salta.

---

## 4. Cuentas, roles y permisos

### 4.1 Crear el primer usuario

Ya lo hiciste en el paso 4 con `create-admin.php`.

### 4.2 Registrarse

Hay un registro público en `/register`: **cualquiera que llegue al sitio puede
crear una cuenta** y entrar al panel. No hay casilla para desactivarlo y no hay
verificación por correo (una cuenta es activa desde el instante en que se
registra). Si tu instalación no es pública, bloquea esa ruta en el servidor web:

```nginx
location = /register { deny all; return 403; }
```

Quien llega sin sesión a un enlace de formulario público **no** necesita
cuenta: eso es distinto del panel.

### 4.3 Roles

| Rol | Para qué |
|---|---|
| `admin` | Todo, incluido el panel de administración. |
| `user` | Uso normal: sus formularios, respuestas, analítica y exportaciones. |
| `editor` | Igual que `user`, pero **sin** permiso de borrar sus propios formularios. |

Un rol nuevo se crea sin permisos: la matriz es la que decide qué puede hacer.
Los permisos efectivos de alguien son **su rol + los roles de sus grupos**, así
que puedes darle a un grupo entero una capacidad sin tocar a cada persona.

### 4.4 Los permisos que importan

| Permiso | Qué abre |
|---|---|
| `forms.create` | Crear y duplicar formularios. |
| `forms.edit_own` | Editar, publicar, cerrar y configurar los propios. |
| `forms.delete_own` | Borrar los propios (no lo tiene `editor`). |
| `forms.manage_all` | Ver y operar los formularios de cualquier usuario. |
| `forms.review` | **Aprobar o devolver** formularios enviados a revisión. |
| `forms.export_own` | Exportar datos. |
| `responses.view_own` | Ver y eliminar respuestas. |
| `responses.pii` | Ver los datos personales **sin** enmascarar (ver §9). |
| `attachments.download_own` | Descargar adjuntos. |
| `analytics.view_own` | Ver la analítica. |
| `admin.access` / `.users` / `.roles` / `.groups` | Entrar al panel y gestionarlo. |
| `logs.view` | Ver el registro de eventos de seguridad. |
| `dashboard.view` | Entrar al panel de usuario. |

Dato importante: **`forms.review` no es lo mismo que `forms.edit_own`.**
Poder editar tu propio formulario no te deja publicarlo si el formulario está
en revisión; hace falta `forms.review`. Existe para que nadie pueda publicar un
texto por su cuenta.

### 4.5 Recuperar la contraseña

En `/forgot-password` escribes tu email y recibes un enlace. El enlace es de
un solo uso y caduca. Si alguien cambia la contraseña, **todas las sesiones
abiertas de esa cuenta se cierran** en el siguiente paso por la web.

---

## 5. Crear un formulario

1. `/dashboard` → **Crear formulario**.
2. Ponle título y, si quieres, descripción.
3. Se crea como **borrador**: aún no lo ve nadie.
4. Ábrelo en el constructor y añádele campos.
5. **Guardar** (estructura) y luego **Publicar**.

Se pueden crear hasta 30 formularios por hora y usuario; duplicar también cuenta.

---

## 6. El constructor

El constructor tiene tres zonas:

- **Agrega elementos** (izquierda): los tipos de campo, para arrastrarlos al lienzo.
- **Lienzo** (centro): los campos ya colocados, reordenables.
- **Configuración** (derecha): los ajustes del campo seleccionado y, con las
  pestañas **Contenido** / **Lógica**, su comportamiento.

El botón **Guardar** (barra superior) persiste la estructura: campos, orden,
opciones y ajustes. El botón **Guardar configuración** (abajo) persiste los
ajustes de la pestaña abierta.

**Guardar es explícito.** El constructor no guarda solo: hasta que pulsas
**Guardar**, el servidor sigue con la estructura anterior. Cierra el navegador
con cambios sin guardar y **se pierden sin aviso** (no hay aviso de salida).
Guarda a menudo, y en forma larga antes de hacer otra cosa.

### 6.1 Tipos de campo

| Tipo | Para qué |
|---|---|
| Sección de preguntas | Agrupa preguntas en un bloque. Puede ocultarse entero por una condición. |
| Página (salto de paso) | Divide el formulario en pasos. |
| Texto | Respuesta corta. |
| Párrafo | Respuesta larga. |
| Email | Correo electrónico (se valida el formato). |
| Número | Valor numérico, con mínimo y máximo. |
| Desplegable | Elegir de una lista. |
| Opción única | Un solo radio. |
| Casillas | Varias casillas. |
| Fecha / Hora / Fecha y hora | Los formatos los pone el navegador. |
| Escala | Escala de 1 a N. |
| NPS | Net Promoter Score, de 0 a 10. |
| Estrellas | Calificación de 1 a 5 (o 1 a N). |
| Matriz | Varias filas de preguntas tipo escala Likert. |
| Ranking | Ordenar N opciones de la 1 a la N. |
| Rango | Control deslizante. |
| URL / Teléfono | Con validación de formato. |
| Archivo | Subir uno o varios archivos. |

**Sección** y **Página** son *estructurales*: no guardan respuesta y no pueden
ser la fuente de una condición.

### 6.2 Validaciones

Por tipo: obligatorio, mínimo y máximo (números y escalas), longitud mínima y
máxima (textos), formato (email, URL, teléfono), rango de la matriz, unicidad
de las posiciones del ranking, y para archivos: tamaño, número y tipo.

El navegador avisa antes de enviar, pero **la validación que decide es la del
servidor**: si algo se cuela, el envío se rechaza entero y no se guarda nada a
medio camino.

### 6.3 Lógica condicional

Pestaña **Lógica**. Una regla dice: *si el campo X cumple la condición, este
campo se muestra*. Los operadores disponibles dependen del tipo del campo
**referido**:

| Tipo del campo de origen | Operadores |
|---|---|
| Texto, párrafo | Igual a · Distinto de · Contiene · No contiene · Tiene valor · Está vacío · Coincide con (regex) |
| Desplegable, opción única, casillas | Incluye la opción · No incluye la opción · Igual a · Distinto de · Tiene valor · Está vacío |
| Número, fecha, hora | Mayor que · Mayor o igual · Menor que · Menor o igual · Igual a · Distinto de · Tiene valor · Está vacío |
| Escala, NPS, estrellas, matriz, rango, archivo | Igual a · Distinto de · Tiene valor · Está vacío · Coincide con (regex) |

Dos cosas que sorprenden:

- La lógica de un grupo es **Y** (se muestra solo si se cumplen su regla y la
  de su contenido).
- Si la condición de un campo **no** se cumple, el campo desaparece y **no se
  valida ni se guarda**, aunque alguien mande su valor a mano. No es
  cosmético.

### 6.4 Marcar campos como personales

En la configuración de un campo de texto, activa **datos personales**. Sirve
para que ese campo salga enmascarado (ver §9) para quien no tenga el permiso
`responses.pii`.

Actívalo en cuanto crees el campo: **sin esa marca no hay enmascarado posible**,
porque un campo de texto puede ser «¿Qué opinas?» o «Nombre de tu jefe», y la
app no puede adivinarlo.

---

## 7. Ajustes del formulario

Todos en la ficha del formulario (la pestaña **Configuración**), o en el
constructor. Grupos y valores por defecto:

| Grupo | Qué hace | Por defecto |
|---|---|---|
| Contenido | Título, descripción, texto del botón de envío | — |
| Tema | Color primario, color de fondo, tipografía (lista blanca) | Sin personalización |
| Comportamiento | Acceso, cupo, programación, embed, barajado | Público |
| Multipágina | Separadores de paso, avance automático | Sin avance automático |
| Confirmación | Mensaje de gracias, redirección | Sin redirección |
| Correo | Aviso al dueño, confirmación al que responde | Desactivado |
| Anti-bot | Honeypot, Turnstile | Honeypot **activado** |
| Integraciones | Webhook (genérico o Slack) con firma HMAC | Desactivado |
| Revisión | Revisión antes de publicar + aviso por correo | Desactivado |
| Analítica | Qué se guarda y durante cuánto | — |
| Idioma | `es` o `en` | `es` |

### 7.1 Honeypot

Un campo invisible que solo rellenan los robots. **Activo por defecto.** Si se
rellena, la app responde como si hubiera ido bien pero **no guarda nada**.

### 7.2 Turnstile

Opcional, de Cloudflare. Necesita `TURNSTILE_SITE_KEY` y
`TURNSTILE_SECRET_KEY` en `.env`. Sin las dos claves, un formulario con
anti-bot activo **no se sirve** (403), en lugar de degradar.

### 7.3 Webhook

URL a la que se envía cada respuesta en JSON. Dos cosas que no son evidentes:

- **La opción de red local exige los dos opt-ins**: `WEBHOOK_ALLOW_PRIVATE_HOSTS=true`
  en `.env` **y** «permitir hosts privados» en el formulario. Con uno solo no
  llega.
- El secreto firma el cuerpo con HMAC-SHA256; verifícalo en el receptor.

### 7.4 Redirección tras enviar

Solo se sigue una URL que esté en la lista de permitidos del servidor
(`APP_REDIRECT_ALLOWLIST`). **Vacía significa que ninguna URL externa se
sigue**, ni siquiera una que hayas puesto en el formulario. La redirección a
una URL vacía está bloqueada.

---

## 8. Publicar y compartir

### 8.1 Los cuatro estados

| Estado | Qué ven los demás |
|---|---|
| `draft` Borrador | Nada: 403. Es tu espacio de trabajo. |
| `in_review` En revisión | Una página informativa de que está en revisión (no un 404) y los envíos se rechazan con 403. |
| `published` Publicado | El formulario funciona. |
| `closed` Cerrado | Ya no se puede enviar. Las respuestas se conservan. |

Publicar y cerrar se hacen desde la ficha del formulario, no desde el
constructor. Hay tres acciones distintas y no se sustituyen:

| Acción | Qué hace |
|---|---|
| **Publicar** | Deja el formulario visible para cualquiera con el enlace. |
| **Cerrar** | Deja de aceptar envíos. Las respuestas se conservan. |
| **Enviar a revisión** | Manda el formulario a la bandeja de revisión. |

**Con revisión activada, «Publicar» no publica.** Se rechaza con un aviso que
te dice que lo envíes a revisión, en lugar de un 403 que no sabrías
interpretar. A partir de ahí el formulario solo se mueve con las tres acciones
de la bandeja (enviar, aprobar, devolver a borrador).

### 8.2 Compartir

Desde la ficha del formulario: **enlace**, **código QR** y **embed**. Los tres
salen de `APP_PUBLIC_URL` si está definido, y si no, del host de la petición.

El embed es un `<iframe>`, y **solo funciona si la página que lo inserta está
en el mismo dominio**: la ruta de embed envía `X-Frame-Options: SAMEORIGIN` y
limita `frame-ancestors` a `'self'`. Poner el iframe en un dominio distinto no
va a funcionar, y es a propósito (evita que cualquiera robe tu formulario). Si
necesitas insertarlo en otro sitio, usa el enlace.

También necesita el interruptor de embed activado en el formulario; sin él, la
ruta de embed responde 403.

---

## 9. Respuestas, analítica y exportación

### 9.1 Respuestas

Listado con filtros (fechas, estado) y detalle de cada envío. Desde el detalle
puedes **eliminar** una respuesta. **No se pueden editar**: una respuesta es un
registro de lo que alguien envió, no un borrador.

### 9.2 Analítica

Series por día, distribución por respuesta y desglose por campo. Si el
formulario tiene preguntas marcadas como personales, la analítica también las
enmascara.

La tabla de respuestas se recarga por AJAX al cambiar de página o de filtro. Si
alguna vez se queda «cargando» para siempre y no puedes abrir ninguna
respuesta, es un fallo de `analytics.js`: la clase `vf-loading` se quedaba
puesta porque solo se quitaba cuando la petición **fallaba**. Corregido en el
commit `751602c`; hay una prueba que lo vigila (R1).

### 9.3 Exportar

| Formato | Para qué | Nombre del archivo |
|---|---|---|
| **CSV** | Hoja de cálculo. Columnas: `#`, `Fecha` y una por campo. Las secciones no salen (no guardan respuestas). | `<slug>.csv` |
| **JSON** | Integración. Envoltura `{form, total, submissions[]}`. | `<slug>.responses.json` |
| **Estructura del formulario** | El JSON con campos y ajustes, para reprocesar. | — |

Se respeta el filtro de fechas activo. Límite: 120 exportaciones por hora y
usuario. La exportación se escribe en streaming: un formulario con muchas
respuestas no se come la memoria.

### 9.4 Adjuntos

Se descargan desde el detalle de la respuesta (`/attachments/{id}`). Tipos
aceptados: PDF, imágenes (jpg, jpeg, png, gif, webp), zip, texto (txt, md,
log, csv), json, documentos de Office (doc, docx, xls, xlsx, ppt, pptx, rtf,
odt, ods). El tipo se comprueba con `finfo` sobre el archivo real, no por la
extensión que dice el navegador, y hay una lista de extensiones ejecutables
rechazadas. Viven en `storage/uploads/`, **nunca** dentro de `public/`.

---

## 10. Datos personales (PII)

Marca los campos personales en el constructor. A partir de ahí, **cualquiera
que no tenga el permiso `responses.pii` ve los datos enmascarados**:

| Dato | Lo ve sin permiso |
|---|---|
| Dirección IP | `192.168.1.x` |
| User-agent | `[oculto]` |
| Campo marcado | `j***@e***.com` (email), prefijo (teléfono), `[oculto]` (resto) |
| Adjunto de un campo PII | **403**, no se descarga |

Se enmascara en los **cuatro** canales de salida: detalle, listado, exportación
JSON y exportación CSV. No solo en pantalla.

`responses.pii` es por defecto solo de `admin`. Si no tienes ese permiso y ves
`[oculto]`, el enmascarado está funcionando, no fallando.

---

## 11. Correos

Cinco plantillas: aviso de respuesta recibida, confirmación al que responde,
solicitud de acceso, recuperación de contraseña y aviso de revisión.

Dos comportamientos que conviene conocer:

- El aviso de nueva respuesta va al **dueño del formulario**, hasta 10
  destinatarios. El de confirmación usa un campo del propio formulario como
  destino.
- Sin SMTP configurado no se envía nada, pero **la respuesta del formulario se
  guarda igual**: el correo nunca decide si se guarda una respuesta.

El enlace de recuperación de contraseña sale de la URL pública, no de
`APP_URL`, para que funcione aunque el correo se envíe desde otra dirección.

---

## 12. Seguridad y operación

### 12.1 Lo que protege cada capa

| Capa | Qué hace |
|---|---|
| CSRF | Todos los POST del panel y del formulario público. |
| CSP | `script-src 'self'`: no hay scripts inline ni `onclick` en ninguna vista. |
| Límite de envíos | 10 envíos por IP cada 10 minutos y formulario. |
| Límite de login | 5 por IP y 10 por email cada 5 minutos. |
| Honeypot | Activo por defecto. |
| Registro de seguridad | Eventos relevantes en `/admin/security-log`. |
| Cabeceras | `X-Frame-Options: DENY` salvo que se cambie. |

### 12.2 Copias de seguridad

Hay dos cosas que respaldar, y **juntas**: la base de datos (respuestas,
usuarios, tokens) y `storage/uploads/` (adjuntos). Una copia de la base sin los
adjuntos conserva el texto de las respuestas pero pierde los archivos; una copia
de los adjuntos sin la base deja archivos sin saber a qué respuesta pertenecen.
Haz las dos en el mismo momento.

### 12.3 Limpieza

`scripts/clean-uploads.php` encuentra adjuntos huérfanos (los que se quedan
sin fila de respuesta tras un borrado). Ejecuta con `--dry-run` primero.

---

## 13. Problemas frecuentes

### 13.1 Páginas de error propias

| Código | Significa en esta app | Qué hacer |
|---|---|---|
| **403** | Acceso denegado. También: formulario en borrador, en revisión o cerrado; falta de acceso; ruta de embed sin activar; anti-bot sin claves. | Revisa el estado del formulario. Si es un visitante sin permiso, no hay nada que hacer. |
| **404** | No existe. | Revisa el enlace. |
| **405** | Método no permitido (por ejemplo, un GET donde el servidor esperaba un POST). | Casi siempre es un enlace mal escrito. |
| **413** | Envío demasiado grande. | Baja el tamaño de los adjuntos o sube `upload_max_filesize` y `post_max_size` en `php.ini`. |
| **419** | Sesión expirada al enviar. | Recarga y reintenta. |
| **429** | Demasiadas peticiones. | Espera el periodo de la ventana. |
| **500** | Error del servidor. | Revisa el log del servidor web. |
| **503** | Mantenimiento o saturación. | Reintenta en unos minutos. |

El 413 y el 419 están ahí porque el orden importa: si el cuerpo del POST supera
`post_max_size`, PHP vacía `$_POST` y el error honesto es «demasiado grande», no
«sesión inválida».

### 13.2 Síntomas frecuentes

| Síntoma | Causa probable |
|---|---|
| «Publicar» no publica y avisa | El formulario exige revisión. Envíalo a revisión (ver §8.1) |
| El enlace da 403 | Sigue en borrador, o está en revisión o cerrado. |
| Editar lo devolvió a la cola | Con revisión activa, editar un formulario aprobado borra la aprobación anterior. Es a propósito. |
| La tabla de respuestas se queda «cargando» | Debe ser un bug de `analytics.js`. Comprobado en `751602c`. |
| Un campo con condición sigue apareciendo al enviar | La condición se cumple: se muestra, y sus respuestas sí se guardan. |
| Los correos no llegan | Sin SMTP configurado. Revisa `MAIL_*` y que el remitente esté autorizado. |
| El webhook no llega a una IP local | Faltan los **dos** opt-ins. |
| Los adjuntos no se suben | Permisos de escritura en `storage/uploads/`, o el tipo no está en la lista. |
| Un usuario ve todo enmascarado | Es correcto: le falta `responses.pii`. |
| La analítica muestra horas raras | `APP_TIMEZONE` solo afecta a la presentación. Ajusta esa variable, no los datos. |
| Faltan extensiones (`php -m` no lista `mbstring` o `fileinfo`) | La app arranca, pero falla justo cuando usas respuestas con texto o adjuntos |

### 13.3 Antes de decir que algo está roto

1. ¿Está el formulario **publicado**? Es la causa número uno de un 403.
2. ¿Estás viendo la información equivocada por falta de un permiso? Comprueba
   la matriz de roles en `/admin/roles`.
3. ¿Es un dato personal enmascarado? Es intencionado (ver §10).
4. ¿Hay un límite en juego? Envíos, exportaciones y creación tienen tope por
   hora y usuario.

---

## 14. Referencia rápida

### Rutas del panel

| Ruta | Qué es |
|---|---|
| `/dashboard` | Lista de tus formularios. |
| `/forms/{id}` | Ficha: enlace, QR, embed, respuestas, analítica, ajustes. |
| `/forms/{id}/builder` | Constructor. |
| `/responses` | Respuestas de todos tus formularios. |
| `/analytics` | Analítica agregada. |
| `/review` | Bandeja de revisión (si tienes `forms.review`). |
| `/admin` | Panel de administración: usuarios. |
| `/admin/roles` | Roles y matriz de permisos. |
| `/admin/groups` | Grupos de roles. |
| `/admin/security-log` | Registro de eventos de seguridad. |

Las rutas exactas y su middleware están en `config/routes.php`, que es la
fuente de verdad: esta tabla es un resumen.

### Documentos relacionados

| Documento | Para qué |
|---|---|
| `docs/pruebas-funcionales.md` | Lista de pruebas para detectar problemas antes y después de un cambio. |
| `docs/technical-document.md` | Arquitectura y modelo de datos. |
| `docs/decisiones.md` | Por qué se tomó cada decisión. |
| `docs/design.md` | Especificación visual del constructor. |
