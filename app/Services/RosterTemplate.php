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

    // =====================================================================
    // Lectura (fase 3)
    // =====================================================================

    /**
     * Techo de bytes descomprimidos por parte XML y por archivo entero.
     *
     * Un `.xlsx` de 2 MB que se sube al servidor puede declarar un `sheet.xml` de
     * 6 GB: es una «bomba», porque no hace falta construirla para que
     * `getFromName()` lo infle entero en memoria. El límite se mira en el
     * **`statName()` antes de descomprimir**, no después, porque para entonces el
     * daño ya está hecho.
     */
    private const MAX_PARTE_BYTES = 16 * 1024 * 1024;
    private const MAX_TOTAL_BYTES = 64 * 1024 * 1024;

    /** Filas de datos que se leen como máximo. */
    private const MAX_FILAS = 20000;

    /**
     * ¿Este servidor puede LEER un `.xlsx`?
     *
     * Es distinto de `puedeEscribir()` y no es un detalle: leer necesita además
     * parsear XML, que en un servidor donde falte `php-xml` no falla con una
     * excepción capturable sino con un error fatal, igual que `ZipArchive`. La
     * plantilla se puede generar y la importación no, y son dos consultas
     * distintas.
     */
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

    /**
     * Lee un `.xlsx` y devuelve cabeceras y filas.
     *
     * Lo que devuelve son las cabeceras TAL COMO están escritas y las filas
     * POSICIONALES, sin interpretar nada: decidir si «Cédula» es la cédula o un
     * atributo es trabajo de quien importa (`ParticipantImporter`), porque esa
     * decisión depende del identificador que tenga elegido el formulario. Aquí solo
     * se lee el archivo.
     *
     * Escribes sobre lo que hacen Excel y LibreOffice, no sobre lo que escribe
     * `escribir()`:
     *
     *  - **La hoja no es siempre `sheet1.xml`.** Se resuelve por `workbook.xml` y
     *    su `.rels`, porque quien reordera, borra o duplica hojas deja el nombre
     *    del archivo sin relación con el orden visible, y el orden es lo único que
     *    la persona ve. La primera hoja del `workbook` es la que se importa.
     *  - **Hay dos formas de string.** `t="s"` (índice a `sharedStrings.xml`) y
     *    `t="inlineStr"` (el texto dentro de la celda). Un `<si>` puede venir
     *    partido en varios `<t>` por temas de formato, así que se concatenan.
     *  - **Un número es un string igual.** Un `12345` sin `t` llega como `12345` y
     *    se queda como texto: si el usuario ya lo guardó como número, los ceros que
     *    los ceros que se perdieron antes de subir el archivo, y «arreglarlo» aquí inventaría
     *    datos en vez de leerlos.
     *  - **Las celdas vacías no se escriben.** Se respeta la referencia `r` para
     *    dejar el hueco en su columna, que es justo lo que rompe una importación si
     *    se cuenta por posición sin mirar el `r`.
     *
     * @return array{cabeceras: list<string>, filas: list<list<string>>}
     *
     * @throws \RuntimeException con una clave legible en el mensaje
     */
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
            if ($rutaHoja === null) {
                throw new \RuntimeException('roster_sin_hoja');
            }

            $hoja = self::leerParte($zip, $rutaHoja);
            if ($hoja === null) {
                throw new \RuntimeException('roster_sin_hoja');
            }

            $compartidas = self::stringsCompartidos($zip);
            $crudas      = self::parsearHoja($hoja, $compartidas);

            if ($crudas === []) {
                throw new \RuntimeException('roster_vacia');
            }

            // La cabecera es la PRIMERA fila con algo en ella. Un archivo cuyo
            // primer `row` está vacío (Excel deja el hueco si alguien borró las
            // cabeceras y volvió a escribir más abajo) no es un archivo sin
            // cabecera: es un archivo cuya cabecera hay que buscar.
            $cabeceras = array_shift($crudas);

            $posiciones = [];
            $nombres    = [];
            foreach ($cabeceras as $i => $texto) {
                $texto = trim($texto);
                if ($texto === '') {
                    continue; // columna sin nombre: no es un atributo, se descarta
                }
                $posiciones[] = $i;
                $nombres[]    = $texto;
            }
            if ($nombres === []) {
                throw new \RuntimeException('roster_sin_cabecera');
            }

            $filas = [];
            foreach ($crudas as $fila) {
                if (count($filas) >= self::MAX_FILAS) {
                    break;
                }
                $salida = [];
                foreach ($posiciones as $i) {
                    $salida[] = $fila[$i] ?? '';
                }
                // Fila del todo vacía: Excel rellena huecos y no son participantes.
                if (trim(implode('', $salida)) === '') {
                    continue;
                }
                $filas[] = $salida;
            }

            return ['cabeceras' => $nombres, 'filas' => $filas];
        } finally {
            $zip->close();
        }
    }

    /**
     * Suma los tamaños declarados y corta antes de descomprimir nada.
     *
     * `ZipArchive::statIndex()` lee la cabecera central sin inflar, así que este
     * es el punto donde una ZIP bomba se puede cortar sin haber ocupado memoria.
     */
    private static function guardarTamano(\ZipArchive $zip): void
    {
        $total = 0;
        for ($i = 0, $n = $zip->numFiles; $i < $n; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }
            $tam = (int) $stat['size'];
            if ($tam > self::MAX_PARTE_BYTES) {
                throw new \RuntimeException('roster_demasiado_grande');
            }
            $total += $tam;
            if ($total > self::MAX_TOTAL_BYTES) {
                throw new \RuntimeException('roster_demasiado_grande');
            }
        }
    }

    /**
     * Ruta interna de la primera hoja visible, resuelta por sus relaciones.
     *
     * El `Target` de una relación puede venir como `worksheets/sheet1.xml` o como
     * `/xl/worksheets/sheet1.xml` según quién escribió el archivo, de ahí el
     * remate en dos pasos: quitar la barra inicial si la hay y, si no, no
     * prefijar `xl/`.
     */
    private static function primeraHoja(\ZipArchive $zip): ?string
    {
        $workbook = self::leerParte($zip, 'xl/workbook.xml');
        if ($workbook === null) {
            return null;
        }
        $rels = self::leerParte($zip, 'xl/_rels/workbook.xml.rels');
        if ($rels === null) {
            return null;
        }

        $doc = self::xml($workbook);
        if ($doc === null) {
            return null;
        }
        $hojas = $doc->getElementsByTagName('sheet');
        if ($hojas->length === 0) {
            return null;
        }
        $idRel = $hojas->item(0)->getAttribute('r:id');
        if ($idRel === '') {
            $idRel = $hojas->item(0)->getAttribute('id');
        }
        if ($idRel === '') {
            return null;
        }

        $docRels = self::xml($rels);
        if ($docRels === null) {
            return null;
        }
        foreach ($docRels->getElementsByTagName('Relationship') as $rel) {
            if ($rel->getAttribute('Id') !== $idRel) {
                continue;
            }
            $target = $rel->getAttribute('Target');
            if ($target === '') {
                return null;
            }
            if (str_starts_with($target, '/')) {
                return substr($target, 1);
            }
            if (!str_starts_with($target, 'xl/')) {
                $target = 'xl/' . $target;
            }

            return $target;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function stringsCompartidos(\ZipArchive $zip): array
    {
        $xml = self::leerParte($zip, 'xl/sharedStrings.xml');
        if ($xml === null) {
            return [];
        }
        $doc = self::xml($xml);
        if ($doc === null) {
            return [];
        }

        $out = [];
        foreach ($doc->getElementsByTagName('si') as $si) {
            // Un `<si>` partido en varios `<r><t>` por formato: se concatena todo
            // el texto descendiente, no solo el primer `<t>`.
            $out[] = self::textoDe($si);
        }

        return $out;
    }

    /**
     * @param list<string> $compartidas
     *
     * @return list<list<string>>
     */
    private static function parsearHoja(string $xml, array $compartidas): array
    {
        $doc = self::xml($xml);
        if ($doc === null) {
            return [];
        }

        $filas = [];
        foreach ($doc->getElementsByTagName('row') as $nodoFila) {
            $celdas = [];
            // `$siguiente` solo se usa cuando la celda NO trae referencia `r`, que
            // es lo que escribe un productor mínimo; Excel siempre la pone.
            $siguiente = 0;

            foreach ($nodoFila->getElementsByTagName('c') as $celda) {
                $ref = $celda->getAttribute('r');
                $col = self::indiceColumna($ref);
                if ($col === null) {
                    $col = $siguiente;
                }
                $siguiente = $col + 1;

                $celdas[$col] = self::valorCelda($celda, $compartidas);
            }

            // Se rellena hasta la columna MÁS ALTA, no hasta cuántas hay: con
            // celdas en A, B y F hay tres celdas pero la fila tiene seis columnas,
            // y cortar por `count()` corría el resto de la fila hacia la izquierda.
            $maximo = $celdas === [] ? -1 : max(array_keys($celdas));
            $linea  = [];
            for ($i = 0; $i <= $maximo; $i++) {
                $linea[] = $celdas[$i] ?? '';
            }
            // Sin celdas la fila es un `<row/>` vacío: se descarta.
            if ($celdas !== []) {
                $filas[] = $linea;
            }
        }

        return $filas;
    }

    /**
     * @param list<string> $compartidas
     */
    private static function valorCelda(\DOMElement $celda, array $compartidas): string
    {
        $tipo = $celda->getAttribute('t');

        if ($tipo === 'inlineStr') {
            foreach ($celda->getElementsByTagName('is') as $is) {
                return self::textoDe($is);
            }

            return '';
        }

        $v = '';
        foreach ($celda->getElementsByTagName('v') as $nodo) {
            $v = trim($nodo->textContent);
            break;
        }

        return match ($tipo) {
            // Índice en la tabla de strings compartidos.
            's'     => $compartidas[(int) $v] ?? '',
            // Booleo: se lee como texto porque un «Sí/No» es lo que espera quien
            // rellena la plantilla, y es reversible.
            'b'     => $v === '1' ? 'Sí' : ($v === '0' ? 'No' : ''),
            // `#REF!` y compañía no son un dato: se leen como vacío, no como error.
            'e'     => '',
            // `str` (resultado de fórmula), `d` (fecha ISO) y número sin `t`.
            default => $v,
        };
    }

    /**
     * Texto de un nodo y de todos sus descendientes `<t>`.
     *
     * Se concatena TODO el texto, no solo el `<t>` del primer nivel, porque un
     * `<si>` con runs deja el valor repartido entre varios `<t>` y quedarse con el
     * primero devolvería media palabra.
     */
    private static function textoDe(\DOMNode $nodo): string
    {
        $out = '';
        foreach ($nodo->getElementsByTagName('t') as $t) {
            $out .= $t->textContent;
        }
        if ($out === '') {
            $out = $nodo->textContent;
        }

        return trim($out);
    }

    /**
     * `B12` -> 1. Devuelve `null` si la referencia no trae letras.
     */
    private static function indiceColumna(string $ref): ?int
    {
        if (preg_match('/^([A-Z]+)/', strtoupper($ref), $m) !== 1) {
            return null;
        }

        $n = 0;
        $letras = $m[1];
        for ($i = 0, $l = strlen($letras); $i < $l; $i++) {
            $n = $n * 26 + (ord($letras[$i]) - 64);
        }

        return max(0, $n - 1);
    }

    /**
     * Parsea XML sin entidades externas ni `DOCTYPE`.
     *
     * El archivo lo sube el dueño del formulario, pero «el dueño no es un
     * atacante» no es un supuesto: el ZIP se pide para ser procesado en memoria y
     * un `sharedStrings.xml` con
     * `<!DOCTYPE s [<!ENTITY e SYSTEM "file:///etc/passwd">]>` se lleva el
     * `DOCTYPE` por delante. `LIBXML_NONET` quita la red y el rechazo explícito
     * quita el resto.
     */
    private static function xml(string $contenido): ?\DOMDocument
    {
        if (stripos($contenido, '<!DOCTYPE') !== false || stripos($contenido, '<!ENTITY') !== false) {
            return null;
        }

        $prev = libxml_use_internal_errors(true);
        try {
            $doc = new \DOMDocument();
            // Sin `LIBXML_NOENT`: las entidades NO se sustituyen, que es
            // precisamente lo que queremos.
            if ($doc->loadXML($contenido, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING) === false) {
                return null;
            }

            return $doc;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    private static function leerParte(\ZipArchive $zip, string $nombre): ?string
    {
        $stat = $zip->statName($nombre);
        if ($stat === false) {
            return null;
        }
        if ((int) $stat['size'] > self::MAX_PARTE_BYTES) {
            throw new \RuntimeException('roster_demasiado_grande');
        }

        $contenido = $zip->getFromName($nombre);

        return $contenido === false ? null : $contenido;
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