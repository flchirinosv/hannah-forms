<?php

use App\Core\Csrf;
use App\Core\View;
use App\Services\RosterPanel;

/**
 * Pantalla de acceso y listado de participantes (`GET /forms/{id}/access`).
 *
 * Antes era una tarjeta al final de la ficha. Se separó porque el listado del
 * roster (fases 5–12) creció hasta no caber con dignidad dentro de la columna de
 * los ajustes: pestañas, alta, importación y, desde el rediseño, modales. La
 * cabecera tiene «← Volver al formulario» y el botón «Participantes» de la ficha
 * apunta aquí.
 *
 * El MODO y el identificador siguen editándose en «Ajustes del formulario» de la
 * ficha: esta pantalla los LEE. Por eso el mismo `_access_card.php` vale en las
 * dos: allí se pintaba dentro del hueco `vfSlotAccess` (que reponía el guardado
 * de ajustes) y aquí directamente.
 *
 * ## TRES tarjetas, no una (rediseño 2026-10-15)
 *
 * La cabecera de acceso, el tablero de números y la lista vivían apilados dentro
 * de la misma tarjeta, y en un formulario del tamaño real la suma se leía como un
 * bloque recargado. Ahora cada cosa va en su tarjeta, a todo el ancho:
 *
 *  1. `#access` — la cabecera: icono, chip del modo y el aviso de cómo se entra.
 *  2. El tablero de números y las invitaciones por correo (`RosterPanel::panel()`,
 *     que pinta `_roster_stats` + `_roster_invites` en su tarjeta).
 *  3. La lista: buscador, filas y paginador, con las pestañas Lista/Importar.
 *
 * `RosterPanel::panel()` se llama AQUÍ, no desde `_access_card.php`: la cabecera
 * no cambia con el estado de la lista y la lista no cambia con el modo de acceso,
 * así que empaquetarlas juntas ataba dos cosas que se repintan por motivos
 * distintos.
 *
 * ## Los modales van AQUÍ, a nivel superior del fragmento
 *
 * `ViewStructureTest` parsea cada vista como lo haría el navegador y prohíbe un
 * `.modal` dentro de otro elemento (todo lo que lo contiene está oculto, por
 * definición). El panel del roster abre `<div id="participantes">` y lo cierra,
 * así que meter los modales dentro lo dejaría anidados. Van después del
 * contenedor, junto al `<script>`. La cabecera del diálogo replica la de
 * `/admin` (`app-navbar` + `app-logo-sm` + `btn-close-white`).
 *
 * El alta funciona sin JavaScript (el montaje del formulario se ve en la
 * tarjeta y `moverFormulariosInline` lo traslada al diálogo con JS); la EDICIÓN
 * por fila es paridad con `/admin` (`[data-vf-participant]` + `#rowactions-<pid>`
 * que `dashboard.js` mueve a `#rosterEditModal`): sin JavaScript la fila solo
 * enseña el botón `⋯`, y se acepta a conciencia ese intercambio.
 *
 * @var array $form
 * @var array $user
 * @var string $accessMode
 * @var array $accessList
 * @var int $accessCount
 * @var array<string,mixed>|null $roster
 * @var array<string,mixed>|null $informe
 */
$formId = (int) $form['id'];
$accessMode = (string) ($accessMode ?? 'public');
?>
<div class="container px-lg-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between mb-4 gap-3 app-page-head">
        <div>
            <a href="/forms/<?= $formId ?>" class="text-decoration-none text-muted small"><i class="fa-solid fa-arrow-left me-1"></i>Volver al formulario</a>
            <h2 class="h4 fw-bold mb-0 mt-2"><i class="fa-solid fa-users me-2 text-primary"></i>Participantes</h2>
            <p class="text-muted small mb-0 mt-1"><?= View::escape($form['title']) ?></p>
        </div>
    </div>

    <?php // Tarjeta 1: la cabecera de acceso. El `id="access"` y los `data-*` los
          // lee `dashboard.js`: el `data-link-endpoint` es el destino del botón de
          // emitir enlace de la allowlist, y el `data-csrf` su token. Con el id en
          // la tarjeta y no en el cuerpo, los atributos sobreviven a cualquier
          // repintado interno de la rama. ?>
    <div class="card border-0 shadow-sm mb-4" id="access"
         data-csrf="<?= View::escape(Csrf::token()) ?>"
         data-link-endpoint="/forms/<?= $formId ?>/access/link">
        <div class="card-body">
            <?= View::partial('dashboard/forms/_access_card', [
                'form'        => $form,
                'accessMode'  => $accessMode,
                'accessList'  => $accessList,
                'accessCount' => $accessCount,
            ]) ?>
        </div>
    </div>

    <?php // Tarjetas 2 y 3: el tablero y la lista del roster, cada uno en su
          // tarjeta. `data()` como red: la vista siempre manda `$roster` en modo
          // `roster`, pero un hueco vacío en un formulario que acaba de abrir la
          // puerta parece roto. ?>
    <?php if ($accessMode === 'roster'): ?>
        <?= RosterPanel::panel(
            $roster ?? RosterPanel::data($formId),
            $form,
            $informe
        ) ?>
    <?php endif; ?>
</div>

<?php if ($accessMode === 'roster'): ?>
    <?php // Alta: el formulario se sirve EN LÍNEA dentro del panel (así funciona
          // sin JavaScript) y `moverFormulariosInline()` lo traslada a este
          // diálogo; el `<div data-vf-modal-body>` es el hueco destino. La
          // cabecera replica la de `/admin` (`app-navbar` + `app-logo-sm`). ?>
    <div class="modal fade" id="rosterAddModal" tabindex="-1" aria-labelledby="rosterAddModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0">
                <div class="modal-header app-navbar text-white border-0 py-2">
                    <div class="d-flex align-items-center gap-2 text-white">
                        <span class="app-logo-sm" aria-hidden="true"><i class="fa-solid fa-user-plus"></i></span>
                        <span class="fw-semibold" id="rosterAddModalLabel">Añadir uno a la lista</span>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body p-4" data-vf-modal-body></div>
            </div>
        </div>
    </div>

    <?php // Edición: `dashboard.js` mueve el panel de la fila (`#rowactions-<pid>`)
          // a este cuerpo y lo devuelve a su sitio al cerrar. El título lo pone
          // el JS con el nombre del participante. `modal-lg` y no `modal-xl`
          // porque el formulario va a UNA columna: en `xl` los campos saldrían de
          // más de 1000 px y no se leerían. Un input por atributo sigue cabiendo
          // (el cuerpo es scrollable). ?>
    <div class="modal fade" id="rosterEditModal" tabindex="-1" aria-labelledby="rosterEditModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
            <div class="modal-content border-0">
                <div class="modal-header app-navbar text-white border-0 py-2">
                    <div class="d-flex align-items-center gap-2 text-white">
                        <span class="app-logo-sm" aria-hidden="true"><i class="fa-solid fa-user-gear"></i></span>
                        <span class="fw-semibold" id="rosterEditModalLabel">Editar a <span data-vf-modal-title></span></span>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body p-2" data-vf-modal-body></div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php // `dashboard.js` va con `defer` (no es crítico): en esta pantalla hace
      // falta para las pestañas del roster, el traslado de los modales, el menú
      // `⋯` de cada fila y el botón de copiar enlace de la allowlist. ?>
<script src="<?= View::asset('js/dashboard.js') ?>" defer></script>
