<?php

declare(strict_types=1);

use App\Core\Database;

/**
 * Migración 0015: cuándo se le mandó el correo de invitación (fase 10).
 *
 * `submissions.responded_at` ya dice quién respondió; esta columna dice quién
 * **recibió** el enlace. Son dos hechos distintos y el segundo no se puede deducir
 * del primero: hay gente a la que se le mandó y no abrió (y volver a mandárselo es
 * lo normal), y hay gente que respondió sin que nadie le mandara nada, porque el
 * código se repartió impreso o en un chat.
 *
 * Sin esta columna, el botón de invitar no tiene memoria: cada clic reenvía los
 * mismos 400 correos, y un formulario con la lista de mil queda con cuatrocientas
 * copias idénticas en la bandeja de la gente. Es también lo que permite que el
 * botón diga "quedan 612" en vez de mentir con un número de la última vez.
 *
 * Es `DATETIME DEFAULT NULL`, no un booleano: «invitado» sin fecha no dice cuándo,
 * y dentro de un mes la pregunta es «¿cuándo se lo mandaron?». `UTC_TIMESTAMP()`
 * lo escriben `markInvited()`, con lo que la conversión a la zona del lector la
 * hace `Clock` como en cualquier otra fecha.
 *
 * ## La posición es parte del contrato
 *
 * `schema.sql` la pone **junto a `responded_at`**, porque las dos columnas cuentan
 * hechos sobre la misma persona y leerlas una al lado de la otra dice algo. Un
 * `ADD COLUMN` a secas la dejaría al final de la tabla, y entonces una instalación
 * migrada y una limpia tendrían las columnas en orden distinto: la comparación
 * buena de los dos esquemas (columnas con su posición, índices y `DELETE_RULE` de
 * cada FK) daría una diferencia que no está en el código sino en el historial de
 * cómo se creó la base. Por eso el `AFTER`, que además hace la migración
 * idempotente de verdad: una base creada con un `schema.sql` viejo que ya tuviera
 * la columna al final también queda en su sitio al re-ejecutar esto.
 *
 * Sin transacción, porque el DDL de MariaDB hace commit implícito.
 */
return static function (Database $db): void {
    $definicion = 'DATETIME DEFAULT NULL COMMENT \'Invitación por correo enviada (fase 10); NULL = nunca\'';

    $row = $db->fetch(
        'SELECT COUNT(*) AS c FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
        ['t' => 'form_participants', 'c' => 'invited_at']
    );

    // Se comprueba en information_schema y no con un try/catch del ALTER: un
    // `ADD COLUMN` repetido revienta con 1060 y para el DDL de MariaDB no hay
    // rollback, así que un fallo a medias dejaría la migración sin registrar con
    // la columna ya puesta (y re-ejecutarla, entonces, sí fallaría).
    if ((int) ($row['c'] ?? 0) === 0) {
        $db->query('ALTER TABLE form_participants ADD COLUMN invited_at ' . $definicion . ' AFTER responded_at');
        echo "  [DDL] form_participants.invited_at añadida\n";
    }

    // La posición, se fije o no la columna. Es la misma sentencia y por eso no
    // hace falta un `IF` alrededor: `MODIFY COLUMN` con la definición y la
    // posición exactas no cambia nada de lo que ya está bien.
    $db->query('ALTER TABLE form_participants MODIFY COLUMN invited_at ' . $definicion . ' AFTER responded_at');
};
