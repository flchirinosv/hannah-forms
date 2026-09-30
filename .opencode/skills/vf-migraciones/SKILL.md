---
name: vf-migraciones
description: Receta para escribir una migración de base de datos en VanillaForms (database/migrations/) sin romperla. Úsalo siempre que añadas o cambies una tabla o columna.
---

# Migraciones en VanillaForms

Cada migración es **un archivo que devuelve una closure** `fn(): void`, en
`database/migrations/`, numerado a partir de `0001_` (el siguiente número libre).
`database/migrate.php` las corre en orden y registra cada una en la tabla
`migrations` **solo tras el éxito**.

## Reglas duras

1. **Idempotente.** La migración se puede re-ejecutar sin romper nada. Para DDL,
   usa siempre `IF NOT EXISTS`:
   - `CREATE TABLE IF NOT EXISTS ...`
   - `CREATE INDEX IF NOT EXISTS ...`
   - `ALTER TABLE ... ADD COLUMN IF NOT EXISTS ...` (MariaDB lo soporta)
   Un `CREATE INDEX` plano revienta con error 1061 al re-ejecutar y deja la matriz
   de migraciones a medias.
2. **Sin transacción.** El DDL de MariaDB hace commit implícito; envolverlo en
   `beginTransaction()` no protege nada y puede dejar la BD en estado raro.
3. **Registra solo tras el éxito.** La fila en `migrations` se inserta cuando la
   closure ha terminado bien; si falla, no la insertes (para que se pueda reintentar).
4. **`declare(strict_types=1);`** al principio, como el resto de `database/`.
5. **No migres los datos a mano.** Los TIMESTAMP existentes ya son instantes UTC
   correctos; el contrato horario tiene tres puntos (sesión MariaDB en
   `SET time_zone = '+00:00'`, PHP en UTC, y conversión a la zona del lector solo al
   pintar). Si tocas fechas, no "corrijas" los datos: rompe la aritmética.

## Estructura de referencia

```php
<?php

declare(strict_types=1);

use App\Core\Database;

return function (): void {
    $pdo = Database::getInstance()->getPdo();

    // DDL idempotente. Ejemplo: añadir una columna.
    $pdo->exec('ALTER TABLE fields ADD COLUMN IF NOT EXISTS is_pii TINYINT(1) NOT NULL DEFAULT 0');

    // Índice siempre IF NOT EXISTS.
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_fields_pii ON fields (is_pii)');
};
```

Si necesitas leer configuración, usa `Database::getInstance(Database::config())` /
`Config::db()`; el runner de tests no siempre tiene `.env` cargado (ver la
advertencia de abajo).

## Correrlas

- Estado: `php database/migrate.php --status`.
- Aplicar: `php database/migrate.php`.
- BD desde cero (solo vacío): `mysql < database/schema.sql` y luego
  `mysql < database/seed.sql`. **Nunca** re-corras `schema.sql` sobre una BD con
  datos: no es idempotente como las migraciones.

## Permisos: el caso especial que se rompe siempre

El catálogo de permisos está **duplicado** a propósito y hay un test que lo vigila
(`tests/unit/PermissionSeedParityTest`):

- `config/permissions.php` — la fuente.
- `database/seed.sql` — la copia para instalaciones limpias (bloque
  `INSERT INTO permissions` + matriz `role_permissions`).

Si añades una clave de permiso, toca **los dos** y el rol por defecto en ambos; el
test compara claves, área, label, description y matriz, y sale rojo si divergen.

## Aviso: el runner de tests NO carga .env

`scripts/test.php` no carga `.env`. Algunas clases de test lo intentan con un
`if (!class_exists(Config::class)) require bootstrap.php`, pero `class_exists`
dispara el autoload y devuelve siempre `true`, así que el `require` nunca corre.
Consecuencia: si corres un filtro estrecho, esas clases salen con
`Errores: 1` y `SKIP: base de datos no disponible`, aunque la suite completa pase.
**Contrasta siempre cualquier filtro con una suite completa** antes de dar por
buena una migración.
