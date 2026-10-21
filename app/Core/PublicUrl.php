<?php

declare(strict_types=1);

namespace App\Core;

/**
 * URL pública de la instancia: la que se copia al portapapeles, la que se
 * codifica en el QR y la que va en el `<iframe>` de embed.
 *
 * ANTES esto salía siempre de `app.url` (`APP_URL`), cuyo default de desarrollo
 * es `http://127.0.0.1:8080`. Servir la app en otro host (el dominio de
 * producción, un túnel, la IP de la LAN) dejaba el enlace copiado y el QR
 * apuntando a `127.0.0.1`, que para quien los recibe no existe: no es un
 * problema de la vista sino de que la config era la única fuente.
 *
 * La resolución tiene tres peldaños, en este orden:
 *
 *   1. `APP_PUBLIC_URL` (config `app.public_url`). Es el pin explícito: quien
 *      opera la instancia lo fija cuando hay un proxy, varios dominios o
 *      cualquier cosa que el `Host` de la petición no refleje. Vacío = no fijado.
 *   2. La petición actual (esquema + `HTTP_HOST`). Es lo correcto en el 99% de
 *      las instalaciones self-hosted y es lo que hace que en desarrollo el
 *      enlace apunte al host por el que se está mirando la app.
 *   3. `app.url` (`APP_URL`). Solo cuando no hay petición (CLI: el runner de
 *      tests, `scripts/*`, un cron) o el `Host` no es utilizable.
 *
 * OJO — por qué el peldaño 2 no es un problema de seguridad: `Host` lo manda
 * el cliente, así que un atacante podría reflejarse un host arbitrario. Aquí
 * eso NO es una vulnerabilidad por tres razones: (a) el valor solo se LEE para
 * pintar un campo de texto y un QR —no entra en ninguna decisión de
 * autorización, ni en cookies, ni en redirects—; (b) la página que lo pinta
 * exige sesión de dueño del formulario; y (c) para reflejarlo habría que
 * conseguir que la víctima cargue la app bajo un host elegido por el atacante,
 * lo que ya exige llegar hasta ese host por algún medio.
 *
 * Aun así el `Host` se valida estrictamente (abajo): cualquier cosa que no
 * tenga forma de host se descarta y se cae al peldaño 3, en vez de acabar
 * inyectando texto en un atributo `src`, en un `value=` o en el texto que se
 * codifica en el QR. Para lo que sí serviría un host falsificado —mandar el QR
 * de una víctima a terceros— el sitio tiene que estar en producción con su
 * `Host` real, y ahí la respuesta es el peldaño 1.
 *
 * `Config` no tiene setter, así que los tests fijan el pin con el seam
 * `setBaseForTesting()` (null = resolver de verdad) y lo limpian en `tearDown`,
 * igual que `RedirectGuard::setAllowlistForTesting()`.
 */
final class PublicUrl
{
    /** Seam de tests: base fija o null para resolver de verdad. */
    private static ?string $baseOverride = null;

    /**
     * Seam de tests: qué responde `app.url` (el último peldaño). null = leer la
     * config; '' = la instancia no tiene ninguna URL utilizable. Existe para
     * poder probar el caso «no hay ninguna base» sin depender de lo que tenga
     * el `.env` de la máquina, que en desarrollo siempre tiene `APP_URL`.
     */
    private static ?string $urlFallbackOverride = null;

    public static function setBaseForTesting(?string $base, ?string $urlFallback = null): void
    {
        self::$baseOverride = $base;
        self::$urlFallbackOverride = $urlFallback;
    }

    /**
     * Base pública sin barra final. '' si no hay ninguna utilizable (app sin
     * URL configurada y sin petición): las URLs salen relativas, que sigue
     * siendo un enlace que funciona si se abre en el mismo origen.
     */
    public static function base(): string
    {
        if (self::$baseOverride !== null) {
            $override = self::cleanBase(self::$baseOverride);
            if ($override !== '') {
                return $override;
            }
        }

        $pinned = trim(InstanceSettings::publicUrl());
        if ($pinned !== '') {
            $clean = self::cleanBase($pinned);
            if ($clean !== '') {
                return $clean;
            }
        }

        $fromRequest = self::fromRequest();
        if ($fromRequest !== '') {
            return $fromRequest;
        }

        return self::cleanBase((string) (self::$urlFallbackOverride ?? Config::get('app.url', '')));
    }

    /** URL pública de respuesta de un formulario. */
    public static function fillUrl(string $slug): string
    {
        return self::base() . '/f/' . rawurlencode($slug);
    }

    /** URL de la página de embed (mismo formulario, action distinto). */
    public static function embedUrl(string $slug): string
    {
        return self::fillUrl($slug) . '/embed';
    }

    /**
     * F4 — URL de acceso con token: la que va en el correo del respondiente y en
     * el enlace que el dueño copia.
     *
     * El token viaja en la query y no en una cookie porque es la misma credencial
     * que se envía por correo: la cookie la pone el servidor, el enlace lo lleva
     * quien lo manda. Sale de `PublicUrl` (y no de `app.url`) por la misma razón
     * que el QR y el embed: en una instalación detrás de un proxy o en un
     * dominio distinto del configurado, `app.url` produce enlaces que no abren
     * en el sitio real.
     */
    public static function accessUrl(string $slug, string $token): string
    {
        return self::fillUrl($slug) . '?t=' . rawurlencode($token);
    }

    /**
     * Fase 10 — URL de invitación de un participante.
     *
     * Es la MISMA forma que `accessUrl()` y a propósito lleva su propio nombre,
     * no un alias: las dos credenciales son distintas y se confundían fácil. El
     * `?t=` de F4 es un token de `form_access_tokens`, con caducidad y revocable
     * desde la ficha; el `?t=` del listado es el **código personal** de la fila
     * (`form_participants.code`), que no caduca y lo que lo cierra es que la
     * persona siga en la lista. En `FillController::resolveAccess()` se busca
     * primero el token de F4, así que un `?t=` que casara con los dos seguiría
     * siendo el token; y para un formulario de listado, que no tiene tokens, esto
     * es lo único que hay.
     *
     * Que las dos credenciales compartan el hueco de la query es lo que hace que
     * el `action` del POST del formulario no se toque: la invitación es un enlace
     * al GET y el canje del código va por su propia ruta (`/f/{slug}/code`).
     */
    public static function rosterUrl(string $slug, string $code): string
    {
        return self::fillUrl($slug) . '?t=' . rawurlencode($code);
    }

    /**
     * ¿La base resuelta es una dirección de red local (localhost, 127.x,
     * ::1, RFC1918) o un nombre sin dominio?
     *
     * Sirve para el aviso de la tarjeta de compartir: un enlace a `127.0.0.1`
     * funciona en el equipo de quien lo ve y en ningún otro, así que el caso
     * real de este aviso no es un fallo de la app sino una URL pública sin
     * fijar. Sin él, el síntoma es un QR que no abre nada.
     */
    public static function isLocalBase(): bool
    {
        $host = self::hostOfBase();
        if ($host === '') {
            return false;
        }

        $host = strtolower(trim($host, '[]'));
        if ($host === 'localhost') {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            // No válida solo si es privada O reservada (loopback incluido).
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false;
        }

        // Un nombre sin punto («servidor», «wsl») solo resuelve en la LAN.
        return strpos($host, '.') === false;
    }

    /** ¿Hay una URL pública fijada a mano y utilizable, o se está deduciendo? */
    public static function isPinned(): bool
    {
        if (self::$baseOverride !== null) {
            return self::cleanBase(self::$baseOverride) !== '';
        }
        $pinned = trim(InstanceSettings::publicUrl());
        return $pinned !== '' && self::cleanBase($pinned) !== '';
    }

    /** Host de la base resuelta (sin puerto ni corchetes), o '' si no hay. */
    public static function hostOfBase(): string
    {
        $base = self::base();
        if ($base === '') {
            return '';
        }
        $host = parse_url($base, PHP_URL_HOST);
        return is_string($host) && $host !== '' ? strtolower(trim($host, '[]')) : '';
    }

    /* ---------- resolución ---------- */

    /** `https://host[:puerto]` de la petición actual, o '' si no es usable. */
    private static function fromRequest(): string
    {
        $host = self::requestHost();
        if ($host === '') {
            return '';
        }
        return self::requestScheme() . '://' . $host;
    }

    private static function requestScheme(): string
    {
        $https = trim((string) ($_SERVER['HTTPS'] ?? ''));
        if ($https !== '' && strtolower($https) !== 'off') {
            return 'https';
        }

        // `X-Forwarded-Proto` lo pone el proxy de confianza; con
        // TRUST_PROXY=false es el cliente y mentiría con el esquema.
        if (Request::trustsProxy()) {
            $forwarded = explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
            $proto = strtolower(trim($forwarded[0]));
            if ($proto === 'https' || $proto === 'http') {
                return $proto;
            }
        }

        return 'http';
    }

    /**
     * `HTTP_HOST` validado, o '' si no hay o no tiene forma de host.
     *
     * El filtro importa aunque el valor no se use para autorizar: acaba dentro
     * de un `value=`, de un `data-url=` y de la URL que se codifica en el QR, y
     * un `Host` con CR/LF, `@`, espacios o comillas lo rompería (o lo
     * inyectaría) en lugar de dar una URL rara.
     */
    private static function requestHost(): string
    {
        $raw = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($raw === '' || strlen($raw) > 255) {
            return '';
        }

        if ($raw[0] === '[') {
            $close = strpos($raw, ']');
            if ($close === false) {
                return '';
            }
            $host = substr($raw, 0, $close + 1);
            // Detrás del cierre va ":puerto" o nada; cualquier otra cosa es basura.
            $port = substr($raw, $close + 1);
            if ($port !== '' && $port[0] === ':') {
                $port = substr($port, 1);
            } elseif ($port !== '') {
                return '';
            }
        } else {
            $pos = strrpos($raw, ':');
            $host = $pos === false ? $raw : substr($raw, 0, $pos);
            $port = $pos === false ? '' : substr($raw, $pos + 1);
        }

        if (!self::validHostName($host)) {
            return '';
        }

        if ($port === '') {
            return $host;
        }
        if (preg_match('/^[0-9]{1,5}$/', $port) !== 1) {
            return '';
        }
        $number = (int) $port;
        if ($number < 1 || $number > 65535) {
            return '';
        }

        return $host . ':' . $number;
    }

    /**
     * Normaliza una URL de base: le pone esquema si falta y le quita la barra
     * final. '' si el host no es válido.
     */
    private static function cleanBase(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $raw) !== 1) {
            $raw = 'http://' . ltrim($raw, '/');
        }
        if (preg_match('#^(https?)://([^/\?\#]*)#i', $raw, $m) !== 1) {
            return '';
        }
        $authority = $m[2];
        if (!self::validHostName(self::hostOf($authority))) {
            return '';
        }
        $path = substr($raw, strlen($m[0]));
        return rtrim(strtolower($m[1]) . '://' . $authority . rtrim($path, '/'), '/');
    }

    /** Host (sin puerto) de una autoridad `host:puerto` o `[v6]:puerto`. */
    private static function hostOf(string $authority): string
    {
        if ($authority !== '' && $authority[0] === '[') {
            $close = strpos($authority, ']');
            return $close === false ? '' : substr($authority, 0, $close + 1);
        }
        $pos = strrpos($authority, ':');
        return $pos === false ? $authority : substr($authority, 0, $pos);
    }

    /**
     * ¿Tiene forma de host? Acepta nombre con etiquetas, IPv4, e IPv6 con o
     * sin corchetes. Rechaza cualquier otra cosa: CR/LF, espacios, `@`, `/`,
     * `?`, `#`, etiquetas vacías o que empiezan por `-`, y ports imposibles
     * los descarta el llamador.
     */
    private static function validHostName(string $host): bool
    {
        if ($host === '') {
            return false;
        }

        $bare = trim($host, '[]');
        if (str_contains($bare, ':')) {
            // IPv6: o con corchetes y solo IPv6 dentro, o suelta.
            if (str_starts_with($host, '[') !== str_ends_with($host, ']')) {
                return false;
            }
            return filter_var($bare, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }

        if (strlen($host) > 253) {
            return false;
        }
        foreach (explode('.', $host) as $label) {
            if ($label === '' || strlen($label) > 63) {
                return false;
            }
            if (preg_match('/^[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?$/', $label) !== 1) {
                return false;
            }
        }

        return true;
    }
}
