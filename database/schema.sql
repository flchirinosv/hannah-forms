-- VanillaForms Schema
SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS vanilla_forms
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE vanilla_forms;

-- Roles
CREATE TABLE IF NOT EXISTS roles (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(50) NOT NULL UNIQUE,
    description VARCHAR(255) DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Users
CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_id       INT UNSIGNED NOT NULL DEFAULT 2,
    name          VARCHAR(100) NOT NULL,
    email         VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    -- F2: instante del último cambio de contraseña (UTC). `Auth::user()`
    -- compara contra la apertura de sesión para cerrarla, porque las sesiones
    -- son ficheros de PHP y no se pueden revocar por id. DATETIME y no
    -- TIMESTAMP a propósito: un TIMESTAMP ON UPDATE saltaría con cualquier
    -- UPDATE de la fila y cerraría sesiones sin motivo.
    password_changed_at DATETIME DEFAULT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- F2: tokens de recuperación de contraseña (un solo uso, con caducidad).
-- Se guarda el sha256 del token, no el token: la fila no es el secreto.
CREATE TABLE IF NOT EXISTS password_resets (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    token_hash  CHAR(64) NOT NULL,
    expires_at  DATETIME NOT NULL,
    used_at     DATETIME DEFAULT NULL,
    ip_address  VARCHAR(45) DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_password_resets_token (token_hash),
    KEY idx_password_resets_user (user_id),
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Forms
CREATE TABLE IF NOT EXISTS forms (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    title       VARCHAR(190) NOT NULL,
    description TEXT DEFAULT NULL,
    slug        VARCHAR(190) NOT NULL UNIQUE,
    status      ENUM('draft','in_review','published','closed') NOT NULL DEFAULT 'draft',
    -- F3 — traza de la revisión. NULL = nunca se pidió/approbó.
    review_requested_at DATETIME DEFAULT NULL COMMENT 'Instante UTC en que se envió a revisión',
    reviewed_at DATETIME DEFAULT NULL COMMENT 'Instante UTC de la aprobación',
    reviewed_by INT UNSIGNED DEFAULT NULL COMMENT 'Usuario con forms.review que aprobó',
    theme       JSON DEFAULT NULL,
    settings    JSON DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_forms_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_forms_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Fields
CREATE TABLE IF NOT EXISTS fields (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_id     INT UNSIGNED NOT NULL,
    type        VARCHAR(30) NOT NULL,
    label       VARCHAR(190) NOT NULL,
    placeholder VARCHAR(190) DEFAULT NULL,
    help_text   VARCHAR(255) DEFAULT NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    is_pii      TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Campo con datos personales: se enmascara sin el permiso responses.pii',
    show_header TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Solo para type=page: pintar la cabecera (icono, título y descripción) en el formulario final',
    options     JSON DEFAULT NULL,
    validations JSON DEFAULT NULL,
    position    INT NOT NULL DEFAULT 0,
    parent_id   INT UNSIGNED DEFAULT NULL COMMENT 'Sección contenedora (NULL = nivel superior)',
    logic       JSON DEFAULT NULL COMMENT 'Condición de visibilidad',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_fields_form FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE,
    CONSTRAINT fk_fields_parent FOREIGN KEY (parent_id) REFERENCES fields(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_fields_form_position ON fields (form_id, position);
-- Índice explícito de la FK `fk_fields_parent`. MariaDB crearía uno automático
-- con el nombre de la restricción, pero declararlo aquí deja el esquema de una
-- instalación limpia idéntico al que deja la migración 0004 (y con el mismo
-- nombre, que es como lo consulta el resto del código).
-- Importa además por el significado de la columna: `parent_id` es NULL para un
-- campo suelto, y 0 nunca puede ser una sección real (AUTO_INCREMENT), así que
-- la FK convierte el descuido de escribir un 0 en un error de integridad en vez
-- de un "campo sin sección" silencioso.
CREATE INDEX idx_fields_parent ON fields (parent_id);

-- Índice para consultas frecuentes de dashboard: forms por usuario, con y sin
-- estado. Es UNO solo a propósito: un `idx_forms_user (user_id)` aparte sería
-- redundante, porque el prefijo izquierdo de este compuesto ya sirve cualquier
-- `WHERE user_id = ?` (y es el índice que necesita la FK `fk_forms_user`).
-- Una BD creada con las migraciones no tiene ese índice suelto, y con él cada
-- INSERT de formulario escribía un índice de más.
CREATE INDEX idx_forms_user_status ON forms (user_id, status);
-- F3 — panel de revisores: «formularios esperando revisión» ordenado por antigüedad
CREATE INDEX idx_forms_status_review ON forms (status, review_requested_at);

-- F4: allowlist de acceso (modo `allowlist`).
-- Va en tabla y no en `settings` porque revocar tiene que borrar los tokens de
-- ese email en la misma operación, y eso es una cascada de SQL, no un bucle de
-- PHP que puede quedarse a medias. El UNIQUE hace idempotente «añadir dos veces».
CREATE TABLE IF NOT EXISTS form_access_emails (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_id    INT UNSIGNED NOT NULL,
    email      VARCHAR(190) NOT NULL COMMENT 'Normalizado a minúsculas por el modelo',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_form_access_email (form_id, email),
    CONSTRAINT fk_form_access_email_form FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- F4: enlaces de acceso. NO es de un solo uso (un F5 lo dejaría inservible):
-- lo que lo limita es la caducidad y `revoked_at`.
-- No lleva FK a `form_access_emails` porque en modo `email` hay tokens sin fila
-- en la allowlist; la revocación es explícita y va en transacción en el modelo.
CREATE TABLE IF NOT EXISTS form_access_tokens (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_id    INT UNSIGNED NOT NULL,
    email      VARCHAR(190) NOT NULL COMMENT 'A quién se emitió; no es la autorización',
    token_hash CHAR(64) NOT NULL COMMENT 'sha256 del token: la fila no es el secreto',
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME DEFAULT NULL COMMENT 'Revocado por el dueño al quitar el email',
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_form_access_token (token_hash),
    KEY idx_form_access_form_email (form_id, email),
    CONSTRAINT fk_form_access_token_form FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Roster: la persona con acceso. `identifier_value` está DENORMALIZADO para que
-- la puerta del fill busque siempre en la misma columna indexada, se elija
-- identificador. `code` es la credencial, no la fila: un entero correlativo sería
-- adivinable. La collation no distingue mayúsculas ni acentos, así que `V-1234` y
-- `v-1234` son el mismo participante.
CREATE TABLE IF NOT EXISTS form_participants (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_id          INT UNSIGNED NOT NULL,
    cedula           VARCHAR(190) NOT NULL DEFAULT '' COMMENT 'Uno de los tres candidatos a identificador',
    nombre           VARCHAR(190) NOT NULL DEFAULT '',
    indicador        VARCHAR(190) NOT NULL DEFAULT '',
    correo           VARCHAR(190) NOT NULL DEFAULT '' COMMENT 'Normalizado a minúsculas por el modelo',
    identifier_value VARCHAR(190) NOT NULL COMMENT 'Denormalizado: la puerta busca siempre por aquí',
    code             CHAR(16) NOT NULL COMMENT 'Credencial personal; NO es la fila ni el identificador',
    responded_at     DATETIME DEFAULT NULL COMMENT 'Su único escritor es el mismo POST que crea la respuesta',
    invited_at       DATETIME DEFAULT NULL COMMENT 'Invitación por correo enviada (fase 10); NULL = nunca',
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_form_participant_identifier (form_id, identifier_value),
    UNIQUE KEY uq_form_participant_code (code),
    KEY idx_form_participant_correo (form_id, correo),
    CONSTRAINT fk_form_participant_form FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='Listado de participantes con acceso al formulario';

-- El catálogo de atributos es la FRONTERA DE SEGURIDAD: el SQL solo recibe un
-- `key_name` que salió de aquí, y una clave inventada no encuentra nada.
-- `label` es lo que escribió el dueño en la cabecera del xlsx y es único por
-- formulario porque es lo que ve en la interfaz.
CREATE TABLE IF NOT EXISTS form_participant_fields (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_id    INT UNSIGNED NOT NULL,
    key_name   VARCHAR(64) NOT NULL COMMENT 'Slug estable; es lo único que viaja al SQL',
    label      VARCHAR(190) NOT NULL COMMENT 'La cabecera que escribió el dueño en el xlsx',
    position   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_form_participant_field_key (form_id, key_name),
    UNIQUE KEY uq_form_participant_field_label (form_id, label),
    CONSTRAINT fk_form_participant_field_form FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='Catálogo de atributos de participante por formulario';

-- Un renglón por valor, y NO una columna JSON: el nombre de la clave no se puede
-- pasar como parámetro enlazado (va dentro de la llamada a la función de SQL) y
-- agrupar por JSON no es indexable. `value` es VARCHAR, no TEXT, para que el
-- índice de filtro cubra la columna entera.
CREATE TABLE IF NOT EXISTS form_participant_attrs (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_id        INT UNSIGNED NOT NULL COMMENT 'Desnormalizado para filtrar sin atravesar dos tablas',
    participant_id INT UNSIGNED NOT NULL,
    key_name       VARCHAR(64) NOT NULL COMMENT 'Salió de form_participant_fields, nunca del POST',
    value          VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'VARCHAR, no TEXT: así el índice es completo',
    KEY idx_form_participant_attr_unique (participant_id, key_name),
    KEY idx_form_participant_attr_filter (form_id, key_name, value),
    CONSTRAINT fk_form_participant_attr_form FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE,
    CONSTRAINT fk_form_participant_attr_participant FOREIGN KEY (participant_id) REFERENCES form_participants(id) ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='Un renglón por valor de atributo';

-- Submissions
CREATE TABLE IF NOT EXISTS submissions (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_id     INT UNSIGNED NOT NULL,
    -- Con qué participante se respondió. NULL si el formulario no es roster, o si
    -- respondió sin código. La FK va DECLARADA (y no solo la columna) para que una
    -- instalación limpia quede idéntica a una migrada: `ON DELETE SET NULL` es lo
    -- que garantiza que borrar a alguien de la lista no borre su respuesta, y si
    -- esta FK faltara, solo estaría en las instalaciones que se migraron.
    participant_id INT UNSIGNED DEFAULT NULL,
    ip_address  VARCHAR(45) DEFAULT NULL,
    user_agent  VARCHAR(255) DEFAULT NULL,
    -- Token de capacidad de 128 bits para la página pública de gracias (P8).
    -- NUNCA usar `id` en `/f/{slug}/thanks`: es un AUTO_INCREMENT recorrible y
    -- filtraría las respuestas de todos los que contestaron el formulario.
    -- NULL = fila antigua sin token: se muestra el agradecimiento sin quiz.
    public_token CHAR(32) DEFAULT NULL,
    finished_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_submissions_form FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE,
    CONSTRAINT fk_submissions_participant FOREIGN KEY (participant_id) REFERENCES form_participants(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_submissions_form_finished ON submissions (form_id, finished_at);
CREATE UNIQUE INDEX idx_submissions_public_token ON submissions (public_token);
CREATE INDEX idx_submissions_participant ON submissions (participant_id);

-- Answers
CREATE TABLE IF NOT EXISTS answers (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    submission_id INT UNSIGNED NOT NULL,
    field_id      INT UNSIGNED NOT NULL,
    value         TEXT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_answers_submission FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE,
    CONSTRAINT fk_answers_field FOREIGN KEY (field_id) REFERENCES fields(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_answers_submission ON answers (submission_id);
CREATE INDEX idx_answers_field ON answers (field_id);
-- Compuesto: es el que usa Submission::withAnswers() (ORDER BY field_id sobre
-- las respuestas de una submission), así que los dos de una columna sueltos no
-- lo cubren. Lo crea la migración 0004; aquí se declara para que una
-- instalación limpia quede igual que una migrada.
CREATE INDEX idx_answers_submission_field ON answers (submission_id, field_id);

-- Rate limiting (prevención de fuerza bruta y abuso)
CREATE TABLE IF NOT EXISTS rate_limits (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scope       VARCHAR(30)  NOT NULL,
    identifier  VARCHAR(64)  NOT NULL,
    attempts    INT UNSIGNED NOT NULL DEFAULT 0,
    window_start TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rate_scope_identifier (scope, identifier)
) ENGINE=InnoDB;

CREATE INDEX idx_rate_identifier ON rate_limits (identifier, window_start);
-- Soporta el DELETE de la limpieza (`window_start < NOW() - INTERVAL ...`).
-- Sin él, cada escritura que se lleva su pasada de limpieza (RateLimiter::recordHit)
-- haría un recorrido completo de la tabla. Lo crea la migración 0004.
CREATE INDEX idx_rate_window ON rate_limits (window_start);

-- Migrations (registro de migraciones aplicadas para el script database/migrate.php)
CREATE TABLE IF NOT EXISTS migrations (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(190) NOT NULL UNIQUE,
    applied_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ===========================================================================
-- RBAC (migración 0005) — permisos, roles, grupos de roles
-- ===========================================================================

-- Catálogo de permisos a nivel de acción. La semántica vive en
-- config/permissions.php y este esquema SOLO declara las tablas: las filas las
-- siembran database/seed.sql (instalación limpia) y la migración 0005 (BD
-- existente), que para este seed las dos son una copia del mismo catálogo.
CREATE TABLE IF NOT EXISTS permissions (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key`       VARCHAR(80) NOT NULL UNIQUE,
    description VARCHAR(255) DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS role_permissions (
    role_id       INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Grupo de ROLES: agrupa roles y usuarios. Un usuario hereda los permisos de
-- su propio rol + los de los roles de sus grupos (Auth::permissions()).
CREATE TABLE IF NOT EXISTS role_groups (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(80) NOT NULL UNIQUE,
    description VARCHAR(255) DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS role_group_roles (
    group_id INT UNSIGNED NOT NULL,
    role_id  INT UNSIGNED NOT NULL,
    PRIMARY KEY (group_id, role_id),
    CONSTRAINT fk_rgr_group FOREIGN KEY (group_id) REFERENCES role_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_rgr_role  FOREIGN KEY (role_id)  REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS role_group_users (
    group_id INT UNSIGNED NOT NULL,
    user_id  INT UNSIGNED NOT NULL,
    PRIMARY KEY (group_id, user_id),
    CONSTRAINT fk_rgu_group FOREIGN KEY (group_id) REFERENCES role_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_rgu_user  FOREIGN KEY (user_id)  REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ===========================================================================
-- Log de eventos de seguridad (migración 0005)
-- ===========================================================================
CREATE TABLE IF NOT EXISTS security_log (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event      VARCHAR(60) NOT NULL,
    detail     VARCHAR(500) DEFAULT NULL,
    ip         VARCHAR(45) DEFAULT NULL,
    user_id    INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sl_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_security_log_created ON security_log (created_at);
CREATE INDEX idx_security_log_event ON security_log (event, created_at);

-- ===========================================================================
-- Caché de consultas frecuentes (dashboard, migración 0005)
-- ===========================================================================
CREATE TABLE IF NOT EXISTS cache (
    cache_key  VARCHAR(190) NOT NULL PRIMARY KEY,
    value      MEDIUMTEXT NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE INDEX idx_cache_expires ON cache (expires_at);
CREATE INDEX idx_cache_key_like ON cache (cache_key);

-- ===========================================================================
-- Ajustes de la instalación (migración 0010)
-- ===========================================================================

-- Interruptores que se cambian desde el panel sin tocar el servidor. Tabla
-- clave-valor y NO una fila por ajuste porque el patrón es «la fila guardada
-- gana; si no hay fila, manda el .env»: sembrarla con el valor del .env
-- congelaría el estado de esta instalación el día de la migración.
-- Sin fila inicial a propósito, también.
CREATE TABLE IF NOT EXISTS app_settings (
    key_name   VARCHAR(64) NOT NULL PRIMARY KEY COMMENT 'Clave del ajuste (p. ej. registration_enabled)',
    value      TEXT DEFAULT NULL COMMENT 'Valor guardado; NULL = usa el valor por defecto',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Cuándo se escribió por última vez'
) ENGINE=InnoDB;
