<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Core\Database;

/**
 * Tests de integración de Database. Usa la base de datos real y crea
 * una tabla temporal de prueba que limpia al finalizar.
 */
class DatabaseTest
{
    private static bool $envLoaded = false;

    public function setUp(): void
    {
        self::loadEnv();
    }

    public function testConexionExitosa(): void
    {
        $db = Database::getInstance(Config::db());
        $row = $db->fetch('SELECT 1 AS ok');
        assertSame('1', (string) ($row['ok'] ?? ''));
    }

    public function testFetchPreparedStatement(): void
    {
        $db = Database::getInstance(Config::db());
        $row = $db->fetch('SELECT ? AS val', [42]);
        assertSame('42', (string) ($row['val'] ?? ''));
    }

    public function testFetchAllDevuelveArray(): void
    {
        $db = Database::getInstance(Config::db());
        $rows = $db->fetchAll('SELECT 1 AS a UNION ALL SELECT 2 AS a');
        assertTrue(count($rows) === 2, 'se esperaban 2 filas');
        assertSame('1', (string) ($rows[0]['a'] ?? ''));
        assertSame('2', (string) ($rows[1]['a'] ?? ''));
    }

    public function testExecuteDevuelveFilasAfectadas(): void
    {
        $db = Database::getInstance(Config::db());
        $db->execute('CREATE TABLE IF NOT EXISTS _db_test (id INT PRIMARY KEY, name VARCHAR(50))');
        $affected = $db->execute('INSERT INTO _db_test (id, name) VALUES (:id, :name)', ['id' => 1, 'name' => 'x']);
        assertSame(1, $affected);

        $row = $db->fetch('SELECT name FROM _db_test WHERE id = :id', ['id' => 1]);
        assertSame('x', $row['name']);

        $db->execute('DROP TABLE IF EXISTS _db_test');
    }

    public function testLastInsertId(): void
    {
        $db = Database::getInstance(Config::db());
        $db->execute('CREATE TABLE IF NOT EXISTS _db_test2 (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50))');
        $db->execute('INSERT INTO _db_test2 (name) VALUES (:name)', ['name' => 'y']);
        $id = $db->lastInsertId();
        assertTrue((int) $id > 0);
        $db->execute('DROP TABLE IF EXISTS _db_test2');
    }

    public function testTransaccionCommit(): void
    {
        $db = Database::getInstance(Config::db());
        $db->execute('CREATE TABLE IF NOT EXISTS _db_test3 (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50))');

        $db->beginTransaction();
        $db->execute('INSERT INTO _db_test3 (name) VALUES (:name)', ['name' => 'tx1']);
        $db->execute('INSERT INTO _db_test3 (name) VALUES (:name)', ['name' => 'tx2']);
        $db->commit();

        $count = $db->fetch('SELECT COUNT(*) AS c FROM _db_test3');
        assertSame('2', (string) ($count['c'] ?? ''));

        $db->execute('DROP TABLE IF EXISTS _db_test3');
    }

    public function testTransaccionRollback(): void
    {
        $db = Database::getInstance(Config::db());
        $db->execute('CREATE TABLE IF NOT EXISTS _db_test4 (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50))');

        $db->beginTransaction();
        $db->execute('INSERT INTO _db_test4 (name) VALUES (:name)', ['name' => 'rb1']);
        $db->rollBack();

        $count = $db->fetch('SELECT COUNT(*) AS c FROM _db_test4');
        assertSame('0', (string) ($count['c'] ?? ''));

        $db->execute('DROP TABLE IF EXISTS _db_test4');
    }

    private static function loadEnv(): void
    {
        if (!self::$envLoaded) {
            \App\Core\Env::load(dirname(__DIR__, 2) . '/.env');
            self::$envLoaded = true;
        }
    }
}
