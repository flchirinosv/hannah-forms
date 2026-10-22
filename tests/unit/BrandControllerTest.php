<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\AdminController;
use App\Core\AppSettings;
use App\Core\Auth;
use App\Core\Brand;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Response;
use App\Core\SecurityLog;
use App\Core\Session;
use App\Models\User;

/**
 * La puerta del panel para la marca de la instancia (AG2): nombre, color, logo
 * y favicon desde `/admin/settings`.
 *
 * Es un POST nativo con PRG, como la revisión, y su tabla es de la INSTALACIÓN:
 * estos tests guardan el valor previo de las cuatro filas, las borran y las
 * reponen al terminar, o dejarían la marca de la instalación cambiada para los
 * tests que vengan detrás (y para la instancia real, que comparte base).
 */
class BrandControllerTest
{
    private const KEYS = [
        Brand::NAME,
        Brand::COLOR,
        Brand::COLOR_GRAD_START,
        Brand::COLOR_GRAD_END,
        Brand::COLOR_BG,
        Brand::COLOR_TEXT,
        Brand::LOGO,
        Brand::FAVICON,
    ];

    private ?int $adminId = null;
    private ?int $userId = null;
    private string $csrfToken = '';

    /** @var array<string, ?string> */
    private array $previas = [];

    /** @var list<string> archivos de marca creados por el test */
    private array $archivos = [];

    public function setUp(): void
    {
        try {
            Database::getInstance(Config::db())->fetch('SELECT 1 AS ok');
        } catch (\Throwable $e) {
            throw new \RuntimeException('SKIP: base de datos no disponible');
        }

        foreach (self::KEYS as $key) {
            $this->previas[$key] = AppSettings::get($key);
        }
        self::borrarFilas();

        $this->adminId = User::create('Brand Admin', 'brandctl-admin-' . bin2hex(random_bytes(3)) . '@test.local', 'clave-larga-123', 1);
        $this->userId  = User::create('Brand User', 'brandctl-user-' . bin2hex(random_bytes(3)) . '@test.local', 'clave-larga-123', 2);
        $this->csrfToken = Csrf::token();
    }

    public function tearDown(): void
    {
        Session::destroy();
        Response::setRecordHalt(false);
        AppSettings::forgetForTesting();
        Config::forgetForTesting();
        Auth::forgetCache();
        unset($_POST, $_GET, $_FILES, $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_X_CSRF_TOKEN']);

        self::borrarFilas();
        foreach ($this->previas as $key => $value) {
            if ($value !== null) {
                AppSettings::set($key, $value);
            }
        }
        foreach ($this->archivos as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        self::limpiarUsuarios();
    }

    private static function borrarFilas(): void
    {
        $ph = [];
        $args = [];
        foreach (self::KEYS as $i => $key) {
            $ph[] = ':k' . $i;
            $args['k' . $i] = $key;
        }
        Database::getInstance(Config::db())->execute(
            'DELETE FROM app_settings WHERE key_name IN (' . implode(', ', $ph) . ')',
            $args
        );
    }

    private static function limpiarUsuarios(): void
    {
        Database::getInstance(Config::db())->execute("DELETE FROM users WHERE email LIKE 'brandctl-%@test.local'");
    }

    private static function fila(string $key): ?array
    {
        return Database::getInstance(Config::db())->fetch(
            'SELECT value FROM app_settings WHERE key_name = :k',
            ['k' => $key]
        );
    }

    private function conHalt(callable $accion): array
    {
        Response::setRecordHalt(true);
        try {
            $accion();
        } finally {
            $halt = Response::lastHalt() ?? [];
            Response::setRecordHalt(false);
        }
        return $halt;
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

        return $this->conHalt(function (): void {
            (new AdminController())->updateBrand();
        });
    }

    /* ------------------------------------------------------------------ */

    public function testUnAdminGuardaNombreYColorYVuelveAAjustes(): void
    {
        $halt = $this->post(['instance_name' => 'Encuestas Sur', 'instance_color' => '#AABBCC'], (int) $this->adminId);

        assertSame('redirect', (string) ($halt['type'] ?? ''), 'POST nativo: PRG');
        assertSame('/admin/settings', (string) ($halt['url'] ?? ''));
        assertSame('Encuestas Sur', Brand::name(), 'el nombre se guarda');
        assertSame('#aabbcc', Brand::color(), 'y el color se normaliza');
    }

    public function testUnAdminGuardaDegradadoFondoYTexto(): void
    {
        $this->post([
            'instance_name'             => 'Marca',
            'instance_color'            => '#112233',
            'instance_color_grad_start' => '#AABBCC',
            'instance_color_grad_end'   => '#ddeeff',
            'instance_color_bg'         => '#ff0000',
            'instance_color_text'       => '#000000',
        ], (int) $this->adminId);

        assertSame('#aabbcc', Brand::gradientStart(), 'el inicio del degradado se normaliza');
        assertSame('#ddeeff', Brand::gradientEnd(), 'y el fin');
        assertSame('#ff0000', Brand::background(), 'el fondo de acceso');
        assertSame('#000000', Brand::text(), 'y el texto sobre el degradado');
    }

    public function testLosColoresVaciosBorranSuFilaYCaenAlAutomatico(): void
    {
        AppSettings::set(Brand::COLOR_GRAD_START, '#abcdef');
        AppSettings::set(Brand::COLOR_TEXT, '#123456');

        $this->post([
            'instance_name'             => '',
            'instance_color'            => '#112233',
            'instance_color_grad_start' => '',
            'instance_color_grad_end'   => '',
            'instance_color_bg'         => '',
            'instance_color_text'       => '',
        ], (int) $this->adminId);

        assertNull(self::fila(Brand::COLOR_GRAD_START), 'un degradado vacío borra su fila');
        assertSame('#112233', Brand::gradientStart(), 'y cae al primario');
        assertNull(self::fila(Brand::COLOR_TEXT), 'el texto vacío borra su fila');
        assertNull(Brand::text(), 'y vuelve al automático (null)');
    }

    public function testUnColorDeDegradadoInvalidoNoSeGuarda(): void
    {
        $halt = $this->post([
            'instance_name'             => 'X',
            'instance_color'            => '#112233',
            'instance_color_grad_start' => 'azul',
        ], (int) $this->adminId);

        assertSame('redirect', (string) ($halt['type'] ?? ''), 'vuelve a ajustes con el aviso');
        // El POST entero se rechaza: el color malo no se guarda, y como el guard
        // va ANTES de escribir, el primario de la misma tanda tampoco.
        assertNull(self::fila(Brand::COLOR_GRAD_START), 'un valor no-hex no llega a la fila');
        assertNull(self::fila(Brand::COLOR), 'ni se escribe el primario de la misma tanda');
        assertSame(Brand::DEFAULT_COLOR, Brand::gradientStart(), 'y el degradado cae al default');
    }

    public function testUnColorInvalidoNoSeGuarda(): void
    {
        $halt = $this->post(['instance_name' => 'X', 'instance_color' => 'azul'], (int) $this->adminId);

        assertSame('redirect', (string) ($halt['type'] ?? ''), 'vuelve a ajustes con el aviso');
        assertNull(self::fila(Brand::COLOR), 'el color inválido no llega a la fila');
        assertSame(Brand::DEFAULT_COLOR, Brand::color(), 'y el color queda en el default');
    }

    public function testUnNombreEnBlancoBorraLaFilaYVuelveAlEnv(): void
    {
        AppSettings::set(Brand::NAME, 'Anterior');
        Config::setForTesting('app.name', 'Del .env');

        $this->post(['instance_name' => '   ', 'instance_color' => ''], (int) $this->adminId);

        assertNull(self::fila(Brand::NAME), 'un nombre en blanco borra la fila');
        assertSame('Del .env', Brand::name(), 'y el nombre cae al .env');
    }

    public function testSinAdminAccessNoSeGuardaNada(): void
    {
        $halt = $this->post(['instance_name' => 'X', 'instance_color' => '#112233'], (int) $this->userId);

        assertSame(403, (int) ($halt['code'] ?? 0));
        assertNull(self::fila(Brand::NAME), 'ni se escribe el nombre');
        assertNull(self::fila(Brand::COLOR), 'ni el color');
    }

    public function testSinSesionNiCsrfNoSeGuardaNada(): void
    {
        $halt = $this->post(['instance_name' => 'X'], null);

        // Sin sesión, `admin` corta con 403 (o redirige al login); lo que importa
        // es que la fila no se escribe.
        assertNull(self::fila(Brand::NAME), 'sin sesión no se guarda');
    }

    public function testElCambioQuedaEnElLogDeSeguridad(): void
    {
        $this->post(['instance_name' => 'Auditada', 'instance_color' => '#445566'], (int) $this->adminId);

        $row = Database::getInstance(Config::db())->fetch(
            "SELECT event FROM security_log WHERE event = 'instance.brand_update' ORDER BY id DESC LIMIT 1"
        );
        assertNotNull($row, 'la marca tiene que dejar traza');
        assertTrue(in_array('instance.brand_update', SecurityLog::knownEvents(), true), 'y el evento es conocido');
    }

    public function testSubirSinArchivoNoRompeNiEscribe(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $this->csrfToken;
        $_POST = ['_token' => $this->csrfToken];
        $_FILES = [];
        Auth::assumeLoggedIn((int) $this->adminId);

        $halt = $this->conHalt(function (): void {
            (new AdminController())->uploadBrandImage('logo');
        });

        assertSame('redirect', (string) ($halt['type'] ?? ''), 'vuelve a ajustes con el error');
        assertNull(self::fila(Brand::LOGO), 'y no hay logo');
    }

    public function testUnKindDesconocidoNoSubeNiBorra(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $this->csrfToken;
        $_POST = ['_token' => $this->csrfToken];
        $_FILES = [];
        Auth::assumeLoggedIn((int) $this->adminId);

        $halt = $this->conHalt(function (): void {
            (new AdminController())->uploadBrandImage('avatar');
        });
        assertSame(404, (int) ($halt['code'] ?? 0), 'un kind que no es logo/favicon es 404');

        $halt = $this->conHalt(function (): void {
            (new AdminController())->removeBrandImage('avatar');
        });
        assertSame(404, (int) ($halt['code'] ?? 0), 'y el borrado también');
    }

    public function testQuitarElLogoLoDejaSinUrl(): void
    {
        // Simula un logo ya guardado: metadatos + archivo real. `remove()` borra
        // los dos y no deja `<img>` apuntando a un archivo inexistente.
        $dir = (string) Config::get('paths.storage', dirname(__DIR__, 2) . '/storage') . '/' . Brand::DIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $name = 'brandctl-' . bin2hex(random_bytes(4)) . '.png';
        $path = $dir . '/' . $name;
        file_put_contents($path, "\x89PNG\r\n\x1a\n");
        $this->archivos[] = $path;
        AppSettings::set(Brand::LOGO, json_encode(['stored' => $name, 'mime' => 'image/png', 'size' => 8]));
        assertNotNull(Brand::logoUrl(), 'el logo está antes de quitarlo');

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $this->csrfToken;
        $_POST = ['_token' => $this->csrfToken];
        Auth::assumeLoggedIn((int) $this->adminId);

        $halt = $this->conHalt(function (): void {
            (new AdminController())->removeBrandImage('logo');
        });

        assertSame('redirect', (string) ($halt['type'] ?? ''), 'PRG al quitar');
        assertSame(false, Brand::hasLogo(), 'la fila se va');
        assertSame(null, Brand::logoUrl(), 'y no queda URL');
        assertFalse(is_file($path), 'el archivo también se borra');
    }
}
