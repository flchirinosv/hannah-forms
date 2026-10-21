<?php
use App\Core\Auth;
use App\Core\Brand;
use App\Core\View;

$currentUser = Auth::user();
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$appName = Brand::name();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= View::escape($pageDescription ?? 'VanillaForms — sistema de formularios web simple y rápido.') ?>">
    <meta property="og:title" content="<?= isset($pageTitle) ? View::escape($pageTitle) . ' — ' : '' ?><?= View::escape($appName) ?>">
    <meta property="og:description" content="<?= View::escape($pageDescription ?? 'VanillaForms — sistema de formularios web simple y rápido.') ?>">
    <meta property="og:type" content="website">
    <title><?= isset($pageTitle) ? View::escape($pageTitle) . ' — ' : '' ?><?= View::escape($appName) ?></title>
    <?= View::favicon() ?>
    <link rel="stylesheet" href="<?= View::asset('css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= View::asset('css/all.min.css') ?>">
    <link rel="stylesheet" href="<?= View::asset('css/app.css') ?>">
    <?= View::brandStyle() ?>
</head>
<?php // `data-csrf` es el token global del panel: `app.js` lo lee para mandarlo
      // en `X-CSRF-Token` en los POST que se hacen por fetch SIN formulario
      // (un `FormData` de un formulario ya lleva su `_token`). Vive en `<body>`
      // y no en cada tarjeta porque el token es de la SESIÓN, no de la vista:
      // duplicarlo por pantalla era una forma de que se quedara viejo en un
      // trozo y pareciera un fallo de CSRF que no existe. Las páginas públicas
      // del fill no pasan por este layout (`View::render(..., null)`). ?>
<body data-csrf="<?= View::escape(\App\Core\Csrf::token()) ?>">
<div class="app-shell">
    <?php // La navegación es una sidebar. En `lg+` es una columna fija; por
          // debajo se comporta como cajón (`offcanvas-lg`) que abre desde la
          // barra fina de móvil. El plegado lo hace Bootstrap, no hay JS propio:
          // al pulsar un enlace la página navega y el cajón se recarga cerrado. ?>
    <aside class="app-sidebar offcanvas-lg offcanvas-start" id="appSidebar" tabindex="-1" aria-label="Navegación principal">
        <div class="app-sidebar-inner d-flex flex-column">
            <div class="offcanvas-header app-sidebar-close">
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#appSidebar" aria-label="Cerrar la navegación"></button>
            </div>
            <a class="app-sidebar-brand app-navbar" href="/">
                <?= View::brandLogo('app-logo') ?>
                <span class="app-sidebar-label fw-bold"><?= View::escape($appName) ?></span>
            </a>
            <ul class="nav flex-column app-sidebar-nav">
                <?php // Cada enlace se enseña solo si su destino le va a dejar pasar.
                      // El caso que motivó el gate: `approver` tiene `dashboard.view`
                      // y `forms.review`, pero NO `forms.create`, así que un botón
                      // «Nuevo formulario» para él es un 403
                      // con toda la pinta de un botón que funciona. ?>
                <?php if ($currentUser && \App\Core\Auth::can('dashboard.view')): ?>
                <li class="nav-item"><a class="nav-link <?= $currentPath === '/dashboard' || $currentPath === '/' ? 'active' : '' ?>" href="/dashboard"><i class="fa-solid fa-gauge" aria-hidden="true"></i><span class="app-sidebar-label">Dashboard</span></a></li>
                <?php endif; ?>
                <?php // F3 — La bandeja solo aparece a quien puede aprobar. Sin este
                      // gate en la vista, el enlace y un 403: la navegación
                      // prometería una pantalla que el usuario no puede abrir. ?>
                <?php if ($currentUser && \App\Core\Auth::can('forms.review')): ?>
                <li class="nav-item"><a class="nav-link <?= $currentPath === '/review' ? 'active' : '' ?>" href="/review"><i class="fa-solid fa-clipboard-check" aria-hidden="true"></i><span class="app-sidebar-label">Revisión</span></a></li>
                <?php endif; ?>
                <?php if ($currentUser && \App\Core\Auth::can('admin.access')): ?>
                <li class="nav-item"><a class="nav-link <?= str_starts_with($currentPath, '/admin') ? 'active' : '' ?>" href="/admin"><i class="fa-solid fa-user-gear" aria-hidden="true"></i><span class="app-sidebar-label">Administración</span></a></li>
                <?php endif; ?>
            </ul>
            <?php if ($currentUser && \App\Core\Auth::can('forms.create')): ?>
            <div class="app-sidebar-cta">
                <a href="/dashboard" class="btn btn-light app-btn-create w-100">
                    <i class="fa-solid fa-plus" aria-hidden="true"></i><span class="app-sidebar-label">Nuevo formulario</span>
                </a>
            </div>
            <?php endif; ?>
            <?php // El usuario vive abajo y a TODO el ancho de la barra. No es un
                  // desplegable flotante (`.dropdown`): es un colapsable que se
                  // abre HACIA ARRIBA —el panel va ANTES del botón, que queda
                  // pegado al borde inferior— y lo gobierna Bootstrap con
                  // `data-bs-toggle="collapse"`, sin JS propio. Así el bloque
                  // comparte el degradado de la barra en vez de salirse a una
                  // caja blanca por encima del borde. ?>
            <div class="app-sidebar-user mt-auto">
                <div class="collapse app-sidebar-user-menu" id="sidebarUserMenu">
                    <ul class="list-unstyled m-0">
                        <li><span class="app-sidebar-user-email"><?= View::escape($currentUser['email'] ?? '') ?></span></li>
                        <li><a class="app-sidebar-user-link" href="/account/password"><i class="fa-solid fa-user-shield" aria-hidden="true"></i>Seguridad de mi cuenta</a></li>
                        <li>
                            <form method="post" action="/logout" class="m-0">
                                <?= \App\Core\Csrf::field() ?>
                                <button type="submit" class="app-sidebar-user-link app-sidebar-user-danger"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>Cerrar sesión</button>
                            </form>
                        </li>
                    </ul>
                </div>
                <button type="button" class="app-sidebar-user-btn" data-bs-toggle="collapse" data-bs-target="#sidebarUserMenu" aria-expanded="false" aria-controls="sidebarUserMenu">
                    <span class="app-avatar"><?= View::escape(strtoupper(substr($currentUser['name'] ?? 'U', 0, 1))) ?></span>
                    <span class="app-sidebar-label app-sidebar-user-name">
                        <span class="app-sidebar-user-text"><?= View::escape($currentUser['name'] ?? '') ?></span>
                        <span class="badge text-bg-light app-role-badge"><?= View::escape($currentUser['role_name'] ?? '') ?></span>
                    </span>
                    <i class="fa-solid fa-chevron-up app-sidebar-user-caret" aria-hidden="true"></i>
                </button>
            </div>
        </div>
    </aside>

    <div class="app-content d-flex flex-column min-vh-100">
        <header class="app-topbar app-navbar d-lg-none sticky-top">
            <button class="app-topbar-toggle" type="button" data-bs-toggle="offcanvas" data-bs-target="#appSidebar" aria-controls="appSidebar" aria-label="Abrir la navegación">
                <i class="fa-solid fa-bars" aria-hidden="true"></i>
            </button>
            <a class="app-topbar-brand" href="/">
                <?= View::brandLogo('app-logo') ?>
                <span class="fw-bold"><?= View::escape($appName) ?></span>
            </a>
        </header>

<main class="container-fluid py-4 main-container flex-grow-1">
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
    <span class="mx-2">© <?= date('Y') ?> <?= View::escape($appName) ?></span>
    <span class="mx-2">·</span>
    <span>Vanilla PHP + MySQL + nginx</span>
</footer>
    </div>
</div>

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