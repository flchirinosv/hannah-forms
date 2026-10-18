<?php

declare(strict_types=1);

use App\Core\Database;

/**
 * Migración 0020: borrador de estructura del builder (modelo Elementor).
 *
 * El builder pasaba a escribir cada autosave directamente sobre `fields` (la
 * estructura viva que sirve el formulario público). Eso tiene dos problemas: el
 * «temporal» se volvía definitivo sin que nadie pulsara «Guardar», y editar un
 * formulario publicado o aprobado re-encolaba la revisión en cada tic de
 * autosave. Este es el modelo de Elementor/WordPress: se trabaja sobre un
 * BORRADOR separado del live, y solo «Guardar/Update» commitea el borrador a la
 * estructura real.
 *
 * - `builder_draft` guarda el payload JSON del builder (lo que `buildPayload()`
 *   manda) mientras se edita. El autosave escribe AHÍ, nunca en `fields`.
 * - `builder_draft_at` es el instante UTC del último autosave; sirve para
 *   «borrador sin guardar desde…» y para podérselo purgar.
 * - `GET /forms/{id}/preview` lee el borrador si existe (lo que el dueño ve al
 *   previsualizar sus cambios sin guardar) y, si no, la estructura viva. El
 *   revisor siempre ve la viva: el dueño limpia el borrador al commitear.
 *
 * `LONGTEXT` a propósito: un formulario con muchas preguntas, opciones y reglas
 * supera con holgura el techo de `TEXT` (64 KB), y aquí no se consulta como
 * columna relational, se lee entero y se decodifica.
 *
 * Sin backfill: no hay nada que rellenar. Las filas existentes quedan con el
 * borrador en NULL, que significa «no hay cambios sin guardar».
 *
 * Idempotente: las dos columnas se buscan en `information_schema` antes de
 * añadirlas (un `ADD COLUMN` repetido revienta con 1060 y el DDL de MariaDB no
 * tiene rollback). Sin transacción, porque el DDL hace commit implícito.
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

    if (!$existe('forms', 'builder_draft')) {
        $db->query(
            "ALTER TABLE forms
             ADD COLUMN builder_draft LONGTEXT DEFAULT NULL
             COMMENT 'Payload JSON de la estructura en edición (borrador del builder); NULL = sin cambios sin guardar'"
        );
        echo "  [DDL] forms.builder_draft añadida\n";
    }

    if (!$existe('forms', 'builder_draft_at')) {
        $db->query(
            "ALTER TABLE forms
             ADD COLUMN builder_draft_at DATETIME DEFAULT NULL
             COMMENT 'Instante UTC del último autosave al borrador'"
        );
        echo "  [DDL] forms.builder_draft_at añadida\n";
    }
};
