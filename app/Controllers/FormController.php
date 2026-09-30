<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Access;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\DashboardLimit;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\Form;
use App\Services\TemplateService;

class FormController
{
    /**
     * Alta de formulario. Además del modo «en blanco» acepta P9 una
     * `template` (key de database/templates): la estructura y los settings de
     * la plantilla se aplican tras crearlo; si algo falla, se borra el
     * formulario para no dejar restos a medias.
     */
    public function create(): void
    {
        if (!Csrf::verifyRequest()) {
            Session::flash('error', 'Token de seguridad inválido.');
            Response::redirect('/dashboard');
            return;
        }

        // RBAC: sin permiso de creación no hay nada que hacer aquí.
        if (!Auth::can('forms.create')) {
            Response::forbidden();
            return;
        }

        $title = trim(Request::post('title', ''));
        $description = trim(Request::post('description', ''));
        $templateKey = trim(Request::post('template', ''));

        $template = null;
        if ($templateKey !== '') {
            $template = TemplateService::load($templateKey);
            if ($template === null) {
                Session::flash('error', 'La plantilla seleccionada no existe.');
                Response::redirect('/dashboard');
                return;
            }
            // Título/descripción: lo que mande el usuario; si no, los de la plantilla.
            if ($title === '') {
                $title = trim((string) ($template['name'] ?? ''));
            }
            if ($description === '') {
                $description = trim((string) ($template['description'] ?? ''));
            }
        }

        if ($title === '') {
            Session::flash('error', 'El título del formulario es obligatorio.');
            Response::redirect('/dashboard');
            return;
        }

        if (mb_strlen($title) > 190) {
            Session::flash('error', 'El título no puede superar los 190 caracteres.');
            Response::redirect('/dashboard');
            return;
        }

        // F5.3 — techo de creaciones por usuario por hora (spam de formularios).
        if (!DashboardLimit::createAllowed()) {
            Session::flash('error', 'Llegaste al límite de formularios por hora. Intenta más tarde.');
            Response::redirect('/dashboard');
            return;
        }

        $formId = Form::create(Auth::id(), $title, $description);

        if ($template !== null) {
            $error = $this->applyTemplate($formId, $template);
            if ($error !== null) {
                // Sin restos: si la plantilla no se pudo aplicar, se elimina
                // el formulario recién creado.
                Form::delete($formId);
                Session::flash('error', 'No se pudo crear el formulario desde la plantilla: ' . $error);
                Response::redirect('/dashboard');
                return;
            }
            Session::flash('success', 'Formulario creado desde la plantilla «' . (string) ($template['name'] ?? $templateKey) . '». Revísalo en el builder.');
            Response::redirect('/forms/' . $formId . '/builder');
            return;
        }

        Session::flash('success', 'Formulario creado. Ahora puedes agregar campos.');
        Response::redirect('/forms/' . $formId . '/builder');
        return;
    }

    /**
     * Aplica settings + campos de una plantilla a un formulario recién creado.
     *
     * @param array<string, mixed> $template
     */
    private function applyTemplate(int $formId, array $template): ?string
    {
        if (isset($template['settings']) && is_array($template['settings'])) {
            Form::saveSettings($formId, $template['settings']);
        }
        $normalized = TemplateService::normalizeStructure((array) ($template['fields'] ?? []));
        if ($normalized['error'] !== null) {
            return $normalized['error'];
        }
        return TemplateService::apply($formId, $normalized['items']);
    }

    public function show(int $id): void
    {
        $form = Form::find($id);
        $user = Auth::user();

        if ($form === null) {
            Response::notFound();
            return;
        }
        if ($user === null) {
            Response::redirect('/login');
            return;
        }

        if (!$this->canEdit($form, (int) $user['id'])) {
            Response::forbidden();
            return;
        }

        $settings = Form::settings($id);

        View::render('dashboard/forms/show', [
            'form'       => $form,
            'user'       => $user,
            'csrf'       => Csrf::token(),
            'canDelete'  => $this->canDelete($form, (int) $user['id']),
            'settings'   => $settings,
            'shareUrl'   => \App\Core\PublicUrl::fillUrl((string) $form['slug']),
            'embedUrl'   => \App\Core\PublicUrl::embedUrl((string) $form['slug']),
            // La URL se dedujo (o se resolvió a una dirección local): el enlace
            // y el QR solo abrirán desde esta red. Ver App\Core\PublicUrl.
            'publicUrlIsLocal' => \App\Core\PublicUrl::isLocalBase(),
        ]);
    }

    public function update(int $id): void
    {
        $form = Form::find($id);
        $user = Auth::user();

        if ($form === null) {
            Response::notFound();
            return;
        }
        if ($user === null) {
            Response::redirect('/login');
            return;
        }
        if (!$this->canEdit($form, (int) $user['id'])) {
            Response::forbidden();
            return;
        }
        if (!Csrf::verifyRequest()) {
            Session::flash('error', 'Token inválido.');
            Response::redirect('/forms/' . $id);
            return;
        }

        $title = trim(Request::post('title', ''));
        $description = trim(Request::post('description', ''));

        if ($title === '') {
            Session::flash('error', 'El título no puede estar vacío.');
            Response::redirect('/forms/' . $id);
            return;
        }

        if (mb_strlen($title) > 190) {
            Session::flash('error', 'El título no puede superar los 190 caracteres.');
            Response::redirect('/forms/' . $id);
            return;
        }

        // F3 — Misma regla que el builder: si el formulario exige revisión y ya
        // está publicado, cambiar su contenido (aquí, título y descripción) lo
        // devuelve a la cola. Cerrar la puerta en el builder y no en esta deja un
        // «cambia el título por aquí y publícalo sin que nadie lo mire».
        $requeued = self::reviewRequired($form) && $form['status'] === 'published';

        Form::update(
            $id,
            $title,
            $description,
            $requeued ? 'in_review' : $form['status']
        );
        if ($requeued) {
            Form::submitForReview($id);
        }
        Session::flash('success', $requeued
            ? 'Formulario actualizado. Como exige revisión, ha vuelto a la cola.'
            : 'Formulario actualizado.');
        Response::redirect('/forms/' . $id);
        return;
    }

    public function delete(int $id): void
    {
        $form = Form::find($id);
        $user = Auth::user();

        if ($form === null) {
            Response::notFound();
            return;
        }
        if ($user === null) {
            Response::redirect('/login');
            return;
        }
        if (!$this->canDelete($form, (int) $user['id'])) {
            Response::forbidden();
            return;
        }
        if (!Csrf::verifyRequest()) {
            Session::flash('error', 'Token inválido.');
            Response::redirect('/dashboard');
            return;
        }

        // P10/F1.1: dos fases. Primero se RECOLECTAN las rutas (mientras las
        // filas `answers` existen: la cascada de BD las borra con el form y
        // después no hay nada que leer, el archivo se queda huérfano para
        // siempre), luego se borra el form, y solo tras el commit se desenlaza
        // del disco. Purgar antes del delete rompe la operación si el delete
        // falla: el formulario seguiría existiendo con respuestas apuntando a
        // adjuntos inexistentes (404 permanente en /attachments/{id}).
        $orphanPaths = \App\Services\Uploader::pendingForForm($id);

        Form::delete($id);

        // Ya sin filas que referencien los archivos: un fallo aquí solo deja
        // huérfanos en disco, que es el mal recuperable.
        try {
            \App\Services\Uploader::purgePaths($orphanPaths);
        } catch (\Throwable $e) {
            error_log('[FormController] purgeForm(' . $id . '): ' . $e->getMessage());
        }

        \App\Core\SecurityLog::record('form.delete', 'formulario #' . $id . ' (' . (string) $form['slug'] . ')');
        Session::flash('success', 'Formulario eliminado.');
        Response::redirect('/dashboard');
        return;
    }

    public function publish(int $id): void
    {
        $form = Form::find($id);
        $user = Auth::user();

        if ($form === null) {
            Response::notFound();
            return;
        }
        if ($user === null) {
            Response::redirect('/login');
            return;
        }
        if (!$this->canEdit($form, (int) $user['id'])) {
            Response::forbidden();
            return;
        }

        // F3 — Si el formulario exige revisión, publicar desde aquí la saltaría
        // entera: el botón «Publicar» que el dueño tiene delante equivaldría a
        // aprobarse a uno mismo. Se rechaza con un mensaje que dice qué hacer en
        // su lugar, en vez de un 403 mudo (que el dueño no sabría interpretar).
        if (self::reviewRequired($form)) {
            Session::flash('error', 'Este formulario exige revisión: envíalo a revisión para que lo apruebe alguien con el permiso.');
            Response::redirect('/forms/' . $id);
            return;
        }

        Form::setStatus($id, 'published');
        Session::flash('success', 'Formulario publicado. Ya puedes compartir el enlace.');
        Response::redirect('/forms/' . $id);
        return;
    }

    public function close(int $id): void
    {
        $form = Form::find($id);
        $user = Auth::user();

        if ($form === null) {
            Response::notFound();
            return;
        }
        if ($user === null) {
            Response::redirect('/login');
            return;
        }
        if (!$this->canEdit($form, (int) $user['id'])) {
            Response::forbidden();
            return;
        }

        Form::setStatus($id, 'closed');
        Session::flash('success', 'Formulario cerrado. Ya no acepta respuestas.');
        Response::redirect('/forms/' . $id);
        return;
    }

    public function duplicate(int $id): void
    {
        $form = Form::find($id);
        $user = Auth::user();

        if ($form === null) {
            Response::notFound();
            return;
        }
        if ($user === null) {
            Response::redirect('/login');
            return;
        }
        if (!$this->canEdit($form, (int) $user['id'])) {
            Response::forbidden();
            return;
        }
        if (!Csrf::verifyRequest()) {
            Session::flash('error', 'Token inválido.');
            Response::redirect('/forms/' . $id);
            return;
        }

        // RBAC (duplicar = crear) + F5.3 (techo de formularios por hora).
        if (!Auth::can('forms.create')) {
            Response::forbidden();
            return;
        }
        if (!DashboardLimit::createAllowed()) {
            Session::flash('error', 'Llegaste al límite de formularios por hora. Intenta más tarde.');
            Response::redirect('/forms/' . $id);
            return;
        }

        $newId = Form::duplicate($id);
        \App\Core\SecurityLog::record('form.duplicate', 'copia de #' . $id . ' creada como #' . $newId);
        Session::flash('success', 'Formulario duplicado. La copia está en borrador.');
        Response::redirect('/forms/' . $newId);
        return;
    }

    /**
     * QR (P7) del enlace público del formulario, en SVG (sin GD).
     * La URL se calcula del lado servidor a partir del slug: nunca se acepta
     * una url del cliente (evita abusos/open-referrer).
     */
    public function qrSvg(int $id): void
    {
        $form = Form::find($id);
        $user = Auth::user();

        if ($form === null) {
            Response::notFound();
            return;
        }
        if ($user === null) {
            Response::redirect('/login');
            return;
        }
        if (!$this->canEdit($form, (int) $user['id'])) {
            Response::forbidden();
            return;
        }

        // La URL se calcula aquí, nunca desde el cliente (P7): si el slug
        // viniera de la request, un QR podría apuntar al formulario que el
        // atacante quiera en vez del que se está viendo.
        $url = \App\Core\PublicUrl::fillUrl((string) $form['slug']);

        // Headers solo fuera de CLI (en el runner la salida ya va a un buffer).
        if (PHP_SAPI !== 'cli') {
            header('Content-Type: image/svg+xml; charset=utf-8');
            header('Cache-Control: private, max-age=3600');
        }
        echo \App\Services\QrCode::svg($url);
    }

    /**
     * Vista imprimible (P9): maquetación de las preguntas para imprimir o
     * guardar como PDF desde el navegador. No envía datos de respuestas.
     */
    public function printForm(int $id): void
    {
        $form = Form::find($id);
        $user = Auth::user();

        if ($form === null) {
            Response::notFound();
            return;
        }
        if ($user === null) {
            Response::redirect('/login');
            return;
        }
        if (!$this->canEdit($form, (int) $user['id'])) {
            Response::forbidden();
            return;
        }

        // Los settings salen de la fila que ya trajo `find()`: `Form::settings($id)`
        // volvería a consultar la misma tabla para leer lo que ya está en memoria.
        View::render('dashboard/forms/print', [
            'form'     => $form,
            'user'     => $user,
            'settings' => Form::settingsFrom($form),
        ], null);
    }

    /**
     * Export de la estructura (P9) en el mismo formato declarativo de las
     * plantillas de database/templates: lo que se descarga se puede volver a
     * subir (copiar como .json nuevo) sin perder secciones ni condiciones.
     */
    public function exportStructure(int $id): void
    {
        $form = Form::find($id);
        $user = Auth::user();

        if ($form === null) {
            Response::notFound();
            return;
        }
        if ($user === null) {
            Response::redirect('/login');
            return;
        }
        if (!$this->canEdit($form, (int) $user['id'])) {
            Response::forbidden();
            return;
        }

        // F5.3 — exportaciones por usuario por hora (barrer el almacén).
        if (!DashboardLimit::exportAllowed()) {
            Response::abort(429, 'Demasiadas exportaciones. Intenta más tarde.');
            return;
        }

        // Igual que en printForm: sin consulta de más.
        $payload = TemplateService::exportPayload($form, Form::settingsFrom($form));
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $json = $json === false ? '{}' : $json;
        $file = ((string) ($form['slug'] ?? 'formulario')) . '.template.json';

        // Headers solo fuera de CLI (en el runner ya se emitió salida); en web
        // la respuesta termina aquí, igual que Response::json.
        if (PHP_SAPI !== 'cli') {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . str_replace('"', '', $file) . '"');
            header('Cache-Control: private, no-store');
        }
        echo $json;

        if (PHP_SAPI !== 'cli') {
            exit;
        }
    }

    private function canAccess(array $form, int $userId): bool
    {
        return Access::canView((int) $form['user_id']);
    }

    private function canEdit(array $form, int $userId): bool
    {
        return Access::canEdit((int) $form['user_id']);
    }

    private function canDelete(array $form, int $userId): bool
    {
        return Access::canDelete((int) $form['user_id']);
    }

    /**
     * F3 — ¿Este formulario exige aprobación antes de publicarse?
     *
     * Se lee del setting normalizado y NO del JSON crudo, porque quien decide
     * es lo que se guardó: un `settings.review.enabled` a `"1"` de un POST
     * antiguo tiene que contar igual que un `true`. `Form::settings()` ya
     * normaliza, así que aquí solo se comprueba la bandera.
     */
    public static function reviewRequired(array $form): bool
    {
        $settings = Form::settings((int) $form['id']);
        return !empty($settings['review']['enabled']);
    }
}