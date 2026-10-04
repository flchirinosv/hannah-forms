<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Access;
use App\Core\Auth;
use App\Core\DashboardLimit;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Form;
use App\Models\FormParticipant;
use App\Models\Submission;
use App\Services\FieldAggregator;
use App\Services\Pii;

class AnalyticsController
{
    public function show(int $id): void
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

        $from = Request::query('from', '');
        $to = Request::query('to', '');

        $from = self::validarFecha($from);
        $to = self::validarFecha($to);

        if ($from !== '' && $to !== '' && $from > $to) {
            $tmp = $from;
            $from = $to;
            $to = $tmp;
        }

        $formId = (int) $form['id'];

        // Fase 7 — el filtro por atributo del listado. Se resuelve UNA vez aquí
        // (`null` si no hay, o si la clave no es del catálogo) y el mismo array
        // viaja a los cuatro caminos: sin esto, el indicador marcaría una cosa y
        // los gráficos y la tabla otra.
        //
        // Y el CATÁLOGO se pide siempre, filtro o no: es la lista de opciones del
        // desplegable. Pedirlo solo cuando ya hay filtro deja la pantalla sin
        // forma de tener el primero — no hay ningún enlace ni botón que ponga la
        // clave en la URL — y el filtro nace muerto sin un solo error.
        $atr = FormParticipant::filtroAtributo(
            $formId,
            (string) Request::query('atr_key', ''),
            (string) Request::query('atr_value', '')
        );
        // La clave ELEGIDA aunque todavía no haya valor: sin esto el segundo
        // desplegable no se pinta nunca y no hay forma de llegar al filtro.
        $atrClave = FormParticipant::atributoDeCatalogo($formId, (string) Request::query('atr_key', ''));
        $catalogo = FormParticipant::catalogFor($formId);
        // Las columnas del detalle, en cambio, solo con filtro puesto: sin él el
        // dueño no ha pedido ver quién es quién y la tabla no gana columnas.
        $atributos = $atr === null ? [] : $catalogo;
        $valores = $atrClave === null ? [] : FormParticipant::valuesOf($formId, (string) $atrClave['key_name']);

        // P1 (2026-09-28): la tabla de detalle se pagina EN EL SERVIDOR (15 por
        // página, lazy-load con fetch). Los agregados siguen siendo globales:
        // es la tabla la que acota, no las métricas. La respuesta de la página
        // 1 se renderiza server-side (sin parpadeo JS) y el pager la refresca.
        $detail = Submission::byFormFilteredPaginated($formId, (string) $from, (string) $to, 1, 15, $atr);
        $submissions = $detail['items'];
        $total = $detail['total'];
        $detailPage = $detail['page'];

        // Fase 3: respuestas por campo en DOS consultas fijas, no 2 por
        // pregunta. Antes este bucle hacía 2 SELECT por campo (3 si era NPS):
        // 16 con 11 preguntas, ~103 con 50, ~403 con 200. La forma del
        // resultado es idéntica, así que la vista no cambia.
        $campos = array_values(array_filter(
            $form['fields'],
            static fn($f) => ($f['type'] ?? '') !== 'section'
        ));
        // F1: tipos de los campos marcados como personales, para los agregados y el
        // detalle. Se calculan una vez y se pasan a los dos.
        //
        // El permiso se decide AQUÍ y no en la vista: `Pii` es el único sitio que
        // decide el valor que sale, y una lista vacía es la forma de decir "este
        // usuario puede verlos". La alternativa —mascarar dentro del partial—
        // dejaba la pantalla siempre enmascarada, también para el admin, que es
        // justo lo contrario de lo que hace el listado de respuestas.
        $piiTypes = Pii::canView() ? [] : Pii::piiFieldTypes($form['fields'] ?? []);
        $fieldStats = FieldAggregator::forForm(
            $formId,
            $campos,
            array_map(static fn($f) => (int) $f['id'], $campos),
            $total,
            (string) $from,
            (string) $to,
            $piiTypes,
            $atr
        );

        // Fase 8 — Comparar por un atributo del listado. Solo se agrupa cuando hay
        // una clave ELEGIDA y NO hay valor filtrado: comparar los grupos de un
        // atributo mientras se está mirando uno de ellos no dice nada (una columna
        // con los datos de todos y el resto en cero), y quien quiere ese detalle
        // lo tiene en la tabla de abajo.
        //
        // Las columnas salen de `gruposDeAtributo()`, que ya aplican los techos (los N
        // más frecuentes + «Resto»), y `$atr` viaja igualmente a las consultas por
        // si algún día se decide pintar las dos cosas: los números tienen que
        // salir del mismo subconjunto que el resto de la pantalla.
        $agrupacion = [];
        if ($atrClave !== null && $atr === null) {
            $atrLabel = (string) ($atrClave['label'] ?? $atrClave['key_name'] ?? '');
            $agrupacion = FieldAggregator::forFormByGroup(
                $formId,
                $campos,
                FieldAggregator::gruposDeAtributo($formId, (string) $atrClave['key_name']),
                (string) $atrClave['key_name'],
                (string) $from,
                (string) $to,
                $piiTypes,
                $atr
            );
            if ($agrupacion !== []) {
                $agrupacion['label'] = $atrLabel;
            }
        }

        // Progreso diario (migrado a Models)
        $daily = Submission::dailyStats($formId, (string) $from, (string) $to, $atr);

        // Respuestas de la tabla (N+1 resuelto: una sola consulta; y SOLO de
        // la página actual, no de todas las submissions del formulario).
        $submissionIds = array_column($submissions, 'id');
        $answersBySubmission = Submission::withAnswersByIds($submissionIds);

        View::render('analytics/show', [
            'form'        => $form,
            'submissions' => $submissions,
            'answers'     => $answersBySubmission,
            'total'       => $total,
            'fieldStats'  => $fieldStats,
            'daily'       => $daily,
            'from'        => $from,
            'to'          => $to,
            'detail'      => $detail,
            'piiTypes'    => $piiTypes,
            // Fase 7 — las columnas del detalle son los atributos del CATÁLOGO y
            // solo se piden si hay filtro: son una columna por atributo y una
            // consulta más, y un formulario sin listado no tiene nada que pintar.
            'atributos'   => $atributos,
            'catalogo'    => $catalogo,
            'atr'         => $atr,
            'atrClave'    => $atrClave,
            'atrValues'   => $valores,
            // Fase 8 — la comparación por atributo, o `[]` si no se puede/puede no
            // agrupar: un formulario sin listado, sin atributo elegido, con un
            // valor filtrado, o cuyas preguntas no son comparables.
            'agrupacion'  => $agrupacion,
            'attrsByPid'  => FormParticipant::atributosDe(array_map(
                static fn(array $s): int => (int) ($s['participant_id'] ?? 0),
                $submissions
            )),
        ]);
    }

    /**
     * P1 (2026-09-28) — Fragmento de la página N de la tabla de detalle.
     *
     * Devuelve JSON con el <tr> de cada respuesta + el paginador nuevo, para
     * que el cliente lo inyecte sin recargar la página (lazy loading). Un solo
     * contrato {html, pagination, page, pages, total}: `html` va al tbody y
     * `pagination` al contenedor del pager.
     */
    public function rows(int $id): void
    {
        $form = Form::find($id);
        if ($form === null) {
            Response::json(['ok' => false, 'error' => 'Formulario no encontrado.'], 404);
            return;
        }
        if (!$this->canAccess((int) $form['user_id'])) {
            Response::json(['ok' => false, 'error' => 'Sin permiso.'], 403);
            return;
        }

        $from = self::validarFecha((string) Request::query('from', ''));
        $to = self::validarFecha((string) Request::query('to', ''));
        $page = max(1, (int) Request::query('page', '1'));

        $formId = (int) $form['id'];

        // El filtro se resuelve aquí también: la página N del detalle es la MISMA
        // analítica que la página 1, y si el fetcher no lo pasara, el contador
        // marcaría 38 y las filas que saldrían serían 12 de otro grupo.
        $atr = FormParticipant::filtroAtributo(
            $formId,
            (string) Request::query('atr_key', ''),
            (string) Request::query('atr_value', '')
        );

        $detail = Submission::byFormFilteredPaginated($formId, $from, $to, $page, 15, $atr);

        $campos = array_values(array_filter(
            (array) ($form['fields'] ?? []),
            static fn($f) => ($f['type'] ?? '') !== 'section'
        ));
        $ids = array_column($detail['items'], 'id');
        $answers = $ids === [] ? [] : Submission::withAnswersByIds($ids);

        $html = View::partial('analytics/_rows', [
            'submissions'      => $detail['items'],
            'answers'          => $answers,
            'answerableFields' => $campos,
            'offset'           => 15 * ((int) $detail['page'] - 1),
            'atributos'        => $atr === null ? [] : FormParticipant::catalogFor($formId),
            'attrsByPid'       => FormParticipant::atributosDe(array_map(
                static fn(array $s): int => (int) ($s['participant_id'] ?? 0),
                $detail['items']
            )),
            // F1: el detalle es un canal de salida y va enmascarado igual que la
            // primera página. Sin esto, el lazy-load del pager-era el camino
            // limpio: lo que se ve en la carga inicial, tapado, y lo que se ve al
            // cambiar de página, en claro.
            'piiTypes'         => Pii::canView() ? [] : Pii::piiFieldTypes($form['fields'] ?? []),
        ]);

        Response::json([
            'ok'         => true,
            'html'       => $html,
            'pagination' => View::partial('analytics/_pagination', $detail),
            'page'       => $detail['page'],
            'pages'      => $detail['pages'],
            'total'      => $detail['total'],
        ]);
    }

    public function exportCsv(int $id): void
    {
        $form = Form::find($id);
        if ($form === null) {
            Response::notFound();
            return;
        }
        if (!$this->canExport((int) $form['user_id'])) {
            Response::forbidden();
            return;
        }

        // F5.3 — exportaciones por usuario por hora (barrer el almacén).
        if (!DashboardLimit::exportAllowed()) {
            Response::abort(429, 'Demasiadas exportaciones. Intenta más tarde.');
            return;
        }

        // M5: aplicar el mismo filtro de fechas que en la pantalla de analítica
        $from = Request::query('from', '');
        $to = Request::query('to', '');
        $from = self::validarFecha($from);
        $to = self::validarFecha($to);

        // Streaming por tandas: en vez de `byFormFiltered()` + `withAnswersByIds()`
        // (que cargaban TODAS las submissions y TODAS sus answers en memoria),
        // `exportBatches()` itera con keyset (finished_at DESC, id DESC) y devuelve
        // de a `ID_BATCH` (5000): cada tanda se escribe y se suelta.
        $headers = ['#', 'Fecha'];
        // F1: tipos de los campos marcados como personales, para enmascararlos en
        // el CSV. El CSV no lleva IP ni user-agent (solo el JSON los lleva), así
        // que aquí lo único que se puede filtrar es el valor de la respuesta.
        $piiTypes = \App\Services\Pii::piiFieldTypes($form['fields'] ?? []);
        foreach ($form['fields'] as $field) {
            if (($field['type'] ?? '') === 'section') {
                continue;
            }
            $headers[] = $field['label'];
        }

        // Headers y exit solo fuera de CLI (en el runner los controllers se
        // invocan sin matar el proceso; en web la respuesta termina aquí).
        if (PHP_SAPI !== 'cli') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . self::safeFile($form['slug']) . '.csv"');
        }

        $out = fopen('php://output', 'w');
        fputcsv($out, $headers, ',', '"', '\\');

        $n = 0;
        // Fase 7 — el CSV respeta el filtro que hay en pantalla. Lo contrario es
        // un export que no corresponde a lo que se está viendo, y se descubre
        // después de abrir el archivo.
        $atr = FormParticipant::filtroAtributo(
            (int) $form['id'],
            (string) Request::query('atr_key', ''),
            (string) Request::query('atr_value', '')
        );

        foreach (Submission::exportBatches((int) $form['id'], (string) $from, (string) $to, 0, $atr) as [$submissions, $answersBySubmission]) {
            foreach ($submissions as $submission) {
                $byField = $answersBySubmission[(int) $submission['id']]['answers'] ?? [];
                $n++;

                $row = [$n, $submission['finished_at']];
                foreach ($form['fields'] as $field) {
                    if (($field['type'] ?? '') === 'section') {
                        continue;
                    }
                    $val = $byField[(int) $field['id']] ?? '';
                    // P10: los adjuntos salen como "nombre (tamaño)", sin la ruta
                    // interna de storage ni el JSON de metadata.
                    if ($val !== '' && \App\Services\Uploader::isAttachmentJson((string) $val)) {
                        $val = \App\Services\Uploader::displayValue((string) $val);
                    }
                    // Decodificar JSON si es respuesta múltiple (checkbox) o
                    // compuesta (matriz/ranking: {clave: valor}).
                    if (str_starts_with($val, '[')) {
                        $decoded = json_decode($val, true);
                        if (is_array($decoded)) {
                            $val = implode('; ', $decoded);
                        }
                    } elseif (str_starts_with($val, '{')) {
                        $decoded = json_decode($val, true);
                        if (is_array($decoded)) {
                            $parts = [];
                            foreach ($decoded as $k => $v) {
                                $parts[] = $k . ': ' . $v;
                            }
                            $val = implode('; ', $parts);
                        }
                    }
                    // F1: se enmascara AQUÍ, dentro del bucle de campos y no en una
                    // pasada aparte sobre `$row`, por dos razones. La primera es
                    // que al final habría que volver a emparejar cada columna con su
                    // campo por posición (headers = 2 + nº de campos), que es un
                    // índice que se rompe en cuanto alguien añade una columna. La
                    // segunda es el orden: enmascarar antes de decodificar dejaría
                    // pasar la metadata del adjunto (el nombre del archivo es el
                    // dato) y un `[oculto]` empieza por `[`, igual que un JSON, así
                    // que el bloque de decodificación lo reinterpretaría.
                    if (isset($piiTypes[(int) $field['id']]) && $val !== '') {
                        // El valor ya es texto legible (los adjuntos se aplanaron
                        // arriba), así que el tipo basta para decidir el formato.
                        $val = \App\Services\Pii::maskValue((string) $val, $piiTypes[(int) $field['id']]);
                    }

                    $row[] = self::csvSafe($val);
                }
                fputcsv($out, $row, ',', '"', '\\');
            }
        }

        fclose($out);
        if (PHP_SAPI !== 'cli') {
            exit;
        }
    }

    private static function safeFile(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
    }

    /**
     * Previene CSV injection (CWE-1236): antepone una comilla simple a
     * valores que podrían interpretarse como fórmula en Excel/Sheets
     * (=, +, @, - seguido de no-dígito).
     */
    private static function csvSafe(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        $first = $value[0];
        if (in_array($first, ['=', '+', '@', "\t", "\r", "\n"], true)) {
            return "'" . $value;
        }
        if ($first === '-' && !preg_match('/^-[0-9.]+$/', $value)) {
            return "'" . $value;
        }

        return $value;
    }

    private function canAccess(int $ownerId): bool
    {
        return Access::canReadOwn($ownerId, 'analytics.view_own');
    }

    private function canExport(int $ownerId): bool
    {
        return Access::canExport($ownerId);
    }

    /**
     * Un `from`/`to` solo se acepta si es un día real de verdad.
     *
     * Se delega en `FieldAggregator::validDay()` (y no en un
     * `createFromFormat` a pelo) por dos razones: `2026-02-31` no es una fecha
     * y `createFromFormat` la "corrige" a marzo sin avisar, y la validación
     * tiene que ser la MISMA que usa el agregador de la fase 3, o la analítica
     * y el CSV podrían recortar periodos distintos con el mismo filtro.
     */
    private static function validarFecha(string $fecha): string
    {
        return FieldAggregator::validDay($fecha);
    }
}