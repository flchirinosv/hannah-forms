<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * Webhook
 *
 * Notificación HTTP POST (fire-and-forget) a un endpoint externo cuando una
 * respuesta llega (P5). El payload es JSON, los valores de respuestas van
 * decodificados (listas y mapas se serializan como objetos/arreglos).
 *
 * Reglas de robustez:
 * - URL vacía o inválida -> no hace nada.
 * - Solo http/https (evita file:// y otros schemes).
 * - Timeout corto (5s) y nunca lanza hacia FillController.
 *
 * SSRF (fase 2, item 2.2). Que la URL la configure el dueño del formulario no
 * la hace de fiar: el destino se RESUELVE y se comprueba antes de conectar, y
 * se rechazan loopback, RFC1918, link-local (incluida 169.254.169.254 de la
 * metadata de la nube), CGNAT, multicast y reservadas. La comprobación es por
 * IP, no por cadena: `http://127.0.0.1.nip.io`, un IPv4 mapeado en IPv6 o un
 * nombre en un dns local se caen igual. Además, si el host resuelve a varias
 * IP y CUALQUIERA es privada se rechaza entero (es la forma de un rebinding).
 *
 * La conexión se fija a la IP ya validada (TOCTOU) pero el `Host` y el SNI
 * siguen siendo el nombre: así el TLS se verifica contra el nombre real, que es
 * lo que rompe si te conectas a la IP suelta. No se siguen redirecciones.
 *
 * Para apuntar a un servicio de la red local propio hacen falta DOS opt-ins
 * (ambos por defecto false):
 *
 *   1. `WEBHOOK_ALLOW_PRIVATE_HOSTS=true` en el servidor (config
 *      `app.webhook.allow_private_hosts`). Lo decide quien opera la instancia.
 *   2. `webhook.allow_private_hosts` en los settings del formulario. Lo decide
 *      quien edita ese formulario.
 *
 * El AND es lo que cierra el agujero: si el opt-in fuera solo el del formulario
 * bastaría con que cualquiera con permiso de edición (dueño, editor o admin —
 * `canEdit()`) mandara `{"webhook":{"allow_private_hosts":true}}` al guardado
 * de settings para usar el webhook como proxy a la red interna. Con el opt-in
 * del servidor, el dueño del formulario no puede ampliar el alcance: si el
 * operador no lo ha puesto, ningún formulario llega a red privada por muchos
 * settings que mande. Al revés tampoco: con el servidor abierto, cada formulario
 * sigue teniendo que pedirlo.
 *
 * Testeable: Webhook::setHandler() inyecta un callable (url, payload, config)
 * que se llama en lugar de hacer el POST real, y Webhook::setAllowPrivate()
 * fija el opt-in del servidor para poder probar los dos interruptores.
 */
final class Webhook
{
    /** @var callable|null (string $url, array $payload, array $settings): void */
    private static $handler = null;

    /**
     * @var callable|null (string $host): string[] Sustituye la resolución DNS
     *   para que los tests no dependan de la red (y puedan simular un host que
     *   resuelve a la vez a IP pública y privada, que es el rebinding).
     */
    private static $resolver = null;

    /**
     * @var bool|null Fija el opt-in del SERVIDOR en los tests. null = se lee de
     *   la config (WEBHOOK_ALLOW_PRIVATE_HOSTS), que es lo normal.
     */
    private static $allowPrivate = null;

    private const CONNECT_TIMEOUT = 5;
    private const IO_TIMEOUT = 5;

    /**
     * Rangos que NO se pueden alcanzar desde un webhook. Formato cidr.
     * La lista es de "negativo": lo que no está aquí y no es una IP pública
     * válida pasa, y lo que está aquí se cae siempre.
     */
    private const BLOCKED_V4 = [
        '0.0.0.0/8',          // this network / unspecified
        '10.0.0.0/8',         // RFC1918
        '100.64.0.0/10',      // CGNAT (RFC6598)
        '127.0.0.0/8',        // loopback
        '169.254.0.0/16',     // link-local: 169.254.169.254 = metadata de nube
        '172.16.0.0/12',      // RFC1918
        '192.0.0.0/24',       // IETF protocol assignments
        '192.0.2.0/24',       // documentación
        '192.88.99.0/24',     // 6to4 relay anycast
        '192.168.0.0/16',     // RFC1918
        '198.18.0.0/15',      // benchmarking
        '198.51.100.0/24',    // documentación
        '203.0.113.0/24',     // documentación
        '224.0.0.0/4',        // multicast
        '240.0.0.0/4',        // reservadas + 255.255.255.255
    ];

    private const BLOCKED_V6 = [
        '::/128',             // unspecified
        '::1/128',            // loopback
        '::ffff:0:0/96',      // IPv4 mapeada (se comprueba el v4 detrás)
        '64:ff9b::/96',       // NAT64
        '100::/64',           // discard
        '2001:db8::/32',      // documentación
        '2002::/16',          // 6to4 (túnel a v4)
        'fc00::/7',           // unique local
        'fe80::/10',          // link-local
        'ff00::/8',           // multicast
    ];

    public static function setHandler(?callable $fn): void
    {
        self::$handler = $fn;
    }

    /**
     * Fija el opt-in del SERVIDOR (tests). null = se lee de la config.
     *
     * @param bool|null $v true/false simula WEBHOOK_ALLOW_PRIVATE_HOSTS
     */
    public static function setAllowPrivate(?bool $v): void
    {
        self::$allowPrivate = $v;
    }

    /**
     * El opt-in del servidor: ¿esta instancia puede hablar con red privada?
     * Es el que el dueño de un formulario NO puede tocar.
     */
    public static function serverAllowsPrivate(): bool
    {
        if (self::$allowPrivate !== null) {
            return self::$allowPrivate;
        }
        return (bool) Config::get('app.webhook.allow_private_hosts', false);
    }

    /** Inyecta la resolución de nombres (tests). null = DNS real. */
    public static function setResolver(?callable $fn): void
    {
        self::$resolver = $fn;
    }

    /**
     * Dispara el webhook si el formulario tiene uno configurado.
     */
    public static function fire(array $settings, array $payload): void
    {
        $url = trim((string) ($settings['webhook']['url'] ?? ''));
        if ($url === '') {
            return;
        }

        // Los DOS opt-ins, no uno: el del formulario no puede abrir la red
        // privada por sí solo (ver la nota de la clase).
        $pidePrivate = !empty($settings['webhook']['allow_private_hosts']);
        if ($pidePrivate && !self::serverAllowsPrivate()) {
            self::log('Webhook: el formulario pide red privada pero el servidor no lo permite (WEBHOOK_ALLOW_PRIVATE_HOSTS)');
            return;
        }
        $allowPrivate = $pidePrivate && self::serverAllowsPrivate();

        $target = self::resolveTarget($url, $allowPrivate);
        if ($target === null) {
            return;
        }

        if (self::$handler !== null) {
            (self::$handler)($url, $payload, $settings);
            return;
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            self::log('Webhook: payload no serializable en ' . $url);
            return;
        }

        if (!self::post($target, $json, $url)) {
            self::log('Webhook falló: ' . $url);
        }
    }

    /**
     * Valida el destino y devuelve los datos de conexión, o null si no se
     * puede usar. Es la función que decide si el SSRF pasa o no.
     *
     * @return array{scheme:string,host:string,port:int,ip:string,path:string}|null
     */
    public static function resolveTarget(string $url, bool $allowPrivate = false): ?array
    {
        // Sin controles ni espacios: también evita colar un CR/LF que rompa la
        // petición o inyecte cabeceras.
        if ($url === '' || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            self::log('Webhook: URL con caracteres de control, descartada');
            return null;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            self::log('Webhook: esquema no permitido (' . $scheme . ')');
            return null;
        }

        // Credenciales embebidas: el cliente antiguo (file_get_contents) no
        // sabía autenticarse con ellas, así que no funcionaban y además
        // ensucian el parseo del host.
        if (isset($parts['user']) || isset($parts['pass'])) {
            self::log('Webhook: URL con credenciales embebidas, descartada');
            return null;
        }

        $host = strtolower(trim($parts['host'], '[]'));
        if ($host === '') {
            return null;
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $port = (int) $port;
        if ($port < 1 || $port > 65535) {
            return null;
        }

        // Camino + query, tal cual (vacío = "/").
        $path = ($parts['path'] ?? '') !== '' ? $parts['path'] : '/';
        if (isset($parts['query'])) {
            $path .= '?' . $parts['query'];
        }

        $ips = self::resolveHost($host);
        if ($ips === []) {
            self::log('Webhook: el host no resuelve a ninguna IP (' . $host . ')');
            return null;
        }

        foreach ($ips as $ip) {
            if (self::isBlockedIp($ip)) {
                if (!$allowPrivate) {
                    self::log('Webhook: destino bloqueado (IP privada/reservada ' . $ip . ')');
                    return null;
                }
            }
        }

        return [
            'scheme' => $scheme,
            'host'   => $host,
            'port'   => $port,
            'ip'     => $ips[0],
            'path'   => $path,
        ];
    }

    /**
     * IPs de un host. Acepta el caso de que el "host" ya sea una IP literal.
     * Se puede falsear en tests.
     *
     * @return string[]
     */
    public static function resolveHost(string $host): array
    {
        if (self::$resolver !== null) {
            $ips = (self::$resolver)($host);
            return is_array($ips) ? array_values(array_unique(array_map('strval', $ips))) : [];
        }

        $filtered = filter_var($host, FILTER_VALIDATE_IP);
        if ($filtered !== false) {
            return [$filtered];
        }

        $ips = [];

        // A records
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            foreach ($v4 as $ip) {
                $ok = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
                if ($ok !== false) {
                    $ips[] = $ok;
                }
            }
        }

        // AAAA records
        if (function_exists('dns_get_record')) {
            $aaaa = @dns_get_record($host, DNS_AAAA);
            if (is_array($aaaa)) {
                foreach ($aaaa as $rec) {
                    $ip = (string) ($rec['ipv6'] ?? '');
                    $ok = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
                    if ($ok !== false) {
                        $ips[] = $ok;
                    }
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * ¿La IP es privada, reservada o de loopback? (Puro, sin red: es el núcleo
     * de la defensa, así que se prueba con la tabla entera.)
     */
    public static function isBlockedIp(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return true; // no es una IP: no se arriesga
        }

        // IPv4 mapeada en IPv6 (::ffff:127.0.0.1) — un bypass clásico. Se
        // comprueba como IPv4, con los 4 últimos bytes YA empaquetados: pasar la
        // IP en texto haría que matchesCidr() comparara 16 bytes contra 4 y no
        // casara nunca (y la(mapped) pasaría como pública).
        if (strlen($packed) === 16) {
            $mappedV4 = self::mappedV4Packed($packed);
            if ($mappedV4 !== null) {
                return self::matchesCidr($mappedV4, self::BLOCKED_V4);
            }
        }

        $cidrs = strlen($packed) === 4 ? self::BLOCKED_V4 : self::BLOCKED_V6;
        return self::matchesCidr($packed, $cidrs);
    }

    /**
     * POST con la IP fijada (la ya validada) y el Host/SNI del nombre real, para
     * no caer en la ventana TOCTOU entre validar y conectar.
     */
    private static function post(array $target, string $body, string $url): bool
    {
        $scheme = $target['scheme'];
        $ip = $target['ip'];
        $port = $target['port'];
        $hostHeader = $target['host']
            . (($scheme === 'http' && $port !== 80) || ($scheme === 'https' && $port !== 443)
                ? ':' . $port
                : '');

        $context = stream_context_create([
            'ssl' => [
                // Conectar a la IP pero validar el certificado contra el
                // NOMBRE: si no, el TLS de un dominio público no encajaría.
                'peer_name'          => $target['host'],
                'SNI_enabled'        => true,
                'verify_peer'        => true,
                'verify_peer_name'   => true,
                'allow_self_signed'  => false,
                'disable_compression' => true,
            ],
            'socket' => ['tcp_nodelay' => true],
        ]);

        $transport = ($scheme === 'https' ? 'ssl' : 'tcp') . '://' . $ip . ':' . $port;
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $transport,
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            self::log('Webhook: no se pudo conectar a ' . $ip . ':' . $port . ' (' . $errstr . ')');
            return false;
        }

        stream_set_timeout($socket, self::IO_TIMEOUT);

        $request = 'POST ' . $target['path'] . " HTTP/1.1\r\n"
            . 'Host: ' . $hostHeader . "\r\n"
            . "User-Agent: VanillaForms/1.0\r\n"
            . "Content-Type: application/json; charset=utf-8\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . "Connection: close\r\n"
            . "Accept: */*\r\n"
            . "\r\n"
            . $body;

        $written = @fwrite($socket, $request);
        if ($written === false || $written < strlen($request)) {
            @fclose($socket);
            return false;
        }

        // Fire-and-forget: no interesa la respuesta, pero hay que leer hasta
        // que cierre para no dejar el socket colgado ni cortar a la mitad.
        @stream_get_contents($socket, 8192);
        @fclose($socket);

        return true;
    }

    /**
     * Compara una IP (packed) contra una lista de CIDR.
     *
     * @param string $packed inet_pton de la IP
     * @param string[] $cidrs
     */
    private static function matchesCidr(string $packed, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            [$net, $bits] = explode('/', $cidr, 2);
            $netPacked = @inet_pton($net);
            if ($netPacked === false) {
                continue;
            }
            $bits = (int) $bits;
            $len = strlen($netPacked);
            if ($bits > $len * 8) {
                continue;
            }
            $bytes = intdiv($bits, 8);
            $rest = $bits % 8;
            if ($bytes > 0 && strncmp($packed, $netPacked, $bytes) !== 0) {
                continue;
            }
            if ($rest === 0) {
                return true;
            }
            $mask = 0xff << (8 - $rest) & 0xff;
            if ((ord($packed[$bytes]) & $mask) === (ord($netPacked[$bytes]) & $mask)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Si los 16 bytes son una IPv4 mapeada (::ffff:a.b.c.d) devuelve esa IPv4
     * YA EMPAQUETADA en 4 bytes; si no, null.
     */
    private static function mappedV4Packed(string $packed16): ?string
    {
        $prefix = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff";
        if (strncmp($packed16, $prefix, 12) !== 0) {
            return null;
        }
        return substr($packed16, 12);
    }

    private static function log(string $message): void
    {
        error_log('[Webhook] ' . $message);
    }
}