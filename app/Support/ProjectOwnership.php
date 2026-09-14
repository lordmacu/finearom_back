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
    public static function canManage(User $user, Project $project): bool
    {
        if (!$user->hasRole('Comercial')) {
            return true;
        }

        return (int) $project->ejecutivo_id === (int) $user->id;
    }
}
