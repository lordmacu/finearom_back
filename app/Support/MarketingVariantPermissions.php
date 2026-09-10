<?php

namespace App\Support;

use Illuminate\Contracts\Auth\Access\Authorizable;

/**
 * Permisos de las variantes de Marketing.
 *
 * La propiedad es por tabla: la variante es de Comercial y sus referencias
 * son de Desarrollo. Por eso no hay filtrado por campo — cada endpoint
 * pertenece entero a un rol y la autorización va en el middleware de ruta.
 */
final class MarketingVariantPermissions
{
    public const COMMERCIAL = 'project variant commercial';
    public const TECHNICAL  = 'project variant technical';

    /**
     * @return array{can_manage_variants: bool, can_manage_references: bool}
     */
    public static function metaFor(?Authorizable $user): array
    {
        return [
            'can_manage_variants'   => (bool) $user?->can(self::COMMERCIAL),
            'can_manage_references' => (bool) $user?->can(self::TECHNICAL),
        ];
    }
}
