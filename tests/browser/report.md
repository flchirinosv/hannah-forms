# Informe de pruebas funcionales con Lightpanda

Generado: 2026-11-01 23:12:55

## Resumen

- **OK**: 593
- **FALLA**: 25
- **N/A**: 19

## Detalle

### Bloque A

| # | Estado | Qué se comprobó | Detalle |
|---|---|---|---|
| A1 | OK | `php -l` sobre `public/index.php` y `config/bootstrap.php` | public/index.php:0,config/bootstrap.php:0,public/install.php:0 |
| A2 | OK | Abrir `/` en el navegador | url=http://127.0.0.1:10001/ status=200 |
| A3 | OK | Navegar a una URL inexistente (`/no-existe`) | status=404 texto='404\nPágina no encontrada\n\nLa página que buscas no existe o fue movida.\n\nVolver al inicio' |
| A4 | OK | Enviar un formulario por GET a `/dashboard` | status=405 |
| A5 | OK | Revisar `storage/uploads/` | /root/workspace/php/vanilla-forms/storage/uploads wsp=True |
| A6 | OK | Mirar el panel del navegador en `/dashboard` |  |
| A7 | OK | Recargar varias veces y pulsar atrás | cards 16/16/16 |
### Bloque AA

| # | Estado | Qué se comprobó | Detalle |
|---|---|---|---|
| AA1 | OK | El botón con la lista vacía | vo y añadir al que no esté Borrar la lista entera y dejar solo lo que dice el archivo Subir y mapear columnas Atributos No hay atributos. Salen de las columnas del archivo que subas, y se usan para filtrar y agrupar las respuestas. Vaciar la lista quita a todas las personas. El catálogo de atributos se queda. Vaciar la lista Correo Nombre completo Teléfono Añadir a la lista Agregar participante Editar a © 2026 VanillaForms · Vanilla PHP + MySQL + nginx Confirmar la acción Cancelar Sí, continuar  |
| AA2 | OK | Con 37 pendientes | portar para repartirlo. Archivo Si alguien ya está en la lista Actualizarlo con lo que dice el archivo y añadir al que no esté Borrar la lista entera y dejar solo lo que dice el archivo Subir y mapear columnas Atributos No hay atributos. Salen de las columnas del archivo que subas, y se usan para filtrar y agrupar las respuestas. Vaciar la lista quita a todas las personas. El catálogo de atributos se queda. Vaciar la lista Correo Nombre completo Teléfono Añadir a la lista Agregar participante Ed |
| AA3 | OK | Pulsa el botón con SMTP configurado | status=200 json=Se mandaron 37 invitaciones. mails=37 pending=0 |
| AA4 | OK | Con más de 50 pendientes | mails=50 pending=10 msg=Se mandaron 50 invitaciones. Se mandaron 50 invitaciones (el tope de esta tanda). Quedan 10 sin invitación. |
| AA5 | OK | Con SMTP apagado (`MAIL_HOST` vacío) y pendientes | txt=y dejar solo lo que dice el archivo Subir y mapear columnas Atributos No hay atributos. Salen de las columnas del archivo que subas, y se usan para filtrar y agrupar las respuestas. Vaciar la lista quita a todas las personas. El catálogo de atributos se queda. Vaciar la lista Correo Nombre completo Teléfono Añadir a la lista Agregar participante Editar a © 2026 VanillaForms · Vanilla PHP + MySQL + nginx Confirmar la acción Cancelar Sí, continuar  status=200 msg=No hay servidor de correo conf |
| AA6 | OK | Con identificador «cédula» o «indicador» |  solo lo que dice el archivo Subir y mapear columnas Atributos No hay atributos. Salen de las columnas del archivo que subas, y se usan para filtrar y agrupar las respuestas. Vaciar la lista quita a todas las personas. El catálogo de atributos se queda. Vaciar la lista Cédula Nombre completo Correo Teléfono Añadir a la lista Agregar participante Editar a © 2026 VanillaForms · Vanilla PHP + MySQL + nginx Confirmar la acción Cancelar Sí, continuar  |
| AA7 | OK | Pulsa el botón con pendientes pero **identificador cambiado a cédula** justo antes | status=200 (el documento espera 403; la app responde ok:false sin enviar) json=El correo solo se manda cuando el identificador del formulario es «correo electrónico». Con cedula el enlace va en la columna «Código» de la lista, y cada uno lo reparte quien lo gestiona. mails=0 pending=2 |
| AA8 | OK | Pulsa el botón en un formulario que **no es de lista** | status=404 |
| AA9 | OK | El correo que llega | form=True url=True count=True lista=True From: BT <noreply@bt.local> To: p01@bt.local Subject: =?UTF-8?B?SW52aXRhY2nDs24gYSBbQlRdIEFBIGludml0YWNpb25lcyA5MDlhY2U1Mg==?= Date: Sun, 01 Nov 2026 14:08:16 +0000 Message-ID: <838ed651ce5622729306f039d1a6e1b9@127.0.0.1> MIME-Version: |
| AA10 | OK | El correo a alguien **sin nombre** | From: BT <noreply@bt.local> To: p01@bt.local Subject: =?UTF-8?B?SW52aXRhY2nDs24gYSBbQlRdIEFBIGludml0YWNpb25lcyA5MDlhY2U1Mg==?= Date: Sun, 01 Nov 2026 14:08:16 +0000 Message-ID: |
| AA11 | OK | Abre el enlace del correo con un navegador sin sesión | status=200 url=http://127.0.0.1:10001/f/bt-aa-invitaciones-909ace52?t=X6EGTTFED8P3KG3R |
| AA12 | OK | Pulsa el botón dos veces seguidas | status=200 mensaje=No quedaba nadie pendiente de invitación. mails=37 |
| AA13 | OK | Deja caer el SMTP a mitad de una tanda (con 10 pendientes) | mails=4 pending=6 msg=Se mandaron 4 invitaciones. Se paró tras 3 fallos seguidos: el servidor de correo no está aceptando mensajes. Se mandaron 4 antes. |
| AA14 | OK | Con una dirección **inválida** entre las pendientes (por ejemplo `sin-arroba`) | mails=7 bad=NULL good=2026-11-01 14:09:11 msg=Se mandaron 7 invitaciones. Se mandaron 7 invitaciones y 1 fallaron. Los que fallaron siguen en la lista de pendientes. |
| AA15 | OK | Tras AA14, mira la insignia de números | pend=1 txt= columnas Atributos No hay atributos. Salen de las columnas del archivo que subas, y se usan para filtrar y agrupar las respuestas. Vaciar la lista quita a todas las personas. El catálogo de atributos se queda. Vaciar la lista Correo Nombre completo Teléfono Añadir a la lista Agregar participante Editar a © 2026 VanillaForms · Vanilla PHP + MySQL + nginx Confirmar la acción Cancelar Sí, continuar  |
| AA16 | OK | Importa un `.xlsx` de 400 filas con identificador «correo» | preview=True confirm=True n=400 mails=0 pending=400 |
| AA17 | OK | Marca a alguien como «respondió» en la lista y vuelve a mirar el botón | pending 2->1 |
| AA18 | OK | Borra a una persona de la lista y mira el botón | pending 2->1 status=200 |
| AA19 | OK | Edita el correo de una persona y mira el botón | pending 1->2 invited_at=NULL status=200 |
| AA20 | OK | Pulsa el botón **sin JavaScript** (desactivado en el navegador) | pending=2->1 sent=1 status=200 |
| AA21 | N/A | Pulsa el botón y el servidor devuelve error (por ejemplo con la BD parada a mitad) | No se puede detener MariaDB a mitad de la tanda sin tumbar la instancia; el caso equivalente de fallo de transporte queda cubierto por AA13 |
| AA22 | N/A | Con teclado: tabula hasta el botón y pulsa `Enter` | Lightpanda no implementa foco/Tab ni :focus-visible: la comprobación de teclado no es medible en este entorno |
### Bloque AB

| # | Estado | Qué se comprobó | Detalle |
|---|---|---|---|
| AB1 | OK | Con 30 formularios | tarjetas=12 pager=True |
| AB2 | OK | El botón de la derecha del paginador | url=http://127.0.0.1:10001/dashboard?page=2 tarjetas=12 cambian=True |
| AB3 | OK | Escribe en el buscador y para | url=http://127.0.0.1:10001/dashboard?estado=&orden=updated&q=norte&orden=updated tarjetas=1 valor=norte |
| AB4 | OK | Escribe otra cosa después | url=http://127.0.0.1:10001/dashboard?estado=&orden=updated&q=sur&orden=updated tarjetas=1 |
| AB5 | OK | Escribe lo mismo que ya estaba (un espacio de más) | antes=http://127.0.0.1:10001/dashboard?q=norte despues=http://127.0.0.1:10001/dashboard?q=norte |
| AB6 | OK | Pulsa «Borradores» | url=http://127.0.0.1:10001/dashboard?estado=draft tarjetas=10 draft=True |
| AB7 | OK | Con un filtro puesto, escribe en el buscador | url=http://127.0.0.1:10001/dashboard?estado=draft&orden=updated&q=casa&orden=updated tarjetas=1 |
| AB8 | OK | Con filtro y búsqueda, cambia el orden | url=http://127.0.0.1:10001/dashboard?estado=draft&orden=updated&q=casa&orden=alpha |
| AB9 | OK | Cambia el orden a «Alfabético» | got=['[BT] AB 86c37761 Ábaco', '[BT] AB 86c37761 abeja', '[BT] AB 86c37761 árbol', '[BT] AB 86c37761 barco', '[BT] AB 86c37761 Casa'] esperado=['[BT] AB 86c37761 Ábaco', '[BT] AB 86c37761 abeja', '[BT] AB 86c37761 árbol', '[BT] AB 86c37761 barco', '[BT] AB 86c37761 Casa'] iguales=True |
| AB10 | OK | Ordena por «Más respuestas» | primera='[BT] AB 86c37761 barco' respuestas=7 stats=[30, 12, 10, 15] |
| AB11 | OK | Copia la URL del filtro a otra pestaña | url=http://127.0.0.1:10001/dashboard?estado=draft&orden=updated&q=casa&orden=alpha iguales=True |
| AB12 | OK | Pulsa el **atrás** del navegador tras filtrar | url=http://127.0.0.1:10001/dashboard tarjetas=12 activos=0 |
| AB13 | OK | Escribe una URL a mano con `?estado=inventado` | tarjetas=12 |
| AB14 | OK | Escribe una URL a mano con `?page=999` | tarjetas=6 url=http://127.0.0.1:10001/dashboard?page=999 |
| AB15 | OK | Filtra por un estado donde no tienes nada | sin=True quitar=True |
| AB16 | OK | Borra todos tus formularios y entra al panel | texto='VanillaForms\nDashboard\nNuevo formulario\nA AB Empty 86c37761 user\nHola, AB Empty 86c37761 👋\n\nGestiona tus formularios y a' filtros=0 |
| AB17 | OK | Con filtro puesto, mira las cuatro tarjetas de arriba | stats=[30, 12, 10, 15] tarjetas=10 |
| AB18 | OK | Todo esto **sin JavaScript** (desactivado) | noscript=True buscar=True ordenar=True filtrar=True paginar=True |
| AB19 | OK | Con teclado: `/` con el foco fuera de un campo | foco=dashSearch |
| AB20 | OK | `/admin` → «Formularios» con más de veinte | status=200 tarjetas=20 next=True p2=20 |
| AB21 | OK | `/admin/forms?page=999` | status=200 pagina=('18', '18') tarjetas=16 |
| AB22 | OK | `/admin/forms` sin JavaScript | enlace=<a class="page-link" href="/admin/forms?page=2" data-vf-page="2" rel="next" aria pagina2=200 |
| AB23 | OK | Con veinte formularios en la base, mira `/dashboard` con el profiler (o `EXPLAIN`) | LIMIT=True agregado_pagina=True explain_filas=4 |
| AB24 | N/A | La fila de filtros, con el buscador y el desplegable de orden juntos | Lightpanda no calcula layout fiable: bounding boxes 5/20/5 y getComputedStyle vacío; la regla CSS está verificada (form-select-sm compartido, sin rescate de altura: True) |
### Bloque AC

| # | Estado | Qué se comprobó | Detalle |
|---|---|---|---|
| AC1 | OK | Abrir «Nuevo formulario» | dialog=modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable header=modal-header app-navbar text-white border-0 py-2 |
| AC2 | OK | Mirar la rejilla | names=['Formulario en blanco', 'Control de asistencia', 'Encuesta de satisfacción', 'Feedback de curso (multi-paso)', 'Inscripción a evento', 'Quiz de conocimiento', 'Sondeo rápido'] counts=['7 preguntas', '6 preguntas · 1 sección', '12 preguntas · 3 secciónes', '11 preguntas · 3 secciónes', '4 preguntas · 1 sección', '3 preguntas'] |
| AC3 | OK | Al abrir | checked='' clase=app-tpl-card app-tpl-checked |
| AC4 | OK | Buscar campos | sin campos de título/descripción |
| AC5 | OK | Pulsar una tarjeta de plantilla | checked=control-asistencia |
| AC6 | OK | Volver a pulsar «Formulario en blanco» | checked='' |
| AC7 | OK | Pulsar «Crear» sin tocar nada | id=265639 title='Formulario en blanco' desc='Empieza desde cero y añade tus propias preguntas.' |
| AC8 | OK | Con una plantilla, crear | id=265640 title='Sondeo rápido' desc='Tres preguntas cortas, ideal para medir en un' fields=3 |
| AC9 | OK | Con la ventana baja, abrir el modal | form es .modal-content y el diálogo es scrollable |
| AC10 | OK | Con teclado: `Tab` por las tarjetas y `Espacio` | Tab no emulable en Lightpanda; foco programático + Espacio sí; focus=template seleccionado='control-asistencia' |
| AC11 | OK | Todo esto **sin JavaScript** (marcar una plantilla con el ratón) | status=200 title='Sondeo rápido' fields=3 |
| AC12 | OK | En móvil estrecho (360px) | rejilla_auto=True media_movil=True (layout no medible) |
### Bloque AD

| # | Estado | Qué se comprobó | Detalle |
|---|---|---|---|
| AD1 | OK | Abrir `/admin/security-log` | buscador + desplegable con ancho acotado como /admin/forms |
| AD2 | OK | Abrir `/admin` | tabs=['Usuarios', 'Grupos de roles', 'Roles y permisos', 'Log de seguridad', 'Formularios', 'Ajustes de la instancia'] |
| AD3 | OK | Abrir `/admin/settings` | registro y revisión viven en /admin/settings, no en la ficha de usuarios |
| AD4 | OK | Como alguien **sin** `admin.access` | status=403 |
| AD5 | OK | Guardar la revisión en `/admin/settings` | status=200 destino=127.0.0.1:10001/admin/settings aviso=guard |
| AD6 | OK | Abrir `/admin/groups` | tarjetas=2 toggles=2 badges=2 collapse='collapse show' efecto=True |
| AD7 | OK | Pulsar «Nuevo grupo» | form_movido=1 gid=28257 marker=1 |
| AD8 | OK | Guardar un grupo sin recarga | sin_recarga=1 resumen='AD Grupo 997433\nDescripción nueva 997433\n0 roles0 miembros' |
| AD9 | OK | Abrir `/admin/users` y pulsar «Nuevo usuario» | columnas=7 otras=0 |
| AD10 | OK | Pulsar el `⋯` de una fila | titulo='Acciones de AD Uno 997433' columnas=3 paneles=1 |
| AD11 | OK | Cambiar el rol desde ese modal | role=3 sin_recarga=1 insignia='editor' |
| AD12 | OK | Cerrar el modal y abrir el de otra fila | titulo='Acciones de AD Dos 997433' panel_nuevo=1 panel_viejo=0 |
| AD13 | OK | Marcar grupos en el modal | marcar_no_cierra=True error_en_modal=True form=1 |
| AD14 | OK | Todo lo anterior **sin JavaScript** | grupo_nativo=28258 rol_nativo=3 |
| AD15 | OK | En móvil (390px) | css_tarjetas=True celdas_con_label=200 (layout no medible) |
| AD16 | OK | Pulsar el bloque de usuario al pie de la sidebar | aria-expanded=true orden_menu_antes_boton=True |
### Bloque AE

| # | Estado | Qué se comprobó | Detalle |
|---|---|---|---|
| AE1 | OK | Lista recién importada, nadie invitado | y dejar solo lo que dice el archivo Subir y mapear columnas Atributos No hay atributos. Salen de las columnas del archivo que subas, y se usan para filtrar y agrupar las respuestas. Vaciar la lista quita a todas las personas. El catálogo de atributos se queda. Vaciar la lista Correo Nombre completo Teléfono Añadir a la lista Agregar participante Editar a © 2026 VanillaForms · Vanilla PHP + MySQL + nginx Confirmar la acción Cancelar Sí, continuar  |
| AE2 | OK | Con 30 invitados y sin respuestas | invitaciones@=694 recordatorios@=741 pendientes=30 |
| AE3 | OK | Pulsa el botón con SMTP configurado | status=200 mails=30 marcados=30 pendientes=30 msg=Se mandaron 30 recordatorios. |
| AE4 | OK | Comprueba que los dos números son distintos | porInvitar=5 porRecordar=30 total=35 |
| AE5 | OK | Abre el correo que acaba de llegar | con_fecha=True url=True sin_fecha_limpia=True |
| AE6 | OK | Con identificador «cédula» o «indicador» |  solo lo que dice el archivo Subir y mapear columnas Atributos No hay atributos. Salen de las columnas del archivo que subas, y se usan para filtrar y agrupar las respuestas. Vaciar la lista quita a todas las personas. El catálogo de atributos se queda. Vaciar la lista Cédula Nombre completo Correo Teléfono Añadir a la lista Agregar participante Editar a © 2026 VanillaForms · Vanilla PHP + MySQL + nginx Confirmar la acción Cancelar Sí, continuar  |
| AE7 | OK | Con `MAIL_HOST` vacío | status=200 msg=No hay servidor de correo configurado (MAIL_HOST vacío), así que el envío está apagado. Los enlaces de la columna «Código» siguen funcionando. mails=0 pendientes=30 |
| AE8 | OK | Pulsando con el formulario **cambiado a cédula** justo antes | status=200 (doc dice 403; el handler responde ok:false) msg=El recordatorio solo se manda cuando el identificador del formulario es «correo electrónico». Con cedula el enlace va en la columna «Código» de la lista, y cada uno lo reparte quien lo gestiona. mails=0 |
| AE9 | OK | Pulsando en un formulario que **no es de lista** | status=404 mails=0 |
| AE10 | OK | Pulsa el botón **otra vez** al día siguiente | status=200 mails=30 con_dos=30 msg=Se mandaron 30 recordatorios. |
| AE11 | OK | Deja caer el SMTP a mitad de una tanda (con 20 pendientes) | status=200 mails=4 marcados=4 pendientes=20 msg=Se mandaron 4 recordatorios. Se paró tras 3 fallos seguidos: el servidor de correo no está aceptando mensajes. Se mandaron 4 antes. |
| AE12 | OK | Tras AE11, comprueba quién quedó marcado | marcados=4 sin_marcar=16 |
| AE13 | OK | Marca a alguien como «respondió» en la lista | pendientes=19 texto=a lista quita a todas las personas. El catálogo de atributos se queda. Vaciar la lista Correo Nombre completo Teléfono Añadir a la lista Agregar participante Editar a © 2026 VanillaForms · Vanilla PHP + MySQL + nginx Confirmar la acción Cancelar Sí, continuar  |
| AE14 | OK | Con más de 50 pendientes | status=200 mails=50 marcados=50 pendientes=60 msg=Se mandaron 50 recordatorios. Se mandaron 50 recordatorios (el tope de esta tanda). Quedan 10 por responder. |
| AE15 | OK | Dos tandas seguidas con más de 50 pendientes | primera=50 segunda=50 nuevos_primero=True solapan=40 max=2 marcados=60 |
| AE16 | OK | El confirmador del botón | confirmador de recordatorio |
| AE17 | OK | Tras un envío, mira la lista y el catálogo de atributos | html=['rosterInvites'] |
| AE18 | OK | Pulsa el botón **sin JavaScript** | status=302 mails=3 marcados=3 |
| AE19 | N/A | Con teclado: tabula hasta el botón y pulsa `Enter` | Lightpanda no emula Tab ni :focus-visible; el diálogo y el foco en Cancelar no son medibles en este entorno |
| AE20 | OK | Borra a una persona de la lista | status=200 antes=2 despues=1 |
### Bloque AF

| # | Estado | Qué se comprobó | Detalle |
|---|---|---|---|
| AF1 | OK | `/admin/settings`, tarjeta «Marca de la instancia» | tarjeta con nombre, color, logo y favicon |
| AF2 | OK | Escribe un nombre y guarda | status=200 url=http://127.0.0.1:10001/admin/settings nombre=BT Marca 56918cdb |
| AF3 | OK | Elige un color en el selector | texto=#2a7f62 primary=#2a7f62 |
| AF4 | OK | Escribe un color **inválido** («azul») y guarda | azul rechazado y ficha sin color guardado |
| AF5 | OK | Borra el nombre y guarda | app_name=VanillaForms |
| AF6 | OK | Sube un logo (PNG) | status=302 url=['/branding/logo?v=68'] ctype=image/png |
| AF7 | OK | Sube un `.txt` renombrado a `.png` | status=302 aviso=True mismo_logo=True |
| AF8 | OK | Sube un favicon | vistas=panel,print,fill,cerrado,borrador,en revisión,acceso,error,login |
| AF9 | OK | Quita el logo | status=200 branding_logo=404 |
| AF10 | OK | Borra el archivo de `storage/branding/` a mano y recarga | fila viva, archivo ausente, fallback al icono |
| AF11 | OK | Apunta `GET /branding/loquesea` | status=404 |
| AF12 | OK | Un usuario sin `admin.access` intenta el POST de marca | status=403 nombre=None |
| AF13 | OK | Todo lo anterior **sin JavaScript** | nombre_status=200 nombre=['BT AF nativo 56918cdb'] subida=200 flash_subida=True meta=True archivo=True borrado=200 flash_borrado=True |
| AF14 | OK | Con `APP_KEY` vacía y un ajuste de marca guardado | status=200 primary=#334155 |
| AF15 | OK | Elige dos colores de degradado y guarda | vars={'--vf-primary': '#334155', '--vf-grad-start': '#ffffee', '--vf-grad-end': '#ffffcc', '--vf-on-grad': '#1e293b'} |
| AF16 | OK | Deja vacío el color de texto con un degradado oscuro | vars={'--vf-primary': '#334155', '--vf-grad-start': '#111111', '--vf-grad-end': '#222222', '--vf-on-grad': '#ffffff'} |
| AF17 | OK | Elige a mano un color de texto y guarda | on_grad=#abcdef |
| AF18 | OK | Escribe un degradado «azul» y guarda | status=200 antes={'instance_color': '#334155', 'instance_color_grad_end': '#222222', 'instance_color_grad_start': '#111111', 'instance_color_text': '#abcdef', 'instance_favicon': '{"stored":"08b554e36503669b6ccc284e8dd8a953.png","mime":"image/png","size":68}'} despues={'instance_color': '#334155', 'instance_color_grad_end': '#222222', 'instance_color_grad_start': '#111111', 'instance_color_text': '#abcdef', 'instance_favicon': '{"stored":"08b554e36503669b6ccc284e8dd8a953.png","mime":"image/png", |
| AF19 | OK | Sube un logo y mira la tarjeta en un móvil (o ventana estrecha) | app-logo/app-logo-img/app-logo-lg conservan caja y proporción |
### Bloque AG

| # | Estado | Qué se comprobó | Detalle |
|---|---|---|---|
| AG1 | OK | Entra a `/admin/settings` | tarjeta inicial sin configurar |
| AG2 | OK | Rellena servidor, puerto, cifrado, usuario y contraseña, y guarda | status=200 url=http://127.0.0.1:10001/admin/settings |
| AG3 | OK | Reabre tras guardar la contraseña | password vacío + insignia guardada |
| AG4 | OK | Guarda otro campo (p. ej. el nombre del remitente) sin tocar la contraseña | contraseña intacta al guardar el nombre |
| AG5 | OK | Marca «Borrar la contraseña guardada» y guarda | fila de contraseña borrada |
| AG6 | OK | Escribe un puerto `70000` y guarda | status=200 antes={'mail_encryption': '', 'mail_from_address': 'noreply@bt.local', 'mail_from_name': 'BT', 'mail_host': '127.0.0.1', 'mail_port': '44421', 'mail_timeout': '10', 'mail_user': 'bt-user'} despues={'mail_encryption': '', 'mail_from_address': 'noreply@bt.local', 'mail_from_name': 'BT', 'mail_host': '127.0.0.1', 'mail_port': '44421', 'mail_timeout': '10', 'mail_user': 'bt-user'} |
| AG7 | OK | Escribe un remitente que no es correo y guarda | status=200 antes={'mail_encryption': '', 'mail_from_address': 'noreply@bt.local', 'mail_from_name': 'BT', 'mail_host': '127.0.0.1', 'mail_port': '44421', 'mail_timeout': '10', 'mail_user': 'bt-user'} despues={'mail_encryption': '', 'mail_from_address': 'noreply@bt.local', 'mail_from_name': 'BT', 'mail_host': '127.0.0.1', 'mail_port': '44421', 'mail_timeout': '10', 'mail_user': 'bt-user'} |
| AG8 | OK | Deja el servidor en blanco y guarda | host borrado y aviso sin configurar |
| AG9 | OK | Con el SMTP bien configurado, pulsa «Enviar prueba» | status=200 mails=1 rcpts=[['destino@bt.local']] flash=Correo de prueba enviado a destino@bt.local. \| Sin claves, los formularios que tengan el anti-bot encendido no se pueden responder (la app funciona igual: solo quedan bloqueados esos). |
| AG10 | OK | Pulsa «Enviar prueba» con un servidor inexistente | status=200 aviso=True |
| AG11 | OK | Pulsa «Enviar prueba» sin destino | status=200 mails=1 rcpts=[['admin@demo.com']] flash=Correo de prueba enviado a admin@demo.com. \| Sin claves, los formularios que tengan el anti-bot encendido no se pueden responder (la app funciona igual: solo quedan bloqueados esos). |
| AG12 | OK | Inspecciona la tabla `app_settings` tras guardar la contraseña | largo=56 contiene_plano=False |
| AG13 | OK | Sin `APP_KEY`, intenta guardar una contraseña | status=200 aviso=True sin_cambios=True |
| AG14 | OK | Un usuario sin `admin.access` intenta los POST de correo | guardar=403 probar=403 |
| AG15 | OK | Todo lo anterior **sin JavaScript** | guardar=302 probar=302 mails=1 flash=Correo de prueba enviado a nativo@bt.local. \| Sin claves, los formularios que tengan el anti-bot encendido no se pueden responder (la app funciona igual: solo quedan bloqueados esos). |
### Bloque AH

| # | Estado | Qué se comprobó | Detalle |
|---|---|---|---|
| AH1 | OK | Entra a `/admin/settings` | tarjeta identidad |
| AH2 | OK | Escribe una URL como `https://formularios.midominio.com` y guarda | status=200 url=http://127.0.0.1:10001/admin/settings |
| AH3 | OK | Copia el enlace de compartir de un formulario | enlace usa URL pública |
| AH4 | OK | Cambia la zona horaria a `America/Bogota` y guarda | zona horaria guardada |
| AH5 | OK | Cambia el idioma a «Inglés» y recarga un formulario público | idioma por defecto guardado |
| AH6 | OK | Vuelve a dejar un campo en blanco y guarda | vuelve al .env |
| AH7 | OK | Escribe una URL inválida (p. ej. `no es una url`) y guarda | URL inválida rechazada sin cambios |
| AH8 | OK | Intenta guardar una zona inventada (`Marte/Olympus`) | zona inválida rechazada |
| AH9 | OK | Inspecciona `app_settings` tras guardar | filas en app_settings |
| AH10 | OK | Un usuario sin `admin.access` intenta el POST de identidad | 403 y no cambia (403) |
| AH11 | OK | Todo lo anterior **sin JavaScript** | nativo PRG |
### Bloque AI

| # | Estado | Qué se comprobó | Detalle |
|---|---|---|---|
| AI1 | OK | Entra a `/admin/settings` | tarjeta anti-bot sin configurar |
| AI2 | OK | Rellena la site key y la secret y guarda | status=200 |
| AI3 | OK | Reabre tras guardar la secret | secret oculta y guardada |
| AI4 | OK | Guarda solo otro campo sin tocar la secret | secret intacta al guardar solo site |
| AI5 | OK | Marca «Borrar la secret key guardada» y guarda | status=200 alertas=Turnstile necesita la site key Y la secret key a la vez: con una sola, los formularios con anti-bot dejarían de servirse. \| No hay ningún servidor configurado, así que los correos no se envían (la app funciona igual: solo no salen los avisos). |
| AI6 | OK | Guarda solo la site key (sin secret) | rechaza solo site: Turnstile necesita la site key Y la secret key a la vez: con una sola, los formularios con anti-bot dejarían de servirse. \| No hay ningún servidor configurado, así que los correos no se envían (la app funciona igual: solo no salen los avisos). \| Sin claves, los formularios que tengan el anti-bot encendido no se pueden responder (la app funciona igual: solo quedan bloqueados esos). |
| AI7 | OK | Guarda solo la secret (sin site key) | rechaza solo secret: Turnstile necesita la site key Y la secret key a la vez: con una sola, los formularios con anti-bot dejarían de servirse. \| No hay ningún servidor configurado, así que los correos no se envían (la app funciona igual: solo no salen los avisos). \| Sin claves, los formularios que tengan el anti-bot encendido no se pueden responder (la app funciona igual: solo quedan bloqueados esos). |
| AI8 | OK | Deja ambas en blanco y guarda | ambas borradas |
| AI9 | OK | Inspecciona `app_settings` tras guardar la secret | largo=64 contiene_plano=False |
| AI10 | OK | Sin `APP_KEY`, intenta guardar una secret | status=200 sin_cambios=True |
| AI11 | OK | Un usuario sin `admin.access` intenta el POST de Turnstile | 403 (403) |
| AI12 | OK | Todo lo anterior **sin JavaScript** | nativo 302 |
### Bloque AJ

| # | Estado | Qué se comprobó | Detalle |
|---|---|---|---|
| AJ1 | OK | Abre `/install.php` en una instalación ya hecha | 403 |
| AJ2 | OK | Abre `/install.php` con `?force` en una instalación hecha | 200 |
| AJ3 | OK | Paso «Requisitos» con todo correcto | 200 |
| AJ4 | OK | Quita permiso de escritura a `storage/uploads` y recarga | 200 |
| AJ5 | OK | Rellena host/base/usuario/clave válidos y continúa | 200 http://127.0.0.1:38617/install.php?paso=admin |
| AJ6 | OK | Escribe un nombre de base con `;` o espacio | 200 |
| AJ7 | OK | Contraseña de BD incorrecta | 200 |
| AJ8 | OK | Crea el admin con email inválido | 200 |
| AJ9 | OK | Crea el admin con contraseña corta o distinta a la repetición | 200/200 |
| AJ10 | OK | Completa el paso admin y termina | 200 http://127.0.0.1:38617/install.php?paso=listo |
| AJ11 | OK | Entra con el email y la contraseña elegidos | 200 http://127.0.0.1:38617/dashboard role=1/1 |
| AJ12 | OK | Inspecciona `app_settings` y `users` | admin y app_settings |
| AJ13 | OK | Mira el `.env` tras instalar | mode=0o600 key=44 |
| AJ14 | OK | Inspecciona `storage/installed.lock` | {'installed_at': '2026-11-02T03:12:49+00:00', 'admin_email': 'aj-admin@test.local', 'php_version': '8.4.27'} |
| AJ15 | OK | Reabre `/install.php` tras terminar | 403 |
| AJ16 | OK | Repite la instalación teniendo ya `APP_KEY` en el `.env` | key_igual=True |
| AJ17 | OK | Intenta el POST del instalador sin token CSRF | 400 |
### Bloque B

| # | Estado | Qué se comprobó | Detalle |
|---|---|---|---|
| B1 | OK | Registrarse en `/register` con email y contraseña de 12 caracteres | url=http://127.0.0.1:10001/dashboard |
| B2 | OK | Registrarse con contraseña de 9 caracteres | VanillaForms La contraseña debe tener al menos 10 caracteres. Nombre completo Email Contraseña Confirmar contraseña Crear cuenta  ¿Ya tienes cuenta? Inicia sesi |
| B3 | OK | Registrarse con email ya existente | VanillaForms No se pudo completar el registro. Verifica los datos e intenta de nuevo. Nombre completo Email Contraseña Confirmar contraseña Crear cuenta  ¿Ya tienes cuenta? Inicia  |
| B4 | OK | Mirar el campo de contraseña de `/register` | placeholder='Mínimo 10 caracteres' minlength=10 server=10 |
| B5 | OK | Entrar con contraseña incorrecta | Credenciales incorrectas. |
| B6 | OK | Entrar con un email inexistente | iguales=True |
| B7 | OK | Fallar el login 6 veces seguidas con la misma IP | VanillaForms Demasiados intentos de inicio de sesión. Intenta de nuevo en unos minutos. Email Contraseña ¿Olvidaste tu contraseña? Iniciar sesión  ¿No tienes cu |
| B8 | OK | Usar un email inexistente en `/forgot-password` | urls=http://127.0.0.1:10001/forgot-password==http://127.0.0.1:10001/forgot-password textos='VanillaForms\nSi esa dirección está registrada, te enviamos u'/'VanillaForms\nSi esa dirección está registrada, te enviamos u' |
| B9 | OK | Pedir recuperación con un email real, usar el enlace | url=http://127.0.0.1:10001/reset-password?t=c52a5a547ccaca495f4c5107bf2f7ad1 |
| B10 | OK | Reutilizar ese mismo enlace una segunda vez | reset=True url=http://127.0.0.1:10001/reset-password?t=c52a5a547ccaca495f4c5107bf2f7ad1 texto='VanillaForms\nEste enlace no es válido o ha caducado. Pide uno nuevo desde «Olvidé mi contraseña». Pedir un enlace nuevo\n' |
| B11 | OK | Con la sesión abierta, cambiar la contraseña desde la cuenta | login_nueva=True url=http://127.0.0.1:10001/account/password |
| B12 | OK | Cambiar la contraseña de un usuario desde `/admin` y usar su sesión | antes=200 despues=http://127.0.0.1:10001/login (no hay UI en /admin; se probó el mecanismo del modelo) |
| B13 | OK | Entrar en `/dashboard` sin sesión | url=http://127.0.0.1:10001/login |
| B14 | OK | Desactivar un usuario desde `/admin` y usar su sesión | status=200 url=http://127.0.0.1:10001/login |
| B15 | OK | Cerrar sesión y volver atrás | url=http://127.0.0.1:10001/login |
| B16 | OK | Con `REGISTRATION_ENABLED=false` (por defecto), abrir `/login` | enlace=False |
| B17 | OK | Con el registro cerrado, entrar en `/register` | status=403 |
| B18 | OK | Con el registro cerrado, mandar un POST a `/register` a mano | status=403 texto='<!DOCTYPE html>\n<html lang="es">\n<head>\n    <meta charset="UTF-8">\n    <meta name="viewport" content' |
| B19 | OK | Crear un usuario desde `/admin` con el registro cerrado | http=302 fila=['315136', '2', '1'] |
| B20 | OK | Con el registro cerrado, en `/admin` → **Nuevo usuario**, crear una cuenta con rol `user` | login=http://127.0.0.1:10001/dashboard |
| B21 | OK | Crear esa misma cuenta otra vez con el mismo email | admin=200/http://127.0.0.1:10001/admin post=200 <!DOCTYPE html> <html lang="es"> <head>     <meta charset="UTF-8">     <meta name="viewport" content="width=device-width |
| B22 | OK | Crear una cuenta con contraseña de 9 caracteres | http=302 |
| B23 | OK | Crear una cuenta con contraseñas que no coinciden | http=302 |
| B24 | OK | Entrar en `/admin` con un rol que **no** tenga `admin.users` | get=403 post=403 |
| B25 | OK | Crear una cuenta desde `/admin` y luego mirar su ficha | ['315136', '2', '1'] |
| B26 | OK | Crear un admin desde `/admin` y entrar con él | status=200 |
| B27 | OK | Activar el registro desde `/admin` (interruptor) y abrir `/login` en una ventana sin sesión | post=200 link=True register=200 |
### Bloque C

| # | Estado | Qué se comprobó | Detalle |
|---|---|---|---|
| C1 | OK | `/dashboard` con formularios | 200 simple=True |
| C2 | OK | Crear un formulario | post=200 url=http://127.0.0.1:10001/forms/264029/builder status=draft builder=200 |
| C3 | OK | Botón de duplicar un formulario | 63->64 dup=['264030', '[BT] C2 nuevo (copia)'] |
| C4 | OK | Pulsar «Crear formulario» 31 veces en una hora | creados=30 bloqueado=True |
| C5 | OK | Navegar por todas las entradas del menú | rutas=52 malas=[] |
| C6 | OK | Entrar al panel con una cuenta sin `admin.access` | 403 |
| C7 | OK | Crear un formulario nuevo y volver al listado | list=200 ficha=200 |
| C8 | OK | Abrir el panel en escritorio (ventana ≥ 992 px) | sidebar sticky de escritorio |
| C9 | OK | Bajar con la rueda en escritorio | sin_scroll_horizontal=True |
| C10 | OK | Abrir el panel en móvil (ventana ≤ 991 px) y pulsar la hamburguesa | class=app-sidebar offcanvas-lg offcanvas-start show vis=visible |
| C11 | OK | En móvil, abrir el cajón y mirar el menú y el bloque de usuario | textos del menú/usuario presentes |
| C12 | OK | En móvil, mirar los botones del contenido (p. ej. «Nuevo formulario» del panel) | botones de contenido con icono se reducen a icono |
| C13 | OK | Comparar, en escritorio, la cabecera de la ficha, el botón «Nuevo formulario» del panel y la cabecera del builder | CTA y cabecera comparten clases y alto declarado |
| C14 | FALLA | En móvil, entrar en Administración y mirar la sub-navegación (Usuarios, Grupos de roles, Roles y permisos…) | la subnavegación de /admin conserva los rótulos en móvil; la doc espera solo iconos |
| C15 | OK | En móvil, mirar los botones de crear de Admin (grupo/rol) y el desplegable de grupos de una fila | crear con icono y badges legibles |
| C16 | OK | Comparar en escritorio las cabeceras de respuestas, analítica y administración (usuarios, formularios, log) y la **sub-navegación de Administración** (Usuarios, Grupos de roles, Roles y permisos…) con la de la ficha | reglas de alineación presentes |
### Bloque D

| # | Estado | Qué se comprobó | Detalle |
|---|---|---|---|
| D1 | OK | Abrir el constructor de un formulario vacío | lienzo vacío con estado y botón |
| D2 | OK | Agregar un campo al lienzo | tarjeta con tipo, obligatoria y herramientas |
| D3 | FALLA | Pulsar el engranaje de una tarjeta | la doc pide cajón derecho; el constructor usa modal centrado (una sola instancia) |
| D4 | OK | Editar etiqueta y opciones, luego **Guardar** y reabrir | etiqueta y opciones persisten |
| D5 | OK | Cambiar el orden de dos campos, **Guardar**, reabrir | orden antes=['text', 'email'] después=['email', 'text'] (drag no soportado; onEnd real) |
| D6 | OK | Añadir 21 tipos de campo distintos | 21 tipos, tarjetas=21 |
| D7 | OK | Editar un campo, cambiar de pestaña a **Lógica** y guardar | cambiar de pestaña conserva contenido |
| D8 | OK | Poner un campo obligatorio y enviar el formulario vacío | servidor rechaza obligatorio vacío |
| D9 | OK | Poner mínimo/máximo/longitud en un campo de texto y número | mensajes por tipo: longitud y máximo |
| D10 | FALLA | Configurar una matriz con una fila incompleta al enviar | la doc espera la fila concreta; el servidor dice «todas las filas» sin nombrar la fila |
| D11 | OK | Configurar un ranking con dos opciones en la misma posición | ranking duplicado rechazado |
| D12 | OK | Marcar un campo de texto como **dato personal** y guardar | marca PII persiste |
| D13 | OK | Intentar marcar como PII una sección o una página | sección no ofrece marcar PII |
| D14 | OK | Guardar con el navegador bloqueando peticiones | fallo de red se avisa, no dice que guardó |
| D15 | OK | Cambiar algo, recargar con F5 sin pulsar **Guardar** | recargar sin Guardar pierde el cambio |
| D16 | OK | Crear una condición que se referencia a sí misma (o dentro de su propio bloque) | ciclo autorreferente rechazado con HTTP 422 |
| D17 | FALLA | Escribir una etiqueta con datos personales («¿Tu nombre y apellidos?») | la etiqueta «nombre» no dispara el aviso: PII_HINT_RE usa nomb[re], que no casa con «nombre» |
| D18 | OK | En ese aviso, pulsar **Sí, es dato personal** | Sí marca y guarda PII (con «correo») |
| D19 | OK | Escribir una etiqueta personal y pulsar **No es** | No es descarta el aviso |
| D20 | OK | Con el aviso descartado, **reescribir** la etiqueta con otras palabras | al reescribir la etiqueta vuelve el aviso |
| D21 | FALLA | Poner una etiqueta con «Nombre de la empresa» | «Nombre de la empresa» tampoco dispara el aviso por el mismo patrón roto |
| D22 | OK | Abrir el engranaje de una sección o una página | sección/página sin aviso PII |
| D23 | FALLA | Abrir **Configuración** del formulario y cambiar el color principal | la doc espera preview que cambie al instante; el constructor ya no tiene preview embebido |
| D24 | OK | Abrir **Configuración**, elegir una tipografía del catálogo | nota verde de fuente local |
| D25 | OK | Abrir **Configuración**, dejar la tipografía en **Predeterminada** | por defecto sin @font-face en el publicado |
| D26 | OK | En la configuración, activar la confirmación y mirar **Campo email** | lista de campos email para confirmación |
| D27 | OK | Buscar en la pantalla de configuración un ajuste sin explicación | 1 campos numéricos con contexto |
### Bloque E

| # | Estado | Qué se comprobó | Detalle |
|---|---|---|---|
| E1 | FALLA | Crear un formulario y abrir su enlace sin publicar | la doc espera 403; hoy devuelve 200 con la página «En preparación» (decisión F3) |
| E2 | OK | Publicar y abrir el enlace | GET publicado -> 200 |
| E3 | OK | Cerrar el formulario y abrir el enlace | cerrado: 5 respuestas siguen, GET 200 |
| E4 | OK | Con revisión activada, pulsar **Publicar** | publicar con revisión -> status draft, 302 |
| E5 | OK | Con revisión activada, editar un formulario ya aprobado | save 200; status=['draft', 'NULL', 'NULL'] |
| E6 | OK | Aprobar un formulario propio | aprobar propio -> approved, log=['formulario #263833 (bt-revisi-n) propio'] |
| E7 | OK | Intentar aprobar sin permiso `forms.review` | user sin forms.review -> 403, status in_review |
| E8 | OK | Abrir un formulario en revisión por su enlace | GET 200, POST 403 |
| E9 | OK | Devolver a borrador un formulario en revisión | devolver -> draft |
| E10 | OK | Aprobar un formulario ya publicado | aprobar publicado -> status published, HTTP 302 |
| E11 | OK | Programar apertura futura (`open_at`) | futuro bloquea (200), pasado sirve (200) |
| E12 | OK | Poner `close_at` anterior a `open_at` | cierre anterior a apertura: 200 |
| E13 | OK | Alcanzar el cupo máximo de respuestas | cupo lleno: GET 200, POST 403 |
| E14 | OK | Poner el cupo a 0 sin activarlo | cupo 0 sin activar -> 200 |
### Bloque F

| # | Estado | Qué se comprobó | Detalle |
|---|---|---|---|
| F1 | FALLA | Rellenar todo y enviar | la doc exige token; hoy 302 -> /f/bt-p-blico-simple/thanks (solo se añade ?t= con quiz activo) |
| F2 | OK | Abrir la URL de gracias de otra persona | quiz thanks anónimo -> 200, /f/bt-quiz/thanks?t=f5c0b46cc9b5511777bea68b8e111425 |
| F3 | OK | Reusar la URL de gracias cambiando el número del token | token alterado -> 200 |
| F4 | OK | Enviar un campo inválido saltándose la validación del navegador | inválido por servidor: 200, 15->15 |
| F5 | OK | Marcar un campo obligatorio con un espacio «   » | espacios: 200, 15->15 |
| F6 | N/A | Navegar un formulario de 3 páginas con **solo** el teclado (Tab y flechas) | Lightpanda no implementa la tecla Tab; la travesía solo-teclado no es medible en este navegador |
| F7 | OK | En una escala, un NPS o unas estrellas, tabular hasta el control | escala=True, nps=True, estrellas=True, anillo CSS=True |
| F8 | OK | En multipágina, pulsar «Siguiente» con el teclado | foco tras Siguiente: {'tag': 'SECTION', 'cls': 'vf-page-block flip-in', 'txt': 'Paso 2 · El material'} |
| F9 | OK | Saltar entre pasos con los botones «Anterior» / «Siguiente» | foco tras Anterior=SECTION, primer campo=False |
| F10 | OK | Abrir la página al revés (Ctrl+R en Chrome) en un formulario de 3 páginas | recarga restaura: 'borrador F10' |
| F11 | OK | Un campo con condición que se cumple | oculto->visible: True->True, guardado=['valor B'] |
| F12 | OK | Un campo con condición que NO se cumple | envío 302; oculto no guardado=None |
| F13 | OK | Condición sobre un campo dentro de una sección con su propia condición | AND sección+hijo: False,True,False,False |
| F14 | OK | Responder el último paso de un multipágina | último paso -> 302 /f/feedback-de-curso-multi-paso-copia/thanks |
| F15 | OK | Abrir dos veces el mismo formulario con barajado activado | 4 órdenes distintos en 8 GET |
| F16 | OK | Con avance automático activado, responder un paso | auto avanza=True; recarga con borrador no avanza=True |
| F17 | OK | Con avance automático, un paso con un ranking de posiciones repetidas | ranking duplicado no avanza solo |
| F18 | OK | Insertar el `<iframe>` de embed en una página **del mismo dominio** | iframe mismo dominio: 2 frames |
| F19 | FALLA | Abrir `/f/{slug}/embed` sin el embed activado | embed desactivado -> 200 |
| F20 | OK | Cambiar el idioma con `?lang=en` (o el selector si existe) | ?lang=en -> 200 |
| F21 | OK | Elegir la 3.ª de 5 estrellas, y luego pasar el ratón por la 5.ª y salir | hover=5, fuera=3, valor=3 |
| F22 | OK | Responder un ranking con los botones ▲▼ (mover una fila arriba y otra abajo) | orden=['Dos', 'Uno', 'Tres'], inputs=['1', '2', '3'], guardado=['{"Uno":"2","Dos":"1","Tres":"3"}'] |
| F23 | OK | Un ranking con la ventana recargada con un borrador ya ordenado | Dos -> Dos |
| F24 | OK | Desactivar JavaScript y abrir un formulario con ranking | inputs=3, clase JS=False, CSS sin JS=True |
### Bloque G

| # | Estado | Qué se comprobó | Detalle |
|---|---|---|---|
| G1 | OK | Una condición de texto con cada operador (igual, distinto, contiene, no contiene, tiene valor, está vacío, regex) | lleno={'EQ': True, 'NEQ': False, 'CON': True, 'NCON': False, 'FILL': True, 'EMPTY': False, 'RE': True} vacío={'EQ': False, 'NEQ': True, 'CON': False, 'NCON': True, 'FILL': False, 'EMPTY': True, 'RE': False} otro={'EQ': False, 'NEQ': True, 'CON': False, 'NCON': True, 'FILL': True, 'EMPTY': False, 'RE': False} |
| G2 | OK | Un campo obligatorio cuya condición no se cumple | oculto 302 0->1; visible 200 1->1 |
| G3 | FALLA | Barajado activado + envío con errores | GET=['277209', '277210', '277208'] error=['277208', '277209', '277210'] |
| G4 | OK | Una página cuya condición la salta | bloques=[('Paso 1 de 2', None, False), ('Paso 2 de 3', '1', False), ('Paso 2 de 2', None, True)] |
| G5 | OK | Barra de progreso en un formulario con páginas omitidas | Avance=50% (A contestada de A+C; B omitida) |
| G6 | OK | Pie del paso actual | bloque='Paso 2 de 2', pie='AnteriorPaso 2 de 2EnviarEnviar' |
### Bloque H

| # | Estado | Qué se comprobó | Detalle |
|---|---|---|---|
| H1 | OK | Subir un PDF, una imagen y un .docx | POST 302; guardados=3 |
| H2 | OK | Subir un `.exe`, `.php` o `.js` | códigos=[200, 200, 200]; respuestas 1->1 |
| H3 | OK | Renombrar un `.exe` a `.pdf` | .exe renombrado: 200, respuestas 1->1 |
| H4 | OK | Subir un archivo de más tamaño del permitido | 1,2MB con máximo 1MB: 200, 0->0 |
| H5 | OK | Subir más archivos de los permitidos | dos con máximo 1: 200, 0->0 |
| H6 | OK | Enviar un formulario con archivo de más de `post_max_size` | 9MB > post_max_size: 413 |
| H7 | OK | Descargar un adjunto desde el detalle de la respuesta | descarga 200, 169 bytes |
| H8 | OK | Comprobar la ruta del archivo en el disco | stored=2026/11/e433b556e4a5c156d3ed13243361ac35.pdf, disco=True, URL directa=403 |
| H9 | FALLA | Borrar una respuesta con adjuntos y ejecutar `clean-uploads.php --dry-run` | borrada: archivo_en_disco=False; dry-run=Vaciaría 0 directorio(s) vacío(s). Base: /root/workspace/php/vanilla-forms/storage/uploads |
| H10 | OK | Un campo de archivo marcado como PII, descargado **sin** el permiso `responses.pii` | user sin responses.pii=403, admin=200 |
### Bloque I

| # | Estado | Qué se comprobó | Detalle |
|---|---|---|---|
| I1 | OK | Formulario en modo `public` (por defecto) | GET 200 con campo; POST 200 |
| I2 | OK | Cambiar a modo `email` y abrir el enlace sin haber pedido acceso | GET 200; pide acceso=True |
| I3 | N/A | Pedir acceso con un email de la lista | Sin SMTP configurado: no se puede comprobar la entrega del correo |
| I4 | OK | Pedir acceso con un email **fuera** de la lista | {'lista': (302, 'http://127.0.0.1:10001/f/bt-email/access'), 'fuera': (302, 'http://127.0.0.1:10001/f/bt-email/access')} |
| I5 | OK | Enviar el formulario sin acceso válido | POST sin acceso: 403 |
| I6 | OK | Usar un enlace de acceso de **otro formulario** | token de allowlist en email: 200, pide acceso=True |
| I7 | OK | Quitar un email de la lista y usar su enlace ya activating | antes=True, después_pide_acceso=True |
| I8 | OK | Abrir el formulario en una ventana privada y usar un enlace válido | con token=True, tras sesión=True, otra sesión pide=True |
| I9 | OK | Ver el enlace copiado y el QR de un formulario con acceso restringido | share=http://127.0.0.1:10001/f/bt-allowlist, QR=200, destino pide acceso=True |
| I10 | OK | Pantalla *Participantes* en modo `allowlist`, escribir un correo y pulsar *Añadir* | aviso_inicial=1, sin_recarga=True, filas=1, valor="", foco=accessAddEmail, aviso_final=0 |
| I11 | OK | En la misma caja, escribir un correo ya presente y pulsar *Añadir* | filas=1, error="i10@bt.local ya estaba en la lista." |
| I12 | OK | Escribir un correo sin formato y pulsar *Añadir* | filas=1, validez=False, typeMismatch=True, motivo="Please enter an email address." |
| I13 | OK | Añadir con el teclado (Enter en la caja) | sin_recarga=True, filas=2 |
| I14 | OK | Abrir la pantalla *Participantes* con JavaScript deshabilitado y añadir un correo | POST nativo 200; fila=True |
| I15 | OK | `/admin/settings` con un `user` normal | user -> 403 |
| I16 | OK | `/admin/settings` con un admin: mover el interruptor del registro | True->False, texto="Registro público cerrado", sin_recarga=True, BD=0 |
| I17 | FALLA | El interruptor, sin red (parar MySQL o cortar la conexión) | casilla True->False, aviso="No se pudo contactar con el servidor: el ajuste sigue como estaba.", clase="form-text mb-0 text-danger fw-semibold" |
| I18 | OK | Poner `REGISTRATION_ENABLED=true` en `.env` con el interruptor apagado y abrir `/register` | .env=true + fila=0 -> /register 403 |
### Bloque J

| # | Estado | Qué se comprobó | Detalle |
|---|---|---|---|
| J1 | OK | Responder un formulario con un campo marcado, entrando como `admin` | POST 200; GET admin 200; en_claro=True |
| J2 | OK | Ver el mismo detalle entrando como `user` | GET user 200; oculto=True, correo=True, libre=True |
| J3 | OK | Ver el listado de respuestas como `user` | GET user 200; ip_masked=True, a_la_vista=False |
| J4 | OK | Exportar a **CSV** como `user` | GET 200; filas=2, columnas=6, oculto=True |
| J5 | OK | Exportar a **JSON** como `user` | GET 200; total=1, respuestas=1, nombre=[oculto], correo=j***@e***.com |
| J6 | OK | Mirar la IP y el user-agent como `user` | user ip=True, ua_oculto=True; admin crudo=True |
| J7 | OK | Exportar como `user` con **muchas** respuestas (>5000) | GET 200; total=5001, filas=5001, ultima=[oculto] |
| J8 | OK | Un campo de texto **no** marcado como dato personal | detalle=True; csv=True; json=Comentario publico |
### Bloque K

| # | Estado | Qué se comprobó | Detalle |
|---|---|---|---|
| K1 | OK | Abrir la analítica de un formulario con respuestas | GET 200; chart=True, numero=True, detalle=True |
| K2 | OK | **Cambiar de página en la tabla de respuestas** | antes #1; pagina2 #16; clase=''; enlaces=5; abrir=200 |
| K3 | OK | Cambiar de página dos veces seguidas | volver #1; segunda vez #16; clase='' |
| K4 | FALLA | Si la petición falla, ver el error | avisos=[]; clase=''; enlaces=15 |
| K5 | OK | Exportar a CSV | status=200; disposition=attachment; filename="bt-k-analitica.csv"; content-type=text/csv; charset=utf-8; columnas=18 |
| K6 | OK | CSV con respuestas de matriz, ranking y casillas | filas=20; matriz='Fila 1: Bueno; Fila 2: '; ranking='Uno: 2; Dos: 3; Tres: 1'; casillas='Tres' |
| K7 | OK | CSV con un filtro de fechas activo | filas=10; fechas=['2026-10-21 14:10:00', '2026-10-21 14:11:00', '2026-10-21 14:12:00', '2026-10-21 14:13:00', '2026-10-21 14:14:00', '2026-10-21 14:15:00', '2026-10-21 14:16:00', '2026-10-21 14:17:00', '2026-10-21 14:18:00', '2026-10-21 14:19:00'] |
| K8 | OK | Exportar a JSON | status=200; claves=['form', 'submissions', 'total']; total=20; submission=['answers', 'finished_at', 'id', 'ip_address', 'user_agent'] |
| K9 | OK | Exportar la estructura del formulario | status=200; claves=['app', 'description', 'fields', 'key', 'name', 'settings', 'version']; campos=17 |
| K10 | OK | Exportar 121 veces en una hora | 200=120; ultimo=429 |
| K11 | OK | CSV con acentos y eñes | BOM=False; ñ=True; café=True; año=True |
| K12 | OK | En la tabla de detalle de la analítica, pulsar **Ver** en una fila (W1, 2026-10-19) | href=/forms/264207/responses/281572; GET=200; #=1 |
| K13 | OK | Mirar la columna **#** de esa misma tabla, primera y segunda página (W1, 2026-10-19) | pagina1 #1; pagina2 #16 |
| K14 | OK | En analítica, mirar una pregunta de texto libre con respuestas (W2, 2026-10-19) | total=True; valores=['Comentario número 19 con ñ y café', 'Comentario número 18 con ñ y café', 'Comentario número 17 con ñ y café', 'Comentario número 16 con ñ y café', 'Comentario número 15 con ñ y café']; fechas=5; ver=5; abrir=200 |
| K15 | OK | Con un filtro de fechas activo, mirar esa misma tarjeta (W2, 2026-10-19) | 21=['Comentario número 19 con ñ y café', 'Comentario número 18 con ñ y café', 'Comentario número 17 con ñ y café', 'Comentario número 16 con ñ y café', 'Comentario número 15 con ñ y café']; 20=['Comentario número 9 con ñ y café', 'Comentario número 8 con ñ y café', 'Comentario número 7 con ñ y café', 'Comentario número 6 con ñ y café', 'Comentario número 5 con ñ y café'] |
| K16 | OK | Con un campo de texto marcado como dato personal, sin `responses.pii` (W2, 2026-10-19) | admin_oculto=False; user_oculto=10 |
| K17 | OK | Mirar en analítica los tipos que antes no aparecían: URL, teléfono, hora, fecha y **archivo adjunto** (W2, 2026-10-19) | presentes={'URL': True, 'TEL': True, 'TIME': True, 'DATE': True, 'DT': True, 'FILE': True}; json_archivo=False |
| K18 | OK | En analítica, mirar una pregunta **numérica** con respuestas (W3, 2026-10-19) | answer_count=20; avg=9.65; dist=[(1, 1), (2, 2), (3, 1), (4, 1), (5, 1), (6, 1), (7, 1), (8, 1), (9, 1), (10, 1), (11, 1), (12, 1), (13, 1), (14, 1), (15, 1), (16, 1), (17, 1), (18, 1), (20, 1)]; suma=20; histograma_activo=True |
| K19 | OK | En un **NPS**, mirar su histograma (W3, 2026-10-19) | labels=['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '10']; data=[3, 0, 0, 0, 0, 0, 3, 4, 3, 4, 3]; colores=['#ef4444', '#ef4444', '#ef4444', '#ef4444', '#ef4444', '#ef4444', '#ef4444', '#f59e0b', '#f59e0b', '#22c55e', '#22c55e'] |
| K20 | OK | En una pregunta de opciones, cambiar la vista a **Anillo** y a **Tabla**, y volver a **Barras** (W3, 2026-10-19) | anillo=doughnut; tabla=True; barras={'type': 'bar', 'axis': 'y', 'labels': ['Uno · 10 (50%)', 'Dos · 10 (50%)']} |
| K21 | OK | En una **matriz**, mirar el pie de la tabla (W3, 2026-10-19) | texto=id="campo-277640"> Matriz Matriz — 20 respuestas Malo Bueno Total Fila 1 10 10 20 Fila 2 0 7 7 Total 10 17 27  |
| K22 | OK | En un **ranking**, alternar **Tabla** y **Barras** (W3, 2026-10-19) | tabla=['Opción\tPromedio\tVecesUno\t1.95\t20', 'Dos\t2.05\t20', 'Tres\t2\t20']; barras={'labels': ['Uno', 'Tres', 'Dos'], 'data': [1.95, 2, 2.05], 'tip': "function (ctx) {\n                                var r = rows[ctx.dataIndex];\n                                return 'Orden medio ' + ctx.raw + ' · ' + r.c + ' respuestas';\n                            }"} |
| K23 | OK | En la analítica, mirar la cabecera y la barra de filtros (W4, 2026-10-19) | posiciones=9564,9925,10167 |
| K24 | OK | Recorrer los campos de fecha con lector de pantalla o `Tab` (W4, 2026-10-19) | desde=Desde; hasta=Hasta |
| K25 | OK | En un formulario con más de tres preguntas, usar el índice (W4, 2026-10-19) | anclas=16; selector=True; salto={'target': 'campo-277637', 'value': ''} |
| K26 | OK | Un formulario sin respuestas y luego un filtro sin resultados (W4, 2026-10-19) | vacio=True; cta=True; filtrado=True |
| K27 | OK | Un formulario en modo **listado** (W4, 2026-10-19) | roster=True; filtrado=True; normal=False |
| K28 | OK | En las dos primeras preguntas con gráfico, alternar la vista varias veces (G1, 2026-10-19) | NUM:histogram=True; NUM:histogram=True; NPS:histogram=True; NPS:histogram=True; errores=[] |
| K29 | OK | Con una etiqueta de pregunta muy larga, mirar la cabecera de su tarjeta (G2, 2026-10-19) | titulo=True; acciones=True; css_titulo=True |
| K30 | OK | En un formulario con muchas preguntas y etiquetas largas, mirar el índice «Preguntas» (G3, 2026-10-19) | anclas=16; etiqueta_larga=True |
| K31 | OK | En la tabla de detalle, desplazar en horizontal con muchas columnas (G4, 2026-10-19) | rotulos=19; primero=# |
| K32 | OK | Con respuestas de **selección múltiple**, **matriz** y **ranking**, mirar la tabla de detalle (G5, 2026-10-19) | chips=1; matriz=True; ranking=['1', '2', '3']; pii=True |
| K33 | OK | En una tarjeta **NPS**, pulsar **Resumen**: alternar Histograma ↔ Resumen (G6, 2026-10-19) | resumen=True; canvas=True; insignias=['Detractores (0-6): 6', 'Pasivos (7-8): 7', 'Promotores (9-10): 7'] |
| K34 | OK | Abrir **Detalle** de una respuesta con respuestas de selección múltiple, matriz y ranking (G5, 2026-10-19) | chips=1; matriz=True; ranking=True; pii=True |
### Bloque L

| # | Estado | Qué se comprobó | Detalle |
|---|---|---|---|
| L1 | N/A | Enviar una respuesta con aviso al dueño activado | SMTP no configurado (MAIL_HOST y MAIL_FROM_ADDRESS vacíos): el bloque L se marca N/A según la nota del documento |
| L2 | N/A | Con confirmación activada y campo de email | SMTP no configurado (MAIL_HOST y MAIL_FROM_ADDRESS vacíos): el bloque L se marca N/A según la nota del documento |
| L3 | N/A | Revisar el remitente | SMTP no configurado (MAIL_HOST y MAIL_FROM_ADDRESS vacíos): el bloque L se marca N/A según la nota del documento |
| L4 | N/A | Abrir el enlace de recuperación desde el correo | SMTP no configurado (MAIL_HOST y MAIL_FROM_ADDRESS vacíos): el bloque L se marca N/A según la nota del documento |
| L5 | N/A | Pedir acceso (F4) y abrir el enlace del correo | SMTP no configurado (MAIL_HOST y MAIL_FROM_ADDRESS vacíos): el bloque L se marca N/A según la nota del documento |
| L6 | N/A | Con revisión activada y aviso por correo | SMTP no configurado (MAIL_HOST y MAIL_FROM_ADDRESS vacíos): el bloque L se marca N/A según la nota del documento |
| L7 | N/A | Enviar una respuesta **sin** SMTP | SMTP no configurado (MAIL_HOST y MAIL_FROM_ADDRESS vacíos): el bloque L se marca N/A según la nota del documento |
### Bloque M

| # | Estado | Qué se comprobó | Detalle |
|---|---|---|---|
| M1 | OK | Honeypot activo (por defecto) | GET=200; wrapper=True; input=True; tabindex=True; css=True |
| M2 | OK | Rellenar el honeypot a mano y enviar | POST=200; destino=http://127.0.0.1:10001/f/bt-m-seguridad/thanks; submissions 0->0 |
| M3 | N/A | Turnstile con las dos claves | Turnstile no configurado: TURNSTILE_SITE_KEY y TURNSTILE_SECRET_KEY vacíos; no hay widget real que probar |
| M4 | OK | Turnstile activado **sin** claves en `.env` | GET=200 (página cerrada); POST=403 |
| M5 | OK | Enviar el mismo formulario 11 veces en 10 minutos desde la misma IP | estados=[302, 302, 302, 302, 302, 302, 302, 302, 302, 302, 429]; permitidos=10; ultimo=429; submissions=12 (antes 2) |
| M6 | OK | Recoger el enlace de un formulario desde `?t=` | token1=32; token2=32; distintos=True; thanks1=200:True; thanks2=200:True; malo/sid=200/200:True; t1_en_malo=False; t1_en_sid=False |
| M7 | OK | Revisar el registro de seguridad tras los intentos anteriores | eventos={"auth.login_fail": 1, "auth.register": 11, "rate_limit.fill": 1} |
| M8 | OK | Ver el origen de los envíos (Network) | recursos=5 mismo_origen=True; scripts=2 mismo_origen=True; inline=0; on*=0 |
| M9 | OK | Comprobar la cabecera `X-Frame-Options` | X-Frame-Options='DENY' |
| M10 | FALLA | Registrar 11 cuentas en 10 minutos desde la misma IP | estados=[302, 302, 302, 302, 302, 302, 302, 302, 302, 302, 302]; usuarios_creados=11; fila_contador=None |
| M11 | OK | Con `TRUST_PROXY=true` y un `X-Forwarded-For` inventado | login=302; rate_identifier=127.0.0.1; X-Forwarded-For=203.0.113.77 |
### Bloque N

| # | Estado | Qué se comprobó | Detalle |
|---|---|---|---|
| N1 | OK | Crear un usuario en `/admin` | user=315199 role=2 extra=[] |
| N2 | OK | Crear un rol nuevo | role=19439 perms=[] |
| N3 | OK | Dar un permiso a un rol en la matriz | perms=['dashboard.view'] dashboard=200 |
| N4 | OK | Crear un rol con un nombre duplicado o con acentos parecidos | dup=200/True acento=200/True |
| N5 | OK | Enviar la matriz de permisos con todo desmarcado | status=422 admin_perms=['admin.access', 'admin.groups', 'admin.roles', 'admin.users', 'analytics.view_own', 'attachments.download_own', 'dashboard.view', 'forms.create', 'forms.delete_own', 'forms.edit_own', 'forms.export_own', 'forms.manage_all', 'forms.publish_without_review', 'forms.review', 'logs.view', 'responses.pii', 'responses.view_own'] |
| N6 | OK | Quitarle el permiso `admin.access` al rol `admin` | admin_perms=['admin.access', 'admin.groups', 'admin.roles', 'admin.users', 'analytics.view_own', 'attachments.download_own', 'dashboard.view', 'forms.create', 'forms.delete_own', 'forms.edit_own', 'forms.export_own', 'forms.manage_all', 'forms.publish_without_review', 'forms.review', 'logs.view', 'responses.pii', 'responses.view_own'] |
| N7 | OK | Crear un grupo y meter dos usuarios | group=28148 members=[315199, 315200] review=200,200 |
| N8 | OK | Sacar un usuario del grupo | fuera=403 dentro=200 |
| N9 | OK | Enviar un cambio de pertenencia con la lista vacía | review=403 grupos=0 |
| N10 | OK | Dar un rol desde la ficha del grupo y desde la del usuario | grupo->rol=200 usuario->grupo=200 |
| N11 | OK | Un `editor` intenta borrar su propio formulario | form=264217 delete=403 sigue=1 |
| N12 | OK | Un `user` con `forms.manage_all` | ficha=200 builder=200 |
| N13 | OK | Guardar un grupo **sin** mandar su nombre | conservado=['bt_n_b329f22d_g_keep', 'descripción original'] tras_vacio=['bt_n_b329f22d_g_keep', 'descripción original'] status=200 |
| N14 | OK | Ver la matriz de roles | keys=17 faltan=[] |
| N15 | FALLA | Entrar con una cuenta con rol `approver` | dashboard=200 review=200 admin=403 nuevo=True adminlink=False |
| N16 | OK | Con ese mismo `approver`, abrir la bandeja de `/review` y pulsar **Ver** | inbox=200 print=200 ficha=403 approve=302 status=approved |
| N17 | OK | En la tarjeta *Baja de roles* | status=200 sistema=4 sin_boton=True |
| N18 | OK | Intentar borrar un rol propio que alguien lleva | role=19440 status=200 motivo=True |
| N19 | OK | Borrar un rol propio que no lleva nadie | role=bt_n_b329f22d_role_free gone=True en_grupo=0 status=200 |
| N20 | OK | El mismo borrado con la confirmación rechazada | dialogo=True sigue=True |
| N21 | OK | Abrir `/admin` | status=200 filas=25 |
| N22 | OK | Entrar a `/admin` con un rol sin `admin.access` | status=403 |
| N23 | OK | Abrir la ficha de un formulario, plegar uno de sus seis grupos, recargar y volver | plegado=True/True key_admin=None abierta=True |
| N24 | OK | Crear un usuario con el registro público **cerrado** | register=403 creado=True form_inline=True post_nativo_sin_js=True (Lightpanda no permite desactivar JS) |
| N25 | FALLA | Con el diálogo de alta abierto, crear una cuenta con un email repetido | modal_abierto=False motivo=True url=http://127.0.0.1:10001/admin |
| N26 | OK | A un `user` darle `forms.manage_all` desde *Permisos extra* del menú `⋯` de su fila | antes=403 despues=200 rol=2 grupos=0 |
| N27 | OK | Quitarle esa casilla y guardar | ficha=403 extra=[] |
| N28 | OK | Abrir el menú `⋯` de un usuario | modal_abierto=True desglose=True |
| N29 | OK | Entrar a `/admin` con un rol con `admin.access` pero sin `admin.users` | status=403 perms=['admin.access'] |
### Bloque O

| # | Estado | Qué se comprobó | Detalle |
|---|---|---|---|
| O1 | OK | Cada página de error (403, 404, 405, 413, 419, 429, 500, 503) | 403:403/403 icono=True motivo='Acceso denegado' repetido=1 ayuda=True sin_traza=True; 404:404/404 icono=True motivo='Página no encontrada' repetido=1 ayuda=True sin_traza=True; 405:405/405 icono=True motivo='Método no permitido' repetido=1 ayuda=True sin_traza=True; 413:413/413 icono=True motivo='El envío supera el tamaño máximo permitido por el servidor (1 MB). Intenta con archivos más pequeños.' repetido=1 ayuda=True sin_traza=True; 419:419/419 icono=True motivo='Token CSRF inválido. La sesión |
| O2 | OK | Provocar un 500 | status=500 texto='500 Ocurrió un error al guardar tu respuesta. Intenta de nuevo. Vuelve a intentarlo. Si el error se repite, avisa a quien administre la inst' |
| O3 | OK | Un envío con el cuerpo enorme | status=413 texto='413 El envío supera el tamaño máximo permitido por el servidor (1 MB). Intenta con archivos más pequeños. El envío supera el tamaño máximo permitido por el serv' |
| O4 | OK | Un envío con el token de sesión caducado | status=419 texto='419 Token CSRF inválido. La sesión ha expirado. Recarga la página y vuelve a intentarlo. Si se repite, entra de nuevo. Volver al inicio' |
| O5 | OK | Navegar por todas las rutas del panel con una cuenta recién creada | rutas=17 malas=[] estados=[200, 200, 200, 200, 200, 200, 200, 200, 200, 200, 404, 200, 200, 200, 200, 403, 403] |
| O6 | OK | Un formulario con un nombre larguísimo (200 caracteres) | {'ok': True, 'actionRight': 290, 'inner': 1366, 'titleLen': 190, 'whiteSpace': '', 'overflowWrap': '', 'scroll': 1366} |
| O7 | OK | Los filtros de la tabla de respuestas en móvil | {'ok': True, 'overflow': False, 'rows': 7, 'inner': 375, 'scroll': 375} |
| O8 | OK | many respuestas y filtrar por un rango sin resultados | status=200 texto_sin_resultados=True |
| O9 | OK | Navegar con el teclado por el panel | {'ok': True, 'focusables': 43, 'alts': True, 'css': True} css_global=True (Lightpanda no mueve el foco con Tab; foco programático + regla CSS) |
| O10 | OK | Reducir la ventana a 375 px y recorrer el panel y el constructor | /dashboard:ok(375/375); /admin:ok(375/375); /forms/263816:ok(375/375); /forms/263816/builder:ok(375/375); /forms/263816/responses:ok(375/375); /forms/263816/analytics:ok(375/375) |
### Bloque P

| # | Estado | Qué se comprobó | Detalle |
|---|---|---|---|
| P1 | OK | Abrir **Configuración** en el constructor | {'notes': [], 'cols': 12, 'rows': 12} |
| P2 | OK | Pasar el ratón por un `?` de tema o de comportamiento | strong='los dos' code='WEBHOOK_ALLOW_PRIVATE_HOSTS' tooltip='Hacen falta los dos opt-ins: este y WEBHOOK_ALLOW_PRIVATE_HOSTS en el servidor. Con el servidor sin ' |
| P3 | OK | En una pantalla de móvil (o con el ratón lejos), **tocar** el `?` | tooltip='Si se llena, cada envío se manda por HTTP a esa URL. Debe ser http o https y alcanzable desde el ser' |
| P4 | OK | Con el teclado: tabular hasta un `?` y pulsar Intro | {'cls': 'app-help', 'tip': True} |
| P5 | OK | Con un interruptor, tabular hasta su `?` y **pulsarlo** | antes=False despues=False tooltip=True |
| P6 | OK | Cambiar la fuente en el modal y mirar `#stFontNote` | {'visible': True, 'text': '«Inter» se sirve desde esta instalación.', 'cls': 'form-text mb-0 text-success'} |
| P7 | OK | Guardar y recargar: ¿sigue el `?` con el mismo texto? | color=#123456 texto_igual=True |
| P8 | OK | El `?` mide 14 px: intentar pulsarlo con el dedo | base='display: inline-flex;\n    align-items: center;\n    justify-content: center;\n    position: ' after="content: '';\n    position: absolute;\n    inset: -.45rem;" (1.1rem≈17.6px + 7.2px por lado) |
### Bloque Q

| # | Estado | Qué se comprobó | Detalle |
|---|---|---|---|
| Q1 | OK | Paleta → *Agrega elementos* → **Estructura** | {'n': 2, 'types': ['section', 'page'], 'gap': ''} css_gap=True |
| Q2 | OK | En el lienzo, una sección con preguntas dentro | secciones=2 hijas=1 css='flex: 1 0 100%;\n    display: flex; flex-direction: column; gap: 1.5rem;\n    margin: .6rem 0 0;\n    border-radius: 8px;\n ' |
| Q3 | OK | Una sección vacía | {'children': 0, 'hint': 'Arrastra preguntas aquí o usa el botón «Agregar pregunta dentro».', 'border': ''} css_empty=True css_before=True |
| Q4 | OK | Añadir un campo NPS o de estrellas a la lienzo | nps=11 estrellas=5 |
| Q5 | OK | Lo mismo con escala, ranking y matriz | escala=5 ranking=3 matriz=1 css='overflow: hidden; border-radius: 8px;' |
| Q6 | OK | Abrir el **preview en vivo** con una sección | título=6404 icono=6490 css='display: flex; align-items: center; justify-content: space-between; gap: .7rem;' |
| Q7 | OK | Preview en vivo con NPS, estrellas, matriz y ranking | {'nps': 11, 'star': 5, 'matrix': 4, 'ranking': 3} |
| Q8 | OK | Publicar un formulario con un salto de página **sin** etiqueta | bloques=3 head2=0 pie='AnteriorPaso 2 de 3Siguiente' |
| Q9 | OK | El mismo salto **con** descripción y sin etiqueta | {'head': 1, 'title': 0, 'desc': 'Descripción del paso 2'} |
| Q10 | OK | Formulario publicado con una **sección** que tiene preguntas dentro (no el builder) | {'sections': 2, 'children': 1} css='margin: .6rem 0 0;\n    padding: 0 0 .4rem;' |
| Q11 | OK | Formulario publicado con un campo NPS | botones=11 css='display: inline-flex; align-items: center; justify-content: center;\n    width: 40px; height: 40px;\n    border-radius: 12' |
| Q12 | OK | Un formulario paginado creado **antes** de la corrección | filas=[['', ''], ['Primera página', ''], ['', 'Descripción conservada']] texto_ok=True |
### Bloque R

| # | Estado | Qué se comprobó | Detalle |
|---|---|---|---|
| R1 | OK | Abrir la analítica y cambiar de página **dos veces** | {'existe': 1, 'pager': 1, 'tras2': {'loading': False, 'op': '1', 'pe': '', 'rows': 15}, 'tras1': {'loading': False, 'op': '1', 'pe': '', 'rows': 15}} |
| R2 | OK | Abrir cada una de las 8 páginas de error | O1-O8=[True, True, True, True, True, True, True, True] 404='404\nPágina no encontrada\n\nLa página que buscas no existe o fue movida.\n\nVolver al inicio' |
| R3 | OK | En multipágina, usar solo el teclado para recorrer los pasos | antes={'tag': 'INPUT', 'txt': ''} despues={'tag': 'SECTION', 'txt': 'Paso 2 · El material\n\nSolo si asististe al menos a la mitad.\n\nMaterial y organización\n¿Qué tan claros fueron los materiales? *\n1 2 3 4 5\n¿Qué materiales usaste?\nDiapositivas\nPDF descargable\nVídeos\nEjercicios\nBibliografía\nAnteriorPaso 2 de 3Siguiente', 'step': 1} |
| R4 | OK | Botón verde en cualquier vista | {'verde': 'rgba(0, 0, 0, 0)', 'primario': 'rgba(0, 0, 0, 0)', 'cls': 'btn btn-success app-btn-primary'} |
| R5 | OK | Ficha de un formulario con revisión | {'primarias': 1, 'guardar': False, 'menu': 1, 'acciones': 'Guardar\nAprobar\nDevolver\nEliminar'} |
| R6 | OK | Escala, NPS y estrellas con el teclado | {'n': 21, 'enfocables': 21, 'scale': 5, 'nps': 11, 'star': 5} |
| R7 | OK | Constructor → «Guardar» **dos veces seguidas**, sin tocar nada entre las dos | primero={'txt': 'Guardar', 'dis': True} segundo={'txt': 'Guardar', 'dis': False} |
| R8 | OK | Ficha del formulario, cabecera del top (Editar campos, Compartir, Publicar/En revisión/Cerrar, «más») | controles=[{'t': 'Editar campos', 'cls': 'btn btn-primary app-btn-primary'}, {'t': 'Participantes', 'cls': 'btn btn-outline-primary'}, {'t': 'Respuestas', 'cls': 'btn btn-outline-primary'}, {'t': 'Analítica', 'cls': 'btn btn-outline-success'}, {'t': '', 'cls': 'btn btn-outline-secondary app-dd-btn'}] |
| R9 | OK | Formulario en `roster`, pantalla *Participantes* → añadir o importar sin recargar | antes={'url': '/forms/263819/access', 'board': 1, 'list': 1, 'imp': 1, 'det': 1} despues={'url': '/forms/263819/access', 'board': 1, 'list': 1, 'imp': 1, 'det': 1, 'fila': True} |
| R10 | OK | Formulario en `roster`, ficha → guardar cualquier ajuste (sin recargar) | status=200 keys=['vfSlotStatusBadge', 'vfSlotReviewTrail', 'vfSlotActions', 'vfSlotShare'] data={'ok': True, 'message': 'Ajustes guardados.', 'html': {'vfSlotStatusBadge': '<span class="app-status app-status-published">Publicado</span>\n', 'vfSlotReviewTrail': '', 'vfSlotActions': '<div class="row g-2 mb-4">\n    <div class="col">\n        <button type="submit" form="formUpdate" class="btn btn-outline-primary w-100 app-form-cta"><i class="fa-solid fa-floppy-disk me-1"></i>Guardar</button>\n    < |
### Bloque S

| # | Estado | Qué se comprobó | Detalle |
|---|---|---|---|
| S1 | OK | En el constructor, un salto de página → «Configuración» | página={'wrap': 'form-check form-switch mb-3', 'hidden': False, 'label': 'Mostrar la cabecera de esta página'} campo={'hidden': True, 'disabled': False} |
| S2 | OK | Publicar con el interruptor apagado | {'blocks': 2, 'head': 0, 'foot': 'Paso 1 de 2Siguiente', 'aria': 'Paso 1 de 2', 'progress': 1} |
| S3 | OK | Recargar el paso sin cabecera | recarga sin cabecera |
| S4 | OK | Volver a encender el interruptor y guardar | {'head': 2, 'title': 'Página 1', 'icon': 1, 'bg': 'rgba(0, 0, 0, 0)'} css_head='\n    display: flex; align-items: center; justify-content: space-between; gap: .7' |
| S5 | OK | Formulario publicado con **secciones** con preguntas dentro | {'section': 1, 'children': 1, 'child_bg': 'rgba(0, 0, 0, 0)'} css='\n    margin: .6rem 0 0;\n    padding: 0 0 .4rem;\n' |
| S6 | OK | Constructor → lienzo, entre dos tarjetas | asas=4 css='\n    position: absolute;\n    top: 100%;\n    /* Alineado al borde de la tarjeta (antes 2.35rem, el an' |
| S7 | OK | «Agregar dentro» en una sección del constructor | {'ctrl': 1, 'label': 'Agregar pregunta dentro'} css='\n    flex: 1 0 100%;\n    max-width: 100%;\n    margin: .25rem 0 0;\n' |
| S8 | OK | Publicar un formulario con reglas de visibilidad | {'b': False, 'sec': False, 'logic': 2} texto_regla=False |
| S9 | OK | Constructor → un campo con condición | chips=['Si: ¿Aplica? = "Sí"', 'Si: ¿Aplica? = "Sí" → Se oculta el bloque entero (1 pregunta)', 'Solo se muestra si su sección «Sección condicional» cumple: ¿Aplica? = "Sí"'] |
| S10 | OK | Ficha del formulario → «Ajustes del formulario» | grupos=['Acceso', 'Programación y cupo', 'Notificaciones', 'Webhook', 'Mensaje final', 'Idioma público'] aria=['true', 'true', 'true', 'true', 'true', 'true'] |
| S11 | OK | Plegar dos grupos, recargar y abrir otro formulario | {'acc': False, 'age': False, 'key': '{"acceso":false,"agenda":false}'} otro={'acc': True, 'age': True} |
| S12 | OK | Plegar un grupo y **guardar con un error** (por ejemplo una fecha de cierre anterior a la de apertura) | {'alert': True, 'closed': 0, 'total': 6} |
| S13 | OK | Recorrer los grupos con el teclado | {'n': 6, 'tags': True, 'tabs': True, 'before': 'true'} {'after': 'false', 'url': 'http://127.0.0.1:10001/forms/263816'} |
| S14 | OK | Ficha → Constructor → «Diseño» → poner un **color de fondo** y publicar | body=rgba(0, 0, 0, 0) card=rgba(0, 0, 0, 0) css='\n    background: #fff;\n    border: 1px solid rgba(226,232,24' |
| S15 | OK | Con el mismo formulario abierto, cambiar el color a uno oscuro | {'style': '@font-face{font-family:Lora;src:url(/assets/fonts/lora-latin-var.woff2) format("woff2");font-weight:400 700;font-display:swap;}:root{--vf-primary:#6366f1;--vf-background:#111827;--vf-grad-start:#2563eb;--vf-grad-end:#7c3aed;--vf-on-grad:#ffffff;--vf-font:Lora;}', 'card': 'rgba(0, 0, 0, 0)'} |
| S16 | OK | «Diseño» → cambiar la **fuente** y publicar | fill=True panel=False |
| S17 | OK | Dejar el fondo y la fuente sin tocar (o en el valor de fábrica) | sin overrides de tema |
| S18 | OK | Un salto de página **sin título ni descripción** y con el interruptor encendido | {'visible': True, 'text': 'Este paso todavía no tiene título ni descripción, así que la cabecera no tendría nada que mostrar y saldrá vacía. El interruptor decide si se pinta, no qué dice: escribe el título de la página o añade una descripción arriba para que se vea.', 'check': True} publicado={'head': 0, 'icon': 0} |
| S19 | OK | Escribir el título del paso con el interruptor encendido | {'hint': True, 'label': 'Paso con título', 'card': 'Paso con título Página (salto de paso)\nInicia un nuevo paso — todo lo de abajo pasa a la siguiente página\nTexto\nPárrafo\nEmail\nNúmero\nDesplegable\nOpción única\nCasillas\nFecha\nEscala\nURL\nTeléfono\nHora\nFecha y hora\nRango\nMatriz\nNPS\nEstrellas\nRanking\nArchivo'} |
| S20 | OK | Poner solo la **descripción**, sin título | {'hint': True, 'help': 'Descripción del paso', 'card': 'Paso con título Página (salto de paso)\nInicia un nuevo paso — todo lo de abajo pasa a la siguiente página\nDescripción del paso\nTexto\nPárrafo\nEmail\nNúmero\nDesplegable\nOpción única\nCasillas\nFecha\nEscala\nURL\nTeléfono\nHora\nFecha y hora\nRango\nMatriz\nNPS\nEstrellas\nRanking\nArchivo'} publicado={'head': 1, 'title': 0, 'desc': 'Descripción del paso', 'icon': 1} |
| S21 | OK | Constructor → agregar un salto de página | tarjetas=['Página 1', 'Página 2'] |
| S22 | OK | Publicar con dos saltos recién creados y el interruptor encendido | antes=['Página 1', 'Página 2'] después={'heads': 1, 'first': 0, 'second': 'Página 2'} aviso=True |
| S23 | FALLA | Formulario publicado con una sección | {'title': 'Sección S', 'icon': True, 'order': ['vf-section-title h4 fw-bold mb-0', 'vf-section-icon']} css=' display: flex; align-items: center; justify-content: space-between; gap: .7rem;' builder=0 |
| S24 | OK | Formulario publicado con un salto de página con título | {'title': 'Página 1', 'order': ['', 'vf-page-icon'], 'icon': True} |
| S25 | OK | Publicar y pulsar «Enviar» | {'text': 'Enviar', 'label': 'Enviar', 'css_color': True, 'class_btn': False} |
| S26 | OK | «Diseño» → poner un color **muy claro** como primario y publicar | tinta automática clara->oscura |
| S27 | OK | Constructor → «Agregar elemento» (regresión) | {'show': True, 'tiles': 21, 'nested': False, 'title': 'Agregar elemento'} |
| S28 | OK | Constructor → «Agregar elemento» **en un móvil** (o con la ventana estrecha) | {'show': True, 'cols': 0, 'top': 5865, 'width': 5, 'vw': 360, 'heading': 'Agregar elemento', 'css': ''} cerrado=True css='@media (max-width: 575.98px) {\n    .app-type-grid {\n        grid-template-column' |
| S29 | OK | Constructor → vista previa del formulario, abajo del todo | {'submit': True, 'label': 'Enviar', 'white': True, 'cls': 'vf-submit'} css='\n    min-height: 52px; padding: .7rem 2.4rem;\n    border-rad' |
| S30 | OK | `/admin` en pantalla ancha | {'wide': True, 'head': True, 'action': True, 'table': True, 'filters': True} {'show': True, 'moved': True, 'fields': 5} |
| S31 | OK | `/admin` a 375 px | {'labels': True, 'display': 'block', 'thead': 'block', 'overflow': True} modal={'one': True, 'fields': 5, 'width': 5, 'vw': 375, 'visible': True} |
| S32 | OK | Ficha del formulario, en pantalla ancha | {'left': True, 'right': True, 'access': False, 'order': 'ok'} |
| S33 | OK | Pantalla *Participantes* con una **larga lista de correos** | {'rows': 25, 'wrap': True, 'overflow': False, 'pageOverflow': True, 'pager': True} |
| S34 | OK | Ficha a 375 px | {'left': True, 'right': True, 'settings': True, 'share': True, 'access': False, 'overflow': True} |
| S35 | OK | Formulario publicado a **1920 px** y después a **800 px**; luego el preview en vivo del constructor a ≥992 px | 1920={'ratio': 0.005208333333333333, 'overflow': True} 800={'ratio': 0.0125, 'overflow': True, 'matrix': True} preview={'ratio': 0.010980966325036604, 'same': True} css=' width: 100%; max-width: 100%; margin: 0 auto; ' |
| S36 | OK | Ficha → Ajustes → Notificaciones y Webhook, en pantalla ancha (≥992 px, con la tarjeta en su columna estrecha) | {'half': True, 'full': True, 'widths': [5, 5, 10, 5, 5, 5], 'clipped': []} |
| S37 | OK | Constructor recién abierto | {'palette': 0, 'canvas': 1, 'save': True, 'preview': 1, 'add': True, 'previewTexts': 1, 'inline': False} |
| S38 | OK | «Agregar elemento» y el `+` entre dos tarjetas | modal={'show': True, 'types': 21, 'heading': 'Agregar elemento\n\nElige el tipo de eleme'} canvas={'count': 5, 'labels': ['Página 1', 'Nueva pregunta (Texto)', 'Campo uno', 'Página 2', 'Campo dos'], 'closed': True} before=4 |
| S39 | OK | Pie de una tarjeta | {'type': 'email', 'req': True, 'preview': True, 'tools': True, 'toolsVisible': True, 'requiredHidden': True, 'checkCss': True} {'required': True, 'noMatrix': True} css='\n    /* Sin tamaño propio hereda el `1em` de Bootstrap, que ' |
| S40 | OK | Engranaje de una tarjeta | {'show': True, 'title': 'Configuración del elemento', 'centered': True, 'lg': True, 'scroll': True} {'closed': True, 'selected': True, 'uid': 'field-1'} |
| S41 | OK | «Configuración» del formulario | {'show': True, 'centered': True, 'lg': True, 'scroll': True, 'footer': True, 'sibling': True, 'close': True} |
| S42 | OK | «Vista previa» con cambios **sin pulsar «Guardar»** | {'url': '/forms/264335/preview?draft=1', 'first': True, 'reload': True} |
| S43 | OK | Formulario publicado con una **matriz** de 3 opciones y en su miniatura del constructor | {'table': True, 'wrap': True, 'ths': ['', 'Malo', 'Bueno'], 'first': '5px', 'css': True} preview=True css='\n    width: 100%;\n    min-width: 9rem;\n' |
| S44 | OK | «Vista previa» de un formulario largo, bajando hasta el final | pub={'row': True, 'cls': 'vf-submit-row flip-in', 'parent': 'd-flex flex-column', 'css': ''} preview={'row': True, 'cls': 'vf-submit-row flip-in', 'parent': 'd-flex flex-column', 'oldModal': False} |
| S45 | OK | «Vista previa» con la ventana baja (o en un móvil) | {'submit': True, 'noOverflow': True, 'scrolls': True} {'y': 400, 'submit': True} |
| S46 | OK | Panel (`/dashboard`, `/admin`, un modal de ajustes) y pantallas de acceso (login/registro) | {'search': 'form-control form-control-sm', 'sort': 'form-select form-select-sm app-dash-sort'} {'name': 'form-control form-control-sm', 'role': 'form-select form-select-sm', 'labels': True} |
| S47 | OK | Un `input-group` con icono (login y buscador del panel) | {'groups': 2, 'icons': 2, 'iconCls': True} |
| S48 | OK | El detalle de una respuesta (`/forms/{id}/responses/{sid}`) | {'cls': 'container px-lg-4', 'style': '', 'head': True, 'crumb': True, 'delete': True, 'confirm': True, 'action': '/forms/263816/responses/281641/delete', 'css': '44px'} |
### Bloque T

| # | Estado | Qué se comprobó | Detalle |
|---|---|---|---|
| T1 | OK | Ficha de un formulario **borrador** → «Publicar» | navs=[] url='http://127.0.0.1:10001/f/bt-t-ficha-10315b6e' {'badge': 'Publicado', 'share': 'Abrir en nueva pestaña\nEste enlace apunta a una dirección local, así que el QR y el enlace solo abren en esta red. Si la app se sirve en otro dominio, define APP_PUBLIC_URL en el .env.\n\nEl embed está desactivado. Actívalo en la Configuración del constructor para obtener el código iframe.', 'qr': True, 'iframe': True, 'actions': 'Guardar\nCerrar\nEliminar'} |
| T2 | FALLA | El mismo formulario → «Cerrar» (con confirmación) | dialogo=0 navs=[] {'badge': 'Cerrado', 'share': 'Publica el formulario para obtener el enlace de respuesta.\n\nPublicar ahora', 'actions': 'Guardar\nReabrir\nEliminar'} |
| T3 | OK | Publicar y cerrar dos veces seguidas en la misma pantalla | {'badge': 'Cerrado', 'share': 'Publica el formulario para obtener el enlace de respuesta.\n\nPublicar ahora', 'actions': 'Guardar\nReabrir\nEliminar'} |
| T4 | FALLA | Ficha → «Ajustes del formulario» → cambiar el nombre y guardar | navs=[] y=2100->2100 {'h': '[BT] T ficha 10315b6e', 'open': 6, 'closed': 0, 'y': 2100} |
| T5 | OK | Un formulario publicado **con revisión activa** → guardar cualquier ajuste | status=draft {'badge': 'Borrador', 'trail': '', 'msg': 'Formulario actualizado. Como exige revisión, quedó en borrador.'} |
| T6 | OK | Un formulario **aprobado** → cambiar algo en el constructor y guardar | status=draft {'badge': 'Borrador', 'actions': 'Guardar\nEnviar a revisión\nEliminar'} |
| T7 | FALLA | Enviar a revisión → aprobar con otra cuenta | send=in_review status=approved navs=['http://127.0.0.1:10001/review'] {'badge': 'Aprobado', 'trail': 'Revisión\nEnviado a revisión el 01/11/2026 08:00\nAprobado por aprobador el 01/11/2026 08:00', 'actions': 'Guardar\nPublicar\nEliminar'} |
### Bloque ?

| # | Estado | Qué se comprobó | Detalle |
|---|---|---|---|
| T7b | OK |  | navs=[] {'badge': 'Publicado', 'share': 'Abrir en nueva pestaña\nEste enlace apunta a una dirección local, así que el QR y el enlace solo abren en esta red. Si la app se sirve en otro dominio, define APP_PUBLIC_URL en el .env.\n\nEl embed está desactivado. Actívalo en la Configuración del constructor para obtener el código iframe.', 'qr': True} |
### Bloque T

| # | Estado | Qué se comprobó | Detalle |
|---|---|---|---|
| T8 | FALLA | Igual, pero **devuelto a borrador** | status=draft {'badge': 'Borrador', 'trail': '', 'actions': 'Guardar\nEnviar a revisión\nEliminar'} |
| T9 | OK | Con el JS desactivado, cualquier acción de la ficha | http=200 status=published url=http://127.0.0.1:10001/forms/264431 |
| T10 | OK | Con el JS desactivado, pulsar dos veces «Desactivar» en `/admin` | uid=315221 |
| T11 | FALLA | `/admin` → desactivar un usuario y observar la fila | navs=[] {'status': 'Activo', 'panel': ''} |
| T12 | FALLA | `/admin` → **Guardar** los cambios de una fila recién desactivada | navs=['http://127.0.0.1:10001/admin'] {'open': True, 'role': '3', 'badge': ''} |
| T13 | OK | Un formulario con acceso por lista → pantalla *Participantes* → quitar un email | navs=[] {'list': 'La lista está vacía: ahora mismo nadie puede responder a este formulario, ni siquiera quien ya tenía un enlace.', 'toast': 'Se quitó quitar@bt.local de la lista y sus enlaces ya no sirven.'} |
| T14 | OK | Respuestas → borrar una | n0=2 {'rows': 1, 'count': '(1)', 'toast': 'Respuesta eliminada.'} |
| T15 | OK | Borrar la **última** respuesta de un formulario | navs=['http://127.0.0.1:10001/forms/264434/responses'] {'rows': 0, 'empty': True, 'count': ''} |
| T16 | OK | Con el JS desactivado, borrar una respuesta | http=200 url=http://127.0.0.1:10001/forms/264435/responses |
| T17 | OK | Con el navegador en «sin conexión» o parando la petición con las herramientas de desarrollo | {'status': 'published', 'url': 'http://127.0.0.1:10001/forms/264436', 'ok': True} |
| T18 | OK | En la ficha, pulsar `Ctrl+S` (o `Cmd+S` en Mac) con el foco dentro de un formulario | settings={'message': 'ctrl-10315b6e', 'redirect_url': ''} |
| T19 | OK | En la ficha, con el foco en un campo de texto, `Ctrl+S` | settings={'message': 'ctrl-10315b6e', 'redirect_url': ''} |
| T20 | OK | Volver a pulsar «Guardar» mientras la petición ya está volando | posts=['http://127.0.0.1:10001/forms/264426/update'] |
| T21 | OK | Un formulario que devuelve **error** al guardar (una fecha de cierre anterior a la de apertura) | {'toast': 'La fecha de apertura no puede ser posterior a la de cierre.', 'open': 6, 'total': 6, 'ok': False} |
| T22 | OK | Con el mismo formulario y una respuesta que dé error de validación | http=200 no hay AJAX de fill; se valida el repintado del POST |
| T23 | FALLA | Cualquier acción de la ficha con el teclado | {'role': 'status', 'live': 'polite', 'focus': 'BODY', 'body': True} |
| T24 | OK | Ficha con un formulario en revisión → mirarla en `/dashboard` | dash=True admin=True |
### Bloque U

| # | Estado | Qué se comprobó | Detalle |
|---|---|---|---|
| U1 | OK | Ficha → «Eliminar formulario» | {'show': True, 'title': 'Confirmar la acción', 'text': '¿Eliminar este formulario y todas sus respuestas? Esta acción no se puede deshacer.', 'ok': 'Eliminar formulario', 'tone': 'btn btn-danger', 'labelled': 'vfConfirmTitle', 'described': 'vfConfirmText', 'focus': 'vfConfirmCancel'} |
| U2 | OK | Con el diálogo abierto, pulsa `Esc` | {'show': False, 'title': 'Confirmar la acción', 'text': '¿Eliminar este formulario y todas sus respuestas? Esta acción no se puede deshacer.', 'ok': 'Eliminar formulario', 'tone': 'btn btn-danger', 'labelled': 'vfConfirmTitle', 'described': 'vfConfirmText', 'focus': 'vfConfirmCancel'} |
| U3 | OK | Con el diálogo abierto, pulsa «Cancelar» y después «Eliminar formulario» otra vez | still=True |
| U4 | OK | En el diálogo abierto, pulsa `Tab` varias veces | foco=vfConfirmCancel dentro=True |
| U5 | OK | Con un lector de pantalla, abre cualquiera de los diez diálogos | {'show': True, 'title': 'Confirmar la acción', 'text': '¿Eliminar este formulario y todas sus respuestas? Esta acción no se puede deshacer.', 'ok': 'Eliminar formulario', 'tone': 'btn btn-danger', 'labelled': 'vfConfirmTitle', 'described': 'vfConfirmText', 'focus': 'vfConfirmCancel'} |
| U6 | FALLA | Publicar / **Aprobar** / devolver a borrador / activar usuario | publicar=False primary=['Aprobar', 'Devolver', 'Aprobar', 'Devolver', 'Aprobar', 'Devolver'] |
| U7 | OK | Desactivar usuario / eliminar / borrar respuesta | danger=['Eliminar formulario', 'Borrar rol', 'Eliminar grupo', 'Borrar', 'Borrar', 'Quitar', 'Eliminar formulario'] |
| U8 | OK | Pantalla *Participantes* con acceso por lista → **añadir** un email a la lista | navs=[] toast='Se añadió uno-c04a826b@bt.local a la lista de acceso.' |
| U9 | OK | Añadir **dos** emails seguidos | {'v': '', 'foco': 'accessAddEmail'} |
| U10 | OK | Añadir un email que **ya está** en la lista | {'txt': 'ya-c04a826b@bt.local ya estaba en la lista.', 'vis': True, 'toast': 'Se añadió dos-c04a826b@bt.local a la lista de acceso.'} |
| U11 | OK | Tras el error de U10, escribir un email válido y añadirlo | {'vis': False, 'txt': ''} |
| U12 | N/A | Pantalla *Participantes* con acceso por lista, en móvil (360 px) → añadir un email | Viewport móvil no fiable en Lightpanda; cubierto por CSS/test de estructura |
| U13 | OK | Con el **JS desactivado**, añadir un email a la lista | status=200 url=http://127.0.0.1:10001/forms/264472/access#access |
| U14 | OK | En la pantalla *Participantes* con allowlist, quita un correo y añade otro seguido | before=4 after=5 |
| U15 | OK | Con el JS desactivado, borra un formulario | status=200 |
| U16 | OK | Ficha → «Acceso restringido» → cambia el **modo** y guarda | navs=[] settings={"theme":{"primary":null,"background":null,"gradient_start":null,"gradient_end":null,"text":null,"font":null},"schedule":{"open_at":"","close_at":""},"quota":{"enabled":false,"max":0},"notify":{"enabled":false,"emails":[],"confirmation":{"enabled":false,"subject":"","message":"","to_field":""}},"webhook":{"url":"","allow_private_hosts":false,"kind":"generic","secret":""},"thanks":{"message":"","redirect_url":""},"quiz":{"enabled":false},"randomize":{"questions":false,"enabled":f |
| U17 | OK | `/admin` → cambia el **rol** de una fila | navs=[] rol=3 badge=editor |
| U18 | OK | `/admin` → despliega los **grupos** de esa misma fila y guarda | navs=[] en=1 badge=Grupo U c04a826b |
| U19 | OK | `/admin` → guarda el rol de una persona **justo después** de abrir sus grupos | navs=[] rol=3 en=1 modal=True |
| U20 | OK | Con el **JS desactivado**, cambia el rol y guarda un grupo de `/admin` | rol=2 en=1 |
### Bloque V

| # | Estado | Qué se comprobó | Detalle |
|---|---|---|---|
| V1 | OK | Formulario en modo `roster` → botón *Participantes* | [True, True, True, True] |
| V2 | OK | La tarjeta «Importar», su `<summary>` | {'open': True, 'izq': True, 'der': True, 'fuera': True} |
| V3 | OK | **Con el JS desactivado**, abrir `/forms/{id}/access` | status=200 |
| V4 | OK | *Añadir uno a la lista* (abre un modal) → escribir una cédula y *Añadir* | navs=[] n=27 |
| V5 | OK | Escribir una cédula **ya presente** y añadir | n=27 err={'txt': 'El identificador V-907 ya está en la lista.', 'vis': True} |
| V6 | OK | Alta repetida sin recargar, tres seguidas | catálogo sin repintar |
| V7 | OK | Cada fila → menú `⋯` | {'show': True, 'title': 'Editar a Nombre 01\nCédula\nNombre completo\nCorreo\nTeléfono\nSede\nQuitar\nGuardar', 'has': True} |
| V8 | OK | Editar el nombre y guardar, con la lista ordenada por nombre | nombre=Zeta editado |
| V9 | OK | Buscar `V-01` en la caja de búsqueda con la lista ordenada por *Nombre* | {'vivo': True, 'url': '?q=V-01&sort=nombre', 'q': 'V-01', 'txt': 'Listado\nBuscar\nOrdenar por cédula\nOrdenar por nombre\nOrdenar por correo\nOrdenar por teléfono\nOrdenar por fecha\nAgregar\nCédula\tNombre completo\tCorreo\tTeléfono\tSede\tCódigo\tAccionesV-01\tZeta editado\tp1@bt.local\t\tNorte\t4SAPHZRAEPFDY9F7\t'} |
| V10 | OK | Con una búsqueda puesta, borrar a alguien | {'url': '?q=V-02', 'txt': 'Listado\nBuscar\nOrdenar por cédula\nOrdenar por nombre\nOrdenar por correo\nOrdenar por teléfono\nOrdenar por fecha\nAgregar\n\nNadie en la lista coincide con la búsqueda.\n\nPrueba con otra cédula, nombre, correo o teléfono.'} |
| V11 | OK | Importar un `.xlsx` con una columna nueva | {'vivo': True, 'alta': True, 'rep': '', 'lista': 'Listado\nBuscar\nOrdenar por cédula\nOrdenar por nombre\nOrdenar por correo\nOrdenar por teléfono\nOrdenar por fecha\nAgregar\nCédula\tNombre completo\tCorreo\tTeléfono\tSede\tCódigo\tAccionesV-01\tNombre 01\tp1@bt.local\t\tNorte\t2FGMY2ERJEBFQZ7A\t\nV-70\tImportado\ti70@bt.local\t\tNorte\tN67ZEPQ5CPG27CEN\t'} |
| V12 | OK | Importar un archivo con una fila sin identificador, en modo *reemplazar* | preview=True confirm=False n=2/2 rep=<div class="alert alert-warning small" role="status">     <div class="fw-semibold mb-1">                     1 de 2 filas no se pudieron guardar.             </div>              <details>              |
| V13 | OK | Con el JS desactivado, importar un archivo con filas malas | >                                                                                                                 1 de 2 filas no se pudieron guardar.                                           Ver las |
| V14 | OK | Importar un archivo que no es un `.xlsx` | ok=False msg=Ese archivo no es un .xlsx de verdad. rep= |
| V15 | OK | Tras una importación, recargar la pantalla | informe de un solo uso |
| V16 | OK | *Vaciar la lista* | dlg={'txt': '¿Vaciar toda la lista de participantes? Se borran sus códigos y sus datos de la lista; las respuestas ya enviadas se quedan y el catálogo de atributos se conserva. Esta acción no se puede deshacer.', 'ok': 'Sí, vaciar la lista'} counts=(0, 1) |
| V17 | OK | Quitar un atributo del catálogo | {'cat': 'Atributos\n\nNo hay atributos. Salen de las columnas del archivo que subas, y se usan para filtrar y agrupar las respuestas.', 'fila': 'Listado\nBuscar\nOrdenar por cédula\nOrdenar por nombre\nOrdenar por correo\nOrdenar por teléfono\nOrdenar por fecha\nAgregar\nCédula\tNombre completo\tCorreo\tTeléfono\tCódigo\tAccionesV-01\tNombre 01\tp1@bt.local\t\tZ3BXSZAK35CNM872\t\nV-02\tNombre 02\tp2@bt.local\t\tGQEDRAS25WATSGCZ\t\nV-03\tNombre 03\tp3@bt.local\t\tTE9T73BD4F35XMJQ\t', 'alta': Fals |
| V18 | OK | Quitar a alguien de la lista | {'txt': '¿Quitar este participante de la lista? Su código deja de servir y sus respuestas se quedan. Esta acción no se puede deshacer.', 'ok': 'Sí, quitar'} |
| V19 | OK | Un formulario con 26 participantes | {'rows': 25, 'stats': '26\nEn la lista\n0\nRespondieron\n26\nSin responder', 'pag': 2} |
| V20 | N/A | En móvil (360 px), la tarjeta «Lista» | Layout móvil no medible en Lightpanda; cubierto por CSS/@media |
| V21 | OK | Con el JS desactivado, buscar, añadir y borrar | POST nativo |
| V22 | OK | Un formulario que **no** es de lista → *Participantes* | {'list': True, 'roster': False, 'alta': True} |
| V23 | N/A | Buscar con el panel a media pantalla (sin recargar antes) | El scroll del panel no es fiable en Lightpanda; cubierto por JS/CSS |
| V24 | OK | Un formulario de lista con alguien que ya respondió: borra su respuesta desde *Respuestas* | responded_at=NULL re=200 |
| V25 | OK | Cerrar `#rosterEditModal` con `Esc` o tocando el fondo | {'show': False, 'caja': True, 'oculta': True} |
| V26 | OK | Abrir la pantalla con `?q=V-01&sort=nombre&page=2` | {'rows': 1, 'q': 'V-', 'page': '2', 'sort': 'nombre'} links=['/forms/264539/access?q=V-&page=1&sort=nombre#participantes', '/forms/264539/access?q=V-&page=2&sort=nombre#participantes'] |
| V27 | OK | Cargar 26+ y pasar de página | {'q': 'V-', 'sort': 'nombre'} |
| V28 | OK | Abrir *Participantes* en un formulario de lista | tres tarjetas separadas |
| V29 | OK | En el resumen, mirar la cabecera de los modales de alta y edición | modal-header app-navbar text-white border-0 py-2 |
| V30 | OK | Buscar y añadir a alguien | {'search': True, 'grupo': True, 'mismo': True} |
| V31 | OK | Abrir los modales de alta y de edición con varios atributos | {'n': 5, 'todas': True} |
| V32 | OK | En el modal de edición, mirar los botones | {'btns': ['', 'Quitar', 'Guardar'], 'guardar': True} |
| V33 | OK | Abrir la tarjeta «Importar» con muchos atributos en el catálogo | {'open': True, 'izq': True, 'der': True, 'fuera': True} |
| V34 | OK | Buscar en la lista de participantes | navs=['http://127.0.0.1:10001/forms/264539/access?q=V-01&sort=cedula#participantes'] {'url': '?q=V-01&sort=cedula', 'q': 'V-01', 'txt': 'Listado\nBuscar\nOrdenar por cédula\nOrdenar por nombre\nOrdenar por correo\nOrdenar por teléfono\nOrdenar por fecha\nAgregar\nCédula\tNombre completo\tCorreo\tTeléfono\tSede\tCódigo\tAccionesV-01\tZeta editado\tp1@bt.local\t\tNorte\t4SAPHZRAEPFDY9F7\t', 'vivo': True} |
| V35 | OK | Tras buscar, pulsar «atrás» del navegador | q='' |
| V36 | OK | Con el JS desactivado, buscar | GET filtra |
### Bloque W

| # | Estado | Qué se comprobó | Detalle |
|---|---|---|---|
| W1 | OK | Abrir el desplegable «Quién puede responder» | ['public', 'email', 'allowlist', 'roster'] |
| W2 | OK | Poner `roster` **sin guardar** | {'hidden': False, 'dis': False, 'val': 'correo'} |
| W3 | OK | Volver a «Cualquiera con el enlace» sin guardar | {'hidden': True, 'dis': True, 'val': 'correo'} |
| W4 | OK | Un formulario en `roster` con identificador «Correo electrónico», abrir la ficha | {'hidden': False, 'dis': False, 'val': 'correo'} |
| W5 | OK | **Con el JS desactivado**: poner `roster` y guardar | {'ok': True, 'message': 'Ajustes guardados.', 'html': {'vfSlotStatusBadge': '<span class="app-status app-status-draft">Borrador</span>\n', 'vfSlotReviewTrail': '', 'vfSlotActions': '<div class="row g-2 mb-4">\n    <div class="col">\n        <button type="submit" form="formUpdate" class="btn btn-outline-primary w-100 app-form-cta"><i class="fa-solid fa-floppy-disk me-1"></i>Guardar</button>\n    </div>\n                                        <div class="col">\n                    <form method="p |
| W6 | OK | Guardar **otro** ajuste que no sea de acceso (el título, por ejemplo) en un formulario en `Público` que tenga lista configurada para correo | {'ok': True, 'message': 'Ajustes guardados.', 'html': {'vfSlotStatusBadge': '<span class="app-status app-status-draft">Borrador</span>\n', 'vfSlotReviewTrail': '', 'vfSlotActions': '<div class="row g-2 mb-4">\n    <div class="col">\n        <button type="submit" form="formUpdate" class="btn btn-outline-primary w-100 app-form-cta"><i class="fa-solid fa-floppy-disk me-1"></i>Guardar</button>\n    </div>\n                                        <div class="col">\n                    <form method="p |
| W7 | OK | Cambiar a un identificador que **alguien de la lista no tiene** | 422 {"ok": false, "error": "No se puede cambiar el identificador a «correo»: 2 personas de la lista no lo tienen. Rellénalo en la lista o quita a quien no lo tenga."} |
| W8 | OK | Importar una lista con **dos correos iguales** y cambiar a «Correo electrónico» | 422 {"ok": false, "error": "No se puede cambiar el identificador a «correo»: 2 personas pasarían a compartir la misma identidad («igual@bt.local»). Corrige la lista antes de cambiarlo."} |
| W9 | OK | Una lista con nueve correos repetidos y cambiar a «Correo electrónico» | citados=5 {"ok": false, "error": "No se puede cambiar el identificador a «correo»: 18 personas pasarían a compartir la misma identidad («rep0@bt.local», «rep1@bt.local», «rep2@bt.local», «rep3@bt.local», «rep4@bt.local»). Corrige la lista antes de cambiarlo."} |
| W10 | OK | Una lista en la que todo el mundo tiene correo, cambiar a «Correo electrónico» y guardar | {'ok': True, 'message': 'Ajustes guardados.', 'html': {'vfSlotStatusBadge': '<span class="app-status app-status-published">Publicado</span>\n', 'vfSlotReviewTrail': '', 'vfSlotActions': '<div class="row g-2 mb-4">\n    <div class="col">\n        <button type="submit" form="formUpdate" class="btn btn-outline-primary w-100 app-form-cta"><i class="fa-solid fa-floppy-disk me-1"></i>Guardar</button>\n    </div>\n                                        <div class="col">\n                               |
| W11 | OK | Con la lista reescrita, entrar por un enlace con el código viejo | 200 http://127.0.0.1:10001/f/bt-w-reescribe-6b37fd57?t=25G29ZYP8HGBTJ5W |
| W12 | OK | Poner `public` en un formulario que tenía lista | {'ok': True, 'message': 'Ajustes guardados.', 'html': {'vfSlotStatusBadge': '<span class="app-status app-status-published">Publicado</span>\n', 'vfSlotReviewTrail': '', 'vfSlotActions': '<div class="row g-2 mb-4">\n    <div class="col">\n        <button type="submit" form="formUpdate" class="btn btn-outline-primary w-100 app-form-cta"><i class="fa-solid fa-floppy-disk me-1"></i>Guardar</button>\n    </div>\n                                        <div class="col">\n                               |
| W13 | OK | El formulario en `roster`, con los botones de la ficha (*Publicar*, *Compartir*) | roster='Guardar\nCerrar\nEliminar' public='Guardar\nCerrar\nEliminar' |
| W14 | OK | Abrir la puerta de un formulario en lista con identificador «Cédula», sin `?t=` | {'label': 'Tu cédula', 'ph': 'Ej.: V-12345678', 'note': '\n                La cédula y el código de invitación son personales: no los compartas.            '} |
| W15 | OK | Cambiar el identificador a «Código» y volver a abrir la puerta sin `?t=` | {'label': 'Tu código personal', 'ph': 'Los 16 caracteres de tu código'} |
| W16 | OK | Con identificador «Correo electrónico», escribir en la puerta la **cédula** de alguien de la lista | 200 http://127.0.0.1:10001/f/bt-w-puerta-6b37fd57 |
| W17 | OK | Con identificador «Cédula», escribir la cédula de un participante | 200 http://127.0.0.1:10001/f/bt-w-puerta-6b37fd57?t=S6CC6YX8NKCW2AFK |
| W18 | OK | Pulsar el «?» de ayuda del desplegable «Con qué se identifica» |  |
| W19 | OK | Mirar el aviso de debajo del desplegable en modo lista | Solo entran las personas del listado, con su código o con el identificador elegido. La lista se gestiona en la tarjeta de Acceso. |
### Bloque X

| # | Estado | Qué se comprobó | Detalle |
|---|---|---|---|
| X1 | OK | Abrir la analítica de un formulario **con** lista importada | {'key': True, 'val': False, 'first': 'Todo el listado', 'dates': 2} |
| X2 | OK | Abrir la analítica de un formulario **normal**, sin lista |  |
| X3 | OK | Elegir un atributo y pulsar «Filtrar» sin elegir valor | {'key': 'sede', 'val': 'ref: <Node>', 'all': 'Todos los valores', 'total': '23'} |
| X4 | OK | Elegir un valor con respuestas y pulsar «Filtrar» | total=20 filas=15 rad={'Tarde': 10, 'Mañana': 10} th=# Sede Región Zona Turno Número Comentario Fecha Ver |
| X5 | OK | La tabla con el filtro puesto | # Sede Región Zona Turno Número Comentario Fecha Ver |
| X6 | OK | Alguien de la lista sin el atributo, dentro del grupo filtrado | ['1 Norte — — Mañana 20 Comentario 20 21/10/2026 10:20 Ver'] |
| X7 | OK | Cambiar de página con el filtro puesto | filas=5 |
| X8 | OK | Un formulario que además tiene respuestas de gente **sin** estar en la lista | todo=23 filtro=20 |
| X9 | OK | Editar la URL a mano con `atr_key=cedula` y una cédula real | total=23 |
| X10 | OK | Editar la URL con un valor que no existe (`atr_value=Oeste`) | total=0 |
| X11 | OK | Editar la URL con `atr_key` inventada | total=23 |
| X12 | OK | Con el filtro puesto, pulsar «Exportar CSV» | status=200 url=http://127.0.0.1:10001/forms/265322/export.csv?atr_key=sede&atr_value=Norte filas_csv=20 |
| X13 | OK | Un formulario de listado con **muchos** valores en un atributo libre | opciones=201 |
| X14 | OK | Poner un filtro de fechas **y** un de atributo | total=10 csv=10 status=200 |
### Bloque Y

| # | Estado | Qué se comprobó | Detalle |
|---|---|---|---|
| Y1 | OK | Elegir atributo y pulsar «Filtrar» sin elegir valor | heads=['Pregunta', 'Grande n = 200', 'Extra n = 17', 'Pequeño n = 2', 'SinRespuesta n = 1'] |
| Y2 | OK | La tabla de comparación con un campo numérico | {'Satisfacción': ['10.00 n = 200', '—', '150.00 n = 2', '—'], 'Recomendación': ['10.00 n = 200', '—', '150.00 n = 2', '—'], '¿Recomendaría?': ['10.00 n = 200', '—', '150.00 n = 2', '—'], 'Dato personal': ['10.00 n = 200', '—', '150.00 n = 2', '—']} |
| Y3 | OK | Un campo NPS | grande=100 n = 200 pequeno=0 n = 2 |
| Y4 | OK | Un desplegable de opciones | grande=Sí 150 (75 %) No 50 (25 %) pequeno=No 1 (50 %) Sí 1 (50 %) |
| Y5 | OK | Con un valor filtrado puesto |  |
| Y6 | OK | Un grupo con dos respuestas y otro con doscientas | promedios por grupo |
| Y7 | OK | Alguien de la lista que no ha respondido | sin=— |
| Y8 | OK | Un atributo libre con **más de ocho** valores distintos | heads=['Pregunta', 'C3 n = 23', 'C1 n = 22', 'C10 n = 22', 'C4 n = 22', 'C5 n = 22', 'C6 n = 22', 'C7 n = 22', 'C8 n = 22', 'Resto n = 43'] |
| Y9 | OK | Ese mismo atributo, con **ocho** valores justos | heads=['Pregunta', 'O3 n = 28', 'O4 n = 28', 'O5 n = 28', 'O6 n = 28', 'O1 n = 27', 'O2 n = 27', 'O7 n = 27', 'O8 n = 27'] |
| Y10 | N/A | Una tabla con nueve columnas y una ventana estrecha | Comprobación manual: sticky de la primera columna en ventana estrecha |
| Y11 | OK | Una pregunta de opción múltiple, de matriz o de ranking | filas=['Satisfacción', 'Recomendación', '¿Recomendaría?', 'Dato personal'] |
| Y12 | OK | Una pregunta marcada como dato personal, entrando **sin** el permiso | filas_user=[] |
| Y13 | OK | La misma pregunta entrando **con** el permiso | admin=7.00 n = 200 |
| Y14 | OK | Con un filtro de fechas puesto y la comparación abierta | peq=200.00 n = 1 grande=10.00 n = 100 |
| Y15 | OK | El botón «Descargar CSV» de la tarjeta de **detalle**, con filtro de atributo y fechas | ['/forms/265324/export.csv?from=2026-10-20&to=2026-10-21&atr_key=grupo&atr_value=Grande', '/forms/265324/export.csv?from=2026-10-20&to=2026-10-21&atr_key=grupo&atr_value=Grande'] |
| Y16 | OK | Un formulario sin lista importada |  |
| Y17 | N/A | Una lista importada de 800 filas con la columna «Sede» vacía para la mayoría | Comprobación manual: lista de 800 filas y «Resto» mayoritario |
### Bloque Z

| # | Estado | Qué se comprobó | Detalle |
|---|---|---|---|
| Z1 | OK | El listado entrando **como admin** | filas=15 primera=1 22/10/2026 12:00 Z-001 Ana Pérez ana.perez@bt.local 10.0.0.1 Ver Borrar |
| Z2 | OK | El mismo listado entrando **como user** (o cualquier rol sin `responses.pii`) | status=200 filas=15 col=False |
| Z3 | OK | Ese mismo listado sin permiso |  |
| Z4 | OK | Un formulario **normal** (sin lista), entrando como admin |  |
| Z5 | OK | Una respuesta de alguien que **no está en la lista** (entró por un formulario público antes de tener lista) | primera=1 22/10/2026 12:00 Z-001 Ana Pérez ana.perez@bt.local 10.0.0.1 Ver Borrar |
| Z6 | OK | Una página de respuestas donde **ninguna** está atribuida | filas=15 |
| Z7 | OK | El detalle de una respuesta, como admin |  |
| Z8 | OK | El detalle de una respuesta sin atribución |  |
| Z9 | OK | Con el identificador del formulario puesto en «correo» | 1 22/10/2026 12:00 z9@bt.local Correo Único 10.0.0.1 Ver Borrar |
| Z10 | OK | Una lista de mil personas, pagina a pagina hasta una página sin atribuir |  |
| Z11 | OK | Descargar el CSV y el JSON de un formulario con lista | csv=200 json=200 |
| Z12 | OK | El detalle de una respuesta de un campo marcado como dato personal, sin permiso |  |
