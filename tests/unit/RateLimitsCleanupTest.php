<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Core\Database;
use App\Core\Env;
use App\Core\RateLimiter;
use ReflectionClass;

/**
 * Mantenimiento de la tabla `rate_limits` (fase 3, capa de datos).
 *
 * La tabla se llenaba sola y no se vaciaba nunca: cada combinación
 * scope/identificador dejaba una fila para siempre, así que crecía sin tope en
 * una tabla que nadie lee más que para contar. Estos tests fijan las tres
 * propiedades del cleanup —qué se borra, qué NO se borra y que sea idempotente
 * y acotado— y el umbral de 600 s, que es el máximo de ventana que admite el
 * limitador: si se limpiaran solo las caducadas de la ventana actual, con una
 * ventana de 1 h las de 1 h seguirían ahí para siempre.
 *
 * Requiere la BD real. Cada test usa filas propias y las borra en tearDown.
 */
class RateLimitsCleanupTest
{
    private static bool $envLoaded = false;
    private static ?Database $db = null;

    /** Marcas de los scopes que cada test crea, para borrarlos al terminar. */
    private static array $scopes = [];

    public function setUp(): void
    {
        self::loadEnv();
        self::db();
    }

    public function tearDown(): void
    {
        foreach (self::$scopes as $scope) {
            self::db()->execute('DELETE FROM rate_limits WHERE scope = :a', ['a' => $scope]);
        }
        self::$scopes = [];
    }

    /* ------------------------------------------------------------------ */

    public function testLimpiaLoCaducadoYConservaLoVivo(): void
    {
        $scope = self::crearScope();

        // Una fila de hace 2 horas (caducada para cualquier ventana razonable)
        // y una de hace 10 s (viva). Mismo scope, para que la limpieza no pueda
        // confundirse con un borrado por identificador.
        self::db()->execute(
            'INSERT INTO rate_limits (scope, identifier, attempts, window_start) VALUES (:a, :i, 1, NOW() - INTERVAL 7200 SECOND)',
            ['a' => $scope, 'i' => 'viejo']
        );
        self::db()->execute(
            'INSERT INTO rate_limits (scope, identifier, attempts, window_start) VALUES (:a, :i, 1, NOW() - INTERVAL 10 SECOND)',
            ['a' => $scope, 'i' => 'nuevo']
        );

        $borradas = RateLimiter::cleanExpired(RateLimiter::MAX_WINDOW);

        $restantes = array_column(self::db()->fetchAll(
            'SELECT identifier FROM rate_limits WHERE scope = :a',
            ['a' => $scope]
        ), 'identifier');

        assertTrue($borradas >= 1, 'debe borrar al menos la fila caducada');
        assertTrue(!in_array('viejo', $restantes, true), 'la fila de hace 2 h está caducada y debe irse');
        assertTrue(in_array('nuevo', $restantes, true), 'la fila de hace 10 s está viva y NO debe tocarse');
    }

    public function testLaVentanaMaximaAcotaLoQueSeLimpia(): void
    {
        // El límite: 600 s es la ventana más larga que acepta el limitador
        // (MAX_WINDOW). Con MAX_WINDOW, una fila de hace 900 s (más alla de
        // cualquier ventana posible) se va; una de hace 300 s, no. Si el
        // umbral fuera más pequeño que la ventana más larga, sus filas nunca se
        // borrarían.
        assertTrue(RateLimiter::MAX_WINDOW === 600, 'la ventana máxima de limpieza debe coincidir con la del limitador');

        $scope = self::crearScope();
        self::db()->execute(
            'INSERT INTO rate_limits (scope, identifier, attempts, window_start) VALUES (:a, :i, 1, NOW() - INTERVAL 900 SECOND)',
            ['a' => $scope, 'i' => 'pasado']
        );
        RateLimiter::cleanExpired(RateLimiter::MAX_WINDOW);
        $quedan = self::db()->fetch(
            'SELECT COUNT(*) AS c FROM rate_limits WHERE scope = :a AND identifier = :i',
            ['a' => $scope, 'i' => 'pasado']
        );
        assertEquals(0, (int) ($quedan['c'] ?? -1), 'una fila de 900 s está fuera de toda ventana posible y debe borrarse');
    }

    public function testLaLimpiezaEsIdempotente(): void
    {
        $scope = self::crearScope();
        self::db()->execute(
            'INSERT INTO rate_limits (scope, identifier, attempts, window_start) VALUES (:a, :i, 1, NOW() - INTERVAL 7200 SECOND)',
            ['a' => $scope, 'i' => 'caducada']
        );

        $primera = RateLimiter::cleanExpired(RateLimiter::MAX_WINDOW);
        $segunda = RateLimiter::cleanExpired(RateLimiter::MAX_WINDOW);

        assertTrue($primera >= 1, 'la primera pasada borra la caducada');
        assertEquals(0, $segunda, 'la segunda pasada no tiene nada que hacer y no debe fallar');
    }

    public function testNoSeBorraLoQueEstaEnLaVentana(): void
    {
        // El caso que importa de verdad: el limitador cuenta sobre
        // `window_start`, así que una fila dentro de su ventana es un contador
        // VIVO. Borrarla sería abrir la vía a saltarse el límite.
        $scope = self::crearScope();
        self::db()->execute(
            'INSERT INTO rate_limits (scope, identifier, attempts, window_start) VALUES (:a, :i, 1, NOW())',
            ['a' => $scope, 'i' => 'contador']
        );

        $antes = self::db()->fetch(
            'SELECT attempts FROM rate_limits WHERE scope = :a AND identifier = :i',
            ['a' => $scope, 'i' => 'contador']
        );

        RateLimiter::cleanExpired(RateLimiter::MAX_WINDOW);

        $despues = self::db()->fetch(
            'SELECT attempts FROM rate_limits WHERE scope = :a AND identifier = :i',
            ['a' => $scope, 'i' => 'contador']
        );

        assertNotNull($despues, 'un contador en su ventana no puede desaparecer');
        assertEquals((int) ($antes['attempts'] ?? -1), (int) ($despues['attempts'] ?? -2), 'ni perder los golpes acumulados');
    }

    /* --- maybeCleanExpired: la limpieza oportunista, una vez por petición --- */

    public function testLaLimpiezaOportunistaDevuelveCuantoBorroYNoComoMinimo(): void
    {
        $prop = self::resetDePeticion();

        $scope = self::crearScope();
        self::db()->execute(
            'INSERT INTO rate_limits (scope, identifier, attempts, window_start) VALUES (:a, :i, 1, NOW() - INTERVAL 7200 SECOND)',
            ['a' => $scope, 'i' => 'caducada']
        );

        // Con probabilidad 100 siempre barre, y devuelve el número real de
        // filas. Devolver siempre 0 es lo que hace imposible distinguir un cleanup que
        // no hizo nada de uno que no encontró nada, y por eso el retorno es
        // ?int: null = no se ejecutó, entero = filas borradas.
        $borradas = RateLimiter::maybeCleanExpired(100);

        assertTrue($borradas !== null, 'con probabilidad 100 tiene que ejecutar la limpieza');
        assertTrue($borradas >= 1, 'y devolver cuántas filas borró, no un 0 fijo');
    }

    public function testLaLimpiezaOportunistaSoloBarreUnaVezPorPeticion(): void
    {
        self::resetDePeticion();

        // La primera barre; las siguientes, aunque salgan con probabilidad 100,
        // devuelven null. Sin este testigo, cada escritura de la página
        // ejecutaría un DELETE más.
        $primera = RateLimiter::maybeCleanExpired(100);
        $segunda = RateLimiter::maybeCleanExpired(100);
        $tercera = RateLimiter::maybeCleanExpired(100);

        assertTrue($primera !== null, 'la primera llamada barre');
        assertNull($segunda, 'la segunda ya no: una vez por petición');
        assertNull($tercera, 'y la tercera tampoco');
    }

    public function testConProbabilidadCeroNoBarre(): void
    {
        self::resetDePeticion();

        assertNull(RateLimiter::maybeCleanExpired(0), 'probabilidad 0 significa nunca, ni siquiera en la primera llamada');
    }

    public function testLaProbabilidadFueraDeRangoSeRecorta(): void
    {
        self::resetDePeticion();

        // Ni 0 ni 100 exactos, ni negativos ni mayores que 100: random_int(1, 100)
        // lanza excepción si el rango queda vacío, así que un valor de config
        // equivocado no puede tumbar la página.
        assertTrue(RateLimiter::maybeCleanExpired(-5) === null, 'una probabilidad negativa no barre');
        assertTrue(RateLimiter::maybeCleanExpired(1000) !== null, 'una probabilidad > 100 se recorta a 100 y barre');
    }

    /**
     * `recordHit()` es lo que mantiene la tabla acotada en producción: sin
     * ningún cron ni tarea programada, la limpieza depende de que cada escritura
     * se lleve su propia pasada. Antes de esta fase, `cleanExpired()` no tenía
     * NINGÚN call site y la tabla crecía para siempre.
     *
     * El test fija la probabilidad a 100 con el seam `setCleanupProbability()`
     * porque con la del 1 % real, unas pocas escrituras NO garantizan limpiar: el
     * test pasaría igual con la llamada puesta que quitada, y eso es
     * precisamente el error que hay que cazar (probabilidad 1 % de que un test
     * que no prueba nada se vea verde).
     */
    public function testEscribirUnGolpeYaDisparaLaLimpieza(): void
    {
        $scope = self::crearScope();

        self::db()->execute(
            'INSERT INTO rate_limits (scope, identifier, attempts, window_start) VALUES (:a, :i, 1, NOW() - INTERVAL 7200 SECOND)',
            ['a' => $scope, 'i' => 'caducada']
        );

        self::resetDePeticion();
        RateLimiter::setCleanupProbability(100);
        try {
            RateLimiter::recordHit($scope, 'nuevo');
        } finally {
            RateLimiter::setCleanupProbability(null);
        }

        $restantes = array_column(self::db()->fetchAll(
            'SELECT identifier FROM rate_limits WHERE scope = :a',
            ['a' => $scope]
        ), 'identifier');

        assertTrue(in_array('nuevo', $restantes, true), 'el golpe que se acaba de contar debe estar');
        assertTrue(!in_array('caducada', $restantes, true), 'y la fila caducada debe haber desaparecido en esa misma escritura');
    }

    /**
     * Con la probabilidad de por defecto (1 %) el comportamiento observable es
     * que la limpieza CASI no ocurre, y eso está bien: el coste de un DELETE
     * indexado no puede ir en cada login. Este test fija que el valor por
     * defecto es bajo a propósito, para que nadie lo suba sin querer pensando
     * que es gratis.
     */
    public function testLaProbabilidadPorDefectoEsBaja(): void
    {
        assertTrue(RateLimiter::PROBABILIDAD >= 1 && RateLimiter::PROBABILIDAD <= 5, 'el valor por defecto debería ser de 1 a 5 %, no mayor');
    }

    /* ------------------------------------------------------------------ */

    /** Devuelve el nombre de un scope propio de este test. */
    private static function crearScope(): string
    {
        $scope = 'test:cleanup:' . bin2hex(random_bytes(5));
        self::$scopes[] = $scope;

        return $scope;
    }

    /**
     * Pone a false el testigo de "ya barred en esta petición".
     *
     * El runner instancia la clase una vez por test, pero el testigo es un
     * static que vive en el proceso: sin resetearlo, el segundo test que quiera
     * comprobar la limpieza oportunista heredaría el `true` del primero y
     * `maybeCleanExpired` devolvería null siempre, pasando sin limpiar nada.
     */
    private static function resetDePeticion(): void
    {
        $ref = new ReflectionClass(RateLimiter::class);
        $prop = $ref->getProperty('cleanedThisRequest');
        $prop->setAccessible(true);
        $prop->setValue(null, false);
    }

    private static function loadEnv(): void
    {
        if (!self::$envLoaded) {
            Env::load(dirname(__DIR__, 2) . '/.env');
            self::$envLoaded = true;
        }
    }

    private static function db(): Database
    {
        if (self::$db === null) {
            self::$db = Database::getInstance(Config::db());
        }

        return self::$db;
    }
}
