<?php
use App\Core\View;
use App\Models\FormParticipant;
$pg = $pagination;
$total = (int) $pg['total'];
$pages = (int) $pg['pages'];
$cur = (int) $pg['page'];

// Fase 9: `null` = sin columna (no hay `responses.pii`, o el formulario no es
// de listado) y `[]` = columna pintada y ninguna fila atribuida.
$participantes = $participantes ?? null;
$identificador = (string) ($identificador ?? 'cedula');

// Ventana de páginas (server-side) con primera/última y elipsis en saltos.
$window = array_values(array_filter(
    range($cur - 2, $cur + 2),
    static fn($x) => $x >= 1 && $x <= $pages
));
if ($window !== []) {
    if ($window[0] > 2) {
        $window = array_merge([1, null], $window);
    } elseif ($window[0] > 1) {
        $window = array_merge([1], $window);
    }
    if (end($window) < $pages - 1) {
        $window[] = null;
        $window[] = $pages;
    } elseif (end($window) < $pages) {
        $window[] = $pages;
    }
}
?>
<div class="container px-lg-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between mb-4 gap-3 app-page-head">
        <div>
            <a href="/forms/<?= (int) $form['id'] ?>" class="text-decoration-none text-muted small"><i class="fa-solid fa-arrow-left me-1"></i>Volver</a>
            <h2 class="h4 fw-bold mb-0 mt-2"><i class="fa-solid fa-inbox me-2 text-primary"></i>Respuestas — <?= View::escape($form['title']) ?></h2>
        </div>
        <div class="d-flex gap-2">
            <a href="/forms/<?= (int) $form['id'] ?>/export.csv" class="btn btn-sm btn-outline-success"><i class="fa-solid fa-file-csv me-1"></i>CSV</a>
            <a href="/forms/<?= (int) $form['id'] ?>/export.json" class="btn btn-sm btn-success app-btn-primary"><i class="fa-solid fa-file-code me-1"></i>Exportar JSON</a>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="d-flex align-items-center justify-content-between mb-3">
                        <h3 class="h6 fw-bold m-0">Respuestas <span data-vf-role="response-count"><?= $total > 0 ? '(' . $total . ')' : '' ?></span></h3>

                <span class="text-muted small"><?= (int) $pg['per_page'] ?> por página</span>
            </div>

            <?php if ($total === 0): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fa-solid fa-inbox fa-2x mb-2 d-block"></i>
                    <span>Este formulario aún no tiene respuestas.</span>
                </div>
            <?php else: ?>
                <div class="table-responsive app-table-wrap">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th class="small text-muted" style="width: 60px;">#</th>
                                <th class="small text-muted">Fecha</th>
                                <?php if ($participantes !== null): ?>
                                    <th class="small text-muted">Participante</th>
                                <?php endif; ?>
                                <th class="small text-muted">IP<?php if (!empty($pii_masked)): ?> <span class="text-muted" title="Tu rol no incluye el permiso responses.pii">(enmascarada)</span><?php endif; ?></th>
                                <th class="small text-muted text-end" style="width: 170px;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $num = ($cur - 1) * (int) $pg['per_page'];
                            foreach ($pg['items'] as $r):
                                $num++;
                            ?>
                            <?php // `data-vf-row` es lo que el JS quita cuando el borrado va
                                  // bien, y `data-vf-async="remove"` lo que decide que el
                                  // POST vaya por fetch. El contador del encabezado es
                                  // `[data-vf-role="response-count"]`: el `count` de la
                                  // respuesta lo actualiza, porque borrar una respuesta y
                                  // recargar la página entera era lo de antes. ?>
                            <tr data-vf-row>
                                <td class="text-muted"><?= $num ?></td>
                                <td><?= View::datetime($r['finished_at']) ?></td>
                                <?php if ($participantes !== null):
                                    // La fila se busca por `participant_id`, no por
                                    // posición: `array_column` de la página y el
                                    // mapa del modelo no tienen por qué venir en
                                    // el mismo orden.
                                    $quien = $participantes[(int) ($r['participant_id'] ?? 0)] ?? null;
                                    $pie = $quien === null ? null : FormParticipant::identidadDe($quien, $identificador);
                                ?>
                                    <td class="small">
                                        <?php if ($pie === null || $pie['principal'] === ''): ?>
                                            <span class="text-muted">—</span>
                                        <?php else: ?>
                                            <span class="fw-semibold"><?= View::escape($pie['principal']) ?></span>
                                            <?php if ($pie['nombre'] !== ''): ?>
                                                <span class="text-muted d-block small"><?= View::escape($pie['nombre']) ?></span>
                                            <?php endif; ?>
                                            <?php if ($pie['correo'] !== ''): ?>
                                                <span class="text-muted d-block small">
                                                    <i class="fa-solid fa-envelope me-1" aria-hidden="true"></i><?= View::escape($pie['correo']) ?>
                                                </span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <td class="small"><?= $r['ip_address'] !== null && $r['ip_address'] !== '' ? View::escape($r['ip_address']) : '—' ?></td>
                                <td class="text-end">
                                    <a href="/forms/<?= (int) $form['id'] ?>/responses/<?= (int) $r['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-eye me-1"></i>Ver</a>
                                    <form method="post" action="/forms/<?= (int) $form['id'] ?>/responses/<?= (int) $r['id'] ?>/delete" data-confirm="¿Eliminar esta respuesta? Esta acción no se puede deshacer."
                                           data-confirm-ok="Borrar" class="d-inline" data-vf-async="remove">
                                        <?= \App\Core\Csrf::field() ?>
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash me-1"></i>Borrar</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (!empty($aviso_atribucion)): ?>
                    <div class="alert alert-light border small text-muted mt-3 mb-0" role="status">
                        <i class="fa-solid fa-user-lock me-1"></i>
                        Este formulario tiene lista de participantes y sus respuestas llevan anotada la atribución. Aquí no se dice
                        <strong>quién</strong> respondió porque tu rol no incluye el permiso <code>responses.pii</code>; en la ficha del
                        formulario, la lista sí marca a quién le falta responder.
                    </div>
                <?php endif; ?>

                <?php if ($pages > 1): ?>
                    <nav aria-label="Paginación de respuestas" class="mt-3">
                        <ul class="pagination pagination-sm justify-content-center mb-0">
                            <li class="page-item <?= $cur <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="/forms/<?= (int) $form['id'] ?>/responses?page=<?= $cur - 1 ?>" aria-label="Anterior">&laquo;</a>
                            </li>
                            <?php foreach ($window as $p): ?>
                                <?php if ($p === null): ?>
                                    <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                                <?php else: ?>
                                    <li class="page-item <?= $p === $cur ? 'active' : '' ?>">
                                        <a class="page-link" href="/forms/<?= (int) $form['id'] ?>/responses?page=<?= $p ?>"><?= $p ?></a>
                                    </li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <li class="page-item <?= $cur >= $pages ? 'disabled' : '' ?>">
                                <a class="page-link" href="/forms/<?= (int) $form['id'] ?>/responses?page=<?= $cur + 1 ?>" aria-label="Siguiente">&raquo;</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>