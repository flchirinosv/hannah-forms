<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Database;
use App\Services\FormSettings;
use App\Services\I18n;

final class FormParticipant
{
    /** Añade lo que no está y actualiza lo que ya está. */
    public const MODO_FUSIONAR = 'fusionar';

    /** Vacía la lista y la vuelve a construir. */
    public const MODO_REEMPLAZAR = 'reemplazar';

    public const MODOS = [self::MODO_FUSIONAR, self::MODO_REEMPLAZAR];

    /** El `identifier_value` ya está en otro participante del MISMO formulario. */
    public const MOTIVO_DUPLICADO = 'identificador_duplicado';

    /** La columna elegida como identificador vino vacía. */
    public const MOTIVO_VACIO = 'identificador_vacio';

    public const MOTIVO_LARGO = 'identificador_largo';

    /** Una `key_name` de `data` no está en el catálogo del formulario. */
    public const MOTIVO_ATRIBUTO = 'atributo_desconocido';

    /** Un valor de atributo pasa de `VARCHAR(255)`. */
    public const MOTIVO_VALOR_LARGO = 'valor_atributo_largo';

    public const MOTIVO_CORREO = 'correo_invalido';

    /** Columna de la tabla de la que sale cada identificador. */
    public const COLUMNA_IDENTIFICADOR = [
        'cedula' => 'cedula',
        'correo' => 'correo',
        'codigo' => 'code',
    ];

    /**
     * Identificador por correo electrónico, y el ÚNICO con el que hay invitación
     * por correo (fase 10).
     */
    public const IDENT_CORREO = 'correo';

    /** Tope de `identifier_value`, que es `VARCHAR(190)`. */
    public const MAX_IDENTIFICADOR = 190;

    /** Tope de un valor de atributo, que es `VARCHAR(255)`. */
    public const MAX_VALOR_ATRIBUTO = 255;

    /**
     * Alfabeto del `code`: sin `0/O`, `1/I/L` ni `U/V`.
     */
    private const ALFABETO = '23456789ABCDEFGHJKMNPQRSTWXYZ';

    private static function db(): Database
    {
        return Database::getInstance(Config::db());
    }

    // =====================================================================
    // Identificador
    // =====================================================================

    public static function identifierOf(array $settings): string
    {
        $id = (string) ($settings['access']['identifier'] ?? 'cedula');

        return in_array($id, ['cedula', 'correo', 'codigo'], true) ? $id : 'cedula';
    }

    public static function identifierFor(int $formId): string
    {
        return self::identifierOf(Form::settings($formId));
    }

    public static function identifierValue(string $identificador, string $cedula, string $correo): string
    {
        $crudo = match ($identificador) {
            'correo' => $correo,
            'codigo' => '',
            default  => $cedula,
        };
        $valor = self::normalizar($crudo);

        return $identificador === 'correo' ? mb_strtolower($valor) : $valor;
    }

    public static function identificadorDeArchivo(string $identificador): string
    {
        return $identificador === 'correo' ? 'correo' : 'cedula';
    }

    public static function normalizar(string $valor): string
    {
        $valor = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $valor) ?? $valor;
        $valor = preg_replace('/\s+/u', ' ', $valor) ?? $valor;

        return trim($valor);
    }

    public static function normalizarCorreo(string $correo): string
    {
        return mb_strtolower(self::normalizar($correo), 'UTF-8');
    }

    public static function codeValido(string $code): bool
    {
        return preg_match('/^[23456789ABCDEFGHJKMNPQRSTWXYZ]{16}$/', $code) === 1;
    }

    public static function normalizarCode(string $code): string
    {
        $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', strtoupper($code)) ?? '');

        return $code;
    }

    private static function nuevoCode(): string
    {
        $code = '';
        $max = strlen(self::ALFABETO) - 1;
        for ($i = 0; $i < 16; $i++) {
            $code .= self::ALFABETO[random_int(0, $max)];
        }

        return $code;
    }

    // =====================================================================
    // Consultas
    // =====================================================================

    public static function find(int $id): ?array
    {
        $row = self::db()->fetch('SELECT * FROM form_participants WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $row;
    }

    public static function findByIdentifier(int $formId, string $identificador, string $valor): ?array
    {
        $columna = self::COLUMNA_IDENTIFICADOR[$identificador] ?? null;
        if ($columna === null) {
            return null;
        }

        $valor = self::normalizar($valor);
        if ($identificador === 'correo') {
            $valor = mb_strtolower($valor, 'UTF-8');
        }

        $sql = "SELECT * FROM form_participants WHERE form_id = :form_id AND {$columna} = :valor";
        $row = self::db()->fetch($sql, ['form_id' => $formId, 'valor' => $valor]);

        return $row === null ? null : $row;
    }

    public static function findByCode(string $code): ?array
    {
        $code = self::normalizarCode($code);
        if (!self::codeValido($code)) {
            return null;
        }

        $row = self::db()->fetch('SELECT * FROM form_participants WHERE code = :code', ['code' => $code]);

        return $row === null ? null : $row;
    }

    public static function findByCodeInForm(int $formId, string $code): ?array
    {
        $code = self::normalizarCode($code);
        if (!self::codeValido($code)) {
            return null;
        }

        $row = self::db()->fetch('SELECT * FROM form_participants WHERE form_id = :form_id AND code = :code', ['form_id' => $formId, 'code' => $code]);

        return $row === null ? null : $row;
    }

    public static function findByIdentifierValue(int $formId, string $identificador, string $valor): ?array
    {
        $valor = self::normalizar($valor);
        if ($identificador === 'correo') {
            $valor = mb_strtolower($valor, 'UTF-8');
        }
        $row = self::db()->fetch(
            'SELECT * FROM form_participants WHERE form_id = :form_id AND identifier_value = :valor',
            ['form_id' => $formId, 'valor' => $valor]
        );

        return $row === null ? null : $row;
    }

    public static function existeEnFormulario(int $formId, int $participantId): bool
    {
        $row = self::db()->fetch(
            'SELECT id FROM form_participants WHERE id = :id AND form_id = :form_id',
            ['id' => $participantId, 'form_id' => $formId]
        );

        return $row !== null;
    }

    public static function countForForm(int $formId, string $q = ''): int
    {
        $q = trim($q);
        if ($q === '') {
            $row = self::db()->fetch('SELECT COUNT(*) AS c FROM form_participants WHERE form_id = :form_id', ['form_id' => $formId]);
            return (int) ($row['c'] ?? 0);
        }

        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $row = self::db()->fetch(
            "SELECT COUNT(*) AS c FROM form_participants WHERE form_id = :form_id AND (cedula LIKE :q1 ESCAPE '\\\\' OR nombre LIKE :q2 ESCAPE '\\\\' OR correo LIKE :q3 ESCAPE '\\\\' OR telefono LIKE :q4 ESCAPE '\\\\')",
            ['form_id' => $formId, 'q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like]
        );

        return (int) ($row['c'] ?? 0);
    }

    public static function allForForm(int $formId, int $limit = 50, int $offset = 0, string $q = '', string $sort = 'cedula'): array
    {
        $q = trim($q);
        $sort = in_array($sort, ['cedula', 'nombre', 'correo', 'telefono', 'creado'], true) ? $sort : 'cedula';
        $order = $sort === 'creado' ? 'created_at DESC, id DESC' : $sort . ' ASC, id ASC';

        $params = ['form_id' => $formId];
        $where = 'WHERE form_id = :form_id';
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $where .= " AND (cedula LIKE :q1 ESCAPE '\\\\' OR nombre LIKE :q2 ESCAPE '\\\\' OR correo LIKE :q3 ESCAPE '\\\\' OR telefono LIKE :q4 ESCAPE '\\\\')";
            $params['q1'] = $like;
            $params['q2'] = $like;
            $params['q3'] = $like;
            $params['q4'] = $like;
        }

        $sql = "SELECT * FROM form_participants {$where} ORDER BY {$order} LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset;
        $rows = self::db()->fetchAll($sql, $params);

        return is_array($rows) ? $rows : [];
    }

    public static function countForFormMode(int $formId): int
    {
        $row = self::db()->fetch('SELECT COUNT(*) AS c FROM form_participants WHERE form_id = :form_id', ['form_id' => $formId]);
        return (int) ($row['c'] ?? 0);
    }

    public static function respondedCountForForm(int $formId): int
    {
        $row = self::db()->fetch('SELECT COUNT(*) AS c FROM form_participants WHERE form_id = :form_id AND responded_at IS NOT NULL', ['form_id' => $formId]);
        return (int) ($row['c'] ?? 0);
    }

    public static function invitedCountForForm(int $formId): int
    {
        $row = self::db()->fetch('SELECT COUNT(*) AS c FROM form_participants WHERE form_id = :form_id AND invited_at IS NOT NULL', ['form_id' => $formId]);
        return (int) ($row['c'] ?? 0);
    }

    public static function pendingInviteCount(int $formId): int
    {
        $row = self::db()->fetch("SELECT COUNT(*) AS c FROM form_participants WHERE form_id = :form_id AND correo <> '' AND invited_at IS NULL AND responded_at IS NULL", ['form_id' => $formId]);
        return (int) ($row['c'] ?? 0);
    }

    public static function pendingReminderCount(int $formId): int
    {
        $row = self::db()->fetch("SELECT COUNT(*) AS c FROM form_participants WHERE form_id = :form_id AND correo <> '' AND invited_at IS NOT NULL AND responded_at IS NULL", ['form_id' => $formId]);
        return (int) ($row['c'] ?? 0);
    }

    public static function pendingInvites(int $formId, int $limite): array
    {
        $rows = self::db()->fetchAll(
            "SELECT * FROM form_participants WHERE form_id = :form_id AND correo <> '' AND invited_at IS NULL AND responded_at IS NULL ORDER BY id ASC LIMIT " . (int) $limite,
            ['form_id' => $formId]
        );
        return is_array($rows) ? $rows : [];
    }

    public static function pendingReminders(int $formId, int $limite): array
    {
        $rows = self::db()->fetchAll(
            "SELECT * FROM form_participants WHERE form_id = :form_id AND correo <> '' AND invited_at IS NOT NULL AND responded_at IS NULL ORDER BY reminded_at IS NULL DESC, reminded_at ASC, id ASC LIMIT " . (int) $limite,
            ['form_id' => $formId]
        );
        return is_array($rows) ? $rows : [];
    }

    public static function markInvited(int $id): void
    {
        self::db()->execute('UPDATE form_participants SET invited_at = NOW() WHERE id = :id', ['id' => $id]);
    }

    public static function markReminded(int $id): void
    {
        self::db()->execute('UPDATE form_participants SET reminded_at = NOW(), reminder_count = reminder_count + 1 WHERE id = :id', ['id' => $id]);
    }

    public static function marcarRespondido(int $id): void
    {
        self::db()->execute('UPDATE form_participants SET responded_at = NOW() WHERE id = :id AND responded_at IS NULL', ['id' => $id]);
    }

    public static function desmarcarSiSinRespuestas(int $participantId): void
    {
        if ($participantId <= 0) {
            return;
        }

        $row = self::db()->fetch(
            'SELECT COUNT(*) AS c FROM submissions WHERE participant_id = :pid',
            ['pid' => $participantId]
        );

        $count = (int) ($row['c'] ?? 0);
        if ($count === 0) {
            self::db()->execute('UPDATE form_participants SET responded_at = NULL WHERE id = :id', ['id' => $participantId]);
        }
    }

    public static function create(int $formId, $cedula = '', $nombre = '', $correo = '', $telefono = '', $data = [], string $identificador = 'cedula'): array
    {
        // Compatibilidad con API antigua: create($formId, $identificador, $datos)
        if (is_string($cedula) && in_array($cedula, ['cedula', 'correo', 'codigo'], true) && is_array($nombre)) {
            $identificadorOld = $cedula;
            $datos = $nombre;
            // El 4.º/5.º argumento puede traer los atributos del catálogo
            // (p. ej. create($formId, 'cedula', [...], [$key => 'valor'])).
            $dataEntrante = [];
            if (is_array($correo)) {
                $dataEntrante = $correo;
            } elseif (is_array($telefono)) {
                $dataEntrante = $telefono;
            } elseif (is_array($data)) {
                $dataEntrante = $data;
            }
            $cedula = (string) ($datos['cedula'] ?? $datos['identificador'] ?? ($dataEntrante['cedula'] ?? ''));
            $nombre = (string) ($datos['nombre'] ?? ($dataEntrante['nombre'] ?? ''));
            $correo = (string) ($datos['correo'] ?? $datos['email'] ?? ($dataEntrante['correo'] ?? ''));
            $telefono = (string) ($datos['telefono'] ?? ($dataEntrante['telefono'] ?? ''));
            $data = $dataEntrante;
            $identificador = $identificadorOld;
        }

        if (is_array($nombre)) {
            $datos = $nombre;
            $nombre = (string) ($datos['nombre'] ?? '');
            $correo = (string) ($datos['correo'] ?? $datos['email'] ?? '');
            $telefono = (string) ($datos['telefono'] ?? '');
            if (is_array($correo)) {
                $data = $correo;
                $correo = '';
            } elseif (is_array($telefono)) {
                $data = $telefono;
                $telefono = '';
            }
            if (isset($datos['cedula'])) { $cedula = (string) $datos['cedula']; }
            if (isset($datos['correo'])) { $correo = (string) $datos['correo']; }
            if (isset($datos['telefono'])) { $telefono = (string) $datos['telefono']; }
        }

        if (is_array($correo)) {
            $data = $correo;
            $correo = '';
        }
        if (is_array($telefono)) {
            $data = $telefono;
            $telefono = '';
        }
        if (!is_array($data)) {
            $data = [];
        }

        $cedula = self::normalizar((string) $cedula);
        $nombre = self::normalizar((string) $nombre);
        $correo = self::normalizarCorreo((string) $correo);
        $telefono = self::normalizar((string) $telefono);

        if (!isset(self::COLUMNA_IDENTIFICADOR[$identificador])) {
            $identificador = 'cedula';
        }

        $code = null;
        if ($identificador === 'codigo') {
            $code = self::nuevoCode();
            while (self::db()->fetch('SELECT id FROM form_participants WHERE code = :code', ['code' => $code]) !== null) {
                $code = self::nuevoCode();
            }
            $identificadorValor = $code;
        } else {
            $identificadorValor = self::identifierValue($identificador, $cedula, $correo);
        }

        $motivo = self::validarDatos($identificador, $identificadorValor, $correo);
        if ($motivo !== 'ok') {
            return ['ok' => false, 'motivo' => $motivo];
        }

        $existe = self::findByIdentifierValue($formId, $identificador, $identificadorValor);
        if ($existe !== null) {
            return ['ok' => false, 'motivo' => self::MOTIVO_DUPLICADO, 'id' => (int) $existe['id']];
        }

        $motivoAtributos = self::validarAtributos($formId, $data);
        if ($motivoAtributos !== 'ok') {
            return ['ok' => false, 'motivo' => $motivoAtributos];
        }

        if ($code === null) {
            $code = self::nuevoCode();
            while (self::db()->fetch('SELECT id FROM form_participants WHERE code = :code', ['code' => $code]) !== null) {
                $code = self::nuevoCode();
            }
        }

        $db = self::db();
        $db->execute(
            'INSERT INTO form_participants (form_id, cedula, nombre, correo, telefono, identifier_value, code) VALUES (:form_id, :cedula, :nombre, :correo, :telefono, :identifier_value, :code)',
            [
                'form_id' => $formId,
                'cedula' => $cedula,
                'nombre' => $nombre,
                'correo' => $correo,
                'telefono' => $telefono,
                'identifier_value' => $identificadorValor,
                'code' => $code,
            ]
        );
        $id = $db->lastInsertId();

        if ($id > 0 && is_array($data) && $data !== []) {
            self::guardarAtributos($formId, (int) $id, $data);
        }

        return ['ok' => true, 'id' => (int) $id, 'motivo' => 'ok'];
    }

    public static function update($id, $formId = null, $cedula = '', $nombre = '', $correo = '', $telefono = '', $data = [], string $identificador = 'cedula'): array
    {
        $fila = self::find((int) $id);
        if ($fila === null) {
            return ['ok' => false, 'motivo' => 'no_encontrado'];
        }

        // Compatibilidad con la API antigua:
        //   update($id, $identificador, $campos[, $data])
        //   update($id, $formId, $identificador, $campos)
        // Los campos que no vienen se conservan de la fila actual, y sin el
        // cuarto argumento los atributos tampoco se tocan.
        $escribirAtributos = true;
        $datosCompat = null;
        if (is_string($formId) && in_array($formId, ['cedula', 'correo', 'codigo'], true) && is_array($cedula)) {
            $identificador = $formId;
            $datosCompat = $cedula;
            $formId = (int) $fila['form_id'];
            $data = is_array($nombre) ? $nombre : null;
            $escribirAtributos = is_array($nombre);
        } elseif (is_string($cedula) && in_array($cedula, ['cedula', 'correo', 'codigo'], true) && is_array($nombre)) {
            $identificador = $cedula;
            $datosCompat = $nombre;
            $data = null;
            $escribirAtributos = false;
        }

        if (is_array($datosCompat)) {
            $cedula = (string) ($datosCompat['cedula'] ?? $datosCompat['identificador'] ?? $fila['cedula'] ?? '');
            $nombre = (string) ($datosCompat['nombre'] ?? $fila['nombre'] ?? '');
            $correo = (string) ($datosCompat['correo'] ?? $datosCompat['email'] ?? $fila['correo'] ?? '');
            $telefono = (string) ($datosCompat['telefono'] ?? $fila['telefono'] ?? '');
        }

        if (is_array($nombre)) {
            $datos = $nombre;
            $nombre = (string) ($datos['nombre'] ?? '');
            $correo = (string) ($datos['correo'] ?? $datos['email'] ?? '');
            $telefono = (string) ($datos['telefono'] ?? '');
            if (is_array($correo)) { $data = $correo; $correo = ''; }
            if (is_array($telefono)) { $data = $telefono; $telefono = ''; }
            if (isset($datos['cedula'])) { $cedula = (string) $datos['cedula']; }
        }
        if (is_array($correo)) { $data = $correo; $correo = ''; }
        if (is_array($telefono)) { $data = $telefono; $telefono = ''; }
        if (!is_array($data)) { $data = []; }

        if ((int) $fila['form_id'] !== (int) $formId) {
            return ['ok' => false, 'motivo' => 'no_encontrado'];
        }

        $cedula = self::normalizar((string) $cedula);
        $nombre = self::normalizar((string) $nombre);
        $correo = self::normalizarCorreo((string) $correo);
        $telefono = self::normalizar((string) $telefono);

        if (!isset(self::COLUMNA_IDENTIFICADOR[$identificador])) {
            $identificador = 'cedula';
        }

        if ($identificador === 'codigo') {
            $identificadorValor = (string) ($fila['code'] ?? '');
        } else {
            $identificadorValor = self::identifierValue($identificador, $cedula, $correo);
        }
        $motivo = self::validarDatos($identificador, $identificadorValor, $correo);
        if ($motivo !== 'ok') {
            return ['ok' => false, 'motivo' => $motivo];
        }

        $existe = self::findByIdentifierValue((int) $formId, $identificador, $identificadorValor);
        if ($existe !== null && (int) $existe['id'] !== (int) $id) {
            return ['ok' => false, 'motivo' => self::MOTIVO_DUPLICADO, 'id' => (int) $existe['id']];
        }

        $motivoAtributos = self::validarAtributos((int) $formId, $data);
        if ($motivoAtributos !== 'ok') {
            return ['ok' => false, 'motivo' => $motivoAtributos];
        }

        $cambioCorreo = $correo !== (string) ($fila['correo'] ?? '');
        self::db()->execute(
            'UPDATE form_participants SET cedula = :cedula, nombre = :nombre, correo = :correo, telefono = :telefono, identifier_value = :identifier_value WHERE id = :id AND form_id = :form_id',
            [
                'cedula' => $cedula,
                'nombre' => $nombre,
                'correo' => $correo,
                'telefono' => $telefono,
                'identifier_value' => $identificadorValor,
                'id' => (int) $id,
                'form_id' => (int) $formId,
            ]
        );

        if ($cambioCorreo) {
            self::db()->execute('UPDATE form_participants SET invited_at = NULL WHERE id = :id', ['id' => (int) $id]);
        }

        if ($escribirAtributos) {
            self::guardarAtributos((int) $formId, (int) $id, $data);
        }

        return ['ok' => true, 'id' => (int) $id, 'motivo' => 'ok'];
    }

    public static function delete(int $id, ?int $formId = null): bool
    {
        $fila = self::find($id);
        if ($fila === null) {
            return false;
        }
        if ($formId !== null && (int) $fila['form_id'] !== $formId) {
            return false;
        }
        self::db()->execute('DELETE FROM form_participants WHERE id = :id', ['id' => $id]);
        return true;
    }

    public static function truncate(int $formId): int
    {
        $antes = self::db()->fetch('SELECT COUNT(*) AS c FROM form_participants WHERE form_id = :form_id', ['form_id' => $formId]);
        self::db()->execute('DELETE FROM form_participants WHERE form_id = :form_id', ['form_id' => $formId]);
        return (int) ($antes['c'] ?? 0);
    }

    public static function validarDatos(string $identificador, string $identificadorValor, string $correo): string
    {
        if (!isset(self::COLUMNA_IDENTIFICADOR[$identificador])) {
            return 'identificador_invalido';
        }
        if ($identificadorValor === '') {
            return self::MOTIVO_VACIO;
        }
        if (mb_strlen($identificadorValor, 'UTF-8') > self::MAX_IDENTIFICADOR) {
            return self::MOTIVO_LARGO;
        }
        if ($correo !== '') {
            $correo = self::normalizarCorreo($correo);
            if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                return self::MOTIVO_CORREO;
            }
        }

        return 'ok';
    }

    // =====================================================================
    // Atributos
    // =====================================================================

    public static function catalogFor(int $formId): array
    {
        $rows = self::db()->fetchAll('SELECT * FROM form_participant_fields WHERE form_id = :form_id ORDER BY position ASC, id ASC', ['form_id' => $formId]);
        return is_array($rows) ? $rows : [];
    }

    public static function ensureAttribute(int $formId, string $label): ?string
    {
        $label = self::normalizar($label);
        if ($label === '') {
            return null;
        }
        if (mb_strlen($label, 'UTF-8') > 190) {
            throw new \RuntimeException('atributo_no_creable');
        }

        $existe = self::db()->fetch(
            'SELECT * FROM form_participant_fields WHERE form_id = :form_id AND label = :label',
            ['form_id' => $formId, 'label' => $label]
        );
        if ($existe !== null) {
            return (string) ($existe['key_name'] ?? '') ?: null;
        }

        $base = self::slug($label);
        $key = $base;
        $usadas = [];
        foreach (self::catalogFor($formId) as $fila) {
            $usadas[(string) $fila['key_name']] = (string) $fila['label'];
        }
        $n = 1;
        while (isset($usadas[$key])) {
            $n++;
            $sufijo = '_' . $n;
            $key = mb_substr($base, 0, 64 - strlen($sufijo), 'UTF-8') . $sufijo;
        }
        if (mb_strlen($key, 'UTF-8') > 64) {
            throw new \RuntimeException('atributo_no_creable');
        }

        $max = self::db()->fetch('SELECT COALESCE(MAX(position), -1) AS m FROM form_participant_fields WHERE form_id = :form_id', ['form_id' => $formId]);
        $pos = (int) ($max['m'] ?? -1) + 1;

        try {
            self::db()->execute(
                'INSERT INTO form_participant_fields (form_id, key_name, label, position) VALUES (:form_id, :key_name, :label, :position)',
                ['form_id' => $formId, 'key_name' => $key, 'label' => $label, 'position' => $pos]
            );
        } catch (\Throwable $e) {
            $existe = self::db()->fetch(
                'SELECT * FROM form_participant_fields WHERE form_id = :form_id AND (key_name = :key OR label = :label)',
                ['form_id' => $formId, 'key' => $key, 'label' => $label]
            );
            if ($existe !== null) {
                return (string) ($existe['key_name'] ?? '') ?: null;
            }
            throw new \RuntimeException('atributo_no_creable', 0, $e);
        }

        return $key;
    }

    public static function deleteAttribute(int $formId, int|string $field): bool
    {
        if (is_int($field) || ctype_digit((string) $field)) {
            $fila = self::db()->fetch(
                'SELECT id, key_name FROM form_participant_fields WHERE id = :id AND form_id = :form_id',
                ['id' => (int) $field, 'form_id' => $formId]
            );
        } else {
            $fila = self::db()->fetch(
                'SELECT id, key_name FROM form_participant_fields WHERE key_name = :key AND form_id = :form_id',
                ['key' => (string) $field, 'form_id' => $formId]
            );
        }
        if ($fila === null) {
            return false;
        }

        $key = (string) $fila['key_name'];
        self::db()->transaccionar(function () use ($formId, $fila, $key): void {
            self::db()->execute(
                'DELETE FROM form_participant_attrs WHERE form_id = :form_id AND key_name = :key',
                ['form_id' => $formId, 'key' => $key]
            );
            self::db()->execute(
                'DELETE FROM form_participant_fields WHERE id = :id AND form_id = :form_id',
                ['id' => (int) $fila['id'], 'form_id' => $formId]
            );
        });

        return true;
    }

    public static function sinIdentificadorPara(int $formId, string $identificador): int
    {
        $columna = self::COLUMNA_IDENTIFICADOR[$identificador] ?? null;
        if ($columna === null) {
            return 0;
        }
        $row = self::db()->fetch(
            "SELECT COUNT(*) AS c FROM form_participants WHERE form_id = :form_id AND TRIM({$columna}) = ''",
            ['form_id' => $formId]
        );

        return (int) ($row['c'] ?? 0);
    }

    /** Expresión SQL del valor con el que se identificaría cada fila. */
    private static function expresionIdentificador(string $identificador): ?string
    {
        return match ($identificador) {
            'correo' => "LOWER(TRIM(correo))",
            'cedula' => 'TRIM(cedula)',
            'codigo' => 'TRIM(code)',
            default  => null,
        };
    }

    /**
     * Qué pasaría al cambiar el identificador, sin escribir nada.
     *
     * Se cuenta en SQL y no agrupando en PHP porque el UNIQUE de la puerta usa
     * la colación de la tabla: «V-1» y «v-1» son la misma identidad para el
     * índice aunque no lo sean para un array asociativo.
     *
     * @return array{sin_identidad:int,a_cambiar:int,repetidos_cuento:int,repetidos:list<string>}
     */
    public static function planDeReidentificacion(int $formId, string $identificador): array
    {
        $expr = self::expresionIdentificador($identificador);
        if ($expr === null) {
            return ['sin_identidad' => 0, 'a_cambiar' => 0, 'repetidos_cuento' => 0, 'repetidos' => []];
        }

        $sin = self::db()->fetch(
            "SELECT COUNT(*) AS c FROM form_participants WHERE form_id = :form_id AND TRIM({$expr}) = ''",
            ['form_id' => $formId]
        );
        $cambiar = self::db()->fetch(
            "SELECT COUNT(*) AS c FROM form_participants WHERE form_id = :form_id AND TRIM({$expr}) <> '' AND identifier_value <> {$expr}",
            ['form_id' => $formId]
        );
        $grupos = self::db()->fetchAll(
            "SELECT COUNT(*) AS c, {$expr} AS v FROM form_participants WHERE form_id = :form_id AND TRIM({$expr}) <> '' GROUP BY v HAVING COUNT(*) > 1 ORDER BY v ASC",
            ['form_id' => $formId]
        );

        $repetidos = [];
        $cuenta = 0;
        foreach ($grupos as $grupo) {
            $cuenta += (int) ($grupo['c'] ?? 0);
            if (count($repetidos) < 5) {
                $repetidos[] = (string) ($grupo['v'] ?? '');
            }
        }

        return [
            'sin_identidad' => (int) ($sin['c'] ?? 0),
            'a_cambiar' => (int) ($cambiar['c'] ?? 0),
            'repetidos_cuento' => $cuenta,
            'repetidos' => $repetidos,
        ];
    }

    /**
     * Reescribe SOLO `identifier_value` con el identificador nuevo.
     *
     * El `code` no se toca: es la credencial que ya se repartió y un enlace
     * viejo tiene que seguir entrando. Devuelve cuántas filas cambiaron.
     */
    public static function reidentificar(int $formId, string $identificador): int
    {
        $expr = self::expresionIdentificador($identificador);
        if ($expr === null) {
            return 0;
        }

        return self::db()->execute(
            "UPDATE form_participants SET identifier_value = {$expr} WHERE form_id = :form_id AND TRIM({$expr}) <> '' AND identifier_value <> {$expr}",
            ['form_id' => $formId]
        );
    }

    private static function validarAtributos(int $formId, array $data): string
    {
        if ($data === []) {
            return 'ok';
        }
        $catalogo = [];
        foreach (self::catalogFor($formId) as $campo) {
            $catalogo[(string) $campo['key_name']] = true;
        }
        foreach ($data as $key => $value) {
            $key = (string) $key;
            if (!isset($catalogo[$key])) {
                return self::MOTIVO_ATRIBUTO;
            }
            if (mb_strlen(trim((string) $value), 'UTF-8') > self::MAX_VALOR_ATRIBUTO) {
                return self::MOTIVO_VALOR_LARGO;
            }
        }

        return 'ok';
    }

    public static function attributesOf(int $participantId): array
    {
        $rows = self::db()->fetchAll('SELECT key_name, value FROM form_participant_attrs WHERE participant_id = :id', ['id' => $participantId]);
        $out = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $out[(string) $r['key_name']] = (string) $r['value'];
            }
        }
        return $out;
    }

    public static function valuesOf(int $formId, string $keyName): array
    {
        $keyName = trim($keyName);
        if ($formId <= 0 || $keyName === '') {
            return [];
        }

        $rows = self::db()->fetchAll(
            'SELECT value, COUNT(*) AS c FROM form_participant_attrs
             WHERE form_id = :form_id AND key_name = :key_name AND value <> \'\'
             GROUP BY value ORDER BY c DESC, value ASC LIMIT 200',
            ['form_id' => $formId, 'key_name' => $keyName]
        );

        $out = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $out[] = (string) ($row['value'] ?? '');
            }
        }

        return $out;
    }

    public static function valoresConConteo(int $formId, string $keyName): array
    {
        $keyName = trim($keyName);
        if ($formId <= 0 || $keyName === '') {
            return ['valores' => [], 'total' => 0];
        }

        $rows = self::db()->fetchAll(
            'SELECT value, COUNT(*) AS c FROM form_participant_attrs
             WHERE form_id = :form_id AND key_name = :key_name AND value <> \'\'
             GROUP BY value ORDER BY c DESC, value ASC',
            ['form_id' => $formId, 'key_name' => $keyName]
        );

        $valores = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $valores[(string) ($row['value'] ?? '')] = (int) ($row['c'] ?? 0);
            }
        }

        $total = self::db()->fetch(
            'SELECT COUNT(*) AS c FROM form_participants WHERE form_id = :form_id',
            ['form_id' => $formId]
        );

        return ['valores' => $valores, 'total' => (int) ($total['c'] ?? 0)];
    }

    public static function atributosDe(array $pids): array
    {
        $pids = array_values(array_filter(array_map('intval', $pids), static fn ($v) => $v > 0));
        if ($pids === []) {
            return [];
        }
        $in = [];
        $params = [];
        foreach ($pids as $i => $pid) {
            $in[] = ':p' . $i;
            $params['p' . $i] = $pid;
        }
        $rows = self::db()->fetchAll('SELECT participant_id, key_name, value FROM form_participant_attrs WHERE participant_id IN (' . implode(',', $in) . ')', $params);
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $pid = (int) ($r['participant_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            if (!isset($out[$pid])) {
                $out[$pid] = [];
            }
            $out[$pid][(string) ($r['key_name'] ?? '')] = (string) ($r['value'] ?? '');
        }
        return $out;
    }

    public static function conAtributos(array $filas, array $atributos): array
    {
        $ids = [];
        foreach ($filas as $f) {
            $id = (int) ($f['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return $filas;
        }

        $placeholders = [];
        $params = [];
        foreach ($ids as $i => $id) {
            $placeholders[] = ':p' . $i;
            $params['p' . $i] = $id;
        }

        $rows = self::db()->fetchAll('SELECT participant_id, key_name, value FROM form_participant_attrs WHERE participant_id IN (' . implode(',', $placeholders) . ')', $params);
        $map = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $pid = (int) $r['participant_id'];
                $map[$pid][(string) $r['key_name']] = (string) $r['value'];
            }
        }

        $out = [];
        foreach ($filas as $f) {
            $pid = (int) ($f['id'] ?? 0);
            $f['data'] = $map[$pid] ?? [];
            $out[] = $f;
        }

        return $out;
    }

    public static function guardarAtributos(int $formId, int $participantId, array $data): void
    {
        $catalogo = [];
        foreach (self::catalogFor($formId) as $c) {
            $catalogo[(string) $c['key_name']] = true;
        }

        $db = self::db();
        foreach ($data as $key => $value) {
            $key = (string) $key;
            if (!isset($catalogo[$key])) {
                continue;
            }
            $value = trim((string) $value);
            if ($value === '') {
                $db->execute('DELETE FROM form_participant_attrs WHERE participant_id = :pid AND key_name = :key', ['pid' => $participantId, 'key' => $key]);
                continue;
            }
            if (mb_strlen($value, 'UTF-8') > self::MAX_VALOR_ATRIBUTO) {
                continue;
            }
            $db->execute(
                'INSERT INTO form_participant_attrs (form_id, participant_id, key_name, value) VALUES (:form_id, :pid, :key, :value)
                 ON DUPLICATE KEY UPDATE value = VALUES(value)',
                ['form_id' => $formId, 'pid' => $participantId, 'key' => $key, 'value' => $value]
            );
        }
    }

    public static function slug(string $texto): string
    {
        $s = mb_strtolower(trim($texto), 'UTF-8');
        $s = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if (!is_string($s)) {
            $s = mb_strtolower(trim($texto), 'UTF-8');
        }
        $s = preg_replace('/[^a-z0-9]+/', '_', $s) ?? $s;
        $s = trim($s, '_');
        if ($s === '') {
            return 'attr';
        }
        if (preg_match('/^[0-9]/', $s) === 1) {
            $s = '_' . $s;
        }

        return $s;
    }

    /**
     * Resuelve identidades de varios participantes del MISMO formulario.
     * Se filtra por form_id en el WHERE para evitar fuga entre formularios.
     */
    public static function identidadesDe(int $formId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $pids = [];
        foreach ($ids as $id) {
            if ($id === null || $id === '') {
                continue;
            }
            $pid = (int) $id;
            if ($pid <= 0) {
                continue;
            }
            $pids[$pid] = true;
        }
        if ($pids === []) {
            return [];
        }
        $pids = array_keys($pids);
        $placeholders = [];
        $params = ['form_id' => $formId];
        foreach ($pids as $i => $pid) {
            $k = 'p' . $i;
            $placeholders[] = ':' . $k;
            $params[$k] = (int) $pid;
        }
        $rows = self::db()->fetchAll(
            'SELECT id, identifier_value, nombre, correo FROM form_participants WHERE form_id = :form_id AND id IN (' . implode(',', $placeholders) . ')',
            $params
        );
        $out = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $pid = (int) ($r['id'] ?? 0);
                if ($pid <= 0) {
                    continue;
                }
                $out[$pid] = [
                    'identifier_value' => (string) ($r['identifier_value'] ?? ''),
                    'nombre' => (string) ($r['nombre'] ?? ''),
                    'correo' => (string) ($r['correo'] ?? ''),
                ];
            }
        }
        return $out;
    }

    /**
     * Datos para pintar la celda "quién respondió".
     * Con identificador = correo, el correo ya va en el principal así que no se repite.
     */
    public static function identidadDe(array $fila, string $identificador): array
    {
        $principal = (string) ($fila['identifier_value'] ?? '');
        $nombre = trim((string) ($fila['nombre'] ?? ''));
        $correo = trim((string) ($fila['correo'] ?? ''));
        if ($identificador === 'correo') {
            $correo = '';
        }
        return [
            'principal' => $principal,
            'nombre' => $nombre,
            'correo' => $correo,
        ];
    }

    /**
     * Resuelve el filtro por atributo para analítica. Solo permite atributos del catálogo
     * (no filtros inválidos). Devuelve ['key' => ..., 'value' => ...] o null.
     */
    public static function filtroAtributo(int $formId, string $key, string $value): ?array
    {
        $key = trim($key);
        $value = trim($value);
        if ($key === '' || $value === '') {
            return null;
        }
        if (isset(self::COLUMNA_IDENTIFICADOR[$key]) || in_array($key, ['cedula', 'nombre', 'correo'], true)) {
            return null;
        }
        if (self::atributoDeCatalogo($formId, $key) === null) {
            return null;
        }
        return ['form_id' => $formId, 'key' => $key, 'value' => $value];
    }

    public static function atributoDeCatalogo(int $formId, string $key): ?array
    {
        $key = trim($key);
        if ($key === '') {
            return null;
        }
        if (isset(self::COLUMNA_IDENTIFICADOR[$key]) || in_array($key, ['cedula', 'nombre', 'correo'], true)) {
            return null;
        }
        foreach (self::catalogFor($formId) as $c) {
            if ((string) ($c['key_name'] ?? '') === $key) {
                return is_array($c) ? $c : null;
            }
        }
        return null;
    }
}
