<?php

declare(strict_types=1);

/**
 * Runner de tests propio para VanillaForms.
 *
 * Sustituye a PHPUnit (que requiere Composer). Sin dependencias externas.
 *
 * Uso:
 *   php scripts/test.php                    # ejecuta todos los tests en tests/
 *   php scripts/test.php --filter Auth      # filtra por NOMBRE DE MÉTODO (espacio, no `=`)
 *   php scripts/test.php --path tests/unit  # carpeta específica (solo directorios)
 *
 * Convenciones para tests en tests/ (archivos con sufijo Test.php):
 *   - Métodos públicos NO estáticos cuyo nombre empiece por `test`
 *     (el filtro casa por substring SINSENSIBLE a mayúsculas sobre el nombre
 *     del método, no de la clase; un filtro que no casa con nada ejecuta 0
 *     tests y sale verde — comprobar siempre el contador).
 *   - setUp() de instancia opcional (antes de CADA test, la clase se instancia
 *     UNA vez por test: el estado compartido va en `static`).
 *   - tearDown() de instancia opcional (después de CADA test, pase lo que
 *     pase, incluso si falló: se usa para limpiar filas/archivos creados).
 *   - Asserts globales: assertTrue, assertFalse, assertEquals, assertNull,
 *     assertNotNull, assertSame, assertInstanceOf, assertArrayHasKey, fail
 */

require_once dirname(__DIR__) . '/config/autoload.php';

$args = $_SERVER['argv'] ?? [];
parseArgs($args, $filter, $pathFilter);

function parseArgs(array $args, ?string &$filter, ?string &$pathFilter): void
{
    $filter = null;
    $pathFilter = null;
    for ($i = 1, $n = count($args); $i < $n; $i++) {
        if ($args[$i] === '--filter' && isset($args[$i + 1])) {
            $filter = $args[++$i];
        }
        if ($args[$i] === '--path' && isset($args[$i + 1])) {
            $pathFilter = $args[++$i];
        }
    }
}

$baseDir = dirname(__DIR__) . '/tests';
if ($pathFilter !== null) {
    $baseDir = rtrim($pathFilter, '/');
    if (!is_dir($baseDir)) {
        fwrite(STDERR, "Error: directorio no existe: {$baseDir}\n");
        exit(1);
    }
}

$testFiles = findTestFiles($baseDir);

if (empty($testFiles)) {
    echo "No se encontraron archivos de test en {$baseDir}\n";
    exit(0);
}

$totalTests = 0;
$totalFailures = 0;
$totalErrors = 0;
$startTime = microtime(true);

foreach ($testFiles as $file) {
    runTestFile($file, $filter, $totalTests, $totalFailures, $totalErrors);
}

$elapsed = round((microtime(true) - $startTime) * 1000, 2);

echo "\n" . str_repeat('=', 60) . "\n";
printf(
    "Tests: %d | Fallidos: %d | Errores: %d | Tiempo: %.2f ms\n",
    $totalTests,
    $totalFailures,
    $totalErrors,
    $elapsed
);
echo str_repeat('=', 60) . "\n";

exit($totalFailures > 0 || $totalErrors > 0 ? 1 : 0);

/**
 * Busca archivos de test recursivamente.
 *
 * @return string[]
 */
function findTestFiles(string $dir): array
{
    $files = [];
    $items = glob($dir . '/*');
    if ($items === false) {
        return $files;
    }
    foreach ($items as $item) {
        if (is_dir($item)) {
            $files = array_merge($files, findTestFiles($item));
        } elseif (is_file($item) && substr($item, -8) === 'Test.php') {
            $files[] = $item;
        }
    }
    return $files;
}

/**
 * Ejecuta un archivo de test.
 */
function runTestFile(string $file, ?string $filter, int &$tests, int &$failures, int &$errors): void
{
    require_once $file;

    // Determinar la clase de test (basada en el nombre del archivo)
    $basename = basename($file, '.php');
    $className = $basename;

    // Si la clase no puede autoloadearse directamente, buscarla por reflexion global
    if (!class_exists($className)) {
        foreach (get_declared_classes() as $class) {
            if ((new ReflectionClass($class))->getFileName() === realpath($file)) {
                $className = $class;
                break;
            }
        }
    }

    if (!class_exists($className)) {
        printf("  SKIP %s: clase no encontrada\n", $basename);
        return;
    }

    $reflection = new ReflectionClass($className);

    // Buscar metodos de test
    $testMethods = [];
    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->isStatic() || $method->isConstructor() || $method->isDestructor()) {
            continue;
        }
        $methodName = $method->getName();
        if (!str_starts_with($methodName, 'test') || $methodName === 'setUp') {
            continue;
        }
        if ($filter !== null && stripos($methodName, $filter) === false) {
            continue;
        }
        if (substr($methodName, 0, 4) !== 'test') {
            continue;
        }
        $testMethods[] = $methodName;
    }

    sort($testMethods);

    if (empty($testMethods)) {
        printf("  SKIP %s: sin metodos test*\n", $basename);
        return;
    }

    printf("\n%s\n", $basename);

    foreach ($testMethods as $methodName) {
        $tests++;
        // Instanciar la clase una vez por test
        $instance = $reflection->newInstance();
        try {
            if ($reflection->hasMethod('setUp')) {
                $reflection->getMethod('setUp')->invoke($instance);
            }
            $reflection->getMethod($methodName)->invoke($instance);
            printf("  \033[32m✔\033[0m %s\n", $methodName);
        } catch (\Throwable $e) {
            // Recolectar fallos vs errores
            // Los fallos lanzan RuntimeException con mensaje "FAILED:"
            if (str_starts_with($e->getMessage(), 'FAILED:')) {
                $failures++;
                printf("  \033[31m✘\033[0m %s — %s\n", $methodName, $e->getMessage());
            } else {
                $errors++;
                printf("  \033[33m!\033[0m %s — ERROR: %s (%s:%d)\n", $methodName, $e->getMessage(), basename($e->getFile()), $e->getLine());
            }
        } finally {
            // tearDown() opcional (se ejecuta pase lo que pase).
            if ($reflection->hasMethod('tearDown')) {
                try {
                    $reflection->getMethod('tearDown')->invoke($instance);
                } catch (\Throwable $e) {
                    printf("  \033[33m!\033[0m teardown: %s\n", $e->getMessage());
                }
            }
        }
    }
}

// ---- Funciones de assert globales (se invocan desde los tests) ----

function assertTrue($condition, string $message = ''): void
{
    if ($condition !== true) {
        throw new RuntimeException('FAILED: assertTrue — ' . ($message ?: 'se esperaba true'));
    }
}

function assertFalse($condition, string $message = ''): void
{
    if ($condition !== false) {
        throw new RuntimeException('FAILED: assertFalse — ' . ($message ?: 'se esperaba false'));
    }
}

function assertEquals($expected, $actual, string $message = ''): void
{
    if ($expected != $actual) {
        throw new RuntimeException('FAILED: assertEquals — ' . ($message ?: 'valores distintos'));
    }
}

function assertSame($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('FAILED: assertSame — ' . ($message ?: 'valores no identicos'));
    }
}

function assertNull($value, string $message = ''): void
{
    if ($value !== null) {
        throw new RuntimeException('FAILED: assertNull — ' . ($message ?: 'se esperaba null'));
    }
}

function assertNotNull($value, string $message = ''): void
{
    if ($value === null) {
        throw new RuntimeException('FAILED: assertNotNull — ' . ($message ?: 'no se esperaba null'));
    }
}

function assertArrayHasKey($key, array $array, string $message = ''): void
{
    if (!array_key_exists($key, $array)) {
        throw new RuntimeException('FAILED: assertArrayHasKey — falta clave "' . $key . '" ' . $message);
    }
}

function fail(string $message): void
{
    throw new RuntimeException('FAILED: ' . $message);
}
