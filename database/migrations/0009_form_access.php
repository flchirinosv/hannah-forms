<?php

declare(strict_types=1);

use App\Core\Database;

/**
 * Migración 0009: F4 — acceso restringido por email.
 *
 * Añade las dos tablas que sostienen el modo restringido del fill:
 * `form_access_emails` (la allowlist) y `form_access_tokens` (los enlaces de
 * acceso). El MODO vive en `settings.access.mode` (`public|email|allowlist`),
 * no en una columna: con el gate en JSON se lee en el mismo sitio que el resto
 * de la configuración del fill y no obliga a un `MODIFY TABLE` cada vez que se
 * añada un modo nuevo.
 *
 * Por qué una TABLA y no el array de emails en `settings` (la opción que se
 * descartó): revocar un acceso tiene que borrar sus tokens en la MISMA
 * operación, y una fila es lo que permite que el borrado sea una cascada de SQL
 * y no un bucle de PHP que puede quedarse a medias. Además el listado necesita
 * consultas propias (buscar, paginar) y un `UNIQUE (form_id, email)` que el JSON
 * no puede garantizar sin código extra. La forma normalizada también aguanta
 * listas que un `JSON` grande empieza a costear en cada lectura de la fila.
 *
 * Por qué el token NO es de un solo uso (a diferencia de `password_resets`): un
 * enlace de acceso tiene que sobrevivir a un F5, a volver atrás para corregir
 * una respuesta y a la barra de progreso. De un solo uso, el formulario se
 * volvería inservible en cuanto se recargara la página. El control real es la
 * caducidad (30 días) y `revoked_at`: quitar un email de la allowlist mata sus
 * tokens, y con ellos cualquier enlace que ese correo pudiera tener guardado.
 *
 * Por qué `token_hash` y no el token: la misma razón que en F2. La fila no es el
 * secreto — si alguien lee un dump o un backup sin cifrar no debe poder
 * autenticarse con lo que encuentra. La comparación va por índice único.
 *
 * Por qué los tokens NO tienen FK a `form_access_emails`: en modo `email` hay
 * tokens sin fila en la allowlist (el caso normal de ese modo, que no usa
 * lista). Una FK sería o incorrecta (permitiría entradas huérfanas) o obligaría
 * a crear la fila fantasma. La revocación es explícita y va en una transacción
 * en el modelo, que es donde se puede garantizar.
 *
 * Idempotente: ambas tablas se crean con `CREATE TABLE IF NOT EXISTS`, que
 * incluye sus índices y su FK. No hay bloque de permisos: F4 no añade ningún
 * permiso nuevo (gestionar la allowlist de un formulario es `canEdit` sobre él,
 * el mismo criterio que el resto de su configuración).
 */
return static function (Database $db): void {
    $db->query(
        "CREATE TABLE IF NOT EXISTS form_access_emails (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            form_id    INT UNSIGNED NOT NULL,
            email      VARCHAR(190) NOT NULL COMMENT 'Normalizado a minúsculas por el modelo',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_form_access_email (form_id, email),
            CONSTRAINT fk_form_access_email_form FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
        ) ENGINE=InnoDB COMMENT='F4: allowlist de emails con acceso al formulario'"
    );

    $db->query(
        "CREATE TABLE IF NOT EXISTS form_access_tokens (
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
        ) ENGINE=InnoDB COMMENT='F4: enlaces de acceso al formulario (varios usos, con caducidad)'"
    );

    echo "  [DDL] form_access_emails y form_access_tokens listas\n";
};
