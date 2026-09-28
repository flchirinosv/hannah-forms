<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Session;
use App\Core\View;
use App\Models\Form;

class DashboardController
{
    public function index(): void
    {
        $user = \App\Core\Auth::user();

        if ($user === null) {
            Session::flash('error', 'Debes iniciar sesión.');
            \App\Core\Response::redirect('/login');
        }

        $stats = [
            'total'   => 0,
            'published' => 0,
            'draft'   => 0,
            'closed'  => 0,
            'submissions' => 0,
        ];

        $forms = [];
        if (\App\Core\Auth::isAdmin()) {
            $forms = Form::all();
        } else {
            $forms = Form::byUser((int) $user['id']);
        }

        foreach ($forms as $form) {
            $stats['total']++;
            $stats[$form['status']] = ($stats[$form['status']] ?? 0) + 1;
            $stats['submissions'] += (int) ($form['submissions_count'] ?? 0);
        }

        View::render('dashboard/index', [
            'csrf'  => Csrf::token(),
            'user'  => $user,
            'forms' => $forms,
            'stats' => $stats,
            // P9 — Plantillas de arranque (database/templates) para el modal.
            'templates' => \App\Services\TemplateService::list(),
        ]);
    }
}