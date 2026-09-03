<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

class ProjectApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // project_applications.dosis es decimal(10,2): validarla como
            // string dejaba pasar texto que después revienta en el insert.
            'dosis'               => ['nullable', 'numeric', 'min:0'],
            'cantidad_aplicacion' => ['nullable', 'integer', 'min:0'],
            'observaciones'       => ['nullable', 'string', 'max:2000'],
        ];
    }
}
