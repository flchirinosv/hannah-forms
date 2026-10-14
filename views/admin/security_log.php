<?php
use App\Core\View;

$q = (string) ($q ?? '');
$filtrado = $event !== '' || $q !== '';

// Los filtros viajan en la URL y el paginador los conserva. Mismo patrón que el
// buscador de la analítica y el listado del panel: un `?page=2` sin `event`/`q`
// es la página 2 de todo lo demás.
$filtroQs = static function () use ($event, $q): string {
    $qs = array_filter([
        'event' => $event !== '' ? $event : null,
        'q'     => $q !== '' ? $q : null,
    ], static fn ($v): bool => $v !== null);
    return $qs === [] ? '' : '?' . http_build_query($qs);
};
?>
<div class="container-fluid px-lg-4">
    <?= View::partial('admin/_page_head', [
        'active'   => 'security_log',
        'icon'     => 'fa-shield-halved',
        'title'    => 'Log de seguridad',
        'subtitle' => 'Eventos de autenticación, CSRF, rate limits, webhooks bloqueados, subidas denegadas y cambios administrativos.',
    ]) ?>

    <?php // F6 — La barra es la MISMA que la del listado de formularios
          // (`views/admin/forms.php`): buscador `.app-dash-search` y selector
          // `.app-dash-sort`, en una fila que envuelve. El evento se filtra solo
          // al cambiar (`js-auto-submit`) y con él viaja lo que haya en el
          // buscador; el buscador se envía con `Enter` (formulario GET nativo).
          // Sin JS, el botón `noscript` es la salida.
          //
          // El evento va en un `<select>` y no en un btn-group de enlaces como el
          // estado de los formularios: son ~40 eventos conocidos y una fila de 40
          // botones es ilegible. El modificador `app-dash-sort-wide` ensancha el
          // control, porque las claves (`auth.password_reset_request`) no son
          // rótulos cortos y a 200px se cortaban. ?>
    <form method="get" action="/admin/security-log" class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <div class="input-group input-group-sm app-dash-search">
            <span class="input-group-text app-dash-search-icon"><i class="fa-solid fa-magnifying-glass"></i></span>
            <input type="search" name="q" value="<?= View::escape($q) ?>" class="form-control form-control-sm" placeholder="Buscar detalle, IP o usuario…" aria-label="Buscar en el log">
        </div>
        <select name="event" class="form-select form-select-sm app-dash-sort app-dash-sort-wide js-auto-submit" aria-label="Filtrar por evento">
            <option value="">Todos los eventos</option>
            <?php foreach ($events as $e): ?>
                <option value="<?= View::escape($e) ?>" <?= $event === $e ? 'selected' : '' ?>><?= View::escape($e) ?></option>
            <?php endforeach; ?>
        </select>
        <noscript><button type="submit" class="btn btn-sm btn-outline-secondary">Aplicar</button></noscript>
    </form>

    <?php if (empty($logs['items'])): ?>
        <?php // F6 — El vacío de un filtro no es «todavía no hay nada»: uno se
              // limpia y el otro solo espera. Con la barra todavía arriba. ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <div class="app-empty-icon mb-3"><i class="fa-solid fa-shield-halved"></i></div>
                <h4 class="h6 fw-bold"><?= $filtrado ? 'Sin resultados' : 'Todavía no hay eventos' ?></h4>
                <p class="text-muted small mb-3">
                    <?= $filtrado
                        ? 'Ningún evento coincide con el filtro o la búsqueda.'
                        : 'Aquí aparecerán los eventos de seguridad según vayan ocurriendo.' ?>
                </p>
                <?php if ($filtrado): ?>
                    <a href="/admin/security-log" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-rotate-left me-1"></i>Quitar los filtros</a>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="px-4 small text-muted" style="width:170px;">Fecha</th>
                                <th class="small text-muted" style="width:200px;">Evento</th>
                                <th class="small text-muted">Detalle</th>
                                <th class="small text-muted" style="width:180px;">IP</th>
                                <th class="small text-muted" style="width:160px;">Usuario</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs['items'] as $log): ?>
                            <tr>
                                <td class="px-4 small text-muted"><?= View::datetime($log['created_at']) ?></td>
                                <td><span class="badge text-bg-light border small text-start"><?= View::escape($log['event']) ?></span></td>
                                <td class="small"><?= View::escape($log['detail'] ?? '') ?></td>
                                <td class="small text-muted"><?= View::escape($log['ip'] ?? '—') ?></td>
                                <td class="small"><?= $log['user_name'] !== null ? View::escape($log['user_name']) : '<span class="text-muted">—</span>' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?= View::partial('dashboard/_pagination', [
            'listado' => $logs,
            'base'    => '/admin/security-log',
            'qs'      => $filtroQs(),
            'sujeto'  => 'evento',
            'aria'    => 'Paginación del log de seguridad',
        ]) ?>
    <?php endif; ?>
</div>
