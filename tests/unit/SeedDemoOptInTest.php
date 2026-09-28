<?php

declare(strict_types=1);

namespace Tests\Unit;

/**
 * Fase 2, item 2.3 — el seed con contraseña de admin publicada.
 *
 * `database/seed.sql` creaba tres usuarios con la MISMA contraseña escrita en el
 * propio archivo del repositorio, y uno era admin. Cualquier despliegue que
 * corriera el seed (y el README/AGENTS.md decían exactamente eso) se quedaba con
 * un admin cuya contraseña estaba en GitHub, y encima servía para entrar.
 *
 * El arrangement es: el SQL solo crea roles (nada de credenciales) y los usuarios
 * demo se siembran con `database/seed.php`, que no hace NADA salvo que se pida
 * con `SEED_DEMO=1`. Estos tests leen el código de los dos archivos, porque un
 * seed es un script que se ejecuta a mano y no hay forma de invocarlo desde la
 * suite sin sembrar de verdad en la BD del entorno.
 */
class SeedDemoOptInTest
{
    /* ---------- El SQL ya no lleva credenciales ---------- */

    /**
     * Lo más importante: ni una sola cadena que parezca un hash, ni una
     * contraseña en claro, ni un INSERT en `users`. Un bcrypt en el repo es una
     * contraseña conocida aunque el archivo esté detrás de un opt-in.
     */
    public function testElSeedSqlNoContieneCredenciales(): void
    {
        $sql = (string) file_get_contents($this->path('database/seed.sql'));

        assertFalse(
            str_contains($sql, 'password_hash'),
            'seed.sql no debe insertar usuarios: el hash vive solo en seed.php'
        );
        assertFalse(
            preg_match('/INSERT\s+INTO\s+users/i', $sql) === 1,
            'seed.sql no debe insertar usuarios'
        );
        assertFalse(
            preg_match('/\$2[aby]\$/', $sql) === 1,
            'seed.sql no debe llevar ningún hash bcrypt (sería una contraseña publicada)'
        );
        foreach (['password123', 'admin@demo.com', 'user@demo.com', 'editor@demo.com'] as $cred) {
            assertFalse(
                str_contains($sql, $cred),
                'seed.sql no debe llevar la credencial publicada: ' . $cred
            );
        }
    }

    /**
     * Lo que sí tiene que seguir haciendo: los roles. Un despliegue nuevo
     * necesita los tres roles, y si `seed.sql` dejara de crearlos, el
     * `create-admin.php` no encontraría el de admin.
     */
    public function testElSeedSqlSigueCreandoLosRoles(): void
    {
        $sql = (string) file_get_contents($this->path('database/seed.sql'));

        assertTrue(str_contains($sql, 'INSERT INTO roles'), 'debe insertar los roles');
        foreach (['admin', 'user', 'editor'] as $rol) {
            assertTrue(
                str_contains($sql, "'" . $rol . "'"),
                'debe crear el rol ' . $rol
            );
        }
        assertTrue(
            str_contains($sql, 'ON DUPLICATE KEY'),
            'debe seguir siendo idempotente'
        );
    }

    /* ---------- El script de usuarios detrás del opt-in ---------- */

    public function testElScriptDeSeedCompruebaElOptInAntesDeSembrar(): void
    {
        $src = (string) file_get_contents($this->path('database/seed.php'));

        assertTrue(
            str_contains($src, 'SEED_DEMO'),
            'el opt-in se llama SEED_DEMO'
        );

        // El orden importa: la comprobación y el `exit(1)` tienen que ocurrir
        // ANTES de cualquier INSERT. Si el INSERT quedara antes, el opt-in sería
        // decorativo. Se ancla en el código, no en el texto del docblock.
        $optIn = strpos($src, "\$optIn = getenv('SEED_DEMO')");
        $negativa = strpos($src, 'if (!$optIn)');
        $sale = strpos($src, 'exit(1)', (int) $optIn);
        $inserta = strpos($src, 'INSERT INTO users');
        assertNotNull($optIn, 'debe leerse el opt-in del entorno');
        assertNotNull($negativa, 'debe comprobar el opt-in');
        assertNotNull($sale, 'debe negarse a sembrar sin opt-in');
        assertNotNull($inserta, 'debe sembrar usuarios');

        assertTrue($optIn < $negativa, 'el opt-in se lee antes de comprobarlo');
        assertTrue($negativa < $sale, 'la comprobación precede a la salida con error');
        assertTrue($sale < $inserta, 'la negativa tiene que ocurrir antes del INSERT');
    }

    /**
     * El opt-in se resuelve con el valor real, no con un "if" que exista pero
     * pueda saltarse: tiene que pasar por un filtro booleano, que es lo que
     * acepta "1"/"true"/"yes" y rechaza "0"/""/cualquier tontería.
     */
    public function testElOptinSeInterpretaComoBooleano(): void
    {
        $src = (string) file_get_contents($this->path('database/seed.php'));
        assertTrue(
            str_contains($src, 'FILTER_VALIDATE_BOOLEAN'),
            'el opt-in debe pasar por FILTER_VALIDATE_BOOLEAN'
        );
        assertTrue(
            preg_match('/if\s*\(\s*!\$optIn\s*\)/', $src) === 1,
            'debe negarse cuando el opt-in no es verdadero'
        );
    }

    /**
     * El hash se genera en el momento. Si el script llevara un bcrypt escrito,
     * el opt-in protegería la ejecución pero la contraseña seguiría publicada.
     */
    public function testElScriptGeneraElHashEnElMomento(): void
    {
        $src = (string) file_get_contents($this->path('database/seed.php'));

        assertTrue(
            str_contains($src, 'password_hash('),
            'el hash debe generarse con password_hash()'
        );
        assertFalse(
            preg_match('/\$2[aby]\$/', $src) === 1,
            'el script no debe llevar ningún hash escrito dentro'
        );
    }

    public function testElScriptEsIdempotenteYTieneModoFresh(): void
    {
        $src = (string) file_get_contents($this->path('database/seed.php'));

        assertTrue(
            str_contains($src, 'SELECT id FROM users WHERE email'),
            'debe comprobar si el usuario ya existe antes de insertar'
        );
        assertTrue(
            str_contains($src, '--fresh'),
            'debe tener un modo --fresh para resetear la contraseña'
        );
    }

    /**
     * Y avisa cuando la contraseña es la de por defecto, que es pública.
     */
    public function testElScriptAvisaSiLaContrasenaEsLaPublicada(): void
    {
        $src = (string) file_get_contents($this->path('database/seed.php'));

        assertTrue(
            str_contains($src, 'SEED_DEMO_PASSWORD'),
            'la contraseña debe poder fijarse por entorno'
        );
        assertTrue(
            preg_match("/password123/", $src) === 1,
            'debe reconocer la contraseña publicada para poder avisar de ella'
        );
        assertTrue(
            str_contains($src, 'AVISO'),
            'debe avisar de que esa contraseña es pública'
        );
    }

    /* ---------- El sustituto: crear un admin a mano ---------- */

    /**
     * Poner el seed detrás de un opt-in deja al operador sin admin si no se le
     * da otra vía: registrarse por la web da rol `user`, y con la app recién
     * instalada no hay forma de ascenderse. Por eso `scripts/create-admin.php`
     * es parte del arreglo, no un extra.
     */
    public function testExisteUnaViaParaCrearElAdminSinElSeed(): void
    {
        $src = (string) file_get_contents($this->path('scripts/create-admin.php'));

        assertTrue(
            str_contains($src, 'FROM roles') && str_contains($src, "'n' => 'admin'"),
            'debe asignar el rol admin (con query parametrizada)'
        );
        assertTrue(str_contains($src, 'password_hash('), 'debe hashear la contraseña');
        assertTrue(
            str_contains($src, 'ADMIN_PASSWORD'),
            'la contraseña debe venir del entorno o de stdin, no de un argumento'
        );
        assertFalse(
            str_contains($src, '$2y$'),
            'no debe llevar ningún hash escrito'
        );

        // Mismo mínimo que el registro, para que la política sea una sola.
        assertTrue(
            preg_match('/PASSWORD_MIN\s*=\s*(\d+)/', $src, $m) === 1,
            'debe declarar su mínimo de contraseña'
        );
        assertTrue(
            (int) $m[1] >= 10,
            'el mínimo del admin no puede ser más flojo que el del registro'
        );
    }

    /**
     * La contraseña no puede ir como argumento de línea de comandos: queda en el
     * historial del shell y en la lista de procesos de todo el sistema.
     */
    public function testElAdminNoAceptaLaContrasenaComoArgumento(): void
    {
        $src = (string) file_get_contents($this->path('scripts/create-admin.php'));
        $args = (string) file_get_contents($this->path('database/seed.php'));

        assertTrue(
            str_contains($args, 'ADMIN_PASSWORD') === false,
            'seed.php no es donde se crea el admin'
        );
        assertFalse(
            preg_match('/opts\[\'password\'\]\s*\?\?\s*getenv\(\'ADMIN_PASSWORD\'\)/', $src) === 1,
            'ADMIN_PASSWORD se lee del entorno, no de --password'
        );
        // Nota: se admite --password-stdin, que va por la entrada estándar y
        // no aparece ni en el historial ni en `ps`.
        assertTrue(
            str_contains($src, '--password-stdin'),
            'debe admitir la contraseña por stdin'
        );
    }

    private function path(string $rel): string
    {
        return dirname(__DIR__, 2) . '/' . $rel;
    }
}
