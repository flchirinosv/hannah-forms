<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Access;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\I18n;
use App\Core\Request;
use App\Core\Response;
use App\Core\SecurityLog;
use App\Core\Session;
use App\Models\Form;
use App\Models\FormAccess;
use App\Models\FormParticipant;
use App\Services\FormSettings;
use App\Services\ParticipantImporter;
use App\Services\RosterTemplate;

/**
 * Fase 3 — gestión del listado de participantes.
 *
 * Va en un controlador aparte por el mismo motivo que F3 sacó `ReviewController`
 * de `FormController` y F4 sacó `AccessController` de `FillController`: aquí se
 * cruzan dos cosas que no deben acabar confundidas, y el sitio donde se confunden
 * es donde aparecen errores que no se ven. Este archivo **es** la gestión del
 * listado, entero. La puerta pública del fill es `FillController` y no se toca
 * hasta la fase 4.
 *
 * ## Por qué solo funciona en modo `roster`
 *
 * Todas las acciones exigen que el formulario esté en `roster`. La razón no es
 * «no dejar jugar a la interfaz», es que en los otros tres modos la lista **no es la
 * puerta**: editarla no cambiaría a quién puede responder, y una lista que parece
 * vigente y no lo es es peor que no tenerla. Los datos no se borran al cambiar de
 * modo —`roster` no borra y `public` tampoco—, así que volver a `roster` los
 * devuelve intactos. Lo que sí tiene que bloquear el cambio es que nadie se quede
 * sin identidad, y eso lo cuenta `FormParticipant::sinIdentificadorPara()`.
 *
 * ## La importación nunca envía correo
 *
 * Subir 400 filas y disparar 400 correos desde un POST es un accidente esperando. El
 * botón de invitaciones es otro action, opt-in y con control de lo ya enviado (fase
 * 10). Aquí no hay ni una llamada al `Mailer`, y `testLaImportacionNoDisparaCorreo()`
 * lo comprueba contando correos con el sumidero puesto.
 */
class ParticipantController
{
    /** Filas por página del listado. */
    private const POR_PAGINA = 50;

    // =====================================================================
    // Lectura
    // =====================================================================

    /**
     * GET /forms/{id}/participants — la lista y su catálogo de atributos.
     *
     * Sale en JSON porque la pestaña todavía no existe (fase 5) y una vista que
     * solo pinta una tabla no aporta nada que JSON no dé. Cuando llegue la pestaña,
     * esta misma respuesta es lo que consumirá, y por eso los ids de lo que
     * devuelve son los del hueco que se rellenará.
     */
    public function index(int $id): void
    {
        $form = $this->rosterForm($id, 'index');
        if ($form === null) {
            return;
        }

        $settings = Form::settings($id);
        $buscar   = trim((string) Request::query('q', ''));
        $pagina   = max(1, (int) Request::query('page', 1));
        $orden    = (string) Request::query('sort', 'cedula');
        $limite   = self::POR_PAGINA;

        $total = FormParticipant::countForForm($id, $buscar);
        $filas = FormParticipant::allForForm($id, $limite, ($pagina - 1) * $limite, $buscar, $orden);
        $formIdDeFila = [];
        foreach ($filas as $fila) {
            $formIdDeFila[(int) $fila['id']] = FormParticipant::attributesOf((int) $fila['id']);
        }

        Response::json([
            'ok'          => true,
            'identifier'  => FormParticipant::identifierFor($id),
            'search'      => $buscar,
            'sort'        => $orden,
            'page'        => $pagina,
            'per_page'    => $limite,
            'total'       => $total,
            'pages'       => $total > 0 ? (int) ceil($total / $limite) : 1,
            'participantes' => array_map(
                static function (array $f) use ($formIdDeFila): array {
                    $f['data'] = $formIdDeFila[(int) $f['id']];

                    return $f;
                },
                $filas
            ),
            'atributos'   => FormParticipant::catalogFor($id),
            'motivos'     => [
                FormParticipant::MOTIVO_DUPLICADO => I18n::t('roster.err_duplicado', I18n::forRequest($settings)),
                FormParticipant::MOTIVO_VACIO     => I18n::t('roster.err_vacio', I18n::forRequest($settings)),
                FormParticipant::MOTIVO_LARGO     => I18n::t('roster.err_largo', I18n::forRequest($settings)),
                FormParticipant::MOTIVO_CORREO    => I18n::t('roster.err_correo', I18n::forRequest($settings)),
            ],
        ]);
    }

    /**
     * Todos los participantes del formulario, en tandas.
     *
     * Va por tandas y no con un `limit` enorme porque `allForForm()` tiene un tope
     * de 1000 filas: pedir «todas» en una llamada devolvería 1000 de 3000 sin decir
     * nada, y un `.xlsx` con la lista a medias es el peor resultado porque parece
     * completo.
     *
     * @return list<array<string,mixed>>
     */
    private static function todosLosParticipantes(int $formId): array
    {
        $todos = [];
        $pagina = 0;

        while (true) {
            $lote = FormParticipant::allForForm($formId, 1000, $pagina * 1000, '', 'cedula');
            if ($lote === []) {
                break;
            }
            $todos = array_merge($todos, $lote);
            if (count($lote) < 1000) {
                break;
            }
            $pagina++;
        }

        return $todos;
    }

    /**
     * GET /forms/{id}/participants/export — la lista en `.xlsx`.
     *
     * Es lo que hace falta para repartir los códigos, así que no lleva los mismos
     * cortes que el listado de pantalla: trae **todo**, con el `code` de cada quien
     * al final. Poner un tope aquí sería cambiar de pronto lo que la gente ya tiene
     * impreso, y quien tiene 3000 personas necesita las 3000 filas aunque la
     * pantalla vaya por páginas.
     */
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
        $todos = FormParticipant::conAtributos(
            self::todosLosParticipantes($id),
            $atributos
        );

        $codigos = [];
        foreach ($todos as $p) {
            $codigos[(int) $p['id']] = (string) $p['code'];
        }

        $binario = RosterTemplate::exportar($todos, $atributos, $codigos);
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
        $campos = [
            'cedula'    => (string) Request::post('cedula', ''),
            'nombre'    => (string) Request::post('nombre', ''),
            'indicador' => (string) Request::post('indicador', ''),
            'correo'    => (string) Request::post('correo', ''),
        ];
        $data = $this->atributosDelPost($id);

        $r = FormParticipant::create($id, $identificador, $campos, $data);

        if (!$r['ok']) {
            $this->motivoDeFallo($id, $r['motivo'], 'roster.not_created', $this->valorIdentificador($identificador, $campos));

            return;
        }

        $this->ok($id, I18n::t('roster.created', I18n::forRequest(Form::settings($id))));
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

        $pid = (int) Request::post('participant_id', 0);
        $fila = $pid > 0 ? FormParticipant::find($pid) : null;

        // El `form_id` en el WHERE no es redundante: sin él, un POST con el id de
        // otro formulario editaría a alguien de una lista que no es la suya. Es lo
        // mismo que hace `FormAccess::findValidToken()` con su token.
        if ($fila === null || (int) $fila['form_id'] !== $id) {
            $this->motivoDeFallo($id, 'no_existe', 'roster.not_saved');

            return;
        }

        $identificador = FormParticipant::identifierFor($id);
        $campos = [
            'cedula'    => (string) Request::post('cedula', ''),
            'nombre'    => (string) Request::post('nombre', ''),
            'indicador' => (string) Request::post('indicador', ''),
            'correo'    => (string) Request::post('correo', ''),
        ];
        $data = $this->atributosDelPost($id);

        $r = FormParticipant::update($pid, $identificador, $campos, $data);

        if (!$r['ok']) {
            $this->motivoDeFallo($id, $r['motivo'], 'roster.not_saved', $this->valorIdentificador($identificador, $campos));

            return;
        }

        $this->ok($id, I18n::t('roster.saved', I18n::forRequest(Form::settings($id))));
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

        $pid = (int) Request::post('participant_id', 0);
        $fila = $pid > 0 ? FormParticipant::find($pid) : null;
        if ($fila === null || (int) $fila['form_id'] !== $id) {
            $this->motivoDeFallo($id, 'no_existe', 'roster.not_deleted');

            return;
        }

        FormParticipant::delete($pid);
        $this->ok($id, I18n::t('roster.deleted', I18n::forRequest(Form::settings($id))));
    }

    // =====================================================================
    // La lista entera: importar y vaciar
    // =====================================================================

    public function import(int $id): void
    {
        $form = $this->rosterForm($id, 'import');
        if ($form === null) {
            return;
        }

        // `post-max` va antes de `csrf` en la ruta (ver `config/routes.php`): si el
        // cuerpo pasa de `post_max_size`, PHP vacía `$_POST` y `$_FILES` y sin el
        // guard se respondería «token CSRF inválido» a quien no tiene ningún
        // problema. Aquí solo se comprueba que el guard ya dejó pasar.
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

        $tmp = (string) $fichero['tmp_name'];
        // El nombre que manda el cliente no se mira. Se lee de `tmp_name` porque
        // PHP ya lo dejó en un temporal y lo borra al acabar la petición, y el
        // roster NO es un adjunto: no se guarda en `storage/uploads/` ni pasa por
        // `Uploader`, porque no es una respuesta de un visitante sino un archivo del
        // dueño que solo existe mientras dura la importación.
        $identificador = FormParticipant::identifierFor($id);
        $r = ParticipantImporter::importar(
            $id,
            $tmp,
            $identificador,
            (string) Request::post('modo', ParticipantImporter::MODO_FUSIONAR)
        );

        $locale = I18n::forRequest(Form::settings($id));

        if (!$r['ok']) {
            $this->ok($id, self::textoError((string) $r['error'], $locale, $identificador), false, [
                'total'     => $r['total'],
                'omitidos'  => $r['omitidos'],
                'problemas' => $r['problemas'],
            ]);

            return;
        }

        SecurityLog::record('roster.imported', $r['modo'] . ' ' . $r['total'] . ' filas en /forms/' . $id);
        $this->ok($id, self::textoResumen($r, $locale), true, $r);
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
        $this->ok($id, I18n::t('roster.truncated', I18n::forRequest(Form::settings($id)), ['n' => $borrados]));
    }

    public function destroyAttribute(int $id): void
    {
        $form = $this->rosterForm($id, 'delete_attribute');
        if ($form === null) {
            return;
        }

        if (!$this->csrfOk($id, 'roster.invalid_csrf')) {
            return;
        }

        $clave = (string) Request::post('key_name', '');
        if ($clave === '') {
            $this->motivoDeFallo($id, FormParticipant::MOTIVO_ATRIBUTO, 'roster.not_deleted');

            return;
        }

        FormParticipant::deleteAttribute($id, $clave);
        $this->ok($id, I18n::t('roster.attribute_deleted', I18n::forRequest(Form::settings($id))));
    }

    // =====================================================================
    // Piezas compartidas
    // =====================================================================

    /**
     * El formulario tiene que ser del usuario y estar en modo `roster`.
     *
     * El permiso es `Access::canEdit()`, el mismo que usa la allowlist, y no uno
     * nuevo: gestionar la lista de un formulario es gestionarlo, y un permiso
     * `roster.manage` que alguien pondría en un sitio y se olvidaría en otro.
     */
    private function rosterForm(int $id, string $action): ?array
    {
        $form = Form::find($id);
        $user = Auth::user();

        if ($form === null) {
            Response::notFound();

            return null;
        }
        if ($user === null) {
            Response::redirect('/login');

            return null;
        }
        if (!Access::canEdit((int) $form['user_id'], (int) $user['id'])) {
            SecurityLog::record('authz.denied', $action . ' acceso /forms/' . $id . '/participants');
            Response::forbidden();

            return null;
        }
        if (FormAccess::mode(Form::settings($id)) !== FormSettings::ACCESS_ROSTER) {
            // 404 y no 403: desde fuera, que un formulario tenga o no tenga listado
            // no es información que deba darse a quien solo puede verlo.
            Response::notFound();

            return null;
        }

        return $form;
    }

    private function csrfOk(int $id, string $clave): bool
    {
        if (Csrf::verifyRequest()) {
            return true;
        }

        $mensaje = I18n::t($clave, I18n::forRequest(Form::settings($id)));
        if (self::wantsJson()) {
            Response::json(['ok' => false, 'error' => $mensaje], 419);
        } else {
            Session::flash('error', $mensaje);
            Response::redirect('/forms/' . $id . '#participantes');
        }

        return false;
    }

    /**
     * Los atributos del POST, **solo** los que están en el catálogo.
     *
     * Que se filtren aquí y no en el modelo es lo que hace que un POST con
     * `data[nombre] = algo` no cree una columna nueva: `FormParticipant::validarValores()`
     * vuelve a comprobarlo (una cosa y la otra, porque el modelo se llama también
     * desde la importación), pero el filtro de aquí evita el viaje y da el motivo
     * bueno.
     *
     * @return array<string,string>
     */
    private function atributosDelPost(int $id): array
    {
        $entrada = Request::post('data', []);
        if (!is_array($entrada)) {
            return [];
        }

        $permitidas = [];
        foreach (FormParticipant::catalogFor($id) as $campo) {
            $permitidas[(string) $campo['key_name']] = true;
        }

        $salida = [];
        foreach ($entrada as $clave => $valor) {
            if (isset($permitidas[(string) $clave])) {
                $salida[(string) $clave] = is_scalar($valor) ? (string) $valor : '';
            }
        }

        return $salida;
    }

    /**
     * Traduce un motivo del modelo o del importador a un mensaje.
     *
     * El motivo se mira por **valor** y no por presencia, y se anota en el log:
     * un `ok:false` sin explicación es el peor resultado posible porque parece un
     * fallo del servidor y hace que se vuelva a intentar lo mismo tres veces.
     */
    /**
     * El identificador viaja al mensaje a propósito: «ya hay alguien con ese
     * identificador» obliga a abrir la lista a buscar a quién se refiere, y el
     * que está escribiendo ya lo tiene delante en el campo. Es el mismo dato que
     * se acaba de escribir y por eso se lee del POST, no de la fila.
     */
    private function motivoDeFallo(int $id, string $motivo, string $claveOk, string $valor = ''): void
    {
        $locale = I18n::forRequest(Form::settings($id));

        $mensaje = match ($motivo) {
            FormParticipant::MOTIVO_DUPLICADO => I18n::t('roster.err_duplicado', $locale, ['id' => $valor]),
            FormParticipant::MOTIVO_VACIO     => I18n::t('roster.err_vacio', $locale),
            FormParticipant::MOTIVO_LARGO     => I18n::t('roster.err_largo', $locale),
            FormParticipant::MOTIVO_CORREO    => I18n::t('roster.err_correo', $locale),
            FormParticipant::MOTIVO_ATRIBUTO  => I18n::t('roster.err_atributo', $locale),
            FormParticipant::MOTIVO_VALOR_LARGO => I18n::t('roster.err_valor_largo', $locale),
            'no_existe'                       => I18n::t('roster.not_found', $locale),
            default                           => I18n::t('roster.unknown_error', $locale),
        };

        SecurityLog::record('roster.rejected', $motivo . ' en /forms/' . $id . ' (' . $claveOk . ')');

        $this->ok($id, $mensaje, false);
    }

    private function ok(int $id, string $mensaje, bool $exito = true, array $extra = []): void
    {
        if (self::wantsJson()) {
            Response::json(['ok' => $exito, 'message' => $mensaje] + $extra);

            return;
        }

        Session::flash($exito ? 'success' : 'error', $mensaje);
        Response::redirect('/forms/' . $id . '#participantes');
    }

    private static function wantsJson(): bool
    {
        return Response::wantsJson();
    }

    /**
     * El motivo del importador, en una frase.
     *
     * Los códigos cortos (`roster_sin_filas`, `identificador_duplicado`…) son para
     * leerlos en un log, no para enseñarlos: quien acaba de subir un archivo no
     * puede saber qué es `roster_vacia`. Se traducen todos aquí y no hay ningún
     * `default` que se coma un motivo nuevo sin que nadie se entere.
     */
    /**
     * El campo que identifica depende del formulario (`cedula`, `indicador` o
     * `correo`); `COLUMNA_IDENTIFICADOR` es el mapa que ya usa el modelo.
     */
    private function valorIdentificador(string $identificador, array $campos): string
    {
        $columna = FormParticipant::COLUMNA_IDENTIFICADOR[$identificador] ?? FormParticipant::COLUMNA_IDENTIFICADOR['cedula'];

        return trim((string) ($campos[$columna] ?? ''));
    }

    private static function textoError(string $motivo, string $locale, string $columna): string
    {
        return match ($motivo) {
            'modo_invalido'               => I18n::t('roster.err_modo', $locale),
            'identificador_invalido'      => I18n::t('roster.err_identificador', $locale),
            'roster_sin_zip'              => I18n::t('roster.sin_zip', $locale),
            'roster_sin_filas'            => I18n::t('roster.err_sin_filas', $locale),
            'roster_no_zip'               => I18n::t('roster.err_no_zip', $locale),
            'roster_demasiadas_filas'     => I18n::t('roster.err_demasiadas', $locale),
            'roster_sin_hoja'             => I18n::t('roster.err_ilegible', $locale),
            'roster_hoja_vacia'           => I18n::t('roster.err_ilegible', $locale),
            'roster_zip_bomb'             => I18n::t('roster.err_zip_bomb', $locale),
            'roster_entidades'            => I18n::t('roster.err_entidades', $locale),
            'falta_columna_identificador' => I18n::t('roster.err_falta_columna', $locale, [
                'id' => self::nombreIdentificador($columna, $locale),
            ]),
            'reemplazar_con_errores'      => I18n::t('roster.err_reemplazar', $locale),
            'atributo_no_creable'         => I18n::t('roster.err_atributo', $locale),
            default                       => I18n::t('roster.err_ilegible', $locale),
        };
    }

    /**
     * El nombre del campo que identifica a la gente, en el idioma del formulario.
     *
     * Vive en i18n y no en un array del controlador porque la misma etiqueta sale
     * en el error de la importación, en la cabecera de la columna y en el texto de
     * ayuda de la pestaña; tres sitios con un array propio se quedan desfasados en
     * cuanto se traduce.
     */
    private static function nombreIdentificador(string $tipo, string $locale): string
    {
        return match ($tipo) {
            'indicador' => I18n::t('roster.id_indicador', $locale),
            'correo'    => I18n::t('roster.id_correo', $locale),
            default     => I18n::t('roster.id_cedula', $locale),
        };
    }

    /** @param array<string,mixed> $r */
    private static function textoResumen(array $r, string $locale): string
    {
        $texto = I18n::t('roster.imported', $locale, [
            'nuevos' => $r['insertados'],
            'total'  => $r['total'],
        ]);

        if ($r['modo'] === ParticipantImporter::MODO_FUSIONAR) {
            $texto .= ' ' . I18n::t('roster.imported_merge', $locale, [
                'cambios' => $r['actualizados'],
                'iguales' => $r['sin_cambios'],
            ]);
        }
        if ($r['omitidos'] > 0) {
            $texto .= ' ' . I18n::t('roster.imported_skipped', $locale, ['n' => $r['omitidos']]);
        }
        if ($r['atributos_nuevos'] !== []) {
            $texto .= ' ' . I18n::t('roster.imported_attrs', $locale, [
                'n'    => count($r['atributos_nuevos']),
                'lista' => implode(', ', $r['atributos_nuevos']),
            ]);
        }

        return $texto;
    }
}