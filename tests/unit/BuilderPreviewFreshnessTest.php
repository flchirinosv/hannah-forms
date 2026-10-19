<?php

declare(strict_types=1);

namespace Tests\Unit;

/**
 * La vista previa del constructor tiene que ver los CAMBIOS TEMPORALES.
 *
 * Regresión reportada por el dueño: los cambios hechos en el builder no se
 * reflejaban en la vista previa hasta recargar el BUILDER, y recargar la
 * propia vista previa tampoco los traía.
 *
 * La causa tenía dos mitades y ninguna daba error:
 *
 *  1. `openLivePreview()` solo añadía `?draft=1` si el HTML traía
 *     `data-vf-has-draft="1"` —dato que el servidor calcula AL CARGAR—, así
 *     que un borrador que el autosave creaba después quedaba fuera de la URL;
 *     la pestaña leía la estructura commiteada y, al recargarla, seguía sin
 *     la query (una URL no se cambia sola).
 *  2. Aun con la query, el autosave tiene un debounce de 2 s: pulsar «Vista
 *     previa» casi al terminar de escribir volcaba un borrador viejo.
 *
 * Por eso este test ata tres piezas que tienen que moverse juntas: la query
 * SIEMPRE presente (F5 relee el borrador), el vaciado antes/después de abrir
 * (`vaciarBorrador()` recarga la pestaña abierta) y la marca `lastDraftJson`
 * que dice qué tiene ya el servidor. Es lectura de estructura del JS, como en
 * `BuilderSaveButtonTest`: `fetch` y el DOM no son inyectables aquí.
 */
class BuilderPreviewFreshnessTest
{
    private const JS = 'public/assets/js/builder.js';

    public function testLaVistaPreviaAbreSiempreConElBorrador(): void
    {
        $abrir = $this->cuerpoDe('openLivePreview');

        assertTrue(
            str_contains($abrir, "'/forms/' + FORM_ID + '/preview?draft=1'"),
            'la vista previa se abre con ?draft=1 SIEMPRE: sin la query la pestaña '
                . 'lee la estructura commiteada y un F5 no relee el borrador'
        );

        assertTrue(
            !str_contains($abrir, 'vfHasDraft'),
            'la condición sobre data-vf-has-draft desapareció: el dato solo lo sabe '
                . 'el HTML al cargar, así que un borrador creado después por el '
                . 'autosave no se reflejaba hasta recargar el builder'
        );

        assertTrue(
            str_contains($abrir, 'vaciarBorrador(win)'),
            'al abrir se vacían los cambios pendientes contra la pestaña nueva'
        );
    }

    public function testElVaciadoVuelcaLoPendienteYRecargaLaPestana(): void
    {
        $vaciar = $this->cuerpoDe('vaciarBorrador');

        assertTrue(
            str_contains($vaciar, 'clearTimeout(autoSaveTimer)'),
            'el vaciado cancela el debounce: lo pendiente se escribe YA, no dentro '
                . 'de 2 segundos'
        );
        assertTrue(
            str_contains($vaciar, 'await draftPromise'),
            'y espera una escritura en vuelo antes de decidir si hace falta otra'
        );
        assertTrue(
            str_contains($vaciar, 'JSON.stringify(buildPayload()) !== lastDraftJson'),
            'compara contra lo que el servidor tiene (lastDraftJson), no contra '
                . '`dirty`, que sigue true tras un autoguardado exitoso'
        );
        assertTrue(
            str_contains($vaciar, 'await saveDraft()'),
            'vacía con el mismo saveDraft() del autosave (un solo camino de escritura)'
        );
        assertTrue(
            str_contains($vaciar, 'win.location.reload()'),
            'y recarga la pestaña abierta, o lo que se ve al abrir sería lo de hace '
                . 'un instante y no lo actual'
        );
    }

    public function testLaMarcaDelBorradorSoloAvanzaConUnOkDeVerdad(): void
    {
        $guardar = $this->cuerpoDe('saveDraft');
        assertTrue(
            str_contains($guardar, 'if (data && data.ok)'),
            'saveDraft() mira el {ok:true}: un redirect a login o un 419/422 no '
                . 'dejan el borrador al día'
        );
        assertTrue(
            str_contains($guardar, 'lastDraftJson = payloadJson'),
            'solo entonces la marca avanza; un fallo la deja vieja y el próximo '
                . 'vaciado reintenta'
        );
        assertTrue(
            str_contains($guardar, 'return true') && str_contains($guardar, 'return false'),
            'saveDraft() devuelve si escribió: sin eso el vaciado recargaría la '
                . 'pestaña también cuando falló'
        );

        // El commit limpia el borrador del servidor, así que tras «Guardar» lo
        // que el lector va a encontrar es lo commiteado: la marca lo sigue.
        $commit = $this->cuerpoDe('saveStructure');
        assertTrue(
            str_contains($commit, 'lastDraftJson = payloadJson'),
            'un «Guardar» exitoso actualiza la marca: el borrador del servidor se '
                . 'acaba de limpiar y lo que hay que leer es este payload'
        );
    }

    public function testElArranqueYElCambioDeFocoDejanLaMarcaAlDia(): void
    {
        $src = $this->sinComentarios((string) file_get_contents(dirname(__DIR__, 2) . '/' . self::JS));

        assertTrue(
            str_contains($src, 'lastDraftJson = JSON.stringify(buildPayload())'),
            'al arrancar la marca parte de la memoria actual (copia del borrador o '
                . 'de la estructura viva): el primer vaciado no reescribe nada inútil'
        );

        assertTrue(
            str_contains($src, "document.addEventListener('visibilitychange'"),
            'el builder escucha visibilitychange'
        );
        assertTrue(
            str_contains($src, 'vaciarBorrador(null)'),
            'y al perder el foco vacía lo pendiente: quien se va a la pestaña de la '
                . 'vista previa a pulsar F5 necesita lo último YA en la BD, no dentro '
                . 'de 2 segundos'
        );
    }

    /* ---------- ayudas ---------- */

    /** Cuerpo de una función de builder.js, sin comentarios, hasta su cierre. */
    private function cuerpoDe(string $fn): string
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/' . self::JS);
        $ini = strpos($src, 'function ' . $fn . '(');
        assertTrue($ini !== false, 'no se encontró ' . $fn . '() en builder.js');
        $fin = strpos($src, "\n    }", (int) $ini);
        assertTrue($fin !== false, $fn . '() parece sin cerrar');

        return $this->sinComentarios(substr($src, (int) $ini, (int) $fin - (int) $ini));
    }

    /** Quita comentarios de bloque y de línea, sin tocar las cadenas. */
    private function sinComentarios(string $cuerpo): string
    {
        $cuerpo = preg_replace('#/\*.*?\*/#s', ' ', (string) $cuerpo);
        $lineas = array_map(
            static function (string $l): string {
                return preg_match('/^\s*(\/\/|\*)/', $l) ? '' : $l;
            },
            explode("\n", (string) $cuerpo)
        );

        return implode("\n", $lineas);
    }
}
