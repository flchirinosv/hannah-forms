<?php
/**
 * W2 — La tarjeta de una pregunta de respuesta ABiertA en la analítica.
 *
 * Recibe el mismo `$stat` que el resto de ramas de `analytics/show` (con
 * `non_empty` y `abiertas`) y no calcula nada: si el valor se enmascara, ya
 * viene enmascarado de `FieldAggregator`, igual que los gráficos.
 *
 * El listado es de las últimas N respuestas y NO de las más frecuentes: en un
 * campo libre cada valor es único, así que la agregación que alimenta a los
 * gráficos solo serviría para enseñar un párrafo con "c = 1". Aquí lo que se
 * lee es la cronología, y por eso cada fila lleva su fecha y su enlace a la
 * ficha de esa submission.
 *
 * OJO: el enlace es a `/forms/{id}/responses/{sid}` (la ficha de una respuesta
 * concreta), que es el mismo drill-down que la tabla de abajo. `sid` viene de
 * `submissions.id` en la consulta, nunca se arma con el índice de la fila.
 *
 * @var array $f     campo (`id`, `label`, `type`)
 * @var array $stat  su estadística, con `non_empty` y `abiertas`
 * @var array $form  el formulario (para su `id` en el enlace)
 */

use App\Core\View;
use App\Services\Uploader;

$recientes = $stat['abiertas'] ?? [];
?>
<div class="col-md-6" id="campo-<?= (int) $f['id'] ?>">
    <div class="card border-0 shadow-sm h-100">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start gap-3">
                <div>
                    <h4 class="h6 fw-bold mb-0"><?= View::escape($f['label']) ?></h4>
                    <span class="text-muted small"><?= (int) $stat['non_empty'] ?> respuestas</span>
                </div>
                <i class="fa-solid fa-comment-dots text-muted" aria-hidden="true"></i>
            </div>
            <?php if ($recientes === []): ?>
                <p class="text-muted small mt-3 mb-0">Sin respuestas todavía.</p>
            <?php else: ?>
                <ul class="list-unstyled mt-3 mb-0 vf-open-answers">
                    <?php foreach ($recientes as $item): ?>
                        <?php
                        $v = (string) $item['value'];
                        if (Uploader::isAttachmentJson($v)) {
                            $v = (string) Uploader::displayValue($v);
                        }
                        ?>
                        <li>
                            <div class="small app-truncate-2" title="<?= View::escape($v) ?>"><?= View::escape($v) ?></div>
                            <div class="d-flex justify-content-between align-items-center gap-2 mt-1">
                                <span class="text-muted vf-open-when"><?= View::datetime($item['at']) ?></span>
                                <a href="/forms/<?= (int) $form['id'] ?>/responses/<?= (int) $item['sid'] ?>" class="small text-decoration-none"><i class="fa-solid fa-arrow-up-right-from-square me-1"></i>Ver</a>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
