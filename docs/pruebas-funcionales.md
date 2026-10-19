# Tabla de pruebas funcionales

Lista de comprobaciones para **detectar problemas** en una instalación, antes
de un despliegue y después de cualquier cambio. Escrita contra el código
(verificada contra el código).

No sustituye a la suite automática (`php scripts/test.php`, 1151 casos): esa
verifica reglas y casos límite sin navegador. Esta tabla verifica lo que solo
se ve en pantalla: que algo se vea bien, que un botón esté donde debe, que el
teclado funcione.

---

## Cómo usarla

**Columnas**

| Columna | Qué hay en ella |
|---|---|
| **#** | Identificador. Cítalo al reportar un fallo. |
| **Qué probar** | Pasos concretos y cortos. |
| **Esperado** | Lo que tiene que pasar. Si no pasa, hay un problema. |
| **P.** | Prioridad: **A** = bloqueante (rompe el uso o pierde datos), **M** = molestia real, **B** = estética. |
| **Estado** | Lo rellenas tú: `OK`, `FALLA` o `N/A` (y por qué). |

**548 pruebas** en 31 bloques (contadas con `grep -cE '^\| [A-Z]+[0-9]+[a-z]? \|'`;
el 417 que decía aquí antes no cuadraba con el fichero y era un número
recordado, no medido): A–Q, las regresiones (R) de fallos que ya
existieron y se corrigieron, la tanda visual (S) de la ronda del constructor y
el fill, las acciones sin recarga (T), el diálogo de confirmación con el alta de
la lista de acceso (U), la pantalla de participantes (V), el identificador del
listado (W), el filtro de la analítica por atributo (X), la comparación por
atributo con su techo (Y), quién respondió (Z), el correo de invitación (AA),
el listado paginado del panel (AB), la galería del modal de nuevo formulario
(AC), la consola de administración (AD) y el recordatorio por correo (AE).

**Estado inicial:** todos en blanco. Una prueba pasa si está `OK` o `N/A`.
Cualquier `FALLA` es un hallazgo; anota el `#` en el issue.

**Cuándo**

- Antes de un despliegue: A, B, N y O enteras.
- Después de tocar el constructor: D, G y R enteras.
- Después de tocar el fill o el panel: E, F, K, R, T, U, AA y AE enteras.
- Después de tocar la analítica: K y X enteras.
- Después de tocar el listado de participantes: V, W, X y AE enteras.
- Después de tocar seguridad o permisos: M, N y O enteras.

---

## Datos de prueba previos

Para no perderte, prepara esto antes de empezar:

- [ ] **Dos cuentas**: `admin` (con todos los permisos) y `usuario` (rol `user`).
- [ ] **Una tercera cuenta** de rol `editor`, para comprobar que el borrado le
      está denegado.
- [ ] **Un formulario simple**: un texto obligatorio y un email. Publicado.
- [ ] **Un formulario largo**: con un campo de archivo, una matriz, un ranking,
      una escala, un NPS y una estrella.
- [ ] **Un formulario multipágina**: al menos 3 páginas y una sección con
      condición.
- [ ] **Un formulario con revisión activada** y otro sin ella.
- [ ] **Un formulario con un campo marcado como dato personal.**
- [ ] **SMTP configurado** (o acepta `N/A` en todo el bloque L).
- [ ] **`TRUST_PROXY=false`** (el valor por defecto). Si lo cambiaste, las
      pruebas del bloque M fallarán, y el fallo sería de configuración, no de
      la app.

---

## A. Instalación y arranque

| # | Qué probar | Esperado | P. | Estado |
|---|---|---|---|---|
| A1 | `php -l` sobre `public/index.php` y `config/bootstrap.php` | Sin errores de sintaxis | A | |
| A2 | Abrir `/` en el navegador | Redirige a `/login` o `/dashboard`; nunca pantalla en blanco ni error PHP | A | |
| A3 | Navegar a una URL inexistente (`/no-existe`) | Página 404 con icono, motivo y botón de volver. **El texto explicativo tiene que verse** | M | |
| A4 | Enviar un formulario por GET a `/dashboard` | 405 con su propia página, no una traza | M | |
| A5 | Revisar `storage/uploads/` | Existe y el servidor web puede escribir (si no, los adjuntos fallarán) | A | |
| A6 | Mirar el panel del navegador en `/dashboard` | Sin errores de JavaScript en la consola | M | |
| A7 | Recargar varias veces y pulsar atrás | No aparece contenido duplicado ni «la página se está mostrando dos veces» | M | |

---

## B. Cuentas, sesión y recuperación

> **Prerrequisito de B1–B4:** poner `REGISTRATION_ENABLED=true` en `.env` y
> reiniciar. Es el registro público el que se prueba ahí; el estado cerrado, que
> es el de por defecto, se prueba en B16–B18.

| # | Qué probar | Esperado | P. | Estado |
|---|---|---|---|---|
| B1 | Registrarse en `/register` con email y contraseña de 12 caracteres | Cuenta creada y **entra directamente** al panel | A | |
| B2 | Registrarse con contraseña de 9 caracteres | Rechazado, con mensaje de longitud mínima | A | |
| B3 | Registrarse con email ya existente | Rechazado sin revelar si el email existe | M | |
| B4 | Mirar el campo de contraseña de `/register` | El texto de longitud mínima coincide con el del servidor (**no un número fijo**) | M | |
| B5 | Entrar con contraseña incorrecta | Mensaje de error genérico, **no** «usuario no encontrado» | A | |
| B6 | Entrar con un email inexistente | El mismo mensaje que B5, y tarda un tiempo parecido | M | |
| B7 | Fallar el login 6 veces seguidas con la misma IP | A partir del 6.º: «demasiados intentos» y no entra. Es aviso + vuelta a `/login`, no un 429 | M | |
| B8 | Usar un email inexistente en `/forgot-password` | **Respuesta idéntica** a la de un email real: mismo mensaje, mismo destino | A | |
| B9 | Pedir recuperación con un email real, usar el enlace | Permite poner contraseña nueva y confirmar | A | |
| B10 | Reutilizar ese mismo enlace una segunda vez | Falla: es de un solo uso | A | |
| B11 | Con la sesión abierta, cambiar la contraseña desde la cuenta | **Se mantiene la sesión** (hay que refrescar) | A | |
| B12 | Cambiar la contraseña de un usuario desde `/admin` y usar su sesión | Al pedir la siguiente página, **la sesión está cerrada** | A | |
| B13 | Entrar en `/dashboard` sin sesión | Redirige a `/login` | A | |
| B14 | Desactivar un usuario desde `/admin` y usar su sesión | En la siguiente petición pierde el acceso, sin esperar a que caduque la sesión | M | |
| B15 | Cerrar sesión y volver atrás | No se puede volver a la zona autenticada | A | |
| B16 | Con `REGISTRATION_ENABLED=false` (por defecto), abrir `/login` | **No aparece** el enlace «Regístrate aquí» | A | |
| B17 | Con el registro cerrado, entrar en `/register` | 403 con la página de error, **no** el formulario de alta | A | |
| B18 | Con el registro cerrado, mandar un POST a `/register` a mano | 403, y **no** «token CSRF inválido» ni ningún mensaje de formulario | M | |
| B19 | Crear un usuario desde `/admin` con el registro cerrado | Se puede entrar con esa cuenta: el alta por panel no depende del interruptor | A | |
| B20 | Con el registro cerrado, en `/admin` → **Nuevo usuario**, crear una cuenta con rol `user` | Cuenta creada y se puede iniciar sesión con ella | A | |
| B21 | Crear esa misma cuenta otra vez con el mismo email | Dice **«Ya hay una cuenta con ese email»**, no un error genérico | A | |
| B22 | Crear una cuenta con contraseña de 9 caracteres | Rechazado por la misma longitud mínima que el registro público | A | |
| B23 | Crear una cuenta con contraseñas que no coinciden | Rechazado, y no se crea la cuenta | A | |
| B24 | Entrar en `/admin` con un rol que **no** tenga `admin.users` | No aparece la tarjeta de alta, y el POST directo responde 403 | M | |
| B25 | Crear una cuenta desde `/admin` y luego mirar su ficha | Nace con el rol elegido y activa | M | |
| B26 | Crear un admin desde `/admin` y entrar con él | Funciona: el alta del panel también puede crear administradores | M | |
| B27 | Activar el registro desde `/admin` (interruptor) y abrir `/login` en una ventana sin sesión | Aparece el enlace «Regístrate aquí» y `/register` sirve el formulario: el interruptor del panel manda sobre el `.env` | A | |

---

## C. Panel y navegación

| # | Qué probar | Esperado | P. | Estado |
|---|---|---|---|---|
| C1 | `/dashboard` con formularios | Lista con título, estado y fecha; enlace a cada uno | A | |
| C2 | Crear un formulario | Se abre su constructor. El estado es **borrador** | A | |
| C3 | Botón de duplicar un formulario | Crea una copia con otro nombre; cuenta para el límite horario | M | |
| C4 | Pulsar «Crear formulario» 31 veces en una hora | A partir del 31.º avisa del límite (30/hora por usuario) | B | |
| C5 | Navegar por todas las entradas del menú | Ninguna devuelve 403 ni 404 inesperado con una cuenta `admin` | A | |
| C6 | Entrar al panel con una cuenta sin `admin.access` | `/admin` da 403 con su propia página | A | |
| C7 | Crear un formulario nuevo y volver al listado | Aparece con estado **borrador** y su ficha abre | M | |
| C8 | Abrir el panel en escritorio (ventana ≥ 992 px) | La navegación es una **columna lateral** fija a la izquierda con menú, botón de crear y usuario; **no** hay barra superior | M | |
| C9 | Bajar con la rueda en escritorio | La sidebar se queda fija (no se va con el scroll) y la página no gana scroll horizontal | B | |
| C10 | Abrir el panel en móvil (ventana ≤ 991 px) y pulsar la hamburguesa | Entra un **cajón** desde la izquierda con la navegación; se cierra al elegir una entrada; sin JS, un enlace navega y recarga | A | |
| C11 | En móvil, abrir el cajón y mirar el menú y el bloque de usuario | El menú, «Nuevo formulario» y el usuario **conservan su texto** (no se quedan solo con el icono) | B | |
| C12 | En móvil, mirar los botones del contenido (p. ej. «Nuevo formulario» del panel) | Los botones con icono muestran **solo el icono**; los de **ancho completo** (Guardar ajustes, Eliminar…) conservan su texto | B | |
| C13 | Comparar, en escritorio, la cabecera de la ficha, el botón «Nuevo formulario» del panel y la cabecera del builder | Los tres grupos de botones miden lo mismo (alto del CTA y mismo radio); no conviven 44 y 38px en la misma fila | M | |
| C14 | En móvil, entrar en Administración y mirar la sub-navegación (Usuarios, Grupos de roles, Roles y permisos…) | Los botones se quedan **solo con su icono** y caben en una línea | B | |
| C15 | En móvil, mirar los botones de crear de Admin (grupo/rol) y el desplegable de grupos de una fila | Los de crear se quedan con el icono; el de grupos conserva sus insignias **legibles** | M | |
| C16 | Comparar en escritorio las cabeceras de respuestas, analítica y administración (usuarios, formularios, log) y la **sub-navegación de Administración** (Usuarios, Grupos de roles, Roles y permisos…) con la de la ficha | Las acciones, los controles y las pestañas de Administración miden lo mismo (44px, radio 12, texto 16px); no queda ningún `btn-sm` de 32px ni pestaña de 40px al lado, y «Exportar CSV» no ocupa la fila entera (sin `w-100`) | M | |

---

## D. Constructor

| # | Qué probar | Esperado | P. | Estado |
|---|---|---|---|---|
| D1 | Abrir el constructor de un formulario vacío | Lienzo central, vacío, con título y descripción editables sobre él; sin columnas laterales; estado vacío con botón «Agregar pregunta» | A | |
| D2 | Agregar un campo al lienzo | Aparece una tarjeta con su etiqueta y su pie (tipo + obligatoria + herramientas) | A | |
| D3 | Pulsar el engranaje de una tarjeta | Se abre el cajón de ajustes a la derecha con la configuración de ese campo; el engranaje de otra tarjeta lleva la suya allí (una sola instancia) | A | |
| D4 | Editar etiqueta y opciones, luego **Guardar** y reabrir | Todo persiste | A | |
| D5 | Cambiar el orden de dos campos, **Guardar**, reabrir | El orden persiste | A | |
| D6 | Añadir 21 tipos de campo distintos | Todos aparecen en la lista y ninguno revienta el lienzo | A | |
| D7 | Editar un campo, cambiar de pestaña a **Lógica** y guardar | La pestaña Lógica no borra lo que había en Contenido ni al revés | A | |
| D8 | Poner un campo obligatorio y enviar el formulario vacío | El navegador avisa; y si se fuerza, el servidor también lo rechaza | A | |
| D9 | Poner mínimo/máximo/longitud en un campo de texto y número | Los mensajes de error son los del tipo correcto, no genéricos | M | |
| D10 | Configurar una matriz con una fila incompleta al enviar | Error indicando la fila concreta | M | |
| D11 | Configurar un ranking con dos opciones en la misma posición | Rechazado («posiciones repetidas») | M | |
| D12 | Marcar un campo de texto como **dato personal** y guardar | La marca persiste | A | |
| D13 | Intentar marcar como PII una sección o una página | No se permite (no guardan respuestas) | M | |
| D14 | Guardar con el navegador bloqueando peticiones | Aviso de que no se pudo guardar; **no** dice que sí | A | |
| D15 | Cambiar algo, recargar con F5 sin pulsar **Guardar** | Los cambios **se pierden**, sin aviso de salida. Si pierdes trabajo, es el comportamiento actual: guarda antes de recargar | M | |
| D16 | Crear una condición que se referencia a sí misma (o dentro de su propio bloque) | El guardado la rechaza con un mensaje entendible | M |
| D17 | Escribir una etiqueta con datos personales («¿Tu nombre y apellidos?») | Aparece un aviso amarillo que lo dice, con dos botones, **sin** marcarlo solo | A | |
| D18 | En ese aviso, pulsar **Sí, es dato personal** | El interruptor se activa y el cambio **se guarda** (reabrir y comprobar) | A | |
| D19 | Escribir una etiqueta personal y pulsar **No es** | El aviso desaparece | A | |
| D20 | Con el aviso descartado, **reescribir** la etiqueta con otras palabras | El aviso vuelve a aparecer, porque es otra pregunta | M | |
| D21 | Poner una etiqueta con «Nombre de la empresa» | Aparece el aviso (no decide), y al pulsar «No es» el campo **sigue sin marcarse** y sin consecuencias | M |
| D22 | Abrir el engranaje de una sección o una página | **No** sale el aviso de PII, porque no guardan respuestas | M |
| D23 | Abrir **Configuración** del formulario y cambiar el color principal | El preview (embebido o en vivo) cambia al instante, sin cerrar el cajón | A | |
| D24 | Abrir **Configuración**, elegir una tipografía del catálogo | `#stFontNote` dice que se sirve desde esta instalación, en verde | A | |
| D25 | Abrir **Configuración**, dejar la tipografía en **Predeterminada** | `#stFontNote` dice que usará la del panel; el formulario publicado no emite `@font-face` | A | |
| D26 | En la configuración, activar la confirmación y mirar **Campo email** | La lista desplegable ofrece las etiquetas de los campos de tipo email que ya existen | M |
| D27 | Buscar en la pantalla de configuración un ajuste sin explicación | Los que necesitan contexto lo tienen en gris debajo; ningún número suelto sin decir qué es | M | |

---

## E. Publicación, estados y revisión

| # | Qué probar | Esperado | P. | Estado |
|---|---|---|---|---|
| E1 | Crear un formulario y abrir su enlace sin publicar | 403 con la página de acceso denegado | A | |
| E2 | Publicar y abrir el enlace | Se ve el formulario | A | |
| E3 | Cerrar el formulario y abrir el enlace | Ya no se puede enviar; **las respuestas anteriores siguen ahí** | A | |
| E4 | Con revisión activada, pulsar **Publicar** | **No se publica**: avisa de que exige revisión y devuelve a la ficha (no un 403 mudo) | A | |
| E4b | Con revisión activada, pulsar **Enviar a revisión** | Pasa a `in_review` y aparece en `/review` | A | |
| E4c | Con revisión activada, **aprobar** con un revisor | Queda **Aprobado**, no publicado: el enlace sigue mostrando la página informativa (no el formulario) y los envíos dan 403 | A | |
| E4d | Como dueño, pulsar **Publicar** sobre un formulario ya aprobado | Se publica sin volver a la cola y el enlace ya sirve el formulario | A | |
| E4e | Intentar **Enviar a revisión** un formulario ya aprobado | Rechazado: «ya está aprobado: publícalo o edítalo» | M | |
| E5 | Con revisión activada, editar un formulario ya aprobado | Queda en **borrador** y **se borra la aprobación anterior**: aparece «Enviar a revisión» y la bandeja ya no lo lista hasta que se envíe | A | |
| E6 | Aprobar un formulario propio | Se permite, y el registro de eventos lo marca como propio | M | |
| E7 | Intentar aprobar sin permiso `forms.review` | 403. Tener `forms.edit_own` **no** sirve para aprobar | A | |
| E7b | Aprobar con un rol que **solo** tiene `forms.review` + `dashboard.view` (sin `forms.manage_all` ni `forms.edit_own`) el formulario de otro | Se aprueba: aprobar exige el permiso de revisión, **no** el de editar. Tras aprobar vuelve a `/review` (la ficha editable le daría 403) | A | |
| E7c | Ese mismo revisor pulsa **Ver** en su bandeja | Abre la estructura **read-only** del formulario en revisión (preguntas, tipos, opciones), con vuelta a la bandeja. La ficha editable le da 403, y un formulario en borrador ajeno tampoco se le abre | A | |
| E8 | Abrir un formulario en revisión por su enlace | Página informativa de «en revisión» (no un 404), y enviar da 403 | M | |
| E9 | Devolver a borrador un formulario en revisión | Vuelve a borrador y se puede editar | A | |
| E10 | Aprobar un formulario ya publicado | Rechazado (el estado de origen no es válido) | M | |
| E11 | Programar apertura futura (`open_at`) | Antes de esa hora no se puede enviar; a partir de ella, sí | M | |
| E12 | Poner `close_at` anterior a `open_at` | El ajuste se rechaza con error | B | |
| E13 | Alcanzar el cupo máximo de respuestas | Envío rechazado con el aviso de cupo | M | |
| E14 | Poner el cupo a 0 sin activarlo | No limita nada (0 = sin cuota) | B | |

---

## F. Llenado del formulario (el punto de vista de quien responde)

| # | Qué probar | Esperado | P. | Estado |
|---|---|---|---|---|
| F1 | Rellenar todo y enviar | Confirmación y URL de gracias con **token** en `?t=` | A | |
| F2 | Abrir la URL de gracias de otra persona | Muestra esa respuesta, no una lista ni un error genérico | A | |
| F3 | Reusar la URL de gracias cambiando el número del token | Error; no se ven respuestas ajenas | A | |
| F4 | Enviar un campo inválido saltándose la validación del navegador | El **servidor** lo rechaza y no guarda nada parcial | A | |
| F5 | Marcar un campo obligatorio con un espacio «   » | Rechazado (un espacio no es una respuesta) | M | |
| F6 | Navegar un formulario de 3 páginas con **solo** el teclado (Tab y flechas) | Se puede responder y avanzar entero sin ratón | A | |
| F7 | En una escala, un NPS o unas estrellas, tabular hasta el control | Recibe el foco y se ve el anillo de foco. **Un tabulador, no ninguno** | A | |
| F8 | En multipágina, pulsar «Siguiente» con el teclado | El foco se queda en el paso: **no vuelve al principio del documento** | A | |
| F9 | Saltar entre pasos con los botones «Anterior» / «Siguiente» | El foco no salta al primer campo por sorpresa | M | |
| F10 | Abrir la página al revés (Ctrl+R en Chrome) en un formulario de 3 páginas | Recupera lo ya contestado si el borrador está activo | M | |
| F11 | Un campo con condición que se cumple | Aparece al instante; al enviar, **sí** se guarda | A | |
| F12 | Un campo con condición que NO se cumple | Desaparece y **no se guarda** aunque se mande su valor a mano | A | |
| F13 | Condición sobre un campo dentro de una sección con su propia condición | La sección se muestra solo si se cumplen **las dos** reglas | A | |
| F14 | Responder el último paso de un multipágina | Se guarda y se llega a la página de gracias | A | |
| F15 | Abrir dos veces el mismo formulario con barajado activado | Las preguntas salen en distinto orden en cada apertura GET | B | |
| F16 | Con avance automático activado, responder un paso | Salta al siguiente solo; y **no** salta al cargar con un borrador restaurado | M | |
| F17 | Con avance automático, un paso con un ranking de posiciones repetidas | No avanza solo: está incompleto aunque «pase» el navegador | M | |
| F18 | Insertar el `<iframe>` de embed en una página **del mismo dominio** | El formulario se ve completo dentro del iframe | M | |
| F18b | Insertar el mismo iframe en una página de **otro dominio** | El navegador lo bloquea, y es lo correcto: el embed solo se permite en el mismo origen | M | |
| F19 | Abrir `/f/{slug}/embed` sin el embed activado | 403 | B | |
| F20 | Cambiar el idioma con `?lang=en` (o el selector si existe) | La interfaz cambia a inglés | B | |
| F21 | Elegir la 3.ª de 5 estrellas, y luego pasar el ratón por la 5.ª y salir | Se encienden 1..3; con el ratón encima se previsualizan 1..5 y al salir vuelve a 1..3; el valor enviado es «3» | A | |
| F22 | Responder un ranking con los botones ▲▼ (mover una fila arriba y otra abajo) | Las filas se reordenan y los números se renumeran 1..N solos; se envía sin teclear ningún número y el orden guardado es el que se ve | A | |
| F23 | Un ranking con la ventana recargada con un borrador ya ordenado | Las filas reaparecen en el orden de las posiciones guardadas, no en el orden original de las opciones | M | |
| F24 | Desactivar JavaScript y abrir un formulario con ranking | Queda el campo numérico editable (no las flechas) y se responde escribiendo 1..N: el ranking sigue siendo respondible sin JS | M | |

---

## G. Condicionales y multipágina (detalle)

| # | Qué probar | Esperado | P. | Estado |
|---|---|---|---|---|
| G1 | Una condición de texto con cada operador (igual, distinto, contiene, no contiene, tiene valor, está vacío, regex) | Cada uno se comporta como su etiqueta | A | |
| G1b | Una condición sobre un desplegable | Aparecen «Incluye la opción» / «No incluye la opción», **no** «Contiene» | M | |
| G1c | Una condición sobre un número o una fecha | Aparecen mayor / mayor o igual / menor / menor o igual | M | |
| G2 | Un campo obligatorio cuya condición no se cumple | **No** bloquea el envío (no se está mostrando, no se valida) | A | |
| G3 | Barajado activado + envío con errores | Al recargar por error **no** reordena las preguntas (mantiene lo que el usuario vio) | M | |
| G4 | Una página cuya condición la salta | El paso se marca como omitido en el pie y **la barra de progreso no cuenta** ese paso | A | |
| G5 | Barra de progreso en un formulario con páginas omitidas | El porcentaje no se descuadra al saltarse un paso | M | |
| G6 | Pie del paso actual | El número de paso y el total coinciden entre el bloque y el pie | M | |

---

## H. Adjuntos

| # | Qué probar | Esperado | P. | Estado |
|---|---|---|---|---|
| H1 | Subir un PDF, una imagen y un .docx | Los tres se aceptan | A | |
| H2 | Subir un `.exe`, `.php` o `.js` | Rechazado, aunque el navegador lo permita | A | |
| H3 | Renombrar un `.exe` a `.pdf` | Rechazado: el tipo se mira en el **archivo**, no en el nombre | A | |
| H4 | Subir un archivo de más tamaño del permitido | Error de tamaño; el tamaño que manda es el menor entre ajuste y `php.ini` | M | |
| H5 | Subir más archivos de los permitidos | Error de número de archivos | M | |
| H6 | Enviar un formulario con archivo de más de `post_max_size` | **413 «demasiado grande»**, no «sesión inválida» | A | |
| H7 | Descargar un adjunto desde el detalle de la respuesta | Se descarga el archivo correcto | A | |
| H8 | Comprobar la ruta del archivo en el disco | Vive en `storage/uploads/`, **fuera de `public/`** (no se accede por URL directa) | A | |
| H9 | Borrar una respuesta con adjuntos y ejecutar `clean-uploads.php --dry-run` | El archivo aparece como huérfano para limpiar | M | |
| H10 | Un campo de archivo marcado como PII, descargado **sin** el permiso `responses.pii` | 403, no el archivo | A | |

---

## I. Acceso restringido por email (F4)

| # | Qué probar | Esperado | P. | Estado |
|---|---|---|---|---|
| I1 | Formulario en modo `public` (por defecto) | Cualquiera con el enlace responde | A | |
| I2 | Cambiar a modo `email` y abrir el enlace sin haber pedido acceso | Pide acceso; no se ve el formulario | A | |
| I3 | Pedir acceso con un email de la lista | Llega el correo con el enlace; al abrirlo, se entra | A | |
| I4 | Pedir acceso con un email **fuera** de la lista | **Respuesta idéntica** a la de un email de la lista (mismo mensaje, mismo destino) | A | |
| I5 | Enviar el formulario sin acceso válido | 403 (no «algo va mal», no «sesión inválida») | A | |
| I6 | Usar un enlace de acceso de **otro formulario** | No vale: el enlace va atado al formulario | A | |
| I7 | Quitar un email de la lista y usar su enlace ya activating | El enlace deja de servir en la petición siguiente | A | |
| I8 | Abrir el formulario en una ventana privada y usar un enlace válido | El acceso depende de la sesión del navegador, no solo del enlace | M | |
| I9 | Ver el enlace copiado y el QR de un formulario con acceso restringido | Ambos funcionan como el enlace, sin trampas | M | |
| I10 | Pantalla *Participantes* en modo `allowlist`, escribir un correo y pulsar *Añadir* | La fila aparece **sin recargar**, la caja queda vacía y con el foco dentro, y el aviso de lista vacía desaparece | M | |
| I11 | En la misma caja, escribir un correo ya presente y pulsar *Añadir* | No aparece una segunda fila; el motivo sale debajo de la caja | M | |
| I12 | Escribir un correo sin formato y pulsar *Añadir* | No se añade nada y el motivo dice que el correo no es válido (no un error de red) | M | |
| I13 | Añadir con el teclado (Enter en la caja) | Funciona igual que con el botón, sin recargar | M | |
| I14 | Abrir la pantalla *Participantes* con JavaScript deshabilitado y añadir un correo | El alta funciona con el POST normal y avisa con un flash | M | |
| I15 | `/admin/settings` con un `user` normal | 403: el panel entero exige `admin.access` | A | |
| I16 | `/admin/settings` con un admin: mover el interruptor del registro | El estado cambia al instante, sin recargar, y el texto de al lado se actualiza | M | |
| I17 | El interruptor, sin red (parar MySQL o cortar la conexión) | La casilla **vuelve** a lo que dice el servidor y sale un aviso rojo: no se queda marcada sin guardar | M | |
| I18 | Poner `REGISTRATION_ENABLED=true` en `.env` con el interruptor apagado y abrir `/register` | 403: la fila de `app_settings` gana al `.env` | A | |

---

## J. Datos personales (P1 / F1)

| # | Qué probar | Esperado | P. | Estado |
|---|---|---|---|---|
| J1 | Responder un formulario con un campo marcado, entrando como `admin` | El valor se ve **en claro** | A | |
| J2 | Ver el mismo detalle entrando como `user` | El campo sale enmascarado (`[oculto]`, `j***@e***.com`, prefijo de teléfono) | A | |
| J3 | Ver el listado de respuestas como `user` | También enmascarado, no solo el detalle | A | |
| J4 | Exportar a **CSV** como `user` | Enmascarado **dentro** del CSV, sin romper el formato | A | |
| J5 | Exportar a **JSON** como `user` | Enmascarado, con el total y la estructura intactos | A | |
| J6 | Mirar la IP y el user-agent como `user` | `192.168.1.x` y `[oculto]` | M | |
| J7 | Exportar como `user` con **muchas** respuestas (>5000) | Las últimas también salen enmascaradas (no solo las primeras) | M | |
| J8 | Un campo de texto **no** marcado como dato personal | Sale **en claro**. La app no puede adivinar si un texto es «tu jefe» o «tu opinión»: sin la marca no hay enmascarado. El constructor avisa si la etiqueta lo parece (D17), pero no marca solo | B | |

---

## K. Analítica y exportaciones

| # | Qué probar | Esperado | P. | Estado |
|---|---|---|---|---|
| K1 | Abrir la analítica de un formulario con respuestas | Series por día, distribución y desglose | A | |
| K2 | **Cambiar de página en la tabla de respuestas** | La tabla **vuelve a ser interactiva**: se puede abrir una respuesta. El spinner no se queda girando | A | |
| K3 | Cambiar de página dos veces seguidas | La segunda también funciona | A | |
| K4 | Si la petición falla, ver el error | Avisa y la tabla vuelve a ser utilizable | M | |
| K5 | Exportar a CSV | Se descarga con el nombre `<slug>.csv`; columnas `#`, `Fecha` y una por campo; **sin columnas de sección** | A | |
| K6 | CSV con respuestas de matriz, ranking y casillas | Quedan aplanadas en una sola celda por campo | M | |
| K7 | CSV con un filtro de fechas activo | Solo incluye las respuestas del rango | A | |
| K8 | Exportar a JSON | Envoltura con `form`, `total` y `submissions[]` | A | |
| K9 | Exportar la estructura del formulario | JSON con campos y ajustes, utilizable para reprocesar | M | |
| K10 | Exportar 121 veces en una hora | A partir de la 121.ª avisa del límite (120/hora por usuario) | B | |
| K11 | CSV con acentos y eñes | Se abre bien en una hoja de cálculo (UTF-8) | M | |

---

## L. Correos

*(Si no hay SMTP configurado, marca el bloque entero `N/A`.)*

| # | Qué probar | Esperado | P. | Estado |
|---|---|---|---|---|
| L1 | Enviar una respuesta con aviso al dueño activado | Llega el correo al dueño con el detalle | A | |
| L2 | Con confirmación activada y campo de email | Llega al email indicado por el que respondió | A | |
| L3 | Revisar el remitente | Es el configurado en `MAIL_FROM_ADDRESS` | M | |
| L4 | Abrir el enlace de recuperación desde el correo | Llega al formulario de contraseña nueva, **aunque haya sesión iniciada** | A | |
| L5 | Pedir acceso (F4) y abrir el enlace del correo | Funciona y no lleva a una página interna | M | |
| L6 | Con revisión activada y aviso por correo | Llega el aviso de que hay algo en la bandeja | B | |
| L7 | Enviar una respuesta **sin** SMTP | La respuesta **se guarda igual**; solo falta el correo | A | |

---

## M. Seguridad y anti-bot

| # | Qué probar | Esperado | P. | Estado |
|---|---|---|---|---|
| M1 | Honeypot activo (por defecto) | Invisible al usuario, presente en el HTML | M | |
| M2 | Rellenar el honeypot a mano y enviar | **Finge éxito** (muestra gracias) y **no guarda la respuesta** | A | |
| M3 | Turnstile con las dos claves | El widget aparece y bloquea el envío si no se resuelve | A | |
| M4 | Turnstile activado **sin** claves en `.env` | El formulario da 403 (no se degrada en silencio) | A | |
| M5 | Enviar el mismo formulario 11 veces en 10 minutos desde la misma IP | A partir del 11.º: página 429. El contador es por formulario e IP | M | |
| M6 | Recoger el enlace de un formulario desde `?t=` | El enlace no permite ver ni cambiar nada más que esa respuesta | A | |
| M7 | Revisar el registro de seguridad tras los intentos anteriores | Quedan registrados los eventos de seguridad relevantes | M | |
| M8 | Ver el origen de los envíos (Network) | Los recursos salen del propio dominio; **no hay scripts inline** | M | |
| M9 | Comprobar la cabecera `X-Frame-Options` | `DENY` | B | |
| M10 | Registrar 11 cuentas en 10 minutos desde la misma IP | A partir del 11.º avisa del límite | B | |
| M11 | Con `TRUST_PROXY=true` y un `X-Forwarded-For` inventado | Solo tiene efecto si **tú** decidiste confiar en el proxy; con el valor por defecto se ignora | M | |

---

## N. Administración, roles y grupos

| # | Qué probar | Esperado | P. | Estado |
|---|---|---|---|---|
| N1 | Crear un usuario en `/admin` | Nace con rol por defecto y **sin** permisos extra | A | |
| N2 | Crear un rol nuevo | Nace **sin** permisos: la matriz es la que decide | A | |
| N3 | Dar un permiso a un rol en la matriz | Lo tienen los usuarios de ese rol | A | |
| N4 | Crear un rol con un nombre duplicado o con acentos parecidos | Rechazado, y el motivo es legible | M | |
| N5 | Enviar la matriz de permisos con todo desmarcado | La petición se rechaza: un envío vacío no significa «desmarcar todo», así que hay que decir explícitamente qué quitar | A | |
| N6 | Quitarle el permiso `admin.access` al rol `admin` | No se puede: el rol `admin` conserva siempre el acceso al panel | M | |
| N7 | Crear un grupo y meter dos usuarios | Ambos heredan los roles del grupo | A | |
| N8 | Sacar un usuario del grupo | Pierde los permisos del grupo en la petición siguiente | A | |
| N9 | Enviar un cambio de pertenencia con la lista vacía | Saca al usuario de todos los grupos (no «no lo he tocado») | A | |
| N10 | Dar un rol desde la ficha del grupo y desde la del usuario | Las dos direcciones coinciden: mismo resultado | M | |
| N11 | Un `editor` intenta borrar su propio formulario | Denegado (no tiene `forms.delete_own`) | M | |
| N12 | Un `user` con `forms.manage_all` | Ve y opera formularios de otros usuarios | M | |
| N13 | Guardar un grupo **sin** mandar su nombre | El nombre se conserva; si lo mandas vacío, se queda vacío. Ausente ≠ vacío | M | |
| N14 | Ver la matriz de roles | Refleja lo que decides en `config/permissions.php` (roles del catálogo) | M | |
| N15 | Entrar con una cuenta con rol `approver` | Ve *Dashboard* y *Revisión*, y **no** ve «Nuevo formulario» ni *Administración*: nada de lo que ve le da un 403 | A | |
| N16 | Con ese mismo `approver`, abrir la bandeja de `/review` y pulsar **Ver** | Ve la **estructura read-only** del formulario en revisión (no la ficha editable, que le da 403), y puede aprobarlo o devolverlo desde la bandeja; al aprobar vuelve a `/review`, no a la ficha | A | |
| N17 | En la tarjeta *Baja de roles* | Los cuatro del sistema salen marcados como «Sistema» y **sin** botón de borrar; los tuyos, con el número de personas que los llevan | A | |
| N18 | Intentar borrar un rol propio que alguien lleva | No se borra y el motivo dice cuántas personas lo tienen | A | |
| N19 | Borrar un rol propio que no lleva nadie | Se borra, avisa, y también desaparece de los grupos que lo confieran (con aviso previo al confirmar) | M | |
| N20 | El mismo borrado con la confirmación rechazada | No pasa nada: el rol sigue ahí | M | |
| N21 | Abrir `/admin` | La cabecera «Usuarios» lleva *Nuevo usuario*; debajo, la barra de filtros (buscador, rol y estado) y la tabla de usuarios a todo el ancho. El alta vive en su diálogo, no en una tarjeta de la fila | M | |
| N22 | Entrar a `/admin` con un rol sin `admin.access` | 403: el panel entero exige `admin.access` | M | |
| N23 | Abrir la ficha de un formulario, plegar uno de sus seis grupos, recargar y volver | Sigue plegado **en esa pantalla**, y al entrar en `/admin/groups` la lista sale **abierta**: el estado va por pantalla | A | |
| N24 | Crear un usuario con el registro público **cerrado** | *Nuevo usuario* abre el diálogo y la cuenta se crea igual. Con el **JavaScript desactivado** el formulario se ve en su tarjeta y funciona: es la única vía para crear un `user` o un `editor` | A | |
| N25 | Con el diálogo de alta abierto, crear una cuenta con un email repetido | El aviso sale dentro del diálogo, que **sigue abierto**, para corregir sin volver a abrirlo | A | |
| N26 | A un `user` darle `forms.manage_all` desde *Permisos extra* del menú `⋯` de su fila | Ve y opera formularios ajenos **sin** cambiarle el rol ni meterlo en un grupo | A | |
| N27 | Quitarle esa casilla y guardar | Pierde el permiso en la petición siguiente: el POST es la lista completa, no «no lo he tocado» | A | |
| N28 | Abrir el menú `⋯` de un usuario | En la primera columna del diálogo, en solo lectura, el desglose de dónde sale cada permiso (Rol / Grupos / Extra) | M | |
| N29 | Entrar a `/admin` con un rol con `admin.access` pero sin `admin.users` | 403: el listado exige `admin.users`, no basta con el acceso al panel | M | |

---

## O. Errores y casos límite

| # | Qué probar | Esperado | P. | Estado |
|---|---|---|---|---|
| O1 | Cada página de error (403, 404, 405, 413, 419, 429, 500, 503) | Todas muestran **icono + motivo + qué hacer**. El motivo aparece **una sola vez** y nunca como «403 Error» | A | |
| O2 | Provocar un 500 | Página de error propia, sin traza PHP visible | A | |
| O3 | Un envío con el cuerpo enorme | 413 con su motivo | A | |
| O4 | Un envío con el token de sesión caducado | 419 «sesión expirada», no un error genérico | A | |
| O5 | Navegar por todas las rutas del panel con una cuenta recién creada | Ninguna revienta con un error PHP | A | |
| O6 | Un formulario con un nombre larguísimo (200 caracteres) | La cabecera y el botón no se desbordan ni se pisan | M | |
| O7 | Los filtros de la tabla de respuestas en móvil | Envuelven en varias líneas, no se salen de la pantalla | M | |
| O8 | many respuestas y filtrar por un rango sin resultados | Mensaje de «sin resultados», no una tabla vacía sin explicación | M | |
| O9 | Navegar con el teclado por el panel | Foco visible en todos los botones; las imágenes con significado tienen texto | M | |
| O10 | Reducir la ventana a 375 px y recorrer el panel y el constructor | Nada se desborda horizontalmente; las acciones principales se siguen viendo | M | |

---

## P. Ayuda con tooltip (`app-help`)

| # | Qué probar | Esperado | P. | Estado |
|---|---|---|---|---|
| P1 | Abrir **Configuración** en el constructor | Los ajustes se ven en fila, sin un párrafo gris debajo de cada control | A | |
| P2 | Pasar el ratón por un `?` de tema o de comportamiento | Aparece el texto con `<code>` y `<strong>` bien formateados | A | |
| P3 | En una pantalla de móvil (o con el ratón lejos), **tocar** el `?` | Aparece la ayuda: el `click` está en el trigger porque no hay hover | M | |
| P4 | Con el teclado: tabular hasta un `?` y pulsar Intro | Se abre la ayuda y el anillo de foco se ve | A | |
| P5 | Con un interruptor, tabular hasta su `?` y **pulsarlo** | Se abre la ayuda y **el interruptor no cambia**: el clic anula el reenvío del `<label>` | A | |
| P6 | Cambiar la fuente en el modal y mirar `#stFontNote` | El aviso aparece **visible** al cambiar la opción, sin pasar el ratón: es estado, no explicación | A | |
| P7 | Guardar y recargar: ¿sigue el `?` con el mismo texto? | Sí, y el ajuste guardado no se ha movido de sitio | A | |
| P8 | El `?` mide 14 px: intentar pulsarlo con el dedo | Se pulsa igual: el área táctil está ampliada con `::after` | M | |

---

## Q. Limpieza visual del constructor y del fill (bloque 6)

| # | Qué probar | Esperado | P. | Estado |
|---|---|---|---|---|
| Q1 | Paleta → *Agrega elementos* → **Estructura** | *Sección* y *Página* son dos tarjetas separadas por un hueco, no un bloque pegado | M | |
| Q2 | En el lienzo, una sección con preguntas dentro | Los campos van sobre el fondo de la sección **sin** la línea vertical de la izquierda: no hay doble escalón | M | |
| Q3 | Una sección vacía | Se ve el recuadro punteado con la pista, no un hueco invisible | M | |
| Q4 | Añadir un campo NPS o de estrellas a la lienzo | La miniatura se ve como una tira de botones o de estrellas con su forma, no como texto suelto | M | |
| Q5 | Lo mismo con escala, ranking y matriz | Cada miniatura se distingue de las demás y cabe en la tarjeta sin scroll | M | |
| Q6 | Abrir el **preview en vivo** con una sección | El título sale a la izquierda con el icono a la derecha, igual que en el formulario | M | |
| Q7 | Preview en vivo con NPS, estrellas, matriz y ranking | Se ven como en el fill: los mismos botones, celdas y posiciones | M | |
| Q8 | Publicar un formulario con un salto de página **sin** etiqueta | No sale un «Página N» inventado; el pie sigue diciendo «Paso 2 de 3» | M | |
| Q9 | El mismo salto **con** descripción y sin etiqueta | Sale la cabecera con la descripción y sin título inventado | M | |
| Q10 | Formulario publicado con una **sección** que tiene preguntas dentro (no el builder) | Los campos van sobre el fondo de la sección **sin** la línea vertical de la izquierda: en la página pública tampoco hay doble escalón | M | |
| Q11 | Formulario publicado con un campo NPS | Las opciones 0–10 son once **botones cuadrados** con el número centrado, no números sueltos con un borde pegado al texto | M | |
| Q12 | Un formulario paginado creado **antes** de la corrección | Sus saltos ya publicados salen sin «Página N»; si le habías puesto nombre a mano («Primera página»), ese nombre se conserva | M | |

---

## R. Regresiones del último cambio (`751602c`)

Estas diez comprueban fallos que existieron de verdad y que ya no están.
Son las de mayor rentabilidad: si alguna falla, algo se ha roto otra vez.

| # | Qué probar | Esperado | P. | Estado |
|---|---|---|---|---|
| R1 | Abrir la analítica y cambiar de página **dos veces** | La tabla se puede abrir y usar tras cada cambio. Antes se quedaba con `opacity .55` y `pointer-events: none` **para siempre** | A | |
| R2 | Abrir cada una de las 8 páginas de error | El texto explicativo de cada una se ve (antes era markup muerto) | A | |
| R3 | En multipágina, usar solo el teclado para recorrer los pasos | El foco no salta al principio del documento tras «Siguiente» | A | |
| R4 | Botón verde en cualquier vista | Sale **verde**, no índigo (`.app-btn-primary` ya no pisa el color de la variante) | A | |
| R5 | Ficha de un formulario con revisión | Una acción primaria, las de siempre y un menú «más»; «Guardar» no compite con «Publicar» | M | |
| R6 | Escala, NPS y estrellas con el teclado | Un tabulador por control y anillo de foco visible (antes no eran alcanzables) | A | |
| R7 | Constructor → «Guardar» **dos veces seguidas**, sin tocar nada entre las dos | El botón vuelve a su estado normal las dos veces. Antes el segundo clic lo dejaba en «Guardando…» apagado para siempre, sin red y sin error | A | |
| R8 | Ficha del formulario, cabecera del top (Editar campos, Compartir, Publicar/En revisión/Cerrar, «más») | Los cuatro controles miden lo mismo (44 px de alto) y comparten radio; ninguno queda más bajo ni más cuadrado que el vecino | B | |
| R9 | Formulario en `roster`, pantalla *Participantes* → añadir o importar sin recargar | Tras el repintado se ven las **mismas tarjetas** (tablero, lista e importación plegada); no se apilan dos copias ni se pierde el `<details>` | A | |
| R10 | Formulario en `roster`, ficha → guardar cualquier ajuste (sin recargar) | La pantalla *Participantes* **no se toca** (ahora tiene camino propio): el guardado devuelve solo la insignia y las acciones del estado, y no un hueco `vfSlotAccess` que ya no existe | A | |

---

## S. Tanda visual del constructor, el fill y la ficha (2026-10-01)

Compruebas de lo que se ve y de lo que se guarda. Los fallos que había aquí
eran de la clase «se guarda bien y no se ve»: un ajuste que se aplica a nada y
una constante de maquetación que nadie sabe por qué está puesta.

| # | Qué probar | Esperado | P. | Estado |
|---|---|---|---|---|
| S1 | En el constructor, un salto de página → «Configuración» | El interruptor «Mostrar cabecera del paso» aparece **solo** en páginas y secciones, no en los demás campos | M | |
| S2 | Publicar con el interruptor apagado | El paso **no** muestra icono, título ni descripción; el bloque, la etiqueta accesible del paso, el progreso y el pie («Paso 2 de 3») siguen ahí | A | |
| S3 | Recargar el paso sin cabecera | Sigue sin cabecera: no reaparece el título ni aunque se re-pinte el paso | A | |
| S4 | Volver a encender el interruptor y guardar | La cabecera reaparece con el separador fino y el icono ámbar, sin caja ni fondo de color | M | |
| S5 | Formulario publicado con **secciones** con preguntas dentro | Los campos van sin tinte de fondo: la sección ya trae su encabezado y su línea, y el tinte encima solo hacía parecer el formulario partido en dos | M | |
| S6 | Constructor → lienzo, entre dos tarjetas | La línea y el «+» arrancan en el borde de la tarjeta, no desplazados a la derecha | M | |
| S7 | «Agregar dentro» en una sección del constructor | El botón ocupa el ancho de la tarjeta, alineado con las de arriba | M | |
| S8 | Publicar un formulario con reglas de visibilidad | No sale el texto «Se muestra si: …» ni en los campos ni en las secciones; las reglas siguen funcionando (se omiten los campos y las secciones que no aplican) | A | |
| S9 | Constructor → un campo con condición | El editor **sí** sigue viendo su insignia con la regla: es información de quien edita, no de quien responde | M | |
| S10 | Ficha del formulario → «Ajustes del formulario» | Seis grupos plegables (Acceso, Programación y cupo, Notificaciones, Webhook, Mensaje final e Idioma público), con el chevron apuntando al grupo abierto | M | |
| S11 | Plegar dos grupos, recargar y abrir otro formulario | Cada formulario **recuerda los suyos**: los grupos de este no se mueven al abrir otra ficha | A | |
| S12 | Plegar un grupo y **guardar con un error** (por ejemplo una fecha de cierre anterior a la de apertura) | Salen todos los grupos abiertos: el aviso está arriba y el ajuste que falló no puede quedar escondido | A | |
| S13 | Recorrer los grupos con el teclado | El título de cada grupo es un botón enfocable, con anillo de foco visible, y `Enter`/`Espacio` lo pliegan sin enviar nada | A | |
| S14 | Ficha → Constructor → «Diseño» → poner un **color de fondo** y publicar | La página del formulario toma ese color y la tarjeta se queda **blanco** (no se tiñe) | M | |
| S15 | Con el mismo formulario abierto, cambiar el color a uno oscuro | Se lee el formulario con normalidad sobre el fondo oscuro | M | |
| S16 | «Diseño» → cambiar la **fuente** y publicar | El formulario sale con esa tipografía; el panel y la ficha **no** cambian | M | |
| S17 | Dejar el fondo y la fuente sin tocar (o en el valor de fábrica) | Se ve el gradiente y la tipografía de siempre: los dos ajustes tienen valor por defecto | M | |
| S18 | Un salto de página **sin título ni descripción** y con el interruptor encendido | En «Configuración» sale el aviso rojo de que la cabecera no tiene nada que mostrar, y el constructor **y** el publicado se ven igual: ni caja ni icono | A | |
| S19 | Escribir el título del paso con el interruptor encendido | El aviso desaparece solo, y en el constructor aparece la cabecera con ese título | A | |
| S20 | Poner solo la **descripción**, sin título | La cabecera sale igual, con el icono y la descripción | M | |
| S21 | Constructor → agregar un salto de página | La tarjeta dice «Página 1», «Página 2»… según cuántas haya, y no «Página» a secas | M | |
| S22 | Publicar con dos saltos recién creados y el interruptor encendido | Cada paso abre con su icono y su cabecera «Página 1» / «Página 2»; al borrar el título de un paso y guardar, ese paso deja de pintar cabecera y el panel avisa | M | |
| S23 | Formulario publicado con una sección | El icono de la sección queda pegado a su título y a la derecha de la fila, sin el hueco de antes; en el constructor se ve igual | M | |
| S24 | Formulario publicado con un salto de página con título | El icono del paso queda a la derecha de la fila, como el de la sección, no a la izquierda | M | |
| S25 | Publicar y pulsar «Enviar» | El texto del botón y el icono se leen en blanco sobre el degradado, no en negro | M | |
| S26 | «Diseño» → poner un color **muy claro** como primario y publicar | El texto de «Enviar» se sigue leyendo sobre el degradado; si no se lee, es que el color de la izquierda del degradado no admite texto blanco | M | |
| S27 | Constructor → «Agregar elemento» (regresión) | **Aparece el diálogo con sus 21 tipos.** Si se ve la pantalla oscurecida y ningún diálogo, es que `#addFieldModal` ha vuelto a quedar anidado dentro de otro `.modal`: lo delata `tests/unit/ViewStructureTest` | A | |
| S28 | Constructor → «Agregar elemento» **en un móvil** (o con la ventana estrecha) | El diálogo entra entero en la pantalla, con **dos columnas** de tipos y el encabezado «Agregar elemento» arriba; no hay que recargar para cerrarlo | A | |
| S29 | Constructor → vista previa del formulario, abajo del todo | El botón es el del formulario publicado: degradado morado con **«Enviar» en blanco**, no un botón blanco con el texto en negro | M | |
| S30 | `/admin` en pantalla ancha | Cabecera, filtros y tabla a todo el ancho; *Nuevo usuario* es un botón de la cabecera y el alta se abre en un diálogo | M | |
| S31 | `/admin` a 375 px | La tabla se apila en tarjetas (con su rótulo por celda) y **nada** desborda; el diálogo de alta se abre a una columna y los cinco campos se leen enteros | M | |
| S32 | Ficha del formulario, en pantalla ancha | Columna ancha: Detalles y Campos. Columna estrecha: Enlace para compartir y Ajustes del formulario. La tarjeta de Acceso ya no está: vive en la pantalla *Participantes* | M | |
| S33 | Pantalla *Participantes* con una **larga lista de correos** | La lista se lee con holgura y no desborda la pantalla | M | |
| S34 | Ficha a 375 px | Las tarjetas se apilan en el orden del punto S32 y **ninguna** desborda | M | |
| S35 | Formulario publicado a **1920 px** y después a **800 px**; luego el preview en vivo del constructor a ≥992 px | A 1920 la tarjeta ocupa **la mitad** centrada (`col-lg-6`); a 800 ocupa todo el ancho y ninguna matriz se sale con scroll horizontal. El preview del constructor se parte **igual** que el publicado: ninguna regla por id le da un ancho distinto | M | |
| S36 | Ficha → Ajustes → Notificaciones y Webhook, en pantalla ancha (≥992 px, con la tarjeta en su columna estrecha) | Los campos no salen en columnas de ~120 px: van a media fila (email/asunto, formato/secreto) o a fila entera (mensaje, URL), y sus textos no se recortan | M | |
| S37 | Constructor recién abierto | Una sola columna central (lienzo), sin paleta izquierda; la barra trae Guardar, Vista previa y Agregar elemento (una sola vista previa: no hay botón embebido duplicado) | M | |
| S38 | «Agregar elemento» y el `+` entre dos tarjetas | Ambos abren el mismo diálogo con los 21 tipos; el `+` entre tarjetas inserta en esa posición (regresión del anidado: ver S27) | A | |
| S39 | Pie de una tarjeta | Selector de tipo, interruptor de obligatoria y duplicar / configurar / eliminar **visibles sin hover**; marcar «Obligatoria» marca de verdad (persiste al recargar y el campo pasa a `required` en el publicado); el checkbox tiene tamaño de control, no de punto; cambiar el tipo desde el pie actualiza la miniatura sin abrir ajustes | M | |
| S40 | Engranaje de una tarjeta | Se abre el **modal centrado** (igual que «Agregar elemento», ~800 px) con los ajustes del campo y el título fijo «Configuración del elemento» (no la etiqueta de la pregunta); al cerrarlo (x, `Esc` o tocar fuera) la tarjeta que lo abrió queda seleccionada | M | |
| S41 | «Configuración» del formulario | Se abre el mismo tipo de modal centrado (~800 px), con «Guardar configuración» **fijo abajo** (el cuerpo se desplaza por encima) | M | |
| S42 | «Vista previa» con cambios **sin pulsar «Guardar»** | Se abre en otra pestaña contra el BORRADOR: se ven los cambios de ahora, y recargar la pestaña (F5) vuelve a leer el borrador y sigue viéndolos | M | |
| S43 | Formulario publicado con una **matriz** de 3 opciones y en su miniatura del constructor | La **primera columna** (las preguntas) es la más ancha y su texto se lee con holgura; las de opciones ocupan solo lo que necesitan. Antes la pregunta salía más estrecha que las opciones | M | |
| S44 | «Vista previa» de un formulario largo, bajando hasta el final | El botón «Enviar» no queda pegado al último campo: deja el mismo respiro que en el publicado (≈1.25 rem) | M | |
| S45 | «Vista previa» con la ventana baja (o en un móvil) | Se comporta como el modal de nuevo formulario: el diálogo cabe entero (la cabecera «Vista previa» no se corta por arriba) y **scrollea su cuerpo**, no el fondo oscurecido; al final se alcanza el botón «Enviar» | M | |
| S46 | Panel (`/dashboard`, `/admin`, un modal de ajustes) y pantallas de acceso (login/registro) | Los `input` y `select` se ven como los de Bootstrap (radio y alto estándar) con el foco en índigo; el buscador y su selector de orden vuelven a su tamaño `-sm` y quedan a la misma altura | M | |
| S47 | Un `input-group` con icono (login y buscador del panel) | El icono y el campo comparten un **único** borde de 1px (el de Bootstrap); ya no se ve la unión sin costura de antes. En el buscador, el foco lo dibuja el contenedor, no el campo | M | |


---

## T. Acciones del panel sin recarga (2026-10-02)

Comprueban que lo que cambia sin recargar cambia **del todo**: los cuatro huecos
de la ficha se repintan a la vez, el estado que se ve es el que queda en la base
de datos y el camino sin JS sigue siendo un POST que recarga. La columna «¿recarga?»
es la que más se vigila: una mejora que quita la recarga en cuatro sitios y la
deja en el quinto es un fallo de la misma clase que un `</div>` de más.

| # | Qué probar | Esperado | P. | Estado |
|---|---|---|---|---|
| T1 | Ficha de un formulario **borrador** → «Publicar» | No recarga. Se apaga el botón mientras vuela y salen juntos: la insignia «Publicado», los botones que tocan, y la tarjeta de compartir con enlace, QR e iframe | A | |
| T2 | El mismo formulario → «Cerrar» (con confirmación) | Antes de salir: pregunta. Después, sin recarga, «Cerrado» en la insignia **y** desaparece la tarjeta de compartir (ya no hay enlace que repartir) | A | |
| T3 | Publicar y cerrar dos veces seguidas en la misma pantalla | El estado que se ve es el que **queda**: nunca se ven a la vez «Publicado» y el QR, ni «Cerrado» con el botón de publicar | A | |
| T4 | Ficha → «Ajustes del formulario» → cambiar el nombre y guardar | No recarga y el título nuevo se ve en el encabezado al instante; los seis grupos **no se repliegan** y el scroll no salta | M | |
| T5 | Un formulario publicado **con revisión activa** → guardar cualquier ajuste | No recarga, y el aviso dice que quedó en borrador: la insignia pasa a «Borrador» y la aprobación anterior desaparece | A | |
| T6 | Un formulario **aprobado** → cambiar algo en el constructor y guardar | La insignia pasa a «Borrador» y aparece la acción de «Enviar a revisión» (es la misma regla del builder, ahora también en la insignia) | A | |
| T7 | Enviar a revisión → aprobar con otra cuenta | No recarga. La insignia pasa a «Aprobado» (no «Publicado»), sale la traza **nombrando al aprobador** y el botón que aparece es el de **publicar** | A | |
| T7b | Aprobar y, sin recargar, pulsar «Publicar» en la misma ficha | La insignia pasa a «Publicado» y la tarjeta de compartir aparece con enlace, QR e iframe | A | |
| T8 | Igual, pero **devuelto a borrador** | La insignia pasa a «Borrador», la traza registra que lo devolvió quién y vuelve la acción de publicar | A | |
| T9 | Con el JS desactivado, cualquier acción de la ficha | Funciona **igual**, con su 302 y su aviso verde; nada depende del fetch | A | |
| T10 | Con el JS desactivado, pulsar dos veces «Desactivar» en `/admin` | La segunda pulsación ya no procede (la página se fue), así que no hay doble cambio | A | |
| T11 | `/admin` → desactivar un usuario y observar la fila | No recarga. Cambian los dos: la insignia y el botón (que pasa a «Activar», con su icono y su color) | A | |
| T12 | `/admin` → **Guardar** los cambios de una fila recién desactivada | Se guardan sobre el estado nuevo: el `<select>` de rol y el desplegable de grupos **siguen abiertos y con lo que elegiste** | M | |
| T13 | Un formulario con acceso por lista → pantalla *Participantes* → quitar un email | No recarga. Desaparece esa fila de la lista y sale un aviso; **no** aparece el aviso verde de «quitado» por otra parte | M | |
| T14 | Respuestas → borrar una | No recarga. Desaparece la fila, baja el contador del encabezado y sale el aviso | A | |
| T15 | Borrar la **última** respuesta de un formulario | Desaparece la fila, el contador llega a 0 y la página **se recarga** una vez: sale el «aún no tiene respuestas» en vez de una tabla vacía | M | |
| T16 | Con el JS desactivado, borrar una respuesta | Recarga con su aviso verde, como antes | A | |
| T17 | Con el navegador en «sin conexión» o parando la petición con las herramientas de desarrollo | El formulario **se envía solo** y se ve la página de verdad (el rescate es un POST nativo). Si además el repintado falla, **no** se repite la operación: como mucho una vez | A | |
| T18 | En la ficha, pulsar `Ctrl+S` (o `Cmd+S` en Mac) con el foco dentro de un formulario | Guarda ajustes (con el atajo del navegador desactivado por este formulario); en el resto de la página, `Ctrl+S` sigue guardando la página del navegador | M | |
| T19 | En la ficha, con el foco en un campo de texto, `Ctrl+S` | Guarda ajustes en vez de guardar la página: solo aplica dentro del formulario | M | |
| T20 | Volver a pulsar «Guardar» mientras la petición ya está volando | No se manda un segundo POST: el botón está apagado y el formulario queda bloqueado | A | |
| T21 | Un formulario que devuelve **error** al guardar (una fecha de cierre anterior a la de apertura) | Salen todos los grupos abiertos con el motivo del error, los datos escritos siguen ahí y **no** aparece además el mensaje verde de «guardado» | A | |
| T22 | Con el mismo formulario y una respuesta que dé error de validación | Se repinta el mensaje de error y los campos que fallen, sin recargar y sin perder lo escrito | M | |
| T23 | Cualquier acción de la ficha con el teclado | El aviso que sale se anuncia (región `role="status"`) y el foco no se pierde: no estás en el primer punto de partida del documento | A | |
| T24 | Ficha con un formulario en revisión → mirarla en `/dashboard` | La insignia dice «En revisión» (con espacio), no «In_review»; lo mismo en el listado de `/admin` | M | |

---

## U. El diálogo de confirmación y lo que recargaba (2026-10-02)

Ninguna acción del panel vuelve a abrir el prompt gris del navegador: sale un
diálogo de la casa, con su fondo oscurecido y **«Cancelar» enfocado**, no «Sí».
Las tres cosas que hay que mirar con calma son la accesibilidad (este bloque es
el único sitio donde se puede comprobar), que **alta y baja de la lista hagan lo
mismo** y que **cada guardado sin recarga repinte lo que ese guardado cambió**:
lo que se guardaba y no se veía, o la fila que se recargaba, es exactamente lo
que se rompió.

| # | Qué probar | Esperado | P. | Estado |
|---|---|---|---|---|
| U1 | Ficha → «Eliminar formulario» | Sale el diálogo (no el prompt del navegador), con «Eliminar formulario» en el botón de confirmar, y el foco en «Cancelar» | A | |
| U2 | Con el diálogo abierto, pulsa `Esc` | Se cierra **sin** borrar nada | A | |
| U3 | Con el diálogo abierto, pulsa «Cancelar» y después «Eliminar formulario» otra vez | Dos veces pregunta; confirmar es lo único que borra | A | |
| U4 | En el diálogo abierto, pulsa `Tab` varias veces | El foco da la vuelta dentro del diálogo: no se sale a la página de detrás | A | |
| U5 | Con un lector de pantalla, abre cualquiera de los diez diálogos | Anuncia el título y **la pregunta** (p. ej. «¿Eliminar formulario?»), no solo «Confirmar» | M | |
| U6 | Publicar / **Aprobar** / devolver a borrador / activar usuario | El botón de confirmar dice qué hace («Publicar», «Aprobar», «Devolver a borrador», «Activar») y sale en azul, no en rojo | A | |
| U7 | Desactivar usuario / eliminar / borrar respuesta | El botón dice la acción concreta y sale en rojo | A | |
| U8 | Pantalla *Participantes* con acceso por lista → **añadir** un email a la lista | **No recarga.** La lista se reescribe abajo y sale el aviso, igual que al quitarlo | A | |
| U9 | Añadir **dos** emails seguidos | Cada alta suma su fila; al terminar, el campo queda **vacío y con el foco dentro**, sin tabular | M | |
| U10 | Añadir un email que **ya está** en la lista | El motivo sale **junto al campo** («Ese correo ya está en la lista»), no flotando arriba, y **no** sale dos veces | A | |
| U11 | Tras el error de U10, escribir un email válido y añadirlo | Se borra el aviso anterior, la fila aparece y el contador sube | A | |
| U12 | Pantalla *Participantes* con acceso por lista, en móvil (360 px) → añadir un email | El diálogo de confirmación cabe entero y los botones se ven los dos | M | |
| U13 | Con el **JS desactivado**, añadir un email a la lista | Recarga con su aviso verde, como siempre | A | |
| U14 | En la pantalla *Participantes* con allowlist, quita un correo y añade otro seguido | Ambas cosas: la fila se quita y la lista se reescribe, sin que una pise a la otra | M | |
| U15 | Con el JS desactivado, borra un formulario | El `data-confirm` no se lee y se borra sin preguntar. Es lo que hay: el diálogo es una mejora, no una condición para no perder datos | A | |

| U16 | Ficha → «Acceso restringido» → cambia el **modo** y guarda | **No recarga** y el estado no se rompe (el guardado devuelve la insignia y las acciones que quedan). Al abrir *Participantes*, la tarjeta ya sale con el modo nuevo | A | |
| U17 | `/admin` → cambia el **rol** de una fila | **No recarga.** El desplegable se queda en el valor nuevo, sale el aviso verde y la tabla no da un salto de posición | A | |
| U18 | `/admin` → despliega los **grupos** de esa misma fila y guarda | **No recarga.** Las insignias del botón se reescriben con el grupo elegido | A | |
| U19 | `/admin` → guarda el rol de una persona **justo después** de abrir sus grupos | Las dos cosas se quedan: el desplegable de grupos que estaba abierto no se cierra ni se pierde lo que se estaba editando | M | |
| U20 | Con el **JS desactivado**, cambia el rol y guarda un grupo de `/admin` | Recarga con su aviso verde y el cambio aplicado, como siempre | A | |

---

## V. La pantalla de participantes (fase 5, rediseñada 2026-10-15)

Se llega poniendo el formulario en modo `roster` (la fase 6 añadió la `<option>`
al desplegable; el bloque W lo mira) y luego abriendo el botón **Participantes**
de la ficha (entre *Editar campos* y *Respuestas*), que lleva a
`/forms/{id}/access`. Es la fase donde más cosas pueden quedar «a medias sin
decir nada» —un hueco repuesto con el id equivocado no falla, no hace nada—, así
que lo que se mira es que **cada guardado sin recarga repinte lo que ese guardado
cambió** y que **sin JavaScript se vea todo**.

Para llegar al modo: ficha del formulario → *Ajustes* → *Quién puede responder* →
«Solo un listado de participantes» → guardar. El listado en sí vive en la pantalla
**Participantes** (botón de la ficha), no en la ficha.

| # | Qué probar | Esperado | P. | Estado |
|---|---|---|---|---|
| V1 | Formulario en modo `roster` → botón *Participantes* | La pantalla trae **cuatro tarjetas**: la cabecera de acceso, el tablero (números + invitaciones), la **lista** y la **importación** (esta última plegada) | A | |
| V2 | La tarjeta «Importar», su `<summary>` | Se pliega y despliega sin JS y con teclado (`Enter`/`Espacio`); al abrirla se ve «Subir un archivo» a la izquierda y el catálogo a la derecha | A | |
| V3 | **Con el JS desactivado**, abrir `/forms/{id}/access` | **Nada desaparece**: la lista se ve entera y el `<details>` de importar se abre y se cierra con el ratón (es colapso nativo, no depende de JS) | A | |
| V4 | *Añadir uno a la lista* (abre un modal) → escribir una cédula y *Añadir* | **No recarga.** Cierra el modal, aparece la fila, suben los números y la caja queda vacía con el foco dentro | M | |
| V5 | Escribir una cédula **ya presente** y añadir | No aparece una segunda fila; el motivo sale **junto al campo**, no flotando, y no sale dos veces | A | |
| V6 | Alta repetida sin recargar, tres seguidas | El catálogo **no** se repinta en cada alta (no se ve el parpadeo de las columnas) y los números suben | M | |
| V7 | Cada fila → menú `⋯` | Se abre `#rosterEditModal` con el panel de esa fila (un campo por atributo del catálogo) y con **ese** nombre en el título | M | |
| V8 | Editar el nombre y guardar, con la lista ordenada por nombre | **No recarga** y la lista sale ordenada por nombre, no por cédula | A | |
| V9 | Buscar `V-01` en la caja de búsqueda con la lista ordenada por *Nombre* | **No recarga la página**: solo cambia la lista; la URL cambia (`?q=…`), se puede compartir y el botón «atrás» deshace la búsqueda; el resultado sale **en el mismo orden** y en la primera página | A | |
| V10 | Con una búsqueda puesta, borrar a alguien | La lista repuesta **respeta la búsqueda** (no vuelve la lista entera) | A | |
| V11 | Importar un `.xlsx` con una columna nueva | Se crean la persona, el atributo, los números **y el campo del atributo en el alta**, sin recargar | A | |
| V12 | Importar un archivo con una fila sin identificador, en modo *reemplazar* | No se importa nada y el **informe sale igualmente**: dice qué fila y que no entró | A | |
| V13 | Con el JS desactivado, importar un archivo con filas malas | Recarga y el informe se ve dentro de la tarjeta «Importar» (abriéndola), con las filas concretas | A | |
| V14 | Importar un archivo que no es un `.xlsx` | Sale el aviso del motivo y **no** un informe diciendo que se guardaron 0 filas | A | |
| V15 | Tras una importación, recargar la pantalla | El informe **no vuelve a salir**: es de un solo uso | A | |
| V16 | *Vaciar la lista* | El diálogo dice **qué** se pierde y que las respuestas se quedan; la lista se vacía y **el catálogo sigue** | A | |
| V17 | Quitar un atributo del catálogo | Pregunta; desaparecen la columna, sus valores en las filas y el campo del alta, sin recargar | M | |
| V18 | Quitar a alguien de la lista | El diálogo dice que su código deja de servir y que sus respuestas se quedan | A | |
| V19 | Un formulario con 26 participantes | 25 en la página y paginación (mismo paginador que el dashboard); los números de arriba son **de los 26**, no de lo filtrado | M | |
| V20 | En móvil (360 px), la tarjeta «Lista» | Los números no se desbordan y el botón de *Quitar* se ve sin scrolls interminables | M | |
| V21 | Con el JS desactivado, buscar, añadir y borrar | Todo funciona con POST normal y avisos con flash | A | |
| V22 | Un formulario que **no** es de lista → *Participantes* | La pantalla trae la tarjeta de acceso de siempre (allowlist): ni panel, ni alta de correo por persona | A | |
| V23 | Buscar con el panel a media pantalla (sin recargar antes) | La página vuelve al panel, no al principio: si el resultado queda fuera de pantalla parece que la búsqueda no hizo nada | M | |
| V24 | Un formulario de lista con alguien que ya respondió: borra su respuesta desde *Respuestas* | Los números bajan (deja de contar como respondida) y esa persona **puede volver a entrar** con su código o enlace y responder otra vez; si tuviera dos respuestas, la marca solo se suelta al borrar la última | A | |
| V25 | Cerrar `#rosterEditModal` con `Esc` o tocando el fondo | El panel de la fila **vuelve a su sitio** (`#rowactions-<pid>`, oculto) y la ficha sigue como estaba | M | |
| V26 | Abrir la pantalla con `?q=V-01&sort=nombre&page=2` | La lista sale ya filtrada, ordenada y en esa página, y el paginador **conserva** `q` y `sort` al pasar de página | A | |
| V27 | Cargar 26+ y pasar de página | La lista nueva llega por GET; el buscador y el orden no se pierden | M | |
| V28 | Abrir *Participantes* en un formulario de lista | Se ven **tres bloques** separados a todo el ancho: la cabecera de acceso, el **resumen** (números + invitar) y la **lista**; no una única tarjeta con todo apilado | M | |
| V29 | En el resumen, mirar la cabecera de los modales de alta y edición | La franja superior es del color de la barra (`app-navbar`) con el icono y el título, igual que los diálogos de `/admin`; no la cabecera blanca por defecto de Bootstrap | M | |
| V30 | Buscar y añadir a alguien | El buscador es un **campo con botón *Buscar*** en la misma fila que *Añadir uno a la lista*; el campo suelto ya no está arriba separado | M | |
| V31 | Abrir los modales de alta y de edición con varios atributos | Los campos van en **una sola columna**, a todo el ancho del diálogo; ninguno queda estrecho ni desalineado | M | |
| V32 | En el modal de edición, mirar los botones | *Quitar* a la izquierda y *Guardar* a la derecha, alineados en una franja al pie; con el foco dentro de un campo, `Enter` guarda y *Guardar* se apaga mientras guarda | M | |
| V33 | Abrir la tarjeta «Importar» con muchos atributos en el catálogo | Subir un archivo queda a la **izquierda** y el catálogo a la **derecha** (en pantalla ancha); *Vaciar la lista* está **abajo, a todo el ancho**, fuera del colapsable | M | |
| V34 | Buscar en la lista de participantes | **No se recarga la página entera**: cambia solo la lista, la URL queda con `?q=…` y el campo de búsqueda conserva lo escrito | A | |
| V35 | Tras buscar, pulsar «atrás» del navegador | Vuelve a la lista sin búsqueda (el campo se re-sincroniza con la URL) | M | |
| V36 | Con el JS desactivado, buscar | El formulario hace un GET normal: la página se recarga con el resultado, que es el camino de siempre | A | |

## W. Elegir con qué se identifica a cada participante (fase 6, 2026-10-04)

El identificador es lo que decide **quién puede entrar**: la puerta busca por esa
columna, así que cambiarlo reescribe la identidad de toda la lista. Es el ajuste más
peligroso de la ficha y el que más parece un detalle, así que lo que se mira es que
**avise antes de romper** y que **sin JavaScript el camino exista**. Y, desde que la
puerta pública nombra el dato, que **lo que pide la pantalla de acceso es lo que
guarda el desplegable** y que la puerta solo admite el código y ese dato: un
identificador que el formulario no pide no abre nada, aunque la persona esté en la
lista.

Para llegar: ficha del formulario → *Ajustes* → *Quién puede responder*.

| # | Qué probar | Esperado | P. | Estado |
|---|---|---|---|---|
| W1 | Abrir el desplegable «Quién puede responder» | Cuatro opciones y «Solo un listado de participantes» es la **última** | A | |
| W2 | Poner `roster` **sin guardar** | Aparece el desplegable «Con qué se identifica», con el valor que ya había guardado | M | |
| W3 | Volver a «Cualquiera con el enlace» sin guardar | El desplegable desaparece y el identificador guardado **no** se pierde | M | |
| W4 | Un formulario en `roster` con identificador «Correo electrónico», abrir la ficha | El desplegable viene a la vista y con «Correo electrónico» elegido, no con la primera opción | A | |
| W5 | **Con el JS desactivado**: poner `roster` y guardar | Al reabrir la ficha el desplegable del identificador ya está a la vista (un guardado de más, sin daha) | A | |
| W6 | Guardar **otro** ajuste que no sea de acceso (el título, por ejemplo) en un formulario en `Público` que tenga lista configurada para correo | El identificador guardado sigue siendo «Correo electrónico»: el desplegable apagado no debe mandar «cédula» | A | |
| W7 | Cambiar a un identificador que **alguien de la lista no tiene** | No se guarda nada: el mensaje dice **qué campo falta** y el identificador y la lista siguen como estaban | A | |
| W8 | Importar una lista con **dos correos iguales** y cambiar a «Correo electrónico» | No se guarda nada y el mensaje **nombra los valores repetidos**, no solo dice que hay repetidos | A | |
| W9 | Una lista con nueve correos repetidos y cambiar a «Correo electrónico» | El mensaje sale **topado** (no nueve nombres) y dice el total al lado | M | |
| W10 | Una lista en la que todo el mundo tiene correo, cambiar a «Correo electrónico» y guardar | Se guarda y la lista se reescribe; los enlaces con `?c=` que ya se habían repartido **siguen sirviendo** | A | |
| W11 | Con la lista reescrita, entrar por un enlace con el código viejo | Entra: el código no cambia, solo lo que se teclea | A | |
| W12 | Poner `public` en un formulario que tenía lista | El formulario se abre y **la lista sigue ahí**, con su catálogo | A | |
| W13 | El formulario en `roster`, con los botones de la ficha (*Publicar*, *Compartir*) | Los botones son los de siempre: el identificador no toca el estado | M | |
| W14 | Abrir la puerta de un formulario en lista con identificador «Cédula», sin `?t=` | El rótulo es «Tu cédula», el placeholder pide la cédula y la nota habla de la cédula y del código de invitación: nada de un texto genérico de «código o identificador» | A | |
| W15 | Cambiar el identificador a «Código» y volver a abrir la puerta sin `?t=` | El campo pide el código personal de 16 caracteres, con su placeholder | A | |
| W16 | Con identificador «Correo electrónico», escribir en la puerta la **cédula** de alguien de la lista | No entra, y el aviso habla del **correo** (el dato que este formulario pide), no de «ese código» | A | |
| W17 | Con identificador «Cédula», escribir la cédula de un participante | Entra y la URL queda con `?t=` del **código** de esa persona | A | |
| W18 | Pulsar el «?» de ayuda del desplegable «Con qué se identifica» | El texto habla de lo que se teclea en la pantalla de acceso y del código de la invitación; ya no dice que «el correo solo sirve para mandarles el enlace» | M | |
| W19 | Mirar el aviso de debajo del desplegable en modo lista | Dice que solo entran los de la lista «con su código o con el identificador elegido», no solo «con su código» | B | |


## X. Filtrar la analítica por un atributo del listado (fase 7, 2026-10-04)

El filtro es lo único de la analítica que **no** se ve en la barra de fechas: son dos
desplegables más que solo existen si el formulario tiene catálogo. Lo que se mira es
que los tres números de la pantalla (el indicador, las barras y las filas) sean del
**mismo grupo**, y que el filtro no se pierda al cambiar de página ni al exportar.

Para llegar: panel → un formulario en «Solo el listado de participantes» → *Analítica*.

| # | Qué probar | Esperado | P. | Estado |
|---|---|---|---|---|
| X1 | Abrir la analítica de un formulario **con** lista importada | Aparecen dos desplegables junto a las fechas: el del atributo («Todo el listado») y ningún segundo desplegable todavía | A | |
| X2 | Abrir la analítica de un formulario **normal**, sin lista | No hay ningún desplegable de atributo: no hay nada que filtrar | A | |
| X3 | Elegir un atributo y pulsar «Filtrar» sin elegir valor | Aparece el segundo desplegable con «Todos los valores» y el atributo **sigue marcado**; la pantalla todavía muestra todo | A | |
| X4 | Elegir un valor con respuestas y pulsar «Filtrar» | El indicador, las barras y la tabla de abajo salen **todas** del grupo elegido, y la tabla gana una columna con el nombre del atributo | A | |
| X5 | La tabla con el filtro puesto | Una columna por atributo del catálogo; **no** salen columnas con la cédula, el nombre ni el correo del participante | A | |
| X6 | Alguien de la lista sin el atributo, dentro del grupo filtrado | Su celda sale con «—», no un hueco en blanco | M | |
| X7 | Cambiar de página con el filtro puesto | Las filas que entran son del mismo grupo y la columna del atributo sigue ahí | A | |
| X8 | Un formulario que además tiene respuestas de gente **sin** estar en la lista | Con filtro no salen (no son de ningún grupo); **sin** filtro sí salen | A | |
| X9 | Editar la URL a mano con `atr_key=cedula` y una cédula real | No filtra nada: sale la pantalla entera, y no se rompen los gráficos | A | |
| X10 | Editar la URL con un valor que no existe (`atr_value=Oeste`) | Pantalla vacía y el valor **sigue marcado** en su desplegable: es lo que se está mirando | A | |
| X11 | Editar la URL con `atr_key` inventada | Pantalla completa, sin errores, y el desplegable vuelve a «Todo el listado» | A | |
| X12 | Con el filtro puesto, pulsar «Exportar CSV» | El archivo trae **solo** las respuestas del grupo, y su número de filas coincide con el indicador | A | |
| X13 | Un formulario de listado con **muchos** valores en un atributo libre | El desplegable no se hace interminable (está topado) y sigue siendo usable | M | |
| X14 | Poner un filtro de fechas **y** un de atributo | Se acumulan: el CSV y la tabla salen con los dos a la vez | A | |


## Y. Comparar la analítica por un atributo del listado (fase 8, 2026-10-04)

La tarjeta que responde «¿se parece la gente de cada grupo?». Lo que se mira es que
**cada celda sea la de su columna** y que el techo no mienta: con un `.xlsx` de una
sola hoja el atributo puede tener 800 valores, y ahí lo interesante no es el número
sino que la pantalla siga siendo usable y que el «resto» sea de verdad el resto.

Para llegar: panel → un formulario en «Solo el listado de participantes» → *Analítica*
→ elegir un atributo y pulsar «Filtrar» (**sin** elegir valor).

| # | Qué probar | Esperado | P. | Estado |
|---|---|---|---|---|
| Y1 | Elegir atributo y pulsar «Filtrar» sin elegir valor | Sale una tarjeta «Comparar por …» **encima** del detalle, con una columna por valor y su «n = personas» | A | |
| Y2 | La tabla de comparación con un campo numérico | Cada columna tiene **su** promedio (el de su grupo), no el del formulario entero | A | |
| Y3 | Un campo NPS | Cada columna sale con su NPS y su «n = …» | A | |
| Y4 | Un desplegable de opciones | Cada columna dice el valor más repetido y **su proporción de todas las respuestas del grupo** | A | |
| Y5 | Con un valor filtrado puesto | La tarjeta **desaparece**: comparar los grupos de un atributo mientras se mira uno de ellos no dice nada | A | |
| Y6 | Un grupo con dos respuestas y otro con doscientas | Los promedios son los de cada uno (no una media de medias), y se pueden comparar | A | |
| Y7 | Alguien de la lista que no ha respondido | Su columna sale con «—», **no** con un 0,00 | A | |
| Y8 | Un atributo libre con **más de ocho** valores distintos | Sale la última columna como «Resto», con su «n»; el número de columnas no crece con el `.xlsx` | A | |
| Y9 | Ese mismo atributo, con **ocho** valores justos | **No** hay columna «Resto»: no queda nadie y una columna en cero diría que hay gente sin el atributo | A | |
| Y10 | Una tabla con nueve columnas y una ventana estrecha | El nombre de la pregunta **se queda pegado al borde** al desplazar, y no se ve texto de otras celdas por detrás | M | |
| Y11 | Una pregunta de opción múltiple, de matriz o de ranking | **No** salen en la comparación (siguen en su tarjeta de arriba); su valor guardado es un JSON con la respuesta entera | A | |
| Y12 | Una pregunta marcada como dato personal, entrando **sin** el permiso | No sale en la comparación | A | |
| Y13 | La misma pregunta entrando **con** el permiso | Sale, con sus números | A | |
| Y14 | Con un filtro de fechas puesto y la comparación abierta | Los promedios son solo de las respuestas del rango, no de todas | A | |
| Y15 | El botón «Descargar CSV» de la tarjeta de **detalle**, con filtro de atributo y fechas | El archivo trae **solo** ese grupo y ese rango (los dos botones llevan lo mismo) | A | |
| Y16 | Un formulario sin lista importada | No hay tarjeta de comparación ni ningún desplegable de atributo | A | |
| Y17 | Una lista importada de 800 filas con la columna «Sede» vacía para la mayoría | Sale «Resto» con casi toda la gente, y la pantalla no se arrastra | M | |

## Z. Quién respondió (fase 9, 2026-10-04)

La atribución se guardaba desde la fase 4 y no se veía en ninguna parte: el listado
de respuestas tenía #, fecha, IP y acciones, y el detalle tenía fecha, IP y
navegador. Lo que se mira aquí es que la columna **solo** exista con el permiso, que
diga lo mismo que la ficha, y que su ausencia **se explique** (una tabla con lista
que no dice quién respondió parece un fallo, y no lo es).

Para llegar: panel → un formulario en «Solo el listado de participantes» → *Respuestas*.

| # | Qué probar | Esperado | P. | Estado |
|---|---|---|---|---|
| Z1 | El listado entrando **como admin** | Sale una columna «Participante» entre la fecha y la IP, con el identificador, el nombre y el correo de quien respondió | A | |
| Z2 | El mismo listado entrando **como user** (o cualquier rol sin `responses.pii`) | **No** hay columna, y no hay ni una cédula ni un nombre en el HTML | A | |
| Z3 | Ese mismo listado sin permiso | Abajo sale un aviso que explica que el rol no incluye `responses.pii` y que en la ficha sí se marca quién respondió | A | |
| Z4 | Un formulario **normal** (sin lista), entrando como admin | No hay columna de participante: nadie está atribuido y sería de «—» en todas las filas | A | |
| Z5 | Una respuesta de alguien que **no está en la lista** (entró por un formulario público antes de tener lista) | La columna sale y esa celda dice «—»; las demás dicen su nombre | A | |
| Z6 | Una página de respuestas donde **ninguna** está atribuida | La columna **sigue** ahí: se decide por el formulario, no por si esa página casó con alguien | A | |
| Z7 | El detalle de una respuesta, como admin | Sale una casilla «Participante» con el identificador, el nombre y el correo | A | |
| Z8 | El detalle de una respuesta sin atribución | No sale la casilla (ni vacía, ni con «—»): el aviso de «enmascarados» ya explica que no hay permiso | B | |
| Z9 | Con el identificador del formulario puesto en «correo» | El correo sale **una sola vez** (es el identificador), no repetido debajo | M | |
| Z10 | Una lista de mil personas, pagina a pagina hasta una página sin atribuir | La columna está en todas las páginas; no aparece y desaparece al paginar | A | |
| Z11 | Descargar el CSV y el JSON de un formulario con lista | **No** llevan ni el nombre ni la cédula ni el identificador del participante (un archivo lo descarga cualquiera con el botón) | A | |
| Z12 | El detalle de una respuesta de un campo marcado como dato personal, sin permiso | Sigue apareciendo el aviso de datos enmascarados, y ahora además **no** se dice quién respondió | A | |


## AA. El correo de invitación (fase 10, 2026-10-05)

El botón escribe a la gente de la lista. Lo que se mira aquí es lo que la suite no
ve: que el correo **se entienda**, que el botón **no prometa más de lo que hace**, y
que lo que queda pendiente después de un envío a medias **se vea** en la misma pantalla.
La lista tiene que estar cargada y el identificador del formulario en «correo» para
que el botón exista.

Para llegar: panel → el formulario → *Participantes*.

| # | Qué probar | Esperado | P. | Estado |
|---|---|---|---|---|
| AA1 | El botón con la lista vacía | Sale «No hay invitaciones por correo pendientes»; sin botón, sin error en el log | A | |
| AA2 | Con 37 pendientes | El botón dice «Enviar invitaciones (37)»: el número es el de pendientes, no el de la lista | M | |
| AA3 | Pulsa el botón con SMTP configurado | Desaparece el aviso de confirmación, aparece un aviso con cuántos correos salieron y **el número de pendientes baja** | A | |
| AA4 | Con más de 50 pendientes | Se mandan 50 y el botón vuelve a decir cuántos quedan (37); no dice «50 enviados» | A | |
| AA5 | Con SMTP apagado (`MAIL_HOST` vacío) y pendientes | Sale el aviso de que no hay correo configurado, **no** un error de servidor, y el número de pendientes **no** baja | A | |
| AA6 | Con identificador «cédula» o «indicador» | **No hay botón**: en su lugar se lee que el enlace se reparte desde la columna «Código» | A | |
| AA7 | Pulsa el botón con pendientes pero **identificador cambiado a cédula** justo antes | Un 403 y no se manda nada; no se ve un correo a medias | A | |
| AA8 | Pulsa el botón en un formulario que **no es de lista** | Un 404, sin correo | A | |
| AA9 | El correo que llega | Dice que hay un formulario que responder, trae el botón y **no menciona la lista ni cuántas personas son** | A | |
| AA10 | El correo a alguien **sin nombre** | No saluda a nadie: el saludo solo aparece si hay nombre | M | |
| AA11 | Abre el enlace del correo con un navegador sin sesión | Entra al formulario en el paso 1 y sin pedir identificación: el `?t=` es la credencial | A | |
| AA12 | Pulsa el botón dos veces seguidas | La segunda vez dice «No hay invitaciones por correo pendientes»: nadie recibe el correo dos veces | A | |
| AA13 | Deja caer el SMTP a mitad de una tanda (con 10 pendientes) | Sale un aviso con cuántos salieron y **cuántos quedan**, y el botón sigue ahí para reintentar | A | |
| AA14 | Con una dirección **inválida** entre las pendientes (por ejemplo `sin-arroba`) | Esa fila no se marca y el resto sí sale: un fallo suelto no corta la tanda | A | |
| AA15 | Tras AA14, mira la insignia de números | Dice cuántos quedan, y son exactamente los no marcados | M | |
| AA16 | Importa un `.xlsx` de 400 filas con identificador «correo» | **No se manda ningún correo** y el número de pendientes sube a 400 | A | |
| AA17 | Marca a alguien como «respondió» en la lista y vuelve a mirar el botón | Ya no está entre los pendientes: a quien ya respondió no se le escribe desde el botón | A | |
| AA18 | Borra a una persona de la lista y mira el botón | El número de pendientes baja en uno | M | |
| AA19 | Edita el correo de una persona y mira el botón | Si la persona ya había recibido el correo, **vuelve a contar como pendiente**: el correo nuevo es el que hay que mandar | A | |
| AA20 | Pulsa el botón **sin JavaScript** (desactivado en el navegador) | Pide confirmación, envía y recarga; el resultado sale y el número de pendientes es correcto | A | |
| AA21 | Pulsa el botón y el servidor devuelve error (por ejemplo con la BD parada a mitad) | Sale el aviso del fallo y los pendientes **no** bajan: nadie se queda marcado sin haber recibido | A | |
| AA22 | Con teclado: tabula hasta el botón y pulsa `Enter` | Abre la confirmación y, en «Cancelar», vuelve el foco al botón | M | |
## AB. El listado del panel, paginado y filtrado (2026-10-05)

La búsqueda, los filtros por estado y el orden del panel pasaron de ser de
JavaScript sobre el listado entero a viajar en la URL (`?q=…&estado=…&orden=…`),
igual que hace el listado de participantes de su pantalla (`/forms/{id}/access`).
Lo que se mira aquí es lo
que la suite no ve: que se **sienta** como antes (o que el cambio se note y se
pueda escribir en el manual), que sin JavaScript haya un camino, y que el rótulo
de página no mienta.

Hace falta un panel con más de una pantalla de formularios (doce o más), y para
las comprobaciones de búsqueda, uno con títulos reconocibles.

| # | Qué probar | Esperado | P. | Estado |
|---|---|---|---|---|
| AB1 | Con 30 formularios | Sale una pantalla de tarjetas y debajo «30 formularios · Página 1 de 3» | A | |
| AB2 | El botón de la derecha del paginador | Va a la página 2 y las tarjetas **cambian**: no es un scroll | A | |
| AB3 | Escribe en el buscador y para | A los ~medio segundo la pantalla se recarga con solo lo que casa; el texto queda en el buscador | M | |
| AB4 | Escribe otra cosa después | Busca lo nuevo, no acumula: el filtro viaja en la URL, no en memoria | M | |
| AB5 | Escribe lo mismo que ya estaba (un espacio de más) | **No** recarga: la URL ya era esa | M | |
| AB6 | Pulsa «Borradores» | La URL lleva `estado=draft`, el botón queda marcado y solo salen borradores | A | |
| AB7 | Con un filtro puesto, escribe en el buscador | El filtro **se conserva**: no se pierde al buscar | A | |
| AB8 | Con filtro y búsqueda, cambia el orden | Los tres viajan juntos en la URL | A | |
| AB9 | Cambia el orden a «Alfabético» | Ordena por título, y con acentos y mayúsculas de forma estable (que dos títulos iguales no se salten uno) | M | |
| AB10 | Ordena por «Más respuestas» | La tarjeta que más tiene sale primero, y su número coincide con el de la tarjeta | M | |
| AB11 | Copia la URL del filtro a otra pestaña | Sale exactamente la misma lista | A | |
| AB12 | Pulsa el **atrás** del navegador tras filtrar | Vuelve a la lista sin filtro, en orden de verdad | M | |
| AB13 | Escribe una URL a mano con `?estado=inventado` | No se rompe ni filtra a la nada: sale el listado normal | A | |
| AB14 | Escribe una URL a mano con `?page=999` | Aterriza en la última página, con su rótulo, y no en un panel vacío | A | |
| AB15 | Filtra por un estado donde no tienes nada | Sale «Sin resultados» con un enlace para quitar el filtro, **no** «Aún no tienes formularios» | A | |
| AB16 | Borra todos tus formularios y entra al panel | «Aún no tienes formularios» con su botón de crear, y **sin** la barra de filtros | A | |
| AB17 | Con filtro puesto, mira las cuatro tarjetas de arriba | Siguen diciendo el total del panel, no el de lo filtrado | A | |
| AB18 | Todo esto **sin JavaScript** (desactivado) | El `Enter` del buscador busca, el botón «Aplicar» ordena, y los enlaces de estado y de página funcionan | A | |
| AB19 | Con teclado: `/` con el foco fuera de un campo | El foco va al buscador | M | |
| AB20 | `/admin` → «Formularios» con más de veinte | Sale una pantalla de tarjetas y el paginador; «Siguiente» cambia el listado | A | |
| AB21 | `/admin/forms?page=999` | Aterriza en la última, sin panel vacío | A | |
| AB22 | `/admin/forms` sin JavaScript | El paginador es un enlace y funciona | A | |
| AB23 | Con veinte formularios en la base, mira `/dashboard` con el profiler (o `EXPLAIN`) | La consulta del listado trae `LIMIT` y el conteo de respuestas va sobre los formularios de la página, no sobre todos | A | |
| AB24 | La fila de filtros, con el buscador y el desplegable de orden juntos | El desplegable mide el mismo alto que el buscador y los botones de estado: antes la regla global `.form-select` le pisaba el `-sm` y salía ~11px más alto, descuadrando la fila | M | |

## AC. El modal de nuevo formulario como galería (2026-10-11)

La elección de arranque dejó de ser dos radios («En blanco» / «Desde una
plantilla») y una galería oculta, y pasó a ser una **única rejilla de tarjetas**
como la portada de Google Forms: primero «Formulario en blanco» y luego una por
plantilla, cada una con portada de color, nombre y número de preguntas. **No hay
campos de título ni descripción**: el formulario nace con lo que dice la tarjeta
elegida y se renombra en el constructor. Se mira lo que la suite no ve: que la
rejilla se sienta como una galería, que la portada se pinte como tal (un `<span>`
con altura necesita `display`) y que el cuerpo del diálogo **scrollee** cuando la
ventana es baja —el `<form>` tiene que ser el `.modal-content`, no envolverlo—.

Hace falta una instancia con plantillas en `database/templates` y una cuenta con
`forms.create`.

| # | Qué probar | Esperado | P. | Estado |
|---|---|---|---|---|
| AC1 | Abrir «Nuevo formulario» | Diálogo **centrado** y ancho (modal-lg), con cabecera de color como la del modal de «Agregar elemento» | M | |
| AC2 | Mirar la rejilla | La primera tarjeta es «Formulario en blanco» y a continuación una por plantilla, con su portada de color, nombre y «N preguntas» (sin chips de tipos) | M | |
| AC3 | Al abrir | «Formulario en blanco» viene marcada (borde y realce) | A | |
| AC4 | Buscar campos | **No hay** campos de Título ni Descripción: solo la rejilla de tarjetas y el pie | A | |
| AC5 | Pulsar una tarjeta de plantilla | Se marca esa (y se desmarca la de blanco) | M | |
| AC6 | Volver a pulsar «Formulario en blanco» | Se desmarca la plantilla y vuelve a quedar marcada la de blanco | M | |
| AC7 | Pulsar «Crear» sin tocar nada | Se crea y abre el builder vacío; se llama «Formulario en blanco» y lleva la **descripción de esa tarjeta** | A | |
| AC8 | Con una plantilla, crear | Se crea con el **nombre y la descripción de la plantilla** y sus preguntas | A | |
| AC9 | Con la ventana baja, abrir el modal | El cuerpo del diálogo **scrollea** hasta el botón «Crear»; nada queda cortado (el `<form>` es el `.modal-content`) | M | |
| AC10 | Con teclado: `Tab` por las tarjetas y `Espacio` | El foco recorre las tarjetas con anillo visible y `Espacio` selecciona | M | |
| AC11 | Todo esto **sin JavaScript** (marcar una plantilla con el ratón) | Da igual: los radios funcionan y el servidor deriva el nombre de la tarjeta | A | |
| AC12 | En móvil estrecho (360px) | La rejilla baja a una columna y el diálogo no se sale de la pantalla | M | |

## AD. La consola de administración: ajustes, modales y sin recarga (2026-10-14)

Requiere dos cuentas: un `admin` y una persona con `admin.access` pero **sin**
`admin.users` (o, más simple, un `admin` y un rol de prueba para el gate). Para
C4 hace falta al menos un usuario y un grupo.

| # | Qué probar | Esperado | P. | Estado |
|---|---|---|---|---|
| AD1 | Abrir `/admin/security-log` | La barra de filtros es la misma que la de `/admin/forms`: buscador + desplegable de evento; el desplegable no ocupa toda la fila | M | |
| AD2 | Abrir `/admin` | La sub-navegación tiene **seis** pestañas, la última «Ajustes de la instancia»; con `admin.access` se entra | M | |
| AD3 | Abrir `/admin/settings` | Dos tarjetas: registro público (interruptor) y política de revisión (formulario). Ninguna está en la ficha de usuarios | A | |
| AD4 | Como alguien **sin** `admin.access` | `/admin/settings` responde 403 y la pestaña no aparece | M | |
| AD5 | Guardar la revisión en `/admin/settings` | PRG que vuelve a `/admin/settings` (no a `/admin`), con el aviso | A | |
| AD6 | Abrir `/admin/groups` | Cada tarjeta es un grupo **plegable** (abre en su estado por defecto); el resumen lleva nombre, insignias y borrado; «lo que otorga» está en el cuerpo | M | |
| AD7 | Pulsar «Nuevo grupo» | Abre `#newGroupModal` con el formulario de alta; guardar recarga y la tarjeta nueva aparece | A | |
| AD8 | Guardar un grupo sin recarga | Se repinta la cabecera-resumen **y** el efecto del grupo, sin recargar | M | |
| AD9 | Abrir `/admin/users` y pulsar «Nuevo usuario» | Abre `#newUserModal` con el alta **en una sola columna**; sin JS el formulario sigue en la página | A | |
| AD10 | Pulsar el `⋯` de una fila | Abre `#userActionsModal` **con el nombre de esa persona** en el título y el panel de su fila dentro, en **tres columnas** (permisos efectivos / rol y grupos / permisos extra) | M | |
| AD11 | Cambiar el rol desde ese modal | Se guarda sin recarga y la insignia de la fila (detrás del modal) queda al día | M | |
| AD12 | Cerrar el modal y abrir el de otra fila | Cada uno trae su panel; no se ve el de la fila anterior | M | |
| AD13 | Marcar grupos en el modal | No se cierra al marcar la primera casilla; el aviso de error, si lo hay, sale dentro del modal | M | |
| AD14 | Todo lo anterior **sin JavaScript** | El alta y las acciones se envían por POST nativo y recargan; nada queda inalcanzable | A | |
| AD15 | En móvil (390px) | La tabla de usuarios se apila en tarjetas, el `⋯` sigue alcanzable y los modales no se salen de la pantalla | M | |
| AD16 | Pulsar el bloque de usuario al pie de la sidebar | Ocupa todo el ancho y se despliega **hacia arriba** (colapsable, no un desplegable flotante), con el correo, «Seguridad de mi cuenta» y «Cerrar sesión»; la flecha rota al abrir | M | |

## AE. El recordatorio por correo (fase 12, 2026-10-15)

El botón de recordatorio vive al lado del de invitaciones, en la misma pantalla, y
es lo que la suite no puede ver: que el correo **se entienda**, que el número que
promete **no mienta** después de un envío a medias y que la tanda **no insista**
sobre los mismos. La lista tiene que tener gente ya **invitada** y sin responder,
con identificador «correo» y SMTP configurado.

Para llegar: panel → el formulario → *Participantes*.

| # | Qué probar | Esperado | P. | Estado |
|---|---|---|---|---|
| AE1 | Lista recién importada, nadie invitado | **No** hay botón de recordatorio: lo que se dice es que no queda nadie pendiente de invitación | A | |
| AE2 | Con 30 invitados y sin respuestas | Aparece «Enviar recordatorios (30)» **debajo** de «Enviar invitaciones», y el número es el de invitados sin responder, no el de la lista | A | |
| AE3 | Pulsa el botón con SMTP configurado | Sale el aviso con cuántos salieron, y el número del botón **no** baja a cero: el recordatorio es repetible, no es «quedan sin recibir» | A | |
| AE4 | Comprueba que los dos números son distintos | Mientras queden personas **sin invitar**, los dos botones cuentan cosas distintas (las que no recibieron nada / las que recibieron y no contestaron) | M | |
| AE5 | Abre el correo que acaba de llegar | Trae el enlace y, si el formulario tiene fecha de cierre, la cita con día y hora. **Si no tiene fecha, no hay ninguna línea de fechas** | A | |
| AE6 | Con identificador «cédula» o «indicador» | No aparece el botón: se dice que el enlace se reparte desde la columna «Código» | A | |
| AE7 | Con `MAIL_HOST` vacío | Se dice que el correo está apagado, **no** un error de servidor, y el número de pendientes no se toca | A | |
| AE8 | Pulsando con el formulario **cambiado a cédula** justo antes | 403 y no se manda nada | A | |
| AE9 | Pulsando en un formulario que **no es de lista** | 404, sin correo | A | |
| AE10 | Pulsa el botón **otra vez** al día siguiente | Sí se vuelve a mandar: nadie queda fuera por «ya le tocó» | A | |
| AE11 | Deja caer el SMTP a mitad de una tanda (con 20 pendientes) | El aviso dice cuántos salieron y **cuántos fallaron**, y el número del botón sigue contándolos a todos | A | |
| AE12 | Tras AE11, comprueba quién quedó marcado | Los que salieron sí; los que fallaron **no** recibieron nada y siguen en la cola | A | |
| AE13 | Marca a alguien como «respondió» en la lista | El número de recordatorios baja: a quien ya respondió no se le insiste | A | |
| AE14 | Con más de 50 pendientes | Se mandan 50 y el aviso dice **a cuántos no se llegó** («Quedan 10»), no «50 enviados» | A | |
| AE15 | Dos tandas seguidas con más de 50 pendientes | La segunda empieza por los que no recibieron ninguno y sigue por los más antiguos: no se martilla a los mismos | M | |
| AE16 | El confirmador del botón | Dice cuántos son, que la tanda manda 50, quién puede recibirlo y el botón que se acepta es «Sí, recordar» | M | |
| AE17 | Tras un envío, mira la lista y el catálogo de atributos | **No** parpadean: solo cambian los números y las invitaciones, que es lo que este botón toca | B | |
| AE18 | Pulsa el botón **sin JavaScript** | Pide confirmación, envía por POST y recarga; el resultado y los números son los mismos | A | |
| AE19 | Con teclado: tabula hasta el botón y pulsa `Enter` | Abre la confirmación y, en «Cancelar», devuelve el foco al botón | M | |
| AE20 | Borra a una persona de la lista | Su correo sale de la cola: dejar de estar en la lista cierra la puerta, aunque tenga enlace | A | |

## Cuando encuentres un fallo


Anota para que el diagnóstico sea rápido:

1. **ID** de la prueba (por ejemplo `R1`) y **qué esperabas** frente a **qué pasó**.
2. **URL** exacta y **qué hiciste** justo antes.
3. **Cuenta** con la que lo viste (`admin`, `user`, `editor`) y si el formulario
   tenía revisión, PII o acceso restringido.
4. **Navegador y versión**, y si es una prueba de móvil.
5. Si es un error del servidor: **el mensaje del log** (el error visible de la
   página es intencionadamente genérico).
6. Si te saltó la suite automática: `php scripts/test.php --filter <nombre del
   método>` para reproducir solo ese caso.

Tres fallos que **no** son fallos, por si aparecen en la lista:

- Un 403 en un formulario que sigue en borrador.
- `[oculto]` en un campo de datos personales sin permiso `responses.pii`.
- Un número escrito a mano en `minlength` de un formulario que no sea el de
  registro (solo ese debe tomarlo de la constante del servidor).
