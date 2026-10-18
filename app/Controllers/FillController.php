<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Clock;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Access;
use App\Core\I18n;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\Answer;
use App\Models\Field;
use App\Models\Form;
use App\Models\FormAccess;
use App\Models\FormParticipant;
use App\Models\Submission;
use App\Services\BuilderDraftMapper;
use App\Services\FormSettings;
use App\Services\LogicEvaluator;
use App\Services\Notifier;
use App\Services\QuizScore;
use App\Services\RedirectGuard;
use App\Services\RosterDoor;
use App\Services\Turnstile;
use App\Services\Uploader;
use App\Services\Webhook;

class FillController
{
    public function show(string $slug): void
    {
        $form = Form::findBySlug($slug);

        if ($form === null) {
            Response::notFound();
            return;
        }

        if ($form['status'] === 'closed') {
            $locale = I18n::forRequest(Form::settings((int) $form['id']));
            View::render('fill/closed', [
                'form' => $form,
                'locale' => $locale,
                'heading' => I18n::t('status.closed_title', $locale),
                'note' => I18n::t('status.closed_note', $locale),
            ], null);
            return;
        }

        if ($form['status'] === 'draft' || $form['status'] === 'approved') {
            // F3 — Un formulario aprobado pero SIN publicar tampoco se sirve:
            // aprobar es «esto se puede publicar», no «esto ya está». De cara al
            // visitante da igual que sea un borrador o un aprobado: el dueño aún
            // no lo ha sacado, y la nota del borrador («todavía no ha sido
            // publicado por su propietario») lo dice exactamente.
            $locale = I18n::forRequest(Form::settings((int) $form['id']));
            View::render('fill/draft', ['form' => $form, 'locale' => $locale], null);
            return;
        }

        // F3 — En revisión se responde con una página informativa, NO con un 404:
        // quien tiene el enlace (se lo enviaron por correo) tiene que entender
        // que el formulario existe y que no está listo, en vez de pensar que el
        // enlace está roto o que le han timado.
        if ($form['status'] === 'in_review') {
            $locale = I18n::forRequest(Form::settings((int) $form['id']));
            View::render('fill/in_review', ['form' => $form, 'locale' => $locale], null);
            return;
        }

        $settings = Form::settings((int) $form['id']);

        // F4 — primero se identifica, después se informa de si está abierto.
        $accessToken = null;
        $participantId = null;
        if (FormAccess::isRestricted($settings)) {
            [$granted, $accessToken, $participantId] = self::resolveAccess($form, $settings);
            if (!$granted) {
                $this->renderAccessPage($form, $settings, false);
                return;
            }
        }

        // P6 — Disponibilidad por calendario/cupo antes de mostrar el formulario.
        if ($this->renderAvailabilityGate($form, $settings)) {
            return;
        }

        // P8 — Randomización solo en GET (ver/embed): barajar unidades por
        // página y, por campo, las opciones. En el re-render del POST con
        // errores NO se baraja (los campos no se reordenan bajo los dedos).
        $form['fields'] = self::randomizeFields($form['fields'], $settings);

        $this->renderFill($form, $settings, [], false, $accessToken);
    }

    /**
     * Página embebible (P7): mismo formulario que /f/{slug} pero solo si
     * settings.embed.enabled. Relaja X-Frame-Options y frame-ancestors para
     * poder estar en un iframe del mismo origen (SAMEORIGIN).
     */
    public function embed(string $slug): void
    {
        $form = Form::findBySlug($slug);

        if ($form === null) {
            Response::notFound();
            return;
        }

        $settings = Form::settings((int) $form['id']);
        if (empty($settings['embed']['enabled'])) {
            Response::abort(403, 'Este formulario no permite ser embebido.');
            return;
        }

        if ($form['status'] === 'closed') {
            View::render('fill/closed', ['form' => $form, 'locale' => I18n::forRequest($settings)], null);
            return;
        }
        if ($form['status'] === 'draft' || $form['status'] === 'approved') {
            // F3 — mismo criterio que en show(): el embed tampoco sirve un
            // formulario aprobado al que todavía le falta publicarse.
            View::render('fill/draft', ['form' => $form, 'locale' => I18n::forRequest($settings)], null);
            return;
        }
        // F3 — mismo criterio que en show(): el embed tampoco sirve un formulario
        // en revisión.
        if ($form['status'] === 'in_review') {
            View::render('fill/in_review', ['form' => $form, 'locale' => I18n::forRequest($settings)], null);
            return;
        }
        $accessToken = null;
        $participantId = null;
        if (FormAccess::isRestricted($settings)) {
            [$granted, $accessToken, $participantId] = self::resolveAccess($form, $settings);
            if (!$granted) {
                $this->renderAccessPage($form, $settings, true);
                return;
            }
        }
        if ($this->renderAvailabilityGate($form, $settings)) {
            return;
        }

        // P8 — Barajar también en el embed (mismo comportamiento que show).
        $form['fields'] = self::randomizeFields($form['fields'], $settings);
        $this->renderFill($form, $settings, [], true, $accessToken);
    }

    /**
     * Vista previa REAL (modelo Elementor) — GET.
     *
     * Renderiza el `fill.js` completo (condiciones, multi-paso, tema, validación
     * en cliente) sin persistir nada. La usa el dueño desde el builder (con
     * `?draft=1` para ver sus cambios aún no guardados) y el revisor desde la
     * bandeja (sin `?draft=1`: siempre ve la estructura viva, que es la que se
     * sometió a revisión). Los gates de fill (cerrado/borrador/revisión/acceso/
     * disponibilidad/Turnstile) NO aplican: aquí lo que se prueba es el formulario
     * tal cual saldría, no si está abierto al público.
     */
    public function preview(int $id): void
    {
        $form = Form::find($id, false);
        if ($form === null) {
            Response::notFound();
            return;
        }
        $user = Auth::user();
        if ($user === null) {
            Response::redirect('/login');
            return;
        }

        $canEdit = Access::canEdit((int) $form['user_id']);
        $puedeRevisar = Auth::can('forms.review') && $form['status'] === 'in_review';
        if (!$canEdit && !$puedeRevisar) {
            Response::forbidden();
            return;
        }

        $wantDraft = isset($_GET['draft']) && $_GET['draft'] === '1';
        // El borrador es del DUEÑO: un revisor no tiene nada que ver ahí.
        if ($wantDraft && !$canEdit) {
            Response::forbidden();
            return;
        }

        $settings = Form::settings((int) $form['id']);
        $draft = $wantDraft ? Form::draft((int) $form['id']) : null;
        if ($draft !== null) {
            $form['fields'] = BuilderDraftMapper::toFillFields($draft);
            // El tema/ajustes del borrador pisan los live: el dueño acaba de
            // moverlos en el builder y la preview tiene que reflejarlo.
            $draftSettings = is_array($draft['settings'] ?? null) ? $draft['settings'] : [];
            $settings = FormSettings::normalize(array_replace_recursive($settings, $draftSettings));
        } else {
            $form['fields'] = Field::byForm((int) $form['id']);
        }

        $this->renderPreviewFill($form, $settings, '/forms/' . $id . '/preview');
    }

    /**
     * Vista previa REAL — POST (el visitante de prueba pulsa «Enviar»).
     *
     * Corre la MISMA validación que el fill de verdad (server + cliente) y
     * re-renderiza con los errores tal cual haría el submit real, pero NUNCA
     * persiste: no crea `Submission`, no dispara webhooks ni `Notifier`, no cuenta
     * rate limit, no aplica Turnstile ni honeypot. Es la mitad que hace que la
     * preview se pueda probar sin ensuciar la BD.
     */
    public function previewSubmit(int $id): void
    {
        $form = Form::find($id, false);
        if ($form === null) {
            Response::notFound();
            return;
        }
        $user = Auth::user();
        if ($user === null) {
            Response::redirect('/login');
            return;
        }

        $canEdit = Access::canEdit((int) $form['user_id']);
        $puedeRevisar = Auth::can('forms.review') && $form['status'] === 'in_review';
        if (!$canEdit && !$puedeRevisar) {
            Response::forbidden();
            return;
        }

        $settings = Form::settings((int) $form['id']);
        // El POST de la preview vive del mismo borrador que el GET: el form
        // apunta a `/forms/{id}/preview` (sin query), así que aquí se decide por
        // el borrador si existe y quien puede editar (mismo criterio que el GET).
        $draft = $canEdit ? Form::draft((int) $form['id']) : null;
        if ($draft !== null) {
            $form['fields'] = BuilderDraftMapper::toFillFields($draft);
            $draftSettings = is_array($draft['settings'] ?? null) ? $draft['settings'] : [];
            $settings = FormSettings::normalize(array_replace_recursive($settings, $draftSettings));
        } else {
            $form['fields'] = Field::byForm((int) $form['id']);
        }

        $locale = I18n::forRequest($settings);
        // MISMA validación que el fill de verdad (núcleo compartido), pero sin
        // persistir: los archivos que se hubieran subido a disco se revierten
        // siempre, porque una preview no debe dejar binarios huérfanos.
        $vr = $this->runValidation($form['fields'], $locale);
        Uploader::rollback($vr['storedFiles']);
        if ($vr['errors'] !== []) {
            $this->renderPreviewFill($form, $settings, '/forms/' . $id . '/preview', Request::all(), $vr['errors']);
            return;
        }

        // Todo válido: no hay nada que guardar. Se re-renderiza limpio con la
        // confirmación de que se probó bien, para que el «visitante» vea el
        // resultado del submit sin que la BD se entere.
        $this->renderPreviewFill($form, $settings, '/forms/' . $id . '/preview', [], []);
    }

    /**
     * Traduce el payload del builder (borrador) a los campos que entiende el
     * fill. El payload referencia por `_uid` (tanto `parent_id` como las reglas
     * de `logic.field_id`), y el fill trabaja con ids numéricos: se asignan ids
     * sintéticos 1..N en orden de lectura y se reescriben todas las referencias.
     *
     * @deprecated Usa \App\Services\BuilderDraftMapper::toFillFields()
     */
    public static function mapDraftToFields(array $payload): array
    {
        return BuilderDraftMapper::toFillFields($payload);
    }

    /**
     * Render del fill en modo preview: mismo `fill/show`, con el `action` del
     * form apuntando a la ruta de preview y sin Turnstile (aquí no se sirve al
     * público, no hay nada que frenar).
     */
    private function renderPreviewFill(array $form, array $settings, string $previewAction, array $old = [], array $errors = []): void
    {
        $this->emitFillCsp($settings, false);

        View::render('fill/show', [
            'form'      => $form,
            'fields'    => $form['fields'],
            'csrf'      => Csrf::token(),
            'settings'  => $settings,
            'old'       => $old,
            'turnstile' => ['enabled' => false],
            'hidden'    => self::initialVisibility($form['fields'], $old),
            'accessToken' => null,
            'previewAction' => $previewAction,
            'errors'    => $errors,
        ], null);
    }

    /**
     * Render del formulario compartido por show() y embed(). El head aplica el
     * tema (themeCss) y el form incluye el honeypot cuando el setting lo exige.
     *
     * @param array $old Valores previos (re-render de error).
     * @param bool  $embed Emite X-Frame-Options SAMEORIGIN y relaja
     *   frame-ancestors (solo para /f/{slug}/embed).
     * @param ?string $accessToken F4: token de acceso que se propaga al
     *   `action` del formulario para que el POST vuelva a presentarlo.
     */
    private function renderFill(array $form, array $settings, array $old = [], bool $embed = false, ?string $accessToken = null): void
    {
        // B2 — La CSP de ESTA respuesta depende del tipo de página: embed
        // (frame-ancestors 'self') y Turnstile (script-src/frame-src del widget).
        $this->emitFillCsp($settings, $embed);

        View::render('fill/show', [
            'form'     => $form,
            'fields'   => $form['fields'],
            'csrf'     => Csrf::token(),
            'settings' => $settings,
            'old'      => $old,
            'turnstile' => self::turnstileData($settings),
            // Gap sin JS: el render ya sale con la visibilidad resuelta.
            'hidden'   => self::initialVisibility($form['fields'], $old),
            'accessToken' => $accessToken,
        ], null);
    }

    /**
     * B2 — Datos para pintar el widget. Solo se pinta sobre un formulario que
     * lo pide y cuando la instancia tiene claves (las sin claves ya quedaron
     * bloqueadas en renderAvailabilityGate).
     */
    private static function turnstileData(array $settings): array
    {
        if (empty($settings['anti_bot']['enabled']) || !Turnstile::isConfigured()) {
            return ['enabled' => false];
        }
        return [
            'enabled'   => true,
            'site_key'  => Turnstile::publicSiteKey(),
        ];
    }

    /**
     * Re-render del formulario con errores (validación o verificación bot).
     * Comparte el data de renderFill pero con `errors` y con las respuestas
     * previas como fuentes de visibilidad (lo que vería el participante).
     * @param ?string $accessToken F4: token de acceso a propagar al `action`.
     */
    private function renderFillErrors(array $form, array $settings, array $errors, ?string $accessToken = null): void
    {
        $this->emitFillCsp($settings, false);
        $old = Request::all();
        View::render('fill/show', [
            'form'     => $form,
            'fields'   => $form['fields'],
            'csrf'     => Csrf::token(),
            'errors'   => $errors,
            'old'      => $old,
            'turnstile' => self::turnstileData($settings),
            'hidden'   => self::initialVisibility($form['fields'], $old),
            'accessToken' => $accessToken,
        ], null);
    }

    /**
     * P8 — Randomización SÓLO de render (nunca del submit). Dos niveles:
     *
     *  1. settings.randomize.questions → baraja las UNIDADES de preguntas
     *     (una avanzada con su sección y descendientes, o una pregunta suelta)
     *     dentro de cada segmento delimitado por los campos «page», sin cruzar
     *     los saltos de paso. Las secciones se mueven con sus hijos.
     *  2. validations.randomize (por campo) → baraja las OPCIONES de
     *     select/radio/checkbox conservando cada par value/label.
     *
     * Las respuestas se guardan por field_id, así que el orden de render no
     * afecta al persistir ni a las condiciones.
     *
     * @param array $fields Lista de campos (Field::byForm).
     * @return array Misma lista, reordenada/barajada a la vista.
     */
    public static function randomizeFields(array $fields, array $settings): array
    {
        $out = $fields;

        if (!empty($settings['randomize']['questions'])) {
            $out = self::shuffleQuestionUnits($out);
        }

        foreach ($out as $i => $f) {
            $id = (int) ($f['id'] ?? 0);
            if ($id <= 0 || !is_array($f['validations'] ?? null)) {
                continue;
            }
            if (in_array(($f['type'] ?? ''), ['select', 'radio', 'checkbox'], true)
                && !empty($f['validations']['randomize'])
                && is_array($f['options'] ?? null)
                && count($f['options']) > 1) {
                $out[$i]['options'] = self::shufflePairs($f['options']);
            }
        }

        return $out;
    }

    /**
     * Baraja las unidades top-level dentro de cada segmento de página
     * (el segmento implícito inicial + uno por cada campo «page»).
     *
     * @return array la lista de campos reordenada (mismo total y mismos ids)
     */
    private static function shuffleQuestionUnits(array $fields): array
    {
        $fieldIds = [];
        foreach ($fields as $f) {
            $id = (int) ($f['id'] ?? 0);
            if ($id > 0) {
                $fieldIds[$id] = true;
            }
        }

        // Segmentos: [0] = campos previos al primer «page», luego uno por cada
        // «page» (que lo abre y lo ancla). Misma pertenencia que pagePlan().
        $segments = [[]];
        foreach ($fields as $f) {
            if (($f['type'] ?? '') === 'page') {
                $segments[] = [];
            }
            $segments[count($segments) - 1][] = $f;
        }

        $out = [];
        foreach ($segments as $segment) {
            // Unidades: un campo raíz (sin padre dentro del form) y su subtree.
            $units = [];
            foreach ($segment as $f) {
                $pid = (int) ($f['parent_id'] ?? 0);
                $isRoot = !($pid > 0 && isset($fieldIds[$pid]));
                if ($isRoot) {
                    $units[] = [$f];
                    continue;
                }
                // Hijo: se agrega a la última unidad (su padre le precede).
                $units[count($units) - 1][] = $f;
            }

            // Barajar las unidades; el «page» que abre el segmento queda fijo en
            // primera posición (el orden de los pasos no cambia).
            $units = array_values($units);
            $anchor = null;
            if (count($units) > 1 && ($units[0][0]['type'] ?? '') === 'page') {
                $anchor = array_shift($units);
            }
            shuffle($units);
            if ($anchor !== null) {
                array_unshift($units, $anchor);
            }

            foreach ($units as $unit) {
                foreach ($unit as $f) {
                    $out[] = $f;
                }
            }
        }

        return $out;
    }

    /**
     * Baraja un array de opciones conservando el par value/label de cada una
     * (sin reindexar claves numéricas).
     */
    private static function shufflePairs(array $options): array
    {
        if (count($options) < 2) {
            return $options;
        }
        $keys = array_keys($options);
        shuffle($keys);
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $options[$k];
        }
        return $out;
    }

    private function renderAvailabilityGate(array $form, array $settings): bool
    {
        $locale = I18n::forRequest($settings);

        // B2 — Antes de nada, el anti-bot: si el form lo pide y la instancia no
        // tiene claves Turnstile, el formulario NO se muestra (protegido a
        // medias es lo mismo que no protegido, pero con aviso).
        if (!empty($settings['anti_bot']['enabled']) && !Turnstile::isConfigured()) {
            View::render('fill/closed', [
                'form' => $form,
                'locale' => $locale,
                'heading' => I18n::t('status.misconfigured_title', $locale),
                'note' => I18n::t('status.misconfigured_note', $locale),
            ], null);
            return true;
        }

        $state = self::scheduleState($settings);
        if ($state === 'closed') {
            View::render('fill/closed', [
                'form' => $form,
                'locale' => $locale,
                'heading' => I18n::t('status.closed_title', $locale),
                'note' => I18n::t('status.closed_note', $locale),
            ], null);
            return true;
        }
        if ($state === 'not_open') {
            View::render('fill/closed', [
                'form' => $form,
                'locale' => $locale,
                'heading' => I18n::t('status.not_open_title', $locale),
                'note' => I18n::t('status.not_open_note', $locale),
            ], null);
            return true;
        }
        if (self::quotaExceeded($form)) {
            View::render('fill/full', ['form' => $form, 'locale' => $locale], null);
            return true;
        }
        return false;
    }

    /**
     * Fase 4 — `POST /f/{slug}/code`: canjea un código personal por acceso.
     *
     * ## Por qué esta puerta existe aunque el enlace ya lleve el código
     *
     * Porque el enlace se equivoca: se copia a mano, se parte al pegar en un
     * correo y a veces se teclea. Sin esta página, un código con un carácter mal
     * deja al visitante en una pantalla de la que no puede salir —la puerta no se
     * abre «a medias» porque no hay forma de decir cuál de los 16 caracteres
     * falló—, y su salida sería escribirle al dueño.
     *
     * ## El rate limit va ANTES de buscar el código, y cuenta los fallidos
     *
     * Es lo que hace de esto una puerta y no un campo de texto. Cuenta por IP y por
     * formulario, y también por código, para que el mismo no se pueda probar desde
     * mil máquinas. **Un intento fallido cuenta igual que uno bueno**: si solo
     * sumaran los válidos, enumerar códigos sería gratis y esto sería un adorno.
     *
     * ## La respuesta es uniforme por CREDENCIAL
     *
     * Un código inexistente, uno de otro formulario y uno con un carácter mal se
     * contestan igual. Distinguirlos convierte esta página en un oráculo de qué
     * códigos existen, que es justo lo que el rate limit intenta que no se pueda
     * averiguar.
     *
     * Lo que SÍ cambia es el texto entre credenciales, y es a propósito: la página
     * pide un campo concreto (`access.identifier`), así que contestar «ese código
     * no es válido» a quien escribió su cédula no le dice nada de lo que tiene que
     * corregir. La elección es por **forma** y no por qué haya fallado: lo que
     * parece código (los 16 caracteres del alfabeto del generador) se contesta con
     * `access.invalid_codigo` exista o no, y lo demás con el mensaje del
     * identificador que el formulario pide. Una forma no es información de la
     * lista: cualquiera puede ver el alfabeto de un código.
     *
     * ## Y es un PRG
     *
     * Un código válido redirige a `/f/{slug}?t=<código>`: la credencial queda en la
     * URL, que es lo que la puerta del GET entiende, y un refresco no vuelve a
     * canjear nada. Entras por la cédula y sales con la URL del código, que es la
     * credencial con la que se propaga el resto del formulario.
     */
    public function verifyCode(string $slug): void
    {
        $form = Form::findBySlug($slug);

        if ($form === null) {
            Response::notFound();
            return;
        }

        $settings = Form::settings((int) $form['id']);
        $locale = I18n::forRequest($settings);

        // 404 y no 403, como en `/f/{slug}/access`: que un formulario tenga o no
        // puerta de código no es información que deba darse a quien solo lo ve.
        if (FormAccess::mode($settings) !== FormSettings::ACCESS_ROSTER) {
            Response::notFound();
            return;
        }

        $formId = (int) $form['id'];
        $ip = RosterDoor::ip();
        $codigo = (string) Request::post('code', '');
        $volver = '/f/' . rawurlencode((string) $form['slug']);

        if (!RosterDoor::permitirIntento($formId, $codigo, $ip)) {
            Session::flash('error', I18n::t('access.code_blocked', $locale));
            Response::redirect($volver);
            return;
        }

        $participante = RosterDoor::buscarAmbos($form, $codigo);
        if ($participante === null) {
            // Forma antes que motivo: lo que parece código se contesta como código
            // exista o no (uniformidad del oráculo), y lo demás como el campo que
            // esta página pide (`access.identifier`).
            $identificador = FormParticipant::identifierOf($settings);
            $esCodigo = $identificador === 'codigo'
                || FormParticipant::codeValido(RosterDoor::normalizar($codigo));
            $msg = RosterDoor::lastMotivo() === 'used'
                ? I18n::t('access.code_used', $locale)
                : I18n::t($esCodigo ? 'access.invalid_codigo' : 'access.invalid_' . $identificador, $locale);
            Session::flash('error', $msg);
            Response::redirect($volver);
            return;
        }

        RosterDoor::grant($formId, (int) $participante['id']);

        Response::redirect($volver . '?t=' . rawurlencode((string) $participante['code']));
    }

    /**
     * F4 — Pinta la página de identificación (`views/fill/access.php`).
     *
     * Va DESPUÉS de los cortes por estado y ANTES del gate de disponibilidad, en
     * ese orden por dos razones: a un formulario en borrador, cerrado o en
     * revisión no tiene sentido pedirle un correo (no hay nada que rellenar), y
     * el calendario y el cupo cuentan datos del formulario («abre el martes»,
     * «cupo lleno») que a un desconocido no hay por qué revelarle. Se identifica
     * uno y después se le dice si está abierto.
     */
    private function renderAccessPage(array $form, array $settings, bool $embed): void
    {
        // Los flashes se LEEN aquí (y por eso se consumen) en los dos caminos: si
        // el visitante ya tuviera acceso no se pinta esta página, y un flash sin
        // consumir aparecería más tarde en otra pantalla.
        $sent = Session::getFlash('success');
        $error = Session::getFlash('error');

        View::render('fill/access', [
            'form'     => $form,
            'locale'   => I18n::forRequest($settings),
            'csrf'     => Csrf::token(),
            'mode'     => FormAccess::mode($settings),
            'embed'    => $embed,
            // El identificador es lo que la página tiene que PEDIR: el texto de la
            // rama `roster` existe por los cuatro, y sin pasarlo la vista tendría
            // que adivinarlo (o peor, preguntar «código o identificador»).
            'identifier' => FormParticipant::identifierOf($settings),
            'sent'     => $sent !== '',
            'sentMessage' => $sent,
            'errorMessage' => $error !== '' ? $error : null,
        ], null);
    }

    /**
     * ¿Está autorizado este visitante a ver y enviar ESTE formulario? ¿Y con qué
     * token lo ha hecho?
     *
     * El token que vuelve a propósito es el que llegó en la query: se propaga al
     * `action` del formulario para que el POST también lo lleve. La sesión sola
     * no basta —sin cookies el GET se sirve igual (el token va en la URL) y el
     * POST se caería con un 403 que no tiene explicación— y dentro de un iframe
     * la cookie es de terceros y muchos navegadores la bloquean.
     *
     * Los `$settings` llegan como argumento y no se vuelven a pedir: `mode()` con
     * ellos es una lectura de la fila de `forms` en cada petición que trae `?t=`.
     *
     * El tercer valor es el **participante** que abrió la puerta, y solo se rellena
     * en modo `roster`: es lo que se guarda en `submissions.participant_id`, y por
     * eso no puede ser «alguien de la sesión sin comprobar», sino el que la puerta
     * acaba de resolver en ESTA petición.
     *
     * @return array{0: bool, 1: ?string, 2: ?int} [autorizado, credencial en claro si vino por query, id del participante]
     */
    private static function resolveAccess(array $form, array $settings): array
    {
        $formId = (int) $form['id'];

        $token = trim((string) Request::query('t', ''));
        if ($token !== '') {
            $row = FormAccess::findValidToken($formId, $token);
            if ($row !== null) {
                // El permiso de sesión caduca CON el token y, además, se
                // revalida contra la fila en cada request (ver
                // `sessionAccessValid`): la caducidad sola dejaría vivo al que
                // ya había pasado por un enlace que después se revoca.
                self::grantSessionAccess($formId, (int) $row['id'], (string) $row['expires_at']);

                return [true, $token, null];
            }

            // El token de F4 no era. En un formulario de lista la credencial es el
            // código personal, y se busca **después** a propósito: un `?t=` que
            // casa con `form_access_tokens` sigue siendo ese token, y los dos tipos
            // de fila no se buscan en la misma tabla.
            if (FormAccess::mode($settings) === FormSettings::ACCESS_ROSTER) {
                $participante = RosterDoor::buscarAmbos($form, $token);
                if ($participante !== null) {
                    RosterDoor::grant($formId, (int) $participante['id']);
                    return [true, $token, (int) $participante['id']];
                }
            }
        }

        // Sin credencial en la URL solo puede salvar la sesión, y ahí lo que se
        // revalida es la fila del permiso: en `roster`, que el participante siga en
        // la lista; en los otros modos restringidos, que su token siga vivo.
        $grant = self::sessionGrant($formId);

        // El tercer valor es el `id` del participante o `null`, no el grant entero:
        // lo que viaja a `submissions.participant_id` es un entero, y un array ahí
        // revienta la persistencia con un `TypeError` que no dice nada de accesos.
        return [$grant !== null, null, $grant['participant'] ?? null];
    }

    /**
     * Fija el permiso de sesión con la caducidad del token que lo origina.
     *
     * Se guarda el **id de la fila**, no el token: la sesión no debe contener
     * ninguna credencial, solo el puntero a lo que ya autorizó una vez. Y el id
     * no sirve para volver a entrar por la URL, que es lo que allows.
     */
    private static function grantSessionAccess(int $formId, int $tokenId, string $expiresAt): void
    {
        $grants = Session::get('vf_form_access', []);
        if (!is_array($grants)) {
            $grants = [];
        }

        // Poda de lo caducado al escribir, para que `$_SESSION` no crezca con
        // cada formulario restringido que visita alguien.
        RosterDoor::podar($grants);

        $grants[$formId] = ['id' => $tokenId, 'exp' => self::toUnix($expiresAt)];
        Session::set('vf_form_access', $grants);
    }

    /**
     * La sesión da acceso, pero solo mientras su fila siga viva.
     *
     * El `form_id` va en la consulta aunque la fila se buscara por id: una fila de
     * token es de un formulario, y un permiso de sesión que no lo comprobara
     * aceptaría un id de token de otro formulario como si fuera suyo.
     */
    /**
     * El permiso de sesión de este formulario, si sigue vivo.
     *
     * Devuelve `null` cuando no hay acceso, y un array cuando sí: `['participant' => id]`
     * en `roster` y `['participant' => null]` con un token de F4. **No devuelve el id
     * como valor de retorno**, porque para un token de `email`/`allowlist` no hay
     * ninguno y `null` significaría a la vez «sin acceso» y «acceso de otro tipo»:
     * un valor que significa dos cosas en el mismo `return` es un `403` a la
     * puerta de F4 en cuanto alguien lo lea mal.
     *
     * @return array{participant:?int}|null
     */
    private static function sessionGrant(int $formId): ?array
    {
        $grants = Session::get('vf_form_access', []);
        if (!is_array($grants) || !isset($grants[$formId]) || !is_array($grants[$formId])) {
            return null;
        }

        $grant = $grants[$formId];

        // Un permiso `roster` no caduca (`exp = 0`): lo que lo cierra es que el
        // participante salga de la lista, y eso se comprueba en la fila, no en un
        // reloj. El `exp` a 0 se trata antes que la caducidad porque comparar
        // `0 <= time()` como si fuera una fecha tira la puerta en cada petición.
        if (($grant['kind'] ?? '') === 'participant') {
            $participantId = (int) ($grant['id'] ?? 0);
            if (!RosterDoor::sigueEnLaLista($formId, $participantId)) {
                unset($grants[$formId]);
                Session::set('vf_form_access', $grants);

                return null;
            }

            return ['participant' => $participantId];
        }

        if ((int) ($grant['exp'] ?? 0) <= time()) {
            unset($grants[$formId]);
            Session::set('vf_form_access', $grants);

            return null;
        }

        if (!FormAccess::tokenIsLive($formId, (int) ($grant['id'] ?? 0))) {
            unset($grants[$formId]);
            Session::set('vf_form_access', $grants);

            return null;
        }

        return ['participant' => null];
    }

    /**
     * Convierte el DATETIME UTC de la BD (`expires_at`) en un instante.
     *
     * El sufijo ` UTC` es explícito A PROPÓSITO: `expires_at` lo escribe
     * `gmdate()` y la sesión de MariaDB está en `+00:00`, así que el valor es
     * UTC aunque el reloj de la máquina no lo esté. Sin el sufijo, `strtotime()`
     * lo interpretaría en `app.timezone` y la sesión duraría unas horas de más o
     * menos según dónde esté el servidor — justo el error que solo se nota en la
     * aritmética, y aquí la aritmética ES la autorización.
     */
    private static function toUnix(string $utcDateTime): int
    {
        $ts = strtotime($utcDateTime . ' UTC');

        return $ts === false ? 0 : $ts;
    }

    /**
     * FASE 1 — Validación sin persistir (núcleo compartido por `submit()` y la
     * preview).
     *
     * Recorre los campos en ORDEN TOPOLÓGICO, resuelve omisión de páginas y
     * visibilidad, y devuelve los errores junto con los valores ya validados.
     * NO toca la BD; lo único que escribe es `Uploader` para los campos de
     * archivo (binarios a disco), y sus metas se devuelven en `storedFiles` para
     * que el llamante haga `rollback` si no persiste (preview) o si el resto del
     * envío resulta inválido.
     *
     * @return array{errors: array, validated: array, answers: array,
     *   storedFiles: array, order: array, byId: array, plan: array,
     *   visible: array, skippedPages: array}
     */
    private function runValidation(array $fields, string $locale): array
    {
        $plan = self::pagePlan($fields);

        // P8 — Salta-página: cada pregunta depende del campo «page» que abre su
        // bloque. Así, en el orden topológico la página se procesa ANTES que sus
        // preguntas y su condición ya está resuelta cuando se valida el bloque.
        $extraDeps = [];
        foreach ($fields as $f) {
            $fid = (int) ($f['id'] ?? 0);
            if ($fid <= 0 || ($f['type'] ?? '') === 'page') {
                continue;
            }
            $blockPage = $plan['blocks'][$plan['pageOf'][$fid]] ?? null;
            if ($blockPage !== null && $blockPage !== $fid) {
                $extraDeps[$fid][] = $blockPage;
            }
        }
        $order = LogicEvaluator::dependencyOrder($fields, $extraDeps);
        if ($order === null) {
            // Ciclo (formulario que no debería poder guardarse): orden natural.
            $order = array_map(static fn (array $f): int => (int) ($f['id'] ?? 0), $fields);
        }

        $byId = [];
        foreach ($fields as $f) {
            $byId[(int) $f['id']] = $f;
        }

        $errors = [];
        $validated = [];
        $answers = [];    // id => valor de fuente (null si oculto)
        $visible = [];    // id => bool
        $skippedPages = [];
        $storedFiles = []; // P10: metas ya escritas en disco (para rollback)

        foreach ($order as $fieldId) {
            $field = $byId[$fieldId] ?? null;
            if ($field === null) {
                continue;
            }

            if ($field['type'] === 'page') {
                // P8 — Condición de omisión de página. Sus fuentes ya están
                // resueltas (orden topológico); si se cumple, TODO el bloque de
                // esta página cuenta como oculto en el resto del recorrido.
                $logic = $field['logic'] ?? null;
                if (is_array($logic) && !empty($logic['rules']) && LogicEvaluator::evaluate($logic, $answers)) {
                    $skippedPages[$fieldId] = true;
                }
                $answers[$fieldId] = null;
                continue;
            }

            if (!isset($visible[$fieldId])) {
                $visible[$fieldId] = self::isFieldVisible($field, $visible, $answers, array_keys($byId));
            }

            if (!$visible[$fieldId]) {
                // Campo o sección oculta: no se valida ni persiste; tampoco es fuente.
                $answers[$fieldId] = null;
                continue;
            }

            // Las secciones no se responden; su "respuesta" nunca es fuente.
            if ($field['type'] === 'section') {
                $answers[$fieldId] = null;
                continue;
            }

            // P8 — Bloque de página omitido (salta-página): el campo no se
            // valida ni persiste aunque el cliente hubiera enviado un valor.
            $blockPage = $plan['blocks'][$plan['pageOf'][$fieldId]] ?? null;
            if ($blockPage !== null && isset($skippedPages[$blockPage])) {
                $answers[$fieldId] = null;
                continue;
            }

            // P10 — Campo de archivo: se valida/guarda FUERA del flujo de
            // $_POST (los binarios llegan en $_FILES). El valor persistido es
            // la metadata JSON; como fuente de lógica se usa el nombre.
            if ($field['type'] === 'file') {
                $upload = Uploader::storeMany(
                    $_FILES['field_' . (int) $field['id']] ?? [],
                    is_array($field['validations'] ?? null) ? $field['validations'] : [],
                    $locale
                );

                if (!$upload['ok']) {
                    $errors[] = I18n::t('val.' . (string) $upload['error'], $locale, (array) $upload['params']);
                    $answers[$fieldId] = null;
                    continue;
                }

                $metas = Uploader::decode(Uploader::encode($upload['value']));
                if ($metas === null || $metas === []) {
                    if ((bool) $field['is_required']) {
                        $errors[] = I18n::t('val.required', $locale);
                    }
                    $answers[$fieldId] = null;
                    continue;
                }

                foreach ($metas as $meta) {
                    $storedFiles[] = $meta;
                }
                $validated[(int) $field['id']] = (string) Uploader::encode($upload['value']);
                $answers[$fieldId] = implode('; ', array_map(
                    static fn(array $m): string => (string) ($m['name'] ?? ''),
                    $metas
                ));
                continue;
            }

            $value = $this->getFieldValue($field);

            if ($this->isEmpty($value)) {
                if ((bool) $field['is_required']) {
                    $errors[] = I18n::t('val.required', $locale);
                }
                $answers[$fieldId] = null;
                continue;
            }

            // Los tipos compuestos (matriz, ranking) llegan como arrays crudos
            // indexados por posición; se normalizan a mapas clave=>valor antes de
            // validar y persistir, preservando las etiquetas de filas/opciones.
            $value = self::normalizeFieldValue($field, $value);

            $validation = $this->validate($field, $value, $locale);
            if ($validation !== true) {
                $errors[] = $validation;
                $answers[$fieldId] = null;
                continue;
            }

            $validated[(int) $field['id']] = $this->stringify($value);
            // Para evaluar condiciones de OTROS campos se usa el valor crudo
            // (los checkbox son arrays en la evaluación, no el JSON stringificado).
            $answers[$fieldId] = $value;
        }

        return [
            'errors'       => $errors,
            'validated'    => $validated,
            'answers'      => $answers,
            'storedFiles'  => $storedFiles,
            'order'        => $order,
            'byId'         => $byId,
            'plan'         => $plan,
            'visible'      => $visible,
            'skippedPages' => $skippedPages,
        ];
    }

    public function submit(string $slug): void
    {
        $form = Form::findBySlug($slug);

        if ($form === null) {
            Response::notFound();
            return;
        }

        $settings = Form::settings((int) $form['id']);
        $locale = I18n::forRequest($settings);

        if ($form['status'] !== 'published') {
            Response::abort(403, I18n::t('err.not_accepting', $locale));
            return;
        }

        // F4 — Contrapartida del gate del GET, y aquí, en el controlador: quitar
        // el `action` del formulario o el botón del cliente no es una puerta. Un
        // POST directo saltándose el GET es justo lo que hay que impedir, y por
        // eso el corte va antes que el `post-max` y que el CSRF: a un formulario
        // restringido no se le contesta nada hasta saber si tiene acceso.
        $accessToken = null;
        $participantId = null;
        if (FormAccess::isRestricted($settings)) {
            [$granted, $accessToken, $participantId] = self::resolveAccess($form, $settings);
            if (!$granted) {
                Response::abort(403, I18n::t('access.denied', $locale));
                return;
            }
        }

        // P10 — Si el POST excede post_max_size, PHP vacía $_POST/$_FILES: el
        // chequeo de CSRF fallaría con un 419 confuso. Se reporta 413 claro.
        if (self::postOverflowed()) {
            Response::abort(413, I18n::t('err.upload_too_big_global', $locale, [
                'max' => Uploader::maxSizeMb(),
            ]));
            return;
        }

        if (!Csrf::verifyRequest()) {
            Response::abort(419, 'Token de seguridad inválido. Recarga la página.');
            return;
        }

        // P6 — Re-chequear disponibilidad (calendario/cupo) en el servidor,
        // aunque el GET /f/{slug} ya la haya mostrado (el GET pudo quedar viejo).
        $state = self::scheduleState($settings);
        if ($state === 'closed') {
            Response::abort(403, I18n::t('err.closed', $locale));
            return;
        }
        if ($state === 'not_open') {
            Response::abort(403, I18n::t('err.not_open', $locale));
            return;
        }
        if (self::quotaExceeded($form)) {
            Response::abort(403, I18n::t('err.quota', $locale));
            return;
        }

        // P7 — Honeypot: si el campo trampa viene rellenado, es un bot. Se finge
        // el flujo normal (PRG a gracias) sin persistir, validar ni notificar.
        if (self::honeypotTriggered($settings)) {
            $this->redirectToThanks($form, $settings);
            return;
        }

        // B2 — Verificación bot server-side (Turnstile). Si el form lo pide pero
        // la instancia no tiene claves, es un formulario mal configurado: se
        // corta como si no aceptara respuestas (el GET ya lo bloquea igual).
        if (!empty($settings['anti_bot']['enabled'])) {
            if (!Turnstile::isConfigured()) {
                Response::abort(403, I18n::t('err.not_accepting', $locale));
                return;
            }
            // El widget inyecta su token en `cf-turnstile-response` (el nombre
            // es de Cloudflare). El token es de UN SOLO uso: si la verificación
            // falla se re-renderiza el form con el error y el visitante reintenta.
            $token = trim((string) Request::post('cf-turnstile-response', ''));
            if ($token === '' || !Turnstile::verify($token, Request::ip())) {
                $this->renderFillErrors($form, $settings, [I18n::t('err.turnstile', $locale)], $accessToken);
                return;
            }
        }

        // Rate limiting: máx. 10 envíos por IP y formulario cada 10 minutos por
        // defecto. El número es configurable (`FILL_SUBMIT_PER_10MIN`) porque un
        // censo se responde desde una IP compartida y el límite que frena a un
        // automatismo es el mismo que deja fuera a la persona número 11.
        $scope = 'submit:' . (int) $form['id'];
        $ip = Request::ip();
        $maxEnvios = max(1, (int) Config::get('app.limits.fill_submit_per_10min', 10));
        if (!RateLimiter::isAllowed($scope, $ip, $maxEnvios, 600)) {
            \App\Core\SecurityLog::record('rate_limit.fill', 'bloqueo por IP al enviar /f/' . (string) $form['slug']);
            Response::abort(429, I18n::t('err.too_many', $locale));
            return;
        }
        // Contar este envío dentro de la ventana
        RateLimiter::recordHit($scope, $ip, 600);

        // FASE 1 — Validación sin persistir (núcleo compartido con la preview).
        // (A10) La submission NO se crea hasta que todos los campos son válidos;
        // así se evitan registros huérfanos al descartar respuestas inválidas.
        $vr = $this->runValidation($form['fields'], $locale);
        $errors = $vr['errors'];
        $validated = $vr['validated'];
        $storedFiles = $vr['storedFiles'];

        if (!empty($errors)) {
            // P10: los archivos ya subidos en este intento no deben quedar
            // huérfanos en disco si el resto del envío es inválido.
            Uploader::rollback($storedFiles);
            $this->renderFillErrors($form, $settings, $errors, $accessToken);
            return;
        }

        // FASE 2 — Persistencia atómica: crear submission + answers en una
        // transacción para que un fallo no deje la submission sin respuestas.
        // El token de capacidad se genera ANTES para poder pasarlo al INSERT: es
        // lo único que abre la página pública de gracias (nunca el id).
        $publicToken = Submission::newToken();
        $db = Database::getInstance(Config::db());
        $db->beginTransaction();
        try {
            $submissionId = Submission::create(
                (int) $form['id'],
                $ip,
                Request::userAgent(),
                $publicToken,
                $participantId
            );

            foreach ($validated as $fieldId => $value) {
                Answer::create($submissionId, $fieldId, $value);
            }

            // `responded_at` va DENTRO de la misma transacción que la respuesta: si
            // la respuesta se guarda y la marca no, la lista de «quién NO ha
            // respondido» enseña a alguien que ya contestó, y no hay forma de
            // saber que las dos cosas se separaron. Es un UPDATE idempotente con
            // estado objetivo (`WHERE responded_at IS NULL`), así que responder dos
            // veces no mueve la fecha de la primera.
            if ($participantId !== null) {
                FormParticipant::marcarRespondido($participantId);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            Uploader::rollback($storedFiles);
            error_log('Error al guardar la respuesta: ' . $e->getMessage());
            Response::abort(500, I18n::t('err.failed_save', $locale));
            return;
        }

        // FASE 3 — Fuera de transacción, a prueba de fallos: notificaciones
        // (P5) y webhook. Un fallo aquí NUNCA rompe la respuesta guardada.
        try {
            // UTC explícito en vez de `date()` a pelo: el valor viaja al
            // webhook como `submission.finished_at` y a los correos, así que
            // no puede depender de la zona por defecto del proceso (que es UTC,
            // pero si alguien la cambiara, el contrato del webhook cambiaría
            // sin que se rompiera ninguna prueba).
            $submittedAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

            Notifier::afterSubmit($form, $settings, (int) $submissionId, $validated, $submittedAt);

            if (!empty($settings['webhook']['url'])) {
                Webhook::fire($settings, self::webhookPayload($form, (int) $submissionId, $ip, $submittedAt, $validated));
            }
        } catch (\Throwable $e) {
            error_log('[FillController] FASE 3 (notificaciones/webhook): ' . $e->getMessage());
        }

        // FASE 4 — Gracias (PRG, P6): redirección externa opt-in o thanks GET.
        // P8 — Quiz: se pasa el token de capacidad, NO el id de la submission.
        // El id es un AUTO_INCREMENT correlativo y el thanks es público: con
        // `?sid=` bastaba recorrer enteros para leer las respuestas ajenas de
        // ese mismo formulario (la vista muestra la respuesta literal de la
        // persona más la correcta). El token son 128 bits, y solo lo conoce la
        // sesión que acaba de enviar.
        $thanksPath = '/f/' . rawurlencode((string) $form['slug']) . '/thanks';
        if (!empty($settings['quiz']['enabled'])) {
            $thanksPath .= '?t=' . rawurlencode($publicToken);
        }
        $this->redirectToThanks($form, $settings, $thanksPath);
    }

    /**
     * Asigna cada campo al bloque de página al que pertenece (P8):
     *  - blocks:  índice de bloque => id del campo «page» que lo abre (null si
     *    es el bloque implícito anterior al primer salto).
     *  - pageOf:  id de campo => índice de bloque.
     * La pertenencia la dicta el ORDEN de lectura (Field::byForm), igual que el
     * render: un campo cae en el bloque cuya página le precedió.
     *
     * @return array{blocks: array<int, int|null>, pageOf: array<int, int>}
     */
    public static function pagePlan(array $fields): array
    {
        $blocks = [0 => null];
        $pageOf = [];
        $current = 0;
        foreach ($fields as $f) {
            $id = (int) ($f['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if (($f['type'] ?? '') === 'page') {
                $current = count($blocks);
                $blocks[$current] = $id;
            }
            $pageOf[$id] = $current;
        }
        return ['blocks' => $blocks, 'pageOf' => $pageOf];
    }

    /**
     * PRG compartido (P6/P7): a `$path` (thanks) o a una URL externa
     * http/https válida si settings.thanks.redirect_url está configurada y el
     * host está en la allowlist del servidor. También usado por el honeypot
     * para fingir éxito.
     *
     * F5.1 — Una redirect_url externa cuyo host NO está en la allowlist no se
     * sigue: se muestra el thanks interno con un aviso (flash) en su lugar.
     * La allowlist la pone quien opera la instancia (APP_REDIRECT_ALLOWLIST),
     * nunca el dueño del formulario.
     *
     * @param string $path Ruta interna de gracias (permite querystring con ?sid)
     */
    private function redirectToThanks(array $form, array $settings, string $path = ''): void
    {
        $redirectUrl = trim((string) ($settings['thanks']['redirect_url'] ?? ''));
        if ($redirectUrl !== '' && self::isHttpUrl($redirectUrl)) {
            if (RedirectGuard::hostAllowed($redirectUrl)) {
                Response::redirectExternal($redirectUrl);
                return;
            }
            Session::flash('redirect_blocked', RedirectGuard::hostOf($redirectUrl));
        }
        if ($path === '') {
            $path = '/f/' . rawurlencode((string) $form['slug']) . '/thanks';
            // Q6 — Si el gracias se servirá en un idioma distinto del que fija
            // el formulario (override ?lang= o navegante), llevarlo en el PRG
            // para que la página de gracias no cambie de idioma al llegar.
            $locale = I18n::forRequest($settings);
            $expected = I18n::resolve($settings);
            if ($locale !== $expected) {
                $path .= '?lang=' . $locale;
            }
        }
        Response::redirect($path);
        return;
    }

    /**
     * Página de gracias (GET, PRG). Muestra el mensaje personalizado si el
     * formulario tiene settings.thanks.message.
     */
    public function thanks(string $slug): void
    {
        $form = Form::findBySlug($slug);

        if ($form === null) {
            Response::notFound();
            return;
        }

        $settings = Form::settings((int) $form['id']);

        // P8 — Quiz: con settings.quiz.enabled y un token de capacidad (query
        // `t`) que resuelva a una submission de ESTE formulario se muestra la
        // puntuación calculada sobre las respuestas persistidas. Cálculo
        // stateless: no hay columna de score.
        //
        // OJO — el identificador del thanks NUNCA es el id de la submission. El
        // id es un AUTO_INCREMENT correlativo y esta página es pública y sin
        // sesión, así que `?sid=1..N` dejaba leer las respuestas de todos los
        // que Contestaron el formulario (la vista enseña la respuesta ajena y la
        // correcta). `t` son 128 bits de aleatoriedad: solo la sesión que envió
        // lo tiene. Un token desconocido —o ausente, como en las submissions
        // antiguas sin backfill— solo muestra el mensaje de gracias.
        $quiz = null;
        if (!empty($settings['quiz']['enabled'])) {
            $token = trim((string) Request::get('t', ''));
            if ($token !== '' && Submission::isWellFormedToken($token)) {
                $record = Submission::findWithToken($token);
                if ($record !== null && (int) $record['submission']['form_id'] === (int) $form['id']) {
                    $quiz = self::quizPayload($form, $record['answers']);
                }
            }
        }

        // El token es una credencial: que no se cuele en el Referer si la página
        // llega a enlazar o cargar recursos de otro origen.
        if (PHP_SAPI !== 'cli') {
            header('Referrer-Policy: no-referrer');
        }

        // Si el formulario es embebible, permitir el thanks dentro del iframe
        // (mismo origen) tras el PRG del envío desde el embed.
        if (!empty($settings['embed']['enabled'])) {
            $this->allowEmbedHeaders();
        }

        // Al llegar a la página de gracias en modo `roster`, se revoca el acceso
        // concedido a este participante para este formulario, para que al volver
        // a abrirlo deba identificarse de nuevo y no pueda acceder a una encuesta
        // ya respondida.
        $mode = FormAccess::mode($settings);
        if ($mode === FormSettings::ACCESS_ROSTER) {
            RosterDoor::revoke((int) $form['id']);
        }

        // F5.1 — Aviso de redirect externo bloqueado (lo fija redirectToThanks
        // vía flash si settings.thanks.redirect_url no está en la allowlist).
        $redirectBlocked = Session::getFlash('redirect_blocked', '');

        View::render('fill/thanks', [
            'form'            => $form,
            'settings'        => $settings,
            'quiz'            => $quiz,
            'redirect_blocked' => $redirectBlocked,
        ], null);
    }

    /**
     * Respuestas para el quiz decodificadas (los arrays —checkbox— llegan como
     * JSON string) y puntuación (P8).
     */
    private static function quizPayload(array $form, array $answersById): ?array
    {
        $decoded = [];
        foreach ($answersById as $fid => $value) {
            $s = ltrim((string) $value);
            if ($s !== '' && $s[0] === '[') {
                $arr = json_decode((string) $value, true);
                $decoded[$fid] = is_array($arr) ? $arr : $value;
            } else {
                $decoded[$fid] = $value;
            }
        }
        return QuizScore::score($form['fields'], $decoded);
    }

    /**
     * CSP que necesita ESTA respuesta HTTP, sobre el default de la app:
     *
     *  - Embed (P7): relaja X-Frame-Options y frame-ancestors a 'self' para
     *    poder estar en un iframe del mismo origen.
     *  - Turnstile (B2): el widget de Cloudflare carga un script y se pinta en
     *    un iframe desde challenges.cloudflare.com, así que esa página concreta
     *    necesita script-src/frame-src para ese origen. Solo cuando el form lo
     *    pide y la instancia tiene claves; el resto de la app sigue 'self'.
     *
     * Solo emite headers en la respuesta HTTP real; en CLI (runner) no se hace
     * nada (el test de vista no depende de esto: ninguna vista inyecta scripts).
     */
    private function emitFillCsp(array $settings, bool $embed): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        $csp = (string) Config::get('app.csp_policy', '');
        $changed = false;

        if ($embed) {
            header('X-Frame-Options: SAMEORIGIN');
            $relaxed = preg_replace("/frame-ancestors\s+'none'/", "frame-ancestors 'self'", $csp);
            if ($relaxed !== null && $relaxed !== $csp) {
                $csp = $relaxed;
                $changed = true;
            }
        }

        if (!empty($settings['anti_bot']['enabled']) && Turnstile::isConfigured() && $csp !== '') {
            $origin = 'https://challenges.cloudflare.com';
            // script-src ya existe ('self' en el default): se añade el origen
            // al final de la directiva (antes del ';' que la cierra).
            if (preg_match('/script-src\s+([^;]*);/', $csp, $m) === 1) {
                if (strpos($m[1], $origin) === false) {
                    $csp = str_replace('script-src ' . $m[1] . ';', 'script-src ' . $m[1] . ' ' . $origin . ';', $csp);
                    $changed = true;
                }
            } else {
                $csp .= '; script-src ' . $origin;
                $changed = true;
            }
            if (strpos($csp, 'frame-src') === false) {
                $csp .= '; frame-src ' . $origin;
                $changed = true;
            }
        }

        if ($changed && $csp !== '') {
            header('Content-Security-Policy: ' . $csp);
        }
    }

    /**
     * True si el honeypot capturó un bot (P7): el campo trampa llega rellenado
     * mientras que los humanos no lo ven ni pueden enfocarlo.
     */
    private static function honeypotTriggered(array $settings): bool
    {
        if (empty($settings['honeypot']['enabled'])) {
            return false;
        }
        return trim((string) Request::post('_vf_hp', '')) !== '';
    }

    /**
     * Estado del calendario del formulario (P6): 'open' | 'not_open' | 'closed'.
     * Las fechas se guardan como 'YYYY-MM-DD HH:MM' (hora local de la app).
     */
    private static function scheduleState(array $settings): string
    {
        $now = time();
        $openAt = self::parseScheduleDate((string) ($settings['schedule']['open_at'] ?? ''));
        $closeAt = self::parseScheduleDate((string) ($settings['schedule']['close_at'] ?? ''));

        if ($openAt !== null && $now < $openAt) {
            return 'not_open';
        }
        if ($closeAt !== null && $now >= $closeAt) {
            return 'closed';
        }
        return 'open';
    }

    /**
     * Interpreta `schedule.open_at` / `close_at` (P6) como instante.
     *
     * Estos valores salen de un `<input type="datetime-local">`, o sea que son
     * una pared de reloj SIN zona: "el 28 a las 09:00" en la zona de quien
     * administera el formulario, no en UTC ni en la del servidor. `strtotime()`
     * a pelo las leía en la zona por defecto de PHP, así que el formulario se
     * abría y se cerraba con el desfase de esa zona: 4 h antes en Caracas (que
     * es lo que corresponde a leerlas en UTC) y 2 h antes cuando PHP corría en
     * America/Mexico_City. Un formulario con `open_at = 09:00` empezaba a
     * aceptar respuestas a las 05:00.
     *
     * Si el string trae zona explícita (ISO-8601 con `Z` o `±HH:MM`, que es lo
     * que devuelve un round-trip por el editor si algún día se serializa así)
     * se respeta tal cual: ahí sí hay una zona que manda y no hay que suponer
     * ninguna.
     */
    private static function parseScheduleDate(string $value): ?int
    {
        if (trim($value) === '') {
            return null;
        }

        $valor = trim($value);

        $conZona = (bool) preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', $valor);

        try {
            $dt = $conZona
                ? new \DateTimeImmutable($valor)
                : new \DateTimeImmutable($valor, Clock::displayZone());
        } catch (\Exception $e) {
            return null;
        }

        return $dt->getTimestamp();
    }

    /**
     * True si el cupo configurado está cubierto (P6). Cupo deshabilitado o
     * max <= 0 = sin límite. La cuenta es por submission persistida.
     */
    private static function quotaExceeded(array $form): bool
    {
        $settings = Form::settings((int) $form['id']);
        $quota = $settings['quota'] ?? [];
        if (empty($quota['enabled']) || (int) ($quota['max'] ?? 0) <= 0) {
            return false;
        }
        return Form::submissionCount((int) $form['id']) >= (int) $quota['max'];
    }

    private static function isHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true);
    }

    /**
     * Payload JSON para el webhook (P5): metadatos del formulario y de la
     * respuesta + respuestas decodificadas (listas/mapas como arreglos).
     */
    private static function webhookPayload(array $form, int $submissionId, string $ip, string $submittedAt, array $validated): array
    {
        $answers = [];
        foreach ($validated as $fieldId => $value) {
            // P10: los adjuntos viajan al webhook como texto legible; la
            // metadata interna (ruta de storage, sha) no sale del servidor.
            if (Uploader::isAttachmentJson((string) $value)) {
                $answers[(string) $fieldId] = Uploader::displayValue((string) $value);
                continue;
            }
            $decoded = json_decode((string) $value, true);
            $answers[(string) $fieldId] = (is_array($decoded) && json_last_error() === JSON_ERROR_NONE)
                ? $decoded
                : $value;
        }

        $fields = [];
        foreach ((array) ($form['fields'] ?? []) as $field) {
            $fields[] = [
                'id'    => $field['id'],
                'label' => (string) ($field['label'] ?? ''),
            ];
        }

        return [
            'form' => [
                'id'    => (int) $form['id'],
                'slug'  => (string) ($form['slug'] ?? ''),
                'title' => (string) ($form['title'] ?? ''),
            ],
            'submission' => [
                'id'          => $submissionId,
                'finished_at' => $submittedAt,
                'ip'          => $ip,
            ],
            'fields'  => $fields,
            'answers' => $answers,
        ];
    }

    private function getFieldValue(array $field)
    {
        $key = 'field_' . $field['id'];
        return Request::post($key, null);
    }

    /**
     * P10 — ¿El cuerpo del POST excede post_max_size? Cuando ocurre, PHP vacía
     * $_POST y $_FILES por completo (y avisa por log), así que se detecta por
     * la ausencia de datos con un Content-Length grande.
     */
    /**
     * P10 — El POST superó `post_max_size`. Defense in depth: el middleware
     * `post-max` ya responde 413 antes del CSRF, pero el controller se invoca
     * directo en tests, así que aquí también se corta.
     */
    private static function postOverflowed(): bool
    {
        return Request::bodyTooLarge();
    }

    /**
     * Determina si un campo debe mostrarse.
     *
     * Visible si: su sección contenedora está visible y su propia condición se
     * cumple. `$answers` (fuentes en orden topológico) permite reglas referidas
     * a CUALQUIER pregunta; un campo oculto aporta valor nulo (semántica de
     * "vacío"). Fail-open + log: si una regla apunta a un campo que no existe
     * en el formulario (configuración inválida que no debería poder guardarse),
     * se descarta esa referencia y se loguea, evitando ocultar preguntas
     * obligatorias por un error de configuración.
     */
    /**
     * Visibilidad inicial para el RENDER (cierra el gap sin JavaScript).
     *
     * El GET rendereaba todos los campos y delegaba el ocultado a `fill.js`:
     * sin JS se veían preguntas cuya condición era falsa, y preguntas de un
     * bloque de página que el servidor luego descarta. Aquí se resuelve la
     * visibilidad en el servidor y la vista la marca con `vf-hidden`.
     *
     * Reutiliza exactamente las reglas del submit (`isFieldVisible` y
     * `pagePlan`), así que no hay una tercera semántica que mantener: la
     * única diferencia es que el submit falla abierto y este render no.
     *
     * Fuentes: en un GET en fresco no hay respuestas (una "afirmación" con
     * fuente vacía es FALSE, una "negación" TRUE — la semántica documentada
     * en LogicEvaluator); en el re-render de errores se usan las ya
     * enviadas, de modo que el participante ve lo mismo que vería con JS.
     *
     * @param array $fields Lista plana en orden de lectura.
     * @param array $old    Respuestas por `field_<id>` (re-render de errores).
     * @return array<int, bool> id => visible
     */
    public static function initialVisibility(array $fields, array $old = []): array
    {
        $plan = self::pagePlan($fields);
        $knownIds = [];
        foreach ($fields as $f) {
            $id = (int) ($f['id'] ?? 0);
            if ($id > 0) {
                $knownIds[] = $id;
            }
        }

        $answers = [];
        foreach ($old as $k => $v) {
            if (is_string($k) && preg_match('/^field_(\d+)$/', $k, $m)) {
                $answers[(int) $m[1]] = $v;
            }
        }

        // P8 — un bloque de página se omite cuando su condición se cumple
        // (semántica invertida respecto a la visibilidad: aquí TRUE = saltar).
        $skipped = [];
        foreach ($fields as $f) {
            if (($f['type'] ?? '') !== 'page') {
                continue;
            }
            $pid = (int) ($f['id'] ?? 0);
            $blockId = array_search($pid, $plan['blocks'], true);
            $logic = $f['logic'] ?? null;
            if ($blockId !== false && is_array($logic) && !empty($logic['rules'])
                && LogicEvaluator::evaluate($logic, $answers)) {
                $skipped[(int) $blockId] = true;
            }
        }

        // Orden de lectura: la sección precede siempre a sus hijas (el
        // guardado lo garantiza), así que un solo paso basta para el AND
        // de contenedor que hace isFieldVisible().
        $visible = [];
        foreach ($fields as $f) {
            $id = (int) ($f['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if (($f['type'] ?? '') === 'page') {
                $visible[$id] = true;
                continue;
            }
            $v = self::isFieldVisible($f, $visible, $answers, $knownIds);
            $blockId = $plan['pageOf'][$id] ?? 0;
            if ($v && isset($skipped[(int) $blockId])) {
                $v = false;
            }
            $visible[$id] = $v;
        }

        return $visible;
    }

    private static function isFieldVisible(array $field, array $visible, array $answers, array $knownIds): bool
    {
        if (!empty($field['parent_id'])) {
            $pid = (int) $field['parent_id'];
            if (isset($visible[$pid]) && $visible[$pid] === false) {
                return false;
            }
        }

        $logic = $field['logic'] ?? null;
        if ($logic === null || empty($logic['rules']) || !is_array($logic['rules'])) {
            return true;
        }

        $sanitizedRules = [];
        foreach ($logic['rules'] as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $target = (int) ($rule['field_id'] ?? 0);
            if ($target > 0 && !in_array($target, $knownIds, true)) {
                error_log('[FillController] Condición con referencia a campo inexistente (' . $target . '); se ignora la regla.');
                continue;
            }
            $sanitizedRules[] = $rule;
        }

        if ($sanitizedRules === []) {
            return true;
        }
        $sanitized = ['operator' => $logic['operator'] ?? 'all', 'rules' => $sanitizedRules];
        return LogicEvaluator::evaluate($sanitized, $answers);
    }

    private function isEmpty($value): bool
    {
        if (is_array($value)) {
            return count(array_filter($value, static fn($v) => $v !== '' && $v !== null)) === 0;
        }
        return $value === null || trim((string) $value) === '';
    }

    private function validate(array $field, $value, string $locale = 'es')
    {
        return self::validateField($field, $value, $locale);
    }

    private static function validateField(array $field, $value, string $locale = 'es')
    {
        $type = $field['type'];
        $validations = $field['validations'] ?? [];

        // F1.3: primero el techo de almacenamiento.Va antes que cualquier otra
        // regla para que una respuesta enorme devuelva un error de validación
        // legible (y que el submit siga su camino normal, con Uploader::rollback
        // de los adjuntos ya subidos) en lugar de un 500 a mitad de transacción.
        if (self::exceedsStorageLimit($value)) {
            return is_array($value)
                ? I18n::t('val.too_long', $locale)
                : I18n::t('val.max_len', $locale, ['n' => self::MAX_VALUE_CHARS]);
        }

        if ($type === 'email' && is_string($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return I18n::t('val.email', $locale);
        }

        if ($type === 'url' && is_string($value) && !filter_var($value, FILTER_VALIDATE_URL)) {
            return I18n::t('val.url', $locale);
        }

        if ($type === 'tel' && is_string($value)) {
            $digitsOnly = preg_replace('/\D/', '', $value);
            if (preg_match('/[^0-9+().\-\s\/]/', $value) || strlen((string) $digitsOnly) < 6) {
                return I18n::t('val.tel', $locale);
            }
        }

        if (in_array($type, ['number', 'range'], true) && is_string($value) && !is_numeric($value)) {
            return I18n::t('val.number', $locale);
        }

        if (in_array($type, ['number', 'range'], true) && is_string($value) && $value !== '') {
            if (isset($validations['min']) && (float) $value < (float) $validations['min']) {
                return I18n::t('val.min_gte', $locale, ['min' => $validations['min']]);
            }
            if (isset($validations['max']) && (float) $value > (float) $validations['max']) {
                return I18n::t('val.max_lte', $locale, ['max' => $validations['max']]);
            }
        }

        // Escala Likert (1..max): los radios solo emiten enteros del rango,
        // pero un POST a mano puede mandar 99 o "abc" y corromper la
        // analítica numérica (promedios/min/max/NPS se derivan de este valor).
        if ($type === 'scale') {
            if (!is_numeric($value)) {
                return I18n::t('val.number', $locale);
            }
            $max = (int) ($validations['max'] ?? 5);
            $n = (int) $value;
            if ((string) $value !== (string) $n || $n < 1 || $n > $max) {
                return I18n::t('val.scale_range', $locale, ['max' => $max]);
            }
            return true;
        }

        if (in_array($type, ['text', 'textarea'], true) && is_string($value)) {
            if (isset($validations['min_length']) && mb_strlen($value) < (int) $validations['min_length']) {
                return I18n::t('val.min_len', $locale, ['n' => $validations['min_length']]);
            }
            if (isset($validations['max_length']) && mb_strlen($value) > (int) $validations['max_length']) {
                return I18n::t('val.max_len', $locale, ['n' => $validations['max_length']]);
            }
        }

        // Validar opciones permitidas
        if (in_array($type, ['select', 'radio'], true) && is_string($value)) {
            $allowed = array_column($field['options'] ?? [], 'value');
            if (!empty($allowed) && !in_array($value, $allowed, true)) {
                return I18n::t('val.invalid_selection', $locale);
            }
        }

        if ($type === 'checkbox' && is_array($value)) {
            $allowed = array_column($field['options'] ?? [], 'value');
            if (!empty($allowed)) {
                $invalid = array_diff($value, $allowed);
                if ($invalid !== []) {
                    return I18n::t('val.invalid_selection', $locale);
                }
            }
        }

        // Matriz / Likert: el valor llega normalizado como {fila => columna}.
        if ($type === 'matrix' && is_array($value)) {
            $cols = array_map(static fn($o) => (string) $o['value'], self::matrixCols($field));
            $allFilled = true;
            foreach ($value as $rowLabel => $col) {
                if ($col === '') {
                    $allFilled = false;
                    continue;
                }
                if ($cols !== [] && !in_array($col, $cols, true)) {
                    return I18n::t('val.matrix_row', $locale, ['row' => $rowLabel]);
                }
            }
            if (((bool) ($field['is_required'] ?? false)) && !$allFilled) {
                return I18n::t('val.matrix_all', $locale);
            }
            return true;
        }

        // Ranking: {opción => orden(1..N)}; si hay alguna ordenada, todas deben
        // estarlo; los valores deben ser únicos y estar dentro de 1..N.
        if ($type === 'ranking' && is_array($value)) {
            $required = (bool) ($field['is_required'] ?? false);
            $filled = [];
            foreach ($value as $v) {
                if ($v !== '') {
                    $filled[] = $v;
                }
            }
            $total = count($value);
            if (count($filled) > 0 || $required) {
                if (count($filled) !== $total) {
                    return I18n::t('val.ranking_all', $locale);
                }
                foreach ($filled as $v) {
                    if (!is_numeric($v)) {
                        return I18n::t('val.ranking_numbers', $locale);
                    }
                    $n = (int) $v;
                    if ($n < 1 || $n > $total) {
                        return I18n::t('val.ranking_range', $locale, ['max' => $total]);
                    }
                }
                if (count(array_unique($filled)) !== $total) {
                    return I18n::t('val.ranking_unique', $locale);
                }
            }
            return true;
        }

        if ($type === 'nps') {
            if (!is_numeric($value)) {
                return I18n::t('val.nps_range', $locale);
            }
            $n = (int) $value;
            if ($n < 0 || $n > 10) {
                return I18n::t('val.nps_range', $locale);
            }
            return true;
        }

        if ($type === 'star') {
            $max = (int) ($validations['max'] ?? 5);
            if (!is_numeric($value)) {
                return I18n::t('val.number', $locale);
            }
            $n = (int) $value;
            if ($n < 1 || $n > $max) {
                return I18n::t('val.star_range', $locale, ['max' => $max]);
            }
            return true;
        }

        return true;
    }

    private static function matrixRows(array $field): array
    {
        return array_values(array_filter(
            $field['options'] ?? [],
            static fn($o) => is_array($o) && ($o['group'] ?? '') === 'rows'
        ));
    }

    private static function matrixCols(array $field): array
    {
        return array_values(array_filter(
            $field['options'] ?? [],
            static fn($o) => is_array($o) && ($o['group'] ?? '') === 'cols'
        ));
    }

    private static function rankingItems(array $field): array
    {
        return array_values(array_filter(
            $field['options'] ?? [],
            static fn($o) => is_array($o)
        ));
    }

    /**
     * Convierte el valor crudo de un campo compuesto en un mapa clave=>valor
     * con las etiquetas reales (filas de matriz, opciones de ranking). Para el
     * resto de tipos devuelve el valor sin cambios.
     */
    private static function normalizeFieldValue(array $field, $value)
    {
        if (!is_array($value)) {
            return $value;
        }

        if ($field['type'] === 'matrix') {
            $rows = array_map(static fn($o) => (string) $o['value'], self::matrixRows($field));
            $out = [];
            foreach ($rows as $i => $rowLabel) {
                $raw = $value[$i] ?? '';
                $out[$rowLabel] = is_string($raw) ? trim($raw) : '';
            }
            return $out;
        }

        if ($field['type'] === 'ranking') {
            $items = array_map(static fn($o) => (string) $o['value'], self::rankingItems($field));
            $out = [];
            foreach ($items as $i => $optLabel) {
                $raw = $value[$i] ?? '';
                $out[$optLabel] = is_string($raw) ? trim($raw) : '';
            }
            return $out;
        }

        return $value;
    }

    /**
     * F1.3 — Tope absoluto del valor de una respuesta.
     *
     * `answers.value` es TEXT (65 535 bytes) en utf8mb4, y el tamaño solo lo
     * acotaba `validations.max_length`, que es OPCIONAL: 3 plantillas lo
     * declaraban y las otras no. Un textarea de 70 KB reventaba con
     * `ERROR 1406 Data too long`, y como el INSERT es lo último del submit, el
     * catch hacía `rollBack()` y `Uploader::rollback()`: devolvía un 500
     * indistinguible de un error del participante y ADEMÁS borraba los adjuntos
     * que ya había subido en ese intento.
     *
     * 16 000 caracteres × 4 bytes (emoji) = 64 000 < 65 535: cabe siempre.
     * Los arrays (checkbox, matriz, ranking) se miden en bytes ya codificados,
     * porque en matriz y ranking las CLAVES las manda el cliente sin validar
     * contra las filas del autor, así que un array enorme es alcanzable sin
     * tocar ningún campo de texto.
     */
    private const MAX_VALUE_CHARS = 16000;
    private const MAX_VALUE_BYTES = 60000;

    private static function stringify($value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return json_encode(array_values($value), JSON_UNESCAPED_UNICODE);
            }
            // Mapas (matriz/ranking) preservan las claves (etiquetas).
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        return (string) $value;
    }

    /** ¿El valor a persistir no cabe en `answers.value`? */
    private static function exceedsStorageLimit($value): bool
    {
        if (is_array($value)) {
            return strlen(self::stringify($value)) > self::MAX_VALUE_BYTES;
        }
        return mb_strlen((string) $value) > self::MAX_VALUE_CHARS;
    }
}