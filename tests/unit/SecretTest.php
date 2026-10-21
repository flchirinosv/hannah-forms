<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Secret;

/**
 * Cifrado de secretos (`App\Core\Secret`).
 *
 * Es la pieza que permite guardar la contraseña SMTP en la BD sin dejarla en
 * claro: quien robe SOLO la base de datos no saca nada porque la llave
 * (`APP_KEY`) vive en `.env`. Aquí se fija el contrato en las dos direcciones:
 * con llave, un ida y vuelta devuelve el texto; sin llave o con otra llave, el
 * descifrado da `null` en vez de lanzar (que es lo que permite caer al default
 * sin un 500 en la pantalla de ajustes).
 *
 * No toca la BD ni la config real: usa `setKeyForTesting()`, el único seam, y lo
 * limpia en el `tearDown`.
 */
class SecretTest
{
    public function tearDown(): void
    {
        Secret::setKeyForTesting(null);
    }

    public function testIdaYVueltaConLaMismaLlave(): void
    {
        Secret::setKeyForTesting(Secret::generateKey());

        $texto = 'una-contraseña-smtp-con-acentos-áéí ó$%&';
        $cifrado = Secret::encrypt($texto);

        assertNotNull($cifrado, 'con llave tiene que poder cifrar');
        assertFalse(str_contains($cifrado, 'contraseña'), 'el criptograma no puede llevar el texto en claro');
        assertSame($texto, Secret::decrypt($cifrado), 'y tiene que volver idéntico');
    }

    /**
     * El IV es aleatorio, así que el mismo texto cifrado dos veces da dos
     * criptogramas distintos. Si coincidieran, el IV sería fijo y el cifrado
     * filtraría «estos dos secretos son iguales».
     */
    public function testDosCifradosDelMismoTextoNoCoinciden(): void
    {
        Secret::setKeyForTesting(Secret::generateKey());

        assertTrue(
            Secret::encrypt('igual') !== Secret::encrypt('igual'),
            'el IV aleatorio tiene que dar criptogramas distintos para el mismo texto'
        );
    }

    public function testConOtraLlaveNoSeDescifraYDevuelveNull(): void
    {
        Secret::setKeyForTesting(Secret::generateKey());
        $cifrado = Secret::encrypt('secreto');
        assertNotNull($cifrado);

        // Cambiar la llave (como si el operador hubiera regenerado APP_KEY)
        // tiene que hacer que el valor guardado deje de ser utilizable, sin
        // lanzar: es el escenario de «restauraste la BD pero no el .env».
        Secret::setKeyForTesting(Secret::generateKey());
        assertNull(Secret::decrypt((string) $cifrado));
    }

    public function testGCMDetectaLaManipulacionDelCriptograma(): void
    {
        Secret::setKeyForTesting(Secret::generateKey());
        $cifrado = (string) Secret::encrypt('secreto');

        // Voltear un byte del contenido (GCM autentica): tiene que fallar.
        $raw = base64_decode($cifrado, true);
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 0x01);
        assertNull(Secret::decrypt(base64_encode((string) $raw)));
    }

    public function testSinLlaveElCifradoEstaInactivo(): void
    {
        Secret::setKeyForTesting(null);

        assertSame(false, Secret::isAvailable(), 'sin APP_KEY no hay cifrado');
        assertNull(Secret::encrypt('lo-que-sea'), 'y cifrar devuelve null, no un texto en claro');
        assertNull(Secret::decrypt('lo-que-sea'), 'ni descifrar se inventa nada');
    }

    /**
     * `decrypt()` no puede lanzar con basura: el valor de la BD es `TEXT` y
     * cualquiera puede escribir en él. Una cadena vacía, un base64 que no lo es
     * o un criptograma demasiado corto tienen que dar `null`.
     */
    public function testBasuraNoLanzaYDevuelveNull(): void
    {
        Secret::setKeyForTesting(Secret::generateKey());

        assertNull(Secret::decrypt(''));
        assertNull(Secret::decrypt('no-es-base64-@@@'));
        assertNull(Secret::decrypt(base64_encode('corto')));
        assertFalse(Secret::canDecrypt(''));
        assertFalse(Secret::canDecrypt(null));
    }

    public function testCanDecryptDistingueLoUtilizable(): void
    {
        Secret::setKeyForTesting(Secret::generateKey());
        $cifrado = (string) Secret::encrypt('algo');
        assertTrue(Secret::canDecrypt($cifrado), 'un criptograma propio se puede descifrar');
        assertFalse(Secret::canDecrypt('texto-en-claro-que-nunca-se-cifro'));
    }

    /**
     * El formato de la llave que escribe `generate-key.php` (base64 de 32 bytes)
     * es el que `Secret` acepta. También una cadena de exactamente 32 bytes.
     */
    public function testLaLlaveGeneradaEsBase64DeTreintaYDosBytes(): void
    {
        $decoded = base64_decode(Secret::generateKey(), true);
        assertSame(true, $decoded !== false, 'la llave generada tiene que ser base64 válido');
        assertSame(32, strlen((string) $decoded), 'y decodificar exactamente 32 bytes');

        // Una llave larga mal pegada (no 32 bytes) se rechaza en vez de usarse a
        // medias: cifrar con una llave corta «funcionaría» y sería más débil.
        Secret::setKeyForTesting('demasiado-corta');
        assertFalse(Secret::isAvailable());
    }
}
