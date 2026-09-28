<?php

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\AnalyticsController;
use App\Controllers\AttachmentController;
use App\Controllers\AuthController;
use App\Controllers\BuilderController;
use App\Controllers\DashboardController;
use App\Controllers\FillController;
use App\Controllers\FormController;
use App\Controllers\ResponsesController;
use App\Core\Router;

/** @var Router $router */

// Auth
$router->get('/login', [AuthController::class, 'showLogin'], ['guest']);
$router->post('/login', [AuthController::class, 'login'], ['guest', 'csrf']);
$router->get('/register', [AuthController::class, 'showRegister'], ['guest']);
$router->post('/register', [AuthController::class, 'register'], ['guest', 'csrf']);
$router->post('/logout', [AuthController::class, 'logout'], ['auth', 'csrf']);

// Dashboard
$router->get('/dashboard', [DashboardController::class, 'index'], ['auth']);
$router->get('/', [DashboardController::class, 'index'], ['auth']);

// Form CRUD
$router->post('/forms', [FormController::class, 'create'], ['auth', 'csrf']);
$router->get('/forms/{id}', [FormController::class, 'show'], ['auth']);
$router->post('/forms/{id}/update', [FormController::class, 'update'], ['auth', 'csrf']);
$router->post('/forms/{id}/delete', [FormController::class, 'delete'], ['auth', 'csrf']);
$router->post('/forms/{id}/publish', [FormController::class, 'publish'], ['auth', 'csrf']);
$router->post('/forms/{id}/close', [FormController::class, 'close'], ['auth', 'csrf']);
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
$router->post('/admin/users/role', [AdminController::class, 'updateRole'], ['auth', 'admin', 'csrf']);
$router->post('/admin/users/toggle', [AdminController::class, 'toggleActive'], ['auth', 'admin', 'csrf']);
$router->get('/admin/forms', [AdminController::class, 'allForms'], ['auth', 'admin']);