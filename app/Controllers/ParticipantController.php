<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Access;
use App\Core\Auth;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\SecurityLog;
use App\Core\Session;
use App\Core\View;
use App\Models\Form;
use App\Models\FormAccess;
use App\Models\FormParticipant;
use App\Services\FormPanel;
use App\Services\FormSettings;
use App\Core\I18n;
use App\Core\Mailer;
use App\Services\ParticipantImporter;
use App\Services\RosterImportPending;
use App\Services\RosterPanel;
use App\Services\RosterTemplate;

final class ParticipantController
{
    private const INVITE_FALLOS_SEGUIDOS = 3;

    /** Filas por página de la API del listado (la pantalla usa 25). */
    private const POR_PAGINA = 50;

    // =====================================================================
    // Listado
    // =====================================================================

    public function index(int $id): void
    {
        $form = $this->rosterForm($id, 'index');
        if ($form === null) {
            return;
        }

        $q = trim((string) Request::get('q', ''));
        $page = max(1, (int) Request::get('page', 1));
        $sort = (string) Request::get('sort', 'cedula');

        if (self::wantsJson()) {
            $settings = Form::settings($id);
            $locale = I18n::forRequest($settings);
            $total = FormParticipant::countForForm($id, $q);
            $filas = FormParticipant::allForForm($id, self::POR_PAGINA, ($page - 1) * self::POR_PAGINA, $q, $sort);

            $datos = [];
            foreach ($filas as $fila) {
                $fila['data'] = FormParticipant::attributesOf((int) $fila['id']);
                $datos[] = $fila;
            }

            Response::json([
                'ok' => true,
                'identifier' => FormParticipant::identifierFor($id),
                'search' => $q,
                'sort' => $sort,
                'page' => $page,
                'per_page' => self::POR_PAGINA,
                'total' => $total,
                'pages' => $total > 0 ? (int) ceil($total / self::POR_PAGINA) : 1,
                'participantes' => $datos,
                'atributos' => FormParticipant::catalogFor($id),
                'motivos' => [
                    FormParticipant::MOTIVO_DUPLICADO => I18n::t('roster.err_duplicado', $locale, ['id' => '']),
                    FormParticipant::MOTIVO_VACIO => I18n::t('roster.err_vacio', $locale),
                    FormParticipant::MOTIVO_LARGO => I18n::t('roster.err_largo', $locale),
                    FormParticipant::MOTIVO_CORREO => I18n::t('roster.err_correo', $locale),
                    FormParticipant::MOTIVO_ATRIBUTO => I18n::t('roster.err_atributo', $locale),
                    FormParticipant::MOTIVO_VALOR_LARGO => I18n::t('roster.err_valor_largo', $locale),
                ],
            ]);
            return;
        }

        $datos = RosterPanel::data($id, $q, $page, $sort);
        $informe = $this->informeEnSesionMostrar();
        $importMapping = RosterImportPending::obtener($id, (int) ($form['user_id'] ?? Auth::id() ?? 0));

        $html = RosterPanel::panel($datos, $form, $informe, $importMapping);
        echo $html;
    }

    public function template(int $id): void
    {
        $form = $this->rosterForm($id, 'template');
        if ($form === null) {
            return;
        }

        if (!RosterTemplate::puedeEscribir()) {
            Response::abort(500, I18n::t('roster.sin_zip', I18n::forRequest(Form::settings($id))));
            return;
        }

        $binario = RosterTemplate::plantilla();
        $nombre = 'plantilla-participantes-' . (string) ($form['slug'] ?? $id) . '.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        header('Content-Length: ' . strlen($binario));
        echo $binario;
        exit;
    }

    public function export(int $id): void
    {
        $form = $this->rosterForm($id, 'export');
        if ($form === null) {
            return;
        }

        if (!RosterTemplate::puedeEscribir()) {
            Response::abort(500, I18n::t('roster.sin_zip', I18n::forRequest(Form::settings($id))));
            return;
        }

        $atributos = FormParticipant::catalogFor($id);
        $todos = FormParticipant::conAtributos(self::todosLosParticipantes($id), $atributos);
        $codigos = [];
        foreach ($todos as $p) {
            $codigos[(int) ($p['id'] ?? 0)] = (string) ($p['code'] ?? '');
        }

        $identificador = FormParticipant::identifierFor($id);
        $binario = RosterTemplate::exportar($todos, $atributos, $codigos, $identificador);
        $nombre = 'participantes-' . (Form::find($id)['slug'] ?? 'formulario') . '.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        header('Content-Length: ' . strlen($binario));
        echo $binario;
        exit;
    }

    // =====================================================================
    // Escritura: alta, edición y baja de uno
    // =====================================================================

    public function store(int $id): void
    {
        $form = $this->rosterForm($id, 'store');
        if ($form === null) {
            return;
        }
        if (!$this->csrfOk($id, 'roster.invalid_csrf')) {
            return;
        }

        $identificador = FormParticipant::identifierFor($id);
        $campos = $_POST;
        $valorIdent = $this->valorIdentificador($identificador, $campos);
        $correo = trim((string) ($campos['correo'] ?? ''));
        $telefono = trim((string) ($campos['telefono'] ?? ''));

        $data = self::datosAtributos($campos, $id);

        $r = FormParticipant::create($id, $valorIdent, trim((string) ($campos['nombre'] ?? '')), $correo, $telefono, $data, $identificador);
        if (!$r['ok']) {
            $locale = I18n::forRequest(Form::settings($id));
            $this->ok($id, self::textoErrorMotivo((string) ($r['motivo'] ?? ''), $locale, $valorIdent), false, ['error_field' => $this->campoError($identificador, (string) ($r['motivo'] ?? ''))], ['list', 'stats', 'invites']);
            return;
        }

        $this->ok($id, I18n::t('roster.created', I18n::forRequest(Form::settings($id))), true, [], ['list', 'stats', 'invites']);
    }

    public function update(int $id): void
    {
        $form = $this->rosterForm($id, 'update');
        if ($form === null) {
            return;
        }
        if (!$this->csrfOk($id, 'roster.invalid_csrf')) {
            return;
        }

        $pid = (int) ($_POST['participant_id'] ?? 0);
        if ($pid <= 0 || !FormParticipant::existeEnFormulario($id, $pid)) {
            $this->ok($id, I18n::t('roster.not_found', I18n::forRequest(Form::settings($id))), false);
            return;
        }

        $identificador = FormParticipant::identifierFor($id);
        $campos = $_POST;
        $valorIdent = $this->valorIdentificador($identificador, $campos);
        $correo = trim((string) ($campos['correo'] ?? ''));
        $telefono = trim((string) ($campos['telefono'] ?? ''));

        $data = self::datosAtributos($campos, $id);

        $r = FormParticipant::update($pid, $id, $valorIdent, trim((string) ($campos['nombre'] ?? '')), $correo, $telefono, $data, $identificador);
        if (!$r['ok']) {
            $locale = I18n::forRequest(Form::settings($id));
            $this->ok($id, self::textoErrorMotivo((string) ($r['motivo'] ?? ''), $locale, $valorIdent), false, ['error_field' => $this->campoError($identificador, (string) ($r['motivo'] ?? ''))], ['list', 'stats', 'invites', 'catalog']);
            return;
        }

        $this->ok($id, I18n::t('roster.saved', I18n::forRequest(Form::settings($id))), true, [], ['list', 'stats', 'invites']);
    }

    public function destroy(int $id): void
    {
        $form = $this->rosterForm($id, 'delete');
        if ($form === null) {
            return;
        }
        if (!$this->csrfOk($id, 'roster.invalid_csrf')) {
            return;
        }

        $pid = (int) ($_POST['participant_id'] ?? 0);
        if ($pid <= 0 || !FormParticipant::existeEnFormulario($id, $pid)) {
            $this->ok($id, I18n::t('roster.not_found', I18n::forRequest(Form::settings($id))), false);
            return;
        }

        FormParticipant::delete($pid, $id);
        $this->ok($id, I18n::t('roster.deleted', I18n::forRequest(Form::settings($id))), true, [], ['list', 'stats', 'invites']);
    }

    // =====================================================================
    // Importación con mapeo
    // =====================================================================

    public function preview(int $id): void
    {
        $form = $this->rosterForm($id, 'import');
        if ($form === null) {
            return;
        }
        if (!$this->csrfOk($id, 'roster.invalid_csrf')) {
            return;
        }
        if (!RosterTemplate::puedeLeer()) {
            $this->ok($id, I18n::t('roster.sin_zip', I18n::forRequest(Form::settings($id))), false);
            return;
        }

        $fichero = $_FILES['archivo'] ?? null;
        if (!is_array($fichero) || (int) ($fichero['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->ok($id, I18n::t('roster.sin_archivo', I18n::forRequest(Form::settings($id))), false);
            return;
        }

        try {
            $libro = RosterTemplate::leer((string) $fichero['tmp_name']);
        } catch (\RuntimeException $e) {
            $this->ok($id, self::textoError((string) $e->getMessage(), I18n::forRequest(Form::settings($id)), ''), false);
            return;
        }

        $modo = (string) Request::post('modo', ParticipantImporter::MODO_FUSIONAR);
        $entry = RosterImportPending::crear($id, (int) Auth::id(), $modo, $libro['cabeceras'], count($libro['filas']), (string) $fichero['tmp_name']);

        $datos = RosterPanel::data($id, (string) Request::post('rosterQ', ''), (int) Request::post('rosterPage', 1), (string) Request::post('rosterSort', 'cedula'));
        $html = RosterPanel::html($id, (string) Request::post('rosterQ', ''), (int) Request::post('rosterPage', 1), (string) Request::post('rosterSort', 'cedula'), ['list', 'stats', 'invites', 'catalog', 'addForm', 'report'], null, $entry);

        if (self::wantsJson()) {
            Response::json(['ok' => true, 'html' => $html]);
            return;
        }

        Session::flash('info', I18n::t('roster.import_map_ready', I18n::forRequest(Form::settings($id)), ['n' => count($libro['filas'])]));
        Response::redirect('/forms/' . $id . '/access#participantes');
    }

    public function confirm(int $id): void
    {
        $form = $this->rosterForm($id, 'import');
        if ($form === null) {
            return;
        }
        if (!$this->csrfOk($id, 'roster.invalid_csrf')) {
            return;
        }

        $token = (string) Request::post('roster_import_token', '');
        $entry = RosterImportPending::tomar($id, (int) Auth::id(), $token);
        if ($entry === null) {
            $this->ok($id, I18n::t('roster.import_pending_expired', I18n::forRequest(Form::settings($id))), false);
            return;
        }

        $mapeo = [
            'identificador' => Request::post('map_identificador'),
            'nombre' => Request::post('map_nombre'),
            'correo' => Request::post('map_correo'),
            'telefono' => Request::post('map_telefono'),
            'ignorar' => Request::post('map_ignorar'),
        ];
        if (isset($mapeo['identificador']) && $mapeo['identificador'] !== '') {
            $mapeo['identificador'] = (int) $mapeo['identificador'];
        } else {
            $mapeo['identificador'] = null;
        }
        if (isset($mapeo['nombre']) && $mapeo['nombre'] !== '') {
            $mapeo['nombre'] = (int) $mapeo['nombre'];
        } else {
            $mapeo['nombre'] = null;
        }
        if (isset($mapeo['correo']) && $mapeo['correo'] !== '') {
            $mapeo['correo'] = (int) $mapeo['correo'];
        } else {
            $mapeo['correo'] = null;
        }
        if (isset($mapeo['telefono']) && $mapeo['telefono'] !== '') {
            $mapeo['telefono'] = (int) $mapeo['telefono'];
        } else {
            $mapeo['telefono'] = null;
        }
        if (is_array($mapeo['ignorar'])) {
            $ign = [];
            foreach ($mapeo['ignorar'] as $v) {
                $ign[] = (int) $v;
            }
            $mapeo['ignorar'] = $ign;
        } else {
            $mapeo['ignorar'] = [];
        }

        $identificador = FormParticipant::identifierFor($id);
        $ruta = (string) ($entry['path'] ?? '');
        $modo = (string) ($entry['modo'] ?? ParticipantImporter::MODO_FUSIONAR);

        try {
            $r = ParticipantImporter::importar($id, $ruta, $identificador, $modo, $mapeo);
        } finally {
            RosterImportPending::borrar($entry);
        }

        $locale = I18n::forRequest(Form::settings($id));
        if (!$r['ok']) {
            $this->ok($id, self::textoError((string) $r['error'], $locale, $identificador), false, [
                'total' => $r['total'],
                'omitidos' => $r['omitidos'],
                'problemas' => $r['problemas'],
            ], ['list', 'stats', 'catalog', 'addForm', 'report'], $r);
            return;
        }

        SecurityLog::record('roster.imported', $r['modo'] . ' ' . $r['total'] . ' filas en /forms/' . $id);
        $this->ok($id, self::textoResumen($r, $locale), true, $r, ['list', 'stats', 'invites', 'catalog', 'addForm', 'report'], $r);
    }

    public function cancel(int $id): void
    {
        $form = $this->rosterForm($id, 'import');
        if ($form === null) {
            return;
        }
        if (!$this->csrfOk($id, 'roster.invalid_csrf')) {
            return;
        }
        $token = (string) Request::post('roster_import_token', '');
        $entry = null;
        $lista = $_SESSION['roster_import'] ?? [];
        if (is_array($lista) && isset($lista[$token])) {
            $entry = $lista[$token];
            unset($_SESSION['roster_import'][$token]);
        }
        RosterImportPending::borrar($entry);
        $this->ok($id, I18n::t('roster.import_cancelled', I18n::forRequest(Form::settings($id))), true, [], ['list', 'stats', 'invites', 'catalog', 'addForm', 'report']);
    }

    public function import(int $id): void
    {
        $form = $this->rosterForm($id, 'import');
        if ($form === null) {
            return;
        }
        if (!$this->csrfOk($id, 'roster.invalid_csrf')) {
            return;
        }
        if (!RosterTemplate::puedeLeer()) {
            $this->ok($id, I18n::t('roster.sin_zip', I18n::forRequest(Form::settings($id))), false);
            return;
        }

        $fichero = $_FILES['archivo'] ?? null;
        if (!is_array($fichero) || (int) ($fichero['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->ok($id, I18n::t('roster.sin_archivo', I18n::forRequest(Form::settings($id))), false);
            return;
        }

        $identificador = FormParticipant::identifierFor($id);
        $r = ParticipantImporter::importar(
            $id,
            (string) $fichero['tmp_name'],
            $identificador,
            (string) Request::post('modo', ParticipantImporter::MODO_FUSIONAR)
        );

        $locale = I18n::forRequest(Form::settings($id));
        if (!$r['ok']) {
            $this->ok($id, self::textoError((string) $r['error'], $locale, $identificador), false, [
                'total' => $r['total'],
                'omitidos' => $r['omitidos'],
                'problemas' => $r['problemas'],
            ], ['list', 'stats', 'catalog', 'addForm', 'report'], $r);
            return;
        }

        SecurityLog::record('roster.imported', $r['modo'] . ' ' . $r['total'] . ' filas en /forms/' . $id);
        $this->ok($id, self::textoResumen($r, $locale), true, $r, ['list', 'stats', 'invites', 'catalog', 'addForm', 'report'], $r);
    }

    public function truncate(int $id): void
    {
        $form = $this->rosterForm($id, 'truncate');
        if ($form === null) {
            return;
        }
        if (!$this->csrfOk($id, 'roster.invalid_csrf')) {
            return;
        }

        $borrados = FormParticipant::truncate($id);
        SecurityLog::record('roster.truncated', $borrados . ' participantes en /forms/' . $id);
        $this->ok($id, I18n::t('roster.truncated', I18n::forRequest(Form::settings($id)), ['n' => $borrados]), true, [], ['list', 'stats', 'invites']);
    }

    // =====================================================================
    // Invitaciones / recordatorios
    // =====================================================================

    public function invitations(int $id): void
    {
        $form = $this->rosterForm($id, 'send_invitations');
        if ($form === null) {
            return;
        }
        if (!$this->csrfOk($id, 'roster.invalid_csrf')) {
            return;
        }

        $locale = I18n::forRequest(Form::settings($id));
        $identificador = FormParticipant::identifierFor($id);
        if ($identificador !== FormParticipant::IDENT_CORREO) {
            $this->ok($id, I18n::t('roster.invite_no_cedula', $locale, ['id' => $identificador]), false, [], ['invites']);
            return;
        }
        if (!Mailer::isConfigured()) {
            $this->ok($id, I18n::t('roster.invite_no_smtp', $locale), false, [], ['invites']);
            return;
        }

        $pendientes = FormParticipant::pendingInvites($id, RosterPanel::INVITE_POR_TANDA);
        if ($pendientes === []) {
            $this->ok($id, I18n::t('roster.invite_none_left', $locale), true, [], ['invites']);
            return;
        }

        $antes = FormParticipant::pendingInviteCount($id);
        $enviados = 0;
        $fallidos = 0;
        $fallidosSeguidos = 0;
        foreach ($pendientes as $p) {
            if ($fallidosSeguidos >= self::INVITE_FALLOS_SEGUIDOS) {
                break;
            }
            $correo = trim((string) ($p['correo'] ?? ''));
            if ($correo === '') {
                continue;
            }
            try {
                $url = \App\Core\PublicUrl::rosterUrl((string) $form['slug'], (string) $p['code']);
                ob_start();
                View::render('mail/roster_invite', [
                    'form' => $form,
                    'url' => $url,
                    'nombre' => $p['nombre'] ?? '',
                ], null);
                $htmlInvite = (string) ob_get_clean();
                Mailer::send([$correo], I18n::t('roster.invite_subject', $locale, ['form' => (string) ($form['title'] ?? '')]), $htmlInvite, strip_tags($htmlInvite));
                FormParticipant::markInvited((int) $p['id']);
                $enviados++;
                $fallidosSeguidos = 0;
            } catch (\Throwable $e) {
                $fallidos++;
                $fallidosSeguidos++;
            }
        }

        $parado = $fallidosSeguidos >= self::INVITE_FALLOS_SEGUIDOS;
        $quedan = max(0, $antes - $enviados);
        $msg = I18n::t('roster.invite_sent', $locale, ['n' => $enviados]);
        if ($parado) {
            $msg .= ' ' . I18n::t('roster.invite_stopped', $locale, ['n' => self::INVITE_FALLOS_SEGUIDOS, 's' => $enviados]);
        } elseif ($fallidos > 0) {
            $msg .= ' ' . I18n::t('roster.invite_sent_some', $locale, ['n' => $enviados, 'f' => $fallidos]);
        } elseif ($quedan > 0) {
            $msg .= ' ' . I18n::t('roster.invite_sent_cap', $locale, ['n' => $enviados, 'q' => $quedan]);
        }
        $this->ok($id, $msg, !$parado && $fallidos === 0, [], ['invites']);
    }

    public function remind(int $id): void
    {
        $form = $this->rosterForm($id, 'send_invitations');
        if ($form === null) {
            return;
        }
        if (!$this->csrfOk($id, 'roster.invalid_csrf')) {
            return;
        }

        $locale = I18n::forRequest(Form::settings($id));
        $identificador = FormParticipant::identifierFor($id);
        if ($identificador !== FormParticipant::IDENT_CORREO) {
            $this->ok($id, I18n::t('roster.remind_no_cedula', $locale, ['id' => $identificador]), false, [], ['invites']);
            return;
        }
        if (!Mailer::isConfigured()) {
            $this->ok($id, I18n::t('roster.remind_no_smtp', $locale), false, [], ['invites']);
            return;
        }

        $pendientes = FormParticipant::pendingReminders($id, RosterPanel::INVITE_POR_TANDA);
        if ($pendientes === []) {
            $this->ok($id, I18n::t('roster.remind_none_left', $locale), true, [], ['invites']);
            return;
        }

        $ajustes = Form::settings($id);
        $deadline = self::deadlineTexto((string) ($ajustes['schedule']['close_at'] ?? ''), $locale);
        $antes = FormParticipant::pendingReminderCount($id);
        $enviados = 0;
        $fallidos = 0;
        $fallidosSeguidos = 0;
        foreach ($pendientes as $p) {
            if ($fallidosSeguidos >= self::INVITE_FALLOS_SEGUIDOS) {
                break;
            }
            $correo = trim((string) ($p['correo'] ?? ''));
            if ($correo === '') {
                continue;
            }
            try {
                $url = \App\Core\PublicUrl::rosterUrl((string) $form['slug'], (string) $p['code']);
                ob_start();
                View::render('mail/roster_reminder', [
                    'form' => $form,
                    'url' => $url,
                    'nombre' => $p['nombre'] ?? '',
                    'locale' => $locale,
                    'deadline' => $deadline,
                    'formId' => $id,
                ], null);
                $htmlRem = (string) ob_get_clean();
                Mailer::send([$correo], I18n::t('roster.remind_subject', $locale, ['form' => (string) ($form['title'] ?? '')]), $htmlRem, strip_tags($htmlRem));
                FormParticipant::markReminded((int) $p['id']);
                $enviados++;
                $fallidosSeguidos = 0;
            } catch (\Throwable $e) {
                $fallidos++;
                $fallidosSeguidos++;
            }
        }

        $parado = $fallidosSeguidos >= self::INVITE_FALLOS_SEGUIDOS;
        $quedan = max(0, $antes - $enviados);
        $msg = I18n::t('roster.remind_sent', $locale, ['n' => $enviados]);
        if ($parado) {
            $msg .= ' ' . I18n::t('roster.remind_stopped', $locale, ['n' => self::INVITE_FALLOS_SEGUIDOS, 's' => $enviados]);
        } elseif ($fallidos > 0) {
            $msg .= ' ' . I18n::t('roster.remind_sent_some', $locale, ['n' => $enviados, 'f' => $fallidos]);
        } elseif ($quedan > 0) {
            $msg .= ' ' . I18n::t('roster.remind_sent_cap', $locale, ['n' => $enviados, 'q' => $quedan]);
        }
        $this->ok($id, $msg, !$parado && $fallidos === 0, [], ['invites']);
    }

    public function destroyAttribute(int $id): void
    {
        $form = $this->rosterForm($id, 'attribute');
        if ($form === null) {
            return;
        }
        if (!$this->csrfOk($id, 'roster.invalid_csrf')) {
            return;
        }

        $field = (string) ($_POST['field_id'] ?? $_POST['key_name'] ?? '');
        if ($field !== '') {
            FormParticipant::deleteAttribute($id, $field);
        }
        $this->ok($id, I18n::t('roster.attribute_deleted', I18n::forRequest(Form::settings($id))), true, [], ['list', 'catalog', 'addForm']);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function rosterForm(int $id, string $action): ?array
    {
        $form = Form::find($id);
        if ($form === null) {
            Response::abort(404);
            return null;
        }
        $user = Auth::user();
        if (!$user) {
            Response::redirect('/login');
            return null;
        }
        if (!Access::canEdit((int) ($form['user_id'] ?? 0))) {
            Response::abort(403);
            return null;
        }
        if (!in_array($action, ['index', 'template', 'export', 'store', 'update', 'delete', 'import', 'truncate', 'attribute', 'send_invitations'], true)) {
            Response::abort(403);
            return null;
        }
        if (FormAccess::mode(Form::settingsFrom($form)) !== FormSettings::ACCESS_ROSTER) {
            Response::abort(404);
            return null;
        }
        return $form;
    }

    private function csrfOk(int $id, string $clave): bool
    {
        if (!\App\Core\Csrf::verifyRequest()) {
            $locale = I18n::forRequest(Form::settings($id));
            Session::flash('error', I18n::t($clave, $locale));
            Response::redirect('/forms/' . $id . '/access#participantes');
            return false;
        }
        return true;
    }

    private function todosLosParticipantes(int $formId): array
    {
        $db = \App\Core\Database::getInstance(\App\Core\Config::db());
        $rows = $db->fetchAll('SELECT * FROM form_participants WHERE form_id = :form_id ORDER BY id ASC', ['form_id' => $formId]);
        return is_array($rows) ? $rows : [];
    }

    private static function deadlineTexto(string $closeAt, string $locale): string
    {
        $closeAt = trim($closeAt);
        if ($closeAt === '') {
            return '';
        }

        try {
            $dt = new \DateTimeImmutable($closeAt, Clock::displayZone());
            return $dt->format('d/m/Y H:i');
        } catch (\Exception $e) {
            return '';
        }
    }

    private function valorIdentificador(string $identificador, array $campos): string
    {
        $columna = FormParticipant::COLUMNA_IDENTIFICADOR[$identificador] ?? FormParticipant::COLUMNA_IDENTIFICADOR['cedula'];
        return trim((string) ($campos[$columna] ?? ''));
    }

    private function campoError(string $identificador, string $motivo): string
    {
        if ($motivo === FormParticipant::MOTIVO_DUPLICADO) {
            return $identificador === 'correo' ? 'correo' : 'cedula';
        }
        if ($motivo === FormParticipant::MOTIVO_VACIO || $motivo === FormParticipant::MOTIVO_LARGO) {
            return $identificador === 'correo' ? 'correo' : 'cedula';
        }
        if ($motivo === FormParticipant::MOTIVO_CORREO) {
            return 'correo';
        }
        return '';
    }

    private static function datosAtributos(array $campos, int $formId = 0): array
    {
        $data = [];
        foreach ($campos as $k => $v) {
            if (strpos($k, 'attr_') === 0) {
                $data[substr($k, 5)] = is_array($v) ? implode(', ', $v) : (string) $v;
            }
        }
        if (is_array($campos['data'] ?? null)) {
            foreach ($campos['data'] as $k => $v) {
                $data[(string) $k] = is_array($v) ? implode(', ', $v) : (string) $v;
            }
        }

        if ($formId > 0 && $data !== []) {
            $permitidos = array_column(FormParticipant::catalogFor($formId), 'key_name');
            $data = array_intersect_key($data, array_flip(array_map('strval', $permitidos)));
        }

        return $data;
    }

    private static function textoErrorMotivo(string $motivo, string $locale, string $valor): string
    {
        return match ($motivo) {
            FormParticipant::MOTIVO_DUPLICADO => I18n::t('roster.err_duplicado', $locale, ['id' => $valor]),
            FormParticipant::MOTIVO_VACIO => I18n::t('roster.err_vacio', $locale),
            FormParticipant::MOTIVO_LARGO => I18n::t('roster.err_largo', $locale),
            FormParticipant::MOTIVO_CORREO => I18n::t('roster.err_correo', $locale),
            FormParticipant::MOTIVO_ATRIBUTO => I18n::t('roster.err_atributo', $locale),
            FormParticipant::MOTIVO_VALOR_LARGO => I18n::t('roster.err_valor_largo', $locale),
            default => I18n::t('roster.err_modo', $locale),
        };
    }

    private static function textoError(string $motivo, string $locale, string $columna): string
    {
        return match ($motivo) {
            'modo_invalido' => I18n::t('roster.err_modo', $locale),
            'identificador_invalido' => I18n::t('roster.err_identificador', $locale),
            'roster_sin_zip' => I18n::t('roster.sin_zip', $locale),
            'roster_sin_filas' => I18n::t('roster.err_sin_filas', $locale),
            'roster_no_zip' => I18n::t('roster.err_no_zip', $locale),
            'roster_demasiadas_filas' => I18n::t('roster.err_demasiadas', $locale),
            'roster_sin_hoja' => I18n::t('roster.err_ilegible', $locale),
            'roster_hoja_vacia' => I18n::t('roster.err_ilegible', $locale),
            'roster_zip_bomb' => I18n::t('roster.err_zip_bomb', $locale),
            'roster_sin_archivo' => I18n::t('roster.sin_archivo', $locale),
            'falta_columna_identificador' => I18n::t('roster.err_falta_columna', $locale, ['id' => 'Cédula']),
            'reemplazar_con_errores' => I18n::t('roster.err_reemplazar', $locale),
            'atributo_no_creable' => I18n::t('roster.err_atributo_largo', $locale),
            'mapeo_invalido' => I18n::t('roster.err_mapeo_invalido', $locale),
            'mapeo_duplicado' => I18n::t('roster.err_mapeo_duplicado', $locale),
            'mapeo_sin_identificador' => I18n::t('roster.err_mapeo_sin_identificador', $locale),
            'import_pendiente_vencido' => I18n::t('roster.import_pending_expired', $locale),
            'todas_fuera' => I18n::t('roster.err_sin_filas', $locale),
            default => I18n::t('roster.err_ilegible', $locale),
        };
    }

    private static function textoResumen(array $r, string $locale): string
    {
        $total = (int) ($r['total'] ?? 0);
        $nuevos = (int) ($r['insertados'] ?? 0);
        $cambios = (int) ($r['actualizados'] ?? 0);
        $iguales = (int) ($r['sin_cambios'] ?? 0);
        $omitidos = (int) ($r['omitidos'] ?? 0);

        $msg = I18n::t('roster.imported', $locale, ['nuevos' => $nuevos, 'total' => $total]);
        if ($cambios > 0 || $iguales > 0) {
            $msg .= ' ' . I18n::t('roster.imported_merge', $locale, ['cambios' => $cambios, 'iguales' => $iguales]);
        }
        if ($omitidos > 0) {
            $msg .= ' ' . I18n::t('roster.imported_skipped', $locale, ['n' => $omitidos]);
        }
        $attrs = is_array($r['atributos_nuevos'] ?? null) ? $r['atributos_nuevos'] : [];
        if ($attrs !== []) {
            $msg .= ' ' . I18n::t('roster.imported_attrs', $locale, ['lista' => implode(', ', $attrs), 'n' => count($attrs)]);
        }
        return $msg;
    }

    private function ok(int $id, string $mensaje, bool $exito = true, array $extra = [], array $cambia = [], ?array $importacion = null): void
    {
        if (self::wantsJson()) {
            $json = ['ok' => $exito, 'message' => $mensaje] + $extra;
            $q = (string) Request::post('rosterQ', '');
            $page = (int) Request::post('rosterPage', 1);
            $sort = (string) Request::post('rosterSort', 'cedula');
            if ($cambia !== []) {
                $json['html'] = RosterPanel::html($id, $q, $page, $sort, $cambia, $this->informe($importacion));
            }
            Response::json($json);
            return;
        }

        if ($importacion !== null) {
            $this->informeEnSesion($importacion);
        }

        Session::flash($exito ? 'success' : 'error', $mensaje);
        Response::redirect('/forms/' . $id . '/access#participantes');
    }

    private static function wantsJson(): bool
    {
        return Response::wantsJson();
    }

    private function informeEnSesion(array $r): void
    {
        $inf = $this->informe($r);
        if ($inf === null) {
            return;
        }
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
        Session::flashValue('roster_report', $inf);
    }

    private function informeEnSesionMostrar(): ?array
    {
        $val = Session::takeValue('roster_report');
        return is_array($val) ? $val : null;
    }

    private function informe(?array $r): ?array
    {
        if (!is_array($r)) {
            return null;
        }
        $omitidos = (int) ($r['omitidos'] ?? 0);
        $problemas = is_array($r['problemas'] ?? null) ? $r['problemas'] : [];
        if ($omitidos <= 0 && $problemas === []) {
            return null;
        }
        return ['omitidos' => $omitidos, 'problemas' => $problemas, 'modo' => $r['modo'] ?? 'fusionar', 'total' => (int) ($r['total'] ?? 0)];
    }
}
