<?php

namespace App\Rules;

use App\Models\ProjectProductType;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * El tipo de producto debe pertenecer a la categoría elegida.
 *
 * El select del formulario ya filtra por categoría, pero un POST directo podría
 * dejar el proyecto incoherente (un shampoo en Home Care).
 *
 * Si el request no trae categoría no valida nada: el tipo solo, sin categoría,
 * es un estado permitido (es el de los proyectos históricos).
 */
class ProductTypeBelongsToCategory implements ValidationRule
{
    public function __construct(private readonly ?int $categoryId) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value || ! $this->categoryId) {
            return;
        }

        $tipo = ProjectProductType::find($value);

        if (! $tipo) {
            return; // lo reporta la regla `exists`
        }

        if ($tipo->product_category_id !== null
            && (int) $tipo->product_category_id !== (int) $this->categoryId) {
            $fail('El tipo de producto seleccionado no pertenece a la categoría elegida.');
        }
    }
}
