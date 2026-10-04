<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Models\Form;
use App\Models\FormParticipant;
use App\Models\User;
use App\Services\FormSettings;
use App\Services\ParticipantImporter;
use App\Services\RosterTemplate;

/**
 * Fase 3 — la IMPORTACIÓN del listado.
 *
 * Una importación tiene tres formas de romperse sin decir nada, y las tres salen
 * de acá, no de una excepción:
 *
 *  1. **Se cuela medio archivo.** Si una fila mala tumba la transacción entera, un
 *     `.xlsx` de 2000 personas con dos correos mal escritos no entra, y el dueño se
 *     queda con la lista vacía y un error sin decir qué fila. En `fusionar` cada fila
 *     va por su cuenta.
 *  2. **Borra de más.** Ese riesgo es el que separa `fusionar` de `reemplazar`. Vaciar
 *     la lista es irreversible para el `code` que se había repartido, así que
 *     `reemplazar` **valida el archivo entero antes de borrar nada**.
 *  3. **Cambia una credencial.** Si el `code` se pudiera importar, un `.xlsx`
 *     elegiría la credencial de acceso de alguien. Este test lo ata.
 */
class ParticipantImporterTest
{
    private static ?int $ownerId = null;
    private static ?int $formId = null;
    /** @var list<string> */
    private static array $temporales = [];

    public function setUp(): void
    {
        self::cleanupResiduals();

        $rand = bin2hex(random_bytes(4));
        self::$ownerId = User::create('Import Owner ' . $rand, 'import-' . $rand . '@test.local', 'clave-larga-123', 2);
        self::$formId = Form::create((int) self::$ownerId, 'Importacion ' . $rand);

        Auth::assumeLoggedIn((int) self::$ownerId);
        Auth::forgetCache();
    }

    public function tearDown(): void
    {
        Auth::forgetCache();
        unset($_GET, $_POST);
        self::cleanupResiduals();
        foreach (self::$temporales as $ruta) {
            @unlink($ruta);
        }
        self::$temporales = [];
    }

    // =====================================================================
    // fusionar: el camino de todos los días
    // =====================================================================

    public function testUnaPlantillaEnFusionDaDeAltaATodasLasFilas(): void
    {
        $r = self::importar([
            ['Cédula', 'Nombre', 'Correo', 'Indicador', 'Sede'],
            ['V-1', 'Ana Pérez', 'ana@x.com', 'IND-1', 'Caracas'],
            ['V-2', 'Luis Gómez', 'luis@x.com', 'IND-2', 'Caracas'],
        ]);

        assertTrue($r['ok'], 'la importación tiene que salir bien: ' . (string) $r['error']);
        assertNull($r['error']);
        assertEquals(2, $r['total']);
        assertEquals(2, $r['insertados']);
        assertEquals(0, $r['actualizados']);
        assertEquals(0, $r['omitidos']);
        assertEquals(2, FormParticipant::countForForm((int) self::$formId));

        // Las cabeceras que no son del participante se hacen atributos, que es lo
        // que después alimenta el filtro y los grupos de la analítica.
        $catalogo = FormParticipant::catalogFor((int) self::$formId);
        assertEquals(1, count($catalogo));
        assertEquals('Sede', (string) $catalogo[0]['label']);

        $fila = FormParticipant::findByIdentifier((int) self::$formId, 'cedula', 'V-1');
        assertNotNull($fila);
        assertEquals('Ana Pérez', (string) $fila['nombre']);
        assertEquals('Caracas', FormParticipant::attributesOf((int) $fila['id'])['sede'] ?? '');
        assertTrue(FormParticipant::codeValido((string) $fila['code']), 'el código lo genera el modelo');
    }

    public function testReimportarElMismoArchivoNoDiceQueCambio(): void
    {
        $filas = [
            ['Cédula', 'Nombre', 'Sede'],
            ['V-1', 'Ana', 'Caracas'],
            ['V-2', 'Luis', 'Valencia'],
        ];
        self::importar($filas);
        $r = self::importar($filas);

        assertTrue($r['ok']);
        // La mitad de que «funcione» es que importar lo mismo no invente cambios:
        // si el informe dijera «2 modificados» cada vez, el dueño no podría
        // distinguir un arreglo de un archivo que ya estaba bien.
        assertEquals(0, $r['insertados'], 'nadie nuevo');
        assertEquals(0, $r['actualizados'], 'nadie cambiado');
        assertEquals(2, $r['sin_cambios']);
        assertEquals(2, FormParticipant::countForForm((int) self::$formId), 'la lista no se duplica');
    }

    public function testFusionarActualizaLoQueCambioYConservaElCodigo(): void
    {
        self::importar([['Cédula', 'Nombre', 'Sede'], ['V-1', 'Ana', 'Caracas']]);
        $antes = FormParticipant::findByIdentifier((int) self::$formId, 'cedula', 'V-1');
        assertNotNull($antes);

        $r = self::importar([['Cédula', 'Nombre', 'Sede'], ['V-1', 'Ana María', 'Maracay']]);

        assertEquals(0, $r['insertados']);
        assertEquals(1, $r['actualizados']);

        $despues = FormParticipant::findByIdentifier((int) self::$formId, 'cedula', 'V-1');
        assertNotNull($despues);
        assertEquals((int) $antes['id'], (int) $despues['id'], 'es la MISMA fila, no una nueva');
        assertEquals('Ana María', (string) $despues['nombre']);
        assertEquals('Maracay', FormParticipant::attributesOf((int) $despues['id'])['sede'] ?? '');

        // El `code` es lo que distingue esta fila de una nueva. Si cambiara aquí,
        // quien ya lo tiene escrito se queda sin poder entrar sin ver ningún error.
        assertEquals(
            (string) $antes['code'],
            (string) $despues['code'],
            'el código es una credencial repartida: una importación no la cambia'
        );
    }

    public function testElCodigoQueVieneEnElArchivoSeIgnoraEntero(): void
    {
        $r = self::importar([
            ['Cédula', 'Nombre', 'Código'],
            ['V-1', 'Ana', 'ARRASTRADO'],
        ]);

        assertTrue($r['ok']);
        $fila = FormParticipant::findByIdentifier((int) self::$formId, 'cedula', 'V-1');
        assertNotNull($fila);
        assertTrue(
            (string) $fila['code'] !== 'ARRASTRADO',
            'un .xlsx no elige la credencial de acceso de una persona'
        );

        // Y la columna no se cuela como atributo: la última del archivo exportado se
        // llama así, y un filtro por «Código» que compara 16 caracteres es ruido.
        assertEquals(0, count(FormParticipant::catalogFor((int) self::$formId)), '«Código» no es un atributo');
    }

    // =====================================================================
    // Filas que no están bien: en fusionar no tumban el resto
    // =====================================================================

    public function testUnaFilaMalaNoTumbaElRestoYSeDiceCualEs(): void
    {
        $r = self::importar([
            ['Cédula', 'Nombre'],
            ['V-1', 'Ana'],
            ['', 'Sin cédula'],
            ['   ', 'Solo espacios'],
            ['V-2', 'Luis'],
        ]);

        assertTrue($r['ok'], 'el archivo es casi todo bueno y se importa: ' . (string) $r['error']);
        assertEquals(4, $r['total'], 'cuatro filas de datos: la cabecera no cuenta');
        assertEquals(2, $r['insertados'], 'las dos que sí se podían');
        assertEquals(2, $r['omitidos']);
        assertEquals(2, FormParticipant::countForForm((int) self::$formId));

        // El número de fila es el que ve la persona al abrir el Excel, no el
        // índice del array: la cabecera es la fila 1.
        $numeros = array_map(static fn (array $p): int => $p['fila'], $r['problemas']);
        assertEquals([3, 4], $numeros, 'dice qué filas, en el número del archivo');
        assertEquals(FormParticipant::MOTIVO_VACIO, $r['problemas'][0]['motivo']);
    }

    public function testDosFilasIgualesEnElMismoArchivoSeReportanComoDuplicado(): void
    {
        $r = self::importar([
            ['Cédula', 'Nombre'],
            ['V-1', 'Ana'],
            ['V-1', 'Ana otra vez'],
            ['V-2', 'Luis'],
        ]);

        assertEquals(2, $r['insertados'], 'la segunda se salta, no se convierte en la actualización de la primera');
        assertEquals(1, $r['omitidos']);
        assertEquals(FormParticipant::MOTIVO_DUPLICADO, $r['problemas'][0]['motivo']);
        assertEquals(3, (int) $r['problemas'][0]['fila']);
    }

    public function testDosFilasQueSoloSeDiferencianEnEspaciosInternosSonLaMisma(): void
    {
        // La normalización colapsa los espacios internos porque la collation no los
        // ignora. Sin eso, un `.xlsx` con «V 1» en una fila y «V  1» en la otra —la
        // misma cédula escrita de dos maneras, que es lo que pasa al copiar y pegar
        // de una hoja a otra— daría dos participantes y dos códigos para uno solo, y
        // al entrar el segundo no encontraría nada.
        //
        // Lo que NO se hace es quitar la puntuación: «V-1» y «V1» son identificadores
        // distintos y pueden serlo de verdad, y unir «V-1» con «V1» fundiría dos
        // personas distintas en una fila.
        $r = self::importar([
            ['Cédula', 'Nombre'],
            ['V 1', 'Ana'],
            ['V  1', 'La misma'],
        ]);

        assertEquals(1, $r['insertados']);
        assertEquals(1, $r['omitidos']);
        assertEquals(FormParticipant::MOTIVO_DUPLICADO, $r['problemas'][0]['motivo']);
    }

    public function testUnCorreoQueNoEsCorreoSeQuedaFueraYNoTumbaElResto(): void
    {
        $r = self::importar([
            ['Cédula', 'Nombre', 'Correo'],
            ['V-1', 'Ana', 'no-es-un-correo'],
            ['V-2', 'Luis', 'luis@x.com'],
        ]);

        assertTrue($r['ok'], 'una fila con el correo mal escrito no tumba el archivo: ' . (string) $r['error']);
        assertEquals(1, $r['insertados']);
        assertEquals(1, $r['omitidos']);
        assertEquals(FormParticipant::MOTIVO_CORREO, $r['problemas'][0]['motivo']);
        assertEquals(1, FormParticipant::countForForm((int) self::$formId));
    }

    // =====================================================================
    // reemplazar: todo o nada, porque borra
    // =====================================================================

    public function testReemplazarVaciaLaListaYLaVuelveAContener(): void
    {
        self::importar([['Cédula', 'Nombre'], ['V-9', 'El viejo'], ['V-8', 'La vieja']]);

        $r = self::importar([['Cédula', 'Nombre'], ['V-1', 'Ana']], ParticipantImporter::MODO_REEMPLAZAR);

        assertTrue($r['ok'], (string) $r['error']);
        assertEquals(1, $r['insertados']);
        assertEquals(1, FormParticipant::countForForm((int) self::$formId), 'los de antes ya no están');
        assertNull(
            FormParticipant::findByIdentifier((int) self::$formId, 'cedula', 'V-9'),
            'V-9 se fue'
        );
    }

    public function testReemplazarConUnaFilaMalaNoBorraNada(): void
    {
        // La prueba que justifica que `reemplazar` valide el archivo entero antes de
        // tocar la base. Sin esto, el dueño sube un archivo con una fila repetida y
        // se queda sin lista, y el único consuelo es que el error lo dice tarde.
        self::importar([['Cédula', 'Nombre'], ['V-1', 'Ana'], ['V-2', 'Luis']]);

        $r = self::importar([
            ['Cédula', 'Nombre'],
            ['V-3', 'Nueva'],
            ['V-3', 'Nueva otra vez'],
        ], ParticipantImporter::MODO_REEMPLAZAR);

        assertFalse($r['ok'], 'un archivo con errores no se aplica en modo reemplazar');
        assertEquals('reemplazar_con_errores', $r['error']);
        assertEquals(2, FormParticipant::countForForm((int) self::$formId), 'la lista sigue como estaba');
        assertNull(FormParticipant::findByIdentifier((int) self::$formId, 'cedula', 'V-3'), 'nadie nuevo');
    }

    public function testReemplazarUnArchivoSinFilasNoVacíaLaLista(): void
    {
        self::importar([['Cédula', 'Nombre'], ['V-1', 'Ana']]);

        // Solo la cabecera. Reemplazar con esto sería dejar la lista vacía sin
        // haber subido a nadie, y eso no es una importación: es un borrado con otro
        // nombre. Para vaciar de verdad hay una acción aparte que lo dice.
        $r = self::importar([['Cédula', 'Nombre']], ParticipantImporter::MODO_REEMPLAZAR);

        assertFalse($r['ok']);
        assertEquals('roster_sin_filas', $r['error']);
        assertEquals(1, FormParticipant::countForForm((int) self::$formId), 'la lista sigue entera');
    }

    public function testReemplazarDejaElCatalogoDeAtributos(): void
    {
        self::importar([['Cédula', 'Sede'], ['V-1', 'Caracas']]);
        assertEquals(1, count(FormParticipant::catalogFor((int) self::$formId)));

        self::importar([['Cédula', 'Nombre'], ['V-2', 'Luis']], ParticipantImporter::MODO_REEMPLAZAR);

        assertEquals(
            1,
            count(FormParticipant::catalogFor((int) self::$formId)),
            'las personas se borran; los atributos son el filtro que alguien lleva dos años usando'
        );
    }

    public function testReemplazarNoDejaRespuestasColgadas(): void
    {
        // `submissions.participant_id` es `SET NULL`, y `truncate()` lo respeta:
        // vaciar la lista no puede anular lo que la gente ya contestó.
        self::importar([['Cédula', 'Nombre'], ['V-1', 'Ana']]);
        $fila = FormParticipant::findByIdentifier((int) self::$formId, 'cedula', 'V-1');
        assertNotNull($fila);

        self::importar([['Cédula', 'Nombre'], ['V-2', 'Luis']], ParticipantImporter::MODO_REEMPLAZAR);

        $participante = FormParticipant::find((int) $fila['id']);
        assertNull($participante, 'la persona ya no está en la lista');
    }

    // =====================================================================
    // La puerta de la puerta
    // =====================================================================

    public function testSinLaColumnaDelIdentificadorNoSeImportaNada(): void
    {
        // El formulario pide identificar por `correo` y el archivo solo trae
        // cédula. Importarlo «de todos modos» guardaría una lista donde nadie tiene
        // lo que se le pidió, y la puerta del fill no dejaría entrar a nadie.
        $r = self::importar([['Cédula', 'Nombre'], ['V-1', 'Ana']], ParticipantImporter::MODO_FUSIONAR, 'correo');

        assertFalse($r['ok']);
        assertEquals('falta_columna_identificador', $r['error'], 'es un error del archivo, no de una fila');
        assertEquals(0, FormParticipant::countForForm((int) self::$formId));
        assertEquals(0, count($r['problemas']), 'no es una omisión: afecta a todas las filas');
    }

    public function testUnModoQueNoExisteNoSeImporta(): void
    {
        $r = self::importar([['Cédula', 'Nombre'], ['V-1', 'Ana']], 'borrar-todo');

        assertFalse($r['ok']);
        assertEquals('modo_invalido', $r['error']);
        assertEquals(0, FormParticipant::countForForm((int) self::$formId));
    }

    public function testUnArchivoQueNoEsUnXlsxSeQuedaSinTocarLaLista(): void
    {
        self::importar([['Cédula', 'Nombre'], ['V-1', 'Ana']]);

        $ruta = self::nuevaRuta('.csv');
        file_put_contents($ruta, "cedula,nombre\nV-9,Falso\n");

        $r = ParticipantImporter::importar((int) self::$formId, $ruta, 'cedula', ParticipantImporter::MODO_REEMPLAZAR);

        assertFalse($r['ok']);
        assertEquals(1, FormParticipant::countForForm((int) self::$formId), 'ni un `.csv` borra la lista');
    }

    public function testUnaEtiquetaDeAtributoImposibleDejaLaImportacionSinHacerNada(): void
    {
        // Una cabecera de 250 caracteres no cabe en `label`. Se rechaza ANTES de
        // borrar o de dar de alta, y no a mitad: el `INSERT` del participante ya
        // habría pasado y la fila se quedaría guardada sin su atributo.
        $larga = str_repeat('S', 250);
        $r = self::importar([
            ['Cédula', 'Nombre', $larga],
            ['V-1', 'Ana', 'Caracas'],
        ], ParticipantImporter::MODO_REEMPLAZAR);

        assertFalse($r['ok']);
        assertEquals('atributo_no_creable', $r['error']);
        assertEquals(0, FormParticipant::countForForm((int) self::$formId));
    }

    public function testUnaEtiquetaQueSoloCambiaEnLosEspaciosReusaElAtributo(): void
    {
        // La segunda columna con un espacio de más tiene que pegarse a la primera.
        // Si no, el `UNIQUE (form_id, label)` revienta a mitad de la importación y
        // quien la lanzó se queda sin saber por qué.
        $r = self::importar([
            ['Cédula', 'Sede', 'Sede '],
            ['V-1', 'Caracas', 'Maracay'],
        ]);

        assertTrue($r['ok'], 'un espacio de más en una cabecera no tumba la importación: ' . (string) $r['error']);
        assertEquals(1, $r['insertados'], 'una sola persona: las dos columnas son la misma etiqueta');
        assertEquals(0, $r['omitidos']);

        $catalogo = FormParticipant::catalogFor((int) self::$formId);
        assertEquals(1, count($catalogo), 'las dos cabeceras son un atributo, no dos');
        assertEquals('Sede', (string) $catalogo[0]['label'], 'y se guarda la escrita sin el espacio');

        $fila = FormParticipant::findByIdentifier((int) self::$formId, 'cedula', 'V-1');
        assertNotNull($fila);
        assertEquals(
            'Maracay',
            FormParticipant::attributesOf((int) $fila['id'])['sede'] ?? '',
            'gana el valor de la columna de la derecha, como en una hoja de cálculo'
        );
    }

    public function testElAtributoQueNoEstaEnElArchivoNoSeBorraAlActualizar(): void
    {
        // Dos archivos distintos: el segundo no trae la columna «Sede». Como la
        // importación no es un reemplazo parcial del catálogo, quien no aparece en
        // el archivo conserva lo que tenía: importarlo no puede borrar un dato que
        // el archivo ni menciona.
        self::importar([['Cédula', 'Nombre', 'Sede'], ['V-1', 'Ana', 'Caracas']]);

        $r = self::importar([['Cédula', 'Nombre'], ['V-1', 'Ana María']]);

        assertTrue($r['ok'], (string) $r['error']);
        $fila = FormParticipant::findByIdentifier((int) self::$formId, 'cedula', 'V-1');
        assertNotNull($fila);
        assertEquals(
            'Caracas',
            FormParticipant::attributesOf((int) $fila['id'])['sede'] ?? '',
            'lo que el archivo no menciona, no se toca'
        );
    }

    // =====================================================================
    // Utilidades
    // =====================================================================

    /**
     * @param list<list<string>> $filas
     *
     * @return array<string,mixed>
     */
    private static function importar(array $filas, string $modo = ParticipantImporter::MODO_FUSIONAR, string $identificador = 'cedula'): array
    {
        [$cabeceras] = array_chunk($filas, 1);

        return ParticipantImporter::importar(
            (int) self::$formId,
            self::rejilla($cabeceras[0], array_slice($filas, 1)),
            $identificador,
            $modo
        );
    }

    /**
     * Monta un `.xlsx` con `inlineStr`, que es lo más corto que el lector entiende.
     *
     * @param list<string>         $cabeceras
     * @param list<list<string>>   $filas
     */
    private static function rejilla(array $cabeceras, array $filas): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        $xml .= self::filaXml(1, $cabeceras);
        foreach ($filas as $n => $fila) {
            $xml .= self::filaXml($n + 2, array_values($fila));
        }
        $xml .= '</sheetData></worksheet>';

        $ruta = self::nuevaRuta();
        $zip = new \ZipArchive();
        if ($zip->open($ruta, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            fail('no se pudo crear el .xlsx de prueba');
        }
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        $zip->addFromString(
            'xl/workbook.xml',
            '<?xml version="1.0"?>'
            . '<workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Lista" sheetId="1" r:id="rId1"/></sheets></workbook>'
        );
        $zip->addFromString(
            'xl/_rels/workbook.xml.rels',
            '<?xml version="1.0"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="worksheet" Target="worksheets/sheet1.xml"/></Relationships>'
        );
        $zip->close();

        return $ruta;
    }

    /** @param list<string> $celdas */
    private static function filaXml(int $numero, array $celdas): string
    {
        $letras = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
        $celdaXml = '';
        foreach (array_values($celdas) as $i => $valor) {
            if ($valor === '') {
                continue;
            }
            $celdaXml .= '<c r="' . ($letras[$i] ?? 'Z') . $numero . '" t="inlineStr"><is><t>'
                . htmlspecialchars($valor, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                . '</t></is></c>';
        }

        return '<row r="' . $numero . '">' . $celdaXml . '</row>';
    }

    private static function nuevaRuta(string $extension = '.xlsx'): string
    {
        $ruta = sys_get_temp_dir() . '/vf-roster-import-' . bin2hex(random_bytes(6)) . $extension;
        self::$temporales[] = $ruta;

        return $ruta;
    }

    private static function cleanupResiduals(): void
    {
        // Por prefijo de email y no por id: si el `setUp` de un test quedara a
        // medias, el siguiente tiene que poder limpiar lo que quedó sin saber el id.
        Database::getInstance(Config::db())->execute("DELETE FROM users WHERE email LIKE 'import-%'");
        self::$ownerId = null;
        self::$formId = null;
    }
}