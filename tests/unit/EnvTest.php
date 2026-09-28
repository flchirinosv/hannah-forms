<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Env;

class EnvTest
{
    public function setUp(): void
    {
        // Restablecer estado por si se cargaron antes
        putenv('TEST_VAR');
        unset($_ENV['TEST_VAR']);
    }

    public function testCargaValoresBasicos(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($tmp, "FOO=bar\nBAZ=qux\n");
        $loaded = Env::load($tmp, true);
        unlink($tmp);

        assertSame('bar', $loaded['FOO']);
        assertSame('qux', $loaded['BAZ']);
        assertSame('bar', Env::get('FOO'));
    }

    public function testComentariosIgnorados(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($tmp, "# comentario\nKEY=valor # inline\n#otro\n");
        $loaded = Env::load($tmp, true);
        unlink($tmp);

        assertFalse(isset($loaded['comentario']));
        assertSame('valor', $loaded['KEY']);
        assertEquals('valor', Env::get('KEY'));
    }

    public function testCasteoDeTipos(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($tmp, "TRUE_VAR=true\nFALSE_VAR=false\nNULL_VAR=null\nINT_VAR=42\nSTRING_VAR=hola\n");
        $loaded = Env::load($tmp, true);
        unlink($tmp);

        assertTrue($loaded['TRUE_VAR']);
        assertFalse($loaded['FALSE_VAR']);
        assertNull($loaded['NULL_VAR']);
        assertSame(42, $loaded['INT_VAR']);
        assertSame('hola', $loaded['STRING_VAR']);
    }

    public function testComillasSimplesYDobles(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($tmp, "SIMPLE='valor con espacio'\nDOBLE=\"otro valor\"\n");
        $loaded = Env::load($tmp, true);
        unlink($tmp);

        assertSame('valor con espacio', $loaded['SIMPLE']);
        assertSame('otro valor', $loaded['DOBLE']);
    }

    public function testNoSobreescribeVariablesExistentes(): void
    {
        putenv('EXISTENTE=v1');
        $tmp = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($tmp, "EXISTENTE=v2\n");
        $loaded = Env::load($tmp, false);
        unlink($tmp);

        assertFalse(isset($loaded['EXISTENTE']));
        assertSame('v1', Env::get('EXISTENTE'));
    }

    public function testClaveInvalidaIgnorada(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($tmp, "CLAVE-INVALIDA=x\nVAR_VALIDA=y\n");
        $loaded = Env::load($tmp, true);
        unlink($tmp);

        assertFalse(isset($loaded['CLAVE-INVALIDA']));
        assertTrue(isset($loaded['VAR_VALIDA']));
    }

    public function testGetConDefault(): void
    {
        assertSame('default', Env::get('NO_EXISTE', 'default'));
        assertNull(Env::get('NO_EXISTE_2'));
    }
}
