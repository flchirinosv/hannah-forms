<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\I18n;
use App\Core\Mailer;
use App\Core\PublicUrl;
use App\Core\Request;
use App\Core\Response;
use App\Core\SecurityLog;
use App\Core\Session;
use App\Core\View;
use App\Models\Form;
use App\Models\User;
use App\Services\FormPanel;
use App\Services\ReviewPolicy;

/**
 * F3 — Revisión y aprobación de formularios.
 *
 * Va en su propio controlador y no en `FormController` porque el permiso que
 * manda aquí NO es el mismo que el del resto: publicar el formulario propio es
 * `forms.edit_own` (que lo tiene el dueño por definición), pero APROBAR es
 * `forms.review`, que el dueño no tiene por defecto. Mezclar las dos cosas en
 * un controlador invite a que un `canEdit()` se lea como si autorizara a
 * aprobar.
 *
 * El flujo son tres transiciones en este controlador, y cada una comprueba que
 * el estado de origen sea el suyo antes de tocar nada:
 *
 *   draft     --(dueño: sendReview)-->    in_review
 *   in_review --(revisor: approve)-->     approved
 *   in_review --(revisor: returnDraft)--> draft
 *
 * Aprobar deja el formulario en `approved`, no en `published`: el paso de
 * `approved` a `published` lo da el DUEÑO con `FormController::publish()`,
 * porque publicar es decidir CUÁNDO sale al público y eso no es revisar.
 *
 * La comprobación del estado de origen no es decorativa: sin ella, el botón
 * «Aprobar» de una tarjeta de revisión que se quedó vieja aprobaría un
 * formulario que el dueño ya había corregido y vuelto a mandar.
 */
class ReviewController
{
    /**
     * «Hecho»: 302 + flash, o el sobre con los huecos de la ficha.
     *
     * Las tres acciones comparten las dos mitades porque las dos pintan las
     * mismas cosas: aprobar sin recarga tenía que reponer la insignia (ya
     * «Aprobado»), la traza de revisión (que aparece justo al aprobar) y los
     * botones (que cambian de «Aprobar/Devolver» a «Publicar»), y la tarjeta
     * de compartir (que pasa a su botón de publicar, todavía sin enlace porque
     * no está publicado). Actualizar solo los botones deja las otras mintiendo.
     */
    private static function accept(int $id, string $message): void
    {
        if (Response::wantsJson()) {
            FormPanel::json($id, $message);
            return;
        }

        Session::flash('success', $message);
        Response::redirect(self::destino($id));
    }

    /**
     * «No se puede»: mismo motivo por los dos caminos. El estado de origen es
     * el que se comprueba, así que esto casi siempre es un botón que se quedó
     * viejo: en JSON es un aviso en la esquina (el aviso del servidor), y sin
     * JS sigue siendo la vuelta a la ficha con el flash de siempre.
     */
    private static function reject(int $id, string $error): void
    {
        if (Response::wantsJson()) {
            Response::json(['ok' => false, 'error' => $error], 422);
            return;
        }

        Session::flash('error', $error);
        Response::redirect(self::destino($id));
    }

    /**
     * A dónde vuelve el actor después de la acción sin recarga.
     *
     * La ficha `/forms/{id}` es del DUEÑO (exige `canEdit`), así que un revisor
     * puro —`forms.review` + `dashboard.view`, sin alcance global— recibiría un
     * 403 al volver ahí: el sitio del revisor es la bandeja. Quien puede editar
     * el formulario (su dueño, o quien tiene `forms.manage_all`) vuelve a la
     * ficha, que es lo que ya hacía.
     */
    private static function destino(int $id): string
    {
        $form = Form::find($id, false);
        if ($form !== null && \App\Core\Access::canEdit((int) $form['user_id'])) {
            return '/forms/' . $id;
        }

        return '/review';
    }

    /**
     * Dueño: mandar el formulario a revisión.
     *
     * El aviso por correo es OPCIONAL y lo decide la política de la instancia
     * (`review_notify_email`), no un parámetro del POST: el dueño no debería
     * poder activarlo por su cuenta al enviar.
     */
    public function sendReview(int $id): void
    {
        $form = $this->editable($id);
        if ($form === null) {
            return;
        }

        if ($form['status'] === 'in_review') {
            self::reject($id, 'Este formulario ya está en revisión.');
            return;
        }
        if ($form['status'] === 'published') {
            self::reject($id, 'Un formulario publicado no se manda a revisión: ciérralo antes.');
            return;
        }
        if ($form['status'] === 'approved') {
            // Un aprobado ya está revisado: mandarlo a la cola solo lo deja
            // esperando de nuevo. Publicarlo, o editarlo (que lo devuelve solo),
            // es lo que quiere hacer el dueño que le enseñan este botón.
            self::reject($id, 'Este formulario ya está aprobado: publícalo o edítalo para volver a mandarlo.');
            return;
        }

        Form::submitForReview($id);
        // El borrador del builder se limpia al mandarlo a revisión: el revisor
        // solo ve la estructura VIVA (`fields`), y un borrador huérfano haría
        // que el dueño, al volver al constructor, siguiera viendo cambios que
        // ya no representan lo que se sometió.
        Form::clearDraft($id);
        SecurityLog::record('form.review_request', 'formulario #' . $id . ' (' . (string) $form['slug'] . ')');

        $this->notifyReviewers($form);

        self::accept($id, 'Formulario enviado a revisión. Queda a la espera de que alguien lo apruebe.');
    }

    /**
     * Revisor: aprobar (sin publicar).
     *
     * Solo deja el formulario en `approved`: quién lo publica es el dueño, y
     * cuándo, él decide. Un aprobado no se sirve al público, igual que un
     * `in_review`, hasta que alguien pase por `FormController::publish()`.
     */
    public function approve(int $id): void
    {
        $form = $this->reviewable($id);
        if ($form === null) {
            return;
        }

        if ($form['status'] !== 'in_review') {
            self::reject($id, 'Este formulario ya no está en revisión.');
            return;
        }

        $user = Auth::user();
        Form::approve($id, (int) ($user['id'] ?? 0));

        // El detalle lleva `propio` cuando el revisor es el dueño, porque en una
        // instancia de un solo usuario la revisión la aprueba el mismo que
        // escribió el formulario. No se prohíbe (sería dejar sin salida a la
        // instalación de un solo usuario, que es el caso normal de un
        // self-hosted), pero sí queda en el log, que es donde se busca cuando
        // alguien pregunta «¿quién aprobó esto?» (publicar ya es un paso del
        // dueño, y ahí manda el log del que pulsa «Publicar»).
        $propio = (int) ($form['user_id'] ?? 0) === (int) ($user['id'] ?? -1) ? ' propio' : '';
        SecurityLog::record(
            'form.review_approve',
            'formulario #' . $id . ' (' . (string) $form['slug'] . ')' . $propio
        );

        self::accept($id, 'Formulario aprobado. Ya lo puede publicar su propietario cuando quiera.');
    }

    /**
     * Revisor: devolver a borrador sin publicar.
     */
    public function returnDraft(int $id): void
    {
        $form = $this->reviewable($id);
        if ($form === null) {
            return;
        }

        if ($form['status'] !== 'in_review') {
            self::reject($id, 'Este formulario ya no está en revisión.');
            return;
        }

        Form::returnToDraft($id);
        SecurityLog::record('form.review_reject', 'formulario #' . $id . ' (' . (string) $form['slug'] . ')');

        self::accept($id, 'Formulario devuelto al dueño como borrador.');
    }

    /**
     * Bandeja del revisor: lo que está esperando.
     *
     * Es una vista aparte y no una sección del dashboard porque el alcance es
     * distinto: el dashboard son «TUS formularios» (o todos, con
     * `forms.manage_all`), y esta lista son los de OTROS que esperan revisión.
     */
    public function inbox(): void
    {
        $user = Auth::user();
        if ($user === null) {
            Response::redirect('/login');
            return;
        }
        if (!Auth::can('forms.review')) {
            Response::forbidden();
            return;
        }

        View::render('review/inbox', [
            'csrf'    => Csrf::token(),
            'pending' => Form::pendingReviews(),
        ]);
    }

    // ---- guards ----

    /**
     * Carga el formulario exigiendo `canEdit` (el dueño, o quien tenga alcance
     * global). Devuelve null y YA respondió con el error correspondiente, para
     * que el llamante no tenga que comprobar nada más.
     */
    private function editable(int $id): ?array
    {
        $form = Form::find($id, false);
        if ($form === null) {
            Response::notFound();
            return null;
        }
        $user = Auth::user();
        if ($user === null) {
            Response::redirect('/login');
            return null;
        }
        if (!\App\Core\Access::canEdit((int) $form['user_id'])) {
            Response::forbidden();
            return null;
        }
        if (!Csrf::verifyRequest()) {
            Response::redirect('/forms/' . $id);
            return null;
        }

        return $form;
    }

    /**
     * Igual que `editable()` pero exigiendo `forms.review`.
     *
     * `Access::canEdit` NO se comprueba aquí, a propósito: aprobar exige el
     * permiso `forms.review` y nunca el de editar (es exactamente el error que
     * este controlador aparte existe para evitar). La bandeja es global —muestra
     * los formularios de TODA la instalación—, así que quien puede revisar
     * revisa el de cualquiera, aunque no sea su dueño ni tenga alcance global.
     * Meter `canEdit` aquí dejaba al rol `approver` (solo `forms.review` +
     * `dashboard.view`) sin poder aprobar nada: el botón estaba y el POST daba
     * 403. Quien revisa NO abre la ficha editable (esa sigue siendo del dueño):
     * mira la estructura read-only y actúa desde la bandeja.
     */
    private function reviewable(int $id): ?array
    {
        if (!Auth::can('forms.review')) {
            Response::forbidden();
            return null;
        }

        $form = Form::find($id, false);
        if ($form === null) {
            Response::notFound();
            return null;
        }
        $user = Auth::user();
        if ($user === null) {
            Response::redirect('/login');
            return null;
        }
        if (!Csrf::verifyRequest()) {
            Response::redirect('/review');
            return null;
        }

        return $form;
    }

    /**
     * Aviso por correo a quienes pueden revisar. Silencioso por diseño: si el
     * SMTP falla, el formulario YA está en revisión y el dueño ya ha visto su
     * mensaje de éxito; un 500 aquí lo dejaría pensando que no se envió y
     * reintentaría, con dos formularios iguales en la cola.
     */
    private function notifyReviewers(array $form): void
    {
        // El aviso es una decisión de la INSTANCIA (opt-in), no del formulario:
        // con el correo apagado el envío a revisión solo aparece en la bandeja.
        if (!ReviewPolicy::notifyEmail()) {
            return;
        }

        $reviewers = User::reviewers();
        if ($reviewers === []) {
            // Nadie puede aprobar: la revisión bloquearía el formulario para
            // siempre. Se avisa al dueño en vez de dejarlo colgado.
            SecurityLog::record(
                'form.review_no_reviewer',
                'formulario #' . (int) $form['id'] . ' sin ningún usuario con forms.review'
            );
            return;
        }

        $locale = \App\Core\InstanceSettings::locale();
        if (!in_array($locale, I18n::supportedLocales(), true)) {
            $locale = 'es';
        }
        $url = PublicUrl::base() . '/review';
        $owner = (string) ($form['owner_name'] ?? '');

        foreach ($reviewers as $reviewer) {
            $html = View::partial('mail/review_request', [
                'locale' => $locale,
                'form'   => [
                    'id'    => (int) $form['id'],
                    'title' => (string) $form['title'],
                ],
                'owner'  => $owner,
                'url'    => $url,
            ]);

            try {
                Mailer::send(
                    [(string) $reviewer['email']],
                    I18n::t('mail.review_subject', $locale),
                    $html,
                    I18n::t('mail.review_text', $locale, [
                        'title' => (string) $form['title'],
                        'owner' => $owner,
                        'url'   => $url,
                    ])
                );
            } catch (\Throwable $e) {
                // Ver el docblock: el aviso es un extra, no el trabajo.
                error_log('[ReviewController] aviso de revisión no enviado a ' . (string) $reviewer['email'] . ': ' . $e->getMessage());
            }
        }
    }
}
