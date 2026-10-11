<?php
use App\Core\View;
?>
<div class="container-fluid px-lg-4">
    <?= View::partial('admin/_nav', ['active' => 'security_log']) ?>

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
        <div>
            <h2 class="h4 fw-bold mb-0"><i class="fa-solid fa-shield-halved me-2 text-primary"></i>Log de seguridad</h2>
            <p class="text-muted small mb-0">Eventos de autenticación, CSRF, rate limits, webhooks bloqueados, subidas denegadas y cambios administrativos.</p>
        </div>
        <form method="get" action="/admin/security-log" class="d-flex gap-2 align-items-center app-head-actions">
            <select name="event" class="form-select" style="width:auto">
                <option value="">Todos los eventos</option>
                <?php foreach ($events as $e): ?>
                    <option value="<?= View::escape($e) ?>" <?= $event === $e ? 'selected' : '' ?>><?= View::escape($e) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-outline-secondary"><i class="fa-solid fa-filter"></i></button>
        </form>
    </div>

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
                        <?php if (empty($logs['items'])): ?>
                            <tr><td colspan="5" class="text-center py-5 text-muted small">Sin eventos<?= $event !== '' ? ' para «' . View::escape($event) . '»' : '' ?>.</td></tr>
                        <?php else: ?>
                            <?php foreach ($logs['items'] as $log): ?>
                            <tr>
                                <td class="px-4 small text-muted"><?= View::datetime($log['created_at']) ?></td>
                                <td><span class="badge text-bg-light border small text-start"><?= View::escape($log['event']) ?></span></td>
                                <td class="small"><?= View::escape($log['detail'] ?? '') ?></td>
                                <td class="small text-muted"><?= View::escape($log['ip'] ?? '—') ?></td>
                                <td class="small"><?= $log['user_name'] !== null ? View::escape($log['user_name']) : '<span class="text-muted">—</span>' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ((int) $logs['pages'] > 1): ?>
    <nav aria-label="Paginación del log" class="mt-3">
        <ul class="pagination pagination-sm justify-content-center">
            <li class="page-item <?= (int) $logs['page'] <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="/admin/security-log?page=<?= max(1, (int) $logs['page'] - 1) ?><?= $event !== '' ? '&event=' . urlencode($event) : '' ?>">Anterior</a>
            </li>
            <li class="page-item disabled"><span class="page-link"><?= (int) $logs['page'] ?> / <?= (int) $logs['pages'] ?></span></li>
            <li class="page-item <?= (int) $logs['page'] >= (int) $logs['pages'] ? 'disabled' : '' ?>">
                <a class="page-link" href="/admin/security-log?page=<?= min((int) $logs['pages'], (int) $logs['page'] + 1) ?><?= $event !== '' ? '&event=' . urlencode($event) : '' ?>">Siguiente</a>
            </li>
        </ul>
    </nav>
    <?php endif; ?>
</div>