<?php

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\AccessController;
use App\Controllers\AccountController;
use App\Controllers\AnalyticsController;
use App\Controllers\AttachmentController;
use App\Controllers\AuthController;
use App\Controllers\BrandingController;
use App\Controllers\BuilderController;
use App\Controllers\DashboardController;
use App\Controllers\FillController;
use App\Controllers\FormController;
use App\Controllers\ParticipantController;
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

// Marca de la instancia (AG2). PÚBLICA a propósito: el logo aparece en el login
// y en el formulario publicado, a los que se llega sin sesión. La imagen vive en
// storage/branding/ (fuera de public/) y se sirve por aquí.
$router->get('/branding/{kind}', [BrandingController::class, 'show']);

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
// Pantalla propia del acceso y del listado de participantes. Antes vivía en la
// ficha; se movió para aligerarla, y su botón («Participantes») está en la
// cabecera de `/forms/{id}`.
$router->get('/forms/{id}/access', [FormController::class, 'access'], ['auth']);
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
// Fase 4 — La puerta del listado: canjear un código personal por acceso. No lleva
// `post-max` porque no sube nada (el cuerpo es un código de 16 caracteres), y el
// `csrf` va solo porque no hay `$_FILES` que vaciarse. Es un PRG: un código
// válido redirige a `/f/{slug}?t=<código>`.
$router->post('/f/{slug}/code', [FillController::class, 'verifyCode'], ['csrf']);
// La gestión de la lista es del dueño: `auth` + el `canEdit` de la acción, igual
// que el resto de la configuración del formulario. `copyLink` devuelve JSON, así
// que su CSRF va por cabecera (`csrf-header`) y no por campo.
$router->post('/forms/{id}/access/email', [AccessController::class, 'addEmail'], ['auth', 'csrf']);
$router->post('/forms/{id}/access/email/remove', [AccessController::class, 'removeEmail'], ['auth', 'csrf']);
$router->post('/forms/{id}/access/link', [AccessController::class, 'copyLink'], ['auth', 'csrf-header']);

// Listado de participantes (`roster`). Todas del dueño (`auth` + `canEdit` de la acción,
// el mismo permiso que la allowlist) y todas exigiendo que el formulario esté en
// modo `roster`; el `id` del participante va en el POST y no en la URL, como en
// `/admin/users/toggle` y `/admin/roles`, para no añadir un segundo parámetro de
// ruta al smoke que las recorre todas.
// `post-max` va ANTES de `csrf` en las de subida (ver `/f/{slug}`): si el cuerpo
// pasa de `post_max_size`, PHP vacía `$_POST` y `$_FILES` y sin el guard se
// respondería «token CSRF inválido» a quien no ha tenido ningún problema.
$router->get('/forms/{id}/participants', [ParticipantController::class, 'index'], ['auth']);
$router->get('/forms/{id}/participants/export', [ParticipantController::class, 'export'], ['auth']);
$router->get('/forms/{id}/participants/template', [ParticipantController::class, 'template'], ['auth']);
$router->post('/forms/{id}/participants', [ParticipantController::class, 'store'], ['auth', 'post-max', 'csrf']);
$router->post('/forms/{id}/participants/update', [ParticipantController::class, 'update'], ['auth', 'post-max', 'csrf']);
$router->post('/forms/{id}/participants/delete', [ParticipantController::class, 'destroy'], ['auth', 'post-max', 'csrf']);
$router->post('/forms/{id}/participants/import', [ParticipantController::class, 'preview'], ['auth', 'post-max', 'csrf']);
$router->post('/forms/{id}/participants/import/confirm', [ParticipantController::class, 'confirm'], ['auth', 'csrf']);
$router->post('/forms/{id}/participants/import/cancel', [ParticipantController::class, 'cancel'], ['auth', 'csrf']);
$router->post('/forms/{id}/participants/truncate', [ParticipantController::class, 'truncate'], ['auth', 'post-max', 'csrf']);
$router->post('/forms/{id}/participants/attribute', [ParticipantController::class, 'destroyAttribute'], ['auth', 'post-max', 'csrf']);
// Fase 10 — el correo de invitación del listado. Sin `post-max` (no hay archivo) y
// con `csrf` como el resto de acciones que cambian algo.
$router->post('/forms/{id}/participants/invitations', [ParticipantController::class, 'invitations'], ['auth', 'csrf']);
// Fase 12 — el recordatorio por correo a quien recibió la invitación y no ha
// respondido. Misma forma que la invitación: sin `post-max` (no hay archivo) y con
// `csrf`.
$router->post('/forms/{id}/participants/reminders', [ParticipantController::class, 'remind'], ['auth', 'csrf']);
// P7
$router->post('/forms/{id}/duplicate', [FormController::class, 'duplicate'], ['auth', 'csrf']);
$router->get('/forms/{id}/qr.svg', [FormController::class, 'qrSvg'], ['auth']);
// P9 — Vista imprimible y export de la estructura (formato plantilla)
$router->get('/forms/{id}/print', [FormController::class, 'printForm'], ['auth']);
$router->get('/forms/{id}/export-structure.json', [FormController::class, 'exportStructure'], ['auth']);
// Vista previa real (modelo Elementor): renderiza el fill completo contra el
// borrador (si ?draft=1 y quien puede editar) o contra la estructura viva. El
// revisor la abre desde la bandeja; el dueño, desde el builder. El POST valida
// pero NUNCA persiste (no crea Submission, webhooks ni rate limit).
$router->get('/forms/{id}/preview', [FillController::class, 'preview'], ['auth']);
$router->post('/forms/{id}/preview', [FillController::class, 'previewSubmit'], ['auth', 'post-max', 'csrf']);

// Builder
$router->get('/forms/{id}/builder', [BuilderController::class, 'show'], ['auth']);
// Autosave al BORRADOR (modelo Elementor): escribe la estructura en edición en
// `forms.builder_draft`, sin tocar `fields` ni re-encolar la revisión. CSRF por
// cabecera (es un fetch JSON), igual que el guardado real.
$router->post('/forms/{id}/builder/draft', [BuilderController::class, 'saveDraft'], ['auth', 'csrf-header']);
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
$router->post('/admin/users/permissions', [AdminController::class, 'updateUserPermissions'], ['auth', 'admin', 'csrf']);
$router->post('/admin/users/toggle', [AdminController::class, 'toggleActive'], ['auth', 'admin', 'csrf']);
$router->get('/admin/settings', [AdminController::class, 'settings'], ['auth', 'admin']);
$router->post('/admin/settings/registration', [AdminController::class, 'updateRegistration'], ['auth', 'admin', 'csrf']);
$router->post('/admin/settings/review', [AdminController::class, 'updateReviewPolicy'], ['auth', 'admin', 'csrf']);
// AG2 — Marca de la instancia. El nombre/color van en un POST normal; el logo y
// el favicon en multipart (con `post-max` para que un cuerpo que pasa el límite
// no se lea como un CSRF inválido).
$router->post('/admin/settings/brand', [AdminController::class, 'updateBrand'], ['auth', 'admin', 'csrf']);
$router->post('/admin/settings/brand/{kind}', [AdminController::class, 'uploadBrandImage'], ['auth', 'admin', 'post-max', 'csrf']);
$router->post('/admin/settings/brand/{kind}/remove', [AdminController::class, 'removeBrandImage'], ['auth', 'admin', 'csrf']);
// AG3 — Correo (SMTP) de la instancia. La contraseña viaja en el mismo POST que
// el resto (no es multipart) y se guarda cifrada; la prueba es un POST aparte.
$router->post('/admin/settings/mail', [AdminController::class, 'updateMail'], ['auth', 'admin', 'csrf']);
$router->post('/admin/settings/mail/test', [AdminController::class, 'sendTestMail'], ['auth', 'admin', 'csrf']);
// AG4 — Identidad operativa de la instancia: URL pública, zona horaria e idioma.
// Los tres son un PIN que gana sobre el `.env`; vaciarlos lo borra.
$router->post('/admin/settings/instance', [AdminController::class, 'updateInstance'], ['auth', 'admin', 'csrf']);
// AG5 — Claves de Cloudflare Turnstile de la instancia. La site key es pública;
// la secret va cifrada en el mismo POST (no es multipart) y es de solo escritura.
$router->post('/admin/settings/turnstile', [AdminController::class, 'updateTurnstile'], ['auth', 'admin', 'csrf']);
// AG6 — Límites de subida de archivos (tamaño/número). El campo solo puede
// restringir, nunca ampliar; el límite efectivo tiene en cuenta upload_max_filesize.
$router->post('/admin/settings/uploads', [AdminController::class, 'updateUploads'], ['auth', 'admin', 'csrf']);
// AG7 — Cupos del dashboard por usuario (formularios/hora, exportaciones/hora).
$router->post('/admin/settings/dashboard-limits', [AdminController::class, 'updateDashboardLimits'], ['auth', 'admin', 'csrf']);
// AG8 — Hardening de sesión / HTTPS (nombre, duración, secure, httponly, samesite).
$router->post('/admin/settings/session', [AdminController::class, 'updateSession'], ['auth', 'admin', 'csrf']);
// AG9 — Red, proxy, redirecciones externas y cabeceras (X-Frame-Options/CSP).
$router->post('/admin/settings/network', [AdminController::class, 'updateNetwork'], ['auth', 'admin', 'csrf']);
// AG10 — Webhook red local.
$router->post('/admin/settings/webhook', [AdminController::class, 'updateWebhook'], ['auth', 'admin', 'csrf']);
$router->get('/admin/forms', [AdminController::class, 'allForms'], ['auth', 'admin']);
// RBAC (2026-09-28)
$router->get('/admin/groups', [AdminController::class, 'groups'], ['auth', 'admin']);
$router->post('/admin/groups/create', [AdminController::class, 'groupCreate'], ['auth', 'admin', 'csrf']);
$router->post('/admin/groups/update', [AdminController::class, 'groupUpdate'], ['auth', 'admin', 'csrf']);
$router->post('/admin/groups/delete', [AdminController::class, 'groupDelete'], ['auth', 'admin', 'csrf']);
$router->get('/admin/roles', [AdminController::class, 'roles'], ['auth', 'admin']);
$router->post('/admin/roles/create', [AdminController::class, 'roleCreate'], ['auth', 'admin', 'csrf']);
$router->post('/admin/roles/save', [AdminController::class, 'updateRoleMatrix'], ['auth', 'admin', 'csrf']);
$router->post('/admin/roles/delete', [AdminController::class, 'roleDelete'], ['auth', 'admin', 'csrf']);
$router->get('/admin/security-log', [AdminController::class, 'securityLog'], ['auth', 'admin']);