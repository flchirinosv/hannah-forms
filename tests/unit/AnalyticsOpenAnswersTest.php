<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\View;
use App\Services\FieldAggregator;

/**
 * W2 — La tarjeta de pregunta abierta de la analítica (`analytics/_open`).
 *
 * La rama existía como COMENTARIO en `analytics/show.php` y cinco tipos de
 * campo ni siquiera llegaban a ninguna rama del `if/elseif`, así que la
 * pregunta desaparecía de la pantalla sin que PHP, el navegador o el log
 * dijeran nada. Este test mira las dos mitades: que la tarjeta se pinta como
 * debe (valor escapado, enlace a la submission de verdad, estado vacío) y que
 * la lista de tipos que la alimenta sigue cubriendo a todos los que guardan
 * respuestas.
 *
 * Solo HTML: no toca la base de datos.
 */
class AnalyticsOpenAnswersTest
{
    private static function pinta(array $f, array $stat, array $form = ['id' => 7]): string
    {
        return (string) View::partial('analytics/_open', [
            'f'    => $f,
            'stat' => $stat,
            'form' => $form,
        ]);
    }

    /**
     * `abiertas` vacío (o ausente) es el estado de un formulario recién
     * publicado: la tarjeta tiene que seguir con su título y su contador, y
     * decir con palabras que no hay nada — no dejar un hueco con la lista
     * intermedia a medias.
     */
    public function testUnaPreguntaSinRespuestasDiceQueNoHayTodavia(): void
    {
        $html = self::pinta(
            ['id' => 9, 'label' => '¿Qué opinas?', 'type' => 'text'],
            ['non_empty' => 12, 'abiertas' => []]
        );

        assertTrue(str_contains($html, 'id="campo-9"'), 'la tarjeta se ancla al id del campo');
        assertTrue(str_contains($html, '¿Qué opinas?'), 'pinta la etiqueta');
        assertTrue(str_contains($html, '12 respuestas'), 'cuenta el total de no vacías, no solo las listadas');
        assertTrue(str_contains($html, 'Sin respuestas todavía.'), 'el estado vacío se dice con texto');
        assertFalse(str_contains($html, 'vf-open-answers'), 'sin respuestas no hay lista');
        assertFalse(str_contains($html, '<li'), 'ni un solo ítem fantasma');
    }

    /**
     * El enlace de cada fila es la ficha de ESA submission (`sid` de
     * `submissions.id`), que es el mismo drill-down que la tabla de detalle.
     * Armarlo con el índice de la fila sería el enlace equivocado en todo
     * salvo en la primera página, y nadie lo notaría: sale 200 y muestra otra
     * respuesta.
     */
    public function testCadaRespuestaAbreLaFichaDeSuSubmission(): void
    {
        $html = self::pinta(
            ['id' => 4, 'label' => 'Comentario', 'type' => 'text'],
            [
                'non_empty' => 3,
                'abiertas'  => [
                    ['value' => 'Primera', 'at' => '2026-10-01 10:00:00', 'sid' => 501],
                    ['value' => 'Segunda', 'at' => '2026-10-02 11:30:00', 'sid' => 502],
                    ['value' => 'Tercera', 'at' => '2026-10-03 09:15:00', 'sid' => 503],
                ],
            ]
        );

        assertTrue(str_contains($html, '/forms/7/responses/501'), 'la primera fila abre su submission');
        assertTrue(str_contains($html, '/forms/7/responses/502'), 'la segunda, la suya');
        assertTrue(str_contains($html, '/forms/7/responses/503'), 'la tercera, la suya');
        // El orden de la lista es el del agregado (más reciente primero) y se
        // conserva tal cual en el HTML.
        $p501 = strpos($html, '/responses/501');
        $p503 = strpos($html, '/responses/503');
        assertTrue($p501 !== false && $p503 !== false && $p501 < $p503, 'se respeta el orden más reciente primero');
        assertTrue(str_contains($html, '>Ver</a>'), 'la fila lleva su enlace Ver');
    }

    /**
     * El valor es texto del visitante y se pinta DOS veces: en el cuerpo y en
     * el `title` del recorte. Sin escapar, un comentario con `<script>` sería
     * HTML de verdad — la analítica es la pantalla que MÁS datos ajenos muestra
     * y además está detrás de una sesión.
     *
     * El `title` se escapa con `ENT_QUOTES`, así que una comilla en el valor no
     * rompe el atributo y no cierra la etiqueta a mitad de la frase.
     */
    public function testElValorDeTextoLibreSeEscapaEnElCuerpoYEnElTitulo(): void
    {
        $valor = '<script>alert("x")</script> y "comillas"';

        $html = self::pinta(
            ['id' => 4, 'label' => '<b>etiqueta</b>', 'type' => 'text'],
            [
                'non_empty' => 1,
                'abiertas'  => [
                    ['value' => $valor, 'at' => '2026-10-01 10:00:00', 'sid' => 501],
                ],
            ]
        );

        assertFalse(str_contains($html, '<script>'), 'el valor no se inyecta como HTML');
        assertTrue(str_contains($html, '&lt;script&gt;'), 'el valor sale escapado');
        assertFalse(str_contains($html, '<b>etiqueta</b>'), 'la etiqueta del campo también se escapa');
        // El atributo title queda cerrado y el valor no se sale de él.
        assertTrue(
            (bool) preg_match('/title="[^"]*"/u', $html),
            'el title se cierra aunque el valor lleve comillas'
        );
    }

    /**
     * Un adjunto guardado es JSON (`{"stored":…,"name":…}`): enseñar eso a mano
     * sería pedirle al dueño que lea JSON para saber qué archivo llegó. La
     * tarjeta lo traduce con el mismo `Uploader::displayValue()` del listado de
     * respuestas.
     */
    public function testElAdjuntoSeTraduceALecturaHumana(): void
    {
        $json = json_encode([
            'stored' => 'abc/cv.pdf',
            'name'   => 'cv.pdf',
            'size'   => 1258291,
        ], JSON_UNESCAPED_SLASHES);

        $html = self::pinta(
            ['id' => 11, 'label' => 'Adjunta tu CV', 'type' => 'file'],
            [
                'non_empty' => 1,
                'abiertas'  => [
                    ['value' => (string) $json, 'at' => '2026-10-01 10:00:00', 'sid' => 9],
                ],
            ]
        );

        assertTrue(str_contains($html, 'cv.pdf'), 'se ve el nombre del archivo');
        assertFalse(str_contains($html, 'stored'), 'no se ve el JSON crudo');
    }

    /**
     * La estructura de la rama dentro de `analytics/show.php`.
     *
     * Si alguien vuelve a comentar la rama, la cambia por otra lista de tipos
     * escrita a mano, o deja de pasarle el partial, esto se pone rojo: es la
     * comprobación de que la tarjeta está EN LA PANTALLA y no solo existe su
     * fichero.
     */
    public function testLaRamaDeLaAnaliticaUsaLaListaDelAgregador(): void
    {
        $fuente = (string) file_get_contents(dirname(__DIR__, 2) . '/views/analytics/show.php');

        assertTrue(
            str_contains($fuente, 'FieldAggregator::TIPOS_ABIERTOS'),
            'la cadena de ramas decide con la MISMA lista que usa el agregador'
        );
        assertTrue(
            str_contains($fuente, "View::partial('analytics/_open'"),
            'la tarjeta se pinta desde su partial, no como markup suelto sin probar'
        );
        assertFalse(
            str_contains($fuente, 'No gráfica para texto libre'),
            'el comentario que reemplaza a esta rama ya no está'
        );
    }

    /**
     * El contrato de `TIPOS_ABIERTOS`.
     *
     * Antes: `text`/`textarea`/`email`/`date` se descartaban a mano en
     * `show.php:150` con un comentario, y `url`/`tel`/`time`/`datetime-local`/
     * `file` NO caían en ninguna rama de la cadena — la pregunta no se pintaba
     * y no había error en ningún sitio. La lista es ahora la ÚNICA fuente de
     * esa decisión, y tiene que seguir cubriendo a los nueve.
     */
    public function testLaListaDeTiposAbiertosCubreALosNueve(): void
    {
        $esperados = [
            'text', 'textarea', 'email', 'url', 'tel', 'time', 'datetime-local', 'date', 'file',
        ];

        assertEquals(
            $esperados,
            FieldAggregator::TIPOS_ABIERTOS,
            'los nueve tipos con respuestas siguen en la lista (y solo ellos)'
        );

        // Los que NO la llevan son los que no pueden aparecer aquí: las
        // secciones y las páginas no guardan respuestas, y los demás tienen su
        // propia rama con agregado propio.
        foreach (['section', 'page'] as $tipo) {
            assertFalse(
                in_array($tipo, FieldAggregator::TIPOS_ABIERTOS, true),
                "$tipo no guarda respuestas y no debe llevar tarjeta de texto"
            );
        }
        foreach (['select', 'radio', 'checkbox', 'matrix', 'ranking', 'nps', 'number', 'scale', 'range', 'star'] as $tipo) {
            assertFalse(
                in_array($tipo, FieldAggregator::TIPOS_ABIERTOS, true),
                "$tipo ya tiene su propia rama con agregado propio"
            );
        }
    }

    /**
     * El techo de la lista es una CONSTANTE, y la tarjeta no lo pinta: la
     * suma «N respuestas» sale de `non_empty`, que es el número real. Si las
     * dos cosas se confundieran, una pregunta con 400 comentarios diría «5
     * respuestas».
     */
    public function testElTechoEsPorCampoYNuncaEsElConteoDeLaTarjeta(): void
    {
        $html = self::pinta(
            ['id' => 4, 'label' => 'Opinión', 'type' => 'text'],
            [
                'non_empty' => 400,
                'abiertas'  => array_map(
                    static fn(int $i): array => [
                        'value' => "Opinión $i",
                        'at'    => '2026-10-01 10:00:00',
                        'sid'   => 600 + $i,
                    ],
                    range(1, FieldAggregator::ABIERTAS_MAX)
                ),
            ]
        );

        assertTrue(str_contains($html, '400 respuestas'), 'el contador es el de las no vacías');
        assertEquals(
            FieldAggregator::ABIERTAS_MAX,
            substr_count($html, 'class="small text-decoration-none"'),
            'solo se listan las ABIERTAS_MAX más recientes'
        );
    }
}
