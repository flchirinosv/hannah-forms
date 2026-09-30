<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Env;
use App\Core\SecurityLog;
use App\Core\Session;
use App\Models\User;

/**
 * Registro de eventos de seguridad (2026-09-28) — SecurityLog.
 *
 * Contratos que se pinzan aquí:
 *
 *  - record() NUNCA rompe el flujo y valida el formato del evento;
 *  - si la sesión apunta a un usuario inexistente (tests, borrado a mitad de
 *    request), la fila se guarda con user_id NULL — antes reventaba por la FK
 *    fk_sl_user y el evento se perdía;
 *  - con usuario real la fila lleva su id;
 *  - recent() filtra solo por eventos conocidos y pagina;
 *  - purgeOlderThan() borra por antigüedad (mantenimiento/cron).
 */
class SecurityLogTest
{
    private static bool $envLoaded = false;
    private static ?int $userId = null;
    private const MARKERS = ['marca-slog-a', 'marca-slog-b', 'marca-slog-c', 'marca-slog-d'];

    public function setUp(): void
    {
        self::loadEnv();
        self::cleanupResiduals();
        self::$userId = User::create('Log User', 'slog-' . bin2hex(random_bytes(4)) . '@test.local', 'password', 2);
    }

    public function tearDown(): void
    {
        self::cleanupResiduals();
        Session::remove('user_id');
        Auth::forgetCache();
    }

    public function testEventoConFormatoInvalidoNoSeGuarda(): void
    {
        SecurityLog::record('No Válido!', 'marca-slog-c');
        SecurityLog::record('a', 'marca-slog-c'); // demasiado corto
        $count = $this->countRowsWithDetail('marca-slog-c');
        assertSame(0, $count, 'un evento con formato inválido no debe insertar fila');
    }

    public function testRecordConUsuarioInexistenteGuardaConUserNull(): void
    {
        Auth::assumeLoggedIn(999999999); // no existe
        Auth::forgetCache();

        SecurityLog::record('form.delete', 'marca-slog-a');
        $row = $this->rowWithDetail('marca-slog-a');
        assertTrue($row !== null, 'el evento se guarda aunque la sesión apunte a un usuario fantasma');
        assertSame(null, $row['user_id'], 'un usuario inexistente se guarda como user_id NULL (FK a salvo)');
    }

    public function testRecordConUsuarioRealGuardaElId(): void
    {
        Auth::assumeLoggedIn(self::$userId);
        Auth::forgetCache();

        SecurityLog::record('form.delete', 'marca-slog-b');
        $row = $this->rowWithDetail('marca-slog-b');
        assertTrue($row !== null, 'el evento se guarda');
        assertSame((int) self::$userId, (int) $row['user_id'], 'un usuario real deja su id en la fila');
    }

    public function testRecentFiltraPorEventoConocidoYPagina(): void
    {
        SecurityLog::record('auth.login_ok', 'marca-slog-c');

        $filtered = SecurityLog::recent('auth.login_ok', 1, 10);
        assertTrue($filtered['total'] >= 1, 'al menos la fila recién escrita');
        $details = array_column($filtered['items'], 'detail');
        assertTrue(in_array('marca-slog-c', $details, true), 'el filtro incluye el evento guardado');

        $unknown = SecurityLog::recent('zip.no_existe', 1, 10);
        assertSame($unknown['total'], SecurityLog::recent('', 1, 10)['total'], 'un evento desconocido NO filtra (cae al total global)');
    }

    public function testPurgeOlderThanBorraSoloLoAnterior(): void
    {
        $db = Database::getInstance(Config::db());
        $db->execute(
            "INSERT INTO security_log (event, detail, created_at) VALUES ('form.delete', :d, UTC_TIMESTAMP() - INTERVAL 40 DAY)",
            ['d' => 'marca-slog-d']
        );
        SecurityLog::record('form.delete', 'marca-slog-c');

        $removed = SecurityLog::purgeOlderThan(date('Y-m-d H:i:s', time() - 30 * 86400));

        assertSame(null, $this->rowWithDetail('marca-slog-d'), 'la fila de hace 40 días se purgó');
        assertTrue($this->rowWithDetail('marca-slog-c') !== null, 'la fila reciente sobrevive a la purga');
        assertTrue($removed >= 1, 'purgeOlderThan devuelve las filas borradas (null vs 0 importa)');
    }

    /* ---------- Helpers ---------- */

    private function rowWithDetail(string $detail): ?array
    {
        return Database::getInstance(Config::db())->fetch(
            'SELECT * FROM security_log WHERE detail = :d ORDER BY id DESC LIMIT 1',
            ['d' => $detail]
        );
    }

    private function countRowsWithDetail(string $detail): int
    {
        $row = Database::getInstance(Config::db())->fetch(
            'SELECT COUNT(*) AS c FROM security_log WHERE detail = :d',
            ['d' => $detail]
        );
        return (int) ($row['c'] ?? 0);
    }

    private static function loadEnv(): void
    {
        if (!self::$envLoaded) {
            Env::load(dirname(__DIR__, 2) . '/.env');
            self::$envLoaded = true;
            register_shutdown_function(static function () {
                try {
                    self::cleanupResiduals();
                } catch (\Throwable $e) {
                }
            });
        }
    }

    private static function cleanupResiduals(): void
    {
        $db = Database::getInstance(Config::db());
        foreach (self::MARKERS as $m) {
            $db->execute('DELETE FROM security_log WHERE detail = :d', ['d' => $m]);
        }
        $db->execute("DELETE FROM users WHERE email LIKE '%@test.local'");
        self::$userId = null;
    }
}