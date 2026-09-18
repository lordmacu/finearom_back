<?php

namespace App\Support;

use App\Models\Project;
use App\Models\User;

/**
 * Regla de dueño del proyecto: un usuario con rol Comercial solo puede editar
 * un proyecto y enviar sus correos si es el ejecutivo asignado. El resto de
 * roles sigue gobernado solo por permisos (sin restricción de dueño).
 */
class ProjectOwnership
{
    public const ADMIN_ROLES = ['admin', 'super-admin', 'Administrador'];

    public static function canManage(User $user, Project $project): bool
    {
        // Un admin que además es Comercial no queda limitado a sus proyectos
        if (!$user->hasRole('Comercial') || $user->hasRole(self::ADMIN_ROLES)) {
            return true;
        }

        return (int) $project->ejecutivo_id === (int) $user->id;
    }
}
