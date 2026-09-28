<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Database;
use App\Services\FormSettings;

class Form
{
    private static function db(): Database
    {
        return Database::getInstance(Config::db());
    }

    public static function create(int $userId, string $title, string $description = ''): int
    {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 190) {
            throw new \InvalidArgumentException('El título del formulario es requerido y debe tener máximo 190 caracteres.');
        }

        $base = self::slugify($title);
        $attempt = 0;

        while ($attempt < 3) {
            $slug = $attempt === 0 ? $base : $base . '-' . bin2hex(random_bytes(2));
            try {
                self::db()->execute(
                    'INSERT INTO forms (user_id, title, description, slug, status) VALUES (:user_id, :title, :description, :slug, :status)',
                    [
                        'user_id'     => $userId,
                        'title'       => $title,
                        'description' => $description,
                        'slug'        => $slug,
                        'status'      => 'draft',
                    ]
                );

                return self::db()->lastInsertId();
            } catch (\PDOException $e) {
                if (str_contains($e->getMessage(), 'slug') && $attempt < 2) {
                    $attempt++;
                    continue;
                }
                throw $e;
            }
        }

        // Fallback (no debería llegar)
        throw new \RuntimeException('No se pudo generar un slug único para el formulario.');
    }

    public static function uniqueSlug(string $title): string
    {
        $base = self::slugify($title);
        $slug = $base;
        $i = 1;

        while (self::db()->fetch('SELECT id FROM forms WHERE slug = :slug', ['slug' => $slug]) !== null) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }

    public static function slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim($text, '-');
        return $text !== '' ? $text : 'form-' . substr(bin2hex(random_bytes(4)), 0, 6);
    }

    public static function find(int $id): ?array
    {
        $form = self::db()->fetch(
            'SELECT f.*, u.name AS owner_name FROM forms f JOIN users u ON u.id = f.user_id WHERE f.id = :id',
            ['id' => $id]
        );
        if ($form === null) {
            return null;
        }
        $form['fields'] = Field::byForm($id);
        return $form;
    }

    public static function findBySlug(string $slug): ?array
    {
        $form = self::db()->fetch(
            'SELECT f.*, u.name AS owner_name FROM forms f JOIN users u ON u.id = f.user_id WHERE f.slug = :slug',
            ['slug' => $slug]
        );
        if ($form === null) {
            return null;
        }
        $form['fields'] = Field::byForm((int) $form['id']);
        return $form;
    }

    public static function byUser(int $userId): array
    {
        return self::db()->fetchAll(
            'SELECT f.*,
                    COALESCE(sc.c, 0) AS submissions_count,
                    COALESCE(sc.last_at, "") AS last_submission_at
             FROM forms f
             LEFT JOIN (SELECT form_id, COUNT(*) AS c, MAX(finished_at) AS last_at FROM submissions GROUP BY form_id) sc ON sc.form_id = f.id
             WHERE f.user_id = :user_id
             ORDER BY f.updated_at DESC',
            ['user_id' => $userId]
        );
    }

    public static function all(): array
    {
        return self::db()->fetchAll(
            'SELECT f.*, u.name AS owner_name,
                    COALESCE(sc.c, 0) AS submissions_count,
                    COALESCE(sc.last_at, "") AS last_submission_at
             FROM forms f
             JOIN users u ON u.id = f.user_id
             LEFT JOIN (SELECT form_id, COUNT(*) AS c, MAX(finished_at) AS last_at FROM submissions GROUP BY form_id) sc ON sc.form_id = f.id
             ORDER BY f.updated_at DESC'
        );
    }

    public static function update(int $id, string $title, string $description, string $status = 'draft'): void
    {
        self::db()->execute(
            'UPDATE forms SET title = :title, description = :description, status = :status WHERE id = :id',
            ['id' => $id, 'title' => $title, 'description' => $description, 'status' => $status]
        );
    }

    public static function delete(int $id): void
    {
        self::db()->execute('DELETE FROM forms WHERE id = :id', ['id' => $id]);
    }

    /**
     * Duplica un formulario (P7): copia los metadatos y los settings tal cual,
     * los campos con sus opciones/validaciones/lógica (remapeando parent_id a
     * los ids nuevos) y queda en estado draft con slug único. No copia
     * respuestas.
     */
    public static function duplicate(int $id): int
    {
        $form = self::find($id);
        if ($form === null) {
            throw new \RuntimeException('Formulario no encontrado para duplicar.');
        }

        $baseTitle = trim((string) $form['title']);
        $suffix = ' (copia)';
        $copyTitle = mb_substr($baseTitle, 0, 190 - mb_strlen($suffix)) . $suffix;
        $slug = self::uniqueSlug($copyTitle);

        $db = self::db();
        // F1.2: el duplicado es 1+N escrituras (el form + un INSERT por campo), y
        // sin transacción un fallo en el campo N dejaba un `forms` en `draft` con
        // N-1 campos, el slug ya consumido y un 500 sin rastro en el dashboard.
        $db->beginTransaction();
        try {
            $db->execute(
                'INSERT INTO forms (user_id, title, description, slug, status, settings)
                 VALUES (:user_id, :title, :description, :slug, :status, :settings)',
                [
                    'user_id'     => (int) $form['user_id'],
                    'title'       => $copyTitle,
                    'description' => (string) ($form['description'] ?? ''),
                    'slug'        => $slug,
                    'status'      => 'draft',
                    'settings'    => (string) ($form['settings'] ?? ''),
                ]
            );
            $newId = (int) $db->lastInsertId();

            $idMap = [];
            foreach ($form['fields'] as $field) {
                $parentId = $field['parent_id'] !== null ? ($idMap[$field['parent_id']] ?? null) : null;
                $idMap[(int) $field['id']] = Field::create(
                    $newId,
                    (string) $field['type'],
                    (string) $field['label'],
                    (string) $field['placeholder'],
                    (string) $field['help_text'],
                    (bool) $field['is_required'],
                    (array) ($field['options'] ?? []),
                    (array) ($field['validations'] ?? []),
                    (int) $field['position'],
                    $parentId,
                    is_array($field['logic']) && $field['logic'] !== [] ? $field['logic'] : null
                );
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return $newId;
    }

    public static function setStatus(int $id, string $status): void
    {
        self::db()->execute('UPDATE forms SET status = :status WHERE id = :id', ['id' => $id, 'status' => $status]);
    }

    public static function submissionCount(int $formId): int
    {
        $row = self::db()->fetch('SELECT COUNT(*) AS c FROM submissions WHERE form_id = :id', ['id' => $formId]);
        return (int) ($row['c'] ?? 0);
    }

    /**
     * Settings del formulario normalizados (nunca null; si no hay JSON usa defaults).
     */
    public static function settings(int $id): array
    {
        $row = self::db()->fetch('SELECT settings FROM forms WHERE id = :id', ['id' => $id]);
        if ($row === null) {
            return FormSettings::defaults();
        }

        return self::settingsFrom($row);
    }

    /**
     * Normaliza los settings de una fila YA cargada (la de `find()`,
     * `findBySlug()`, etc.), sin volver a consultar.
     *
     * Existe porque `find()` hace `SELECT f.*`, o sea que ya trae la columna
     * `settings`: pedirla otra vez con `settings($id)` es una consulta de más
     * por petición, y encima vuelve a pagar el `FormSettings::normalize()`,
     * que no es trivial (recorta y pasa por lista blanca un puñado de bloques).
     *
     * La normalización vive en un solo sitio para que el "settings ya
     * normalizado" sea el mismo venga de donde venga: una fila guardada antes
     * de que existiera un ajuste de la whitelist tiene que salir igual por
     * aquí que por `settings($id)`.
     */
    public static function settingsFrom(array $form): array
    {
        $raw = json_decode((string) ($form['settings'] ?? ''), true);

        return FormSettings::normalize(is_array($raw) ? $raw : []);
    }

    /**
     * Persiste los settings normalizados. El JSON ya sanitizado por FormSettings.
     */
    public static function saveSettings(int $id, array $settings): void
    {
        $normalized = FormSettings::normalize($settings);
        self::db()->execute(
            'UPDATE forms SET settings = :settings, updated_at = NOW() WHERE id = :id',
            ['id' => $id, 'settings' => json_encode($normalized, JSON_UNESCAPED_UNICODE)]
        );
    }
}