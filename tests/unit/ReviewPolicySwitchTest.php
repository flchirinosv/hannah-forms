<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\AdminController;
use App\Core\AppSettings;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Models\Form;
use App\Models\Role;
use App\Models\User;
use App\Services\ReviewPolicy;

/**
 * Política de revisión de la INSTANCIA (interruptor de `/admin`).
 *
 * Antes la revisión era un ajuste POR FORMULARIO (`settings.review`); ahora es
 * una sola decisión de la instalación, con dos fuentes y el mismo orden que el
 * registro público: la fila de `app_settings` gana si existe, y si no manda el
 * `.env`. Se prueba ese orden, que lo que se guarda es `0`/`1` y no el default,
 * que la puerta exige `admin.access`, y que el cambio va al log.
 *
 * `app_settings` es una tabla de la INSTALACIÓN, no un fixture: estos tests
 * borran sus dos filas al terminar (y las reponen a su valor previo si había)
 * para no dejar la revisión de la instancia al revés.
 */
class ReviewPolicySwitchTest
{
    private const REQUIRED = 'review_required';
    private const NOTIFY   = 'review_notify_email';

    private ?int $adminId = null;
    private ?int $userId = null;
    private string $csrfToken = '';

    /** @var array<string, ?string> Valor previo de cada fila (null = no existía). */
    private array $previas = [];

    public function setUp(): void
    {
        try {
            Database::getInstance(Config::db())->fetch('SELECT 1 AS ok');
        } catch (\Throwable $e) {
            throw new \RuntimeException('SKIP: base de datos no disponible');
        }

        $this->previas = [
            self::REQUIRED => AppSettings::get(self::REQUIRED),
            self::NOTIFY   => AppSettings::get(self::NOTIFY),
        ];
        self::borrarFilas();

        $this->adminId = User::create('Review Admin', 'reviewswitch-admin-' . bin2hex(random_bytes(3)) . '@test.local', 'clave-larga-123', 1);
        $this->userId  = User::create('Review User', 'reviewswitch-user-' . bin2hex(random_bytes(3)) . '@test.local', 'clave-larga-123', 2);
        $this->csrfToken = Csrf::token();
    }

    public function tearDown(): void
    {
        Session::destroy();
        Response::setRecordHalt(false);
        AppSettings::forgetForTesting();
        Config::forgetForTesting();
        Auth::forgetCache();
        unset($_POST, $_GET, $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_X_CSRF_TOKEN']);

        self::borrarFilas();
        foreach ($this->previas as $key => $value) {
            if ($value !== null) {
                AppSettings::setBool($key, $value === '1');
            }
        }
        self::limpiarUsuarios();
        self::limpiarRoles();
    }

    private static function borrarFilas(): void
    {
        Database::getInstance(Config::db())->execute(
            'DELETE FROM app_settings WHERE key_name IN (:a, :b)',
            ['a' => self::REQUIRED, 'b' => self::NOTIFY]
        );
    }

    private static function limpiarUsuarios(): void
    {
        Database::getInstance(Config::db())->execute("DELETE FROM users WHERE email LIKE 'reviewswitch-%@test.local'");
    }

    private static function limpiarRoles(): void
    {
        Database::getInstance(Config::db())->execute("DELETE FROM roles WHERE name LIKE 'reviewswitch-%'");
    }

    /** @param array<string, mixed> $post */
    private function post(array $post, ?int $como): array
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $this->csrfToken;
        $_POST = $post + ['_token' => $this->csrfToken];

        if ($como !== null) {
            Auth::assumeLoggedIn($como);
        } else {
            Auth::forgetCache();
        }

        Response::setRecordHalt(true);
        ob_start();
        try {
            (new AdminController())->updateReviewPolicy();
        } finally {
            $body = (string) ob_get_clean();
        }
        $halt = Response::lastHalt() ?? [];
        Response::setRecordHalt(false);

        return ['halt' => $halt, 'body' => $body];
    }

    private static function fila(string $key): ?array
    {
        return Database::getInstance(Config::db())->fetch(
            'SELECT value FROM app_settings WHERE key_name = :k',
            ['k' => $key]
        );
    }

    /* ------------------------------------------------------------------
     * Orden de las dos fuentes
     * ------------------------------------------------------------------ */

    /**
     * Sin fila manda el `.env`. Es el estado de una instalación recién migrada,
     * así que va contra la BD de verdad y no con un override.
     */
    public function testSinFilaMandaElValorDelEnv(): void
    {
        Config::setForTesting('app.review_required', true);
        assertNull(AppSettings::get(self::REQUIRED), 'la fila no está: se decide fuera de la tabla');
        assertTrue(ReviewPolicy::policyOn());

        Config::setForTesting('app.review_required', false);
        assertFalse(ReviewPolicy::policyOn());
    }

    /**
     * La fila gana al `.env` en LOS DOS sentidos. Si solo ganara para
     * «encendido», el panel no podría cerrar una revisión que el `.env` tiene
     * abierta, y el interruptor sería un adorno.
     */
    public function testLaFilaGanaAlEnvEnLosDosSentidos(): void
    {
        Config::setForTesting('app.review_required', true);

        AppSettings::setBool(self::REQUIRED, false);
        assertFalse(ReviewPolicy::policyOn(), 'el panel puede apagar lo que el .env encendió');

        AppSettings::setBool(self::REQUIRED, true);
        Config::setForTesting('app.review_required', false);
        assertTrue(ReviewPolicy::policyOn(), 'y encenderlo aunque el .env lo tenga apagado');
    }

    /**
     * Lo que se guarda es exactamente `0`/`1`, y no «el valor del `.env`»
     * volcado dentro de la tabla. Si se guardara el default, la fila se
     * convertiría en una segunda fuente de verdad que el operador ya no controla
     * desde el fichero.
     */
    public function testLoQueSeGuardaEsElInterruptorYNoElDefault(): void
    {
        Config::setForTesting('app.review_required', true);

        $this->post(['review_required' => '0', 'review_notify_email' => '0'], (int) $this->adminId);

        $row = self::fila(self::REQUIRED);
        assertNotNull($row, 'la escritura tiene que existir');
        assertSame('0', (string) $row['value'], 'guarda 0, no el true que venía del .env');
    }

    /**
     * Un valor escrito a mano que no es booleano tiene que caer al lado SEGURO,
     * no lanzar. La tabla es `TEXT` y cualquiera puede escribir «cualquiera».
     */
    public function testUnValorRaroNoRompeYValeComoDesactivado(): void
    {
        Database::getInstance(Config::db())->execute(
            'INSERT INTO app_settings (key_name, value) VALUES (:k, :v)',
            ['k' => self::REQUIRED, 'v' => 'quiza']
        );

        assertSame(false, AppSettings::bool(self::REQUIRED, true), 'lo que no se entiende es «desactivado»');
        assertFalse(ReviewPolicy::policyOn());
    }

    /* ------------------------------------------------------------------
     * La puerta
     * ------------------------------------------------------------------ */

    public function testUnAdminEnciendeLaRevisionYLasFilasQuedanEscritas(): void
    {
        Config::setForTesting('app.review_required', false);

        $r = $this->post(['review_required' => '1', 'review_notify_email' => '1'], (int) $this->adminId);

        assertSame('redirect', (string) ($r['halt']['type'] ?? ''), 'es un POST nativo: redirige');
        assertSame('/admin', (string) ($r['halt']['url'] ?? ''), 'y vuelve al panel (PRG)');
        assertTrue(ReviewPolicy::policyOn(), 'la fila manda sobre el .env apagado');
        assertTrue(ReviewPolicy::notifyEmail());
        assertSame('1', (string) (self::fila(self::NOTIFY)['value'] ?? ''), 'y el aviso por correo también se guardó');
    }

    /** Una casilla desmarcada NO viaja en el POST: ese es el caso de apagar. */
    public function testSinCamposSeApagaLaRevision(): void
    {
        AppSettings::setBool(self::REQUIRED, true);
        AppSettings::setBool(self::NOTIFY, true);

        $r = $this->post([], (int) $this->adminId);

        assertSame('redirect', (string) ($r['halt']['type'] ?? ''));
        assertFalse(ReviewPolicy::policyOn());
        assertFalse(ReviewPolicy::notifyEmail());
    }

    /**
     * `admin.access` y no `admin.users`: quien entra al panel administra la
     * instalación. Un grupo con `admin.access` sin `admin.users` puede entrar,
     * así que el permiso concreto lo comprueba la propia acción y no solo el
     * middleware de la ruta.
     */
    public function testSinAdminAccessNoSeGuardaNada(): void
    {
        $r = $this->post(['review_required' => '1'], (int) $this->userId);

        assertSame(403, (int) ($r['halt']['code'] ?? 0));
        assertNull(self::fila(self::REQUIRED), 'y no se escribe la fila');
    }

    public function testSinSesionNiCsrfNoSeGuardaNada(): void
    {
        $r = $this->post(['review_required' => '1'], null);
        assertSame(403, (int) ($r['halt']['code'] ?? 0), 'sin sesión, 403 antes de mirar el token');
        assertNull(self::fila(self::REQUIRED));

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['review_required' => '1', '_token' => 'no-es-el-token'];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'no-es-el-token';
        Auth::assumeLoggedIn((int) $this->adminId);
        Response::setRecordHalt(true);
        ob_start();
        try {
            (new AdminController())->updateReviewPolicy();
        } finally {
            ob_get_clean();
        }
        $halt = Response::lastHalt() ?? [];
        Response::setRecordHalt(false);

        assertSame('redirect', (string) ($halt['type'] ?? ''), 'con sesión pero sin token, se vuelve al panel');
        assertNull(self::fila(self::REQUIRED));
    }

    /**
     * Activar la revisión cambia quién puede publicar en toda la instalación: es
     * de las pocas escrituras de configuración con efecto sobre el trabajo de
     * terceros, y por eso va al log con el `user_id` de la sesión.
     */
    public function testElCambioQuedaEnElLogDeSeguridad(): void
    {
        $db = Database::getInstance(Config::db());

        $this->post(['review_required' => '1'], (int) $this->adminId);

        $filas = $db->fetchAll(
            "SELECT event, user_id FROM security_log WHERE event LIKE 'instance.review_%' ORDER BY id DESC LIMIT 1"
        );
        assertSame(1, count($filas), 'el evento se registra: ' . json_encode($filas));
        assertSame('instance.review_on', (string) $filas[0]['event']);
        assertSame((int) $this->adminId, (int) $filas[0]['user_id'], 'y con quién lo hizo');

        assertTrue(
            in_array('instance.review_on', \App\Core\SecurityLog::knownEvents(), true)
                && in_array('instance.review_off', \App\Core\SecurityLog::knownEvents(), true),
            'los dos eventos tienen que estar en el catálogo del filtro'
        );

        $this->post(['review_required' => '0'], (int) $this->adminId);
        $despues = $db->fetchAll(
            "SELECT event FROM security_log WHERE event = 'instance.review_off' AND user_id = :u
             ORDER BY id DESC LIMIT 1",
            ['u' => (int) $this->adminId]
        );
        assertSame(1, count($despues), 'apagar también: no es una escritura menor');
    }

    /* ------------------------------------------------------------------
     * Lo que el interruptor gobierna de verdad
     * ------------------------------------------------------------------ */

    /**
     * El interruptor no solo cambia un texto: la política gobierna el gate del
     * formulario. Con la fila a `1` y el dueño sin el permiso de exención,
     * `requiredFor()` lo sujeta a revisión; con la fila a `0`, no.
     */
    public function testLaFilaGobiernaLaRevisionDeUnFormulario(): void
    {
        $ownerId = User::create('Review Owner', 'reviewswitch-owner-' . bin2hex(random_bytes(3)) . '@test.local', 'clave-larga-123', 2);
        $formId  = Form::create((int) $ownerId, 'Formulario del interruptor');
        $form    = Form::find((int) $formId, false);

        AppSettings::setBool(self::REQUIRED, false);
        assertFalse(ReviewPolicy::requiredFor($form), 'apagada, el dueño publica directo');

        AppSettings::setBool(self::REQUIRED, true);
        assertTrue(ReviewPolicy::requiredFor($form), 'encendida, queda sujeto a revisión');

        // Y con el permiso de exención en su rol, vuelve a publicar directo. Se
        // le da un rol PROPIO y temporal: tocar el rol `user` del sistema para
        // esto cambiaría los permisos de media suite.
        $rolExento = Role::create('reviewswitch-exento-' . bin2hex(random_bytes(3)));
        assertNotNull($rolExento, 'el rol temporal tiene que crearse');
        Role::setPermissions((int) $rolExento, ['dashboard.view', 'forms.edit_own', 'forms.publish_without_review']);
        Database::getInstance(Config::db())->execute(
            'UPDATE users SET role_id = :r WHERE id = :u',
            ['r' => (int) $rolExento, 'u' => (int) $ownerId]
        );
        $form = Form::find((int) $formId, false);

        assertFalse(ReviewPolicy::requiredFor($form), 'exento por permiso, no pasa por revisión');
    }

    /* ------------------------------------------------------------------
     * La vista
     * ------------------------------------------------------------------ */

    /**
     * La tarjeta de la revisión vive en `/admin`, va detrás de `admin.access` y
     * manda al endpoint nativo (nada de URL escrita dentro del JS).
     */
    public function testLaVistaPintaLaTarjetaDeRevision(): void
    {
        $vista = (string) file_get_contents(dirname(__DIR__, 2) . '/views/admin/users.php');

        assertTrue(str_contains($vista, 'action="/admin/settings/review"'), 'la tarjeta apunta al endpoint nativo');
        assertTrue(str_contains($vista, 'name="review_required"'), 'con su interruptor');
        assertTrue(str_contains($vista, 'name="review_notify_email"'), 'y el del aviso por correo');
        assertTrue(
            str_contains($vista, "(\$reviewRequired ?? false) ? ' checked' : ''"),
            'el `checked` sale del valor que resolvió el controlador, no de un default del HTML'
        );
        assertTrue(
            str_contains($vista, '<input type="hidden" name="review_required" value="0">'),
            'los hidden delante: una casilla desmarcada no llega y hay que poder apagar el ajuste'
        );
    }
}
