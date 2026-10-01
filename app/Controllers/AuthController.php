<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppSettings;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\SecurityLog;
use App\Core\Session;
use App\Core\View;
use App\Models\User;

class AuthController
{
    /**
     * Hash señuelo para igualar el coste cuando el email NO existe.
     *
     * `password_verify` con bcrypt cuesta ~100 ms, y el caso "usuario no
     * encontrado" se resolvía con un `||` cortocircuitado que se saltaba esa
     * llamada: un login con email inexistente respondía en microsegundos y uno
     * con email existente en ~100 ms, o sea que **el tiempo de respuesta
     * enumeraba qué emails están dados de alta**. Con este señuelo se verifica
     * contra un hash válido pero ajeno, así que ambos caminos hacen el mismo
     * trabajo y el mismo mensaje.
     *
     * Es un bcrypt real de PASSWORD_DEFAULT a propósito: un hash inventado o
     * más barato (md5) devolvería antes y volvería a delatar la diferencia.
     *
     * F2: `public` porque `AccountController` lo usa también, al cambiar la
     * contraseña desde la sesión y al aplanar el tiempo de `/forgot-password`.
     * Es la MISMA constante y no una copia: dos señuelos con distinto coste
     * volverían a delatar la diferencia de tiempos que existen para tapar.
     */
    public const DUMMY_HASH = '$2y$12$z0PiH/0pr1y5CX2qWa9S6ec4Cw9eMuNODP2I8F.k9OTEkKh5I/RRu';

    /**
     * Mínimo de contraseña al registrarse.
     *
     * 10, no 12, y a propósito: el plan pedía 10-12, pero los usuarios demo
     * del seed usan `password123` (11) y subirlos a 12 los dejaría fuera de su
     * propia contraseña documentada. Con 10 el mínimo sigue siendo firme y el
     * seed sigue funcionando; el hash del seed es bcrypt coste 12, que es la
     * mitad del trabajo.
     *
     * F2: `public` y compartido con `AccountController`: el mínimo de una
     * contraseña fijada por recuperación tiene que ser el MISMO que el del
     * registro, o se podría fijar por aquí una contraseña que el registro
     * rechazaría. También la usa `views/auth/register.php`.
     */
    public const PASSWORD_MIN = 10;

    /**
     * Valida los datos de un alta de cuenta. Devuelve `null` si todo está bien,
     * o el mensaje de error si no.
     *
     * Lo comparten `register()` (alta pública) y `AdminController::createUser()`
     * (alta por panel) a propósito, por la misma razón que `PASSWORD_MIN` es
     * `public`: si cada uno validara por su cuenta, la contraseña que acepta uno
     * podría ser la que el otro rechaza, y aparecería un usuario creado por
     * admin que el propio registro no habría dejado crear.
     *
     * Lo que NO está aquí, a propósito, es lo que depende de la puerta de
     * entrada y no del formulario: el rate limit y el mensaje uniforme de
     * «email ya existe» son de `register()` (un atacante que puebla el registro
     * público no debe poder distinguir un email tomado de uno libre), y el
     * alta por panel va detrás de `admin.users`, que ya es su propia puerta.
     *
     * M3: los topes de longitud evitan errores 500 silenciosos de la BD
     * (users.name VARCHAR(100), users.email VARCHAR(190)).
     */
    public static function validateNewUser(
        string $name,
        string $email,
        string $password,
        string $passwordConfirm
    ): ?string {
        if ($name === '' || $email === '' || $password === '') {
            return 'Todos los campos son obligatorios.';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Email no válido.';
        }

        if (mb_strlen($name) > 100) {
            return 'El nombre no puede superar los 100 caracteres.';
        }

        if (mb_strlen($email) > 190) {
            return 'El email no puede superar los 190 caracteres.';
        }

        if (strlen($password) < self::PASSWORD_MIN) {
            return 'La contraseña debe tener al menos ' . self::PASSWORD_MIN . ' caracteres.';
        }

        if ($password !== $passwordConfirm) {
            return 'Las contraseñas no coinciden.';
        }

        return null;
    }

    public function showLogin(): void
    {
        View::render('auth/login', ['csrf' => Csrf::token()], 'guest');
    }

    public function login(): void
    {
        if (!Csrf::verifyRequest()) {
            Session::flash('error', 'Token de seguridad inválido.');
            Response::redirect('/login');
            return;
        }

        $email = trim(Request::post('email', ''));
        $password = (string) Request::post('password', '');

        if ($email === '' || $password === '') {
            Session::flash('error', 'Ingresa email y contraseña.');
            Response::redirect('/login');
            return;
        }

        // Rate limiting: 5 intentos por IP cada 5 minutos
        $ip = Request::ip();
        if (!RateLimiter::isAllowed('login', $ip, 5, 300)) {
            SecurityLog::record('rate_limit.login', 'bloqueo por IP (5 intentos / 5 min)');
            Session::flash('error', 'Demasiados intentos de inicio de sesión. Intenta de nuevo en unos minutos.');
            Response::redirect('/login');
            return;
        }

        // A2: además, límite por email (identificador hasheado para no
        // persistir PII en rate_limits) para mitigar fuerza bruta distribuida.
        $emailKey = hash('sha256', strtolower($email));
        $emailHit = false;
        if (!RateLimiter::isAllowed('login_email', $emailKey, 10, 300)) {
            Session::flash('error', 'Demasiados intentos para esta cuenta. Intenta de nuevo en unos minutos.');
            Response::redirect('/login');
            return;
        }

        $user = User::findByEmail($email);

        // Siempre se verifica una contraseña, exista el usuario o no: el coste
        // de `password_verify` es el mismo en los dos caminos, así que el
        // tiempo de respuesta no dice si el email está registrado. Solo cuando
        // el señuelo verifica bien (nunca) cambia el resultado.
        $ok = $user !== null
            ? password_verify($password, (string) $user['password_hash'])
            : password_verify($password, self::DUMMY_HASH);

        if (!$ok) {
            RateLimiter::recordHit('login', $ip, 300);
            RateLimiter::recordHit('login_email', $emailKey, 300);
            SecurityLog::record('auth.login_fail', strtolower($email));
            Session::flash('error', 'Credenciales incorrectas.');
            Response::redirect('/login');
            return;
        }

        if ((int) $user['is_active'] !== 1) {
            Session::flash('error', 'La cuenta está desactivada. Contacta al administrador.');
            Response::redirect('/login');
            return;
        }

        // Éxito: resetear los contadores de este origen
        RateLimiter::reset('login', $ip);
        RateLimiter::reset('login_email', $emailKey);

        // Rehash oportunista: si el hash guardado usa un algoritmo o coste que ya
        // no es el de PASSWORD_DEFAULT, se re-hashea con la contraseña que
        // acabamos de verificar en claro. Es la única vía de migración en una
        // app sin flujo de "cambiar contraseña", y no necesita la contraseña
        // antigua. Importante: `password_needs_rehash` compara con el coste
        // actual, así que una cuenta con coste bajo se actualiza sola al
        // primer login correcto.
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            User::updatePasswordHash((int) $user['id'], $password);
        }

        Auth::login((int) $user['id']);
        SecurityLog::record('auth.login_ok', strtolower($email));
        Session::flash('success', 'Bienvenido, ' . $user['name'] . '!');
        Response::redirect('/dashboard');
        return;
    }

    /**
     * El registro público es opt-in, y hay dos fuentes.
     *
     * `AppSettings::REGISTRATION` manda si alguien lo escribió desde el panel
     * (`POST /admin/settings/registration`); si no hay fila, manda
     * `REGISTRATION_ENABLED` del `.env`. Ese orden es el que deja el control
     * donde estaba: el `.env` es lo que gobierna un despliegue, y el panel solo
     * lo sustituye cuando alguien lo toca a propósito.
     *
     * Es `public` y no `private` porque la resuelve el panel para pintar el
     * interruptor: si cada sitio leyera laBD por su cuenta, el interruptor
     * podría enseñar un estado distinto del que realmente aplica.
     */
    public static function registrationEnabled(): bool
    {
        return AppSettings::bool(AppSettings::REGISTRATION, (bool) Config::get('app.registration_enabled', false));
    }

    public function showRegister(): void
    {
        if (!self::registrationEnabled()) {
            Response::abort(403, 'El registro público está desactivado en esta instalación.');
            return;
        }

        // `min` es PASSWORD_MIN, no un 10 escrito aquí: `register()` valida
        // contra la constante y este formulario es la otra mitad del mismo
        // límite. Con el número fijo, subir el mínimo hacía que el navegador
        // aceptara lo que el servidor iba a rechazar (y al revés).
        View::render('auth/register', [
            'csrf' => Csrf::token(),
            'min'  => self::PASSWORD_MIN,
        ], 'guest');
    }

    public function register(): void
    {
        // El gate va en las DOS rutas, GET y POST, y en el action va ANTES del
        // csrf: apagar la puerta en el formulario pero no en el POST dejaría un
        // endpoint vivo que responde «token inválido» en vez de «no hay
        // registro», que es justo la respuesta que hace perder el tiempo a
        // quien depura.
        if (!self::registrationEnabled()) {
            Response::abort(403, 'El registro público está desactivado en esta instalación.');
            return;
        }

        if (!Csrf::verifyRequest()) {
            Session::flash('error', 'Token de seguridad inválido.');
            Response::redirect('/register');
            return;
        }

        // Rate limiting: máx. 10 registros por IP cada 10 minutos
        $ip = Request::ip();
        if (!RateLimiter::isAllowed('register', $ip, 10, 600)) {
            Session::flash('error', 'Demasiados intentos de registro. Intenta de nuevo en unos minutos.');
            Response::redirect('/register');
            return;
        }

        $name = trim(Request::post('name', ''));
        $email = trim(Request::post('email', ''));
        $password = (string) Request::post('password', '');
        $passwordConfirm = (string) Request::post('password_confirm', '');

        $invalid = self::validateNewUser($name, $email, $password, $passwordConfirm);
        if ($invalid !== null) {
            Session::flash('error', $invalid);
            Response::redirect('/register');
            return;
        }

        if (User::findByEmail($email) !== null) {
            RateLimiter::recordHit('register', $ip, 600);
            Session::flash('error', 'No se pudo completar el registro. Verifica los datos e intenta de nuevo.');
            Response::redirect('/register');
            return;
        }

        $userId = User::create($name, $email, $password, 2);

        // Éxito: resetear el contador de este origen
        RateLimiter::reset('register', $ip);

        Auth::login($userId);
        SecurityLog::record('auth.register', strtolower($email));
        Session::flash('success', 'Cuenta creada correctamente.');
        Response::redirect('/dashboard');
        return;
    }

    public function logout(): void
    {
        SecurityLog::record('auth.logout', 'sesión cerrada por el usuario');
        Auth::logout();
        Response::redirect('/login');
        return;
    }
}