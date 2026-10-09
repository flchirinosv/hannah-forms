<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\Form;
use App\Models\FormAccess;
use App\Models\FormParticipant;
use App\Models\User;
use App\Services\FormSettings;
use App\Services\ParticipantImporter;
use App\Services\RosterPanel;
use App\Services\RosterTemplate;

/**
 * Fase 5 — La pestaña de participantes de la tarjeta de Acceso.
 *
 * Esta fase es, de todas, la que más cosas puede dejar «a medias sin decir nada»,
 * porque casi ninguna de ellas es un error de PHP: un hueco que se repone con el
 * id equivocado no falla, **no hace nada**; una pestaña que el CSS esconde no
 * falla, **no aparece**; un formulario que se queda con el `action` equivocado no
 * falla, recarga. Lo que se comprueba aquí son esas cuatro cosas:
 *
 *  1. **El panel solo existe en modo `roster`**, y la rama de la allowlist sigue
 *     siendo la de F4. El `else` de `_access_card.php` es de la allowlist, y
 *     meter el listado ahí habría producido una casilla de correo por persona.
 *  2. **Las dos zonas se pintan las dos y ninguna viene escondida.** El
 *     interruptor lo pone `dashboard.js` AÑADIENDO `hidden`, así que sin JS se
 *     ven las dos; si algún día el servidor empezara a pintar `hidden`, el
 *     segundo panel desaparecería en el navegador que menos lo puede permitir y
 *     ningún test de PHP se enteraría.
 *  3. **Los ids de los huecos son los que declara la vista, y ninguno está en un
 *     `<form>`** (el repintado de un id que está en el formulario mete un
 *     formulario dentro de otro, que el parser descarta).
 *  4. **Cada acción repone lo que cambia y solo eso**, y con la búsqueda y el
 *     orden que el dueño tenía delante: un `html` sin `rosterList` deja la lista
 *     diciendo lo que decía antes de borrar a alguien, y uno que reponga el
 *     catálogo entero en cada alta hace un `information_schema` que nadie pidió.
 *
 * Y una quinta, que es la que más caro sale cuando se rompe: **el informe de la
 * importación llega por los dos caminos**. Con JS, en el `html` (también cuando
 * `ok` es `false`, que es cuando más falta hace). Sin JS, en la sesión, porque el
 * POST es nativo y redirige: sin eso el dueño se queda con «5 filas quedaron
 * fuera» y sin saber cuáles, que es el trabajo que la importación vino a quitar.
 */
class RosterPanelTest
{
    private const VISTA_TARJETA = __DIR__ . '/../../views/dashboard/forms/_access_card.php';
    private const JS = __DIR__ . '/../../public/assets/js/dashboard.js';
    private const CSS = __DIR__ . '/../../public/assets/css/app.css';
    private const RUTAS = __DIR__ . '/../../config/routes.php';

    private static ?int $ownerId = null;
    private static ?int $formId = null;
    /** @var list<string> */
    private static array $temporales = [];

    public function setUp(): void
    {
        self::cleanupResiduals();

        $rand = bin2hex(random_bytes(4));
        self::$ownerId = User::create('Roster Panel ' . $rand, 'rosterpanel-' . $rand . '@test.local', 'clave-larga-123', 2);
        self::$formId  = Form::create((int) self::$ownerId, 'Roster Panel ' . $rand);

        Auth::assumeLoggedIn((int) self::$ownerId);
        Auth::forgetCache();
    }

    public function tearDown(): void
    {
        Auth::forgetCache();
        unset($_GET, $_POST, $_FILES, $_SESSION['_flash']);
        self::cleanupResiduals();
        foreach (self::$temporales as $ruta) {
            @unlink($ruta);
        }
        self::$temporales = [];
    }

    // =====================================================================
    // 1. El panel solo en `roster`, y la allowlist intacta
    // =====================================================================

    public function testElListadoSoloSaleEnModoRoster(): void
    {
        self::modo(FormSettings::ACCESS_PUBLIC);
        assertTrue(
            !str_contains(self::tarjeta(), 'id="participantes"'),
            'en «Público» no hay lista de participantes'
        );

        self::modo(FormSettings::ACCESS_ROSTER);
        assertTrue(
            str_contains(self::tarjeta(), 'id="participantes"'),
            'en «Solo el listado» la tarjeta trae el panel'
        );
    }

    public function testLaRamaDeLaAllowlistNoSeHaTocado(): void
    {
        self::modo(FormSettings::ACCESS_ALLOWLIST);
        $html = self::tarjeta();

        assertTrue(str_contains($html, 'id="accessAddForm"'), 'la allowlist sigue con su alta');
        assertTrue(str_contains($html, 'id="accessList"'), 'y con su lista');
        assertTrue(
            !str_contains($html, 'id="participantes"'),
            'y no se le ha colado el panel del listado'
        );

        self::modo(FormSettings::ACCESS_ROSTER);
        $html = self::tarjeta();
        assertTrue(
            !str_contains($html, 'id="accessAddForm"'),
            'en roster no puede aparecer el alta de la allowlist: no usa correos'
        );
    }

    // =====================================================================
    // 2. Las dos zonas, y ninguna escondida de entrada
    // =====================================================================

    public function testLasDosZonasSePintanLasDosYNingunaVieneEscondida(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER);
        $html = self::tarjeta();

        assertTrue(str_contains($html, 'id="vfPaneRosterLista"'), 'la zona de la lista');
        assertTrue(str_contains($html, 'id="vfPaneRosterImportar"'), 'la zona de importar');
        assertEquals(2, substr_count($html, 'data-vf-pane'), 'y son dos, no tres');

        // `\shidden` y no `hidden` a secas: `visually-hidden` (Bootstrap) lleva un
        // guion delante y no es un atributo, así que no cuenta. Lo que no puede
        // aparecer es el atributo `hidden` puesto por el servidor: sin JS no hay
        // nadie que lo quite y el panel de importar se perdería entero.
        assertEquals(
            0,
            preg_match('/\shidden(\s|=|>)/', $html),
            'el servidor NO pinta `hidden`: sin JS se ven las dos zonas'
        );
    }

    public function testElPanelNoUsaElTabDeBootstrap(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER);

        assertEquals(
            0,
            substr_count(self::tarjeta(), 'data-bs-toggle="tab"'),
            'el `tab` de Bootstrap esconde los paneles con CSS: sin JS se pierde uno'
        );
    }

    // =====================================================================
    // 3. Los huecos: los que declara la vista, y ninguno en un `<form>`
    // =====================================================================

    public function testLosHuecosSonLosQueDeclaraLaVistaYNingunoEsUnFormulario(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER);
        $html = self::tarjeta();

        foreach (['rosterStats', 'rosterInvites', 'rosterList', 'rosterCatalog', 'rosterAddForm', 'rosterImportReport'] as $hueco) {
            assertTrue(
                str_contains($html, 'id="' . $hueco . '"'),
                'falta el hueco ' . $hueco
            );
            assertEquals(
                0,
                preg_match('/<form[^>]*\bid="' . $hueco . '"/', $html),
                'el id del hueco ' . $hueco . ' no puede estar en un <form>'
            );
        }
    }

    public function testLasAccionesQueNoSePuedenDeshacerPreguntanAntes(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER);
        // Con una persona en la lista, porque el «quitar» es una fila y una lista
        // vacía no tiene ninguna: contando formularios con el panel recién salido
        // esta comprobación valía 1 y pasaba verde sin mirar el segundo.
        FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => 'V-1', 'nombre' => 'Ana']);
        $html = self::tarjeta();

        // La regla de la casa: cada `data-confirm` trae su `data-confirm-ok` y su
        // tono, porque «Sí, continuar» sobre «¿Eliminar?» no dice qué se acepta.
        preg_match_all('/<form\b[^>]*data-confirm="[^"]*"[^>]*>/', $html, $coincidencias);
        $formularios = $coincidencias[0];
        assertEquals(2, count($formularios), 'vaciar la lista y quitar a alguien preguntan, y no hay un tercero');
        foreach ($formularios as $formulario) {
            assertTrue(str_contains($formulario, 'data-confirm-ok='), 'cada uno dice qué se acepta');
            assertTrue(str_contains($formulario, 'data-confirm-tone='), 'cada uno dice con qué tono');
        }
        assertTrue(str_contains($html, 'data-confirm-tone="danger"'), 'lo que destruye va en rojo');
    }

    // =====================================================================
    // El alta: un campo por atributo, y el correo solo cuando hace falta
    // =====================================================================

    public function testElAltaPideCorreoSoloCuandoElIdentificadorNoEsElCorreo(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER, 'cedula');
        $html = self::panel();

        assertTrue(str_contains($html, 'name="cedula"'), 'con cédula pide la cédula');
        assertEquals(1, substr_count($html, 'name="correo"'), 'y además el correo, que es otra columna');
        assertTrue(str_contains($html, 'id="rosterAddId"'), 'el identificador tiene id propio para el foco');

        self::modo(FormSettings::ACCESS_ROSTER, 'correo');
        $html = self::panel();

        assertTrue(str_contains($html, 'name="correo"'), 'con correo, el identificador ES el correo');
        assertEquals(1, substr_count($html, 'name="correo"'), 'y no puede haber dos casillas para lo mismo');
        assertEquals(0, substr_count($html, 'name="cedula"'), 'ni una cédula fantasma');
    }

    public function testElAltaTraeUnCampoPorAtributoDelCatalogo(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER);
        FormParticipant::ensureAttribute((int) self::$formId, 'Sede');

        $html = self::panel();

        assertTrue(str_contains($html, 'name="data[sede]"'), 'el atributo tiene su campo');
        assertTrue(str_contains($html, 'Sede'), 'con su etiqueta, que es lo que se lee');
    }

    // =====================================================================
    // 4. Cada acción repone lo que cambia, con la búsqueda y el orden de verdad
    // =====================================================================

    public function testElAltaReponeLaListaYLosNumerosYNoElCatalogo(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER);
        FormParticipant::ensureAttribute((int) self::$formId, 'Sede');

        $out = self::llamar('store', ['cedula' => 'V-1', 'nombre' => 'Ana']);
        $html = $out['json']['html'] ?? [];

        assertTrue(isset($html['rosterList']), 'la lista cambia');
        assertTrue(isset($html['rosterStats']), 'los números cambian');
        assertTrue(isset($html['rosterInvites']), 'y el botón de invitar, que acaba de tener una persona más que escribirle');
        assertTrue(!isset($html['rosterCatalog']), 'el alta no cambia el catálogo');
        assertTrue(!isset($html['rosterAddForm']), 'ni el formulario de alta, que el dueño podría estar rellenando');
        assertTrue(str_contains((string) $html['rosterList'], 'V-1'), 'y la fila nueva sale en la lista repuesta');
    }

    public function testBorrarYEditarReponenLaListaYLosNumeros(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER);
        FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => 'V-1', 'nombre' => 'Ana']);
        FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => 'V-2', 'nombre' => 'Luis']);
        $pid = (int) (FormParticipant::findByIdentifier((int) self::$formId, 'cedula', 'V-2')['id'] ?? 0);

        $out = self::llamar('update', ['participant_id' => $pid, 'cedula' => 'V-2', 'nombre' => 'Luis Editado']);
        $html = $out['json']['html'] ?? [];
        assertTrue(isset($html['rosterList'], $html['rosterStats']), 'editar repone lista y números');
        assertTrue(str_contains((string) $html['rosterList'], 'Luis Editado'), 'con el nombre nuevo');

        $out = self::llamar('destroy', ['participant_id' => $pid]);
        $html = $out['json']['html'] ?? [];
        assertTrue(isset($html['rosterList'], $html['rosterStats']), 'borrar repone lista y números');
        assertTrue(
            !str_contains((string) $html['rosterList'], 'V-2'),
            'y la lista repuesta ya no tiene a quien se borró'
        );
    }

    public function testQuitarUnAtributoReponeElCatalogoYElAlta(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER);
        FormParticipant::ensureAttribute((int) self::$formId, 'Sede');
        $clave = FormParticipant::catalogFor((int) self::$formId)[0]['key_name'];

        $out = self::llamar('destroyAttribute', ['key_name' => $clave]);
        $html = $out['json']['html'] ?? [];

        assertTrue(isset($html['rosterCatalog']), 'el catálogo cambia');
        assertTrue(isset($html['rosterAddForm']), 'y el alta, que tenía un campo por atributo');
        assertTrue(
            !isset($html['rosterInvites']),
            'pero NO el botón de invitar: quitar una columna no cambia a quién falta escribirle'
        );
        assertTrue(isset($html['rosterList']), 'y la lista, que tenía el atributo como etiqueta');
        assertTrue(
            !str_contains((string) $html['rosterAddForm'], 'data[sede]'),
            'el alta ya no manda un campo hacia una columna que no está'
        );
    }

    public function testLaBusquedaYElOrdenDelDuenoSeConservanAlRepintar(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER);
        foreach ([['V-1', 'Zeta'], ['V-2', 'Alba'], ['V-3', 'Marta']] as $fila) {
            FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => $fila[0], 'nombre' => $fila[1]]);
        }

        // El dueño está buscando algo que no está y ha ordenado por nombre: si el
        // repintado ignorara `rosterQ` y `rosterSort`, la lista volvería entera y
        // reordenada, y el cambio parecería un fallo de la operación (que sí fue).
        // Lo que se busca no puede ser un PREFIJO de una cédula de la lista —
        // `V-1` casa con `V-1`, `V-10` y `V-19`— porque entonces el filtro
        // parecería no funcionar sin que fuera su culpa.
        $out = self::llamar('store', [
            'cedula'     => 'V-9',
            'rosterQ'    => 'ZZZ',
            'rosterSort' => 'nombre',
        ]);
        $lista = (string) ($out['json']['html']['rosterList'] ?? '');

        assertTrue(str_contains($lista, 'Nadie en la lista coincide'), 'la búsqueda sigue puesta');
        assertTrue(!str_contains($lista, 'V-9'), 'y por eso el alta no se ve en la lista repuesta');
    }

    public function testElOrdenPorNombreSeRespetaEnLaListaRepuesta(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER);
        foreach ([['V-1', 'Zeta'], ['V-2', 'Alba']] as $fila) {
            FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => $fila[0], 'nombre' => $fila[1]]);
        }

        $out = self::llamar('store', ['cedula' => 'V-3', 'rosterSort' => 'nombre']);
        $lista = (string) ($out['json']['html']['rosterList'] ?? '');

        assertTrue(
            strpos($lista, 'Alba') !== false && strpos($lista, 'Zeta') !== false,
            'las dos filas están'
        );
        assertTrue(
            strpos($lista, 'Alba') < strpos($lista, 'Zeta'),
            'y en orden alfabético, no en orden de cédula: elDueño no pidió volver a cédula'
        );
    }

    // =====================================================================
    // 5. El informe de la importación, por los dos caminos
    // =====================================================================

    public function testLaImportacionReponeLosSeisHuecos(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER);
        self::subir([
            ['Cédula', 'Nombre', 'Correo', 'Sede'],
            ['V-1', 'Ana', 'ana@test.local', 'Norte'],
            ['V-2', 'Luis', 'luis@test.local', 'Sur'],
        ]);

        $out = self::llamar('import', ['modo' => ParticipantImporter::MODO_FUSIONAR]);
        $html = $out['json']['html'] ?? [];

        foreach (['rosterList', 'rosterStats', 'rosterInvites', 'rosterCatalog', 'rosterAddForm', 'rosterImportReport'] as $hueco) {
            assertTrue(isset($html[$hueco]), 'la importación repone ' . $hueco);
        }
        // Y el hueco de las invitaciones (fase 10) se repone también. Este
        // formulario se identifica por cédula, así que lo que tiene que decir es
        // la rama de «el enlace va en la columna Código» y NO un botón de correo
        // que este formulario no puede usar.
        assertTrue(
            str_contains((string) $html['rosterInvites'], 'No hay invitaciones por correo'),
            'el hueco de invitar se repone con lo que corresponde a este identificador'
        );
        assertTrue(
            str_contains((string) $html['rosterAddForm'], 'data[sede]'),
            'y el alta ya tiene el campo del atributo que acaba de crear el archivo'
        );
    }

    public function testElInformeSeReponeAunConOkFalso(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER);
        // Una fila sin identificador no se puede guardar; con «reemplazar» eso hace
        // que la importación ENTERA no se haga, y es entonces cuando el informe es
        // lo único que dice qué ha pasado.
        self::subir([
            ['Cédula', 'Nombre'],
            ['V-1', 'Ana'],
            ['', 'Sin cédula'],
        ]);

        $out = self::llamar('import', ['modo' => ParticipantImporter::MODO_REEMPLAZAR]);
        $json = $out['json'];

        assertFalse((bool) ($json['ok'] ?? true), 'no se importó nada');
        assertTrue(
            isset($json['html']['rosterImportReport']),
            'y el informe se repone igual: es cuando más falta hace'
        );
        $informe = (string) $json['html']['rosterImportReport'];
        assertTrue(str_contains($informe, 'Fila 3'), 'el informe dice qué fila');
        assertTrue(str_contains($informe, 'no se pudieron guardar'), 'y que no entraron');
    }

    public function testElInformeLlegaTambienSinJavaScript(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER);
        self::subir([
            ['Cédula', 'Nombre'],
            ['V-1', 'Ana'],
            ['', 'Sin cédula'],
        ]);

        // Sin la cabecera de `vfPost()` el POST es nativo: redirect y nada de JSON.
        $out = self::llamar('import', ['modo' => ParticipantImporter::MODO_FUSIONAR], false);

        assertEquals(
            '/forms/' . (int) self::$formId . '#participantes',
            $out['halt']['url'] ?? '',
            'sin JS es un 302 a la ficha, al ancla del panel'
        );

        $informe = Session::takeValue('roster_report');
        assertTrue(is_array($informe), 'el informe quedó en la sesión para el GET que viene');
        assertEquals(2, (int) ($informe['total'] ?? 0), 'con el total de filas del archivo');
        assertEquals(1, (int) ($informe['omitidos'] ?? 0), 'y las omitidas');
        assertTrue(($informe['problemas'][0]['fila'] ?? 0) === 3, 'con el número de fila de la que no entró');

        // Y de un solo uso: en la siguiente visita no está, porque si se quedara
        // el dueño leería el mismo informe cada vez que guarda los ajustes.
        assertTrue(Session::takeValue('roster_report') === null, 'el informe es de un solo uso');
    }

    public function testElInformeNoSeGuardaCuandoNoHuboImportacion(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER);

        assertTrue(
            !str_contains(self::panel(), 'alert-warning'),
            'sin importación no hay un aviso de algo que pasó'
        );
    }

    // =====================================================================
    // Los números y la página
    // =====================================================================

    public function testLosNumerosSonDelFormularioEnteroYNoDeLoFiltrado(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER);
        // Cédulas con dos dígitos: la búsqueda es un `LIKE '%…%'`, así que `V-1`
        // también casa con `V-10`…`V-19` y el filtro que se quiere probar aquí
        // («una fila de las 26») devolvería once.
        for ($i = 1; $i <= 26; $i++) {
            $cedula = sprintf('V-%02d', $i);
            FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => $cedula, 'nombre' => 'P' . $i]);
        }
        // Tres han respondido de los 26.
        foreach ([1, 2, 3] as $i) {
            $fila = FormParticipant::findByIdentifier((int) self::$formId, 'cedula', sprintf('V-%02d', $i));
            FormParticipant::marcarRespondido((int) $fila['id']);
        }

        $datos = RosterPanel::data((int) self::$formId, 'V-01');

        assertEquals(1, (int) $datos['total'], 'la lista filtrada trae una fila');
        assertEquals(1, count($datos['filas']), 'y solo una tarjeta');
        assertEquals(
            3,
            (int) $datos['respondidos'],
            'pero los respondedores son de la lista ENTERA: el dueño quiere saber cuánto de SU lista ha respondido'
        );
    }

    public function testLaPaginaNoSeSaleDelRango(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER);
        for ($i = 1; $i <= 26; $i++) {
            FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => 'V-' . $i]);
        }

        $datos = RosterPanel::data((int) self::$formId, '', 99);

        assertEquals(2, (int) $datos['page'], 'la página 99 de un archivo de 26 se recorta a la última');
        assertEquals(1, count($datos['filas']), 'que trae la fila que sobraba');
        assertEquals(25, RosterPanel::POR_PAGINA, 'y el tamaño de página es el del panel');
    }

    // =====================================================================
    // El interruptor de pestañas (markup y JS, sin navegador)
    // =====================================================================

    public function testElInterruptorDePestabasLoPoneElJsYNoElServidor(): void
    {
        $js = (string) file_get_contents(self::JS);

        assertTrue(
            str_contains($js, "querySelectorAll('[data-vf-tabs]')"),
            'el interruptor existe: sin él las dos zonas se verían siempre'
        );
        assertTrue(str_contains($js, "panel.setAttribute('hidden', '')"), 'y lo que hace es poner `hidden`');
        assertTrue(
            str_contains($js, "panel.removeAttribute('hidden')"),
            'que se quita del panel elegido, y no de los otros dos al revés'
        );
    }

    public function testLaPestabaDelArranqueNoSeGuarda(): void
    {
        $js = self::sinComentarios((string) file_get_contents(self::JS));

        assertTrue(
            str_contains($js, 'pintar(inicial, false)'),
            'el `hidden` de arranque no se guarda: si se guardara, la primera visita '
            . 'fijaría la pestaña para siempre y un cambio del valor por defecto no llegaría a nadie'
        );
        assertTrue(str_contains($js, "guardado(clave, elegido.id)"), 'y el que sí se guarda es el que elige alguien');
    }

    public function testLaPestabaSeRecuerdaPorPantalla(): void
    {
        $js = self::sinComentarios((string) file_get_contents(self::JS));

        assertTrue(
            str_contains($js, "'vf:tab:'"),
            'la memoria es por pantalla, como la de los grupos: `vf:tab:<clave>`'
        );
        assertTrue(
            !str_contains($js, "'vf:tab:' + formId") && !str_contains($js, "getItem('vf:tab:' + document"),
            'y la clave sale del contenedor, no de una variable global'
        );
    }

    /**
     * El repintado de la tarjeta de Acceso vuelve a inicializar las pestañas.
     *
     * Las pestañas viven DENTRO de `#vfSlotAccess` (`_roster_panel.php`), y
     * guardar los ajustes repone ese hueco entero con `innerHTML` (lo manda
     * `FormPanel::accessSlot()`). El HTML nuevo trae las dos zonas SIN `hidden`
     * —a propósito, para el navegador sin JS—, así que sin reinicializarlas se
     * ven las dos a la vez: la regresión que este test ata. `app.js` las
     * reinicializa después de reponer los huecos y el arranque es idempotente
     * por `data-vf-tabs-ready`.
     */
    public function testElRepintadoDeLaTarjetaReinicializaLasPestabas(): void
    {
        $js = (string) file_get_contents(self::JS);
        $app = (string) file_get_contents(__DIR__ . '/../../public/assets/js/app.js');

        assertTrue(
            str_contains($js, 'window.vfInitTabs = initTabs'),
            'el arranque de pestañas se expone para poder reejecutarlo tras un repintado'
        );
        assertTrue(
            str_contains($js, "getAttribute('data-vf-tabs-ready') !== '1'"),
            'y salta los contenedores ya inicializados: sin eso, cada repintado de'
                . ' cualquier hueco acumularía un `click` por pestaña'
        );
        assertTrue(
            str_contains($js, "setAttribute('data-vf-tabs-ready', '1')"),
            'marcando el contenedor que inicializó'
        );
        assertTrue(
            str_contains($app, 'window.vfInitTabs()'),
            'app.js tiene que reinicializarlas al reponer los huecos: la tarjeta de'
                . ' Acceso es el hueco `vfSlotAccess` y guardar ajustes la repinta'
        );
    }

    public function testElPanelEsUnaTablaConEstilo(): void
    {
        $css = self::sinComentariosDeBloque((string) file_get_contents(self::CSS));

        assertTrue(
            str_contains($css, '.vf-tabs [data-vf-pane][hidden]'),
            'el refuerzo de `hidden`: sin él, cualquier `display` de autor gana al del navegador '
            . 'y la pestaña se queda pegada abierta'
        );
        assertTrue(
            str_contains($css, '.vf-tabs details > summary'),
            'el `<summary>` es un control y lo tiene que decir el CSS'
        );
    }

    // =====================================================================
    // La plantilla
    // =====================================================================

    public function testLaPlantillaNoEsDeTodosLosFormularios(): void
    {
        // Es una hoja en blanco, no tiene datos de nadie, pero el listado de un
        // formulario es asunto de su dueño: este GET tiene que pasar por
        // `canEdit()` antes de existir, o sirve para preguntar «¿este formulario
        // tiene listado?» a cualquier usuario con sesión.
        self::modo(FormSettings::ACCESS_PUBLIC);
        $out = self::llamar('template', []);

        assertEquals(404, $out['halt']['code'] ?? 0, 'en un formulario sin listado, 404');

        $rutas = (string) file_get_contents(self::RUTAS);
        assertTrue(
            str_contains($rutas, "'/forms/{id}/participants/template'"),
            'y la ruta existe para cuando sí hay listado'
        );
        assertTrue(
            (bool) preg_match(
                "#/forms/\{id\}/participants/template',\s*\[[^]]+\],\s*\['auth'\]#",
                $rutas
            ),
            'protegida por sesión, como todo lo demás del panel'
        );
    }

    // =====================================================================
    // A Fixture
    // =====================================================================

    /** La tarjeta de Acceso tal como la pinta la ficha, en el modo que hay. */
    private static function tarjeta(): string
    {
        $formId = (int) self::$formId;
        $mode = FormAccess::mode(Form::settings($formId));

        return View::partial('dashboard/forms/_access_card', [
            'form'        => Form::find($formId),
            'accessMode'  => $mode,
            'accessList'  => [],
            'accessCount' => 0,
            'roster'      => $mode === FormSettings::ACCESS_ROSTER ? RosterPanel::data($formId) : null,
            'informe'     => null,
        ]);
    }

    /** Solo el panel, para las pruebas que no necesitan la tarjeta entera. */
    private static function panel(): string
    {
        return RosterPanel::panel(RosterPanel::data((int) self::$formId), Form::find((int) self::$formId));
    }

    /**
     * @param array<string,string> $post
     *
     * @return array{json:array<string,mixed>,halt:array<string,mixed>,html:string}
     */
    private static function llamar(string $accion, array $post = [], bool $json = true): array
    {
        $_POST = $post;
        $_GET = [];
        // `$_FILES` NO se vacía aquí: la importación deja el `.xlsx` puesto justo
        // antes de llamar, y vaciarlo aquí convertía las tres pruebas de la
        // importación en «no se subió ningún archivo» — con un `$html` sin ningún
        // hueco, que es el síntoma que tenía el fallo de verdad.
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '198.51.100.9';
        if ($json) {
            $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        } else {
            unset($_SERVER['HTTP_X_REQUESTED_WITH']);
        }

        Session::set('_csrf_token', 'token-de-prueba');
        $_POST['_token'] = 'token-de-prueba';
        Request::setRequestBodyForTests(null);
        Request::resetJsonState();
        Response::setRecordHalt(false);
        Response::setRecordHalt(true);

        $controlador = new \App\Controllers\ParticipantController();
        ob_start();
        try {
            $controlador->{$accion}((int) self::$formId);
        } finally {
            $html = (string) ob_get_clean();
        }

        $halt = Response::lastHalt();
        Response::setRecordHalt(false);

        $decodificado = [];
        if ($html !== '' && str_starts_with(ltrim($html), '{')) {
            $json2 = json_decode(ltrim($html), true);
            if (is_array($json2)) {
                $decodificado = $json2;
            }
        }

        return ['json' => $decodificado, 'halt' => $halt, 'html' => $html];
    }

    /**
     * Un `.xlsx` de verdad en `$_FILES`, escrito por el escritor de la casa.
     *
     * Se genera con `RosterTemplate::exportar()` a propósito: el importador tiene
     * que poder leer lo que el escritor escribe, y un `.xlsx` hecho a mano en el
     * test dejaría pasar por bueno un archivo que el usuario no podría producir.
     *
     * @param list<list<string>> $filas
     */
    private static function subir(array $filas): void
    {
        $cabeceras = array_shift($filas);
        $participantes = [];
        $n = 0;
        foreach ($filas as $fila) {
            $n++;
            // Un `foreach` y no un `array_reduce`: `array_reduce` pasa el valor
            // acumulado y el elemento, no el índice, así que el índice del
            // atributo hay que sacarlo de otra parte. Con tres atributos eso da
            // un `Undefined array key` silencioso que se ve como una celda vacía
            // en el `.xlsx`, y el importador aceptaría el archivo «vacío».
            $data = [];
            foreach (array_slice($cabeceras, 3) as $i => $cabecera) {
                $data[FormParticipant::slug((string) $cabecera)] = (string) ($fila[$i + 3] ?? '');
            }
            $participantes[] = [
                'id'               => $n,
                'cedula'           => (string) ($fila[0] ?? ''),
                'nombre'           => (string) ($fila[1] ?? ''),
                'correo'           => (string) ($fila[2] ?? ''),
                'indicador'        => '',
                'identifier_value' => (string) ($fila[0] ?? ''),
                'code'             => strtoupper(bin2hex(random_bytes(8))),
                'responded_at'     => null,
                'created_at'       => date('Y-m-d H:i:s'),
                'data'             => $data,
            ];
        }

        $atributos = [];
        foreach (array_slice($cabeceras, 3) as $cabecera) {
            $atributos[] = [
                'key_name' => FormParticipant::slug((string) $cabecera),
                'label'    => (string) $cabecera,
                'position' => count($atributos),
            ];
        }

        $binario = RosterTemplate::exportar($participantes, $atributos, []);
        $ruta = sys_get_temp_dir() . '/vf-roster-panel-' . bin2hex(random_bytes(6)) . '.xlsx';
        self::$temporales[] = $ruta;
        file_put_contents($ruta, $binario);

        $_FILES['archivo'] = [
            'name'     => 'lista.xlsx',
            'type'     => '',
            'tmp_name' => $ruta,
            'error'    => UPLOAD_ERR_OK,
            'size'     => strlen($binario),
        ];
    }

    private static function modo(string $modo, string $identificador = 'cedula'): void
    {
        $db = Database::getInstance(Config::db());
        $fila = $db->fetch('SELECT settings FROM forms WHERE id = :id', ['id' => (int) self::$formId]);
        $ajustes = $fila !== null && is_string($fila['settings']) ? (json_decode($fila['settings'], true) ?: []) : [];
        $ajustes['access'] = ['mode' => $modo, 'identifier' => $identificador];
        $db->execute(
            'UPDATE forms SET settings = :s WHERE id = :id',
            ['s' => json_encode($ajustes), 'id' => (int) self::$formId]
        );
    }

    /** El JS sin comentarios: un `str_contains` sobre el archivo pasa por el comentario. */
    private static function sinComentarios(string $js): string
    {
        return (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $js);
    }

    private static function sinComentariosDeBloque(string $css): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', $css);
    }

    private static function cleanupResiduals(): void
    {
        Database::getInstance(Config::db())->execute("DELETE FROM users WHERE email LIKE 'rosterpanel-%'");
        self::$ownerId = null;
        self::$formId = null;
    }
}
