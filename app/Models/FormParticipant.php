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
        return $identificador === 'codigo' ? 'cedula' : $identificador;
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
            "SELECT COUNT(*) AS c FROM form_participants WHERE form_id = :form_id AND (cedula LIKE :q ESCAPE '\\\\' OR nombre LIKE :q ESCAPE '\\\\' OR correo LIKE :q ESCAPE '\\\\' OR telefono LIKE :q ESCAPE '\\\\')",
            ['form_id' => $formId, 'q' => $like]
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
            $where .= " AND (cedula LIKE :q ESCAPE '\\\\' OR nombre LIKE :q ESCAPE '\\\\' OR correo LIKE :q ESCAPE '\\\\' OR telefono LIKE :q ESCAPE '\\\\')";
            $params['q'] = $like;
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

    public static function create(int $formId, $cedula = '', $nombre = '', $correo = '', $telefono = '', $data = [], string $identificador = 'cedula'): array
    {
        // Compatibilidad con API antigua: create($formId, $identificador, $datos)
        if (is_string($cedula) && in_array($cedula, ['cedula', 'correo', 'codigo'], true) && is_array($nombre)) {
            $identificadorOld = $cedula;
            $datos = $nombre;
            $cedula = (string) ($datos['cedula'] ?? $datos['identificador'] ?? $datos['indicador'] ?? '');
            $nombre = (string) ($datos['nombre'] ?? '');
            $correo = (string) ($datos['correo'] ?? $datos['email'] ?? '');
            $telefono = (string) ($datos['telefono'] ?? '');
            $data = is_array($correo) ? $correo : (is_array($telefono) ? $telefono : (is_array($data) && !is_string($data) ? $data : []));
            // si $correo era array (3 args antiguos con array como 3er)
            if (is_array($correo) && isset($correo['cedula']) || (is_array($correo) && isset($correo['nombre']) && !isset($correo[0]))) {
                // ya manejado arriba
            }
            // ajustar
            if (is_array($correo) && !isset($correo['cedula']) && !isset($correo['nombre']) && !isset($correo['correo'])) {
                // no es datos
            }
            // mejor: si $correo es array
            if (is_array($correo)) {
                $data = $correo;
                $correo = (string) ($datos['correo'] ?? $datos['email'] ?? '');
            } elseif (is_array($telefono)) {
                $data = $telefono;
                $telefono = '';
            }
            if (is_array($data) && isset($data['cedula'])) {
                $cedula = (string) $data['cedula'];
            }
            if (is_array($data) && isset($data['nombre'])) {
                $nombre = (string) $data['nombre'];
            }
            if (is_array($data) && isset($data['correo'])) {
                $correo = (string) $data['correo'];
            }
            if (is_array($data) && isset($data['telefono'])) {
                $telefono = (string) $data['telefono'];
            }
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

        $identificadorValor = self::identifierValue($identificador, $cedula, $correo);
        $motivo = self::validarDatos($identificador, $identificadorValor, $correo);
        if ($motivo !== 'ok') {
            return ['ok' => false, 'motivo' => $motivo];
        }

        $existe = self::findByIdentifierValue($formId, $identificador, $identificadorValor);
        if ($existe !== null) {
            return ['ok' => false, 'motivo' => self::MOTIVO_DUPLICADO, 'id' => (int) $existe['id']];
        }

        $code = self::nuevoCode();
        while (self::db()->fetch('SELECT id FROM form_participants WHERE code = :code', ['code' => $code]) !== null) {
            $code = self::nuevoCode();
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

        return ['ok' => true, 'id' => (int) $id];
    }

    public static function update(int $id, int $formId, string $cedula, string $nombre, string $correo, string $telefono, array $data = [], string $identificador = 'cedula'): array
    {
        $fila = self::find($id);
        if ($fila === null || (int) $fila['form_id'] !== $formId) {
            return ['ok' => false, 'motivo' => 'no_encontrado'];
        }

        $cedula = self::normalizar($cedula);
        $nombre = self::normalizar($nombre);
        $correo = self::normalizarCorreo($correo);
        $telefono = self::normalizar($telefono);

        if (!isset(self::COLUMNA_IDENTIFICADOR[$identificador])) {
            $identificador = 'cedula';
        }

        $identificadorValor = self::identifierValue($identificador, $cedula, $correo);
        $motivo = self::validarDatos($identificador, $identificadorValor, $correo);
        if ($motivo !== 'ok') {
            return ['ok' => false, 'motivo' => $motivo];
        }

        $existe = self::findByIdentifierValue($formId, $identificador, $identificadorValor);
        if ($existe !== null && (int) $existe['id'] !== $id) {
            return ['ok' => false, 'motivo' => self::MOTIVO_DUPLICADO, 'id' => (int) $existe['id']];
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
                'id' => $id,
                'form_id' => $formId,
            ]
        );

        if ($cambioCorreo) {
            self::db()->execute('UPDATE form_participants SET invited_at = NULL WHERE id = :id', ['id' => $id]);
        }

        if (is_array($data)) {
            self::guardarAtributos($formId, (int) $id, $data);
        }

        return ['ok' => true];
    }

    public static function delete(int $id, int $formId): bool
    {
        $fila = self::find($id);
        if ($fila === null || (int) $fila['form_id'] !== $formId) {
            return false;
        }
        self::db()->execute('DELETE FROM form_participants WHERE id = :id AND form_id = :form_id', ['id' => $id, 'form_id' => $formId]);
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

    public static function ensureAttribute(int $formId, string $label): ?array
    {
        $label = trim($label);
        if ($label === '') {
            return null;
        }
        $key = self::slug($label);
        if ($key === '') {
            return null;
        }

        $existe = self::db()->fetch('SELECT * FROM form_participant_fields WHERE form_id = :form_id AND (key_name = :key OR label = :label)', ['form_id' => $formId, 'key' => $key, 'label' => $label]);
        if ($existe !== null) {
            return $existe;
        }

        $max = self::db()->fetch('SELECT COALESCE(MAX(position), -1) AS m FROM form_participant_fields WHERE form_id = :form_id', ['form_id' => $formId]);
        $pos = (int) ($max['m'] ?? -1) + 1;

        try {
            $id = self::db()->insert(
                'INSERT INTO form_participant_fields (form_id, key_name, label, position) VALUES (:form_id, :key_name, :label, :position)',
                ['form_id' => $formId, 'key_name' => $key, 'label' => $label, 'position' => $pos]
            );
        } catch (\Throwable $e) {
            $existe = self::db()->fetch('SELECT * FROM form_participant_fields WHERE form_id = :form_id AND (key_name = :key OR label = :label)', ['form_id' => $formId, 'key' => $key, 'label' => $label]);
            return $existe;
        }

        return self::db()->fetch('SELECT * FROM form_participant_fields WHERE id = :id', ['id' => $id]);
    }

    public static function deleteAttribute(int $formId, int $fieldId): bool
    {
        $fila = self::db()->fetch('SELECT id FROM form_participant_fields WHERE id = :id AND form_id = :form_id', ['id' => $fieldId, 'form_id' => $formId]);
        if ($fila === null) {
            return false;
        }
        self::db()->execute('DELETE FROM form_participant_fields WHERE id = :id AND form_id = :form_id', ['id' => $fieldId, 'form_id' => $formId]);
        return true;
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
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? $s;
        $s = trim($s, '-');

        return $s === '' ? 'attr' : $s;
    }
}
