<?php
use App\Core\View;
use App\Models\Answer;
$sid = (int) $submission['id'];
$files = is_array($files ?? null) ? $files : [];
?>
<div class="container px-lg-4" style="max-width: 860px;">
    <div class="mb-4">
        <a href="/forms/<?= (int) $form['id'] ?>/responses" class="text-decoration-none text-muted small"><i class="fa-solid fa-arrow-left me-1"></i>Volver a respuestas</a>
        <h2 class="h4 fw-bold mb-0 mt-2"><i class="fa-solid fa-inbox me-2 text-primary"></i>Respuesta #<?= $sid ?> — <?= View::escape($form['title']) ?></h2>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="row g-3 small text-muted">
                <div class="col-sm-3">
                    <div class="text-uppercase mb-1">Fecha</div>
                    <div class="text-dark"><?= View::datetime($submission['finished_at']) ?></div>
                </div>
                <div class="col-sm-3">
                    <div class="text-uppercase mb-1">IP</div>
                    <div class="text-dark"><?= $submission['ip_address'] !== null && $submission['ip_address'] !== '' ? View::escape($submission['ip_address']) : '—' ?></div>
                </div>
                <div class="col-sm-6">
                    <div class="text-uppercase mb-1">Navegador</div>
                    <div class="text-dark app-truncate-1" title="<?= View::escape($submission['user_agent'] ?? '') ?>"><?= View::escape($submission['user_agent'] ?? '—') ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <h3 class="h6 fw-bold mb-3">Respuestas</h3>
            <?php
            $any = false;
            foreach ($form['fields'] as $field):
                if (($field['type'] ?? '') === 'section') {
                    continue;
                }
                $any = true;
                $val = $answers[(int) $field['id']] ?? '';
                $display = is_string($val) ? Answer::displayValue($val) : '';
                $files = $files[(int) $field['id']] ?? [];
            ?>
                <div class="d-flex flex-column flex-sm-row align-items-sm-start py-3 border-bottom">
                    <div class="text-muted small me-sm-4" style="min-width: 220px;">
                        <?= View::escape($field['label']) ?>
                        <?php if ($field['is_required']): ?><span class="text-danger">*</span><?php endif; ?>
                    </div>
                    <div class="flex-grow-1">
                        <?php if ($files): ?>
                            <?php foreach ($files as $f): ?>
                                <a class="d-inline-flex align-items-center gap-2 text-decoration-none vf-attachment-link"
                                   href="/attachments/<?= (int) $f['answer_id'] ?>?i=<?= (int) $f['index'] ?>"
                                   title="<?= View::escape($f['name']) ?>">
                                    <i class="fa-solid fa-paperclip"></i>
                                    <span class="fw-semibold"><?= View::escape($f['name']) ?></span>
                                    <span class="badge text-bg-light border"><?= View::escape(\App\Services\Uploader::humanSize((int) $f['size'])) ?></span>
                                </a>
                            <?php endforeach; ?>
                        <?php elseif ($display !== ''): ?>
                            <?= View::escape($display) ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (!$any): ?>
                <p class="text-muted small mb-0">Este formulario no tiene campos de respuesta.</p>
            <?php endif; ?>
        </div>
    </div>
</div>