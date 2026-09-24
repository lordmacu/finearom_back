<?php

namespace App\Support;

/**
 * El ingeniero de desarrollo lo asigna otro ingeniero (rol Desarrollo) o un
 * admin / super-admin, no la comercial: se hace desde el panel del listado,
 * no al editar el proyecto.
 */
final class ProjectEngineerPermission
{
    public const ASSIGN = 'project assign engineer';

    /** Rol de los usuarios que se pueden asignar como ingeniero. */
    public const ROLE = 'Desarrollo';
}
