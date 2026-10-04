<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\RosterTemplate;

/**
 * Fase 3 — el LECTOR `.xlsx`.
 *
 * El escritor de la fase 1 se probó contra un lector, pero un lector que solo
 * entiende lo que escribe uno mismo no sirve para nada: el archivo que se importa
 * lo **`guarda una persona en Excel o LibreOffice**, y su salida no es la del
 * escritor. Por eso el grueso de estos tests construye el `.xlsx` a mano, con las
 * formas que de verdad usan esos programas, y no con `escribir()`.
 *
 * Los cinco que de verdad rompen una importación, en orden de disponibilidad:
 *
 *  1. **`sharedStrings.xml`.** Es lo que escribe Excel y no `t="inlineStr"`. Un
 *     lector que solo sepa de cadenas en línea lee un archivo de verdad y no ve
 *     **ni una sola celda**, sin un solo error.
 *  2. **La hoja no es `sheet1.xml`.** Si el usuario borra la primera hoja, reordena
 *     o duplica, el nombre del archivo deja de ir con el orden visible. Un lector
 *     que abra `sheet1.xml` a pelo lee la hoja equivocada o ninguna.
 *  3. **Un `<si>` partido en varios `<t>`.** Con formato dentro de la celda, el
 *     valor se reparte entre runs y quedarse con el primero devuelve media
 *     palabra — un nombre «Cocha» en vez de «Cochabamba».
 *  4. **Los huecos.** Excel no escribe la celda vacía; la columna la fija el `r` de
 *     la siguiente. Contar celdas en vez de mirar la referencia corre la fila entera
 *     hacia la izquierda, y un participante hereda la cédula de otro.
 *  5. **La ZIP bomba.** Un `.xlsx` de 2 MB puede declarar una parte de 6 GB.
 */
class RosterReadTest
{
    /**
     * Ficheros temporales de los fixtures, en `static` a propósito.
     *
     * Los helpers que construyen el `.xlsx` son estáticos y no reciben `$this`,
     * así que el registro tiene que ser estático también. Y tiene que vivir aquí,
     * no en el `tearDown`: si el proceso muere a mitad de un test, el `tearDown`
     * no corre y los archivos se quedan en `/tmp` para siempre.
     *
     * @var list<string>
     */
    private static array $temporales = [];

    public function tearDown(): void
    {
        foreach (self::$temporales as $ruta) {
            @unlink($ruta);
        }
        self::$temporales = [];
    }

    // =====================================================================
    // Ida y vuelta con lo que escribe la aplicación
    // =====================================================================

    public function testElArchivoQueExportamosSeVuelveALeerIgualQueEstaba(): void
    {
        // El contrato que importa: exportar la lista para corregirla y volver a
        // subirla tiene que devolver LOS MISMOS datos, no una versión degradada.
        $participantes = [
            ['id' => 1, 'cedula' => 'V-1', 'nombre' => 'Ana Pérez', 'correo' => '', 'indicador' => 'C-1', 'data' => []],
            ['id' => 2, 'cedula' => 'V-2', 'nombre' => 'Luis & María <R>', 'correo' => 'luis@x.com', 'indicador' => '', 'data' => ['sede' => 'Caracas']],
        ];
        $atributos = [['key_name' => 'sede', 'label' => 'Sede']];

        $ruta = self::guardar(RosterTemplate::exportar($participantes, $atributos, [1 => 'a1b2c3', 2 => '']));
        $leido = RosterTemplate::leer($ruta);

        assertEquals(
            ['cedula', 'nombre', 'correo', 'indicador', 'Sede', 'codigo'],
            $leido['cabeceras'],
            'las cabeceras del archivo exportado deben releerse tal cual'
        );
        assertEquals(2, count($leido['filas']));
        assertEquals(['V-1', 'Ana Pérez', '', 'C-1', '', 'a1b2c3'], $leido['filas'][0]);
        // El `&` y el `<` es lo que un nombre real trae, y un lector que no
        // deshace las entidades lo leería como algo que no es el texto guardado.
        assertEquals('Luis & María <R>', $leido['filas'][1][1]);
        // Y el hueco de la fila 2 (correo vacío) no corrió «Caracas» de sitio.
        assertEquals('Caracas', $leido['filas'][1][4]);
        assertEquals('', $leido['filas'][1][5], 'el código vacío es una celda vacía, no la anterior');
    }

    // =====================================================================
    // Lo que hacen Excel y LibreOffice
    // =====================================================================

    public function testLasCeldasEnHuecoNoCorrenLasColumnasSiguientes(): void
    {
        // A, B y F: cinco columnas de hueco en el medio. Es el fallo que hace que
        // un participante se guarde con el valor del de al lado sin que nada falle.
        $ruta = self::fixture([
            'xl/worksheets/sheet1.xml' => self::hoja([
                '<row r="1"><c r="A1" t="inlineStr"><is><t>a</t></is></c>'
                . '<c r="B1" t="inlineStr"><is><t>b</t></is></c>'
                . '<c r="F1" t="inlineStr"><is><t>f</t></is></c></row>',
                '<row r="2"><c r="A2" t="inlineStr"><is><t>1</t></is></c>'
                . '<c r="F2" t="inlineStr"><is><t>6</t></is></c></row>',
            ]),
        ]);

        $leido = RosterTemplate::leer($ruta);

        // La fila sale con las TRES columnas que nombra la cabecera, no con seis:
        // el hueco no inventa una columna. Y el «6» cae en «f» y no en «b», que es
        // justo lo que falla un lector que cuenta celdas en vez de mirar el `r`.
        assertEquals(['a', 'b', 'f'], $leido['cabeceras']);
        assertEquals(['1', '', '6'], $leido['filas'][0]);
    }

    public function testLaHojaSeResuelvePorElWorkbookYNoPorSuNombreDeArchivo(): void
    {
        // La primera hoja del `workbook` es `sheet2.xml`, y el `Target` de la
        // relación viene con barra inicial, que es una de las dos formas que hay.
        $ruta = self::fixture([
            'xl/worksheets/sheet2.xml' => self::hoja([
                '<row r="1"><c r="A1" t="inlineStr"><is><t>buena</t></is></c></row>',
            ]),
            'xl/worksheets/sheet1.xml' => self::hoja([
                '<row r="1"><c r="A1" t="inlineStr"><is><t>la otra</t></is></c></row>',
            ]),
            'xl/workbook.xml' => '<?xml version="1.0"?><workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . '<sheets><sheet name="Lista" sheetId="2" r:id="rId7"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId7" Type="worksheet" Target="/xl/worksheets/sheet2.xml"/></Relationships>',
        ]);

        $leido = RosterTemplate::leer($ruta);

        assertEquals(['buena'], $leido['cabeceras'], 'tiene que leer la hoja que el workbook declara primera');
    }

    public function testUnStringCompartidoPartidoEnVariosRunsSeJuntaEntero(): void
    {
        // Es lo que sale cuando hay formato dentro de la celda: el valor se reparte
        // en `<r><t>` y quedarse con el primero devuelve media palabra.
        $ruta = self::fixture([
            'xl/sharedStrings.xml' => self::sst('<si><r><t>Cocha</t></r><r><t>bamba</t></r></si>'),
            'xl/worksheets/sheet1.xml' => self::hoja([
                '<row r="1"><c r="A1" t="s"><v>0</v></c></row>',
            ]),
        ]);

        $leido = RosterTemplate::leer($ruta);

        assertEquals(['Cochabamba'], $leido['cabeceras']);
    }

    public function testUnArchivoDeVerdadConSharedStringsSeLeeEntero(): void
    {
        // El caso de disponibilidad: `sharedStrings.xml` con `t="s"`. Un lector que
        // solo sepa de `inlineStr` lee un archivo de verdad y no ve una celda, sin
        // dar ningún error.
        $ruta = self::fixture([
            'xl/sharedStrings.xml' => self::sst(
                '<si><t>cedula</t></si><si><t>nombre</t></si><si><t>V-1</t></si><si><t>Ana Pérez</t></si>'
            ),
            'xl/worksheets/sheet1.xml' => self::hoja([
                '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>',
                '<row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2" t="s"><v>3</v></c></row>',
            ]),
        ]);

        $leido = RosterTemplate::leer($ruta);

        assertEquals(['cedula', 'nombre'], $leido['cabeceras']);
        assertEquals([['V-1', 'Ana Pérez']], $leido['filas']);
    }

    public function testUnNumeroSeLeeComoTextoYUnBooleanoComoSiNo(): void
    {
        $ruta = self::fixture([
            'xl/worksheets/sheet1.xml' => self::hoja([
                '<row r="1"><c r="A1" t="inlineStr"><is><t>cedula</t></is></c>'
                . '<c r="B1" t="inlineStr"><is><t>activo</t></is></c>'
                . '<c r="C1" t="inlineStr"><is><t>roto</t></is></c></row>',
                '<row r="2"><c r="A2"><v>12345</v></c><c r="B2" t="b"><v>1</v></c><c r="C2" t="e"><v>#REF!</v></c></row>',
            ]),
        ]);

        $leido = RosterTemplate::leer($ruta);

        assertEquals('12345', $leido['filas'][0][0], 'un número se lee igual, como texto');
        assertEquals('Sí', $leido['filas'][0][1]);
        // `#REF!` no es un dato del participante: se lee vacío para que no acabe
        // de participante en un filtro.
        assertEquals('', $leido['filas'][0][2]);
    }

    public function testLaPrimeraFilaConContenidoEsLaCabecera(): void
    {
        // Excel deja el `<row r="1">` vacío si alguien borró las cabeceras y volvió
        // a escribir más abajo. Un archivo sin cabecera no es un archivo sin fila 1.
        $ruta = self::fixture([
            'xl/worksheets/sheet1.xml' => self::hoja([
                '<row r="1"></row>',
                '<row r="2"><c r="A2" t="inlineStr"><is><t>cedula</t></is></c>'
                . '<c r="B2" t="inlineStr"><is><t>nombre</t></is></c></row>',
                '<row r="3"><c r="A3" t="inlineStr"><is><t>V-1</t></is></c>'
                . '<c r="B3" t="inlineStr"><is><t>Ana</t></is></c></row>',
            ]),
        ]);

        $leido = RosterTemplate::leer($ruta);

        assertEquals(['cedula', 'nombre'], $leido['cabeceras']);
        assertEquals([['V-1', 'Ana']], $leido['filas']);
    }

    public function testUnaColumnaSinNombreSeDescartaYNoDesplazaALasDemas(): void
    {
        $ruta = self::fixture([
            'xl/worksheets/sheet1.xml' => self::hoja([
                // La celda B1 NO se escribe: Excel tampoco la escribe. Una columna
                // sin nombre no es un atributo, y su contenido se pierde con ella.
                '<row r="1"><c r="A1" t="inlineStr"><is><t>cedula</t></is></c>'
                . '<c r="C1" t="inlineStr"><is><t>correo</t></is></c></row>',
                '<row r="2"><c r="A2" t="inlineStr"><is><t>V-1</t></is></c>'
                . '<c r="B2" t="inlineStr"><is><t>basura</t></is></c>'
                . '<c r="C2" t="inlineStr"><is><t>a@x.com</t></is></c></row>',
            ]),
        ]);

        $leido = RosterTemplate::leer($ruta);

        // Una columna sin nombre no es un atributo: no se puede filtrar por ella.
        assertEquals(['cedula', 'correo'], $leido['cabeceras']);
        assertEquals(['V-1', 'a@x.com'], $leido['filas'][0], '«correo» tiene que seguir siendo el correo de la fila');
    }

    public function testLasFilasDelTodoVaciasNoCuentanComoParticipantes(): void
    {
        $ruta = self::fixture([
            'xl/worksheets/sheet1.xml' => self::hoja([
                '<row r="1"><c r="A1" t="inlineStr"><is><t>cedula</t></is></c></row>',
                '<row r="2"><c r="A2" t="inlineStr"><is><t>   </t></is></c></row>',
                '<row r="3"></row>',
                '<row r="4"><c r="A4" t="inlineStr"><is><t>V-1</t></is></c></row>',
            ]),
        ]);

        $leido = RosterTemplate::leer($ruta);

        assertEquals(1, count($leido['filas']), 'una fila de espacios no es un participante');
        assertEquals(['V-1'], $leido['filas'][0]);
    }

    // =====================================================================
    // Archivos que hay que rechazar
    // =====================================================================

    public function testUnArchivoQueNoEsZipSeRechaza(): void
    {
        $ruta = self::guardar('esto es un csv, no un xlsx');
        $this->esperaError($ruta, 'roster_no_zip');
    }

    public function testUnZipSinHojaSeRechaza(): void
    {
        // Un ZIP válido sin `workbook.xml` no es un libro: puede ser un `.xlsx`
        // renombrado a mano desde cualquier otra cosa.
        $ruta = self::fixture([
            'xl/sharedStrings.xml' => self::sst('<si><t>hola</t></si>'),
        ]);
        $this->esperaError($ruta, 'roster_sin_hoja');
    }

    public function testUnaHojaSinNingunDatoSeRechaza(): void
    {
        $ruta = self::fixture([
            'xl/worksheets/sheet1.xml' => self::hoja([]),
        ]);
        $this->esperaError($ruta, 'roster_vacia');
    }

    public function testUnaHojaSinCabecerasSeRechaza(): void
    {
        $ruta = self::fixture([
            'xl/worksheets/sheet1.xml' => self::hoja([
                '<row r="1"><c r="A1" t="inlineStr"><is><t>  </t></is></c></row>',
            ]),
        ]);
        $this->esperaError($ruta, 'roster_sin_cabecera');
    }

    public function testUnaHojaConDoctypeNoSeParsea(): void
    {
        // El archivo lo sube el dueño del formulario, pero «el dueño no es un
        // atacante» no es un supuesto. Un `.xlsx` con
        // `<!ENTITY e SYSTEM "file:///etc/passwd">` en el prologo se lleva el
        // fichero entero en la celda si el parser procesa entidades.
        //
        // Aquí la entidad es **interna** (`<!ENTITY e "x">`) y no una `SYSTEM`, y
        // a propósito: una `SYSTEM` la bloquea `LIBXML_NONET` aunque el guard no
        // existiera, así que el test pasaría igual y no probaría nada. Lo que se
        // comprueba aquí es la regla que de verdad se quiere — **ningún DOCTYPE se
        // procesa**, ni el que parece inocuo — y para eso tiene que ser una
        // entidad que libxml aceptaría sin ayuda.
        $ruta = self::fixture([
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8"?>'
                . '<!DOCTYPE worksheet [<!ENTITY e "x">]>'
                . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
                . '<row r="1"><c r="A1" t="inlineStr"><is><t>cedula</t></is></c></row>'
                . '<row r="2"><c r="A2" t="inlineStr"><is><t>&e;</t></is></c></row>'
                . '</sheetData></worksheet>',
        ]);

        // Sin parsear la hoja no hay filas y el motivo es `roster_vacia`: lo que
        // importa no es el código exacto, es que el contenido del fichero local
        // no llega a ninguna celda.
        $this->esperaError($ruta, 'roster_vacia');
    }

    public function testUnaZipBombSeCortaSinDescomprimirLaParte(): void
    {
        // 20 MB declarados que en el disco son 20 KB. Se corta mirando el
        // `statName()`, o sea ANTES de pedirle el contenido: para cuando se infla,
        // la memoria ya está comprometida.
        $ruta = self::fixture([
            'xl/worksheets/sheet1.xml' => str_repeat('A', 20 * 1024 * 1024),
        ]);

        $this->esperaError($ruta, 'roster_demasiado_grande');
    }

    public function testUnFicheroQueNoExisteSeRechaza(): void
    {
        $this->esperaError(sys_get_temp_dir() . '/vf-no-existe-' . bin2hex(random_bytes(4)) . '.xlsx', 'roster_no_legible');
    }

    public function testEsteServidorPuedeLeerUnXlsx(): void
    {
        assertTrue(RosterTemplate::puedeLeer());
        // Leer y escribir son capacidades DISTINTAS: la plantilla se puede generar
        // sin poder importarse, y son dos consultas distintas en la pantalla.
        assertTrue(RosterTemplate::puedeEscribir());
    }

    // =====================================================================
    // Utilidades
    // =====================================================================

    /**
     * @param list<string> $filas
     */
    private static function hoja(array $filas): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            . implode('', $filas)
            . '</sheetData></worksheet>';
    }

    private static function sst(string $sis): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . $sis . '</sst>';
    }

    /**
     * Monta un `.xlsx` con las partes que se le pasen, y rellena las que falten
     * con un `workbook`/`rels` de una sola hoja, que es el mínimo que el lector
     * necesita para resolverla.
     *
     * @param array<string,string> $partes
     */
    private static function fixture(array $partes): string
    {
        if (!isset($partes['xl/workbook.xml'])) {
            $partes['xl/workbook.xml'] = '<?xml version="1.0"?>'
                . '<workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . '<sheets><sheet name="Lista" sheetId="1" r:id="rId1"/></sheets></workbook>';
        }
        if (!isset($partes['xl/_rels/workbook.xml.rels'])) {
            $partes['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="worksheet" Target="worksheets/sheet1.xml"/></Relationships>';
        }

        $ruta = self::nuevaRuta();
        $zip = new \ZipArchive();
        if ($zip->open($ruta, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            fail('no se pudo crear el .xlsx de prueba');
        }
        foreach ($partes as $nombre => $contenido) {
            $zip->addFromString($nombre, $contenido);
        }
        $zip->close();

        return $ruta;
    }

    private static function guardar(string $binario): string
    {
        $ruta = self::nuevaRuta();
        file_put_contents($ruta, $binario);

        return $ruta;
    }

    private static function nuevaRuta(): string
    {
        $ruta = sys_get_temp_dir() . '/vf-roster-read-' . bin2hex(random_bytes(6)) . '.xlsx';
        self::$temporales[] = $ruta;

        return $ruta;
    }

    private function esperaError(string $ruta, string $codigo): void
    {
        try {
            RosterTemplate::leer($ruta);
        } catch (\RuntimeException $e) {
            assertEquals($codigo, $e->getMessage(), 'el motivo tiene que ser el esperado');

            return;
        }
        fail('se esperaba un RuntimeException con «' . $codigo . '» y el archivo se leyó sin error');
    }
}