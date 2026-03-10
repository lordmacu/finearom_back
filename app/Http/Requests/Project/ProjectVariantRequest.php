<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

class ProjectVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre'        => ['required', 'string', 'max:255'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
