<?php

declare(strict_types=1);

namespace Tests\Unit;

/**
 * F0.2 — Contrato de CONTENIDO: las validaciones de un campo sobreviven a la
 * edición en el builder.
 *
 * Regresión real: `applySettings()` asignaba `f.validations = v` donde `v` se
 * construía desde cero (`const v = {}`), escribiendo solo las claves con un
 * control visible en el panel. Abrir el engranaje de una pregunta de texto y
 * teclear en la etiqueta borraba `min_length`/`max_length` (que el servidor sí
 * aplica, FillController.php:966-973) y `max_files` (que lee
 * Uploader.php:100 y declara `evento-inscripcion.json`), y el autosave lo
 * persistía: 5 de las 6 plantillas perdían el cap que el autor nunca tocó.
 *
 * `BuilderPayloadContractTest` no lo detectaba porque compara NOMBRES de clave y
 * este fallo tenía los nombres correctos: lo que se perdía era el valor. Aquí se
 * compara el CONTENIDO, y el invariante se plantea sobre el mecanismo de
 * reparación, no sobre la lista:
 *
 *   `applySettings()` parte de `preservedValidations(f)` y encima escribe las
 *   claves que el panel controla. De ahí sale la regla:
 *
 *     · K ∈ MANAGED  → se re-deriva del input. Si el input NO existe, listar K
 *                       aquí es precisamente lo que la BORRA. Por eso toda clave
 *                       de MANAGED debe tener un control que la escriba.
 *     · K ∉ MANAGED  → se copia tal cual. Segura por construcción, y es lo
 *                       correcto para lo que no tiene UI (max_files, hoy).
 *
 * O sea: el peligro no es que una clave falte en MANAGED (se preserva), es que
 * esté en MANAGED sin control (se destruye). Los tests miden justo eso.
 */
class BuilderValidationsContractTest
{
    private const JS = 'public/assets/js/builder.js';
    private const PANEL = 'views/builder/index.php';
    private const TEMPLATES = 'database/templates';

    /**
     * Claves que el servidor consume de `validations`, con el archivo que las
     * lee. El test `testLaListaDeValidacionesDelServidorEstaAlDia` la contrasta
     * contra el código real, así que la lista no puede quedarse vieja en
     * silencio cuando se añada una validación nueva.
     *
     * @var array<string, string>
     */
    private const READ_BY_SERVER = [
        'min_length'  => 'app/Controllers/FillController.php',
        'max_length'  => 'app/Controllers/FillController.php',
        'min'         => 'app/Controllers/FillController.php',
        'max'         => 'app/Controllers/FillController.php',
        'accept'      => 'app/Services/Uploader.php',
        'max_size_mb' => 'app/Services/Uploader.php',
        'max_files'   => 'app/Services/Uploader.php',
        'multiple'    => 'app/Services/Uploader.php',
        'correct'     => 'app/Services/QuizScore.php',
        'randomize'   => 'app/Controllers/FillController.php',
    ];

    /** Tipos a los que el servidor aplica min_length/max_length (FillController.php:966). */
    private const LENGTH_TYPES = ['text', 'textarea'];

    /**
     * Validación => id del control del panel que la escribe.
     *
     * Es el puente que faltaba en el contrato: saber qué input produce qué
     * clave permite exigir que ese input esté escuchando a `applySettings`.
     * `max` aparece dos veces (valMax y fieldScaleMax) y por eso el valor es una
     * lista; basta con que uno de los dos esté vivo para no perder el cap.
     *
     * @var array<string, string[]>
     */
    private const CONTROL_FOR_KEY = [
        'min'         => ['valMin', 'fieldScaleMax'],
        'max'         => ['valMax', 'fieldScaleMax'],
        'min_length'  => ['valMinLen'],
        'max_length'  => ['valMaxLen'],
        'accept'      => ['valFileAccept'],
        'max_size_mb' => ['valFileMaxSize'],
        'multiple'    => ['valFileMultiple'],
        'correct'     => ['quizAnswer'],
        'randomize'   => ['fieldShuffleOps'],
    ];

    /* ---------- el invariante central ---------- */

    public function testCadaClavePreservadaPorElPanelTieneUnControlQueLaEscriba(): void
    {
        $body = $this->applySettingsBody();
        $managed = $this->managedKeys();
        $orphans = [];

        foreach ($managed as $key) {
            if (!$this->hasControl($body, $key)) {
                $orphans[] = $key;
            }
        }

        assertTrue($managed !== [], 'MANAGED_VALIDATION_KEYS no se pudo leer de builder.js');
        assertTrue(
            $orphans === [],
            'estas claves están en MANAGED_VALIDATION_KEYS pero applySettings() no les da ningún '
                . 'control: eso las BORRA en cada edición, que es el bug original: '
                . implode(', ', $orphans)
                . '. Saca de la lista las que no tengan input (se preservan solas) o añade el control.'
        );
    }

    public function testLaPreservacionCopiaTodoLoQueNoEstaEnManaged(): void
    {
        $src = (string) file_get_contents($this->path(self::JS));
        assertTrue(
            str_contains($src, 'function preservedValidations('),
            'no se encontró preservedValidations(): es la que evita que applySettings() destruya '
                . 'las validaciones sin control (max_files y cualquier clave futura)'
        );
        $body = $this->functionBody($src, 'function preservedValidations(');
        assertTrue(
            str_contains($body, 'MANAGED_VALIDATION_KEYS.indexOf(k)')
                || str_contains($body, '!MANAGED_VALIDATION_KEYS.includes(k)'),
            'preservedValidations() debe copiar las claves que NO están en MANAGED_VALIDATION_KEYS'
        );
    }

    public function testApplySettingsNoReconstruyeValidacionesDesdeCero(): void
    {
        $body = $this->applySettingsBody();
        assertTrue(
            str_contains($body, 'preservedValidations(f)'),
            'applySettings() debe partir de preservedValidations(f); `const v = {}` borra las '
                . 'validaciones que no tienen control en el panel'
        );
        assertTrue(
            !preg_match('/const\s+v\s*=\s*\{\s*\};/', $body),
            'applySettings() volvió a inicializar `v` vacío: eso destruye min_length/max_length y max_files'
        );
    }

    /* ---------- el mismo invariante, del lado del disparador ---------- */

    /**
     * El bug del quiz fue el MISMO descuido que este test de arriba, dos capas
     * más abajo: `applySettings()` sí escribía `v.correct` (por eso el test de
     * contenido pasaba), pero `#quizAnswer` no tenía NINGÚN listener. Nadie
     * llamaba a `applySettings()`, así que el autosave seguía guardando la
     * validación anterior y la respuesta correcta desaparecía al recargar.
     *
     * El servidor nunca estuvo en medio: `correct` viaja en `validations` y
     * QuizScore la lee. Faltaba el evento.
     *
     * O sea: un control puede escribir la clave perfectamente y aun así no
     * guardar nada. La condición necesaria es que además esté ESCUCHADO, y eso
     * es lo que se mide aquí.
     */
    public function testCadaControlQueEscribeUnaValidacionEstaEscuchado(): void
    {
        $wired = $this->wiredControls();
        $dead = [];

        foreach (self::CONTROL_FOR_KEY as $key => $ids) {
            $alive = array_values(array_filter($ids, static fn(string $id): bool => in_array($id, $wired, true)));
            if ($alive === []) {
                $dead[] = $key . ' (' . implode('/', $ids) . ')';
            }
        }

        assertTrue(
            $dead === [],
            'estos controles escriben una validación en applySettings() pero no tienen listener: '
                . implode(', ', $dead)
                . '. El valor se ve en el input, no llega a f.validations y el autosave guarda la '
                . 'validación anterior. Enlázalos con applySettings (\'input\' para texto, '
                . '\'change\' para casillas).'
        );
    }
    public function testNingunaValidacionSeEscribeSinUnControlDeclaradoAqui(): void
    {
        $body = $this->applySettingsBody();
        preg_match_all('/\bv\.([a-z_]+)\s*[.=]/', $body, $m);
        $written = array_values(array_unique($m[1]));

        $unmapped = array_values(array_diff($written, array_keys(self::CONTROL_FOR_KEY)));
        assertTrue(
            $unmapped === [],
            'applySettings() escribe estas validaciones sin control declarado en '
                . 'CONTROL_FOR_KEY: ' . implode(', ', $unmapped)
                . '. Declara el control (y su listener) o quítalo de MANAGED_VALIDATION_KEYS.'
        );
    }

    /* ---------- lo que el autor puede ver y editar ---------- */

    public function testLosTextosDeclaranLasLongitudesQueElServidorLesAplica(): void
    {
        $declared = $this->typeInfoValidations();
        $misses = [];

        foreach (self::LENGTH_TYPES as $type) {
            foreach (['min_length', 'max_length'] as $key) {
                if (!in_array($key, $declared[$type] ?? [], true)) {
                    $misses[] = $type . '.' . $key;
                }
            }
        }

        assertTrue(
            $misses === [],
            'FillController.php:966-973 aplica min_length/max_length a '
                . implode(' y ', self::LENGTH_TYPES)
                . ', pero no las declaran en typeInfo: ' . implode(', ', $misses)
                . '. El input queda oculto (fillSettings) y applySettings() no las re-serializa,'
                . ' así que el cap de la plantilla se pierde al teclear.'
        );
    }

    public function testLosControlesDeLongitudExistenEnElPanel(): void
    {
        $panel = (string) file_get_contents($this->path(self::PANEL));
        foreach (['valMinLen', 'valMaxLen'] as $id) {
            assertTrue(
                str_contains($panel, $id),
                'el panel no tiene el input ' . $id . ': declararlo en typeInfo no mostraría nada'
            );
        }
    }

    public function testLaSeccionDeLongitudesSeMuestraParaTextos(): void
    {
        // El input existe y el tipo lo declara: si fillSettings no destapa el
        // contenedor, el cap sigue invisible.
        $src = (string) file_get_contents($this->path(self::JS));
        assertTrue(
            str_contains($src, 'valMinLenWrap.classList.toggle')
                && str_contains($src, 'valMaxLenWrap.classList.toggle'),
            'fillSettings() debe alternar valMinLenWrap/valMaxLenWrap según info.validations'
        );
    }

    /* ---------- que la lista del test no se vuelva vieja ---------- */

    public function testLaListaDeValidacionesDelServidorEstaAlDia(): void
    {
        $listed = array_keys(self::READ_BY_SERVER);
        assertTrue($listed !== [], 'la lista de validaciones leídas está vacía');

        $missing = [];
        foreach ($listed as $key) {
            $src = (string) file_get_contents($this->path(self::READ_BY_SERVER[$key]));
            if (!str_contains($src, "'" . $key . "'")) {
                $missing[] = $key . ' (ya no aparece en ' . self::READ_BY_SERVER[$key] . ')';
            }
        }
        assertTrue(
            $missing === [],
            'estas claves ya no se leen en el archivo que el test declara: '
                . implode('; ', $missing)
        );

        // Y al revés: si el servidor añadió una lectura nueva, que se note.
        $union = [];
        foreach (array_unique(array_values(self::READ_BY_SERVER)) as $rel) {
            $src = (string) file_get_contents($this->path($rel));
            preg_match_all("/\\\$validations\['([a-z_]+)'\]/", $src, $m);
            $union = array_merge($union, $m[1]);
        }
        $union = array_values(array_unique($union));
        $new = array_values(array_diff($union, $listed));
        assertTrue(
            $new === [],
            'el servidor lee validaciones que este test no conoce: ' . implode(', ', $new)
                . '. Añádelas a READ_BY_SERVER con su archivo para que el invariante las cubra.'
        );
    }

    /* ---------- ayudas ---------- */

    /** ¿applySettings() escribe esta clave en `v`? */
    private function hasControl(string $body, string $key): bool
    {
        return preg_match('/\bv\.' . preg_quote($key, '/') . '\s*[.=]/', $body) === 1;
    }

    /**
     * Ids de los controles que tienen un listener que acaba en `applySettings`.
     *
     * Se recogen las dos formas que usa el builder, porque el test no debe
     * atarse a una: el enlace directo `el.quizAnswer.addEventListener('input',
     * applySettings)` y el bucle sobre una lista
     * (`['quizAnswer', ...].forEach(k => el[k].addEventListener(...))`). Para el
     * bucle se toman los tokens entre comillas de la sentencia.
     */
    private function wiredControls(): array
    {
        $src = (string) file_get_contents($this->path(self::JS));
        $wired = [];

        foreach (preg_split('/;\s*\n/', $src) ?: [] as $stmt) {
            if (!str_contains($stmt, 'addEventListener') || !str_contains($stmt, 'applySettings')) {
                continue;
            }
            if (preg_match_all('/el\.([A-Za-z_][A-Za-z0-9_]*)\.addEventListener/', $stmt, $m) > 0) {
                foreach ($m[1] as $id) {
                    $wired[] = $id;
                }
            }
            if (preg_match_all("/'([A-Za-z_][A-Za-z0-9_]*)'/", $stmt, $q) > 0) {
                foreach ($q[1] as $id) {
                    $wired[] = $id;
                }
            }
        }

        return array_values(array_unique($wired));
    }

    /** Claves de MANAGED_VALIDATION_KEYS declaradas en builder.js. */
    private function managedKeys(): array
    {
        $src = (string) file_get_contents($this->path(self::JS));
        assertTrue(
            preg_match('/const\s+MANAGED_VALIDATION_KEYS\s*=\s*\[(.*?)\]/s', $src, $m) === 1,
            'no se encontró MANAGED_VALIDATION_KEYS en builder.js'
        );
        preg_match_all("/'([a-z_]+)'/", $m[1], $q);
        return array_values(array_unique($q[1]));
    }

    /** typeInfo: tipo => validaciones declaradas. */
    private function typeInfoValidations(): array
    {
        $src = (string) file_get_contents($this->path(self::JS));
        $out = [];
        preg_match_all(
            '/^\s{8}([a-z]+):\s*\{[^{}]*?validations:\s*\[([^\]]*)\]/m',
            $src,
            $m,
            PREG_SET_ORDER
        );
        foreach ($m as $hit) {
            preg_match_all("/'([a-z_]+)'/", $hit[2], $q);
            $out[$hit[1]] = array_values(array_unique($q[1]));
        }
        return $out;
    }

    private function applySettingsBody(): string
    {
        $src = (string) file_get_contents($this->path(self::JS));
        $start = strpos($src, 'function applySettings(');
        assertTrue($start !== false, 'no se encontró applySettings()');
        return $this->functionBody($src, 'function applySettings(');
    }

    /** Cuerpo de una función: desde su firma hasta el primer cierre de bloque. */
    private function functionBody(string $src, string $signature): string
    {
        $start = strpos($src, $signature);
        if ($start === false) {
            return '';
        }
        $end = strpos($src, "\n    }", (int) $start);
        return $end === false ? '' : substr($src, (int) $start, (int) $end - (int) $start);
    }

    private function path(string $rel): string
    {
        return dirname(__DIR__, 2) . '/' . $rel;
    }
}
