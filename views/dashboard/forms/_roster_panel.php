<?php

use App\Core\View;

/**
 * FASE 5 — El panel de participantes de la pantalla de acceso
 * (`/forms/{id}/access`).
 *
 * ## Dos tarjetas: el tablero y la lista (rediseño 2026-10-15)
 *
 * Los números del formulario y la tabla de filas estaban apilados dentro de la
 * tarjeta de Acceso, y la suma se leía como un bloque recargado. Ahora el tablero
 * (números + invitaciones por correo) y la lista (buscador, filas y paginador) van
 * cada uno en su tarjeta, a todo el ancho. La cabecera de acceso es una tercera,
 * pero esa la pinta `_access_card.php`.
 *
 * ## Por qué dos pestañas dentro de la tarjeta de la lista
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
 * ## El tablero va FUERA de `#participantes`
 *
 * Porque los números son del formulario entero (y lo mismo las invitaciones, que
 * no cambian al abrir una pestaña), así que no tienen por qué viajar con el
 * interruptor de la lista. `#rosterStats` y `#rosterInvites` siguen siendo los
 * mismos huecos que repone `RosterPanel::html()`: lo que cambió es su tarjeta, no
 * su contrato.
 *
 * ## Los ids de los huecos van en ENVOLTORIOS `<div>`, nunca en el `<form>`
 *
 * Por lo que dice la regla de la casa: el repintado mete markup dentro del
 * elemento, y un `<form>` anidado es un formulario que el parser descarta. El
 * montaje del alta vive FUERA del hueco `rosterAddForm`: `#rosterAddForm` es el
 * `data-vf-modal-form` que `moverFormulariosInline()` traslada al diálogo, y el
 * envoltorio `#rosterAddMount` (que se oculta) se queda fuera para que un
 * repintado no recrea el montaje dentro de otra copia.
 *
 * ## El rediseño no cambió el contrato
 *
 * `#participantes`, `vfPaneRosterLista`/`vfPaneRosterImportar`, los dos
 * `data-vf-pane`, los seis huecos y las acciones con `data-confirm` siguen
 * siendo los mismos: lo único que cambió es el aspecto y que tablero y lista
 * viven en tarjetas distintas. Lo fija `RosterPanelTest`. `#rosterList` lo pone
 * su propio partial (`_roster_list.php`): antes el panel lo envolvía en OTRO
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

<?php // TARJETA 1: el tablero. Números del formulario entero + invitaciones por
      // correo, en su propia tarjeta para que la lista no los empuje fuera. ?>
<div class="card border-0 shadow-sm mb-4" id="rosterBoardCard">
    <div class="card-body">
        <h3 class="h6 fw-bold mb-3">
            <i class="fa-solid fa-chart-simple me-1 text-primary" aria-hidden="true"></i>Resumen
        </h3>

        <?= View::partial('dashboard/forms/_roster_stats', ['roster' => $roster]) ?>

        <?php // FASE 10: el botón de invitar va aquí, debajo de los números y SIN
              // `<details>`. Es un hueco (`rosterInvites`) y no una fila más de la
              // tabla porque lo que lo decide —el identificador del formulario y si
              // hay SMTP— no es estado de la lista, y un alta de una persona no
              // puede reponer algo que no ha cambiado. Los tres estados se DICEN. ?>
        <div class="mt-3">
            <?= View::partial('dashboard/forms/_roster_invites', [
                'roster' => $roster,
                'formId' => $formId,
            ]) ?>
        </div>
    </div>
</div>

<?php // TARJETA 2: la lista. Pestañas Lista/Importar, el buscador, las filas y el
      // paginador. `#participantes` es el contenedor de las pestañas y `dashboard.js`
      // lo marca con `data-vf-tabs-ready` para re-inicializarlo tras un repintado. ?>
<div class="card border-0 shadow-sm" id="rosterListCard">
    <div class="card-body">
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

            <?php // «Lista»: el buscador, el alta de uno y las filas (el tablero y las
                  // invitaciones quedaron arriba, en su tarjeta). ?>
            <div id="vfPaneRosterLista" role="tabpanel" aria-labelledby="vfTabRosterLista" data-vf-pane">
                <?php // El buscador es un GET a la MISMA pantalla con `?q=` y `?page=`, y no
                      // un fetch: así llega sin JavaScript, se puede compartir en un
                      // correo y el botón «atrás» del navegador deshace una búsqueda. Las
                      // acciones por fetch guardan la búsqueda actual en `rosterQ` /
                      // `rosterPage` para reponer la misma vista en la que se estaba.
                      //
                      // El `sort` sí viaja (oculto) porque el orden también es estado de la
                      // vista. La página NO viaja, y a propósito: una búsqueda nueva empieza
                      // por la primera, que es lo que se espera de un buscador. ?>
                <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                    <form method="get" action="/forms/<?= $formId ?>/access" class="flex-grow-1" role="search">
                        <?php if ((string) $roster['sort'] !== 'cedula') { ?>
                            <input type="hidden" name="sort" value="<?= View::escape((string) $roster['sort']) ?>">
                        <?php } ?>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text" aria-hidden="true"><i class="fa-solid fa-magnifying-glass"></i></span>
                            <input type="search" name="q" value="<?= View::escape((string) $roster['q']) ?>"
                                   class="form-control" maxlength="190"
                                   aria-label="Buscar participante"
                                   placeholder="Cédula, nombre, correo o código">
                            <button type="submit" class="btn btn-outline-secondary">Buscar</button>
                        </div>
                    </form>

                    <?php if ((string) $roster['q'] !== '') { ?>
                        <a class="btn btn-link btn-sm" href="/forms/<?= $formId ?>/access#participantes">Limpiar</a>
                    <?php } ?>

                    <?php // El alta va por fetch con el resto de las acciones
                          // (`data-vf-async="slots"`), y vuelve en `html.rosterAddForm`, que
                          // es este MISMO `#rosterAddForm`. `data-vf-error` dice dónde va el
                          // «ese identificador ya está»: junto al campo, que es donde se busca.
                          // `data-vf-clear` lo vacía y devuelve el foco a él para apuntar el
                          // siguiente sin tabular hasta el principio. El `method`/`action`
                          // siguen ahí a propósito, así que SIN JavaScript el alta funciona
                          // igual (el mismo POST responde en HTML o en JSON).
                          //
                          // El formulario se sirve EN LÍNEA dentro del montaje (así funciona
                          // sin JavaScript) y `moverFormulariosInline()` de `dashboard.js` lo
                          // traslada al diálogo `#rosterAddModal` —patrón de `/admin/users`—.
                          // El botón solo lo convierte en modal con JS; sin él sigue viéndose
                          // el montaje y el botón no hace nada. Esto reemplaza al `<details>`
                          // anterior, que era un cajón sin JS de Bootstrap: con la tarjeta de
                          // Acceso en su propia pantalla ya hay sitio para el modal, y en la
                          // misma página está el `⋯` de editar que lo necesita. ?>
                    <button type="button" class="btn btn-outline-primary btn-sm"
                            data-bs-toggle="modal" data-bs-target="#rosterAddModal">
                        <i class="fa-solid fa-user-plus me-1"></i>Añadir uno a la lista
                    </button>
                </div>

                <div id="rosterAddMount" data-vf-modal-move="#rosterAddModal" class="mb-3">
                    <div id="rosterAddForm" data-vf-modal-form>
                        <?= View::partial('dashboard/forms/_roster_add_form', [
                            'roster' => $roster,
                            'formId' => $formId,
                        ]) ?>
                    </div>
                </div>

                <?php // `#rosterList` lo pone el partial: envolverlo aquí lo duplicaba. ?>
                <?= View::partial('dashboard/forms/_roster_list', [
                    'roster' => $roster,
                    'formId' => $formId,
                ]) ?>
            </div>

            <?php // «Importar»: subir un archivo y el catálogo de atributos en DOS
                  // columnas, porque el catálogo puede ser largo y el formulario corto
                  // (en una sola columna, veinte atributos empujaban el botón de
                  // importar fuera de la vista). «Vaciar la lista» cierra abajo, a todo
                  // el ancho y separado: es lo único que no se puede deshacer. ?>
            <div id="vfPaneRosterImportar" role="tabpanel" aria-labelledby="vfTabRosterImportar" data-vf-pane>
                <div class="row g-4">
                    <div class="col-lg-7">
                        <h4 class="h6 fw-bold mb-1">
                            <i class="fa-solid fa-file-arrow-up me-1 text-primary" aria-hidden="true"></i>Subir un archivo
                        </h4>
                        <p class="small text-muted mb-3"><?= $rotuloColumnas ?></p>

                        <a class="btn btn-outline-secondary btn-sm mb-3" href="/forms/<?= $formId ?>/participants/template">
                            <i class="fa-solid fa-file-arrow-down me-1"></i>Descargar la plantilla
                        </a>

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
                    </div>

                    <div class="col-lg-5">
                        <?php // Como `#rosterList`, el id lo pone el propio partial: envolverlo
                              // aquí lo duplicaba y anidaba. ?>
                        <?= View::partial('dashboard/forms/_roster_catalog', [
                            'roster' => $roster,
                            'formId' => $formId,
                        ]) ?>
                    </div>

                    <?php // Vaciar la lista y quitar un atributo son las dos cosas que no se
                          // pueden deshacer, así que las dos preguntan. Y «vaciar» NO borra
                          // el catálogo (ver `FormParticipant::truncate()`): vaciar quita
                          // gente, y las columnas son el formato del próximo archivo. ?>
                    <div class="col-12">
                        <hr class="my-0">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 pt-3">
                            <p class="small text-muted mb-0">
                                <strong>Vaciar la lista</strong> quita a todas las personas. El catálogo
                                de atributos se queda, para que el próximo archivo tenga el mismo formato.
                            </p>
                            <form method="post" action="/forms/<?= $formId ?>/participants/truncate"
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
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
