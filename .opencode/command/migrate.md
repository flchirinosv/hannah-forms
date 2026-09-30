---
description: Aplica o consulta el estado de las migraciones de base de datos.
---

Gestiona las migraciones de VanillaForms.

Aplica las pendientes:
```
php database/migrate.php
```

Consulta el estado (qué se ha aplicado y qué falta):
```
php database/migrate.php --status $ARGUMENTS
```

## Notas importantes

- Solo para una BD **existente**. Para una BD desde cero se usa
  `mysql < database/schema.sql` y luego `mysql < database/seed.sql`.
- **Nunca** re-corras `database/schema.sql` sobre una BD con datos: no es
  idempotente como las migraciones. Usa `php database/migrate.php` en su lugar.
- Las migraciones son closures idempotentes y sin transacción (el DDL de MariaDB
  hace commit implícito). Los índices se crean siempre con `CREATE INDEX IF NOT
  EXISTS`; un `CREATE INDEX` plano revienta con 1061 al re-ejecutar.
- Si estás **escribiendo** una migración nueva, consulta el skill
  `vf-migraciones` para la receta y las reglas duras.
