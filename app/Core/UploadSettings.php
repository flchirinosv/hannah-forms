<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Límites de subida de archivos editables desde el panel (`/admin/settings`).
 *
 * Mismo patrón que marca (AG2), correo (AG3), identidad (AG4) y Turnstile (AG5):
 * **la fila guardada gana; si no hay fila, manda el `.env`** (`app.uploads.*`).
 * Nada se congela.
 *
 * Dos límites a nivel de instancia:
 * - `max_size_mb`: tamaño máximo por archivo (en MB).
 * - `max_files`: número máximo de archivos por campo.
 *
 * El campo de formulario SOLO puede restringir (el mínimo entre el global y el
 * del campo), nunca ampliarlo. El `post-max` del middleware, `FillController`
 * y `Uploader` deben leer la MISMA fuente para que los mensajes de error sean
 * coherentes. Además `Brand::saveUpload()` usa este mismo límite para el logo
 * y el favicon.
 *
 * El límite **efectivo** que se muestra en el panel tiene que tener en cuenta
 * `upload_max_filesize` de PHP (lo hace `Uploader::maxSizeMb()`), pero el valor
 * guardado sigue siendo el configurado por la instancia.
 */
final class UploadSettings
{
    /** Tamaño máximo por archivo en MB. */
    public const MAX_SIZE_MB = 'instance_upload_max_size_mb';

    /** Número máximo de archivos por campo. */
    public const MAX_FILES = 'instance_upload_max_files';

    /** Claves que gestiona esta clase. */
    public const KEYS = [self::MAX_SIZE_MB, self::MAX_FILES];

    public const DEFAULT_MAX_SIZE_MB = 10;
    public const DEFAULT_MAX_FILES = 5;

    /**
     * Vista completa para el panel: lo GUARDADO, lo del `.env` (para «Por defecto»)
     * y el valor EFECTIVO resuelto.
     *
     * @return array{stored_max_size_mb:string, stored_max_files:string, env_max_size_mb:int, env_max_files:int, max_size_mb:int, max_files:int}
     */
    public static function all(): array
    {
        return [
            'stored_max_size_mb' => (string) (AppSettings::get(self::MAX_SIZE_MB) ?? ''),
            'stored_max_files'   => (string) (AppSettings::get(self::MAX_FILES) ?? ''),
            'env_max_size_mb'    => self::envMaxSizeMb(),
            'env_max_files'      => self::envMaxFiles(),
            'max_size_mb'        => self::maxSizeMb(),
            'max_files'          => self::maxFiles(),
        ];
    }

    public static function maxSizeMb(): int
    {
        $stored = AppSettings::get(self::MAX_SIZE_MB);
        if ($stored !== null && trim($stored) !== '' && preg_match('/^\d+$/', trim($stored)) === 1) {
            $v = (int) trim($stored);
            if ($v >= 1) {
                return $v;
            }
        }

        return self::envMaxSizeMb();
    }

    public static function maxFiles(): int
    {
        $stored = AppSettings::get(self::MAX_FILES);
        if ($stored !== null && trim($stored) !== '' && preg_match('/^\d+$/', trim($stored)) === 1) {
            $v = (int) trim($stored);
            if ($v >= 1) {
                return $v;
            }
        }

        return self::envMaxFiles();
    }

    private static function envMaxSizeMb(): int
    {
        $v = (int) Config::get('app.uploads.max_size_mb', self::DEFAULT_MAX_SIZE_MB);
        return $v > 0 ? $v : self::DEFAULT_MAX_SIZE_MB;
    }

    private static function envMaxFiles(): int
    {
        $v = (int) Config::get('app.uploads.max_files', self::DEFAULT_MAX_FILES);
        return $v > 0 ? $v : self::DEFAULT_MAX_FILES;
    }

    /**
     * Guarda los límites. Devuelve `['ok' => bool, 'error' => ?string]`.
     *
     * Texto vacío BORRA la fila (vuelve al `.env` / default).
     * Valor inválido (0, negativo, no numérico) no se guarda.
     */
    public static function save(array $data): array
    {
        $maxSize = trim((string) ($data['max_size_mb'] ?? ''));
        $maxFiles = trim((string) ($data['max_files'] ?? ''));

        if ($maxSize !== '' && (preg_match('/^\d+$/', $maxSize) !== 1 || (int) $maxSize < 1)) {
            return ['ok' => false, 'error' => 'El tamaño máximo tiene que ser un número entero mayor que 0.'];
        }
        if ($maxFiles !== '' && (preg_match('/^\d+$/', $maxFiles) !== 1 || (int) $maxFiles < 1)) {
            return ['ok' => false, 'error' => 'El número máximo de archivos tiene que ser un número entero mayor que 0.'];
        }

        $writes = [
            self::MAX_SIZE_MB => $maxSize === '' ? null : $maxSize,
            self::MAX_FILES   => $maxFiles === '' ? null : $maxFiles,
        ];

        foreach ($writes as $key => $value) {
            if (!AppSettings::set($key, $value)) {
                return ['ok' => false, 'error' => 'No se pudo guardar la configuración (¿migraciones?).'];
            }
        }

        return ['ok' => true, 'error' => null];
    }

    public static function setForTesting(?array $values): void
    {
        AppSettings::setForTesting($values ?? []);
    }

    public static function forgetForTesting(): void
    {
        AppSettings::forgetForTesting();
    }
}
