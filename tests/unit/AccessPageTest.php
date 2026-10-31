<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\FormController;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Response;
use App\Models\Form;
use App\Models\FormParticipant;
use App\Models\User;
use App\Services\FormSettings;

/**
 * La pantalla de acceso y listado de participantes (`GET /forms/{id}/access`).
 *
 * Antes era una tarjeta al final de la ficha. Se separó a una pantalla propia y
 * el rediseño le dio la UX del módulo `/admin`: alta y edición en modal, menú de
 * fila `⋯`, paginador compartido. Lo que este test ata es el contrato de la
 * PANTALLA entera, que los partials por separado no ven:
 *
 *  1. Se sirve con `canEdit` y pinta la tarjeta `#access` (la misma que antes
 *     vivía en la ficha).
 *  2. En modo `roster` trae el panel y los DOS modales (`#rosterAddModal` y
 *     `#rosterEditModal`), con el cableado que `dashboard.js` necesita:
 *     `data-vf-modal-move`, el `⋯` de cada fila (`data-vf-participant`) y su caja
 *     `#rowactions-<pid>`.
 *  3. Los modales van a nivel SUPERIOR del fragmento, no dentro del panel: un
 *     `.modal` dentro de otro elemento lo descarta el parser y `ViewStructureTest`
 *     lo prohíbe.
 *  4. La ficha ya no pinta la tarjeta y sí enlaza con esta pantalla.
 *  5. La búsqueda del listado, con `X-Requested-With`, devuelve solo el hueco
 *     `rosterList` en JSON; sin esa cabecera, la misma ruta sirve la pantalla
 *     entera.
 */
class AccessPageTest
{
    private const VISTA_FICHA = __DIR__ . '/../../views/dashboard/forms/show.php';

    private static ?int $ownerId = null;
    private static ?int $formId = null;

    public function setUp(): void
    {
        self::cleanupResiduals();

        $rand = bin2hex(random_bytes(4));
        self::$ownerId = User::create('Access Page ' . $rand, 'accesspage-' . $rand . '@test.local', 'clave-larga-123', 2);
        self::$formId  = Form::create((int) self::$ownerId, 'Access Page ' . $rand);

        Auth::assumeLoggedIn((int) self::$ownerId);
        Auth::forgetCache();
    }

    public function tearDown(): void
    {
        Auth::forgetCache();
        unset($_GET, $_POST, $_SESSION['_flash']);
        self::cleanupResiduals();
    }

    public function testLaPantallaSeSirveYPintaLaTarjetaDeAcceso(): void
    {
        self::modo(FormSettings::ACCESS_ALLOWLIST);

        $html = self::pagina();

        assertTrue(str_contains($html, 'id="access"'), 'la pantalla trae la tarjeta con su id');
        assertTrue(str_contains($html, 'id="accessAddForm"'), 'y, en allowlist, su alta');
        assertTrue(str_contains($html, 'Volver al formulario'), 'con la vuelta a la ficha');
        assertTrue(
            !str_contains($html, 'id="rosterAddModal"'),
            'sin listado no se pintan los modales del roster'
        );
    }

    public function testEnRosterLaPantallaTraeElPanelYLosDosModalesCableados(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER);
        FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => 'V-1', 'nombre' => 'Ana']);
        $pid = (int) (FormParticipant::findByIdentifier((int) self::$formId, 'cedula', 'V-1')['id'] ?? 0);

        $html = self::pagina();

        assertTrue(str_contains($html, 'id="participantes"'), 'el panel del listado');
        assertTrue(str_contains($html, 'id="rosterAddModal"'), 'el modal del alta');
        assertTrue(str_contains($html, 'id="rosterEditModal"'), 'el modal compartido de edición');
        assertTrue(
            str_contains($html, 'data-vf-modal-move="#rosterAddModal"'),
            'el montaje del alta apunta a su modal: es lo que lee `moverFormulariosInline()`'
        );
        assertTrue(
            (bool) preg_match('/<form\b[^>]*\bdata-vf-modal-form\b/', $html),
            'lo que se mueve al modal es el formulario, no su contenedor: mover el contenedor y ocultarlo deja el diálogo vacío'
        );
        assertTrue(
            !preg_match('/<div\b[^>]*\bdata-vf-modal-move="#rosterAddModal"[^>]*\bdata-vf-modal-form\b/', $html),
            'el contenedor no puede ser a la vez el nodo que se mueve y el que se oculta'
        );
        assertTrue(str_contains($html, 'data-vf-participant="' . $pid . '"'), 'el `⋯` de la fila');
        assertTrue(str_contains($html, 'id="rowactions-' . $pid . '"'), 'y su caja de acciones');
    }

    /**
     * La caja de acciones tiene UN solo hijo, que es el panel que viaja al modal.
     *
     * `initAccionesDeFila()` mueve `caja.firstElementChild` y, al cerrar, lo
     * devuelve con `appendChild()`. Con los dos formularios (editar y quitar)
     * como hijos sueltos, el que había viajado volvía al final: el clic
     * siguiente movía el otro y el modal alternaba entre «formulario + Guardar»
     * y «solo Quitar». El panel envuelve las dos acciones para que el primer
     * hijo sea siempre el mismo.
     */
    public function testLaCajaDeAccionesTieneUnSoloHijoYElModalNoAlterna(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER);
        FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => 'V-1', 'nombre' => 'Ana']);
        $pid = (int) (FormParticipant::findByIdentifier((int) self::$formId, 'cedula', 'V-1')['id'] ?? 0);

        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . self::pagina());
        libxml_clear_errors();
        $xpath = new \DOMXPath($doc);

        $cajas = $xpath->query('//*[@id="rowactions-' . $pid . '"]');
        assertSame(1, $cajas->length, 'la fila tiene su caja de acciones');

        $caja = $cajas->item(0);
        $hijos = 0;
        foreach ($caja->childNodes as $nodo) {
            if ($nodo->nodeType === XML_ELEMENT_NODE) {
                $hijos++;
            }
        }
        assertSame(1, $hijos, 'la caja tiene un único hijo: el panel que se mueve al modal');
        assertSame(
            2,
            $xpath->query('.//form', $caja)->length,
            'el panel conserva las dos acciones: editar y quitar'
        );
        assertSame(
            1,
            $xpath->query('.//form[@id="rosterEditForm' . $pid . '"]', $caja)->length,
            'y el formulario de edición sigue siendo el que se mueve, no el de borrar'
        );

        $columnas = $xpath->query('.//form[@id="rosterEditForm' . $pid . '"]/*[contains(concat(" ", normalize-space(@class), " "), " row ")]/*[starts-with(normalize-space(@class), "col-")]');
        assertTrue($columnas->length >= 4, 'el formulario de edición pinta sus campos');
        foreach ($columnas as $columna) {
            assertTrue(
                str_contains(' ' . $columna->getAttribute('class') . ' ', ' col-12 '),
                'todos los campos van en una sola columna, como el alta'
            );
        }
    }

    /**
     * La ficha ya no lleva la tarjeta, pero sí el botón que trae aquí.
     *
     * Es la mitad que se rompe sola: mover la tarjeta y dejar la ficha sin enlace
     * deja la pantalla inalcanzable sin escribir la URL a mano.
     */
    public function testLaFichaYaNoPintaLaTarjetaYEnlazaConLaPantalla(): void
    {
        $vista = (string) file_get_contents(self::VISTA_FICHA);

        assertFalse(str_contains($vista, 'id="access"'), 'la ficha ya no pinta la tarjeta de Acceso');
        assertTrue(
            str_contains($vista, '/forms/<?= (int) $form[\'id\'] ?>/access'),
            'y enlaza con la pantalla de participantes'
        );
        assertTrue(str_contains($vista, 'Participantes'), 'con el botón que la abre');
    }

    /**
     * La búsqueda del listado, sin recargar, responde JSON con `rosterList`.
     *
     * El mismo `GET /forms/{id}/access` sirve la pantalla entera (navegador sin
     * JavaScript, enlace compartido) y, cuando el `fetch` de `dashboard.js` manda
     * `X-Requested-With`, solo el hueco `rosterList` con lo que pide `q`. Esa
     * cabecera es lo único que decide: si cambiara a otra señal (un parámetro de
     * la URL, un header privado) el buscador caería en la otra rama y volvería a
     * recargar la página entera.
     *
     * Y el `return;` tras `Response::json()` es contrato, no estilo: en CLI
     * `halt()` no sale, así que un controlador que no vuelve seguiría pintando la
     * pantalla DESPUÉS del JSON y `res.json()` de JavaScript se rompería.
     */
    public function testLaBusquedaPorAjaxRespondeJSONConElHuecoDeLaLista(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER);
        FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => 'V-1', 'nombre' => 'Ana']);
        FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => 'V-2', 'nombre' => 'Beto']);

        $_GET = ['q' => 'Beto'];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';

        $controlador = new FormController();
        ob_start();
        try {
            $controlador->access((int) self::$formId);
        } finally {
            $salida = (string) ob_get_clean();
        }
        unset($_SERVER['HTTP_X_REQUESTED_WITH']);

        $json = json_decode($salida, true);
        assertTrue(is_array($json), 'la respuesta es JSON, no la página entera');
        assertTrue(($json['ok'] ?? null) === true, 'y dice que fue bien');
        assertTrue(isset($json['html']['rosterList']), 'con el hueco de la lista dentro');
        $lista = (string) ($json['html']['rosterList'] ?? '');
        assertTrue(str_contains($lista, 'id="rosterList"'), 'que es el markup real de la lista');
        assertTrue(str_contains($lista, 'Beto'), 'con lo que filtró');
        assertTrue(!str_contains($lista, 'Ana'), 'y dejó fuera lo que no coincide');
        assertTrue(
            !str_contains($salida, 'id="access"'),
            'no sigue pintando la pantalla entera debajo del JSON'
        );
    }

    /**
     * Sin la cabecera, el mismo GET sigue sirviendo la página entera.
     *
     * Es el camino sin JavaScript (y el de un enlace copiado): nada de esto se
     * rompe porque no exista `fetch`. Se comprueba aparte porque los dos caminos
     * son la misma ruta y solo los separa una cabecera.
     */
    public function testSinCabeceraJsonSigueSiendoLaPaginaEntera(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER);
        unset($_SERVER['HTTP_X_REQUESTED_WITH']);

        $html = self::pagina();

        assertTrue(str_contains($html, 'id="access"'), 'el camino sin JS es la pantalla completa');
        assertTrue(str_contains($html, 'id="participantes"'), 'y trae el panel');
        assertTrue(!str_contains($html, '"ok":true'), 'no es JSON');
    }

    private static function pagina(): string
    {
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        // Sin esto, una prueba anterior que marcara la cabecera dejaría la pantalla
        // contestando JSON y este helper diría «la página no se pinta».
        unset($_SERVER['HTTP_X_REQUESTED_WITH']);
        Response::setRecordHalt(false);

        $controlador = new FormController();
        ob_start();
        try {
            $controlador->access((int) self::$formId);
        } finally {
            $html = (string) ob_get_clean();
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

    private static function cleanupResiduals(): void
    {
        Database::getInstance(Config::db())->execute("DELETE FROM users WHERE email LIKE 'accesspage-%'");
        self::$ownerId = null;
        self::$formId = null;
    }
}
