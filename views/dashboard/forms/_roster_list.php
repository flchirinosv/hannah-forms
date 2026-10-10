<?php

use App\Core\Csrf;
use App\Core\View;

/**
 * FASE 5 — Las tarjetas de la lista de participantes.
 *
 * ## Tarjetas y no una tabla
 *
 * Porque las columnas son las que el dueño defina: una tabla con 8 atributos se
 * sale de cualquier pantalla y con 2 no dice nada. Y porque lo que hay que
 * meter en cada fila —el formulario de editar, con un input por atributo— no cabe
 * en una celda. Las filas comparten lenguaje con la allowlist
 * (`_access_list.php`): avatar, cuerpo y acciones, así que la pantalla del
 * «Acceso» se lee igual en los dos modos que tienen lista.
 *
 * ## El `<details>` de editar, nativo otra vez
 *
 * Un modal de Bootstrap (uno por fila, o uno único que el JS rellene) no se abre
 * sin JavaScript, y sin JavaScript no se puede editar a nadie. `<details>` lo
 * abre el navegador solo.
 *
 * ## El código se enseña, y es lo que se reparte
 *
 * Es la credencial con la que entra la gente, y sin una lista de correos a quien
 * mandarle nada es la única forma de llegar a un participante. Por eso sale en la
 * tarjeta y no solo en el `.xlsx`: quien gestiona la lista la está leyendo.
 *
 * ## El orden son enlaces, no cabeceras
 *
 * Con tarjetas no hay `<th>` donde poner el enlace de orden, así que van en una
 * línea de enlaces con el activo marcado (`aria-current`). Son GET a la ficha,
 * como la búsqueda: sin JavaScript, compartibles y con el «atrás» del navegador.
 *
 * `#rosterList` es el dueño del hueco (el panel ya no lo envuelve): el texto
 * «Nadie en la lista coincide» y el orden de `.filas` son contrato de test.
 *
 * Variables: `$roster`, `$formId`.
 *
 * @var array $roster
 * @var int $formId
 */
$q         = (string) $roster['q'];
$pagina    = (int) $roster['page'];
$paginas   = (int) $roster['paginas'];
$total     = (int) $roster['total'];
$sort      = (string) $roster['sort'];
$esCorreo  = (string) $roster['identificador'] === 'correo';
$columnaId = match ((string) $roster['identificador']) {
    'indicador' => 'indicador',
    'correo'    => 'correo',
    default     => 'cedula',
};
$rotuloId = match ((string) $roster['identificador']) {
    'indicador' => 'Indicador',
    'correo'    => 'Correo',
    default     => 'Cédula',
};

// Con identificador `codigo`, `identifier_value` ES el `code` (es lo que busca la
// puerta) y la cédula se queda solo en su columna. Con los otros tres, la
// columna de la identidad y el `identifier_value` son el mismo valor, así que
// esto es la misma lectura de siempre, con la columna como red por si una fila
// antigua estuviera guardando otra cosa.
$identidad = static function (array $fila) use ($columnaId): string {
    $columna = trim((string) ($fila[$columnaId] ?? ''));

    return $columna !== '' ? $columna : (string) ($fila['identifier_value'] ?? '');
};

$url = static function (int $page, string $sort = '') use ($formId, $q): string {
    $params = ['page' => $page];
    if ($q !== '') {
        $params['q'] = $q;
    }
    if ($sort !== '') {
        $params['sort'] = $sort;
    }

    return '/forms/' . $formId . '?' . http_build_query($params) . '#participantes';
};

$ordenes = [
    'identificador' => (string) $roster['identificador'] === 'codigo' ? 'Código' : $rotuloId,
    'nombre'        => 'Nombre',
    'creado'        => 'Añadido',
];
?>
<div id="rosterList">
    <?php if ($total === 0) { ?>
        <div class="vf-roster-empty">
            <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
            <div>
                <?php if ($q !== '') { ?>
                    Nadie en la lista coincide con «<?= View::escape($q) ?>».
                    <a href="/forms/<?= (int) $formId ?>#participantes">Ver la lista entera</a>.
                <?php } else { ?>
                    La lista está vacía: nadie puede responder todavía. Añade a uno con el
                    formulario de arriba o importa un archivo desde la pestaña <em>Importar</em>.
                <?php } ?>
            </div>
        </div>
    <?php } ?>

    <div class="vf-roster-toolbar">
        <div class="small text-muted">
            <?php if ($q !== '' && $total > 0) { ?>
                <?= $total ?> coincide<?= $total === 1 ? '' : 'n' ?> con «<?= View::escape($q) ?>».
            <?php } elseif ($total > 0) { ?>
                <?= $total ?> participante<?= $total === 1 ? '' : 's' ?>.
            <?php } ?>
            <?php if ($paginas > 1) { ?>
                Página <?= $pagina ?> de <?= $paginas ?>.
            <?php } ?>
            <span class="ms-2">Orden:
                <?php $primero = true; ?>
                <?php foreach ($ordenes as $clave => $rotulo) {
                    if ($clave === 'correo' || ($clave === 'identificador' && $esCorreo)) {
                        continue;
                    } ?>
                    <?php if (!$primero) { ?><span aria-hidden="true">·</span><?php } ?>
                    <?php $primero = false; ?>
                    <?php if ($sort === $clave) { ?>
                        <span class="fw-semibold text-body" aria-current="true"><?= View::escape($rotulo) ?></span>
                    <?php } else { ?>
                        <a href="<?= View::escape($url(1, $clave)) ?>"><?= View::escape($rotulo) ?></a>
                    <?php } ?>
                <?php } ?>
            </span>
        </div>
        <a class="btn btn-outline-secondary btn-sm" href="/forms/<?= (int) $formId ?>/participants/export">
            <i class="fa-solid fa-file-excel me-1"></i>Descargar
        </a>
    </div>

    <?php if ($roster['filas'] !== []) { ?>
        <ul class="vf-access-list mb-2">
            <?php foreach ($roster['filas'] as $fila) {
                $pid = (int) $fila['id'];
                $respondio = !empty($fila['responded_at']);
                $inicial = mb_strtoupper(mb_substr($identidad($fila), 0, 1)) ?: '·'; ?>
                <li class="vf-roster-row">
                    <span class="vf-roster-avatar" aria-hidden="true"><?= View::escape($inicial) ?></span>

                    <div class="vf-roster-main">
                        <div class="vf-roster-name">
                            <?= View::escape($identidad($fila)) ?>
                            <?php if (!empty($fila['nombre'])) { ?>
                                <span class="vf-roster-sub"><?= View::escape((string) $fila['nombre']) ?></span>
                            <?php } ?>
                        </div>

                        <div class="vf-roster-meta">
                            <?php if (!$esCorreo && !empty($fila['correo'])) { ?>
                                <span><i class="fa-solid fa-envelope me-1"></i><?= View::escape((string) $fila['correo']) ?></span>
                            <?php } ?>
                            <span><code><?= View::escape((string) $fila['code']) ?></code></span>
                            <span>Añadido el <?= View::escape(View::date($fila['created_at'] === null ? null : (string) $fila['created_at'])) ?></span>
                        </div>

                        <?php if ($roster['atributos'] !== []) { ?>
                            <div class="vf-roster-tags">
                                <?php foreach ($roster['atributos'] as $campo) {
                                    $clave = (string) $campo['key_name'];
                                    $valor = (string) ($fila['data'][$clave] ?? '');
                                    if ($valor === '') {
                                        continue;
                                    } ?>
                                    <span class="vf-roster-tag">
                                        <?= View::escape((string) $campo['label']) ?>:
                                        <?= View::escape($valor) ?>
                                    </span>
                                <?php } ?>
                            </div>
                        <?php } ?>

                        <div class="vf-roster-actions">
                            <details class="d-inline-block">
                                <summary class="btn btn-outline-secondary btn-sm">Editar</summary>
                                <form method="post" action="/forms/<?= (int) $formId ?>/participants/update"
                                      class="mt-2 p-2 border rounded bg-body"
                                      data-vf-async="slots" data-vf-error="rosterEditError<?= $pid ?>">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="participant_id" value="<?= $pid ?>">
                                    <input type="hidden" name="rosterQ" value="<?= View::escape($q) ?>">
                                    <input type="hidden" name="rosterPage" value="<?= $pagina ?>">
                                    <input type="hidden" name="rosterSort" value="<?= View::escape($sort) ?>">
                                    <div class="row g-2">
                                        <div class="col-12 col-md-4">
                                            <label class="form-label small mb-1" for="rosterEditId<?= $pid ?>"><?= $rotuloId ?></label>
                                        <input type="<?= $esCorreo ? 'email' : 'text' ?>" name="<?= $columnaId ?>"
                                               id="rosterEditId<?= $pid ?>" class="form-control form-control-sm" maxlength="190"
                                               required value="<?= View::escape((string) ($fila[$columnaId] ?? '')) ?>">
                                        </div>
                                        <div class="col-12 col-md-4">
                                            <label class="form-label small mb-1" for="rosterEditNombre<?= $pid ?>">Nombre (opcional)</label>
                                            <input type="text" name="nombre" id="rosterEditNombre<?= $pid ?>"
                                                   class="form-control form-control-sm" maxlength="190"
                                                   value="<?= View::escape((string) ($fila['nombre'] ?? '')) ?>">
                                        </div>
                                        <?php if (!$esCorreo) { ?>
                                            <div class="col-12 col-md-4">
                                                <label class="form-label small mb-1" for="rosterEditCorreo<?= $pid ?>">Correo (opcional)</label>
                                                <input type="email" name="correo" id="rosterEditCorreo<?= $pid ?>"
                                                       class="form-control form-control-sm" maxlength="190"
                                                       value="<?= View::escape((string) ($fila['correo'] ?? '')) ?>">
                                            </div>
                                        <?php } ?>
                                        <?php foreach ($roster['atributos'] as $campo) {
                                            $clave = (string) $campo['key_name']; ?>
                                            <div class="col-12 col-md-4">
                                                <label class="form-label small mb-1" for="rosterEditAttr<?= $pid ?><?= View::escape($clave) ?>">
                                                    <?= View::escape((string) $campo['label']) ?>
                                                </label>
                                                <input type="text" name="data[<?= View::escape($clave) ?>]"
                                                       id="rosterEditAttr<?= $pid ?><?= View::escape($clave) ?>"
                                                       class="form-control form-control-sm" maxlength="255"
                                                       value="<?= View::escape((string) ($fila['data'][$clave] ?? '')) ?>">
                                            </div>
                                        <?php } ?>
                                    </div>
                                    <button type="submit" class="btn btn-outline-primary btn-sm mt-2">
                                        <i class="fa-solid fa-check me-1"></i>Guardar
                                    </button>
                                    <p class="small mb-0 mt-1 d-none text-danger fw-semibold"
                                       id="rosterEditError<?= $pid ?>" role="alert"></p>
                                </form>
                            </details>

                            <?php // Quitar a alguien NO borra sus respuestas (ver
                                  // `FormParticipant::delete()`), y por eso el diálogo
                                  // lo dice: quien lee el aviso tiene que saber qué va
                                  // a perder y qué no. ?>
                            <form method="post" action="/forms/<?= (int) $formId ?>/participants/delete"
                                  class="d-inline-block" data-vf-async="slots"
                                  data-confirm="¿Quitar a <?= View::escape($identidad($fila)) ?> de la lista? Su código deja de servir y sus respuestas, si ya respondió, se quedan guardadas."
                                  data-confirm-ok="Sí, quitar de la lista" data-confirm-tone="danger">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="participant_id" value="<?= $pid ?>">
                                <input type="hidden" name="rosterQ" value="<?= View::escape($q) ?>">
                                <input type="hidden" name="rosterPage" value="<?= $pagina ?>">
                                <input type="hidden" name="rosterSort" value="<?= View::escape($sort) ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm">
                                    <i class="fa-solid fa-trash me-1"></i>Quitar
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="vf-roster-state">
                        <?php if ($respondio) { ?>
                            <span class="vf-roster-state-label vf-roster-state-ok">
                                <i class="fa-solid fa-circle-check me-1"></i>Respondió
                            </span>
                            <div class="vf-roster-date">
                                <?= View::escape(View::datetime($fila['responded_at'] === null ? null : (string) $fila['responded_at'])) ?>
                            </div>
                        <?php } else { ?>
                            <span class="vf-roster-state-label vf-roster-state-pending">Sin responder</span>
                        <?php } ?>
                    </div>
                </li>
            <?php } ?>
        </ul>
    <?php } ?>

    <?php if ($paginas > 1) { ?>
        <nav aria-label="Páginas de la lista" class="d-flex justify-content-between align-items-center">
            <?php if ($pagina > 1) { ?>
                <a class="btn btn-outline-secondary btn-sm" href="<?= View::escape($url($pagina - 1)) ?>">
                    <i class="fa-solid fa-chevron-left me-1"></i>Anterior
                </a>
            <?php } else { ?>
                <span></span>
            <?php } ?>

            <?php if ($pagina < $paginas) { ?>
                <a class="btn btn-outline-secondary btn-sm" href="<?= View::escape($url($pagina + 1)) ?>">
                    Siguiente<i class="fa-solid fa-chevron-right ms-1"></i>
                </a>
            <?php } else { ?>
                <span></span>
            <?php } ?>
        </nav>
    <?php } ?>
</div>
