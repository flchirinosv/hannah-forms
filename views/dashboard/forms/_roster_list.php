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
 * ## La edición va en el menú `⋯` de la fila, en un modal compartido
 *
 * Es el mismo patrón que la tabla de usuarios de `/admin`: cada fila tiene un
 * botón `⋯` (`[data-vf-participant]`) que abre `#rosterEditModal` con el panel de
 * ESA fila (`#rowactions-<pid>`) movido dentro. Antes se editaba con un
 * `<details>` por fila, que funcionaba sin JavaScript; se cambió por el modal a
 * conciencia para tener paridad con el panel de administración: sin JavaScript la
 * fila solo muestra el botón. El ALTA conserva su camino sin JS (el formulario se
 * sirve en línea en el panel y `moverFormulariosInline()` lo traslada al modal).
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
 * línea de enlaces con el activo marcado (`aria-current`). Son GET a la pantalla
 * de participantes, como la búsqueda: sin JavaScript, compartibles y con el
 * «atrás» del navegador.
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

    return '/forms/' . $formId . '/access?' . http_build_query($params) . '#participantes';
};

// El query string de los filtros que el paginador compartido (`_pagination.php`)
// debe conservar al saltar de página: la búsqueda y el orden viven en la URL, y
// un enlace de paginación sin ellos sería la página N de la lista entera.
$qs = http_build_query(array_filter(
    ['q' => $q, 'sort' => $sort !== 'cedula' ? $sort : ''],
    static fn(string $v): bool => $v !== ''
));

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
                    <a href="/forms/<?= (int) $formId ?>/access#participantes">Ver la lista entera</a>.
                <?php } else { ?>
                    La lista está vacía: nadie puede responder todavía. Añade a uno con el
                    formulario de arriba o importa un archivo desde la tarjeta <em>Importar</em>.
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
                            <?php // Acciones de la fila en un menú `⋯`, igual que la
                                  // tabla de usuarios de `/admin`: un solo modal
                                  // (#rosterEditModal) que `dashboard.js` rellena con
                                  // el panel de ESA fila. Sin JavaScript la fila solo
                                  // enseña el botón (no se abre nada), y se acepta a
                                  // conciencia ese intercambio por paridad con /admin.
                                  //
                                  // El panel de la fila vive en un `<span hidden>`
                                  // (`rowactions-<pid>`) y se MUEVE al modal y se
                                  // devuelve al cerrar: los formularios, los `id` de
                                  // los huecos y los avisos son de esa fila, y
                                  // clonarlos sería una copia por participante. ?>
                            <button type="button" class="btn btn-outline-secondary btn-sm app-row-menu-btn"
                                    data-vf-participant="<?= $pid ?>"
                                    data-vf-participant-name="<?= View::escape($identidad($fila)) ?>"
                                    aria-label="Acciones de <?= View::escape($identidad($fila)) ?>"
                                    aria-haspopup="true">
                                <i class="fa-solid fa-ellipsis"></i>
                            </button>

                            <span id="rowactions-<?= $pid ?>" hidden>
                                <div class="p-2 app-row-menu">
                                    <?php // El botón «Guardar» vive FUERA del formulario y lo asocia
                                          // `form="rosterEditForm<pid>"`, igual que el «Guardar» de la
                                          // ficha (`form="formUpdate"`): así comparte fila con «Quitar»
                                          // y los dos quedan alineados. `vfSubmitButton()` lo busca por
                                          // ese atributo para apagarlo mientras el POST vuela.
                                          //
                                          // Una sola columna: con `col-md-4` el rótulo y el campo
                                          // quedaban tan estrechos que no se leía lo que se escribía. ?>
                                    <form method="post" action="/forms/<?= (int) $formId ?>/participants/update"
                                          id="rosterEditForm<?= $pid ?>"
                                          data-vf-async="slots" data-vf-error="rosterEditError<?= $pid ?>">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="participant_id" value="<?= $pid ?>">
                                        <input type="hidden" name="rosterQ" value="<?= View::escape($q) ?>">
                                        <input type="hidden" name="rosterPage" value="<?= $pagina ?>">
                                        <input type="hidden" name="rosterSort" value="<?= View::escape($sort) ?>">
                                        <div class="row g-2">
                                            <div class="col-12">
                                                <label class="form-label small mb-1" for="rosterEditId<?= $pid ?>"><?= $rotuloId ?></label>
                                                <input type="<?= $esCorreo ? 'email' : 'text' ?>" name="<?= $columnaId ?>"
                                                       id="rosterEditId<?= $pid ?>" class="form-control form-control-sm" maxlength="190"
                                                       required value="<?= View::escape((string) ($fila[$columnaId] ?? '')) ?>">
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label small mb-1" for="rosterEditNombre<?= $pid ?>">Nombre (opcional)</label>
                                                <input type="text" name="nombre" id="rosterEditNombre<?= $pid ?>"
                                                       class="form-control form-control-sm" maxlength="190"
                                                       value="<?= View::escape((string) ($fila['nombre'] ?? '')) ?>">
                                            </div>
                                            <?php if (!$esCorreo) { ?>
                                                <div class="col-12">
                                                    <label class="form-label small mb-1" for="rosterEditCorreo<?= $pid ?>">Correo (opcional)</label>
                                                    <input type="email" name="correo" id="rosterEditCorreo<?= $pid ?>"
                                                           class="form-control form-control-sm" maxlength="190"
                                                           value="<?= View::escape((string) ($fila['correo'] ?? '')) ?>">
                                                </div>
                                            <?php } ?>
                                            <?php foreach ($roster['atributos'] as $campo) {
                                                $clave = (string) $campo['key_name']; ?>
                                                <div class="col-12">
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
                                        <p class="small mb-0 mt-1 d-none text-danger fw-semibold"
                                           id="rosterEditError<?= $pid ?>" role="alert"></p>
                                    </form>

                                    <?php // «Quitar» a la izquierda y «Guardar» a la derecha, en la
                                          // misma fila: el `ms-auto` los separa sin depender del ancho.
                                          // Quitar a alguien NO borra sus respuestas (ver
                                          // `FormParticipant::delete()`), y por eso su diálogo lo dice:
                                          // quien lee el aviso tiene que saber qué va a perder y qué no. ?>
                                    <div class="d-flex align-items-center gap-2 mt-3">
                                        <form method="post" action="/forms/<?= (int) $formId ?>/participants/delete"
                                              data-vf-async="slots"
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
                                        <button type="submit" form="rosterEditForm<?= $pid ?>"
                                                class="btn btn-outline-primary btn-sm ms-auto">
                                            <i class="fa-solid fa-check me-1"></i>Guardar
                                        </button>
                                    </div>
                                </div>
                            </span>
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

    <?php // El paginador es el MISMO partial que el listado del dashboard y el de
          // usuarios de `/admin` (`_pagination.php`): enlaces de verdad que
          // conservan los filtros, no botones. Antes era un «Anterior/Siguiente»
          // propio que no decía en qué página estabas. ?>
    <?= View::partial('dashboard/_pagination', [
        'listado' => [
            'items'    => $roster['filas'],
            'total'    => $total,
            'page'     => $pagina,
            'pages'    => $paginas,
            'per_page' => \App\Services\RosterPanel::POR_PAGINA,
        ],
        'base'   => '/forms/' . (int) $formId . '/access',
        'qs'     => $qs === '' ? '' : '?' . $qs,
        'sujeto' => 'participante',
        'aria'   => 'Páginas de la lista de participantes',
    ]) ?>
</div>
