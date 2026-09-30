<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\I18n;
use App\Core\Mailer;
use App\Core\PublicUrl;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\SecurityLog;
use App\Core\Session;
use App\Core\View;
use App\Models\PasswordReset;
use App\Models\User;

/**
 * F2 — Recuperación de contraseña y cambio de contraseña.
 *
 * Tres pantallas, tres puertas:
 *   - `POST /forgot-password`     pide un enlace (anónimo, mensaje uniforme).
 *   - `POST /reset-password`      fija la nueva contraseña desde el enlace.
 *   - `POST /account/password`    la cambia quien ya está dentro.
 *
 * Dos invariantes que este controlador sostiene y que son fáciles de romper:
 *
 *  1. **El mensaje no dice si el email existe.** `/forgot-password` responde
 *     exactamente lo mismo haya cuenta o no, ni con un emoji de diferencia. Con
 *     un mensaje honesto ("si existe, te enviamos..." es ambiguo, pero "no
 *     existe esa cuenta" no) esta pantalla se convierte en un oráculo de qué
 *     emails están dados de alta, que en un panel donde se guardan evaluaciones
 *     de desempeño vale bastante más que la propia contraseña. El login ya
 *     resuelve lo mismo con `DUMMY_HASH`; aquí se copia el patrón.
 *  2. **Cambiar la contraseña cierra las sesiones abiertas.** Ver
 *     `Auth::user()`: sin esto, recuperar la cuenta no expulsaría al atacante
 *     que le robó la sesión, y el enlace de recuperación seguiría siendo útil
 *     solo para el atacante.
 */
class AccountController
{
    /**
     * Mínimo de contraseña. Es la MISMA constante que usa el registro, no una
     * copia: si divergieran, se podría fijar una contraseña por recuperación que
     * el registro no aceptaría.
     */
    private const PASSWORD_MIN = AuthController::PASSWORD_MIN;

    /**
     * Segundos para el rate limit: 3 peticiones por IP y 3 por email en 15 min.
     * Es deliberadamente más estricto que el del login (5/5 min): aquí cada
     * petición dispara un correo, y un buzón lleno es una vía de spam desde
     * nuestra propia app.
     */
    private const RL_WINDOW = 900;

    // ---- /forgot-password ----

    public function showForgot(): void
    {
        View::render('auth/forgot', ['csrf' => Csrf::token()], 'guest');
    }

    public function sendReset(): void
    {
        if (!Csrf::verifyRequest()) {
            Session::flash('error', 'Token de seguridad inválido.');
            Response::redirect('/forgot-password');
            return;
        }

        $email = trim(Request::post('email', ''));
        $ip = Request::ip();

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // Mismo mensaje que el éxito: validando el formato NO se filtra nada
            // (no se ha consultado la BD), pero unificar el texto evita que
            // después alguien "mejore" esta rama para dar un error más claro y
            // abra la enumeración sin darse cuenta.
            Session::flash('success', self::okMessage());
            Response::redirect('/forgot-password');
            return;
        }

        $emailKey = hash('sha256', strtolower($email));

        if (!RateLimiter::isAllowed('password_reset', $ip, 3, self::RL_WINDOW)) {
            SecurityLog::record('rate_limit.password_reset', 'bloqueo por IP (3 peticiones / 15 min)');
            Session::flash('success', self::okMessage());
            Response::redirect('/forgot-password');
            return;
        }
        if (!RateLimiter::isAllowed('password_reset_email', $emailKey, 3, self::RL_WINDOW)) {
            SecurityLog::record('rate_limit.password_reset', 'bloqueo por email (3 peticiones / 15 min)');
            Session::flash('success', self::okMessage());
            Response::redirect('/forgot-password');
            return;
        }

        $user = User::findByEmail($email);

        if ($user === null) {
            // Se verifica una contraseña señuelo para que este camino cuesta lo
            // mismo que el de un correo real (mismo criterio que `DUMMY_HASH` en
            // el login). No lo deja perfecto: el envío SMTP sigue siendo el
            // grueso del tiempo y eso sí delata la diferencia. El mensaje
            // uniforme y el rate limit son las defensas de verdad; esto solo
            // aplana la parte barata del tiempo.
            password_verify($email, AuthController::DUMMY_HASH);
            Session::flash('success', self::okMessage());
            Response::redirect('/forgot-password');
            return;
        }

        // Una cuenta desactivada no se puede recuperar: se le avisa al
        // administrador. El mensaje sigue siendo el mismo para no confirmar que
        // el email existe (el usuario ya lo sabe: es su cuenta).
        if ((int) $user['is_active'] !== 1) {
            SecurityLog::record('auth.password_reset_inactive', (string) $user['id']);
            Session::flash('success', self::okMessage());
            Response::redirect('/forgot-password');
            return;
        }

        $token = PasswordReset::issue((int) $user['id'], $ip);

        $this->sendResetMail($user, $token);

        RateLimiter::reset('password_reset', $ip);
        RateLimiter::reset('password_reset_email', $emailKey);
        SecurityLog::record('auth.password_reset_request', (string) $user['id']);

        Session::flash('success', self::okMessage());
        Response::redirect('/forgot-password');
        return;
    }

    // ---- /reset-password ----

    public function showReset(): void
    {
        $token = trim((string) Request::query('t', ''));

        // Sin middleware `guest` a propósito: el enlace se abre en un navegador
        // que puede tener sesión iniciada, y `guest` lo mandaría a /dashboard
        // sin mostrar nunca el formulario.
        $error = null;
        if (PasswordReset::findValid($token) === null) {
            $error = 'Este enlace no es válido o ha caducado. Pide uno nuevo desde «Olvidé mi contraseña».';
        }

        View::render('auth/reset', [
            'csrf'  => Csrf::token(),
            'token' => $token,
            'error' => $error,
        ], 'guest');
    }

    public function doReset(): void
    {
        if (!Csrf::verifyRequest()) {
            Session::flash('error', 'Token de seguridad inválido.');
            Response::redirect('/forgot-password');
            return;
        }

        // El token llega por el query (enlace del correo) o por el campo oculto
        // del formulario; ambos son la misma credencial.
        $token = trim((string) (Request::post('token') ?: Request::query('t', '')));
        $password = (string) Request::post('password', '');
        $confirm = (string) Request::post('password_confirm', '');

        $invalid = static function (): void {
            Session::flash('error', 'Este enlace no es válido o ha caducado. Pide uno nuevo desde «Olvidé mi contraseña».');
            Response::redirect('/forgot-password');
        };

        if (strlen($password) < self::PASSWORD_MIN) {
            Session::flash('error', 'La contraseña debe tener al menos ' . self::PASSWORD_MIN . ' caracteres.');
            Response::redirect('/reset-password?t=' . urlencode($token));
            return;
        }
        if ($password !== $confirm) {
            Session::flash('error', 'Las contraseñas no coinciden.');
            Response::redirect('/reset-password?t=' . urlencode($token));
            return;
        }


        $row = PasswordReset::findValid($token);
        if ($row === null) {
            $invalid();
            return;
        }
        if ((int) ($row['is_active'] ?? 1) !== 1) {
            $invalid();
            return;
        }

        // No se puede elegir la MISMA contraseña que ya tenía la cuenta: quien
        // recupera el control de una cuenta robada necesita poder distinguirla
        // de la anterior, y si aceptara la misma, el atacante seguiría dentro
        // con la credencial que ya tenía.
        //
        // Se compara contra el hash guardado y NO contra un campo del POST: en
        // la recuperación no se conoce la contraseña actual, así que un campo
        // "la actual es X" sólo probaría lo que el propio POST afirma (y además
        // se lo saltaría con cualquier otro valor). Aquí se verifica de verdad,
        // con un bcrypt, y contra el señuelo si el usuario hubiera desaparecido
        // entre la emisión del enlace y este POST, para no delatar con el
        // tiempo de respuesta que la fila existe o no.
        $owner = User::find((int) $row['user_id']);
        $isSame = $owner !== null
            ? password_verify($password, (string) $owner['password_hash'])
            : password_verify($password, AuthController::DUMMY_HASH);
        if ($isSame) {
            Session::flash('error', 'La nueva contraseña debe ser distinta de la actual.');
            Response::redirect('/reset-password?t=' . urlencode($token));
            return;
        }

        $db = Database::getInstance(\App\Core\Config::db());
        $db->beginTransaction();
        try {
            // Marcar el token usado y cambiar la contraseña en la MISMA
            // transacción: si se marcara antes, un fallo al guardar dejaría el
            // enlace quemado y la contraseña sin cambiar (la persona se queda
            // fuera y tiene que pedir otro correo); si se cambiara antes, dos
            // peticiones simultáneas con el mismo enlace podrían prevailas las
            // dos.
            $claimed = PasswordReset::markUsed((int) $row['id']);
            if ($claimed === 0) {
                // Otra petición llegó primero: esta no toca nada.
                $db->rollBack();
                $invalid();
                return;
            }

            User::updatePassword((int) $row['user_id'], $password);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('[AccountController] doReset: ' . $e->getMessage());
            Session::flash('error', 'No se pudo completar el cambio. Intenta de nuevo.');
            Response::redirect('/reset-password?t=' . urlencode($token));
            return;
        }

        // Los tokens pendientes que quedaran (no debería haber, `issue()` los
        // limpia) se invalidan igual: este correo ya está en la bandeja de
        // quien pidió el enlace.
        PasswordReset::invalidateFor((int) $row['user_id']);
        SecurityLog::record('auth.password_reset_done', (string) $row['user_id']);

        // El navegador que acaba de completar la recuperación se queda SIN
        // sesión. No por descuido: `/reset-password` no lleva middleware `guest`
        // (el enlace se abre en un navegador que puede estar ya identificado) y
        // sin este cierre esa sesión seguiría marcada como de la credencial
        // anterior, con el `guest` de `/login` rebotando a `/dashboard` en vez
        // de dejar entrar. Se usa el cierre que conserva el flash para poder
        // confirmar el cambio.
        Auth::revokeCurrentSession();

        Session::flash('success', 'Tu contraseña se ha cambiado. Ya puedes iniciar sesión.');
        Response::redirect('/login');
        return;
    }

    // ---- /account/password ----

    public function showPassword(): void
    {
        $user = Auth::user();
        View::render('account/password', [
            'csrf' => Csrf::token(),
            'user' => $user,
        ]);
    }

    public function changePassword(): void
    {
        if (!Csrf::verifyRequest()) {
            Session::flash('error', 'Token de seguridad inválido.');
            Response::redirect('/account/password');
            return;
        }

        $user = Auth::user();
        if ($user === null) {
            Response::redirect('/login');
            return;
        }

        $current = (string) Request::post('current_password', '');
        $password = (string) Request::post('password', '');
        $confirm = (string) Request::post('password_confirm', '');

        $fail = static function (string $message): void {
            Session::flash('error', $message);
            Response::redirect('/account/password');
        };

        if (strlen($password) < self::PASSWORD_MIN) {
            $fail('La contraseña debe tener al menos ' . self::PASSWORD_MIN . ' caracteres.');
            return;
        }
        if ($password !== $confirm) {
            $fail('Las contraseñas no coinciden.');
            return;
        }
        if ($password === $current) {
            $fail('La nueva contraseña debe ser distinta de la actual.');
            return;
        }

        // Se verifica SIEMPRE una contraseña, exista o no el campo, contra su
        // hash o contra el señuelo (mismo criterio que el login): sin esto, un
        // POST sin `current_password` respondería en microsegundos y revelaría
        // que la sesión es falsa.
        $fresh = User::find((int) $user['id']);
        $ok = $fresh !== null
            ? password_verify($current, (string) $fresh['password_hash'])
            : password_verify($current, AuthController::DUMMY_HASH);

        if (!$ok) {
            SecurityLog::record('auth.password_change_fail', (string) $user['id']);
            $fail('La contraseña actual no es correcta.');
            return;
        }

        $changedAt = User::updatePassword((int) $user['id'], $password);
        PasswordReset::invalidateFor((int) $user['id']);
        SecurityLog::record('auth.password_change', (string) $user['id']);

        // La sesión actual se queda viva: es la misma persona que acaba de
        // demostrar la contraseña Y de cambiar la credencial, así que no hay
        // nada que cerrar. `updatePassword` movió `password_changed_at` a ahora,
        // y como `auth_at` es ANTERIOR, `Auth::user()` cerraría la sesión en
        // la siguiente página: se refresca con el instante que devolvió la BD
        // (no con el reloj de PHP, que puede ir un segundo por detrás) para que
        // la sesión actual sobreviva y las ANTIGUAS mueran.
        Auth::markSessionFresh($changedAt);

        Session::flash('success', 'Tu contraseña se ha cambiado.');
        Response::redirect('/account/password');
        return;
    }

    // ---- correo ----

    /**
     * Envía el correo con el enlace de recuperación.
     *
     * NUNCA propaga una excepción hacia el controlador: si el SMTP falla, la
     * persona ya ha visto el mensaje de éxito y un 500 aquí la dejaría pensando
     * que no se envió nada (o, peor, que sí). Se registra y se sigue.
     */
    private function sendResetMail(array $user, string $token): void
    {
        // Los usuarios no tienen idioma guardado, así que el correo sale en el
        // idioma por defecto de la app, validado contra las soportadas. No se
        // infiere del `Accept-Language` de quien pide el enlace: el correo lo lee
        // quien lo recibe, que puede ser otra persona con otro navegador.
        $locale = (string) \App\Core\Config::get('app.locale', 'es');
        if (!in_array($locale, I18n::supportedLocales(), true)) {
            $locale = 'es';
        }
        $url = PublicUrl::base() . '/reset-password?t=' . urlencode($token);

        try {
            Mailer::send(
                [(string) $user['email']],
                I18n::t('mail.reset_subject', $locale),
                View::partial('mail/password_reset', [
                    'name' => (string) $user['name'],
                    'url'  => $url,
                    'ttl'  => PasswordReset::TTL_HOURS,
                    'locale' => $locale,
                ]),
                I18n::t('mail.reset_alt', $locale, ['name' => (string) $user['name'], 'url' => $url])
            );
        } catch (\Throwable $e) {
            // Sin correo, el token existe pero nadie lo tiene. Se registra para
            // que el admin lo vea en el log y no para mostrárselo a quien lo
            // pidió (el mensaje de éxito es el mismo siempre).
            error_log('[AccountController] correo de recuperación no enviado a '
                . (int) $user['id'] . ': ' . $e->getMessage());
        }
    }

    /**
     * El mensaje de éxito, uno solo para todos los caminos. Cualquier cambio de
     * texto aquí es un cambio en el formato del mensaje, no en su contenido:
     * debe seguir sin decir si la cuenta existe.
     */
    private static function okMessage(): string
    {
        return 'Si esa dirección está registrada, te enviamos un enlace para cambiar la contraseña. Revisa también la carpeta de spam.';
    }
}
