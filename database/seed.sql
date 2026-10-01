-- VanillaForms Seed — SOLO roles.
--
-- Este archivo NO crea usuarios. Antes sí, y con la misma contraseña publicada
-- en el repositorio para los tres, uno de ellos admin: desplegar la app y
-- correr este seed dejaba una puerta abierta con la cerradura escrita al lado
-- (item 2.3 del plan de remediación). Los usuarios demo ahora van en
-- database/seed.php, que no hace nada salvo que se pida con SEED_DEMO=1.
--
-- Para una instalación real, el admin se crea a mano:
--     ADMIN_PASSWORD=una-larga php scripts/create-admin.php
--
-- Este archivo sí es idempotente y se puede correr en cualquier momento.

USE vanilla_forms;

-- `approver` es un rol del SISTEMA (Role::SYSTEM_ROLES): no se puede borrar
-- desde /admin/roles, porque perderlo dejaría la revisión de F3 sin nadie que
-- pueda aprobar. Nace con `forms.review` (aprobar) y `dashboard.view` (poder
-- LLEGAR a /review, que el dashboard exige); darle además edición sería un
-- dueño a medio camino, que es justo lo que este rol no es.
INSERT INTO roles (name, description) VALUES
    ('admin',    'Acceso total al sistema'),
    ('user',     'Crea y gestiona sus propios formularios'),
    ('editor',   'Puede editar formularios pero no eliminarlos'),
    ('approver', 'Revisa y aprueba los formularios que otros le envían')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- ===========================================================================
-- Permisos (RBAC, 2026-09-28) — COPIA de config/permissions.php.
-- La migración 0005 siembra la MISMA lista para bases existentes; aquí va la
-- copia para instalaciones limpias (schema.sql + seed.sql). SIEMPRE que cambie
-- config/permissions.php hay que actualizar este bloque; lo comprueba
-- PermissionSeedParityTest (que lee este archivo y compara claves y roles).
-- ===========================================================================

INSERT INTO permissions (`key`, description) VALUES
    ('admin.access',  'Entrar al panel de administración'),
    ('admin.users',   'Gestionar usuarios (rol y estado)'),
    ('admin.groups',  'Gestionar grupos de roles'),
    ('admin.roles',   'Gestionar roles (alta y matriz de permisos)'),
    ('logs.view',     'Ver el registro de eventos de seguridad'),
    ('forms.manage_all', 'Ver y operar los formularios de cualquier usuario'),
    ('forms.create',  'Crear formularios (y duplicarlos)'),
    ('forms.edit_own','Editar, publicar, cerrar y configurar formularios propios'),
    ('forms.delete_own', 'Eliminar formularios propios'),
    ('forms.export_own', 'Exportar datos de formularios propios'),
    ('forms.review', 'Aprobar o devolver formularios enviados a revisión'),
    ('analytics.view_own', 'Ver la analítica de formularios propios'),
    ('responses.view_own', 'Ver y eliminar respuestas de formularios propios'),
    ('attachments.download_own', 'Descargar adjuntos de formularios propios'),
    ('responses.pii', 'Ver datos personales sin enmascarar (IP, user-agent y campos marcados)'),
    ('dashboard.view','Entrar al dashboard')
ON DUPLICATE KEY UPDATE description = VALUES(description);

-- Asignación por defecto de permisos a roles (admin: todo; user: operación de
-- formularios propios; editor: igual que user pero sin eliminar).
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON (
    (r.name = 'admin')
    OR (r.name = 'user'  AND p.`key` IN ('forms.create','forms.edit_own','forms.delete_own','forms.export_own','analytics.view_own','responses.view_own','attachments.download_own','dashboard.view'))
    OR (r.name = 'editor' AND p.`key` IN ('forms.create','forms.edit_own','forms.export_own','analytics.view_own','responses.view_own','attachments.download_own','dashboard.view'))
    OR (r.name = 'approver' AND p.`key` IN ('forms.review','dashboard.view'))
)
ON DUPLICATE KEY UPDATE permission_id = VALUES(permission_id);
