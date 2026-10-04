<?php

use App\Core\Csrf;
use App\Core\View;

/**
 * FASE 5 — El informe de la última importación.
 *
 * ## Por qué un bloque para esto y no solo un aviso
 *
 * Porque el mensaje dice **cuántas** filas quedaron fuera y el informe dice
 * **cuáles**, y lo que el dueño puede arreglar está en las filas: una cédula mal
 * tecleada se corrige en la hoja y se vuelve a subir. Un «5 filas quedaron
 * fuera» sin más obliga a abrir el archivo y buscarlas una a una, que es el
 * trabajo que la importación vino a quitar.
 *
 * ## Se pinta en los DOS caminos, y por eso el informe viaja en la sesión
 *
 * Con JavaScript llega en `html.rosterImportReport`. Sin él, el POST es nativo y
 * el controlador redirige: si el informe viviera solo en el JSON, el navegador
 * sin JS se quedaría con el número y sin las filas — el caso para el que todo lo
 * demás de esta pantalla se degrada a visible. Por eso `import()` deja el
 * informe en la sesión (`Session::flashValue`) y este partial lo pinta en el GET
 * que viene después.
 *
 * ## Solo hay problema si hubo omisiones
 *
 * Una importación limpia pinta el resumen (nuevos, actualizados, atributos) y
 * una lista vacía, que es información: el dueño ve que la operación ocurrió y no
 * que se rompió algo.
 *
 * Variables: `$informe` (null si no hay nada que contar).
 *
 * @var array{total:int,omitidos:int,problemas:list<array{fila:int,motivo:string,detalle:string}>}|null $informe
 */
?>
<?php if ($informe === null || $informe === []) {
    return;
} ?>
<div class="alert alert-<?= (int) $informe['omitidos'] > 0 ? 'warning' : 'success' ?> small" role="status">
    <div class="fw-semibold mb-1">
        <?php if ((int) $informe['omitidos'] > 0) { ?>
            <?= (int) $informe['omitidos'] ?> de <?= (int) $informe['total'] ?> filas no se pudieron guardar.
        <?php } else { ?>
            Las <?= (int) $informe['total'] ?> filas del archivo se guardaron.
        <?php } ?>
    </div>

    <?php if ($informe['problemas'] !== []) { ?>
        <details>
            <summary class="fw-semibold">Ver las <?= count($informe['problemas']) ?> filas</summary>
            <ul class="mb-0 mt-2">
                <?php foreach ($informe['problemas'] as $problema) { ?>
                    <li>
                        <strong>Fila <?= (int) $problema['fila'] ?></strong>:
                        <?= View::escape((string) $problema['motivo']) ?>
                        <?php if (!empty($problema['detalle'])) { ?>
                            <span class="text-muted">(<?= View::escape((string) $problema['detalle']) ?>)</span>
                        <?php } ?>
                    </li>
                <?php } ?>
            </ul>
            <?php // El tope de la lista (200) es lo cómodo de leer y `omitidos` es
                  // el número honesto, así que cuando la lista está topada se
                  // dice: si no, quien lee el informe cree que esas son todas. ?>
            <?php if ((int) $informe['omitidos'] > count($informe['problemas'])) { ?>
                <p class="mb-0 mt-2 text-muted">
                    Se enseñan <?= count($informe['problemas']) ?> de <?= (int) $informe['omitidos'] ?>.
                </p>
            <?php } ?>
        </details>
    <?php } ?>
</div>
