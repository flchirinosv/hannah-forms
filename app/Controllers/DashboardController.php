<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Cache;
use App\Core\Csrf;
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

        // Fase 5 §Rendimiento: el cálculo del dashboard (layout de todos los
        // formularios + sumatorio de respuestas) se cachea ~1 min. No romper
        // nunca el request: ante cualquier fallo de caché se calcula en vivo.
        $cacheKey = 'dash:' . ($scopeAll ? 'all' : (string) $user['id']);

        $forms = Cache::remember($cacheKey, 60, static function () use ($scopeAll, $user): array {
            return $scopeAll ? Form::all() : Form::byUser((int) $user['id']);
        });

        $stats = [
            'total'       => 0,
            'published'   => 0,
            'draft'       => 0,
            'closed'      => 0,
            'in_review'   => 0,
            'submissions' => 0,
        ];

        foreach ($forms as $form) {
            $stats['total']++;
            $stats[$form['status']] = ($stats[$form['status']] ?? 0) + 1;
            $stats['submissions'] += (int) ($form['submissions_count'] ?? 0);
        }

        // F3 — El contador sale del mismo listado ya cacheado, no de una consulta
        // propia: si el listado va cacheado y el contador no, la tarjeta y el
        // aviso discreparían hasta un minuto después de una revisión.
        View::render('dashboard/index', [
            'csrf'  => Csrf::token(),
            'user'  => $user,
            'forms' => $forms,
            'stats' => $stats,
            // F3 — Solo quien puede aprobar ve los botones de acción; el aviso
            // de «hay N esperando» lo ve cualquiera con formularios en alcance,
            // porque también tiene que saber por qué uno no aparece publicado.
            'canReview' => Auth::can('forms.review'),
            // V5 — Serie de actividad para el sparkline de cada tarjeta (días
            // locales, consulta única para todos los forms de la página).
            'activity' => Submission::activityByForms(array_map(
                static fn (array $f): int => (int) ($f['id'] ?? 0),
                $forms
            )),
            // P9 — Plantillas de arranque (database/templates) para el modal.
            'templates' => \App\Services\TemplateService::list(),
        ]);
    }
}