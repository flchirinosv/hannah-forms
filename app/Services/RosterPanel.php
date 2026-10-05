<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\View;
use App\Models\FormParticipant;

/**
 * Fase 5 — Los datos y los huecos de la pestaña de participantes.
 *
 * ## Por qué un servicio y no el controlador
 *
 * Porque los mismos bloques se pintan desde **dos sitios distintos** y tienen que
 * salir idénticos: el GET de la ficha (`FormController::show()`) y el `html` que
 * devuelve `ParticipantController` cuando algo se ha hecho por fetch. Dos ya
 * obliga a que el segundo no tenga su copia, y además leer la BD por su cuenta
 * en cada uno significa que un cambio en la página (borrar el último de la
 * última página) pinta una lista vacía en un hueco y deja la cabecera diciendo
 * «40» en otro.
 *
 * Y hay un tercer consumidor que **no pinta nada**: `FormPanel::accessSlot()`,
 * que devuelve el `html` de la tarjeta de Acceso al guardar los ajustes. Va por
 * el mismo `data()` con la página 1 y sin búsqueda a propósito (ver allí).
 *
 * ## Los huecos son SEIS y cada uno depende de una cosa distinta
 *
 * - `rosterList`: qué filas se ven (búsqueda, página y orden).
 * - `rosterStats`: los números de arriba. **No son los de la lista**: son del
 *   formulario entero, y por eso van aparte (ver `respondedCountForForm()`).
 * - `rosterInvites`: el botón de invitar por correo y su contador (fase 10). Va
 *   aparte por una razón de las que este archivo lleva todo el rato: lo que
 *   depende de `Mailer::isConfigured()` no es una fila de la lista. Si el botón
 *   fuera una fila más, un alta de una persona repintaría el botón con el
 *   `MAIL_HOST` leído otra vez, y el número de pendientes viajaría atado a la
 *   tabla en vez de al estado que lo produce.
 * - `rosterCatalog`: los atributos. Los crea y los quita la importación, no el
 *   alta de una persona, así que un alta no puede reponerlos.
 * - `rosterAddForm`: el formulario de alta, que tiene un input por atributo. No
 *   lo repone un alta (borraría lo que el dueño tiene a medio escribir) pero sí
 *   la importación ni la baja de un atributo.
 * - `rosterImportReport`: el informe de la última importación, y **solo** de la
 *   última. Por eso viaja por parámetro (`$informe`) en vez de salir de `data()`:
 *   `data()` se llama en cada acción, y un informe guardado en la sesión que se
 *   leyera sin borrarse volvería a enseñar las mismas filas omitidas después de
 *   cualquier alta.
 *
 * Reponer los seis en las seis acciones sería más simple de escribir y peor de
 * leer: quien lee el `html` de un borrado no puede saber cuál de los seis era
 * el que de verdad cambió, y acaba **`innerHTML`-ando el catálogo entero en cada
 * alta** para no tener que pensarlo. La regla de la casa («un hueco va con lo
 * que depende de SU estado») es también una regla de coste: estos se calculan en
 * una visita a la ficha, no en cada tecleo.
 *
 * ## La búsqueda y la página viajan en la URL, no en el `POST`
 *
 * Una búsqueda es un GET a `/forms/{id}?q=…&page=…#participantes`: así llega
 * sin JavaScript, se puede compartir y el botón «atrás» del navegador deshace
 * una búsqueda. Por eso las acciones por fetch **guardan la búsqueda, la página y
 * el orden actuales en inputs ocultos** (`rosterQ`, `rosterPage`, `rosterSort`)
 * para reponer la misma vista en la que se estaba, en vez de saltar a la primera
 * página —y a ordenar por cédula— al borrar a alguien.
 */
final class RosterPanel
{
    public const POR_PAGINA = 25;

    /**
     * FASE 10 — Cuántas invitaciones caben en una tanda.
     *
     * Vive aquí y no en el controlador porque el botón de `_roster_invites.php`
     * **lo dice** («esta tanda manda 50», y el `data-confirm` lo repite): con el
     * número en dos sitios, cambiar el tope sin cambiar el texto produce un
     * botón que anuncia una cosa y manda otra, y eso no da ningún error. Es el
     * mismo motivo por el que `POR_PAGINA` es de la pantalla y no de la API.
     *
     * El controlador lo lee para el `LIMIT` del lote, de modo que los dos caminos
     * (cuánto se manda y qué se anuncia) no pueden separarse.
     */
    public const INVITE_POR_TANDA = 50;

    /** Identificadores que la columna del listado sabe ordenar. */
    public const ORDENES = ['cedula', 'nombre', 'correo', 'identificador', 'creado'];

    /**
     * Cuántos vienen en una página **del panel**.
     *
     * 25, y no el 50 de `ParticipantController::index()`, y la diferencia es
     * deliberada: el 50 es un contrato de API (JSON, con su test atado al
     * número) y esto es una decisión de pantalla. Aquí cada fila lleva su
     * `<details>` de edición con un input por atributo, así que una página de 50
     * participantes con 6 atributos son del orden de 20 000 nodos, y el botón de
     * «Quitar» deja de notarse a los diez scrolls de altura. Los dos números
     * viven en su sitio y ninguno se deduce del otro.
     */
    private function __construct()
    {
    }

    /**
     * Todo lo que necesita la vista del panel.
     *
     * @return array<string,mixed>
     */
    public static function data(int $formId, string $q = '', int $page = 1, string $sort = 'cedula'): array
    {
        $q = trim($q);
        $page = max(1, $page);
        $sort = in_array($sort, self::ORDENES, true) ? $sort : 'cedula';

        $total = FormParticipant::countForForm($formId, $q);
        $paginas = $total > 0 ? (int) ceil($total / self::POR_PAGINA) : 1;
        $page = min($page, $paginas);

        $filas = FormParticipant::allForForm(
            $formId,
            self::POR_PAGINA,
            ($page - 1) * self::POR_PAGINA,
            $q,
            $sort
        );

        $atributos = FormParticipant::catalogFor($formId);
        $identificador = FormParticipant::identifierFor($formId);

        return [
            'identificador' => $identificador,
            // Fase 10. `invitados` es el histórico («a estos les escribimos»),
            // `porInvitar` es lo que puede cambiar con el botón. El segundo solo
            // se cuenta si el identificador es `correo`: en los otros dos el botón
            // no existe, y un número que no se enseña es un `COUNT` que se paga en
            // cada visita a la ficha.
            'invitados'     => FormParticipant::invitedCountForForm($formId),
            'porInvitar'    => $identificador === FormParticipant::IDENT_CORREO
                ? FormParticipant::pendingInviteCount($formId)
                : 0,
            'formId'        => $formId,
            'q'             => $q,
            'page'          => $page,
            'sort'          => $sort,
            'total'         => $total,
            'paginas'       => $paginas,
            'porPagina'     => self::POR_PAGINA,
            'respondidos'   => FormParticipant::respondedCountForForm($formId),
            'atributos'     => $atributos,
            // `conAtributos()` y no un bucle de `attributesOf()` por fila: son
            // 25 consultas que piden lo mismo. Aquí importa porque la página se
            // repinta en cada alta, y cada repintado son 25 viajes.
            'filas'         => FormParticipant::conAtributos($filas, $atributos),
        ];
    }

    /**
     * El `html` de una acción por fetch: `id` → markup ya escapado.
     *
     * Los ids son los que **declara la vista**, no los que se le antojan aquí:
     * si los dos no coinciden, el repintado no falla, no hace nada, y la lista
     * queda diciendo lo que decía antes de borrar a alguien.
     *
     * @param list<string> $cambia qué huecos cambiaron de verdad en esta acción
     * @param array<string,mixed>|null $informe el informe de la importación, si esta
     *                                        acción ES una importación
     *
     * @return array<string,string>
     */
    public static function html(
        int $formId,
        string $q = '',
        int $page = 1,
        string $sort = 'cedula',
        array $cambia = ['list', 'stats', 'invites', 'catalog'],
        ?array $informe = null
    ): array {
        $datos = self::data($formId, $q, $page, $sort);
        $html = [];

        if (in_array('list', $cambia, true)) {
            $html['rosterList'] = View::partial('dashboard/forms/_roster_list', [
                'roster' => $datos,
                'formId' => $formId,
            ]);
        }
        if (in_array('stats', $cambia, true)) {
            $html['rosterStats'] = View::partial('dashboard/forms/_roster_stats', ['roster' => $datos]);
        }
        if (in_array('invites', $cambia, true)) {
            $html['rosterInvites'] = View::partial('dashboard/forms/_roster_invites', [
                'roster' => $datos,
                'formId' => $formId,
            ]);
        }
        if (in_array('catalog', $cambia, true)) {
            // Solo el id, y no la fila del formulario. Aquí no hace falta releerla
            // después de escribir (que es lo que hay que hacer cuando el partial
            // pinta el título): el catálogo solo compone el `action` de cada baja
            // de atributo, y ese `action` es `/forms/{id}/participants/attribute`.
            // El `$form` del controlador, leído antes de la importación, valía
            // igual para eso y por eso no se estropea al reponerlo.
            $html['rosterCatalog'] = View::partial('dashboard/forms/_roster_catalog', [
                'roster' => $datos,
                'formId' => $formId,
            ]);
        }
        if (in_array('addForm', $cambia, true)) {
            $html['rosterAddForm'] = View::partial('dashboard/forms/_roster_add_form', [
                'roster' => $datos,
                'formId' => $formId,
            ]);
        }
        if (in_array('report', $cambia, true)) {
            $html['rosterImportReport'] = View::partial('dashboard/forms/_roster_import_report', [
                'informe' => $informe,
            ]);
        }

        return $html;
    }

    /**
     * El panel entero, para la tarjeta de Acceso.
     *
     * `$informe` va por parámetro y no dentro de `$datos` por lo que dice el
     * bloque de arriba: `data()` se recalcula en cada visita a la ficha, y el
     * informe es de **una** importación. Aquí solo lo trae quien lo ha sacado de
     * la sesión con `takeValue()` (un solo uso) o quien lo ha puesto al importar.
     *
     * @param array<string,mixed>      $datos  lo que devuelve `data()`
     * @param array<string,mixed>      $form   la fila del formulario
     * @param array<string,mixed>|null $informe
     */
    public static function panel(array $datos, array $form, ?array $informe = null): string
    {
        return View::partial('dashboard/forms/_roster_panel', [
            'roster'  => $datos,
            'form'    => $form,
            'informe' => $informe,
        ]);
    }
}
