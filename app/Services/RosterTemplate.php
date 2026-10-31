<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FormParticipant;

/**
 * Plantilla `.xlsx` del listado de participantes.
 */
final class RosterTemplate
{
    /** Etiquetas de las columnas fijas, que son también las del archivo. */
    private const ETIQUETA = [
        'cedula'   => 'Cédula',
        'nombre'   => 'Nombre completo',
        'correo'   => 'Correo',
        'telefono' => 'Teléfono',
        'codigo'   => 'Código',
    ];

    /**
     * Cabeceras reservadas que NO se convierten en atributo libre.
     */
    public const RESERVED = [
        'cedula'       => 'cedula',
        'documento'    => 'cedula',
        'nombre'       => 'nombre',
        'nombres'      => 'nombre',
        'nombrecompleto' => 'nombre',
        'correo'       => 'correo',
        'email'        => 'correo',
        'telefono'     => 'telefono',
        'telefonico'   => 'telefono',
        'tel'          => 'telefono',
        'movil'        => 'telefono',
        'celular'      => 'telefono',
        'codigo'       => 'codigo',
    ];

    public static function puedeEscribir(): bool
    {
        return class_exists(\ZipArchive::class);
    }

    public static function motivoNoDisponible(): string
    {
        return 'Este servidor no puede generar archivos .xlsx porque falta la extensión '
            . 'PHP «zip» (clase ZipArchive). Instálala y vuelve a intentarlo.';
    }

    public static function cabeceras(): array
    {
        return ['Cédula', 'Nombre completo', 'Correo', 'Teléfono'];
    }

    public static function plantilla(string $nombreHoja = 'Participantes'): string
    {
        return self::escribir($nombreHoja, self::cabeceras(), []);
    }

    public static function exportar(
        array $participantes,
        array $atributos,
        array $codigos = [],
        string $identificador = 'cedula',
        string $nombreHoja = 'Participantes'
    ): string {
        $cabeceras = self::cabeceras();
        foreach ($atributos as $atributo) {
            $cabeceras[] = (string) ($atributo['label'] ?? $atributo['key_name'] ?? '');
        }
        $cabeceras[] = self::ETIQUETA['codigo'];

        $identArchivo = FormParticipant::identificadorDeArchivo($identificador);

        $filas = [];
        foreach ($participantes as $participante) {
            $celdas = [];
            if ($identArchivo === 'correo') {
                $celdas[] = (string) ($participante['correo'] ?? '');
            } else {
                $celdas[] = (string) ($participante['cedula'] ?? '');
            }
            $celdas[] = (string) ($participante['nombre'] ?? '');
            $celdas[] = (string) ($participante['correo'] ?? '');
            $celdas[] = (string) ($participante['telefono'] ?? '');
            $data = is_array($participante['data'] ?? null) ? $participante['data'] : [];
            foreach ($atributos as $atributo) {
                $celdas[] = (string) ($data[(string) ($atributo['key_name'] ?? '')] ?? '');
            }
            $celdas[] = (string) ($codigos[$participante['id'] ?? 0] ?? '');
            $filas[] = $celdas;
        }

        return self::escribir($nombreHoja, $cabeceras, $filas);
    }

    public static function normalizarCabecera(string $cabecera): string
    {
        $minusculas = mb_strtolower(trim($cabecera), 'UTF-8');
        $plano = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $minusculas);
        if (is_string($plano) && $plano !== '') {
            $minusculas = $plano;
        }

        return preg_replace('/[^a-z0-9]+/', '', $minusculas) ?? $minusculas;
    }

    public static function esReservada(string $cabecera): bool
    {
        return isset(self::RESERVED[self::normalizarCabecera($cabecera)]);
    }

    public static function columnaReservada(string $cabecera): ?string
    {
        return self::RESERVED[self::normalizarCabecera($cabecera)] ?? null;
    }

    private const MAX_PARTE_BYTES = 16 * 1024 * 1024;
    private const MAX_TOTAL_BYTES = 64 * 1024 * 1024;
    private const MAX_FILAS = 20000;

    public static function puedeLeer(): bool
    {
        return class_exists(\ZipArchive::class) && class_exists(\DOMDocument::class);
    }

    public static function motivoNoLeible(): string
    {
        if (!class_exists(\ZipArchive::class)) {
            return 'Este servidor no puede importar archivos .xlsx porque falta la extensión '
                . 'PHP «zip» (clase ZipArchive).';
        }

        return 'Este servidor no puede importar archivos .xlsx porque falta la extensión '
            . 'PHP «xml» (clases DOMDocument y SimpleXML).';
    }

    public static function leer(string $ruta): array
    {
        if (!self::puedeLeer()) {
            throw new \RuntimeException('roster_sin_zip');
        }
        if (!is_file($ruta) || !is_readable($ruta)) {
            throw new \RuntimeException('roster_no_legible');
        }

        $zip = new \ZipArchive();
        if ($zip->open($ruta) !== true) {
            throw new \RuntimeException('roster_no_zip');
        }

        try {
            self::guardarTamano($zip);

            $rutaHoja = self::primeraHoja($zip);
            $xmlHoja = $zip->getFromName($rutaHoja);
            if ($xmlHoja === false) {
                throw new \RuntimeException('roster_sin_hoja');
            }

            $sharedStrings = self::leerSharedStrings($zip);
            $filasRaw = self::parsearHoja($xmlHoja, $sharedStrings);

            $cabecera = null;
            $cabeceraIndice = null;
            foreach ($filasRaw as $indice => $fila) {
                if (!self::filaTieneContenido($fila)) {
                    continue;
                }
                $cabecera = $fila;
                $cabeceraIndice = $indice;
                break;
            }

            if ($cabecera === null) {
                throw new \RuntimeException($filasRaw === [] ? 'roster_vacia' : 'roster_sin_cabecera');
            }

            $columnas = [];
            $cabeceras = [];
            foreach ($cabecera as $i => $valor) {
                $valor = trim((string) $valor);
                if ($valor === '') {
                    continue;
                }
                $columnas[] = $i;
                $cabeceras[] = $valor;
            }

            if ($cabeceras === []) {
                throw new \RuntimeException('roster_sin_cabecera');
            }

            $filas = [];
            foreach ($filasRaw as $indice => $fila) {
                if ($cabeceraIndice !== null && $indice <= $cabeceraIndice) {
                    continue;
                }
                if (count($filas) >= self::MAX_FILAS) {
                    break;
                }
                $proyectada = [];
                $conContenido = false;
                foreach ($columnas as $indice) {
                    $valor = (string) ($fila[$indice] ?? '');
                    if (trim($valor) !== '') {
                        $conContenido = true;
                    }
                    $proyectada[] = $valor;
                }
                if ($conContenido) {
                    $filas[] = $proyectada;
                }
            }

            return ['cabeceras' => $cabeceras, 'filas' => $filas];
        } finally {
            $zip->close();
        }
    }

    private static function guardarTamano(\ZipArchive $zip): void
    {
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) {
                continue;
            }
            $size = (int) ($stat['size'] ?? 0);
            if ($size > self::MAX_PARTE_BYTES) {
                throw new \RuntimeException('roster_demasiado_grande');
            }
            $total += $size;
            if ($total > self::MAX_TOTAL_BYTES) {
                throw new \RuntimeException('roster_demasiado_grande');
            }
        }
    }

    private static function primeraHoja(\ZipArchive $zip): string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        if ($workbook === false) {
            throw new \RuntimeException('roster_sin_hoja');
        }

        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($relsXml === false) {
            throw new \RuntimeException('roster_sin_hoja');
        }

        libxml_use_internal_errors(true);
        $workbookDoc = new \DOMDocument();
        if (!$workbookDoc->loadXML($workbook)) {
            throw new \RuntimeException('roster_sin_hoja');
        }
        $relsDoc = new \DOMDocument();
        if (!$relsDoc->loadXML($relsXml)) {
            throw new \RuntimeException('roster_sin_hoja');
        }
        libxml_clear_errors();

        $relaciones = [];
        foreach ($relsDoc->getElementsByTagName('Relationship') as $rel) {
            $id = $rel->getAttribute('Id');
            $target = $rel->getAttribute('Target');
            if ($id !== '') {
                $relaciones[$id] = $target;
            }
        }

        $sheets = $workbookDoc->getElementsByTagName('sheet');
        if ($sheets->length === 0) {
            throw new \RuntimeException('roster_sin_hoja');
        }
        $sheet = $sheets->item(0);
        if (!$sheet instanceof \DOMElement) {
            throw new \RuntimeException('roster_sin_hoja');
        }
        $rid = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
        if ($rid === '') {
            $rid = $sheet->getAttribute('r:id');
        }
        if ($rid === '' || !isset($relaciones[$rid])) {
            throw new \RuntimeException('roster_sin_hoja');
        }

        $target = $relaciones[$rid];
        if (strpos($target, '/xl/') === 0) {
            $ruta = ltrim($target, '/');
        } elseif (strpos($target, 'xl/') === 0) {
            $ruta = $target;
        } else {
            $ruta = 'xl/' . ltrim($target, '/');
        }

        if ($zip->locateName($ruta) === false && $zip->locateName(ltrim($ruta, '/')) === false) {
            throw new \RuntimeException('roster_sin_hoja');
        }

        return $ruta;
    }

    private static function leerSharedStrings(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        if (!$doc->loadXML($xml)) {
            libxml_clear_errors();
            return [];
        }
        libxml_clear_errors();

        $strings = [];
        foreach ($doc->getElementsByTagName('si') as $si) {
            $texto = '';
            foreach ($si->getElementsByTagName('t') as $t) {
                $texto .= $t->nodeValue ?? '';
            }
            $strings[] = $texto;
        }

        return $strings;
    }

    private static function parsearHoja(string $xml, array $sharedStrings): array
    {
        if (stripos($xml, '<!DOCTYPE') !== false) {
            return [];
        }

        libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        if (!$doc->loadXML($xml)) {
            libxml_clear_errors();
            throw new \RuntimeException('roster_sin_hoja');
        }
        libxml_clear_errors();

        $sheetData = $doc->getElementsByTagName('sheetData')->item(0);
        if (!$sheetData instanceof \DOMElement) {
            throw new \RuntimeException('roster_sin_hoja');
        }

        $filas = [];
        foreach ($sheetData->getElementsByTagName('row') as $row) {
            $celdasPorRef = [];
            foreach ($row->getElementsByTagName('c') as $c) {
                if (!$c instanceof \DOMElement) {
                    continue;
                }
                $ref = $c->getAttribute('r');
                $tipo = $c->getAttribute('t');
                $valor = self::valorCelda($c, $tipo, $sharedStrings);
                if ($ref !== '') {
                    $celdasPorRef[$ref] = $valor;
                }
            }
            if ($celdasPorRef === []) {
                continue;
            }
            $filas[] = self::alinearFila($celdasPorRef);
        }

        return $filas;
    }

    private static function valorCelda(\DOMElement $c, string $tipo, array $sharedStrings): string
    {
        if ($tipo === 's') {
            $v = $c->getElementsByTagName('v')->item(0);
            if (!$v instanceof \DOMElement) {
                return '';
            }
            $idx = (int) ($v->nodeValue ?? '-1');
            return isset($sharedStrings[$idx]) ? (string) $sharedStrings[$idx] : '';
        }
        if ($tipo === 'inlineStr') {
            $is = $c->getElementsByTagName('is')->item(0);
            if (!$is instanceof \DOMElement) {
                return '';
            }
            $texto = '';
            foreach ($is->getElementsByTagName('t') as $t) {
                $texto .= $t->nodeValue ?? '';
            }
            return $texto;
        }
        if ($tipo === 'str') {
            $v = $c->getElementsByTagName('v')->item(0);
            if (!$v instanceof \DOMElement) {
                return '';
            }
            return (string) ($v->nodeValue ?? '');
        }
        if ($tipo === 'b') {
            $v = $c->getElementsByTagName('v')->item(0);
            if (!$v instanceof \DOMElement) {
                return '';
            }
            return ((string) ($v->nodeValue ?? '0')) === '1' ? 'Sí' : '';
        }
        if ($tipo === 'e') {
            return '';
        }

        $v = $c->getElementsByTagName('v')->item(0);
        if (!$v instanceof \DOMElement) {
            return '';
        }
        return (string) ($v->nodeValue ?? '');
    }

    private static function alinearFila(array $celdasPorRef): array
    {
        $columnas = [];
        foreach (array_keys($celdasPorRef) as $ref) {
            if (preg_match('/^([A-Z]+)(\d+)$/', $ref, $m)) {
                $col = self::columnaAIndice($m[1]);
                $columnas[$col] = $celdasPorRef[$ref];
            }
        }
        if ($columnas === []) {
            return [];
        }
        $max = max(array_keys($columnas));
        $fila = [];
        for ($i = 0; $i <= $max; $i++) {
            $fila[] = isset($columnas[$i]) ? (string) $columnas[$i] : '';
        }
        return $fila;
    }

    private static function filaTieneContenido(array $fila): bool
    {
        foreach ($fila as $valor) {
            if (trim((string) $valor) !== '') {
                return true;
            }
        }

        return false;
    }

    private static function columnaAIndice(string $col): int
    {
        $col = strtoupper($col);
        $len = strlen($col);
        $idx = 0;
        for ($i = 0; $i < $len; $i++) {
            $idx = $idx * 26 + (ord($col[$i]) - ord('A') + 1);
        }
        return $idx - 1;
    }

    private static function indiceAColumna(int $idx): string
    {
        $idx = $idx + 1;
        $col = '';
        while ($idx > 0) {
            $mod = ($idx - 1) % 26;
            $col = chr(65 + $mod) . $col;
            $idx = intdiv($idx - 1, 26);
        }
        return $col;
    }

    private static function escribir(string $nombreHoja, array $cabeceras, array $filas): string
    {
        if (!self::puedeEscribir()) {
            throw new \RuntimeException('zip_no_disponible');
        }

        $tmp = sys_get_temp_dir() . '/vf-roster-' . bin2hex(random_bytes(8)) . '.xlsx';
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('zip_no_creable');
        }

        $zip->addFromString('[Content_Types].xml', self::contentTypes());
        $zip->addFromString('_rels/.rels', self::rels());
        $zip->addFromString('xl/workbook.xml', self::workbook($nombreHoja));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRels());
        $zip->addFromString('xl/worksheets/sheet1.xml', self::hoja($cabeceras, $filas));

        $zip->close();

        $binario = @file_get_contents($tmp);
        @unlink($tmp);
        if (!is_string($binario)) {
            throw new \RuntimeException('zip_vacio');
        }

        return $binario;
    }

    private static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>';
    }

    private static function rels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function workbook(string $nombreHoja): string
    {
        $nombre = str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', $nombreHoja);
        $nombre = preg_replace('/[\x00-\x1F\x7F]/', '', $nombre) ?? $nombre;
        $nombre = trim(preg_replace('/\s+/', ' ', $nombre) ?? $nombre);
        if ($nombre === '') {
            $nombre = 'Participantes';
        }
        $nombre = mb_substr($nombre, 0, 31, 'UTF-8');
        $nombre = self::escXml($nombre);
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . $nombre . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private static function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '</Relationships>';
    }

    private static function hoja(array $cabeceras, array $filas): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        $xml .= self::filaXml(1, $cabeceras);

        $r = 2;
        foreach ($filas as $fila) {
            $xml .= self::filaXml($r, $fila);
            $r++;
        }

        $xml .= '</sheetData></worksheet>';

        return $xml;
    }

    private static function filaXml(int $numFila, array $valores): string
    {
        $xml = '<row r="' . $numFila . '">';
        $c = 0;
        foreach ($valores as $valor) {
            $valor = (string) $valor;
            if ($valor !== '') {
                $ref = self::indiceAColumna($c) . $numFila;
                $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . self::escXml($valor) . '</t></is></c>';
            }
            $c++;
        }
        $xml .= '</row>';

        return $xml;
    }

    private static function escXml(string $s): string
    {
        $s = str_replace("\r", '', $s);
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s) ?? $s;
        return htmlspecialchars($s, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}
