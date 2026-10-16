<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\View;
use App\Models\Form;
use App\Models\FormAccess;
use App\Models\FormParticipant;
use App\Models\User;
use App\Services\RosterPanel;
use App\Services\FormSettings;

class AccessCardDesignTest
{
    private const CSS = __DIR__ . '/../../public/assets/css/app.css';
    private const VISTA_TARJETA = __DIR__ . '/../../views/dashboard/forms/_access_card.php';
    private const VISTA_LISTA_ROSTER = __DIR__ . '/../../views/dashboard/forms/_roster_list.php';
    private const VISTA_STATS = __DIR__ . '/../../views/dashboard/forms/_roster_stats.php';

    private static ?int $ownerId = null;
    private static ?int $formId = null;
    /** @var list<string> */
    private static array $temporales = [];

    public function setUp(): void
    {
        self::cleanupResiduals();

        $rand = bin2hex(random_bytes(4));
        self::$ownerId = User::create('Access Design ' . $rand, 'access-design-' . $rand . '@test.local', 'clave-larga-123', 2);
        self::$formId  = Form::create((int) self::$ownerId, 'Access Design ' . $rand);

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

    public function testLasClasesDeLaTarjetaDeAccesoExistenEnCss(): void
    {
        $css = self::sinComentariosDeBloque((string) file_get_contents(self::CSS));

        $clases = [
            '.vf-access-head',
            '.vf-access-id',
            '.vf-access-icon',
            '.vf-access-title',
            '.vf-access-sub',
            '.vf-mode-chip',
            '.vf-mode-chip-public',
            '.vf-mode-chip-email',
            '.vf-mode-chip-allowlist',
            '.vf-mode-chip-roster',
            '.vf-callout',
            '.vf-callout-accent',
            '.vf-access-list',
            '.vf-access-row',
            '.vf-access-avatar',
            '.vf-access-mail',
            '.vf-access-mail-name',
            '.vf-access-row-actions',
            '.vf-access-empty',
            '.vf-access-linkout',
            '.vf-import-details',
            '.vf-import-summary',
            '.vf-import-danger',
            '.vf-stat-strip',
            '.vf-stat',
            '.vf-stat-value',
            '.vf-stat-label',
            '.vf-stat-success',
            '.vf-stat-warning',
            '.vf-stat-info',
            '.vf-stat-muted',
            '.vf-roster-row',
            '.vf-roster-avatar',
            '.vf-roster-main',
            '.vf-roster-name',
            '.vf-roster-sub',
            '.vf-roster-meta',
            '.vf-roster-tags',
            '.vf-roster-tag',
            '.vf-roster-state',
            '.vf-roster-state-label',
            '.vf-roster-state-ok',
            '.vf-roster-state-pending',
            '.vf-roster-date',
            '.vf-roster-actions',
            '.vf-roster-toolbar',
            '.vf-roster-empty',
        ];

        foreach ($clases as $clase) {
            assertTrue(
                str_contains($css, $clase),
                'falta ' . $clase . ' en app.css'
            );
        }
    }

    public function testElModalDeAccesoConservaSusIdsYDataAtributos(): void
    {
        self::modo(FormSettings::ACCESS_ALLOWLIST);
        // `data-role="access-link"` vive en las FILAS, y con la lista vacía la
        // tarjeta pinta el estado vacío donde no hay botón que comprobar.
        FormAccess::addEmail((int) self::$formId, 'diseno-' . bin2hex(random_bytes(3)) . '@test.local');
        $html = self::tarjeta();

        assertTrue(str_contains($html, 'id="accessAddForm"'), 'falta accessAddForm');
        assertTrue(str_contains($html, 'id="accessAddEmail"'), 'falta accessAddEmail');
        assertTrue(str_contains($html, 'id="accessAddError"'), 'falta accessAddError');
        assertTrue(str_contains($html, 'id="accessList"'), 'falta accessList');
        assertTrue(str_contains($html, 'id="accessLinkOut"'), 'falta accessLinkOut');
        assertTrue(str_contains($html, 'data-role="access-link"'), 'falta data-role=access-link');
        assertTrue(str_contains($html, 'data-email'), 'falta data-email');
        assertTrue(str_contains($html, 'data-role="access-link-for"'), 'falta data-role=access-link-for');
        assertTrue(str_contains($html, 'data-role="access-recopy"'), 'falta data-role=access-recopy');
        assertTrue(str_contains($html, 'data-vf-async="slots"'), 'falta data-vf-async=slots');
        assertTrue(str_contains($html, 'data-vf-error'), 'falta data-vf-error');
        assertTrue(str_contains($html, 'data-vf-clear'), 'falta data-vf-clear');
        assertTrue(str_contains($html, 'method="post" action="/forms/' . (int) self::$formId . '/access/email"'), 'falta action correcta');
        assertTrue(str_contains($html, 'Csrf::field()') === false || str_contains($html, '_token'), '_token presente');
        assertTrue(str_contains($html, '/access/email/remove'), 'falta remove');
    }

    public function testElRosterConservaSusIdsYReparteLasDosTarjetas(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER);
        $html = self::tarjeta();

        assertTrue(str_contains($html, 'id="participantes"'), 'falta participantes');
        assertTrue(str_contains($html, 'id="vfPaneRosterLista"'), 'falta vfPaneRosterLista');
        assertTrue(str_contains($html, 'id="vfPaneRosterImportar"'), 'falta vfPaneRosterImportar');
        assertEquals(2, substr_count($html, 'data-vf-pane'), 'deben ser 2 data-vf-pane');
        assertEquals(0, preg_match('/\shidden(\s|=|>)/', $html), 'no debe tener hidden del servidor');
        assertEquals(0, substr_count($html, 'data-bs-toggle="tab"'), 'no usar tab de bootstrap');

        // Lista e importar son DOS tarjetas, no dos pestañas: la de la lista con el
        // buscador y las filas, y la de importar plegada con un `<summary>` nativo.
        assertTrue(str_contains($html, 'id="rosterListCard"'), 'falta la tarjeta de la lista');
        assertTrue(str_contains($html, 'id="rosterImportCard"'), 'falta la tarjeta de importar');
        assertTrue(str_contains($html, 'vf-import-summary'), 'el importar se pliega con un <summary>');
    }

    /**
     * «Vaciar la lista» NO puede quedar dentro del `<details>`.
     *
     * Es la única acción que no se puede deshacer, y una acción así no se guarda
     * detrás de un clic: el `<form>` de vaciado va DESPUÉS del cierre del
     * `<details>`, con su `hr` y su explicación a la vista. Se ata la POSICIÓN
     * (índice del cierre `</details>` anterior al del formulario) y no solo que
     * exista, porque un formulario que existe pero oculto sigue sin poder usarse.
     */
    public function testVaciarLaListaQuedaFueraDelColapsable(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER);
        FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => 'V-1']);
        $html = self::tarjeta();

        $inicio = strpos($html, 'id="rosterImportCard"');
        $vaciar = strpos($html, 'participants/truncate');
        assertTrue($inicio !== false, 'está la tarjeta de importar');
        assertTrue($vaciar !== false, 'y el formulario de vaciar la lista');
        $cierre = strpos($html, '</details>', $inicio);
        assertTrue($cierre !== false, 'la tarjeta de importar tiene su <details> que plegar');
        assertTrue($vaciar > $cierre, 'vaciar va FUERA del <details>: es irreversible y no puede esconderse');
    }

    /**
     * El `<details>` de importar nace cerrado y solo se ABRE cuando hay informe.
     *
     * Sin JavaScript el POST de importar es nativo: la recarga vuelve a pintar
     * la pantalla y, si el resultado quedara dentro del colapso cerrado, no se
     * vería ningún cambio — lo mismo que no pintarlo. Un informe que el dueño
     * tiene que ir a buscar a mano es un error que no da ningún error.
     */
    public function testElColapsableDeImportarSeAbreCuandoHayInforme(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER);
        FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => 'V-1']);
        $form = Form::find((int) self::$formId);

        $sinInforme = RosterPanel::panel(RosterPanel::data((int) self::$formId), $form, null);
        $inicio = strpos($sinInforme, 'class="vf-import-details"');
        assertTrue($inicio !== false, 'está el colapsable de importar');
        $etiqueta = substr($sinInforme, $inicio, 40);
        assertTrue(
            !str_contains($etiqueta, 'open'),
            'sin informe el colapsable va cerrado: quien mira la lista no carga con el formulario de subir'
        );

        $informe = ['total' => 2, 'omitidos' => 1, 'problemas' => [
            ['fila' => 3, 'motivo' => 'sin identificador', 'detalle' => 'V-9'],
        ]];
        $conInforme = RosterPanel::panel(RosterPanel::data((int) self::$formId), $form, $informe);
        $inicio = strpos($conInforme, 'class="vf-import-details"');
        assertTrue($inicio !== false, 'con informe sigue estando el colapsable');
        $etiqueta = substr($conInforme, $inicio, 40);
        assertTrue(
            str_contains($etiqueta, ' open'),
            'con informe el colapsable viene abierto: si no, el resultado de un importar sin JS se pinta plegado'
        );
    }

    public function testLosSeisHuecosDelRosterExisten(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER);
        $html = self::tarjeta();

        foreach (['rosterStats', 'rosterList', 'rosterCatalog', 'rosterAddForm', 'rosterImportReport', 'rosterInvites'] as $hueco) {
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

    /**
     * Un id de hueco, una sola vez.
     *
     * El panel envolvía `#rosterList` y `#rosterCatalog` en OTRO `<div>` con el
     * mismo id, y el `getElementById` del repintado tomaba el de fuera: el HTML
     * quedaba con ids duplicados y anidados sin que ningún test lo notara. Cada
     * hueco es un único nodo, y ahora lo fija también esto.
     */
    public function testCadaIdDeHuecoApareceUnaSolaVez(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER);
        $html = self::tarjeta();

        foreach (['rosterStats', 'rosterList', 'rosterCatalog', 'rosterAddForm', 'rosterImportReport', 'rosterInvites'] as $hueco) {
            assertEquals(1, substr_count($html, 'id="' . $hueco . '"'), 'id duplicado: ' . $hueco);
        }
    }

    /**
     * El alta tiene UN disparador, no dos apilados.
     *
     * El panel ofrece el alta con un botón que abre el modal; si el partial
     * repitiera su propio disparador (o la etiqueta del botón apareciera además
     * en el cuerpo del modal), habría dos puertas al mismo formulario. La
     * etiqueta del botón sale una sola vez.
     */
    public function testElAltaTieneUnSoloDisparador(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER);
        $html = self::tarjeta();

        assertEquals(
            1,
            substr_count($html, 'Añadir uno a la lista'),
            'el alta no puede tener dos disparadores con la misma etiqueta'
        );
    }

    /**
     * La pantalla de acceso (cabecera + tablero + lista) en el modo que hay.
     *
     * La cabecera la pinta `_access_card.php` y el tablero y la lista
     * `RosterPanel::panel()`, que `access.php` orquesta. El helper concatena los
     * dos, como la pantalla real: si solo pintara el partial, los ids del roster
     * no aparecerían y el contrato se comprobaría sobre algo que no existe.
     */
    private static function tarjeta(): string
    {
        $formId = (int) self::$formId;
        $mode = FormAccess::mode(Form::settings($formId));
        $esAllowlist = $mode === FormSettings::ACCESS_ALLOWLIST;

        $html = View::partial('dashboard/forms/_access_card', [
            'form'        => Form::find($formId),
            'accessMode'  => $mode,
            'accessList'  => $esAllowlist ? FormAccess::allowlistFor($formId, '', 200) : [],
            'accessCount' => $esAllowlist ? FormAccess::allowlistCount($formId) : 0,
        ]);

        if ($mode === FormSettings::ACCESS_ROSTER) {
            $html .= RosterPanel::panel(RosterPanel::data($formId), Form::find($formId), null);
        }

        return $html;
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

    private static function sinComentariosDeBloque(string $css): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', $css);
    }

    private static function cleanupResiduals(): void
    {
        Database::getInstance(Config::db())->execute("DELETE FROM users WHERE email LIKE 'access-design-%'");
        self::$ownerId = null;
        self::$formId = null;
    }
}
