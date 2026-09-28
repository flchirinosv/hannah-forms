<?php

declare(strict_types=1);

/**
 * Fuente de verdad de los permisos de VanillaForms (RBAC, 2026-09-28).
 *
 * Formato: 'clave' => [descripción, lista de roles a los que se otorga por
 * defecto].
 *
 * Esta lista es la que siembran la migración 0005 y (como copia) `database/seed.sql`.
 * SIEMPRE que cambie una clave o sus roles por defecto hay que actualizar los
 * DOS sitios — lo comprueba `PermissionSeedParityTest` (leer `seed.sql` no puede
 * resolver la migración, así que el test lee el archivo y compara las claves y
 * los roles por defecto).
 *
 * Al añadir una clave nueva, hay que decidir dónde se comprueba en la app (cada
 * controlador hace su `Auth::can(...)`) y si aparece en la UI de la matriz de
 * roles (`/admin/roles` la pinta dinámicamente, así que no hay que tocarla).
 *
 * Convención de nombres: `area.accion_alcance` (alcance = own | all).
 */
return [
    // Panel de administración (área /admin).
    'admin.access'  => ['Entrar al panel de administración', ['admin']],
    'admin.users'   => ['Gestionar usuarios (rol y estado)', ['admin']],
    'admin.groups'  => ['Gestionar grupos de roles', ['admin']],
    'admin.roles'   => ['Editar la matriz de permisos de los roles', ['admin']],

    // Registro de eventos de seguridad.
    'logs.view'     => ['Ver el registro de eventos de seguridad', ['admin']],

    // Formularios propios (cada usuario solo ve los suyos por defecto;
    // `forms.manage_all` abre el alcance a los de cualquiera).
    'forms.manage_all'      => ['Ver y operar los formularios de cualquier usuario', ['admin']],
    'forms.create'          => ['Crear formularios (y duplicarlos)', ['admin', 'user', 'editor']],
    'forms.edit_own'        => ['Editar, publicar, cerrar y configurar formularios propios', ['admin', 'user', 'editor']],
    'forms.delete_own'      => ['Eliminar formularios propios', ['admin', 'user']],
    'forms.export_own'      => ['Exportar datos de formularios propios', ['admin', 'user', 'editor']],

    // Analítica, respuestas y adjuntos de formularios propios.
    'analytics.view_own'    => ['Ver la analítica de formularios propios', ['admin', 'user', 'editor']],
    'responses.view_own'    => ['Ver y eliminar respuestas de formularios propios', ['admin', 'user', 'editor']],
    'attachments.download_own' => ['Descargar adjuntos de formularios propios', ['admin', 'user', 'editor']],

    // Entrada a la app autenticada.
    'dashboard.view'        => ['Entrar al dashboard', ['admin', 'user', 'editor']],
];