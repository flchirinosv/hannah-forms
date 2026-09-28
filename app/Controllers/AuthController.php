<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
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
     */
    private const DUMMY_HASH = '$2y$12$z0PiH/0pr1y5CX2qWa9S6ec4Cw9eMuNODP2I8F.k9OTEkKh5I/RRu';

    /**
     * Mínimo de contraseña al registrarse.
     *
     * 10, no 12, y a propósito: el plan pedía 10-12, pero los usuarios demo
     * del seed usan `password123` (11) y subirlos a 12 los dejaría fuera de su
     * propia contraseña documentada. Con 10 el mínimo sigue siendo firme y el
     * seed sigue funcionando; el hash del seed es bcrypt coste 12, que es la
     * mitad del trabajo.
     */
    private const PASSWORD_MIN = 10;

    public function showLogin(): void
    {
        View::render('auth/login', ['csrf' => Csrf::token()], 'guest');
    }

    public function login(): void
    {
        if (!Csrf::verifyRequest()) {
            Session::flash('error', 'Token de seguridad inválido.');
            Response::redirect('/login');
        }

        $email = trim(Request::post('email', ''));
        $password = (string) Request::post('password', '');

        if ($email === '' || $password === '') {
            Session::flash('error', 'Ingresa email y contraseña.');
            Response::redirect('/login');
        }

        // Rate limiting: 5 intentos por IP cada 5 minutos
        $ip = Request::ip();
        if (!RateLimiter::isAllowed('login', $ip, 5, 300)) {
            Session::flash('error', 'Demasiados intentos de inicio de sesión. Intenta de nuevo en unos minutos.');
            Response::redirect('/login');
        }

        // A2: además, límite por email (identificador hasheado para no
        // persistir PII en rate_limits) para mitigar fuerza bruta distribuida.
        $emailKey = hash('sha256', strtolower($email));
        $emailHit = false;
        if (!RateLimiter::isAllowed('login_email', $emailKey, 10, 300)) {
            Session::flash('error', 'Demasiados intentos para esta cuenta. Intenta de nuevo en unos minutos.');
            Response::redirect('/login');
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
            Session::flash('error', 'Credenciales incorrectas.');
            Response::redirect('/login');
        }

        if ((int) $user['is_active'] !== 1) {
            Session::flash('error', 'La cuenta está desactivada. Contacta al administrador.');
            Response::redirect('/login');
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
        Session::flash('success', 'Bienvenido, ' . $user['name'] . '!');
        Response::redirect('/dashboard');
    }

    public function showRegister(): void
    {
        View::render('auth/register', ['csrf' => Csrf::token()], 'guest');
    }

    public function register(): void
    {
        if (!Csrf::verifyRequest()) {
            Session::flash('error', 'Token de seguridad inválido.');
            Response::redirect('/register');
        }

        // Rate limiting: máx. 10 registros por IP cada 10 minutos
        $ip = Request::ip();
        if (!RateLimiter::isAllowed('register', $ip, 10, 600)) {
            Session::flash('error', 'Demasiados intentos de registro. Intenta de nuevo en unos minutos.');
            Response::redirect('/register');
        }

        $name = trim(Request::post('name', ''));
        $email = trim(Request::post('email', ''));
        $password = (string) Request::post('password', '');
        $passwordConfirm = (string) Request::post('password_confirm', '');

        if ($name === '' || $email === '' || $password === '') {
            Session::flash('error', 'Todos los campos son obligatorios.');
            Response::redirect('/register');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Session::flash('error', 'Email no válido.');
            Response::redirect('/register');
        }

        // M3: validar longitudes para evitar errores 500 silenciosos de la BD
        // (users.name VARCHAR(100), users.email VARCHAR(190)).
        if (mb_strlen($name) > 100) {
            Session::flash('error', 'El nombre no puede superar los 100 caracteres.');
            Response::redirect('/register');
        }

        if (mb_strlen($email) > 190) {
            Session::flash('error', 'El email no puede superar los 190 caracteres.');
            Response::redirect('/register');
        }

        if (strlen($password) < self::PASSWORD_MIN) {
            Session::flash('error', 'La contraseña debe tener al menos ' . self::PASSWORD_MIN . ' caracteres.');
            Response::redirect('/register');
        }

        if ($password !== $passwordConfirm) {
            Session::flash('error', 'Las contraseñas no coinciden.');
            Response::redirect('/register');
        }

        if (User::findByEmail($email) !== null) {
            RateLimiter::recordHit('register', $ip, 600);
            Session::flash('error', 'No se pudo completar el registro. Verifica los datos e intenta de nuevo.');
            Response::redirect('/register');
        }

        $userId = User::create($name, $email, $password, 2);

        // Éxito: resetear el contador de este origen
        RateLimiter::reset('register', $ip);

        Auth::login($userId);
        Session::flash('success', 'Cuenta creada correctamente.');
        Response::redirect('/dashboard');
    }

    public function logout(): void
    {
        Auth::logout();
        Response::redirect('/login');
    }
}