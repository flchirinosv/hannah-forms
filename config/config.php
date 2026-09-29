<?php

declare(strict_types=1);

/**
 * Configuracion de VanillaForms.
 *
 * Los valores se leen de variables de entorno (.env, cargado por App\Core\Env)
 * con fallback a valores por defecto para desarrollo local. No se deben
 * hardcodear credenciales reales aqui; usar .env en produccion.
 */

return [
    'app' => [
        'name' => App\Core\Env::get('APP_NAME', 'VanillaForms'),
        'url'  => App\Core\Env::get('APP_URL', 'http://127.0.0.1:8080'),
        // URL pública para el enlace que se copia, el QR y el embed. VACÍO = se
        // deduce de la petición actual (esquema + Host), que es lo correcto en
        // una instalación self-hosted normal; fíjala (APP_PUBLIC_URL) cuando hay
        // un proxy, varios dominios o cualquier cosa que el Host no refleje.
        // Antes de esto, estos tres usos salían SIEMPRE de `app.url`, así que
        // servir la app en otro host producía enlaces y QR apuntando a
        // 127.0.0.1. Ver App\Core\PublicUrl.
        'public_url' => App\Core\Env::get('APP_PUBLIC_URL', ''),
        'env'  => App\Core\Env::get('APP_ENV', 'development'),
        // Solo true si la app está detrás de un proxy de confianza que
        // sobrescribe X-Forwarded-For / X-Real-IP (nginx/haproxy).
        'trust_proxy' => (bool) App\Core\Env::get('TRUST_PROXY', false),
        // Límite de cuerpo JSON (bytes) para endpoints como builder/save.
        'max_json_bytes' => (int) App\Core\Env::get('MAX_JSON_BYTES', 2097152),
        // Content-Security-Policy. El default es una política segura, no vacío:
        // antesvenía vacía "porque las vistas usaban <script> inline", pero ya
        // no hay ninguno (todo se externaliza; ver AGENTS.md, P9 plantillas) y
        // el único handler en línea que quedaba (el botón de imprimir) se movió
        // a public/assets/js/print.js. Con el default vacío, una instalación
        // limpia se quedaba SIN CSP y eso convertía cualquier inyección de HTML
        // en XSS con total libertad.
        //
        // `style-src 'unsafe-inline'` es deliberado: Bootstrap y varias vistas
        // usan atributos style="" y un <style> en la vista imprimible.
        // Para desactivarla de verdad hay que ponerla vacío explícitamente
        // (CSP_POLICY=), no basta con no configurarla.
        'csp_policy' => (string) App\Core\Env::get(
            'CSP_POLICY',
            "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
            . "img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; "
            . "frame-ancestors 'none'; base-uri 'self'; form-action 'self'"
        ),
        // X-Frame-Options: DENY | SAMEORIGIN | vacío (para embeber formularios).
        'frame_options' => (string) App\Core\Env::get('FRAME_OPTIONS', 'DENY'),
        // B2 — Cloudflare Turnstile (anti-bot opt-in por formulario). Sin claves
        // la instancia NO sirve formularios que lo pidan: los bloquea en el
        // fill con un aviso (nunca abrir un formulario "protegido" sin protección).
        'turnstile' => [
            'site_key'   => (string) App\Core\Env::get('TURNSTILE_SITE_KEY', ''),
            'secret_key' => (string) App\Core\Env::get('TURNSTILE_SECRET_KEY', ''),
        ],
        // Webhooks a la red local (fase 2, item 2.2). Este es el opt-in del
        // SERVIDOR y es el que cierra el SSRF: aunque el dueño de un formulario
        // mande `webhook.allow_private_hosts: true` en sus settings, sin esto
        // aucun webhook alcanza loopback/RFC1918/link-local. Lo pone quien opera
        // la instancia, no quien edita un formulario (ver App\Services\Webhook).
        'webhook' => [
            'allow_private_hosts' => (bool) App\Core\Env::get('WEBHOOK_ALLOW_PRIVATE_HOSTS', false),
        ],
        // F5.1 — Allowlist de hosts para `thanks.redirect_url` (post-submit).
        //
        // Vacía por defecto = NINGUNA URL externa se sigue tras el envío: el
        // thanks interno se muestra con un aviso. Quien opera la instancia la
        // habilita con APP_REDIRECT_ALLOWLIST=host1,host2 (lista separada por
        // comas). Es un opt-in SERVIDOR: un formulario solo puede declarar una
        // URL, nunca ampliar esta lista, o era un redirect abierto usable como
        // trampolín de phishing hacia cualquier dominio.
        'redirect_allowlist' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) App\Core\Env::get('APP_REDIRECT_ALLOWLIST', ''))
        ))),
        // F5.3 — Límites del dashboard por USUARIO (crear formularios /
        // exportar datos): evita que una cuenta sirva para spamear formularios
        // o barrer el almacén con exportaciones. Ventana de 1 h (la más larga
        // de la app: véase `RateLimiter::MAX_WINDOW`, que se subió por esto).
        'limits' => [
            'create_per_hour' => (int) App\Core\Env::get('DASH_CREATE_PER_HOUR', 30),
            'export_per_hour' => (int) App\Core\Env::get('DASH_EXPORT_PER_HOUR', 120),
        ],
        // Idioma por defecto de la app (P9, i18n reducido): es | en.
        // Un formulario puede sobrescribirlo con settings.locale.code.
        'locale' => (string) App\Core\Env::get('APP_LOCALE', 'es'),
        // Zona horaria de PRESENTACIÓN (fase 3, zona horaria canónica).
        //
        // El contrato de datos es UTC en las dos puntas: la sesión de MariaDB
        // hace `SET time_zone = '+00:00'` (ver App\Core\Database) y PHP corre
        // con `date_default_timezone_set('UTC')`, o sea que lo que sale de la
        // BD se interpreta como UTC, que es lo que la BD escribió. Esta clave
        // es la única que convierte a la zona en la que se LEE.
        //
        // Antes: PHP corría en America/Mexico_City e imprimía el string de la
        // BD tal cual, así que un texto con la hora guardada se veía bien y
        // cualquier aritmética sobre él (un "hace 2 horas", un filtro de día, un
        // `DATE()`) iba 6 h desviado. Caracas es UTC-4 y Mexico City UTC-6, así
        // que además la hora mostrada era 2 h incorrecta para el lector local.
        'timezone' => (string) App\Core\Env::get('APP_TIMEZONE', 'America/Caracas'),
        // P10 — Uploads de archivos (límites globales; un campo solo puede
        // restringirlos, nunca ampliarlos). Ver app/Services/Uploader.php.
        'uploads' => [
            'max_size_mb' => (int) App\Core\Env::get('UPLOAD_MAX_SIZE_MB', 10),
            'max_files'   => (int) App\Core\Env::get('UPLOAD_MAX_FILES', 5),
        ],
    ],

    'database' => [
        'host'     => App\Core\Env::get('DB_HOST', '127.0.0.1'),
        'port'     => (int) App\Core\Env::get('DB_PORT', 3306),
        'name'     => App\Core\Env::get('DB_NAME', 'vanilla_forms'),
        'user'     => App\Core\Env::get('DB_USER', 'mariadb'),
        'password' => App\Core\Env::get('DB_PASSWORD', ''),
        'charset'  => App\Core\Env::get('DB_CHARSET', 'utf8mb4'),
    ],

    'session' => [
        'name'     => App\Core\Env::get('SESSION_NAME', 'vanillaforms_session'),
        'lifetime' => (int) App\Core\Env::get('SESSION_LIFETIME', 7200),
        'path'     => '/',
        'secure'   => (bool) App\Core\Env::get('SESSION_SECURE', false),
        'httponly' => (bool) App\Core\Env::get('SESSION_HTTPONLY', true),
        'samesite' => App\Core\Env::get('SESSION_SAMESITE', 'Lax'),
    ],

    'mail' => [
        // Servidor SMTP para notificaciones y confirmaciones (P5).
        // Con MAIL_HOST vacío el envío se omite y las notificaciones quedan
        // registradas en el log, sin romper el flujo de envío de respuestas.
        'host'         => (string) App\Core\Env::get('MAIL_HOST', ''),
        'port'         => (int) App\Core\Env::get('MAIL_PORT', 587),
        'encryption'   => (string) App\Core\Env::get('MAIL_ENCRYPTION', 'tls'), // '' | tls | ssl
        'user'         => (string) App\Core\Env::get('MAIL_USER', ''),
        'password'     => (string) App\Core\Env::get('MAIL_PASSWORD', ''),
        'from_address' => (string) App\Core\Env::get('MAIL_FROM_ADDRESS', ''),
        'from_name'    => (string) App\Core\Env::get('MAIL_FROM_NAME', 'VanillaForms'),
        'timeout'      => (int) App\Core\Env::get('MAIL_TIMEOUT', 15),
    ],

    'paths' => [
        'base'    => dirname(__DIR__),
        'public'  => dirname(__DIR__) . '/public',
        'app'     => dirname(__DIR__) . '/app',
        'views'   => dirname(__DIR__) . '/views',
        'config'  => dirname(__DIR__) . '/config',
        'storage' => dirname(__DIR__) . '/storage',
        // P9 — Plantillas JSON de arranque (crear formulario desde plantilla).
        'templates' => dirname(__DIR__) . '/database/templates',
        // P9 — Catálogos de i18n (es/en) del fill público y correos.
        'lang'     => dirname(__DIR__) . '/app/lang',
        // P10 — Adjuntos de los campos de tipo `file`. SIEMPRE fuera de public/
        // (nunca servible por el web server): se descargan por
        // AttachmentController con autorización y headers de descarga.
        'uploads'  => dirname(__DIR__) . '/storage/uploads',
    ],
];
