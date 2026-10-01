<?php

declare(strict_types=1);

use App\Core\Database;

/**
 * Migración 0013: el salto de página puede ocultar su cabecera en el formulario.
 *
 * El elemento página es la caja con icono, título y descripción (`.vf-page-head`).
 * Publicarla es una decisión de quien edita, no una consecuencia de existir el
 * salto: hay pasos que solo son un cambio de pantalla y una cabecera encima no
 * dice nada. Ocultarla NO oculta el paso — el bloque, su `aria-label`, la barra de
 * progreso y la insignia del pie se siguen pintando —, así que el paso sigue
 * existento y nombrándose igual.
 *
 * `DEFAULT 1` a propósito: es lo que hace esta migración inocua. No hay backfill
 * porque no hay nada que rellenar; las filas existentes se quedan como estaban
 * (cabecera visible) y solo un `page` que el dueño marque cambia.
 *
 * Es una COLUMNA y no una clave en `validations` porque ahí no cabe: los tipos
 * estructurales la ven vacía (`BuilderController` la fuerza a `[]` y `builder.js`
 * hace lo mismo al serializar), así que un toggle guardado ahí se perdería en
 * cada «Guardar estructura» sin decir nada. Tampoco sirve `options`: el cliente
 * la normaliza con `Array.isArray(f.options) ? f.options : []` y un objeto se
 * borraría al cargar. Es el mismo camino que `is_required` e `is_pii`.
 *
 * Idempotente: la columna se busca en `information_schema` antes de añadirla (un
 * `ADD COLUMN` repetido revienta con 1060 y para el DDL de MariaDB no hay
 * rollback, así que un fallo a medias dejaría la migración sin registrar con la
 * columna ya puesta). Sin transacción, porque el DDL hace commit implícito.
 */
return static function (Database $db): void {
    $existe = static function (string $tabla, string $columna) use ($db): bool {
        $row = $db->fetch(
            'SELECT COUNT(*) AS c FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
            ['t' => $tabla, 'c' => $columna]
        );

        return ((int) ($row['c'] ?? 0)) > 0;
    };

    if (!$existe('fields', 'show_header')) {
        $db->query(
            "ALTER TABLE fields
             ADD COLUMN show_header TINYINT(1) NOT NULL DEFAULT 1
             COMMENT 'Página: pintar la cabecera (icono, título y descripción) en el formulario final'"
        );
        echo "  [DDL] fields.show_header añadida\n";
    }
};