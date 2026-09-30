<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Database;

/**
 * F2 — Tokens de recuperación de contraseña.
 *
 * Reglas que este modelo sostiene, y que son las que hacen que el flujo no sea
 * un agujero:
 *
 *  - **Un solo uso.** `markUsed()` es idempotente (solo marca donde `used_at
 *    IS NULL`), así que un segundo intento con el mismo enlace no cambia nada.
 *  - **Caducidad corta** (1 hora) y comparación en SQL contra `UTC_TIMESTAMP()`,
 *    que es el contrato horario del proyecto: la BD está en UTC.
 *  - **Se guarda el hash del token**, nunca el token. Es la misma razón por la
 *    que `users.password_hash` no guarda la contraseña: si alguien lee la tabla
 *    (un dump, una inyección, un backup en un bucket sin cifrar) no puede
 *    autenticarse con ella. Por eso la comparación se hace por hash y el
 *    índice es `UNIQUE` sobre `token_hash`.
 *  - **Un token pendiente por usuario**: al pedir uno nuevo se invalidan los
 *    anteriores, que ya no le sirven a nadie. Acota la tabla y evita que un
 *    correo robado de hace tres días siga valiendo.
 *
 * El token se compara por hash en la BD (índice único) y no en PHP, para que
 * el tiempo de respuesta no dependa de cuántos tokens haya.
 */
class PasswordReset
{
    /** Horas de validez de un enlace de recuperación. */
    public const TTL_HOURS = 1;

    private static function db(): Database
    {
        return Database::getInstance(Config::db());
    }

    /**
     * Emite un token nuevo para el usuario y devuelve el token EN CLARO (el
     * único momento en que existe fuera de la sesión del navegador: se manda
     * por correo). Los pendientes anteriores se invalidan en la misma llamada.
     */
    public static function issue(int $userId, ?string $ip = null): string
    {
        // 16 bytes aleatorios = 128 bits, en hex (32 caracteres), el mismo
        // formato que `submissions.public_token` para no tener dos formatos de
        // token en el código.
        $token = bin2hex(random_bytes(16));

        self::db()->execute(
            'DELETE FROM password_resets WHERE user_id = :uid AND used_at IS NULL',
            ['uid' => $userId]
        );

        $db = self::db();
        $db->execute(
            'INSERT INTO password_resets (user_id, token_hash, expires_at, ip_address)
             VALUES (:uid, :hash, :expires, :ip)',
            [
                'uid'     => $userId,
                'hash'    => self::hash($token),
                'expires' => gmdate('Y-m-d H:i:s', time() + self::TTL_HOURS * 3600),
                'ip'      => $ip !== null && $ip !== '' ? $ip : null,
            ]
        );

        return $token;
    }

    /**
     * Busca un token válido (no usado y no caducado) y devuelve la fila con los
     * datos del usuario.
     *
     * No marca nada: el consumo va en la transacción que cambia la contraseña
     * (`AccountController::doReset`), para que dos peticiones simultáneas con el
     * mismo enlace no puedan prevailas las dos.
     */
    public static function findValid(string $token): ?array
    {
        if (!self::isWellFormedToken($token)) {
            return null;
        }

        $row = self::db()->fetch(
            'SELECT r.id, r.user_id, u.email, u.name, u.is_active
             FROM password_resets r
             INNER JOIN users u ON u.id = r.user_id
             WHERE r.token_hash = :hash
               AND r.used_at IS NULL
               AND r.expires_at > UTC_TIMESTAMP()',
            ['hash' => self::hash($token)]
        );

        return $row === null ? null : $row;
    }

    /**
     * Marca el token como usado. Idempotente: solo marca lo que sigue sin usar,
     * de modo que un reintento del POST no falla y no reabre nada.
     *
     * @return int filas afectadas (0 = ya estaba usado)
     */
    public static function markUsed(int $id): int
    {
        return self::db()->execute(
            'UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE id = :id AND used_at IS NULL',
            ['id' => $id]
        );
    }

    /**
     * Invalida todos los tokens pendientes de un usuario. Se llama al cambiar la
     * contraseña desde la sesión: cualquier enlace de recuperación que la
     * persona hubiera pedido antes (y que por lo tanto puede estar en la bandeja
     * de otra persona) deja de servir para nada.
     */
    public static function invalidateFor(int $userId): void
    {
        self::db()->execute(
            'UPDATE password_resets SET used_at = UTC_TIMESTAMP()
             WHERE user_id = :uid AND used_at IS NULL',
            ['uid' => $userId]
        );
    }

    /**
     * Mantenimiento: borra tokens ya usados o caducados. Los pendientes nunca
     * se caducan solos, así que esto es para el cron.
     */
    public static function purgeExpired(): int
    {
        return self::db()->execute(
            'DELETE FROM password_resets
             WHERE used_at IS NOT NULL
                OR expires_at <= UTC_TIMESTAMP()'
        );
    }

    /**
     * Un token es 32 caracteres hex. Se comprueba ANTES de tocar la BD para no
     * gastar una consulta con basura, y porque un `hash()` de una cadena de 10
     * MB sería trabajo inútil.
     */
    public static function isWellFormedToken(string $token): bool
    {
        return $token !== '' && preg_match('/^[a-f0-9]{32}$/', $token) === 1;
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
