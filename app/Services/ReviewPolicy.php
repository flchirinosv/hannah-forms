<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppSettings;
use App\Core\Config;
use App\Models\User;

/**
 * Política de revisión de la INSTANCIA.
 *
 * Antes la revisión era opt-in POR FORMULARIO (`settings.review.enabled`): cada
 * dueño decidía si su encuesta se revisaba. Eso convierte la revisión en un
 * pacto con uno mismo —quien quiere revisión ya se la autoaprueba en la
 * bandeja— y no deja a quien opera la instalación exigirla de verdad. Ahora la
 * decide un administrador en dos mitades:
 *
 *  - `review_required` (ajuste de la instancia, en `/admin`): si está activo,
 *    TODA encuesta pasa por revisión antes de publicarse.
 *  - `forms.publish_without_review` (permiso RBAC): quien lo tenga, exento. Se
 *    concede desde la matriz de `/admin/roles` como cualquier otro permiso.
 *
 * La exención se evalúa sobre el DUEÑO del formulario (`forms.user_id`), no
 * sobre quien actúa: un `admin` con `forms.manage_all` que publica el formulario
 * de otra persona no puede saltarse la revisión por su propio permiso; la regla
 * es «el dueño está exento», no «quien pulsa está exento».
 *
 * `User::hasPermission()` reproduce la regla efectiva de `Auth::can()` (rol
 * propio + roles de los grupos + permisos directos), así que esta puerta no
 * inventa una semántica distinta de la del resto de permisos.
 */
final class ReviewPolicy
{
    /**
     * Permiso que exime de la revisión. Se siembra en `config/permissions.php`
     * (default: solo `admin`) y se edita en `/admin/roles`.
     */
    public const PERMISSION = 'forms.publish_without_review';

    /**
     * ¿La instancia exige revisión a todas las encuestas?
     *
     * La fila de `app_settings` gana si alguien la escribió; si no hay fila
     * manda `REVIEW_REQUIRED` del `.env` (patrón de `registration_enabled`).
     */
    public static function policyOn(): bool
    {
        return AppSettings::bool(
            AppSettings::REVIEW_REQUIRED,
            (bool) Config::get('app.review_required', false)
        );
    }

    /** ¿La instancia avisa por correo a los revisores al enviar a revisión? */
    public static function notifyEmail(): bool
    {
        return AppSettings::bool(
            AppSettings::REVIEW_NOTIFY_EMAIL,
            (bool) Config::get('app.review_notify_email', false)
        );
    }

    /**
     * ¿Este formulario exige revisión antes de publicarse?
     *
     * Solo cuando la política está activa Y su dueño NO tiene el permiso de
     * exención. Con la política apagada (default) nadie necesita revisión, que
     * es el comportamiento histórico.
     *
     * @param array<string, mixed> $form fila de `forms` (necesita `user_id`)
     */
    public static function requiredFor(array $form): bool
    {
        if (!self::policyOn()) {
            return false;
        }

        return !User::hasPermission((int) ($form['user_id'] ?? 0), self::PERMISSION);
    }
}
