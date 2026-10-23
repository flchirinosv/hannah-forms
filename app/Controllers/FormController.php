<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Access;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\DashboardLimit;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\Field;
use App\Models\Form;
use App\Models\FormAccess;
use App\Models\FormParticipant;
use App\Services\FormPanel;
use App\Services\FormSettings;
use App\Services\ReviewPolicy;
use App\Services\RosterPanel;
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
            // Título/descripción: los de la plantilla salvo que un POST los traiga.
            if ($title === '') {
                $title = trim((string) ($template['name'] ?? ''));
            }
            if ($description === '') {
                $description = trim((string) ($template['description'] ?? ''));
            }
        } else {
            // La galería ya NO tiene campos de título ni descripción: lo que se
            // lee en la tarjeta «en blanco» es lo que queda guardado, y sale de
            // `TemplateService::blank()` para que la vista y el servidor no
            // puedan divergir. Un POST con `title`/`description` todavía manda
            // (lo usa el smoke), pero la UI ya no los envía.
            $blank = TemplateService::blank();
            if ($title === '') {
                $title = trim((string) $blank['name']);
            }
            if ($description === '') {
                $description = trim((string) $blank['description']);
            }
        }

        // Red de seguridad: un título vacío (p. ej. una plantilla sin `name`)
        // nacería como cadena vacía, y el dueño lo renombra en el builder.
        if ($title === '') {
            $title = 'Formulario sin título';
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
            // F3 — la vista decide qué botón pintar según esto: con revisión
            // activa, «Publicar» dejaría de existir para un dueño no exento.
            'reviewRequired' => ReviewPolicy::requiredFor($form),
            'canReview'      => Auth::can('forms.review'),
            'reviewTrail'    => $form['status'] === 'in_review' || $form['reviewed_at'] !== null
                ? Form::reviewTrail($id)
                : null,
            // Las etiquetas de los campos de tipo email alimentan el datalist de
            // la confirmación: `notify.confirmation.to_field` se resuelve por
            // etiqueta EXACTA y `Notifier` se come el fallo en silencio (una
            // línea de log, ningún aviso), así que la lista es lo que quita la
            // adivinanza. El builder lo sacaba de su estado en memoria; aquí se
            // lee de la BD, que es donde están de verdad.
            'emailFieldLabels' => $this->emailFieldLabels($id),
        ]);
    }

    /**
     * Pantalla del acceso y del listado de participantes.
     *
     * Antes era una tarjeta al final de la ficha; se movió aquí para aligerarla y
     * dar sitio al listado del roster (alta, importación, búsqueda). El modo y el
     * identificador se siguen editando en los ajustes de la ficha: esta pantalla
     * los lee, no los cambia.
     *
     * Mismos guards que `show()` —`canEdit` es la única puerta—, y los mismos
     * datos que alimentaban la tarjeta: la lista de la allowlist solo se carga en
     * ese modo (recortada a 200) y el roster solo se consulta en `roster`.
     */
    public function access(int $id): void
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
        $mode = FormAccess::mode($settings);

        /* La búsqueda del listado se puede hacer sin recargar la pantalla: el
         * formulario es un GET y `dashboard.js` lo envía por `fetch` con
         * `X-Requested-With`, así que la misma ruta responde JSON. El hueco que
         * devuelve es `rosterList`, el mismo markup que pinta el GET normal
         * (viene de `RosterPanel::html()`), y por eso los dos caminos no pueden
         * divergir. Sin la cabecera —navegador sin JS o enlace compartido— la
         * petición sigue el camino de siempre y sirve la pantalla entera. */
        if (Response::wantsJson() && $mode === FormSettings::ACCESS_ROSTER) {
            Response::json([
                'ok'   => true,
                'html' => RosterPanel::html(
                    $id,
                    (string) Request::query('q', ''),
                    (int) Request::query('page', 1),
                    (string) Request::query('sort', 'cedula'),
                    ['list']
                ),
            ]);
            return;
        }

        View::render('dashboard/forms/access', [
            'form'        => $form,
            'user'        => $user,
            // F4 — La lista solo se carga en modo `allowlist`, que es el único
            // caso en que existe: en los otros dos son dos consultas de más en
            // cada visita a la pantalla. Y recortada a 200, porque pintar 5000
            // emails en un `<ul>` no ayuda a nadie a administrarlos.
            'accessMode'  => $mode,
            'accessList'  => $mode === FormSettings::ACCESS_ALLOWLIST
                ? FormAccess::allowlistFor($id, '', 200)
                : [],
            'accessCount' => $mode === FormSettings::ACCESS_ALLOWLIST
                ? FormAccess::allowlistCount($id)
                : 0,
            // Fase 5 — La búsqueda y la página de la lista de participantes son
            // un GET a ESTA misma pantalla (`?q=`, `?page=`, `?sort=`), y no un
            // fetch: así llegan sin JavaScript, se pueden compartir y el botón
            // «atrás» del navegador deshace una búsqueda. El ancla `#participantes`
            // es la que usan el POST nativo del alta y el redirect del
            // ParticipantController, así que también es lo que devuelve la
            // búsqueda.
            //
            // Y el informe de la importación se saca de la sesión AQUÍ, una sola
            // vez: es lo que hace que el camino sin JavaScript enseñe las filas
            // que no entraron y no solo cuántas fueron.
            'roster'      => $mode === FormSettings::ACCESS_ROSTER
                ? RosterPanel::data(
                    $id,
                    (string) Request::query('q', ''),
                    (int) Request::query('page', 1),
                    (string) Request::query('sort', 'cedula')
                )
                : null,
            'informe'     => $mode === FormSettings::ACCESS_ROSTER
                ? Session::takeValue('roster_report')
                : null,
        ]);
    }

    /**
     * Etiquetas de los campos que pueden recoger un correo. Se excluyen
     * secciones y páginas por el mismo motivo que en el builder: no guardan
     * respuestas, así que no sirven de destinatario.
     *
     * @return array<int, string>
     */
    private function emailFieldLabels(int $formId): array
    {
        $campos = Field::byForm($formId);
        $etiquetas = [];
        foreach ($campos as $campo) {
            if (($campo['type'] ?? '') !== 'email') {
                continue;
            }
            $etiqueta = trim((string) ($campo['label'] ?? ''));
            if ($etiqueta !== '') {
                $etiquetas[] = $etiqueta;
            }
        }
        return $etiquetas;
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

        // Sin recarga (`data-vf-async="slots"`): la misma puerta devuelve los
        // huecos de la ficha ya pintados. Con `!Csrf::verifyRequest()` hay que
        // tener cuidado con el orden: un token caducado en JSON es un aviso, y
        // en HTML es la vuelta de siempre.
        $json = Response::wantsJson();

        if (!Csrf::verifyRequest()) {
            if ($json) {
                Response::json(['ok' => false, 'error' => 'Token inválido.'], 419);
                return;
            }
            Session::flash('error', 'Token inválido.');
            Response::redirect('/forms/' . $id);
            return;
        }

        $title = trim(Request::post('title', ''));
        $description = trim(Request::post('description', ''));

        if ($title === '') {
            if ($json) {
                Response::json(['ok' => false, 'error' => 'El título no puede estar vacío.'], 422);
                return;
            }
            Session::flash('error', 'El título no puede estar vacío.');
            Response::redirect('/forms/' . $id);
            return;
        }

        if (mb_strlen($title) > 190) {
            if ($json) {
                Response::json(['ok' => false, 'error' => 'El título no puede superar los 190 caracteres.'], 422);
                return;
            }
            Session::flash('error', 'El título no puede superar los 190 caracteres.');
            Response::redirect('/forms/' . $id);
            return;
        }

        // F3 — Con revisión activa, cualquier edición deja el formulario en
        // `draft` para que el dueño decida explícitamente cuándo enviarlo a
        // revisión.
        $needsReviewEdit = ReviewPolicy::requiredFor($form)
            && in_array($form['status'], ['published', 'approved'], true);

        Form::update(
            $id,
            $title,
            $description,
            $needsReviewEdit ? 'draft' : $form['status']
        );
        if ($needsReviewEdit) {
            // La traza de aprobación y la fecha de petición no deben sobrevivir
            // a un contenido que nadie miró ni a un formulario que ya no está
            // en la cola: es lo mismo que hacía `submitForReview()` antes.
            Form::returnToDraft($id);
        }
        $message = $needsReviewEdit
            ? 'Formulario actualizado. Como exige revisión, quedó en borrador.'
            : 'Formulario actualizado.';

        if ($json) {
            FormPanel::json($id, $message);
            return;
        }

        Session::flash('success', $message);
        Response::redirect('/forms/' . $id);
        return;
    }

    /**
     * Ajustes OPERATIVOS del formulario (ficha `/forms/{id}`), en un POST aparte
     * del que ya existe para título y descripción.
     *
     * El motivo de que sean un POST propio y no campos más de `update()` es
     * `FormSettings::merge()`: cada puerta escribe solo los bloques que trae
     * (aquí los operativos, en el builder los de diseño), y `normalize()` —que
     * es el que limpia y sanea— arranca de los defaults. Sin el parche, guardar
     * el cupo volvería a su valor de fábrica el color del tema.
     *
     * La lista de bloques es `FormSettings::DASHBOARD_BLOCKS`: el recorte es la
     * puerta, no una comprobación posterior, así que un campo manipulative de
     * más en el POST se queda sin guardar.
     */
    public function updateSettings(int $id): void
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

        $json = Response::wantsJson();

        if (!Csrf::verifyRequest()) {
            if ($json) {
                Response::json(['ok' => false, 'error' => 'Token inválido.'], 419);
                return;
            }
            Session::flash('error', 'Token inválido.');
            Response::redirect('/forms/' . $id);
            return;
        }

        $actuales = Form::settings($id);

        $errores = [];

        // El modo crudo se valida AQUÍ y no solo dentro de
        // `FormSettings::validate()` porque hace falta el valor saneado para
        // fusionar: `validate()` recibe el resultado, así que no puede
        // devolver «el modo no es válido» si antes lo cambiamos por `public`, y
        // quien lo escribió se quedaría creyendo que su formulario sigue
        // cerrado para todo el mundo.
        $modo = trim((string) Request::post('access_mode', (string) $actuales['access']['mode']));
        if (!in_array($modo, FormSettings::ACCESS_MODES, true)) {
            $errores[] = 'El modo de acceso no es válido.';
            $modo = (string) $actuales['access']['mode'];
        }

        $parche = FormSettings::fromDashboardPost(Request::postAll());
        // El identificador viaja en el mismo POST que el modo (su `<select>` está
        // al lado) y `fromDashboardPost()` lo deja puesto si vino. Esta línea le
        // pone encima el modo YA saneado, así que el identificador **sobrevive**:
        // antes era `$parche['access'] = ['mode' => $modo]`, que se lo comía sin
        // decir nada y dejaba un `<select>` que se podía cambiar y no se guardaba
        // —el peor de los fallos de ajustes, porque en pantalla parecía funcionar.
        $parche['access'] = array_merge(is_array($parche['access'] ?? null) ? $parche['access'] : [], ['mode' => $modo]);

        $fusionado = FormSettings::merge($actuales, $parche, FormSettings::DASHBOARD_BLOCKS);

        // Se valida DOS veces y no por redundancia: el parche crudo es lo que
        // conserva los valores que `normalize()` ya saneó (un color que no es
        // hex, un modo inventado) y que por eso hay que mirar antes de
        // fusionar; y el resultado fusionado es lo único que puede detectar las
        // combinaciones con lo que ya había guardado (cerrar antes de abrir,
        // dejar los avisos sin destinatario con los de antes puestos).
        $errores[] = FormSettings::validate($parche);
        $errores[] = FormSettings::validate($fusionado);

        /* Fase 6 — Cambiar el identificador CON GENTE EN LA LISTA no es un cambio de
         * ajustes más, y por eso va aquí y no en `FormSettings::validate()`: el
         * identificador solo significa algo para el listado, así que un error suyo
         * necesita saber si el formulario tiene participantes, y `validate()` no
         * recibe el id del formulario.
         *
         * Y lo que hay que impedir no es un valor raro, es un formulario que deja
         * de dejar entrar a nadie sin decir nada. `identifier_value` está
         * desnormalizada y es la columna por la que la puerta busca: si el dueño
         * pasa de `cedula` a `correo` y las filas siguen con la cédula, la puerta
         * busca el correo de cada quien llega y no encuentra a nadie. Con la lista
         * reescrita (`FormParticipant::reidentificar()`) el problema no aparece,
         * pero solo se puede resolver si nadie se queda sin identidad y si dos
         * personas no pasan a compartir la misma, que es el `UNIQUE` de la puerta.
         *
         * El `code` NO se toca nunca en ese cambio: es la credencial que ya se
         * repartió, y vaciar la lista obligaría a repartirla otra vez. */
        $identificadorAntes = FormParticipant::identifierOf($actuales);
        $identificadorDespues = FormParticipant::identifierOf($fusionado);
        $cambiaIdentificador = $identificadorAntes !== $identificadorDespues;
        $plan = ['a_cambiar' => 0];

        if ($cambiaIdentificador) {
            $plan = FormParticipant::planDeReidentificacion($id, $identificadorDespues);

            if ($plan['sin_identidad'] > 0) {
                $errores[] = sprintf(
                    'No se puede cambiar el identificador a «%s»: %d persona%s de la lista no lo tiene%s. Rellénalo en la lista o quita a quien no lo tenga.',
                    self::rotuloIdentificador($identificadorDespues),
                    $plan['sin_identidad'],
                    $plan['sin_identidad'] === 1 ? '' : 's',
                    $plan['sin_identidad'] === 1 ? '' : 'n'
                );
            } elseif ($plan['repetidos_cuento'] > 0) {
                $errores[] = sprintf(
                    'No se puede cambiar el identificador a «%s»: %d persona%s pasarían a compartir la misma identidad (%s). Corrige la lista antes de cambiarlo.',
                    self::rotuloIdentificador($identificadorDespues),
                    $plan['repetidos_cuento'],
                    $plan['repetidos_cuento'] === 1 ? '' : 's',
                    implode(', ', array_map(static fn(string $v): string => '«' . $v . '»', $plan['repetidos']))
                );
            }
        }

        $errores = array_values(array_unique(array_filter($errores)));

        if ($errores !== []) {
            if ($json) {
                Response::json(['ok' => false, 'error' => implode(' ', $errores)], 422);
                return;
            }
            Session::flash('error', implode(' ', $errores));
            Response::redirect('/forms/' . $id);
            return;
        }

        /* Los ajustes y la reescritura de `identifier_value` van en la MISMA
         * transacción, y no por ordenado: son las dos mitades de una sola decisión.
         * Guardar el ajuste y reescribir después deja, si la segunda falla, un
         * formulario cuyo ajuste dice «correo» y cuya lista dice «cédula» — es decir,
         * cerrado a todo el mundo; y reescribir antes de guardar deja lo mismo al
         * revés. Con `transaccionar()` alrededor de las dos no hay ningún orden en
         * el que quede el formulario a medias, y `Database::transaccionar()` es
         * reentrante: la de `saveSettings()` se apoya en la de aquí.
         *
         * Y `reidentificar()` devuelve cuántas filas cambiaron, así que cuando no
         * hay nada que reescribir (la lista vacía, o el identificador nuevo que ya
         * era el de todas las filas) esto es exactamente el guardado de antes. */
        if ($cambiaIdentificador && $plan['a_cambiar'] > 0) {
            Database::getInstance(Config::db())->transaccionar(static function () use ($id, $fusionado, $identificadorDespues): void {
                Form::saveSettings($id, $fusionado);
                FormParticipant::reidentificar($id, $identificadorDespues);
            });
        } else {
            Form::saveSettings($id, $fusionado);
        }

        // F3 — mismo criterio: con revisión activa, cualquier edición deja el
        // formulario en `draft` para que el dueño decida explícitamente cuándo
        // enviarlo a revisión.
        $needsReviewEdit = ReviewPolicy::requiredFor($form)
            && in_array($form['status'], ['published', 'approved'], true)
            && self::publishedChangesMatter($actuales, $fusionado);

        if ($needsReviewEdit) {
            Form::returnToDraft($id);
        }

        $message = $needsReviewEdit
            ? 'Ajustes guardados. Como exige revisión, el formulario quedó en borrador.'
            : 'Ajustes guardados.';

        if ($json) {
            /* Solo los cuatro huecos del estado. La tarjeta de Acceso ya no se
             * repinta aquí: vive en su propia pantalla (`GET /forms/{id}/access`),
             * que se lee al abrirla, así que guardar el modo no deja ninguna
             * tarjeta desactualizada en la ficha. */
            FormPanel::json($id, $message);
            return;
        }

        Session::flash('success', $message);
        Response::redirect('/forms/' . $id);
        return;
    }

    /**
     * El nombre que el dueño ve del identificador, para los mensajes de error.
     *
     * Vive aquí y no en `FormSettings` porque es texto de interfaz del **panel**.
     * Las etiquetas del desplegable de la ficha son suyas y **están escritas en la
     * vista** (`views/dashboard/forms/show.php`): el error dice «cédula» en minúscula
     * dentro de una frase y el desplegable dice «Cédula» como nombre de columna, y
     * son dos textos distintos. Si un día se renombra a «Documento», hay que
     * cambiar los dos sitios: no comparten fuente, y conviene que no la compartan.
     */
    private static function rotuloIdentificador(string $identificador): string
    {
        return match ($identificador) {
            'correo' => 'correo',
            default  => 'cédula',
        };
    }

    /**
     * F3 — ¿Alguno de los ajustes de esta puerta cambia lo que un visitante se
     * encuentra? Un cambio de color o de idioma no re-puta en cola nada: lo que
     * justifica la revisión es tocar la oferta (acceso, agenda, cupo) o cómo
     * se recoge lo que se manda (avisos, webhook, gracias).
     */
    private static function publishedChangesMatter(array $antes, array $despues): bool
    {
        foreach (['access', 'schedule', 'quota', 'notify', 'webhook', 'thanks'] as $bloque) {
            if (($antes[$bloque] ?? null) !== ($despues[$bloque] ?? null)) {
                return true;
            }
        }
        return false;
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

        // Sin recarga, el destino es `/dashboard` en el sobre y no un 302: la
        // ficha de un formulario borrado ya no existe, así que no hay nada que
        // repintar — solo a dónde ir. `FormPanel::slots()` no sirve aquí por
        // eso, y por eso lleva `$redirect` aparte.
        if (Response::wantsJson()) {
            Response::json([
                'ok'       => true,
                'message'  => 'Formulario eliminado.',
                'redirect' => '/dashboard',
            ]);
            return;
        }

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

        // F3 — Si el formulario exige revisión, publicar desde aquí lo saltaría
        // entero: el botón «Publicar» que el dueño tiene delante equivaldría a
        // aprobarse a uno mismo. La única excepción es que ya esté aprobado: ese
        // formulario YA pasó por la revisión y publicarlo es exactamente el paso
        // siguiente que se le dejó al dueño. Se rechaza con un mensaje que dice
        // qué hacer en su lugar, en vez de un 403 mudo (que el dueño no sabría
        // interpretar).
        if (ReviewPolicy::requiredFor($form) && $form['status'] !== 'approved') {
            $error = 'Este formulario exige revisión: envíalo a revisión para que lo apruebe alguien con el permiso.';
            if (Response::wantsJson()) {
                Response::json(['ok' => false, 'error' => $error], 422);
                return;
            }
            Session::flash('error', $error);
            Response::redirect('/forms/' . $id);
            return;
        }

        Form::setStatus($id, 'published');
        // El trazo de «quién publicó esto» es este y no la aprobación: con el
        // nuevo flujo las dos son acciones de gente distinta en momentos
        // distintos. Sin esta línea, publicar no dejaría rastro en el log.
        \App\Core\SecurityLog::record('form.publish', 'formulario #' . $id . ' (' . (string) $form['slug'] . ')');
        self::afterStatusChange($id, 'Formulario publicado. Ya puedes compartir el enlace.');
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
        self::afterStatusChange($id, 'Formulario cerrado. Ya no acepta respuestas.');
    }

    /**
     * Lo que hacen las dos acciones de estado (`publish()` y `close()`) una vez
     * que el estado está en la BD.
     *
     * Comparten las dos mitades porque un `redirect` a la misma ficha de la que
     * se viene no arregla nada por sí solo: sin recarga, quien cerró el
     * formulario se queda mirando la insignia de «Publicado», el QR de un
     * formulario que ya no acepta respuestas y un botón de «Cerrar» que sigue
     * ahí. El camino JSON devuelve los cuatro huecos de la ficha; el de
     * siempre, el 302 de siempre.
     */
    private static function afterStatusChange(int $id, string $message): void
    {
        if (Response::wantsJson()) {
            FormPanel::json($id, $message);
            return;
        }

        Session::flash('success', $message);
        Response::redirect('/forms/' . $id);
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

        // El dueño (o quien tiene alcance global) puede imprimir su estructura.
        // Un revisor puro, que no puede abrir la ficha editable, sí necesita ver
        // las preguntas para decidir: se le deja esta vista READ-ONLY, y solo del
        // formulario que está en su cola. `in_review` es justo el estado que la
        // bandeja le enseña; un borrador ajeno no es asunto suyo.
        $canEdit = $this->canEdit($form, (int) $user['id']);
        $puedeRevisar = Auth::can('forms.review') && $form['status'] === 'in_review';
        if (!$canEdit && !$puedeRevisar) {
            Response::forbidden();
            return;
        }

        // Los settings salen de la fila que ya trajo `find()`: `Form::settings($id)`
        // volvería a consultar la misma tabla para leer lo que ya está en memoria.
        View::render('dashboard/forms/print', [
            'form'     => $form,
            'user'     => $user,
            'settings' => Form::settingsFrom($form),
            // El revisor no abre la ficha (es del dueño): su vuelta es la bandeja.
            'volverUrl'   => $canEdit ? '/forms/' . $id : '/review',
            'volverLabel' => $canEdit ? 'Volver al formulario' : 'Volver a la bandeja',
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
}