<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Cifrado simétrico de secretos que viven en la BD (`app_settings`).
 *
 * Problema que resuelve: hasta ahora los secretos (contraseña SMTP, clave de
 * Turnstile) vivían SOLO en `.env`, fuera de la base de datos. Al moverlos a la
 * UI para que se puedan editar sin SSH, pasan a estar en `app_settings`… y en
 * claro en cada backup, en cada `mysqldump`, en cada phpMyAdmin. Cifrarlos hace
 * que quien robe SOLO la BD no saque la contraseña.
 *
 * La llave (`APP_KEY`) vive aparte: en `.env`, nunca en la BD. Es la analogía
 * de la caja fuerte —la caja (columna `value`) se guarda cerrada en la BD y la
 * llave (línea `APP_KEY`) en el fichero de configuración—. Si la llave
 * estuviera en la BD, quien robara la BD tendría las dos cosas.
 *
 * Algoritmo: **AES-256-GCM** (`openssl`), el estándar con autenticación
 * (detecta manipulación del criptograma). Formato del valor almacenado:
 *
 *     base64( iv(12) || tag(16) || ciphertext )
 *
 * Sin `APP_KEY` el cifrado queda INACTIVO (`isAvailable() === false`) y los
 * llamadores caen al valor de `.env`: una instalación que no genera la clave
 * sigue funcionando, solo que sin secretos editables por UI.
 *
 * `decrypt()` NUNCA lanza: una clave cambiada, un dato corrupto o un valor en
 * claro devuelven `null`, que el llamador interpreta como «no hay secreto
 * utilizable» (y vuelve al default).
 */
final class Secret
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_LEN = 12;
    private const TAG_LEN = 16;
    private const KEY_LEN = 32;

    /**
     * Override de test con la misma forma que `Config`: `null` = se lee `APP_KEY`.
     */
    private static ?string $keyOverride = null;

    /**
     * True si hay una `APP_KEY` válida (32 bytes) y `openssl` disponible.
     */
    public static function isAvailable(): bool
    {
        return self::key() !== null;
    }

    /**
     * Cifra un texto. Devuelve `null` si no hay clave o el cifrado falla.
     *
     * Un texto vacío se cifra igual (no se trata como «borrar»): quien quiera
     * borrar un secreto llama a `AppSettings::delete()`.
     */
    public static function encrypt(string $plain): ?string
    {
        $key = self::key();
        if ($key === null) {
            return null;
        }

        $iv = random_bytes(self::IV_LEN);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LEN);
        if ($cipher === false) {
            return null;
        }

        return base64_encode($iv . $tag . $cipher);
    }

    /**
     * Descifra un valor almacenado. `null` = no se pudo descifrar (clave
     * cambiada, dato corrupto o valor que nunca estuvo cifrado).
     */
    public static function decrypt(string $stored): ?string
    {
        $key = self::key();
        if ($key === null) {
            return null;
        }

        $raw = base64_decode($stored, true);
        if ($raw === false || strlen($raw) < self::IV_LEN + self::TAG_LEN) {
            return null;
        }

        $iv = substr($raw, 0, self::IV_LEN);
        $tag = substr($raw, self::IV_LEN, self::TAG_LEN);
        $cipher = substr($raw, self::IV_LEN + self::TAG_LEN);

        $plain = openssl_decrypt($cipher, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? null : $plain;
    }

    /**
     * True si el valor almacenado se puede descifrar con la clave actual.
     * Útil para saber si un secreto guardado sigue siendo utilizable.
     */
    public static function canDecrypt(?string $stored): bool
    {
        return $stored !== null && $stored !== '' && self::decrypt($stored) !== null;
    }

    /** Genera una `APP_KEY` nueva, en el formato que espera `key()`. */
    public static function generateKey(): string
    {
        return base64_encode(random_bytes(self::KEY_LEN));
    }

    /**
     * Fija la clave para un test. `null` vuelve a leer `APP_KEY`.
     */
    public static function setKeyForTesting(?string $key): void
    {
        self::$keyOverride = $key;
    }

    /**
     * La clave en crudo (32 bytes) o `null` si `APP_KEY` falta o no es válida.
     *
     * Acepta base64 (lo que genera `generateKey()`) y, como conveniencia, una
     * cadena de exactamente 32 caracteres (para quien pegue una clave «a mano»).
     */
    private static function key(): ?string
    {
        if (self::$keyOverride !== null) {
            return self::normalize(self::$keyOverride);
        }

        if (!extension_loaded('openssl')) {
            return null;
        }

        return self::normalize((string) Config::get('app.key', ''));
    }

    private static function normalize(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $decoded = base64_decode($raw, true);
        if ($decoded !== false && strlen($decoded) === self::KEY_LEN) {
            return $decoded;
        }

        // Clave ya en crudo (32 bytes exactos): aceptarla tal cual.
        if (strlen($raw) === self::KEY_LEN) {
            return $raw;
        }

        return null;
    }
}
