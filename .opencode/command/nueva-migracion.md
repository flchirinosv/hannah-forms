---
description: Crea el esqueleto de una migración nueva en database/migrations/ con la convención del repo.
---

Prepara una migración nueva en VanillaForms.

Nombre sugerido (usa $ARGUMENTS como pista, en minúsculas y con guiones bajos):
$ARGUMENTS

Pasos:
1. Determina el siguiente número: el mayor `NNNN_` en `database/migrations/` + 1.
2. Crea `database/migrations/NNNN_<nombre>.php` que devuelva una closure
   `fn(): void`, con `declare(strict_types=1);` y un docblock que explique el
   porqué (igual que las existentes).
3. La closure debe ser **idempotente**: `CREATE TABLE IF NOT EXISTS`,
   `CREATE INDEX IF NOT EXISTS`, `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`.
   **Sin transacción** (el DDL hace commit implícito). Registra en la tabla
   `migrations` **solo tras el éxito** (esto lo hace `database/migrate.php`).
4. No inventes datos de arranque: las migraciones no migran datos.

Después aplícala con el command `/migrate` y verifica con `/test`.

Para las reglas duras y casos especiales (p. ej. permisos duplicados en
`config/permissions.php` + `database/seed.sql`), carga el skill `vf-migraciones`.
