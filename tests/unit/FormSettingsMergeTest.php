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
