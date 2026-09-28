<?php

declare(strict_types=1);

/**
 * Migración 0001: tabla rate_limits.
 *
 * Crea la tabla de rate limiting (anti fuerza bruta / abuso) usada por
 * App\Core\RateLimiter. Es idempotente (CREATE TABLE IF NOT EXISTS) para
 * poder aplicarse tanto en instalaciones nuevas como existentes.
 *
 * El script espera que la conexión ya esté configurada. Se invoca a través
 * de database/migrate.php.
 */

use App\Core\Config;
use App\Core\Database;

return static function (): void {
    $db = Database::getInstance(Config::db());

    $db->query(<<<SQL
CREATE TABLE IF NOT EXISTS rate_limits (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scope       VARCHAR(30)  NOT NULL,
    identifier  VARCHAR(64)  NOT NULL,
    attempts    INT UNSIGNED NOT NULL DEFAULT 0,
    window_start TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rate_scope_identifier (scope, identifier)
) ENGINE=InnoDB
SQL);

    $db->query('CREATE INDEX IF NOT EXISTS idx_rate_identifier ON rate_limits (identifier, window_start)');
};
