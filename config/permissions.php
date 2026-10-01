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
    'admin.roles'   => ['Gestionar roles (alta y matriz de permisos)', ['admin']],

    // Registro de eventos de seguridad.
    'logs.view'     => ['Ver el registro de eventos de seguridad', ['admin']],

    // Formularios propios (cada usuario solo ve los suyos por defecto;
    // `forms.manage_all` abre el alcance a los de cualquiera).
    'forms.manage_all'      => ['Ver y operar los formularios de cualquier usuario', ['admin']],
    'forms.create'          => ['Crear formularios (y duplicarlos)', ['admin', 'user', 'editor']],
    'forms.edit_own'        => ['Editar, publicar, cerrar y configurar formularios propios', ['admin', 'user', 'editor']],
    'forms.delete_own'      => ['Eliminar formularios propios', ['admin', 'user']],
    'forms.export_own'      => ['Exportar datos de formularios propios', ['admin', 'user', 'editor']],

    // F3 — revisión/aprobación. NO lleva sufijo `_own` por la misma razón que
    // `responses.pii`: no decide el alcance (a qué formularios se llega, eso es
    // `forms.edit_own` / `forms.manage_all`), sino si quien llega puede APROBAR.
    // Por defecto admin y el rol del sistema `approver` (migración 0011): con
    // solo `admin`, «mandar a revisión» era en la práctica «que lo mire el que
    // ya puede hacer todo». Publicar un formulario de otra persona es el acto
    // con más consecuencias de la app (decide si un texto se hace público), y
    // `approver` es exactamente el rol de quien solo hace eso.
    'forms.review'          => ['Aprobar o devolver formularios enviados a revisión', ['admin', 'approver']],

    // Analítica, respuestas y adjuntos de formularios propios.
    'analytics.view_own'    => ['Ver la analítica de formularios propios', ['admin', 'user', 'editor']],
    'responses.view_own'    => ['Ver y eliminar respuestas de formularios propios', ['admin', 'user', 'editor']],
    'attachments.download_own' => ['Descargar adjuntos de formularios propios', ['admin', 'user', 'editor']],

    // F1 (PII): es una CAPACIDAD, no un alcance, y por eso no lleva sufijo
    // `_own`: no decide a qué formularios se llega (eso es `responses.view_own`),
    // sino si dentro de los formularios ya autorizados se leen los valores
    // enmascarados o los reales. Quien NO lo tiene (user y editor por defecto)
    // ve `***` en la IP, el user-agent y los campos marcados como personales;
    // quien sí lo tiene los ve en claro. Falla cerrado: si la clave no existe en
    // la BD (migración no aplicada) `Auth::can()` da false y TODO sale enmascarado.
    'responses.pii'         => ['Ver datos personales sin enmascarar (IP, user-agent y campos marcados)', ['admin']],

    // Entrada a la app autenticada. `approver` entra por aquí para poder llegar
    // a `/review`: sin `dashboard.view` el rol que solo puede aprobar se
    // encontraría con un 403 en la puerta de entrada, y con el enlace «Revisión»
    // en la barra sin forma de verlo.
    'dashboard.view'        => ['Entrar al dashboard', ['admin', 'user', 'editor', 'approver']],
];