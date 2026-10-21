<?php

declare(strict_types=1);

namespace App\Core;

/**
 * F5.3 — Límites del dashboard por USUARIO (crear formularios / exportar datos).
 *
 * El identificador es el id de usuario autenticado, NO la IP: un office detrás
 * de una NAT comparte IP, y estos límites protegen a la CUENTA de abusos
 * (barrer el almacén con exportaciones o spamear formularios), no al servidor
 * de una sola dirección. El middleware `auth` garantiza que llegamos aquí con
 * sesión; aún así, un `Auth::id()` nulo se deja pasar (en el flujo real la
 * ruta no se alcanza sin sesión, y no queremos compartir un contador "" entre
 * peticiones anónimas de tests).
 *
 * Límites configurables desde `/admin/settings` (`app_settings`) con
 * precedencia fila→`.env` → default. Defaults 30 y 120 por hora.
 */
final class DashboardLimit
{
    public const WINDOW = 3600;

    public static function createAllowed(): bool
    {
        return self::isAllowed('dash:create', DashboardLimitSettings::createPerHour());
    }

    public static function exportAllowed(): bool
    {
        return self::isAllowed('dash:export', DashboardLimitSettings::exportPerHour());
    }

    private static function isAllowed(string $scope, int $max): bool
    {
        $userId = Auth::id();
        if ($userId === null) {
            // Sin sesión la ruta no debería alcanzarse (middleware `auth`).
            // Si un test la invoca directo así, que no comparta el contador "".
            return true;
        }

        $identifier = (string) $userId;

        $allowed = RateLimiter::isAllowed($scope, $identifier, $max, self::WINDOW);
        if ($allowed) {
            // Un intento de creación cuenta aunque luego falle la plantilla: la
            // ventana es un techo de trabajo, no una contabilidad transaccional.
            RateLimiter::recordHit($scope, $identifier, self::WINDOW);
        } else {
            SecurityLog::record(
                'rate_limit.dash_' . ($scope === 'dash:create' ? 'create' : 'export'),
                'techo alcanzado (usuario ' . $identifier . ', ' . $max . '/h)'
            );
        }
        return $allowed;
    }
}