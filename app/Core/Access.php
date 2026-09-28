<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Acceso a formularios (RBAC, 2026-09-28).
 *
 * El acceso por OBJETO va en dos ejes ortogonales:
 *
 *  1. Alcance: cada usuario solo ve y opera sus formularios ("cada usuario
 *     solo ve sus formularios"); el permiso `forms.manage_all` abre el alcance
 *     a los formularios de cualquiera (lo tienen admin por defecto y los
 *     grupos a los que se les otorgue).
 *  2. Capacidad: dentro del alcance, qué puede hacer: editar, borrar, exportar.
 *     Se comprueba con `Auth::can()` sobre el permiso correspondiente.
 *
 * Estas comprobaciones sustituyen a los `canAccess`/`canEdit` que daban al rol
 * `editor` acceso global a todo formulario (decisión F5.2): con RBAC, `editor`
 * sigue siendo un rol con permisos (editar, no borrar) pero SOLO sobre sus
 * propios formularios, como el resto.
 */
final class Access
{
    /** Ver un formulario: dueño (cualquiera de sus permisos) o alcance global. */
    public static function canView(int $ownerId): bool
    {
        return (int) Auth::id() === $ownerId || Auth::can('forms.manage_all');
    }

    /** Editar/configurar un formulario (builder, publish, close, settings). */
    public static function canEdit(int $ownerId): bool
    {
        return self::ownWith($ownerId, 'forms.edit_own') || Auth::can('forms.manage_all');
    }

    /** Eliminar un formulario. */
    public static function canDelete(int $ownerId): bool
    {
        return self::ownWith($ownerId, 'forms.delete_own') || Auth::can('forms.manage_all');
    }

    /** Exportar datos/estructura de un formulario. */
    public static function canExport(int $ownerId): bool
    {
        return self::ownWith($ownerId, 'forms.export_own') || Auth::can('forms.manage_all');
    }

    /**
     * Leer el contenido de un formulario propio con un permiso concreto
     * (`analytics.view_own`, `responses.view_own`, ...).
     */
    public static function canReadOwn(int $ownerId, string $ownPermission): bool
    {
        return self::ownWith($ownerId, $ownPermission) || Auth::can('forms.manage_all');
    }

    private static function ownWith(int $ownerId, string $ownPermission): bool
    {
        return (int) Auth::id() === $ownerId && Auth::can($ownPermission);
    }
}