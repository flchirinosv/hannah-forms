<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Cache;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Config;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\Form;
use App\Models\Submission;

class DashboardController
{
    public function index(): void
    {
        $user = Auth::user();

        if ($user === null) {
            Session::flash('error', 'Debes iniciar sesión.');
            Response::redirect('/login');
            return;
        }

        if (!Auth::can('dashboard.view')) {
            Response::forbidden();
            return;
        }

        // RBAC: alcance. Por defecto cada usuario ve SOLO sus formularios;
        // `forms.manage_all` (admin por defecto, otorgable a grupos) abre el
        // alcance a todos.
        $scopeAll = Auth::can('forms.manage_all');

        // M2 — Los tres filtros viajan en la URL (`/dashboard?q=…&estado=…`).
        // Antes eran de cliente sobre el listado entero, y con el `LIMIT` en SQL
        // eso solo habría roto la pantalla: buscando «norte» se pintarían las
        // doce tarjetas de la página y se ocultarían todas. Sin JS el mismo
        // formulario GET con las cuatro cosas dentro.
        $filtros = self::filtrosDeUrl();
        $listado = Form::page((int) $user['id'], $scopeAll, $filtros, max(1, (int) Request::query('page', '1')));

        // Las cuentas de las tarjetas son del alcance entero y no dependen de la
        // página ni de los filtros, así que van cacheadas aparte: son el agregado
        // caro (dos COUNT sobre el alcance) y lo que antes se pintaba contando
        // el array de formularios que ahora son doce. Un fallo de caché se
        // calcula en vivo; nunca rompe el request.
        //
        // F3 (sigue igual): la insignia de cada tarjeta y el aviso de «hay N en
        // revisión» salen de ESTE MISMO array. Si el aviso lo sacara otra
        // consulta, una revisión recién hecha dejaría los dos números
        // discrepando hasta un minuto.
        $cacheKey = 'dash:stats:' . ($scopeAll ? 'all' : (string) $user['id']);
        $stats = Cache::remember($cacheKey, 60, static function () use ($scopeAll, $user): array {
            return Form::counts((int) $user['id'], $scopeAll);
        });

        // F3 — Solo quien puede aprobar ve los botones de acción; el aviso
        // de «hay N esperando» lo ve cualquiera con formularios en alcance,
        // porque también tiene que saber por qué uno no aparece publicado.
        View::render('dashboard/index', [
            'csrf'  => Csrf::token(),
            'user'  => $user,
            'forms' => $listado['items'],
            'listado' => $listado,
            'filtros' => $filtros,
            'stats' => $stats,
            'canReview' => Auth::can('forms.review'),
            // V5 — Serie de actividad para el sparkline de cada tarjeta (días
            // locales, consulta única para todos los forms de la página).
            'activity' => Submission::activityByForms(array_map(
                static fn (array $f): int => (int) ($f['id'] ?? 0),
                $listado['items']
            )),
            // P9 — Plantillas de arranque (database/templates) para el modal.
            'templates' => \App\Services\TemplateService::list(),
        ]);
    }

    /**
     * Los filtros del listado, ya validados contra las listas blancas del
     * modelo. Un `estado` o un `orden` que no existe se cambian por el
     * predeterminado en vez de acabar en el SQL: es la URL, y una URL escrita a
     * mano no es una lista de valores posibles.
     */
    private static function filtrosDeUrl(): array
    {
        $estado = (string) Request::query('estado', '');
        $orden = (string) Request::query('orden', '');

        return [
            'q'      => (string) Request::query('q', ''),
            'estado' => in_array($estado, Form::STATUS, true) ? $estado : '',
            'orden'  => in_array($orden, Form::ORDENES, true) ? $orden : 'updated',
        ];
    }
}