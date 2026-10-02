<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\AccessController;
use App\Controllers\FillController;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Mailer;
use App\Core\PublicUrl;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\Field;
use App\Models\Form;
use App\Models\FormAccess;
use App\Models\Submission;
use App\Models\User;
use App\Services\FormSettings;

/**
 * F4 — Acceso restringido por email.
 *
 * El criterio de estos tests es el mismo que en `FormReviewTest`: lo que se fija
 * no es que el camino feliz funcione, sino que **no se pueda entrar por una puerta
 * que no sea la del token**. Un gate de acceso se rompe por accumulation —un POST
 * sin GET, un token de otro formulario, un enlace revocado, una lista vacía— y
 * cada una de esas vías merece un test que falle si vuelve a abrirse.
 *
 * Los que más importan, por orden:
 *
 *  1. El **POST** cortado sin acceso. Es la puerta real: el GET es una cortesía.
 *  2. El token **atado a su formulario**. Sin el `form_id` en el WHERE, un enlace
 *     sería la llave maestra de todos los formularios restringidos.
 *  3. **Revocar mata los enlaces ya repartidos**, no solo la fila de la lista.
 *  4. La **respuesta uniforme**: la página de acceso no puede ser un oráculo de
 *     «este correo está en la lista».
 *
 * Sobre el correo: se filtra por `@test.local` porque `User::reviewers()` y los
 * buzones reales de la instancia también están en la BD.
 */
class FormAccessTest
{
    private static ?int $ownerId = null;
    private static ?int $formId = null;
    private static ?string $slug = null;
    private static ?int $fieldId = null;

    private $sent = [];

    private const MAIL_CFG = [
        'host'         => 'smtp.example.com',
        'port'         => 587,
        'encryption'   => 'tls',
        'user'         => '',
        'password'     => '',
        'from_address' => 'no-reply@example.com',
        'from_name'    => 'VanillaForms',
        'timeout'      => 15,
    ];

    public function setUp(): void
    {
        self::cleanupResiduals();
        $this->sent = [];

        // El rate limit de F4 es GLOBAL y por IP, y todos los tests de esta clase
        // salen de 127.0.0.1: sin limpiarlo, el cupo de los cinco primeros tests
        // agotaría el de los siguientes y los correos de `postAccess()` —
        // legítimamente cortados— no llegarían a enviarse. Los dos scopes son
        // exclusivos de esta funcionalidad, así que borrarlos no toca nada ajeno.
        Database::getInstance(Config::db())->execute(
            "DELETE FROM rate_limits WHERE scope IN ('form_access', 'form_access_email')"
        );

        Mailer::setTransport(function (array $envelope, array $config): void {
            $this->sent[] = $envelope;
        });
        Mailer::setConfigOverride(self::MAIL_CFG);
        PublicUrl::setBaseForTesting('https://forms.example.com');

        $rand = bin2hex(random_bytes(4));
        self::$ownerId = User::create('Access Owner ' . $rand, 'access-' . $rand . '@test.local', 'clave-larga-123', 2);
        self::$formId = Form::create((int) self::$ownerId, 'Formulario restringido ' . $rand);
        self::$slug = (string) Form::find((int) self::$formId, false)['slug'];
        self::$fieldId = Field::create((int) self::$formId, 'text', 'Pregunta privada', '', '', true);
        Form::setStatus((int) self::$formId, 'published');

        self::authAs((int) self::$ownerId);
    }

    public function tearDown(): void
    {
        Mailer::setTransport(null);
        Mailer::setConfigOverride(null);
        PublicUrl::setBaseForTesting(null);
        Response::setRecordHalt(false);
        unset($_GET['t'], $_POST, $_SERVER['HTTP_X_CSRF_TOKEN'], $_SERVER['REQUEST_METHOD']);
        // El rótulo decide si `addEmail()` responde en JSON; si se quedara puesto,
        // el test siguiente que espere un 302 vería un 200 y el fallo aparecería
        // en otro sitio.
        unset($_SERVER['HTTP_X_REQUESTED_WITH']);
        Auth::forgetCache();
        Session::destroy();
        self::cleanupResiduals();
    }

    // =============================================================
    // El setting
    // =============================================================

    public function testElModoPublicoEsElDefaultParaNoRomperLoExistente(): void
    {
        $d = FormSettings::defaults();
        assertSame('public', $d['access']['mode'], 'abierto por defecto');

        $n = FormSettings::normalize(['access' => ['mode' => 'allowlist', 'basura' => 1]]);
        assertSame('allowlist', $n['access']['mode']);
        assertFalse(array_key_exists('basura', $n['access']), 'descarta claves desconocidas');
    }

    public function testUnModoDesconocidoCaeAPublicoYNoSeGuardaEnSilencio(): void
    {
        // Dos puertas, como la tipografía: normalize deja el valor utilizable y
        // validate avisa. Sin el aviso, quien escribiera 'allowlst' se quedaría
        // con un formulario abierto creyendo que lo hadow nada.
        $n = FormSettings::normalize(['access' => ['mode' => 'allowlst']]);
        assertSame('public', $n['access']['mode'], 'lo que sale es el default histórico, no un invento');

        $err = FormSettings::validate(['access' => ['mode' => 'allowlst']]);
        assertNotNull($err, 'pero guardar eso tiene que decir algo');
        assertTrue(str_contains((string) $err, 'allowlist'), 'el error nombra los valores válidos: ' . $err);
    }

    /**
     * Frontera JS⇄PHP del modo de acceso, que ahora vive en la ficha del
     * formulario y no en el builder.
     *
     * Los dos lados de la frontera se comprueban por separado porque fallan de
     * forma distinta: si el `<select>` no estuviera en la ficha, el modo se
     * quedaría siempre en el que ya había; y si la ficha no lo tradujera al
     * bloque `access`, el POST se guardaría sin tocar nada y tampoco habría
     * error. Un test que solo mirara el PHP daría verde con el campo ausente.
     */
    public function testElModoDeAccesoSeEditaEnLaFichaYElServidorLoAcepta(): void
    {
        $vista = (string) file_get_contents(dirname(__DIR__, 2) . '/views/dashboard/forms/show.php');
        assertTrue(str_contains($vista, 'name="access_mode"'), 'la ficha tiene el select del modo');
        assertTrue(
            str_contains($vista, '/forms/<?= (int) $form[\'id\'] ?>/settings'),
            'y el form apunta a la puerta de ajustes'
        );

        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/dashboard.js');
        assertTrue(
            str_contains($js, "getElementById('access_mode')"),
            'el texto de estado del modo se pinta en la ficha'
        );

        // Y el builder ya NO manda `access`: si lo mandara, su `merge()` con
        // BUILDER_BLOCKS lo recortaría igual, pero dejaría un bloque muerto que
        // sugiere que se guarda desde allí.
        $builderJs = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/builder.js');
        assertFalse(str_contains($builderJs, 'access: {'), 'el builder ya no serializa el bloque access');
        assertFalse(str_contains($builderJs, 'stAccessMode'), 'ni tiene el select');
    }

    // =============================================================
    // El gate
    // =============================================================

    public function testSinTokenNoSeSirvenLosCampos(): void
    {
        $this->setMode('allowlist');
        $html = $this->getFill();

        assertTrue(str_contains($html, 'vfAccessForm'), 'pide identificación');
        assertFalse(str_contains($html, 'Pregunta privada'), 'y NO pinta los campos del formulario');
    }

    public function testConTokenValidoSeSirveElFormulario(): void
    {
        $this->setMode('allowlist');
        $token = $this->issueToken('ana@ejemplo.com');

        $html = $this->getFill($token);

        assertTrue(str_contains($html, 'id="vfForm"'), 'se sirve el formulario');
        assertTrue(str_contains($html, 'Pregunta privada'), 'con sus campos');
        assertFalse(str_contains($html, 'vfAccessForm'), 'y no la página de identificación');
    }

    public function testElTokenViajaEnElActionParaNoDependerDeLasCookies(): void
    {
        // Sin esto, sin cookies el GET se sirve (el token viene en la URL) y el
        // POST se muere en un 403 sin explicación. Y dentro de un iframe la
        // cookie es de terceros y muchos navegadores la bloquean.
        $this->setMode('email');
        $token = $this->issueToken('ana@ejemplo.com');

        $html = $this->getFill($token);

        assertTrue(
            str_contains($html, 'action="/f/' . self::$slug . '?t=' . $token . '"'),
            'el action del formulario lleva el token'
        );
    }

    public function testUnTokenDeOtroFormularioNoSirve(): void
    {
        // La mitad de la autorización es el form_id. Sin él, un enlace sería la
        // llave maestra de todos los formularios restringidos de la instalación.
        $this->setMode('allowlist');
        $token = $this->issueToken('ana@ejemplo.com');

        $otro = Form::create((int) self::$ownerId, 'Otro ' . bin2hex(random_bytes(3)));
        Form::setStatus((int) $otro, 'published');
        $this->setMode('allowlist', (int) $otro);

        $html = $this->getFill($token, (string) Form::find($otro, false)['slug']);

        assertTrue(str_contains($html, 'vfAccessForm'), 'el token del formulario A no abre el B');
    }

    public function testUnTokenCaducadoNoSirve(): void
    {
        $this->setMode('allowlist');
        $token = $this->issueToken('ana@ejemplo.com');
        $this->expireToken($token);

        assertTrue(str_contains($this->getFill($token), 'vfAccessForm'), 'caducado = sin acceso');
    }

    public function testRevocarLaListaMataLosEnlacesYaRepartidos(): void
    {
        // El caso real: el enlace se mandó por WhatsApp hace una semana. Si quitar
        // el email solo borrara la fila de la lista, ese enlace seguiría
        // abriendo el formulario, y revocar no revoca nada.
        $this->setMode('allowlist');
        FormAccess::addEmail((int) self::$formId, 'ana@ejemplo.com');
        $token = $this->issueToken('ana@ejemplo.com');
        assertTrue(str_contains($this->getFill($token), 'id="vfForm"'), 'precondición: el enlace funciona');

        FormAccess::removeEmail((int) self::$formId, 'ana@ejemplo.com');

        assertTrue(str_contains($this->getFill($token), 'vfAccessForm'), 'tras revocar, ese enlace ya no abre nada');
    }

    public function testSoloQuedaUnTokenVivoPorCorreoYFormulario(): void
    {
        // Mismo criterio que «un token pendiente por usuario» de F2: un correo
        // robado de hace tres meses no debe seguir valiendo, y la tabla no crece.
        $this->setMode('allowlist');
        $primero = $this->issueToken('ana@ejemplo.com');
        $segundo = $this->issueToken('ana@ejemplo.com');

        assertTrue($primero !== $segundo, 'son tokens distintos');
        assertTrue(str_contains($this->getFill($primero), 'vfAccessForm'), 'el anterior deja de servir');
        assertTrue(str_contains($this->getFill($segundo), 'id="vfForm"'), 'y el nuevo sí');
    }

    // =============================================================
    // El POST: la puerta que de verdad importa
    // =============================================================

    public function testElPostSeCortaSinAccesoYNoGuardaNada(): void
    {
        $this->setMode('allowlist');
        $antes = $this->submits();

        $halt = self::statusOf($this->postSubmit());

        assertSame(403, $halt, 'sin token no se envía');
        assertSame($antes, $this->submits(), 'y no se crea ninguna respuesta (ni fantasma)');
    }

    public function testElPostTambienSeCortaSiSoloHayLaSesionDelPanel(): void
    {
        // El dueño tiene sesión iniciada en el panel. Eso no es acceso al
        // formulario: si lo fuera, cualquier usuario de la instancia podría
        // enviar respuestas a un formulario restringido.
        $this->setMode('allowlist');

        $halt = self::statusOf($this->postSubmit());

        assertSame(403, $halt, 'la sesión del panel no abre el fill');
    }

    public function testConTokenElPostGuardaLaRespuesta(): void
    {
        $this->setMode('allowlist');
        $antes = $this->submits();

        $halt = self::statusOf($this->postSubmit($this->issueToken('ana@ejemplo.com')));

        assertTrue($halt !== 403, 'con token entra: ' . json_encode($halt));
        assertSame($antes + 1, $this->submits(), 'y guarda una respuesta');
    }

    public function testLaSesionSustituyeAlTokenEnElSiguientePost(): void
    {
        // Un solo GET con el enlace debe bastar para todo el flujo: si el POST
        // following exigiera el token en la URL, un F5 con la barra de direcciones
        // recortada dejaría el formulario a medias.
        $this->setMode('email');
        $this->getFill($this->issueToken('ana@ejemplo.com'));

        $antes = $this->submits();
        $halt = self::statusOf($this->postSubmit());

        assertTrue($halt !== 403, 'tras pasar por el enlace, la sesión da acceso: ' . $halt);
        assertSame($antes + 1, $this->submits());
    }

    public function testLaSesionNoSobreviveALaCaducidadDelToken(): void
    {
        // Si el permiso de sesión tuviera su propia caducidad, podría outlive la
        // credencial que lo creó: un enlace revocado dejaría de servir y el
        // navegador seguiría dentro.
        $this->setMode('email');
        $token = $this->issueToken('ana@ejemplo.com');
        $this->getFill($token);

        $this->expireToken($token);
        Session::destroy();

        $this->getFill($token);
        assertSame(403, self::statusOf($this->postSubmit()), 'con el token caducado, tampoco la sesión');
    }

    // =============================================================
    // La página de acceso: respuesta uniforme
    // =============================================================

    public function testLaRespuestaEsLaMismaExistaONoElAcceso(): void
    {
        $this->setMode('allowlist');
        FormAccess::addEmail((int) self::$formId, 'ana@ejemplo.com');

        $dentro = $this->postAccess('ana@ejemplo.com');
        $fuera = $this->postAccess('intruso@ejemplo.com');

        assertSame($dentro['flash'], $fuera['flash'], 'el mismo texto en los dos casos');
        assertSame($dentro['url'], $fuera['url'], 'y el mismo destino');
    }

    public function testAQuienNoEstaEnLaListaNoSeLeMandaCorreoNiSeLeCreaToken(): void
    {
        $this->setMode('allowlist');
        FormAccess::addEmail((int) self::$formId, 'ana@ejemplo.com');

        $this->postAccess('intruso@ejemplo.com');

        assertSame([], $this->sent, 'nada de correo para un email no autorizado');
        assertNull(FormAccess::findValidToken((int) self::$formId, 'cualquiera'), 'y ningún token');
        assertSame(0, $this->tokensFor('intruso@ejemplo.com'), 'ni filas de token');
    }

    public function testAlAutorizadoSeLeMandaElCorreoConElEnlace(): void
    {
        $this->setMode('allowlist');
        FormAccess::addEmail((int) self::$formId, 'ana@ejemplo.com');

        $this->postAccess('ana@ejemplo.com');

        $aLaLista = array_values(array_filter(
            $this->sent,
            static fn (array $e): bool => in_array('ana@ejemplo.com', (array) $e['to'], true)
        ));
        assertSame(1, count($aLaLista), 'un correo, y solo a la persona de la lista');
        assertTrue(
            str_contains((string) $aLaLista[0]['html'], 'https://forms.example.com/f/'),
            'y el enlace sale de PublicUrl, no de app.url: ' . substr((string) $aLaLista[0]['html'], 0, 200)
        );
    }

    public function testEnModoEmailEntraCualquierCorreoValido(): void
    {
        $this->setMode('email');

        $this->postAccess('quien-quiera@ejemplo.com');

        assertSame(1, count($this->sent), 'sin lista, todo correo válido entra');
    }

    public function testUnFormularioPublicoNoEmiteTokens(): void
    {
        // Con un `?t=` pegado sobre la URL de un fill abierto, la ruta de acceso
        // no puede seguir «funcionando»: crearía filas para un formulario que
        // no pide nada.
        $halt = $this->postAccess('ana@ejemplo.com');

        assertSame(404, $halt['status'], 'no hay nada que identificar');
        assertSame([], $this->sent, 'y no se manda correo');
    }

    public function testLaPetitionDeAccesoTambienRespetaElRateLimit(): void
    {
        $this->setMode('email');
        for ($i = 0; $i < 5; $i++) {
            $this->postAccess('ana' . $i . '@ejemplo.com');
        }
        $antes = count($this->sent);
        $this->postAccess('ana999@ejemplo.com');

        assertSame($antes, count($this->sent), 'a partir del cupo no se envía nada');
    }

    // =============================================================
    // La lista (lado dueño)
    // =============================================================

    public function testLaListaEsDelDueñoYNoDeUnTercero(): void
    {
        $this->setMode('allowlist');
        $ajenoId = User::create('Access Ajeno ' . bin2hex(random_bytes(3)), 'ajeno-access-' . bin2hex(random_bytes(3)) . '@test.local', 'clave-larga-123', 2);
        self::authAs((int) $ajenoId);

        $halt = $this->postOwner('addEmail');

        assertSame(403, $halt['status'], 'un tercero no toca la lista de otro');
        assertSame(0, FormAccess::allowlistCount((int) self::$formId), 'y no se añade nada');
    }

    public function testAnadirEnModoEmailSeRechazaParaNoPrometerUnCerramientoQueNoHay(): void
    {
        $this->setMode('email');

        $halt = $this->postOwner('addEmail', ['email' => 'ana@ejemplo.com']);

        assertSame(302, $halt['status'], 'vuelve al panel con un aviso (PRG)');
        assertTrue($halt['error'] !== '', 'y avisa de que no aplica en este modo');
        assertSame(0, FormAccess::allowlistCount((int) self::$formId), 'sin filas: prometer un cierre que no hay sería peor');
    }

    public function testLaListaIgnoraMayusculasYNoDuplica(): void
    {
        $this->setMode('allowlist');

        $primero = $this->postOwner('addEmail', ['email' => '  Ana@Ejemplo.COM ']);
        $segundo = $this->postOwner('addEmail', ['email' => 'ana@ejemplo.com']);

        assertSame(302, $primero['status'], 'el primero se añade');
        assertSame(1, FormAccess::allowlistCount((int) self::$formId), 'un solo correo: Ana@… y ana@… son la misma');
        assertTrue($segundo['error'] !== '', 'y el segundo avisa en vez de duplicar en silencio');
        assertSame(1, FormAccess::allowlistCount((int) self::$formId));
    }

    // =============================================================
    // El alta sin recarga (fetch)
    // =============================================================

    /**
     * El camino JSON devuelve el HTML de la lista ya rerenderizado, no la lista
     * de correos: así el JS no sabe cómo es una fila y el partial es el único
     * sitio donde se pinta.
     *
     * Y lo devuelve **en un mapa `id → markup`**, no como una cadena suelta. Es
     * el contrato de `vfApplySlots()`, el mismo que usan los cuatro huecos de la
     * ficha y que el borrado ya usaba: con el `html` como string el JS recibía algo
     * que no tenía a qué reponer, el alta se quedaba en el POST nativo (y
     * recargaba) y el síntoma parecía del servidor.
     */
    public function testElAltaPorFetchDevuelveElHtmlDeLaListaYElContador(): void
    {
        $this->setMode('allowlist');

        $r = $this->postOwner('addEmail', ['email' => 'ana@ejemplo.com'], true);

        assertSame(200, $r['status'], 'responde JSON en vez de redirigir');
        $data = json_decode((string) $r['body'], true);
        assertTrue(is_array($data), 'el cuerpo es JSON: ' . substr((string) $r['body'], 0, 200));
        assertSame(true, $data['ok'] ?? null);
        assertSame(1, $data['count'] ?? null, 'y el contador nuevo');
        assertTrue(
            is_array($data['html'] ?? null) && isset($data['html']['accessList']),
            'el `html` es un mapa de ids (aquí, `accessList`, el del contenedor de '
                . 'la vista), no markup suelto'
        );
        assertTrue(
            str_contains((string) ($data['html']['accessList'] ?? ''), 'ana@ejemplo.com'),
            'el html trae la fila recién añadida, escapada por el partial'
        );
        assertSame(1, FormAccess::allowlistCount((int) self::$formId));
    }

    /**
     * El HTML que vuelve ya trae los controles de la fila (enlace, quitar), y
     * el partial los pinta con `Csrf::field()`: si el partial no metiera el
     * token, quitar un correo por la lista recién añadida no se enviaría.
     */
    public function testElHtmlDevueltoTraeElCsrfDeQuitar(): void
    {
        $this->setMode('allowlist');
        FormAccess::addEmail((int) self::$formId, 'ana@ejemplo.com');

        $r = $this->postOwner('addEmail', ['email' => 'beto@ejemplo.com'], true);
        $data = json_decode((string) $r['body'], true);
        $html = (string) ($data['html']['accessList'] ?? '');

        assertTrue($html !== '', 'el hueco `accessList` trae el partial');
        assertTrue(str_contains($html, 'name="_token"'), 'la fila trae token de CSRF para quitarla');
        assertTrue(str_contains($html, '/access/email/remove'), 'y el endpoint de quitar');
        assertTrue(str_contains($html, 'data-role="access-link"'), 'y el botón de emitir enlace');
    }

    /** Sin lista vacía, el partial devuelve el aviso en vez de un `<ul>` vacío. */
    public function testElHtmlDevueltoAvisaCuandoLaListaSigueVacia(): void
    {
        $this->setMode('allowlist');

        $r = $this->postOwner('addEmail', ['email' => 'no-es-un-correo'], true);

        $data = json_decode((string) $r['body'], true);
        assertSame(false, $data['ok'] ?? null, 'rechazado');
        assertTrue(($data['error'] ?? '') !== '', 'con el motivo: un fetch que devuelve 200 sin texto no dice nada');
        assertSame(0, FormAccess::allowlistCount((int) self::$formId));
    }

    /**
     * Las reglas son las mismas por los dos caminos. Si el JSON validara otra
     * cosa, el formulario sin JavaScript aceptaría lo que el fetch rechaza (o al
     * revés), y el camino «raro» sería el que nadie prueba.
     */
    public function testElCaminoJsonAplicaLasMismasReglasQueElFormulario(): void
    {
        // Modo equivocado: `email` no tiene lista.
        $this->setMode('email');
        $r1 = $this->postOwner('addEmail', ['email' => 'ana@ejemplo.com'], true);
        $d1 = json_decode((string) $r1['body'], true);
        assertSame(422, $r1['status'], 'mismo status que el 302 con flash de error');
        assertTrue(($d1['error'] ?? '') !== '', 'y el mismo motivo');
        assertSame(0, FormAccess::allowlistCount((int) self::$formId));

        // Duplicado.
        $this->setMode('allowlist');
        FormAccess::addEmail((int) self::$formId, 'ana@ejemplo.com');
        $r2 = $this->postOwner('addEmail', ['email' => 'ANA@ejemplo.com'], true);
        $d2 = json_decode((string) $r2['body'], true);
        assertSame(422, $r2['status'], 'el duplicado se rechaza también por fetch');
        assertSame(1, FormAccess::allowlistCount((int) self::$formId));

        // Sin token.
        $r3 = $this->postOwner('addEmail', ['email' => 'beto@ejemplo.com'], true, ['_token' => 'incorrecto']);
        $d3 = json_decode((string) $r3['body'], true);
        assertSame(419, $r3['status'], 'sin CSRF, 419 como en el resto de la app');
        assertSame(1, FormAccess::allowlistCount((int) self::$formId), 'y no añade nada');
    }

    /**
     * El `form` conserva method/action: sin JavaScript, el alta sigue
     * funcionando.
     *
     * Y sin JS **tampoco hay nada que «desactivar»**: no hay un
     * `if (algo) return;` que deje pasar el formulario porque la mitad de la
     * configuración del alta no exista en la vista.
     *
     * El camino sin JS lo cubre el delegado de `app.js` y su `.catch`, no un
     * handler propio en `dashboard.js`: aquel IIFE tenía su propio `fetch`, su
     * propio `.catch` y su propio `form.submit()` de reserva, o sea **tres**
     * caminos al mismo sitio, y el que se quedó sin escapar (`card.dataset.
     * addEndpoint`, un atributo que la vista ya no tenía) era el que hacía que el
     * alta recargara mientras el borrado no.
     */
    public function testElFormularioDelAltaFuncionaSinJavaScript(): void
    {
        $vista = (string) file_get_contents(dirname(__DIR__, 2) . '/views/dashboard/forms/show.php');

        assertTrue(
            str_contains($vista, 'method="post" action="/forms/<?= (int) $form[\'id\'] ?>/access/email"'),
            'el form apunta a la puerta con method y action de verdad'
        );
        assertTrue(str_contains($vista, 'Csrf::field()'), 'con su token CSRF');
        /* Sin comentarios: en dashboard.js queda escrito POR QUÉ ya no está
         * (`card.dataset.addEndpoint`, el atributo que la vista perdió), y una
         * nota que nombra el código muerto no es código muerto. */
        $js = preg_replace('#[/][/*].*?[/][*]#s', ' ', (string) file_get_contents(
            dirname(__DIR__, 2) . '/public/assets/js/dashboard.js'
        ));
        assertTrue(
            !str_contains($js, 'addEndpoint'),
            'dashboard.js no vuelve a manejar el alta: eso ya lo hace el '
                . 'delegado, y un segundo camino al mismo sitio se queda viejo en silencio'
        );

        $app = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/app.js');
        assertTrue(
            str_contains($app, "e.preventDefault();"),
            'el delegado anula el submit cuando va por fetch'
        );
        assertTrue(
            str_contains($app, 'p.form.submit();'),
            'y el fallo de red cae al POST normal en vez de tragarse el alta'
        );
    }

    public function testCopiarEnlaceSoloParaAlguienDeLaLista(): void
    {
        $this->setMode('allowlist');
        FormAccess::addEmail((int) self::$formId, 'ana@ejemplo.com');

        $ok = $this->postOwner('copyLink', ['email' => 'ana@ejemplo.com']);
        assertSame(200, $ok['status'], 'para uno de la lista, enlace');
        $json = json_decode($ok['body'], true);
        assertTrue(
            is_array($json) && str_contains((string) ($json['url'] ?? ''), 'https://forms.example.com/f/'),
            'absoluto, con PublicUrl: ' . $ok['body']
        );

        $no = $this->postOwner('copyLink', ['email' => 'intruso@ejemplo.com']);
        assertSame(422, $no['status'], 'para un desconocido, ni enlace: si no, sería un generador de accesos');
    }

    public function testQuitarRevocaYDesapareceDeLaLista(): void
    {
        $this->setMode('allowlist');
        FormAccess::addEmail((int) self::$formId, 'ana@ejemplo.com');
        $token = $this->issueToken('ana@ejemplo.com');

        $halt = $this->postOwner('removeEmail', ['email' => 'ana@ejemplo.com']);

        assertSame(302, $halt['status']);
        assertSame(0, FormAccess::allowlistCount((int) self::$formId), 'sale de la lista');
        assertNull(FormAccess::findValidToken((int) self::$formId, $token), 'y su enlace muere con ella');
    }

    // =============================================================
    // Utilidades
    // =============================================================

    private function setMode(string $mode, ?int $formId = null): void
    {
        $id = $formId ?? (int) self::$formId;
        Database::getInstance(Config::db())->execute(
            'UPDATE forms SET settings = :s WHERE id = :id',
            ['s' => json_encode(['access' => ['mode' => $mode]]), 'id' => $id]
        );
    }

    private function issueToken(string $email): string
    {
        return FormAccess::issue((int) self::$formId, $email, '127.0.0.1');
    }

    private function expireToken(string $token): void
    {
        Database::getInstance(Config::db())->execute(
            "UPDATE form_access_tokens SET expires_at = '2000-01-01 00:00:00' WHERE token_hash = :h",
            ['h' => hash('sha256', $token)]
        );
    }

    private function tokensFor(string $email): int
    {
        $row = Database::getInstance(Config::db())->fetch(
            'SELECT COUNT(*) AS c FROM form_access_tokens WHERE form_id = :f AND email = :e',
            ['f' => (int) self::$formId, 'e' => strtolower($email)]
        );

        return (int) ($row['c'] ?? 0);
    }

    private function submits(): int
    {
        $row = Database::getInstance(Config::db())->fetch(
            'SELECT COUNT(*) AS c FROM submissions WHERE form_id = :f',
            ['f' => (int) self::$formId]
        );

        return (int) ($row['c'] ?? 0);
    }

    /** GET /f/{slug} con `?t=` opcional. Devuelve el HTML. */
    private function getFill(?string $token = null, ?string $slug = null): string
    {
        $_GET = $token === null ? [] : ['t' => $token];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        ob_start();
        try {
            (new FillController())->show($slug ?? (string) self::$slug);
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /** POST /f/{slug}: el envío de verdad. */
    private function postSubmit(?string $token = null): array
    {
        $_GET = $token === null ? [] : ['t' => $token];
        // La clave es `field_<id>`, no un array: es la convención de todo el fill
        // (ver `TurnstileFormTest::postValid`). `_vf_hp` vacío es el honeypot en
        // su estado honesto; sin él, el controller finge éxito sin persistir.
        $_POST = [
            '_token'    => Csrf::token(),
            '_vf_hp'    => '',
            'lang'      => 'es',
            'field_' . (int) self::$fieldId => 'Respuesta privada',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        Request::setRequestBodyForTests(null);

        return $this->invokeFill('submit');
    }

    /** POST /f/{slug}/access: pedir el enlace. */
    private function postAccess(string $email): array
    {
        $_GET = [];
        $_POST = ['_token' => Csrf::token(), 'email' => $email, 'lang' => 'es'];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        Request::setRequestBodyForTests(null);

        $halt = $this->run(static function (): void {
            (new AccessController())->requestAccess((string) self::$slug);
        });

        return [
            'status' => self::statusOf($halt),
            'url'    => (string) ($halt['url'] ?? ''),
            'flash'  => (string) ($_SESSION['_flash']['success'] ?? ''),
        ];
    }

    /** Acción del dueño: `addEmail`, `removeEmail` o `copyLink`. */
    /**
     * @param bool $json envía el rótulo `X-Requested-With`, que es lo que hace
     *                   que `addEmail()` responda en JSON en vez de redirigir.
     * @param array<string, mixed>|null $override sustituye campos del POST
     *                                   (para probar el CSRF inválido).
     */
    private function postOwner(string $action, array $post = [], bool $json = false, ?array $override = null): array
    {
        $_GET = [];
        $_POST = $post + ['_token' => Csrf::token()];
        if ($override !== null) {
            $_POST = array_merge($_POST, $override);
        }
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = Csrf::token();
        if ($json) {
            $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        } else {
            unset($_SERVER['HTTP_X_REQUESTED_WITH']);
        }
        Request::setRequestBodyForTests(null);

        $halt = $this->run(static function () use ($action): void {
            (new AccessController())->{$action}((int) self::$formId);
        });

        return [
            'status' => self::statusOf($halt),
            'url'    => (string) ($halt['url'] ?? ''),
            // `Response::json()` vuelca el cuerpo a stdout y solo anota
            // status+type en el log, así que el JSON se lee del buffer.
            'body'   => self::$lastOutput,
            'flash'  => (string) ($_SESSION['_flash']['success'] ?? ''),
            'error'  => (string) ($_SESSION['_flash']['error'] ?? ''),
        ];
    }

    private function invokeFill(string $action): array
    {
        return $this->run(static function () use ($action): void {
            (new FillController())->{$action}((string) self::$slug);
        });
    }

    private static string $lastOutput = '';

    /**
     * Ejecuta un controlador y devuelve lo registrado por `Response`.
     *
     * En CLI `Response::halt()` no corta el proceso, así que el controlador sigue
     * después de cada `Response::*` salvo que él mismo haga `return`. Los tests
     * comprueban el `code` del log, no la salida.
     */
    private function run(callable $fn): array
    {
        Response::setRecordHalt(true);
        ob_start();
        try {
            $fn();
        } finally {
            self::$lastOutput = (string) ob_get_clean();
        }
        $halt = Response::lastHalt() ?? [];
        Response::setRecordHalt(false);

        return $halt;
    }

    /**
     * Normaliza las tres formas del log a un status HTTP.
     *
     * `Response` registra `code` para `abort` y `url` sin status para
     * `redirect` (que en HTTP siempre es 302), así que leer `$halt['status']` a
     * pelo daría 0 en todos los errores y en todas las redirecciones.
     */
    private static function statusOf(?array $halt): int
    {
        if ($halt === null) {
            return 0;
        }

        return match ((string) ($halt['type'] ?? '')) {
            'abort' => (int) ($halt['code'] ?? 0),
            'json'  => (int) ($halt['status'] ?? 0),
            'redirect', 'redirect_external' => 302,
            default => 0,
        };
    }

    private static function authAs(int $userId): void
    {
        Auth::assumeLoggedIn($userId);
        Auth::forgetCache();
    }

    /**
     * Residuos. EL ORDEN IMPORTA: `users.role_id` es ON DELETE **RESTRICT**, así
     * que los usuarios van antes que los roles. Y los usuarios primero, porque su
     * cascada se lleva los formularios y con ellos `form_access_emails` y
     * `form_access_tokens` (FK ON DELETE CASCADE): borrar los access antes dejaría
     * filas apuntando a un formulario que aún no existe.
     */
    private static function cleanupResiduals(): void
    {
        $db = Database::getInstance(Config::db());

        $db->execute("DELETE FROM role_groups WHERE name LIKE 'Grupo Acceso-%'");
        $db->execute("DELETE FROM users WHERE email LIKE 'access-%'");
        $db->execute("DELETE FROM users WHERE email LIKE 'ajeno-access-%'");
        $db->execute("DELETE FROM roles WHERE name LIKE 'acceso-%'");

        self::$ownerId = null;
        self::$formId = null;
        self::$slug = null;
        self::$fieldId = null;
    }
}
