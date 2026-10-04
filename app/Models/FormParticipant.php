<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Database;
use App\Services\FormSettings;

/**
 * Listado de participantes (fase 3).
 *
 * Es la fila de **una persona invitada**, y se parece a `FormAccess` (que también
 * es una lista de gente con acceso) en todo menos en lo que sostiene:
 *
 *  - **`FormAccess` guarda un HASH de la credencial**, porque su credencial es un
 *    token de un solo uso por correo. Aquí la credencial es un `code` corto que la
 *    persona **teclea** y el dueño reparte en persona, y tiene que estar en la
 *    fila: es lo que se exporta para repartirlo y lo que se imprime. Por eso aquí
 *    no hay hash, y ese es el mismo motivo por el que `code` no es un id: se dice
 *    en voz alta y se teclea, y un `id=7` adivinado es un acceso de más.
 *  - **`FormAccess` son emails**, y el email es su identidad. Aquí hay tres
 *    candidatos a identidad (`cedula`, `indicador`, `correo`) y el dueño elige uno,
 *    así que la columna se llama `identifier_value` y es **denormalizada**: los
 *    tres campos existen por separado porque se muestran y se filtran por ellos,
 *    pero la puerta del fill busca siempre en `identifier_value`, que es la única
 *    indexada. Escribir un `CASE` sobre tres columnas serían tres índices y el
 *    mismo código tres veces.
 *  - **`FormAccess` es una lista de emails y ya está.** Aquí la lista tiene
 *    atributos libres (la columna `sede` de un `.xlsx`), y por eso hay catálogo.
 *
 * Motivos por los que el alta devuelve un motivo y no un `false` seco: la
 * importación necesita **explicar la fila que no entró**, y un `false` obliga a
 * adivinar entre «duplicado», «identificador vacío» y «atributo que no existe».
 */
class FormParticipant
{
    public const OK = 'ok';

    /** El `identifier_value` ya está en otro participante del MISMO formulario. */
    public const MOTIVO_DUPLICADO = 'identificador_duplicado';

    /** La columna elegida como identificador vino vacía. */
    public const MOTIVO_VACIO = 'identificador_vacio';

    public const MOTIVO_LARGO = 'identificador_largo';

    /** Una `key_name` de `data` no está en el catálogo del formulario. */
    public const MOTIVO_ATRIBUTO = 'atributo_desconocido';

    /** Un valor de atributo pasa de `VARCHAR(255)`. */
    public const MOTIVO_VALOR_LARGO = 'valor_atributo_largo';

    public const MOTIVO_CORREO = 'correo_invalido';

    /** Columna de la tabla de la que sale cada identificador. */
    public const COLUMNA_IDENTIFICADOR = [
        'cedula'    => 'cedula',
        'indicador' => 'indicador',
        'correo'    => 'correo',
    ];

    /** Tope de `identifier_value`, que es `VARCHAR(190)`. */
    public const MAX_IDENTIFICADOR = 190;

    /** Tope de un valor de atributo, que es `VARCHAR(255)`. */
    public const MAX_VALOR_ATRIBUTO = 255;

    /**
     * Alfabeto del `code`: sin `0/O`, `1/I/L` ni `U/V`.
     *
     * Es un código que se **lee en voz alta y se teclea**, y esos cuatro pares son
     * exactamente los que una lista deReserved se equivoca al transcribir («O» por
     * cero). Con 16 caracteres de 31 sale a unos 79 bits, que es de sobra para una
     * lista de miles.
     */
    private const ALFABETO = '23456789ABCDEFGHJKMNPQRSTWXYZ';

    private static function db(): Database
    {
        return Database::getInstance(Config::db());
    }

    // =====================================================================
    // Identificador
    // =====================================================================

    /**
     * Identificador configurado en los ajustes, ya validado contra la lista.
     *
     * Se resuelve **una vez** en la entrada (importar, alta manual) y luego se pasa
     * el string a las filas: leer `Form::settings()` dentro de un bucle de 3000
     * filas son 3000 lecturas de settings, y el valor no puede cambiar a mitad de
     * una importación porque la petición es una sola.
     */
    public static function identifierOf(array $settings): string
    {
        $id = (string) ($settings['access']['identifier'] ?? 'cedula');

        return in_array($id, FormSettings::ACCESS_IDENTIFIERS, true) ? $id : 'cedula';
    }

    public static function identifierFor(int $formId): string
    {
        return self::identifierOf(Form::settings($formId));
    }

    /**
     * El valor de `identifier_value` de una fila, ya normalizado.
     *
     * El correo va en **minúsculas** por el mismo motivo que en `FormAccess`: es la
     * clave de la puerta, y `Ana@X.com` y `ana@x.com` tienen que ser la misma
     * persona. El tipo de columna ya es insensible a mayúsculas, pero normalizar en
     * PHP deja la fila y la query del gate en la misma forma y no depende de cómo
     * esté creada la tabla.
     */
    public static function identifierValue(string $identificador, string $cedula, string $indicador, string $correo): string
    {
        $crudo = match ($identificador) {
            'indicador' => $indicador,
            'correo'    => $correo,
            default     => $cedula,
        };
        $valor = self::normalizar($crudo);

        return $identificador === 'correo' ? mb_strtolower($valor) : $valor;
    }

    /**
     * Recorta, colapsa espacios internos y quita caracteres de control.
     *
     * Los espacios internos se colapsan porque la collation de la tabla **no** los
     * ignora: `V 1` y `V1` serían dos participantes y dos filas. Los caracteres de
     * control se quitan porque llegan del `.xlsx` y no son un dato: un `\x01` pegado
     * en un nombre no es información, es ruido que además no se puede buscar.
     */
    public static function normalizar(string $valor): string
    {
        // Primero se limpian los controles, y después se colapsan los espacios:
        // al revés, un `\x01` entre dos palabras dejaría dos espacios que
        // encontrarían como separador.
        $valor = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $valor) ?? $valor;
        $valor = preg_replace('/\s+/u', ' ', $valor) ?? $valor;

        return trim($valor);
    }

    /**
     * Participantes a los que cambiar a `$nuevo` les dejaría la identidad vacía.
     *
     * Es lo que hace que cambiar el identificador **no sea gratis**: un formulario
     * cuya lista tiene la cédula rellena pero el indicador vacío se queda sin forma
     * de identificar a nadie en el identificador nuevo, y seguir aceptando el
     * cambio dejaría una lista que no deja entrar a nadie. Se cuenta aquí y lo
     * comprueba quien llama antes de guardar el ajuste.
     *
     * Es la versión SQL (un `COUNT`, sin traer filas) y por eso no mira lo demás
     * que el cambio puede romper: para eso está `planDeReidentificacion()`, que es
     * la que usa el guardado de ajustes. Esta se queda para lo que solo necesita
     * el número.
     */
    public static function sinIdentificadorPara(int $formId, string $nuevo): int
    {
        $columna = self::COLUMNA_IDENTIFICADOR[$nuevo] ?? null;
        if ($columna === null) {
            return 0;
        }

        $row = self::db()->fetch(
            'SELECT COUNT(*) AS c FROM form_participants
             WHERE form_id = :fid AND TRIM(' . $columna . ') = :vacio',
            ['fid' => $formId, 'vacio' => '']
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Qué pasaría al cambiar el identificador de un formulario a `$nuevo`, **sin
     * escribir nada**.
     *
     * Es la pregunta que se hace antes de aceptar el cambio, y tiene tres partes
     * porque el fallo que evita es un **formulario que deja de dejar entrar a
     * nadie** sin decir nada:
     *
     *  1. `sin_identidad`: personas a las que el identificador nuevo les quedaría
     *     vacío. Es el mismo caso de `sinIdentificadorPara()`.
     *  2. `repetidos`: valores que **dos** personas pasarían a compartir. El
     *     `UNIQUE (form_id, identifier_value)` es la frontera de la puerta, así que
     *     un duplicado revienta con un 1062 a mitad de la reescritura y se lleva por
     *     delante a las que ya estaban bien. Dos personas con el mismo correo —una
     *     errata en el `.xlsx`, dos filas copiadas del mismo correo— no es raro.
     *  3. `a_cambiar`: cuántas filas reescribiría `reidentificar()`, para que quien
     *     llama sepa si tiene algo que hacer. Las que **no** tienen el dato nuevo
     *     quedan fuera del número porque no se van a reescribir: cambian de
     *     identidad a una cadena vacía, que es justo lo que `sin_identidad` impide
     *     antes de llegar aquí.
     *
     * Los repetidos se cuentan **en SQL y no en PHP** por una razón que salió al
     * escribirlo: `UNIQUE (form_id, identifier_value)` no es sensible a mayúsculas
     * ni a acentos (`utf8mb4_unicode_ci`), así que `V-1` y `v-1` —o `Pérez` y
     * `Perez`— son **la misma clave para el índice**. Agruparlos en PHP con un
     * array asociativo los daría por distintos, el plan diría «no hay repetidos» y
     * el `UPDATE` se comería un 1062 con un error de servidor en vez de un mensaje
     * que dice qué corregir. Al revés que `normalizar()`: ahí la normalización es
     * de PHP porque decide qué es el mismo dato, y aquí lo que decide es el índice.
     *
     * Que el grupo se quede con una u otra grafía («V-1» o «v-1») lo decide el
     * servidor, y da igual: lo que avisa es que están repetidas.
     *
     * `repetidos` viene topado a cinco valores con el total al lado
     * (`repetidos_cuento`): el mensaje tiene que ser corto —si no no lo lee nadie—
     * y la lista completa está a una edición de distancia.
     *
     * @return array{sin_identidad:int, repetidos:list<string>, repetidos_cuento:int, a_cambiar:int}
     */
    public static function planDeReidentificacion(int $formId, string $nuevo): array
    {
        $vacio = ['sin_identidad' => 0, 'repetidos' => [], 'repetidos_cuento' => 0, 'a_cambiar' => 0];

        $columna = self::COLUMNA_IDENTIFICADOR[$nuevo] ?? null;
        if ($columna === null) {
            return $vacio;
        }

        $sinIdentidad = $aCambiar = 0;
        $repetidos = [];
        $cuentaRepetidos = 0;

        try {
            $vacias = self::db()->fetch(
                'SELECT COUNT(*) AS c FROM form_participants
                 WHERE form_id = :fid AND TRIM(' . $columna . ') = :vacio',
                ['fid' => $formId, 'vacio' => '']
            );
            $sinIdentidad = (int) ($vacias['c'] ?? 0);

            $distintas = self::db()->fetch(
                'SELECT COUNT(*) AS c FROM form_participants
                 WHERE form_id = :fid AND TRIM(' . $columna . ') <> \'\'
                   AND BINARY identifier_value <> BINARY ' . $columna,
                ['fid' => $formId]
            );
            $aCambiar = (int) ($distintas['c'] ?? 0);

            foreach (self::db()->fetchAll(
                'SELECT ' . $columna . ' AS valor, COUNT(*) AS c FROM form_participants
                 WHERE form_id = :fid
                 GROUP BY ' . $columna . ' HAVING c > 1
                 ORDER BY ' . $columna . ' ASC',
                ['fid' => $formId]
            ) as $grupo) {
                $repetidos[] = (string) ($grupo['valor'] ?? '');
                $cuentaRepetidos += (int) ($grupo['c'] ?? 0);
            }
        } catch (\PDOException $e) {
            // Una lista anterior a la migración 0014 no tiene la columna y este
            // guardado no puede ser el que se entere con un error de SQL. Se trata
            // como «no hay nada que reescribir», que es lo que había antes de que
            // existiera el identificador.
            if (self::esDuplicado($e) || str_contains($e->getMessage(), 'form_participants')) {
                return $vacio;
            }
            throw $e;
        }

        return [
            'sin_identidad'   => $sinIdentidad,
            'repetidos'       => array_slice($repetidos, 0, 5),
            'repetidos_cuento' => $cuentaRepetidos,
            'a_cambiar'       => $aCambiar,
        ];
    }

    /**
     * Reescribe `identifier_value` de toda la lista con la columna que ahora es la
     * identidad. **Solo la denormalizada**: `cedula`, `indicador` y `correo` no se
     * tocan, y el `code` menos, porque es la credencial que ya se repartió.
     *
     * Es un `UPDATE` y no un bucle de filas porque las tres columnas ya están
     * normalizadas —las normaliza `identifierValue()` en cada alta y cada
     * edición— y `correo` además se guarda en minúsculas, así que copiar la columna
     * ES el valor nuevo. Un bucle de 400 filas daría el mismo resultado a un coste
     * que no se ve en una pantalla de guardado.
     *
     * El `WHERE` compara en **binario** y no con la collation de la tabla: con
     * `utf8mb4_unicode_ci` una fila que solo se diferencie en mayúsculas se
     * consideraría igual y no se reescribiría, y quedaría guardando el valor viejo
     * por puro capricho. Da igual para la puerta (que también compara con la
     * collation) y no da igual para lo que se ve en la lista.
     *
     * **Quien llama tiene que haber comprobado antes el plan**: sin duplicados
     * (`planDeReidentificacion()`), este `UPDATE` puede reventar con un 1062 y
     * dejarlo a medias. Es lo mismo que el `UNIQUE` de cualquier otra escritura.
     *
     * @return int cuántas filas han cambiado de verdad
     */
    public static function reidentificar(int $formId, string $nuevo): int
    {
        $columna = self::COLUMNA_IDENTIFICADOR[$nuevo] ?? null;
        if ($columna === null) {
            return 0;
        }

        return self::db()->execute(
            'UPDATE form_participants SET identifier_value = ' . $columna . '
             WHERE form_id = :fid AND BINARY identifier_value <> BINARY ' . $columna,
            ['fid' => $formId]
        );
    }

    // =====================================================================
    // Catálogo de atributos
    // =====================================================================

    /**
     * Catálogo de atributos de un formulario, en el orden en que se crearon.
     *
     * Es la **frontera de seguridad** del asunto: `key_name` es lo único que viaja
     * al SQL y solo puede ser uno de los de aquí. Un `key_name` del POST que no
     * esté en esta lista se rechaza, y no se «limpia» ni se normaliza para que
     * casara: sería abrir un atributo por cada clave arbitraria que se mande.
     *
     * @return list<array{id:int,key_name:string,label:string,position:int}>
     */
    public static function catalogFor(int $formId): array
    {
        return self::db()->fetchAll(
            'SELECT id, key_name, label, position FROM form_participant_fields
             WHERE form_id = :fid ORDER BY position ASC, id ASC',
            ['fid' => $formId]
        );
    }

    /**
     * Resuelve una cabecera escrita por el dueño a un atributo del catálogo,
     * creándolo si hace falta, y devuelve la `key_name`.
     *
     * Reusa el existente y lo dice, y eso no es una comodidad: el `UNIQUE` de
     * `label` hace que `Gerencia` y `Gerencia ` no puedan ser dos atributos, así
     * que la segunda columna que llega con un espacio de más **tiene** que reusar
     * la primera o el `INSERT` revienta con un error de clave duplicada en mitad de
     * una importación. Devolver `null` significa «no se pudo ni reusar ni crear».
     */
    public static function ensureAttribute(int $formId, string $cabecera): ?string
    {
        $label = self::normalizar($cabecera);
        if ($label === '' || mb_strlen($label) > 190) {
            return null;
        }

        $existente = self::db()->fetch(
            'SELECT key_name FROM form_participant_fields WHERE form_id = :fid AND label = :label',
            ['fid' => $formId, 'label' => $label]
        );
        if ($existente !== null) {
            return (string) $existente['key_name'];
        }

        // Rara vez, pero posible: la etiqueta existe con OTRA capitalización y la
        // comparación no la encuentra si la collation de la tabla fuera binaria.
        // El `INSERT` con reintentos resuelve el caso sin abrir otra consulta.
        $key = self::claveUnica($formId, self::slug($label));
        for ($intento = 0; $intento < 5; $intento++) {
            try {
                self::db()->execute(
                    'INSERT INTO form_participant_fields (form_id, key_name, label, position)
                     VALUES (:fid, :key, :label, :pos)',
                    [
                        'fid'   => $formId,
                        'key'   => $key,
                        'label' => $label,
                        'pos'   => self::siguientePosicion($formId),
                    ]
                );

                return $key;
            } catch (\PDOException $e) {
                if (!self::esDuplicado($e)) {
                    throw $e;
                }
                // El duplicado puede ser el `label` (otra capitalización) o la
                // propia `key_name`. Si es el label, se reusa la fila que hay.
                $porLabel = self::db()->fetch(
                    'SELECT key_name FROM form_participant_fields WHERE form_id = :fid AND label = :label',
                    ['fid' => $formId, 'label' => $label]
                );
                if ($porLabel !== null) {
                    return (string) $porLabel['key_name'];
                }
                $key = self::claveUnica($formId, self::slug($label));
            }
        }

        return null;
    }

    /**
     * Slug estable a partir de una etiqueta.
     *
     * Se **transliteran** los acentos en vez de borrarlos: `Sede` y `Sede Ñ` dan
     * claves distintas sin ambigüedad, mientras que quitar la `ñ` a secas haría que
     * «Diseño» y `Diseno` colisionaran en la misma clave y el segundo valor
     * machacaría al del primero. El prefijo `a` evita además que la clave empiece
     * por un dígito.
     */
    public static function slug(string $label): string
    {
        $trans = strtr(
            $label,
            [
                'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
                'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u',
                'ñ' => 'n', 'Ñ' => 'n', 'ç' => 'c', 'Ç' => 'c',
            ]
        );
        $trans = preg_replace('/[^A-Za-z0-9]+/', '_', $trans) ?? '';
        $trans = trim($trans, '_');
        if ($trans === '') {
            return 'attr';
        }
        if (ctype_digit($trans[0])) {
            $trans = 'a' . $trans;
        }

        return mb_substr(mb_strtolower($trans), 0, 64);
    }

    /** Quita un atributo del catálogo y sus valores. */
    public static function deleteAttribute(int $formId, string $keyName): void
    {
        $db = self::db();
        $db->beginTransaction();
        try {
            $db->execute(
                'DELETE FROM form_participant_attrs
                 WHERE form_id = :fid AND key_name = :k',
                ['fid' => $formId, 'k' => $keyName]
            );
            $db->execute(
                'DELETE FROM form_participant_fields WHERE form_id = :fid AND key_name = :k',
                ['fid' => $formId, 'k' => $keyName]
            );
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    // =====================================================================
    // Lectura
    // =====================================================================

    /**
     * Filtro de búsqueda y sus parámetros.
     *
     * Vive en UN sitio porque lo usan `allForForm()` y `countForForm()`, y porque
     * los dos tienen que buscar **exactamente lo mismo**: si el listado filtrara
     * por nombre y el contador por identificador, el panel diría «38
     * participantes» con una tabla de 12 filas.
     *
     * Cada valor va en un marcador DISTINTO (`aguja1`, `aguja2`, …) y no
     * reusado: `Database` va con `ATTR_EMULATE_PREPARES = false`, y ahí un
     * marcador repetido lanza HY093 en la propia consulta con un «Invalid
     * parameter number» que no dice qué marcador sobra.
     *
     * @return array{0:string,1:array<string,mixed>}
     */
    private static function filtroBusqueda(int $formId, string $search): array
    {
        $aguja = trim($search);
        if ($aguja === '') {
            return ['WHERE form_id = :fid', ['fid' => $formId]];
        }

        $escapada = self::escapeLike($aguja);
        $params = [
            'fid'    => $formId,
            'aguja1' => '%' . $escapada . '%',
            'aguja2' => '%' . $escapada . '%',
            'aguja3' => '%' . $escapada . '%',
            'aguja4' => '%' . $escapada . '%',
            'exacta1' => $escapada,
            'exacta2' => $escapada,
        ];

        // Busca en los tres candidatos de identidad y en el nombre, que es lo que
        // quien busca está intentando escribir: «buscar V-1» y «buscar Ana» son las
        // dos cosas que se ocurren. Los comodines van escapados porque sin eso el
        // buscador de un panel es una superficie para `LIKE` caro con `%`. El
        // `code` va por igualdad, y también con `escapeLike` por el mismo motivo:
        // un `code` buscado con `%` no es una búsqueda, es un escaneo.
        $where = 'WHERE form_id = :fid'
            . ' AND (cedula LIKE :aguja1 ESCAPE \'\\\\\''
            . ' OR indicador LIKE :aguja2 ESCAPE \'\\\\\''
            . ' OR correo LIKE :aguja3 ESCAPE \'\\\\\''
            . ' OR nombre LIKE :aguja4 ESCAPE \'\\\\\''
            . ' OR identifier_value = :exacta1'
            . ' OR code = :exacta2)';

        return [$where, $params];
    }

    /**
     * Página de participantes del formulario.
     *
     * @return list<array<string,mixed>>
     */
    public static function allForForm(
        int $formId,
        int $limit = 200,
        int $offset = 0,
        string $search = '',
        string $orden = 'cedula'
    ): array {
        [$where, $params] = self::filtroBusqueda($formId, $search);

        // `cedula` es la orden por defecto porque es la columna de la plantilla y la
        // que el dueño tiene delante al abrir la pestaña, no porque sea «la mejor»:
        // la orden por defecto se elige por lo que se ve primero.
        $columnaOrden = match ($orden) {
            'nombre'        => 'nombre',
            'correo'        => 'correo',
            'identificador' => 'identifier_value',
            'creado'        => 'id',
            default         => 'cedula',
        };

        $params['lim'] = max(1, min(1000, $limit));
        $params['off'] = max(0, $offset);

        return self::db()->fetchAll(
            'SELECT id, cedula, nombre, indicador, correo, identifier_value, code, responded_at, created_at
             FROM form_participants ' . $where . '
             ORDER BY ' . $columnaOrden . ' ASC, id ASC
             LIMIT :lim OFFSET :off',
            $params
        );
    }

    public static function countForForm(int $formId, string $search = ''): int
    {
        [$where, $params] = self::filtroBusqueda($formId, $search);

        $row = self::db()->fetch('SELECT COUNT(*) AS c FROM form_participants ' . $where, $params);

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Cuántos han respondido de los que hay en la lista.
     *
     * Es un `COUNT` aparte y no un `COUNT` con `WHERE responded_at IS NOT NULL`
     * porque el número de la cabecera («12 de 40 han respondido») es del listado
     * COMPLETO, no de la página ni de lo que dice la búsqueda: si contara lo
     * filtrado, buscar «V-» y ver «0 de 3 respondieron» sería verdad de una
     * forma que no sirve para nada, porque el dueño quiere saber cuánto de SU
     * lista ha respondido, no cuánto de la parte que está leyendo.
     *
     * Sin argumento de búsqueda a propósito, y con el `form_id` en el WHERE
     * (que es la mitad de la autorización, como en el resto del modelo).
     */
    public static function respondedCountForForm(int $formId): int
    {
        $row = self::db()->fetch(
            'SELECT COUNT(*) AS c FROM form_participants
             WHERE form_id = :form_id AND responded_at IS NOT NULL',
            ['form_id' => $formId]
        );

        return (int) ($row['c'] ?? 0);
    }

    public static function find(int $id): ?array
    {
        return self::db()->fetch(
            'SELECT id, form_id, cedula, nombre, indicador, correo, identifier_value, code, responded_at, created_at
             FROM form_participants WHERE id = :id',
            ['id' => $id]
        );
    }

    public static function findByCode(string $code): ?array
    {
        if (!self::codeValido($code)) {
            return null;
        }

        return self::db()->fetch(
            'SELECT id, form_id, cedula, nombre, indicador, correo, identifier_value, code, responded_at
             FROM form_participants WHERE code = :code',
            ['code' => $code]
        );
    }

    /**
     * Buscar por código tiene que ser rápido y sin adivinar: el `WHERE` va por
     * `code` y el `form_id` se comprueba **en el SQL**, no después.
     *
     * Es la mitad de la autorización, igual que en `FormAccess::findValidToken()`:
     * un código de otro formulario no existe para esta puerta, aunque el código sea
     * válido y el participante exista.
     */
    public static function findByCodeInForm(int $formId, string $code): ?array
    {
        if (!self::codeValido($code)) {
            return null;
        }

        return self::db()->fetch(
            'SELECT id, form_id, cedula, nombre, indicador, correo, identifier_value, code, responded_at
             FROM form_participants WHERE code = :code AND form_id = :fid',
            ['code' => $code, 'fid' => $formId]
        );
    }

    /**
     * Buscar por la columna de identidad, para cuando quien entra escribe su
     * cédula o su indicador en vez de su código.
     *
     * El valor se normaliza con **la misma función** que usa el alta
     * (`identifierValue()`), y no con un `trim()` suelto: si al guardar se
     * colapsan los espacios y al buscar no, «V 1» entró como una fila y «V1» no
     * la encuentra, y el participante queda con su código y sin poder escribirlo.
     */
    public static function findByIdentifier(int $formId, string $identificador, string $valor): ?array
    {
        $columna = self::COLUMNA_IDENTIFICADOR[$identificador] ?? null;
        if ($columna === null) {
            return null;
        }

        $valor = self::identifierValue(
            $identificador,
            $identificador === 'cedula' ? $valor : '',
            $identificador === 'indicador' ? $valor : '',
            $identificador === 'correo' ? $valor : ''
        );
        if ($valor === '') {
            return null;
        }

        return self::db()->fetch(
            'SELECT id, form_id, cedula, nombre, indicador, correo, identifier_value, code, responded_at
             FROM form_participants WHERE form_id = :fid AND ' . $columna . ' = :v',
            ['fid' => $formId, 'v' => $valor]
        );
    }

    /** @return array<string,string> `key_name` => valor */
    public static function attributesOf(int $participantId): array
    {
        $filas = self::db()->fetchAll(
            'SELECT key_name, value FROM form_participant_attrs WHERE participant_id = :pid',
            ['pid' => $participantId]
        );

        $out = [];
        foreach ($filas as $fila) {
            $out[(string) $fila['key_name']] = (string) $fila['value'];
        }

        return $out;
    }

    /**
     * Filas de participantes con sus atributos, para la exportación.
     *
     * @param list<array<string,mixed>> $participantes
     * @param list<array<string,mixed>> $atributos
     *
     * @return list<array<string,mixed>>
     */
    public static function conAtributos(array $participantes, array $atributos): array
    {
        if ($participantes === []) {
            return [];
        }

        $ids = array_map(static fn(array $p): int => (int) $p['id'], $participantes);
        $todo = [];

        // Troceado a 5000 porque ese es el techo de marcadores de MariaDB, y la
        // lista de una importación grande lo pasa holgadamente.
        foreach (array_chunk($ids, 5000) as $tanda) {
            $marcas = implode(',', array_fill(0, count($tanda), '?'));
            $stmt = self::db()->getPdo()->prepare(
                'SELECT participant_id, key_name, value FROM form_participant_attrs
                 WHERE participant_id IN (' . $marcas . ')'
            );
            $stmt->execute($tanda);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $fila) {
                $todo[(int) $fila['participant_id']][(string) $fila['key_name']] = (string) $fila['value'];
            }
        }

        foreach ($participantes as &$participante) {
            $participante['data'] = $todo[(int) $participante['id']] ?? [];
        }
        unset($participante);

        return $participantes;
    }

    // =====================================================================
    // Escritura
    // =====================================================================

    /**
     * Alta de un participante.
     *
     * @param array{cedula?:string,nombre?:string,indicador?:string,correo?:string} $campos
     * @param array<string,string> $data atributos, por `key_name` del catálogo
     *
     * @return array{ok:bool,id:int,motivo:string}
     */
    public static function create(int $formId, string $identificador, array $campos, array $data = []): array
    {
        $cedula    = self::normalizar((string) ($campos['cedula'] ?? ''));
        $nombre    = self::normalizar((string) ($campos['nombre'] ?? ''));
        $indicador = self::normalizar((string) ($campos['indicador'] ?? ''));
        $correo    = self::normalizar((string) ($campos['correo'] ?? ''));
        $identificadorValor = self::identifierValue($identificador, $cedula, $indicador, $correo);

        $motivo = self::validarValores($formId, $identificador, $identificadorValor, $correo, $data);
        if ($motivo !== self::OK) {
            return ['ok' => false, 'id' => 0, 'motivo' => $motivo];
        }

        $id = 0;
        $fallo = self::OK;

        // El alta y sus atributos van en la MISMA transacción. Sin ella, un atributo
        // que no se puede escribir (una etiqueta duplicada que se coló por la
        // normalización, un valor larguísimo) deja al participante guardado a medias:
        // alguien que no tiene atributos pero sí está en la lista, y el `code` que
        // se le repartió ya no lleva a nadie.
        self::db()->transaccionar(function () use ($formId, $cedula, $nombre, $indicador, $correo, $identificadorValor, $data, &$id, &$fallo): void {
            try {
                self::db()->execute(
                    'INSERT INTO form_participants
                         (form_id, cedula, nombre, indicador, correo, identifier_value, code)
                     VALUES (:fid, :cedula, :nombre, :indicador, :correo, :idval, :code)',
                    [
                        'fid'        => $formId,
                        'cedula'     => $cedula,
                        'nombre'     => $nombre,
                        'indicador'  => $indicador,
                        'correo'     => mb_strtolower($correo),
                        'idval'      => $identificadorValor,
                        'code'       => self::nuevoCode(),
                    ]
                );
            } catch (\PDOException $e) {
                if (!self::esDuplicado($e)) {
                    throw $e;
                }
                $fallo = self::MOTIVO_DUPLICADO;

                return;
            }

            // `lastInsertId()` se lee AQUÍ, y no se guarda para después: MariaDB lo
            // pone a 0 con el `DELETE` que hace `ON DELETE CASCADE` al borrar
            // atributos, así que leerlo más tarde da 0 y el participante se
            // escribiría sin atributos sin decir nada.
            $id = (int) self::db()->lastInsertId();
            self::escribirAtributos($formId, $id, $data);
        });

        if ($fallo !== self::OK) {
            return ['ok' => false, 'id' => 0, 'motivo' => $fallo];
        }

        return ['ok' => true, 'id' => $id, 'motivo' => self::OK];
    }

    /**
     * Modificación de un participante.
     *
     * El `code` **no** se toca nunca, ni aunque venga en el POST: es la credencial
     * que ya se repartió y cambiarla dejaría sin acceso a quien la tiene escrita.
     *
     * @param array{cedula?:string,nombre?:string,indicador?:string,correo?:string} $campos
     * @param array<string,string>|null $data `null` deja los atributos como están
     *
     * @return array{ok:bool,id:int,motivo:string}
     */
    public static function update(int $id, string $identificador, array $campos, ?array $data = null): array
    {
        $actual = self::find($id);
        if ($actual === null) {
            return ['ok' => false, 'id' => 0, 'motivo' => 'no_existe'];
        }

        $cedula    = self::normalizar((string) ($campos['cedula'] ?? $actual['cedula']));
        $nombre    = self::normalizar((string) ($campos['nombre'] ?? $actual['nombre']));
        $indicador = self::normalizar((string) ($campos['indicador'] ?? $actual['indicador']));
        $correo    = self::normalizar((string) ($campos['correo'] ?? $actual['correo']));
        $identificadorValor = self::identifierValue($identificador, $cedula, $indicador, $correo);

        $motivo = self::validarValores(
            (int) $actual['form_id'],
            $identificador,
            $identificadorValor,
            $correo,
            $data ?? []
        );
        if ($motivo !== self::OK) {
            return ['ok' => false, 'id' => 0, 'motivo' => $motivo];
        }

        $fallo = self::OK;

        // Un EDITAR también es una cosa o nada: los atributos se borran y se
        // reescriben todos, así que un fallo a mitad dejaría al participante con la
        // ficha nueva y la mitad de los atributos viejos. Se hace en una sola
        // transacción por eso, y con `transaccionar()` para que pueda ir dentro de la
        // de una importación sin que una de las dos se coma a la otra.
        self::db()->transaccionar(function () use ($id, $actual, $cedula, $nombre, $indicador, $correo, $identificadorValor, $data, &$fallo): void {
            try {
                self::db()->execute(
                    'UPDATE form_participants
                     SET cedula = :cedula, nombre = :nombre, indicador = :indicador,
                         correo = :correo, identifier_value = :idval
                     WHERE id = :id',
                    [
                        'id'        => $id,
                        'cedula'    => $cedula,
                        'nombre'    => $nombre,
                        'indicador' => $indicador,
                        'correo'    => mb_strtolower($correo),
                        'idval'     => $identificadorValor,
                    ]
                );
            } catch (\PDOException $e) {
                if (!self::esDuplicado($e)) {
                    throw $e;
                }
                $fallo = self::MOTIVO_DUPLICADO;

                return;
            }

            if ($data === null) {
                return;
            }

            self::db()->execute(
                'DELETE FROM form_participant_attrs WHERE participant_id = :pid',
                ['pid' => $id]
            );
            self::escribirAtributos((int) $actual['form_id'], $id, $data);
        });

        if ($fallo !== self::OK) {
            return ['ok' => false, 'id' => 0, 'motivo' => $fallo];
        }

        return ['ok' => true, 'id' => $id, 'motivo' => self::OK];
    }

    /**
     * Marca que este participante ya contestó (`responded_at`, fase 4).
     *
     * Es idempotente con estado **objetivo**: si ya estaba marcada, no se
     * reescribe. La razón es que esta columna se usa para listar «quién NO ha
     * respondido» y su `COMMENT` dice que su único escritor es el mismo POST que
     * crea la respuesta; si una segunda respuesta volviera a poner «ahora»,
     * el que respondió primero desaparecería del historial, que es justo lo que la
     * columna promete.
     */
    public static function marcarRespondido(int $id): void
    {
        self::db()->execute(
            'UPDATE form_participants SET responded_at = UTC_TIMESTAMP()
             WHERE id = :id AND responded_at IS NULL',
            ['id' => $id]
        );
    }

        /**
     * ¿Existe este participante en ESTE formulario?
     *
     * Es la revalidación del permiso de sesión (fase 4), así que el `form_id` va en
     * el WHERE y no se filtra después: es la mitad de la autorización, igual que en
     * `FormAccess::tokenIsLive()`.
     *
     * `LIMIT 1` acompaña a un `fetch()` porque la puerta se consulta en **cada**
     * petición de cada formulario restringido: es la consulta más repetida del fill, y
     * con el `id` (clave primaria) el MySQL no necesita ni mirar las filas siguientes.
     * Un `COUNT(*)` daría el mismo booleano y recorrería el índice entero.
     */
    public static function existeEnFormulario(int $formId, int $participantId): bool
    {
        if ($formId <= 0 || $participantId <= 0) {
            return false;
        }

        $fila = self::db()->fetch(
            'SELECT id FROM form_participants WHERE id = :id AND form_id = :fid LIMIT 1',
            ['id' => $participantId, 'fid' => $formId]
        );

        return $fila !== null;
    }

    public static function delete(int $id): bool
    {
        // El `ON DELETE SET NULL` de `submissions.participant_id` deja las
        // respuestas en su sitio: borrar a alguien de la lista es dejar de
        // invitarlo, no anular lo que ya contestó. Por eso aquí no hay transacción:
        // la cascada de `form_participant_attrs` y el `SET NULL` los hace MariaDB
        // en la misma sentencia.
        return self::db()->execute('DELETE FROM form_participants WHERE id = :id', ['id' => $id]) > 0;
    }

    /**
     * Vacía la lista entera del formulario.
     *
     * Devuelve cuántos participantes había, y **no** toca `submissions`: sus
     * `participant_id` quedan a NULL por el mismo `SET NULL` que en `delete()`.
     */
    public static function truncate(int $formId): int
    {
        return self::db()->execute('DELETE FROM form_participants WHERE form_id = :fid', ['fid' => $formId]);
    }

    // =====================================================================
    // Utilidades internas
    // =====================================================================

    /**
     * Valida lo que se puede validar SIN escribir, y devuelve el motivo o `OK`.
     *
     * Va aparte de `create()`/`update()` porque los dos necesitan exactamente el
     * mismo criterio, y un `INSERT` que falla por un valor demasiado largo devuelve
     * un error de MariaDB en inglés que no le dice al dueño qué columna era.
     *
     * Recibe el `identifier_value` YA NORMALIZADO en vez de recalcularlo: quien
     * llama lo ha tenido que calcular igual para insertarlo, y pedir el catálogo
     * del formulario por un parámetro embebido en `$data` fue un atajo que solo
     * funcionaba porque nadie miraba.
     *
     * @param array<string,string> $data
     */
    /**
     * Comprobaciones que NO dependen del catálogo, para poder hacerlas antes de
     * escribir nada.
     *
     * Van separadas de `validarValores()` porque un importador necesita poder
     * rechazar una fila **antes** de tocar la base: si una fila con el correo mal
     * escrito solo se cayera en el `INSERT`, el informe de la importación la daría
     * por buena y el dueño se encontraría después una fila a medio guardar. Y el
     * catálogo no se puede mirar aquí porque en una importación las columnas
     * nuevas aún no existen.
     */
    public static function validarDatos(string $identificador, string $identificadorValor, string $correo): string
    {
        if (!isset(self::COLUMNA_IDENTIFICADOR[$identificador])) {
            return self::MOTIVO_ATRIBUTO;
        }
        if ($identificadorValor === '') {
            return self::MOTIVO_VACIO;
        }
        if (mb_strlen($identificadorValor) > self::MAX_IDENTIFICADOR) {
            return self::MOTIVO_LARGO;
        }

        // El correo se valida aunque NO sea el identificador: es lo único que se
        // manda por correo y una dirección mal escrita es un enlace que no llega.
        if ($correo !== '' && !FormAccess::isValidEmail(mb_strtolower($correo))) {
            return self::MOTIVO_CORREO;
        }

        return self::OK;
    }

    private static function validarValores(
        int $formId,
        string $identificador,
        string $identificadorValor,
        string $correo,
        array $data
    ): string {
        $motivo = self::validarDatos($identificador, $identificadorValor, $correo);
        if ($motivo !== self::OK) {
            return $motivo;
        }

        if ($data === []) {
            return self::OK;
        }

        // La frontera de seguridad: una `key_name` que no esté en el catálogo del
        // formulario no se escribe. El SQL recibe el nombre del atributo como
        // **valor enlazado**, nunca como identificador de columna, y además solo
        // se le pasa una clave que el dueño ya creó.
        $permitidas = [];
        foreach (self::catalogFor($formId) as $campo) {
            $permitidas[(string) $campo['key_name']] = true;
        }
        foreach ($data as $clave => $valor) {
            if (!isset($permitidas[$clave])) {
                return self::MOTIVO_ATRIBUTO;
            }
            if (mb_strlen((string) $valor) > self::MAX_VALOR_ATRIBUTO) {
                return self::MOTIVO_VALOR_LARGO;
            }
        }

        return self::OK;
    }

    /**
     * @param array<string,string> $data
     */
    private static function escribirAtributos(int $formId, int $participantId, array $data): void
    {
        foreach ($data as $clave => $valor) {
            $valor = (string) $valor;
            if ($valor === '') {
                // Un atributo vacío **no** es un renglón con la cadena vacía: así el
                // catálogo no crece con columnas que nadie rellenó y el filtro por
                // «vacío» no existe, porque no es un grupo de gente.
                continue;
            }
            self::db()->execute(
                'INSERT INTO form_participant_attrs (form_id, participant_id, key_name, value)
                 VALUES (:fid, :pid, :k, :v)',
                ['fid' => $formId, 'pid' => $participantId, 'k' => (string) $clave, 'v' => $valor]
            );
        }
    }

    /**
     * Código nuevo, único.
     *
     * El `UNIQUE` de `code` es GLOBAL, no por formulario: el código es una
     * credencial que se teclea en la puerta sin decir de qué formulario es, así que
     * dos participantes de formularios distintos no pueden compartirlo. Por eso el
     * reintento, que solo hace falta si el azar saca un código repetido (o si una
     * fila corrupta ya lo ocupa): en 16 de 31 no pasa, pero «no pasa» no es «no
     * puede pasar».
     */
    private static function nuevoCode(): string
    {
        $alfabeto = self::ALFABETO;
        $largo    = strlen($alfabeto);

        for ($intento = 0; $intento < 8; $intento++) {
            $code = '';
            for ($i = 0; $i < 16; $i++) {
                $code .= $alfabeto[random_int(0, $largo - 1)];
            }
            $existe = self::db()->fetch('SELECT 1 AS ok FROM form_participants WHERE code = :code', ['code' => $code]);
            if ($existe === null) {
                return $code;
            }
        }

        // Agotados los ocho intentos se devuelve una cadena que no existe, porque
        // `false` en el INSERT sería un código vacío y eso SÍ colisiona siempre.
        // Es una señal de que la tabla está en muy mal estado, no algo que deba
        // poder pasar en operación normal.
        return substr(bin2hex(random_bytes(16)), 0, 16);
    }

    public static function codeValido(string $code): bool
    {
        return preg_match('/^[23456789ABCDEFGHJKMNPQRSTWXYZ]{16}$/', $code) === 1;
    }

    /**
     * Variante de un slug que no esté en el catálogo.
     *
     * El sufijo es correlativo (`sede`, `sede_2`, `sede_3`), no aleatorio: una clave
     * estable importa porque es lo que viaja al SQL y lo que se ve en el filtro, y
     * `sede_7` no dice más que `sede_2`.
     */
    private static function claveUnica(int $formId, string $base): string
    {
        // La base se prueba ANTES que cualquier sufijo. Al revés —empezando el
        // bucle en 2 y sobrescribiendo `$candidata` antes de comprobarla— la
        // primera columna de una lista se llamaba siempre `sede_2` y devolvía
        // `diseno_2` para dos etiquetas distintas, sin ningún error.
        $existe = self::db()->fetch(
            'SELECT 1 AS ok FROM form_participant_fields WHERE form_id = :fid AND key_name = :k',
            ['fid' => $formId, 'k' => $base]
        );
        if ($existe === null) {
            return $base;
        }

        for ($n = 2; $n < 200; $n++) {
            $candidata = $base . '_' . $n;
            $existe = self::db()->fetch(
                'SELECT 1 AS ok FROM form_participant_fields WHERE form_id = :fid AND key_name = :k',
                ['fid' => $formId, 'k' => $candidata]
            );
            if ($existe === null) {
                return $candidata;
            }
        }

        return $base . '_' . random_int(100, 999);
    }

    private static function siguientePosicion(int $formId): int
    {
        $row = self::db()->fetch(
            'SELECT COALESCE(MAX(position), 0) AS p FROM form_participant_fields WHERE form_id = :fid',
            ['fid' => $formId]
        );

        return (int) ($row['p'] ?? 0) + 1;
    }

    /** MariaDB, no SQL estándar: el 1062 es la clave duplicada. */
    private static function esDuplicado(\PDOException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062;
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}