# Plan — Modo de acceso «Listado de participantes» (2026-10-04)

**Estado de partida:** `30e4a34` · 769 tests en verde · MariaDB 11.8.6 · PHP 8.2+ sin
Composer.

Cuarto modo de acceso: en vez de «público / cualquiera con el enlace / los correos de
la lista», el dueño sube una **lista de participantes** y solo ellos entran. La lista
se gestiona en una pestaña nueva de la tarjeta «Acceso», con plantilla `.xlsx`
descargable, importación y alta/edición/borrado. Además, las estadísticas se pueden
**filtrar y agrupar por los atributos** que el dueño defina al subir el archivo
(gerencia, área, …).

**Cada fase empieza por su número; no las reordenes sin actualizar la tabla de
estado del final.** El documento existe para no perder el contexto entre sesiones:
las decisiones ya tomadas y su porqué están aquí, no en la conversación.

## Decisiones ya tomadas (con el usuario)

| Tema | Decisión |
|---|---|
| Identificador | Configurable por formulario: `cedula`, `indicador` o `correo` |
| Credencial | Todo participante recibe un **código personal** de 16 caracteres. Lo que se elige arriba es **qué campo lo identifica en tus registros**, no el secreto con el que entra |
| Correo | Con identificador `correo` el enlace se puede mandar por correo (opt-in). Con `cedula`/`indicador` lo descargas en la lista y lo repartes |
| Formato | **`.xlsx` real**, generado a mano con `ZipArchive`. Si falta la extensión, error claro; no hay CSV de respaldo |
| Columnas | `cédula`, `nombre`, `correo` + las que el dueño elija al subir |
| Importar | A elegir: **fusionar** o **reemplazar todo**. Además, botón «vaciar lista» en la gestión |
| Quién respondió | Sí se guarda, y **solo se ve con `responses.pii`** (admin) |
| Estadísticas | Filtrar **y** agrupar por atributo, más columnas de atributo en el detalle |
| Cruce completo | **No** ahora. «Cada pregunta × cada atributo» es un paso aparte |
| Identidad en analítica | **Solo los atributos que defina el dueño.** Cédula, nombre y correo del participante no aparecen ni para el admin |
| Datos personales | El bug de la analítica se arregla **antes** que todo lo demás |
| Correo de pruebas | Sumidero SMTP local propio, sin credenciales |

## Restricciones del entorno (verificadas, no recordadas)

- **`zip` SÍ está instalada**: `ZipArchive` existe. La plantilla se puede generar hoy.
- **`php-xml` SÍ está instalada**: hay `DOMDocument`, `SimpleXMLElement`, `XMLReader`
  y `mbstring` (`php -m` lo confirma). Ojo: `class_exists('mbstring')` devuelve
  «no» porque `mbstring` es una extensión, no una clase, así que la comprobación
  de disponibilidad tiene que hacerse con `extension_loaded()`, no con
  `class_exists()` salvo para clases de verdad como `ZipArchive`.
- **En MariaDB 11.8.6 los operadores `->` y `->>` NO parsean** (error 1064): hay que
  usar `JSON_EXTRACT`. El proyecto **nunca** usa funciones JSON de SQL; todo el JSON
  se decodifica en PHP. Por eso el diseño de abajo **no usa JSON en SQL**.
- `database/schema.sql:5-6` fija `utf8mb4_unicode_ci` como default y ninguna tabla lo
  cambia. Los `UNIQUE` y los `GROUP BY` son **insensibles a mayúsculas y acentos**.
- `upload_max_filesize` en esta máquina es **2M**, no los 10 MB del `.env`.
- El `Router` **sí aguanta dos parámetros**: `compile()` genera grupos con nombre y
  `coerceParam()` responde 404 si no es entero.

## Por qué el diseño es este

**El cuarto modo se degrada en silencio en 20 sitios de 8 archivos.** Es el riesgo
número uno del proyecto, y cada uno falla de forma distinta y sin error visible:

| Archivo:línea | Qué pasa si se olvida el modo nuevo |
|---|---|
| `FormSettings.php:33,219` | `normalize()` lo convierte a `public`: **formulario abierto a todo el mundo** |
| `FormSettings.php:40` | `RESTRICTED_MODES` no lo incluye: `/f/{slug}/access` da **404** |
| `FormSettings.php:314` | El POST solo manda `mode`: el identificador no se guarda |
| `FormSettings.php:464` | `validate()` acepta el modo sin validar el identificador |
| `FormAccess.php:194,197` | `isAllowed()` cae en `false` sin tocar la BD: **cerrado a todos** |
| `FormController.php:167,170` | `accessList`/`accessCount` son `=== ALLOWLIST`: se apagan |
| `AccessController.php:246,408,415` | Tres acciones con el allowlist cosido a mano |
| `FormPanel.php:136,139` | Ídem que en el controlador |
| `views/fill/access.php:23-27` | Coacciona cualquier modo nuevo a `email` |
| `views/dashboard/forms/show.php:214` | El `<select>` sin la opción |
| `dashboard.js:320,343` | El `else` final anuncia la allowlist |

**El interruptor de vuelta es un cambio de ajustes, no un deploy.** Ese es el
principio que sostiene el «no romper nada»: los datos del roster son aditivos y las
ramas `public`/`email`/`allowlist` quedan **byte a byte intactas**. Si algo falla, el
dueño pone «Público» y todo vuelve al comportamiento de hoy.

## Modelo: tres tablas

`form_participants` — la persona.

| Columna | Nota |
|---|---|
| `id`, `form_id` | FK `CASCADE` |
| `cedula`, `nombre`, `indicador`, `correo` | Los tres candidatos a identificador |
| `identifier_value VARCHAR(190) NOT NULL` | **Denormalizado**: la puerta busca siempre por la misma columna indexada, elijas identificador |
| `code CHAR(16) NOT NULL UNIQUE` | La credencial personal |
| `responded_at DATETIME NULL` | Cuándo respondió |
| `created_at`, `updated_at` | |

`UNIQUE (form_id, identifier_value)` e índice `(form_id, correo)`.

`form_participant_fields` — **el catálogo de atributos por formulario**. Es la
frontera de seguridad: el SQL solo recibe un `key_name` que salió de aquí.

| Columna | Nota |
|---|---|
| `id`, `form_id` | FK `CASCADE` |
| `key_name VARCHAR(64)` | Slug técnico estable. Es lo que viaja al SQL |
| `label VARCHAR(190)` | Lo que escribió el dueño en la cabecera del xlsx. `UNIQUE (form_id, label)` |
| `position` | Orden de las columnas |

`form_participant_attrs` — un renglón por valor.

| Columna | Nota |
|---|---|
| `form_id` | **Desnormalizado** para filtrar sin atravesar dos tablas |
| `participant_id` | FK `CASCADE` |
| `key_name`, `value VARCHAR(255)` | `VARCHAR` y no `TEXT` para que el índice sea completo: aquí no hay párrafos, hay «Gerencia» |
| índices | `UNIQUE (participant_id, key_name)`, `(form_id, key_name, value)` |

`submissions` gana `participant_id INT UNSIGNED NULL` con FK **`ON DELETE SET NULL`**:
borrar un participante **no** borra su respuesta.

**19 tablas → 22.**

### Por qué sin JSON

La alternativa obvia era una columna `data` con JSON y
`JSON_UNQUOTE(JSON_EXTRACT(data,'$."clave"'))`. Se descartó por dos razones
verificadas:

1. **El nombre de la clave no se puede pasar como parámetro enlazado**: va dentro de
   la llamada a la función. Es decir, texto del usuario —la cabecera del xlsx—
   dentro del SQL.
2. `GROUP BY` sobre eso no es indexable, y el proyecto ya tiene la lección escrita:
   `a.value` es `TEXT` y `MIN`/`MAX` comparan como cadena (`Answer.php:347-350`).

Con la tabla de atributos, `key_name` y `value` viajan **enlazados como valores**: no
hay por dónde entrar.

## Analítica

**Filtrar no necesita un `JOIN` nuevo**, es un `AND` más, y como es una subconsulta
`IN` — el patrón que ya usa `Form.php:132/147` — las consultas existentes **no
cambian de forma**:

```sql
AND s.participant_id IN (
    SELECT participant_id FROM form_participant_attrs
    WHERE form_id = :fid AND key_name = :k AND value = :v
)
```

El filtro tiene que entrar por **los dos caminos** (`byFormFilteredPaginated()` y los
agregados de `Answer`), o el indicador marca «38 respuestas» y la tabla de abajo
tiene 12 filas.

**Agrupar es una consulta fija**, con la misma forma que la que ya existe, que trae
todos los cubos de golpe sin importar cuántas áreas o preguntas haya:

```sql
SELECT pa.key_name, pa.value, an.field_id, an.value, COUNT(*) c
FROM answers an
JOIN submissions s ON s.id = an.submission_id
JOIN form_participant_attrs pa ON pa.participant_id = s.participant_id
WHERE s.form_id = :fid AND pa.key_name = :k AND ...
GROUP BY pa.key_name, pa.value, an.field_id, an.value
```

Esto respeta la decisión documentada en `FieldAggregator.php:11-34` («número fijo de
consultas») en vez de multiplicarla por el número de grupos. El **techo de grupos**
(«los N más frecuentes + resto») sale de una consulta pequeña de valores beforehand;
no es opcional, porque los agregados de hoy no tienen `LIMIT` y un atributo libre con
800 valores serían 800 grupos.

**Normalización:** la collation ya resuelve mayúsculas y acentos (`Gerencia X` y
`gerencia x` son el mismo grupo). Lo que **sí** hay que hacer es recortar y colapsar
espacios internos, porque la collation no los ignora. Y el `UNIQUE` del `label` hace
que `Gerencia` y `Gerencia ` no creen dos atributos sino uno: la importación **reusa**
el existente y lo dice.

## Fases

| # | Fase | Riesgo | Estado |
|---|---|---|---|
| 0 | Bug de PII en la analítica | bajo | **Hecha** (6 tests nuevos) |
| 1 | Escritor xlsx (`RosterTemplate`) | **cero** | **Hecha** (15 tests nuevos) |
| 2 | Migración `0014` | **cero** | **Hecha** (11 tests nuevos) |
| 3 | `FormParticipant`, CRUD, importar | bajo | Pendiente |
| 4 | **La puerta del fill** | **alto** | Pendiente |
| 5 | Pestaña Participantes + huecos | bajo | Pendiente |
| 6 | Exponer `roster` en el `<select>` | bajo | Pendiente |
| 7 | Filtro por atributo + detalle | bajo | Pendiente |
| 8 | Agrupar por atributo + techo | medio | Pendiente |
| 9 | Quién respondió, `responses.pii` | bajo | Pendiente |
| 10 | Correo de invitaciones, opt-in | bajo | Pendiente |
| 11 | Documentación y recuentos | — | Pendiente |

### Tres reglas que no son sugerencias

1. **La fase 6 es la última en aparecer en pantalla.** `roster` existe en el código
   desde la fase 2 (si no, `normalize()` lo convierte a `public`), pero sin `<option>`
   nadie lo alcanza aunque la puerta esté mal.
2. **Las tres ramas de acceso actuales no se tocan.** La nueva es un `if` añadido. Un
   test compara el comportamiento de `public`, `email` y `allowlist` antes y después.
3. **Volver atrás es un cambio de ajustes, no un deploy.**

## Fase 0 — el bug que hay que arreglar primero — **HECHA**

`AnalyticsController` **no enmascara datos personales en pantalla**. Todo su `Pii::`
está dentro de `exportCsv()` (líneas 174 y 238); `show()` y `rows()` no lo llaman, y
`views/analytics/_rows.php:26` imprime el valor crudo. En cambio
`ResponsesController` **sí** enmascara, en el listado y en el detalle.

Contradecía `AGENTS.md` («los cuatro canales de salida se enmascaran») y el manual, y
`PiiMaskingTest` no lo cubría porque prueba justo los otros tres canales.

**No basta con tapar la tabla:** el filtrado es para agrupar, y los gráficos se
construyen desde `data-chart` con los valores crudos (`views/analytics/show.php:6-23`).
Si solo se tapa la tabla, un campo de correo marcado seguiría apareciendo como una
**barra con la dirección real escrita en la etiqueta**. La forma correcta es la misma
que usa el CSV: **enmascarar antes de agregar**, para que los gráficos muestren una
barra `[oculto]` con el total de respuestas, que además es información útil.

### Lo que salió al implementarla

1. **La primera versión tapaba también al admin**, y un test lo cazó. El fallo era
   mío y de fondo: había dejado la decisión del permiso **dentro del partial**, así
   que la lista de campos marcados llegaba siempre llena. El patrón correcto, que ya
   usan `ResponsesController:48,58,88,93`, es decidir en el controlador y pasar
   `Pii::canView() ? [] : Pii::piiFieldTypes(...)`: **una lista vacía es la forma de
   decir «este usuario puede verlos»**. La vista queda como función pura de lo que
   recibe.
2. **El CSV ya enmascara siempre, admin incluido**, porque su puerta es
   `forms.export_own` y no `responses.pii`. Se deja así (está del lado estricto) y
   queda documentado en `AGENTS.md` y en el manual como asimetría conocida, para que
   nadie lo «arregle por simetría»: abrirlo sería aflojar una exportación.
3. Los agregados salen honestos sin inventar nada: `numerico`/`nps` filtran lo no
   numérico con una regex y quedan a `null`; `matriz`/`ranking` buscan la etiqueta en
   las opciones y la fila desaparece. Solo `conteoSimple`/`conteoCheckbox` producen
   una barra `[oculto]` con el total, que es lo único que sí es útil.

6 tests nuevos en `PiiMaskingTest` (el detalle, el permiso en claro, el marcador que
no se reinterpreta como JSON, la fusión de barras, el promedio que no se inventa y
los gráficos en claro con permiso). **775 tests en verde.**

## Fase 1 — el escritor `.xlsx` — **HECHA**

`app/Services/RosterTemplate.php`. Función pura: sin BD, sin rutas, sin puerta, sin
sesión. Eso la hace verificable por separado de todo lo demás, y es por eso que se
puede hacer antes de tocar el esquema.

Un `.xlsx` son **cinco partes XML dentro de un ZIP** y no hay formato intermedio:

| Parte | Para qué |
|---|---|
| `[Content_Types].xml` | Declara qué es cada parte |
| `_rels/.rels` | Apunta al workbook |
| `xl/workbook.xml` | Nombra la hoja |
| `xl/_rels/workbook.xml.rels` | Apunta a la hoja |
| `xl/worksheets/sheet1.xml` | Los datos |

**Tres decisiones deliberadas, no accidentales:**

1. **Celdas `inlineStr`, sin tabla de cadenas compartidas.** Una hoja de 500×6
   repite 3000 cadenas y sigue pesando ~100 KB, a cambio de no mantener una quinta
   parte sincronizada con sus índices. Excel y LibreOffice leen las dos formas.
2. **Sin `styles.xml`.** Se aplica el estilo por defecto, que es lo que quiere quien
   rellena la plantilla. Una sexta parte sin consumidor es una fuente más de «el
   archivo está dañado».
3. **Sin fila de ejemplo.** Una fila de ejemplo es un participante de mentira que
   alguien se deja puesta. La plantilla son cabeceras y nada más.

**Todo el texto va como cadena, nunca como número.** Una cédula `00123` o un código
`0007` guardados como número pierden los ceros por la izquierda en cuanto se reabre
el archivo, y para una lista de participantes eso es un dato perdido sin aviso.

**Los tres fallos que de verdad rompen un archivo, y que por eso son los que se
comprueban:**

| Fallo | Síntoma si ocurre |
|---|---|
| Columna contada por celdas escritas, no por posición | La hoja abre **desplazada**, sin error |
| `&`, `<` o tilde sin escapar | Rompe el XML **entero**, no una celda |
| Carácter de control (`\x01`, `\x0B`) de una cabecera de Excel | El archivo **no abre**, con un error de Excel que no menciona la plantilla |

Por eso el test **no mira el binario**: abre el ZIP con `ZipArchive`, parsea cada
parte con DOM y lee la hoja como la leería una hoja de cálculo. Un test que solo
comprobarse que el archivo «no está vacío» pasaría con un ZIP corrupto.

El orden de columnas del archivo es **un contrato con la importación** y por eso
tiene test propio: fijas, atributos, código. Si el código no fuera el último, la
importación se lo leería como un atributo libre. Lo mismo con las cabeceras
reservadas, que se reconocen aunque el dueño las escriba distintas («Cédula»,
«CEDULA», «E-mail»): si no se normalizaran, se crearía un atributo «Cédula» que el
dueño no puede filtrar de forma estable y que se solapa con la cédula real.

**Verificación independiente:** además de los 15 tests de PHP, el archivo generado se
validó con un script aparte en Python que usa `zipfile` y `xml.etree` —otro ZIP y
otro parser XML, ninguno compartido con el escritor— comprobando el grafo de
relaciones, que `Content_Types` declare cada parte por su extensión, y que cada
referencia de celda decodifique y recodifique. 142 comprobaciones en verde. Ese
script no entra al repo: añadir Python a una base sin CI es peor que la cobertura
que aporta, que ya está en `RosterTemplateTest`.

**15 tests nuevos. Suite completa: 790, 0 fallos.**

## Fase 2 — el esquema (migración `0014`) — **HECHA**

Tres tablas y una columna. Las dos decisiones que justifican el diseño están
escritas en el `COMMENT` de la propia columna, que es donde se van a leer:

- **`identifier_value` denormalizado.** Los tres candidatos a identificador
  (`cedula`, `indicador`, `correo`) son columnas propias, pero la puerta del fill
  busca siempre en esta, que está indexada. Un `CASE` sobre tres columnas
  significa tres índices y el mismo código repetido tres veces.
- **`submissions.participant_id` con `ON DELETE SET NULL`.** Borrar a alguien de
  la lista es dejar de invitarlo, **no** anular lo que ya contestó. Con `CASCADE`,
  quitar a un participante tiraría sus respuestas y no habría forma de
  recuperarlas. Es la regla que se comprueba con un test, porque no se deduce
  leyendo el nombre de la columna.

`responded_at` es redundante a propósito y su comentario lo dice: existe para poder
listar «quién NO ha respondido» con un `LEFT JOIN ... WHERE responded_at IS NULL` en
vez de una subconsulta correlacionada. Se puede quedar viejo, y su único escritor es
el mismo sitio que crea la respuesta.

### Lo que salió al implementarla (y no estaba previsto)

**1. `database/schema.sql` estaba ROTO y no loIndicaba ningún test.** La línea 102
era la continuación de un comentario a la que se le había caído el `--`:

```
-- la FK convierte el descuido de escribir un 0 en un error de integridad en vez
 * de un "campo sin sección" silencioso.
```

MariaDB intentaba ejecutar esa prosa como SQL y **`schema.sql` no instalaba una base
limpia**: el error sale en la línea 102, mil líneas más allá del `--` que se perdió,
y no menciona el esquema. Es decir, la ruta documentada de «BD desde cero» estaba
rota. Ahora hay un test que la vigila
(`testSchemaSqlNoTieneComentariosRotos`) y que se comprobó **reintroduciendo el bug a
propósito**: sale rojo señalando la línea.

**2. Yo introduje el mismo descuido en mis propias tablas**, y por eso el orden de las
comprobaciones era: instalar limpio, migrar, y **comparar las dos bases**. Salieron
tres diferencias reales que ninguna revisión de código habría visto:

| Diferencia | Por qué importa |
|---|---|
| `submissions.participant_id` **sin la FK** en `schema.sql` | Instalaba sin `ON DELETE SET NULL`: solo las bases migradas garantizaban que borrar a alguien no tira sus respuestas |
| La columna **al final** de `submissions` en la migración | Al arreglar unas comillas se me fue el `AFTER form_id`; el orden de columnas ya no cuadraba |
| Faltaban `COMMENT` en 4 sitios | Cosmético, pero hacen que las dos bases difieran y por tanto que el diff deje de servir |

La comparación no es un `mysqldump | diff`, sino una consulta a `information_schema`
que compara columnas, tipos, nulabilidad, posición, índices y **reglas de borrado de
las FK**: 239 elementos estructurales, idénticos por las dos vías. (Y con un aviso:
`diff` de dos ficheros **vacíos** sale limpio. Se comprobó que las dos listas tuvieran
239 líneas antes de creerse nada.)

**3. La herramienta de comparación mintió dos veces**, y las dos por el mismo motivo:
`diff` sobre ficheros vacíos sale con código 0. Está escrito en el propio script.

**11 tests nuevos** en `ParticipantSchemaTest`. No miran el archivo de la migración:
miran **la base de datos**, que es donde el error se vuelve problema. Cogen tres
cosas que de otro modo no habría quien las viera — que no se haya migrado, que el
esquema esté a medias, y que un índice haya dejado de cubrir su columna — y una
cuarta que compara el texto del DDL de las dos fuentes, que es como una instalación
limpia acaba sin la FK que sí tiene toda base migrada.

**Suite completa: 801, 0 fallos.**

## Decisiones de correo

- **`Mailer::setTransport(callable)`** intercepta el sobre entero en memoria y no toca
  la red (`app/Core/Mailer.php:39,105`). Las pruebas automáticas no necesitan SMTP.
- **La importación nunca envía correo.** Subir 400 filas y disparar 400 correos desde
  un `POST` es un accidente esperando. El botón «Enviar invitaciones» va aparte y
  marca a quién ya se le envió.
- **Degradar, no lanzar:** `Mailer::send()` lanza `RuntimeException` si `MAIL_HOST`
  está vacío, así que hay que preguntar antes por `Mailer::isConfigured()`.
- **En tests, destinatarios `@test.local`**, porque la instancia tiene usuarios reales
  (la convención que ya usa la revisión de F3).
- **Sumidero SMTP local** (script propio en `tests/tools/`): el `Mailer` se conecta a
  `MAIL_HOST:MAIL_PORT` y nada más, así que un listener en `127.0.0.1` con
  `MAIL_ENCRYPTION=` vacío funciona. Se descartó usar un Gmail personal: obliga a un
  *app password* en `.env` y a que `MAIL_FROM_ADDRESS` sea esa cuenta, con lo que la
  dirección personal queda como remitente de todas las invitaciones.

## Lo que queda fuera

- **Cruce completo** (cada pregunta × cada atributo): paso aparte. Suma una tabla de
  medias y el techo de grupos tiene que ser doble, porque las celdas son producto de
  dos dimensiones.
- **Envío automático en la importación**: nunca, por diseño.
- **`FormAccess`/`form_access_emails`**: no se tocan. El roster es un modo más, no una
  ampliación de la allowlist.

## Estado

| Fase | Estado |
|---|---|
| 0 | **Hecha** — `4b4d31b`, 775 tests |
| 1 | **Hecha** — 790 tests |
| 2 | **Hecha** — 801 tests |
| 3 | Pendiente |
| 4 | Pendiente |
| 5 | Pendiente |
| 6 | Pendiente |
| 7 | Pendiente |
| 8 | Pendiente |
| 9 | Pendiente |
| 10 | Pendiente |
| 11 | Pendiente |
