<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\Form;
use App\Models\Submission;

/**
 * Gestión de respuestas (P3): listado con paginación server-side,
 * detalle, borrado y exportación JSON.
 */
class ResponsesController
{
    private const PER_PAGE = 15;

    public function index(int $id): void
    {
        $form = Form::find($id);

        if ($form === null) {
            Response::notFound();
        }
        if (!$this->canAccess((int) $form['user_id'])) {
            Response::forbidden();
        }

        $page = max(1, (int) Request::query('page', '1'));
        $pagination = Submission::byFormPaginated((int) $form['id'], $page, self::PER_PAGE);

        View::render('responses/index', [
            'form'       => $form,
            'pagination' => $pagination,
            'csrf'       => Csrf::token(),
        ]);
    }

    public function show(int $id, int $sid): void
    {
        $form = Form::find($id);

        if ($form === null) {
            Response::notFound();
        }
        if (!$this->canAccess((int) $form['user_id'])) {
            Response::forbidden();
        }

        $data = Submission::findWithAnswers($sid);
        if ($data === null || (int) $data['submission']['form_id'] !== (int) $form['id']) {
            Response::notFound();
        }

        View::render('responses/show', [
            'form'       => $form,
            'submission' => $data['submission'],
            'answers'    => $data['answers'],
            // P10: adjuntos de esta respuesta (field_id => [ ...adjuntos ]).
            'files'      => self::filesByField($sid),
            'csrf'       => Csrf::token(),
        ]);
    }

    public function delete(int $id, int $sid): void
    {
        $form = Form::find($id);

        if ($form === null) {
            Response::notFound();
        }
        if (!$this->canAccess((int) $form['user_id'])) {
            Response::forbidden();
        }
        if (!Csrf::verifyRequest()) {
            Session::flash('error', 'Token de seguridad inválido.');
            Response::redirect('/forms/' . $id . '/responses');
        }

        $data = Submission::findWithAnswers($sid);
        if ($data === null || (int) $data['submission']['form_id'] !== (int) $form['id']) {
            Response::notFound();
        }

        // P10/F1.1: dos fases, y el orden importa en las dos (ver el bloque
        // comentado en Uploader). Las rutas se leen ANTES de borrar la fila: las
        // `answers` se van por la FK de answers y después no queda nada que leer,
        // con lo que el binario se quedaría huérfano para siempre. El unlink va
        // después del delete: un huérfano se limpia en un mantenimiento, un
        // adjunto fantasma (fila viva apuntando a un binario inexistente) no.
        $orphanPaths = \App\Services\Uploader::pendingForSubmission($sid);

        Submission::delete($sid);

        try {
            \App\Services\Uploader::purgePaths($orphanPaths);
        } catch (\Throwable $e) {
            error_log('[ResponsesController] purgePaths: ' . $e->getMessage());
        }

        Session::flash('success', 'Respuesta eliminada.');
        Response::redirect('/forms/' . $id . '/responses');
    }

    public function exportJson(int $id): void
    {
        $form = Form::find($id);

        if ($form === null) {
            Response::notFound();
        }
        if (!$this->canAccess((int) $form['user_id'])) {
            Response::forbidden();
        }

        // Mismo criterio de filtro de fechas que el CSV y la analítica (M5).
        $from = self::validarFecha(Request::query('from', ''));
        $to = self::validarFecha(Request::query('to', ''));
        if ($from !== '' && $to !== '' && $from > $to) {
            $tmp = $from;
            $from = $to;
            $to = $tmp;
        }

        $submissions = Submission::byFormFiltered((int) $form['id'], $from, $to);
        $submissionIds = array_column($submissions, 'id');
        $bySubmission = Submission::withAnswersByIds($submissionIds);
        $payload = Submission::exportPayload($form, $bySubmission, count($submissions));

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . self::safeFile((string) $form['slug']) . '.responses.json"');
        echo json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        exit;
    }

    private function canAccess(int $ownerId): bool
    {
        return (int) Auth::id() === $ownerId || Auth::isAdmin() || Auth::role() === 'editor';
    }

    /**
     * P10: agrupa los adjuntos de la respuesta por field_id para pintar los
     * enlaces de descarga (id de la answer + índice del archivo).
     */
    private static function filesByField(int $submissionId): array
    {
        $out = [];
        foreach (\App\Models\Answer::attachmentsBySubmission($submissionId) as $file) {
            $out[$file['field_id']][] = $file;
        }
        return $out;
    }

    private static function safeFile(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
    }

    private static function validarFecha(string $fecha): string
    {
        $fecha = trim($fecha);
        if ($fecha === '' || \DateTime::createFromFormat('Y-m-d', $fecha) === false) {
            return '';
        }
        return $fecha;
    }
}