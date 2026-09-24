<?php

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Quién puede ver el factor de un proyecto: Mónica y el rol Desarrollo
 * (permiso 'project factor view'). Se consulta el permiso asignado, no el
 * Gate: super-admin lo pasa todo y aquí no debe verlo.
 */
final class ProjectFactorPermission
{
    public const VIEW = 'project factor view';

    public static function canView(?Authenticatable $user): bool
    {
        return $user !== null
            && method_exists($user, 'checkPermissionTo')
            && $user->checkPermissionTo(self::VIEW);
    }
}
