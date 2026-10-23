<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\RosterTemplate;

/**
 * Fase 1 — el escritor `.xlsx`.
 *
 * Un `.xlsx` es un ZIP con XML dentro, y **cualquier cosa que no cuadre produce un
 * archivo que no abre**, con un mensaje de Excel que no menciona la aplicación que
 * lo generó. Por eso estos tests no miran el binario: lo abren con `ZipArchive`,
 * parsean cada parte con DOM y comprueban la hoja como la vería una hoja de
 * cálculo. Un test que solo mirase que el archivo "no está vacío" pasaría con un
 * ZIP corrupto.
 *
 * Los tres casos que de verdad rompen archivos, y que se comprueban aquí:
 *
 *  1. **Una celda vacía que desplaza las referencias.** Si la columna se calcula
 *     contando las celdas escritas en vez de su posición, `['Ana']` en la
 *     segunda columna se escribe como `A2` y la hoja abre con el nombre corrido un
 *     sitio, en silencio y sin error.
 *  2. **Un `&`, un `<` o una tilde sin escapar.** Rompe el XML entero: no una
 *     celda, el archivo.
 *  3. **Un carácter de control pegado de una cabecera escrita en Excel.** XML 1.0
 *     los prohíbe y el archivo no abre.
 */
class RosterTemplateTest
{
    public function testEnEsteServidorSePuedeEscribirUnXlsx(): void
    {
        assertTrue(RosterTemplate::puedeEscribir(), 'la extensión zip debe estar instalada');
        assertTrue(extension_loaded('dom'), 'necesaria para leer el .xlsx en los tests y en la importación');
        assertTrue(extension_loaded('mbstring'));
    }

    public function testElMotivoQueSeVeSinLaExtensionDiceQueInstalarZip(): void
    {
        // No se puede quitar ZipArchive en caliente, así que el mensaje se ata a
        // algo comprobable: que nombre la extensión, y no «class not found».
        $motivo = RosterTemplate::motivoNoDisponible();
        assertTrue(str_contains($motivo, 'zip'), 'el motivo tiene que decir qué extensión falta');
        assertTrue(str_contains($motivo, 'ZipArchive'));
    }

    public function testLaPlantillaTraeLasCincoPartesMinimas(): void
    {
        $partes = self::partes(RosterTemplate::plantilla());

        foreach ([
            '[Content_Types].xml',
            '_rels/.rels',
            'xl/workbook.xml',
            'xl/_rels/workbook.xml.rels',
            'xl/worksheets/sheet1.xml',
        ] as $esperada) {
            assertArrayHasKey($esperada, $partes);
        }
        // Ni una más: una parte que nadie declara en `[Content_Types].xml` es
        // justo lo que hace que Excel diga «el archivo está dañado».
        assertEquals(5, count($partes));
    }

    public function testCadaParteEsXmlBienFormado(): void
    {
        foreach (self::partes(RosterTemplate::plantilla()) as $nombre => $contenido) {
            assertTrue(self::xmlValido($contenido), 'la parte ' . $nombre . ' no es XML válido');
        }
    }

    public function testLasCabecerasDeLaPlantillaSonLasCuatroFijas(): void
    {
        $celdas = self::celdas(self::parte(RosterTemplate::plantilla(), 'xl/worksheets/sheet1.xml'));

        assertEquals('Cédula', $celdas['A1']);
        assertEquals('Nombre completo', $celdas['B1']);
        assertEquals('Correo', $celdas['C1']);
        assertEquals('Teléfono', $celdas['D1']);
    }

    public function testLaPlantillaNoTraeFilasDeEjemplo(): void
    {
        // Una fila de ejemplo es un participante de mentira que alguien se deja
        // puesta. Solo la fila de cabeceras.
        $doc = self::documento(self::parte(RosterTemplate::plantilla(), 'xl/worksheets/sheet1.xml'));

        assertEquals(1, $doc->getElementsByTagName('row')->length);
    }

    public function testTodoViaComoCadenaParaNoPerderLosCerosPorLaIzquierda(): void
    {
        $binario = RosterTemplate::exportar(
            [['id' => 1, 'cedula' => '00123', 'nombre' => 'Ana', 'correo' => '', 'indicador' => '']],
            [],
            [1 => 'A1B2C3D4E5F6G7H8']
        );
        $celdas = self::celdas(self::parte($binario, 'xl/worksheets/sheet1.xml'));

        assertEquals('00123', $celdas['A2']);
        assertEquals('A1B2C3D4E5F6G7H8', $celdas['E2']);

        $xml = self::parte($binario, 'xl/worksheets/sheet1.xml');
        // Si alguna celda fuera numérica, el `t` sería el de número y el valor
        // viajaría en `<v>`; con `<v>00123</v>` Excel abre la hoja y enseña 123.
        assertFalse(str_contains($xml, '<v>'), 'ninguna celda puede ir como número');
        assertFalse(str_contains($xml, 't="n"'));
    }

    public function testUnaCeldaVaciaNoMueveLasColumnasSiguientes(): void
    {
        // La referencia se calcula por POSICIÓN, no contando celdas escritas: si
        // una vacía no contara, el nombre caería en A2 y la hoja abriría
        // desplazada sin ningún error.
        $binario = RosterTemplate::exportar(
            [['id' => 1, 'cedula' => '', 'nombre' => 'Ana', 'correo' => 'a@b.test']],
            [],
            []
        );
        $celdas = self::celdas(self::parte($binario, 'xl/worksheets/sheet1.xml'));

        assertEquals('Ana', $celdas['B2']);
        assertEquals('a@b.test', $celdas['C2']);
        assertFalse(array_key_exists('A2', $celdas), 'la cédula vacía no escribe celda');
    }

    public function testUnSimboloYUnaTildeEnUnaCabeceraNoRompenElArchivo(): void
    {
        $atributos = [['label' => 'Sede & zona <2026>', 'key_name' => 'sede']];
        $binario = RosterTemplate::exportar([], $atributos, []);
        $celdas = self::celdas(self::parte($binario, 'xl/worksheets/sheet1.xml'));

        assertEquals('Sede & zona <2026>', $celdas['E1']);
        assertTrue(self::xmlValido(self::parte($binario, 'xl/worksheets/sheet1.xml')));
    }

public function testLosCaracteresDeControlSeDescartanYElArchivoAbre(): void
    {
        // `\x01` y `\x0B` vienen pegados de una cabecera escrita en Excel. XML 1.0
        // los prohíbe: con uno suelto el archivo no abre y el error que ve quien
        // lo descargó no menciona la plantilla.
        $sucios = "Sede\x01X\x0B Y";
        $binario = RosterTemplate::exportar([], [['label' => $sucios, 'key_name' => 'sede']], []);

        $xml = self::parte($binario, 'xl/worksheets/sheet1.xml');
        assertTrue(self::xmlValido($xml));
        assertFalse(str_contains($xml, "\x01"), 'un carácter prohibido no puede quedar en el XML');
        assertFalse(str_contains($xml, "\x0B"));
        // Se descartan los caracteres, no los espacios que los rodean: quitar el
        // blanco de más sería inventarse un dato del usuario.
        assertEquals('SedeX Y', self::celdas($xml)['E1']);
    }

    public function testUnaColumnaMasAllaDeLaZNoSeSaleDelRangoVisible(): void
    {
        // 4 fijas + 23 atributos + 1 código = 28 columnas → la última es AB.
        // Con el `chr(64 + n)` ingenuo salía «AB» pero se rompía en la 27, y en la
        // 53 daba «[» sin ningún aviso.
        $atributos = [];
        for ($i = 1; $i <= 23; $i++) {
            $atributos[] = ['label' => 'a' . $i, 'key_name' => 'a' . $i];
        }
        $binario = RosterTemplate::exportar([], $atributos, []);
        $celdas = self::celdas(self::parte($binario, 'xl/worksheets/sheet1.xml'));

        assertEquals('a1', $celdas['E1']);
        assertEquals('a23', $celdas['AA1']);
        assertEquals('codigo', $celdas['AB1']);
    }

    public function testElNombreDeLaPestañaSeLimpiaYSeRecorta(): void
    {
        // Excel pone un límite de 31 caracteres y prohíbe `[]:*?/\`: un nombre de
        // formulario con corchetes hacía un archivo que no abría.
        $binario = RosterTemplate::plantilla('Inventario [2026]:Miembro/Equipo*?');
        $doc = self::documento(self::parte($binario, 'xl/workbook.xml'));
        $hoja = $doc->getElementsByTagName('sheet')->item(0);
        assertNotNull($hoja);
        $nombre = (string) $hoja->getAttribute('name');

        assertEquals('Inventario 2026 Miembro Equipo', $nombre);
        assertTrue(mb_strlen($nombre, 'UTF-8') <= 31);
    }

    public function testUnNombreDePestañaEnBlancoCaenEnUnoPorDefecto(): void
    {
        $binario = RosterTemplate::plantilla('   ');
        $doc = self::documento(self::parte($binario, 'xl/workbook.xml'));

        assertEquals('Participantes', $doc->getElementsByTagName('sheet')->item(0)->getAttribute('name'));
    }

    public function testUnaCabeceraReservadaSeReconoceAunQueElDuenoLaEscribaDistinta(): void
    {
        // Si «Cédula» no se normalizara, se crearía un atributo «Cédula» que el
        // dueño no puede filtrar de forma estable y que se solapa con la cédula.
        foreach (['Cédula', 'CEDULA ', 'cédula', 'documento', 'Nombres', 'correo', 'Email', 'e-mail'] as $variante) {
            assertTrue(RosterTemplate::esReservada($variante), $variante . ' debería ser reservada');
        }

        assertEquals('cedula', RosterTemplate::columnaReservada('Cédula'));
        assertEquals('correo', RosterTemplate::columnaReservada('E-MAIL'));
        assertNull(RosterTemplate::columnaReservada('Sede'));

        assertFalse(RosterTemplate::esReservada('Sede'));
        assertFalse(RosterTemplate::esReservada(''));
    }

    public function testLaColumnaDeAtributosVaDetrasDeLasFijasYTerminadaEnCodigo(): void
    {
        // El orden del archivo es un contrato con la importación: fijas, atributos,
        // código. Si el código no fuera el último se leería como atributo libre.
        $binario = RosterTemplate::exportar(
            [],
            [['label' => 'Sede', 'key_name' => 'sede']],
            []
        );
        $celdas = self::celdas(self::parte($binario, 'xl/worksheets/sheet1.xml'));

        assertEquals('indicador', $celdas['D1']);
        assertEquals('Sede', $celdas['E1']);
        assertEquals('codigo', $celdas['F1']);
    }

    // ---- Utilidades ----

    /**
     * Abre el ZIP y devuelve `parte => contenido`.
     *
     * @return array<string,string>
     */
    private static function partes(string $binario): array
    {
        $ruta = sys_get_temp_dir() . '/vf-roster-test-' . bin2hex(random_bytes(6)) . '.xlsx';
        file_put_contents($ruta, $binario);

        $zip = new \ZipArchive();
        try {
            assertTrue($zip->open($ruta) === true, 'el .xlsx generado no abre como ZIP');
            $salida = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $contenido = $zip->getFromIndex($i);
                assertTrue($contenido !== false);
                $salida[(string) $zip->getNameIndex($i)] = $contenido;
            }

            return $salida;
        } finally {
            $zip->close();
            if (is_file($ruta)) {
                @unlink($ruta);
            }
        }
    }

    private static function parte(string $binario, string $nombre): string
    {
        $partes = self::partes($binario);
        assertArrayHasKey($nombre, $partes);

        return $partes[$nombre];
    }

    private static function documento(string $xml): \DOMDocument
    {
        $doc = new \DOMDocument();
        assertTrue(self::cargarXml($doc, $xml), 'XML inválido: ' . self::erroresXml());

        return $doc;
    }

    private static function xmlValido(string $xml): bool
    {
        $doc = new \DOMDocument();

        return self::cargarXml($doc, $xml);
    }

    private static function cargarXml(\DOMDocument $doc, string $xml): bool
    {
        $previo = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previo);

        return $ok;
    }

    private static function erroresXml(): string
    {
        $errores = '';
        foreach (libxml_get_errors() as $error) {
            $errores .= trim($error->message) . ' ';
        }
        libxml_clear_errors();

        return $errores;
    }

    /**
     * Devuelve `referencia => texto` de todas las celdas con texto de la hoja.
     *
     * @return array<string,string>
     */
    private static function celdas(string $xml): array
    {
        $doc = self::documento($xml);
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $salida = [];
        foreach ($xpath->query('//m:sheetData/m:row/m:c') ?: [] as $celda) {
            $texto = $xpath->query('m:is/m:t', $celda)->item(0);
            $salida[$celda->getAttribute('r')] = $texto !== null ? $texto->textContent : '';
        }

        return $salida;
    }
}