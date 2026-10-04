<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Configuración por formulario (`forms.settings`, JSON).
 *
 * Fuente única para: programación (schedule), cupo (quota), notificaciones
 * email (notify), webhook, mensaje de gracias (thanks), quiz, randomización,
 * embed, honeypot, tema (theme) e idioma (locale). Todos los consumidores
 * (FillController, Notifier, webhook, embed…) pasan por aquí para leer el
 * mismo esquema con defaults y sin claves desconocidas.
 *
 * normalize(): limpia/coeerciona contra un whitelist y aplica defaults.
 * validate():   devuelve el primer error de entrada como string (o null).
 * merge():      fusiona un PARCHE sobre lo que ya había, por bloques.
 */
final class FormSettings
{
    public const LOCALES = ['es', 'en'];

    /**
     * F4 — modos de acceso, en orden de apertura: cada uno es "todo lo anterior
     * más esto". La lista es pública porque la comparten la normalización (lo que
     * entra), el gate del fill (lo que sale) y las vistas.
     */
    public const ACCESS_PUBLIC    = 'public';
    public const ACCESS_EMAIL     = 'email';
    public const ACCESS_ALLOWLIST = 'allowlist';
    public const ACCESS_ROSTER    = 'roster';

    public const ACCESS_MODES = [
        self::ACCESS_PUBLIC,
        self::ACCESS_EMAIL,
        self::ACCESS_ALLOWLIST,
        self::ACCESS_ROSTER,
    ];

    /**
     * Columnas de `form_participants` que pueden ser la identidad del
     * participante, en el orden en que se ofrecen.
     *
     * Los tres son los que ya tienen columna propia en la tabla: `cedula` e
     * `indicador` se rellenan a mano y `correo` es el único que además sirve para
     * mandar el enlace por correo. Añadir un cuarto sería añadir una columna, y el
     * identificador está **denormalizado** en `identifier_value` precisamente para
     * que la puerta del fill busque siempre en la misma columna indexada.
     */
    public const ACCESS_IDENTIFIERS = ['cedula', 'indicador', 'correo'];

    /**
     * El fill pide identificación con estos tres.
     *
     * `roster` entró aquí **en el mismo commit** que su puerta en el
     * `FillController` (fase 4), y no un commit antes: un `roster` en
     * `ACCESS_MODES` pero fuera de esta lista se sirve **abierto**, y es el fallo
     * que no da ningún error ni en el log ni en la respuesta.
     *
     * Lo que sigue sin aparecer es el `<option>` en el selector de modos (fase 6),
     * así que hoy un formulario en `roster` solo se alcanza si alguien edita sus
     * ajustes a mano o viene de una fase anterior.
     */
    public const RESTRICTED_MODES = [
        self::ACCESS_EMAIL,
        self::ACCESS_ALLOWLIST,
        self::ACCESS_ROSTER,
    ];

    public static function defaults(): array
    {
        return [
            'theme'     => ['primary' => null, 'background' => null, 'font' => null],
            'schedule'  => ['open_at' => null, 'close_at' => null],
            'quota'     => ['enabled' => false, 'max' => 0],
            'notify'    => [
                'enabled'      => false,
                'emails'       => [],
                'confirmation' => [
                    'enabled'  => false,
                    'subject'  => '',
                    'message'  => '',
                    'to_field' => null,
                ],
            ],
            'webhook'    => ['url' => '', 'allow_private_hosts' => false, 'kind' => 'generic', 'secret' => ''],
            'thanks'     => ['message' => '', 'redirect_url' => ''],
            'quiz'       => ['enabled' => false],
            'randomize'  => ['questions' => false],
            'embed'      => ['enabled' => false],
            'honeypot'   => ['enabled' => true],
            'anti_bot'   => ['enabled' => false],
            // F3 — revisión/aprobación. `enabled` es OPT-IN por formulario a
            // propósito: si la revisión fuese el comportamiento por defecto,
            // todos los formularios existentes dejarían de poder publicarse sin
            // un revisor, y en una instancia de un solo usuario (el caso normal
            // de un self-hosted) la revisión sería teatro que bloquea al
            // propietario de su propia herramienta.
            'review'     => ['enabled' => false, 'notify_email' => false],
            // F4 — acceso al formulario. `public` es el default porque es el
            // comportamiento histórico: poner esto en `email` o `allowlist` por
            // defecto cerraría de golpe todos los formularios ya publicados, y
            // una actualización no puede dejar de servir algo que ayer servía.
            // La lista de emails NO va aquí (está en `form_access_emails`): la
            // revoca un borrado en cascada de sus tokens, y eso es SQL, no un
            // array que reescribir entero en cada cambio.
            'access'     => ['mode' => self::ACCESS_PUBLIC, 'identifier' => 'cedula'],
            // F5 — avance automático entre páginas. OPT-IN por formulario, y no
            // por un motivo distinto del de las demás banderas: un salto de página
            // automático es la clase de detalle que molesta si aparece sin
            // pedirlo, y en un formulario de una sola pregunta la opción no hace
            // nada. Quien lo active lo hace porque su formulario encaja con ello.
            'advance'    => ['auto' => false],
            'locale'     => ['code' => 'es'],
        ];
    }

    /**
     * Normaliza un arreglo crudo (JSON de BD o del builder) contra el esquema.
     */
    public static function normalize(array $raw): array
    {
        $out = self::defaults();

        if (isset($raw['theme']) && is_array($raw['theme'])) {
            foreach (['primary', 'background', 'font'] as $k) {
                if (array_key_exists($k, $raw['theme'])) {
                    $v = $raw['theme'][$k];
                    $out['theme'][$k] = is_string($v) ? self::short(trim($v), 40) : null;
                }
            }
            // Una familia tipográfica no es texto libre: va directa a un
            // `font-family` de un <style> que el visitante carga en el fill
            // público. Sin patrón, `;`/`}`/`url(` permiten inyectar
            // declaraciones CSS nuevas (y con ellas, beacons y fugas por CSS).
            // Se descarta aquí, al normalizar: es la puerta por la que pasa
            // todo lo que llega del builder o de un POST de settings.
            if ($out['theme']['font'] !== null && !self::isValidFontFamily((string) $out['theme']['font'])) {
                $out['theme']['font'] = null;
            }
        }

        if (isset($raw['schedule']) && is_array($raw['schedule'])) {
            foreach (['open_at', 'close_at'] as $k) {
                if (array_key_exists($k, $raw['schedule'])) {
                    $v = $raw['schedule'][$k];
                    $out['schedule'][$k] = is_string($v) ? self::short(trim($v), 40) : null;
                }
            }
        }

        if (isset($raw['quota']) && is_array($raw['quota'])) {
            $out['quota']['enabled'] = (bool) ($raw['quota']['enabled'] ?? false);
            $out['quota']['max'] = max(0, min(1000000, (int) ($raw['quota']['max'] ?? 0)));
        }

        if (isset($raw['notify']) && is_array($raw['notify'])) {
            $n = $raw['notify'];
            $out['notify']['enabled'] = (bool) ($n['enabled'] ?? false);
            if (isset($n['emails']) && is_array($n['emails'])) {
                $emails = [];
                foreach ($n['emails'] as $e) {
                    if (!is_string($e)) {
                        continue;
                    }
                    $e = trim($e);
                    if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) && count($emails) < 10) {
                        $emails[] = $e;
                    }
                }
                $out['notify']['emails'] = array_values(array_unique($emails));
            }
            if (isset($n['confirmation']) && is_array($n['confirmation'])) {
                $c = $n['confirmation'];
                $out['notify']['confirmation']['enabled'] = (bool) ($c['enabled'] ?? false);
                $out['notify']['confirmation']['subject'] = is_string($c['subject'] ?? null)
                    ? self::short(trim($c['subject']), 190) : '';
                $out['notify']['confirmation']['message'] = is_string($c['message'] ?? null)
                    ? self::short(trim($c['message']), 2000) : '';
                $out['notify']['confirmation']['to_field'] = is_string($c['to_field'] ?? null)
                    ? self::short(trim($c['to_field']), 190) : null;
            }
        }

        if (isset($raw['webhook']) && is_array($raw['webhook'])) {
            $w = $raw['webhook']['url'] ?? '';
            $out['webhook']['url'] = is_string($w) ? self::short(trim($w), 500) : '';
            // SSRF: el destino se valida y se bloquean las IP privadas/reservadas
            // (loopback, RFC1918, 169.254.169.254 de la metadata…). Esta bandera
            // es el opt-in explícito para apuntar a un servicio de la red local
            // propia. OJO: la clave tiene que estar aquí o normalize() la
            // borraría y el opt-in nunca llegaría a Webhook.
            $out['webhook']['allow_private_hosts'] = !empty($raw['webhook']['allow_private_hosts']);
            // B1 — tipo de destino: 'generic' (payload JSON crudo) o 'slack'
            // (mensaje de la API de incoming-webhooks de Slack).
            $kind = $raw['webhook']['kind'] ?? 'generic';
            $out['webhook']['kind'] = in_array($kind, ['generic', 'slack'], true) ? $kind : 'generic';
            // B1 — secreto opcional: si se fija, el POST viaja firmado (HMAC-SHA256
            // del body) en `X-VanillaForms-Signature` para que el receptor pueda
            // verificar que la petición es de esta instancia.
            $secret = $raw['webhook']['secret'] ?? '';
            $out['webhook']['secret'] = is_string($secret) ? self::short(trim($secret), 190) : '';
        }

        if (isset($raw['thanks']) && is_array($raw['thanks'])) {
            $t = $raw['thanks'];
            $out['thanks']['message'] = is_string($t['message'] ?? null)
                ? self::short(trim($t['message']), 2000) : '';
            $out['thanks']['redirect_url'] = is_string($t['redirect_url'] ?? null)
                ? self::short(trim($t['redirect_url']), 500) : '';
        }

        foreach (['quiz', 'randomize', 'embed', 'honeypot', 'anti_bot'] as $k) {
            if (isset($raw[$k]) && is_array($raw[$k])) {
                $out[$k]['enabled'] = (bool) ($raw[$k]['enabled'] ?? false);
            }
        }
        if (isset($raw['randomize']) && is_array($raw['randomize'])) {
            $out['randomize']['questions'] = (bool) ($raw['randomize']['questions'] ?? false);
        }

        // F3 — el bloque `review` NO entra en el foreach de arriba porque tiene
        // dos claves, no una: `enabled` (este formulario exige aprobación) y
        // `notify_email` (avisar por correo a los revisores). El aviso es
        // independiente del toggle a propósito: se puede querer el correo sin
        // revisión, yviceversa no tiene sentido (no hay a quién avisar).
        if (isset($raw['review']) && is_array($raw['review'])) {
            $out['review']['enabled'] = (bool) ($raw['review']['enabled'] ?? false);
            $out['review']['notify_email'] = (bool) ($raw['review']['notify_email'] ?? false);
        }

        // F5 — el avance automático es una clave y no un `enabled` del foreach de
        // arriba, porque su nombre de bloque (`auto`) describe lo que hace y no
        // si el bloque está activo: se lee `[advance][auto]`, no
        // `[advance][enabled]`.
        if (isset($raw['advance']) && is_array($raw['advance'])) {
            $out['advance']['auto'] = (bool) ($raw['advance']['auto'] ?? false);
        }

        // F4 — el MODO de acceso se normaliza contra la lista blanca. Cualquier
        // valor desconocido cae en `public`, que es el default histórico: una
        // fila corrupta deja de pedir identificación en vez de dejar el
        // formulario cerrado para siempre (y se ve al instante, no de forma
        // silenciosa para el visitante).
        if (isset($raw['access']) && is_array($raw['access']) && isset($raw['access']['mode'])) {
            $mode = (string) $raw['access']['mode'];
            $out['access']['mode'] = in_array($mode, self::ACCESS_MODES, true) ? $mode : self::ACCESS_PUBLIC;
        }

        // El identificador se lee junto al modo pero se guarda SIEMPRE, incluso en
        // `public`: es el valor por defecto al que se vuelve, y borrarlo en cada
        // cambio de modo haría que «cambiar a público y volver» perdiera la
        // elección. Un valor desconocido cae en `cedula` por el mismo motivo que
        // el modo cae en `public`.
        if (isset($raw['access']) && is_array($raw['access']) && isset($raw['access']['identifier'])) {
            $identificador = (string) $raw['access']['identifier'];
            $out['access']['identifier'] = in_array($identificador, self::ACCESS_IDENTIFIERS, true)
                ? $identificador
                : 'cedula';
        }

        if (isset($raw['locale']) && is_array($raw['locale']) && isset($raw['locale']['code'])) {
            $code = (string) $raw['locale']['code'];
            $out['locale']['code'] = in_array($code, self::LOCALES, true) ? $code : 'es';
        }

        return $out;
    }

    /**
     * Fusiona un PARCHE sobre los ajustes que ya había, por bloques de primer
     * nivel, y devuelve el resultado normalizado.
     *
     * Existe porque hay DOS puertas que escriben `forms.settings` y ninguna de
     * las dos se traga el array entero:
     *
     *  - el builder, con la estructura y la pestaña de ajustes abiertos, y
     *  - la ficha del formulario (`POST /forms/{id}/settings`), que solo trae
     *    los bloques operativos: acceso, agenda, cupo, avisos, webhook, gracias,
     *    revisión e idioma.
     *
     * `normalize()` arranca siempre de `defaults()`, así que guardar un
     * subconjunto con `Form::saveSettings()` PONDRÍA EN DEFAULT todo lo demás: el
     * color del tema volvería al de fábrica al cambiar el cupo. Con este
     * `array_replace_recursive` el parche solo pisa lo que trae.
     *
     * El parche se RECORTA a `$allowed` antes de fusionar, y no después: es la
     * puerta, no una comprobación de TResultado. Un bloque que no esté en la
     * lista no se guarda aunque venga en el POST.
     *
     * @param array<string> $allowed bloques que esta puerta puede escribir
     */
    public static function merge(array $current, array $patch, array $allowed = []): array
    {
        $recortado = [];
        foreach ($patch as $bloque => $valor) {
            if (!is_array($valor)) {
                continue;
            }
            if ($allowed !== [] && !in_array((string) $bloque, $allowed, true)) {
                continue;
            }
            $recortado[$bloque] = $valor;
        }

        $base = is_array($current) ? $current : [];
        foreach ($recortado as $bloque => $valor) {
            $previo = $base[$bloque] ?? null;
            $base[$bloque] = is_array($previo)
                ? array_replace_recursive($previo, $valor)
                : $valor;
        }

        return self::normalize($base);
    }

    /** Bloques que escribe la ficha del formulario (`/forms/{id}`). */
    public const DASHBOARD_BLOCKS = [
        'access', 'schedule', 'quota', 'notify', 'webhook', 'thanks', 'review', 'locale',
    ];

    /**
     * Bloques que escribe el constructor. Los operativos se editan en la ficha
     * del formulario, así que la lista es la complemento de `DASHBOARD_BLOCKS`
     * y entre las dos cubren `defaults()`: lo que se guarda es siempre el
     * conjunto completo, solo que repartido en dos POST.
     */
    public const BUILDER_BLOCKS = [
        'theme', 'quiz', 'randomize', 'advance', 'embed', 'honeypot', 'anti_bot',
    ];

    /**
     * Los mismos bloques, pero traducidos desde campos planos de un formulario
     * HTML (`access_mode`, `open_at`…). Un POST de navegador manda
     * `notify[confirmation][to_field]`, así que los nombres del DOM son los del
     * esquema: no hace falta un mapa de sinónimos y cada anidamiento es el que
     * lee `normalize()`.
     *
     * Solo se traduce lo que VIENE en el POST: una casilla sin marcar no es un
     * «vacío» que apagaría el bloque, es una clave que no existe. Por eso el
     * `<input type=checkbox>` del constructor es un `hidden` con el mismo
     * `name` delante — sin él, desmarcar una casilla no enviaría nada y el
     * ajuste se quedaría como estaba en vez de apagarse.
     */
    public static function fromDashboardPost(array $post): array
    {
        $enviado = static function (string $key) use ($post): bool {
            return array_key_exists($key, $post);
        };

        $patch = [];

        // --- acceso ---
        // El identificador viaja aunque el modo no cambie: el `<select>` del
        // identificador se manda siempre junto al del modo, y separarlos con dos
        // `if` pondría el segundo en el parche con una condición que no depende
        // del primero. Se decide con `$enviado`, no con «si no viene, no lo toques»:
        // es el mismo criterio del resto del parche.
        if ($enviado('access_mode') || $enviado('access_identifier')) {
            $patch['access'] = array_filter([
                'mode'       => $enviado('access_mode') ? (string) ($post['access_mode'] ?? '') : null,
                'identifier' => $enviado('access_identifier') ? (string) ($post['access_identifier'] ?? '') : null,
            ], static fn($v): bool => $v !== null);
        }

        // --- programación y cupo ---
        // El `datetime-local` del navegador manda `2026-11-01T09:00` y lo
        // guardado lleva espacio. Se traduce aquí y no en `normalize()` porque
        // es un formato de TRANSPORTE (el `T` es del input), no del esquema.
        $reloj = static function (string $v): string {
            return trim(str_replace('T', ' ', $v));
        };

        $schedule = [];
        if ($enviado('open_at')) {
            $schedule['open_at'] = $reloj((string) ($post['open_at'] ?? ''));
        }
        if ($enviado('close_at')) {
            $schedule['close_at'] = $reloj((string) ($post['close_at'] ?? ''));
        }
        if ($schedule !== []) {
            $patch['schedule'] = $schedule;
        }

        $quota = [];
        if ($enviado('quota_enabled')) {
            $quota['enabled'] = (string) ($post['quota_enabled'] ?? '') === '1';
        }
        if ($enviado('quota_max')) {
            $quota['max'] = trim((string) ($post['quota_max'] ?? '')) === ''
                ? 0
                : (int) $post['quota_max'];
        }
        if ($quota !== []) {
            $patch['quota'] = $quota;
        }

        // --- avisos y confirmación ---
        $notify = [];
        if ($enviado('notify_enabled')) {
            $notify['enabled'] = (string) ($post['notify_enabled'] ?? '') === '1';
        }
        if ($enviado('notify_emails')) {
            // El campo es un texto con comas; `normalize()` es quien descarta los
            // que no son correos válidos y corta a los 10 primeros.
            $partes = preg_split('/[,;\s]+/', (string) ($post['notify_emails'] ?? '')) ?: [];
            $notify['emails'] = array_values(array_filter(
                array_map('trim', $partes),
                static fn(string $e): bool => $e !== ''
            ));
        }
        if ($enviado('confirm_enabled')) {
            $notify['confirmation'] = [
                'enabled' => (string) ($post['confirm_enabled'] ?? '') === '1',
            ];
        }
        if ($enviado('confirm_to_field')) {
            $notify['confirmation']['to_field'] = trim((string) ($post['confirm_to_field'] ?? ''));
        }
        if ($enviado('confirm_subject')) {
            $notify['confirmation']['subject'] = (string) ($post['confirm_subject'] ?? '');
        }
        if ($enviado('confirm_message')) {
            $notify['confirmation']['message'] = (string) ($post['confirm_message'] ?? '');
        }
        if ($notify !== []) {
            $patch['notify'] = $notify;
        }

        // --- webhook ---
        $webhook = [];
        if ($enviado('webhook_url')) {
            $webhook['url'] = trim((string) ($post['webhook_url'] ?? ''));
        }
        if ($enviado('webhook_kind')) {
            $webhook['kind'] = (string) ($post['webhook_kind'] ?? '');
        }
        if ($enviado('webhook_secret')) {
            $webhook['secret'] = (string) ($post['webhook_secret'] ?? '');
        }
        if ($enviado('webhook_allow_private')) {
            $webhook['allow_private_hosts'] = (string) ($post['webhook_allow_private'] ?? '') === '1';
        }
        if ($webhook !== []) {
            $patch['webhook'] = $webhook;
        }

        // --- final ---
        $thanks = [];
        if ($enviado('thanks_message')) {
            $thanks['message'] = (string) ($post['thanks_message'] ?? '');
        }
        if ($enviado('thanks_redirect')) {
            $thanks['redirect_url'] = trim((string) ($post['thanks_redirect'] ?? ''));
        }
        if ($thanks !== []) {
            $patch['thanks'] = $thanks;
        }

        // --- revisión ---
        $review = [];
        if ($enviado('review_enabled')) {
            $review['enabled'] = (string) ($post['review_enabled'] ?? '') === '1';
        }
        if ($enviado('review_notify_email')) {
            $review['notify_email'] = (string) ($post['review_notify_email'] ?? '') === '1';
        }
        if ($review !== []) {
            $patch['review'] = $review;
        }

        // --- idioma ---
        if ($enviado('locale')) {
            $patch['locale'] = ['code' => (string) ($post['locale'] ?? '')];
        }

        return $patch;
    }

    /**
     * Valida la entrada (antigua al normalize) y devuelve un mensaje de error
     * o null si todo es válido. Se usa en el builder para responder 422.
     */
    public static function validate(array $raw): ?string
    {
        $n = self::normalize($raw);

        foreach (['open_at', 'close_at'] as $k) {
            $v = $n['schedule'][$k];
            if ($v !== null && $v !== '' && preg_match(
                '/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?)?$/',
                $v
            ) !== 1) {
                return 'La fecha programada "' . $k . '" no tiene un formato válido (YYYY-MM-DD o con hora).';
            }
        }
        if ($n['schedule']['open_at'] !== null && $n['schedule']['close_at'] !== null
            && $n['schedule']['open_at'] !== '' && $n['schedule']['close_at'] !== ''
            && strtotime((string) $n['schedule']['open_at']) > strtotime((string) $n['schedule']['close_at'])) {
            return 'La fecha de apertura no puede ser posterior a la de cierre.';
        }

        if ($n['quota']['enabled'] && $n['quota']['max'] < 1) {
            return 'El cupo máximo debe ser un número mayor que cero.';
        }

        // F4 — el modo crudo se mira a propósito, igual que la tipografía: si
        // solo se mirase el normalizado, un modo inválido se habría convertido
        // en `public` y el que lo escribió se quedaría sin saber que su
        // formulario acaba de quedar abierto para todo el mundo.
        $rawMode = $raw['access']['mode'] ?? null;
        if (is_string($rawMode) && $rawMode !== '' && !in_array($rawMode, self::ACCESS_MODES, true)) {
            return 'El modo de acceso debe ser "public", "email", "allowlist" o "roster".';
        }

        // El identificador se mira CRUDO y no el normalizado, por la misma razón
        // que el resto de `validate()`: `normalize()` convierte un valor
        // desconocido en `cedula`, así que mirar el normalizado no distinguiría
        // «el dueño eligió cédula» de «el dueño mandó basura».
        $rawIdentifier = $raw['access']['identifier'] ?? null;
        if (is_string($rawIdentifier) && $rawIdentifier !== ''
            && !in_array($rawIdentifier, self::ACCESS_IDENTIFIERS, true)) {
            return 'El identificador del participante debe ser "cedula", "indicador" o "correo".';
        }

        if ($n['notify']['enabled'] && $n['notify']['emails'] === []) {
            return 'Activa al menos un correo para notificar.';
        }

        foreach (['url'] as $k) {
            $u = $n['webhook'][$k];
            if ($u !== '' && self::invalidUrl($u)) {
                return 'La URL del webhook no es válida (debe ser http/https).';
            }
        }
        if (!in_array($n['webhook']['kind'], ['generic', 'slack'], true)) {
            return 'El tipo de webhook debe ser "generic" o "slack".';
        }

        $r = $n['thanks']['redirect_url'];
        if ($r !== '' && self::invalidUrl($r)) {
            return 'La URL de redirección no es válida (debe ser http/https).';
        }

        foreach (['primary', 'background'] as $k) {
            $c = $n['theme'][$k];
            if ($c !== null && $c !== '' && preg_match('/^#[0-9a-fA-F]{3,8}$/', $c) !== 1) {
                return 'El color de tema "' . $k . '" no es un hex válido (#RRGGBB o #RRGGBBAA).';
            }
        }

        // Se mira el valor CRUDO y no el normalizado: `normalize()` ya lo dejó en
        // null cuando no cumple el patrón, así que comprobar `$n` nunca
        // detectaría nada y quien escribe la tipografía se quedaría sin aviso de
        // que su valor se descartó en silencio. Se aplica el mismo recorte de 40
        // para que lo que se valida sea exactamente lo que se guarda.
        $rawFont = $raw['theme']['font'] ?? null;
        if (is_string($rawFont)) {
            $font = self::short(trim($rawFont), 40);
            if ($font !== '' && !self::isValidFontFamily($font)) {
                return 'La tipografía solo admite letras, números, espacios y los signos - _ . , (una o varias familias separadas por comas).';
            }
        }

        return null;
    }

    /**
     * ¿Es una lista de familias tipográficas legítima?
     *
     * Lista blanca, no solo de blacklist: lo que se acepta es lo que se puede
     * escribir como `font-family` sin cambiar de contexto. Se permiten coma
     * (familias alternativas), espacio, punto, guion y guion bajo, que es lo que
     * cubre desde `Inter` hasta `Source Sans 3, sans-serif`. Quedan fuera
     * comillas, paréntesis, punto y coma, llaves, barra, barra invertida,
     * dos puntos, coma... y por tanto `url(`, `@import` y el cierre de la
     * declaración con `;`.
     *
     * OJO: el mismo filtro se aplica en `themeCss()` al emitir, porque puede
     * haber filas ya guardadas en `forms.settings` de antes de este arreglo:
     * filtrar solo al guardar no las limpia.
     */
    public static function isValidFontFamily(string $font): bool
    {
        // 120 = 40 del recorte de normalize() × 3 familias.
        return preg_match("/^[A-Za-z0-9][A-Za-z0-9 _.,-]{0,119}$/", $font) === 1;
    }

    /**
     * Aplica un tema CSS por formulario (variables) si está definido.
     *
     * El filtro de `font` se repite aquí a propósito, y no por redundancia:
     * esta función lee de `forms.settings`, así que puede recibir una fila
     * guardada antes de que existiera el patrón, y un `<style>` que escapa
     * solo a posteriori sigue siendo una inyección de CSS. Lo que sale de aquí
     * está en el fill público de cualquiera, sin sesión.
     *
     * @param array<string, mixed> $settings settings normalizados
     * @return string CSS a incluir en <style> de la página pública
     */
    public static function themeCss(array $settings): string
    {
        $theme = $settings['theme'] ?? [];
        $vars = [];
        if (isset($theme['primary']) && $theme['primary'] !== '') {
            $vars[] = '--vf-primary:' . $theme['primary'];
        }
        if (isset($theme['background']) && $theme['background'] !== '') {
            $vars[] = '--vf-background:' . $theme['background'];
        }
        if (isset($theme['font']) && $theme['font'] !== '' && self::isValidFontFamily((string) $theme['font'])) {
            // Sin comillas: el patrón no las admite, así que no hacen falta y
            // además su htmlspecialchars previo las convertía en &quot; (que
            // dentro de un <style> no se decodifica, era un nombre de fuente
            // roto). Una familia con espacios es CSS válido sin comillas.
            $vars[] = '--vf-font:' . $theme['font'];
        }
        if ($vars === []) {
            return '';
        }
        return ':root{' . implode(';', array_map('htmlspecialchars', $vars)) . ';}';
    }

    private static function short(string $s, int $max): string
    {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max) : $s;
    }

    private static function invalidUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return true;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return !in_array($scheme, ['http', 'https'], true);
    }
}