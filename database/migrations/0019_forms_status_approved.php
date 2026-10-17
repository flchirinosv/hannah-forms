<?php

declare(strict_types=1);

use App\Core\Database;

/**
 * Migración 0019: «Aprobado» como estado propio de un formulario (F3).
 *
 * Hasta ahora aprobar y publicar eran el mismo clic: `in_review --(aprobar)-->
 * published`. Eso deja al dueño sin voz sobre CUÁNDO sale su formulario al
 * público, que es justo lo que necesita una encuesta que se aprueba con
 * semanas de antelación. El flujo pasa a tener una parada más:
 *
 *   in_review --(revisor: aprobar)--> approved --(dueño: publicar)--> published
 *
 * `approved` no se sirve al público (como `in_review`): el formulario ya está
 * revisado, pero el dueño decide si lo publica ya o lo deja para su agenda.
 *
 * ## Por qué `approved` va AL FINAL del ENUM
 *
 * En MariaDB el valor guardado de una columna ENUM es el ÍNDICE de la opción en
 * la lista, no el texto. Añadir un valor al final deja los índices de los cuatro
 * de siempre intactos; insertarlo en medio (donde parecería ir por orden lógico,
 * entre `in_review` y `published`) reescribiría el significado de cada fila
 * guardada: lo que era `published` pasaría a leerse como el valor nuevo. Por eso
 * el orden de la lista no es el orden del flujo.
 *
 * Idempotente (un `MODIFY` con la definición exacta no cambia nada de lo que ya
 * está bien) y sin transacción, porque el DDL de MariaDB hace commit implícito.
 */
return static function (Database $db): void {
    $db->query(
        "ALTER TABLE forms MODIFY status "
        . "ENUM('draft','in_review','published','closed','approved') "
        . "NOT NULL DEFAULT 'draft'"
    );

    $row = $db->fetch(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forms' AND COLUMN_NAME = 'status'"
    );
    echo '  [DDL] forms.status = ' . (string) ($row['COLUMN_TYPE'] ?? '?') . "\n";
};
