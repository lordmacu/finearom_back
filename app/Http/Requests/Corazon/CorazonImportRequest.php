<?php

namespace App\Http\Requests\Corazon;

use Illuminate\Foundation\Http\FormRequest;

class CorazonImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file'    => ['required', 'file', 'mimes:xlsx', 'max:5120'],
            'dry_run' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Selecciona el archivo Excel a importar.',
            'file.mimes'    => 'El archivo debe ser un Excel .xlsx (descarga la plantilla de ejemplo).',
            'file.max'      => 'El archivo no puede pesar más de 5 MB.',
        ];
    }
}
