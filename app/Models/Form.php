<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Cache;
use App\Core\Config;
use App\Core\Database;
use App\Services\FormSettings;

class Form
{
    private static function db(): Database
    {
        return Database::getInstance(Config::db());
    }

    /**
     * El dashboard se cachea (fase 5, rendimiento): cualquier mutación de un
     * formulario (alta, título, estado, borrado, duplicado, settings) o de sus
     * submissions invalida la caché entera de dashboards. Incluso si la
     * invalidación fallara, el TTL (60 s) se comería el error: no propaga.
     */
    private static function bumpDashboardCache(): void
    {
        try {
            Cache::forgetLike('dash:');
        } catch (\Throwable $e) {
        }
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

                $id = self::db()->lastInsertId();
                self::bumpDashboardCache();
                return $id;
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

    /**
     * @param bool $withFields cargar también los campos (Field::byForm); las
     *                         rutas que solo leen el contenedor (owner/permisos)
     *                         lo apagan para ahorrarse una query.
     */
    public static function find(int $id, bool $withFields = true): ?array
    {
        $form = self::db()->fetch(
            'SELECT f.*, u.name AS owner_name FROM forms f JOIN users u ON u.id = f.user_id WHERE f.id = :id',
            ['id' => $id]
        );
        if ($form === null) {
            return null;
        }
        $form['fields'] = $withFields ? Field::byForm($id) : [];
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

    /** Los cinco estados de `forms.status`, y la lista blanca del filtro. */
    public const STATUS = ['draft', 'in_review', 'published', 'closed', 'approved'];

    /** Filas por página del listado de formularios (dashboard y /admin/forms). */
    public const POR_PAGINA = 12;

    /**
     * Los cuatro órdenes del desplegable. Las claves son las de `<option
     * value>` del `dashSort`, así que quien llame a esto no puede inventarse
     * una quinta por un `match` sin default.
     */
    public const ORDENES = ['updated', 'alpha', 'subs', 'activity'];

    /**
     * M2 — Una página del listado de formularios, con su total.
     *
     * Antes `byUser()`/`all()` devolvían el alcance entero y el `LIMIT` era de
     * cliente: con doscientos formularios la página pintaba doscientas tarjetas
     * para mostrar nueve, y la subconsulta de `submissions` agregaba la tabla
     * entera en cada consulta. Aquí son DOS consultas porque el agregado va
     * sobre los ids de la página y no sobre el alcance: la segunda solo cuenta
     * las respuestas de los doce formularios que se van a pintar.
     *
     * `$filtros` acepta `q` (texto), `estado` (uno de `STATUS`) y `orden` (uno
     * de `ORDENES`). Un filtro desconocido se ignora en vez de romper: quien lo
     * manda es la URL.
     *
     * El `id` como segundo criterio de orden no es decoración: `ORDER BY
     * updated_at DESC` solo sobre una columna que se empata deja la frontera
     * entre páginas indeterminada, y con ella un formulario que aparece en dos
     * páginas o en ninguna al paginar. Lo mismo con dos títulos iguales.
     */
    public static function page(int $userId, bool $scopeAll, array $filtros = [], int $page = 1, ?int $porPagina = null): array
    {
        $db = self::db();
        $porPagina = max(1, min(60, $porPagina ?? self::POR_PAGINA));
        $page = max(1, $page);

        [$where, $params] = self::whereListado($userId, $scopeAll, $filtros);

        $row = $db->fetch("SELECT COUNT(*) AS c FROM forms f WHERE 1 = 1$where", $params);
        $total = (int) ($row['c'] ?? 0);
        $pages = max(1, (int) ceil($total / $porPagina));
        // Una página de la URL por encima del final se recorta a la última en
        // vez de pintar un grid vacío: con el filtro puesto es el caso normal
        // (filtrar deja menos filas que las que había).
        $page = min($page, $pages);

        $orden = self::orderBy($filtros['orden'] ?? 'updated');
        $ids = $db->fetchAll(
            "SELECT f.id FROM forms f WHERE 1 = 1$where ORDER BY $orden LIMIT $porPagina OFFSET " . (($page - 1) * $porPagina),
            $params
        );
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $ids);

        return [
            'items'     => $ids === [] ? [] : self::rowsDeListado($db, $ids, $orden),
            'total'     => $total,
            'page'      => $page,
            'pages'     => $pages,
            'per_page'  => $porPagina,
        ];
    }

    /**
     * Las cuentas de las tarjetas del dashboard, del alcance entero y **sin
     * filtros**: son datos de la instalación («publicados: 12»), no de lo que
     * hay en pantalla. Por eso van en su propia consulta y no se derivan de la
     * página — derivarlas de la página hacía que en la segunda apareciera
     * «Total 12» con doce tarjetas pero solo las de la primera.
     */
    public static function counts(int $userId, bool $scopeAll): array
    {
        $db = self::db();
        $where = $scopeAll ? '' : ' WHERE f.user_id = :user_id';
        $params = $scopeAll ? [] : ['user_id' => $userId];

        $row = $db->fetch(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(f.status = 'draft'), 0) AS draft,
                    COALESCE(SUM(f.status = 'in_review'), 0) AS in_review,
                    COALESCE(SUM(f.status = 'published'), 0) AS published,
                    COALESCE(SUM(f.status = 'closed'), 0) AS closed,
                    COALESCE(SUM(f.status = 'approved'), 0) AS approved
             FROM forms f$where",
            $params
        ) ?? [];

        // COUNT por estado y no COUNT con WHERE: seis escaneos del índice
        // `idx_forms_user_status` para sacar una fila.
        $stats = ['submissions' => 0];
        foreach (self::STATUS as $estado) {
            $stats[$estado] = (int) ($row[$estado] ?? 0);
        }
        $stats['total'] = (int) ($row['total'] ?? 0);

        $row = $db->fetch(
            'SELECT COUNT(*) AS c FROM submissions s JOIN forms f ON f.id = s.form_id' . ($scopeAll ? '' : ' WHERE f.user_id = :user_id'),
            $params
        );
        $stats['submissions'] = (int) ($row['c'] ?? 0);

        return $stats;
    }

    /** El WHERE del listado, con los filtros ya validados. */
    private static function whereListado(int $userId, bool $scopeAll, array $filtros): array
    {
        $where = '';
        $params = [];

        if (!$scopeAll) {
            $where .= ' AND f.user_id = :user_id';
            $params['user_id'] = $userId;
        }

        $q = trim((string) ($filtros['q'] ?? ''));
        if ($q !== '') {
            // Un solo marcador con el `CONCAT`, no dos `LIKE` con `:q`
            // repetido: `Database` va con `ATTR_EMULATE_PREPARES = false` y un
            // marcador repetido lanza HY093. Y así busca sobre título y
            // descripción a la vez, que es lo que hacía el buscador de cliente
            // (`data-title` era los dos textos juntos).
            //
            // El `%` y el `_` de lo que se escribe son texto, comodines. Sin
            // escaparlos, buscar «100%» devuelve el alcance entero y parece que
            // el buscador está roto.
            $where .= ' AND CONCAT(f.title, " ", f.description) LIKE :q';
            $params['q'] = '%' . addcslashes($q, '%_\\') . '%';
        }

        $estado = (string) ($filtros['estado'] ?? '');
        if (in_array($estado, self::STATUS, true)) {
            $where .= ' AND f.status = :estado';
            $params['estado'] = $estado;
        }

        return [$where, $params];
    }

    /**
     * El `ORDER BY` como fragmento de SQL, con el `id` de desempate.
     *
     * `subs` y `activity` necesitan un valor por formulario, así que van como
     * subconsulta correlacionada: con `idx_submissions_form_finished` cada una es
     * una búsqueda en el índice, no un escaneo. Y las dos se usan en el listado
     * **y** en la página de filas con el mismo texto, para que el orden de lo
     * pintado sea el que pidió la persona y no el que saliera de un array.
     */
    private static function orderBy(string $orden): string
    {
        return match ($orden) {
            'alpha'    => 'f.title ASC, f.id ASC',
            'subs'     => '(SELECT COUNT(*) FROM submissions s WHERE s.form_id = f.id) DESC, f.id DESC',
            'activity' => '(SELECT MAX(s.finished_at) FROM submissions s WHERE s.form_id = f.id) DESC, f.id DESC',
            default    => 'f.updated_at DESC, f.id DESC',
        };
    }

    /**
     * Las filas de los ids de la página, con su conteo de respuestas.
     *
     * Los marcadores van numerados y **por juego**: `:c0, :c1…` para el `IN` del
     * agregado y `:w0, :w1…` para el `WHERE`, aunque valgan lo mismo. Es el
     * motivo por el que `Database` (con `ATTR_EMULATE_PREPARES = false`) lanza
     * HY093 ante un marcador repetido, y por el que `Answer::marcadoresDeCampos()`
     * también los numera.
     */
    private static function rowsDeListado(Database $db, array $ids, string $orden): array
    {
        $phCount = implode(', ', array_map(
            static fn (int $id, int $i): string => ':c' . $i,
            $ids,
            array_keys($ids)
        ));
        $phWhere = implode(', ', array_map(
            static fn (int $id, int $i): string => ':w' . $i,
            $ids,
            array_keys($ids)
        ));
        $params = [];
        foreach ($ids as $i => $id) {
            $params['c' . $i] = $id;
            $params['w' . $i] = $id;
        }

        return $db->fetchAll(
            "SELECT f.*, u.name AS owner_name,
                    COALESCE(sc.c, 0) AS submissions_count,
                    COALESCE(sc.last_at, '') AS last_submission_at
             FROM forms f
             JOIN users u ON u.id = f.user_id
             LEFT JOIN (
                SELECT s.form_id, COUNT(*) AS c, MAX(s.finished_at) AS last_at
                FROM submissions s
                WHERE s.form_id IN ($phCount)
                GROUP BY s.form_id
             ) sc ON sc.form_id = f.id
             WHERE f.id IN ($phWhere)
             ORDER BY $orden",
            $params
        );
    }

    public static function update(int $id, string $title, string $description, string $status = 'draft'): void
    {
        self::db()->execute(
            'UPDATE forms SET title = :title, description = :description, status = :status WHERE id = :id',
            ['id' => $id, 'title' => $title, 'description' => $description, 'status' => $status]
        );
        self::bumpDashboardCache();
    }

    public static function delete(int $id): void
    {
        self::db()->execute('DELETE FROM forms WHERE id = :id', ['id' => $id]);
        self::bumpDashboardCache();
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
                    // La copia sale normalizada: `settingsFrom()` devuelve
                    // siempre un JSON DECLARATIVO válido (defaults si la fila
                    // original tiene NULL/vacío), y la columna tiene CHECK de
                    // JSON_VALID — copiar el string crudo reventaba con 4025
                    // al duplicar un form que nunca guardó settings.
                    'settings'    => json_encode(self::settingsFrom($form), JSON_UNESCAPED_UNICODE),
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
                    is_array($field['logic']) && $field['logic'] !== [] ? $field['logic'] : null,
                    // F1: la marca de PII se copia con el campo. Omitirla
                    // desmascararía en silencio la copia: el dueño ve un
                    // formulario "igual" pero que filtra IP, user-agent y
                    // respuestas personales a todo el que tenga acceso a él.
                    (int) ($field['is_pii'] ?? 0) === 1
                );
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        self::bumpDashboardCache();
        return $newId;
    }

    /**
     * El texto de la insignia, con el guion bajo RAIDO y sin inventar.
     *
     * Estaba en tres vistas como `ucfirst($form['status'])` y eso escribe
     * «In_review» (con guion bajo) en el listado y en la ficha, que es lo que
     * se ve de un formulario en cola. Vive aquí para que las tres no vuelvan a
     * separarse: la clase CSS sigue siendo `app-status-<status>` sin traducir
     * (la que le pone el color), y el texto es de las vistas.
     */
    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'draft'     => 'Borrador',
            'in_review' => 'En revisión',
            'published' => 'Publicado',
            'closed'    => 'Cerrado',
            'approved'  => 'Aprobado',
            default     => ucfirst($status),
        };
    }

    public static function setStatus(int $id, string $status): void
    {
        self::db()->execute('UPDATE forms SET status = :status WHERE id = :id', ['id' => $id, 'status' => $status]);
        self::bumpDashboardCache();
    }

    // ---- F3: revisión/aprobación ----

    /**
     * F3 — Lleva el formulario a `in_review`.
     *
     * Borra la aprobación anterior (`reviewed_at`/`reviewed_by`) porque si un
     * formulario ya publicado se vuelve a mandar a revisión, su aprobación es
     * de una versión que ya no existe: dejarla pondría "aprobado por X" sobre un
     * contenido que nadie miró.
     */
    public static function submitForReview(int $id): void
    {
        self::db()->execute(
            "UPDATE forms
             SET status = 'in_review',
                 review_requested_at = UTC_TIMESTAMP(),
                 reviewed_at = NULL,
                 reviewed_by = NULL
             WHERE id = :id",
            ['id' => $id]
        );
        self::bumpDashboardCache();
    }

    /**
     * F3 — Aprueba el formulario. Deja constancia de QUIÉN y CUÁNDO, que es lo
     * que convierte la revisión en una traza y no en un clic anónimo.
     *
     * Deja el formulario en `approved`, NO en `published`: aprobar es «esto se
     * puede publicar», no «esto está publicado». Publicar es el paso siguiente
     * y lo da el dueño (`FormController::publish()`), que es quien decide
     * CUÁNDO sale al público. Sin esta parada, una aprobación con semanas de
     * antelación publicaba sola y el dueño no tenía forma de sujetarla.
     */
    public static function approve(int $id, int $reviewerId): void
    {
        self::db()->execute(
            "UPDATE forms
             SET status = 'approved',
                 reviewed_at = UTC_TIMESTAMP(),
                 reviewed_by = :by
             WHERE id = :id",
            ['id' => $id, 'by' => $reviewerId]
        );
        self::bumpDashboardCache();
    }

    /**
     * F3 — Devuelve el formulario a `draft` sin publicarlo.
     *
     * Lo usan el «Devolver» del revisor (`ReviewController::returnDraft`) y el
     * guardado de las tres puertas de edición con revisión activa
     * (`BuilderController::saveStructure`, `FormController::update`,
     * `updateSettings`): en las dos, `draft` significa «fuera de la cola y sin
     * aprobación» — `review_requested_at` se borra, y también `reviewed_at`/
     * `reviewed_by`, porque una aprobación sobre contenido editado es de otra
     * cosa. La fecha del último intento no se archiva en ninguna otra parte
     * porque no hay historial de revisiones en F3: el log de seguridad es el
     * que guarda el rastro, y este método no lo escribe (el controlador, que
     * es quien sabe si el cambio tiene sentido).
     */
    public static function returnToDraft(int $id): void
    {
        self::db()->execute(
            "UPDATE forms
             SET status = 'draft',
                 review_requested_at = NULL,
                 reviewed_at = NULL,
                 reviewed_by = NULL
             WHERE id = :id",
            ['id' => $id]
        );
        self::bumpDashboardCache();
    }

    /**
     * F3 — Formularios esperando revisión, del más antiguo al más reciente.
     *
     * Lo primero que se ordena por `review_requested_at` es "más antiguo", y
     * no "más nuevo", porque la pregunta de quien revisa es «¿qué lleva más
     * tiempo parado?». Un NULL (filararía de un `status` cambiado a mano)
     * queda el último con `IS NULL` al final en MySQL: se ordenan al final en
     * vez de construed como los más antiguos.
     *
     * @return array<int,array>
     */
    public static function pendingReviews(): array
    {
        return self::db()->fetchAll(
            "SELECT f.id, f.title, f.description, f.slug, f.status, f.review_requested_at,
                    u.name AS owner_name, u.email AS owner_email
             FROM forms f
             JOIN users u ON u.id = f.user_id
             WHERE f.status = 'in_review'
             ORDER BY f.review_requested_at IS NULL, f.review_requested_at ASC, f.id ASC"
        );
    }

    /**
     * F3 — Traza de la revisión de un formulario para la tarjeta del panel:
     * quién lo pidió, quién lo aprobó y cuándo.
     *
     * @return array{solicitado: ?string, aprobado: ?string, aprobador: ?string}
     */
    public static function reviewTrail(int $id): array
    {
        $row = self::db()->fetch(
            "SELECT f.review_requested_at, f.reviewed_at, u.name AS approver
             FROM forms f
             LEFT JOIN users u ON u.id = f.reviewed_by
             WHERE f.id = :id",
            ['id' => $id]
        );
        if ($row === null) {
            return ['solicitado' => null, 'aprobado' => null, 'aprobador' => null];
        }

        return [
            'solicitado' => $row['review_requested_at'] === null ? null : (string) $row['review_requested_at'],
            'aprobado'   => $row['reviewed_at'] === null ? null : (string) $row['reviewed_at'],
            'aprobador'  => $row['approver'] === null ? null : (string) $row['approver'],
        ];
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
        self::bumpDashboardCache();
    }

    // ---- Borrador de la estructura del builder (modelo Elementor, migración 0020) ----

    /**
     * Guarda el payload del builder como BORRADOR (lo que el autosave escribe).
     *
     * No toca `fields` (la estructura viva) ni el `status`: escribir ahí era lo
     * que hacía que un autosave re-encolara la revisión en cada tic. El commit
     * real es `BuilderController::saveStructure`, que además limpia el borrador.
     */
    public static function saveDraft(int $id, array $payload): void
    {
        self::db()->execute(
            'UPDATE forms SET builder_draft = :draft, builder_draft_at = UTC_TIMESTAMP() WHERE id = :id',
            ['id' => $id, 'draft' => json_encode($payload, JSON_UNESCAPED_UNICODE)]
        );
    }

    /** El borrador decodificado, o `null` si no hay (NULL o JSON inválido). */
    public static function draft(int $id): ?array
    {
        $row = self::db()->fetch('SELECT builder_draft FROM forms WHERE id = :id', ['id' => $id]);
        if ($row === null || $row['builder_draft'] === null || $row['builder_draft'] === '') {
            return null;
        }

        $decoded = json_decode((string) $row['builder_draft'], true);

        return is_array($decoded) ? $decoded : null;
    }

    /** Limpia el borrador (tras un commit o un envío a revisión). */
    public static function clearDraft(int $id): void
    {
        self::db()->execute(
            'UPDATE forms SET builder_draft = NULL, builder_draft_at = NULL WHERE id = :id',
            ['id' => $id]
        );
    }
}