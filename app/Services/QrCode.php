<?php

declare(strict_types=1);

namespace App\Services;

/**
 * QrCode
 *
 * Generación de códigos QR como SVG sin dependencias externas (P7).
 *
 * Usa la librería single-file vendored `libs/qrcode/qrcode.php`
 * (qrcode-generator de Kazuhiko Arase, MIT) solo para generar la MATRIZ de
 * módulos (no toca GD); el SVG se renderiza aquí desde isDark()/getModuleCount().
 */
final class QrCode
{
    private static bool $libLoaded = false;

    /**
     * Devuelve un QR en SVG (string).
     *
     * @param string $data   Texto a codificar (URL del formulario)
     * @param int    $pixels Tamaño en píxeles de salida (por módulo)
     * @param int    $margin Módulos de margen silencioso
     */
    public static function svg(string $data, int $pixels = 5, int $margin = 2): string
    {
        if ($data === '') {
            throw new \InvalidArgumentException('QrCode: el dato a codificar no puede estar vacío.');
        }

        self::loadLib();

        $qr = \QRCode::getMinimumQRCode($data, QR_ERROR_CORRECT_LEVEL_M);
        $count = $qr->getModuleCount();

        $size = $count * $pixels + ($margin * 2);
        $dark = array_fill(0, $count, []);

        for ($row = 0; $row < $count; $row++) {
            for ($col = 0; $col < $count; $col++) {
                if ($qr->isDark($row, $col)) {
                    $dark[$row][] = $col;
                }
            }
        }

        $rects = [];
        foreach ($dark as $row => $cols) {
            if ($cols === []) {
                continue;
            }
            // Comprimir módulos contiguos en la fila (rectangulares de 1 módulo).
            $start = $cols[0];
            $prev = $start;
            foreach (array_slice($cols, 1) as $col) {
                if ($col !== $prev + 1) {
                    $rects[] = self::rect($row, $start, $prev, $count, $margin, $pixels);
                    $start = $col;
                }
                $prev = $col;
            }
            $rects[] = self::rect($row, $start, $prev, $count, $margin, $pixels);
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size
            . '" viewBox="0 0 ' . $size . ' ' . $size . '" shape-rendering="crispEdges">'
            . '<rect width="' . $size . '" height="' . $size . '" fill="#fff"/>'
            . implode('', $rects)
            . '</svg>';
    }

    private static function rect(int $row, int $colStart, int $colEnd, int $count, int $margin, int $pixels): string
    {
        $x = ($colStart + $margin) * $pixels;
        $y = ($row + $margin) * $pixels;
        $w = ($colEnd - $colStart + 1) * $pixels;
        return '<rect x="' . $x . '" y="' . $y . '" width="' . $w . '" height="' . $pixels . '" fill="#000"/>';
    }

    private static function loadLib(): void
    {
        if (self::$libLoaded) {
            return;
        }
        $file = dirname(__DIR__, 2) . '/libs/qrcode/qrcode.php';
        if (!is_file($file)) {
            throw new \RuntimeException('QrCode: no se encontró libs/qrcode/qrcode.php.');
        }
        require_once $file;
        self::$libLoaded = true;
    }
}