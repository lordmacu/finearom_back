<?php

namespace App\Http\Requests\ProjectPotential;

use Illuminate\Foundation\Http\FormRequest;

class ProjectPotentialPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'precio' => ['present', 'nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return ['precio.min' => 'El precio no puede ser negativo.'];
    }
}
