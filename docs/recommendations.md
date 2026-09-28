# Recomendaciones Tecnologicas

project: vanilla-forms
date: 2026-09-02
status: approved

stack:
  frontend:
    framework: Bootstrap 5.3 + Vanilla JS
    justification: Ya integrado y funcional, evita introducir dependencias pesadas sin necesidad
  backend:
    framework: PHP 8.2 vanilla (MVC propio)
    justification: Proyecto pequeño y autocontenido; un framework completo seria sobre-ingenieria
  database:
    engine: MariaDB 10.11
    justification: Compatible con MySQL, ya configurado y soportado por el entorno
  infrastructure:
    type: on-premise
    services: [PHP built-in server, MariaDB]
    justification: Proyecto local/self-hosted, cobertura suficiente para el alcance actual
  tooling:
    ci_cd: GitHub Actions
    monitoring: Error log + script de monitoreo
    testing: Runner propio (sin dependencias)

dependencies:
  core:
    - {name: PDO, version: "8.2", purpose: "Acceso a base de datos con prepared statements"}
    - {name: Bootstrap, version: "5.3", purpose: "UI responsiva"}
    - {name: Chart.js, version: "4.x", purpose: "Graficos en analytics"}
    - {name: SortableJS, version: "1.x", purpose: "Reordenamiento de campos en builder"}
  security:
    - {name: password_hash, version: "8.2", purpose: "Hashing de contrasenas"}
    - {name: CSRF tokens, version: "implementacion propia", purpose: "Prevencion de CSRF"}
    - {name: httponly session cookies, version: "implementacion propia", purpose: "Seguridad de sesion"}
  testing:
    - {name: Runner de tests propio, version: "1.0", purpose: "Tests unitarios y de integracion sin PHPUnit (evita Composer)"}
    - {name: Linter propio, version: "1.0", purpose: "Convenciones PSR estilo manual (sin PHP_CodeSniffer)"}
  build:
    - {name: dotenv (implementacion propia), version: "1.0", purpose: "Variables de entorno sin dependencias externas"}
    - {name: autoload (PSR-4 propio), version: "implementacion propia", purpose: "Autoloading manual sin Composer"}

architecture:
  pattern: monolith
  folders:
    - "app/Controllers/": Logica de negocio y orquestacion HTTP
    - "app/Models/": Acceso a datos y entidades
    - "app/Core/": Clases base (Router, Database, Auth, Request, Response, View, Session, Config, Csrf)
    - "config/": Configuracion de rutas, autoload, bootstrap y parametros
    - "views/": Templates PHP (MVC)
    - "public/": Punto de entrada y assets estaticos
    - "database/": Schema y seeds
    - "tests/": Suite de pruebas
  responsibilities:
    - "Router": Despacha metodos HTTP a controladores con middleware
    - "Controllers": Manejan request/response y delegacion a modelos
    - "Models": Encapsulan SQL y logica de dominio
    - "Views": Rendering HTML con datos
  data_flow: "Request HTTP -> Router -> Middleware -> Controller -> Model -> DB -> View -> Response"

considerations:
  security:
    - Migrar credenciales de config.php a variables de entorno (.env) cargadas con implementacion propia (sin Composer)
    - Mantener CSRF tokens en todos los POST
    - Usar password_hash/verify en auth
    - Validar y sanitizar todo input del usuario
  performance:
    - Agregar indices en consultas de analytics
    - Implementar caching de consultas frecuentes en dashboard
  accessibility:
    - Mantener semantic HTML y ARIA roles en formularios
    - Asegurar contraste y foco visible en el builder
  seo:
    - No aplica (sistema administrativo), pero mantener titulos descriptivos
  testing:
    - Cobertura minima 70% en controladores
    - Tests unitarios obligatorios para Modelos y Core
    - Tests de integracion para flujo auth y envio de respuestas
    - Correr con `php scripts/test.php` (runner propio, sin PHPUnit/Composer)
  maintainability:
    - Mover config a .env.example con cargador propio (sin Composer/dotenv externo)
    - Documentar endpoints en docs/api-spec.md
    - Mantener convenciones de nombres PSR-4 (autoload propio ya existente) revisadas manualmente (sin Composer/PHP_CodeSniffer)

approval:
  - section: stack
    decision: accepted
    feedback: "Aprobado con modificacion: excluir Composer; dotenv y testing resueltos sin dependencias externas"
  - section: dependencies
    decision: accepted
    feedback: "Aprobado: dotenv propio (App\\Core\\Env), autoload PSR-4 propio, runner de tests propio. Sin Composer."
  - section: architecture
    decision: accepted
    feedback: "Aprobado: se mantiene monolith MVC existente"
  - section: considerations
    decision: accepted
    feedback: "Aprobado: migrar credenciales a .env con cargador propio, testing con runner sin dependencias"

# Notas de remediación (auditoría 2026-09-25)

hallazgos_resueltos:
  - B4 (getPdo expuesto): "`Database::getPdo()` sigue existiendo, pero a partir de esta auditoría solo se usa desde tests (FormTest, DatabaseTest, SubmissionAnalyticsTest). El código de producción usa Database::query/execute. No se elimina para no romper la suite; documentado como superficie a reducir."
  - B7 (CSP desactivada por defecto): "CSP_POLICY estaba vacía porque el frontend enviaba <script> inline (datos embebidos en builder/analytics y handlers on*). Desde 2026-09-25 los datos viajan en atributos data-* leídos por app.js/builder.js/analytics.js y los handlers on* fueron movidos a listeners externos; CSP quedó ACTIVA por defecto con la política de abajo. Quedan excepciones deliberadas: style-src 'unsafe-inline' (atributos style de Bootstrap)."
  csp_politica_recomendada: "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'"
  - B8 (seed con credenciales conocidas): "database/seed.sql crea usuarios de demo (admin@example.com / password123) con password_hash válido. NO es un secreto; es para local y desarrollo. Queda documentado aquí para evitar que se instale en producción o se reporte como vulnerabilidad."
  - A2 (fuerza bruta por email): "Login ahora reporta en dos alcances de RateLimiter: 'login' (por IP, 5/300s) y 'login_email' (identificador sha256(email en minúsculas), 10/300s). Ambos se resetean en login exitoso."
