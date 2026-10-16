<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Access;
use App\Core\Auth;
use App\Core\PublicUrl;
use App\Core\Response;
use App\Core\View;
use App\Models\Form;

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
 * La tarjeta de Acceso **no** es uno de estos huecos: depende de
 * `settings.access.mode`, no de `forms.status`, y desde que tiene pantalla propia
 * (`GET /forms/{id}/access`) ni siquiera vive en la ficha. Guardar los ajustes en
 * la ficha ya no la repinta: la tarjeta se ve al abrir su pantalla.
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
     */
    public static function json(int $formId, string $message): void
    {
        Response::json([
            'ok'      => true,
            'message' => $message,
            'html'    => self::slots($formId),
        ]);
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
