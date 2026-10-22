<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Guarda del instalador web (AG11): decide si el instalador se puede abrir y si
 * el servidor cumple los requisitos. Es pura y estática (salvo la ruta del lock,
 * que se puede fijar para el test) para probarla sin HTTP ni base de datos.
 *
 * El instalador es un entrypoint propio (`public/install.php`) que NO carga el
 * bootstrap: una vez instalada la app, el fichero seguiría siendo alcanzable por
 * URL, así que el primer trabajo del guard es cerrarlo. El marcador es un fichero
 * en disco (`storage/installed.lock`) porque no se puede preguntar a la BD: en
 * mitad de una instalación la BD puede no existir, y la pregunta «¿hay
 * instalación?» tiene que contestarse antes de conectar a nada.
 */
final class InstallGuard
{
    private static ?string $lockPathOverride = null;

    /**
     * Ruta del marcador. Por defecto `storage/installed.lock` de la instalación.
     */
    public static function lockPath(): string
    {
        if (self::$lockPathOverride !== null) {
            return self::$lockPathOverride;
        }

        return dirname(__DIR__, 2) . '/storage/installed.lock';
    }

    /**
     * Fija la ruta del lock para un test. `null` vuelve a la de la instalación.
     */
    public static function setLockPathForTesting(?string $path): void
    {
        self::$lockPathOverride = $path;
    }

    public static function estaInstalado(): bool
    {
        return is_file(self::lockPath());
    }

    /**
     * Marca la instalación como completada. Escribe un JSON con la fecha (UTC) y
     * el email del primer admin —sin la contraseña— para que quede un rastro de
     * cuándo se instaló esto. `0600`.
     *
     * @throws \RuntimeException si no se puede escribir.
     */
    public static function marcarInstalado(string $adminEmail): void
    {
        $path = self::lockPath();
        $dir = dirname($path);

        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('No se pudo crear el directorio del marcador de instalación.');
        }

        $contenido = json_encode([
            'installed_at' => gmdate('c'),
            'admin_email'  => $adminEmail,
            'php_version'  => PHP_VERSION,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

        if (file_put_contents($path, $contenido) === false) {
            throw new \RuntimeException('No se pudo escribir el marcador de instalación.');
        }

        @chmod($path, 0600);
    }

    /**
     * Desmarca (solo para tests o reinstalación deliberada).
     */
    public static function desmarcarParaTesting(): void
    {
        $path = self::lockPath();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Comprueba los requisitos del servidor. Devuelve una lista de problemas
     * (vacía = todo bien). Cada problema es `['nivel' => 'error'|'aviso', 'texto' => ...]`,
     * de modo que la vista pueda pintar los avisos sin ocultar un error real.
     *
     * @return array<int, array{nivel: string, texto: string}>
     */
    public static function revisarRequisitos(string $raiz): array
    {
        $problemas = [];

        if (version_compare(PHP_VERSION, '8.2.0', '<')) {
            $problemas[] = [
                'nivel' => 'error',
                'texto' => 'PHP 8.2 o superior (este servidor tiene ' . PHP_VERSION . ').',
            ];
        }

        $extensiones = ['pdo_mysql', 'mbstring', 'fileinfo', 'openssl'];
        foreach ($extensiones as $ext) {
            if (!extension_loaded($ext)) {
                $problemas[] = [
                    'nivel' => 'error',
                    'texto' => 'La extensión de PHP `' . $ext . '` no está activa.',
                ];
            }
        }

        // Los directorios de escritura: uploads y branding viven fuera de
        // public/ y la app escribe ahí (adjuntos, logo, favicon).
        foreach (['storage', 'storage/uploads', 'storage/branding'] as $rel) {
            $dir = $raiz . '/' . $rel;
            if (!is_dir($dir)) {
                $problemas[] = [
                    'nivel' => 'error',
                    'texto' => 'Falta el directorio `' . $rel . '/`.',
                ];
            } elseif (!is_writable($dir)) {
                $problemas[] = [
                    'nivel' => 'error',
                    'texto' => 'El directorio `' . $rel . '/` no tiene permiso de escritura.',
                ];
            }
        }

        // El .env debe poder escribirse (o crearse): es el paso final.
        $envPath = $raiz . '/.env';
        if (is_file($envPath) && !is_writable($envPath)) {
            $problemas[] = [
                'nivel' => 'error',
                'texto' => 'El fichero `.env` existe pero no tiene permiso de escritura.',
            ];
        } elseif (!is_file($envPath) && !is_writable($raiz)) {
            $problemas[] = [
                'nivel' => 'aviso',
                'texto' => 'No se puede crear el `.env` (la raíz no es escribible); crea el fichero a mano.',
            ];
        }

        return $problemas;
    }

    public static function hayErrores(array $problemas): bool
    {
        foreach ($problemas as $p) {
            if (($p['nivel'] ?? '') === 'error') {
                return true;
            }
        }

        return false;
    }
}
