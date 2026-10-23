<?php

declare(strict_types=1);

use App\Core\Database;

/**
 * Migración 0021: pasar el listado de participantes a 4 columnas fijas
 * Cédula / Nombre completo / Correo / Teléfono. Se elimina el modo indicador.
 */
return static function (Database $db): void {
    // Añadir telefono si no existe
    $existeTelefono = $db->fetch(
        'SELECT COUNT(*) AS c FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
        ['t' => 'form_participants', 'c' => 'telefono']
    );
    if (((int) ($existeTelefono['c'] ?? 0)) === 0) {
        $db->query("ALTER TABLE form_participants ADD COLUMN telefono VARCHAR(190) NOT NULL DEFAULT '' AFTER correo");
        echo "  [DDL] form_participants.telefono añadida\n";
    }

    // Eliminar indicador si existe
    $existeIndicador = $db->fetch(
        'SELECT COUNT(*) AS c FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
        ['t' => 'form_participants', 'c' => 'indicador']
    );
    if (((int) ($existeIndicador['c'] ?? 0)) === 1) {
        // Intentar quitar índice si lo hubiera (no es crítico si no existe)
        try {
            $db->query('ALTER TABLE form_participants DROP COLUMN indicador');
        } catch (\Throwable $e) {
            // ignorar
        }
        echo "  [DDL] form_participants.indicador eliminada\n";
    }

    // Actualizar comentarios si se desea (no crítico)
};
