<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Access;
use App\Core\Auth;
use App\Core\PublicUrl;
use App\Core\Response;
use App\Core\View;
use App\Models\Form;
use App\Models\FormAccess;

/**
 * Los huecos de la ficha (`/forms/{id}`) que dependen de `forms.status`, y el
 * sobre JSON que los devuelve para un POST sin recarga.
 *
 * Existe porque «cambiar el estado sin recargar» tiene una mitad invisible que
 * es la que se rompe: no basta con reponer los botones, hay que reponer TODO
 * lo que el estado decide, y hay cuatro sitios en la ficha (la insignia, la
 * traza de revisión, la fila de acciones y la tarjeta de compartir). Si cada
 * uno de los cuatro se queda en la vista, un «Cerrar» sin recarga deja el
 * formulario cerrado con un QR que abre un formulario que ya no acepta
 * respuestas, y no hay forma de que se puestos de acuerdo porque uno se pintó
 * y el otro no.
 *
 * Un quinto hueco, `vfSlotAccess`, **no** va aquí: depende de
 * `settings.access.mode`, no de `forms.status`, y se repone solo cuando se
 * guarda ese ajuste (`accessSlot()`). Meterlo en `slots()` lo devolvería
 * también en publicar o cerrar, que es cuando no ha cambiado nada, y el coste
 * no es trivial: se perderían el enlace de acceso recién emitido y lo que
 * tivesse escrito en el campo del alta.
 *
 * El sobre es `{ok, message, html: {<id del hueco>: <html>}}` —y, para las
 * acciones que se van, `{ok, message, redirect}`—: el HTML viaja ya escapado y
 * el JS solo sustituye el `innerHTML` de los ids que vienen. El JS no sabe
 * pintar una insignia ni un botón —eso es de la vista— y la vista no necesita
 * dos rutas de render (el GET pinta los partials en línea y este POST los
 * devuelve), porque los partials son las dos.
 */
final class FormPanel
{
    /**
     * Los cuatro huecos de la ficha, ya renderizados.
     *
     * Los datos que necesitan los partials se releen aquí (no se pasan) porque
     * quien llama a este método tiene el `$form` del POST, que puede tener un
     * estado distinto del que se acaba de guardar: releer es la única forma de
     * que el HTML sea el del estado real y no el de antes de la acción.
     *
     * @return array<string, string>
     */
    public static function slots(int $formId): array
    {
        $form = Form::find($formId);
        if ($form === null) {
            return [];
        }

        $settings = Form::settings($formId);
        $reviewRequired = ReviewPolicy::requiredFor($form);

        return [
            'vfSlotStatusBadge' => View::partial('dashboard/forms/_status_badge', ['form' => $form]),
            'vfSlotReviewTrail' => View::partial('dashboard/forms/_review_trail', [
                'reviewTrail' => self::reviewTrail($form),
            ]),
            'vfSlotActions' => View::partial('dashboard/forms/_status_actions', [
                'form'           => $form,
                'reviewRequired' => $reviewRequired,
                'canReview'      => Auth::can('forms.review'),
                'canDelete'      => Access::canDelete((int) $form['user_id']),
            ]),
            'vfSlotShare' => View::partial('dashboard/forms/_share_card', [
                'form'             => $form,
                'settings'         => $settings,
                'shareUrl'         => PublicUrl::fillUrl((string) $form['slug']),
                'embedUrl'         => PublicUrl::embedUrl((string) $form['slug']),
                'publicUrlIsLocal' => PublicUrl::isLocalBase(),
            ]),
        ];
    }

    /**
     * Respuesta de un POST de estado que ya se guardó.
     *
     * Solo para las acciones cuyo formulario SIGUE existiendo: las que se van
     * (eliminar el formulario, borrar una respuesta) responden con
     * `Response::json()` y un `redirect`, porque `slots()` releería una ficha
     * que ya no está.
     *
     * `$extraSlots` son los huecos que además cambiaron y no dependen del
     * estado: el guardado de ajustes pasa aquí `accessSlot()`, porque cambiar el
     * modo de acceso repinta la tarjeta de Acceso y nada más. Van sumados con
     * `+`, y no reemplazados, para que nadie pueda perder los cuatro huecos del
     * estado por añadir un quinto.
     */
    public static function json(int $formId, string $message, array $extraSlots = []): void
    {
        Response::json([
            'ok'      => true,
            'message' => $message,
            'html'    => self::slots($formId) + $extraSlots,
        ]);
    }

    /**
     * El hueco de la tarjeta de Acceso, ya renderizado.
     *
     * Se pide aparte de `slots()` por lo que dice el docblock de la clase: este
     * hueco cambia con el **modo de acceso**, y solo lo cambia el guardado de
     * ajustes. Los datos se releen de la BD (no se pasan) porque quien llama
     * tiene los ajustes de *antes* de guardarlos.
     *
     * La lista solo se carga en modo `allowlist` y recortada a 200, igual que
     * en el GET de la ficha (`FormController::show()`): son dos consultas que en
     * los otros dos modos no dicen nada, y 5000 correos en un `<ul>` no ayudan a
     * administrarlos.
     *
     * ## El listado (fase 5) va aquí con la página 1 y sin búsqueda, a propósito
     *
     * Este hueco se repone al **guardar los ajustes**, y quien guarda los ajustes
     * está cambiando el título o el color, no mirando la lista: si el repintado
     * respetara la búsqueda que hubiera en la barra de direcciones, cambiar el
     * color del formulario y volver a la ficha dejaría la lista filtrada por
     * «V-» sin que nadie lo hubiera pedido. La lista se repinta con lo que el
     * dueño tiene delante (la primera página entera), y el GET normal de la ficha
     * es el que lee `?q=` y `?page=`.
     *
     * `$informe` NO se pasa aquí, y por el mismo motivo: el informe de una
     * importación es de un solo uso y lo consume el GET que viene detrás del
     * POST nativo. Si este hueco lo leyera, un guardado de ajustes que coincidiera
     * con ese GET se comería el informe.
     *
     * @return array<string, string>
     */
    public static function accessSlot(int $formId): array
    {
        $form = Form::find($formId);
        if ($form === null) {
            return [];
        }

        $mode = FormAccess::mode(Form::settings($formId));

        return [
            'vfSlotAccess' => View::partial('dashboard/forms/_access_card', [
                'form'        => $form,
                'accessMode'  => $mode,
                'accessList'  => $mode === FormSettings::ACCESS_ALLOWLIST
                    ? FormAccess::allowlistFor($formId, '', 200)
                    : [],
                'accessCount' => $mode === FormSettings::ACCESS_ALLOWLIST
                    ? FormAccess::allowlistCount($formId)
                    : 0,
                'roster'      => $mode === FormSettings::ACCESS_ROSTER
                    ? RosterPanel::data($formId)
                    : null,
                'informe'     => null,
            ]),
        ];
    }

    /**
     * F3 — La traza solo se calcula si hay algo que contar, igual que en la
     * vista (`views/dashboard/forms/show.php`): son dos consultas que en cada
     * visita de una ficha que no está en revisión no dicen nada.
     */
    private static function reviewTrail(array $form): ?array
    {
        if ($form['status'] !== 'in_review' && $form['reviewed_at'] === null) {
            return null;
        }
        return Form::reviewTrail((int) $form['id']);
    }
}
