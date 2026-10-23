<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\SecurityLog;
use App\Core\Session;
use App\Models\Form;
use App\Models\FormParticipant;

final class RosterDoor
{
    public const MAX_INTENTOS = 10;
    public const VENTANA = 900;

    private const GRANT_PARTICIPANTE = 'participant';

    private static string $lastMotivo = 'not_found';

    public static function lastMotivo(): string
    {
        return self::$lastMotivo;
    }

    public static function normalizar(string $codigo): string
    {
        return FormParticipant::normalizarCode($codigo);
    }

    public static function buscar(array $form, string $codigo): ?array
    {
        $fila = FormParticipant::findByCodeInForm((int) $form['id'], self::normalizar($codigo));
        if ($fila === null) {
            self::$lastMotivo = 'not_found';
            SecurityLog::record('roster.code_rejected', 'código no válido en /f/' . $form['slug']);
            return null;
        }
        if (!empty($fila['responded_at'])) {
            self::$lastMotivo = 'used';
            SecurityLog::record('roster.code_rejected', 'código ya utilizado en /f/' . $form['slug']);
            return null;
        }
        self::$lastMotivo = 'ok';
        return $fila;
    }

    public static function buscarAmbos(array $form, string $valor): ?array
    {
        self::$lastMotivo = 'not_found';
        $fila = FormParticipant::findByCodeInForm((int) $form['id'], self::normalizar($valor));
        if ($fila !== null) {
            if (!empty($fila['responded_at'])) {
                self::$lastMotivo = 'used';
                SecurityLog::record('roster.code_rejected', 'credencial ya utilizada en /f/' . $form['slug']);
                return null;
            }
            self::$lastMotivo = 'ok';
            return $fila;
        }

        $identificador = FormParticipant::identifierOf(Form::settings((int) $form['id']));
        $valorTrim = trim($valor);
        if ($valorTrim === '' || $identificador === 'codigo') {
            SecurityLog::record('roster.code_rejected', 'credencial no válida en /f/' . $form['slug']);
            return null;
        }

        $fila = FormParticipant::findByIdentifier((int) $form['id'], $identificador, $valorTrim);
        if ($fila === null) {
            SecurityLog::record('roster.code_rejected', 'credencial no válida en /f/' . $form['slug']);
            return null;
        }
        if (!empty($fila['responded_at'])) {
            self::$lastMotivo = 'used';
            SecurityLog::record('roster.code_rejected', 'credencial ya utilizada en /f/' . $form['slug']);
            return null;
        }
        self::$lastMotivo = 'ok';

        return $fila;
    }

    public static function permitirIntento(int $formId, string $codigo, string $ip): bool
    {
        $codigo = self::normalizar($codigo);

        $maxIp = max(1, (int) Config::get('app.limits.roster_code_per_15min', self::MAX_INTENTOS));

        if (!RateLimiter::isAllowed('form_code', (string) $formId . '|' . $ip, $maxIp, self::VENTANA)) {
            SecurityLog::record('rate_limit.form_code', 'bloqueo por IP (' . $maxIp . ' / 15 min)');

            return false;
        }
        RateLimiter::recordHit('form_code', (string) $formId . '|' . $ip, self::VENTANA);

        if ($codigo !== '') {
            $llave = 'form_code_codigo|' . $formId . '|' . $codigo;
            if (!RateLimiter::isAllowed('form_code_codigo', $llave, self::MAX_INTENTOS, self::VENTANA)) {
                SecurityLog::record('rate_limit.form_code', 'bloqueo por código');

                return false;
            }
            RateLimiter::recordHit('form_code_codigo', $llave, self::VENTANA);
        }

        return true;
    }

    public static function otorgar(array $form, array $participante): void
    {
        Session::regenerateId(true);
        $_SESSION['vf_roster_grant'] = [
            'form_id' => (int) $form['id'],
            'participant_id' => (int) $participante['id'],
            'kind' => self::GRANT_PARTICIPANTE,
            'exp' => 0,
            'at' => time(),
        ];
    }

    public static function concedido(array $form): ?array
    {
        $grant = $_SESSION['vf_roster_grant'] ?? null;
        if (!is_array($grant)) {
            return null;
        }
        if ((int) ($grant['form_id'] ?? 0) !== (int) $form['id']) {
            return null;
        }
        if (($grant['kind'] ?? '') !== self::GRANT_PARTICIPANTE) {
            return null;
        }
        $exp = (int) ($grant['exp'] ?? 0);
        if ($exp > 0 && $exp < time()) {
            unset($_SESSION['vf_roster_grant']);
            return null;
        }
        $pid = (int) ($grant['participant_id'] ?? 0);
        if ($pid <= 0) {
            unset($_SESSION['vf_roster_grant']);
            return null;
        }
        $participante = FormParticipant::find($pid);
        if ($participante === null || (int) $participante['form_id'] !== (int) $form['id']) {
            unset($_SESSION['vf_roster_grant']);
            return null;
        }
        if (!empty($participante['responded_at'])) {
            unset($_SESSION['vf_roster_grant']);
            return null;
        }
        if (!FormParticipant::existeEnFormulario((int) $form['id'], $pid)) {
            unset($_SESSION['vf_roster_grant']);
            return null;
        }
        return $participante;
    }

    public static function limpiar(): void
    {
        unset($_SESSION['vf_roster_grant']);
    }
}
