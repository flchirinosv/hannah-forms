<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Plantilla `.xlsx` del listado de participantes.
 *
 * Un `.xlsx` es un contenedor ZIP con cinco partes XML dentro. No hay atajo ni
 * formato intermedio: sin `ZipArchive` no hay archivo, y sin un parser XML no hay
 * forma de leer lo que el usuario devuelve re-guardado por Excel o LibreOffice
 * (que lo reescriben con su propia estructura y con tabla de cadenas
 * compartidas).
 *
 * OJO con lo que este servicio NO hace y por qué:
 *
 *  - **No usa tabla de cadenas compartidas.** Cada celda de texto va como
 *    `inlineStr`, que es XML legible. Una hoja con 500 filas y 6 columnas repite
 *    3000 cadenas en el archivo, que sigue pesando unos 100 KB, y a cambio no hay
 *    una quinta parte que mantener sincronizada con sus índices. Excel y
 *    LibreOffice leen las dos formas sin problema.
 *  - **No escribe `styles.xml`.** Se aplica el estilo por defecto, que es lo que
 *    quiere quien va a rellenar la plantilla. Añadirlo sería una sexta parte que
 *    no aporta nada a este uso y sí es una fuente de archivos que Excel rechaza.
 *  - **No inventa datos de ejemplo.** Una fila de ejemplo es una fila que alguien
 *    se deja puesta y que acaba en la lista como un participante de mentira. La
 *    plantilla son cabeceras y nada más.
 *
 * Los textos van SIEMPRE como cadenas, nunca como números, aunque el valor lo sea:
 * una cédula `00123` o un código `0007` que se guarden como número pierden los
 * ceros por la izquierda en cuanto se reabre el archivo, y para una lista de
 * participantes eso no es cosmético, es un dato perdido sin aviso.
 */
final class RosterTemplate
{
    /** Etiquetas de las columnas fijas, que son también las del archivo. */
    private const ETIQUETA = [
        'cedula'    => 'cedula',
        'nombre'    => 'nombre',
        'correo'    => 'correo',
        'indicador' => 'indicador',
        'codigo'    => 'codigo',
    ];

    /** Columnas fijas de la plantilla, en orden. */
    public const COLUMNS = ['cedula', 'nombre', 'correo', 'indicador'];

    /**
     * Cabeceras reservadas que NO se convierten en atributo libre.
     *
     * El mapa es `cabecera normalizada => columna del participante`, y se lee con
     * `normalizarCabecera()` para que una plantilla que el dueño escribió a mano
     * con «Cédula» o «CEDULA» en vez de la de la aplicación se importe igual, en
     * vez de crear un atributo «Cédula» que no se puede filtrar de forma estable.
     */
    public const RESERVED = [
        'cedula'    => 'cedula',
        'documento' => 'cedula',
        'nombre'    => 'nombre',
        'nombres'   => 'nombre',
        'correo'    => 'correo',
        'email'     => 'correo',
        'indicador' => 'indicador',
        'codigo'    => 'codigo',
    ];

    /**
     * ¿Este servidor puede escribir un `.xlsx`?
     *
     * Se consulta ANTES de intentar descargar, y no desde dentro del try/catch del
     * controlador: el mensaje que necesita quien lo lee es «instala php-zip», no
     * «class ZipArchive not found», que además en PHP es un error fatal y no una
     * excepción que se pueda capturar.
     */
    public static function puedeEscribir(): bool
    {
        return class_exists(\ZipArchive::class);
    }

    /**
     * Explicación para la pantalla cuando falta la extensión.
     */
    public static function motivoNoDisponible(): string
    {
        return 'Este servidor no puede generar archivos .xlsx porque falta la extensión '
            . 'PHP «zip» (clase ZipArchive). Instálala y vuelve a intentarlo.';
    }

    /**
     * Plantilla en blanco: solo las cabeceras, sin filas.
     */
    public static function plantilla(string $nombreHoja = 'Participantes'): string
    {
        return self::escribir($nombreHoja, self::cabeceras(), []);
    }

    /**
     * Exporta una lista ya guardada, para poder corregirla y volver a subirla.
     *
     * @param array<int, array<string,mixed>> $participantes cada uno con `id`, las
     *        columnas fijas y `data` => [clave => valor]
     * @param array<int, array{label?:string,key_name?:string}> $atributos catálogo
     * @param array<int|string,string> $codigos código de acceso por id de participante
     */
    public static function exportar(
        array $participantes,
        array $atributos,
        array $codigos = [],
        string $nombreHoja = 'Participantes'
    ): string {
        $cabeceras = self::cabeceras();
        foreach ($atributos as $atributo) {
            $cabeceras[] = (string) ($atributo['label'] ?? $atributo['key_name'] ?? '');
        }
        $cabeceras[] = self::ETIQUETA['codigo'];

        $filas = [];
        foreach ($participantes as $participante) {
            $celdas = [];
            foreach (self::COLUMNS as $columna) {
                $celdas[] = (string) ($participante[$columna] ?? '');
            }
            $data = is_array($participante['data'] ?? null) ? $participante['data'] : [];
            foreach ($atributos as $atributo) {
                $celdas[] = (string) ($data[(string) ($atributo['key_name'] ?? '')] ?? '');
            }
            $celdas[] = (string) ($codigos[$participante['id'] ?? 0] ?? '');
            $filas[] = $celdas;
        }

        return self::escribir($nombreHoja, $cabeceras, $filas);
    }

    /**
     * Normaliza una cabecera escrita a mano para compararla con las reservadas.
     */
    public static function normalizarCabecera(string $cabecera): string
    {
        $minusculas = mb_strtolower(trim($cabecera), 'UTF-8');
        // Se quitan tildes y diéresis: «cédula» y «cedula» tienen que ser la misma
        // columna, no dos atributos que el dueño no puede distinguir.
        $plano = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $minusculas);
        if (is_string($plano) && $plano !== '') {
            $minusculas = $plano;
        }

        return preg_replace('/[^a-z0-9]+/', '', $minusculas) ?? $minusculas;
    }

    /**
     * ¿Esta cabecera del archivo es una columna fija?
     */
    public static function esReservada(string $cabecera): bool
    {
        return isset(self::RESERVED[self::normalizarCabecera($cabecera)]);
    }

    /**
     * Columna del participante a la que apunta una cabecera, o `null` si es un
     * atributo libre.
     */
    public static function columnaReservada(string $cabecera): ?string
    {
        return self::RESERVED[self::normalizarCabecera($cabecera)] ?? null;
    }

    /**
     * @return string[]
     */
    private static function cabeceras(): array
    {
        $cabeceras = [];
        foreach (self::COLUMNS as $columna) {
            $cabeceras[] = self::ETIQUETA[$columna] ?? $columna;
        }

        return $cabeceras;
    }

    /**
     * @param string[] $cabeceras
     * @param array<int, string[]> $filas
     */
    private static function escribir(string $nombreHoja, array $cabeceras, array $filas): string
    {
        if (!self::puedeEscribir()) {
            throw new \RuntimeException(self::motivoNoDisponible());
        }

        // `ZipArchive::open()` necesita una ruta real de disco: no acepta un
        // `php://temp`. El temporal va en el directorio del sistema y no en
        // `storage/`, porque esto no es contenido de la aplicación sino un
        // archivo de paso que existe menos de un segundo.
        $ruta = sys_get_temp_dir() . '/vf-roster-' . bin2hex(random_bytes(6)) . '.xlsx';
        try {
            $zip = new \ZipArchive();
            $codigo = $zip->open($ruta, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            if ($codigo !== true) {
                throw new \RuntimeException(
                    'No se pudo crear el archivo .xlsx (código ' . (int) $codigo . ').'
                );
            }

            $zip->addFromString('[Content_Types].xml', self::contentTypes());
            $zip->addFromString('_rels/.rels', self::rels());
            $zip->addFromString('xl/workbook.xml', self::workbook($nombreHoja));
            $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRels());
            $zip->addFromString('xl/worksheets/sheet1.xml', self::hoja($cabeceras, $filas));
            $zip->close();

            $binario = file_get_contents($ruta);
            if ($binario === false) {
                throw new \RuntimeException('No se pudo leer el .xlsx recién generado.');
            }
        } finally {
            if (is_file($ruta)) {
                @unlink($ruta);
            }
        }

        return $binario;
    }

    /**
     * El nombre de la pestaña lo pone Excel, no la aplicación: 31 caracteres y
     * sin `[]:*?/\`. Una lista de formularios con nombre largo recortaría el
     * archivo entero, así que se normaliza antes de escribirlo en el XML.
     */
    private static function nombreHoja(string $nombre): string
    {
        $limpio = preg_replace('/[\\[\\]:*?\\/\\\\]/u', ' ', $nombre) ?? $nombre;
        $limpio = trim(preg_replace('/\s+/u', ' ', $limpio) ?? $limpio);
        if ($limpio === '') {
            $limpio = 'Participantes';
        }

        return mb_substr($limpio, 0, 31, 'UTF-8');
    }

    private static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml"'
            . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml"'
            . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>';
    }

    private static function rels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"'
            . ' Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function workbook(string $nombreHoja): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::esc(self::nombreHoja($nombreHoja))
            . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private static function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
            . ' Target="worksheets/sheet1.xml"/>'
            . '</Relationships>';
    }

    /**
     * @param string[] $cabeceras
     * @param array<int, string[]> $filas
     */
    private static function hoja(array $cabeceras, array $filas): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>';
        $xml .= self::fila(1, $cabeceras);

        // El `r` de fila y de celda es correlativo porque Excel lo respeta: si el
        // `r` de una celda no cuadra con su posición, la hoja abre desordenada.
        $r = 2;
        foreach ($filas as $fila) {
            $xml .= self::fila($r++, array_values($fila));
        }

        return $xml . '</sheetData></worksheet>';
    }

    /**
     * @param string[] $celdas
     */
    private static function fila(int $numero, array $celdas): string
    {
        $xml = '<row r="' . $numero . '">';
        $columna = 0;
        foreach ($celdas as $celda) {
            $columna++;
            $valor = (string) $celda;
            if ($valor === '') {
                continue;
            }
            $xml .= '<c r="' . self::columna($columna) . $numero . '" t="inlineStr"><is>'
                . '<t xml:space="preserve">' . self::esc($valor) . '</t></is></c>';
        }

        return $xml . '</row>';
    }

    /**
     * 1 -> A, 26 -> Z, 27 -> AA. El índice es 1-based porque es la posición en la
     * hoja, no un número de letra.
     */
    private static function columna(int $indice): string
    {
        $letras = '';
        while ($indice > 0) {
            $letras = chr(65 + (($indice - 1) % 26)) . $letras;
            $indice = intdiv($indice - 1, 26);
        }

        return $letras;
    }

    /**
     * Escapa para XML y descarta lo que XML 1.0 prohíbe.
     *
     * El descarte no es hipotético: los caracteres de control llegan pegados de
     * una cabecera escrita en Excel, y con uno suelto el archivo no abre, con un
     * mensaje de Excel que no menciona el archivo que generó la aplicación. Se
     * quitan los C0 salvo tabulador, salto de línea y retorno de carro.
     */
    private static function esc(string $valor): string
    {
        if (preg_match('//u', $valor) !== 1) {
            // Bytes UTF-8 inválidos: `htmlspecialchars` los deja pasar y el
            // documento resultante no sería XML válido.
            $valor = mb_convert_encoding($valor, 'UTF-8', 'UTF-8');
        }

        $valor = preg_replace('/[^\x09\x0A\x0D\x20-\x{10FFFF}]/u', '', $valor) ?? '';

        return htmlspecialchars($valor, \ENT_XML1 | \ENT_QUOTES, 'UTF-8');
    }
}