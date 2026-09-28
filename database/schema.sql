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
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Forms
CREATE TABLE IF NOT EXISTS forms (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    title       VARCHAR(190) NOT NULL,
    description TEXT DEFAULT NULL,
    slug        VARCHAR(190) NOT NULL UNIQUE,
    status      ENUM('draft','published','closed') NOT NULL DEFAULT 'draft',
    theme       JSON DEFAULT NULL,
    settings    JSON DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_forms_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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

-- Índice para consultas frecuentes de dashboard: forms por usuario
CREATE INDEX idx_forms_user ON forms (user_id);
CREATE INDEX idx_forms_user_status ON forms (user_id, status);

-- Submissions
CREATE TABLE IF NOT EXISTS submissions (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_id     INT UNSIGNED NOT NULL,
    ip_address  VARCHAR(45) DEFAULT NULL,
    user_agent  VARCHAR(255) DEFAULT NULL,
    -- Token de capacidad de 128 bits para la página pública de gracias (P8).
    -- NUNCA usar `id` en `/f/{slug}/thanks`: es un AUTO_INCREMENT recorrible y
    -- filtraría las respuestas de todos los que contestaron el formulario.
    -- NULL = fila antigua sin token: se muestra el agradecimiento sin quiz.
    public_token CHAR(32) DEFAULT NULL,
    finished_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_submissions_form FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_submissions_form_finished ON submissions (form_id, finished_at);
CREATE UNIQUE INDEX idx_submissions_public_token ON submissions (public_token);

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

-- Migrations (registro de migraciones aplicadas para el script database/migrate.php)
CREATE TABLE IF NOT EXISTS migrations (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(190) NOT NULL UNIQUE,
    applied_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
