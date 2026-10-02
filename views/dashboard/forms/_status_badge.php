<?php

use App\Core\View;
/**
 * La insignia de estado de la ficha (`/forms/{id}`).
 *
 * Es un partial porque su clase Y su texto dependen de `forms.status`, y hay
 * dos caminos que la pintan: el GET de la ficha y el `html` de un POST de
 * estado (publicar, cerrar, aprobar…). Con el badge dentro de la vista, un
 * «Cerrar» sin recarga dejaba el formulario cerrado con la insignia de
 * «Publicado» al lado, y no había forma de que los dos se pusieran de acuerdo:
 * el botón se iba a `/publish` y el texto se quedaba.
 *
 * Variables: `$form`.
 */
?>
<span class="app-status app-status-<?= View::escape($form['status']) ?>"><?= View::escape(\App\Models\Form::statusLabel((string) $form['status'])) ?></span>
