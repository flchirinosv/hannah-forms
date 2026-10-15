<?php

declare(strict_types=1);

use App\Core\Database;

/**
 * Migración 0018: recordatorios por correo a quien no ha respondido (fase 12).
 *
 * La 0015 dio memoria a la invitación (`invited_at`: quién RECIBIÓ el enlace) y
 * con eso el botón dejó de reenviar los mismos 400 correos. Lo que no resolvía es
 * el caso siguiente, que en un censo es el normal: la persona recibió el enlace y
 * no lo abrió, o lo abrió y no terminó. Volver a escribirle **es** la operación
 * («quedan 612 por responder, avísales otra vez»), así que la invitación se manda
 * una vez y el recordatorio se puede repetir.
 *
 * De ahí las dos columnas y no una:
 *
 *  - `reminded_at`: CUÁNDO se le mandó el último recordatorio. `NULL` = nunca. Es
 *    lo que permite que la tanda empiece por quien no ha recibido ninguno y luego
 *    rote por los que ya llevan uno, en vez de martillar siempre a los mismos.
 *  - `reminder_count`: CUÁNTOS lleva. Un «sí/no» habría bastado para «repetible»,
 *    pero no para responder «¿a esta persona cuántas veces le hemos escrito?», que
 *    es lo que se pregunta cuando alguien llama diciendo que le llegan correos de
 *    más. Es el equivalente al `remindercount` de LimeSurvey.
 *
 * `reminded_at` es `DATETIME DEFAULT NULL` de verdad —no `'0000-00-00'`— por la
 * misma razón que `invited_at`: `IS NOT NULL` sobre la fecha cero marcaría a toda
 * la lista como recordada desde el primer día.
 *
 * ## La posición es parte del contrato
 *
 * `schema.sql` pone las dos **justo detrás de `invited_at`**: son la memoria de
 * los dos correos que se mandan (invitación y recordatorio) y leerlas juntas dice
 * algo. Un `ADD COLUMN` a secas las dejaría al final, y una instalación migrada y
 * una limpia tendrían las columnas en orden distinto: la comparación buena de los
 * dos esquemas (columnas con su posición, índices y `DELETE_RULE` de cada FK)
 * daría una diferencia que no está en el código sino en cómo se creó la base. De
 * ahí el `AFTER`, que además hace la migración idempotente de verdad.
 *
 * Sin transacción, porque el DDL de MariaDB hace commit implícito.
 */
return static function (Database $db): void {
    $definiciones = [
        'reminded_at' => 'DATETIME DEFAULT NULL COMMENT \'Último recordatorio por correo (fase 12); NULL = nunca\'',
        'reminder_count' => 'INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'Recordatorios por correo enviados (fase 12)\'',
    ];

    // La posición de cada una: `reminded_at` detrás de `invited_at` y
    // `reminder_count` detrás de `reminded_at`. Se aplican en este orden.
    $posiciones = [
        'reminded_at' => 'AFTER invited_at',
        'reminder_count' => 'AFTER reminded_at',
    ];

    foreach ($definiciones as $columna => $definicion) {
        // Se comprueba en information_schema y no con un try/catch del ALTER: un
        // `ADD COLUMN` repetido revienta con 1060 y para el DDL de MariaDB no hay
        // rollback, así que un fallo a medias dejaría la migración sin registrar
        // con la columna ya puesta (y re-ejecutarla, entonces, sí fallaría).
        $row = $db->fetch(
            'SELECT COUNT(*) AS c FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
            ['t' => 'form_participants', 'c' => $columna]
        );

        if ((int) ($row['c'] ?? 0) === 0) {
            $db->query('ALTER TABLE form_participants ADD COLUMN ' . $columna . ' ' . $definicion . ' ' . $posiciones[$columna]);
            echo "  [DDL] form_participants." . $columna . " añadida\n";
        }

        // La posición, se fije o no la columna. `MODIFY COLUMN` con la definición
        // y la posición exactas no cambia nada de lo que ya está bien.
        $db->query('ALTER TABLE form_participants MODIFY COLUMN ' . $columna . ' ' . $definicion . ' ' . $posiciones[$columna]);
    }
};
