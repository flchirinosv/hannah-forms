<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Models\FormParticipant;

/**
 * Fase 3 — importación de un listado de participantes desde un `.xlsx`.
 *
 * Lo que decide es qué pasa con las filas que **no** están bien, y por eso los dos
 * modos se comportan de forma distinta a propósito:
 *
 *  - **`fusionar`** es indulgente. Cada fila se mira por su cuenta: si esa persona
 *    ya está, se actualiza conservando su `code`; si no, se da de alta; si esa fila
 *    tiene un problema, se cuenta y se cuenta por qué, y las demás entran. Un
 *    archivo de 2000 filas con tres duplicados se importa entero salvo tres.
 *  - **`reemplazar`** es TODO O NADA, y no por rigididad sino por destructividad.
 *    Vaciar la lista borra a la gente que ya tenía su `code` repartido; si eso
 *    pasara y luego el archivo tuviera una fila mala, el dueño se queda sin lista
 *    y sin saber qué se perdió. Así que **todas las filas se validan antes de
 *    borrar nada**: si hay un error, no se toca la lista y se dice cuál fue.
 *
 * Todo el trabajo de escritura va en **una transacción** que cubre la importación
 * entera. En modo `reemplazar` incluye el `DELETE`, y por eso el borrado va
 * dentro y no antes: si se borrara fuera y la inserción fallara, la lista quedaría
 * vacía con el error ya mostrado.
 *
 * Dos reglas que parecen detalles y no lo son:
 *
 *  1. **El `code` NUNCA se importa.** La columna existe en el archivo para que quien
 *     lo lleva impreso pueda ver qué código tiene cada quien, pero el valor se
 *     ignora: un código aceptado del archivo sería dejar que un `.xlsx` eligiera la
 *     credencial de acceso de una persona. En `fusionar` gana el que ya está en la
 *     BD; en `reemplazar` lo genera el modelo.
 *  2. **El catálogo de atributos no se borra al reemplazar.** Los participantes sí,
 *     porque son las personas; los atributos son la red de filtros que se ha ido
 *     construyendo, y una importación no debe dejar el formulario sin la columna
 *     «Sede» que alguien llevaba usando dos años.
 */
final class ParticipantImporter
{
    /** Añade lo que no está y actualiza lo que ya está. */
    public const MODO_FUSIONAR = 'fusionar';

    /** Vacía la lista y la vuelve a construir con el archivo entero. */
    public const MODO_REEMPLAZAR = 'reemplazar';

    public const MODOS = [self::MODO_FUSIONAR, self::MODO_REEMPLAZAR];

    /** Tope de filas que se procesan, para que un archivo enorme no se lleve la petición. */
    public const MAX_FILAS = 20000;

    /** Cuántos problemas concretos se guardan en el informe antes de resumir. */
    private const MAX_PROBLEMAS = 200;

    /**
     * Motivo de «el archivo trae una etiqueta que no se puede guardar».
     *
     * Va en el **mensaje** de la excepción porque el importador no tiene una clase
     * propia para eso: el autoload es PSR-4 de una clase por fichero y meter un
     * segundo nombre en este archivo haría que no se encontrara nunca. El `catch`
     * compara el mensaje y **re-lanza** cualquier otro `RuntimeException`, así que un
     * fallo de verdad durante la escritura no se traduce a «etiqueta».
     */
    private const ERROR_ATRIBUTO = 'atributo_no_creable';

    private static function db(): Database
    {
        return Database::getInstance(Config::db());
    }

    /**
     * Importa un archivo.
     *
     * @return array{
     *     ok:bool, error:?string, modo:string, total:int,
     *     insertados:int, actualizados:int, sin_cambios:int, omitidos:int,
     *     atributos_nuevos:list<string>, problemas:list<array{fila:int,motivo:string,detalle:?string}>
     * }
     */
    public static function importar(int $formId, string $ruta, string $identificador, string $modo): array
    {
        $vacio = [
            'ok' => false, 'error' => null, 'modo' => $modo, 'total' => 0,
            'insertados' => 0, 'actualizados' => 0, 'sin_cambios' => 0, 'omitidos' => 0,
            'atributos_nuevos' => [], 'problemas' => [],
        ];

        if (!in_array($modo, self::MODOS, true)) {
            $vacio['error'] = 'modo_invalido';

            return $vacio;
        }
        if (!in_array($identificador, FormSettings::ACCESS_IDENTIFIERS, true)) {
            $vacio['error'] = 'identificador_invalido';

            return $vacio;
        }
        if (!RosterTemplate::puedeLeer()) {
            $vacio['error'] = 'roster_sin_zip';

            return $vacio;
        }

        try {
            $libro = RosterTemplate::leer($ruta);
        } catch (\RuntimeException $e) {
            // El motivo es una clave corta (`roster_no_zip`, `roster_vacia`…) que
            // quien llama traduce. Se propaga tal cual en vez de tragárselo aquí:
            // un importador que devuelve siempre «el archivo no se pudo leer»
            // obliga a abrir el archivo a mano para saber por qué.
            $vacio['error'] = $e->getMessage();

            return $vacio;
        }

        $filas = $libro['filas'];
        if ($filas === []) {
            // Un archivo sin filas rejected en AMBOS modos, y en `reemplazar` es
            // justamente el caso peligroso: vaciar la lista y no poner nada en su
            // lugar no es una importación, es un borrado disfrazado.
            $vacio['error'] = 'roster_sin_filas';

            return $vacio;
        }
        if (count($filas) > self::MAX_FILAS) {
            $vacio['error'] = 'roster_demasiadas_filas';

            return $vacio;
        }

        $columnas = self::mapaColumnas($libro['cabeceras']);
        if ($columnas['error'] !== null) {
            $vacio['error'] = $columnas['error'];

            return $vacio;
        }

        $preparadas = self::preparar($filas, $columnas['mapa'], $identificador);

        if ($preparadas['error'] !== null) {
            $vacio['error'] = $preparadas['error'];
            $vacio['total'] = count($filas);

            return $vacio;
        }

        if ($modo === self::MODO_REEMPLAZAR && $preparadas['problemas'] !== []) {
            // Nada de `DELETE` todavía: se dice qué está mal y la lista sigue como
            // estaba.
            $vacio['error'] = 'reemplazar_con_errores';
            $vacio['total'] = count($filas);
            $vacio['omitidos'] = (int) ($preparadas['omitidos'] ?? 0);
            $vacio['problemas'] = $preparadas['problemas'];

            return $vacio;
        }

        // Los atributos se crean DENTRO de la transacción, junto con los
        // participantes: un `INSERT` de catálogo que sobrevive a una importación
        // deshecha deja columnas sin usar que el dueño no ha pedido.
        $fallo = null;
        $resumen = ['insertados' => 0, 'actualizados' => 0, 'sin_cambios' => 0];

        try {
            self::db()->transaccionar(function () use ($formId, $modo, $filas, $columnas, $preparadas, $identificador, &$resumen): void {
                $atributos = self::asegurarAtributos($formId, $columnas['atributos']);

                // El borrado va AQUÍ y no antes: si fuera fuera de la transacción y la
                // inserción fallara, la lista quedaría vacía con el error ya mostrado y
                // sin vuelta atrás. Adentro, un fallo posterior deshace también el
                // `DELETE`.
                if ($modo === self::MODO_REEMPLAZAR) {
                    FormParticipant::truncate($formId);
                }

                $resumen = self::escribir($formId, $preparadas['filas'], $identificador, $atributos);
            });
        } catch (\RuntimeException $e) {
            // La excepción sale DESPUÉS del `rollBack()` que hace `transaccionar()`
            // en su `catch`, así que solo se traduce a «el archivo trae una etiqueta
            // que no se puede guardar» cuando es ÉSTA. Cualquier otro error de
            // escritura se re-lanza: si no, un fallo de verdad acabaría contestando
            // «renombra la columna».
            if ($e->getMessage() !== self::ERROR_ATRIBUTO) {
                throw $e;
            }
            $fallo = self::ERROR_ATRIBUTO;
        }

        if ($fallo !== null) {
            $vacio['error'] = $fallo;
            $vacio['total'] = count($filas);

            return $vacio;
        }

        return [
            'ok'               => true,
            'error'            => null,
            'modo'             => $modo,
            'total'            => count($filas),
            'insertados'       => $resumen['insertados'],
            'actualizados'     => $resumen['actualizados'],
            'sin_cambios'      => $resumen['sin_cambios'],
            'omitidos'         => (int) ($preparadas['omitidos'] ?? 0),
            'atributos_nuevos' => $preparadas['nuevos'],
            'problemas'        => $preparadas['problemas'],
        ];
    }

    /**
     * Traduce las cabeceras a columnas del participante o a atributos.
     *
     * @param list<string> $cabeceras
     *
     * @return array{error:?string, mapa:array<int,array<string,string>>, atributos:list<string>}
     */
    private static function mapaColumnas(array $cabeceras): array
    {
        $mapa = [];
        $atributos = [];

        foreach ($cabeceras as $i => $cabecera) {
            $reservada = RosterTemplate::columnaReservada($cabecera);

            // `codigo` sale del mapa como columna IGNORADA, no como atributo: es la
            // última del archivo exportado y si no se trata aparte se guardaría
            // como un atributo llamado `codigo` que nadie quiere filtrar por.
            if ($reservada === 'codigo') {
                $mapa[$i] = ['tipo' => 'ignorar'];
                continue;
            }
            if ($reservada !== null) {
                $mapa[$i] = ['tipo' => 'reservada', 'columna' => $reservada];
                continue;
            }

            $mapa[$i] = ['tipo' => 'atributo', 'columna' => $cabecera];
            $atributos[$cabecera] = true;
        }

        return ['error' => null, 'mapa' => $mapa, 'atributos' => array_keys($atributos)];
    }

    /**
     * Valida y normaliza cada fila, sin tocar la BD.
     *
     * Va separado de la escritura para que `reemplazar` pueda rechazar el archivo
     * entero ANTES de borrar la lista, y para que la detección de duplicados dentro
     * del propio archivo sea posible: dos filas iguales en el `.xlsx` son un error
     * del archivo, no un choque con la base de datos.
     *
     * @param list<list<string>> $filas
     * @param array<int,array<string,string>> $mapa
     *
     * @return array{error:?string,filas:list<array<string,mixed>>,problemas:list<array{fila:int,motivo:string,detalle:?string}>,nuevos:list<string>}
     */
    private static function preparar(array $filas, array $mapa, string $identificador): array
    {
        $reservadas = [];
        foreach ($mapa as $entrada) {
            if ($entrada['tipo'] === 'reservada') {
                $reservadas[$entrada['columna']] = true;
            }
        }

        // Sin la columna del identificador no hay forma de saber QUIÉN es cada fila,
        // y una lista sin identidad no es una lista: es un directorio. Esto NO es un
        // problema de una fila sino del archivo entero —una columna que falta afecta
        // a todas—, así que sale como error y no como omisión: admitir «0 de 2000
        // importados» con un archivo entero de motivo es peor que un no claro.
        if (!isset($reservadas[$identificador])) {
            return [
                'error'     => 'falta_columna_identificador',
                'filas'     => [],
                'problemas' => [],
                'omitidos'  => 0,
                'nuevos'    => [],
            ];
        }

        $buenas  = [];
        $vistas  = [];
        $problemas = [];
        $omitidos = 0;
        $nuevos  = [];

        foreach ($filas as $n => $fila) {
            // +2 porque la cabecera es la fila 1 del archivo y esto es lo que ve la
            // persona en el Excel, no el índice del array.
            $numero = $n + 2;

            $campos = ['cedula' => '', 'nombre' => '', 'indicador' => '', 'correo' => ''];
            $bruto  = [];
            foreach ($mapa as $i => $entrada) {
                $valor = (string) ($fila[$i] ?? '');
                if ($entrada['tipo'] === 'reservada') {
                    $campos[$entrada['columna']] = $valor;
                } elseif ($entrada['tipo'] === 'atributo') {
                    $bruto[$entrada['columna']] = $valor;
                }
            }

            $identificadorValor = FormParticipant::identifierValue(
                $identificador,
                $campos['cedula'],
                $campos['indicador'],
                $campos['correo']
            );

            // Se pasan los criterios del MODELO y no una copia de ellos: si aquí se
            // comprobaran solo el identificador, una fila con el correo mal escrito
            // se contaría como importada y se encontraría a medias después. Es la
            // misma función la que usan `create()` y `update()`.
            $motivo = FormParticipant::validarDatos($identificador, $identificadorValor, $campos['correo']);
            if ($motivo !== 'ok') {
                self::anotar($problemas, $numero, $motivo, null, $omitidos);
                continue;
            }

            // La deduplicación usa el identificador YA NORMALIZADO, no el de la
            // cabecera: dos filas que escriben la cédula con espacios distintos
            // son la misma persona, y aceptarlas como dos sería dar de alta a la
            // misma dos veces en lugar de avisar de que el archivo está repetido.
            $clave = $identificadorValor;
            if (isset($vistas[$clave])) {
                self::anotar($problemas, $numero, FormParticipant::MOTIVO_DUPLICADO, 'la fila ' . $vistas[$clave], $omitidos);
                continue;
            }
            $vistas[$clave] = $numero;

            foreach (array_keys($bruto) as $cabecera) {
                // La deduplicación es por etiqueta **normalizada**, no por cabecera
                // cruda: `Sede` y `Sede ` son la misma columna del catálogo (se
                // pegan a propósito y gana la de la derecha), así que anunciarlas
                // como dos atributos nuevos sería mentirle sobre lo que se
                // guardó. Se guarda la etiqueta normalizada porque es la que queda
                // en la fila.
                $etiqueta = FormParticipant::normalizar($cabecera);
                if (!in_array($etiqueta, $nuevos, true)) {
                    $nuevos[] = $etiqueta;
                }
            }

            $buenas[] = [
                'numero' => $numero,
                'campos' => $campos,
                'bruto'  => $bruto,
            ];
        }

        return [
            'error'     => null,
            'filas'     => $buenas,
            'problemas' => $problemas,
            'omitidos'  => $omitidos,
            'nuevos'    => $nuevos,
        ];
    }

    /**
     * Una cabecera del archivo que no se puede convertir en columna del catálogo.
     *
     * Sale como **excepción**, y no como `null`, por una razón que no se ve leyendo el
     * camino feliz: `Database::transaccionar()` confirma la transacción cuando la
     * closure **devuelve**, y un `return` dentro de la closure es un `return` normal.
     * Devolviendo `null` a mitad, las columnas anteriores ya se habían creado y la
     * callback salía «sin error»: se confirmaban columnas sin las filas que las iban
     * a usar, y el dueño se encontraba un catálogo inflado con el mensaje de «esa
     * etiqueta no se puede guardar». Lanzar es lo que deshace la transacción entera.
     */
    private static function cabeceraNoCreable(): \RuntimeException
    {
        return new \RuntimeException(self::ERROR_ATRIBUTO);
    }

    /**
     * Crea (o reusa) los atributos del archivo y devuelve cabecera => `key_name`.
     *
     * @param list<string> $atributos
     *
     * @return array<string,string>
     *
     * @throws RuntimeException si alguna cabecera no se pudo crear
     */
    private static function asegurarAtributos(int $formId, array $atributos): array
    {
        $mapa = [];
        foreach ($atributos as $cabecera) {
            $clave = FormParticipant::ensureAttribute($formId, $cabecera);
            if ($clave === null) {
                throw self::cabeceraNoCreable();
            }
            $mapa[$cabecera] = $clave;
        }

        return $mapa;
    }

    /**
     * Escribe las filas ya validadas.
     *
     * @param list<array{numero:int,campos:array<string,string>,bruto:array<string,string>}> $filas
     * @param array<string,string> $atributosMapa
     *
     * @return array{insertados:int,actualizados:int,sin_cambios:int}
     */
    private static function escribir(
        int $formId,
        array $filas,
        string $identificador,
        array $atributosMapa
    ): array {
        $insertados = 0;
        $actualizados = 0;
        $sinCambios = 0;

        foreach ($filas as $fila) {
            // `null` y NO `[]` cuando el archivo no trae ninguna columna de
            // atributo. La diferencia es la que separa «no he tocado los atributos»
            // de «los he dejado todos vacíos»: `update()` con `[]` borra las filas
            // de `form_participant_attrs`, así que un archivo de una sola columna
            // «Cédula» vaciaría los atributos de todo el mundo sin decir nada.
            // Un archivo que SÍ trae la columna y la celda vacía sí borra ese
            // atributo, y para eso `$bruto` tiene la clave con valor `''`.
            $data = null;
            if ($fila['bruto'] !== []) {
                $data = [];
                foreach ($fila['bruto'] as $cabecera => $valor) {
                    $data[$atributosMapa[$cabecera]] = FormParticipant::normalizar($valor);
                }
            }

            $existente = FormParticipant::findByIdentifier(
                $formId,
                $identificador,
                self::columnaDe($fila['campos'], $identificador)
            );

            if ($existente !== null) {
                $antes = FormParticipant::attributesOf((int) $existente['id']);
                $r = FormParticipant::update((int) $existente['id'], $identificador, $fila['campos'], $data);
                if (!$r['ok']) {
                    // Solo puede ser el correo: la identidad ya se comprobó al
                    // preparar y el `UPDATE` sobre la misma fila no puede chocar
                    // con el `UNIQUE` que el alta acaba de pasar.
                    self::notaFallo((int) $existente['id'], $r['motivo']);
                    continue;
                }
                if (self::sinCambios($fila['campos'], $existente, $antes, $data)) {
                    $sinCambios++;
                } else {
                    $actualizados++;
                }
                continue;
            }

            // `create()` recibe `[]` y no `null`: dar de alta sin atributos no es un
            // caso raro que haya que distinguir de un alta con atributos vacíos, es
            // simplemente un alta. Quien sí necesita distinguirlo es `update()`, que
            // es donde está el `DELETE`.
            $r = FormParticipant::create($formId, $identificador, $fila['campos'], $data ?? []);
            if ($r['ok']) {
                $insertados++;
            }
        }

        return ['insertados' => $insertados, 'actualizados' => $actualizados, 'sin_cambios' => $sinCambios];
    }

    /** @param array<string,string> $campos */
    private static function columnaDe(array $campos, string $identificador): string
    {
        return $campos[$identificador] ?? '';
    }

    /**
     * ¿Esta fila dejaría la ficha exactamente como está?
     *
     * Solo es una forma de que el informe diga «300 revisados, 0 cambiados» en vez
     * de «300 modificados», que es lo que se ve al reimportar el mismo archivo dos
     * veces seguidas. Importar sin cambios tiene que verse como importar sin
     * cambios, o el dueño no puede distinguir un arreglo de un no-op.
     *
     * @param array<string,string> $campos
     * @param array<string,mixed>  $existente
     * @param array<string,string>      $atributosAntes
     * @param array<string,string>|null $atributosDespues `null` si el archivo no traía columnas
     */
    private static function sinCambios(
        array $campos,
        array $existente,
        array $atributosAntes,
        ?array $atributosDespues
    ): bool {
        foreach (['cedula', 'nombre', 'indicador', 'correo'] as $columna) {
            if (FormParticipant::normalizar((string) $existente[$columna]) !== FormParticipant::normalizar($campos[$columna])) {
                return false;
            }
        }

        // Se comparan los atributos que el ARCHIVO menciona. Los que no aparecen en
        // el archivo no se tocan (la importación no los borra), así que no pueden
        // hacer que una fila parezca cambiada por algo que no se ha tocado.
        foreach ($atributosDespues ?? [] as $clave => $valor) {
            $antes = FormParticipant::normalizar((string) ($atributosAntes[$clave] ?? ''));
            if ($antes !== FormParticipant::normalizar($valor)) {
                return false;
            }
        }

        return true;
    }

    private static function notaFallo(int $participantId, string $motivo): void
    {
        // Un fallo al actualizar una fila existente no puede abortar la importación
        // entera: lo normal es que el `code` siga sirviendo. Se registra en el log
        // del sistema, que es donde se busca cuando alguien pregunta por qué su
        // correo no se guardó, y el contador `omitidos` no lo incluye porque el
        // informe ya se cerró antes de escribir.
        \App\Core\SecurityLog::record('roster.import_fila_fallida', $motivo . ' (participante ' . $participantId . ')');
    }

    /**
     * Anota un problema, sin pasarse del tope.
     *
     * La lista que se devuelve al dueño está **topada** (`MAX_PROBLEMAS`: nadie
     * quiere leer 2000 líneas de errores), pero el **contador va entero**. Medir
     * con `count($problemas)` daría «200 omitidos» de un archivo con 2000, que es
     * peor que no dar ninguno: quien lee el informe no puede saber si se le quedó
     * gente fuera a propósito o si el archivo tenía lo que parecía.
     *
     * @param list<array{fila:int,motivo:string,detalle:?string}> $problemas
     */
    private static function anotar(array &$problemas, int $fila, string $motivo, ?string $detalle, int &$total): void
    {
        $total++;
        if (count($problemas) < self::MAX_PROBLEMAS) {
            $problemas[] = ['fila' => $fila, 'motivo' => $motivo, 'detalle' => $detalle];
        }
    }
}
