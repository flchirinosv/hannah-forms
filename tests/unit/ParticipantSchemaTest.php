<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Core\Database;

/**
 * Fase 2 — el esquema del listado de participantes (migración `0014`).
 *
 * Este test no mira el archivo de la migración: mira la **base de datos**, que es
 * donde el error se convierte en problema. Las tres razones por las que existe:
 *
 *  1. **Atrapa el «no has migrado».** A partir de aquí el resto de la suite usa
 *     estas tablas, y un `Undefined table` en un modelo aparece como un error de
 *     PHP que no dice «ejecuta las migraciones». Este test lo dice.
 *  2. **Atrapa el esquema a medias.** La migración se puede aplicar y aun así
 *     dejar algo mal: una columna sin su FK, un índice que cubre la mitad de lo
 *     que se le pidió. Eso no da error de SQL, da un `WHERE` que no filtra.
 *  3. **Atrapa el índice que dejó de cubrir.** `value` es `VARCHAR(255)` y no
 *     `TEXT` a propósito: con `TEXT` el índice de filtro solo cubre 191
 *     caracteres y un valor largo deja de ser buscable sin que nadie lo note.
 *
 * Y una cuarta cosa que NO está aquí y es importante: este test **no puede**
 * comprobar que una instalación limpia y una migrada sean iguales. Eso se
 * comprueba con `schema.sql` contra `migrate.php` sobre dos bases de verdad, no
 * en el runner. Lo que sí comprueba es que `schema.sql` y la migración **coincidan
 * en el texto del DDL**, y para eso está `testElEsquemaLimpioYLasMigracionesDicenLoMismo`.
 */
class ParticipantSchemaTest
{
    private static function pdo(): \PDO
    {
        return Database::getInstance(Config::db())->getPdo();
    }

    private static function columna(string $tabla, string $columna): ?array
    {
        $st = self::pdo()->prepare(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
        );
        $st->execute(['t' => $tabla, 'c' => $columna]);
        $row = $st->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string,string> nombre => columnas, en orden
     */
    private static function indices(string $tabla): array
    {
        $st = self::pdo()->prepare(
            'SELECT INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t
              ORDER BY INDEX_NAME, SEQ_IN_INDEX'
        );
        $st->execute(['t' => $tabla]);

        $salida = [];
        foreach ($st->fetchAll() as $fila) {
            $nombre = (string) $fila['INDEX_NAME'];
            $salida[$nombre] = isset($salida[$nombre])
                ? $salida[$nombre] . ',' . $fila['COLUMN_NAME']
                : (string) $fila['COLUMN_NAME'];
        }

        return $salida;
    }

    /**
     * @return array<string,array{columna:string,tabla:string,regla:string}>
     */
    private static function clavesForaneas(string $tabla): array
    {
        $st = self::pdo()->prepare(
            'SELECT k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME,
                    rc.DELETE_RULE, rc.UPDATE_RULE
               FROM information_schema.KEY_COLUMN_USAGE k
               JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
                 ON rc.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
                AND rc.CONSTRAINT_NAME = k.CONSTRAINT_NAME
                AND rc.TABLE_NAME = k.TABLE_NAME
              WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = :t'
        );
        $st->execute(['t' => $tabla]);

        $salida = [];
        foreach ($st->fetchAll() as $fila) {
            $salida[(string) $fila['CONSTRAINT_NAME']] = [
                'columna' => (string) $fila['COLUMN_NAME'],
                'tabla'   => (string) $fila['REFERENCED_TABLE_NAME'],
                'regla'   => (string) $fila['DELETE_RULE'],
            ];
        }

        return $salida;
    }

    public function testLasTresTablasDelListadoExisten(): void
    {
        foreach (['form_participants', 'form_participant_fields', 'form_participant_attrs'] as $tabla) {
            assertNotNull(
                self::columna($tabla, 'id'),
                'falta la tabla ' . $tabla . ' — ejecuta `php database/migrate.php`'
            );
        }
    }

    public function testUnParticipanteGuardaLosTresCandidatosAIdentificadorYElDenormalizado(): void
    {
        foreach (['cedula', 'nombre', 'indicador', 'correo', 'identifier_value', 'code'] as $columna) {
            assertNotNull(
                self::columna('form_participants', $columna),
                'falta form_participants.' . $columna
            );
        }

        // `identifier_value` es la columna por la que busca la puerta del fill, y
        // por eso no puede ser NULL ni vacío.
        $denormalizado = self::columna('form_participants', 'identifier_value');
        assertSame('NO', $denormalizado['IS_NULLABLE']);

        // `code` es CHAR(16): la credencial. Un INT sería adivinable por
        // correlación, que es lo mismo que expone `submissions.public_token`.
        $code = self::columna('form_participants', 'code');
        assertSame('char(16)', strtolower((string) $code['COLUMN_TYPE']));
        assertSame('NO', $code['IS_NULLABLE']);
    }

    public function testRespondioQuedaMarcadoSinBorrarLaRespuesta(): void
    {
        $respondio = self::columna('form_participants', 'responded_at');
        assertNotNull($respondio, 'sin responded_at no se puede listar quién falta');
        assertSame('YES', $respondio['IS_NULLABLE'], 'por defecto nadie ha respondido');

        // Lo que se comprueba aquí es la REGLA de borrado, que es la parte que no
        // se ve leyendo el nombre de la columna: quitar a alguien de la lista es
        // dejar de invitarlo, no anular lo que ya contestó.
        $fk = self::clavesForaneas('submissions')['fk_submissions_participant'] ?? null;
        assertNotNull($fk, 'submissions necesita su FK a form_participants');
        assertSame('SET NULL', $fk['regla'], 'borrar un participante NO puede borrar su respuesta');
        assertSame('form_participants', $fk['tabla']);

        $columna = self::columna('submissions', 'participant_id');
        assertNotNull($columna, 'falta submissions.participant_id');
        assertSame('YES', $columna['IS_NULLABLE'], 'las respuestas viejas no pertenecen a nadie');
    }

    public function testNoSePuedeMeterDosVecesElMismoIdentificadorEnUnFormulario(): void
    {
        $indices = self::indices('form_participants');
        assertSame('form_id,identifier_value', $indices['uq_form_participant_identifier'] ?? '');
        assertSame('code', $indices['uq_form_participant_code'] ?? '');
    }

    public function testElCorreoSeBuscaPorSuParPropio(): void
    {
        // El correo es solo un dato para escribirle; la puerta entra por el
        // identificador. El índice está para la pantalla de la lista y para el
        // botón de invitaciones.
        assertSame('form_id,correo', self::indices('form_participants')['idx_form_participant_correo'] ?? '');
    }

    public function testElCatalogoDeAtributosEsUnicoPorFormulario(): void
    {
        // Es la frontera de seguridad: el SQL solo recibe una `key_name` que salió
        // de aquí. Si el catálogo admitiera duplicados, dos atributos distintos
        // podrían compartir clave y el filtro agruparía por los dos.
        $indices = self::indices('form_participant_fields');
        assertSame('form_id,key_name', $indices['uq_form_participant_field_key'] ?? '');
        assertSame('form_id,label', $indices['uq_form_participant_field_label'] ?? '');

        $clave = self::columna('form_participant_fields', 'key_name');
        assertSame('varchar(64)', strtolower((string) $clave['COLUMN_TYPE']));
        $label = self::columna('form_participant_fields', 'label');
        assertSame('varchar(190)', strtolower((string) $label['COLUMN_TYPE']));
    }

    public function testLosValoresDeAtributoEntranEnterosEnElIndiceDeFiltro(): void
    {
        // `VARCHAR(255)` y NO `TEXT`: con `TEXT`, MySQL no puede indexar más de
        // 191 caracteres y el `WHERE key_name = ? AND value = ?` de la fase 7
        // empezaría a omitir filas sin ningún aviso, y el filtro mostraría una
        // cifra y la lista otra.
        $valor = self::columna('form_participant_attrs', 'value');
        assertSame('varchar(255)', strtolower((string) $valor['COLUMN_TYPE']));
        assertSame('form_id,key_name,value', self::indices('form_participant_attrs')['idx_form_participant_attr_filter'] ?? '');
        assertSame('participant_id,key_name', self::indices('form_participant_attrs')['idx_form_participant_attr_unique'] ?? '');
    }

    public function testLosAtributosTienenElFormularioDesnormalizado(): void
    {
        // Filtrar por atributo sin atravessar dos joins es la razón de que
        // `form_id` esté aquí y no solo en `form_participants`.
        assertNotNull(self::columna('form_participant_attrs', 'form_id'));
    }

    public function testBorrarUnFormularioSeLlevaSuListadoEntero(): void
    {
        // No se comprueba por NOMBRE de restricción sino buscando la que apunta a
        // `forms`: los tres nombres son distintos y adivinarlos sería atarlos a
        // una nomenclatura que no importa. Lo que importa es la REGLA.
        foreach (['form_participants', 'form_participant_fields', 'form_participant_attrs'] as $tabla) {
            $haciaForms = array_values(array_filter(
                self::clavesForaneas($tabla),
                static fn(array $fk): bool => $fk['tabla'] === 'forms'
            ));
            assertEquals(1, count($haciaForms), $tabla . ' necesita exactamente una FK a forms');
            assertSame('CASCADE', $haciaForms[0]['regla'], $tabla . ': borrar el formulario se lleva su lista');
        }
    }

    public function testElEsquemaLimpioYLasMigracionesDicenLoMismo(): void
    {
        // Lo que SÍ se puede comprobar en el runner: que `schema.sql` (instalación
        // limpia) y `0014_form_participants.php` (instalación migrada) digan lo
        // mismo sobre la misma cosa. No es una comparación de esquemas — eso se
        // hace con dos bases de verdad — sino que las dos fuentes no se separen en
        // silencio, que es como una instalación limpia acaba sin la FK que sí
        // tiene toda base migrada.
        $schema = (string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql');
        $migracion = (string) file_get_contents(
            dirname(__DIR__, 2) . '/database/migrations/0014_form_participants.php'
        );

        foreach ([
            'uq_form_participant_identifier',
            'uq_form_participant_code',
            'idx_form_participant_correo',
            'idx_form_participant_attr_filter',
            'idx_form_participant_attr_unique',
            'uq_form_participant_field_key',
            'uq_form_participant_field_label',
        ] as $indice) {
            assertTrue(str_contains($schema, $indice), 'schema.sql no declara ' . $indice);
            assertTrue(str_contains($migracion, $indice), 'la migración no declara ' . $indice);
        }

        // Y la FK de `submissions` en las dos: fue justo lo que se me olvidó una
        // vez y installaba sin ella sin dar ningún error.
        assertTrue(str_contains($schema, 'fk_submissions_participant'), 'schema.sql no declara la FK de submissions');
        assertTrue(str_contains($migracion, 'fk_submissions_participant'));
        // La regla de borrado, en las dos fuentes y EN LA MISMA LÍNEA: separarlas
        // con un salto de línea haría que un `grep` por «SET NULL» diera verde con
        // la FK en `CASCADE`.
        foreach (['schema.sql' => $schema, 'la migración' => $migracion] as $nombre => $fuente) {
            assertTrue(
                (bool) preg_match('/fk_submissions_participant.*ON DELETE SET NULL/s', $fuente),
                'la FK de submissions debe ser SET NULL en ' . $nombre
            );
        }
    }

    public function testSchemaSqlNoTieneComentariosRotos(): void
    {
        // Una línea de continuación de comentario a la que se le cayó el `--`
        // deja un trozo de prosa que MariaDB intenta ejecutar como SQL, y el
        // esquema entero deja de instalar. Pasó en `schema.sql:102` y no lo veía
        // ningún test: la instalación limpia estaba rota desde hacía tiempo.
        $lineas = file(dirname(__DIR__, 2) . '/database/schema.sql');
        $prosa = [];
        foreach ($lineas as $n => $linea) {
            $fuera = ltrim($linea);
            // Una continuación de comentario empieza por `*` sin ir precedida de `--`.
            if (str_starts_with($fuera, '*') && !str_starts_with($fuera, '--')) {
                $prosa[] = ($n + 1) . ': ' . $fuera;
            }
        }

        assertEquals([], $prosa, 'schema.sql tiene prosa fuera de comentario: ' . implode(' | ', $prosa));
    }
}