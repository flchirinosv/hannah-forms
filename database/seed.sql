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

INSERT INTO roles (name, description) VALUES
    ('admin',  'Acceso total al sistema'),
    ('user',   'Crea y gestiona sus propios formularios'),
    ('editor', 'Puede editar formularios pero no eliminarlos')
ON DUPLICATE KEY UPDATE name = VALUES(name);
