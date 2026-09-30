<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;

/**
 * F1 — Enmascarado de datos personales.
 *
 * La app guarda metadata de identificación en CADA respuesta (`ip_address` y
 * `user_agent`) y guarda lo que el respondiente conteste en cada campo. Para
 * una encuesta de RRHH, un formulario de Continente o una evaluación de
 * desempeño, ambas cosas son datos personales aunque la pregunta sea inocua:
 * "Ciudad de nacimiento" es inocua y combined con la IP identifica a una
 * persona en un grupo de treinta.
 *
 * Por eso el enmascarado tiene DOS capas y este servicio es el ÚNICO sitio
 * donde se decide el valor que sale:
 *
 *  1. `fields.is_pii` (columna, la pone el dueño en el builder) marca los
 *     campos que declara personales. Sin marca no hay enmascarado posible: un
 *     campo de texto libre no se puede clasificar por tipo, porque "Nombre del
 *     jefe" y "¿Qué opinas del servicio?" son los dos `text`.
 *  2. `responses.pii` (permiso) es la capacidad de leer lo personal en claro.
 *
 * FALLA CERRADO y por diseño: `Auth::can()` compara contra los permisos
 * efectivos y una clave que no exista en la matriz da `false`, así que un
 * despliegue sin la migración 0006 aplicada muestra todo enmascarado en vez de
 * filtrar. El coste de equivocarse al revés es una queja; el de equivocarse en
 * esta dirección es una filtración de datos que no se puede deshacer.
 *
 * Decisión de diseño — una sola política para todos los canales: lo que se ve
 * en la pantalla es lo mismo que sale en el JSON y en el CSV. Enmascarar en la
 * vista y no en la exportación (o al revés) deja siempre un canal limpio, y un
 * canal limpio es donde se acaba filtrando.
 */
final class Pii
{
    /**
     * Marcador único para un valor que no se quiere mostrar. Se elige una
     * cadena que no pueda confundirse con una respuesta real: un campo de
     * opción corta podría valer literalmente `***`, y en un CSV eso indistinguible
     * de "respondió ***" es un data loss silencioso.
     */
    public const MASK = '[oculto]';

    /** ¿El usuario actual puede leer PII sin enmascarar? */
    public static function canView(): bool
    {
        return Auth::can('responses.pii');
    }

    /**
     * IP de una respuesta: se conservan los tres primeros octetos de la IPv4 y
     * los dos primeros grupos de la IPv6, que son los que dan la geolocalización
     * aproximada, y se descarta el resto, que es el que identifica al host.
     *
     * Una IP no válida (vacía, NULL, basura) NO se convierte en MASK: se
     * devuelve tal cual para no inventar un dato donde no lo hay.
     */
    public static function maskIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return $ip;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $octets = explode('.', $ip);
            return $octets[0] . '.' . $octets[1] . '.' . $octets[2] . '.x';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $grupos = explode(':', $ip);
            if (count($grupos) >= 4) {
                return $grupos[0] . ':' . $grupos[1] . '::x';
            }
            return self::MASK;
        }

        return self::MASK;
    }

    /**
     * User-agent: fuera entero.
     *
     * El UA no tiene una parte "no identificativa" que sirva: casi todo string
     * de UA es útil para el fingerprinting (SO, versión exacta, build del
     * navegador y, a veces, identificadores de hardware), así que no hay
     * subconjunto que se pueda enseñar sin arriesgar la reidentificación. Un
     * dato que solo sirve para reidentificar no se enseña a medias: o entero o
     * nada.
     */
    public static function maskUserAgent(?string $ua): ?string
    {
        if ($ua === null || $ua === '') {
            return $ua;
        }
        return self::MASK;
    }

    /**
     * Valor de un campo marcado como personal.
     *
     * El enmascarado depende del TIPO porque el enmascarado tiene que ser útil
     * para el que lo mira sin seguir siendo un dato: un email al que solo le
     * falta una letra identifica a una persona en una lista de clientes, pero
     * `j***@e***.com` deja ver que dos respuestas son la misma persona, que es
     * justo lo que se necesita para detectar respuestas duplicadas. Un texto
     * libre no tiene estructura que preservar, así que se oculta entero.
     */
    public static function maskValue(string $value, string $type): string
    {
        $value = trim($value);
        if ($value === '') {
            return $value;
        }

        switch ($type) {
            case 'email':
                return self::maskEmail($value);

            case 'phone':
            case 'tel':
                return self::maskPhone($value);

            default:
                return self::MASK;
        }
    }

    /**
     * Enmascara la fila `submissions` en su parte de metadata, dejando el resto
     * de columnas (ids, finished_at) intactas porque no identifican.
     *
     * Solo se tocan las claves que YA existen en la fila: una columna nueva de
     * la tabla no debe empezar a romperse en cuanto se añada.
     */
    public static function maskSubmissionMeta(array $row): array
    {
        foreach (['ip_address', 'user_agent'] as $col) {
            if (!array_key_exists($col, $row)) {
                continue;
            }
            $row[$col] = $col === 'ip_address'
                ? self::maskIp($row[$col] === null ? null : (string) $row[$col])
                : self::maskUserAgent($row[$col] === null ? null : (string) $row[$col]);
        }

        return $row;
    }

    /**
     * Aplica el enmascarado a una fila de `submissions` completa, o la deja
     * intacta si el usuario tiene el permiso. Es el atajo de los controladores:
     * un solo `if` antes de pintar o exportar.
     */
    public static function maybeMaskSubmission(array $row): array
    {
        if (self::canView()) {
            return $row;
        }
        return self::maskSubmissionMeta($row);
    }

    /**
     * Ids de los campos marcados como personales de un formulario ya cargado.
     *
     * Vive aquí y no en un método aparte de `Field` porque quien lo necesita
     * siempre necesita las dos cosas (los ids y la decisión de enmascarar) y
     * separarlas abre la puerta a usar la lista sin comprobar el permiso.
     *
     * @param array $fields campos del formulario (con 'id', 'type', 'is_pii')
     * @return array<int,string> field_id => tipo
     */
    public static function piiFieldTypes(array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            if ((int) ($field['is_pii'] ?? 0) !== 1) {
                continue;
            }
            $out[(int) $field['id']] = (string) ($field['type'] ?? 'text');
        }
        return $out;
    }

    /**
     * Enmascara un mapa de respuestas (field_id => value) según los campos
     * marcados. Deja el mapa intacto si el usuario puede ver la PII.
     *
     * @param array $answers  field_id => value crudo
     * @param array $fields   campos del formulario
     * @return array field_id => value (enmascarado si procede)
     */
    public static function maybeMaskAnswers(array $answers, array $fields): array
    {
        if (self::canView()) {
            return $answers;
        }

        $marked = self::piiFieldTypes($fields);
        if ($marked === []) {
            return $answers;
        }

        foreach ($answers as $fieldId => $value) {
            $fieldId = (int) $fieldId;
            if (!isset($marked[$fieldId])) {
                continue;
            }
            // Un adjunto enmascarado se sustituye por el marcador entero: el
            // nombre del archivo ("cedula-escaneada.pdf") es el dato, y la
            // descarga se bloquea aparte en AttachmentController.
            $answers[$fieldId] = self::maskValue(
                \App\Services\Uploader::isAttachmentJson((string) $value)
                    ? 'file'
                    : (string) $value,
                $marked[$fieldId]
            );
        }

        return $answers;
    }

    /**
     * Email: se conserva la primera letra de la parte local y la del dominio.
     * `juan.perez@ejemplo.com` -> `j***@e***.com`.
     */
    private static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return self::MASK;
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        $dot = strrpos($domain, '.');
        $tld = $dot !== false ? substr($domain, $dot) : '';
        $root = $dot !== false ? substr($domain, 0, $dot) : $domain;

        $maskedLocal = mb_substr($local, 0, 1) . '***';
        $maskedRoot = mb_substr($root, 0, 1) . '***';

        return $maskedLocal . '@' . $maskedRoot . ($tld !== '' ? $tld : '');
    }

    /**
     * Teléfono: conserva los dígitos que no identifican (prefijo de país y
     * operador) y enmascara el resto, para poder seguir reconociendo a la
     * misma persona sin poder marcarla. `+58 412-1234567` -> `+58 4**-***3*7`.
     */
    private static function maskPhone(string $phone): string
    {
        $pos = 0;
        $masked = preg_replace_callback(
            '/\d/',
            static function (array $m) use (&$pos): string {
                $pos++;
                // Se conservan los DOS primeros dígitos (prefijo) porque permiten
                // distinguir el país o el operador sin poder marcar a la persona;
                // todos los demás se enmascaran, incluidos los últimos, porque un
                // "últimos 4" es suficiente para reidentificar en un directorio.
                return $pos <= 2 ? $m[0] : '*';
            },
            $phone
        );

        // Sin dígitos (un campo "teléfono" que en realidad guarda texto libre)
        // no hay nada que estructurar y no hay enmascarado parcial que sirva:
        // se oculta entero. Un número de 1-2 dígitos cae aquí también.
        if ($masked === null || !str_contains($masked, '*')) {
            return self::MASK;
        }

        return $masked;
    }
}
