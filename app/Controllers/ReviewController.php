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
 * El flujo son tres transiciones, y cada una comprueba que el estado de origen
 * sea el suyo antes de tocar nada:
 *
 *   draft     --(dueño: sendReview)-->    in_review
 *   in_review --(revisor: approve)-->     published
 *   in_review --(revisor: returnDraft)--> draft
 *
 * La comprobación del estado de origen no es decorativa: sin ella, el botón
 * «Aprobar» de una tarjeta de revisión que se quedó vieja aprobaría un
 * formulario que el dueño ya había corregido y vuelto a mandar.
 */
class ReviewController
{
    /**
     * Dueño: mandar el formulario a revisión.
     *
     * El aviso por correo es OPCIONAL y va en `settings.review.notify_email`,
     * no en un parámetro del POST: quien activa la revisión en el builder está
     * eligiendo si avisar, y el constructor del POST no debería poder activarlo
     * por su cuenta.
     */
    public function sendReview(int $id): void
    {
        $form = $this->editable($id);
        if ($form === null) {
            return;
        }

        if ($form['status'] === 'in_review') {
            Session::flash('error', 'Este formulario ya está en revisión.');
            Response::redirect('/forms/' . $id);
            return;
        }
        if ($form['status'] === 'published') {
            Session::flash('error', 'Un formulario publicado no se manda a revisión: ciérralo antes.');
            Response::redirect('/forms/' . $id);
            return;
        }

        Form::submitForReview($id);
        SecurityLog::record('form.review_request', 'formulario #' . $id . ' (' . (string) $form['slug'] . ')');

        $this->notifyReviewers($form);

        Session::flash('success', 'Formulario enviado a revisión. Queda a la espera de que alguien lo apruebe.');
        Response::redirect('/forms/' . $id);
        return;
    }

    /**
     * Revisor: aprobar y publicar.
     */
    public function approve(int $id): void
    {
        $form = $this->reviewable($id);
        if ($form === null) {
            return;
        }

        if ($form['status'] !== 'in_review') {
            Session::flash('error', 'Este formulario ya no está en revisión.');
            Response::redirect('/forms/' . $id);
            return;
        }

        $user = Auth::user();
        Form::approve($id, (int) ($user['id'] ?? 0));

        // El detalle lleva `propio` cuando el revisor es el dueño, porque en una
        // instancia de un solo usuario la revisión la aprueba el mismo que
        // escribió el formulario. No se prohíbe (sería dejar sin salida a la
        // instalación de un solo usuario, que es el caso normal de un
        // self-hosted), pero sí queda en el log, que es donde se busca cuando
        // alguien pregunta «¿quién publicó esto?».
        $propio = (int) ($form['user_id'] ?? 0) === (int) ($user['id'] ?? -1) ? ' propio' : '';
        SecurityLog::record(
            'form.review_approve',
            'formulario #' . $id . ' (' . (string) $form['slug'] . ')' . $propio
        );

        Session::flash('success', 'Formulario aprobado y publicado.');
        Response::redirect('/forms/' . $id);
        return;
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
            Session::flash('error', 'Este formulario ya no está en revisión.');
            Response::redirect('/forms/' . $id);
            return;
        }

        Form::returnToDraft($id);
        SecurityLog::record('form.review_reject', 'formulario #' . $id . ' (' . (string) $form['slug'] . ')');

        Session::flash('success', 'Formulario devuelto al dueño como borrador.');
        Response::redirect('/forms/' . $id);
        return;
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
     * `Access::canEdit` sigue estando: el revisor tiene que poder ver el
     * formulario, y con `forms.review` sin `forms.manage_all` no lo vería en el
     * dashboard. Se comprueba el permiso de revisión PRIMERO porque es el que
     * decide de esta pantalla.
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
     * Aviso por correo a quienes pueden revisar. Silencioso por diseño: si el
     * SMTP falla, el formulario YA está en revisión y el dueño ya ha visto su
     * mensaje de éxito; un 500 aquí lo dejaría pensando que no se envió y
     * reintentaría, con dos formularios iguales en la cola.
     */
    private function notifyReviewers(array $form): void
    {
        $settings = Form::settings((int) $form['id']);
        if (empty($settings['review']['notify_email'])) {
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

        $locale = (string) \App\Core\Config::get('app.locale', 'es');
        if (!in_array($locale, I18n::supportedLocales(), true)) {
            $locale = 'es';
        }
        $url = PublicUrl::base() . '/forms/' . (int) $form['id'];
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
