<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Models\FormParticipant;

final class RosterImportPending
{
    public const TTL = 1800;
    public const DIR = 'tmp/roster-import';

    public static function dir(): string
    {
        $base = rtrim((string) Config::get('paths.storage', dirname(__DIR__, 2) . '/storage'), '/');
        return $base . '/' . self::DIR;
    }

    public static function asegurarDir(): void
    {
        $dir = self::dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        @chmod($dir, 0700);
        $ht = $dir . '/.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "Require all denied\nDeny from all\n");
        }
    }

    public static function crear(int $formId, int $userId, string $modo, array $cabeceras, int $totalFilas, string $archivoOriginalTmp): array
    {
        self::asegurarDir();
        self::limpiarVencidos();

        self::borrarPendienteUsuario($formId, $userId);

        $token = bin2hex(random_bytes(32));
        $destino = self::dir() . '/' . $token . '.xlsx';

        if (!@move_uploaded_file($archivoOriginalTmp, $destino) && !@rename($archivoOriginalTmp, $destino) && !@copy($archivoOriginalTmp, $destino)) {
            // intentar copiar contenido
            $contenido = @file_get_contents($archivoOriginalTmp);
            if (is_string($contenido)) {
                @file_put_contents($destino, $contenido);
            }
        }
        @chmod($destino, 0600);

        $entry = [
            'token' => $token,
            'form_id' => $formId,
            'user_id' => $userId,
            'path' => $destino,
            'cabeceras' => $cabeceras,
            'sugerencia' => self::sugerencia($cabeceras),
            'total' => $totalFilas,
            'modo' => in_array($modo, [ParticipantImporter::MODO_FUSIONAR, ParticipantImporter::MODO_REEMPLAZAR], true) ? $modo : ParticipantImporter::MODO_FUSIONAR,
            'created_at' => time(),
        ];

        $_SESSION['roster_import'] = $_SESSION['roster_import'] ?? [];
        if (is_array($_SESSION['roster_import'])) {
            $_SESSION['roster_import'][$token] = $entry;
        }

        return $entry;
    }

    public static function obtener(int $formId, int $userId): ?array
    {
        self::limpiarVencidos();
        $lista = $_SESSION['roster_import'] ?? [];
        if (!is_array($lista)) {
            return null;
        }
        foreach ($lista as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if ((int) ($entry['form_id'] ?? 0) !== $formId) {
                continue;
            }
            if ((int) ($entry['user_id'] ?? 0) !== $userId) {
                continue;
            }
            $creado = (int) ($entry['created_at'] ?? 0);
            if ($creado > 0 && (time() - $creado) > self::TTL) {
                self::borrar($entry);
                unset($_SESSION['roster_import'][$entry['token'] ?? '']);
                continue;
            }
            if (!empty($entry['path']) && is_file($entry['path'])) {
                $entry['sugerencia'] = self::sugerencia((array) ($entry['cabeceras'] ?? []));
                return $entry;
            }
        }
        return null;
    }

    public static function tomar(int $formId, int $userId, string $token): ?array
    {
        self::limpiarVencidos();
        $lista = $_SESSION['roster_import'] ?? [];
        if (!is_array($lista)) {
            return null;
        }
        $entry = $lista[$token] ?? null;
        if (!is_array($entry)) {
            return null;
        }
        if ((int) ($entry['form_id'] ?? 0) !== $formId || (int) ($entry['user_id'] ?? 0) !== $userId) {
            return null;
        }
        $creado = (int) ($entry['created_at'] ?? 0);
        if ($creado > 0 && (time() - $creado) > self::TTL) {
            self::borrar($entry);
            unset($_SESSION['roster_import'][$token]);
            return null;
        }
        if (empty($entry['path']) || !is_file($entry['path'])) {
            unset($_SESSION['roster_import'][$token]);
            return null;
        }
        // quitar de sesión pero no borrar archivo (se borra en borrar())
        unset($_SESSION['roster_import'][$token]);
        return $entry;
    }

    public static function borrar(?array $entry): void
    {
        if (!is_array($entry)) {
            return;
        }
        $path = (string) ($entry['path'] ?? '');
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    private static function borrarPendienteUsuario(int $formId, int $userId): void
    {
        $lista = $_SESSION['roster_import'] ?? [];
        if (!is_array($lista)) {
            return;
        }
        foreach ($lista as $token => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if ((int) ($entry['form_id'] ?? 0) === $formId && (int) ($entry['user_id'] ?? 0) === $userId) {
                self::borrar($entry);
                unset($_SESSION['roster_import'][$token]);
            }
        }
    }

    public static function limpiarVencidos(): int
    {
        $lista = $_SESSION['roster_import'] ?? [];
        if (!is_array($lista)) {
            return 0;
        }
        $ahora = time();
        $borrados = 0;
        foreach ($lista as $token => $entry) {
            if (!is_array($entry)) {
                unset($_SESSION['roster_import'][$token]);
                $borrados++;
                continue;
            }
            $creado = (int) ($entry['created_at'] ?? 0);
            if ($creado > 0 && ($ahora - $creado) > self::TTL) {
                self::borrar($entry);
                unset($_SESSION['roster_import'][$token]);
                $borrados++;
                continue;
            }
        }
        // barrido huérfanos en disco (>2h)
        $dir = self::dir();
        if (is_dir($dir)) {
            $items = @scandir($dir);
            if (is_array($items)) {
                foreach ($items as $item) {
                    if ($item === '.' || $item === '..' || $item === '.htaccess') {
                        continue;
                    }
                    if (substr($item, -5) !== '.xlsx') {
                        continue;
                    }
                    $path = $dir . '/' . $item;
                    if (!is_file($path)) {
                        continue;
                    }
                    if ((time() - @filemtime($path)) > 7200) {
                        @unlink($path);
                    }
                }
            }
        }
        return $borrados;
    }

    public static function sugerencia(array $cabeceras): array
    {
        $sugerencia = [
            'identificador' => null,
            'nombre' => null,
            'correo' => null,
            'telefono' => null,
        ];
        foreach ($cabeceras as $i => $cabecera) {
            $norm = RosterTemplate::normalizarCabecera((string) $cabecera);
            if ($sugerencia['identificador'] === null && in_array($norm, ['cedula', 'documento'], true)) {
                $sugerencia['identificador'] = $i;
                continue;
            }
            if ($sugerencia['nombre'] === null && in_array($norm, ['nombre', 'nombres', 'nombrecompleto'], true)) {
                $sugerencia['nombre'] = $i;
                continue;
            }
            if ($sugerencia['correo'] === null && in_array($norm, ['correo', 'email'], true)) {
                $sugerencia['correo'] = $i;
                continue;
            }
            if ($sugerencia['telefono'] === null && in_array($norm, ['telefono', 'telefonico', 'tel', 'movil', 'celular'], true)) {
                $sugerencia['telefono'] = $i;
                continue;
            }
        }
        if ($sugerencia['identificador'] === null && isset($cabeceras[0])) {
            $sugerencia['identificador'] = 0;
        }
        return $sugerencia;
    }
}
