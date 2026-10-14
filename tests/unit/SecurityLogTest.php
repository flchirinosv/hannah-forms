<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
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
    private static bool $cleanupHookRegistered = false;
    private static ?int $userId = null;
    private const MARKERS = ['marca-slog-a', 'marca-slog-b', 'marca-slog-c', 'marca-slog-d'];

    public function setUp(): void
    {
        self::ensureCleanupHook();
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

    public function testRecentBuscaPorDetalleIpYUsuario(): void
    {
        Auth::assumeLoggedIn(self::$userId);
        Auth::forgetCache();
        SecurityLog::record('form.delete', 'marca-slog-c');

        // Detalle.
        $porDetalle = SecurityLog::recent('', 1, 50, 'marca-slog-c');
        assertTrue(
            in_array('marca-slog-c', array_column($porDetalle['items'], 'detail'), true),
            'el buscador casa con el detalle'
        );

        // Nombre de usuario: la fila se guardó con self::$userId, cuyo nombre es
        // «Log User»; el buscador mira `u.name` por el JOIN.
        $porUsuario = SecurityLog::recent('', 1, 50, 'Log User');
        assertTrue(
            in_array('marca-slog-c', array_column($porUsuario['items'], 'detail'), true),
            'el buscador casa con el nombre del usuario'
        );

        // IP: se inserta a mano porque `record()` usa la IP del request (vacía en CLI).
        Database::getInstance(Config::db())->execute(
            "INSERT INTO security_log (event, detail, ip) VALUES ('auth.login_ok', :d, :ip)",
            ['d' => 'marca-slog-a', 'ip' => '203.0.113.77']
        );
        $porIp = SecurityLog::recent('', 1, 50, '203.0.113.77');
        assertTrue(
            in_array('marca-slog-a', array_column($porIp['items'], 'detail'), true),
            'el buscador casa con la IP'
        );

        // El `%` escrito es texto, no comodín: sin escapar, buscar «%» traería el
        // log entero y parecería que el buscador está roto.
        $soloPct = SecurityLog::recent('', 1, 50, '%');
        assertFalse(
            in_array('marca-slog-c', array_column($soloPct['items'], 'detail'), true),
            'un «%» literal no hace de comodín'
        );
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

    private static function ensureCleanupHook(): void
    {
        if (!self::$cleanupHookRegistered) {
            self::$cleanupHookRegistered = true;
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