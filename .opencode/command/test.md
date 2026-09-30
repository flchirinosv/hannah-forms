---
description: Corre un test por nombre de método, o la suite completa si no se pasa un filtro.
---

Corre los tests de VanillaForms con el filtro indicado: $ARGUMENTS

Si `$ARGUMENTS` está vacío, corre la suite completa:
```
php scripts/test.php
```

Si trae un nombre, úsalo como filtro de **nombre de método** (no de clase):
```
php scripts/test.php --filter $ARGUMENTS
```

## Antes de dar por buena una ejecución

- Un filtro casa por **nombre de método**, no de clase. Si pasas el nombre de la
  clase, ejecuta **0 tests y sale verde**: mira siempre el contador `Tests:` y
  confirma que es > 0.
- `scripts/test.php` **no carga `.env`**. Con un filtro estrecho, 7 clases
  (I18nTest, P10UploadsTest, P7ShareTest, P8SmartTest, P9TemplatesTest,
  QuizScoreTest, RandomizeTest) salen con `Errores: 1` y
  `SKIP: base de datos no disponible` aunque la suite completa pase. Si ves ese
  SKIP, **no es un fallo real**: confirma con la suite completa
  `php scripts/test.php` (~70 s) antes de asumir que algo se rompió.
- Para una carpeta: `php scripts/test.php --path tests/unit`.
- Sale con código 1 solo si hay `Fallidos` o `Errores`.
