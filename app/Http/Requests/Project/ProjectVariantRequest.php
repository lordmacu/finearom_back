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
            'nombre'                 => ['required', 'string', 'max:255'],
            'categoria'              => ['nullable', 'string', 'max:100'],
            'observaciones'          => ['nullable', 'string', 'max:2000'],
            'descripcion'            => ['nullable', 'string', 'max:5000'],
            'benchmark_reference_id' => ['nullable', 'integer', 'exists:finearom_references,id'],
        ];
    }
}
