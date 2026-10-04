# Tabla de pruebas funcionales

Lista de comprobaciones para **detectar problemas** en una instalación, antes
de un despliegue y después de cualquier cambio. Escrita contra el código
(verificada contra el código, sin commit aún).

No sustituye a la suite automática (`php scripts/test.php`, 765 casos): esa
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

**312 pruebas** en 21 bloques: A–Q, las regresiones (R) de fallos que ya
existieron y se corrigieron, la tanda visual (S) de la ronda del constructor y
el fill, las acciones sin recarga (T), y el diálogo de confirmación con el alta
de la lista de acceso (U).

**Estado inicial:** todos en blanco. Una prueba pasa si está `OK` o `N/A`.
Cualquier `FALLA` es un hallazgo; anota el `#` en el issue.

**Cuándo**

- Antes de un despliegue: A, B, N y O enteras.
- Después de tocar el constructor: D, G y R enteras.
- Después de tocar el fill o el panel: E, F, K, R, T y U enteras.
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

---

## D. Constructor

| # | Qué probar | Esperado | P. | Estado |
|---|---|---|---|---|
| D1 | Abrir el constructor de un formulario vacío | Lienzo vacío, panel «Agrega elementos» y «Configuración» | A | |
| D2 | Arrastrar un campo al lienzo | Aparece una tarjeta con su etiqueta | A | |
| D3 | Pulsar el engranaje de una tarjeta | Se abre su configuración; el engranaje de otra tarjeta lleva la suya allí (no se duplica el panel) | A | |
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
| D23 | Abrir **Configuración** del formulario y cambiar el color principal | La maqueta de abajo cambia al instante, sin cerrar la pantalla | A | |
| D24 | Escribir una tipografía con comillas (`'Georgia', serif`) | Aviso en rojo dizendo que no se guarda, **antes** de guardar | A | |
| D25 | Escribir una tipografía válida (`Georgia, serif`) | Aviso en verde, y se aplica | A | |
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
| E5 | Con revisión activada, editar un formulario ya aprobado | Vuelve a la cola y **se borra la aprobación anterior** | A | |
| E6 | Aprobar un formulario propio | Se permite, y el registro de eventos lo marca como propio | M | |
| E7 | Intentar aprobar sin permiso `forms.review` | 403. Tener `forms.edit_own` **no** sirve para aprobar | A | |
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
| I10 | Ficha en modo `allowlist`, escribir un correo y pulsar *Añadir* | La fila aparece **sin recargar**, la caja queda vacía y con el foco dentro, y el aviso de lista vacía desaparece | M | |
| I11 | En la misma caja, escribir un correo ya presente y pulsar *Añadir* | No aparece una segunda fila; el motivo sale debajo de la caja | M | |
| I12 | Escribir un correo sin formato y pulsar *Añadir* | No se añade nada y el motivo dice que el correo no es válido (no un error de red) | M | |
| I13 | Añadir con el teclado (Enter en la caja) | Funciona igual que con el botón, sin recargar | M | |
| I14 | Abrir la ficha con JavaScript deshabilitado y añadir un correo | El alta funciona con el POST normal y avisa con un flash | M | |
| I15 | `/admin` con un `user` normal | No aparece la tarjeta *Acceso de cualquiera* | A | |
| I16 | `/admin` con un admin: mover el interruptor del registro | El estado cambia al instante, sin recargar, y el texto de al lado se actualiza | M | |
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
| N16 | Con ese mismo `approver`, abrir la ficha de un formulario enviado a revisión | Puede aprobarlo o devolverlo, pero no editarlo ni borrarlo | A | |
| N17 | En la tarjeta *Baja de roles* | Los cuatro del sistema salen marcados como «Sistema» y **sin** botón de borrar; los tuyos, con el número de personas que los llevan | A | |
| N18 | Intentar borrar un rol propio que alguien lleva | No se borra y el motivo dice cuántas personas lo tienen | A | |
| N19 | Borrar un rol propio que no lleva nadie | Se borra, avisa, y también desaparece de los grupos que lo confieran (con aviso previo al confirmar) | M | |
| N20 | El mismo borrado con la confirmación rechazada | No pasa nada: el rol sigue ahí | M | |
| N21 | Abrir `/admin` | El alta y «Acceso de cualquiera» están **en la misma fila** (2/3 y 1/3), y la tabla de usuarios debajo a todo el ancho | M | |
| N22 | Entrar con un rol sin `admin.access` | Solo se pinta el alta, y ocupa las mismas 8 columnas: no queda un hueco a la derecha | M | |
| N23 | Plegar «Nuevo usuario», recargar y volver a entrar en `/admin` | Sigue plegado **en esa pantalla**, y al abrir la ficha de un formulario sus siete grupos salen **abiertos**: el estado va por pantalla | A | |
| N24 | Crear un usuario con el registro público **cerrado** | El alta se despliega y se puede enviar igual. Con el **JavaScript desactivado** el formulario también se ve y funciona: es la única vía para crear un `user` o un `editor` | A | |
| N25 | Plegar «Nuevo usuario» y crear una cuenta con un email repetido | El aviso sale arriba y **el alta se abre sola**, para poder corregir sin desplegar a ciegas | A | |

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
| P6 | Escribir una tipografía inválida | `#stFontNote` aparece **visible**, sin pasar el ratón: es estado, no explicación | A | |
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

Estas seis comprueban fallos que existieron de verdad y que ya no están.
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
| S10 | Ficha del formulario → «Ajustes del formulario» | Siete grupos plegables (Acceso, Programación y cupo, Notificaciones, Webhook, Mensaje final, Revisión e Idioma), con el chevron apuntando al grupo abierto | M | |
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
| S30 | `/admin` en pantalla ancha | El alta ocupa 2/3 y «Acceso de cualquiera» 1/3, con el chevron de «Nuevo usuario» a la derecha de su título | M | |
| S31 | `/admin` a 375 px | Las dos tarjetas se apilan y **ninguna** desborda; los cinco campos del alta se reparten en 3+2 sin cortes | M | |
| S32 | Ficha del formulario, en pantalla ancha | Columna ancha: Detalles, Campos y Acceso, en ese orden. Columna estrecha: Enlace para compartir y Ajustes del formulario | M | |
| S33 | Ficha con una **larga lista de correos** | La lista se lee con holgura y no estira la columna de los ajustes, que se queda a su ancho | M | |
| S34 | Ficha a 375 px | Las tarjetas se apilan en el orden del punto S32 y **ninguna** desborda | M | |


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
| T4 | Ficha → «Ajustes del formulario» → cambiar el nombre y guardar | No recarga y el título nuevo se ve en el encabezado al instante; los siete grupos **no se repliegan** y el scroll no salta | M | |
| T5 | Un formulario publicado **con revisión activa** → guardar cualquier ajuste | No recarga, y el aviso dice que volvió a la cola: la insignia pasa a «En revisión» y la aprobación anterior desaparece | A | |
| T6 | Un formulario **aprobado** → cambiar algo en el constructor y guardar | La insignia pasa a «En revisión» y aparece la acción de aprobar (es la misma regla del builder, ahora también en la insignia) | A | |
| T7 | Enviar a revisión → aprobar con otra cuenta | No recarga. La insignia pasa a «Publicado», sale la traza **nombrando al aprobador** y el botón es el de cerrar | A | |
| T8 | Igual, pero **devuelto a borrador** | La insignia pasa a «Borrador», la traza registra que lo devolvió quién y vuelve la acción de publicar | A | |
| T9 | Con el JS desactivado, cualquier acción de la ficha | Funciona **igual**, con su 302 y su aviso verde; nada depende del fetch | A | |
| T10 | Con el JS desactivado, pulsar dos veces «Desactivar» en `/admin` | La segunda pulsación ya no procede (la página se fue), así que no hay doble cambio | A | |
| T11 | `/admin` → desactivar un usuario y observar la fila | No recarga. Cambian los dos: la insignia y el botón (que pasa a «Activar», con su icono y su color) | A | |
| T12 | `/admin` → **Guardar** los cambios de una fila recién desactivada | Se guardan sobre el estado nuevo: el `<select>` de rol y el desplegable de grupos **siguen abiertos y con lo que elegiste** | M | |
| T13 | Un formulario con acceso por lista → «Acceso restringido» → quitar un email | No recarga. Desaparece esa fila de la lista y sale un aviso; **no** aparece el aviso verde de «quitado» por otra parte | M | |
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
| U6 | Publicar / devolver a borrador / activar usuario | El botón de confirmar dice qué hace («Publicar», «Devolver a borrador», «Activar») y sale en azul, no en rojo | A | |
| U7 | Desactivar usuario / eliminar / borrar respuesta | El botón dice la acción concreta y sale en rojo | A | |
| U8 | Ficha → «Acceso restringido» → **añadir** un email a la lista | **No recarga.** La lista se reescribe abajo y sale el aviso, igual que al quitarlo | A | |
| U9 | Añadir **dos** emails seguidos | Cada alta suma su fila; al terminar, el campo queda **vacío y con el foco dentro**, sin tabular | M | |
| U10 | Añadir un email que **ya está** en la lista | El motivo sale **junto al campo** («Ese correo ya está en la lista»), no flotando arriba, y **no** sale dos veces | A | |
| U11 | Tras el error de U10, escribir un email válido y añadirlo | Se borra el aviso anterior, la fila aparece y el contador sube | A | |
| U12 | Acceso restringido en móvil (360 px) → añadir un email | El diálogo de confirmación cabe entero y los botones se ven los dos | M | |
| U13 | Con el **JS desactivado**, añadir un email a la lista | Recarga con su aviso verde, como siempre | A | |
| U14 | En `/dashboard`, borra una fila y añade un email a una lista en la misma pantalla | Ambas cosas: la fila se quita y la lista se reescribe, sin que una pise a la otra | M | |
| U15 | Con el JS desactivado, borra un formulario | El `data-confirm` no se lee y se borra sin preguntar. Es lo que hay: el diálogo es una mejora, no una condición para no perder datos | A | |

| U16 | Ficha → «Acceso restringido» → cambia el **modo** y guarda | **No recarga** y la tarjeta de debajo cambia: insignia «Solo la lista» con el alta y los correos, «Público» sin ellos | A | |
| U17 | `/admin` → cambia el **rol** de una fila | **No recarga.** El desplegable se queda en el valor nuevo, sale el aviso verde y la tabla no da un salto de posición | A | |
| U18 | `/admin` → despliega los **grupos** de esa misma fila y guarda | **No recarga.** Las insignias del botón se reescriben con el grupo elegido | A | |
| U19 | `/admin` → guarda el rol de una persona **justo después** de abrir sus grupos | Las dos cosas se quedan: el desplegable de grupos que estaba abierto no se cierra ni se pierde lo que se estaba editando | M | |
| U20 | Con el **JS desactivado**, cambia el rol y guarda un grupo de `/admin` | Recarga con su aviso verde y el cambio aplicado, como siempre | A | |

---

## V. La pestaña de participantes (fase 5, 2026-10-04)

Se llega poniendo el formulario en modo `roster` (la fase 6 añadió la `<option>`
al desplegable; el bloque W lo mira). Es la fase donde más cosas pueden quedar «a
medias sin decir nada» —un hueco repuesto con el id equivocado no falla, no hace
nada—, así que lo que se mira es que **cada guardado sin recarga repinte lo que ese
guardado cambió** y que **sin JavaScript se vea todo**.

Para llegar al panel: ficha del formulario → *Ajustes* → *Quién puede responder* →
«Solo un listado de participantes» → guardar.

| # | Qué probar | Esperado | P. | Estado |
|---|---|---|---|---|
| V1 | Formulario en modo `roster` | La tarjeta de Acceso trae la lista de participantes con dos pestañas («Lista», «Importar») | A | |
| V2 | Las dos pestañas | Solo se ve la elegida; el estado se recuerda al volver a la ficha | M | |
| V3 | **Con el JS desactivado**, abrir la ficha en `roster` | **Se ven las dos zonas**, una debajo de otra: el panel de importar no desaparece | A | |
| V4 | *Añadir uno a la lista* → escribir una cédula y *Añadir* | **No recarga.** Aparece la tarjeta de la persona, suben los números y la caja queda vacía con el foco dentro | M | |
| V5 | Escribir una cédula **ya presente** y añadir | No aparece una segunda tarjeta; el motivo sale **junto al campo**, no flotando, y no sale dos veces | A | |
| V6 | Alta repetida sin recargar, tres seguidas | El catálogo **no** se repinta en cada alta (no se ve el parpadeo de las columnas) y los números suben | M | |
| V7 | Cada tarjeta → *Editar* | Se abre con los valores y un campo por atributo del catálogo | M | |
| V8 | Editar el nombre y guardar, con la lista ordenada por nombre | **No recarga** y la lista sale ordenada por nombre, no por cédula | A | |
| V9 | Buscar `V-01` en la caja de búsqueda con la lista ordenada por *Nombre* | Es un GET: la URL cambia, se puede compartir y el botón «atrás» deshace la búsqueda; el resultado sale **en el mismo orden** y en la primera página | A | |
| V10 | Con una búsqueda puesta, borrar a alguien | La tarjeta repuesta **respeta la búsqueda** (no vuelve la lista entera) | A | |
| V11 | Importar un `.xlsx` con una columna nueva | Se crean la persona, el atributo, los números **y el campo del atributo en el alta**, sin recargar | A | |
| V12 | Importar un archivo con una fila sin identificador, en modo *reemplazar* | No se importa nada y el **informe sale igualmente**: dice qué fila y que no entró | A | |
| V13 | Con el JS desactivado, importar un archivo con filas malas | Recarga y el informe se ve en la pestaña «Importar», con las filas concretas | A | |
| V14 | Importar un archivo que no es un `.xlsx` | Sale el aviso del motivo y **no** un informe diciendo que se guardaron 0 filas | A | |
| V15 | Tras una importación, recargar la ficha | El informe **no vuelve a salir**: es de un solo uso | A | |
| V16 | *Vaciar la lista* | El diálogo dice **qué** se pierde y que las respuestas se quedan; la lista se vacía y **el catálogo sigue** | A | |
| V17 | Quitar un atributo del catálogo | Pregunta; desaparecen la columna, sus valores en las tarjetas y el campo del alta, sin recargar | M | |
| V18 | Quitar a alguien de la lista | El diálogo dice que su código deja de servir y que sus respuestas se quedan | A | |
| V19 | Un formulario con 26 participantes | 25 en la página y paginación; los números de arriba son **de los 26**, no de lo filtrado | M | |
| V20 | En móvil (360 px), la pestaña «Lista» | Los números no se desbordan y el botón de *Quitar* se ve sin scrolls interminables | M | |
| V21 | Con el JS desactivado, buscar, añadir y borrar | Todo funciona con POST normal y avisos con flash | A | |
| V22 | Un formulario que **no** es de lista | La tarjeta de Acceso es la de siempre: ni panel, ni alta de correo | A | |
| V23 | Buscar con el panel a media pantalla (sin recargar antes) | La página vuelve al panel, no al principio de la ficha: si el resultado queda fuera de pantalla parece que la búsqueda no hizo nada | M | |

## W. Elegir con qué se identifica a cada participante (fase 6, 2026-10-04)

El identificador es lo que decide **quién puede entrar**: la puerta busca por esa
columna, así que cambiarlo reescribe la identidad de toda la lista. Es el ajuste más
peligroso de la ficha y el que más parece un detalle, así que lo que se mira es que
**avise antes de romper** y que **sin JavaScript el camino exista**.

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
