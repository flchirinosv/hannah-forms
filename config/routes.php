<?php

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\AccessController;
use App\Controllers\AccountController;
use App\Controllers\AnalyticsController;
use App\Controllers\AttachmentController;
use App\Controllers\AuthController;
use App\Controllers\BuilderController;
use App\Controllers\DashboardController;
use App\Controllers\FillController;
use App\Controllers\FormController;
use App\Controllers\ResponsesController;
use App\Controllers\ReviewController;
use App\Core\Router;

/** @var Router $router */

// Auth
$router->get('/login', [AuthController::class, 'showLogin'], ['guest']);
$router->post('/login', [AuthController::class, 'login'], ['guest', 'csrf']);
$router->get('/register', [AuthController::class, 'showRegister'], ['guest']);
$router->post('/register', [AuthController::class, 'register'], ['guest', 'csrf']);
$router->post('/logout', [AuthController::class, 'logout'], ['auth', 'csrf']);

// F2 — Recuperación y cambio de contraseña.
// `/reset-password` NO lleva middleware `guest`: el enlace del correo se abre en
// un navegador que puede tener sesión iniciada, y `guest` lo redirigiría a
// /dashboard sin mostrar nunca el formulario (el enlace parecería roto).
$router->get('/forgot-password', [AccountController::class, 'showForgot'], ['guest']);
$router->post('/forgot-password', [AccountController::class, 'sendReset'], ['guest', 'csrf']);
$router->get('/reset-password', [AccountController::class, 'showReset']);
$router->post('/reset-password', [AccountController::class, 'doReset'], ['csrf']);
$router->get('/account/password', [AccountController::class, 'showPassword'], ['auth']);
$router->post('/account/password', [AccountController::class, 'changePassword'], ['auth', 'csrf']);

// Dashboard
$router->get('/dashboard', [DashboardController::class, 'index'], ['auth']);
$router->get('/', [DashboardController::class, 'index'], ['auth']);

// Form CRUD
$router->post('/forms', [FormController::class, 'create'], ['auth', 'csrf']);
$router->get('/forms/{id}', [FormController::class, 'show'], ['auth']);
$router->post('/forms/{id}/update', [FormController::class, 'update'], ['auth', 'csrf']);
// Ajustes operativos del formulario (acceso, agenda, cupo, avisos, webhook,
// gracias, revisión, idioma). POST aparte de `/update` porque escribe con
// FormSettings::merge(): cada puerta toca solo los bloques que trae.
$router->post('/forms/{id}/settings', [FormController::class, 'updateSettings'], ['auth', 'csrf']);
$router->post('/forms/{id}/delete', [FormController::class, 'delete'], ['auth', 'csrf']);
$router->post('/forms/{id}/publish', [FormController::class, 'publish'], ['auth', 'csrf']);
$router->post('/forms/{id}/close', [FormController::class, 'close'], ['auth', 'csrf']);

// F3 — Revisión/aprobación. El envío a revisión es del dueño (`auth` + el
// `canEdit` de la acción); aprobar y devolver exigen `forms.review`, que se
// comprueba DENTRO de la acción y no en el middleware: el middleware solo
// conoce `auth`/`admin`/`editor`, y un permiso de la matriz no cabe ahí sin
// enseñarle el catálogo entero a la lista de middlewares.
$router->post('/forms/{id}/review/send', [ReviewController::class, 'sendReview'], ['auth', 'csrf']);
$router->post('/forms/{id}/review/approve', [ReviewController::class, 'approve'], ['auth', 'csrf']);
$router->post('/forms/{id}/review/return', [ReviewController::class, 'returnDraft'], ['auth', 'csrf']);
$router->get('/review', [ReviewController::class, 'inbox'], ['auth']);

// F4 — Acceso restringido. La parte pública NO lleva middleware `auth` (el
// visitante no tiene sesión: por eso existe) y solo `csrf`, porque el formulario
// de identificación es un POST normal con campo `_token`.
// La variante `/embed` devuelve al embed para que el visitante se quede dentro
// del iframe; el gate, el correo y el token son los mismos.
$router->post('/f/{slug}/access', [AccessController::class, 'requestAccess'], ['csrf']);
$router->post('/f/{slug}/access/embed', [AccessController::class, 'requestAccessEmbed'], ['csrf']);
// La gestión de la lista es del dueño: `auth` + el `canEdit` de la acción, igual
// que el resto de la configuración del formulario. `copyLink` devuelve JSON, así
// que su CSRF va por cabecera (`csrf-header`) y no por campo.
$router->post('/forms/{id}/access/email', [AccessController::class, 'addEmail'], ['auth', 'csrf']);
$router->post('/forms/{id}/access/email/remove', [AccessController::class, 'removeEmail'], ['auth', 'csrf']);
$router->post('/forms/{id}/access/link', [AccessController::class, 'copyLink'], ['auth', 'csrf-header']);
// P7
$router->post('/forms/{id}/duplicate', [FormController::class, 'duplicate'], ['auth', 'csrf']);
$router->get('/forms/{id}/qr.svg', [FormController::class, 'qrSvg'], ['auth']);
// P9 — Vista imprimible y export de la estructura (formato plantilla)
$router->get('/forms/{id}/print', [FormController::class, 'printForm'], ['auth']);
$router->get('/forms/{id}/export-structure.json', [FormController::class, 'exportStructure'], ['auth']);

// Builder
$router->get('/forms/{id}/builder', [BuilderController::class, 'show'], ['auth']);
$router->post('/forms/{id}/builder/save', [BuilderController::class, 'saveStructure'], ['auth', 'csrf-header']);

// Front público de respuesta
$router->get('/f/{slug}/thanks', [FillController::class, 'thanks']);
$router->get('/f/{slug}/embed', [FillController::class, 'embed']);
$router->get('/f/{slug}', [FillController::class, 'show']);
$router->post('/f/{slug}', [FillController::class, 'submit'], ['post-max', 'csrf']);

// Analítica
$router->get('/forms/{id}/analytics', [AnalyticsController::class, 'show'], ['auth']);
$router->get('/forms/{id}/analytics/rows', [AnalyticsController::class, 'rows'], ['auth']);
$router->get('/forms/{id}/export.csv', [AnalyticsController::class, 'exportCsv'], ['auth']);

// Respuestas (P3)
$router->get('/forms/{id}/responses', [ResponsesController::class, 'index'], ['auth']);
$router->get('/forms/{id}/responses/{sid}', [ResponsesController::class, 'show'], ['auth']);
$router->post('/forms/{id}/responses/{sid}/delete', [ResponsesController::class, 'delete'], ['auth', 'csrf']);
$router->get('/forms/{id}/export.json', [ResponsesController::class, 'exportJson'], ['auth']);

// Adjuntos (P10): descarga autenticada. El id de la URL es el id de `answers`.
$router->get('/attachments/{id}', [AttachmentController::class, 'download'], ['auth']);

// Admin
$router->get('/admin', [AdminController::class, 'index'], ['auth', 'admin']);
$router->post('/admin/users/create', [AdminController::class, 'createUser'], ['auth', 'admin', 'csrf']);
$router->post('/admin/users/role', [AdminController::class, 'updateRole'], ['auth', 'admin', 'csrf']);
$router->post('/admin/users/groups', [AdminController::class, 'updateUserGroups'], ['auth', 'admin', 'csrf']);
$router->post('/admin/users/toggle', [AdminController::class, 'toggleActive'], ['auth', 'admin', 'csrf']);
$router->post('/admin/settings/registration', [AdminController::class, 'updateRegistration'], ['auth', 'admin', 'csrf']);
$router->get('/admin/forms', [AdminController::class, 'allForms'], ['auth', 'admin']);
// RBAC (2026-09-28)
$router->get('/admin/groups', [AdminController::class, 'groups'], ['auth', 'admin']);
$router->post('/admin/groups/create', [AdminController::class, 'groupCreate'], ['auth', 'admin', 'csrf']);
$router->post('/admin/groups/update', [AdminController::class, 'groupUpdate'], ['auth', 'admin', 'csrf']);
$router->post('/admin/groups/delete', [AdminController::class, 'groupDelete'], ['auth', 'admin', 'csrf']);
$router->get('/admin/roles', [AdminController::class, 'roles'], ['auth', 'admin']);
$router->post('/admin/roles/create', [AdminController::class, 'roleCreate'], ['auth', 'admin', 'csrf']);
$router->post('/admin/roles/save', [AdminController::class, 'updateRoleMatrix'], ['auth', 'admin', 'csrf']);
$router->get('/admin/security-log', [AdminController::class, 'securityLog'], ['auth', 'admin']);