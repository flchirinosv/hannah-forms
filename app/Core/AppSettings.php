<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Ajustes de la instalación que se cambian desde el panel (`app_settings`).
 *
 * El patrón es «la fila guardada gana; si no hay fila, manda el `.env`». Es lo
 * que hace falta para un interruptor como el registro público: quien administra
 * la instancia no siempre tiene acceso al servidor, pero reescribir el `.env`
 * desde un request obligaría a reiniciar PHP-FPM (o a que el cambio no se viera
 * hasta el siguiente request, según la SAPI) y a tener el fichero de
 * configuración en un directorio escribible por el web. Con una tabla, la
 * escritura es un `UPDATE` y el valor cambia en la siguiente petición.
 *
 * Por qué `bool()` normaliza «cualquier cosa» a `false` en vez de lanzar: el
 * valor viene de un `<input type=checkbox>` y de un `.env` escrito a mano. Un
 * `parse` estricto convertiría un `sí` o un `1` mal puesto en un error 500 en
 * una página que solo quiere enseñar un formulario. La comparación es de
 * strings explícitos, y `1`/`on`/`true`/la clave presente en la tabla = encendido.
 *
 * `Config` y `AppSettings` NO se mezclan: el primero se congela al leer `.env`
 * una vez (y por eso tiene un único seam, `setForTesting()`), y este se escribe
 * en caliente. Un ajuste que cambia en caliente no puede vivir en un array
 * congelado.
 */
final class AppSettings
{
    /** Registro público abierto. La fila ausente devuelve el valor de `.env`. */
    public const REGISTRATION = 'registration_enabled';

    /**
     * Política de revisión de la instancia: si está encendida, toda encuesta
     * pasa por revisión antes de publicarse (salvo dueños exentos por el
     * permiso `forms.publish_without_review`). Fila ausente = `.env`.
     */
    public const REVIEW_REQUIRED = 'review_required';

    /** Aviso por correo a los revisores cuando alguien envía a revisión. */
    public const REVIEW_NOTIFY_EMAIL = 'review_notify_email';

    /**
     * Override de test, con la misma forma que el de `Config`.
     *
     * Es un seam y no un setter: la app no lo llama nunca. Existe porque el
     * runner comparte BD entre tests y dejar escrita una fila real
     * convertiría un test de «el panel abre el registro» en un efecto para
     * todos los que vinieran detrás.
     *
     * @var array<string, mixed>|null `null` = sin override (se lee la tabla)
     */
    private static ?array $overrides = null;

    /**
     * Lee un ajuste crudo, o `null` si no hay fila guardada.
     *
     * `null` es distinto de `'0'`: es lo que permite que la fila ausente
     * devuelva el default en vez de escribir un valor vacío.
     */
    public static function get(string $key): ?string
    {
        if (self::$overrides !== null) {
            return array_key_exists($key, self::$overrides)
                ? self::stringify(self::$overrides[$key])
                : null;
        }

        $db = self::db();
        if ($db === null) {
            return null;
        }

        try {
            $row = $db->fetch(
                'SELECT value FROM app_settings WHERE key_name = :k LIMIT 1',
                ['k' => $key]
            );
        } catch (\Throwable $e) {
            // Instancia sin la migración aplicada (o sin BD): el default manda.
            return null;
        }

        if ($row === null) {
            return null;
        }

        return $row['value'] === null ? null : (string) $row['value'];
    }

    /**
     * Lee un interruptor.
     *
     * Sin fila guardada devuelve `$default`, que es el valor de `.env`: es el
     * orden correcto porque `.env` es lo que el operador controla desde el
     * despliegue, y el panel solo lo sustituye cuando alguien lo toca
     * explícitamente.
     */
    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }

        $normalizado = strtolower(trim($value));
        if ($normalizado === '') {
            return $default;
        }

        return in_array($normalizado, ['1', 'true', 'on', 'yes', 'si', 'sí'], true);
    }

    /**
     * Escribe un interruptor.
     *
     * `INSERT ... ON DUPLICATE KEY UPDATE` en vez de `UPDATE` seguido de
     * `INSERT si no hay filas`: entre las dos consultas cabe otra petición, y
     * quien llegara segundo se llevaría un error de clave duplicada que en una
     * tabla de UN interruptor no puede significar nada útil.
     */
    public static function setBool(string $key, bool $value): bool
    {
        $db = self::db();
        if ($db === null) {
            return false;
        }

        try {
            $db->execute(
                'INSERT INTO app_settings (key_name, value) VALUES (:k, :v)
                 ON DUPLICATE KEY UPDATE value = VALUES(value)',
                ['k' => $key, 'v' => $value ? '1' : '0']
            );
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    /**
     * Escribe un ajuste de TEXTO.
     *
     * A diferencia de `setBool()`, aquí `null` y `''` son cosas distintas:
     * `null` borra la fila (el ajuste vuelve a su default, que es el `.env`) y
     * `''` guarda un valor vacío explícito. Es la diferencia que necesita un
     * ajuste opcional como el color de la instancia: «no lo toques» (sin fila)
     * no es lo mismo que «déjalo en blanco».
     */
    public static function set(string $key, ?string $value): bool
    {
        if ($value === null) {
            return self::delete($key);
        }

        $db = self::db();
        if ($db === null) {
            return false;
        }

        try {
            $db->execute(
                'INSERT INTO app_settings (key_name, value) VALUES (:k, :v)
                 ON DUPLICATE KEY UPDATE value = VALUES(value)',
                ['k' => $key, 'v' => $value]
            );
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    /**
     * Borra un ajuste: la fila ausente vuelve a devolver el default.
     *
     * Devuelve `true` tanto si borró una fila como si no había ninguna: el
     * objetivo (la fila sin existir) es el mismo en los dos casos.
     */
    public static function delete(string $key): bool
    {
        $db = self::db();
        if ($db === null) {
            return false;
        }

        try {
            $db->execute('DELETE FROM app_settings WHERE key_name = :k', ['k' => $key]);
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $values */
    public static function setForTesting(array $values): void
    {
        self::$overrides = $values;
    }

    public static function forgetForTesting(): void
    {
        self::$overrides = null;
    }

    private static function stringify(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    /**
     * La BD es opcional aquí, a diferencia de en `Cache` o `RateLimiter`: sin
     * conexión no hay nada que leer y el default es una respuesta legítima.
     */
    private static function db(): ?Database
    {
        try {
            return Database::getInstance(Config::db());
        } catch (\Throwable $e) {
            return null;
        }
    }
}
