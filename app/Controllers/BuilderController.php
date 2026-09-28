<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\Field;
use App\Models\Form;
use App\Services\LogicEvaluator;

class BuilderController
{
    public function show(int $id): void
    {
        $form = Form::find($id);
        $user = Auth::user();

        if ($form === null) {
            Response::notFound();
        }
        if ($user === null) {
            Response::redirect('/login');
        }
        if (!$this->canEdit((int) $form['user_id'])) {
            Response::forbidden();
        }

        View::render('builder/index', [
            'form' => $form,
            'fields' => $form['fields'],
            'user' => $user,
            'csrf' => Csrf::token(),
        ]);
    }

    public function saveStructure(int $id): void
    {
        $form = Form::find($id);
        if ($form === null) {
            Response::notFound();
        }
        if (Auth::user() === null) {
            Response::redirect('/login');
        }
        if (!$this->canEdit((int) $form['user_id'])) {
            Response::forbidden();
        }

        // C1: Verificar CSRF (el cliente envia el token via header X-CSRF-Token)
        if (!Csrf::verifyHeader()) {
            Response::json(['ok' => false, 'error' => 'Token de seguridad inválido. Recarga la página e intenta de nuevo.'], 419);
        }

        $body = Request::json();
        if (Request::jsonTooLarge()) {
            Response::json(['ok' => false, 'error' => 'La estructura excede el tamaño máximo permitido.'], 413);
        }
        if (!is_array($body) || !isset($body['fields']) || !is_array($body['fields'])) {
            Response::json(['ok' => false, 'error' => 'Estructura inválida'], 422);
        }

        // C4: Lista de tipos de campo permitidos (whitelist) - debe coincidir con typeInfo en builder.js
        $allowedTypes = [
            'text', 'textarea', 'email', 'number', 'date', 'select', 'radio', 'checkbox', 'scale',
            'url', 'tel', 'time', 'datetime-local', 'range', 'section', 'page',
            'matrix', 'nps', 'star', 'ranking', 'file',
        ];
        // Fuentes de condición: solo preguntas contestables (nunca secciones ni páginas).
        $answerableTypes = array_values(array_diff($allowedTypes, ['section', 'page']));

        $items = $body['fields'];

        $db = \App\Core\Database::getInstance(\App\Core\Config::db());
        $db->beginTransaction();

        // P10/F1.1: los ARCHIVOS se desenlazan DESPUÉS del commit, nunca dentro
        // de la transacción: si se purga antes y luego hay un rollback (por
        // ejemplo al validar los settings), la fila `answers` vuelve con su ruta
        // `stored` intacta pero el binario ya no existe, y `GET /attachments/...`
        // da 404 para siempre. Al revés, un huérfano se limpia en un
        // mantenimiento; un adjunto fantasma, no.
        //
        // OJO, dos fases: las rutas se RECOLECTAN antes de borrar el campo,
        // porque `Field::deleteField()` se lleva los `answers` por cascada y
        // `Answer::attachmentValuesByField()` ya no encontraría nada. Guardar
        // solo los ids aquí y purgar después del commit era un bug: los
        // archivos se quedaban huérfanos para siempre.
        $purgeAfterCommit = [];

        try {
            $existing = Field::byForm((int) $form['id']);
            $existingIds = array_map(static fn($f) => (int) $f['id'], $existing);
            $keptIds = [];

            // Referencias por _uid: un item puede apuntar a otro (padre o regla).
            $uidToId = [];
            $idToUid = [];
            $typeOf = [];      // uid => type
            $parentUid = [];   // uid => uid|null
            $refOf = [];       // uid => listado de refs raw (logic)
            $orderIdx = [];    // uid => índice en lectura (posición)

            $payloadByUid = [];
            foreach ($items as $idx => $item) {
                if (!is_array($item) || !isset($item['_uid'])) {
                    $db->rollBack();
                    Response::json(['ok' => false, 'error' => 'Estructura inválida: falta identificador de campo.'], 422);
                }
                $payloadByUid[(string) $item['_uid']] = $item + ['_index' => $idx];
            }

            $positionCounters = ['__root__' => 0];

            foreach ($items as $item) {
                $uid = (string) $item['_uid'];
                $type = (string) ($item['type'] ?? '');
                $orderIdx[$uid] = count($orderIdx);
                $typeOf[$uid] = $type;

                // C4: Validar que el tipo sea permitido; si no, rechazar
                if (!in_array($type, $allowedTypes, true)) {
                    $db->rollBack();
                    Response::json([
                        'ok'    => false,
                        'error' => 'Tipo de campo no permitido: ' . $type,
                    ], 422);
                }

                // Resolver sección contenedora (parent_id llega como _uid o null).
                $rawParent = array_key_exists('parent_id', $item) ? $item['parent_id'] : null;
                // Secciones y páginas son elementos ESTRUCTURALES: solo raíz.
                $isStructural = $type === 'section' || $type === 'page';
                $parentIdResolved = null;

                if ($isStructural) {
                    if ($rawParent !== null) {
                        $db->rollBack();
                        Response::json(['ok' => false, 'error' => ($type === 'page'
                            ? 'Los saltos de página no pueden estar dentro de una sección.'
                            : 'Las secciones no pueden estar dentro de otra sección.')], 422);
                    }
                } elseif ($rawParent !== null && $rawParent !== '') {
                    $pu = (string) $rawParent;
                    if ($pu === $uid || !isset($payloadByUid[$pu])) {
                        $db->rollBack();
                        Response::json(['ok' => false, 'error' => 'La pregunta hace referencia a una sección inválida.'], 422);
                    }
                    if (($payloadByUid[$pu]['type'] ?? '') !== 'section') {
                        $db->rollBack();
                        Response::json(['ok' => false, 'error' => 'Las preguntas solo pueden anidarse dentro de una sección.'], 422);
                    }
                    if (!isset($uidToId[$pu])) {
                        // La sección debe aparecer ANTES en el orden de lectura.
                        $db->rollBack();
                        Response::json(['ok' => false, 'error' => 'La sección contenedora debe ir antes que sus preguntas.'], 422);
                    }
                    $parentIdResolved = $uidToId[$pu];
                }
                $parentUid[$uid] = $rawParent === null ? null : (string) $rawParent;

                $logicRaw = isset($item['logic']) && is_array($item['logic']) ? $item['logic'] : null;
                if ($logicRaw !== null) {
                    $refOf[$uid] = $logicRaw;
                }

                // Las secciones y páginas nunca son obligatorias ni llevan validaciones
                $isRequired = $isStructural ? false : (bool) ($item['is_required'] ?? false);
                $validations = $isStructural ? [] : (array) ($item['validations'] ?? []);

                // M3: longitud máx. según el esquema (label/placeholder VARCHAR(190),
                // help_text VARCHAR(255)) para evitar 500 silenciosos.
                $label = (string) ($item['label'] ?? '');
                $placeholder = (string) ($item['placeholder'] ?? '');
                $helpText = (string) ($item['help_text'] ?? '');
                if (mb_strlen($label) > 190 || mb_strlen($placeholder) > 190 || mb_strlen($helpText) > 255) {
                    $db->rollBack();
                    Response::json(['ok' => false, 'error' => 'Alguna etiqueta o texto de ayuda excede el máximo permitido.'], 422);
                }

                $counterKey = $parentUid[$uid] ?? '__root__';
                if (!isset($positionCounters[$counterKey])) {
                    $positionCounters[$counterKey] = 0;
                }
                $position = ++$positionCounters[$counterKey];

                $hasId = isset($item['id']) && in_array((int) $item['id'], $existingIds, true);
                if ($hasId) {
                    Field::updateField(
                        (int) $item['id'],
                        $type,
                        $label,
                        $placeholder,
                        $helpText,
                        $isRequired,
                        (array) ($item['options'] ?? []),
                        $validations,
                        $position,
                        $parentIdResolved,
                        null
                    );
                    $finalId = (int) $item['id'];
                } else {
                    $finalId = Field::create(
                        (int) $form['id'],
                        $type,
                        $label,
                        $placeholder,
                        $helpText,
                        $isRequired,
                        (array) ($item['options'] ?? []),
                        $validations,
                        $position,
                        $parentIdResolved,
                        null
                    );
                }

                $uidToId[$uid] = $finalId;
                $idToUid[$finalId] = $uid;
                $keptIds[] = $finalId;
            }

            // PASO 2 — Resolver y validar las condiciones, y probar el grafo (DFS/topo).
            $resolvedLogicByUid = [];
            foreach ($refOf as $uid => $logicRaw) {
                $resolved = $this->resolveLogic($uid, $logicRaw, $uidToId, $idToUid, $typeOf, $payloadByUid);
                if (isset($resolved['error'])) {
                    $db->rollBack();
                    Response::json([
                        'ok'    => false,
                        'error' => $this->logicErrorMessage($resolved['error'], (string) ($payloadByUid[$uid]['label'] ?? '')),
                    ], 422);
                }
                $resolvedLogicByUid[$uid] = $resolved['logic'];
            }
            unset($logicRaw);

            // Grafo de candidatos para validación de ciclos.
            $graph = [];
            foreach ($items as $item) {
                $uid = (string) $item['_uid'];
                $graph[] = [
                    'id'        => $uidToId[$uid],
                    'type'      => (string) $item['type'],
                    'parent_id' => $parentUid[$uid] === null ? null : ($uidToId[$parentUid[$uid]] ?? null),
                    'logic'     => $resolvedLogicByUid[$uid] ?? null,
                ];
            }

            $validationError = LogicEvaluator::validateStructure($graph, $allowedTypes, $answerableTypes);
            if ($validationError !== null) {
                $db->rollBack();
                Response::json(['ok' => false, 'error' => $validationError], 422);
            }

            // PASO 3 — Persistir lógica resuelta.
            foreach ($resolvedLogicByUid as $uid => $logic) {
                Field::setLogic($uidToId[$uid], $logic);
            }

            // Eliminar campos no incluidos en el payload
            foreach ($existingIds as $eid) {
                if (!in_array($eid, $keptIds, true)) {
                    // El campo se va por cascade en BD con sus `answers`, así que
                    // sus rutas se leen ANTES de borrarlo (ver $purgeAfterCommit).
                    $purgeAfterCommit[] = \App\Services\Uploader::pendingForField($eid);
                    Field::deleteField($eid);
                }
            }

            if (isset($body['title'], $body['description'], $body['status'])) {
                $validStatuses = ['draft', 'published', 'closed'];
                $status = in_array($body['status'], $validStatuses, true) ? $body['status'] : $form['status'];
                $title = trim((string) $body['title']);
                if (mb_strlen($title) > 190) {
                    $db->rollBack();
                    Response::json(['ok' => false, 'error' => 'El título no puede superar los 190 caracteres.'], 422);
                }
                Form::update(
                    (int) $form['id'],
                    $title,
                    trim((string) $body['description']),
                    $status
                );
            }

            // P1: settings del formulario (fuente única). Opcional: si no vienen,
            // no se tocan. Si llegan, se validan con whitelist antes de persistir.
            if (array_key_exists('settings', $body)) {
                $rawSettings = $body['settings'];
                if (!is_array($rawSettings)) {
                    $db->rollBack();
                    Response::json(['ok' => false, 'error' => 'Configuración inválida.'], 422);
                }
                $settingsError = \App\Services\FormSettings::validate($rawSettings);
                if ($settingsError !== null) {
                    $db->rollBack();
                    Response::json(['ok' => false, 'error' => $settingsError], 422);
                }
                Form::saveSettings((int) $form['id'], $rawSettings);
            }

            $db->commit();

            // P10/F1.1: ya es demasiado tarde para revertir. Desenlaza del disco
            // los adjuntos de los campos eliminados (rutas recogidas antes del
            // delete). Un fallo aquí solo deja archivos huérfanos en disco, que
            // es el mal que sí tiene arreglo.
            foreach ($purgeAfterCommit as $paths) {
                try {
                    \App\Services\Uploader::purgePaths($paths);
                } catch (\Throwable $e) {
                    error_log('[BuilderController] purgePaths: ' . $e->getMessage());
                }
            }

            Response::json(['ok' => true, 'message' => 'Estructura guardada']);
        } catch (\Throwable $e) {
            $db->rollBack();

            // C3: No exponer detalles internos al cliente; registrar en log
            error_log('[BuilderController] Error al guardar formulario ' . $id . ': ' . $e->getMessage());
            Response::json(['ok' => false, 'error' => 'Ocurrió un error al guardar. Intenta de nuevo.'], 500);
        }
    }

    /**
     * Normaliza la condición enviada por el builder a su forma definitiva
     * (field_id numérico de BD). Devuelve null si alguna referencia es inválida.
     */
    /**
     * F3 — Resuelve la condición de un campo a ids de BD.
     *
     * Devuelve `['logic' => [...]]` o `['error' => <código>]`. Antes todo
     * devolvía `null` y el controlador respondía con un único mensaje que
     * mezclaba cuatro causas distintas; el autor no tenía forma de saber
     * cuál era. Los códigos: `self` (auto-referencia), `section` (la fuente
     * es un estructural), `missing` (la referencia ya no existe) e
     * `invalid` (regla u operador mal formado).
     */
    private function resolveLogic(string $uid, array $logicRaw, array $uidToId, array $idToUid, array $typeOf, array $payloadByUid): array
    {
        if (empty($logicRaw['rules']) || !is_array($logicRaw['rules'])) {
            return ['error' => 'invalid'];
        }
        $operator = $logicRaw['operator'] ?? 'all';
        if ($operator !== 'all' && $operator !== 'any') {
            return ['error' => 'invalid'];
        }

        $rules = [];
        foreach ($logicRaw['rules'] as $rule) {
            if (!is_array($rule) || !isset($rule['op'])) {
                return ['error' => 'invalid'];
            }
            $op = (string) $rule['op'];
            if (!in_array($op, LogicEvaluator::OPERATORS, true)) {
                return ['error' => 'invalid'];
            }

            $ref = $rule['field_id'] ?? null;
            $targetId = null;

            if (is_string($ref) && str_starts_with($ref, 'field-')) {
                if (isset($uidToId[$ref])) {
                    $targetId = $uidToId[$ref];
                }
            } elseif (is_numeric($ref)) {
                $candidate = (int) $ref;
                if (isset($idToUid[$candidate]) && $uidToId[$idToUid[$candidate]] === $candidate) {
                    $targetId = $candidate;
                }
            }

            if ($targetId === null) {
                return ['error' => 'missing'];
            }
            if ($targetId === ($uidToId[$uid] ?? -1)) {
                return ['error' => 'self'];
            }
            // Las fuentes solo pueden ser preguntas contestables (no secciones/páginas).
            $targetUid = $idToUid[$targetId] ?? null;
            if ($targetUid === null) {
                return ['error' => 'missing'];
            }
            $targetType = $payloadByUid[$targetUid]['type'] ?? '';
            if ($targetType === 'section' || $targetType === 'page') {
                return ['error' => 'section'];
            }

            $value = (string) ($rule['value'] ?? '');
            $rules[] = [
                'field_id' => $targetId,
                'op'       => $op,
                'value'    => in_array($op, ['filled', 'empty'], true) ? '' : $value,
            ];
        }

        if ($rules === []) {
            return ['error' => 'invalid'];
        }
        return ['logic' => ['operator' => $operator, 'rules' => $rules]];
    }

    /** F3 — Mensajes por causa, en vez de un único error genérico. */
    private function logicErrorMessage(string $code, string $label): string
    {
        $name = $label !== '' ? '«' . $label . '»' : 'este elemento';
        return match ($code) {
            'self'    => 'La condición de ' . $name . ' no puede basarse en su propia respuesta: no se puede ocultar una pregunta según lo que se responda en ella misma.',
            'section' => 'Las condiciones no pueden usar una sección o una página como fuente, porque no reciben respuesta. Elige una pregunta.',
            'missing' => 'La condición de ' . $name . ' hace referencia a un campo que ya no existe. Quita esa condición o vuelve a crearla.',
            default   => 'La condición de ' . $name . ' no es válida: revisa el operador y las reglas.',
        };
    }

    private function canEdit(int $ownerId): bool
    {
        return (int) Auth::id() === $ownerId || Auth::isAdmin() || Auth::role() === 'editor';
    }
}