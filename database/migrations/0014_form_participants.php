<?php

declare(strict_types=1);

use App\Core\Database;

/**
 * Migración 0014: el listado de participantes (modo de acceso `roster`).
 *
 * Añade las tres tablas que sostienen el cuarto modo de acceso del fill y una
 * columna en `submissions`. El MODO, como en F4, vive en `settings.access.mode`
 * (`public|email|allowlist|roster`) y no en una columna: con el gate en JSON se lee
 * en el mismo sitio que el resto de la configuración del fill y no obliga a un
 * `MODIFY TABLE` cada vez que se añada un modo nuevo.
 *
 * Por qué TRES tablas y no una con JSON. La alternativa obvia era
 * `data JSON` + `JSON_UNQUOTE(JSON_EXTRACT(data,'$."clave"'))`, y se descartó por
 * dos cosas verificadas en este proyecto:
 *
 *  1. **El nombre de la clave no se puede pasar como parámetro enlazado**: va
 *     dentro de la llamada a la función, es decir texto del usuario —la cabecera
 *     del xlsx que subió— dentro del SQL. Con la tabla de atributos, `key_name` y
 *     `value` viajan enlazados como valores y no hay por dónde entrar.
 *  2. **`GROUP BY` sobre eso no es indexable**, y la lección ya está escrita en
 *     `Answer.php`: `a.value` es `TEXT` y `MIN`/`MAX` comparan como cadena. Aquí
 *     agrupar por un atributo es de las funciones principales de la pantalla.
 *
 * Por qué `form_participant_fields` es una tabla aparte y no una constante en el
 * código: es **la frontera de seguridad**. El filtro y el agrupado reciben un
 * `key_name` que salió de aquí, y una clave inventada no encuentra nada ni en SQL
 * ni en la vista. Además guarda la etiqueta que escribió el dueño y el orden de
 * las columnas, que no se derivan de la fila de valores.
 *
 * Por qué `identifier_value` está DENORMALIZADO aunque los tres candidatos a
 * identificador (`cedula`, `indicador`, `correo`) son columnas propias: para que la
 * puerta del fill busque siempre en la misma columna indexada, se elija el
 * identificador que se elija. Si la puerta atendiera a un `CASE` sobre tres
 * columnas distintas, cada modo tendría su propio índice y el mismo lugar del
 * código se repetiría tres veces.
 *
 * Por qué `UNIQUE (form_id, identifier_value)`: es lo que impide que un mismo
 * participante esté dos veces en la lista, y hace que la importación pueda decir
 * «este ya existe» en vez de duplicarlo en silencio. Ojo con la collation
 * `utf8mb4_unicode_ci`: **no distingue mayúsculas ni acentos**, así que
 * `V-1234567` y `v-1234567` son el mismo participante y eso es lo que se quiere.
 *
 * Por qué `code CHAR(16)` y no un entero autoincremental: un código corto y
 * enumerable es adivinable por correlación, que es exactamente el problema que
 * `submissions.public_token` ya resolvió con 128 bits. La credencial NO es la fila
 * ni el identificador, es una cadena aleatoria, y `UNIQUE` global porque es lo que
 * se teclea sin saber de qué formulario es.
 *
 * Por qué `submissions.participant_id` es `ON DELETE SET NULL` y no `CASCADE`:
 * **borrar un participante no puede borrar su respuesta.** Quitar a alguien de la
 * lista es dejar de invitarlo, no anular lo que ya contestó. Lo contrario
 * destruiría datos ya recogidos y no habría forma de recuperarlos.
 *
 * Por qué `responded_at` y no solo mirar `submissions`: se actualiza en el mismo
 * sitio donde se crea la respuesta, dentro de la misma transacción, y permite
 * listar «quién NO ha respondido» con un `LEFT JOIN ... WHERE responded_at IS NULL`
 * en vez de una subconsulta correlacionada sobre la tabla de respuestas. Es
 * redundante a propósito: se recalcula al responder, y no se puede quedar viejo
 * porque su único escritor es el mismo camino que escribe la respuesta.
 *
 * Idempotente: las tres tablas se crean con `CREATE TABLE IF NOT EXISTS`, que
 * incluye sus índices y sus FK. La columna se busca en `information_schema` antes de
 * añadirla, porque un `ADD COLUMN` repetido revienta con 1060 y para el DDL de
 * MariaDB no hay rollback: un fallo a medias dejaría la migración sin registrar con
 * la columna ya puesta. Sin transacción, porque el DDL hace commit implícito.
 */
return static function (Database $db): void {
    $db->query(
        "CREATE TABLE IF NOT EXISTS form_participants (
            id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            form_id          INT UNSIGNED NOT NULL,
            cedula           VARCHAR(190) NOT NULL DEFAULT '' COMMENT 'Uno de los tres candidatos a identificador',
            nombre           VARCHAR(190) NOT NULL DEFAULT '',
            indicador        VARCHAR(190) NOT NULL DEFAULT '',
            correo           VARCHAR(190) NOT NULL DEFAULT '' COMMENT 'Normalizado a minúsculas por el modelo',
            identifier_value VARCHAR(190) NOT NULL COMMENT 'Denormalizado: la puerta busca siempre por aquí',
            code             CHAR(16) NOT NULL COMMENT 'Credencial personal; NO es la fila ni el identificador',
            responded_at     DATETIME DEFAULT NULL COMMENT 'Su único escritor es el mismo POST que crea la respuesta',
            created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_form_participant_identifier (form_id, identifier_value),
            UNIQUE KEY uq_form_participant_code (code),
            KEY idx_form_participant_correo (form_id, correo),
            CONSTRAINT fk_form_participant_form FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
        ) ENGINE=InnoDB COMMENT='Listado de participantes con acceso al formulario'"
    );

    $db->query(
        "CREATE TABLE IF NOT EXISTS form_participant_fields (
            id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            form_id  INT UNSIGNED NOT NULL,
            key_name VARCHAR(64) NOT NULL COMMENT 'Slug estable; es lo único que viaja al SQL',
            label    VARCHAR(190) NOT NULL COMMENT 'La cabecera que escribió el dueño en el xlsx',
            position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_form_participant_field_key (form_id, key_name),
            UNIQUE KEY uq_form_participant_field_label (form_id, label),
            CONSTRAINT fk_form_participant_field_form FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
        ) ENGINE=InnoDB COMMENT='Catálogo de atributos de participante por formulario'"
    );

    $db->query(
        "CREATE TABLE IF NOT EXISTS form_participant_attrs (
            id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            form_id        INT UNSIGNED NOT NULL COMMENT 'Desnormalizado para filtrar sin atravesar dos tablas',
            participant_id INT UNSIGNED NOT NULL,
            key_name       VARCHAR(64) NOT NULL COMMENT 'Salió de form_participant_fields, nunca del POST',
            value          VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'VARCHAR, no TEXT: así el índice es completo',
            KEY idx_form_participant_attr_unique (participant_id, key_name),
            KEY idx_form_participant_attr_filter (form_id, key_name, value),
            CONSTRAINT fk_form_participant_attr_form FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE,
            CONSTRAINT fk_form_participant_attr_participant FOREIGN KEY (participant_id) REFERENCES form_participants(id) ON DELETE CASCADE
        ) ENGINE=InnoDB COMMENT='Un renglón por valor de atributo'"
    );

    // `submissions.participant_id` se añade SOLO si no está. Es NULL, no NOT NULL:
    // las respuestas ya guardadas no pertenecen a nadie y no hay backfill que
    // inventar (no se puede saber de qué participante vino cada una sin haber
    // registrado el código antes, que es justo lo que empieza a pasar ahora).
    $existe = $db->fetch(
        'SELECT COUNT(*) AS c FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
        ['t' => 'submissions', 'c' => 'participant_id']
    );
    if (((int) ($existe['c'] ?? 0)) === 0) {
        $db->query(
            "ALTER TABLE submissions
             ADD COLUMN participant_id INT UNSIGNED DEFAULT NULL
             COMMENT 'Con qué participante se respondió; NULL si el formulario no es roster o respondió sin código' AFTER form_id,
             ADD KEY idx_submissions_participant (participant_id),
             ADD CONSTRAINT fk_submissions_participant FOREIGN KEY (participant_id)
                 REFERENCES form_participants(id) ON DELETE SET NULL"
        );
        echo "  [DDL] submissions.participant_id añadida\n";
    }

    echo "  [DDL] form_participants, form_participant_fields y form_participant_attrs listas\n";
};