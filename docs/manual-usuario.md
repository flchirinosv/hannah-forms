# Manual de usuario — VanillaForms

Manual de instalación y uso de la aplicación, escrito **contra el código**.
Si algo de este manual y lo que ves en pantalla no coinciden, el código manda:
`config/routes.php` para las rutas, `app/Services/FormSettings.php` para los
ajustes y `config/permissions.php` para los permisos.

Documento terminado: 2026-09-30. Última revisión: 2026-10-14, con el
listado de participantes (fases 1 a 10), las invitaciones por correo, el
listado del panel paginado en el servidor (sección 5.1) y la consola de
administración rediseñada: los ajustes de la instancia viven en
`/admin/settings` y las altas y las acciones de fila, en diálogos.

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

# 2. Base de datos (crea la BD y las 19 tablas)
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
| `FILL_SUBMIT_PER_10MIN` | `10` | Envíos que una misma IP puede mandar al formulario cada 10 minutos. Sube el número para un censo con muchas respuestas desde la misma red; bájalo para apretar. |
| `ROSTER_CODE_PER_15MIN` | `10` | Intentos de **código de lista** que una IP puede probar cada 15 minutos. Baja este número en un padrón sensible. Lo que **no** se cambia por aquí son los intentos por código concreto: son siempre 10 por un rato, y contar por código es lo que hace que adivinarlo cueste. |
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

**El registro público está cerrado por defecto.** Para una instalación que no es
pública esto es lo que quieres: nadie se registra solo, y las cuentas las crea
quien administra desde el panel.

Hay dos maneras de abrirlo, y se pueden pisar:

| Dónde | Cómo | Cuándo gana |
|---|---|---|
| `.env` | `REGISTRATION_ENABLED=true` y reiniciar PHP | Mientras nadie lo toque en el panel |
| `/admin/settings` — tarjeta **Registro público** | El interruptor *Registro público* | A partir de ese momento |

Lo que se guarda es el interruptor que se ha marcado, no el valor del `.env`:
así el fichero sigue siendo la referencia de un despliegue limpio y quien
administra la instancia puede cambiarlo sin acceso al servidor. Para volver a
que mande el `.env`, borra la fila:

```sql
DELETE FROM app_settings WHERE key_name = 'registration_enabled';
```

Ojo con el orden: la fila **gana** al `.env`, en los dos sentidos. Si el
`.env` dice `true` y el interruptor del panel está apagado, el registro está
**cerrado**.

Con el registro abierto, cualquiera que llegue a `/register` puede crear una
cuenta y entrar al panel. **No hay verificación por correo**: una cuenta queda
activa desde el instante en que se registra, así que cualquiera puede usar la
dirección que escriba. Ábrelo solo si el panel es público a propósito.

Con el registro cerrado (el valor por defecto) pasan las dos cosas:

- El enlace «Regístrate aquí» desaparece del formulario de acceso, y `/register`
  responde 403 tanto si se entra por GET como si se envía el formulario.
- Las cuentas se crean desde el panel.

### 4.3 Crear cuentas desde el panel

En `/admin` (pestaña **Usuarios**) el botón **Nuevo usuario** de la cabecera abre
el alta en un diálogo, y funciona con el registro público cerrado: es lo
contrario de lo que hace `/register`, y a propósito.

Quién puede verlo: cualquiera con el permiso **`admin.users`**. Si no lo tienes,
la pantalla no aparece y el POST responde 403 aunque la ruta exista.

Para crear una cuenta:

1. Abre `/admin` y pulsa **Nuevo usuario**.
2. Nombre, email, contraseña y su repetición.
3. Elige el **rol** (lo que podrá hacer) y pulsa **Crear cuenta**.

La contraseña mínima es la misma que en el registro público (10 caracteres), y
las cuentas que crees desde aquí son exactamente iguales que las que se
registran solas: misma contraseña, mismo estado activo, mismo cambio de rol
después.

Al darle la cuenta tú, su email **no** queda verificado, así que comunícale la
contraseña por un canal que no sea el correo.

> **Ojo con esto:** el alta del panel existe porque cerrar el registro público
> dejaba sin forma de crear un `user` o un `editor`. No es un atajo para saltarse
> el registro: el rol lo eliges tú, así que es también la forma de crear
> administradores. En una instalación normal, quien puede entrar en el panel ya
> es alguien de confianza; en una abierta, el rol de la primera cuenta decide
> cuánto puede hacer un desconocido.

Para dar de alta a alguien **sin** sesión (por ejemplo, tras restaurar un
backup, cuando todavía no hay ningún admin con quien entrar) el script:

```bash
php scripts/create-admin.php --email=admin@mi-dominio.com --name=Administración
```

Ese script **solo crea administradores**, y sin `--force` se niega a crear un
segundo admin: si ya hay alguno, en realidad lo que hace es convertir esa cuenta
en admin y cambiarle la contraseña. Para un `user` o un `editor` no hay script:
hay que usar `/admin`.

| Valor | `/register` | Quién puede crear cuentas |
| --- | --- | --- |
| Sin fila en `app_settings` y `REGISTRATION_ENABLED=false` (por defecto) | 403 | Solo quien tenga `admin.users`, desde `/admin` (botón **Nuevo usuario**) |
| Sin fila en `app_settings` y `REGISTRATION_ENABLED=true` | Formulario de alta | Cualquiera que llegue al sitio, y quien tenga `admin.users` |
| Con fila en `app_settings` | Lo que diga la fila, en los dos sentidos | Igual, según la fila |

> La tabla de arriba es el camino largo. El corto es el interruptor de la
> tarjeta **Registro público** en `/admin/settings`: escribe la fila, y a partir
> de ahí manda ella sobre el `.env`.

> Si además quieres que la ruta devuelva 403 **antes** de que PHP la vea
> (por ejemplo, en un despliegue con la aplicación en otro servidor), puedes
> mantener el bloqueo en el web server. Los dos mecanismos son compatibles.

Quien llega sin sesión a un enlace de formulario público **no** necesita
cuenta: eso es distinto del panel.

### 4.3.1 En la tabla de personas

El **rol** de una fila se cambia en su desplegable: se guarda solo, sin botón, y
**no recarga la página** (sale el aviso verde y el desplegable se queda donde
estaba). Los **grupos** se eligen en el botón de cada fila y se guardan con su
propio botón: tampoco recargan, y las insignias del botón se reescriben con lo
que acabas de marcar. Puedes tener un desplegable de grupos abierto y cambiar el
rol de otra persona sin que se te cierre.

Lo que **no** se puede es dejarte a ti mismo sin `admin`: el desplegable propio
no se pinta y sale un aviso explicando por qué. Un rol o un grupo que ya no
existen (por ejemplo, una pestaña que lleva mucho tiempo abierta) tampoco se
aplican: sale el motivo junto a la celda y no se cambia nada.

La tercera columna editable es **Permisos**: ahí puedes dar a una persona
permisos **sueltos** (un permiso a una persona, sin cambiarle el rol ni meterla
en un grupo, que afectaría a todo el equipo). Se marcan y se guardan con su
botón, como los grupos. Arriba del desplegable, en solo lectura, tienes el
**desglose de dónde sale cada permiso** que ya tiene (Rol / Grupos / Extra), para
no tener que ir mirando su rol y sus grupos por separado. Borrar el rol de
alguien y volver a ponérselo **no** borra sus permisos sueltos; para quitárselos
hay que desmarcarlos aquí. Si tu cuenta no tiene `admin.users` (el permiso que
mueve la autorización de una persona) verás las insignias de lo que tiene, pero
no podrás marcar casillas.

Sin JavaScript, los tres se guardan como formularios de toda la vida y la página
se recarga.

### 4.4 Roles

| Rol | Para qué |
|---|---|
| `admin` | Todo, incluido el panel de administración. |
| `user` | Uso normal: sus formularios, respuestas, analítica y exportaciones. |
| `editor` | Igual que `user`, pero **sin** permiso de borrar sus propios formularios. |
| `approver` | Revisa y aprueba: entra al panel y a la bandeja de revisión, y no puede crear ni editar formularios. Es el rol para que quien aprueba **no** sea quien puede cambiar el formulario. |

Un rol nuevo se crea sin permisos: la matriz es la que decide qué puede hacer.
Los permisos efectivos de alguien son **su rol + los roles de sus grupos + los
permisos sueltos que le concedas**, así que puedes darle a un grupo entero una
capacidad sin tocar a cada persona, o a una sola persona un permiso sin tocarle
ni el rol ni los grupos.

**Los cuatro roles de la tabla son del sistema y no se pueden borrar**: sin
`admin` la instalación se queda sin panel, sin `user` no hay rol por defecto en
el alta de cuentas, sin `editor` nadie tiene el perfil de editor, y sin
`approver` la revisión de formularios no tiene a quién enviarlos. En la tarjeta
**Baja de roles** los verás marcados como «Sistema», y los roles que hayas
creado tú con el resto de la instalación aparecen con el número de personas que
los llevan: solo se pueden borrar los que están a cero. Borrar uno también lo
quita de los grupos que lo confieran, así que sus miembros pierden lo que ese
rol les traía.

### 4.5 Los permisos que importan

| Permiso | Qué abre |
|---|---|
| `forms.create` | Crear y duplicar formularios. |
| `forms.edit_own` | Editar, publicar, cerrar y configurar los propios. |
| `forms.delete_own` | Borrar los propios (no lo tiene `editor`). |
| `forms.manage_all` | Ver y operar los formularios de cualquier usuario. |
| `forms.review` | **Aprobar o devolver** formularios enviados a revisión. |
| `forms.publish_without_review` | Publicar sin pasar por la revisión cuando la instancia la exige (ver §4.5 y §8). Pensado para quien responde por la instalación, no para que cada autor se autoapruebe. |
| `forms.export_own` | Exportar datos. |
| `responses.view_own` | Ver y eliminar respuestas. |
| `responses.pii` | Ver los datos personales **sin** enmascarar (ver §9). |
| `attachments.download_own` | Descargar adjuntos. |
| `analytics.view_own` | Ver la analítica. |
| `admin.access` / `.users` / `.roles` / `.groups` | Entrar al panel y gestionarlo. |
| `logs.view` | Ver el registro de eventos de seguridad. |
| `dashboard.view` | Entrar al panel de usuario. |

Dato importante: **`forms.review` no es lo mismo que `forms.edit_own`.**
Poder editar tu propio formulario no te deja aprobarlo. Si la revisión está
activada en la instalación (§8, y el interruptor de `/admin/settings`), tu formulario
tiene que pasar por la bandeja y lo aprueba alguien con `forms.review` — que
puede ser tú mismo si lo tienes. Quien tenga `forms.publish_without_review`
publica sin pasar por la bandeja; existe para quien responde por la instalación,
no para que cada autor se apruebe lo suyo.

### 4.6 Recuperar la contraseña

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

### 5.1 La lista del panel

La lista sale **paginada** (doce formularios por pantalla) y con buscador, filtro
por estado y cuatro órdenes. Los tres viajan en la dirección de la página:

| Quiero… | Cómo |
|---|---|
| Buscar por título o descripción | Escribe en el buscador. La lista se actualiza sola al parar; sin JavaScript, `Enter` |
| Ver solo de un estado | Pulsa el botón del estado: es un enlace, así que la URL queda con `?estado=…` y el botón de atrás funciona |
| Cambiar el orden | El desplegable de la derecha (más recientes, alfabético, más respuestas, última actividad). Sin JavaScript, el botón **Aplicar** |
| Ver los siguientes | Los botones del paginador, abajo. **Conservan la búsqueda y el filtro** |

Las cuatro cifras de arriba (formularios, publicados, borradores, respuestas) son
de **toda tu cuenta**, no de lo que estás viendo: con un filtro puesto no cambian,
porque dicen cuántos hay y no cuántos se ven. Si un filtro no encuentra nada, sale
«Sin resultados» con un enlace para quitarlo — no un panel vacío.

Ojo: `100%` se busca como texto (no es un comodín), así que `100%` encuentra los
títulos que llevan ese porcentaje y solo esos.

---

## 6. El constructor

El constructor es **una sola pantalla**: un lienzo central con una tarjeta por
pregunta, reordenables, y botones para agregar. No hay columnas laterales
fijas; las pantallas de ajustes salen cuando las pides.

- **Lienzo** (centro): los campos ya colocados. El título y la descripción del
  formulario se editan arriba, en línea. Cada tarjeta tiene un pie fijo con
  **el tipo de campo**, el interruptor de **obligatoria** y los botones de
  duplicar, configurar y eliminar: cambias de tipo o marcas la obligatoria sin
  abrir ningún panel.
- **Agregar elemento** (barra o `+`): abre la lista de los 21 tipos. Hay dos
  formas de llegar: el botón **Agregar elemento** de la barra y el `+` que
  aparece entre dos tarjetas (inserta ahí). Dentro de una sección, el botón
  «Agregar pregunta dentro» hace lo mismo.
- **Configuración** (cajón a la derecha): los ajustes del campo seleccionado
  (con las pestañas **Contenido** / **Lógica**) y, por separado, los de todo el
  formulario. Se abren con el engranaje de una tarjeta o con **Configuración**
  de la barra, y se cierran con su «x», con `Esc` o tocando fuera.
- **Vista previa**: el botón **Vista previa** abre el formulario en **otra
  pestaña**, tal como saldrá publicado (con el `fill.js` de verdad: condiciones,
  pasos, tema y validación). Si acabas de editar en el constructor, la pestaña
  muestra **tus cambios sin guardar** (el borrador). Puedes probarlo, pero
  **no se guarda ninguna respuesta**: es una prueba, no un envío real.

El botón **Guardar** (barra superior) persiste la estructura: campos, orden,
opciones y los ajustes del cajón **Configuración** (tema, quiz, orden de las
preguntas, avance automático, embed y anti-bot). El botón **Guardar
configuración** (abajo del cajón) aplica esos ajustes al formulario en memoria:
no los persiste por sí solo, los persiste el *Guardar* de la barra superior.

Los ajustes que cambian **quién responde y cuándo** no están aquí: están en la
ficha del formulario, en la tarjeta *Ajustes del formulario* (ver §7).

**Cada ajuste lleva un `?` al lado.** Al pasar el ratón por encima aparece qué
hace ese ajuste y un ejemplo; en el móvil, tócalo. Los textos que aparecen solos
al escribir (por ejemplo, si la tipografía no es válida) son **estado**, no
explicación: esos se ven siempre, sin pasar el ratón por ningún lado.

**Guardar es explícito, pero hay un respaldo.** El botón **Guardar** es lo que
deja los cambios públicos: hasta que lo pulsas, el formulario sigue con la
estructura anterior. Ahora bien, cada pocos segundos el constructor guarda
solo un **borrador** en el servidor: si cierras el navegador o se cae la
conexión, al volver al constructor **recuperas lo que llevabas**. Ese borrador
no afecta al formulario publicado (sigue sirviendo la versión anterior) y se
borra al pulsar **Guardar** o al mandar el formulario a revisión. El botón
**Guardar** está apagado mientras no haya cambios: si no se mueve, no hay nada
que guardar.

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

**El constructor te avisa.** Cuando escribes una pregunta cuya etiqueta parece
un dato personal («¿Tu nombre y apellidos?», «Correo de contacto», «Teléfono»,
«Cédula», «Dirección», «Fecha de nacimiento»…), el panel te lo señala con un
aviso amarillo y dos botones:

| Botón | Qué hace |
|---|---|
| **Sí, es dato personal** | Activa el interruptor y guarda el cambio |
| **No es** | Oculta el aviso **para esa pregunta** |

Que avise y no decida es a propósito: «Nombre de la empresa» o «¿Qué nombre le
pones a tu proyecto?» no son datos de nadie, y esconderlos sin que lo pidas
sería justo el error contrario. Si reescribes la etiqueta, el aviso vuelve a
aparecer, porque a lo mejor ahora sí es una pregunta distinta.

El aviso solo sale en preguntas de verdad: en las secciones y en las páginas no
aparece, porque no guardan respuestas.

---

## 7. Ajustes del formulario

Están repartidos en **dos sitios**, según lo que cambia el ajuste:

| Dónde | Qué se edita allí |
|---|---|
| **Ficha del formulario** (`/forms/{id}`) — tarjeta *Ajustes del formulario* | Acceso (quién responde), programación, cupo, avisos y confirmación, webhook, mensaje final e idioma |
| **Constructor** — botón *Configuración* | Tema, quiz, orden de las preguntas, avance automático, embed y anti-bot |

La ficha se reparte en **dos columnas**. A la izquierda, la ancha, va lo que
describe el formulario: *Detalles* y *Campos*. A la derecha, la estrecha, lo que
se comparte y lo que se configura: *Enlace para compartir* y *Ajustes del
formulario*. En móvil las dos columnas se apilan en ese mismo orden.

La **lista de correos** y el **listado de participantes** ya no viven en la ficha:
tienen su propia pantalla, `GET /forms/{id}/access`, que abres con el botón
**Participantes** (entre *Editar campos* y *Respuestas*). El **modo** de acceso y
**con qué se identifica** cada persona se siguen editando en la ficha, en el grupo
*Acceso* de *Ajustes del formulario*; esa pantalla los **lee** para saber qué panel
pintar.

La regla es qué cambia el ajuste: los de la ficha cambian **a quién llega el
formulario y cuándo** (y ahí se ven las consecuencias: si la lista de correos
está vacía, a quién le llega el aviso, si la revisión deja el formulario en
borrador), y los del constructor cambian **cómo se rellena**.

Grupos y valores por defecto:

| Grupo | Dónde | Qué hace | Por defecto |
|---|---|---|---|
| Contenido | Ficha | Título, descripción, texto del botón de envío | — |
| Acceso | Ficha | Quién puede responder: cualquiera, por correo, o solo la lista | Público |
| Programación y cupo | Ficha | Ventana de fechas y máximo de respuestas | Siempre, sin tope |
| Correo | Ficha | Aviso al dueño, confirmación al que responde | Desactivado |
| Webhook | Ficha | Endpoint (JSON o Slack) con firma HMAC | Desactivado |
| Mensaje final | Ficha | Mensaje de gracias y redirección | Sin redirección |
| Idioma | Ficha | `es` o `en` | `es` |
| Tema | Constructor | Color primario, color de fondo, tipografía | Sin personalización |
| Comportamiento | Constructor | Quiz, barajado, avance automático, embed | Desactivado |
| Anti-bot | Constructor | Honeypot, Turnstile | Honeypot **activado** |
| Analítica | — | Qué se guarda y durante cuánto | — |

### La lista de correos con acceso

Cuando el modo de acceso es **Solo la lista**, la pantalla **Participantes**
(`/forms/{id}/access`) tiene el alta de correos. Escribes el correo y pulsas
*Añadir*: la lista se actualiza en el sitio, sin recargar, y la caja queda lista
para el siguiente. Si
el correo no es válido, ya estaba en la lista o el formulario no está en modo
*Solo la lista*, el motivo sale debajo de la caja y **no** se añade nada.

Dos botones acompañan a cada correo de la lista: el de **enlace** genera y copia
el enlace de acceso de esa persona, y el de **quitar** la saca de la lista.
Quitar **invalida los enlaces que ya se le hayan enviado**: no es solo borrar una
fila. Los dos piden confirmación.

La caja se queda con el foco al añadir, así que se pueden encadenar varios
correos sin volver al ratón. Sin JavaScript, el alta sigue funcionando igual:
el formulario se envía y la página se recarga.

El **modo** se cambia en el grupo *Acceso* de la tarjeta *Ajustes del
formulario* de la ficha, con su botón *Guardar ajustes*. Esa pantalla de
**Participantes** es la que dice «Solo la lista» con el alta y los correos, o
«Público» sin ellos, sin tocar nada más.

> El enlace se genera **al vuelo**: el token de acceso solo existe en claro en
> el momento de emitirlo, así que si el portapapeles falla, la caja de texto que
> aparece debajo de la tarjeta es la única forma de recuperarlo.

### 7.0 Cómo leer estas pantallas

En el constructor, los ajustes vienen apagados y **se guardan con la
estructura** (el botón *Guardar*). En la ficha hay un botón *Guardar ajustes*.

Los ajustes con un botón <kbd>?</kbd> al lado de la etiqueta esconden su
explicación ahí: se lee al pasar el ratón por encima, al enfocarlo con el
teclado o al tocarlo en el móvil. Los que tienen un texto gris **visible** no
son explicación, son el estado del ajuste en este momento (si la tipografía es
válida, si el aviso de revisión se manda por correo, qué modo de acceso hace el
formulario ahora mismo), y ese texto cambia según lo que marques.

Cinco cosas que la pantalla avisa por ti porque fallan en silencio:

| Aviso | Qué pasa si no lo lees |
|---|---|
| **Tipografía**: elige una familia del catálogo (se sirve desde tu instalación, en `assets/fonts/`) | Escribir una familia a mano (p. ej. `Georgia`) se descarta: solo están las que el servidor puede servir, así que lo que eliges se ve igual en cualquier equipo |
| **Campo email** de la confirmación: es la etiqueta exacta de la pregunta | Si no coincide con ninguna, el correo no se envía y no hay error en pantalla |
| **Turnstile**: actívalo solo si el servidor ya tiene claves | El formulario deja de mostrarse a **todo el mundo**, no solo a los bots |
| **Abre/Cierra**: se leen en la zona horaria del servidor, y abrir después de cerrar se rechaza | Una ventana que no existe nunca |
| **Redirección de gracias**: solo si el dominio está en la lista del servidor | Se muestra la página de gracias y no se redirige |

Bajo **Tema** hay una maqueta con el botón y la barra de progreso: cambia los
dos colores y los ves al instante, sin abrir la vista previa aparte. La fuente
no sale en la maqueta a propósito, porque el equipo de quien responde puede no
tenerla instalada y el resultado real no sería el que ves.

### 7.0.1 Antes de borrar algo

Las acciones que se llevan algo —eliminar un formulario, una respuesta, un rol o
un grupo, desactivar o cerrar— **no se ejecutan en el acto**: sale un diálogo con
la pregunta y dos botones, y el que viene enfocado es **«Cancelar»**. El botón de
confirmar dice **qué va a pasar** («Eliminar formulario», «Desactivar cuenta») y
sale en rojo, salvo en lo que no destruye nada (publicar, devolver a borrador,
activar), que sale en azul. `Esc` es cancelar.

Si te aparece el diálogo, es que hay JavaScript. **Sin él no hay confirmación y
tampoco hay acciones sin recarga**: el formulario se envía como un formulario y
la página se recarga, que es lo que siempre ha pasado.

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

### 8.1 Los cinco estados

| Estado | Qué ven los demás |
|---|---|
| `draft` Borrador | Nada: 403. Es tu espacio de trabajo. |
| `in_review` En revisión | Una página informativa de que está en revisión (no un 404) y los envíos se rechazan con 403. |
| `approved` Aprobado | Nada: una página informativa de que todavía no se ha publicado. Los envíos se rechazan con 403. |
| `published` Publicado | El formulario funciona. |
| `closed` Cerrado | Ya no se puede enviar. Las respuestas se conservan. |

Publicar y cerrar se hacen desde la ficha del formulario, no desde el
constructor. Hay tres acciones distintas y no se sustituyen:

| Acción | Qué hace |
|---|---|
| **Publicar** | Deja el formulario visible para cualquiera con el enlace. |
| **Cerrar** | Deja de aceptar envíos. Las respuestas se conservan. |
| **Enviar a revisión** | Manda el formulario a la bandeja de revisión. |

**Con la revisión activada en la instalación, «Publicar» desde un borrador no
publica.** Se rechaza con un aviso que te dice que lo envíes a revisión, en lugar
de un 403 que no sabrías interpretar. El flujo completo queda así:

**Enviar a revisión** (tú, desde la ficha) → **Aprobar** o **Devolver a
borrador** (quien tenga el permiso `forms.review`, desde `/review`) → **Publicar**
(tú, desde la ficha).

**Aprobar no publica**: deja el formulario en `approved`, que es «revisado y
listo, pero todavía sin salir». Su propietario ve entonces un botón **Publicar** y
decide cuándo pulsarlo. Así una aprobación con días o semanas de antelación no
sacaba el formulario al público sola: puedes publicarlo al momento o dejarlo
esperando el día previsto (si el formulario tiene horario de apertura, ese
horario sigue siendo el que decide cuándo se puede responder).

**Quien revisa trabaja desde la bandeja**, no desde la ficha del formulario: la
ficha editable es de su dueño. En `/review` cada formulario pendiente trae **Ver**,
**Aprobar** y **Devolver**. **Ver** abre la **estructura en modo lectura** (las
preguntas, sus tipos y opciones, la obligatoriedad y la lógica), suficiente para
decidir, con un enlace para volver a la bandeja. Un revisor con el rol `approver`
(solo `forms.review` + `dashboard.view`) no puede abrir la ficha editable ni la
de un formulario en borrador: su sitio es la bandeja. Aprobar o devolver devuelve
a la bandeja a quien no puede editar el formulario, y a la ficha a su dueño.

Si cambias el contenido después de aprobar —en el constructor o desde la ficha—,
el formulario pasa a **borrador** y la aprobación anterior se borra: quien revisó
aprobó otra cosa. No va solo a la cola: tú decides cuándo volver a enviarlo a
revisión con **Enviar a revisión**.

La revisión es **una política de la instalación, no un ajuste de cada
formulario**: un administrador la enciende (o la apaga) en `/admin/settings` —
tarjeta **Política de revisión** — con los interruptores *Exigir revisión a
todos* y *Avisar por correo a los revisores*. Quien tenga el permiso
`forms.publish_without_review` (ver §4.5) queda exento y publica sin pasar por la
bandeja. Antes esto se configuraba formulario a formulario; ya no.

La bandeja de `/review` muestra **todos** los formularios de la instalación que
están esperando, no solo los tuyos: es un punto de control común. Si revisas los
formularios de otra gente, ponte de acuerdo con el orden antes de empezar.

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

Se enmascara en **todos** los canales de salida: detalle, listado, analítica,
exportación JSON y exportación CSV. No solo en pantalla.

Una asimetría que conviene conocer: el **CSV se enmascara siempre**, aunque tengas
`responses.pii`. Su puerta es el permiso de exportar, no el de leer datos
personales, así que con permiso ves los valores en la analítica y `[oculto]` en el
CSV del mismo formulario. Está del lado estricto por decisión propia; no es un
fallo.

`responses.pii` es por defecto solo de `admin`. Si no tienes ese permiso y ves
`[oculto]`, el enmascarado está funcionando, no fallando.

---

## 11. Correos

Siete plantillas: aviso de respuesta recibida, confirmación al que responde,
solicitud de acceso, recuperación de contraseña, aviso de revisión, la
invitación a un listado de participantes y su recordatorio (ver §12.3 y
§12.4).

Dos comportamientos que conviene conocer:

- El aviso de nueva respuesta va al **dueño del formulario**, hasta 10
  destinatarios. El de confirmación usa un campo del propio formulario como
  destino.
- Sin SMTP configurado no se envía nada, pero **la respuesta del formulario se
  guarda igual**: el correo nunca decide si se guarda una respuesta.

El enlace de recuperación de contraseña sale de la URL pública, no de
`APP_URL`, para que funcione aunque el correo se envíe desde otra dirección.

---

## 12. Un formulario para una lista de personas (modo «Solo el listado de participantes»)

Este modo sirve para un caso concreto: **sabes de antemano quién tiene que
responder** (un padrón, un censo, un equipo) y quieres dos cosas a la vez —que
respondan solo esas personas y que puedas ver quién respondió y comparar sus
respuestas por atributos (sede, zona, segmento).

Se activa en la **ficha** del formulario, en *Acceso*, eligiendo «Solo el listado
de participantes» y con qué se identifica cada persona:

| Identificador | Qué es | Cuándo usarlo |
|---|---|---|
| **Cédula** | Cada persona entra con su documento. | Cuando no tienes el correo de todo el mundo, o el documento es lo que ya manejas. |
| **Indicador del centro** | Cada persona entra con un código que tú defines (nº de cliente, expediente…). | Cuando tu identificador es un código interno. |
| **Correo electrónico** | Cada persona entra con la dirección de la lista. | Cuando ya tienes el correo de todo el mundo y quieres que entren solos. |
| **Código** | Cada persona entra con el código de la columna «Código». | Cuando no quieres que la lista se pueda buscar por ningún dato: el código no se adivina. |

Sin lista, un formulario es público, por lista de correos o por enlace (ver
*Acceso*). Con lista, el formulario **no se sirve a quien no esté en él**: la
pantalla de acceso pide **el código personal de 16 caracteres** (el de la columna
«Código») **o el dato con el que figuras en la lista**, el mismo que dice el
desplegable de la ficha — la cédula si el formulario identifica por cédula, el
correo si identifica por correo, y nada más que esos dos. Quien se pierda el
código entra con ese dato, y al revés también: el código sirve siempre, en
cualquiera de los cuatro modos.

### 12.1 El panel de Participantes

Una vez cargada la lista, la pantalla **Participantes** (`/forms/{id}/access`,
botón *Participantes* en la ficha) se organiza en **cuatro tarjetas**: arriba, la
cabecera de **acceso** (cómo entra la gente); debajo, el **resumen** (los números
y el botón de invitar); después, la **lista**; y al final, la **importación**,
plegada. Son siete cosas en total:

**Lista** — todo lo del día a día:

1. **Los números**: cuántas personas hay en total, cuántas han respondido,
   cuántas quedan por responder y cuántas han recibido el enlace por correo.
2. **El botón de invitar por correo** (ver §12.3).
3. **El alta de una persona** suelta, para quien llega tarde. El botón *Añadir a la
   lista* abre un diálogo con el formulario (una sola columna, para que los campos
   se lean enteros).
4. **La lista** en sí, con buscador (campo + botón *Buscar*), orden por columna y
   un menú `⋯` por fila que abre la edición de esa persona y su código de entrada.
   La lista se **pagina** de 25 en 25, con el recuento total, el número de página
   y flechas de anterior/siguiente.

**Importar** — el archivo y su formato. La tarjeta viene **plegada**: pulsa su
título para abrirla.

5. **Descargar la plantilla** o **subir un `.xlsx`**. La plantilla tiene una hoja
   con una fila de encabezados; las columnas fijas (`cédula`, `nombre`,
   `indicador`, `correo`, y `código`, que al leer se ignora porque ese dato lo
   genera la app) se reconocen por su encabezado, y **cualquier otro
   encabezado es un atributo**.
6. **El informe de una importación**: qué filas entraron, cuántas quedaron
   fuera y por qué, con el detalle de las omitidas. Tras importar, la tarjeta se
   abre sola para que veas el resultado.
7. **El catálogo de atributos**: las columnas extra (sede, zona, segmento…) que
   puedes renombrar o quitar. Se administra en el mismo panel, junto al
   importador, porque **es el formato del `.xlsx`**: vaciar la lista no lo borra.

Dentro de **Importar**, el formulario de subida y el catálogo de atributos van
uno al lado del otro, y el botón *Vaciar la lista* queda **fuera** del bloque
plegable, abajo del todo: es lo único que no se puede deshacer.

> La búsqueda es un «contiene»: buscar `V-1` también encuentra `V-10`. Usa
> identificadores de ancho fijo (`V-01`) si te importa. La lista se muestra de
> 25 en 25. La búsqueda **no recarga la página**: cambia solo la lista y la
> dirección, así que puedes compartirla o volver con «atrás».

### 12.2 Importar

1. Descarga la plantilla y rellénala. Los identificadores van **como texto**, no
   como número (una cédula `00123` tiene tres ceros).
2. En el panel Participantes, abre la tarjeta **Importar** y sube el archivo.
3. Elige si **fusiona** con lo que ya había o si **reemplaza** toda la lista.
4. Lee el informe: dice cuántas filas entraron, cuántas quedaron fuera y por qué.

Dos cosas que conviene saber:

- **Reemplazar con un archivo vacío se rechaza.** Para vaciar la lista hay un
  botón propio, y existe para que el catálogo de atributos sobreviva: vaciar la
  lista no borra las columnas, para que el próximo archivo tenga el mismo formato.
- **Importar no manda ningún correo.** Ver §12.3.

### 12.3 Invitar por correo

Si el formulario se identifica por **correo**, el panel muestra un botón
**«Enviar invitaciones»** con el número de pendientes entre paréntesis. Púlsalo y
cada persona recibe un correo con un enlace al formulario.

Cinco reglas, para que no te sorprenda nada:

1. **No hay botón si el identificador no es el correo.** Con cédula, indicador o
   código, el enlace de cada persona está en la columna «Código» y lo repartes
   tú. Es a propósito: el correo es un dato opcional de la lista, no la identidad
   con la que se entra.
2. **No hay botón si no hay servidor de correo configurado.** El panel te lo
   dice. Los códigos de la columna «Código» siguen funcionando.
3. **La importación no manda correos.** Los 400 correos de un archivo de 400
   filas son un accidente esperando, así que el envío es un botón aparte que
   pulsas tú.
4. **De 50 en 50.** El botón dice cuántos quedan y envía como mucho 50 por
   pulsación. Si quedan 87, después del primer clic dirá 37. El tope es para que
   un envío no se quede colgando; no es un fallo.
5. **A quien ya respondió no se le escribe**, y a quien ya recibió el enlace no
   se le vuelve a escribir. Si le cambias el correo a alguien, vuelve a contar como
   pendiente: el correo nuevo es el que hay que mandar.

Si el servidor de correo se cae a mitad de una tanda, el aviso dice cuántos
salieron y cuántos quedan, y **los que fallaron siguen en la lista**: no se marca
a nadie a quien no le llegó el correo. Repite el botón para reintentar.

> **Cambiar el identificador del formulario con gente dentro.** Al cambiar de
> cédula a correo (o al revés) la app comprueba que toda la lista tenga el dato
> nuevo y, si algo falta, te dice exactamente a quién antes de tocar nada. Los
> **códigos no cambian** al cambiar el identificador: los enlaces repartidos siguen
> sirviendo.

### 12.4 Enviar recordatorios

Cuando ya se ha invitado y **todavía faltan respuestas**, la misma tarjeta de
invitaciones muestra otro botón, **«Enviar recordatorios»**, con el número de
quienes llevan la invitación y siguen sin responder.

Cinco reglas, porque un recordatorio no es «invitar otra vez»:

1. **Solo a quien fue invitado y no respondió.** Quien nunca recibió la
   invitación no es candidato a un recordatorio, y a quien ya respondió le
   estarías pidiendo algo que ya hizo. A quien nunca se le invitó, la invitación
   es su botón.
2. **Se puede recordar muchas veces.** No es de un solo uso como la invitación:
   la fila guarda el **contador** de recordatorios y su fecha. El envío se marca
   **después** de que el correo salga, nunca antes, para que un fallo no saque
   a nadie de la cola.
3. **No insiste solo sobre los mismos.** Cada tanda empieza por quien no ha
   recibido ningún recordatorio y sigue por el más antiguo, así que rotan.
4. **De 50 en 50, con corte a tres fallos.** El texto del confirmador dice el
   tope y el número de gente. Si el servidor de correo cae, el aviso dice cuántos
   salieron y cuántos fallaron, y **los que fallaron vuelven a estar pendientes**.
5. **Solo con identificación por correo y con SMTP configurado.** Con cédula,
   indicador o código no hay recordatorio que mandar: el enlace lo repartes tú
   desde la columna «Código».

Si el formulario tiene **fecha de cierre**, el correo la cita; si no tiene, el
correo simplemente no dice nada de fechas.

> **Los dos botones conviven.** «Enviar invitaciones» cuenta a los que aún no
> recibieron nada, «Enviar recordatorios» cuenta a los que ya lo recibieron y no
> han respondido. Los dos mandan 50 por pulsación y ninguno marca a quien no le
> llegó el correo.

### 12.5 Ver y comparar respuestas

Dos cosas aparecen ahora en la ficha y en la analítica del formulario:

- **Quién respondió.** En el listado de respuestas y en el detalle hay una
  columna «Participante» con la identidad de quien envió cada respuesta, **detrás
  del permiso `responses.pii`**. Sin ese permiso no hay columna (y hay un aviso
  que lo explica); los archivos exportados **no** llevan nunca esa identidad.
- **Comparar por atributo.** En la analítica puedes filtrar las respuestas por un
  valor de un atributo (sede Norte, por ejemplo) y comparar los grupos así
  filtrados por otro atributo. El número de filas está topado a propósito.

## 13. Seguridad y operación

### 13.1 Lo que protege cada capa

| Capa | Qué hace |
|---|---|
| CSRF | Todos los POST del panel y del formulario público. |
| CSP | `script-src 'self'`: no hay scripts inline ni `onclick` en ninguna vista. |
| Límite de envíos | 10 envíos por IP cada 10 minutos y formulario. |
| Límite de login | 5 por IP y 10 por email cada 5 minutos. |
| Honeypot | Activo por defecto. |
| Registro de seguridad | Eventos relevantes en `/admin/security-log`. |
| Cabeceras | `X-Frame-Options: DENY` salvo que se cambie. |

### 13.2 Copias de seguridad

Hay dos cosas que respaldar, y **juntas**: la base de datos (respuestas,
usuarios, tokens) y `storage/uploads/` (adjuntos). Una copia de la base sin los
adjuntos conserva el texto de las respuestas pero pierde los archivos; una copia
de los adjuntos sin la base deja archivos sin saber a qué respuesta pertenecen.
Haz las dos en el mismo momento.

### 13.3 Limpieza

`scripts/clean-uploads.php` encuentra adjuntos huérfanos (los que se quedan
sin fila de respuesta tras un borrado). Ejecuta con `--dry-run` primero.

---

## 14. Problemas frecuentes

### 14.1 Páginas de error propias

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

### 14.2 Síntomas frecuentes

| Síntoma | Causa probable |
|---|---|
| «Publicar» no publica y avisa | La revisión está activada en la instalación. Envíalo a revisión (ver §8.1) |
| Lo aprobé pero el enlace no sale al público | **Aprobar no publica**: el formulario queda «Aprobado» y su dueño lo saca pulsando «Publicar» en la ficha (§8.1) |
| El enlace da 403 | Sigue en borrador, aprobado sin publicar, o en revisión; también si está cerrado. |
| Editar lo dejó en borrador | Con la revisión activada en la instalación, editar un formulario publicado o aprobado lo deja en borrador y borra la aprobación anterior. Es a propósito: tú decides cuándo volver a enviarlo a revisión. |
| La tabla de respuestas se queda «cargando» | Debe ser un bug de `analytics.js`. Comprobado en `751602c`. |
| Un campo con condición sigue apareciendo al enviar | La condición se cumple: se muestra, y sus respuestas sí se guardan. |
| Los correos no llegan | Sin SMTP configurado. Revisa `MAIL_*` y que el remitente esté autorizado. |
| No aparece el botón de invitar por correo | El formulario tiene que ser de lista **y** de identificación por correo (ver §12.3). |
| No aparece el botón de recordatorios | No hay nadie pendiente: hay que haber invitado antes y faltar respuestas, con identificación por correo (ver §12.4). |
| El webhook no llega a una IP local | Faltan los **dos** opt-ins. |
| Los adjuntos no se suben | Permisos de escritura en `storage/uploads/`, o el tipo no está en la lista. |
| Un usuario ve todo enmascarado | Es correcto: le falta `responses.pii`. |
| La analítica muestra horas raras | `APP_TIMEZONE` solo afecta a la presentación. Ajusta esa variable, no los datos. |
| Faltan extensiones (`php -m` no lista `mbstring` o `fileinfo`) | La app arranca, pero falla justo cuando usas respuestas con texto o adjuntos |

### 14.3 Antes de decir que algo está roto

1. ¿Está el formulario **publicado**? Es la causa número uno de un 403.
2. ¿Estás viendo la información equivocada por falta de un permiso? Comprueba
   la matriz de roles en `/admin/roles`.
3. ¿Es un dato personal enmascarado? Es intencionado (ver §10).
4. ¿Hay un límite en juego? Envíos, exportaciones y creación tienen tope por
   hora y usuario.

---

## 15. Referencia rápida

### Rutas del panel

| Ruta | Qué es |
|---|---|
| `/dashboard` | Lista de tus formularios, paginada y con búsqueda, filtro de estado y orden en la URL. |
| `/forms/{id}` | Ficha: enlace, QR, embed, ajustes, y los botones *Editar campos*, *Participantes* y *Respuestas*. |
| `/forms/{id}/access` | Pantalla de **Participantes**: la tarjeta de acceso (allowlist) y el listado de la lista, con buscador, paginación y edición. |
| `/forms/{id}/builder` | Constructor. |
| `/forms/{id}/preview` | Vista previa real en otra pestaña (dueño o revisor; no persiste nada). |
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
