<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppSettings;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\InstanceSettings;
use App\Core\Mailer;
use App\Core\MailSettings;
use App\Core\Request;
use App\Core\Response;
use App\Core\Secret;
use App\Core\SecurityLog;
use App\Core\Session;
use App\Core\TurnstileSettings;
use App\Core\UploadSettings;
use App\Core\DashboardLimitSettings;
use App\Core\SessionSettings;
use App\Core\NetworkSettings;
use App\Core\View;
use App\Models\Role;
use App\Models\RoleGroup;
use App\Models\User;
use App\Models\Form;
use App\Services\ReviewPolicy;

/**
 * Panel de administración.
 *
 * El middleware `admin` ya pide `admin.access`; dentro de cada acción se
 * vuelve a comprobar el permiso concreto (`admin.users`, `admin.groups`, ...)
 * como defensa en profundidad y para que un grupo con `admin.access` (pero sin
 * el resto) no abra acciones que no le corresponden.
 */
class AdminController
{
    // ------------------------------------------------------------------
    // Usuarios (/admin)
    // ------------------------------------------------------------------

    public function index(): void
    {
        if (!Auth::can('admin.users')) {
            Response::forbidden();
            return;
        }

        // La búsqueda y los filtros viajan en la URL y se resuelven en SQL
        // (`User::page()`), como en el listado de formularios: un filtro de
        // cliente con el `LIMIT` ya aplicado solo oculta las filas de la página.
        $filtros = [
            'q'      => trim((string) Request::query('q', '')),
            'rol'    => (string) Request::query('rol', ''),
            'estado' => (string) Request::query('estado', ''),
        ];
        $listado = User::page($filtros, max(1, (int) Request::query('page', '1')));

        View::render('admin/users', [
            'users'             => $listado['items'],
            'listado'           => $listado,
            'filtros'           => $filtros,
            'roles'             => Role::all(),
            'groups'            => RoleGroup::all(),
            'userGroups'        => User::groupsMap(),
            'userPermissions'   => User::permissionsMap(),
            // Desglose efectivo por usuario (rol / grupos / extra): lo pinta la
            // primera columna del menú `⋯` de la fila, que es lo que hace
            // auditable una concesión directa.
            'userEffective'     => User::effectivePermissionsMap(),
            'permissionCatalog' => self::catalog(),
            'canEditGroups'     => Auth::can('admin.groups'),
            'canEditPermissions' => Auth::can('admin.users'),
            'csrf'              => Csrf::token(),
        ]);
    }

    /**
     * GET /admin/settings — ajustes de la INSTANCIA (registro y revisión).
     *
     * Son de la instalación, no de la cuenta de nadie, así que viven en su
     * propia pantalla y con su propio permiso (`admin.access`): quien la gestiona
     * los cambia, pero no necesita poder tocar la cuenta de nadie. Antes iban
     * apilados en una columna de `/admin`, donde competían en altura con el
     * listado de usuarios sin ser de esa pantalla.
     */
    public function settings(): void
    {
        if (!Auth::can('admin.access')) {
            Response::forbidden();
            return;
        }

        $mail = MailSettings::all();
        $instance = InstanceSettings::all();
        View::render('admin/settings', [
            'registrationEnabled' => AuthController::registrationEnabled(),
            'reviewRequired'      => ReviewPolicy::policyOn(),
            'reviewNotifyEmail'   => ReviewPolicy::notifyEmail(),
            'instancePublicUrl'   => $instance['stored_public_url'],
            'instanceTimezone'    => $instance['stored_timezone'],
            'instanceLocale'      => $instance['stored_locale'],
            'envPublicUrl'        => $instance['env_public_url'],
            'envTimezone'         => $instance['env_timezone'],
            'envLocale'           => $instance['env_locale'],
            'timezoneOptions'     => \DateTimeZone::listIdentifiers(),
            'brandName'           => \App\Core\Brand::name(),
            'brandColor'          => \App\Core\Brand::color(),
            'brandHasLogo'        => \App\Core\Brand::hasLogo(),
            'brandHasFavicon'     => \App\Core\Brand::hasFavicon(),
            'brandLogoUrl'        => \App\Core\Brand::logoUrl(),
            'brandFaviconUrl'     => \App\Core\Brand::faviconUrl(),
            'mailHost'            => $mail['host'],
            'mailPort'            => (int) $mail['port'],
            'mailEncryption'      => (string) $mail['encryption'],
            'mailUser'            => $mail['user'],
            'mailFromAddress'     => $mail['from_address'],
            'mailFromName'        => $mail['from_name'],
            'mailTimeout'         => (int) $mail['timeout'],
            // Nunca se pasa la contraseña: solo si hay una guardada y utilizable.
            'mailPasswordStored'  => MailSettings::passwordStored(),
            'mailConfigured'      => MailSettings::isConfigured(),
            'mailSecretAvailable' => \App\Core\Secret::isAvailable(),
            'turnstile'           => TurnstileSettings::all(),
            'secretAvailable'     => \App\Core\Secret::isAvailable(),
            'uploads'             => UploadSettings::all(),
            'dashboardLimits'     => DashboardLimitSettings::all(),
            'sessionSettings'     => SessionSettings::all(),
            'networkSettings'     => NetworkSettings::all(),
            'csrf'                => Csrf::token(),
        ]);
    }

    /**
     * POST /admin/settings/registration — abre o cierra el registro público.
     *
     * El interruptor se guarda por fetch, así que esta acción responde JSON.
     * Y responde JSON con `ok: false` en vez de un flash porque un fetch que
     * recibe un 302 sigue la redirección y descarga la página entera en el
     * proceso: el JS vería un `200` con la pantalla dentro y no sabría que el
     * guardado falló.
     *
     * El permiso es `admin.access`, no `admin.users`: quien puede entrar al
     * panel administra la instalación, y abrir el registro no es tocar la
     * cuenta de nadie. Es también el mismo permiso que exige el middleware
     * `admin` de la ruta, así que no hay forma de llegar aquí con menos.
     *
     * Va al log de seguridad porque es la puerta que decide si cualquiera puede
     * crear una cuenta en la instalación: es de las pocas escrituras de
     * configuración con consecuencias sobre el acceso de terceros.
     */
    public function updateRegistration(): void
    {
        if (!Auth::can('admin.access')) {
            Response::json(['ok' => false, 'error' => 'No tienes permiso para cambiar los ajustes de la instalación.'], 403);
            return;
        }
        if (!Csrf::verifyRequest()) {
            Response::json(['ok' => false, 'error' => 'Token de seguridad inválido.'], 419);
            return;
        }

        // Ausente = no marcado. No se mira `Request::post('enabled')` con un
        // default: una casilla desmarcada no llega, y `post('enabled', '0')`
        // daría a entender que siempre hay un valor enviado.
        $enabled = (string) Request::post('enabled', '0') === '1';

        if (!AppSettings::setBool(AppSettings::REGISTRATION, $enabled)) {
            Response::json([
                'ok'     => false,
                'error'  => 'No se pudo guardar el ajuste. ¿Falta ejecutar las migraciones?',
                'enabled' => AuthController::registrationEnabled(),
            ], 500);
            return;
        }

        // El detalle es texto plano (el `user_id` lo pone `SecurityLog` solo):
        // el evento ya dice QUIÉN lo hizo, porque `record()` guarda el usuario
        // de la sesión.
        SecurityLog::record(
            $enabled ? 'instance.registration_on' : 'instance.registration_off',
            'registro público ' . ($enabled ? 'abierto' : 'cerrado') . ' desde el panel'
        );

        Response::json([
            'ok'      => true,
            'enabled' => $enabled,
            'message' => $enabled
                ? 'Registro público abierto: cualquiera con la dirección puede crear su cuenta.'
                : 'Registro público cerrado. Las cuentas se crean desde aquí.',
        ]);
    }

    /**
     * POST /admin/settings/review — política de revisión de la instancia.
     *
     * Dos interruptores de una vez: `review_required` (toda encuesta pasa por
     * la bandeja antes de publicarse, salvo dueños con el permiso
     * `forms.publish_without_review`) y `review_notify_email` (avisar por
     * correo a los revisores al enviar).
     *
     * A diferencia del registro público, este POST es NATIVO (no fetch): la
     * pantalla no repinta ningún hueco, así que se responde con el PRG de
     * siempre (`flash` + redirect a `/admin/settings`, que es donde se edita)
     * y el camino sin JavaScript es exactamente el mismo. Un fetch aquí solo
     * añadiría una ruta paralela.
     *
     * El permiso es `admin.access` (el mismo del middleware `admin` y el que la
     * vista exige para pintar la tarjeta), no `admin.users`: quien entra al
     * panel administra la instalación.
     */
    public function updateReviewPolicy(): void
    {
        if (!Auth::can('admin.access')) {
            Response::forbidden();
            return;
        }
        if (!Csrf::verifyRequest()) {
            Session::flash('error', 'Token de seguridad inválido.');
            Response::redirect('/admin/settings');
            return;
        }

        // Ausente = desmarcado (una casilla sin marcar no llega). El
        // `<input type="hidden">` que la vista pone delante con el MISMO nombre
        // asegura que el apagado explícito también viaje.
        $required = (string) Request::post('review_required', '0') === '1';
        $notify   = (string) Request::post('review_notify_email', '0') === '1';

        $ok = AppSettings::setBool(AppSettings::REVIEW_REQUIRED, $required);
        $ok = AppSettings::setBool(AppSettings::REVIEW_NOTIFY_EMAIL, $notify) && $ok;

        if (!$ok) {
            Session::flash('error', 'No se pudo guardar el ajuste. ¿Falta ejecutar las migraciones?');
            Response::redirect('/admin/settings');
            return;
        }

        // Mismo prefijo `instance.` que el registro: es de la instalación
        // entera, no de una cuenta ni de un formulario. El usuario lo pone
        // `SecurityLog::record()` solo.
        SecurityLog::record(
            $required ? 'instance.review_on' : 'instance.review_off',
            'revisión de formularios ' . ($required ? 'activada' : 'desactivada')
            . ($notify ? ' (aviso por correo activado)' : ' (aviso por correo desactivado)')
            . ' desde el panel'
        );

        Session::flash('success', $required
            ? 'Revisión activada: los formularios pasan por la bandeja antes de publicarse.'
            : 'Revisión desactivada: cada quien publica su formulario sin revisión.');

        Response::redirect('/admin/settings');
    }

    // ------------------------------------------------------------------
    // Marca de la instancia (AG2)
    // ------------------------------------------------------------------

    /**
     * POST /admin/settings/brand — nombre y color primario de la instancia.
     *
     * POST nativo con PRG, como la revisión: la tarjeta de marca no repinta
     * huecos, así que el camino sin JavaScript es exactamente el mismo. El logo
     * y el favicon van por sus propias rutas (multipart), porque un archivo no
     * viaja en un `application/x-www-form-urlencoded` ni se puede validar junto
     * a un texto.
     *
     * Semántica de «vaciar»: un nombre en blanco BORRA la fila (vuelve al
     * `APP_NAME` del `.env`), no guarda un `''`. Un color en blanco también
     * borra la fila (vuelve al default). Es la diferencia entre «no lo he
     * tocado» y «déjalo en blanco» que `AppSettings::set()` distingue.
     */
    public function updateBrand(): void
    {
        if (!Auth::can('admin.access')) {
            Response::forbidden();
            return;
        }
        if (!Csrf::verifyRequest()) {
            Session::flash('error', 'Token de seguridad inválido.');
            Response::redirect('/admin/settings');
            return;
        }

        $name  = trim((string) Request::post('instance_name', ''));
        $color = trim((string) Request::post('instance_color', ''));

        if (mb_strlen($name) > 100) {
            Session::flash('error', 'El nombre de la instancia no puede superar los 100 caracteres.');
            Response::redirect('/admin/settings');
            return;
        }

        // El color se valida ANTES de guardar: `Brand::themeCss()` lo inyecta en
        // un `<style>` sin escapar, así que un valor que no sea `#rrggbb` no debe
        // llegar nunca a la fila (aunque `color()` también lo filtre al salir).
        if ($color !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $color) !== 1) {
            Session::flash('error', 'El color debe ser un valor hex como #5b5bd6.');
            Response::redirect('/admin/settings');
            return;
        }

        $ok = AppSettings::set(\App\Core\Brand::NAME, $name === '' ? null : $name);
        $ok = AppSettings::set(\App\Core\Brand::COLOR, $color === '' ? null : strtolower($color)) && $ok;

        if (!$ok) {
            Session::flash('error', 'No se pudo guardar la marca. ¿Falta ejecutar las migraciones?');
            Response::redirect('/admin/settings');
            return;
        }

        SecurityLog::record('instance.brand_update', 'marca de la instancia actualizada desde el panel');
        Session::flash('success', 'Marca actualizada.');
        Response::redirect('/admin/settings');
    }

    /**
     * POST /admin/settings/brand/logo — sube el logo de la instancia.
     * POST /admin/settings/brand/favicon — sube el favicon.
     *
     * Dos rutas que comparten este cuerpo porque hacen lo mismo con distinto
     * destino, y separarlas en dos métodos idénticos solo daría dos sitios donde
     * olvidar el log. El `kind` lo fija el controlador, nunca el POST: el
     * archivo no elige dónde se guarda.
     */
    public function uploadBrandImage(string $kind): void
    {
        if (!Auth::can('admin.access')) {
            Response::forbidden();
            return;
        }
        if (!Csrf::verifyRequest()) {
            Session::flash('error', 'Token de seguridad inválido.');
            Response::redirect('/admin/settings');
            return;
        }

        if (!in_array($kind, ['logo', 'favicon'], true)) {
            Response::notFound();
            return;
        }

        $file = $_FILES['brand_image'] ?? null;
        if (!is_array($file)) {
            Session::flash('error', 'No se recibió ninguna imagen.');
            Response::redirect('/admin/settings');
            return;
        }

        $result = \App\Core\Brand::saveUpload($kind, $file);
        if (!$result['ok']) {
            Session::flash('error', (string) $result['error']);
            Response::redirect('/admin/settings');
            return;
        }

        SecurityLog::record('instance.brand_update', $kind . ' de la instancia actualizado desde el panel');
        Session::flash('success', ucfirst($kind) . ' actualizado.');
        Response::redirect('/admin/settings');
    }

    /**
     * POST /admin/settings/brand/logo/remove — quita el logo.
     * POST /admin/settings/brand/favicon/remove — quita el favicon.
     */
    public function removeBrandImage(string $kind): void
    {
        if (!Auth::can('admin.access')) {
            Response::forbidden();
            return;
        }
        if (!Csrf::verifyRequest()) {
            Session::flash('error', 'Token de seguridad inválido.');
            Response::redirect('/admin/settings');
            return;
        }

        if (!in_array($kind, ['logo', 'favicon'], true)) {
            Response::notFound();
            return;
        }

        \App\Core\Brand::remove($kind);

        SecurityLog::record('instance.brand_update', $kind . ' de la instancia eliminado desde el panel');
        Session::flash('success', ucfirst($kind) . ' eliminado: se vuelve al icono por defecto.');
        Response::redirect('/admin/settings');
    }

    // ------------------------------------------------------------------
    // Correo (SMTP) de la instancia (AG3)
    // ------------------------------------------------------------------

    /**
     * POST /admin/settings/mail — servidor SMTP de la instancia.
     *
     * PRG como la marca y la revisión. La contraseña se guarda CIFRADA
     * (`MailSettings::save()` + `Secret`): el formulario la manda como campo de
     * solo escritura y nunca se devuelve. Un `POST` sin contraseña no la toca;
     * para borrarla hay que marcar la casilla `clear_password`.
     */
    public function updateMail(): void
    {
        if (!Auth::can('admin.access')) {
            Response::forbidden();
            return;
        }
        if (!Csrf::verifyRequest()) {
            Session::flash('error', 'Token de seguridad inválido.');
            Response::redirect('/admin/settings');
            return;
        }

        $result = MailSettings::save([
            'host'            => Request::post('mail_host', ''),
            'port'            => Request::post('mail_port', ''),
            'encryption'      => Request::post('mail_encryption', ''),
            'user'            => Request::post('mail_user', ''),
            'password'        => Request::post('mail_password', null),
            'clear_password'  => Request::post('clear_password', null),
            'from_address'    => Request::post('mail_from_address', ''),
            'from_name'       => Request::post('mail_from_name', ''),
            'timeout'         => Request::post('mail_timeout', ''),
        ]);

        if (!$result['ok']) {
            Session::flash('error', (string) $result['error']);
            Response::redirect('/admin/settings');
            return;
        }

        SecurityLog::record('instance.mail_update', 'configuración SMTP de la instancia actualizada desde el panel');
        Session::flash('success', 'Correo actualizado.');
        Response::redirect('/admin/settings');
    }

    /**
     * POST /admin/settings/mail/test — manda un correo de prueba.
     *
     * Es lo que hace usable la tarjeta: guardar `smtp.gmail.com` sin poder
     * probarlo deja al operador adivinando. El destino por defecto es el correo
     * de quien lo pide (así la prueba llega a algún sitio real), y se puede
     * escribir otro. Un fallo de SMTP NO es un error de servidor: se cuenta como
     * aviso, con el mensaje de `Mailer` (que es lo que dice QUÉ falló: conexión,
     * autenticación, STARTTLS…).
     */
    public function sendTestMail(): void
    {
        if (!Auth::can('admin.access')) {
            Response::forbidden();
            return;
        }
        if (!Csrf::verifyRequest()) {
            Session::flash('error', 'Token de seguridad inválido.');
            Response::redirect('/admin/settings');
            return;
        }

        $to = trim((string) Request::post('test_to', ''));
        if ($to === '') {
            $me = Auth::user();
            $to = is_array($me) ? trim((string) ($me['email'] ?? '')) : '';
        }
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            Session::flash('error', 'Escribe una dirección de destino válida para la prueba.');
            Response::redirect('/admin/settings');
            return;
        }

        if (!Mailer::isConfigured()) {
            Session::flash('error', 'No hay SMTP configurado: rellena el servidor y guarda antes de probar.');
            Response::redirect('/admin/settings');
            return;
        }

        $subject = 'Correo de prueba de ' . \App\Core\Brand::name();
        $nombre = \App\Core\Brand::name();
        $html = '<p>Si estás leyendo esto, el envío de correo de <strong>' . View::escape($nombre) . '</strong> funciona.</p>'
            . '<p>Este mensaje se envió desde <em>Ajustes de la instancia</em> para comprobar la configuración SMTP.</p>';
        $alt = "Si estas leyendo esto, el envio de correo de {$nombre} funciona.\n\n"
            . "Este mensaje se envio desde Ajustes de la instancia para comprobar la configuracion SMTP.";

        try {
            Mailer::send([$to], $subject, $html, $alt);
        } catch (\Throwable $e) {
            SecurityLog::record('instance.mail_test', 'prueba SMTP fallida: ' . $e->getMessage());
            Session::flash('error', 'No se pudo enviar la prueba: ' . $e->getMessage());
            Response::redirect('/admin/settings');
            return;
        }

        SecurityLog::record('instance.mail_test', 'prueba SMTP enviada');
        Session::flash('success', 'Correo de prueba enviado a ' . $to . '.');
        Response::redirect('/admin/settings');
    }

    // ------------------------------------------------------------------
    // Identidad operativa de la instancia (AG4)
    // ------------------------------------------------------------------

    /**
     * POST /admin/settings/instance — URL pública, zona horaria e idioma.
     *
     * PRG como marca, correo y revisión. Los tres valores son un PIN que gana
     * sobre el `.env` (`APP_PUBLIC_URL`, `APP_TIMEZONE`, `APP_LOCALE`), y vaciar
     * cualquiera BORRA su fila (vuelve al `.env` / al default), no guarda un
     * `''`. Los consumidores (`PublicUrl`, `Clock`, `I18n`) leen la fila en cada
     * petición, así que el cambio se ve en la siguiente carga sin reiniciar nada.
     *
     * El permiso es `admin.access`, el mismo del middleware `admin`: son ajustes
     * de la instalación entera, no de la cuenta de nadie.
     */
    public function updateInstance(): void
    {
        if (!Auth::can('admin.access')) {
            Response::forbidden();
            return;
        }
        if (!Csrf::verifyRequest()) {
            Session::flash('error', 'Token de seguridad inválido.');
            Response::redirect('/admin/settings');
            return;
        }

        $result = InstanceSettings::save([
            'public_url' => Request::post('instance_public_url', ''),
            'timezone'   => Request::post('instance_timezone', ''),
            'locale'     => Request::post('instance_locale', ''),
        ]);

        if (!$result['ok']) {
            Session::flash('error', (string) $result['error']);
            Response::redirect('/admin/settings');
            return;
        }

        SecurityLog::record('instance.settings_update', 'identidad de la instancia (URL, zona horaria e idioma) actualizada desde el panel');
        Session::flash('success', 'Identidad de la instancia actualizada.');
        Response::redirect('/admin/settings');
    }

    /**
     * POST /admin/settings/turnstile — claves de Cloudflare Turnstile.
     *
     * PRG como marca, correo e identidad. El anti-bot es opt-in por formulario,
     * pero las claves son de la INSTANCIA: sin ellas, un formulario que pide
     * Turnstile no se sirve. La site key es pública; la secret se guarda CIFRADA
     * (`TurnstileSettings::save()` + `Secret`) y es de solo escritura: el
     * formulario la manda a vaciar salvo que se teclee una nueva, y borrarla
     * exige la casilla `clear_secret`. `save()` rechaza dejar una sola de las
     * dos: medio configurado es peor que no configurado.
     *
     * El permiso es `admin.access`, el del middleware `admin`: son ajustes de la
     * instalación entera, no de la cuenta de nadie.
     */
    public function updateTurnstile(): void
    {
        if (!Auth::can('admin.access')) {
            Response::forbidden();
            return;
        }
        if (!Csrf::verifyRequest()) {
            Session::flash('error', 'Token de seguridad inválido.');
            Response::redirect('/admin/settings');
            return;
        }

        $result = TurnstileSettings::save([
            'site_key'    => Request::post('turnstile_site_key', ''),
            'secret'      => Request::post('turnstile_secret', null),
            'clear_secret' => Request::post('clear_secret', null),
        ]);

        if (!$result['ok']) {
            Session::flash('error', (string) $result['error']);
            Response::redirect('/admin/settings');
            return;
        }

        SecurityLog::record('instance.turnstile_update', 'claves de Cloudflare Turnstile de la instancia actualizadas desde el panel');
        Session::flash('success', 'Claves de Turnstile actualizadas.');
        Response::redirect('/admin/settings');
    }

    /**
     * POST /admin/settings/uploads — límites de subida de la instancia.
     *
     * PRG como los demás ajustes. El campo solo puede restringir, nunca ampliar;
     * el límite efectivo tiene en cuenta `upload_max_filesize` de PHP.
     */
    public function updateUploads(): void
    {
        if (!Auth::can('admin.access')) {
            Response::forbidden();
            return;
        }
        if (!Csrf::verifyRequest()) {
            Session::flash('error', 'Token de seguridad inválido.');
            Response::redirect('/admin/settings');
            return;
        }

        $result = UploadSettings::save([
            'max_size_mb' => Request::post('upload_max_size_mb', ''),
            'max_files'   => Request::post('upload_max_files', ''),
        ]);

        if (!$result['ok']) {
            Session::flash('error', (string) $result['error']);
            Response::redirect('/admin/settings');
            return;
        }

        SecurityLog::record('instance.uploads_update', 'límites de subida de la instancia actualizados desde el panel');
        Session::flash('success', 'Límites de subida actualizados.');
        Response::redirect('/admin/settings');
    }

    /**
     * POST /admin/settings/dashboard-limits — cupos por usuario del dashboard.
     *
     * PRG. Por defecto 30 formularios/hora y 120 exportaciones/hora.
     */
    public function updateDashboardLimits(): void
    {
        if (!Auth::can('admin.access')) {
            Response::forbidden();
            return;
        }
        if (!Csrf::verifyRequest()) {
            Session::flash('error', 'Token de seguridad inválido.');
            Response::redirect('/admin/settings');
            return;
        }

        $result = DashboardLimitSettings::save([
            'create_per_hour' => Request::post('dash_create_per_hour', ''),
            'export_per_hour' => Request::post('dash_export_per_hour', ''),
        ]);

        if (!$result['ok']) {
            Session::flash('error', (string) $result['error']);
            Response::redirect('/admin/settings');
            return;
        }

        SecurityLog::record('instance.dashboard_limits_update', 'cupos del dashboard por usuario actualizados desde el panel');
        Session::flash('success', 'Cupos del dashboard actualizados.');
        Response::redirect('/admin/settings');
    }

    /**
     * POST /admin/settings/session — hardening de sesión / HTTPS.
     *
     * PRG. Afecta a nuevas sesiones (cambios inmediatos al loguearse de nuevo).
     */
    public function updateSession(): void
    {
        if (!Auth::can('admin.access')) {
            Response::forbidden();
            return;
        }
        if (!Csrf::verifyRequest()) {
            Session::flash('error', 'Token de seguridad inválido.');
            Response::redirect('/admin/settings');
            return;
        }

        $result = SessionSettings::save([
            'name'      => Request::post('session_name', ''),
            'lifetime'  => Request::post('session_lifetime', ''),
            'secure'    => Request::post('session_secure') !== null,
            'httponly'  => Request::post('session_httponly') !== null,
            'samesite'  => Request::post('session_samesite', ''),
        ]);

        if (!$result['ok']) {
            Session::flash('error', (string) $result['error']);
            Response::redirect('/admin/settings');
            return;
        }

        SecurityLog::record('instance.session_update', 'ajustes de sesión actualizados desde el panel');
        Session::flash('success', 'Ajustes de sesión actualizados.');
        Response::redirect('/admin/settings');
    }

    /**
     * POST /admin/settings/network — red, proxy y cabeceras de seguridad.
     *
     * PRG.
     */
    public function updateNetwork(): void
    {
        if (!Auth::can('admin.access')) {
            Response::forbidden();
            return;
        }
        if (!Csrf::verifyRequest()) {
            Session::flash('error', 'Token de seguridad inválido.');
            Response::redirect('/admin/settings');
            return;
        }

        $result = NetworkSettings::save([
            'trust_proxy'         => Request::post('trust_proxy') !== null,
            'redirect_allowlist'  => Request::post('redirect_allowlist', ''),
            'frame_options'       => Request::post('frame_options', ''),
            'csp_policy'          => Request::post('csp_policy', ''),
        ]);

        if (!$result['ok']) {
            Session::flash('error', (string) $result['error']);
            Response::redirect('/admin/settings');
            return;
        }

        SecurityLog::record('instance.network_update', 'ajustes de red/cabeceras actualizados desde el panel');
        Session::flash('success', 'Ajustes de red actualizados.');
        Response::redirect('/admin/settings');
    }

    /**
     * Rol de un usuario, cambiado desde su fila de `/admin`.
     *
     * Bimodal: el `select` se envía solo al cambiarlo (`js-auto-submit`, que
     * dispara un `requestSubmit()` y por tanto pasa por el delegado), así que
     * recargar la página entera por cada cambio de rol era tirar abajo el
     * desplegable de grupos que se estuviera editando al lado y todo lo que
     * hubiera escrito en cualquier otra parte.
     *
     * El `html` viene **vacío a propósito**: cambiar el rol no cambia nada de
     * lo que se ve en la fila (el `select` ya está en el valor nuevo, y el
     * nombre del rol lo pone el propio `select`), así que lo único que hay que
     * pintar es el aviso de que se guardó. Repintar la fila entera, o el botón
     * de los grupos, para un cambio de rol sería tirar estado away por nada.
     */
    public function updateRole(): void
    {
        if (!Auth::can('admin.users')) {
            if (Response::wantsJson()) {
                Response::json(['ok' => false, 'error' => 'No tienes permiso para cambiar roles.'], 403);
            } else {
                Response::forbidden();
            }
            return;
        }

        $json = Response::wantsJson();

        if (!Csrf::verifyRequest()) {
            if ($json) {
                Response::json(['ok' => false, 'error' => 'Token de seguridad inválido.'], 419);
                return;
            }
            Session::flash('error', 'Token inválido.');
            Response::redirect('/admin');
            return;
        }

        $userId = (int) Request::post('user_id', 0);
        $roleId = (int) Request::post('role_id', 0);

        $target = User::find($userId);
        if ($target === null) {
            if ($json) {
                Response::json(['ok' => false, 'error' => 'Usuario no encontrado.'], 404);
                return;
            }
            Session::flash('error', 'Usuario no encontrado.');
            Response::redirect('/admin');
            return;
        }

        // No permitir degradar al propio admin
        if (Auth::id() === $userId && $roleId !== 1) {
            if ($json) {
                Response::json(['ok' => false, 'error' => 'No puedes cambiar tu propio rol de administrador.'], 422);
                return;
            }
            Session::flash('error', 'No puedes cambiar tu propio rol de administrador.');
            Response::redirect('/admin');
            return;
        }

        // El rol se busca en la tabla y no se compara con una lista de ids: un
        // id inventado (o el de un rol borrado) dejaría la cuenta con un rol que
        // no existe y `Auth::can()` daría false para todo lo suyo sin decir nada.
        $role = Role::find($roleId);
        if ($role === null) {
            if ($json) {
                Response::json(['ok' => false, 'error' => 'Rol inválido.'], 422);
                return;
            }
            Session::flash('error', 'Rol inválido.');
            Response::redirect('/admin');
            return;
        }

        // Idempotente con estado OBJETIVO: un reintento o un doble clic no
        // pueden degradar la cuenta a un rol anterior, porque el «objetivo» es
        // el rol que se acaba de elegir, no el contrario.
        User::updateRole($userId, $roleId);
        SecurityLog::record('admin.role_change', 'usuario #' . $userId . ' -> rol #' . $roleId);

        if ($json) {
            Response::json([
                'ok'      => true,
                'message' => 'Rol de «' . $target['name'] . '»: ' . ucfirst((string) $role['name']) . '.',
                // La insignia de la columna «Rol» se repinta: desde la fase 3 la
                // fila muestra el rol como insignia (se edita en el menú), así
                // que sin esto el panel seguiría diciendo el rol anterior.
                'html'    => [
                    'vfUserRole' . $userId => View::partial('admin/_user_role', [
                        'roleName' => (string) $role['name'],
                    ]),
                ],
            ]);
            return;
        }

        Session::flash('success', 'Rol actualizado.');
        Response::redirect('/admin');
        return;
    }

    /**
     * Grupos a los que pertenece un usuario, editados desde su fila.
     *
     * La pertenencia es una N:M, así que el POST es la lista COMPLETA: un
     * `groups[]` sin el id de un grupo significa "sácalo de ahí", no "no lo
     * he tocado". Por eso `postedIds()` devuelve `[]` de verdad cuando no
     * viene nada, y quitar el último grupo es un POST vacío.
     *
     * El permiso es `admin.groups` y no `admin.users`: la operación mueve
     * membresía de grupos, que es lo que `admin.groups` autoriza. Quien solo
     * puede cambiar el rol de alguien no puede meterlo en grupos por aquí.
     */
    public function updateUserGroups(): void
    {
        $json = Response::wantsJson();

        if (!Auth::can('admin.groups')) {
            if ($json) {
                Response::json(['ok' => false, 'error' => 'No tienes permiso para gestionar los grupos.'], 403);
            } else {
                Response::forbidden();
            }
            return;
        }
        if (!Csrf::verifyRequest()) {
            if ($json) {
                Response::json(['ok' => false, 'error' => 'Token de seguridad inválido.'], 419);
                return;
            }
            Session::flash('error', 'Token inválido.');
            Response::redirect('/admin');
            return;
        }

        $userId = (int) Request::post('user_id', 0);
        $target = User::find($userId);
        if ($target === null) {
            if ($json) {
                Response::json(['ok' => false, 'error' => 'Usuario no encontrado.'], 404);
                return;
            }
            Session::flash('error', 'Usuario no encontrado.');
            Response::redirect('/admin');
            return;
        }

        /* Un solo `RoleGroup::all()` para las dos cosas que hacen falta: validar
         * que ningún id venga inventado (map por id) y volver a pintar las
         * insignias del botón con el NOMBRE que le pone el servidor. No se usa
         * `User::groupsMap()` —que es la tabla entera— porque eso sería una
         * consulta por cada usuario de la instalación para pintar un botón. */
        $groupNames = array_column(RoleGroup::all(), 'name', 'id');
        $groupIds = self::postedIds('groups');
        foreach ($groupIds as $groupId) {
            if (!isset($groupNames[$groupId])) {
                if ($json) {
                    Response::json(['ok' => false, 'error' => 'Uno de los grupos ya no existe.'], 422);
                    return;
                }
                Session::flash('error', 'Grupo inválido.');
                Response::redirect('/admin');
                return;
            }
        }

        User::setGroups($userId, $groupIds);
        SecurityLog::record(
            'admin.group_membership',
            'usuario #' . $userId . ' -> grupos [' . implode(',', $groupIds) . ']'
        );

        $message = $groupIds === []
            ? '«' . $target['name'] . '» ya no está en ningún grupo.'
            : 'Grupos de «' . $target['name'] . '» actualizados.';

        if ($json) {
            /* Solo las insignias del botón (`vfUserGroups<uid>`), que es lo
             * único de la celda que depende de la pertenencia. Las casillas del
             * desplegable NO se repintan: son las que enviaron el POST, así que
             * ya son la verdad, y sustituirlas cerraría el desplegable que se
             * está editando.
             *
             * Se recorren los grupos del catálogo y no `$groupIds`, para que las
             * insignias salgan en el MISMO orden que las casillas del menú: el
             * POST va en el orden en que se marcaron, y en el botón se verían
             * desordenadas respecto a lo que hay justo debajo. */
            $myGroups = [];
            foreach ($groupNames as $groupId => $groupName) {
                if (in_array((int) $groupId, $groupIds, true)) {
                    $myGroups[] = ['id' => (int) $groupId, 'name' => (string) $groupName];
                }
            }
            Response::json([
                'ok'      => true,
                'message' => $message,
                'html'    => [
                    'vfUserGroups' . $userId => View::partial('admin/_user_groups', [
                        'myGroups' => $myGroups,
                    ]),
                ],
            ]);
            return;
        }

        Session::flash('success', $message);
        Response::redirect('/admin');
        return;
    }

    /**
     * Permisos DIRECTOS de un usuario, editados desde su fila de `/admin`.
     *
     * Es la cuarta fuente del RBAC: dar UN permiso a UNA persona sin cambiarle el
     * rol (que cambia todo su conjunto) ni meterla en un grupo (que se lo da a
     * todos). Se SUMAN a los del rol y a los de los grupos; no hay forma de
     * quitar por aquí un permiso que venga de otro sitio.
     *
     * El POST es la lista COMPLETA (N:M), como `groups[]`: una clave que no venga
     * es que se quita. Se filtra contra el catálogo (`postedKeys()`), así que una
     * clave inventada se ignora sin romper el guardado.
     *
     * El permiso es `admin.users`: conceder una capacidad suelta es de la misma
     * familia que cambiar el rol, y por eso NO se exige `admin.roles` (que es de
     * la matriz de roles, no de una cuenta concreta).
     */
    public function updateUserPermissions(): void
    {
        $json = Response::wantsJson();

        if (!Auth::can('admin.users')) {
            if ($json) {
                Response::json(['ok' => false, 'error' => 'No tienes permiso para conceder permisos.'], 403);
            } else {
                Response::forbidden();
            }
            return;
        }
        if (!Csrf::verifyRequest()) {
            if ($json) {
                Response::json(['ok' => false, 'error' => 'Token de seguridad inválido.'], 419);
                return;
            }
            Session::flash('error', 'Token inválido.');
            Response::redirect('/admin');
            return;
        }

        $userId = (int) Request::post('user_id', 0);
        $target = User::find($userId);
        if ($target === null) {
            if ($json) {
                Response::json(['ok' => false, 'error' => 'Usuario no encontrado.'], 404);
                return;
            }
            Session::flash('error', 'Usuario no encontrado.');
            Response::redirect('/admin');
            return;
        }

        // Solo claves del catálogo, y la lista entera como verdad: una clave
        // desconocida se descarta (no hay nada que validar que falle, como el id
        // de un grupo que ya no existe: las claves del catálogo no se borran).
        $keys = self::postedKeys('permissions', array_keys(self::catalog()));

        User::setPermissions($userId, $keys);
        SecurityLog::record(
            'admin.user_permissions',
            'usuario #' . $userId . ' -> permisos directos [' . implode(',', $keys) . ']'
        );

        $message = $keys === []
            ? '«' . $target['name'] . '» ya no tiene permisos adicionales.'
            : 'Permisos adicionales de «' . $target['name'] . '» actualizados.';

        if ($json) {
            /* Solo las insignias del botón (`vfUserPerms<uid>`), por la misma
             * razón que en los grupos: la lista de casillas que envió el POST ya
             * es la verdad, y repintarlas cerraría el desplegable que se está
             * editando. Se recorren las claves del CATÁLOGO para que salgan en el
             * mismo orden que las casillas del menú. */
            $myPerms = [];
            foreach (array_keys(self::catalog()) as $key) {
                if (in_array($key, $keys, true)) {
                    $myPerms[] = (string) $key;
                }
            }
            Response::json([
                'ok'      => true,
                'message' => $message,
                'html'    => [
                    'vfUserPerms' . $userId => View::partial('admin/_user_perms', [
                        'myPerms' => $myPerms,
                    ]),
                ],
            ]);
            return;
        }

        Session::flash('success', $message);
        Response::redirect('/admin');
        return;
    }

    /**
     * Alta de cuenta desde el panel.
     *
     * Existe porque `REGISTRATION_ENABLED` está cerrado por defecto, y con esa
     * puerta cerrada el único alta que quedaba era `scripts/create-admin.php`,
     * que crea **administradores** y solo el primero. Sin esta acción, dar de
     * alta un `user` o un `editor` exigía abrir el registro público un rato,
     * dejar que la persona se apuntara y volverlo a cerrar: justo lo contrario
     * de lo que se pidió al cerrar el interruptor.
     *
     * Deliberadamente NO lleva rate limit ni mensaje uniforme en el email ya
     * ocupado: esas dos defensas son del alta pública, que es la puerta que un
     * atacante puede golpear a saco. Aquí ya se ha pasado el permiso
     * `admin.users`, así que el admin puede saber si el email está en uso.
     */
    public function createUser(): void
    {
        if (!Auth::can('admin.users')) {
            Response::forbidden();
            return;
        }
        if (!Csrf::verifyRequest()) {
            Session::flash('error', 'Token inválido.');
            Response::redirect('/admin');
            return;
        }

        $name = trim(Request::post('name', ''));
        $email = trim(Request::post('email', ''));
        $password = (string) Request::post('password', '');
        $passwordConfirm = (string) Request::post('password_confirm', '');
        $roleId = (int) Request::post('role_id', 2);

        $invalid = AuthController::validateNewUser($name, $email, $password, $passwordConfirm);
        if ($invalid !== null) {
            Session::flash('error', $invalid);
            Response::redirect('/admin');
            return;
        }

        // El rol se valida contra la tabla, no contra una lista de ids: un
        // `role_id` invented (o de un rol borrado) dejaría una cuenta sin rol
        // interpretable, y `Auth::can()` devolvería false para todo lo suyo sin
        // que nada lo dijera.
        $role = Role::find($roleId);
        if ($role === null) {
            Session::flash('error', 'El rol indicado no existe.');
            Response::redirect('/admin');
            return;
        }

        if (User::findByEmail($email) !== null) {
            Session::flash('error', 'Ya hay una cuenta con ese email.');
            Response::redirect('/admin');
            return;
        }

        $userId = User::create($name, $email, $password, $roleId);
        if ($userId === null) {
            Session::flash('error', 'No se pudo crear la cuenta.');
            Response::redirect('/admin');
            return;
        }

        SecurityLog::record('admin.user_create', 'usuario #' . $userId . ' rol ' . $role['name']);
        Session::flash('success', 'Cuenta creada para ' . $email . ' (rol ' . $role['name'] . ').');
        Response::redirect('/admin');
        return;
    }

    public function toggleActive(): void
    {
        if (!Auth::can('admin.users')) {
            Response::forbidden();
            return;
        }
        if (!Csrf::verifyRequest()) {
            Session::flash('error', 'Token inválido.');
            Response::redirect('/admin');
            return;
        }

        $userId = (int) Request::post('user_id', 0);
        $target = User::find($userId);

        if ($target === null) {
            Session::flash('error', 'Usuario no encontrado.');
            Response::redirect('/admin');
            return;
        }

        if (Auth::id() === $userId) {
            Session::flash('error', 'No puedes desactivar tu propia cuenta.');
            Response::redirect('/admin');
            return;
        }

        // Estado objetivo, no «invertir»: `active` es lo que pinta el botón
        // (views/admin/users.php lo manda en un `hidden`), y así un reintento o
        // un doble clic no se quedan activando lo que se quería desactivar. Un
        // POST sin el campo —una página cacheada de antes de este cambio— sigue
        // invirtiendo, que es lo que hacía: el camino sin JS no se rompe.
        $pedido = Request::post('active', null);
        $activo = $pedido === null
            ? ((int) $target['is_active'] === 1 ? 0 : 1)
            : ((int) $pedido === 1 ? 1 : 0);

        $cambio = User::setActive($userId, $activo === 1);

        // `Auth::forgetCache()` NO hace falta aquí: no te puedes desactivar a ti
        // mismo (el guard de arriba), así que el actor nunca es el afectado y su
        // caché —que es por request, no de sesión— no puede estar vieja.
        SecurityLog::record('admin.user_toggle', 'usuario #' . $userId . ($activo === 1 ? ' -> activado' : ' -> desactivado'));

        // Idempotente de verdad también en el mensaje: si ya estaba en ese
        // estado, «Estado de usuario actualizado» sería cierto pero no
        // avisa de que no pasó nada, que es la mitad de lo que el botón promete.
        $message = $cambio
            ? ($activo === 1 ? 'Usuario activado.' : 'Usuario desactivado.')
            : 'El usuario ya estaba en ese estado.';

        if (Response::wantsJson()) {
            // Los DOS partials de la fila, y solo los de ESE usuario: con el
            // estado objetivo, lo que hay que repintar es el resultado —insignia
            // y botón—, no «lo que le pasó». Un `html` con la fila entera
            // repondría también el selector de rol y el desplegable de grupos,
            // que no han cambiado, y se comerían un `<select>` abierto.
            $fresco = User::find($userId);
            Response::json([
                'ok'      => true,
                'message' => $message,
                'html'    => $fresco === null ? [] : [
                    'vfUserStatus' . $userId => View::partial('admin/_user_status', ['user' => $fresco]),
                    'vfUserToggle' . $userId => View::partial('admin/_user_toggle', [
                        'user' => $fresco,
                        'uid'  => $userId,
                    ]),
                ],
            ]);
            return;
        }

        Session::flash('success', $message);
        Response::redirect('/admin');
        return;
    }

    public function allForms(): void
    {
        if (!Auth::can('admin.access')) {
            Response::forbidden();
            return;
        }

        // M2/F6 — El mismo listado que el dashboard (`Form::page()`), con su
        // buscador/filtro/orden en la URL y su paginación server-side. Por
        // página propia (20), que es más corta porque la tarjeta es más pequeña;
        // el alcance es global (`$userId = 0`, `$scopeAll = true`) porque esta
        // vista es de administración. Un `estado`/`orden` que no exista se cae
        // al predeterminado: quien los manda es la URL.
        $estado = (string) Request::query('estado', '');
        $orden = (string) Request::query('orden', '');
        $filtros = [
            'q'      => (string) Request::query('q', ''),
            'estado' => in_array($estado, Form::STATUS, true) ? $estado : '',
            'orden'  => in_array($orden, Form::ORDENES, true) ? $orden : 'updated',
        ];

        $listado = Form::page(0, true, $filtros, max(1, (int) Request::query('page', '1')), 20);
        View::render('admin/forms', [
            'forms'   => $listado['items'],
            'listado' => $listado,
            'filtros' => $filtros,
            'csrf'    => Csrf::token(),
        ]);
    }

    // ------------------------------------------------------------------
    // Grupos de roles (/admin/groups)
    // ------------------------------------------------------------------

    public function groups(): void
    {
        if (!Auth::can('admin.groups')) {
            Response::forbidden();
            return;
        }

        View::render('admin/groups', [
            'groups'        => RoleGroup::all(),
            'roles'         => Role::allWithPermissions(),
            'users'         => User::all(),
            'groupRoles'    => self::groupRoles(),
            'groupMembers'  => self::groupMembers(),
            'groupPerms'    => self::groupEffectivePermissionsMap(),
            'catalog'       => self::catalog(),
            'csrf'          => Csrf::token(),
        ]);
    }

    /** Permisos efectivos por grupo, para la vista (ver groupEffectivePermissions). */
    private static function groupEffectivePermissionsMap(): array
    {
        $roleMap = self::groupRoles();
        $out = [];
        foreach (RoleGroup::all() as $group) {
            $out[(int) $group['id']] = self::groupEffectivePermissions($roleMap[(int) $group['id']] ?? []);
        }
        return $out;
    }

    public function groupCreate(): void
    {
        if (!Auth::can('admin.groups')) {
            Response::forbidden();
            return;
        }
        if (!Csrf::verifyRequest()) {
            Session::flash('error', 'Token inválido.');
            Response::redirect('/admin/groups');
            return;
        }

        $name = trim(Request::post('name', ''));
        if ($name === '' || mb_strlen($name) > 80) {
            Session::flash('error', 'El nombre del grupo es obligatorio (máx. 80 caracteres).');
            Response::redirect('/admin/groups');
            return;
        }

        $groupId = RoleGroup::create($name, trim(Request::post('description', '')));
        RoleGroup::setRoles($groupId, self::postedIds('roles'));
        RoleGroup::setUsers($groupId, self::postedIds('users'));
        SecurityLog::record('admin.group_create', 'grupo #' . $groupId . ' («' . $name . '»)');
        Session::flash('success', 'Grupo creado.');
        Response::redirect('/admin/groups');
        return;
    }

    public function groupUpdate(): void
    {
        $json = Response::wantsJson();

        if (!Auth::can('admin.groups')) {
            if ($json) {
                Response::json(['ok' => false, 'error' => 'No tienes permiso para gestionar los grupos.'], 403);
            } else {
                Response::forbidden();
            }
            return;
        }
        if (!Csrf::verifyRequest()) {
            if ($json) {
                Response::json(['ok' => false, 'error' => 'Token de seguridad inválido.'], 419);
                return;
            }
            Session::flash('error', 'Token inválido.');
            Response::redirect('/admin/groups');
            return;
        }

        $groupId = (int) Request::post('group_id', 0);
        $current = RoleGroup::find($groupId);
        if ($current === null) {
            if ($json) {
                Response::json(['ok' => false, 'error' => 'Grupo no encontrado.'], 404);
                return;
            }
            Session::flash('error', 'Grupo no encontrado.');
            Response::redirect('/admin/groups');
            return;
        }

        // `name` ausente ≠ nombre vacío. Editar la pertenencia (roles y miembros)
        // no tiene por qué mandar el nombre, y exigirlo hacía la función
        // inusable: la UI no lo mandaba, así que guardar un grupo fallaba siempre
        // con «El nombre del grupo es obligatorio» sin tocar absolutamente nada.
        // Presente pero vacío sí es un error: un grupo no puede quedarse sin
        // nombre a media edición.
        $postedName = Request::post('name');
        if ($postedName === null) {
            $name = (string) $current['name'];
        } else {
            $name = trim((string) $postedName);
            if ($name === '' || mb_strlen($name) > 80) {
                if ($json) {
                    Response::json(['ok' => false, 'error' => 'El nombre del grupo es obligatorio (máx. 80 caracteres).'], 422);
                    return;
                }
                Session::flash('error', 'El nombre del grupo es obligatorio (máx. 80 caracteres).');
                Response::redirect('/admin/groups');
                return;
            }
        }

        // La fila del grupo solo se toca si el nombre o la descripción cambian:
        // `setRoles`/`setUsers` borran y reinsertan dentro de su propia
        // transacción, y hacerlo en cada guardado daría una falsa sensación de
        // cambio (y un `updated_at` que miente).
        $postedDescription = Request::post('description');
        $description = $postedDescription === null
            ? (string) ($current['description'] ?? '')
            : trim((string) $postedDescription);

        if ($name !== (string) $current['name'] || $description !== (string) ($current['description'] ?? '')) {
            RoleGroup::update($groupId, $name, $description);
        }
        $roleIds = self::postedIds('roles');
        $memberIds = self::postedIds('users');
        RoleGroup::setRoles($groupId, $roleIds);
        RoleGroup::setUsers($groupId, $memberIds);
        SecurityLog::record('admin.group_create', 'grupo #' . $groupId . ' actualizado («' . $name . '»)');

        if ($json) {
            /* Dos huecos, porque el rediseño separó el resumen (cabecera) del
             * efecto del grupo (cuerpo): el renombrado cambia los dos. Las
             * casillas y los inputs NO se repintan: son los que enviaron el
             * POST, así que ya son la verdad, y sustituirlos cerraría lo que se
             * esté editando al lado. */
            Response::json([
                'ok'      => true,
                'message' => 'Grupo actualizado.',
                'html'    => [
                    'vfGroupSummary' . $groupId => self::groupSummaryPartial($groupId, $name, $description, $roleIds, $memberIds),
                    'vfGroupEffect' . $groupId  => self::groupEffectPartial($roleIds),
                ],
            ]);
            return;
        }

        Session::flash('success', 'Grupo actualizado.');
        Response::redirect('/admin/groups');
        return;
    }

    public function groupDelete(): void
    {
        $json = Response::wantsJson();

        if (!Auth::can('admin.groups')) {
            if ($json) {
                Response::json(['ok' => false, 'error' => 'No tienes permiso para gestionar los grupos.'], 403);
            } else {
                Response::forbidden();
            }
            return;
        }
        if (!Csrf::verifyRequest()) {
            if ($json) {
                Response::json(['ok' => false, 'error' => 'Token de seguridad inválido.'], 419);
                return;
            }
            Session::flash('error', 'Token inválido.');
            Response::redirect('/admin/groups');
            return;
        }

        $groupId = (int) Request::post('group_id', 0);
        $group = RoleGroup::find($groupId);
        if ($group === null) {
            if ($json) {
                Response::json(['ok' => false, 'error' => 'Grupo no encontrado.'], 404);
                return;
            }
            Session::flash('error', 'Grupo no encontrado.');
            Response::redirect('/admin/groups');
            return;
        }

        RoleGroup::delete($groupId);
        SecurityLog::record('admin.group_delete', 'grupo #' . $groupId . ' eliminado');

        if ($json) {
            /* El JS quita la tarjeta por `[data-vf-row]` y, si `count` queda en
             * cero, recarga (con una columna entera vacía no hay tarjeta que
             * quite y el vacío es del marco, no de la fila). Es exactamente el
             * contrato del borrado de respuestas: el `count` es del servidor
             * porque la lista es global y el cliente no la conoce. */
            Response::json([
                'ok'      => true,
                'message' => 'Grupo eliminado.',
                'count'   => count(RoleGroup::all()),
            ]);
            return;
        }

        Session::flash('success', 'Grupo eliminado.');
        Response::redirect('/admin/groups');
        return;
    }

    /**
     * La cabecera-resumen de una tarjeta de grupo: nombre, descripción y los
     * recuentos de roles y miembros. Es uno de los dos huecos que repinta
     * `groupUpdate()` sin recargar, y el MISMO partial que pinta el GET en
     * línea — con dos copias divergirían.
     *
     * El efecto («lo que otorga este grupo») ya no va aquí: mudó al cuerpo de
     * la tarjeta y tiene su propio partial y su propio hueco, `vfGroupEffect`.
     *
     * @param int[] $roleIds
     * @param int[] $memberIds
     */
    private static function groupSummaryPartial(
        int $groupId,
        string $name,
        string $description,
        array $roleIds,
        array $memberIds
    ): string {
        return View::partial('admin/_group_summary', [
            'groupId'     => $groupId,
            'groupName'   => $name,
            'groupDesc'   => $description,
            'roleCount'   => count($roleIds),
            'memberCount' => count($memberIds),
        ]);
    }

    /**
     * El efecto del grupo: los permisos que otorgan sus roles. Segundo hueco de
     * repintado de `groupUpdate()` (`vfGroupEffect<gid>`), en el cuerpo de la
     * tarjeta.
     *
     * @param int[] $roleIds
     */
    private static function groupEffectPartial(array $roleIds): string
    {
        return View::partial('admin/_group_effect', [
            'gPerms' => self::groupEffectivePermissions($roleIds),
        ]);
    }

    // ------------------------------------------------------------------
    // Matriz de permisos (/admin/roles)
    // ------------------------------------------------------------------

    public function roles(): void
    {
        if (!Auth::can('admin.roles')) {
            Response::forbidden();
            return;
        }

        $roles = Role::allWithPermissions();

        // El recuento va aparte (`allWithPermissions()` no lo trae) y en un
        // mapa indexado por id, no como una clave más del rol: la lista de la
        // baja se recorre con `foreach` y un `count` por fila son N consultas
        // para un número que se enseña junto al nombre.
        $usuarios = [];
        foreach ($roles as $rol) {
            $usuarios[(int) $rol['id']] = Role::userCount((int) $rol['id']);
        }

        View::render('admin/roles', [
            'roles'       => $roles,
            'userCounts'  => $usuarios,
            'permissions' => self::catalog(),
            'csrf'        => Csrf::token(),
        ]);
    }

    /**
     * Permisos que otorga un grupo = unión de los de sus roles.
     *
     * La vista de grupos lo pinta para que se vea el EFECTO de marcar un rol y
     * no solo el cableado: la pregunta de quien administra no es «¿qué roles
     * tiene este grupo?» sino «¿qué pasa si saco a esta persona de aquí?».
     */
    private static function groupEffectivePermissions(array $roleIds): array
    {
        $roleIds = array_values(array_unique(array_filter(array_map('intval', $roleIds))));
        if ($roleIds === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($roleIds), '?'));
        return array_column(
            Database::getInstance(Config::db())->fetchAll(
                "SELECT DISTINCT p.`key`
                 FROM permissions p
                 JOIN role_permissions rp ON rp.permission_id = p.id
                 WHERE rp.role_id IN ($ph)
                 ORDER BY p.`key`",
                $roleIds
            ),
            'key'
        );
    }

    public function roleCreate(): void
    {
        if (!Auth::can('admin.roles')) {
            Response::forbidden();
            return;
        }
        if (!Csrf::verifyRequest()) {
            Session::flash('error', 'Token inválido.');
            Response::redirect('/admin/roles');
            return;
        }

        $raw = trim((string) Request::post('name', ''));
        if ($raw === '' || mb_strlen($raw) > 50) {
            Session::flash('error', 'El nombre del rol es obligatorio (máx. 50 caracteres).');
            Response::redirect('/admin/roles');
            return;
        }
        if (preg_match('/^[A-Za-z0-9._-]+$/', $raw) !== 1) {
            Session::flash('error', 'El nombre solo admite letras, números, punto, guion y guion bajo.');
            Response::redirect('/admin/roles');
            return;
        }

        $description = trim((string) Request::post('description', ''));
        $existing = Role::findByName(mb_strtolower($raw));
        if ($existing !== null) {
            Session::flash('error', 'Ya existe un rol llamado «' . mb_strtolower($raw) . '».');
            Response::redirect('/admin/roles');
            return;
        }

        // Nace sin permisos a propósito (ver Role::create()): se avisa de que
        // hay que marcarlo en la matriz o no hace nada.
        $roleId = Role::create($raw, $description);
        if ($roleId === null) {
            Session::flash('error', 'No se pudo crear el rol.');
            Response::redirect('/admin/roles');
            return;
        }

        SecurityLog::record('admin.role_create', 'rol «' . (string) (Role::find($roleId)['name'] ?? $raw) . '» creado.');
        Session::flash('success', 'Rol creado sin permisos. Márcalos en la matriz de abajo.');
        Response::redirect('/admin/roles');
        return;
    }

    /**
     * POST /admin/roles/delete — borra un rol propio que no lleva a nadie.
     *
     * Las dos condiciones (no del sistema y sin usuarios) las decide
     * `Role::delete()`, que además devuelve el motivo. Aquí no se reimplementan:
     * una comprobación de «tiene usuarios» en el controlador y otra en el
     * modelo divergirían en cuanto una cambiara, y el «borrado en cascada» que
     * hace la base sería la única que queda.
     *
     * Va al log porque borrar un rol cambia lo que puede hacer un grupo entero:
     * los grupos confieren roles, así que quitar `soporte-tecnico` deja a sus
     * miembros sin nada que el grupo les traía.
     */
    public function roleDelete(): void
    {
        if (!Auth::can('admin.roles')) {
            Response::forbidden();
            return;
        }
        if (!Csrf::verifyRequest()) {
            Session::flash('error', 'Token inválido.');
            Response::redirect('/admin/roles');
            return;
        }

        $roleId = (int) Request::post('role_id', 0);
        $rol = $roleId > 0 ? Role::find($roleId) : null;
        $nombre = $rol === null ? ('#' . $roleId) : (string) $rol['name'];

        $motivo = null;
        if (!Role::delete($roleId, $motivo)) {
            Session::flash('error', 'No se pudo borrar «' . $nombre . '»: ' . (string) $motivo);
            Response::redirect('/admin/roles');
            return;
        }

        SecurityLog::record('admin.role_delete', 'rol «' . $nombre . '» borrado.');
        Session::flash('success', 'Rol «' . $nombre . '» borrado.');
        Response::redirect('/admin/roles');
        return;
    }

    public function updateRoleMatrix(): void
    {
        if (!Auth::can('admin.roles')) {
            if (Response::wantsJson()) {
                Response::json(['ok' => false, 'error' => 'No tienes permiso para editar los permisos.'], 403);
            } else {
                Response::forbidden();
            }
            return;
        }

        $json = Response::wantsJson();

        if (!Csrf::verifyRequest()) {
            if ($json) {
                Response::json(['ok' => false, 'error' => 'Token de seguridad inválido.'], 419);
                return;
            }
            Session::flash('error', 'Token inválido.');
            Response::redirect('/admin/roles');
            return;
        }

        $catalogKeys = array_keys(self::catalog());
        $roles = Role::all();

        // El navegador siempre manda `perms[<roleId>][]` = claves marcadas.
        // Un POST sin esa estructura (p. ej. cuerpo JSON o una petición vacía)
        // NO significa "desmarcar todo": sin la matriz no hay nada que guardar
        // y vaciarla dejaría todos los roles sin permisos salvo admin.access.
        $posted = Request::post('perms', []);
        if ($posted === []) {
            if ($json) {
                Response::json(['ok' => false, 'error' => 'No se recibió la matriz de permisos. Nada que guardar.'], 422);
                return;
            }
            Session::flash('error', 'No se recibió la matriz de permisos. Nada que guardar.');
            Response::redirect('/admin/roles');
            return;
        }

        foreach ($roles as $role) {
            $roleId = (int) $role['id'];
            // El navegador manda `perms[<roleId>][]` = claves marcadas.
            $keys = isset($posted[(string) $roleId]) && is_array($posted[(string) $roleId])
                ? array_values($posted[(string) $roleId])
                : [];

            // Solo claves del catálogo (nunca junk del cliente).
            $keys = array_values(array_filter(array_map('strval', $keys), static fn($k) => in_array($k, $catalogKeys, true)));

            // El rol admin siempre conserva admin.access: sin él el propio
            // panel queda inaccesible y nadie puede arreglar la matriz.
            if (strtolower((string) $role['name']) === 'admin' && !in_array('admin.access', $keys, true)) {
                $keys[] = 'admin.access';
            }

            if ($keys === Role::permissionKeys($roleId)) {
                continue; // sin cambios: no tocar ni re-loggear
            }

            Role::setPermissions($roleId, $keys);
            SecurityLog::record(
                'admin.role_matrix',
                'rol «' . (string) $role['name'] . '»: ' . implode(', ', $keys) . '.'
            );
        }

        // Un cambio de permisos afecta a la caché de permisos de CADA request;
        // con la caché de procesos y el retorno normal se ve en el siguiente.
        if ($json) {
            Response::json(['ok' => true, 'message' => 'Matriz de permisos actualizada.']);
            return;
        }

        Session::flash('success', 'Matriz de permisos actualizada.');
        Response::redirect('/admin/roles');
        return;
    }

    // ------------------------------------------------------------------
    // Log de seguridad (/admin/security-log)
    // ------------------------------------------------------------------

    public function securityLog(): void
    {
        if (!Auth::can('logs.view')) {
            Response::forbidden();
            return;
        }

        $event = trim(Request::query('event', ''));
        $q = trim(Request::query('q', ''));
        $page = max(1, (int) Request::query('page', '1'));

        $logs = SecurityLog::recent($event, $page, 50, $q);

        View::render('admin/security_log', [
            'logs'    => $logs,
            'event'   => $event,
            'q'       => $q,
            'events'  => SecurityLog::knownEvents(),
            'csrf'    => Csrf::token(),
        ]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Catálogo de permisos: 'clave' => 'descripción'. Sin autoload: archivo
     * plano de config.
     *
     * @return array<string,string>
     */
    private static function catalog(): array
    {
        $catalog = require dirname(__DIR__, 2) . '/config/permissions.php';
        return array_map(static fn($v) => is_array($v) ? (string) ($v[0] ?? '') : '', (array) $catalog);
    }

    /** roles/users marcados por checkbox: 'roles[]' => list<int>. */
    private static function postedIds(string $key): array
    {
        $posted = Request::post($key, []);
        if (!is_array($posted)) {
            return [];
        }
        return array_values(array_map('intval', $posted));
    }

    /**
     * Claves de permiso marcadas por checkbox: 'permissions[]' => list<string>,
     * filtradas contra el catálogo. Un `permissions[]` ausente (o no-array) es
     * una lista vacía, no un error: para esta N:M la lista completa es la
     * verdad y «ninguna clave» significa «sin permisos directos».
     */
    private static function postedKeys(string $key, array $allowed): array
    {
        $posted = Request::post($key, []);
        if (!is_array($posted)) {
            return [];
        }
        return array_values(array_filter(
            array_map('strval', $posted),
            static fn($k) => in_array($k, $allowed, true)
        ));
    }

    /** Por grupo, los ids de usuarios que lo componen. */
    private static function groupMembers(): array
    {
        $out = [];
        foreach (RoleGroup::all() as $group) {
            $out[(int) $group['id']] = RoleGroup::userIds((int) $group['id']);
        }
        return $out;
    }

    /** Por grupo, los ids de roles que lo componen. */
    private static function groupRoles(): array
    {
        $out = [];
        foreach (RoleGroup::all() as $group) {
            $out[(int) $group['id']] = RoleGroup::roleIds((int) $group['id']);
        }
        return $out;
    }
}