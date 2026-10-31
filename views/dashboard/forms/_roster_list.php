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
        <?php if ((string) ($roster['q'] ?? '') !== ''): ?>
            <p class="mb-2">Nadie en la lista coincide con la búsqueda.</p>
            <p class="small mb-0">Prueba con otra cédula, nombre, correo o teléfono.</p>
        <?php else: ?>
            <p class="mb-2">No hay participantes en la lista.</p>
            <p class="small mb-0">Descarga la plantilla, rellénala o añade uno manualmente.</p>
        <?php endif; ?>
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
                    <tr data-vf-row>
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
                            <button class="btn btn-sm btn-outline-secondary" type="button"
                                    data-vf-participant="<?= $pid ?>"
                                    data-vf-participant-name="<?= View::escape((string) (($fila['nombre'] ?? '') !== '' ? $fila['nombre'] : ($fila[$columnaIdent] ?? $fila['cedula'] ?? ''))) ?>">
                                <i class="fa-solid fa-ellipsis"></i>
                            </button>
                        </td>
                    </tr>
                    <tr class="d-none">
                        <td colspan="100">
                            <?php // Un ÚNICO hijo: `initAccionesDeFila()` mueve el primer
                                  // elemento de la caja al modal. Con los dos formularios
                                  // sueltos, cada cierre devolvía el que había viajado al
                                  // final y el siguiente clic abría el otro (el de borrar). ?>
                            <div id="rowactions-<?= $pid ?>">
                                <div class="p-2">
                                    <form id="rosterEditForm<?= $pid ?>" method="post" action="/forms/<?= $formId ?>/participants/update"
                                          data-vf-error="rosterEditError<?= $pid ?>">
                                        <?= \App\Core\Csrf::field() ?>
                                        <input type="hidden" name="participant_id" value="<?= $pid ?>">
                                        <div class="row g-2">
                                            <div class="col-12">
                                                <label class="form-label small mb-1" for="rosterEditId<?= $pid ?>"><?= $identificador === 'correo' ? 'Correo' : 'Cédula' ?></label>
                                                <input type="text" class="form-control form-control-sm" id="rosterEditId<?= $pid ?>" name="<?= View::escape($columnaIdent) ?>" value="<?= View::escape((string) ($fila[$columnaIdent] ?? $fila['cedula'] ?? '')) ?>" required>
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label small mb-1" for="rosterEditNombre<?= $pid ?>">Nombre completo</label>
                                                <input type="text" class="form-control form-control-sm" id="rosterEditNombre<?= $pid ?>" name="nombre" value="<?= View::escape((string) ($fila['nombre'] ?? '')) ?>">
                                            </div>
                                            <?php if ($identificador !== FormParticipant::IDENT_CORREO): ?>
                                                <div class="col-12">
                                                    <label class="form-label small mb-1" for="rosterEditCorreo<?= $pid ?>">Correo</label>
                                                    <input type="email" class="form-control form-control-sm" id="rosterEditCorreo<?= $pid ?>" name="correo" value="<?= View::escape((string) ($fila['correo'] ?? '')) ?>">
                                                </div>
                                            <?php endif; ?>
                                            <div class="col-12">
                                                <label class="form-label small mb-1" for="rosterEditTelefono<?= $pid ?>">Teléfono</label>
                                                <input type="text" class="form-control form-control-sm" id="rosterEditTelefono<?= $pid ?>" name="telefono" value="<?= View::escape((string) ($fila['telefono'] ?? '')) ?>">
                                            </div>
                                            <?php foreach ($atributos as $atributo):
                                                $key = (string) ($atributo['key_name'] ?? '');
                                                $label = (string) ($atributo['label'] ?? $key);
                                            ?>
                                                <div class="col-12">
                                                    <label class="form-label small mb-1" for="rosterEditAttr<?= $pid ?>-<?= View::escape($key) ?>"><?= View::escape($label) ?></label>
                                                    <input type="text" class="form-control form-control-sm" id="rosterEditAttr<?= $pid ?>-<?= View::escape($key) ?>" name="attr_<?= View::escape($key) ?>" value="<?= View::escape((string) ($data[$key] ?? '')) ?>">
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <p class="small mb-0 mt-2 d-none text-danger fw-semibold" id="rosterEditError<?= $pid ?>" role="alert"></p>
                                    </form>
                                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3 pt-3 border-top">
                                        <form method="post" action="/forms/<?= $formId ?>/participants/delete" data-confirm="¿Quitar este participante de la lista?" data-confirm-ok="Sí, quitar" data-confirm-tone="danger">
                                            <?= \App\Core\Csrf::field() ?>
                                            <input type="hidden" name="participant_id" value="<?= $pid ?>">
                                            <button class="btn btn-outline-danger app-form-cta" type="submit"><i class="fa-solid fa-user-minus me-1"></i>Quitar</button>
                                        </form>
                                        <button class="btn btn-primary app-form-cta ms-auto" type="submit" form="rosterEditForm<?= $pid ?>">Guardar</button>
                                    </div>
                                </div>
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
