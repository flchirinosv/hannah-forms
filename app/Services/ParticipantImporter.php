<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Models\FormParticipant;

final class ParticipantImporter
{
    public const MODO_FUSIONAR = 'fusionar';
    public const MODO_REEMPLAZAR = 'reemplazar';
    public const MODOS = [self::MODO_FUSIONAR, self::MODO_REEMPLAZAR];
    public const MAX_FILAS = 20000;
    private const MAX_PROBLEMAS = 200;
    private const ERROR_ATRIBUTO = 'atributo_no_creable';

    private static function db(): Database
    {
        return Database::getInstance(Config::db());
    }

    public static function importar(int $formId, string $ruta, string $identificador, string $modo, ?array $mapeo = null): array
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
        if (!in_array($identificador, ['cedula', 'correo', 'codigo'], true)) {
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
            $vacio['error'] = $e->getMessage();
            return $vacio;
        }

        $filas = $libro['filas'];
        if ($filas === []) {
            $vacio['error'] = 'roster_sin_filas';
            return $vacio;
        }
        if (count($filas) > self::MAX_FILAS) {
            $vacio['error'] = 'roster_demasiadas_filas';
            return $vacio;
        }

        $columnas = self::mapaColumnas($libro['cabeceras'], $mapeo, $identificador);
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
            $vacio['error'] = 'reemplazar_con_errores';
            $vacio['total'] = count($filas);
            $vacio['omitidos'] = (int) ($preparadas['omitidos'] ?? 0);
            $vacio['problemas'] = $preparadas['problemas'];
            return $vacio;
        }

        $fallo = null;
        $resumen = ['insertados' => 0, 'actualizados' => 0, 'sin_cambios' => 0];
        $asegurados = ['keys' => [], 'nuevos' => []];

        try {
            self::db()->transaccionar(function () use ($formId, $modo, $filas, $columnas, $preparadas, $identificador, &$resumen, &$asegurados): void {
                $asegurados = self::asegurarAtributos($formId, $columnas['atributos']);

                if ($modo === self::MODO_REEMPLAZAR) {
                    FormParticipant::truncate($formId);
                }

                $resumen = self::escribir($formId, $preparadas['filas'], $identificador, $asegurados['keys']);
            });
        } catch (\RuntimeException $e) {
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
            'ok' => true,
            'error' => null,
            'modo' => $modo,
            'total' => count($filas),
            'insertados' => $resumen['insertados'],
            'actualizados' => $resumen['actualizados'],
            'sin_cambios' => $resumen['sin_cambios'],
            'omitidos' => (int) ($preparadas['omitidos'] ?? 0),
            'atributos_nuevos' => $asegurados['nuevos'],
            'problemas' => $preparadas['problemas'],
        ];
    }

    private static function mapaColumnas(array $cabeceras, ?array $mapeo, string $identificador): array
    {
        $mapa = [];
        $atributos = [];
        $atributosCatalogo = [];

        $reservadasPorCabecera = [
            'cedula' => 'cedula',
            'documento' => 'cedula',
            'nombre' => 'nombre',
            'nombres' => 'nombre',
            'nombrecompleto' => 'nombre',
            'correo' => 'correo',
            'email' => 'correo',
            'telefono' => 'telefono',
            'telefonico' => 'telefono',
            'tel' => 'telefono',
            'movil' => 'telefono',
            'celular' => 'telefono',
            'codigo' => 'ignorar',
            'indicador' => 'ignorar',
        ];

        if (is_array($mapeo)) {
            $usados = [];
            if (isset($mapeo['ignorar']) && is_array($mapeo['ignorar'])) {
                foreach ($mapeo['ignorar'] as $idx) {
                    $i = (int) $idx;
                    $mapa[$i] = ['tipo' => 'ignorar'];
                    $usados[$i] = true;
                }
            }
            if (isset($mapeo['identificador']) && $mapeo['identificador'] !== null && $mapeo['identificador'] !== '') {
                $i = (int) $mapeo['identificador'];
                if (isset($usados[$i])) {
                    return ['error' => 'mapeo_duplicado', 'mapa' => [], 'atributos' => [], 'atributos_catalogo' => []];
                }
                $mapa[$i] = ['tipo' => 'fijo', 'columna' => FormParticipant::identificadorDeArchivo($identificador)];
                $usados[$i] = true;
            } else {
                return ['error' => 'mapeo_sin_identificador', 'mapa' => [], 'atributos' => [], 'atributos_catalogo' => []];
            }
            if (isset($mapeo['nombre']) && $mapeo['nombre'] !== null && $mapeo['nombre'] !== '') {
                $i = (int) $mapeo['nombre'];
                if (isset($usados[$i])) {
                    return ['error' => 'mapeo_duplicado', 'mapa' => [], 'atributos' => [], 'atributos_catalogo' => []];
                }
                $mapa[$i] = ['tipo' => 'fijo', 'columna' => 'nombre'];
                $usados[$i] = true;
            }
            if (isset($mapeo['correo']) && $mapeo['correo'] !== null && $mapeo['correo'] !== '') {
                $i = (int) $mapeo['correo'];
                if (isset($usados[$i])) {
                    return ['error' => 'mapeo_duplicado', 'mapa' => [], 'atributos' => [], 'atributos_catalogo' => []];
                }
                $mapa[$i] = ['tipo' => 'fijo', 'columna' => 'correo'];
                $usados[$i] = true;
            }
            if (isset($mapeo['telefono']) && $mapeo['telefono'] !== null && $mapeo['telefono'] !== '') {
                $i = (int) $mapeo['telefono'];
                if (isset($usados[$i])) {
                    return ['error' => 'mapeo_duplicado', 'mapa' => [], 'atributos' => [], 'atributos_catalogo' => []];
                }
                $mapa[$i] = ['tipo' => 'fijo', 'columna' => 'telefono'];
                $usados[$i] = true;
            }
            foreach ($cabeceras as $i => $cabecera) {
                if (isset($usados[$i])) {
                    continue;
                }
                $norm = RosterTemplate::normalizarCabecera((string) $cabecera);
                if (isset($reservadasPorCabecera[$norm])) {
                    $res = $reservadasPorCabecera[$norm];
                    if ($res === 'ignorar') {
                        $mapa[$i] = ['tipo' => 'ignorar'];
                        continue;
                    }
                    $mapa[$i] = ['tipo' => 'fijo', 'columna' => $res];
                    continue;
                }
                $mapa[$i] = ['tipo' => 'atributo', 'columna' => (string) $cabecera];
                $atributos[(string) $cabecera] = true;
                $atributosCatalogo[] = (string) $cabecera;
            }
            return ['error' => null, 'mapa' => $mapa, 'atributos' => array_keys($atributos), 'atributos_catalogo' => $atributosCatalogo];
        }

        foreach ($cabeceras as $i => $cabecera) {
            $norm = RosterTemplate::normalizarCabecera((string) $cabecera);
            $reservada = $reservadasPorCabecera[$norm] ?? null;
            if ($reservada === 'ignorar') {
                $mapa[$i] = ['tipo' => 'ignorar'];
                continue;
            }
            if ($reservada !== null) {
                $mapa[$i] = ['tipo' => 'fijo', 'columna' => $reservada];
                continue;
            }
            $mapa[$i] = ['tipo' => 'atributo', 'columna' => (string) $cabecera];
            $atributos[(string) $cabecera] = true;
            $atributosCatalogo[] = (string) $cabecera;
        }

        return ['error' => null, 'mapa' => $mapa, 'atributos' => array_keys($atributos), 'atributos_catalogo' => $atributosCatalogo];
    }

    private static function preparar(array $filas, array $mapa, string $identificador): array
    {
        $reservadas = [];
        foreach ($mapa as $entrada) {
            if (($entrada['tipo'] ?? null) === 'fijo') {
                $reservadas[$entrada['columna']] = true;
            }
        }

        $archivo = FormParticipant::identificadorDeArchivo($identificador);

        if ($archivo === 'cedula' && !isset($reservadas['cedula'])) {
            return ['error' => 'falta_columna_identificador', 'filas' => [], 'problemas' => [], 'omitidos' => 0, 'nuevos' => []];
        }
        if ($archivo === 'correo' && !isset($reservadas['correo'])) {
            return ['error' => 'falta_columna_identificador', 'filas' => [], 'problemas' => [], 'omitidos' => 0, 'nuevos' => []];
        }

        $buenas = [];
        $vistas = [];
        $problemas = [];
        $omitidos = 0;
        $nuevos = [];

        foreach ($filas as $n => $fila) {
            $numero = $n + 2;

            $campos = ['cedula' => '', 'nombre' => '', 'correo' => '', 'telefono' => ''];
            $bruto = [];
            foreach ($mapa as $i => $entrada) {
                $valor = (string) ($fila[$i] ?? '');
                $tipo = $entrada['tipo'] ?? '';
                if ($tipo === 'fijo') {
                    $col = $entrada['columna'] ?? '';
                    if (isset($campos[$col])) {
                        $campos[$col] = $valor;
                    } elseif ($col === 'cedula') {
                        $campos['cedula'] = $valor;
                    }
                } elseif ($tipo === 'atributo') {
                    $bruto[$entrada['columna']] = $valor;
                }
            }

            $identificadorValor = $archivo === 'correo'
                ? FormParticipant::normalizarCorreo($campos['correo'])
                : FormParticipant::normalizar($campos['cedula']);
            $motivo = FormParticipant::validarDatos($archivo, $identificadorValor, $campos['correo']);
            if ($motivo !== 'ok') {
                self::anotar($problemas, $numero, $motivo, null, $omitidos);
                continue;
            }

            $clave = $identificadorValor;
            if (isset($vistas[$clave])) {
                self::anotar($problemas, $numero, FormParticipant::MOTIVO_DUPLICADO, 'la fila ' . $vistas[$clave], $omitidos);
                continue;
            }
            $vistas[$clave] = $numero;

            $buenas[] = [
                'cedula' => $campos['cedula'],
                'nombre' => $campos['nombre'],
                'correo' => $campos['correo'],
                'telefono' => $campos['telefono'],
                'identificador' => $identificador,
                'archivo' => $archivo,
                'identificadorValor' => $identificadorValor,
                'data' => $bruto,
                'fila' => $numero,
            ];
        }

        return ['error' => null, 'filas' => $buenas, 'problemas' => $problemas, 'omitidos' => $omitidos, 'nuevos' => $nuevos];
    }

    private static function anotar(array &$problemas, int $fila, string $motivo, ?string $detalle, int &$omitidos): void
    {
        $omitidos++;
        if (count($problemas) < self::MAX_PROBLEMAS) {
            $problemas[] = ['fila' => $fila, 'motivo' => $motivo, 'detalle' => $detalle];
        }
    }

    private static function asegurarAtributos(int $formId, array $atributos): array
    {
        $antes = [];
        foreach (FormParticipant::catalogFor($formId) as $campo) {
            $antes[FormParticipant::normalizar((string) $campo['label'])] = true;
        }

        $catalogo = [];
        $nuevos = [];
        foreach ($atributos as $label) {
            $label = FormParticipant::normalizar((string) $label);
            $key = FormParticipant::ensureAttribute($formId, $label);
            if ($key === null) {
                throw new \RuntimeException(self::ERROR_ATRIBUTO);
            }
            $catalogo[$key] = true;
            if (!isset($antes[$label])) {
                $nuevos[] = $label;
                $antes[$label] = true;
            }
        }

        return ['keys' => $catalogo, 'nuevos' => $nuevos];
    }

    private static function escribir(int $formId, array $filas, string $identificador, array $catalogoKeys): array
    {
        $insertados = 0;
        $actualizados = 0;
        $sin_cambios = 0;

        $db = self::db();
        $catalogo = FormParticipant::catalogFor($formId);
        $keyMap = [];
        foreach ($catalogo as $c) {
            $keyMap[FormParticipant::normalizar((string) $c['label'])] = (string) $c['key_name'];
        }

        foreach ($filas as $fila) {
            $cedula = FormParticipant::normalizar((string) ($fila['cedula'] ?? ''));
            $nombre = FormParticipant::normalizar((string) ($fila['nombre'] ?? ''));
            $correo = FormParticipant::normalizarCorreo((string) ($fila['correo'] ?? ''));
            $telefono = FormParticipant::normalizar((string) ($fila['telefono'] ?? ''));
            $identificadorValor = (string) ($fila['identificadorValor'] ?? '');
            $archivoIdentificador = (string) ($fila['archivo'] ?? 'cedula');

            $existe = FormParticipant::findByIdentifier($formId, $archivoIdentificador, $identificadorValor);
            if ($existe !== null) {
                $cambio = false;
                if ((string) $existe['cedula'] !== $cedula) {
                    $cambio = true;
                }
                if ((string) $existe['nombre'] !== $nombre) {
                    $cambio = true;
                }
                if ((string) $existe['correo'] !== $correo) {
                    $cambio = true;
                }
                if ((string) $existe['telefono'] !== $telefono) {
                    $cambio = true;
                }

                $identidad = $identificador === 'codigo'
                    ? (string) ($existe['code'] ?? '')
                    : $identificadorValor;

                $db->execute(
                    'UPDATE form_participants SET cedula = :cedula, nombre = :nombre, correo = :correo, telefono = :telefono, identifier_value = :identifier_value WHERE id = :id AND form_id = :form_id',
                    ['cedula' => $cedula, 'nombre' => $nombre, 'correo' => $correo, 'telefono' => $telefono, 'identifier_value' => $identidad, 'id' => (int) $existe['id'], 'form_id' => $formId]
                );

                if (is_array($fila['data'] ?? null)) {
                    $dataMap = [];
                    foreach ($fila['data'] as $k => $v) {
                        $kl = FormParticipant::normalizar((string) $k);
                        if (isset($keyMap[$kl])) {
                            $dataMap[$keyMap[$kl]] = $v;
                        } elseif (isset($catalogoKeys[$kl]) || in_array(FormParticipant::slug($kl), $catalogoKeys, true)) {
                            $dataMap[FormParticipant::slug($kl)] = $v;
                        }
                    }
                    FormParticipant::guardarAtributos($formId, (int) $existe['id'], $dataMap);
                }

                if ($cambio) {
                    $actualizados++;
                } else {
                    $sin_cambios++;
                }
                continue;
            }

            $code = self::nuevoCodeDb();
            while ($db->fetch('SELECT id FROM form_participants WHERE code = :code', ['code' => $code]) !== null) {
                $code = self::nuevoCodeDb();
            }

            $identidad = $identificador === 'codigo' ? $code : $identificadorValor;
            $db->execute(
                'INSERT INTO form_participants (form_id, cedula, nombre, correo, telefono, identifier_value, code) VALUES (:form_id, :cedula, :nombre, :correo, :telefono, :identifier_value, :code)',
                ['form_id' => $formId, 'cedula' => $cedula, 'nombre' => $nombre, 'correo' => $correo, 'telefono' => $telefono, 'identifier_value' => $identidad, 'code' => $code]
            );
            $pid = $db->lastInsertId();

            if ($pid > 0 && is_array($fila['data'] ?? null)) {
                $dataMap = [];
                foreach ($fila['data'] as $k => $v) {
                    $kl = FormParticipant::normalizar((string) $k);
                    if (isset($keyMap[$kl])) {
                        $dataMap[$keyMap[$kl]] = $v;
                    }
                }
                if ($dataMap !== []) {
                    FormParticipant::guardarAtributos($formId, (int) $pid, $dataMap);
                }
            }

            $insertados++;
        }

        return ['insertados' => $insertados, 'actualizados' => $actualizados, 'sin_cambios' => $sin_cambios];
    }

    private static function nuevoCodeDb(): string
    {
        $alfabeto = '23456789ABCDEFGHJKMNPQRSTWXYZ';
        $code = '';
        $max = strlen($alfabeto) - 1;
        for ($i = 0; $i < 16; $i++) {
            $code .= $alfabeto[random_int(0, $max)];
        }
        return $code;
    }
}
