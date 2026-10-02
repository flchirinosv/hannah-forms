<?php
use App\Core\Auth;
use App\Core\View;

$config = \App\Core\Config::all();
$currentUser = Auth::user();
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= View::escape($pageDescription ?? 'VanillaForms — sistema de formularios web simple y rápido.') ?>">
    <meta property="og:title" content="<?= isset($pageTitle) ? View::escape($pageTitle) . ' — ' : '' ?><?= View::escape($config['app']['name']) ?>">
    <meta property="og:description" content="<?= View::escape($pageDescription ?? 'VanillaForms — sistema de formularios web simple y rápido.') ?>">
    <meta property="og:type" content="website">
    <title><?= isset($pageTitle) ? View::escape($pageTitle) . ' — ' : '' ?><?= View::escape($config['app']['name']) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= View::asset('favicon.svg') ?>">
    <link rel="stylesheet" href="<?= View::asset('css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= View::asset('css/all.min.css') ?>">
    <link rel="stylesheet" href="<?= View::asset('css/app.css') ?>">
</head>
<?php // `data-csrf` es el token global del panel: `app.js` lo lee para mandarlo
      // en `X-CSRF-Token` en los POST que se hacen por fetch SIN formulario
      // (un `FormData` de un formulario ya lleva su `_token`). Vive en `<body>`
      // y no en cada tarjeta porque el token es de la SESIÓN, no de la vista:
      // duplicarlo por pantalla era una forma de que se quedara viejo en un
      // trozo y pareciera un fallo de CSRF que no existe. Las páginas públicas
      // del fill no pasan por este layout (`View::render(..., null)`). ?>
<body data-csrf="<?= View::escape(\App\Core\Csrf::token()) ?>">
<nav class="navbar navbar-expand-lg navbar-dark bg-primary sticky-top app-navbar">
    <div class="container-fluid px-4">
        <a class="navbar-brand d-flex align-items-center gap-2 fw-bold" href="/">
            <span class="app-logo"><i class="fa-solid fa-list-check"></i></span>
            <?= View::escape($config['app']['name']) ?>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav me-auto">
                <?php // Cada enlace se enseña solo si su destino le va a dejar pasar.
                      // El caso que motivó el gate: `approver` tiene `dashboard.view`
                      // y `forms.review`, pero NO `forms.create`, así que un botón
                      // «Nuevo formulario» para él es un 403
                      // con toda la pinta de un botón que funciona. ?>
                <?php if ($currentUser && \App\Core\Auth::can('dashboard.view')): ?>
                <li class="nav-item"><a class="nav-link <?= $currentPath === '/dashboard' || $currentPath === '/' ? 'active' : '' ?>" href="/dashboard"><i class="fa-solid fa-gauge me-1"></i>Dashboard</a></li>
                <?php endif; ?>
                <?php // F3 — La bandeja solo aparece a quien puede aprobar. Sin este
                      // gate en la vista, el enlace y un 403: la navegación
                      // prometería una pantalla que el usuario no puede abrir. ?>
                <?php if ($currentUser && \App\Core\Auth::can('forms.review')): ?>
                <li class="nav-item"><a class="nav-link <?= $currentPath === '/review' ? 'active' : '' ?>" href="/review"><i class="fa-solid fa-clipboard-check me-1"></i>Revisión</a></li>
                <?php endif; ?>
                <?php if ($currentUser && \App\Core\Auth::can('admin.access')): ?>
                <li class="nav-item"><a class="nav-link <?= str_starts_with($currentPath, '/admin') ? 'active' : '' ?>" href="/admin"><i class="fa-solid fa-user-gear me-1"></i>Administración</a></li>
                <?php endif; ?>
            </ul>
            <ul class="navbar-nav ms-auto align-items-lg-center">
                <?php if ($currentUser && \App\Core\Auth::can('forms.create')): ?>
                <li class="nav-item me-lg-2 mb-2 mb-lg-0">
                    <a href="/dashboard" class="btn btn-light btn-sm app-btn-create w-100">
                        <i class="fa-solid fa-plus me-1"></i>Nuevo formulario
                    </a>
                </li>
                <?php endif; ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="app-avatar"><?= View::escape(strtoupper(substr($currentUser['name'] ?? 'U', 0, 1))) ?></span>
                        <span class="d-none d-md-inline"><?= View::escape($currentUser['name'] ?? '') ?></span>
                        <span class="badge text-bg-light app-role-badge"><?= View::escape($currentUser['role_name'] ?? '') ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow">
                        <li><span class="dropdown-item-text small text-muted"><?= View::escape($currentUser['email'] ?? '') ?></span></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item" href="/account/password"><i class="fa-solid fa-user-shield me-1"></i>Seguridad de la cuenta</a>
                        </li>
                        <li>
                            <form method="post" action="/logout">
                                <?= \App\Core\Csrf::field() ?>
                                <button type="submit" class="dropdown-item text-danger"><i class="fa-solid fa-right-from-bracket me-1"></i>Cerrar sesión</button>
                            </form>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>

<main class="container-fluid py-4 main-container">
    <?php if (\App\Core\Session::hasFlash('success')): ?>
        <div class="alert alert-success alert-dismissible fade show app-alert" role="alert">
            <i class="fa-solid fa-circle-check me-1"></i><?= View::escape(\App\Core\Session::getFlash('success')) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (\App\Core\Session::hasFlash('error')): ?>
        <div class="alert alert-danger alert-dismissible fade show app-alert" role="alert">
            <i class="fa-solid fa-circle-xmark me-1"></i><?= View::escape(\App\Core\Session::getFlash('error')) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?= $content ?>
</main>

<footer class="text-center text-muted small py-4 app-footer">
    <span class="mx-2">© <?= date('Y') ?> <?= View::escape($config['app']['name']) ?></span>
    <span class="mx-2">·</span>
    <span>Vanilla PHP + MySQL + nginx</span>
</footer>

<?php // F0.4-tres — el diálogo de confirmación del panel. UNO, aquí, y no uno en
      // cada vista: `confirm()` del navegador no se puede estilar ni traducir y
      // además es el prompt feo que hay que sustituir; este es un `role="dialog"`
      // de Bootstrap, con trampa de foco, `Esc`, vuelta del foco al botón y el
      // texto puesto por `textContent` (`app.js`).
      //
      // Va como hijo DIRECTO de `<body>` y no dentro de `<main>`: Bootstrap lleva
      // el `.modal` a `display: none`, así que cualquier contenedor con `display`
      // distinto lo deja invisible para siempre (lo pasó con `#addFieldModal`).
      // Los dos botones de «cerrar» usan `data-bs-dismiss` y los ata Bootstrap:
      // el JS solo decide si se envía, y pone el foco en «Cancelar» al abrir. ?>
<div class="modal fade" id="vfConfirm" tabindex="-1" aria-labelledby="vfConfirmTitle" aria-describedby="vfConfirmText" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content app-confirm">
            <div class="modal-header border-0 pb-0">
                <h2 class="modal-title h6 fw-bold mb-0" id="vfConfirmTitle">
                    <i class="fa-solid fa-circle-exclamation text-warning me-2" aria-hidden="true"></i>Confirmar la acción
                </h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar sin continuar"></button>
            </div>
            <div class="modal-body py-2">
                <p class="mb-0" id="vfConfirmText"></p>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-outline-secondary" id="vfConfirmCancel" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-danger" id="vfConfirmOk">Sí, continuar</button>
            </div>
        </div>
    </div>
</div>

<script src="<?= View::asset('js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= View::asset('js/app.js') ?>"></script>
</body>
</html>