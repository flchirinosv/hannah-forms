<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Escritor de `.env` para el instalador web.
 *
 * El cargador (`Env::load()`) solo LEE; hasta ahora nadie escribía el `.env`
 * de forma programática (el `scripts/generate-key.php` lo hacía con un par de
 * `preg_replace` ad hoc). El instalador necesita algo más cuidadoso:
 *
 * - **Preserva** el fichero existente: comentarios, orden y claves que no toca.
 *   El `.env.example` es documentación de campo; reescribirlo a ciegas la borra.
 * - **Upsert**: si la clave ya existe, la reemplaza en su sitio; si no, la
 *   añade al final. Nunca duplica una clave (el parser tomaría la primera).
 * - **Atómico**: escribe en un temporal del mismo directorio y `rename()`, que
 *   en el mismo sistema de ficheros es atómico. Un fallo a medias no deja el
 *   `.env` truncado (contiene `DB_PASSWORD` y `APP_KEY`).
 * - **0600**: el fichero solo lo lee su dueño. El default de copiar
 *   `.env.example` suele ser 0644, que deja la contraseña de la BD legible.
 *
 * No cifra nada: la `APP_KEY` es una clave, no un secreto cifrado, y es el
 * propio `.env` quien la guarda (ver `Secret`).
 */
final class EnvWriter
{
    private string $path;

    /** @var array<int, string> */
    private array $lines;

    public function __construct(string $path)
    {
        $this->path = $path;

        if (is_file($path)) {
            $contents = (string) file_get_contents($path);
            $this->lines = $contents === '' ? [] : explode("\n", $contents);
            // `explode` deja una última línea vacía si el fichero acaba en \n.
            if ($this->lines !== [] && end($this->lines) === '') {
                array_pop($this->lines);
            }
        } else {
            $this->lines = [];
        }
    }

    /**
     * Pone (o reemplaza) una clave. Devuelve $this para encadenar.
     */
    public function set(string $key, string $value): self
    {
        $line = $key . '=' . self::formatValue($value);
        $patron = '/^\s*' . preg_quote($key, '/') . '\s*=/';
        $reemplazada = false;

        foreach ($this->lines as $i => $linea) {
            if (preg_match($patron, $linea) === 1) {
                if (!$reemplazada) {
                    $this->lines[$i] = $line;
                    $reemplazada = true;
                } else {
                    // Duplicado preexistente: se elimina para no dejar dos.
                    unset($this->lines[$i]);
                }
            }
        }

        if (!$reemplazada) {
            $this->lines[] = $line;
        }

        $this->lines = array_values($this->lines);

        return $this;
    }

    /**
     * Pone varias claves de golpe.
     *
     * @param array<string, scalar|null> $pares
     */
    public function setMany(array $pares): self
    {
        foreach ($pares as $key => $value) {
            $this->set((string) $key, $value === null ? '' : (string) $value);
        }

        return $this;
    }

    /**
     * Escribe el fichero de forma atómica y con permisos 0600.
     *
     * @throws \RuntimeException si no se puede escribir o renombrar.
     */
    public function save(): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            throw new \RuntimeException('No existe el directorio de destino: ' . $dir);
        }

        $contenido = implode("\n", $this->lines) . "\n";

        $tmp = $dir . '/.env.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, $contenido) === false) {
            throw new \RuntimeException('No se pudo escribir el .env temporal.');
        }

        @chmod($tmp, 0600);

        if (!@rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new \RuntimeException('No se pudo reemplazar el .env.');
        }

        @chmod($this->path, 0600);
    }

    /**
     * Formatea el valor para que `Env::parseLine()` lo devuelva idéntico.
     *
     * El parser no soporta comillas embebidas (solo quita la envolvente), así
     * que aquí solo se entrecomilla cuando hace falta para que no se corten
     * caracteres:
     *
     * - Un ` #` dentro del valor se leería como comentario → comillas dobles.
     * - Espacios al principio/final → comillas dobles.
     * - Si el valor contiene comillas dobles, se usan simples; si contiene las
     *   dos, se deja sin comillas (un valor así no es representable en este
     *   formato, y es un caso patológico para las claves que escribe el
     *   instalador).
     * - El vacío se escribe `""` (explícito, aunque `KEY=` también valdría).
     */
    private static function formatValue(string $value): string
    {
        if ($value === '') {
            return '""';
        }

        $necesitaComillas = str_contains($value, ' #')
            || (trim($value) !== $value);

        if (!$necesitaComillas) {
            return $value;
        }

        if (!str_contains($value, '"')) {
            return '"' . $value . '"';
        }

        if (!str_contains($value, "'")) {
            return "'" . $value . "'";
        }

        return $value;
    }
}
