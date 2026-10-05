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
| 3 | `FormParticipant`, CRUD, importar | bajo | **Hecha** (85 tests nuevos) |
| 4 | **La puerta del fill** | **alto** | **Hecha** (23 tests nuevos) |
| 5 | Pestaña Participantes + huecos | bajo | **Hecha** (24 tests nuevos) |
| 6 | Exponer `roster` en el `<select>` | bajo | **Hecha** (17 tests nuevos) |
| 7 | Filtro por atributo + detalle | bajo | **Hecha** (17 tests nuevos) |
| 8 | Agrupar por atributo + techo | medio | **Hecha** (13 tests nuevos) |
| 9 | Quién respondió, `responses.pii` | bajo | **Hecha** (13 tests nuevos) |
| 10 | Correo de invitaciones, opt-in | bajo | **Hecha** (13 tests nuevos) |
| 11 | Documentación y recuentos | — | **Hecha** |

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

## Fase 3 — modelo, CRUD e importación — **HECHA**

Cuatro entregables y **85 tests**: lector XLSX (17), modelo (31), importador (19) y
controlador (18). La fase quedó en 886 tests, 0 fallos.

### El lector tiene que adivinar, y cada suposición es una forma de perder datos

Un `.xlsx` es un ZIP de XML y **no dice cómo se llaman las hojas**. El orden de los
ficheros dentro del ZIP no es el de las hojas (va por CRC), así que «la primera» es
una moneda al aire. `RosterTemplate::leer()` usa `xl/workbook.xml` para el nombre
real, `xl/_rels/workbook.xml.rels` para el destino, y solo si no aparece busca por
posición. Un test genera un ZIP **con las entradas barajadas** y otro pone las hojas
en el orden inverso a como se escribieron: los dos salen del caso que un
`glob('xl/worksheets/sheet1.xml')` no habría visto.

Otras cuatro suposiciones que también se prueban: `sharedStrings.xml` (no todas las
celdas de texto van inline), celdas con varios `<r>` («se**ñ**or» partido), huecos
intermedios (una fila vacía **no** termina la tabla) y límites de descompresión.

### El identificador es denormalizado, y eso obliga a un invariante

`identifier_value` es una copia de `cedula` / `indicador` o `correo`. Eso hace la
búsqueda barata y deja la puerta del fill en una sola columna, pero crea una
obligación: **`sinIdentificadorPara()` tiene que reescribir la copia si el formulario
cambia de identificador, y si no la puerta busca en una columna que ya nadie
escribe.** Hay un test que cambia el identificador del formulario y comprueba que el
nuevo `identifier_value` es el nuevo campo.

### `utf8mb4_unicode_ci` no distingue mayúsculas ni acentos: dos cosas que se creían distintas

**1. «Diseño» y «Diseno» son el mismo atributo.** El `key_name` sale del
`slugify()` de la cabecera, así que un archivo con `Diseño` y otro con `Diseno` —incluso
en el mismo archivo— convergen a `diseno`. `ensureAttribute()` reutiliza la fila
existente en lugar de intentar insertar y comerse un `1062`. El test
`testDosCabecerasQueSoloSeDifierenEnAcentosSonElMismoAtributo` lo fija. Sin esto,
importar un archivo de una columna y otro de la suya creaba dos atributos que se
pisan.

**2. El slug de un identificador puede chocar aunque el texto sea distinto.**
`Sede-Norte` y `Sede Norte` dan el mismo `key_name`, y `ensureAttribute()` resuelve el
conflicto como **sufijo** (`sede_norte_2`), que es la convención que ya usa el
generador de `code`.

### Lo que rompió al testear el modelo, y era del modelo

**`FormParticipant::create()` devuelve `['ok','id','motivo']`, no la fila.** Lo primero
que escribí fue `$fila = FormParticipant::create(...); $fila['code']`, que es `null`
en silencio. Un test que compara el código «antes» contra «después» pasaba probando
`null === null`. Lo mismo con `Form::create()` y `Submission::create()`, que sí
devuelven un `int`: leer `$form['id']` de un entero produce `null`, la llamada se va
a `/forms/0`, responde 404 y el test sigue verde.

### El importador: tres decisiones que parecían detalles

**1. `reemplazar` valida ANTES de borrar, siempre.** Con un `DELETE` dentro del
bucle, un archivo con 399 filas buenas y 1 mala deja la lista con las 399 y nadie se
entera de que era una sustitución. Se comprobó **quitando el `return` de la
validación previa**: el test se pone rojo.

**2. En `fusionar`, un error de fila no tumba el resto, y eso también se probó**
metiendo una fila con el correo inválido en medio de un archivo de cinco.

**3. Los atributos que el archivo no trae no se borran al actualizar.** Una columna
que el archivo nuevo ya no trae es una columna que el editor ya no quiere, pero un
`key_name` ausente en **esa fila** es «no la he rellenado». Distinguir las dos cosas es
lo que separa «actualizar» de «reemplazar mis datos», y la prueba es un archivo que
trae `Sede` y `Turno` donde el participante solo tenía `Sede`: después sigue teniendo
las dos, con `Turno` en blanco.

Y una cuarta, que salió al mirar los tests y no al escribir el código: el catálogo
**no se borra al reemplazar**. Las columnas son el formato del próximo archivo; si se
fueran con la gente, la segunda importación del mismo archivo no tendría nada que
reconocer.

### El controlador devuelve JSON, pero con el mismo contrato de siempre

`ParticipantController` responde `{ok, message, ...}` tanto en JSON como con el flash
y el 302 de siempre, según `wantsJson()`, porque **la pestaña todavía no existe** (fase
5) y una vista que solo pinta una tabla no aporta nada que JSON no dé. Cuando llegue
la pestaña, esta misma respuesta es lo que consumirá.

Tres cosas del controlador que parecen no código y son la puerta:

- **`rosterForm()` exige `Access::canEdit()` y el modo `roster`, y devuelve 404 (no
  403) si el formulario no es de lista.** Desde fuera, que un formulario tenga o no
  listado no es información que deba darse a quien solo puede verlo.
- **`update()` y `destroy()` comparan `(int) $fila['form_id'] !== $id`.** Los dos
  reciben un `participant_id` del POST, y el dueño de dos formularios —cosa normal—
  puede mandar el id de la lista que no es. **La primera versión de los tests no lo
  cubría** (creaban el participante ajeno en el mismo formulario, así que el `form_id`
  era el mismo y todo pasaba), y se vio al **quitar el `form_id` a propósito y ver que
  la suite seguía verde**: un test que no falla cuando rompes lo que vigila está
  probando otra cosa. Ese agujero estaba en los tests, no en el código.
- **`index()` no lee `per_page`.** El tamaño de página lo fija el servidor.

### El orden del listado es de texto, no de número

`allForForm()` ordena por `identifier_value`, que es una columna de texto: `V-51` va
**antes** que `V-6`. El test de paginación crea `V-1`…`V-51` a propósito, y al
escribirlo se esperaba `V-51` al final. Está anotado en el test porque «arreglarlo» por
cambiar el `ORDER BY` a algo numérico rompería la búsqueda, que es textual también.

### Lo que seguía sin hacer al cerrar la fase 3

**`roster` no era una puerta.** No se había tocado `FillController`, ni
`FormSettings::RESTRICTED_MODES`, ni el `<select>` de los modos de acceso, así que un
formulario en modo `roster` se comportaba como `public` para quien responde: se podía
llenar sin invitación. Es lo que hizo la fase 4 (ver más abajo), y por eso la fase 3 no
se podía dar por buena en pantalla: **nada de esto era visible todavía.**

**Suite completa: 886, 0 fallos. 8 mutaciones comprobadas** (quitar el `form_id` del
`update`, del `destroy` y del alta; quitar el `canEdit`; no exigir el modo `roster`;
dejar pasar cualquier `key_name`; ignorar la búsqueda en el total y en las filas; dejar
elegir `per_page`). Las ocho se ponen rojas.

## Fase 4 — la puerta del fill — **HECHA**

Veintitrés tests en `tests/unit/RosterDoorTest.php` y **912 en total, 0 fallos**. Es la
fase de riesgo alto del plan, y el riesgo no es el código: es que **un `roster` fuera
de `RESTRICTED_MODES` deja el formulario abierto** sin un error en el log ni en la
respuesta. Por eso la puerta y su `<option>` van en fases distintas pero la lista y el
gate van **en el mismo commit**, y por eso uno de los tests mira la lista.

### La credencial viaja en `?t=`, y eso no es casualidad

F4 ya usa `?t=` para el token de email, así que el código entra por ahí y **no hay
que tocar el `action` del POST**: el enlace con el código ya reenvía la credencial al
enviar. Un segundo parámetro habría significado un segundo camino de entrada en el
formulario, y el corte del POST (que va **antes** que `post-max` y `csrf`, igual que
F4) solo podía mirar uno.

El orden de resolución es deliberado: **primero el token de F4**, y solo si ese no
casó, el código. En un formulario de listado un `?t=` que casa con
`form_access_tokens` sigue siendo ese token, y los dos tipos de fila no se buscan en
la misma tabla.

### El identificador identifica, el código autentica

Un código de 16 caracteres del alfabeto sin `0/O/1/I/L` (32 símbolos, ~80 bits) vive
en la URL, así que **no lleva caducidad**: caducaría en una cadena de reenvíos y de
correos, y el problema se trasladaría al sitio que lo reenvió. Lo que lo cierra es
que la persona **siga en la lista**, y eso se comprueba en cada request.

### La sesión guarda el id, y revalida

`$_SESSION['vf_form_access'][$formId] = ['id' => <participante>, 'exp' => 0,
'kind' => 'participant']`. El `exp = 0` significa «sin caducidad» y se comprueba
**antes** que la caducidad, porque comparar `0 <= time()` como si fuera una fecha
tira la puerta en cada petición. La revalidación es `FormParticipant::existeEnFormulario()`
y su consecuencia es la que importa: **borrar a alguien de la lista le cierra la puerta
en la petición siguiente**, y no solo a quien aún no había entrado.

Los permisos de F4 no llevan `kind` y se siguen tratando como tokens, que es lo
único compatible con lo ya repartido.

### La puerta también es un formulario

El enlace se equivoca: se parte al pegarlo, se teclea en minúsculas, se le copia un
carácter de más. Sin `POST /f/{slug}/code`, un código con un carácter mal deja al
visitor en una pantalla de la que no puede salir —la puerta no se abre «a medias»
porque no hay forma de decir cuál de los 16 caracteres falló—, y su salida sería
escribirle al dueño.

- El **rate limit va antes de buscar el código** (10 intentos / 15 min) y por dos
  claves: formulario+IP y código. **Un intento fallido cuenta igual que uno bueno**: si
  solo sumaran los válidos, enumerar códigos sería gratis y esto sería un adorno.
- La **respuesta es uniforme**: inexistente, de otro formulario o con un carácter mal
  dicen exactamente lo mismo. Distinguirlos convierte la página en un oráculo.
- Es un **PRG**: un código válido redirige a `/f/{slug}?t=<código>`, y un refresco no
  vuelve a canjear nada. En un embed **no hay variante con `/embed`** porque el canje
  redirige el frame entero, que es lo que se quiere.
- La página devuelve **404** (no 403) si el formulario no es de lista: que tenga o no
  listado es información que no se le da a quien solo lo ve.

### La respuesta queda atribuida, y marcada en la lista

`Submission::create()` acepta un quinto argumento `?int $participantId` y
`FormParticipant::marcarRespondido()` va **en la misma transacción** que la respuesta.
Si la respuesta se guarda y la marca no, la lista de «quién NO ha respondido» enseña a
alguien que ya contestó y no hay forma de saber que las dos cosas se separaron. El
`UPDATE` es idempotente con estado objetivo (`WHERE responded_at IS NULL`): responder
dos veces no mueve la fecha de la primera, y **las dos respuestas se guardan** (es
fácil querer lo contrario por error: `roster` no es «una respuesta por persona»).

En `public`, `email` y `allowlist` la columna queda **NULL**: existe para atribuir
respuestas de un listado, no para marcar «alguien entró con un correo», que ya está en
`form_access_tokens`.

### Lo que sigue sin verse

Sigue sin haber `<option>` en el selector de modos (fase 6) ni pestaña de
participantes (fase 5): hoy un formulario en `roster` solo se alcanza si alguien edita
sus ajustes a mano o viene de una fase anterior. **La puerta está probada y no se ve.**

### Tres correcciones de la fase 3 que salieron al revisar la puerta

No eran de la puerta, pero se encontraron al mirar el importador con otros ojos, y las
tres son de las que no dan ningún error:

1. **`asegurarAtributos()` devolvía `null` a mitad y la transacción se confirmaba.**
   `Database::transaccionar()` confirma cuando la closure **devuelve**, y un `return`
   dentro de la closure es un `return` normal: una etiqueta de 250 caracteres (que no
   cabe en `label`) dejaba **creadas y confirmadas** las columnas anteriores del
   catálogo. El dueño veía el mensaje correcto y un catálogo que no había pedido. Ahora
   lanza, y el `catch` compara el mensaje y re-lanza cualquier otro `RuntimeException`
   para que un fallo de escritura no acabe contestando «renombra la columna».
2. **`omitidos` se medía con `count($problemas)`**, que está topado en 200: un archivo
   con 260 filas malas informaba «200 omitidos». La lista topeada es lo cómodo, pero
   el número es lo honesto, así que ahora se cuentan por separado.
3. **`atributos_nuevos` se deduplicaba por la cabecera cruda.** El lector hace `trim()`
   de cada celda pero **no** colapsa espacios interiores, así que `Sede Norte` y
   `Sede  Norte` llegaban distintas y el catálogo las pegaba en una: el informe
   anunciaba dos atributos nuevos donde había uno. Se deduplica por etiqueta
   **normalizada**.

Las tres tienen su test, y **tres mutaciones** las ponen rojas (volver al `null`, medir
el contador con la lista topada y deduplicar por la cabecera cruda).

**Suite completa: 912, 0 fallos. 9 mutaciones comprobadas** — quitar `roster` de
`RESTRICTED_MODES` (13 tests), scope del código sin `form_id`, revalidación de sesión
contra la lista, `participant_id` al guardar, `marcarRespondido()`, el cupo en la página
del código, el rate limit que solo cuenta los aciertos, el mensaje que distingue el
código ajeno y el corte del POST sin acceso. Las nueve se ponen rojas.

## Fase 5 — la pestaña de participantes — **HECHA**

Veinticuatro tests en `tests/unit/RosterPanelTest.php` (y uno más en el arnés de JS)
y **936 en total, 0 fallos**. Es la fase con menos riesgo del plan y la que más
sitios puede dejar «a medias sin decir nada», porque casi ninguno de sus fallos es un
error de PHP: un hueco repuesto con el id equivocado no falla, **no hace nada**; una
pestaña escondida por CSS no falla, **no aparece**; un formulario que conserva su
`action` no falla, recarga.

### Las dos zonas se pintan las dos, y ninguna viene escondida

Lo primero que se probó, antes de escribir el JS: **`.tab-pane` sin `.active` está
oculto por CSS**, así que el `tab` de Bootstrap habría hecho desaparecer el panel de
importar en el navegador que menos lo puede permitir. Aquí las dos zonas salen SIN
`hidden` y `dashboard.js` lo **añade** (`[data-vf-pane]`), con el estado en
`localStorage['vf:tab:<clave>']` **por pantalla** y sin guardar el `hidden` de arranque,
por la misma trampa que los grupos plegables. Sin JS, no hay nadie que lo ponga y se
ven las dos, una debajo de otra.

### Cinco huecos, y cada acción declara los suyos

`rosterStats`, `rosterList`, `rosterCatalog`, `rosterAddForm` y `rosterImportReport`.
Los markup salen de `RosterPanel::html()`, que pinta **los mismos partials** que la
ficha: con dos copias, la lista repuesta tras un borrado y la que se vería recargando
divergirían sin que nada fallara. Cada acción dice qué huecos cambiaron (`$cambia` en
`ok()`), y el alta **no repone `rosterAddForm`** a propósito: el repintado borraría lo
que el dueño tiene a medio escribir y el foco que devuelve `data-vf-clear` apuntaría a
un nodo que ya no está.

### La búsqueda viaja en la URL

Un GET a `/forms/{id}?q=…&page=…&sort=…#participantes`, no un fetch: así llega sin JS,
se puede compartir y el botón «atrás» deshace una búsqueda. Las acciones por fetch
guardan `rosterQ`/`rosterPage`/`rosterSort` en inputs ocultos para reponer la vista en
la que se estaba. Sin ellos, borrar a alguien con la lista ordenada por nombre la
repinta ordenada por cédula y en la primera página, y el cambio parece un fallo de la
operación —que sí lo fue.

### El informe llega por los DOS caminos

Con JS, en `html.rosterImportReport`, y **también con `ok:false`**: el informe de una
importación con filas malas es justo el caso en el que hay algo que reponer, y
`app.js` aplica los huecos **antes** de pintar el aviso. Sin JS, en
`Session::flashValue('roster_report')`, que consume `FormController::show()` con
`takeValue()` (un solo uso). Y solo en el camino nativo: guardado también en el camino
con JS, reaparecería en la siguiente carga de la ficha, que es cuando el dueño vuelve a
la pestaña «Lista».

Un `$error` con cero omitidas **no** se pinta como informe: un `.xlsx` ilegible no ha
guardado «las 0 filas del archivo», y ese bloque al lado de un mensaje que sí dice la
verdad dice exactamente lo que no pasó.

### Lo que seguía sin verse

Quedaba el interruptor de la fase 6: la puerta y el panel estaban probados y sin una
`<option>` que los alcanzara. Es lo que viene ahora, y por eso era la última en
aparecer en pantalla.

**Suite completa: 936, 0 fallos.**

## Fase 6 — exponer `roster` y elegir con qué se identifica — **HECHA**

Diecisiete tests en `tests/unit/RosterIdentifierTest.php` y **953 en total, 0 fallos**.
El riesgo era bajo y así fue, pero la fase destapó dos cosas que no estaban en el plan.

### La cuarta opción, y la última en la lista

`roster` va al final del desplegable, después de `allowlist`. El orden es el mensaje:
los tres modos de siempre se leen como «cada vez más cerrado» y el cuarto encaja ahí.
Con JS se elige y el formulario sigue abierto para configurarlo; sin JS se elige,
se guarda y al volver a abrir la ficha el selector del identificador ya está a la
vista. No es un control que exista solo en el navegador, pero necesita un guardado de
más, y eso está escrito en la vista.

### El identificador viaja con el modo, y por eso viene `disabled`

El segundo desplegable (cédula, indicador o correo) lo decide el **servidor**: el
`<select>` llega sin `hidden` en `roster` y con él en los otros tres, y `dashboard.js`
solo lo mantiene al día cuando se cambia de modo sin guardar. Oculto **con**
`disabled`, y no por descuido: un `<select>` escondido sigue mandando su valor, así que
un formulario en «Público» con la lista ya configurada para correo guardaría «cédula» —
la primera opción— en cada guardado de cualquier ajuste, y al volver al listado el
identificador elegido en su día habría desaparecido sin que nadie lo tocase. Con
`disabled` el campo no viaja, que es lo que ya decía `fromDashboardPost()` («si no
viene, no lo toques»).

### Cambiar el identificador es la operación peligrosa de la fase

`identifier_value` es la columna desnormalizada por la que la puerta busca, así que
cambiarla reescribe la identidad de **toda** la lista. Va en dos mitades porque el
fallo que evita es el peor posible en esta tabla —un formulario que deja de dejar
entrar a nadie sin decir nada—:

1. `FormParticipant::planDeReidentificacion()` calcula, **sin escribir**, cuántas
   personas se quedarían sin identidad y cuántos valores pasarían a compartirse.
2. El POST se **corta** con un 422 y un mensaje que dice qué corregir si hay alguna de
   las dos cosas.
3. Si el plan está limpio, ajustes y reescritura van en **una transacción**.

El plan es una pregunta de SQL y no de PHP, y la razón salió al escribir el test:
`UNIQUE (form_id, identifier_value)` es `utf8mb4_unicode_ci`, así que `V-1` y `v-1` —o
`Pérez` y `Perez`— son **la misma clave para el índice**. Agruparlos en PHP con un array
asociativo los daría por distintos, el plan diría «no hay repetidos» y el `UPDATE` se
comería un 1062 con un error de servidor en vez de un mensaje que dice qué corregir.
Al revés que la normalización: ahí lo que decide qué es el mismo dato es PHP, y aquí lo
que decide es el índice.

El mensaje nombra los valores repetidos, topados a cinco con el total al lado: si no
lo lee nadie, no arregla nadie.

### Los códigos no se tocan

La reescritura solo mueve `identifier_value`. `code` es la credencial que ya se
repartió —y lo que va en los enlaces de correo y en el QR—, así que quien entra por un
enlace viejo sigue entrando. El test compara el código antes y después.

### Dos cosas que se encontraron de paso

- **`Database::transaccionar()` no es estático.** Es un método de la instancia, y la
  primera versión de la fase 6 lo llamaba con `Database::` a secas.
- **El patrón que quita comentarios en `FormAccessTest` no era un patrón de
  comentarios.** `[/][/*]` es una clase de caracteres: significa «una barra o un
  asterisco», así que el recorte empezaba en la primera barra del fichero —la de
  `'use strict'`— y llegaba hasta el primer cierre de verdad. Tapaba por casualidad el
  comentario que ese test vigila, y al añadir código nuevo dejó de taparlo: el test
  falló en la fase 6 sin que el código vigilado hubiera cambiado. Ahora es `~` con la
  barra y el asterisco escapados, y el comentario explica por qué.

**Suite completa: 953, 0 fallos.**

## Fase 7 — filtrar la analítica por un atributo del listado — **HECHA**

El filtro es un `AND` más sobre `submissions.participant_id`, y por eso **no hay
`JOIN` nuevo**: las consultas de la analítica no cambian de forma, solo growen tres
condiciones. Lo que sí cambia es cuántas veces se repite la decisión.

### Un filtro, cuatro caminos

`AnalyticsController` lo resuelve **una vez** (`FormParticipant::filtroAtributo()`) y
el mismo array viaja a:

1. el detalle (`Submission::byFormFilteredPaginated()`),
2. los agregados de los gráficos (`Answer::valueCountsInForm()` y
   `nonEmptyCountsInForm()`, vía `FieldAggregator::forForm()`),
3. el gráfico por día (`Submission::dailyStats()`),
4. el CSV (`Submission::exportBatches()`).

Los dos primeros son el contrato de la fase —«el indicador marca «38 respuestas»
y la tabla de abajo tiene 12»— y los otros dos son una decisión que no estaba escrita:
un botón que descarga un archivo **distinto** de lo que se está viendo es un fallo
que solo se descubre abriendo el archivo, así que el filtro viaja también al CSV. La
columna del CSV no se añade: el filtro es de las respuestas que entran, no una
columna más.

### El catálogo se pide SIEMPRE, aunque no haya filtro

La primera versión lo pedía solo con filtro puesto, y el filtro quedaba **muerto
desde el primer día**: no hay ningún botón ni enlace que ponga `atr_key` en la URL,
así que los desplegables nunca se pintaban y no había forma de tener el primero. La
vista necesita tres cosas y son tres variables distintas:

- `$catalogo` — **siempre**: es la lista de opciones del desplegable de atributos.
- `$atrClave` — la fila del catálogo de la clave **pedida**, aunque no haya valor:
  sin esto, elegir «Sede» y guardar devuelve la pantalla a «Todo el listado» y el
  filtro se deselige solo.
- `$atr` — el filtro **resuelto** (`null` sin valor): esto es lo que filtra, y lo
  único que decide las columnas del detalle.

Elegir el atributo y filtrar por un valor son **dos pasos**, y el segundo no existe
si el primero se pierde. `atributoDeCatalogo()` es la puerta que los separa.

### Solo se filtra por lo que el dueño definió

La lista blanca es el catálogo, y la cédula, el nombre y el correo **no pueden
estar en él** porque el importador trata esas cabeceras como columnas fijas
(`COLUMNA_IDENTIFICADOR`). Por eso `atr_key=cedula` en la URL no filtra por la
cédula de nadie: `filtroAtributo()` devuelve `null` y sale la pantalla entera. Un
valor que **no** existe en ese atributo, en cambio, **no se descarta**: se queda el
filtro y la pantalla sale vacía, que es la respuesta honesta a un valor que se borró
al quitar una columna del catálogo.

### Las columnas del detalle

Una columna por atributo del catálogo, y **solo con filtro puesto**: son los datos
de quién es quién y nadie los ha pedido ver sin haber filtrado. El valor que falta
se pinta como `—` y no como un hueco, que en un `table-hover` no se lee. La
identidad no sale nunca por aquí: ni la cédula ni el nombre aparecen como columna ni
como celda, también para el admin.

### El detalle es una consulta más

`atributosDe()` trae los atributos de los 15 participantes de la página en **una**
consultada, en tandas de 5000 como `withAnswersByIds()`: `attributesOf()` por fila
serían 15 consultas por página, que es el N+1 que la fase 3 de la analítica vino a
quitar.

### Lo que se encontró de paso

- **`Answer` mezclaba `?` con `:nombrado`.** Con `ATTR_EMULATE_PREPARES = false`,
  añadir el filtro al WHERE obligaba a unificar los marcadores, porque PDO no deja
  mezclarlos en la misma sentencia (HY093). Ahora `marcadoresDeCampos()` genera los
  `:val_f0, :val_f1…` y `queryField()` usa `:field_id` en vez de `?`.
- **El total del CSV es `countByFiltered()`**, así que el envelope del archivo lleva
  el `total` del grupo y no el del formulario: si solo se hubiera filtrado el
  `foreach`, el archivo tendría una fila y su envoltorio anunciaría cinco.

**Suite completa: 970, 0 fallos.**

## Fase 8 — agrupar por atributo, con techo — **HECHA**

La fase 7 dejó el filtro (una fila = un valor). Esta es la pregunta contraria: **¿se
parece la gente de cada grupo?** Solo se agrupa cuando hay un atributo **elegido** y
**no** hay valor filtrado: comparar los grupos de un atributo mientras se está
mirando uno de ellos no dice nada — una columna con los datos de todos y el resto en
cero — y quien quiere ese detalle lo tiene en la tabla de abajo.

### Dos consultas fijas, no una por grupo

El plan inicial hablaba de una consulta con `key_name, value, field_id, value,
COUNT(*)`. Se acabó haciendo **dos**, y la razón es el techo:

- `numericStatsByGroup()` trae, por grupo y campo, `COUNT`, `SUM`, y los tres cubos
  del NPS (`SUM(CASE …)`). El promedio sale de `SUM/COUNT` en SQL y **no** de la
  media de las medias: con un grupo de 2 personas y otro de 200 saldría otra cosa, y
  no con un error sino con un número plausible.
- `topValueCountsByGroup()` trae los `TOP_VALORES` más repetidos por grupo y campo,
  con `ROW_NUMBER()` + `SUM() OVER (PARTITION BY …)`. El `total` de la ventana es el
  de **todas** las respuestas del grupo, no el de las que han sobrevivido al tope:
  con un recorte, el porcentaje de la celda sería una proporción de lo enseñado y
  saldría **más alto**, que es la clase de número que parece un dato.

Las dos son el mismo número de consultas que ya hacía `forForm()`, así que la
decisión de `FieldAggregator.php:11-34` sigue valiendo. **Las preguntas se eligen
antes de consultar** (`FILAS_MAX` primeras, comparables y no marcadas como PII): si
el techo se aplicara después, serían dos consultas de 200 ids.

### El techo, y por qué el «resto» es la resta

`FormParticipant::valoresConConteo()` trae los valores **con su gente** y el total
completo, sin `LIMIT`: el cubo de «resto» es `total − lo que ocupa cada columna`, y
con solo los 200 valores del desplegable un atributo de 800 daría un resto que en
realidad es la cola del `LIMIT`. `valuesOf()` (el desplegable del filtro) topa en PHP
con ese mismo array.

El resto **no se pinta si no queda nadie**: con 8 valores exactos la resta da 0 y una
columna en cero diría que hay gente sin el atributo.

Y en SQL el resto es `CASE WHEN pa.value IN (…) THEN pa.value ELSE NULL END`, no un
centinela de texto tipo `__resto__`: un valor puede ser cualquier cosa que venga en
un `.xlsx`, y `NULL` no puede colisionar con nada. `pa.form_id = s.form_id` va **en
el `ON`** y no en el `WHERE`, porque es la condición de la unión.

### Qué preguntas se comparan, y cuáles no

| Tipo | En la comparación | Por qué |
|---|---|---|
| `number`, `scale`, `range`, `star` | sí | promedio y `n` por grupo |
| `nps` | sí | NPS y sus tres cubos por grupo |
| `select`, `radio` | sí | valor más repetido y proporción |
| `checkbox` | **no** | lo guardado es la combinación entera en JSON: «el valor más repetido» sería la combinación, no lo que eligió la gente. Se podría descifrar en PHP, pero el recorte por combinaciones perdería las opciones que solo salen en combinaciones minoritarias y su porcentaje saldría mal |
| `matrix`, `ranking` | **no** | el valor es un JSON con la respuesta entera: el «valor más repetido» sería literalmente la respuesta |
| `text`, `textarea`, `email`, `date`, `file` | no | no tienen métrica comparable |

Las marcadas como PII **no se consultan** si quien mira no tiene `responses.pii` (la
misma lista que decide el resto de la pantalla). El test comprueba los dos lados: sin
permiso la pregunta no sale, con permiso sí.

### Lo que se encontró de paso

- **El segundo enlace de CSV se armaba a mano** con `from`/`to` y se olvidaba del
  filtro por atributo: un archivo que no corresponde a lo que hay en pantalla, y solo
  se nota al abrirlo. Ahora los dos usan el mismo `$qsAnalytics`. Es una regresión de
  la fase 7 que salió al leer la vista para la fase 8, y por eso el test vive en el
  fichero de la fase 8: **«se arregló y se comprobó» en una superficie no es nada
  sobre la otra.**
- **La primera columna va con `sticky`.** Con 9 columnas el `.table-responsive` la
  desplaza y el nombre de la pregunta es la única columna que tiene que estar a la
  vista; sin fondo, las celdas que pasan por debajo se ven a través del texto.

**Suite completa: 983, 0 fallos.**

## Fase 9 — quién respondió, con la puerta de `responses.pii` — **HECHA**

La atribución se guardaba desde la fase 4 (`submissions.participant_id`, escrito en la
misma transacción que la respuesta) y **no se veía en ninguna parte**: el listado de
respuestas tenía #, fecha, IP y acciones, y el detalle tenía fecha, IP y navegador. La
ficha marcaba «respondió» por participante, así que el dato existía en dos sitios y no
se podía cruzar respuesta → persona.

### La tensión con la analítica, y por qué aquí sí se ve

Para la analítica se decidió que cédula, nombre y correo **no aparecen ni para el
admin** (la decisión está en la tabla de arriba): esa pantalla existe para agrupar, y
un nombre en un gráfico no agrupa nada. El detalle de una respuesta es lo contrario:
existe para leer *esa* respuesta, y quién la envió es parte de lo que se viene a ver.
Por eso la puerta es `responses.pii` —el permiso que ya significa «puedes leer datos
personales en claro»— y no uno nuevo que nadie tendría, ni `responses.view_own`, que
decide el alcance pero no la lectura en claro.

### Sin permiso no hay columna, y no una columna enmascarada

Con `Pii::canView()` falso **no se pinta la columna**: ni con `[oculto]`, ni con «—».
Dos razones, y las dos importan:

- En un formulario que **no** es de listado, la columna sería de «—» en todas las
  filas siempre. Ruido en el 90 % de las instalaciones.
- Una celda con `[oculto]` en cada fila no dice nada que el aviso no diga. El aviso sí
  lo dice, y además **explica dónde está la información**: en la ficha, la lista marca
  quién respondió. Un formulario con lista que no dice quién respondió parece un
  fallo, y no lo es.

La columna se decide por el **formulario y el permiso**, nunca por si esa página casó
con alguien: si no, al paginar una lista de mil aparecería y desaparecería por
casualidad.

### `identidadesDe($formId, $ids)`: el `form_id` en el WHERE

Es el hermano de `atributosDe()` para el caso de la atribución, y por eso va troceado
a 5000 igual. Los ids salen de las filas de `submissions` de **un** formulario, así
que el `form_id` del WHERE nunca descarta nada — y es la mitad de la autorización, como
en `FormAccess::findValidToken()`: un id de otra lista no resuelve identidad aunque la
fila exista. Los `null` (una respuesta sin atribución) se filtran antes de consultar,
porque `intval(null)` es 0 y un `IN (0)` hace trabajo de más.

`identidadDe()` devuelve **datos, no markup** (`principal`, `nombre`, `correo`), y con
identificador `correo` el `correo` sale vacío a propósito: el `identifier_value` ya es
esa dirección y repetirla debajo sería la misma dirección dos veces en la misma celda.

### La columna se comprueba por estructura

El `<td>` va en la fila dentro del mismo `if` que el `<th>` del encabezado, y el test
cuenta las celdas de cada `<tr data-vf-row>` (5 con columna, 4 sin ella). Es
exactamente el fallo que el proyecto ya se encontró dos veces en el builder: una
columna en el encabezado que no llega a las filas se ve «casi bien» y no da ningún
error. Quitar el `<td>` de las filas dejando el `<th>` pone el test en rojo.

### Lo que NO cambia: los archivos exportados

Ni el CSV ni el JSON llevan el nombre, la cédula, el identificador **ni el
`participant_id`** (que tampoco estaba: `exportSubmissionRow()` solo mete id, fecha,
IP, user agent y answers). Es una decisión, no un olvido: un archivo lo descarga
cualquiera que tenga el botón, y el CSV además **enmascara siempre** porque su puerta
es `forms.export_own`, no `responses.pii`. Poner un nombre ahí daría el «canal
limpio» que F1 cerró: lo que se ve en pantalla es lo que sale en el JSON, y en un
archivo eso va en la otra dirección. La unión sigue disponible para quien tenga los
dos ficheros.

**Suite completa: 996, 0 fallos.**

## Fase 10 — el correo de invitación, opt-in y por tandas — **HECHA**

Con la lista cargada, el dueño tenía una operación manual: abrir una hoja, copiar la
columna de correos en su cliente de correo y mandar uno por uno. Para una lista de 400
personas eso es media mañana y, sobre todo, es **imposible de auditar**: nadie sabe a
quién se le escribió y a quién no, así que el «a V-237 no le llegó nada» del correo es
siempre el mismo correo.

### Una columna que dice cuándo, no solo que

`form_participants.invited_at DATETIME NULL` (migración `0015`). Podría haber sido un
`TINYINT(1) invited`: al botón le basta «¿ya le escribí?». Lo que cambia con una fecha
es la pregunta que se le hace a la lista dos meses después —«¿a quién le escribí la
semana pasada?»—, y además es lo que permite un informe honesto sin una segunda tabla
de historial. `NULL` de verdad, no `DEFAULT '0000-00-00'`: `IS NOT NULL` sobre la fecha
cero contaría a todo el mundo como invitado desde el primer día y el botón no mandaría
nada nunca.

El `invited_at` va con `AFTER responded_at` en la migración para que una base migrada y
una limpia salgan con las columnas en el mismo orden. Es el mismo criterio con el que
una comparación real de `information_schema` encuentra las diferencias que ninguna
prueba de esquema del runner ve.

**Y se borra cuando el correo cambia.** La marca dice «a esta dirección se le mandó el
enlace», así que una dirección nueva no puede heredar el acta de la anterior:
`update()` lleva `invited_at = IF(:cambioCorreo = 1, NULL, invited_at)` y la comparación
de las dos direcciones va **en minúsculas** (el `UPDATE` guarda `mb_strtolower`, y si solo
se normalizara una de las dos, escribir la misma dirección en mayúsculas borraría la
marca). El filtro es **el correo y solo el correo**, y eso es lo que impide que
**reimportar el mismo `.xlsx`** deshaga las invitaciones: la importación en modo
fusionar llama a `update()` fila por fila, así que si la marca se borrara en cada
escritura bastaría con volver a subir el archivo para que las 400 invitaciones
volvieran a salir. Renombrar a alguien no deshace nada, porque el correo no cambió.

### Las cinco condiciones de entrada

1. **El formulario es de lista**: `rosterForm()` (sesión + `canEdit` + modo `roster`).
   La ruta es un `POST` con `auth` y `csrf`, y **no** lleva `post-max` porque no recibe
   archivo (el `post-max` del fill existe por los adjuntos, no por este botón).
2. **El identificador es `correo`**. Con `cedula` o `indicador` la dirección es un dato
   opcional de la lista, no la identidad con la que se entra, así que **no hay botón**:
   el enlace se reparte desde la columna «Código», que ya está en pantalla. Es la misma
   razón que en la fase 6: la invitación es para *quien tiene que responder*, y con
   cédula eso lo decide una persona.
3. **Hay SMTP configurado**: `Mailer::isConfigured()` va primero, porque `Mailer::send()`
   lanza con `MAIL_HOST` vacío y eso se leería como «el servidor de correo falló»
   cuando lo que falta es configuración.
4. **Queda alguien pendiente**: `invited_at IS NULL AND responded_at IS NULL AND
   correo <> ''`, ordenados por `id` para que dos tandas seguidas manden a la misma
   gente en el mismo orden. `responded_at IS NULL` porque a quien ya respondió no se le
   escribe: se le puede escribir otra vez desde el correo, pero no desde el botón.
5. **El dueño pulsa.** Importar una lista **nunca** manda correo (400 correos desde un
   `POST` es un accidente esperando), y tampoco hay envío al crear una fila a mano.

### Un botón, tres decisiones, y por qué hay un tope

`Mailer::send()` lanza y no devuelve nada, así que 400 personas son 400 iteraciones sin
límite en una sola petición. De ahí:

- **50 por tanda** (`INVITE_POR_TANDA`). El botón dice cuántos quedan
  (`Enviar invitaciones (37)`), de modo que el tope es información para el dueño y no
  un fallo: si quedan 87, la primera tanda manda 50 y el botón vuelve a decir 37.
- **Se para tras 3 fallos seguidos** (`INVITE_FALLOS_SEGUIDOS`). Un SMTP caído produce
  400 excepciones idénticas en cuatro segundos y un `ok:false` que solo dice «algo
  falló»; con el tope de fallos se para y el mensaje dice cuántos salieron y que los
  fallidos **siguen en la lista**. Un fallo suelto **no** corta la tanda, porque de
  verdad pasa (una dirección que el cliente acepta y el servidor no) y cortar por eso
  castiga al resto.
- **Se marca solo lo que se mandó.** `markInvited()` va **después** del `send()` con
  éxito, así que nadie que no recibió el correo se queda marcado como invitado y
  desaparece de la cola. El fallo no es «se pierde el correo»: es «el correo sigue
  pendiente», que es justo lo que permite reintentar.

### El botón, el hueco y el fallo en el sitio

`rosterInvites` es el **sexto** slot de `RosterPanel`, junto a `rosterStats`,
`rosterList`, `rosterCatalog`, `rosterAddForm` y `rosterImportReport`. Lo reponen las
acciones que cambian el conjunto de pendientes —alta, edición, borrado, vaciado,
importación y el propio envío— y **no** `destroyAttribute`, que no toca correos.

El formulario del botón **no declara `data-vf-error`**, y es a propósito. En un
`ok:false` por fallos el servidor devuelve `html` con `invites` repintado, de modo que
un `data-vf-error` dentro del slot se quedaría **desacoplado** del nodo que lo contiene
y no se vería nunca. El resultado de la tanda va al aviso flotante (`vfToast`), que es
donde ya se ven los avisos del panel.

`invited_at` no aparece en la lista ni en las exportaciones: es una marca operativa de
quien escribió a quien, no una respuesta ni un atributo del listado. Quien gestiona la
lista la ve en la insignia de los números (`37 invitations enviadas`); las exportaciones
siguen siendo lo que exportaban antes, sin una columna nueva.

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
| 3 | **Hecha** — 886 tests |
| 4 | **Hecha** — 912 tests |
| 5 | **Hecha** — `b9f131e`, 936 tests |
| 6 | **Hecha** — `2f715db`, 953 tests |
| 7 | **Hecha** — 970 tests |
| 8 | **Hecha** — 983 tests |
| 9 | **Hecha** — 996 tests |
| 10 | **Hecha** — 1012 tests |
| 11 | Pendiente |
