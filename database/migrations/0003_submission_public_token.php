<?php

declare(strict_types=1);

use App\Core\Database;

/**
 * Migración 0003: token público por submission — cierra el IDOR de
 * `GET /f/{slug}/thanks?sid=N`.
 *
 * El `sid` era el AUTO_INCREMENT de `submissions`: un entero corto y
 * correlativo que cualquiera podía recorrer. La página de thanks es PÚBLICA y
 * sin sesión, así que bastaba con `/f/<slug>/thanks?sid=1`, `?sid=2`… para leer
 * la respuesta de cada persona que contestó ese formulario, y la vista del quiz
 * muestra la respuesta literal ajena (`Tu respuesta: PATCH`) junto a la
 * correcta. La comprobación de que la submission pertenece al formulario
 * impedía el cruce entre formularios, pero no el acceso a las respuestas ajenas
 * DENTRO del mismo formulario.
 *
 * Se sustituye el id por una capacidad opaca: `public_token` (128 bits de
 * `random_bytes`), único e indexado. Quien tiene el token es la sesión que
 * acaba de enviar el formulario.
 *
 * `public_token` es NULLABLE a propósito: las submissions anteriores no lo
 * tienen y se quedan sin puntuación en el thanks, que es el fallo seguro (se ve
 * el mensaje de agradecimiento y nada más). Se hace backfill para que las
 * antiguas respuestas conserven su resultado sin exponerlas.
 *
 * Idempotente (columna e índice solo si no existen) y sin transacción, porque el
 * DDL hace commit implícito en MariaDB (convención del proyecto).
 */

return static function (Database $db): void {
    $columnExists = static function (string $table, string $column) use ($db): bool {
        $row = $db->fetch(
            'SELECT COUNT(*) AS c FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
            ['t' => $table, 'c' => $column]
        );
        return (int) ($row['c'] ?? 0) > 0;
    };

    $indexExists = static function (string $table, string $index) use ($db): bool {
        $row = $db->fetch(
            'SELECT COUNT(*) AS c FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i',
            ['t' => $table, 'i' => $index]
        );
        return (int) ($row['c'] ?? 0) > 0;
    };

    $added = false;
    if (!$columnExists('submissions', 'public_token')) {
        $db->query('ALTER TABLE submissions ADD COLUMN public_token CHAR(32) DEFAULT NULL AFTER user_agent');
        $added = true;
    }

    if (!$indexExists('submissions', 'idx_submissions_public_token')) {
        $db->query('CREATE UNIQUE INDEX idx_submissions_public_token ON submissions (public_token)');
    }

    // Backfill ÚNICO: un token por fila antigua, para que su resultado siga
    // siendo visible para quien ya tenía el enlace. Solo si se acaba de añadir
    // la columna, igual que en la 0002.
    if ($added) {
        $ids = $db->fetchAll('SELECT id FROM submissions WHERE public_token IS NULL ORDER BY id');
        foreach ($ids as $row) {
            $db->execute(
                'UPDATE submissions SET public_token = :t WHERE id = :id',
                ['t' => bin2hex(random_bytes(16)), 'id' => (int) $row['id']]
            );
        }
    }
};
