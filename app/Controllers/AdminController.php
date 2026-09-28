<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\Role;
use App\Models\User;
use App\Models\Form;

class AdminController
{
    public function index(): void
    {
        $users = User::all();
        View::render('admin/users', [
            'users' => $users,
            'roles' => Role::all(),
            'csrf'  => Csrf::token(),
        ]);
    }

    public function updateRole(): void
    {
        if (!Csrf::verifyRequest()) {
            Session::flash('error', 'Token inválido.');
            Response::redirect('/admin');
        }

        $userId = (int) Request::post('user_id', 0);
        $roleId = (int) Request::post('role_id', 0);

        $target = User::find($userId);
        if ($target === null) {
            Session::flash('error', 'Usuario no encontrado.');
            Response::redirect('/admin');
        }

        // No permitir degradar al propio admin
        if ((int) \App\Core\Auth::id() === $userId && $roleId !== 1) {
            Session::flash('error', 'No puedes cambiar tu propio rol de administrador.');
            Response::redirect('/admin');
        }

        if (!in_array($roleId, array_column(Role::all(), 'id'), true)) {
            Session::flash('error', 'Rol inválido.');
            Response::redirect('/admin');
        }

        User::updateRole($userId, $roleId);
        Session::flash('success', 'Rol actualizado.');
        Response::redirect('/admin');
    }

    public function toggleActive(): void
    {
        if (!Csrf::verifyRequest()) {
            Session::flash('error', 'Token inválido.');
            Response::redirect('/admin');
        }

        $userId = (int) Request::post('user_id', 0);
        $target = User::find($userId);

        if ($target === null) {
            Session::flash('error', 'Usuario no encontrado.');
            Response::redirect('/admin');
        }

        if ((int) \App\Core\Auth::id() === $userId) {
            Session::flash('error', 'No puedes desactivar tu propia cuenta.');
            Response::redirect('/admin');
        }

        User::toggleActive($userId);
        Session::flash('success', 'Estado de usuario actualizado.');
        Response::redirect('/admin');
    }

    public function allForms(): void
    {
        $forms = Form::all();
        View::render('admin/forms', [
            'forms' => $forms,
            'csrf'  => Csrf::token(),
        ]);
    }
}