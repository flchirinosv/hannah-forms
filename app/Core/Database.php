<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

class Database
{
    private static ?Database $instance = null;
    private PDO $pdo;

    private function __construct(array $config)
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['name'],
            $config['charset']
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $this->pdo = new PDO($dsn, $config['user'], $config['password'], $options);
        } catch (PDOException $e) {
            error_log('Error de conexión a BD: ' . $e->getMessage());
            throw new PDOException('No se pudo conectar a la base de datos.');
        }

        // La sesión escribe y lee en UTC, SIEMPRE, sin depender de dónde esté
        // el host. `@@session.time_zone` viene en SYSTEM por defecto, o sea que
        // heredaba la zona del servidor: en esta máquina daba igual porque el
        // contenedor está en UTC, pero en un host en America/Caracas la sesión
        // habría pasado a -04:00 y las columnas TIMESTAMP se habrían leído
        // como hora local, con PHP creyendo que ese string era UTC: 4 h de
        // error en cada fecha, invisible salvo en comparaciones y en "hace X".
        // Fijarlo aquí convierte en explícito el contrato "la BD habla UTC" en
        // vez de dejarlo como casualidad del despliegue.
        //
        // PDO no tiene parámetro de DSN para la zona, y `init_command` es de
        // mysqlnd, no de PDO: la vía portable es un SET justo tras conectar.
        $this->pdo->exec("SET time_zone = '+00:00'");
    }

    public static function getInstance(array $config): self
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    public function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        $this->pdo->rollBack();
    }
}
