# Evaluación de propuestas (F0–F5)

**Fecha:** 2026-09-29
**Estado:** F0, F1, F2, F3, F4 y F5 **implementadas y verificadas**.

Este documento es la evaluación de las seis propuestas que traía la lista de
mejoras, no un plan de trabajo. Para cada una: qué es, qué cuesta de verdad, qué
se decidió y por qué. Lo decidido está además razonado en
[`decisiones.md`](decisiones.md) y los invariantes que no conviene deshacer viven
en [`../AGENTS.md`](../AGENTS.md).

**Estado de la suite al cerrar F4:** 615 tests, 0 fallos, 0 errores
(`php scripts/test.php`).

---

## F0 — Agente de OpenCode con el conocimiento del proyecto

**Propuesta.** Que el agente no tenga que redescubrir el repo en cada sesión:
skills de documentación y de migraciones, commands reutilizables y el `AGENTS.md`
al día.

**Evaluación.** Es la de mejor relación coste/beneficio de las seis y la única
que no toca el producto. El riesgo real no es escribir los ficheros, es que se
**pudran**: un skill que indexa docs obsoletas, o un `AGENTS.md` que afirma
cosas que ya no son verdad, hace que el agente propague errores con más confianza
que si no tuviera nada. Por eso el valor está en lo que dice de la doc
(`technical-document.md` §4 desactualizada, `api-spec.md` obsoleta) más que en los
ficheros en sí.

**Decisión.** Aceptada, completa. Tres skills/commands con `name` coincidente
con su directorio y `description` verificada, y la sección `## Comandos` de
`AGENTS.md` actualizada.

**Ficheros.** `.opencode/skills/vf-docs/`, `.opencode/skills/vf-migraciones/`,
`.opencode/command/{test,migrate,nueva-migracion}.md`, `AGENTS.md`.

---

## F1 — Enmascarado de datos personales

**Propuesta.** Marcar los campos personales y decidir quién los ve en claro,
para no exponer PII de respondientes a quien solo gestiona formularios.

**Evaluación.** El riesgo de diseño está en **dónde** se decide el enmascarado. La
tentación natural es hacerlo en las vistas; es la decisión equivocada porque hay
cuatro canales de salida (detalle, listado, JSON, CSV) y enmascarar solo en la
pantalla deja siempre un canal limpio que alguien usa creyendo que está limpio.

**Decisión.** Aceptada, completa, con dos capas:
`fields.is_pii` (lo que el dueño **declara**, como una columna, no una clave en
`settings`) y el permiso `responses.pii` (la **capacidad** de leerlo en claro),
que **por defecto solo tiene `admin`**. Sin marca no hay enmascarado posible: un
`text` puede ser «¿Qué opinas?» o «Nombre de tu jefe», y el sistema no puede
saberlo.

Lo que se descartó: automatizar la detección de emails y teléfonos por regex
sobre los valores. Sería falso positivo (un texto libre puede contener un email
al azar) y sobre todo sería **falso negativo silencioso** en el campo que más
duele, el archivo adjunto.

**Ficheros.** Migración `0006_fields_is_pii.php`, `app/Services/Pii.php`,
`config/permissions.php` + `database/seed.sql`, `BuilderController` +
`views/builder/*` + `builder.js`, `ResponsesController`, `AnalyticsController`,
`Submission::exportJson/exportCsv`, `AttachmentController::download`,
`views/responses/*`. Tests: `tests/unit/PiiMaskingTest.php` (19 métodos).

**Pendiente de producto (no bloquea).** Si algún día el enmascarado bothersa a un
`user` legítimo, el sitio es `config/permissions.php`; la migración que lo
extienda es una nueva, nunca reejecutar la 0006.

---

## F2 — Recuperación y cambio de contraseña

**Propuesta.** Que un usuario que pierde su contraseña pueda recuperarla, y que
cambiar la contraseña cierre las sesiones de antes.

**Evaluación.** Es la propuesta con más superficie de seguridad de las seis, y
también la que más cosas «que funcionan pero no sirven» genera:

- Un enlace de recuperación **sin cerrar sesiones** deja al atacante dentro con
  la credencial nueva vigente: la recuperación sería cosmetics.
- Un `GET /reset-password` con middleware `guest` parece correcto y rompe el caso
  real (abrir el enlace con la sesión puesta).
- Comparar «la contraseña nueva es distinta de la actual» con un campo del POST
  no comprueba nada en recuperación: **no se conoce la actual**, así que el
  campo solo afirma lo que el propio POST dice.

La restricción estructural que condiciona todo: **las sesiones son ficheros
PHP**, no hay tabla de sesión, así que no se pueden revocar por id. Lo único
disponible es comparar instantes.

**Decisión.** Aceptada, completa. `/forgot-password` (respuesta uniforme, sin
token ni correo para emails desconocidos), `/reset-password` (token aleatorio de
128 bits **hasheado** con sha256, TTL 1 h, un solo uso, quemado en la **misma
transacción** que el cambio de contraseña), `/account/password` (exige la
actual). La revocación compara `Session['auth_at']` con
`users.password_changed_at` con comparación **estricta**, tratando la igualdad
como obsoleta porque los dos relojes tienen resolución de segundo;
`Auth::markSessionFresh()` evita que la estrictez expulse a nadie legítimo.

**Ficheros.** Migración `0007_password_reset.php`, `app/Models/PasswordReset.php`,
`app/Controllers/AccountController.php`, `app/Core/Auth.php` (`markSessionFresh`,
`revokeCurrentSession`), `app/Models/User.php` (`updatePassword` devuelve el
instante escrito), `views/auth/{forgot,reset}.php`,
`views/account/password.php`, `views/mail/password_reset.php`, `app/lang/{es,en}.php`.

**Coste de despliegue asumido.** Una sesión sin `auth_at` se cierra: al aplicar
la migración, todo el mundo tiene que entrar otra vez. Es el precio de no
aceptar sesiones cuyo origen no se puede comprobar.

**Tests.** `tests/unit/PasswordResetTest.php` (18, modelo y revocación) y
`tests/unit/PasswordRecoveryFlowTest.php` (11, controlador con
`Mailer::setTransport` y `Response::lastHalt`).

---

## F3 — Revisión y aprobación antes de publicar

**Propuesta.** Estados `draft → in_review → published` con revisores, de forma que
un formulario no sea visible hasta que alguien lo apruebe.

**Evaluación.** Es la propuesta que **más valor de producto** aporta y la que más
superficie de **UX** crea. El riesgo no es el modelo de datos (`forms.status` es
una enumeración corta), son las cuatro preguntas que hay que responder antes de
escribir código, y todas son de producto:

1. ¿Quién revisa? Si es «otro usuario del panel», hace falta un permiso nuevo y
   una noción de «revisor asignado»; si es un rol concreto, se puede ir sin más.
2. ¿Hay varios revisores en serie, o basta con uno?
3. Mientras está en revisión, ¿el enlace público responde 404 o una página de
   «en revisión»? (404 es más simple; la página informa mejor y delata la
   existencia del formulario.)
4. ¿Se notifica por correo a los revisores? (Es un envío más que ya existe:
   `Mailer` + `views/mail/`.)

**Decisión (tomada, 2026-09-29).** Las cuatro preguntas se respondieron antes de
escribir la migración:

1. **Quién revisa:** el permiso nuevo `forms.review`, en el mismo catálogo que
   el resto (`config/permissions.php` + `database/seed.sql` + migración que
   re-siembra), **solo `admin` por defecto**. No hay «revisor asignado»: la cola
   es de quien tenga el permiso, que es la noción más simple que no requiere un
   modelo de asignación.
2. **Revisores en serie:** **uno basta**. Aprobar publica. No hay cadena de
   aprobaciones ni estados intermedios; añadirla después es compatible porque
   `reviewed_by`/`reviewed_at` ya son columnas, no un booleano.
3. **En revisión, el GET público** responde una **página informativa**
   (`views/fill/in_review.php`), no un 404: quien tiene el enlace se lo han
   mandado y un 404 le haría pensar que está roto. El POST sigue exigiendo
   `published` (403), como ya hacía con `draft`.
4. **Notificación por correo:** **sí, pero opcional** (`settings.review
   .notify_email`) y apuntando al panel, no al enlace público. El aviso en el
   panel es siempre; el correo es un extra.

**Lo que resultó decidirse sin llegar a preguntarlo: el opt-in.** `settings.review.enabled`
viene **`false` por defecto**. Con revisión por defecto, todos los formularios ya
creados dejarían de poder publicarse sin un revisor, y en la instalación de un
solo usuario —el caso normal de un self-hosted— la revisión sería un teatro que
bloquea al dueño de su propia herramienta sin salida (no hay a quién pedirle
aprobación). Además, la autoaprobación se permite a propósito (con marca
`propio` en el log de seguridad): prohibirla deja sin publicar a quien no tiene
a nadie más.

**Lo que resultó ser la decisión difícil.** La revisión se puede saltar por la
puerta de atrás más obvia: el builder manda `status` en su payload, así que
guardar la estructura con `status: "published"` publicaba sin aprobar. Por eso el
estado solo lo mueven las tres acciones de `ReviewController`, y con la revisión
activa, **editar un formulario ya aprobado lo devuelve a la cola** (su contenido
ya no es el que se aprobó). La misma regla está en las DOS puertas de edición
(builder y ficha del formulario): cerrar solo una deja «cámbiale el título por
ahí y publícalo sin que nadie lo mire». El botón de publicar del dashboard
tampoco aparece para esos formularios: produciría un error de vuelta, que es
peor que no ofrecerlo.

**Verificación.** `tests/unit/FormReviewTest.php` (21). Fija lo contrario a lo
obvio: que el builder no publica, que aprobar exige el permiso y no ser el
dueño, que editar lo aprobado lo devuelve a la cola, que el aviso es opt-in y
va al panel, y que la página en revisión no acepta envíos.

---

## F4 — Acceso restringido y respondiente externo

**Propuesta.** `public | email | allowlist`: que el acceso al formulario exija
identificarse por correo o estar en una lista, para poder mandar el enlace a
gente concreta.

**Evaluación.** El mecanismo de identificado es conocido y ya está construido
(F2: token hasheado, un solo uso, TTL), así que el coste técnico es bajo. Lo caro
es el modelo de datos: un formulario "con lista de 300 personas" necesita una
tabla de invitados, y eso se parece mucho a un equipo de personas, que es
justamente lo que se decidió **no** hacer.

**Decisión.** **Implementada y verificada.** Los tres modos conviven, con
`public` como default para no romper los formularios ya publicados:

- **`email`:** cualquiera que verifique su correo recibe un enlace. No hay lista.
- **`allowlist`:** solo los emails de `form_access_emails` (migración **0009**).
  Tabla propia desde el principio, no el `settings` en JSON: la lista se edita,
  se filtra, se cuenta y se revoca por email, y a los 300 invitados un `JSON`
  dentro de una fila de `forms` deja de ser una lista para ser un campo de texto
  con permisos de base de datos.
- La allowlist se edita **en la ficha del formulario**, no en el builder, y F4
  **no añade permiso**: usa `Access::canEdit()`. Un permiso nuevo habría que
  sembrarlo en `config/permissions.php` **y** en `database/seed.sql` y darle una
  migración de re-siembra, todo para poder editar una lista que solo puede ver
  quien ya puede editar el formulario.
- El dueño puede **copiar el enlace** de alguien de su lista sin depender del
  SMTP (para mandar por WhatsApp, por ejemplo). Solo en `allowlist` y solo para
  emails que ya estén en ella: emitir para quien fuera convertiría el botón en
  un generador de accesos.
- **Fuera de alcance:** cuenta de respondiente con login propio, invitaciones,
  grupos y multi-tenant. Un respondiente externo **no** es un usuario: es alguien
  con un token. La línea que separa ambas cosas es si el respondiente manages
  algo; si no, un token basta y una cuenta sería solo un token con contraseña.

**Aviso de diseño.** El enlace con token por correo **no** es un control de
acceso fuerte por sí solo: viaja por el buzón, que es donde el atacante con la
contraseña robada puede leerlo. Es una barrera de puntuación, no de frontera;
conviene decirlo explícitamente en la documentación en vez de venderlo como
seguridad.

---

## F5 — Avance automático entre páginas

**Propuesta.** Que el paso siguiente se habilite solo al completar el actual, en
vez de exigir pulsar «Siguiente».

**Evaluación.** Es la más barata de implementar y la que más se nota al usar. El
riesgo es de Accessibility y no de código: si el avance depende de un evento
`change` de un input, con el teclado o con lector de pantalla el usuario puede
quedarse atascado en un paso que no ve como completado. Y hay que evitar que
avance con un campo vacío solo porque el navegador marques `required` como
"satisfecho" por un valor que el usuario no ha escrito de verdad.

**Decisión.** Implementada como **opt-in por formulario**
(`settings.advance.auto`, apagado por omisión), porque es un detalle que molesta
si aparece sin pedirlo y en un formulario de una sola pregunta no hace nada. Los
tres riesgos que la evaluación señalaba son los que gobiernan el diseño:

- **La trampa del `required`.** No se usa `checkValidity()` ni
  `reportValidity()` en ningún sitio de la decisión. Completa el paso
  `isCardAnswered()` —la misma medida que el envío y la barra de progreso—, que
  no cuenta un campo de texto con solo espacios. En un campo de texto, el
  navegador además marca `required` como satisfecho con un espacio en blanco, y
  su respuesta no es la misma en todos los navegadores.
- **El atasco de accesibilidad.** El avance salta con `change`, nunca con
  `input`: en un campo de texto `change` llega al perder el foco o al pulsar
  Enter («he terminado este campo»), y es el evento que el teclado y un lector de
  pantalla provocan igual que un ratón. Además, al saltar, el foco se mueve al
  **bloque** del paso nuevo (que lleva `tabindex="-1"` y un nombre accesible
  «Paso 2 de 3»), no a su primer campo: al cambiar de página la persona todavía
  no ha leído las preguntas, y meterle el cursor en un input la manda a escribir
  a ciegas. El foco se queda donde está en el salto manual, que sí viene de un
  botón.
- **Que el formulario se recorra solo al cargar.** `autoAdvanceReady` se
  enciende al final del arranque, después de `restoreDraft()` y de
  `evaluateConditions()`. Con el borrador local restaurado, un paso puede venir
  ya completo; si el mecanismo estuviera armado desde el primer momento se comería
  el resto del formulario sin que nadie hubiera tocado nada. `goPrev()` lo
  cancela, porque volver atrás es siempre una decisión consciente.

Reglas de la puerta (`autoAdvanceAllowed()`): solo las obligatorias **visibles**
del paso cuentan (una opcional no puede bloquear el salto, y si el paso no tiene
ninguna obligatoria no hay nada que esperar y no se avanza nunca solo); el último
paso no avanza (su botón es Enviar); un paso **omitido** no avanza; y un `change`
que venga de otro paso no mueve este.

Notas de implementación que siguen vigentes:

- El denominador de la barra de progreso es `progressFieldCards()` en
  `public/assets/js/fill.js`, y es **otro conjunto** que `visibleFieldCards()`:
  tocar uno sin el otro desincroniza la barra. F5 no lo toca: usa
  `stepCards()`/`conditionVisible()`, los mismos filtros de visibilidad pero por
  paso.
- El paso realmente **omitido** se marca con `data-vf-skipped` (se pone y se
  quita), no con una clase permanente. Y se comprueba **por valor**
  (`=== '1'`), no por presencia: `toggleAttribute()` deja el valor en `""`.
- `isCardAnswered()` es «completa o nada» para matriz y ranking, igual que
  `val.matrix_all`. Mide **completitud**, no validez, y por eso el avance pide
  las dos cosas (`isCardAnswered() && validateField(card).length === 0`): con la
  primera sola, un ranking con posiciones repetidas —que el campo ya pinta en
  rojo con `val.ranking_unique`, y que validan el cliente y el servidor— daría el
  paso por bueno y el error se quedaría en la página anterior.

---

## Descartado de esta tanda (y por qué)

| Propuesta | Motivo |
|---|---|
| PASETO/vouchers en vez de tokens con tabla | rotating keys, re-emisión y revocación solo tienen sentido si un tercero firma tokens. Aquí el servidor es el único emisor: un token aleatorio de 128 bits, hasheado, es más simple de hacer bien. |
| Invitaciones y equipos | Es un paradigma de "equipo" y luego pide roles por recurso, permisos
heredados, invitaciones pendientes, baja de miembros y traspaso de propiedad. Es un producto
distinto, no una fase. |
| Cuentas de respondiente | Duplica el sistema de auth entero para alguien que solo rellena una vez. |
| Multi-tenant | Sigue en `Tier 3` del roadmap. Es el que más cambia el modelo de datos de todos los que se han mirado. |

## Documentación del repo

Con F0–F5 cerradas, la higiene documental de esta tanda se hizo el 2026-09-29:
`technical-document.md` §4 ya describe las 16 tablas, `api-spec.md` refleja
`config/routes.php`, y `posicionamiento-comparativa.md` y
`roadmap-encuesta.md` §Tier 3 dejaron de citar cifras y brechas obsoletas.


