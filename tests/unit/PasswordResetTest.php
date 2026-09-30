<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Env;
use App\Core\RateLimiter;
use App\Core\Session;
use App\Models\PasswordReset;
use App\Models\User;

/**
 * F2 — Recuperación de contraseña: el modelo de tokens y la revocación de
 * sesiones.
 *
 * El punto de todo el flujo es `users.password_changed_at`: sin él, recuperar la
 * contraseña no expulsaría a nadie y el enlace solo serviría para que el
 * atacante que ya tenía la sesión siga dentro. Por eso hay tests que comprueban
 * las DOS direcciones (que una sesión vieja muere y que la del dueño sobrevive).
 *
 * La parte del correo NO se prueba aquí: el envío va por `Mailer::setTransport`,
 * que es de otro test. Lo que se comprueba es el modelo, que es donde está la
 * lógica que se puede equivocar (caducidad, un solo uso, hash, invalidación).
 */
class PasswordResetTest
{
    private static bool $envLoaded = false;
    private static ?int $userId = null;
    private static ?string $password = null;

    public function setUp(): void
    {
        self::loadEnv();
        self::cleanupResiduals();

        self::$password = 'vieja-clave-' . bin2hex(random_bytes(4));
        self::$userId = User::create(
            'Reset Test',
            'pwreset-' . bin2hex(random_bytes(4)) . '@test.local',
            self::$password,
            2
        );
    }

    public function tearDown(): void
    {
        $db = Database::getInstance(\App\Core\Config::db());
        if (self::$userId !== null) {
            $db->execute('DELETE FROM users WHERE id = :id', ['id' => self::$userId]);
        }
        RateLimiter::reset('test', 'x');
        Auth::forgetCache();
        Session::destroy();
        self::$userId = null;
    }

    // ---- El token ----

    public function testElTokenEmitidoSeValidaYCaducaEnUnaHora(): void
    {
        $token = PasswordReset::issue((int) self::$userId, '192.0.2.10');

        assertTrue(PasswordReset::isWellFormedToken($token), 'el token debe ser 32 hex');
        assertNotNull(PasswordReset::findValid($token), 'un token recién emitido debe ser válido');

        $row = Database::getInstance(\App\Core\Config::db())->fetch(
            'SELECT * FROM password_resets WHERE user_id = :uid',
            ['uid' => (int) self::$userId]
        );
        assertNotNull($row);
        $caduca = strtotime((string) $row['expires_at'] . ' UTC') - time();
        // Margen amplio: lo que se comprueba es el ORDEN DE MAGNITUD (una hora,
        // no cinco minutos ni cinco días), no el reloj al segundo.
        assertTrue($caduca > 3000 && $caduca <= 3600, 'el token debe caducar en ~1 hora, tardó ' . $caduca . 's');
    }

    public function testLaTablaGuardaElHashYNoElToken(): void
    {
        $token = PasswordReset::issue((int) self::$userId);

        $row = Database::getInstance(\App\Core\Config::db())->fetch(
            'SELECT token_hash FROM password_resets WHERE user_id = :uid',
            ['uid' => (int) self::$userId]
        );
        assertNotNull($row);

        $hash = (string) $row['token_hash'];
        assertTrue($hash !== $token, 'el token NO se guarda en claro');
        assertSame(hash('sha256', $token), $hash, 'el hash debe ser sha256 del token');
        assertTrue(!str_contains($hash, substr($token, 0, 12)), 'ni siquiera un trozo del token debe aparecer');
    }

    public function testElTokenEsDeUnSoloUso(): void
    {
        $token = PasswordReset::issue((int) self::$userId);
        $row = PasswordReset::findValid($token);
        assertNotNull($row);

        assertSame(1, PasswordReset::markUsed((int) $row['id']), 'el primer markUsed marca');
        assertNull(PasswordReset::findValid($token), 'un token usado ya no es válido');
        assertSame(0, PasswordReset::markUsed((int) $row['id']), 'marcar dos veces no revienta ni reabre');
    }

    public function testUnTokenCaducadoNoValida(): void
    {
        $token = PasswordReset::issue((int) self::$userId);
        Database::getInstance(\App\Core\Config::db())->execute(
            'UPDATE password_resets SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE user_id = :uid',
            ['uid' => (int) self::$userId]
        );

        assertNull(PasswordReset::findValid($token), 'un token caducado no puede servir');
    }

    public function testPedirUnTokenInvalidaLosAnteriores(): void
    {
        $primero = PasswordReset::issue((int) self::$userId);
        $segundo = PasswordReset::issue((int) self::$userId);

        assertNull(PasswordReset::findValid($primero), 'el token anterior deja de servir al pedir otro');
        assertNotNull(PasswordReset::findValid($segundo), 'el nuevo sí sirve');

        $cuantas = Database::getInstance(\App\Core\Config::db())->fetch(
            'SELECT COUNT(*) AS c FROM password_resets WHERE user_id = :uid AND used_at IS NULL',
            ['uid' => (int) self::$userId]
        );
        assertSame(1, (int) ($cuantas['c'] ?? 0), 'solo queda un token pendiente por usuario');
    }

    public function testUnTokenMalFormadoNoLlegaNiABuscarlo(): void
    {
        assertFalse(PasswordReset::isWellFormedToken(''));
        assertFalse(PasswordReset::isWellFormedToken('no-hex'));
        assertFalse(PasswordReset::isWellFormedToken(str_repeat('z', 32)));
        assertFalse(PasswordReset::isWellFormedToken(str_repeat('a', 31)), '31 chars no es un token');
        assertNull(PasswordReset::findValid('basura'));
        // Una cadena enorme no debe ni intentar hashearse.
        assertNull(PasswordReset::findValid(str_repeat('a', 100000)));
    }

    public function testInvalidateForCierraLosTokensPendientes(): void
    {
        $token = PasswordReset::issue((int) self::$userId);
        PasswordReset::invalidateFor((int) self::$userId);
        assertNull(PasswordReset::findValid($token), 'cambiar la contraseña invalida los enlaces previos');
    }

    public function testLaPurgaBorraUsadosYCaducados(): void
    {
        $usado = PasswordReset::issue((int) self::$userId);
        $fila = PasswordReset::findValid($usado);
        PasswordReset::markUsed((int) $fila['id']);

        $caducado = PasswordReset::issue((int) self::$userId);
        Database::getInstance(\App\Core\Config::db())->execute(
            'UPDATE password_resets SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 HOUR WHERE user_id = :uid',
            ['uid' => (int) self::$userId]
        );

        PasswordReset::purgeExpired();

        $restantes = Database::getInstance(\App\Core\Config::db())->fetchAll(
            'SELECT id FROM password_resets WHERE user_id = :uid',
            ['uid' => (int) self::$userId]
        );
        assertSame([], $restantes, 'la purga debe llevarse los usados y los caducados');
        assertNull(PasswordReset::findValid($caducado));
    }

    // ---- Cambiar la contraseña revoca las sesiones ----

    public function testCambiarLaPasswordCierraLasSesionesAnteriores(): void
    {
        Auth::assumeLoggedIn((int) self::$userId);
        assertNotNull(Auth::user(), 'la sesión acaba de abrirse, es válida');

        // Alguien entra en otra pestaña más tarde que el cambio de contraseña.
        User::updatePassword((int) self::$userId, 'otra-clave-larga-1234');
        Auth::forgetCache();

        assertNull(Auth::user(), 'una sesión abierta ANTES del cambio debe quedar cerrada');
        assertFalse(Auth::check(), 'y el usuario debe tratar como anónimo');
    }

    public function testLaSesionDelDueoSobreviveASuPropioCambio(): void
    {
        // La sesión actual se refresca (ver AccountController::changePassword);
        // sin ese refresco, cambiar tu propia contraseña te echaría del panel en
        // la siguiente página, que sería el fallo más absurdo posible.
        Auth::assumeLoggedIn((int) self::$userId);
        $changedAt = User::updatePassword((int) self::$userId, 'otra-clave-larga-1234');
        Auth::markSessionFresh($changedAt);

        assertNotNull(Auth::user(), 'quien cambia su contraseña no se expulsa a sí mismo');
    }

    public function testCambioYLoginEnElMismoSegundoNoSeCierran(): void
    {
        // La trampa de la granularidad de un segundo: `auth_at` (PHP) y
        // `password_changed_at` (MariaDB) son dos relojes con resolución de
        // segundo, así que abrir sesión y cambiar la contraseña dentro del
        // mismo segundo son indistinguibles. Sin el `+1` de `markSessionFresh`
        // y sin tratar la igualdad como obsoleta, uno de los dos casos
        // falla: o la sesión sobrevive a un robo real, o se expulsa a quien
        // acaba de entrar.
        User::updatePassword((int) self::$userId, 'otra-clave-larga-1234');
        Auth::assumeLoggedIn((int) self::$userId);
        assertNotNull(
            Auth::user(),
            'iniciar sesión en el mismo segundo del cambio no debe cerrar la sesión'
        );
    }

    public function testRevocarLaSesionActualConservaElFlash(): void
    {
        Auth::assumeLoggedIn((int) self::$userId);
        Session::flash('success', 'mensaje');

        Auth::revokeCurrentSession();

        assertFalse(Auth::check(), 'tras revocar, el usuario es anónimo');
        assertSame('mensaje', Session::getFlash('success'), 'el flash debe sobrevivir al cierre');
    }

    public function testUnaSesionSinAuthAtSeCierra(): void
    {
        // Sesión anterior a F2: no se puede comprobar de qué credencial viene,
        // así que se cierra. Cuesta un cierre de sesión al desplegar.
        Session::set('user_id', (int) self::$userId);
        Auth::forgetCache();

        assertNull(Auth::user(), 'una sesión sin auth_at no se puede validar y se cierra');
    }

    public function testUnaSesionDeposteriorAlCambioNoSeCierra(): void
    {
        User::updatePassword((int) self::$userId, 'otra-clave-larga-1234');
        // Un login NUEVO, posterior al cambio: sí vale.
        Auth::assumeLoggedIn((int) self::$userId);
        assertNotNull(Auth::user(), 'iniciar sesión después del cambio debe funcionar');
    }

    public function testElRehashDelLoginNoCierraLaSesion(): void
    {
        // `updatePasswordHash` (el rehash oportunista del login) NO debe mover
        // `password_changed_at`: rehashear con el mismo secreto no es cambiar la
        // contraseña, y si lo hiciera, cada login con hash antiguo echaría al
        // usuario nada más entrar.
        Auth::assumeLoggedIn((int) self::$userId);
        $antes = $this->passwordChangedAt();

        $user = User::find((int) self::$userId);
        assertNotNull($user);
        User::updatePasswordHash((int) self::$userId, (string) (self::$password ?? ''));
        Auth::forgetCache();

        assertSame($antes, $this->passwordChangedAt(), 'el rehash no debe tocar password_changed_at');
        assertNotNull(Auth::user(), 'ni cerrar la sesión');
    }

    public function testUpdatePasswordGuardaHashYInstanteJuntos(): void
    {
        User::updatePassword((int) self::$userId, 'clave-nueva-larguisima');

        $user = User::find((int) self::$userId);
        assertNotNull($user);
        assertTrue(
            password_verify('clave-nueva-larguisima', (string) $user['password_hash']),
            'la contraseña nueva debe verificar'
        );
        assertNotNull($this->passwordChangedAt(), 'password_changed_at debe quedar escrito');
    }

    public function testCambiarLaPasswordDejaDeVerificarLaAnterior(): void
    {
        $vieja = (string) (self::$password ?? '');
        assertTrue(password_verify($vieja, (string) (User::find((int) self::$userId)['password_hash'] ?? '')));

        User::updatePassword((int) self::$userId, 'clave-nueva-larguisima');

        assertFalse(
            password_verify($vieja, (string) (User::find((int) self::$userId)['password_hash'] ?? '')),
            'la contraseña anterior debe dejar de servir'
        );
    }

    private function passwordChangedAt(): ?string
    {
        $row = Database::getInstance(\App\Core\Config::db())->fetch(
            'SELECT password_changed_at FROM users WHERE id = :id',
            ['id' => (int) self::$userId]
        );
        $value = $row['password_changed_at'] ?? null;
        return $value === null ? null : (string) $value;
    }

    private static function loadEnv(): void
    {
        if (!self::$envLoaded) {
            Env::load(dirname(__DIR__, 2) . '/.env');
            self::$envLoaded = true;
        }
    }

    private static function cleanupResiduals(): void
    {
        Database::getInstance(\App\Core\Config::db())
            ->execute("DELETE FROM users WHERE email LIKE '%@test.local'");
    }
}
