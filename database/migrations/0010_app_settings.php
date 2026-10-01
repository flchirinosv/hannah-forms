<?php

declare(strict_types=1);

use App\Core\Database;

/**
 * Migración 0010: ajustes de la instalación (`app_settings`).
 *
 * Tabla de UNA columna clave-valor, y no una fila por ajuste, porque el patrón
 * que se repite es «el valor guardado gana, y si no hay ninguno, el `.env`». Un
 * interruptor como el registro público tiene que poder contestarse en el panel
 * (para el que no tiene acceso al servidor) sin que eso deje de ser reversible
 * desde el `.env` ni dependa de reescribir un fichero de configuración en
 * caliente. Con la fila ausente, `.env` manda; con la fila presente, manda ella.
 *
 * Por qué NO una tabla de una columna por ajuste: el día que aparezca el
 * segundo (idioma por defecto de la instancia, mantenimiento…), esta ya está
 * escrita y no hay que hacer un `ALTER` ni un `CREATE OR REPLACE` de la tabla
 * entera. Y por qué no `settings` de `forms`: esto no es de un formulario, es de
 * la instalación entera.
 *
 * El comentario de `key` es lo que hace legible un `SELECT *` en una consola:
 * `key` a secas no dice si el valor es un interruptor o un texto.
 *
 * SIN fila inicial a propósito: sembrarla con el valor de `.env` congelaría el
 * estado de esta instalación en el momento de migrar, y el que vino a poner la
 * migración ya no podría cambiar el interruptor desde el panel (su escritura
 * crearía la fila, pero mientras nadie la creara, el `.env` seguiría mandando
 * y nadie lo vería pasar al panel). El valor por defecto de la app se decide en
 * `App\Core\AppSettings`, no en el DDL.
 *
 * Idempotente: `CREATE TABLE IF NOT EXISTS` (que incluye su PK y su UNIQUE).
 * Sin bloque de permisos: el interruptor lo usa `admin.access`, que ya existe.
 */
return static function (Database $db): void {
    $db->query(
        "CREATE TABLE IF NOT EXISTS app_settings (
            key_name  VARCHAR(64) NOT NULL PRIMARY KEY COMMENT 'Clave del ajuste (p. ej. registration_enabled)',
            value     TEXT DEFAULT NULL COMMENT 'Valor guardado; NULL = usa el valor por defecto',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Cuándo se escribió por última vez'
        ) ENGINE=InnoDB COMMENT='Ajustes de la instalación; la fila ausente deja mandar al .env'"
    );

    echo "  [DDL] app_settings lista\n";
};
