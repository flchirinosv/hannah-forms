<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Cupos del dashboard editables desde el panel (`/admin/settings`).
 *
 * Mismo patrón que el resto de ajustes de instancia: **la fila guardada gana;
 * si no hay fila, manda el `.env`**. Nada se congela.
 *
 * Dos límites por usuario autenticado (no por IP):
 * - `create_per_hour`: formularios que se pueden crear por hora.
 * - `export_per_hour`: exportaciones que se pueden lanzar por hora.
 *
 * `App\Core\DashboardLimit` los lee a través de este servicio para que el panel
 * muestre el mismo valor que se aplica. Los defaults coinciden con los
 * históricos: 30 / 120.
 */
final class DashboardLimitSettings
{
    public const CREATE_PER_HOUR = 'instance_dash_create_per_hour';
    public const EXPORT_PER_HOUR = 'instance_dash_export_per_hour';

    public const KEYS = [self::CREATE_PER_HOUR, self::EXPORT_PER_HOUR];

    public const DEFAULT_CREATE_PER_HOUR = 30;
    public const DEFAULT_EXPORT_PER_HOUR = 120;

    public const WINDOW = 3600;

    /**
     * @return array{stored_create_per_hour:string, stored_export_per_hour:string, env_create_per_hour:int, env_export_per_hour:int, create_per_hour:int, export_per_hour:int}
     */
    public static function all(): array
    {
        return [
            'stored_create_per_hour' => (string) (AppSettings::get(self::CREATE_PER_HOUR) ?? ''),
            'stored_export_per_hour' => (string) (AppSettings::get(self::EXPORT_PER_HOUR) ?? ''),
            'env_create_per_hour'    => self::envCreatePerHour(),
            'env_export_per_hour'    => self::envExportPerHour(),
            'create_per_hour'        => self::createPerHour(),
            'export_per_hour'        => self::exportPerHour(),
        ];
    }

    public static function createPerHour(): int
    {
        $stored = AppSettings::get(self::CREATE_PER_HOUR);
        if ($stored !== null && trim($stored) !== '' && preg_match('/^\d+$/', trim($stored)) === 1) {
            $v = (int) trim($stored);
            if ($v >= 1) {
                return $v;
            }
        }

        return self::envCreatePerHour();
    }

    public static function exportPerHour(): int
    {
        $stored = AppSettings::get(self::EXPORT_PER_HOUR);
        if ($stored !== null && trim($stored) !== '' && preg_match('/^\d+$/', trim($stored)) === 1) {
            $v = (int) trim($stored);
            if ($v >= 1) {
                return $v;
            }
        }

        return self::envExportPerHour();
    }

    private static function envCreatePerHour(): int
    {
        $v = (int) Env::get('DASH_CREATE_PER_HOUR', (string) self::DEFAULT_CREATE_PER_HOUR);
        if ($v < 1) {
            $v = self::DEFAULT_CREATE_PER_HOUR;
        }
        return $v;
    }

    private static function envExportPerHour(): int
    {
        $v = (int) Env::get('DASH_EXPORT_PER_HOUR', (string) self::DEFAULT_EXPORT_PER_HOUR);
        if ($v < 1) {
            $v = self::DEFAULT_EXPORT_PER_HOUR;
        }
        return $v;
    }

    /**
     * @return array{ok:bool,error:?string}
     */
    public static function save(array $data): array
    {
        $create = trim((string) ($data['create_per_hour'] ?? ''));
        $export = trim((string) ($data['export_per_hour'] ?? ''));

        if ($create !== '' && (preg_match('/^\d+$/', $create) !== 1 || (int) $create < 1)) {
            return ['ok' => false, 'error' => 'Los formularios por hora tienen que ser un número entero mayor que 0.'];
        }
        if ($export !== '' && (preg_match('/^\d+$/', $export) !== 1 || (int) $export < 1)) {
            return ['ok' => false, 'error' => 'Las exportaciones por hora tienen que ser un número entero mayor que 0.'];
        }

        if (!AppSettings::set(self::CREATE_PER_HOUR, $create === '' ? null : $create)) {
            return ['ok' => false, 'error' => 'No se pudo guardar la configuración (¿migraciones?).'];
        }
        if (!AppSettings::set(self::EXPORT_PER_HOUR, $export === '' ? null : $export)) {
            return ['ok' => false, 'error' => 'No se pudo guardar la configuración (¿migraciones?).'];
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
