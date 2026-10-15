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

/**
 * La puerta del modo `roster` (fase 4 del plan de participantes).
 *
 * ## Qué es la credencial
 *
 * Hay DOS, y las dos valen:
 *
 *  - El `code` de 16 caracteres que lleva cada participante: es la credencial del
 *    correo, del QR y de `?t=`, y no cambia nunca aunque el dueño cambie el
 *    identificador (que es lo que haría falta para revocar a alguien).
 *  - El dato con el que la persona figura en la lista, que es el que el dueño
 *    elige en `access.identifier` (cédula, indicador, correo o el propio código).
 *    La página de acceso lo pide por su nombre —`access.label_*`—, y por eso
 *    aquí **solo se prueba ESE**: un formulario que pide la cédula no debería
 *    dejar entrar con el correo de al lado, que es lo que hacía la lista de
 *    candidatos antigua (`[$identificador, 'correo', 'cedula', 'indicador']`) y
 *    lo que hacía que el texto y la puerta dijeran cosas distintas.
 *
 * ## Por qué vive en `?t=` y no en un parámetro propio
 *
 * Es el mismo hueco que usan el enlace de email, el QR, el embed y el botón de
 * copiar. Un solo parámetro de credencial en la URL significa un solo camino que
 * propagar al `action` del POST y un solo sitio donde un enlace puede llevar la
 * credencial.
 *
 * Se comprueba **después** del token de F4, y no es un detalle: un `?t=` que casa
 * con una fila de `form_access_tokens` sigue siendo ese token, y los dos tipos de
 * fila no se buscan en la misma tabla.
 *
 * ## Por qué el permiso de sesión guarda el `id` del participante y no el código
 *
 * Por lo mismo que en F4: la sesión no debe contener ninguna credencial, solo el
 * puntero a lo que ya autorizó una vez. Y por eso el permiso **se revalida contra
 * la fila en cada request**: la caducidad no es lo que cierra la puerta de un
 * participante revocado —no tiene caducidad—, es que la fila ya no está. Borrar a
 * alguien de la lista es, por definición, dejar de invitarlo, y con esta puerta se
 * nota en la petición siguiente y no «cuando expire algo».
 */
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

    /**
     * Normaliza lo que alguien puede pegar o teclear.
     *
     * El pegado es el camino normal (el código va en un correo o en un QR), y un
     * pegado trae espacios y saltos de línea alrededor. Se quitan los espacios
     * internos y se pasa a mayúsculas porque el alfabeto del generador ya lo es;
     * buscar el código tal cual falla con un espacio invisible.
     *
     * La regla vive en `FormParticipant::normalizarCode()`, que es la que también
     * usa el modelo al leer el `code` de una fila: dos copias de este `preg_replace`
     * significan que un día alguien cambie una y la puerta deje de encontrar los
     * códigos que el modelo acaba de guardar.
     */
    public static function normalizar(string $codigo): string
    {
        return FormParticipant::normalizarCode($codigo);
    }

    /**
     * Busca a alguien por su código **en este formulario**.
     *
     * El `form_id` va en el WHERE y no se filtra después: es la mitad de la
     * autorización. Un código de otro formulario no existe aquí, igual que un token
     * de acceso de otro formulario no vale (ver
     * `FormAccess::findValidToken()`).
     */
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

    /**
     * Busca a alguien por **las dos credenciales que la puerta acepta**: su código
     * y el dato con el que figura en la lista (`access.identifier`).
     *
     * El código va primero porque es el que traen el correo, el QR y `?t=`, y
     * porque su normalización es destructiva (`V-123` → `V123`): probarlo al final
     * haría que una cédula con guion se buscara dos veces y con dos formas
     * distintas.
     *
     * Después se prueba **el identificador del formulario y nada más**. La lista
     * vieja probaba `[$identificador, 'correo', 'cedula', 'indicador']`, y eso
     * significaba que un formulario que pide la cédula dejaba entrar con el correo
     * de cualquier otra persona: la puerta hacía menos de lo que la página decía.
     * Con `identifier = codigo` no hay segunda vuelta a propósito —el código ya se
     * probó arriba—, así que un valor que no lo sea (una cédula pegada en un
     * formulario cuyo identificador es el código) no se convierte en nadie.
     *
     * `lastMotivo()` (`ok` | `not_found` | `used`) es lo que luego decide el texto
     * del error en `FillController::verifyCode()`.
     */
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

    /**
     * Cuenta el intento y devuelve si se puede seguir.
     *
     * El límite es por IP **y** por código: el de IP evita que alguien pruebe
     * códigos desde una máquina, y el de código evita que el mismo se pruebe desde
     * mil. Se cuentan los **dos**, incluso cuando el código no existe: si el
     * contador solo sumara para los válidos, probar códigos inventados sería gratis
     * y esto dejaría de ser una puerta.
     */
    public static function permitirIntento(int $formId, string $codigo, string $ip): bool
    {
        $codigo = self::normalizar($codigo);

        // El límite por IP es configurable (`ROSTER_CODE_PER_15MIN`): un censo se
        // contesta desde una IP compartida (kiosco, colegio, NAT de sede) y el
        // límite que frena a quien prueba códigos es el mismo que deja fuera a la
        // persona número 11. El de por código NO se configura: es una sola
        // persona tecleando y no lo agota un kiosco.
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

    /**
     * Da acceso a esta sesión por el participante indicado.
     *
     * El permiso **no caduca** (`exp` a 0) y por eso no se guarda en el mapa que
     * poda por caducidad: lo que lo cierra es la revalidación contra la fila. La
     * alternativa —darle una caducidad larga inventada— daría la sensación de
     * seguridad sin ningún efecto real, porque el permiso sobrevive igual mientras
     * la fila exista.
     */
    public static function grant(int $formId, int $participantId): void
    {
        $grants = Session::get('vf_form_access', []);
        if (!is_array($grants)) {
            $grants = [];
        }

        self::podar($grants);

        $grants[$formId] = ['id' => $participantId, 'exp' => 0, 'kind' => self::GRANT_PARTICIPANTE];
        Session::set('vf_form_access', $grants);
    }

    /**
     * ¿Sigue este participante en la lista?
     *
     * Se busca por id **y** por `form_id` en la misma consulta: el permiso de
     * sesión es del formulario, y un id de participante que se moviera de lista
     * no puede abrir la puerta que ya se abrió una vez.
     */
    public static function sigueEnLaLista(int $formId, int $participantId): bool
    {
        if ($participantId <= 0) {
            return false;
        }

        // Se resuelve por id y por formulario en la **misma** consulta: el permiso
        // de sesión es de este formulario, y un id que se moviera de lista no puede
        // abrir la puerta que ya se abrió una vez.
        return FormParticipant::existeEnFormulario($formId, $participantId);
    }

    /**
     * Quita de la sesión los permisos de formulario que ya no aplican.
     *
     * Es la misma poda que hace F4, con una diferencia: aquí `exp = 0` significa
     * «no caduca» y **no** es un permiso caducado. Confundir los dos sería tirar la
     * puerta en cada petición, que es un fallo invisible: el visitante siempre
     * tendría que volver a pegar su código.
     */
    public static function podar(array &$grants): void
    {
        $now = time();
        foreach ($grants as $id => $grant) {
            if (!is_array($grant) || (int) ($grant['id'] ?? 0) <= 0) {
                unset($grants[$id]);
                continue;
            }
            $exp = (int) ($grant['exp'] ?? 0);
            if ($exp !== 0 && $exp <= $now) {
                unset($grants[$id]);
            }
        }
    }

    /**
     * Identificador de la petición para el rate limit.
     *
     * `Request::ip()` ya aplica la regla de `TRUST_PROXY`: sin ese opt-in no se
     * cree ninguna cabecera de reenvío, que es lo que hace bypasseable el límite.
     */
    public static function ip(): string
    {
        return Request::ip();
    }

    /**
     * Deja de contar los intentos de un par (formulario, IP).
     *
     * Lo usan los tests y, en el futuro, un botón de «no soy yo». Vive aquí y no
     * en el llamador porque el nombre de la llave tiene que ser el mismo en los
     * dos sitios, y una llave escrita dos veces es una llave que se desincroniza.
     */
    public static function revoke(int $formId): void
    {
        $grants = Session::get('vf_form_access', []);
        if (!is_array($grants)) {
            $grants = [];
        }
        self::podar($grants);
        unset($grants[$formId]);
        Session::set('vf_form_access', $grants);
    }

    /**
     * Deja de contar los intentos de un par (formulario, IP).
     *
     * Lo usan los tests y, en el futuro, un botón de «no soy yo». Vive aquí y no
     * en el llamador porque el nombre de la llave tiene que ser el mismo en los
     * dos sitios, y una llave escrita dos veces es una llave que se desincroniza.
     */
    public static function olvidarIntento(int $formId, string $ip): void
    {
        RateLimiter::reset('form_code', (string) $formId . '|' . $ip);
    }
}