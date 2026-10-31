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
use App\Models\FormAccess;
use App\Models\FormParticipant;
use App\Models\Submission;
use App\Services\FormSettings;

/**
 * Gestión de respuestas (P3): listado con paginación server-side,
 * detalle, borrado y exportación JSON.
 */
class ResponsesController
{
    private const PER_PAGE = 15;

    public function index(int $id): void
    {
        // F3: el listado y el borrado no pintan los campos; solo el contenedor.
        $form = Form::find($id, false);

        if ($form === null) {
            Response::notFound();
            return;
        }
        if (!$this->canAccess((int) $form['user_id'])) {
            Response::forbidden();
            return;
        }

        $page = max(1, (int) Request::query('page', '1'));
        $pagination = Submission::byFormPaginated((int) $form['id'], $page, self::PER_PAGE);

        // F1: el listado pinta la IP de cada respuesta, así que la fila pasa por
        // el enmascarado ANTES de llegar a la vista. Se hace aquí y no en la
        // plantilla a propósito: si el valor ya viene enmascarado, no hay forma
        // de que un `print` o un `echo` futuro lo salte por error.
        $pagination['items'] = array_map(
            [\App\Services\Pii::class, 'maybeMaskSubmission'],
            $pagination['items']
        );

        // Fase 9 (quién respondió): una columna que solo existe en dos casos
        // combinados — que el formulario sea de listado y que quien mira tenga
        // `responses.pii`. En cualquier otro caso NO se pinta columna, ni
        // enmascarada: una columna de «—» en todos los formularios normales
        // (donde nadie está nunca atribuido) es ruido, y una celda con
        // `[oculto]` en cada fila no dice nada que el aviso de abajo no diga.
        $esListado = self::esListado($form);
        $participantes = $esListado && \App\Services\Pii::canView()
            ? self::identidades($form, array_column($pagination['items'], 'participant_id'))
            : null;

        View::render('responses/index', [
            'form'       => $form,
            'pagination' => $pagination,
            // La vista pinta "(enmascarada)" en la cabecera de la IP con esto. Va
            // en el controlador, y no como una llamada a Pii desde la plantilla,
            // para que ninguna vista tenga que decidir permisos.
            'pii_masked' => !\App\Services\Pii::canView(),
            // `null` = sin columna (no hay permiso, o no es un formulario de
            // listado); `[]` = columna pintada y ninguna respuesta atribuida.
            // Las dos cosas se distinguen porque con permiso y sin participantes
            // la columna tiene que verse y decir «—».
            'participantes' => $participantes,
            // Con la columna fuera por falta de permiso, el motivo se explica:
            // un formulario con lista que no dice quién respondió parece un
            // fallo, y no lo es.
            'aviso_atribucion' => $esListado && $participantes === null,
            // La vista solo pinta datos: el identificador configurado lo decide
            // el controlador, porque `settingsFrom()` normaliza el bloque entero
            // y hacerlo en la plantilla sería trabajo por cada fila.
            'identificador' => FormParticipant::identifierOf(Form::settingsFrom($form)),
            'csrf'       => Csrf::token(),
        ]);
    }

    public function show(int $id, int $sid): void
    {
        $form = Form::find($id);

        if ($form === null) {
            Response::notFound();
            return;
        }
        if (!$this->canAccess((int) $form['user_id'])) {
            Response::forbidden();
            return;
        }

        $data = Submission::findWithAnswers($sid);
        if ($data === null || (int) $data['submission']['form_id'] !== (int) $form['id']) {
            Response::notFound();
            return;
        }

        // F1: sin el permiso `responses.pii` la IP y el user-agent salen
        // truncados y los campos marcados como personales salen como `[oculto]`.
        // Los adjuntos de un campo personal NO se listan (su nombre es el dato);
        // la descarga además se bloquea en AttachmentController, porque si solo
        // se ocultara el nombre, `/attachments/{id}` seguiría sirviendo el
        // archivo a quien supiera el id de la fila `answers`.
        $canSeePii = \App\Services\Pii::canView();
        $files = $canSeePii ? self::filesByField($sid) : self::filesByField($sid, self::piiFieldIds($form));

        // Fase 9: quién respondió, con la misma puerta que el listado. La fila
        // puede no estar (respuesta de alguien que no estaba en la lista, o una
        // respuesta vieja anterior al listado) y eso se dice con «—» en vez de
        // con un hueco en la maquetación.
        $participante = null;
        if ($canSeePii && self::esListado($form)) {
            $pid = (int) ($data['submission']['participant_id'] ?? 0);
            $participante = self::identidades($form, [$pid])[$pid] ?? null;
        }

        View::render('responses/show', [
            'form'       => $form,
            'submission' => $data['submission'] === null ? null : \App\Services\Pii::maybeMaskSubmission($data['submission']),
            'answers'    => \App\Services\Pii::maybeMaskAnswers($data['answers'], $form['fields']),
            'files'      => $files,
            'pii_masked' => !$canSeePii,
            // `null` cuando no hay permiso, no es de listado o no hay fila en la
            // lista. El rótulo de la casilla no se pinta: una casilla «—» al
            // lado de fecha, IP y navegador parece un dato que falta cuando lo
            // que falta es el permiso, y eso ya lo dice el aviso de abajo.
            'participante' => $participante,
            'identificador' => FormParticipant::identifierOf(Form::settingsFrom($form)),
            'csrf'       => Csrf::token(),
        ]);
    }

    public function delete(int $id, int $sid): void
    {
        $form = Form::find($id, false);

        if ($form === null) {
            Response::notFound();
            return;
        }
        if (!$this->canAccess((int) $form['user_id'])) {
            Response::forbidden();
            return;
        }
        if (!Csrf::verifyRequest()) {
            Session::flash('error', 'Token de seguridad inválido.');
            Response::redirect('/forms/' . $id . '/responses');
            return;
        }

        $data = Submission::findWithAnswers($sid);
        if ($data === null || (int) $data['submission']['form_id'] !== (int) $form['id']) {
            Response::notFound();
            return;
        }

        // P10/F1.1: dos fases, y el orden importa en las dos (ver el bloque
        // comentado en Uploader). Las rutas se leen ANTES de borrar la fila: las
        // `answers` se van por la FK de answers y después no queda nada que leer,
        // con lo que el binario se quedaría huérfano para siempre. El unlink va
        // después del delete: un huérfano se limpia en un mantenimiento, un
        // adjunto fantasma (fila viva apuntando a un binario inexistente) no.
        $orphanPaths = \App\Services\Uploader::pendingForSubmission($sid);

        // El participante atribuido se lee ANTES del delete: `submissions` es
        // donde vive la unión y la fila se va con el borrado.
        $participantId = (int) ($data['submission']['participant_id'] ?? 0);

        Submission::delete($sid);

        // Borrar la respuesta suelta la marca de «ya respondió» si no le queda
        // ninguna otra: sin esto la tarjeta de Acceso seguiría contando a esa
        // persona como respondida y la puerta de `roster` —que cierra a quien ya
        // respondió— no le dejaría volver a entrar. Va DESPUÉS del delete, que
        // es cuando el `NOT EXISTS` de la comprobación ya ve el hueco.
        FormParticipant::desmarcarSiSinRespuestas($participantId);

        try {
            \App\Services\Uploader::purgePaths($orphanPaths);
        } catch (\Throwable $e) {
            error_log('[ResponsesController] purgePaths: ' . $e->getMessage());
        }

        // Sin recarga: el JS quita la fila (`data-vf-row`) y baja el contador
        // del encabezado. `count` es el total que queda, y a cero el JS
        // recarga (ver `app.js`): una tabla sin filas con su «aún no tiene
        // respuestas» al lado mentiría, y recargar es el caso raro.
        //
        // El flash NO se deja puesto en este camino: se vería dos veces, en el
        // aviso y otra vez en la siguiente página que lo leyera.
        if (Response::wantsJson()) {
            Response::json([
                'ok'      => true,
                'message' => 'Respuesta eliminada.',
                'count'   => Submission::countByFiltered((int) $form['id']),
            ]);
            return;
        }

        Session::flash('success', 'Respuesta eliminada.');
        Response::redirect('/forms/' . $id . '/responses');
        return;
    }

    public function exportJson(int $id): void
    {
        $form = Form::find($id);

        if ($form === null) {
            Response::notFound();
            return;
        }
        if (!$this->canAccess((int) $form['user_id'])) {
            Response::forbidden();
            return;
        }

        // F5.3 — exportaciones por usuario por hora (barrer el almacén).
        if (!DashboardLimit::exportAllowed()) {
            Response::abort(429, 'Demasiadas exportaciones. Intenta más tarde.');
            return;
        }

        // Mismo criterio de filtro de fechas que el CSV y la analítica (M5).
        $from = self::validarFecha(Request::query('from', ''));
        $to = self::validarFecha(Request::query('to', ''));
        if ($from !== '' && $to !== '' && $from > $to) {
            $tmp = $from;
            $from = $to;
            $to = $tmp;
        }

        if (PHP_SAPI !== 'cli') {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . self::safeFile((string) $form['slug']) . '.responses.json"');
        }

        // Streaming por tandas: exportBatches() devuelve submissions + answers
        // de 5000 en 5000 con keyset (finished_at DESC, id DESC), así que un
        // formulario de miles de respuestas no se carga entero en memoria.
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

        echo '{' . "\n";
        echo '  "form": ' . json_encode(Submission::exportFormMeta($form), $flags) . ',' . "\n";
        echo '  "total": ' . Submission::countByFiltered((int) $form['id'], $from, $to) . ',' . "\n";
        echo '  "submissions": [' . "\n";

        $first = true;
        $canView = \App\Services\Pii::canView();
        $marked = $canView ? [] : \App\Services\Pii::piiFieldTypes($form['fields'] ?? []);
        foreach (Submission::exportBatches((int) $form['id'], $from, $to) as [, $bySubmission]) {
            foreach ($bySubmission as $sid => $row) {
                $entry = Submission::exportSubmissionRow((int) $sid, $row);
                if (!$canView) {
                    $entry = \App\Services\Pii::maybeMaskSubmission($entry);
                    foreach ($marked as $fieldId => $type) {
                        if (!array_key_exists($fieldId, $entry['answers'])) {
                            continue;
                        }
                        $raw = $entry['answers'][$fieldId];
                        $entry['answers'][$fieldId] = is_array($raw)
                            ? \App\Services\Pii::MASK
                            : \App\Services\Pii::maskValue((string) $raw, $type);
                    }
                }

                echo $first ? '' : ',' . "\n";
                echo '    ' . json_encode($entry, $flags);
                $first = false;
            }
        }

        echo "\n" . '  ]' . "\n" . '}' . "\n";
        if (PHP_SAPI !== 'cli') {
            exit;
        }
    }

    private function canAccess(int $ownerId): bool
    {
        return Access::canReadOwn($ownerId, 'responses.view_own');
    }

    /**
     * ¿El formulario es de los que atribuyen respuestas a una persona?
     *
     * Sale de los settings YA cargados (`f.*` trae la columna) y no de
     * `Form::settings()`, que sería una consulta de más por petición.
     */
    private static function esListado(array $form): bool
    {
        return FormAccess::mode(Form::settingsFrom($form)) === FormSettings::ACCESS_ROSTER;
    }

    /**
     * Identidades de los participantes de una página de respuestas, indexadas por
     * `participant_id`.
     *
     * Los `null` (una respuesta sin atribución) se filtran antes de la consulta:
     * `intval(null)` es 0, y un `IN (0)` no da error pero sí hace trabajo de más
     * delatando que alguien no está atribuido.
     *
     * @param list<mixed> $participantIds
     *
     * @return array<int, array<string,mixed>>
     */
    private static function identidades(array $form, array $participantIds): array
    {
        $ids = [];
        foreach ($participantIds as $id) {
            if ($id !== null && $id !== '' && (int) $id > 0) {
                $ids[] = (int) $id;
            }
        }

        return FormParticipant::identidadesDe((int) $form['id'], $ids);
    }

    /**
     * P10: agrupa los adjuntos de la respuesta por field_id para pintar los
     * enlaces de descarga (id de la answer + índice del archivo).
     */
    private static function filesByField(int $submissionId, array $hiddenFieldIds = []): array
    {
        $out = [];
        foreach (\App\Models\Answer::attachmentsBySubmission($submissionId) as $file) {
            if (isset($hiddenFieldIds[(int) $file['field_id']])) {
                continue;
            }
            $out[$file['field_id']][] = $file;
        }
        return $out;
    }

    /**
     * F1: ids de los campos marcados como personales en este formulario, para no
     * listar (ni dejar descargar) sus adjuntos a quien no tiene `responses.pii`.
     *
     * @return array<int,string> field_id => tipo
     */
    private static function piiFieldIds(array $form): array
    {
        return \App\Services\Pii::piiFieldTypes($form['fields'] ?? []);
    }

    private static function safeFile(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
    }

    private static function validarFecha(string $fecha): string
    {
        $fecha = trim($fecha);
        return Submission::isValidDay($fecha) ? $fecha : '';
    }
}