<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\View;
use App\Models\FormParticipant;

final class RosterPanel
{
    public const POR_PAGINA = 25;
    public const INVITE_POR_TANDA = 50;
    public const ORDENES = ['cedula', 'nombre', 'correo', 'telefono', 'creado'];

    private function __construct()
    {
    }

    public static function data(int $formId, string $q = '', int $page = 1, string $sort = 'cedula'): array
    {
        $q = trim($q);
        $page = max(1, $page);
        $sort = in_array($sort, self::ORDENES, true) ? $sort : 'cedula';

        $total = FormParticipant::countForForm($formId, $q);
        $paginas = $total > 0 ? (int) ceil($total / self::POR_PAGINA) : 1;
        $page = min($page, $paginas);

        $filas = FormParticipant::allForForm($formId, self::POR_PAGINA, ($page - 1) * self::POR_PAGINA, $q, $sort);

        $atributos = FormParticipant::catalogFor($formId);
        $identificador = FormParticipant::identifierFor($formId);

        return [
            'identificador' => $identificador,
            'invitados' => FormParticipant::invitedCountForForm($formId),
            'porInvitar' => $identificador === FormParticipant::IDENT_CORREO ? FormParticipant::pendingInviteCount($formId) : 0,
            'porRecordar' => $identificador === FormParticipant::IDENT_CORREO ? FormParticipant::pendingReminderCount($formId) : 0,
            'formId' => $formId,
            'q' => $q,
            'page' => $page,
            'sort' => $sort,
            'total' => $total,
            'paginas' => $paginas,
            'porPagina' => self::POR_PAGINA,
            'respondidos' => FormParticipant::respondedCountForForm($formId),
            'atributos' => $atributos,
            'filas' => FormParticipant::conAtributos($filas, $atributos),
        ];
    }

    public static function html(int $formId, string $q = '', int $page = 1, string $sort = 'cedula', array $cambia = ['list', 'stats', 'invites', 'catalog'], ?array $informe = null, ?array $importMapping = null): array
    {
        $datos = self::data($formId, $q, $page, $sort);
        $html = [];

        if (in_array('list', $cambia, true)) {
            $html['rosterList'] = '<div id="rosterList">' . View::partial('dashboard/forms/_roster_list', ['roster' => $datos, 'formId' => $formId]) . '</div>';
        }
        if (in_array('stats', $cambia, true)) {
            $html['rosterStats'] = View::partial('dashboard/forms/_roster_stats', ['roster' => $datos]);
        }
        if (in_array('invites', $cambia, true)) {
            $html['rosterInvites'] = View::partial('dashboard/forms/_roster_invites', ['roster' => $datos, 'formId' => $formId]);
        }
        if (in_array('catalog', $cambia, true)) {
            $html['rosterCatalog'] = View::partial('dashboard/forms/_roster_catalog', ['roster' => $datos, 'formId' => $formId]);
        }
        if (in_array('addForm', $cambia, true)) {
            $html['rosterAddForm'] = View::partial('dashboard/forms/_roster_add_form', ['roster' => $datos, 'formId' => $formId]);
        }
        if (in_array('report', $cambia, true)) {
            if (is_array($importMapping) && !empty($importMapping['path']) && is_file($importMapping['path'])) {
                $html['rosterImportReport'] = View::partial('dashboard/forms/_roster_import_mapping', ['importMapping' => $importMapping, 'formId' => $formId]);
            } else {
                $html['rosterImportReport'] = View::partial('dashboard/forms/_roster_import_report', ['informe' => $informe]);
            }
        }

        return $html;
    }

    public static function panel(array $datos, array $form, ?array $informe = null, ?array $importMapping = null): string
    {
        return View::partial('dashboard/forms/_roster_panel', [
            'roster' => $datos,
            'form' => $form,
            'informe' => $informe,
            'importMapping' => $importMapping,
        ]);
    }
}
