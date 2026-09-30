<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Database;
use App\Services\FormSettings;

/**
 * F4 — Acceso restringido por email.
 *
 * Dos cosas distintas y que no se mezclan a propósito:
 *
 *  - **La autorización** es el MODO (`settings.access.mode`), una propiedad del
 *    formulario. `public` no mira nada; `email` y `allowlist` exigen un token.
 *  - **La credencial** es el token, que es lo único que se manda por correo.
 *
 * Reglas que este modelo sostiene:
 *
 *  - **El token va atado a su formulario** (`form_id` en la fila y en el WHERE).
 *    Un enlace de acceso de un formulario no sirve en otro: sin esto, el mismo
 *    token sería una llave maestra de todos los formularios restringidos de la
 *    instalación.
 *  - **Se guarda el hash, no el token** (sha256), por la misma razón que en F2:
 *    un dump o un backup sin cifrar no debe entregar capacidades. La comparación
 *    va por el índice único de `token_hash`, no en PHP.
 *  - **Caducidad de 30 días**, no de un día como el enlace de recuperación: aquí
 *    el enlace no se usa una vez y se tira, se abre, se relee y se vuelve atrás
 *    para corregir respuestas. De un solo uso (como `password_resets`) el
 *    formulario quedaría inservible en el primer F5.
 *  - **Un solo token vivo por (formulario, email)**: pedir un enlace nuevo invalida
 *    el anterior. Es el mismo criterio que «un token pendiente por usuario» de F2,
 *    por el mismo motivo —un correo robado de hace tres meses no debe seguir
 *    valiendo— y además acota la tabla.
 *  - **Revocar es borrar los tokens, no solo quitar el email de la allowlist**: un
 *    enlace ya repartido por WhatsApp seguiría funcionando si solo se borrara la
 *    fila de la lista. `removeEmail()` hace las dos cosas en una transacción.
 *
 * La allowlist es una TABLA y no un array en `settings` porque revocar necesita
 * esa cascada en SQL, y porque el `UNIQUE (form_id, email)` hace que «añadir dos
 * veces» sea un no-op en vez de un duplicado que hay que filtrar en cada lectura.
 *
 * La comparación de emails es en minúsculas (`normalizeEmail`): la collation de
 * MariaDB ya es insensible a mayúsculas, pero normalizar en PHP deja la fila y la
 * query en la misma forma y evita depender de cómo esté creada la tabla.
 */
class FormAccess
{
    /** Días de validez de un enlace de acceso. */
    public const TTL_DAYS = 30;

    private static function db(): Database
    {
        return Database::getInstance(Config::db());
    }

    // ---- Modo ----

    /**
     * ¿Este formulario pide identificación antes de servirlo?
     *
     * Se apoya en que el gate lee settings YA normalizados (`Form::settings()`), y
     * aun así compara contra la lista: un `mode` desconocido se trata como
     * público, que es el mismo criterio que `FormSettings::normalize()`. La
     * alternativa —cerrar por defecto ante un valor raro— dejaría formularios
     * fuera de servicio por una fila corrupta, sin que nadie entienda por qué.
     */
    public static function isRestricted(array $settings): bool
    {
        $mode = (string) ($settings['access']['mode'] ?? FormSettings::ACCESS_PUBLIC);

        return in_array($mode, FormSettings::RESTRICTED_MODES, true);
    }

    public static function mode(array $settings): string
    {
        $mode = (string) ($settings['access']['mode'] ?? FormSettings::ACCESS_PUBLIC);

        return in_array($mode, FormSettings::ACCESS_MODES, true)
            ? $mode
            : FormSettings::ACCESS_PUBLIC;
    }

    // ---- Allowlist ----

    /**
     * Emails autorizados. Con `$search` filtra por coincidencia (lo usa el
     * buscador del panel) y con `$limit` acota: una lista puede ser larga y el
     * panel no necesita las 5000 de una vez.
     *
     * @return list<array{id:int,email:string,created_at:string}>
     */
    public static function allowlistFor(int $formId, string $search = '', int $limit = 200, int $offset = 0): array
    {
        $sql = 'SELECT id, email, created_at FROM form_access_emails WHERE form_id = :fid';
        $params = ['fid' => $formId];

        $needle = trim($search);
        if ($needle !== '') {
            // Se escapan los comodines del usuario: sin esto, buscar `%` o `_`
            // devuelve la lista entera, y el buscador de un panel no es una
            // superficie para LIKE-costoso.
            $sql .= " AND email LIKE :needle ESCAPE '\\\\'";
            $params['needle'] = '%' . self::escapeLike($needle) . '%';
        }

        $sql .= ' ORDER BY email ASC LIMIT :lim OFFSET :off';
        $params['lim'] = max(1, $limit);
        $params['off'] = max(0, $offset);

        return self::db()->fetchAll($sql, $params);
    }

    public static function allowlistCount(int $formId): int
    {
        $row = self::db()->fetch(
            'SELECT COUNT(*) AS c FROM form_access_emails WHERE form_id = :fid',
            ['fid' => $formId]
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Añade un email a la allowlist.
     *
     * @return bool `true` si se añadió; `false` si ya estaba (o el email no es
     *   válido). Que añadir dos veces no duplique la fila es cosa del
     *   `UNIQUE (form_id, email)`, no de una comprobación previa: una lectura
     *   antes del INSERT solo serviría para convertir una carrera en un error
     *   visible en vez de en un no-op.
     */
    public static function addEmail(int $formId, string $email): bool
    {
        $email = self::normalizeEmail($email);
        if (!self::isValidEmail($email)) {
            return false;
        }

        // `INSERT IGNORE` y no try/catch del duplicate key: aquí el duplicado
        // NO es un error sino el resultado esperado de volver a añadir lo mismo.
        return self::db()->execute(
            'INSERT IGNORE INTO form_access_emails (form_id, email) VALUES (:fid, :email)',
            ['fid' => $formId, 'email' => $email]
        ) > 0;
    }

    /**
     * Quita un email de la allowlist Y revoca sus tokens, en una transacción.
     *
     * Las dos cosas o ninguna: si el borrado de tokens quedara para después, un
     * fallo en medio dejaría enlaces repartidos por correo que siguen dando
     * acceso a un formulario que ya no los tiene en la lista. Y el borrado de la
     * fila va PRIMERO porque es la fila que dice si hay algo que revocar.
     */
    public static function removeEmail(int $formId, string $email): bool
    {
        $email = self::normalizeEmail($email);
        if ($email === '') {
            return false;
        }

        $db = self::db();
        $db->beginTransaction();
        try {
            $db->execute(
                'DELETE FROM form_access_emails WHERE form_id = :fid AND email = :email',
                ['fid' => $formId, 'email' => $email]
            );
            $db->execute(
                'UPDATE form_access_tokens SET revoked_at = UTC_TIMESTAMP()
                 WHERE form_id = :fid AND email = :email AND revoked_at IS NULL',
                ['fid' => $formId, 'email' => $email]
            );
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return true;
    }

    /**
     * ¿Este email puede pasar en modo `allowlist`?
     *
     * En modo `email` devuelve siempre `true`: no hay lista, la prueba es que el
     * correo exista. El modo lo decide el que llama, no este método.
     */
    public static function isAllowed(int $formId, string $email, string $mode): bool
    {
        if ($mode === FormSettings::ACCESS_EMAIL) {
            return true;
        }
        if ($mode !== FormSettings::ACCESS_ALLOWLIST) {
            return false;
        }

        $row = self::db()->fetch(
            'SELECT id FROM form_access_emails WHERE form_id = :fid AND email = :email',
            ['fid' => $formId, 'email' => self::normalizeEmail($email)]
        );

        return $row !== null;
    }

    // ---- Tokens ----

    /**
     * Emite un token para (formulario, email) y lo devuelve EN CLARO: el único
     * momento en que existe fuera de la sesión o del correo. Invalida los
     * anteriores de esa misma pareja, por el criterio de «un token vivo».
     */
    public static function issue(int $formId, string $email, ?string $ip = null): string
    {
        $token = self::newToken();
        $email = self::normalizeEmail($email);

        $db = self::db();
        $db->beginTransaction();
        try {
            $db->execute(
                'UPDATE form_access_tokens SET revoked_at = UTC_TIMESTAMP()
                 WHERE form_id = :fid AND email = :email AND revoked_at IS NULL',
                ['fid' => $formId, 'email' => $email]
            );
            $db->execute(
                'INSERT INTO form_access_tokens (form_id, email, token_hash, expires_at, ip_address)
                 VALUES (:fid, :email, :hash, :expires, :ip)',
                [
                    'fid'     => $formId,
                    'email'   => $email,
                    'hash'    => self::hash($token),
                    'expires' => gmdate('Y-m-d H:i:s', time() + self::TTL_DAYS * 86400),
                    'ip'      => $ip !== null && $ip !== '' ? $ip : null,
                ]
            );
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return $token;
    }

    /**
     * Busca un token válido PARA ESTE FORMULARIO y devuelve la fila.
     *
     * El `form_id` va en el WHERE y no se filtra después: es la mitad de la
     * autorización. Un token de otro formulario no existe, aunque el hash esté
     * ahí y caduque mañana.
     */
    public static function findValidToken(int $formId, string $token): ?array
    {
        if (!self::isWellFormedToken($token)) {
            return null;
        }

        $row = self::db()->fetch(
            'SELECT id, form_id, email, expires_at
             FROM form_access_tokens
             WHERE token_hash = :hash AND form_id = :fid
               AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()',
            ['hash' => self::hash($token), 'fid' => $formId]
        );

        return $row;
    }

    /** Revoca todos los tokens vivos de un formulario (cambiar de modo, cerrar). */
    public static function revokeAll(int $formId): int
    {
        return self::db()->execute(
            'UPDATE form_access_tokens SET revoked_at = UTC_TIMESTAMP()
             WHERE form_id = :fid AND revoked_at IS NULL',
            ['fid' => $formId]
        );
    }

    /**
     * ¿Sigue vivo la fila de token que originó un permiso de sesión?
     *
     * Existe por el caso que `findValidToken()` no cubre: alguien que YA pasó
     * por el enlace tiene el permiso en `$_SESSION` y sigue entrando sin volver a
     * presentar el token. Si la sesión solo guardara la caducidad, revocar el
     * enlace o quitar el email de la lista no cerraría esa puerta hasta que
     * expirase el token —y el enlace revocado seguiría sirviendo dentro de los
     * 30 días, que es justo lo contrario de revocar.
     *
     * Se consulta por `id` de fila y no por el token: la sesión no guarda ninguna
     * credencial, solo el puntero a la fila que la autorizó.
     */
    public static function tokenIsLive(int $formId, int $tokenId): bool
    {
        if ($formId <= 0 || $tokenId <= 0) {
            return false;
        }

        $row = self::db()->fetch(
            'SELECT 1 AS ok FROM form_access_tokens
             WHERE id = :id AND form_id = :fid
               AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()',
            ['id' => $tokenId, 'fid' => $formId]
        );

        return $row !== null;
    }

    /** Mantenimiento: tokens caducados o revocados hace tiempo. */
    public static function purgeExpired(int $olderThanDays = 7): int
    {
        return self::db()->execute(
            'DELETE FROM form_access_tokens
             WHERE (expires_at <= UTC_TIMESTAMP())
                OR (revoked_at IS NOT NULL AND revoked_at < (UTC_TIMESTAMP() - INTERVAL :d DAY))',
            ['d' => max(0, $olderThanDays)]
        );
    }

    // ---- Utilidades ----

    /**
     * Minúsculas y sin espacios. Un email es una clave, y `Foo@X.com` y
     * `foo@x.com` tienen que ser la misma fila: si no, la allowlist admite
     * duplicados que parecen personas distintas y revocar uno deja vivo al otro.
     */
    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public static function isValidEmail(string $email): bool
    {
        return $email !== '' && mb_strlen($email) <= 190
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** Mismo formato que `submissions.public_token` y `password_resets`. */
    public static function isWellFormedToken(string $token): bool
    {
        return $token !== '' && preg_match('/^[a-f0-9]{32}$/', $token) === 1;
    }

    private static function newToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
