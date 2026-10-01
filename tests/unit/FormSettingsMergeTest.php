<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Controllers\FormController;
use App\Models\Form;
use App\Models\User;
use App\Services\FormSettings;

/**
 * Tests de `FormSettings::merge()` / `fromDashboardPost()` y de la puerta que
 * los usa: `POST /forms/{id}/settings` (ajustes operativos en la ficha).
 *
 * Lo que se fija aquí es la razón por la que `merge()` existe: hay DOS puertas
 * que escriben `forms.settings` y ninguna manda el array entero. `normalize()`
 * arranca de `defaults()`, así que guardar un subconjunto con
 * `Form::saveSettings()` pondría en default todo lo demás — el tema volvería al
 * de fábrica cada vez que se guardase el cupo.
 */
class FormSettingsMergeTest
{
    /** @var int|null */
    private $formId = null;

    /** @var int|null */
    private $userId = null;

    private string $csrfToken = '';

    public function setUp(): void
    {
        self::cleanupResiduals();

        $this->userId = User::create('Merge Test', 'merge-' . bin2hex(random_bytes(4)) . '@test.local', 'password', 2);
        $this->formId = Form::create((int) $this->userId, 'Formulario de merge');

        // La puerta es `auth, csrf`, y `updateSettings()` no lo comprueba por su
        // cuenta (lo hace el middleware), así que el token tiene que estar en
        // la sesión como en cualquier POST real.
        $this->csrfToken = Csrf::token();
    }

    public function tearDown(): void
    {
        Session::destroy();
        Response::setRecordHalt(false);
        unset($_POST, $_GET);
        self::cleanupResiduals();
    }

    private static function cleanupResiduals(): void
    {
        $db = Database::getInstance(Config::db());
        $db->execute(
            "DELETE FROM answers WHERE submission_id IN (SELECT id FROM submissions WHERE form_id IN
                (SELECT id FROM forms WHERE title LIKE 'Formulario de merge%'))"
        );
        $db->execute(
            "DELETE FROM submissions WHERE form_id IN (SELECT id FROM forms WHERE title LIKE 'Formulario de merge%')"
        );
        $db->execute("DELETE FROM forms WHERE title LIKE 'Formulario de merge%'");
        $db->execute("DELETE FROM users WHERE email LIKE 'merge-%@test.local'");
    }

    /**
     * Simula el POST de la ficha: el token va en `_token` porque así lo manda
     * `Csrf::field()`, que es lo que hay en el formulario real.
     *
     * @param array<string, mixed> $post
     */
    private function post(array $post): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $post + ['_token' => $this->csrfToken];
        Response::setRecordHalt(true);
    }

    /* ------------------------------------------------------------------
     * merge()
     * ------------------------------------------------------------------ */

    public function testElParchePisaSoloLoQueTraeYDejaLoDemasIntacto(): void
    {
        $antes = [
            'theme'    => ['primary' => '#ff0000', 'background' => '#eeeeee', 'font' => 'Georgia, serif'],
            'quota'    => ['enabled' => true, 'max' => 50],
            'schedule' => ['open_at' => '2026-12-01 08:00', 'close_at' => null],
        ];

        $despues = FormSettings::merge($antes, ['quota' => ['max' => 10]]);

        assertSame(10, $despues['quota']['max']);
        // El resto de `quota` sobrevive: el parche no reconstruye el bloque.
        assertSame(true, $despues['quota']['enabled']);
        assertSame('#ff0000', $despues['theme']['primary']);
        assertSame('Georgia, serif', $despues['theme']['font']);
        assertSame('2026-12-01 08:00', $despues['schedule']['open_at']);
    }

    public function testLaAllowlistRecortaLoQueNoPuedeEscribirLaPuerta(): void
    {
        $antes = ['quota' => ['enabled' => false, 'max' => 7]];

        // La ficha no puede escribir `theme`: aunque el POST lo mande, no se guarda.
        $despues = FormSettings::merge($antes, ['theme' => ['primary' => '#123456']], FormSettings::DASHBOARD_BLOCKS);

        assertNull($despues['theme']['primary']);
        assertSame(7, $despues['quota']['max']);
    }

    public function testLasDosListasCubrenTodosLosBloques(): void
    {
        $bloques = array_keys(FormSettings::defaults());
        $cubiertos = array_merge(FormSettings::DASHBOARD_BLOCKS, FormSettings::BUILDER_BLOCKS);

        sort($bloques);
        sort($cubiertos);
        assertSame($bloques, $cubiertos);
        assertSame(count($cubiertos), count(array_unique($cubiertos)));
    }

    /**
     * El round-trip completo: el builder escribe diseño, la ficha escribe
     * operativo, y entre medias ninguno de los dos se pisa. Es el escenario que
     * justificaba `merge()`, y por eso va contra la BD y no solo con arrays.
     */
    public function testGuardarElTemaNoBorraElCupoYViceversa(): void
    {
        $diseño = FormSettings::merge(
            Form::settings((int) $this->formId),
            ['theme' => ['primary' => '#00ff00']],
            FormSettings::BUILDER_BLOCKS
        );
        Form::saveSettings((int) $this->formId, $diseño);

        $operativo = FormSettings::merge(
            Form::settings((int) $this->formId),
            ['quota' => ['enabled' => true, 'max' => 25]],
            FormSettings::DASHBOARD_BLOCKS
        );
        Form::saveSettings((int) $this->formId, $operativo);

        $final = Form::settings((int) $this->formId);
        assertSame('#00ff00', $final['theme']['primary']);
        assertSame(true, $final['quota']['enabled']);
        assertSame(25, $final['quota']['max']);
    }

    /* ------------------------------------------------------------------
     * fromDashboardPost()
     * ------------------------------------------------------------------ */

    public function testElPostDeLaFichaTraduceLosCamposPlanosABloques(): void
    {
        $parche = FormSettings::fromDashboardPost([
            'access_mode'  => 'allowlist',
            'open_at'      => '2026-11-01T09:00',
            'notify_emails' => 'a@ejemplo.com, b@ejemplo.com',
            'confirm_enabled' => '1',
            'confirm_to_field' => '  Correo electrónico ',
            'locale'       => 'en',
        ]);

        assertSame('allowlist', $parche['access']['mode']);
        // El `datetime-local` llega con `T` y lo guardado lleva espacio: el
        // mismo formato que producía el builder con `fromLocalInput()`.
        assertSame('2026-11-01 09:00', $parche['schedule']['open_at']);
        assertSame(['a@ejemplo.com', 'b@ejemplo.com'], $parche['notify']['emails']);
        assertSame(true, $parche['notify']['confirmation']['enabled']);
        assertSame('Correo electrónico', $parche['notify']['confirmation']['to_field']);
        assertSame('en', $parche['locale']['code']);
    }

    /**
     * El ausente y el vacío no son lo mismo, y la diferencia es la que permite
     * que un `<input type=checkbox>` conserve su sentido.
     *
     * Sin el `hidden` con el mismo `name`, un checkbox desmarcado no llega en el
     * POST; si eso se leyera como «vacío» se apagaría el bloque entero al
     * guardar cualquier otra cosa de la misma tarjeta. Con el `hidden`, llega
     * `0` explícito y el ajuste se apaga cuando —y solo cuando— se desmarca.
     */
    public function testUnaCasillaSinEnviarNoTocaElBloque(): void
    {
        $parche = FormSettings::fromDashboardPost(['locale' => 'en']);

        assertArrayHasKey('locale', $parche);
        assertFalse(array_key_exists('notify', $parche));
        assertFalse(array_key_exists('review', $parche));
        assertFalse(array_key_exists('quota', $parche));
    }

    public function testUnaCasillaDesmarcadaApagaElBloque(): void
    {
        $parche = FormSettings::fromDashboardPost([
            'notify_enabled' => '0',
            'quota_enabled'  => '0',
        ]);

        assertSame(false, $parche['notify']['enabled']);
        assertSame(false, $parche['quota']['enabled']);
    }

    /**
     * Las seis casillas de la ficha tienen su `hidden` delante. Es lo que
     * sostiene el test de arriba en el HTML real y no solo en el array: sin él,
     * el formulario seguiría funcionando pero no podría apagar nada.
     */
    public function testLasCasillasDeLaFichaTraenHidden(): void
    {
        $html = file_get_contents(__DIR__ . '/../../views/dashboard/forms/show.php');
        assertTrue(is_string($html));

        foreach ([
            'quota_enabled', 'notify_enabled', 'confirm_enabled',
            'webhook_allow_private', 'review_enabled', 'review_notify_email',
        ] as $campo) {
            assertTrue(
                str_contains($html, '<input type="hidden" name="' . $campo . '" value="0">'),
                'falta el hidden de ' . $campo
            );
        }
    }

    /**
     * Los siete grupos de «Ajustes del formulario» se pliegan, pero el plegado
     * no puede comerles el nombre accesible: el `<fieldset>` conserva su
     * `<legend>` y dentro va un `<button type="button">` con `data-bs-toggle`,
     * `aria-expanded` y `aria-controls` apuntando al `id` real del panel.
     *
     * Se ata el TRIPLE por grupo porque los tres se necesitan juntos y fallan
     * por separado de forma distinta: sin `type="button"` el botón envía el
     * formulario de ajustes al pulsar el título; sin `aria-controls`/`aria-expanded`
     * el lector de pantalla no anuncia ni qué despliega ni en qué estado está;
     * y si el `id` del panel no es el que apunta el botón, el clic no pliega nada
     * sin error visible (Bootstrap no lanza, simplemente no encuentra destino).
     *
     * Y todos abiertos por defecto (`collapse show`): plegarlos en el servidor
     * dejaría fuera de pantalla ajustes que el servidor sigue guardando.
     */
    public function testLosSieteGruposDeAjustesSonColapsablesYAccesibles(): void
    {
        $html = file_get_contents(__DIR__ . '/../../views/dashboard/forms/show.php');
        assertTrue(is_string($html));

        $esperados = [
            'acceso'   => 'Acceso',
            'agenda'   => 'Programación y cupo',
            'avisos'   => 'Notificaciones',
            'webhook'  => 'Webhook',
            'gracias'  => 'Mensaje final',
            'revision' => 'Revisión',
            'idioma'   => 'Idioma',
        ];

        // El marcado se lee con los espacios plegados: los atributos del botón
        // están repartidos en dos líneas por el ancho, y un `str_contains` que
        //_hwnea_ la maquetación se pondría rojo el día que se vuelva a
        // cortar la línea en otro sitio.
        $html = (string) preg_replace('/\s+/', ' ', $html);

        foreach ($esperados as $slug => $titulo) {
            $boton = '<button type="button" class="app-group-toggle" data-bs-toggle="collapse"'
                . ' data-bs-target="#grp-' . $slug . '" aria-expanded="true" aria-controls="grp-' . $slug . '">';
            assertTrue(
                str_contains($html, $boton),
                'el grupo «' . $titulo . '» se pliega con un <button type="button"> que apunta a #grp-' . $slug
            );
            assertTrue(
                str_contains($html, '<div class="collapse show" id="grp-' . $slug . '">'),
                'y existe el panel #grp-' . $slug . ' abierto por defecto (`collapse show`)'
            );
            // El <span> es lo que lee el lector de pantalla (el texto visible va
            // dentro); puede llevar el icono delante, que es un <i> vacío.
            assertTrue(
                preg_match('/<span>(?:<i\b[^>]*><\/i>)?\s*' . preg_quote($titulo, '/') . '/u', $html) === 1,
                'el <span> del botón nombra el grupo («' . $titulo . '») para el lector de pantalla'
            );
        }

        // Ni un grupo de más ni de menos: el CSS y el JS de persistencia trabajan
        // con lo que hay, así que un octavo grupo sin panel (o un panel sin botón)
        // se rompería en silencio.
        assertSame(
            7,
            preg_match_all('/class="app-group-toggle"/', (string) $html),
            'hay exactamente siete botones de grupo en la ficha'
        );
        assertSame(
            7,
            preg_match_all('/class="collapse show" id="grp-/', (string) $html),
            'y exactamente siete paneles'
        );

        // La persistencia es POR FORMULARIO: la clave sale del id de la tarjeta,
        // no de una constante global, porque el mismo usuario visita varias fichas.
        assertTrue(
            str_contains($html, 'data-vf-form-id="<?= (int) $form[\'id\'] ?>"'),
            'el form de ajustes lleva data-vf-form-id, de donde sale la clave `vf:grp:<id>`'
        );
    }

    /**
     * El texto «Se muestra si: …» salía en el formulario publicado, en cada
     * campo y cada sección con reglas. Al visitante no le sirve: las reglas son
     * del editor, y en un formulario de encuestas lo que le importa es la pregunta, no
     * la regla que la gobierna. Peor: se leía como si fuera una etiqueta más del
     * campo, y con el campo oculto por la propia condición quedaba el texto
     * «Se muestra si: …» solo, sin nada a lo que referirse.
     *
     * Se ata lo que QUEDA, que es lo importante: la lógica sigue en el
     * `data-vf-logic` (sin él no hay omisión de campo en el navegador) y el
     * builder sigue pintando su propia insignia, porque el que edita sí necesita
     * ver la regla.
     */
    public function testElFormularioPublicadoNoDiceLasReglasDeVisibilidad(): void
    {
        $fill = file_get_contents(__DIR__ . '/../../views/fill/show.php');
        assertTrue(is_string($fill));

        assertTrue(
            !str_contains($fill, 'vf-cond-chip'),
            'el fill no pinta la insignia de condición: describe la regla al visitante'
        );
        assertTrue(
            !str_contains($fill, 'condSummary'),
            'ni mantiene la closure que la construía'
        );
        assertTrue(
            !str_contains($fill, '$fieldById'),
            'ni el mapa de campos por id que solo alimentaba esa closure'
        );
        // Se mira el LITERAL de la vista y no `data-vf-logic` a secas: un
        // `str_contains` del nombre entero seguiría encontrándose en
        // `data-vf-logicX`, que es justo el fallo que se quiere detectar.
        assertTrue(
            str_contains($fill, "' data-vf-logic=\"'"),
            'pero la lógica sigue en el markup: sin `data-vf-logic` no hay omisión de campo en el cliente'
        );

        // Y en el builder la insignia sigue, porque el editor sí la necesita.
        $builder = file_get_contents(__DIR__ . '/../../public/assets/js/builder.js');
        assertTrue(
            str_contains((string) $builder, 'vf-cond-chip'),
            'el builder conserva su insignia de condición: quien edita necesita ver la regla'
        );

        // Las claves que solo leía esa closure salen del catálogo: sin lector son
        // ruido que invita a traducirlas al inglés dos veces.
        foreach (['ui.shown_if', 'ui.unknown_field', 'ui.and_join', 'ui.or_join'] as $clave) {
            foreach (['es', 'en'] as $idioma) {
                $catalogo = file_get_contents(__DIR__ . '/../../app/lang/' . $idioma . '.php');
                assertTrue(
                    !str_contains((string) $catalogo, "'" . $clave . "'"),
                    'la clave ' . $clave . ' ya no se lee, así que sale de ' . $idioma . '.php'
                );
            }
        }
    }

    /* ------------------------------------------------------------------
     * La puerta: POST /forms/{id}/settings
     * ------------------------------------------------------------------ */

    public function testLaPuertaGuardaElCupoYDejaElTema(): void
    {
        Auth::assumeLoggedIn((int) $this->userId);

        Form::saveSettings((int) $this->formId, ['theme' => ['primary' => '#abcdef']]);

        $this->post(['access_mode' => 'public', 'quota_enabled' => '1', 'quota_max' => '30', 'notify_enabled' => '0']);

        (new FormController())->updateSettings((int) $this->formId);

        $final = Form::settings((int) $this->formId);
        assertSame(30, $final['quota']['max']);
        assertSame(true, $final['quota']['enabled']);
        assertSame('#abcdef', $final['theme']['primary']);

    }

    public function testUnCupoDeCeroSeRechazaYNoSeGuardaNada(): void
    {
        Auth::assumeLoggedIn((int) $this->userId);

        Form::saveSettings((int) $this->formId, ['theme' => ['primary' => '#abcdef']]);

        $this->post(['access_mode' => 'public', 'quota_enabled' => '1', 'quota_max' => '0']);

        (new FormController())->updateSettings((int) $this->formId);

        $final = Form::settings((int) $this->formId);
        // El rechazo tiene que dejar los ajustes como estaban: un formulario
        // con el cupo a 0 es un formulario cerrado, no uno «a medio guardar».
        assertSame(false, $final['quota']['enabled']);
        assertSame('#abcdef', $final['theme']['primary']);

    }

    public function testUnModoDeAccesoInventadoNoAbreElFormulario(): void
    {
        Auth::assumeLoggedIn((int) $this->userId);

        $this->post(['access_mode' => 'inventado']);

        (new FormController())->updateSettings((int) $this->formId);

        $final = Form::settings((int) $this->formId);
        assertSame('public', $final['access']['mode']);

    }

    public function testLaPuertaNoPuedeEscribirElTemaNiElQuiz(): void
    {
        Auth::assumeLoggedIn((int) $this->userId);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'access_mode' => 'public',
            'theme'       => ['primary' => '#000000'],
            'quiz'        => ['enabled' => true],
        ];
        Response::setRecordHalt(true);

        (new FormController())->updateSettings((int) $this->formId);

        $final = Form::settings((int) $this->formId);
        assertNull($final['theme']['primary']);
        assertSame(false, $final['quiz']['enabled']);

    }

    public function testSinSesionNoSeGuarda(): void
    {
        $this->post(['access_mode' => 'allowlist', 'quota_max' => '5']);

        (new FormController())->updateSettings((int) $this->formId);

        $final = Form::settings((int) $this->formId);
        assertSame('public', $final['access']['mode']);

    }
}
