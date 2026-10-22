<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Role;
use App\Models\User;
use PDO;
use PDOException;

/**
 * Instalador web (AG11): crea la base, aplica el esquema, siembra los roles y
 * crea el primer administrador, para poder arrancar una instancia nueva sin
 * SSH (hoy exige `mysql < schema.sql`, `mysql < seed.sql`, copiar el `.env`,
 * generar la `APP_KEY` y `php scripts/create-admin.php`).
 *
 * Por qué NO vive dentro del arranque normal de la app: `config/bootstrap.php`
 * conecta la BD y **mata el proceso** si falla. En una instalación nueva la
 * base no existe, así que ninguna ruta de `config/routes.php` llegaría a
 * ejecutarse. Por eso el instalador es un entrypoint propio (`public/install.php`)
 * que no carga el bootstrap, y esta clase es su motor: todo lo que toca la BD
 * lo hace con su **propia** conexión PDO.
 *
 * Reglas de seguridad:
 * - El nombre de la base se valida con una regex estricta antes de concatenarlo
 *   (no admite marcadores en un `CREATE DATABASE`).
 * - `schema.sql`/`seed.sql` llevan `USE vanilla_forms;` embebido; se reemplaza
 *   por la base elegida antes de ejecutarlos.
 * - `PDO::exec()` sí acepta multi-sentencia; `query()` (con `prepare`) no.
 */
final class Installer
{
    /** @var array{host:string,port:string,name:string,user:string,password:string,charset:string} */
    private array $db;

    public function __construct(array $db)
    {
        $this->db = [
            'host'     => (string) ($db['host'] ?? ''),
            'port'     => (string) ($db['port'] ?? '3306'),
            'name'     => (string) ($db['name'] ?? ''),
            'user'     => (string) ($db['user'] ?? ''),
            'password' => (string) ($db['password'] ?? ''),
            'charset'  => (string) ($db['charset'] ?? 'utf8mb4'),
        ];
    }

    /**
     * ¿El nombre de base es válido para concatenar en `CREATE DATABASE`?
     */
    public static function nombreValido(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_]+$/', $name);
    }

    /**
     * Conecta SIN `dbname` (para poder crear la base si no existe) y la crea.
     * Si la base ya existe, no falla.
     *
     * @throws \RuntimeException con el mensaje REAL de MySQL (no el genérico de
     *                           `Database`, que oculta el detalle en el log).
     */
    public function crearBase(): void
    {
        if (!self::nombreValido($this->db['name'])) {
            throw new \RuntimeException('El nombre de la base solo admite letras, números y guion bajo.');
        }

        $pdo = $this->conectarSinBase();

        $nombre = '`' . $this->db['name'] . '`';
        $pdo->exec(
            'CREATE DATABASE IF NOT EXISTS ' . $nombre
            . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
    }

    /**
     * Comprueba que se puede conectar con la base ya creada.
     */
    public function probarConexionConBase(): void
    {
        $this->conectarConBase();
    }

    /**
     * Aplica `schema.sql` y `seed.sql` (reemplazando su `USE vanilla_forms;`) y
     * las migraciones pendientes.
     *
     * **No es idempotente a propósito**: `schema.sql` usa `CREATE INDEX` plano
     * (y `CREATE TABLE IF NOT EXISTS`), así que está pensado para una base
     * VACÍA. Volver a correrlo sobre una instalada revienta con 1061
     * «Duplicate key name» —el mismo aviso que AGENTS.md da para no re-ejecutar
     * `schema.sql` sobre una base con datos—. Las migraciones SÍ son
     * idempotentes (se saltan las ya aplicadas).
     */
    public function aplicarEsquema(string $raiz): void
    {
        $pdo = $this->conectarConBase();

        $schema = $this->sqlSinUseDesde($raiz . '/database/schema.sql');
        $pdo->exec($schema);

        $seed = $this->sqlSinUseDesde($raiz . '/database/seed.sql');
        $pdo->exec($seed);

        $this->aplicarMigraciones($pdo, $raiz . '/database/migrations');
    }

    /**
     * Crea el primer administrador. Valida con `AuthController::validateNewUser`
     * (la fuente compartida entre registro público, alta por panel e instalador)
     * y resuelve el rol `admin` **por nombre**, nunca por id fijo.
     *
     * @return int id del usuario creado.
     * @throws \RuntimeException si la validación falla o el email ya existe.
     */
    public function crearAdmin(string $nombre, string $email, string $password, string $passwordConfirm): int
    {
        $invalid = \App\Controllers\AuthController::validateNewUser($nombre, $email, $password, $passwordConfirm);
        if ($invalid !== null) {
            throw new \RuntimeException($invalid);
        }

        // Tras `aplicarEsquema()`, el singleton `Database` puede apuntar a la
        // base recién creada: los modelos lo necesitan.
        Database::getInstance($this->config());

        if (User::findByEmail($email) !== null) {
            throw new \RuntimeException('Ya hay una cuenta con ese email.');
        }

        $rolAdmin = Role::findByName('admin');
        if ($rolAdmin === null) {
            throw new \RuntimeException('No se encontró el rol `admin` tras aplicar el esquema.');
        }

        $id = User::create($nombre, $email, $password, (int) $rolAdmin['id']);
        if ($id === null) {
            throw new \RuntimeException('No se pudo crear el administrador.');
        }

        return $id;
    }

    /**
     * @return array{host:string,port:string,name:string,user:string,password:string,charset:string}
     */
    public function config(): array
    {
        return $this->db;
    }

    private function conectarSinBase(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;charset=%s',
            $this->db['host'],
            $this->db['port'],
            $this->db['charset']
        );

        return $this->pdo($dsn);
    }

    private function conectarConBase(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $this->db['host'],
            $this->db['port'],
            $this->db['name'],
            $this->db['charset']
        );

        return $this->pdo($dsn);
    }

    private function pdo(string $dsn): PDO
    {
        try {
            return new PDO($dsn, $this->db['user'], $this->db['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            // El mensaje REAL (p. ej. «Access denied for user…») es lo que el
            // instalador tiene que mostrar; el de `Database` lo esconde.
            throw new \RuntimeException('No se pudo conectar a la base de datos: ' . $e->getMessage());
        }
    }

    /**
     * Reemplaza el `USE vanilla_forms;` de un `.sql` por la base elegida y
     * neutraliza un `CREATE DATABASE ... vanilla_forms` para que no cree una
     * base con el nombre por defecto. Es el punto delicado del instalador (el
     * esquema trae su propio `USE`, ver AGENTS.md), así que es puro y estático
     * para poder probarlo sin base de datos.
     */
    public static function sqlSinUse(string $sql, string $nombre): string
    {
        $sql = (string) preg_replace('/^\s*USE\s+`?vanilla_forms`?\s*;/mi', '', $sql);

        return (string) preg_replace(
            '/CREATE\s+DATABASE\s+IF\s+NOT\s+EXISTS\s+`?vanilla_forms`?/mi',
            'CREATE DATABASE IF NOT EXISTS `' . $nombre . '`',
            $sql
        );
    }

    /**
     * Lee un `.sql` y le aplica `sqlSinUse()`.
     */
    private function sqlSinUseDesde(string $path): string
    {
        if (!is_file($path)) {
            throw new \RuntimeException('No se encontró el archivo SQL: ' . basename($path));
        }

        return self::sqlSinUse((string) file_get_contents($path), $this->db['name']);
    }

    /**
     * Aplica las migraciones pendientes. Reutiliza la lógica de
     * `database/migrate.php` (closure idempotente, sin transacción por el DDL
     * implícito, registro en `migrations` solo tras éxito).
     */
    private function aplicarMigraciones(PDO $pdo, string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name        VARCHAR(190) NOT NULL UNIQUE,
                applied_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB'
        );

        $files = glob($dir . '/*.php') ?: [];
        sort($files);

        $aplicadas = [];
        foreach ($pdo->query('SELECT name FROM migrations')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $aplicadas[(string) $row['name']] = true;
        }

        // El singleton `Database` ya apunta a la base (lo fijó `crearAdmin`), pero
        // aquí puede no estarlo todavía si se llama `aplicarEsquema` solo; las
        // migraciones reciben un `Database`, así que garantizamos el singleton.
        $db = Database::getInstance($this->config());

        foreach ($files as $file) {
            $name = basename($file);
            if (isset($aplicadas[$name])) {
                continue;
            }

            $migration = require $file;
            if (!is_callable($migration)) {
                throw new \RuntimeException("La migración $name no devuelve un closure ejecutable.");
            }

            try {
                $migration($db);
                $db->execute('INSERT INTO migrations (name) VALUES (:name)', ['name' => $name]);
            } catch (\Throwable $e) {
                throw new \RuntimeException("Falló la migración $name: " . $e->getMessage());
            }
        }
    }
}
