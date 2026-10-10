<?php

use App\Core\View;

/**
 * FASE 5 — La pestaña de participantes de la tarjeta de Acceso.
 *
 * ## Por qué dos pestañas y no dos tarjetas
 *
 * Porque las dos cosas que hace el dueño con la lista —mirar quién está y meter
 * gente— no se usan juntas: quien importa 200 filas no está leyendo la tabla, y
 * quien busca a uno para cambiarle el correo no tiene un archivo delante. Con las
 * dos cosas en un bloque, la tabla de 25 filas empuja el formulario de importación
 * fuera de la pantalla, que es lo que pasa en las pantallas de verdad.
 *
 * ## Las dos pestañas se ven SIN JavaScript, y es a propósito
 *
 * El interruptor lo pone `dashboard.js` **añadiendo** `hidden` (el atributo
 * nativo), así que sin JS no hay nadie que lo ponga y las dos pestañas salen
 * abiertas, una debajo de otra. Es lo contrario de lo que hace
 * `data-bs-toggle` de Bootstrap, donde `.tab-pane` sin `.active` está oculto por
 * CSS: un panel que depende de JavaScript para *existir* desaparece sin decir
 * nada en el navegador que no lo tiene, y aquí todo lo que hay debajo es de
 * dueño, con `Access::canEdit()` detrás.
 *
 * ## Los ids de los huecos van en ENVOLTORIOS `<div>`, nunca en el `<form>`
 *
 * Por lo que dice la regla de la casa: el repintado mete markup dentro del
 * elemento, y un `<form>` anidado es un formulario que el parser descarta. El
 * `<details>` que despliega el alta vive FUERA del hueco `rosterAddForm`: si
 * viajara en el HTML que repone la importación, cada importación lo recrearía
 * cerrado y el formulario nuevo quedaría escondido.
 *
 * ## El rediseño (2026-10-08) no cambió el contrato
 *
 * `#participantes`, `vfPaneRosterLista`/`vfPaneRosterImportar`, los dos
 * `data-vf-pane`, los seis huecos y las acciones con `data-confirm` siguen
 * siendo los mismos: lo único que cambió es el aspecto (pestañas segmentadas,
 * tira de números, filas). Lo fija `RosterPanelTest`. `#rosterList` lo pone su
 * propio partial (`_roster_list.php`): antes el panel lo envolvía en OTRO
 * `<div id="rosterList">` y quedaba un id duplicado y anidado.
 *
 * Variables: `$form`, `$roster`, y opcionalmente `$informe` (el informe de la
 * última importación, que solo existe en el GET de la ficha; ver
 * `ParticipantController::import()`).
 *
 * @var array $form
 * @var array $roster
 * @var array<string,mixed>|null $informe
 */
$formId = (int) $form['id'];

/* La frase de la plantilla se arma ANTES del HTML, y no con un cierre de PHP
   dentro de otro cierre de PHP: el que aparece dentro de una cadena CORTA el modo
   PHP y lo que viene detrás se va como HTML. Por eso va en bloque. */
$columnaId = match ((string) $roster['identificador']) {
    'indicador' => 'Indicador',
    'correo'    => 'Correo',
    default     => 'Cédula',
};
$rotuloColumnas = 'Un <code>.xlsx</code> con «' . $columnaId . '», «Nombre» y «Correo»';
if ($roster['atributos'] !== []) {
    $rotuloColumnas .= ', más una columna por atributo ('
        . View::escape(implode(', ', array_column($roster['atributos'], 'label'))) . ')';
}
$rotuloColumnas .= '. Guárdalo con el mismo formato y no lo edites a mano.';
?>
<div id="participantes" class="vf-tabs" data-vf-tabs="roster-<?= (int) $form['id'] ?>">
    <div class="vf-tabs-nav" role="tablist" aria-label="Participantes">
        <button type="button" class="vf-tab active" id="vfTabRosterLista" role="tab"
                aria-selected="true" aria-controls="vfPaneRosterLista" data-vf-tab="vfPaneRosterLista">
            <i class="fa-solid fa-users me-1"></i>Lista (<?= (int) $roster['total'] ?>)
        </button>
        <button type="button" class="vf-tab" id="vfTabRosterImportar" role="tab"
                aria-selected="false" aria-controls="vfPaneRosterImportar" data-vf-tab="vfPaneRosterImportar">
            <i class="fa-solid fa-file-arrow-up me-1"></i>Importar
        </button>
    </div>

    <?php // «Lista»: los números, las invitaciones por correo, el alta de uno, la
          // búsqueda y las filas. ?>
    <div id="vfPaneRosterLista" role="tabpanel" aria-labelledby="vfTabRosterLista" data-vf-pane>
        <?= View::partial('dashboard/forms/_roster_stats', ['roster' => $roster]) ?>

        <?php // FASE 10: el botón de invitar va aquí, debajo de los números y SIN
              // `<details>`. Es un hueco (`rosterInvites`) y no una fila más de la
              // tabla porque lo que lo decide —el identificador del formulario y si
              // hay SMTP— no es estado de la lista, y un alta de una persona no
              // puede reponer algo que no ha cambiado. Los tres estados se DICEN. ?>
        <?= View::partial('dashboard/forms/_roster_invites', [
            'roster' => $roster,
            'formId' => $formId,
        ]) ?>

        <?php // El alta va por fetch con el resto de las acciones
              // (`data-vf-async="slots"`), y vuelve en `html.rosterAddForm`, que
              // es este MISMO partial. `data-vf-error` dice dónde va el «ese
              // identificador ya está»: junto al campo, que es donde se busca.
              // `data-vf-clear` lo vacía y devuelve el foco a él para apuntar el
              // siguiente sin tabular hasta el principio. El `method`/`action`
              // siguen ahí a propósito, así que SIN JavaScript el alta funciona
              // igual (el mismo POST responde en HTML o en JSON).
              //
              // Y va en un `<details>` —nativo, sin JavaScript— en vez de en un
              // modal de Bootstrap, porque un modal sin JS no se abre: el alta
              // sería imposible justo en el navegador que más lo necesita. ?>
        <details class="mt-3 mb-3">
            <summary class="vf-details-trigger">
                <i class="fa-solid fa-user-plus me-1"></i>Añadir uno a la lista
            </summary>
            <div class="mt-2" id="rosterAddForm">
                <?= View::partial('dashboard/forms/_roster_add_form', [
                    'roster' => $roster,
                    'formId' => $formId,
                ]) ?>
            </div>
        </details>

        <?php // La búsqueda es un GET a la MISMA ficha con `?q=` y `?page=`, y no
              // un fetch: así llega sin JavaScript, se puede compartir en un
              // correo y el botón «atrás» del navegador deshace una búsqueda. Las
              // acciones por fetch guardan la búsqueda actual en `rosterQ` /
              // `rosterPage` para reponer la misma vista en la que se estaba.
              //
              // El `sort` sí viaja (oculto) porque el orden también es estado de la
              // vista. La página NO viaja, y a propósito: una búsqueda nueva empieza
              // por la primera, que es lo que se espera de un buscador. ?>
        <form method="get" action="/forms/<?= $formId ?>" class="row g-2 mb-2" role="search">
            <?php if ((string) $roster['sort'] !== 'cedula') { ?>
                <input type="hidden" name="sort" value="<?= View::escape((string) $roster['sort']) ?>">
            <?php } ?>
            <div class="col-12 col-sm">
                <input type="search" name="q" value="<?= View::escape((string) $roster['q']) ?>"
                       class="form-control form-control-sm" maxlength="190"
                       aria-label="Buscar participante"
                       placeholder="Cédula, nombre, correo o código">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-outline-secondary btn-sm">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <span class="visually-hidden">Buscar</span>
                </button>
            </div>
            <?php if ((string) $roster['q'] !== '') { ?>
                <div class="col-auto">
                    <a class="btn btn-link btn-sm" href="/forms/<?= $formId ?>#participantes">Limpiar</a>
                </div>
            <?php } ?>
        </form>

        <?php // `#rosterList` lo pone el partial: envolverlo aquí lo duplicaba. ?>
        <?= View::partial('dashboard/forms/_roster_list', [
            'roster' => $roster,
            'formId' => $formId,
        ]) ?>
    </div>

    <?php // «Importar»: plantilla, archivo, informe y catálogo. ?>
    <div id="vfPaneRosterImportar" role="tabpanel" aria-labelledby="vfTabRosterImportar" data-vf-pane>
        <div class="mb-3">
            <a class="btn btn-outline-secondary btn-sm" href="/forms/<?= $formId ?>/participants/template">
                <i class="fa-solid fa-file-arrow-down me-1"></i>Descargar la plantilla
            </a>
            <span class="small text-muted ms-1"><?= $rotuloColumnas ?></span>
        </div>

        <form method="post" action="/forms/<?= $formId ?>/participants/import" enctype="multipart/form-data"
              class="mb-3" data-vf-async="slots" data-vf-error="rosterImportError">
            <?= \App\Core\Csrf::field() ?>
            <input type="hidden" name="rosterQ" value="<?= View::escape((string) $roster['q']) ?>">
            <input type="hidden" name="rosterPage" value="<?= (int) $roster['page'] ?>">
            <input type="hidden" name="rosterSort" value="<?= View::escape((string) $roster['sort']) ?>">

            <div class="mb-2">
                <label class="form-label small mb-1" for="rosterArchivo">Archivo</label>
                <input type="file" name="archivo" id="rosterArchivo" accept=".xlsx" required
                       class="form-control form-control-sm">
            </div>

            <?php // La pregunta del «modo» es la que más caro sale cuando se
                  // contesta mal, así que va ANTES del botón y con las dos
                  // consecuencias escritas al lado, no escondidas en un tooltip. ?>
            <fieldset class="mb-2">
                <legend class="form-label small mb-1">Si alguien ya está en la lista</legend>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="modo" value="fusionar" id="rosterModoFusionar" checked>
                    <label class="form-check-label small" for="rosterModoFusionar">
                        Actualizarlo con lo que dice el archivo y añadir al que no esté
                    </label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="modo" value="reemplazar" id="rosterModoReemplazar">
                    <label class="form-check-label small" for="rosterModoReemplazar">
                        Borrar la lista entera y dejar solo lo que dice el archivo
                    </label>
                </div>
            </fieldset>

            <button type="submit" class="btn btn-outline-primary btn-sm">
                <i class="fa-solid fa-upload me-1"></i>Importar
            </button>
            <p class="small mb-0 mt-1 d-none text-danger fw-semibold" id="rosterImportError" role="alert"></p>
        </form>

        <div id="rosterImportReport">
            <?= View::partial('dashboard/forms/_roster_import_report', ['informe' => $informe ?? null]) ?>
        </div>

        <?php // Vaciar la lista y quitar un atributo son las dos cosas que no se
              // pueden deshacer, así que las dos preguntan. Y «vaciar» NO borra
              // el catálogo (ver `FormParticipant::truncate()`): vaciar quita
              // gente, y las columnas son el formato del próximo archivo. ?>
        <form method="post" action="/forms/<?= $formId ?>/participants/truncate" class="mb-3"
              data-vf-async="slots" data-confirm="¿Vaciar la lista entera? Se quedan fuera todas las personas; sus respuestas, si ya respondieron, siguen guardadas."
              data-confirm-ok="Sí, vaciar" data-confirm-tone="danger">
            <?= \App\Core\Csrf::field() ?>
            <input type="hidden" name="rosterQ" value="<?= View::escape((string) $roster['q']) ?>">
            <input type="hidden" name="rosterPage" value="<?= (int) $roster['page'] ?>">
            <input type="hidden" name="rosterSort" value="<?= View::escape((string) $roster['sort']) ?>">
            <button type="submit" class="btn btn-outline-danger btn-sm">
                <i class="fa-solid fa-eraser me-1"></i>Vaciar la lista
            </button>
        </form>

        <?php // Como `#rosterList`, el id lo pone el propio partial: envolverlo
              // aquí lo duplicaba y anidaba. ?>
        <?= View::partial('dashboard/forms/_roster_catalog', [
            'roster' => $roster,
            'formId' => $formId,
        ]) ?>
    </div>
</div>
