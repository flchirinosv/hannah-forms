<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Access;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\I18n;
use App\Core\Mailer;
use App\Core\PublicUrl;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\SecurityLog;
use App\Core\Session;
use App\Core\View;
use App\Models\Form;
use App\Models\FormAccess;
use App\Services\FormSettings;

/**
 * F4 — Acceso restringido por email.
 *
 * Vive en un controlador aparte de `FillController` por la misma razón por la que
 * F3 sacó la revisión de `FormController`: aquí se mezclan dos puertas que no
 * deben confundirse. La parte **pública** (pedir un enlace, sin sesión) y la
 * **del dueño** (gestionar la lista) comparten archivo porque comparten modelo,
 * pero cada una repite sus comprobaciones enteras: que un visitante pueda pedir
 * un enlace no dice nada sobre quién puede modificar la lista, ni al revés.
 *
 * La respuesta del POST público es **uniforme**: misma página, mismo texto y mismo
 * destino exista el correo en la lista o no. Es la misma defensa que en
 * `/forgot-password`, y sin ella esta página sería un oráculo de «quién tiene
 * acceso a este formulario» para quien probara tres direcciones.
 */
class AccessController
{
    /**
     * Ventana y cupos del envío de enlaces.
     *
     * Más estricto que el del fill (10/10 min) porque cada petición dispara un
     * correo: un buzón lleno es una vía de spam desde la propia instancia. Y el
     * límite por email es por respondent y no solo por IP, porque el caso que
     * duele es el que prueba direcciones contra un mismo formulario.
     */
    private const RL_WINDOW = 900;
    private const RL_PER_IP = 5;
    private const RL_PER_EMAIL = 3;

    // =====================================================================
    // Parte pública
    // =====================================================================

    /**
     * POST /f/{slug}/access/embed — el mismo POST, pero la respuesta se queda
     * dentro del iframe.
     *
     * Es un método aparte y no un parámetro de ruta porque el router rellena los
     * argumentos por los `{...}` de la URL: un segundo parámetro que no aparece
     * en la ruta se quedaría con su default en el camino real y solo cambiaría
     * en los tests, que es la forma más difícil de tener un bug.
     */
    public function requestAccessEmbed(string $slug): void
    {
        $this->requestAccess($slug, true);
    }

    /**
     * POST /f/{slug}/access — pide un enlace de acceso.
     *
     * `$embed` solo cambia la URL de vuelta (el embed vive dentro de un iframe y
     * tiene que quedarse dentro): ni el gate, ni el correo, ni el token cambian.
     */
    public function requestAccess(string $slug, bool $embed = false): void
    {
        $form = Form::findBySlug($slug);
        if ($form === null) {
            Response::notFound();
            return;
        }

        $settings = Form::settings((int) $form['id']);
        $locale = I18n::forRequest($settings);
        $back = '/f/' . $slug . ($embed ? '/embed' : '');

        // Un formulario público no tiene nada que identificar. Sin este chequeo
        // la ruta seguiría «funcionando» con cualquier email y emitiría tokens
        // para un formulario abierto: un `?t=` pegado sobre la URL de un fill
        // normal dejaría de ser inocuo y crearía filas sin propósito.
        if (!FormAccess::isRestricted($settings)) {
            Response::notFound();
            return;
        }
        if ($form['status'] !== 'published') {
            Response::abort(403, I18n::t('err.not_accepting', $locale));
            return;
        }

        // El `csrf` de la ruta ya cubrió el caso HTTP; aquí se repite para el
        // camino CLI de los tests, donde el middleware no corre. Ante un token
        // inválido se responde con el MISMO texto y destino que el éxito: si esta
        // rama deltara al visitante, el corte distinguiría «petición válida» de
        // «petición manipulada» sin necesidad.
        if (!Csrf::verifyRequest()) {
            $this->uniformAnswer($locale, $back);
            return;
        }

        $email = FormAccess::normalizeEmail((string) Request::post('email', ''));
        $ip = Request::ip();
        $emailKey = hash('sha256', $email);

        // Rate limit ANTES de mirar la allowlist, y no por gusto: el camino que
        // encuentra el email y el que no lo encuentra hacen cosas distintas, así
        // que el tiempo de respuesta acaba distinguiendo quién tiene acceso. Con
        // el corte antes, ambos caminos se parecen.
        //
        // El `recordHit` va en los DOS scopes y solo si la consulta dejó pasar:
        // `isAllowed()` lee, no cuenta. Un «te lo compro» por cada email válido es
        // exactamente el tráfico que hay que poder cortar, y sin contar aquí el
        // límite no existiría.
        if (!RateLimiter::isAllowed('form_access', $ip, self::RL_PER_IP, self::RL_WINDOW)) {
            SecurityLog::record('rate_limit.form_access', 'bloqueo por IP (' . self::RL_PER_IP . ' / 15 min)');
            $this->uniformAnswer($locale, $back);
            return;
        }
        RateLimiter::recordHit('form_access', $ip, self::RL_WINDOW);
        if (!RateLimiter::isAllowed('form_access_email', $emailKey, self::RL_PER_EMAIL, self::RL_WINDOW)) {
            SecurityLog::record('rate_limit.form_access', 'bloqueo por email (' . self::RL_PER_EMAIL . ' / 15 min)');
            $this->uniformAnswer($locale, $back);
            return;
        }
        RateLimiter::recordHit('form_access_email', $emailKey, self::RL_WINDOW);

        // Email con formato inválido: se responde igual. Validar el formato NO
        // filtra nada (aquí no se ha consultado la BD), pero unificar el texto
        // evita que alguien «mejore» esta rama después y abra la enumeración sin
        // darse cuenta.
        if (!FormAccess::isValidEmail($email)) {
            $this->uniformAnswer($locale, $back);
            return;
        }

        if (FormAccess::isAllowed((int) $form['id'], $email, FormAccess::mode($settings))) {
            try {
                $token = FormAccess::issue((int) $form['id'], $email, $ip);
                $this->sendAccessMail($form, $email, $token, $locale);
                SecurityLog::record('access.link_sent', (string) $form['id'] . ' → ' . $email);
            } catch (\Throwable $e) {
                // Sin correo no hay token útil para nadie. Se registra para el log
                // del admin y se responde LO MISMO: el visitante no puede hacer
                // nada con este fallo, y el mensaje uniforme es el contrato.
                error_log('[AccessController] correo de acceso no enviado (form '
                    . (int) $form['id'] . '): ' . $e->getMessage());
            }
        }

        $this->uniformAnswer($locale, $back);
    }

    /**
     * La respuesta ÚNICA: mismo texto y mismo destino, haya acceso o no.
     *
     * Todas las ramas que se cortan antes (rate limit, formato inválido, CSRF)
     * acaban aquí, y las que sueltan el token también: por eso es un `return` a
     * un único sitio y no un texto repetido cinco veces que se desincroniza.
     */
    private function uniformAnswer(string $locale, string $back): void
    {
        Session::flash('success', I18n::t('access.sent_body', $locale));
        Response::redirect($back);
    }

    /**
     * Correo con el enlace.
     *
     * Sale en el idioma que resolvió la página de identificación, no en el de la
     * app: quien pide el enlace está delante del formulario en un idioma concreto
     * y lo va a leer ahí. (Al revés que el correo de contraseña, que lo lee otra
     * persona con otro navegador: ver `AccountController::sendResetMail`.)
     */
    private function sendAccessMail(array $form, string $email, string $token, string $locale): void
    {
        $url = PublicUrl::accessUrl((string) $form['slug'], $token);

        Mailer::send(
            [$email],
            I18n::t('mail.access_subject', $locale),
            View::partial('mail/form_access', [
                'form'   => $form,
                'url'    => $url,
                'ttl'    => FormAccess::TTL_DAYS,
                'locale' => $locale,
            ]),
            I18n::t('mail.access_alt', $locale, [
                'form' => (string) $form['title'],
                'url'  => $url,
            ])
        );
    }

    // =====================================================================
    // Parte del dueño: la allowlist
    // =====================================================================

    /**
     * POST /forms/{id}/access/email — añade un email a la lista.
     *
     * Es bimodal a propósito: si el POST viene de `fetch` (el rótulo
     * `X-Requested-With`) devuelve JSON con el HTML de la lista ya rerenderizado
     * y el contador; si no, el 302 con flash de siempre. La misma puerta, el
     * mismo mensaje y las mismas reglas en los dos caminos: el `fetch` es una
     * comodidad, no una segunda puerta con su propia validación (que es como
     * aparecerían dos definiciones de «email válido» divergiendo).
     *
     * Devolver el HTML y no la lista de emails en JSON es lo que evita que el
     * JS tenga que saber cómo se pinta una fila: el partial
     * `dashboard/forms/_access_list` es el único sitio, y el GET y el POST lo
     * comparten.
     */
    public function addEmail(int $id): void
    {
        $form = $this->ownedForm($id, 'add_email');
        if ($form === null) {
            return;
        }

        $settings = Form::settings($id);
        $locale = I18n::forRequest($settings);
        $json = self::wantsJson();

        if (!Csrf::verifyRequest()) {
            if ($json) {
                Response::json(['ok' => false, 'error' => I18n::t('err.csrf', $locale)], 419);
            } else {
                $this->back($id, 'error', I18n::t('err.csrf', $locale));
            }
            return;
        }
        // Solo en `allowlist`. En modo `email` la lista no es la puerta: aceptar
        // Emails «por si acaso» haría creer al dueño que el formulario es más
        // cerrado de lo que es, que es el error justo contra el que existe esta
        // opción.
        if (FormAccess::mode($settings) !== FormSettings::ACCESS_ALLOWLIST) {
            $error = I18n::t('access.not_allowlist_mode', $locale);
            if ($json) {
                Response::json(['ok' => false, 'error' => $error], 422);
            } else {
                $this->back($id, 'error', $error);
            }
            return;
        }

        $email = FormAccess::normalizeEmail((string) Request::post('email', ''));
        if (!FormAccess::isValidEmail($email)) {
            $error = I18n::t('access.invalid_email', $locale);
            if ($json) {
                Response::json(['ok' => false, 'error' => $error], 422);
            } else {
                $this->back($id, 'error', $error);
            }
            return;
        }
        if (!FormAccess::addEmail($id, $email)) {
            $error = I18n::t('access.already_added', $locale, ['email' => $email]);
            if ($json) {
                Response::json(['ok' => false, 'error' => $error], 422);
            } else {
                $this->back($id, 'error', $error);
            }
            return;
        }

        if (!$json) {
            $this->back($id, 'success', I18n::t('access.added', $locale, ['email' => $email]));
            return;
        }

        Response::json([
            'ok'      => true,
            'email'   => $email,
            'message' => I18n::t('access.added', $locale, ['email' => $email]),
            /* `html` va keyed por el `id` del hueco (`accessList`), como en
             * `removeEmail()` y en las cuatro acciones de la ficha: el JS solo
             * sustituye el `innerHTML` de los ids que vienen y no sabe cómo se
             * pinta una fila. Aquí era una CADENA suelta, y como el
             * `vfApplySlots()` de `app.js` recorre un mapa se la saltaba: el
             * alta se quedaba en el POST nativo y recargaba la página. */
            'html'    => [
                'accessList' => View::partial('dashboard/forms/_access_list', [
                    'accessList'  => FormAccess::allowlistFor($id, '', 200),
                    'accessCount' => FormAccess::allowlistCount($id),
                    'formId'      => $id,
                ]),
            ],
            'count'   => FormAccess::allowlistCount($id),
        ]);
    }

    /**
     * ¿El POST viene de un `fetch` de nuestra casa?
     *
     * Ahora la pregunta vive en `Response::wantsJson()`, que es donde la hacen
     * las cinco acciones de la ficha también. Este método se queda porque es
     * la puerta de las tres acciones de la allowlist y su docblock explica el
     * rótulo; delegar mantiene las dos cosas en un sitio sin dejar de apuntar
     * al viejo.
     */
    public static function wantsJson(): bool
    {
        return Response::wantsJson();
    }

    /**
     * POST /forms/{id}/access/email/remove — quita un email y revoca sus enlaces.
     *
     * Bimodal como `addEmail()`: si el POST viene de un `fetch`, devuelve el
     * MISMO partial de la lista que pintó el alta. Es el mismo truco y por el
     * mismo motivo —que el JS no sepa cómo se pinta una fila—, y además el
     * borrado es la operación que peor se lleva sin recarga: quitar a diez
     * correos de uno en uno recargando diez veces, con el panel de acceso
     * desplegado en cada recarga.
     */
    public function removeEmail(int $id): void
    {
        $form = $this->ownedForm($id, 'remove_email');
        if ($form === null) {
            return;
        }

        $settings = Form::settings($id);
        $locale = I18n::forRequest($settings);
        $json = self::wantsJson();

        if (!Csrf::verifyRequest()) {
            if ($json) {
                Response::json(['ok' => false, 'error' => I18n::t('err.csrf', $locale)], 419);
            } else {
                $this->back($id, 'error', I18n::t('err.csrf', $locale));
            }
            return;
        }

        $email = FormAccess::normalizeEmail((string) Request::post('email', ''));
        if (!FormAccess::isValidEmail($email)) {
            $error = I18n::t('access.invalid_email', $locale);
            if ($json) {
                Response::json(['ok' => false, 'error' => $error], 422);
            } else {
                $this->back($id, 'error', $error);
            }
            return;
        }

        FormAccess::removeEmail($id, $email);
        $message = I18n::t('access.removed', $locale, ['email' => $email]);

        if (!$json) {
            $this->back($id, 'success', $message);
            return;
        }

        Response::json([
            'ok'      => true,
            'email'   => $email,
            'message' => $message,
            'html'    => [
                'accessList' => View::partial('dashboard/forms/_access_list', [
                    'accessList'  => FormAccess::allowlistFor($id, '', 200),
                    'accessCount' => FormAccess::allowlistCount($id),
                    'formId'      => $id,
                ]),
            ],
            'count'   => FormAccess::allowlistCount($id),
        ]);
    }

    /**
     * POST /forms/{id}/access/link — emite un enlace para un email de la lista y
     * lo devuelve en JSON para copiarlo.
     *
     * Es el segundo camino del que habla el requisito: el dueño no depende del
     * SMTP para invitar, puede mandar el enlace por donde quiera. El token se
     * devuelve EN CLARO porque se acaba de emitir: es la única vez que existe
     * fuera del correo, igual que en `PasswordReset::issue()`.
     *
     * Solo en `allowlist` y solo para un email que YA esté en la lista: emitir
     * enlaces para quien sea convertiría el copiador en un generador de accesos
     * para el formulario entero, y con eso el modo `allowlist` no restringiría
     * nada.
     */
    public function copyLink(int $id): void
    {
        $form = $this->ownedForm($id, 'copy_link');
        if ($form === null) {
            return;
        }

        $settings = Form::settings($id);
        $locale = I18n::forRequest($settings);

        if (!Csrf::verifyRequest()) {
            Response::json(['ok' => false, 'error' => I18n::t('err.csrf', $locale)], 419);
            return;
        }
        if (FormAccess::mode($settings) !== FormSettings::ACCESS_ALLOWLIST) {
            Response::json(['ok' => false, 'error' => I18n::t('access.not_allowlist_mode', $locale)], 422);
            return;
        }

        $email = FormAccess::normalizeEmail((string) Request::post('email', ''));
        if (!FormAccess::isValidEmail($email)
            || !FormAccess::isAllowed($id, $email, FormSettings::ACCESS_ALLOWLIST)) {
            Response::json(['ok' => false, 'error' => I18n::t('access.not_in_list', $locale)], 422);
            return;
        }

        $token = FormAccess::issue($id, $email, Request::ip());
        SecurityLog::record('access.link_issued_by_owner', (string) $id . ' → ' . $email);

        Response::json([
            'ok'  => true,
            'url' => PublicUrl::accessUrl((string) $form['slug'], $token),
        ]);
    }

    // ---- Utilidades ----

    /**
     * Carga el formulario exigiendo que el visitante sea su dueño (o tenga
     * `forms.manage_all`). Devuelve `null` con la respuesta ya emitida, para que
     * quien llama solo tenga que hacer `return`.
     */
    private function ownedForm(int $id, string $action): ?array
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
            SecurityLog::record('authz.denied', $action . ' acceso /forms/' . $id);
            Response::forbidden();
            return null;
        }

        return $form;
    }

    private function back(int $id, string $type, string $message): void
    {
        Session::flash($type, $message);
        // El alta y el quitar de la allowlist se hacen en la pantalla de
        // participantes (la tarjeta de Acceso vive allí desde el rediseño), y el
        // ancla `#access` es el id de esa misma tarjeta.
        Response::redirect('/forms/' . $id . '/access#access');
    }
}
