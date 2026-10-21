<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Registro de eventos de seguridad (2026-09-28, tabla `security_log`).
 *
 * Contrato: `record()` NUNCA puede romper el request ni contaminar el flujo.
 * El log es de auditoría, no un componente del negocio: ante cualquier fallo
 * (BD caída, serialización, lo que sea) registra en error_log y sigue.
 *
 * La `ip` es la real del request (`Request::ip()`): con `TRUST_PROXY=false` es
 * `REMOTE_ADDR`, y los eventos de auth/rate-limit van con ese valor aunque el
 * cliente haya intentado engañar con `X-Forwarded-For`.
 */
final class SecurityLog
{
    private const MAX_DETAIL = 500;

    /**
     * @param string $event  clave corta del evento (ver los 'auth.*' etc.)
     * @param string $detail detalle de 1 línea (escapado en la vista, aquí crudo)
     */
    public static function record(string $event, string $detail = ''): void
    {
        if (!preg_match('/^[a-z0-9_.]{2,60}$/', $event)) {
            error_log('[SecurityLog] evento con formato inválido: ' . $event);
            return;
        }
        if (mb_strlen($detail, 'UTF-8') > self::MAX_DETAIL) {
            $detail = mb_substr($detail, 0, self::MAX_DETAIL, 'UTF-8');
        }

        try {
            $db = Database::getInstance(Config::db());

            // `Auth::id()` es un valor de sesión: puede apuntar a un usuario que
            // ya no existe (o a uno ficticio en los tests). Con un `user_id`
            // inexistente el INSERT reventaría por la FK `fk_sl_user` y el evento
            // se perdería. La auditoría NO puede depender de eso: si la sesión no
            // referencia un usuario real, la fila se guarda con `user_id = NULL`.
            $userId = null;
            $authId = Auth::id();
            if ($authId !== null) {
                $exists = $db->fetch('SELECT 1 AS ok FROM users WHERE id = :id', ['id' => $authId]);
                if ($exists !== null) {
                    $userId = (int) $authId;
                }
            }

            $db->execute(
                'INSERT INTO security_log (event, detail, ip, user_id) VALUES (:event, :detail, :ip, :user)',
                [
                    'event'  => $event,
                    'detail' => $detail !== '' ? $detail : null,
                    'ip'     => Request::ip() !== '' ? Request::ip() : null,
                    'user'   => $userId,
                ]
            );
        } catch (\Throwable $e) {
            error_log('[SecurityLog] no se pudo registrar ' . $event . ': ' . $e->getMessage());
        }
    }

    /**
     * Eventos de una página, con paginación server-side.
     *
     * `$q` (F6) es el buscador del panel: casa con el detalle, la IP o el
     * nombre de usuario. Va **después** de `$page`/`$perPage` para no romper las
     * llamadas posicionales que ya existían.
     *
     * @return array{items: array, total: int, page: int, per_page: int, pages: int}
     */
    public static function recent(string $event = '', int $page = 1, int $perPage = 50, string $q = ''): array
    {
        $perPage = max(1, min(200, $perPage));
        $page = max(1, $page);

        $conds = [];
        $params = [];
        if ($event !== '' && in_array($event, self::knownEvents(), true)) {
            $conds[] = 'sl.event = :event';
            $params['event'] = $event;
        }

        // Tres marcadores distintos (no `:q` repetido): `Database` va con
        // `ATTR_EMULATE_PREPARES = false` y un marcador repetido lanza HY093. El
        // `%` y el `_` de lo escrito son comodines, no texto: se escapan o buscar
        // «50%» devuelve todo.
        $q = trim($q);
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $conds[] = '(sl.detail LIKE :q1 OR sl.ip LIKE :q2 OR u.name LIKE :q3)';
            $params['q1'] = $like;
            $params['q2'] = $like;
            $params['q3'] = $like;
        }

        // El `JOIN` va también en el `COUNT` porque el buscador mira `u.name`.
        $where = $conds === [] ? '' : ' WHERE ' . implode(' AND ', $conds);

        $db = Database::getInstance(Config::db());
        $row = $db->fetch(
            'SELECT COUNT(*) AS c FROM security_log sl LEFT JOIN users u ON u.id = sl.user_id' . $where,
            $params
        );
        $total = (int) ($row['c'] ?? 0);
        $pages = (int) max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);

        $limit = $perPage;
        $offset = ($page - 1) * $perPage;
        $items = $db->fetchAll(
            "SELECT sl.*, u.name AS user_name
             FROM security_log sl
             LEFT JOIN users u ON u.id = sl.user_id$where
             ORDER BY sl.id DESC
             LIMIT $limit OFFSET $offset",
            $params
        );

        return [
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => $pages,
        ];
    }

    /**
     * Eventos conocidos, para el filtro de la UI y para validar.
     *
     * @return list<string>
     */
    public static function knownEvents(): array
    {
        return [
            'auth.login_ok',
            'auth.login_fail',
            'auth.register',
            'auth.logout',
            'auth.logout_idle',
            'auth.password_reset_request',
            'auth.password_reset_inactive',
            'auth.password_reset_done',
            'auth.password_change',
            'auth.password_change_fail',
            'auth.session_revoked',
            'csrf.invalid',
            'rate_limit.login',
            'rate_limit.password_reset',
            'rate_limit.fill',
            'rate_limit.dash_create',
            'rate_limit.dash_export',
            'webhook.private_host',
            'upload.denied_ext',
            'admin.user_toggle',
            'admin.role_change',
            'admin.user_permissions',
            'admin.group_create',
            'admin.group_membership',
            'admin.group_delete',
            'admin.role_matrix',
            'admin.role_create',
            'admin.role_delete',
            // Ajustes de la instalación que cambian QUIÉN PUEDE ENTRAR. Van con
            // prefijo `instance.` y no `admin.` porque no son de una cuenta ni de
            // un formulario: son de la instalación entera, y en el filtro del
            // panel «admin» significaría algo distinto.
            'instance.registration_on',
            'instance.registration_off',
            'instance.review_on',
            'instance.review_off',
            // AG2 — Marca de la instancia (nombre, color, logo, favicon). Un solo
            // evento para las cuatro: todas son «se cambió la identidad visual de
            // la instalación» y separarlas daría cuatro claves que decir es lo
            // mismo.
            'instance.brand_update',
            // AG3 — Configuración SMTP de la instancia y su correo de prueba.
            'instance.mail_update',
            'instance.mail_test',
            // AG4 — Identidad operativa de la instancia (URL pública, zona
            // horaria e idioma). Un solo evento: los tres son «cambió la forma en
            // que la instalación se presenta y se interpreta», y separarlos daría
            // tres claves para el mismo hecho.
            'instance.settings_update',
            // AG5 — Claves de Cloudflare Turnstile de la instancia (site key y
            // secret). Un solo evento: las dos son «se cambió el anti-bot de la
            // instalación», y la secret nunca se registra.
            'instance.turnstile_update',
            // AG6 — Límites de subida de la instancia (tamaño por archivo y
            // número por campo). Los campos de formulario solo pueden restringir.
            'instance.uploads_update',
            'form.delete',
            'form.duplicate',
            'form.review_request',
            'form.review_approve',
            'form.review_reject',
            'form.review_no_reviewer',
        ];
    }

    /**
     * Purga eventos anteriores a una fecha (mantenimiento manual/cron).
     *
     * @return int filas eliminadas
     */
    public static function purgeOlderThan(string $until): int
    {
        $row = Database::getInstance(Config::db())->execute(
            'DELETE FROM security_log WHERE created_at < :until',
            ['until' => $until]
        );
        return $row;
    }
}