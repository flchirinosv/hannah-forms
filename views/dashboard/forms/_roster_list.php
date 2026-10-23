<?php

declare(strict_types=1);

use App\Core\View;
use App\Models\FormParticipant;
use App\Services\RosterPanel;

$roster = is_array($roster ?? null) ? $roster : [];
$formId = (int) ($formId ?? 0);
$filas = is_array($roster['filas'] ?? null) ? $roster['filas'] : [];
$atributos = is_array($roster['atributos'] ?? null) ? $roster['atributos'] : [];
$identificador = (string) ($roster['identificador'] ?? 'cedula');
$columnaIdent = FormParticipant::COLUMNA_IDENTIFICADOR[$identificador] ?? 'cedula';
?>
<?php if ($filas === []): ?>
    <div class="text-center text-muted py-4">
        <p class="mb-2">No hay participantes en la lista.</p>
        <p class="small mb-0">Descarga la plantilla, rellénala o añade uno manualmente.</p>
    </div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr>
                    <th><?= $identificador === 'correo' ? 'Correo' : 'Cédula' ?></th>
                    <th>Nombre completo</th>
                    <th>Correo</th>
                    <th>Teléfono</th>
                    <?php foreach ($atributos as $atributo): ?>
                        <th><?= View::escape((string) ($atributo['label'] ?? '')) ?></th>
                    <?php endforeach; ?>
                    <th>Código</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($filas as $fila): 
                    $pid = (int) ($fila['id'] ?? 0);
                    $data = is_array($fila['data'] ?? null) ? $fila['data'] : [];
                ?>
                    <tr data-vf-row data-vf-participant="<?= $pid ?>">
                        <td><?= View::escape((string) ($fila[$columnaIdent] ?? $fila['cedula'] ?? '')) ?></td>
                        <td><?= View::escape((string) ($fila['nombre'] ?? '')) ?></td>
                        <td><?= View::escape((string) ($fila['correo'] ?? '')) ?></td>
                        <td><?= View::escape((string) ($fila['telefono'] ?? '')) ?></td>
                        <?php foreach ($atributos as $atributo): 
                            $key = (string) ($atributo['key_name'] ?? '');
                        ?>
                            <td><?= View::escape((string) ($data[$key] ?? '')) ?></td>
                        <?php endforeach; ?>
                        <td><code><?= View::escape((string) ($fila['code'] ?? '')) ?></code></td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-secondary" type="button" data-vf-row-actions="#rowactions-<?= $pid ?>">
                                <i class="fa-solid fa-ellipsis"></i>
                            </button>
                        </td>
                    </tr>
                    <tr class="d-none">
                        <td colspan="100">
                            <div id="rowactions-<?= $pid ?>" class="d-flex flex-wrap gap-2 align-items-center">
                                <form method="post" action="/forms/<?= $formId ?>/participants/update" class="d-flex flex-wrap gap-2 align-items-center">
                                    <?= \App\Core\Csrf::field() ?>
                                    <input type="hidden" name="participant_id" value="<?= $pid ?>">
                                    <input class="form-control form-control-sm" style="max-width: 160px" name="<?= View::escape($columnaIdent) ?>" value="<?= View::escape((string) ($fila[$columnaIdent] ?? $fila['cedula'] ?? '')) ?>" placeholder="<?= $identificador === 'correo' ? 'Correo' : 'Cédula' ?>">
                                    <input class="form-control form-control-sm" style="max-width: 200px" name="nombre" value="<?= View::escape((string) ($fila['nombre'] ?? '')) ?>" placeholder="Nombre completo">
                                    <input class="form-control form-control-sm" style="max-width: 200px" name="correo" value="<?= View::escape((string) ($fila['correo'] ?? '')) ?>" placeholder="Correo">
                                    <input class="form-control form-control-sm" style="max-width: 160px" name="telefono" value="<?= View::escape((string) ($fila['telefono'] ?? '')) ?>" placeholder="Teléfono">
                                    <?php foreach ($atributos as $atributo): 
                                        $key = (string) ($atributo['key_name'] ?? '');
                                    ?>
                                        <input class="form-control form-control-sm" style="max-width: 160px" name="attr_<?= View::escape($key) ?>" value="<?= View::escape((string) ($data[$key] ?? '')) ?>" placeholder="<?= View::escape((string) ($atributo['label'] ?? $key)) ?>">
                                    <?php endforeach; ?>
                                    <button class="btn btn-sm btn-primary app-form-cta" type="submit">Guardar</button>
                                </form>
                                <form method="post" action="/forms/<?= $formId ?>/participants/delete" data-confirm="¿Quitar este participante de la lista?" data-confirm-tone="danger">
                                    <?= \App\Core\Csrf::field() ?>
                                    <input type="hidden" name="participant_id" value="<?= $pid ?>">
                                    <button class="btn btn-sm btn-outline-danger app-form-cta" type="submit">Quitar</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (($roster['paginas'] ?? 1) > 1): ?>
        <nav class="mt-3">
            <ul class="pagination pagination-sm mb-0">
                <?php for ($p = 1; $p <= (int) $roster['paginas']; $p++): ?>
                    <li class="page-item <?= $p === (int) $roster['page'] ? 'active' : '' ?>">
                        <a class="page-link" href="/forms/<?= $formId ?>/access?q=<?= urlencode((string) $roster['q']) ?>&page=<?= $p ?>&sort=<?= urlencode((string) $roster['sort']) ?>#participantes"><?= $p ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>
<?php endif; ?>
