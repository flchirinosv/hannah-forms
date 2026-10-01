<?php

declare(strict_types=1);

use App\Core\Database;

/**
 * Migración 0012: los saltos de página se quedan sin etiqueta de relleno.
 *
 * El builder escribía `Página N` como etiqueta al soltar un salto de paso, y
 * `fields.label` es CONTENIDO: lo que se guarda es lo que se publica. En el
 * formulario final eso salía como encabezado del paso, que es un título que no
 * dice el contenido del paso y repite lo que el `aria-label` del bloque y el
 * badge del pie ya dicen sin decir nunca qué hay en él.
 *
 * Esto limpia las filas que ya tienen ese relleno, porque el builder solo ha
 * dejado de inventarlo para los saltos NUEVOS: sin esta migración, corregir el
 * código no cambiaría nada en las instalaciones que ya tenían formularios
 * paginados. La migración borra la etiqueta, no el salto: el paso, su posición y
 * sus campos siguen igual, y el rótulo del editor («Página») lo pone el nombre
 * del tipo al pintar la tarjeta.
 *
 * El patrón es deliberadamente estrecho: solo `Página 1`/`Page 1` con número, que
 * es exactamente lo que el builder escribía. Una etiqueta que alguien haya
 * escrito a mano se respeta, y una descripción (`help_text`) no se toca nunca:
 * esa sí es contenido y sigue pintándose.
 *
 * Idempotente: la segunda pasada no encuentra nada que limpiar. Sin transacción
 * (el DDL de MariaDB hace commit implícito, aunque aquí solo haya DML).
 */
return static function (Database $db): void {
    $db->execute(
        "UPDATE fields
            SET label = ''
          WHERE type = 'page'
            AND label REGEXP '^(Página|Page|Pagina)[[:space:]]+[0-9]+$'"
    );
};
