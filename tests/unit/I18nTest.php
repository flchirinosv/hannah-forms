<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\I18n;

/**
 * P9 — i18n reducido (catálogos es/en).
 *
 * Cubre la resolución de idioma (precedencia settings > app > es), la
 * interpolación de placeholders, el fail-safe de clave inexistente y que los
 * dos catálogos estén en paridad de claves (si no, el inglés mostraría la
 * clave cruda).
 */
class I18nTest
{
    public function setUp(): void
    {
        // Q6 — estado limpio del request (el runner CLI no lo inyecta). Se
        // borran solo las claves: $_GET/$_POST deben seguir siendo arrays para
        // Request::all().
        unset($_GET['lang'], $_POST['lang'], $_SERVER['HTTP_ACCEPT_LANGUAGE']);
    }

    public function tearDown(): void
    {
        unset($_GET['lang'], $_POST['lang'], $_SERVER['HTTP_ACCEPT_LANGUAGE']);
    }

    public function testIdiomasSoportadosSonEsEn(): void
    {
        assertSame(['es', 'en'], I18n::supportedLocales());
    }

    public function testResolveUsaSettingsLocale(): void
    {
        assertSame('en', I18n::resolve(['locale' => ['code' => 'en']]));
        assertSame('es', I18n::resolve(['locale' => ['code' => 'es']]));
    }

    public function testResolveIgnoraLocaleNoSoportado(): void
    {
        // Un idioma desconocido (p. ej. de un .json manipulado) cae al de la app.
        $fallback = \App\Core\Config::get('app.locale', 'es');
        assertSame($fallback, I18n::resolve(['locale' => ['code' => 'xx']]));
        assertSame($fallback, I18n::resolve(['locale' => ['code' => '']]));
    }

    public function testResolveSinSettingsUsaAppLocale(): void
    {
        assertSame(\App\Core\Config::get('app.locale', 'es'), I18n::resolve());
        assertSame(\App\Core\Config::get('app.locale', 'es'), I18n::resolve([]));
    }

    // ----- Q6 · override ?lang= + navegante -----

    public function testResolvePriorizaLangDelRequest(): void
    {
        // El override explícito gana incluso a settings.locale del formulario.
        assertSame('en', I18n::resolve(['locale' => ['code' => 'es']], 'en'));
        assertSame('es', I18n::resolve(['locale' => ['code' => 'en']], 'es'));
    }

    public function testResolveIgnoraLangNoSoportado(): void
    {
        // Un ?lang=xx no debe tumbar la selección del formulario.
        assertSame('en', I18n::resolve(['locale' => ['code' => 'en']], 'fr'));
        assertSame(\App\Core\Config::get('app.locale', 'es'), I18n::resolve([], 'xx'));
    }

    public function testResolveUsaNaveganteSoloSinLocaleDeFormulario(): void
    {
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-US,en;q=0.9,es;q=0.8';
        // Sin idioma explícito del form → el navegante manda.
        assertSame('en', I18n::resolve([], null, true));
        // Con locale explícito del form, el navegante NO lo pisa.
        assertSame('es', I18n::resolve(['locale' => ['code' => 'es']], null, true));
        // Idiomas no soportados del navegante caen a app.locale.
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'fr-FR,fr;q=0.9';
        assertSame(\App\Core\Config::get('app.locale', 'es'), I18n::resolve([], null, true));
        // Sin header: igual.
        unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
        assertSame(\App\Core\Config::get('app.locale', 'es'), I18n::resolve([], null, true));
    }

    public function testForRequestLeeLangGetYPostYElNavegante(): void
    {
        // POST (submit normal del fill): el campo oculto `lang` del form.
        $_POST['lang'] = 'en';
        assertSame('en', I18n::forRequest(['locale' => ['code' => 'es']]));
        unset($_POST['lang']);

        // GET con ?lang= (enlace compartido).
        $_GET['lang'] = 'en';
        assertSame('en', I18n::forRequest([]));
        unset($_GET['lang']);

        // Sin override → navegante.
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-GB,en;q=0.8';
        assertSame('en', I18n::forRequest([]));
        // ... pero SOLO si el formulario no fija idioma.
        assertSame('es', I18n::forRequest(['locale' => ['code' => 'es']]));
    }

    public function testTranslateEsYEn(): void
    {
        assertSame('Enviar', I18n::t('ui.enviar', 'es'));
        assertSame('Submit', I18n::t('ui.enviar', 'en'));
    }

    public function testTranslateInterpolaPlaceholders(): void
    {
        assertSame('Debe tener al menos 5 caracteres.', I18n::t('val.min_len', 'es', ['n' => 5]));
        assertSame('Must be at least 5 characters.', I18n::t('val.min_len', 'en', ['n' => 5]));
        // Varios placeholders a la vez.
        assertTrue(str_contains(I18n::t('quiz.result', 'es', ['points' => 3, 'max' => 4]), '3'));
        assertTrue(str_contains(I18n::t('quiz.result', 'en', ['points' => 3, 'max' => 4]), '3 of 4'));
    }

    public function testClaveInexistenteDevuelveLaClave(): void
    {
        assertSame('no.existe.esta.clave', I18n::t('no.existe.esta.clave', 'en'));
    }

    public function testCatalogosEstanEnParidadDeClaves(): void
    {
        $es = I18n::allFor('es');
        $en = I18n::allFor('en');

        assertTrue(count($es) > 40);
        $missing = array_values(array_diff(array_keys($es), array_keys($en)));
        $extra = array_values(array_diff(array_keys($en), array_keys($es)));
        assertSame([], $missing);
        assertSame([], $extra);
    }

    public function testAllForDevuelveCatalogoPlanoDeStrings(): void
    {
        $en = I18n::allFor('en');
        assertArrayHasKey('ui.siguiente', $en);
        assertArrayHasKey('val.required', $en);
        assertArrayHasKey('js.saved', $en);
        foreach ($en as $k => $v) {
            assertTrue(is_string($v) && $v !== '', 'Clave con valor vacío o no string: ' . $k);
        }
    }

    public function testFillUsaElIdiomaDelFormulario(): void
    {
        $form = $this->makeForm('Encuesta @test.local');
        $fields = \App\Models\Field::byForm((int) $form['id']);
        $field = $fields[0];

        $es = $this->renderFill($form, $fields, ['locale' => ['code' => 'es']]);
        assertTrue(str_contains($es, 'lang="es"'));
        assertTrue(str_contains($es, '>Enviar<'));
        assertTrue(str_contains($es, 'Este campo es obligatorio.'));

        $en = $this->renderFill($form, $fields, ['locale' => ['code' => 'en']]);
        assertTrue(str_contains($en, 'lang="en"'));
        assertTrue(str_contains($en, '>Submit<'));
        assertTrue(str_contains($en, 'This field is required.'));

        // El catálogo cliente viaja en data-vf-i18n (sin <script> inline).
        assertTrue(str_contains($en, 'data-vf-i18n='));
        assertTrue(str_contains(html_entity_decode($en, ENT_QUOTES, 'UTF-8'), '"ui.siguiente":"Next"'));

        \App\Models\Form::delete($form['id']);
    }

    private function makeForm(string $title): array
    {
        $db = \App\Core\Database::getInstance(\App\Core\Config::db());
        $suffix = bin2hex(random_bytes(4));
        $userId = \App\Models\User::create('Test I18n', 'i18n-' . $suffix . '@test.local', 'password');
        $formId = \App\Models\Form::create((int) $userId, $title);
        \App\Models\Form::update($formId, $title, '', 'published');
        \App\Models\Field::create($formId, 'text', 'Tu nombre', '', '', true, [], [], 1);
        unset($db);
        return \App\Models\Form::find($formId);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function renderFill(array $form, array $fields, array $settings): string
    {
        $full = \App\Services\FormSettings::normalize($settings);
        ob_start();
        \App\Core\View::render('fill/show', [
            'form'     => $form,
            'fields'   => $fields,
            'csrf'     => 'token-i18n',
            'settings' => $full,
            'old'      => [],
            'errors'   => ['Este campo es obligatorio.'],
        ], null);
        return (string) ob_get_clean();
    }
}
